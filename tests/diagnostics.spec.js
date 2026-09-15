const { test, expect } = require('./fixtures/fake-paycenter');
const crypto = require('node:crypto');

test.use({ extraHTTPHeaders: { 'X-Epay-Test': 'epay-qualification', 'X-Epay-Test-Diagnostics': '1', 'X-Epay-Test-Diagnostics-Debug': 'yes' } });

async function createOrder(request) {
  const response = await request.post('/wp-json/epay-test/v1/create-order', { data: {} });
  expect(response.ok(), await response.text()).toBe(true);
  return response.json();
}

async function receipt(request, order) {
  const response = await request.get(order.receipt_url);
  const html = await response.text();
  expect(response.ok()).toBe(true);
  const encoded = html.match(/data-epay-diagnostics="([^"]+)"/)?.[1];
  expect(encoded, 'The payment page must supply diagnostics-only authorization').toBeTruthy();
  return JSON.parse(encoded.replaceAll('&quot;', '"').replaceAll('&amp;', '&').replaceAll('&#039;', "'"));
}

async function logs(request, reference) {
  const response = await request.get('/wp-json/epay-test/v1/diagnostics/logs', { params: { reference } });
  expect(response.ok()).toBe(true);
  return response.json();
}

function report(context, extra = {}) {
  return { authorization: context.authorization, event: 'script_started', sequence: 1, elapsed_ms: 0, details: {}, ...extra };
}

async function sendReport(request, context, payload = report(context)) {
  return request.post(context.ajax_url, { form: { action: 'epay_paycenter_diagnostic', payload: JSON.stringify(payload) } });
}

function observations(entries) {
  return entries.filter(entry => entry.message.startsWith('ePay handoff diagnostic '))
    .map(entry => JSON.parse(entry.message.slice(entry.message.indexOf('{'))));
}

async function events(request, reference) {
  return observations(await logs(request, reference)).map(entry => entry.event);
}

async function fixture(request, path, data = {}, headers = {}) {
  const response = await request.post(`/wp-json/epay-test/v1/${path}`, { data, headers });
  expect(response.ok(), await response.text()).toBe(true);
  return response.json();
}

async function enableRecovery(request, order) {
  await fixture(request, 'fake-follow-up', { scenario: 'paid', channel: 'eCommerce' });
  expect((await fixture(request, `follow-up/test-channel/${order.order_id}`)).success).toBe(true);
  expect((await fixture(request, 'follow-up/enable')).follow_up_enabled).toBe(true);
  await fixture(request, 'fake-follow-up', { scenario: 'not_found', channel: 'eCommerce' });
}

test('@diagnostics a guest can report an issued attempt without changing its payment state', async ({ request }) => {
  const order = await createOrder(request);
  const context = await receipt(request, order);
  expect(context.order_id).toBe(order.order_id);
  expect(context.reference).toMatch(new RegExp(`^${order.order_id}-[A-Z0-9]{12}$`));
  expect(context.trace_id).toMatch(/^[a-f0-9-]{36}$/);
  expect(context.expires_at).toBeGreaterThan(Date.now() / 1000 + 3500);
  const before = await (await request.get(`/wp-json/epay-test/v1/order/${order.order_id}`)).json();
  const response = await sendReport(request, context);
  expect(response.status()).toBe(202);
  await expect.poll(() => events(request, context.reference)).toContain('script_started');
  const entries = await logs(request, context.reference);
  expect(entries.some(entry => entry.message.includes('redirect_rendered'))).toBe(true);
  expect(entries.some(entry => entry.message.includes('"origin":"browser"'))).toBe(true);
  expect(JSON.stringify(entries)).not.toContain(context.authorization);
  const after = await (await request.get(`/wp-json/epay-test/v1/order/${order.order_id}`)).json();
  expect(after).toEqual(before);
});

