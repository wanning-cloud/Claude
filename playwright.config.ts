import { defineConfig, devices } from '@playwright/test';

const chromium = process.env.PW_CHROMIUM ?? '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

export default defineConfig({
  testDir: 'e2e',
  outputDir: 'test-results',
  reporter: [['list']],
  workers: 1,
  fullyParallel: false,
  use: {
    baseURL: 'http://127.0.0.1:4173/podcast-admin/analytics/',
    launchOptions: { executablePath: chromium },
  },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 1000 }, launchOptions: { executablePath: chromium } } },
    { name: 'mobile-360', use: { viewport: { width: 360, height: 780 }, isMobile: true, hasTouch: true, launchOptions: { executablePath: chromium } } },
  ],
  webServer: [
    { command: './e2e/serve-api.sh', url: 'http://127.0.0.1:8787/podcast-admin/analytics/api/health', reuseExistingServer: false },
    {
      command: 'npx vite build && npx vite preview --port 4173 --strictPort --host 127.0.0.1',
      cwd: 'apps/web',
      url: 'http://127.0.0.1:4173/podcast-admin/analytics/',
      reuseExistingServer: false,
      timeout: 120_000,
    },
  ],
});
