const http = require('node:http');
const { test: base, expect } = require('@playwright/test');

const BANK_URL = 'https://paycenter.piraeusbank.gr/redirection/pay.aspx';

const test = base.extend({
  fakePaycenter: async ({ page }, use) => {
    const submissions = [];
    const destinations = [];
    const server = http.createServer((request, response) => {
      if (request.method !== 'POST') { response.end(); return; }
      let body = '';
      request.on('data', chunk => { body += chunk; });
      request.on('end', () => {
        submissions.push(new URLSearchParams(body));
        destinations.push(new URL(request.url, 'http://127.0.0.1').searchParams.get('destination'));
        response.writeHead(200, { 'Content-Type': 'text/html' });
        response.end('<h1>Fake Paycenter</h1>');
      });
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
      const url = `http://127.0.0.1:${server.address().port}/`;
      // Real cross-origin navigation: WebKit interception can discard in-flight beacons.
      await page.addInitScript(target => {
        document.addEventListener('DOMContentLoaded', () => {
          const form = document.getElementById('epay-paycenter-form');
          if (form instanceof HTMLFormElement) {
            form.action = target + '?destination=' + encodeURIComponent(form.action);
          }
        });
      }, url);
      await use({ submissions });
      for (const destination of destinations) expect(destination).toBe(BANK_URL);
    } finally {
      await new Promise(resolve => server.close(resolve));
    }
  },
});

module.exports = { test, expect };
