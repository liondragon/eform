const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './specs',
  timeout: 30 * 1000,
  expect: {
    timeout: 10 * 1000
  },
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: process.env.CI ? [['list'], ['github']] : [['list']],
  use: {
    baseURL: process.env.EFORMS_E2E_BASE_URL || 'http://127.0.0.1:8080',
    headless: true,
    trace: 'retain-on-failure'
  }
});
