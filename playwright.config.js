const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests',
  testMatch: '**/*.spec.js',
  timeout: 60_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  workers: 1,
  outputDir: 'artifacts/playwright-output',
  reporter: [['list'], ['html', { outputFolder: 'artifacts/html-report', open: 'never' }]],
  use: {
    baseURL: process.env.EPAY_TEST_BASE_URL || 'http://localhost:8081',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    extraHTTPHeaders: { 'X-Epay-Test': 'epay-qualification' },
  },
  projects: [
    { name: 'chromium-desktop', use: { ...devices['Desktop Chrome'] } },
    { name: 'firefox-desktop', use: { ...devices['Desktop Firefox'] } },
    { name: 'webkit-desktop', use: { ...devices['Desktop Safari'] } },
    { name: 'iphone-15-safari', use: { ...devices['iPhone 15 Pro'] } },
    { name: 'android-chrome', use: { ...devices['Pixel 7'] } },
    { name: 'instagram-ios-webview', use: {
      ...devices['iPhone 15 Pro'],
      userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 385.0.0.38.84',
    } },
    { name: 'facebook-ios-webview', use: {
      ...devices['iPhone 15 Pro'],
      userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/520.0.0.42.101]',
    } },
  ],
});
