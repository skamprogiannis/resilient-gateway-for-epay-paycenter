const crypto = require('node:crypto');
const { test, expect } = require('@playwright/test');

async function setLayout(request, layout, options = {}) {
  const response = await request.post('/wp-json/epay-test/v1/checkout-layout', { data: { layout, ...options } });
  expect(response.ok(), await response.text()).toBe(true);
  return response.json();
}

async function fillClassicBilling(page) {
  await page.locator('#billing_first_name').fill('Test');
  await page.locator('#billing_last_name').fill('Buyer');
  await page.locator('#billing_country').selectOption('GR', { force: true });
  await page.locator('#billing_address_1').fill('Test Street 1');
  await page.locator('#billing_city').fill('Athens');
  await page.locator('#billing_postcode').fill('10557');
  await page.locator('#billing_phone').fill('+306912345678');
  await page.locator('#billing_email').fill('checkout-layout@example.test');
  await page.locator('#billing_state').selectOption('I', { force: true });
}

async function refreshClassicCheckout(page) {
  await page.evaluate(() => new Promise((resolve) => {
    window.jQuery(document.body).one('updated_checkout', resolve).trigger('update_checkout');
  }));
}

async function selectDuringRefresh(page, value) {
  await page.route('**/?wc-ajax=update_order_review', async (route) => {
    const response = await route.fetch();
    const data = await response.json();
    // WooCommerce skips byte-identical fragments; exercise an actual replacement.
    data.fragments['.woocommerce-checkout-payment'] += '\n<!-- checkout refresh regression -->';
    await page.locator('#epay_installments').selectOption(value);
    await route.fulfill({ response, json: data });
  }, { times: 1 });
  await refreshClassicCheckout(page);
}

async function fillBlocksBilling(page) {
  await page.locator('#email').fill('checkout-layout@example.test');
  await page.locator('#billing-first_name').fill('Test');
  await page.locator('#billing-last_name').fill('Buyer');
  await page.locator('#billing-address_1').fill('Test Street 1');
  await page.locator('#billing-city').fill('Athens');
  await page.locator('#billing-postcode').fill('10557');
  const phone = page.locator('#billing-phone');
  if (await phone.count()) await phone.fill('+306912345678');
  const state = page.locator('#billing-state');
  if (await state.count()) {
    if (await state.evaluate((element) => element.tagName === 'SELECT')) {
      await state.selectOption('I', { force: true });
    } else {
      await state.fill('Attica');
      await page.getByRole('option', { name: /Attica|Αττική/ }).click();
    }
  }
}

test.describe('checkout layouts', () => {
  test.afterEach(async ({ request }) => {
    await setLayout(request, 'restore');
  });

  test('@installments refresh warns about an unavailable choice and respects disabled installments', async ({ page, request }) => {
    const fixture = await setLayout(request, 'classic');
    await page.goto(fixture.add_to_cart_url);
    await fillClassicBilling(page);
    await refreshClassicCheckout(page);
    await setLayout(request, 'classic', { max_installments: 2 });
    await selectDuringRefresh(page, '3');
    await expect(page.locator('#epay_installments')).toHaveValue('1');
    await expect(page.locator('#epay-installments-notice')).toContainText('please review your payment choice');
    await page.locator('#epay_installments').selectOption('2');
    await expect(page.locator('#epay-installments-notice')).toHaveCount(0);
    await setLayout(request, 'classic', { installments: false });
    await refreshClassicCheckout(page);
    await expect(page.locator('#epay_installments')).toHaveCount(0);
    await expect(page.locator('#epay-installments-notice')).toBeVisible();
    await page.reload();
    await expect(page.locator('#epay_installments')).toHaveCount(0);
    await expect(page.locator('#epay-installments-notice')).toHaveCount(0);
  });

  for (const layout of ['classic', 'blocks']) {
    test(`@matrix ${layout} checkout submits a real cart through ePay`, async ({ page, request }) => {
      const fixture = await setLayout(request, layout);
      let handoff = null;
      await page.route('https://paycenter.piraeusbank.gr/**', async (route) => {
        expect(route.request().method()).toBe('POST');
        handoff = new URLSearchParams(route.request().postData() || '');
        await route.fulfill({ status: 200, contentType: 'text/html', body: '<h1>Fake Paycenter</h1>' });
      });

      await page.goto(fixture.add_to_cart_url);
      await page.goto(fixture.checkout_url);
      await expect(page.getByText('Card or IRIS', { exact: true })).toBeVisible();

      if (layout === 'classic') {
        await expect(page.locator('form.checkout')).toBeVisible();
        await fillClassicBilling(page);
        await refreshClassicCheckout(page);
        await page.locator('#payment_method_epay_paycenter').check({ force: true });
        // Finish a real payment-fragment refresh after the customer chooses installments.
        await selectDuringRefresh(page, '3');
        await expect(page.locator('#epay_installments')).toHaveValue('3');
        const terms = page.locator('#terms');
        if (await terms.count()) await terms.check();
        await page.locator('#place_order').click();
      } else {
        await expect(page.locator('.wp-block-woocommerce-checkout')).toBeVisible();
        await expect(page.locator('#epay_installments')).toHaveCount(0);
        await fillBlocksBilling(page);
        const payment = page.locator('input[type="radio"][value="epay_paycenter"]');
        if (await payment.count()) await payment.check();
        const terms = page.getByRole('checkbox', { name: /terms and conditions/i });
        if (await terms.count()) await terms.check();
        await page.locator('.wc-block-components-checkout-place-order-button').click();
      }

      await expect(page.getByRole('heading', { name: 'Fake Paycenter' })).toBeVisible({ timeout: 25_000 });
      expect(handoff).not.toBeNull();
      const reference = handoff.get('MerchantReference');
      expect(reference).toMatch(/^\d+-[A-Z0-9]+$/);
      for (const field of ['TranTicket', 'Password', 'HashKey']) expect(handoff.has(field)).toBe(false);
      const body = handoff.toString();
      const ticket = `TST${crypto.createHash('sha256').update(reference).digest('hex').slice(0, 29)}`;
      expect(body).not.toContain(ticket);
      expect(body).not.toContain(crypto.createHash('md5').update('local-fake-password').digest('hex'));

      const orderId = Number(reference.split('-')[0]);
      const response = await request.get(`/wp-json/epay-test/v1/checkout-layout/order/${orderId}`);
      expect(response.ok(), await response.text()).toBe(true);
      expect(await response.json()).toMatchObject({
        order_id: orderId,
        created_via: layout === 'blocks' ? 'store-api' : 'checkout',
        payment_method: 'epay_paycenter',
        installments: layout === 'blocks' ? 1 : 3,
        ticket_installments: layout === 'blocks' ? 1 : 3,
        item_count: 1,
      });
    });
  }
});