test('@diagnostics @matrix browser handoff reports observations and submits the original bank form once', async ({ page, request, fakePaycenter }) => {
  const order = await createOrder(request);
  await page.goto(order.receipt_url);
  await expect(page.getByRole('heading', { name: 'Fake Paycenter' })).toBeVisible();
  expect(fakePaycenter.submissions).toHaveLength(1);
  const handoff = fakePaycenter.submissions[0];
  expect(handoff.has('authorization')).toBe(false);
  expect(handoff.has('trace_id')).toBe(false);
  const reference = handoff.get('MerchantReference');
  await expect.poll(() => events(request, reference)).toEqual(expect.arrayContaining(['script_started', 'form_found', 'submission_attempted']));
  const entries = await logs(request, reference);
  const observations = entries.filter(entry => entry.message.includes('"origin":"browser"'))
    .map(entry => JSON.parse(entry.message.slice(entry.message.indexOf('{'))));
  expect(observations.map(entry => entry.event)).toEqual(expect.arrayContaining(['script_started', 'form_found', 'submission_attempted']));
  expect(new Set(observations.map(entry => entry.trace_id)).size).toBe(1);
  expect(observations.every(entry => entry.order_id === order.order_id && entry.reference === reference)).toBe(true);
});

test('@diagnostics a helper moved into the document head still finds its reporting context', async ({ page, request }) => {
  const order = await createOrder(request);
  await page.route(order.receipt_url, async route => {
    const response = await route.fetch();
    let html = await response.text();
    const script = html.match(/<script\b[^>]*src=["'][^"']*\/epay-paycenter-redirect\.js[^"']*["'][^>]*>[\s\S]*?<\/script>/)?.[0];
    expect(script).toBeTruthy();
    const earlyScript = script.replace(/\sdefer(?:=["'][^"']*["'])?/g, '');
    html = html.replace(script, '').replace('</head>', earlyScript + '</head>');
    await route.fulfill({ response, body: html });
  });
  let reference;
  await page.route('https://paycenter.piraeusbank.gr/**', async route => {
    reference = new URLSearchParams(route.request().postData()).get('MerchantReference');
    await route.fulfill({ contentType: 'text/html', body: '<h1>Fake Paycenter</h1>' });
  });
  await page.goto(order.receipt_url);
  await expect(page.getByRole('heading', { name: 'Fake Paycenter' })).toBeVisible();
  await expect.poll(() => events(request, reference)).toContain('submission_attempted');
});

test('@diagnostics rejects invalid authorization and bounded-input violations without payment changes', async ({ request }) => {
  const order = await createOrder(request);
  const context = await receipt(request, order);
  const before = await (await request.get(`/wp-json/epay-test/v1/order/${order.order_id}`)).json();
  const [encoded, signature] = context.authorization.split('.');
  const claims = JSON.parse(Buffer.from(encoded, 'base64').toString());
  const forged = Buffer.from(JSON.stringify({ ...claims, order_id: order.order_id + 1 })).toString('base64') + '.' + signature;
  const expired = await (await request.post('/wp-json/epay-test/v1/diagnostics/authorization', { data: { ...claims, expires_at: Math.floor(Date.now() / 1000) - 1 } })).json();
  for (const authorization of ['', 'not-a-signature', forged, expired.authorization]) {
    expect((await sendReport(request, context, report(context, { authorization }))).status()).toBe(403);
  }
  for (const invalid of [
    { event: 'payment_complete' }, { order_id: order.order_id + 1 }, { reference: 'another-attempt' },
    { details: { password: 'must-not-log' } }, { details: { line: 'not-a-number' } },
    { details: { error_message: { nested: 'unsafe' } } }, { sequence: 0 }, { sequence: 21 },
    { elapsed_ms: -1 }, { elapsed_ms: 3600001 },
  ]) {
    expect((await sendReport(request, context, report(context, invalid))).status()).toBe(400);
  }
  expect((await sendReport(request, context, report(context, { details: { error_message: 'x'.repeat(8192) } }))).status()).toBe(413);
  expect((await request.get(context.ajax_url, { params: { action: 'epay_paycenter_diagnostic' } })).status()).toBe(405);
  expect(await events(request, context.reference)).not.toContain('script_started');
  expect(await (await request.get(`/wp-json/epay-test/v1/order/${order.order_id}`)).json()).toEqual(before);
});

test('@diagnostics bounds reports and scrubs browser-supplied details', async ({ request }) => {
  const order = await createOrder(request);
  const context = await receipt(request, order);
  const details = {
    error_name: context.authorization,
    error_message: 'email@example.test password=secret-value Bearer bearer-value https://example.test/?key=order-secret 4111111111111111 "password":"quoted-secret" Authorization=Bearer auth-space-secret password="two word secret"',
    script_path: '/assets/broken.js?token=script-secret#fragment',
    browser: 'https://url-user:url-secret@example.test/script "username": "json-user"',
  };
  expect((await sendReport(request, context, report(context, { details }))).status()).toBe(202);
  expect((await sendReport(request, context)).status()).toBe(429);
  for (let sequence = 2; sequence <= 20; sequence++) {
    expect((await sendReport(request, context, report(context, { sequence }))).status()).toBe(202);
  }
  const entries = observations(await logs(request, context.reference)).filter(entry => entry.origin === 'browser');
  expect(entries).toHaveLength(20);
  const output = JSON.stringify(entries);
  for (const secret of ['email@example.test', 'secret-value', 'bearer-value', 'order-secret', '4111111111111111', 'script-secret', 'quoted-secret', 'url-user', 'url-secret', 'json-user', 'auth-space-secret', 'two word secret', context.authorization.split('.')[0]]) {
    expect(output).not.toContain(secret);
  }
  expect(entries[0].details.script_path).toBe('/assets/broken.js');
});

for (const { label, message, expected } of [
  { label: 'Greek text crossing 400 bytes', message: 'Σφάλμα ' + 'α'.repeat(250), expected: 'Σφάλμα ' + 'α'.repeat(250) },
  { label: 'four-byte characters at the output limit', message: 'Σφάλμα ' + '🙂'.repeat(450), expected: 'Σφάλμα ' + '🙂'.repeat(393) },
  { label: 'redacted text crossing the input limit', message: 'password=' + 'x'.repeat(970) + ' Σφάλμα ' + 'α'.repeat(250), expected: '[credential omitted] Σφάλμα ' + 'α'.repeat(13) },
]) {
  test(`@diagnostics @unicode preserves readable ${label} in WooCommerce logs`, async ({ request }) => {
    const order = await createOrder(request);
    const context = await receipt(request, order);
    const response = await sendReport(request, context, report(context, {
      event: 'javascript_error', details: { error_message: message },
    }));
    expect(response.status()).toBe(202);
    const entry = observations(await logs(request, context.reference)).find(entry => entry.origin === 'browser');
    expect(entry.details.error_message).toBe(expected);
    expect(Array.from(entry.details.error_message).length).toBeLessThanOrEqual(400);
  });
}

test('@diagnostics concurrent receipts keep independent reporting identities', async ({ request }) => {
  const order = await createOrder(request);
  const contexts = await Promise.all([receipt(request, order), receipt(request, order)]);
  expect(contexts[0].trace_id).not.toBe(contexts[1].trace_id);
  expect(contexts[0].reference).not.toBe(contexts[1].reference);
  for (const context of contexts) {
    expect((await sendReport(request, context)).status()).toBe(202);
    const entry = observations(await logs(request, context.reference)).find(entry => entry.origin === 'browser');
    expect(entry).toMatchObject({ order_id: order.order_id, reference: context.reference, trace_id: context.trace_id });
  }
});

test('@diagnostics correlates unauthenticated callback arrivals without treating them as payment evidence', async ({ request }) => {
  const order = await createOrder(request);
  const context = await receipt(request, order);
  const before = await (await request.get(`/wp-json/epay-test/v1/order/${order.order_id}`)).json();
  await request.post('/wc-api/epay_paycenter/', {
    form: { MerchantReference: context.reference, ResultCode: '0', StatusFlag: 'Success', HashKey: 'invalid-signature' }, maxRedirects: 0,
  });
  const entries = await logs(request, context.reference);
  expect(entries.find(entry => entry.message.startsWith('Callback envelope')).message).toContain('"claimed_reference"');
  expect(entries.some(entry => entry.message.startsWith('HashKey verification failed'))).toBe(true);
  expect(entries.some(entry => entry.message.startsWith('Authenticated Paycenter callback'))).toBe(false);
  expect(await (await request.get(`/wp-json/epay-test/v1/order/${order.order_id}`)).json()).toEqual(before);
});

test('@diagnostics verified callbacks and thank-you requests retain distinct evidence', async ({ request }) => {
  const order = await createOrder(request);
  const context = await receipt(request, order);
  const payload = {
    MerchantReference: context.reference, ResultCode: '0', ResponseCode: '0', StatusFlag: 'Success',
    ApprovalCode: 'TST123', Parameters: `wc_order_id=${order.order_id}`, SupportReferenceID: `8${order.order_id}`,
    AuthStatus: '', PackageNo: '1', TransactionId: `9${order.order_id}`, PaymentMethod: 'Card',
  };
  const ticket = `TST${crypto.createHash('sha256').update(context.reference).digest('hex').slice(0, 29)}`;
  const signed = [ticket, process.env.EPAY_TEST_POS_ID || '99999999', process.env.EPAY_TEST_ACQUIRER_ID || '14',
    payload.MerchantReference, payload.ApprovalCode, payload.Parameters, payload.ResponseCode,
    payload.SupportReferenceID, payload.AuthStatus, payload.PackageNo, payload.StatusFlag].join(';');
  payload.HashKey = crypto.createHmac('sha256', ticket).update(signed).digest('hex').toUpperCase();
  const response = await request.post('/wc-api/epay_paycenter/', { form: payload, maxRedirects: 0 });
  expect(response.status()).toBe(302);
  expect(await events(request, context.reference)).toContain('callback_completed');
  const output = JSON.stringify(await logs(request, context.reference));
  expect(output).toContain('Authenticated Paycenter callback received');
  expect(output).not.toContain(payload.HashKey);
  expect(output).not.toContain(ticket);
  expect((await request.get(response.headers().location)).ok()).toBe(true);
  expect(await events(request, `"order_id":${order.order_id}`)).toContain('thankyou_requested');
  const stored = await (await request.get(`/wp-json/epay-test/v1/order/${order.order_id}`)).json();
  expect(['processing', 'completed']).toContain(stored.status);
  expect(stored.epay.payment_complete_count).toBe(1);
});

test('@diagnostics distinguishes customer cancellation and authenticated checkout return', async ({ request }) => {
  const order = await createOrder(request);
  const response = await request.get(order.receipt_url);
  const html = await response.text();
  const backlink = html.match(/name="ParamBackLink"\s+value="([^"]+)"/)[1].replaceAll('&amp;', '&');
  const reference = html.match(/name="MerchantReference"\s+value="([^"]+)"/)[1];
  const result = await request.get('/wc-api/epay_paycenter/?' + backlink);
  expect(result.ok()).toBe(true);
  expect(await events(request, reference)).toContain('customer_cancelled');
  expect(await events(request, `"order_id":${order.order_id}`)).toContain('checkout_return_requested');
  expect((await (await request.get(`/wp-json/epay-test/v1/order/${order.order_id}`)).json()).status).toBe('cancelled');
});

