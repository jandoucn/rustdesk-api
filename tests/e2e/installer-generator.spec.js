const { test, expect } = require('@playwright/test');

const installerUrl = process.env.RUSTDESK_INSTALLER_URL || 'http://127.0.0.1:18080/';

async function fillGenerator(page) {
  await page.goto(installerUrl);
  await page.getByLabel('ACR 用户名').fill('yanolly');
  await page.getByLabel('ACR 固定密码').fill('acr_browser_test_password');
  await page.getByLabel('install.sh 地址').fill('https://shell.olii.cc/install.sh');
  await page.getByLabel('外部端口').fill('8123');
}

test('desktop generator includes private ACR credentials and selected port', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await fillGenerator(page);
  await page.getByRole('button', { name: '生成命令' }).click();
  const command = await page.locator('#command').textContent();
  expect(command).toContain("curl -fsSL 'https://shell.olii.cc/install.sh'");
  expect(command).toContain("REGISTRY_USERNAME='yanolly' REGISTRY_PASSWORD='acr_browser_test_password' RUSTDESK_PORT=8123 bash \"$f\"");
  expect(command).toContain('sha256sum -c -');
  expect(command).toContain('686995bf512fb2fdd28e68ad9431627541957f80566c39635102fee529f70e82');
  await expect(page.locator('#history-warning')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy();
});

test('390px prompt mode omits token and has no horizontal overflow', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await fillGenerator(page);
  await page.getByLabel('服务器隐藏输入密码').check();
  await page.getByRole('button', { name: '生成命令' }).click();
  const command = await page.locator('#command').textContent();
  expect(command).toContain("REGISTRY_USERNAME='yanolly' RUSTDESK_PORT=8123 bash \"$f\"");
  expect(command).not.toContain('REGISTRY_PASSWORD=');
  await expect(page.locator('#history-warning')).toBeHidden();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy();
});
