const crypto = require('node:crypto');
const { test, expect } = require('@playwright/test');

const POS_ID = process.env.EPAY_TEST_POS_ID || '99999999';
const ACQUIRER_ID = process.env.EPAY_TEST_ACQUIRER_ID || '14';
const PRODUCT_ID = Number(process.env.EPAY_TEST_PRODUCT_ID || 0);
const CANONICAL_CALLBACK = '/wc-api/epay_paycenter/';
const LEGACY_CALLBACK = '/wc-api/WC_Piraeusbank_Gateway';

function decodeHtml(value) {
  return value
    .replaceAll('&amp;', '&')
    .replaceAll('&quot;', '"')
    .replaceAll('&#039;', "'")
    .replaceAll('&lt;', '<')
    .replaceAll('&gt;', '>');
}

function redirectFields(html) {
  const form = html.match(/<form[^>]+id=["']epay-paycenter-form["'][\s\S]*?<\/form>/i)?.[0] || '';
  const fields = {};
  for (const input of form.matchAll(/<input\b[^>]*>/gi)) {
    const name = input[0].match(/\bname=["']([^"']*)["']/i)?.[1];
    const value = input[0].match(/\bvalue=["']([^"']*)["']/i)?.[1] || '';
    if (name) fields[decodeHtml(name)] = decodeHtml(value);
  }
  return fields;
}

function fakeTicket(merchantReference) {
  return `TST${crypto.createHash('sha256').update(merchantReference).digest('hex').slice(0, 29)}`;
}

function callbackPayload(orderId, merchantReference, overrides = {}) {
  const payload = {
    ResultCode: '0',
    ResultDescription: 'Approved',
    MerchantReference: merchantReference,
    ResponseCode: '0',
    ResponseDescription: 'Approved',
    StatusFlag: 'Success',
    SupportReferenceID: `8${orderId}`,
    ApprovalCode: 'TST123',
    Parameters: `wc_order_id=${orderId}`,
    TransactionId: `9${orderId}`,
    AuthStatus: '',
    PackageNo: '1',
    PaymentMethod: 'Card',
    CardType: 'VISA',
    ...overrides,
  };
  const ticket = fakeTicket(merchantReference);
  const signed = [
    ticket,
    POS_ID,
    ACQUIRER_ID,
    payload.MerchantReference,
    payload.ApprovalCode,
    payload.Parameters,
    payload.ResponseCode,
    payload.SupportReferenceID,
    payload.AuthStatus,
    payload.PackageNo,
    payload.StatusFlag,
  ].join(';');
	payload.HashKey = Object.prototype.hasOwnProperty.call(overrides, 'HashKey')
	  ? overrides.HashKey
	  : crypto.createHmac('sha256', ticket).update(signed).digest('hex').toUpperCase();
  return payload;
}

async function setFixture(request, path, data) {
  const response = await request.post(`/wp-json/epay-test/v1/${path}`, { data });
  expect(response.ok(), await response.text()).toBeTruthy();
  return response.json();
}

async function createOrder(request, { shippingMethod = 'flat_rate', ...extra } = {}) {
  const response = await request.post('/wp-json/epay-test/v1/create-order', {
    data: {
      product_id: PRODUCT_ID,
      shipping_method: shippingMethod,
      ...extra,
    },
  });
  expect(response.ok(), await response.text()).toBeTruthy();
  return response.json();
}

async function issueAttempt(request, order, headers = {}) {
  const response = await request.get(order.receipt_url, { headers });
  const html = await response.text();
  expect(response.ok(), html.slice(0, 500)).toBeTruthy();
  const fields = redirectFields(html);
  expect(fields.MerchantReference).toMatch(new RegExp(`^${order.order_id}-[A-Z0-9]{12}$`));
  expect(fields.ParamBackLink).toContain(`order_id=${order.order_id}`);
  expect(fields).not.toHaveProperty('TranTicket');
  return fields;
}

async function readOrder(request, orderId) {
  const response = await request.get(`/wp-json/epay-test/v1/order/${orderId}`);
  expect(response.ok(), await response.text()).toBeTruthy();
  return response.json();
}

async function loginAsLocalAdmin(page) {
  await page.goto('/wp-login.php');
  await page.locator('#user_login').fill('localadmin');
  await page.locator('#user_pass').fill('localadmin123');
  await page.locator('#wp-submit').click();
  await page.waitForURL(/\/wp-admin\//);
}

async function sendCallback(request, path, payload) {
  return request.post(path, { form: payload, maxRedirects: 0 });
}

test.beforeEach(async ({ request }) => {
  await setFixture(request, 'legacy-plugin', { active: false });
  await setFixture(request, 'fake-epay-result', { result_code: '0' });
  await setFixture(request, 'fake-epay-barrier', { target: 0 });
  await setFixture(request, 'fake-waf-result', { scenario: 'passthrough' });
  await setFixture(request, 'follow-up/reset', {});
  await setFixture(request, 'callback-ip-policy', { allowed: [], trusted: [] });
});

test.afterEach(async ({ request }) => {
  await setFixture(request, 'legacy-plugin', { active: false });
  await setFixture(request, 'fake-epay-barrier', { target: 0 });
  await setFixture(request, 'fake-waf-result', { scenario: 'passthrough' });
  await setFixture(request, 'callback-ip-policy', { allowed: [], trusted: [] });
});

async function verifyAndEnableFollowUp(request, order, channel = 'eCommerce') {
  await setFixture(request, 'fake-follow-up', { scenario: 'paid', channel });
  const verification = await setFixture(request, `follow-up/test-channel/${order.order_id}`, {});
  expect(verification.success).toBe(true);
  expect(verification.channel).toBe(channel);
  const enabled = await setFixture(request, 'follow-up/enable', {});
  expect(enabled.follow_up_enabled).toBe(true);
  expect(enabled.follow_up_channel).toBe(channel);
  return verification;
}

test('@smoke downstream and local Paycenter boundary are active', async ({ request }) => {
  const response = await request.get('/wp-json/epay-test/v1/health');
  expect(response.ok(), await response.text()).toBeTruthy();
  const health = await response.json();

  expect(health).toMatchObject({
    local_only: true,
    fake_epay_available: true,
    epay_version: '2.0.0',
    epay_enabled: true,
    epay_mode: 'test',
    epay_password_storage: 'md5-digest',
    legacy_papaki_active: false,
  });
});

test('@smoke @locale bundled Greek translations load for the selected request only', async ({ request }) => {
  const before = await request.get('/wp-json/epay-test/v1/locale');
  expect(before.ok(), await before.text()).toBeTruthy();
  const defaultLocale = await before.json();

  const translated = await request.get('/wp-json/epay-test/v1/locale', {
    headers: { 'X-Epay-Test-Locale': 'el' },
  });
  expect(translated.ok(), await translated.text()).toBeTruthy();
  expect(await translated.json()).toEqual({
    locale: 'el',
    not_verified: 'Δεν έχει επαληθευτεί',
  });

  const after = await request.get('/wp-json/epay-test/v1/locale');
  expect(after.ok(), await after.text()).toBeTruthy();
  expect(await after.json()).toEqual(defaultLocale);
});

test('@smoke gateway is text-only unless a valid custom icon URL is supplied', async ({ request }) => {
  try {
    expect(await setFixture(request, 'gateway-icon', { action: 'clear' })).toEqual({ icon_html: '' });
    expect(
      await setFixture(request, 'gateway-icon', { action: 'set', url: 'javascript:alert(1)' }),
    ).toEqual({ icon_html: '' });

    const custom = await setFixture(request, 'gateway-icon', {
      action: 'set',
      url: 'https://example.com/custom-payment-icon.svg',
    });
    expect(custom.icon_html).toContain('src="https://example.com/custom-payment-icon.svg"');
  } finally {
    await setFixture(request, 'gateway-icon', { action: 'clear' });
  }
});

test('@callback canonical card success verifies and clears all one-time secrets', async ({ request }) => {
  const order = await createOrder(request);
  const attempt = await issueAttempt(request, order);

  await sendCallback(request, CANONICAL_CALLBACK, callbackPayload(order.order_id, attempt.MerchantReference));

  const stored = await readOrder(request, order.order_id);
  expect(['processing', 'completed']).toContain(stored.status);
  expect(stored.epay).toMatchObject({
    ticket_rows: 1,
    open_ticket_count: 0,
    has_open_ticket_secrets: false,
    has_legacy_tran_ticket: false,
    has_legacy_cancel_token: false,
    has_transaction_id: true,
    has_callback_transaction_id: true,
    payment_method: 'Card',
  });
  expect(stored.epay.ticket_statuses).toEqual({
    [attempt.MerchantReference]: 'succeeded',
  });
});

test('@diagnostic actual callback handler identifies its checkout redirect', async ({ request }) => {
  const response = await sendCallback(request, CANONICAL_CALLBACK, {
    MerchantReference: 'WAFTEST-NO-ORDER',
    ResultCode: '981',
    ResponseCode: '0',
    StatusFlag: 'Failure',
    Parameters: 'wc_order_id=0',
  });

  expect(response.status()).toBe(302);
  expect(response.headers()['x-epay-paycenter-handler']).toBe('1');
  expect(new URL(response.headers().location).pathname).toBe('/checkout/');
});

test('@diagnostic AJAX self-test requires the handler marker and exact checkout redirect', async ({ page, request }) => {
  await loginAsLocalAdmin(page);
  await page.goto('/wp-admin/admin.php?page=wc-settings&tab=checkout&section=epay_paycenter');
  const button = page.locator('#epay-waftest-run');
  const result = page.locator('#epay-waftest-result');

  for (const scenario of ['handler_redirect', 'html_200', 'missing_marker', 'wrong_location']) {
    await test.step(scenario, async () => {
      await setFixture(request, 'fake-waf-result', { scenario });
      const responsePromise = page.waitForResponse((response) =>
        response.url().includes('/wp-admin/admin-ajax.php') &&
        response.request().postData()?.includes('action=epay_paycenter_waf_test'),
      );
      await button.click();
      const response = await responsePromise;
      expect(response.ok(), await response.text()).toBeTruthy();
      const payload = await response.json();
      expect(payload.success).toBe(true);

      if (scenario === 'handler_redirect') {
        expect(payload.data).toMatchObject({ verdict: 'pass', status: 302 });
        await expect(result).toContainText('Callback handler reached. This does not verify delivery from the bank.');
        await expect(result).toHaveClass(/--pass\b/);
      } else {
        expect(payload.data).toMatchObject({ verdict: 'unexpected_response' });
        expect(payload.data.status).toBe(scenario === 'html_200' ? 200 : 302);
        await expect(result).toContainText('The callback handler could not be verified.');
        await expect(result).toHaveClass(/--error\b/);
        await expect(result).not.toHaveClass(/--pass\b/);
      }
      await expect(button).toBeEnabled();
    });
  }
});

for (const automaticRecovery of [false, true]) {
  test(`@callback @iris-pending signed IRIS09 gives accurate buyer guidance with recovery ${automaticRecovery ? 'enabled' : 'disabled'}`, async ({ page, request }) => {
    const order = await createOrder(request);
    const attempt = await issueAttempt(request, order);
    if (automaticRecovery) {
      await verifyAndEnableFollowUp(request, order);
    }

    const response = await sendCallback(request, CANONICAL_CALLBACK, callbackPayload(order.order_id, attempt.MerchantReference, {
      ResponseCode: '09',
      ResponseDescription: 'IRIS payment initiated but not completed',
      StatusFlag: 'Failure',
      PaymentMethod: 'IRIS',
      CardType: '15',
      ApprovalCode: '',
      PackageNo: '',
    }));

    expect(response.status()).toBe(302);
    expect(response.headers().location).toContain(`/order-received/${order.order_id}/`);
    const stored = await readOrder(request, order.order_id);
    expect(stored.status).toBe('on-hold');
    expect(stored.epay.has_open_ticket_secrets).toBe(true);
    expect(stored.epay.ticket_statuses).toEqual({ [attempt.MerchantReference]: 'pending' });

    await page.goto(response.headers().location);
    const expectedNotice = automaticRecovery
      ? 'Your IRIS payment has started but is not yet confirmed. We will check its status automatically. Please do not pay again, or you may be charged twice. Contact us if you do not hear back.'
      : 'Your IRIS payment has started but is not yet confirmed. Please contact us so we can check the payment with ePay. Do not pay again, or you may be charged twice.';
    await expect(page.getByText(expectedNotice, { exact: true })).toBeVisible();
    await expect(page.locator('#place_order')).toHaveCount(0);
    if (!automaticRecovery) {
      await expect(page.getByText('We will check its status automatically.', { exact: false })).toHaveCount(0);
    }
  });
}

test('@callback legacy Papaki URL forwards an authenticated IRIS success', async ({ request }) => {
  const order = await createOrder(request);
  const attempt = await issueAttempt(request, order);
  const payload = callbackPayload(order.order_id, attempt.MerchantReference, {
    PaymentMethod: 'IRIS',
    CardType: '15',
    PackageNo: '',
  });

  await sendCallback(request, LEGACY_CALLBACK, payload);

  const stored = await readOrder(request, order.order_id);
  expect(['processing', 'completed']).toContain(stored.status);
  expect(stored.epay.payment_method).toBe('IRIS');
  expect(stored.epay.ticket_statuses).toEqual({
    [attempt.MerchantReference]: 'succeeded',
  });
});

test('@callback pre-upgrade open-ticket metadata remains verifiable', async ({ request }) => {
  for (const format of ['serialized', 'single']) {
    const order = await createOrder(request);
    const merchantReference = `${order.order_id}-${format === 'serialized' ? 'LEGACYMAP123' : 'LEGACYSINGLE'}`;
    const seeded = await setFixture(request, `legacy-open-ticket/${order.order_id}`, {
      format,
      reference: merchantReference,
      ticket: fakeTicket(merchantReference),
      cancel: `cancel-${format}`,
    });
    expect(seeded.order.epay.open_ticket_count).toBe(1);
    expect(seeded.order.epay.has_open_ticket_secrets).toBe(true);

    await sendCallback(request, CANONICAL_CALLBACK, callbackPayload(order.order_id, merchantReference));

    const stored = await readOrder(request, order.order_id);
    expect(['processing', 'completed']).toContain(stored.status);
    expect(stored.epay.open_ticket_count).toBe(0);
    expect(stored.epay.has_open_ticket_secrets).toBe(false);
  }
});

test('@callback both mixed-case Papaki URL forms dispatch through the normalized hook', async ({ request }) => {
  const compatibility = await setFixture(request, 'legacy-callback-compatibility', {});
  expect(compatibility).toMatchObject({
    normalized_hook_registered: true,
    mixed_case_hook_registered: false,
    admin_notice: '',
  });

  for (const path of [LEGACY_CALLBACK, '/?wc-api=WC_Piraeusbank_Gateway']) {
    const response = await sendCallback(request, path, {});
    expect(response.status(), `${path} did not reach the callback handler`).toBe(302);
    expect(await response.text()).not.toBe('-1');
  }
});

test('@callback administrators are warned when Papaki owns its callback route', async ({ request }) => {
  await setFixture(request, 'legacy-plugin', { active: true });
  const compatibility = await setFixture(request, 'legacy-callback-compatibility', {});
  expect(compatibility.admin_notice).toContain(
    'The Papaki Piraeus Bank gateway is active and owns the WC_Piraeusbank_Gateway callback route.',
  );
});

test('@callback legacy plaintext credentials migrate once without changing bank authentication', async ({ request }) => {
  const password = 'local-fake-password';
  const digest = crypto.createHash('md5').update(password).digest('hex');

  try {
    const seeded = await setFixture(request, 'credential-migration', { action: 'seed' });
    expect(seeded).toEqual({
      db_version: '2.0',
      stored_password: password,
      password_digest: digest,
    });

    const migrated = await setFixture(request, 'credential-migration', { action: 'upgrade' });
    expect(migrated).toEqual({
      db_version: '2.2',
      stored_password: `md5:${digest}`,
      password_digest: digest,
    });

    const repeated = await setFixture(request, 'credential-migration', { action: 'upgrade' });
    expect(repeated).toEqual(migrated);

    // Issuing a ticket exercises the gateway credential path. The local bank
    // fake rejects the request unless it receives the same MD5 digest.
    const order = await createOrder(request);
    const receipt = await setFixture(request, `render-epay-receipt/${order.order_id}`, {});
    expect(receipt.has_redirect_form).toBe(true);
    expect(receipt.merchant_reference).toMatch(new RegExp(`^${order.order_id}-[A-Z0-9]{12}$`));

    // Channel verification exercises the independently constructed FOLLOW_UP
    // credential path against the same strict local bank boundary.
    await setFixture(request, 'fake-follow-up', { scenario: 'paid', channel: 'eCommerce' });
    const verification = await setFixture(request, `follow-up/test-channel/${order.order_id}`, {});
    expect(verification).toMatchObject({ success: true, channel: 'eCommerce' });

    await setFixture(request, 'credential-migration', { action: 'seed-future-version' });
    const future = await setFixture(request, 'credential-migration', { action: 'upgrade' });
    expect(future.db_version).toBe('99.0');
  } finally {
    await setFixture(request, 'credential-migration', { action: 'restore' });
  }
});

test('@callback invalid success HashKey cannot mutate the order', async ({ request }) => {
  const order = await createOrder(request);
  const attempt = await issueAttempt(request, order);
  const payload = callbackPayload(order.order_id, attempt.MerchantReference, { HashKey: 'BAD_HASH' });

  await sendCallback(request, CANONICAL_CALLBACK, payload);

  const stored = await readOrder(request, order.order_id);
  expect(stored.status).toBe('pending');
  expect(stored.epay.has_transaction_id).toBe(false);
  expect(stored.epay.has_callback_transaction_id).toBe(false);
  expect(stored.epay.result_code).toBe('');
  expect(stored.epay.payment_method).toBe('');
  expect(stored.epay.has_open_ticket_secrets).toBe(true);
  expect(stored.epay.ticket_statuses).toEqual({
    [attempt.MerchantReference]: 'pending',
  });
});

test('@callback unsigned decline cannot mutate the order', async ({ request }) => {
  const order = await createOrder(request);
  const attempt = await issueAttempt(request, order);
  const payload = callbackPayload(order.order_id, attempt.MerchantReference, {
    ResultCode: '100',
    ResultDescription: 'Declined',
    ResponseCode: '05',
    ResponseDescription: 'Do not honor',
    StatusFlag: 'Failure',
    HashKey: '',
  });

  await sendCallback(request, CANONICAL_CALLBACK, payload);

  const stored = await readOrder(request, order.order_id);
  expect(stored.status).toBe('pending');
  expect(stored.epay.result_code).toBe('');
  expect(stored.epay.payment_method).toBe('');
  expect(stored.epay.has_transaction_id).toBe(false);
  expect(stored.epay.ticket_statuses).toEqual({
    [attempt.MerchantReference]: 'pending',
  });
});

test('@callback invalid signed decline leaves order and ticket pending', async ({ request }) => {
  const order = await createOrder(request);
  const attempt = await issueAttempt(request, order);
  const payload = callbackPayload(order.order_id, attempt.MerchantReference, {
    ResultCode: '981',
    ResultDescription: 'Incorrect card details',
    ResponseCode: '05',
    ResponseDescription: 'Do not honor',
    StatusFlag: 'Failure',
    HashKey: 'F'.repeat(64),
  });

  await sendCallback(request, CANONICAL_CALLBACK, payload);

  const stored = await readOrder(request, order.order_id);
  expect(stored.status).toBe('pending');
  expect(stored.epay.ticket_statuses).toEqual({
    [attempt.MerchantReference]: 'pending',
  });
});

test('@callback forwarded IP is trusted only from an explicitly configured proxy', async ({ request }) => {
  const order = await createOrder(request);
  const attempt = await issueAttempt(request, order);
  const forwardedIp = '203.0.113.42';

  const policy = await setFixture(request, 'callback-ip-policy', {
    allowed: [forwardedIp],
    trusted: [],
  });
  await request.post(CANONICAL_CALLBACK, {
    form: callbackPayload(order.order_id, attempt.MerchantReference),
    headers: { 'CF-Connecting-IP': forwardedIp },
    maxRedirects: 0,
  });
  expect((await readOrder(request, order.order_id)).status).toBe('pending');

  await setFixture(request, 'callback-ip-policy', {
    allowed: [forwardedIp],
    trusted: [policy.remote_addr],
  });
  await request.post(CANONICAL_CALLBACK, {
    form: callbackPayload(order.order_id, attempt.MerchantReference),
    headers: { 'CF-Connecting-IP': forwardedIp },
    maxRedirects: 0,
  });
  expect(['processing', 'completed']).toContain((await readOrder(request, order.order_id)).status);
});

test('@callback approved payment remains retryable when WooCommerce persistence fails', async ({ request }) => {
  const verificationOrder = await createOrder(request);
  await issueAttempt(request, verificationOrder);
  await verifyAndEnableFollowUp(request, verificationOrder);

  const order = await createOrder(request);
  const attempt = await issueAttempt(request, order);
  await setFixture(request, 'follow-up/fail-next-payment-complete', {});
  await sendCallback(request, CANONICAL_CALLBACK, callbackPayload(order.order_id, attempt.MerchantReference));

  const pending = await readOrder(request, order.order_id);
  expect(pending.status).toBe('pending');
  expect(pending.epay.has_open_ticket_secrets).toBe(true);
  expect(pending.epay.ticket_statuses).toEqual({ [attempt.MerchantReference]: 'pending' });

  await setFixture(request, 'fake-follow-up', { scenario: 'paid', channel: 'eCommerce' });
  const recovered = await setFixture(request, `follow-up/run/${order.order_id}`, {});
  expect(['processing', 'completed']).toContain(recovered.order.status);
  expect(recovered.order.epay.has_open_ticket_secrets).toBe(false);
  expect(recovered.order.epay.ticket_statuses).toEqual({ [attempt.MerchantReference]: 'succeeded' });
});

test('@callback @followup @settlement-retry persisted reference cannot prevent recovery of an unpaid order', async ({ request }) => {
  const verificationOrder = await createOrder(request);
  await issueAttempt(request, verificationOrder);
  await verifyAndEnableFollowUp(request, verificationOrder);

  const order = await createOrder(request);
  const attempt = await issueAttempt(request, order);
  await setFixture(request, 'follow-up/fail-next-payment-complete', { persist_reference: true });
  await sendCallback(request, CANONICAL_CALLBACK, callbackPayload(order.order_id, attempt.MerchantReference));

  const pending = await readOrder(request, order.order_id);
  expect(pending.status).toBe('pending');
  expect(pending.epay).toMatchObject({
    settled_reference: attempt.MerchantReference,
    payment_complete_count: 0,
    has_open_ticket_secrets: true,
    ticket_statuses: { [attempt.MerchantReference]: 'pending' },
  });

  await setFixture(request, 'fake-follow-up', { scenario: 'paid', channel: 'eCommerce' });
  const recovered = await setFixture(request, `follow-up/run/${order.order_id}`, {});
  expect(['processing', 'completed']).toContain(recovered.order.status);
  expect(recovered.order.epay).toMatchObject({
    has_transaction_id: true,
    has_open_ticket_secrets: false,
    payment_complete_count: 1,
    ticket_statuses: { [attempt.MerchantReference]: 'succeeded' },
    follow_up: { [attempt.MerchantReference]: { state: 'paid' } },
  });
  expect(recovered.result.unresolved).toBe(0);

  const repeated = await setFixture(request, `follow-up/run/${order.order_id}`, {});
  expect(repeated.order.status).toBe(recovered.order.status);
  expect(repeated.order.epay.payment_complete_count).toBe(1);
  expect(repeated.requests).toEqual(recovered.requests);
});

test('@callback callback replay and later failure cannot change a paid order', async ({ request }) => {
  const order = await createOrder(request);
  const attempt = await issueAttempt(request, order);
  const success = callbackPayload(order.order_id, attempt.MerchantReference);

  await sendCallback(request, CANONICAL_CALLBACK, success);
  const paid = await readOrder(request, order.order_id);
  await sendCallback(request, CANONICAL_CALLBACK, success);
  await sendCallback(request, CANONICAL_CALLBACK, { ...success, ResultCode: '1048', StatusFlag: 'Failure', HashKey: '' });

  const replayed = await readOrder(request, order.order_id);
  expect(replayed.status).toBe(paid.status);
  expect(replayed.epay.has_transaction_id).toBe(true);
  expect(replayed.epay.open_ticket_count).toBe(0);
  expect(replayed.epay.ticket_statuses).toEqual({
    [attempt.MerchantReference]: 'succeeded',
  });
  expect(replayed.epay.has_recharge_attempt).toBe(false);
});

test('@callback canonical and legacy cancel require the issued one-time token', async ({ request }) => {
  const rejectedOrder = await createOrder(request);
  const rejectedAttempt = await issueAttempt(request, rejectedOrder);
  await sendCallback(request, LEGACY_CALLBACK, {
    peiraeus: 'cancel',
    order_id: String(rejectedOrder.order_id),
    token: 'wrong-token',
  });
  expect((await readOrder(request, rejectedOrder.order_id)).status).toBe('pending');

  const acceptedOrder = await createOrder(request);
  const acceptedAttempt = await issueAttempt(request, acceptedOrder);
  const backlink = new URLSearchParams(acceptedAttempt.ParamBackLink);
  await sendCallback(request, LEGACY_CALLBACK, {
    peiraeus: 'cancel',
    order_id: backlink.get('order_id'),
    token: backlink.get('token'),
  });
  const cancelled = await readOrder(request, acceptedOrder.order_id);
  expect(cancelled.status).toBe('cancelled');
  expect(cancelled.epay.ticket_statuses).toEqual({
    [acceptedAttempt.MerchantReference]: 'cancelled',
  });
  expect(rejectedAttempt.ParamBackLink).toContain('epp_action=cancel');
});

for (const layout of ['classic', 'blocks']) {
  for (const lostSession of [false, true]) {
    test(`@callback @cancel-return ${layout} cancellation returns once to a usable cart, lost session ${lostSession}`, async ({ page, request, context }) => {
      const fixture = await setFixture(request, 'checkout-layout', { layout });
      try {
        await page.goto(fixture.add_to_cart_url);
        const order = await createOrder(request);
        const attempt = await issueAttempt(request, order);
        const backlink = new URLSearchParams(attempt.ParamBackLink);
        const response = await sendCallback(page.request, LEGACY_CALLBACK, {
          peiraeus: 'cancel', order_id: String(order.order_id), token: backlink.get('token'),
        });
        expect(response.status()).toBe(302);
        const forgedReturn = new URL(response.headers().location);
        forgedReturn.searchParams.set('key', 'not-the-order-key');
        const forged = await request.get(forgedReturn.href);
        expect(await forged.text()).not.toContain('You cancelled the payment process.');
        if (lostSession) await context.clearCookies();
        await page.goto(response.headers().location);
        expect(page.url()).not.toContain('order-pay');
        await expect(page.getByText(/You cancelled the payment process\./)).toHaveCount(1);
        await expect(page.getByText(/it cannot be paid for/i)).toHaveCount(0);
        await expect(page.getByText(/If your bank shows a charge, contact us before paying again\./)).toBeVisible();
        if (!lostSession) {
          await expect(page.locator(layout === 'classic' ? 'form.checkout' : '.wp-block-woocommerce-checkout')).toBeVisible();
        }
        if (process.env.EPAY_TEST_CAPTURE_UI) await page.screenshot({ path: test.info().outputPath('cancel-return.png'), fullPage: true });
        await page.reload();
        await expect(page.getByText(/You cancelled the payment process\./)).toHaveCount(0);
        expect((await readOrder(request, order.order_id)).status).toBe('cancelled');
      } finally {
        await setFixture(request, 'checkout-layout', { layout: 'restore' });
      }
    });
  }
}

test('@locale Greek cancellation guidance is delivered once', async ({ page, request }) => {
  const fixture = await setFixture(request, 'checkout-layout', { layout: 'classic' });
  try {
    await page.goto(fixture.add_to_cart_url);
    const order = await createOrder(request);
    const attempt = await issueAttempt(request, order);
    const response = await page.request.post(LEGACY_CALLBACK, {
      form: { peiraeus: 'cancel', order_id: String(order.order_id), token: new URLSearchParams(attempt.ParamBackLink).get('token') },
      headers: { 'X-Epay-Test-Locale': 'el' }, maxRedirects: 0,
    });
    await page.goto(response.headers().location);
    await expect(page.getByText('Ακυρώσατε τη διαδικασία πληρωμής. Αν βλέπετε χρέωση στην τράπεζά σας, επικοινωνήστε μαζί μας πριν πληρώσετε ξανά.', { exact: true })).toHaveCount(1);
  } finally {
    await setFixture(request, 'checkout-layout', { layout: 'restore' });
  }
});

test('@callback cancelling an earlier attempt expires its remaining siblings', async ({ request }) => {
  const order = await createOrder(request);
  const earlier = await issueAttempt(request, order);
  const later = await issueAttempt(request, order);
  const backlink = new URLSearchParams(earlier.ParamBackLink);

  await sendCallback(request, CANONICAL_CALLBACK, {
    epp_action: 'cancel',
    order_id: backlink.get('order_id'),
    token: backlink.get('token'),
  });

  const cancelled = await readOrder(request, order.order_id);
  expect(cancelled.status).toBe('cancelled');
  expect(cancelled.epay.ticket_statuses).toEqual({
    [earlier.MerchantReference]: 'cancelled',
    [later.MerchantReference]: 'expired',
  });
});

test('@callback legacy endpoint yields while the Papaki plugin is active', async ({ request }) => {
  const order = await createOrder(request);
  const attempt = await issueAttempt(request, order);
  const payload = callbackPayload(order.order_id, attempt.MerchantReference);
  await setFixture(request, 'legacy-plugin', { active: true });

  await sendCallback(request, `${LEGACY_CALLBACK}?epay-test-owner-check=1`, payload);

  expect((await readOrder(request, order.order_id)).status).toBe('pending');
});

test('@attempt concurrent receipt renders preserve both attempts and accept the earlier callback', async ({ request }) => {
  const order = await createOrder(request);
  await setFixture(request, 'fake-epay-barrier', { target: 2 });

  const [earlier, later] = await Promise.all([
    issueAttempt(request, order, { 'X-Epay-Test-Barrier-Role': 'earlier' }),
    issueAttempt(request, order, { 'X-Epay-Test-Barrier-Role': 'retained' }),
  ]);
  await setFixture(request, 'fake-epay-barrier', { target: 0 });

  expect(earlier.MerchantReference).not.toBe(later.MerchantReference);
  const issued = await readOrder(request, order.order_id);
  expect(issued.epay.ticket_rows).toBe(2);
  expect(issued.epay.open_ticket_count).toBe(2);

  await sendCallback(request, CANONICAL_CALLBACK, callbackPayload(order.order_id, earlier.MerchantReference));
  const stored = await readOrder(request, order.order_id);
  expect(['processing', 'completed']).toContain(stored.status);
  expect(stored.epay.open_ticket_count).toBe(0);
  expect(stored.epay.has_open_ticket_secrets).toBe(false);
  expect(stored.epay.ticket_statuses).toEqual({
    [earlier.MerchantReference]: 'succeeded',
    [later.MerchantReference]: 'superseded',
  });
});

test('@attempt an earlier signed decline remains auditable before a later attempt succeeds', async ({ request }) => {
  const order = await createOrder(request);
  const earlier = await issueAttempt(request, order);
  const later = await issueAttempt(request, order);
  const decline = callbackPayload(order.order_id, earlier.MerchantReference, {
    ResultCode: '981',
    ResultDescription: 'Incorrect card details',
    ResponseCode: '05',
    ResponseDescription: 'Do not honor',
    StatusFlag: 'Failure',
  });

  await sendCallback(request, CANONICAL_CALLBACK, decline);
  const declined = await readOrder(request, order.order_id);
  expect(declined.status).toBe('failed');
  expect(declined.epay.ticket_statuses).toEqual({
    [earlier.MerchantReference]: 'failed',
    [later.MerchantReference]: 'pending',
  });

  await sendCallback(request, CANONICAL_CALLBACK, callbackPayload(order.order_id, later.MerchantReference));
  const paid = await readOrder(request, order.order_id);
  expect(['processing', 'completed']).toContain(paid.status);
  expect(paid.epay.ticket_statuses).toEqual({
    [earlier.MerchantReference]: 'failed',
    [later.MerchantReference]: 'succeeded',
  });
});

test('@attempt open secrets stay bounded while every issued ticket remains auditable', async ({ request }) => {
  const order = await createOrder(request);
  for (let index = 0; index < 6; index += 1) {
    await issueAttempt(request, order);
  }

  const stored = await readOrder(request, order.order_id);
  expect(stored.epay.ticket_rows).toBe(6);
  expect(stored.epay.open_ticket_count).toBe(5);
  expect(Object.values(stored.epay.ticket_statuses)).toEqual(Array(6).fill('pending'));
});

test('@attempt ticket issuance failure creates no misleading audit row', async ({ request }) => {
	for (const resultCode of ['999', 'MALFORMED', 'OVERSIZED', 'THROW']) {
	  await setFixture(request, 'fake-epay-result', { result_code: resultCode });
	  try {
	    const order = await createOrder(request);
	    const receipt = await request.get(order.receipt_url);
	    expect(receipt.ok(), await receipt.text()).toBeTruthy();

    const stored = await readOrder(request, order.order_id);
    expect(stored.status).toBe('failed');
    expect(stored.epay.ticket_rows).toBe(0);
    expect(stored.epay.ticket_statuses).toEqual({});
    expect(stored.epay.has_open_ticket_secrets).toBe(false);
	  } finally {
	    await setFixture(request, 'fake-epay-result', { result_code: '0' });
	  }
	}
});

test('@attempt credential outage warning is raised by code 100 and cleared by a valid ticket', async ({ request }) => {
  try {
    await setFixture(request, 'fake-epay-result', { result_code: '100' });
    const rejectedOrder = await createOrder(request);
    const rejected = await setFixture(request, `render-epay-receipt/${rejectedOrder.order_id}`, {});
    expect(rejected.has_redirect_form).toBe(false);

    const raised = await setFixture(request, 'credential-notice', {});
    expect(raised.active).toBe(true);
    expect(raised.notice).toContain('ePay Paycenter: payments are failing');
    expect(raised.notice).toContain('ResultCode 100');

    await setFixture(request, 'fake-epay-result', { result_code: '0' });
    const recoveredOrder = await createOrder(request);
    await issueAttempt(request, recoveredOrder);
    expect(await setFixture(request, 'credential-notice', {})).toEqual({ active: false, notice: '' });
  } finally {
    await setFixture(request, 'fake-epay-result', { result_code: '0' });
    await setFixture(request, 'credential-notice', { action: 'clear' });
  }
});

test('@attempt an aged unpaid order is cancelled by WooCommerce', async ({ request }) => {
  const verificationOrder = await createOrder(request);
  await issueAttempt(request, verificationOrder);
  await verifyAndEnableFollowUp(request, verificationOrder);

  const order = await createOrder(request);
  const attempt = await issueAttempt(request, order);
  const earlyResponse = await request.post(`/wp-json/epay-test/v1/cancel-unpaid/${order.order_id}`, {
    data: { age_minutes: 90 },
  });
  expect(earlyResponse.ok(), await earlyResponse.text()).toBeTruthy();
  const early = await earlyResponse.json();
  expect(early.order.status).toBe('pending');

  const response = await request.post(`/wp-json/epay-test/v1/cancel-unpaid/${order.order_id}`, {
    data: { age_minutes: 245 },
  });
  expect(response.ok(), await response.text()).toBeTruthy();
  const body = await response.json();
  expect(body.order.status).toBe('cancelled');
  expect(body.order.payment_method).toBe('epay_paycenter');
  expect(body.order.epay.ticket_statuses).toEqual({
    [attempt.MerchantReference]: 'expired',
  });
});

test('@attempt recovery remains opt-in and does not extend stock hold while disabled', async ({ request }) => {
  const order = await createOrder(request);
  await issueAttempt(request, order);
  const response = await request.post(`/wp-json/epay-test/v1/cancel-unpaid/${order.order_id}`, {
    data: { age_minutes: 90 },
  });
  expect(response.ok(), await response.text()).toBeTruthy();
  expect((await response.json()).order.status).toBe('cancelled');
});

test('@followup channel detector tries eCommerce then 3DSecure without changing the order', async ({ request }) => {
  const order = await createOrder(request);
  const attempt = await issueAttempt(request, order);
  await setFixture(request, 'fake-follow-up', { scenario: 'paid', channel: '3DSecure' });

  const verification = await setFixture(request, `follow-up/test-channel/${order.order_id}`, {});
  expect(verification).toMatchObject({
    success: true,
    channel: '3DSecure',
    tested_channels: ['eCommerce', '3DSecure'],
  });

  const untouched = await readOrder(request, order.order_id);
  expect(untouched.status).toBe('pending');
  expect(untouched.epay.has_open_ticket_secrets).toBe(true);
  expect(untouched.epay.ticket_statuses).toEqual({
    [attempt.MerchantReference]: 'pending',
  });

  const health = await request.get('/wp-json/epay-test/v1/health');
  const state = await health.json();
  expect(state.follow_up_enabled).toBe(false);
  expect(state.follow_up_channel).toBe('3DSecure');
});

test('@followup successful channel verification updates the settings card status immediately', async ({ page, request }) => {
  const order = await createOrder(request);
  await issueAttempt(request, order);
  await setFixture(request, 'fake-follow-up', { scenario: 'paid', channel: 'eCommerce' });
  await loginAsLocalAdmin(page);
  await page.goto('/wp-admin/admin.php?page=wc-settings&tab=checkout&section=epay_paycenter');

  const card = page.locator('section[aria-labelledby="epay-follow-up-title"]');
  const status = card.locator('.epay-card__subtitle');
  await expect(status).not.toContainText('eCommerce');
  await card.locator('#epay-follow-up-order').fill(String(order.order_id));
  await card.locator('#epay-follow-up-test').click();

  await expect(card.locator('#epay-follow-up-result')).toContainText('eCommerce');
  await expect(status).toContainText('eCommerce');
});

test('@followup enabling backfills old ticket rows and the scheduled worker recovers them', async ({ request }) => {
  const oldOrder = await createOrder(request);
  const oldAttempt = await issueAttempt(request, oldOrder);
  const verificationOrder = await createOrder(request);
  await issueAttempt(request, verificationOrder);

  await verifyAndEnableFollowUp(request, verificationOrder);
  await setFixture(request, 'fake-follow-up', { scenario: 'paid', channel: 'eCommerce' });
  await setFixture(request, `follow-up/prioritise/${oldOrder.order_id}`, {});
  const worker = await setFixture(request, 'follow-up/worker', {});
  expect(worker.result.paid).toBeGreaterThanOrEqual(1);

  const recovered = await readOrder(request, oldOrder.order_id);
  expect(['processing', 'completed']).toContain(recovered.status);
  expect(recovered.epay.ticket_statuses).toEqual({
    [oldAttempt.MerchantReference]: 'succeeded',
  });
});

test('@followup a late authorised IRIS transaction settles once and clears secrets', async ({ request }) => {
  const order = await createOrder(request);
  const attempt = await issueAttempt(request, order);
  await verifyAndEnableFollowUp(request, order);
  await setFixture(request, 'fake-follow-up', { scenario: 'paid', channel: 'eCommerce' });

  const first = await setFixture(request, `follow-up/run/${order.order_id}`, {});
  expect(first.result).toMatchObject({ paid: 1, query_errors: 0 });
  expect(['processing', 'completed']).toContain(first.order.status);
  expect(first.order.epay).toMatchObject({
    has_open_ticket_secrets: false,
    has_transaction_id: true,
    has_callback_transaction_id: true,
    payment_method: 'IRIS',
  });
  expect(first.order.epay.ticket_statuses).toEqual({
    [attempt.MerchantReference]: 'succeeded',
  });
  expect(first.order.epay.follow_up[attempt.MerchantReference]).toMatchObject({
    state: 'paid',
    attempts: 1,
    result_code: '0',
    response_code: '00',
    status_flag: 'Success',
    payment_method: 'IRIS',
  });

  const replay = await setFixture(request, `follow-up/run/${order.order_id}`, {});
  expect(replay.result.paid).toBe(0);
  expect(replay.requests).toEqual(first.requests);
});

test('@followup rejects a mismatched bank identity without changing payment state', async ({ request }) => {
  const verificationOrder = await createOrder(request);
  await issueAttempt(request, verificationOrder);
  await verifyAndEnableFollowUp(request, verificationOrder);

  const order = await createOrder(request);
  const attempt = await issueAttempt(request, order);
  await setFixture(request, 'fake-follow-up', { scenario: 'identity_mismatch', channel: 'eCommerce' });

  const checked = await setFixture(request, `follow-up/run/${order.order_id}`, {});
  expect(checked.result).toMatchObject({ paid: 0, query_errors: 1 });
  expect(checked.order.status).toBe('pending');
  expect(checked.order.epay.has_open_ticket_secrets).toBe(true);
  expect(checked.order.epay.ticket_statuses).toEqual({
    [attempt.MerchantReference]: 'pending',
  });
  expect(checked.order.epay.follow_up[attempt.MerchantReference].state).toBe('query_error');
});

test('@followup can recover an order after the four-hour stock hold released it', async ({ request }) => {
  const verificationOrder = await createOrder(request);
  await issueAttempt(request, verificationOrder);
  await verifyAndEnableFollowUp(request, verificationOrder);

  const order = await createOrder(request);
  const attempt = await issueAttempt(request, order);
  const cancelled = await request.post(`/wp-json/epay-test/v1/cancel-unpaid/${order.order_id}`, {
    data: { age_minutes: 245 },
  });
  expect(cancelled.ok(), await cancelled.text()).toBeTruthy();
  expect((await cancelled.json()).order.status).toBe('cancelled');

  await setFixture(request, 'fake-follow-up', { scenario: 'paid', channel: 'eCommerce' });
  const recovered = await setFixture(request, `follow-up/run/${order.order_id}`, {});
  expect(['processing', 'completed']).toContain(recovered.order.status);
  expect(recovered.order.epay.follow_up_late_payment).toBe(true);
  expect(recovered.order.epay.ticket_statuses).toEqual({
    [attempt.MerchantReference]: 'succeeded',
  });
});

test('@followup checks sibling attempts and flags more than one bank success', async ({ request }) => {
  const verificationOrder = await createOrder(request);
  await issueAttempt(request, verificationOrder);
  await verifyAndEnableFollowUp(request, verificationOrder);

  const order = await createOrder(request);
  const firstAttempt = await issueAttempt(request, order);
  const secondAttempt = await issueAttempt(request, order);
  await setFixture(request, 'fake-follow-up', { scenario: 'paid', channel: 'eCommerce' });

  const checked = await setFixture(request, `follow-up/run/${order.order_id}`, {});
  expect(checked.result.paid).toBe(2);
  expect(checked.requests).toHaveLength(2);
  expect(checked.order.epay.follow_up_multiple_payments).toBe(true);
  expect(checked.order.epay.ticket_statuses).toEqual({
    [firstAttempt.MerchantReference]: 'succeeded',
    [secondAttempt.MerchantReference]: 'succeeded',
  });
});

test('@followup keeps checking siblings after a normal callback exposes a second success', async ({ request }) => {
  const verificationOrder = await createOrder(request);
  await issueAttempt(request, verificationOrder);
  await verifyAndEnableFollowUp(request, verificationOrder);

  const order = await createOrder(request);
  const firstAttempt = await issueAttempt(request, order);
  const secondAttempt = await issueAttempt(request, order);
  await sendCallback(request, CANONICAL_CALLBACK, callbackPayload(order.order_id, firstAttempt.MerchantReference));
  expect(['processing', 'completed']).toContain((await readOrder(request, order.order_id)).status);

  await setFixture(request, 'fake-follow-up', { scenario: 'paid', channel: 'eCommerce' });
  const checked = await setFixture(request, `follow-up/run/${order.order_id}`, {});
  expect(checked.result.paid).toBe(1);
  expect(checked.requests).toMatchObject([
    { reference: secondAttempt.MerchantReference },
  ]);
  expect(checked.order.epay.follow_up_multiple_payments).toBe(true);
  expect(checked.order.epay.merchant_reference).toBe(firstAttempt.MerchantReference);
  expect(checked.order.epay.ticket_statuses).toEqual({
    [firstAttempt.MerchantReference]: 'succeeded',
    [secondAttempt.MerchantReference]: 'succeeded',
  });
});

test('@followup @sibling-retry persisted sibling approval preserves the canonical payment', async ({ request }) => {
  const verificationOrder = await createOrder(request);
  await issueAttempt(request, verificationOrder);
  await verifyAndEnableFollowUp(request, verificationOrder);

  const order = await createOrder(request);
  const firstAttempt = await issueAttempt(request, order);
  const secondAttempt = await issueAttempt(request, order);
  const firstPayment = callbackPayload(order.order_id, firstAttempt.MerchantReference);
  await sendCallback(request, CANONICAL_CALLBACK, firstPayment);
  const canonical = await readOrder(request, order.order_id);
  expect(canonical.epay).toMatchObject({
    merchant_reference: firstAttempt.MerchantReference,
    settled_reference: firstAttempt.MerchantReference,
    transaction_id: firstPayment.TransactionId,
    callback_transaction_id: firstPayment.TransactionId,
    payment_complete_count: 1,
  });

  const interrupted = await setFixture(request, `follow-up/persist-paid-sibling/${order.order_id}`, {
    reference: secondAttempt.MerchantReference,
  });
  expect(interrupted.epay.follow_up[secondAttempt.MerchantReference].state).toBe('paid_unsettled');
  await setFixture(request, 'fake-follow-up', { scenario: 'transport_error', channel: 'eCommerce' });

  const checked = await setFixture(request, `follow-up/run/${order.order_id}`, {});
  expect(checked.result).toMatchObject({ paid: 1, double_payments: 1, settlement_errors: 0 });
  expect(checked.requests).toEqual([]);
  expect(checked.order.status).toBe(canonical.status);
  expect(checked.order.epay).toMatchObject({
    merchant_reference: firstAttempt.MerchantReference,
    settled_reference: firstAttempt.MerchantReference,
    transaction_id: firstPayment.TransactionId,
    callback_transaction_id: firstPayment.TransactionId,
    payment_complete_count: 1,
    follow_up_multiple_payments: true,
    ticket_statuses: {
      [firstAttempt.MerchantReference]: 'succeeded',
      [secondAttempt.MerchantReference]: 'succeeded',
    },
    follow_up: { [secondAttempt.MerchantReference]: { state: 'paid' } },
  });

  const repeated = await setFixture(request, `follow-up/run/${order.order_id}`, {});
  expect(repeated.requests).toEqual([]);
  expect(repeated.result.paid).toBe(0);
  expect(repeated.order.epay).toEqual(checked.order.epay);
});

test('@followup treats result 1010 as inconclusive and can recover on a later check', async ({ request }) => {
  const verificationOrder = await createOrder(request);
  await issueAttempt(request, verificationOrder);
  await verifyAndEnableFollowUp(request, verificationOrder);

  const order = await createOrder(request);
  const attempt = await issueAttempt(request, order);
  await setFixture(request, 'fake-follow-up', { scenario: 'not_found', channel: 'eCommerce' });
  const waiting = await setFixture(request, `follow-up/run/${order.order_id}`, {});
  expect(waiting.result).toMatchObject({ paid: 0, pending: 1, query_errors: 0 });
  expect(waiting.order.status).toBe('pending');
  expect(waiting.order.epay.follow_up[attempt.MerchantReference]).toMatchObject({
    state: 'pending',
    result_code: '1010',
  });

  await setFixture(request, 'fake-follow-up', { scenario: 'paid', channel: 'eCommerce' });
  const recovered = await setFixture(request, `follow-up/run/${order.order_id}`, {});
  expect(['processing', 'completed']).toContain(recovered.order.status);
  expect(recovered.order.epay.ticket_statuses).toEqual({
    [attempt.MerchantReference]: 'succeeded',
  });
});

for (const method of ['iris', 'unknown', 'card']) {
  test(`@followup Failure/09 ${method} only ends checking for an identified card decline`, async ({ request }) => {
    const order = await createOrder(request);
    const attempt = await issueAttempt(request, order);
    await verifyAndEnableFollowUp(request, order);
    await setFixture(request, 'fake-follow-up', { scenario: `failure_09_${method}`, channel: 'eCommerce' });
    const checked = await setFixture(request, `follow-up/run/${order.order_id}`, {});
    expect(checked.order.status).toBe(method === 'card' ? 'failed' : 'pending');
    expect(checked.order.epay.follow_up[attempt.MerchantReference]).toMatchObject({
      state: method === 'card' ? 'declined' : 'pending', response_code: '09',
    });
    expect(checked.result.paid).toBe(0);
    if (method !== 'card') {
      await setFixture(request, 'fake-follow-up', { scenario: 'paid', channel: 'eCommerce' });
      const recovered = await setFixture(request, `follow-up/run/${order.order_id}`, {});
      expect(['processing', 'completed']).toContain(recovered.order.status);
      expect(recovered.result.paid).toBe(1);
    }
  });
}

test('@followup upgrade resumes an ambiguous closed 09 without reopening a card decline', async ({ request }) => {
  const card = await createOrder(request);
  await issueAttempt(request, card);
  const order = await createOrder(request);
  const attempt = await issueAttempt(request, order);
  await verifyAndEnableFollowUp(request, order);
  await setFixture(request, `follow-up/seed-closed-09/${card.order_id}`, { method: 'card' });
  await setFixture(request, `follow-up/seed-closed-09/${order.order_id}`, { method: 'unknown' });
  await setFixture(request, 'fake-follow-up', { scenario: 'paid', channel: 'eCommerce' });
  await setFixture(request, `follow-up/prioritise/${order.order_id}`, {});
  await setFixture(request, 'follow-up/worker', {});
  const recovered = await readOrder(request, order.order_id);
  expect(['processing', 'completed']).toContain(recovered.status);
  expect(recovered.epay.follow_up[attempt.MerchantReference].state).toBe('paid');
  expect((await readOrder(request, card.order_id)).status).toBe('failed');
});

test('@followup an unpaid order regains its stock-release timer after package deactivation', async ({ request }) => {
  const order = await createOrder(request);
  const attempt = await issueAttempt(request, order);
  await verifyAndEnableFollowUp(request, order);
  await sendCallback(request, CANONICAL_CALLBACK, callbackPayload(order.order_id, attempt.MerchantReference, {
    ResponseCode: '09', StatusFlag: 'Failure', PaymentMethod: 'IRIS', CardType: '15',
  }));
  await setFixture(request, `follow-up/drop-stock-event/${order.order_id}`, {});
  await setFixture(request, 'fake-follow-up', { scenario: 'pending', channel: 'eCommerce' });
  await setFixture(request, `follow-up/prioritise/${order.order_id}`, {});
  await setFixture(request, 'follow-up/worker', {});
  const waiting = await readOrder(request, order.order_id);
  expect(waiting.status).toBe('on-hold');
  expect(waiting.epay.stock_release_scheduled).toBe(true);
});

test('@followup rejects an incomplete approved response without changing payment state', async ({ request }) => {
  const verificationOrder = await createOrder(request);
  await issueAttempt(request, verificationOrder);
  await verifyAndEnableFollowUp(request, verificationOrder);

  const order = await createOrder(request);
  const attempt = await issueAttempt(request, order);
  await setFixture(request, 'fake-follow-up', { scenario: 'incomplete_paid', channel: 'eCommerce' });

  const checked = await setFixture(request, `follow-up/run/${order.order_id}`, {});
  expect(checked.result).toMatchObject({ paid: 0, query_errors: 1 });
  expect(checked.order.status).toBe('pending');
  expect(checked.order.epay.has_open_ticket_secrets).toBe(true);
  expect(checked.order.epay.follow_up[attempt.MerchantReference].state).toBe('query_error');
});

test('@followup keeps a transport failure retryable and later recovers the payment', async ({ request }) => {
  const verificationOrder = await createOrder(request);
  await issueAttempt(request, verificationOrder);
  await verifyAndEnableFollowUp(request, verificationOrder);

  const order = await createOrder(request);
  const attempt = await issueAttempt(request, order);
  await setFixture(request, 'fake-follow-up', { scenario: 'transport_error', channel: 'eCommerce' });

  const failedCheck = await setFixture(request, `follow-up/run/${order.order_id}`, {});
  expect(failedCheck.result).toMatchObject({ paid: 0, query_errors: 1 });
  expect(failedCheck.order.status).toBe('pending');
  expect(failedCheck.order.epay.has_open_ticket_secrets).toBe(true);
  expect(failedCheck.order.epay.follow_up[attempt.MerchantReference].state).toBe('query_error');

  await setFixture(request, 'fake-follow-up', { scenario: 'paid', channel: 'eCommerce' });
  const recovered = await setFixture(request, `follow-up/run/${order.order_id}`, {});
  expect(['processing', 'completed']).toContain(recovered.order.status);
  expect(recovered.order.epay.ticket_statuses).toEqual({
    [attempt.MerchantReference]: 'succeeded',
  });
});

test('@followup retries when WooCommerce cannot persist the paid transition', async ({ request }) => {
  const verificationOrder = await createOrder(request);
  await issueAttempt(request, verificationOrder);
  await verifyAndEnableFollowUp(request, verificationOrder);

  const order = await createOrder(request);
  const attempt = await issueAttempt(request, order);
  await setFixture(request, 'fake-follow-up', { scenario: 'paid', channel: 'eCommerce' });
  await setFixture(request, 'follow-up/fail-next-payment-complete', {});

  const failedSettlement = await setFixture(request, `follow-up/run/${order.order_id}`, {});
  expect(failedSettlement.result).toMatchObject({ paid: 1, settlement_errors: 1 });
  expect(failedSettlement.order.status).toBe('pending');
  expect(failedSettlement.order.epay.has_open_ticket_secrets).toBe(true);
  expect(failedSettlement.order.epay.follow_up[attempt.MerchantReference].state).toBe('paid_unsettled');

  const recovered = await setFixture(request, `follow-up/run/${order.order_id}`, {});
  expect(recovered.result).toMatchObject({ paid: 1, settlement_errors: 0 });
  expect(recovered.requests).toEqual(failedSettlement.requests);
  expect(['processing', 'completed']).toContain(recovered.order.status);
  expect(recovered.order.epay.has_open_ticket_secrets).toBe(false);
  expect(recovered.order.epay.ticket_statuses).toEqual({
    [attempt.MerchantReference]: 'succeeded',
  });
});

test('@followup fails an unpaid order only after every attempt is definitively declined', async ({ request }) => {
  const verificationOrder = await createOrder(request);
  await issueAttempt(request, verificationOrder);
  await verifyAndEnableFollowUp(request, verificationOrder);

  const order = await createOrder(request);
  const attempt = await issueAttempt(request, order);
  await setFixture(request, 'fake-follow-up', { scenario: 'declined', channel: 'eCommerce' });

  const checked = await setFixture(request, `follow-up/run/${order.order_id}`, {});
  expect(checked.result).toMatchObject({ paid: 0, declined: 1, query_errors: 0 });
  expect(checked.order.status).toBe('failed');
  expect(checked.order.epay.ticket_statuses).toEqual({
    [attempt.MerchantReference]: 'failed',
  });
  expect(checked.order.epay.follow_up[attempt.MerchantReference]).toMatchObject({
    state: 'declined',
    result_code: '0',
    response_code: '05',
    status_flag: 'Failure',
  });
});

test('@followup reports a paid trashed order without restoring it', async ({ request }) => {
  const verificationOrder = await createOrder(request);
  await issueAttempt(request, verificationOrder);
  await verifyAndEnableFollowUp(request, verificationOrder);

  const order = await createOrder(request);
  const attempt = await issueAttempt(request, order);
  await setFixture(request, `trash-order/${order.order_id}`, {});
  await setFixture(request, 'fake-follow-up', { scenario: 'paid', channel: 'eCommerce' });

  const checked = await setFixture(request, `follow-up/run/${order.order_id}`, {});
  expect(checked.result.paid).toBe(1);
  expect(checked.order === null || checked.order.status === 'trash').toBe(true);
  expect(checked.ticket_statuses).toEqual({ [attempt.MerchantReference]: 'succeeded' });
});

test('@followup reports a paid refunded order without reviving it', async ({ request }) => {
  const verificationOrder = await createOrder(request);
  await issueAttempt(request, verificationOrder);
  await verifyAndEnableFollowUp(request, verificationOrder);

  const order = await createOrder(request);
  const attempt = await issueAttempt(request, order);
  await setFixture(request, `refund-order/${order.order_id}`, {});
  await setFixture(request, 'fake-follow-up', { scenario: 'paid', channel: 'eCommerce' });

  const checked = await setFixture(request, `follow-up/run/${order.order_id}`, {});
  expect(checked.result.paid).toBe(1);
  expect(checked.order.status).toBe('refunded');
  expect(checked.ticket_statuses).toEqual({ [attempt.MerchantReference]: 'succeeded' });
});

test('@followup classifies an already-paid historical order locally without querying the bank', async ({ request }) => {
  const verificationOrder = await createOrder(request);
  await issueAttempt(request, verificationOrder);
  await verifyAndEnableFollowUp(request, verificationOrder);

  const order = await createOrder(request);
  const attempt = await issueAttempt(request, order);
  await setFixture(request, `mark-local-paid/${order.order_id}`, {});
  await setFixture(request, 'fake-follow-up', { scenario: 'transport_error', channel: 'eCommerce' });

  const checked = await setFixture(request, `follow-up/run/${order.order_id}`, {});
  expect(checked.result).toMatchObject({ local_paid: 1, paid: 0, query_errors: 0 });
  expect(checked.requests).toEqual([]);
  expect(['processing', 'completed']).toContain(checked.order.status);
  expect(checked.order.epay.has_open_ticket_secrets).toBe(false);
  expect(checked.order.epay.ticket_statuses).toEqual({ [attempt.MerchantReference]: 'succeeded' });
  expect(checked.order.epay.follow_up[attempt.MerchantReference].state).toBe('local_paid');
});

test('@followup @manual-processing does not treat a manually-set Processing status as payment provenance', async ({ request }) => {
  const verificationOrder = await createOrder(request);
  await issueAttempt(request, verificationOrder);
  await verifyAndEnableFollowUp(request, verificationOrder);

  const order = await createOrder(request);
  const attempt = await issueAttempt(request, order);
  await setFixture(request, `mark-processing/${order.order_id}`, {});
  await setFixture(request, 'fake-follow-up', { scenario: 'paid', channel: 'eCommerce' });

  const checked = await setFixture(request, `follow-up/run/${order.order_id}`, {});
  expect(checked.result).toMatchObject({ local_paid: 0, paid: 1, query_errors: 0 });
  expect(checked.requests).toHaveLength(1);
  expect(['processing', 'completed']).toContain(checked.order.status);
  expect(checked.order.epay.has_transaction_id).toBe(true);
  expect(checked.order.epay.payment_complete_count).toBe(0);
  expect(checked.order.epay.ticket_statuses).toEqual({ [attempt.MerchantReference]: 'succeeded' });
  expect(checked.order.epay.follow_up[attempt.MerchantReference].state).toBe('paid');
});

test('@followup reports a paid permanently deleted order without recreating it', async ({ request }) => {
  const verificationOrder = await createOrder(request);
  await issueAttempt(request, verificationOrder);
  await verifyAndEnableFollowUp(request, verificationOrder);

  const order = await createOrder(request);
  const attempt = await issueAttempt(request, order);
  const deleted = await setFixture(request, `delete-order/${order.order_id}`, {});
  expect(deleted.deleted).toBe(true);
  await setFixture(request, 'fake-follow-up', { scenario: 'paid', channel: 'eCommerce' });

  const checked = await setFixture(request, `follow-up/run/${order.order_id}`, {});
  expect(checked.result.paid).toBe(1);
  expect(checked.order).toBeNull();
  expect(checked.ticket_statuses).toEqual({ [attempt.MerchantReference]: 'succeeded' });
});

test('@review staff see actionable cases only on order screens and can acknowledge without changing payment data', async ({ page, request }) => {
  const historical = await createOrder(request);
  const oldAttempt = await issueAttempt(request, historical);
  await verifyAndEnableFollowUp(request, historical);
  await setFixture(request, `follow-up/age-attempt/${historical.order_id}`, {});
  await setFixture(request, 'fake-follow-up', { scenario: 'not_found', channel: 'eCommerce' });
  await setFixture(request, `follow-up/run/${historical.order_id}`, {});
  const missing = await createOrder(request);
  const paidAttempt = await issueAttempt(request, missing);
  await setFixture(request, `delete-order/${missing.order_id}`, {});
  await setFixture(request, 'fake-follow-up', { scenario: 'paid', channel: 'eCommerce' });
  await setFixture(request, `follow-up/run/${missing.order_id}`, {});
  await loginAsLocalAdmin(page);
  await page.goto('/wp-admin/');
  await expect(page.getByText(paidAttempt.MerchantReference, { exact: false })).toHaveCount(0);
  await page.goto('/wp-admin/edit.php?post_type=shop_order');
  const review = page.locator('.epay-paycenter-review');
  const paidRow = review.locator('li').filter({ hasText: paidAttempt.MerchantReference });
  await expect(paidRow).toContainText('Bank confirmed payment, but the order is missing');
  const reviewKey = await paidRow.locator('[name="review_key"]').inputValue();
  const nonce = await paidRow.locator('[name="_wpnonce"]').inputValue();
  const unprivileged = await request.post('/wp-admin/admin-post.php', {
    form: { action: 'epay_paycenter_review', review_key: reviewKey, _wpnonce: nonce }, maxRedirects: 0,
  });
  expect(unprivileged.ok()).toBe(false);
  const forged = await page.request.post('/wp-admin/admin-post.php', {
    form: { action: 'epay_paycenter_review', review_key: reviewKey, _wpnonce: 'invalid' }, maxRedirects: 0,
  });
  expect(forged.status()).toBe(403);
  const historicalRow = review.locator('li').filter({ hasText: oldAttempt.MerchantReference });
  await expect(historicalRow).not.toBeVisible();
  if (process.env.EPAY_TEST_CAPTURE_UI) await page.screenshot({ path: test.info().outputPath('staff-review.png'), fullPage: true });
  await review.getByText('Historical checks', { exact: false }).click();
  await expect(historicalRow).toBeVisible();
  await paidRow.getByRole('button', { name: 'Mark reviewed', exact: true }).click();
  await expect(review.getByText(paidAttempt.MerchantReference, { exact: false })).toHaveCount(0);
  await page.reload();
  await expect(review.getByText(paidAttempt.MerchantReference, { exact: false })).toHaveCount(0);
  expect((await readOrder(request, historical.order_id)).epay.follow_up[oldAttempt.MerchantReference].state).toBe('unresolved');
  const missingState = await setFixture(request, `follow-up/run/${missing.order_id}`, {});
  expect(missingState.order).toBeNull();
  expect(missingState.ticket_statuses).toEqual({ [paidAttempt.MerchantReference]: 'succeeded' });
  await page.goto('/wp-admin/admin.php?page=wc-settings&tab=checkout&section=epay_paycenter');
  await review.getByText('Reviewed cases', { exact: false }).click();
  await expect(review.locator('li').filter({ hasText: paidAttempt.MerchantReference })).toContainText('Reviewed (UTC)');
});

test('@matrix payment handoff renders with supported shipping methods', async ({ page, request }) => {
  for (const shippingMethod of ['local_pickup', 'flat_rate']) {
    const order = await createOrder(request, { shippingMethod });
    let handoff = null;
    await page.route('https://paycenter.piraeusbank.gr/**', async (route) => {
      handoff = Object.fromEntries(new URLSearchParams(route.request().postData() || ''));
      await route.fulfill({ status: 200, contentType: 'text/html', body: '<h1>Fake Paycenter</h1>' });
    });

    await page.goto(order.receipt_url);
    await expect.poll(() => handoff, { timeout: 20_000 }).not.toBeNull();
    expect(handoff.MerchantReference).toMatch(new RegExp(`^${order.order_id}-`));
    expect(handoff.User).toBe('epay-test-local-test');
    expect(handoff).not.toHaveProperty('TranTicket');
    await page.unroute('https://paycenter.piraeusbank.gr/**');
  }
});

module.exports = { callbackPayload, createOrder, issueAttempt, readOrder, sendCallback, setFixture, verifyAndEnableFollowUp, CANONICAL_CALLBACK };