test('@diagnostics preserves actual scheduler errors and identifies automatic stock cancellation', async ({ request }) => {
  const order = await createOrder(request);
  await receipt(request, order);
  await enableRecovery(request, order);
  const failureHeaders = { 'X-Epay-Test-Recovery-Scenario': 'diagnostic-schedule-error' };
  const earlierErrors = (await logs(request, 'schedule')).length;
  await request.get(order.receipt_url, { headers: failureHeaders });
  const scheduler = (await logs(request, 'schedule')).slice(earlierErrors).filter(entry => entry.message.includes('epay_test_schedule_failed'));
  expect(scheduler.some(entry => entry.message.includes('epay_paycenter_reconcile'))).toBe(true);
  expect(scheduler.some(entry => entry.message.includes('epay_paycenter_release_stock'))).toBe(true);
  expect(scheduler.every(entry => entry.level === 'error' && entry.message.includes('scheduled_at'))).toBe(true);
  expect(JSON.stringify(scheduler)).not.toContain('schedule-secret');
  expect((await fixture(request, `diagnostics/release-stock/${order.order_id}`)).status).toBe('cancelled');
  expect(await events(request, `"order_id":${order.order_id}`)).toContain('stock_reservation_expired');
});

test('@diagnostics reporting works for an authenticated WordPress session', async ({ page, request }) => {
  await page.request.get('/wp-login.php');
  const login = await page.request.post('/wp-login.php', {
    form: { log: 'localadmin', pwd: 'localadmin123', testcookie: '1' }, maxRedirects: 0,
  });
  expect(login.status()).toBe(302);
  const order = await createOrder(request);
  const context = await receipt(page.request, order);
  expect((await sendReport(page.request, context)).status()).toBe(202);
  expect(await events(request, context.reference)).toContain('script_started');
});

