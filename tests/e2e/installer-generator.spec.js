const { test, expect } = require('@playwright/test');

const installerUrl = process.env.RUSTDESK_INSTALLER_URL || 'http://127.0.0.1:18080/';

async function fillGenerator(page) {
  await page.goto(installerUrl);
  await page.getByLabel('GitHub 用户名').fill('jandoucn');
  await page.getByLabel('Classic PAT').fill('ghp_browser_test_token');
  await page.getByLabel('install.sh 地址').fill('https://install.example.com/install.sh');
  await page.getByLabel('外部端口').fill('8123');
}

test('desktop generator includes private GHCR credentials and selected port', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await fillGenerator(page);
  await page.getByRole('button', { name: '生成命令' }).click();
  await expect(page.locator('#command')).toHaveText(
    "curl -fsSL 'https://install.example.com/install.sh' | sudo env GHCR_USERNAME='jandoucn' GHCR_TOKEN='ghp_browser_test_token' RUSTDESK_PORT=8123 bash",
  );
  await expect(page.locator('#history-warning')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy();
});

test('390px prompt mode omits token and has no horizontal overflow', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await fillGenerator(page);
  await page.getByLabel('服务器隐藏输入 Token').check();
  await page.getByRole('button', { name: '生成命令' }).click();
  await expect(page.locator('#command')).toHaveText(
    "curl -fsSL 'https://install.example.com/install.sh' | sudo env GHCR_USERNAME='jandoucn' RUSTDESK_PORT=8123 bash",
  );
  await expect(page.locator('#history-warning')).toBeHidden();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy();
});
