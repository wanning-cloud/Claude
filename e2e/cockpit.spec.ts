import { expect, test } from '@playwright/test';

// Runs against TEST data from apps/api/bin/seed-test.php.

const PAGES = ['', 'folgen', 'folgen/7', 'portale/youtube', 'social', 'social?kanal=instagram', 'kommentare', 'automatik'];

test('without podcast-admin session the cockpit sends you to the login', async ({ page }) => {
  await page.route('**/api/session', (route) =>
    route.fulfill({
      status: 401,
      contentType: 'application/json',
      body: JSON.stringify({ error: 'Nicht angemeldet.', loginUrl: '/podcast-admin/?login=test' }),
    }),
  );
  await page.route('**/podcast-admin/?login=test', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<h1>podcast-admin Login</h1>' }));
  await page.goto('./');
  await expect(page).toHaveURL(/podcast-admin\/\?login=test/);
});

test('overview: total reach equals the sum of the lead values', async ({ page }) => {
  await page.goto('./?range=28');
  const panel = page.getByRole('region', { name: 'Reichweite gesamt' });
  await expect(panel).toBeVisible();
  const total = Number((await panel.locator('p').nth(0).innerText()).replace(/\./g, ''));
  const formula = await panel.locator('p').last().innerText();
  const parts = [...formula.matchAll(/(\d[\d.]*)/g)].map((m) => Number(m[1]!.replace(/\./g, '')));
  expect(parts.length).toBeGreaterThan(1);
  expect(parts.reduce((a, b) => a + b, 0)).toBe(total);
  await expect(page.getByText('Website-Player').first()).toBeVisible();
});

test('inbox: answer a Spotify comment goes into the routine queue', async ({ page }, info) => {
  const answer = `Ja, auch dann. Entscheidend ist die Beherbergung. (${info.project.name})`;
  await page.goto('kommentare?platform=spotify');
  await page.getByRole('link', { name: /Anna/ }).click();
  await page.getByLabel(/Deine Antwort/).fill(answer);
  await expect(page.getByText('Vorschau')).toBeVisible();
  await page.getByRole('button', { name: 'In Warteschlange (nächster Routine-Lauf)' }).click();
  await expect(page.getByText('Wartet auf Routine').first()).toBeVisible();
  await page.goto('automatik');
  await expect(page.getByText(answer)).toBeVisible();
});

test('inbox: Apple reviews cannot be answered', async ({ page }) => {
  await page.goto('kommentare?platform=apple');
  await page.getByRole('link', { name: /Vermieter aus Bingen/ }).click();
  await expect(page.getByText('Antwort bei Apple nicht möglich.')).toBeVisible();
});

test('social media has its own numbers and never touches the podcast reach', async ({ page }) => {
  await page.goto('./?range=28');
  const reach = await page.getByRole('region', { name: 'Reichweite gesamt' }).locator('p').nth(0).innerText();
  await page.goto('social?range=28');
  await expect(page.getByRole('heading', { name: 'Social Media', level: 1 })).toBeVisible();
  await expect(page.getByText('zählen nie in die Podcast-Reichweite')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Welche Serie trägt?' })).toBeVisible();
  await expect(page.getByRole('cell', { name: 'Fehler der Woche' }).first()).toBeVisible();
  await page.getByRole('button', { name: 'Instagram', exact: true }).click();
  await expect(page).toHaveURL(/kanal=instagram/);
  await expect(page.getByRole('heading', { name: 'Instagram', level: 2 })).toBeVisible();
  await page.goto('./?range=28');
  await expect(page.getByRole('region', { name: 'Reichweite gesamt' }).locator('p').nth(0)).toHaveText(reach);
});

test('inbox: social area filter shows Instagram, Facebook and Shorts comments', async ({ page }) => {
  await page.goto('kommentare?area=social');
  await expect(page.getByRole('link', { name: /vermieterin_nrw/ })).toBeVisible();
  await expect(page.getByRole('link', { name: /Disponent Jörg/ })).toBeVisible();
  await expect(page.getByRole('link', { name: /Handwerker Ben/ })).toBeVisible();
  await expect(page.getByRole('link', { name: /Anna/ })).toHaveCount(0);
});

for (const path of PAGES) {
  test(`no horizontal page scroll: /${path}`, async ({ page }, info) => {
    await page.goto(`./${path}`);
    await page.waitForLoadState('networkidle');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(0);
    await page.screenshot({ path: info.outputPath(`${path.replace(/[/?=]/g, '-') || 'overview'}.png`), fullPage: true });
  });
}