test('@diagnostics missing forms are reported without attempting bank submission', async ({ page, request }) => {
  const order = await createOrder(request);
  await page.route(order.receipt_url, async route => {
    const response = await route.fetch();
    await route.fulfill({ response, body: (await response.text()).replace('id="epay-paycenter-form"', 'id="missing-payment-form"') });
  });
  await page.goto(order.receipt_url);
  const context = JSON.parse(await page.locator('[data-epay-diagnostics]').getAttribute('data-epay-diagnostics'));
  await expect.poll(() => events(request, context.reference)).toContain('form_missing');
  expect(await events(request, context.reference)).not.toContain('submission_attempted');
});

test('@diagnostics submission exceptions and script errors retain bounded context', async ({ page, request }) => {
  const order = await createOrder(request);
  const sentDetails = [];
  await page.route('**/wp-admin/admin-ajax.php', async route => {
    const form = new URLSearchParams(route.request().postData());
    if (form.get('action') === 'epay_paycenter_diagnostic') sentDetails.push(JSON.parse(form.get('payload')).details);
    await route.continue();
  });
  await page.addInitScript(() => {
    HTMLFormElement.prototype.submit = function () { throw new TypeError('submit blocked password=browser-secret "password":"quoted-browser-secret" 4111111111111111'); };
  });
  await page.goto(order.receipt_url);
  const context = JSON.parse(await page.locator('[data-epay-diagnostics]').getAttribute('data-epay-diagnostics'));
  await expect.poll(() => events(request, context.reference)).toContain('submission_exception');
  await page.evaluate(() => {
    window.dispatchEvent(new ErrorEvent('error', { message: 'failed?token=browser-query', filename: location.origin + '/assets/broken.js?key=script-query', lineno: 12, colno: 3 }));
    const rejected = Promise.resolve();
    window.dispatchEvent(new PromiseRejectionEvent('unhandledrejection', { promise: rejected, reason: new Error('rejected token=rejection-secret') }));
  });
  await expect.poll(() => events(request, context.reference)).toEqual(expect.arrayContaining(['javascript_error', 'unhandled_rejection']));
  const browser = observations(await logs(request, context.reference)).filter(entry => entry.origin === 'browser');
  expect(browser.some(entry => entry.details.script_path === '/assets/broken.js' && entry.details.line === 12)).toBe(true);
  expect(JSON.stringify(browser)).not.toMatch(/browser-secret|browser-query|script-query|rejection-secret|4111111111111111/);
  expect(JSON.stringify(sentDetails)).not.toMatch(/browser-secret|browser-query|script-query|rejection-secret|4111111111111111/);
  expect((await (await request.get(`/wp-json/epay-test/v1/order/${order.order_id}`)).json()).status).toBe('pending');
});

