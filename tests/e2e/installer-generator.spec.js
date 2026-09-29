const { test, expect } = require('@playwright/test');

const installerUrl = process.env.RUSTDESK_INSTALLER_URL || 'http://127.0.0.1:18080/';

async function fillGenerator(page) {
  await page.goto(installerUrl);
  await page.getByLabel('GitHub 用户名').fill('jandoucn');
  await page.getByLabel('Classic PAT').fill('ghp_browser_test_token');
  await page.getByLabel('install.sh 地址（默认 GitHub 加速）').fill('https://ghfast.top/https://raw.githubusercontent.com/jandoucn/rustdesk-api/main/installer/install.sh');
  await page.getByLabel('外部端口').fill('8123');
}

test('desktop generator includes private GHCR credentials and selected port', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await fillGenerator(page);
  await page.getByRole('button', { name: '生成命令' }).click();
  const command = await page.locator('#command').textContent();
  expect(command).toContain("curl -fsSL 'https://ghfast.top/https://raw.githubusercontent.com/jandoucn/rustdesk-api/main/installer/install.sh'");
  expect(command).toContain("GHCR_USERNAME='jandoucn' GHCR_TOKEN='ghp_browser_test_token' RUSTDESK_PORT=8123 bash \"$f\"");
  expect(command).toContain('sha256sum -c -');
  expect(command).toContain('519b2181c5088b3c732d1acc75859cef141153d2c027a6d6628e7dda4be31bae');
  await expect(page.locator('#history-warning')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy();
});

test('390px prompt mode omits token and has no horizontal overflow', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await fillGenerator(page);
  await page.getByLabel('服务器隐藏输入 Token').check();
  await page.getByRole('button', { name: '生成命令' }).click();
  const command = await page.locator('#command').textContent();
  expect(command).toContain("GHCR_USERNAME='jandoucn' RUSTDESK_PORT=8123 bash \"$f\"");
  expect(command).not.toContain('GHCR_TOKEN=');
  await expect(page.locator('#history-warning')).toBeHidden();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy();
});