test('@diagnostics a still-visible receipt and a restored document are observations, not payment failures', async ({ page, request }) => {
  const order = await createOrder(request);
  await page.clock.install();
  await page.addInitScript(() => { HTMLFormElement.prototype.submit = function () {}; });
  await page.goto(order.receipt_url);
  const context = JSON.parse(await page.locator('[data-epay-diagnostics]').getAttribute('data-epay-diagnostics'));
  await page.clock.runFor(150);
  await expect.poll(() => events(request, context.reference)).toContain('submission_attempted');
  await page.clock.runFor(10000);
  await expect.poll(() => events(request, context.reference)).toContain('still_visible_after_submit');
  await page.evaluate(() => {
    window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
    window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
  });
  await expect.poll(() => events(request, context.reference)).toContain('page_restored');
  const observed = await events(request, context.reference);
  expect(observed.filter(event => event === 'submission_attempted')).toHaveLength(1);
  expect(observed.filter(event => event === 'still_visible_after_submit')).toHaveLength(1);
  expect(observed).toContain('page_hidden');
  expect((await (await request.get(`/wp-json/epay-test/v1/order/${order.order_id}`)).json()).status).toBe('pending');
});

for (const transport of ['beacon-unavailable', 'beacon-not-queued', 'beacon-throws', 'fetch-rejects', 'receiver-rejects']) {
  test(`@diagnostics @matrix ${transport} cannot block or duplicate the bank submission`, async ({ page, request, fakePaycenter }) => {
    const order = await createOrder(request);
    await page.addInitScript(mode => {
      if (mode === 'beacon-unavailable') Object.defineProperty(navigator, 'sendBeacon', { value: undefined });
      if (mode === 'beacon-not-queued' || mode === 'fetch-rejects') navigator.sendBeacon = () => false;
      if (mode === 'beacon-throws') navigator.sendBeacon = () => { throw new Error('Blocked diagnostics'); };
      if (mode === 'fetch-rejects') window.fetch = () => Promise.reject(new Error('Offline diagnostics'));
    }, transport);
    let reports = 0;
    page.on('request', incoming => {
      if (new URL(incoming.url()).pathname === '/wp-admin/admin-ajax.php') reports++;
    });
    if (transport === 'receiver-rejects') {
      await page.route('**/wp-admin/admin-ajax.php', route => route.fulfill({ status: 503, body: 'Unavailable' }));
    }
    await page.goto(order.receipt_url);
    await expect(page.getByRole('heading', { name: 'Fake Paycenter' })).toBeVisible();
    expect(fakePaycenter.submissions).toHaveLength(1);
    const handoff = fakePaycenter.submissions[0];
    expect(handoff.has('authorization')).toBe(false);
    if (['beacon-unavailable', 'beacon-not-queued'].includes(transport)) {
      await expect.poll(() => events(request, handoff.get('MerchantReference'))).toContain('submission_attempted');
    }
    expect(reports).toBeLessThanOrEqual(20);
    expect((await (await request.get(`/wp-json/epay-test/v1/order/${order.order_id}`)).json()).status).toBe('pending');
  });
}

test('@diagnostics disabled debugging omits reporting context and traffic without changing handoff', async ({ page, request }) => {
  const order = await createOrder(request);
  const context = await receipt(request, order);
  await page.context().setExtraHTTPHeaders({ 'X-Epay-Test': 'epay-qualification', 'X-Epay-Test-Diagnostics': '1', 'X-Epay-Test-Diagnostics-Debug': 'no' });
  let receiptHtml;
  await page.route(order.receipt_url, async route => {
    const response = await route.fetch();
    receiptHtml = await response.text();
    await route.fulfill({ response });
  });
  let reports = 0;
  await page.route('**/wp-admin/admin-ajax.php', async route => { reports++; await route.continue(); });
  await page.route('https://paycenter.piraeusbank.gr/**', route => route.fulfill({ contentType: 'text/html', body: '<h1>Fake Paycenter</h1>' }));
  await page.goto(order.receipt_url);
  await expect(page.getByRole('heading', { name: 'Fake Paycenter' })).toBeVisible();
  expect(receiptHtml).not.toContain('data-epay-diagnostics');
  expect(reports).toBe(0);
  expect((await sendReport(page.request, context)).status()).toBe(404);
});

test('@diagnostics server logger failure does not interrupt ticket issuance or bank submission', async ({ page, request }) => {
  const order = await createOrder(request);
  await page.context().setExtraHTTPHeaders({
    'X-Epay-Test': 'epay-qualification', 'X-Epay-Test-Diagnostics': '1', 'X-Epay-Test-Diagnostics-Debug': 'yes', 'X-Epay-Test-Logger-Failure': '1',
  });
  let submissions = 0;
  await page.route('https://paycenter.piraeusbank.gr/**', async route => {
    submissions++;
    await route.fulfill({ contentType: 'text/html', body: '<h1>Fake Paycenter</h1>' });
  });
  await page.goto(order.receipt_url);
  await expect(page.getByRole('heading', { name: 'Fake Paycenter' })).toBeVisible();
  expect(submissions).toBe(1);
  const stored = await (await request.get(`/wp-json/epay-test/v1/order/${order.order_id}`)).json();
  expect(stored.status).toBe('pending');
  expect(stored.epay.ticket_rows).toBe(1);
});
