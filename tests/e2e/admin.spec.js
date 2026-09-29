const { test, expect } = require('@playwright/test');

const adminPath = process.env.RUSTDESK_ADMIN_PATH || '/ops-x9';

async function loginAdmin(page) {
  await page.goto(adminPath);
  await page.locator('#lu').fill('admin');
  await page.locator('#lp').fill('admin123');
  await page.getByRole('button', { name: '登录' }).click();
  await expect(page.locator('#app')).toBeVisible();
}

function collectPageErrors(page) {
  const errors = [];
  page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
  page.on('pageerror', error => errors.push(error.message));
  return errors;
}

async function reportClient(request, id, hostname = `${id}-host`) {
  const uuid = `${id}-uuid`;
  const heartbeat = await request.post('/api/heartbeat', {
    data: { id, uuid, ver: 10, conns: [], modified_at: 0 },
  });
  expect(heartbeat.ok()).toBeTruthy();
  const sysinfo = await request.post('/api/sysinfo', {
    data: { id, uuid, hostname, username: 'browser-operator', os: 'linux', version: '1.4.6' },
  });
  expect(sysinfo.ok()).toBeTruthy();
}

test('public routing and user CRUD work through the real browser', async ({ page }) => {
  const suffix = Date.now().toString(36);
  const createdName = `browser-${suffix}`;
  const renamedName = `renamed-${suffix}`;
  const errors = collectPageErrors(page);

  await page.goto('/');
  await expect(page.getByRole('heading', { name: 'RustDesk API' })).toBeVisible();
  await page.goto('/admin');
  await expect(page.getByRole('heading', { name: 'RustDesk API' })).toBeVisible();
  await loginAdmin(page);

  await page.getByRole('button', { name: '新建用户' }).click();
  await expect(page.locator('#cd')).toBeVisible();
  await page.locator('#nu').fill(createdName);
  await page.locator('#np').fill('1');
  await page.getByRole('dialog', { name: '新建用户' }).getByRole('button', { name: '创建' }).click();
  await expect(page.locator('#cd')).not.toBeVisible();
  await page.getByPlaceholder('搜索用户名').fill(createdName);
  await page.getByRole('button', { name: '搜索' }).click();
  const createdRow = page.locator('tbody tr').filter({ hasText: createdName });
  await expect(createdRow).toHaveCount(1);

  await createdRow.getByRole('button', { name: '编辑' }).click();
  await page.locator('#eu').fill(renamedName);
  await page.locator('#ee').uncheck();
  await page.getByRole('dialog').getByRole('button', { name: '保存' }).click();
  await expect(page.locator('#ed')).not.toBeVisible();
  await page.getByPlaceholder('搜索用户名').fill(renamedName);
  await page.getByRole('button', { name: '搜索' }).click();
  const renamedRow = page.locator('tbody tr').filter({ hasText: renamedName });
  await expect(renamedRow.getByText('停用', { exact: true })).toBeVisible();

  page.once('dialog', dialog => dialog.accept());
  await renamedRow.getByRole('button', { name: '删除' }).click();
  await expect(page.getByText('没有匹配的用户。')).toBeVisible();
  expect(errors).toEqual([]);
});

test('heartbeat-only client supports status filtering, alias sync and confirmed removal', async ({ page, request }) => {
  const suffix = Date.now().toString(36);
  const deviceId = `browser-heartbeat-${suffix}`;
  const hostname = `browser-host-${suffix}`;
  const alias = `机房入口-${suffix}`;
  const errors = collectPageErrors(page);
  await reportClient(request, deviceId, hostname);
  await loginAdmin(page);
  await page.getByRole('link', { name: '客户端管理' }).click();

  await expect(page.getByText('全部客户端', { exact: true })).toBeVisible();
  await page.locator('#q').fill(hostname);
  await page.getByRole('button', { name: '搜索' }).click();
  const row = page.locator(`[data-device-id="${deviceId}"]`);
  await expect(row).toBeVisible();
  await expect(row.getByText('在线', { exact: true })).toBeVisible();
  await expect(row.getByText('未备注', { exact: true })).toBeVisible();

  await page.locator('#presence-filter').selectOption('online');
  await expect(row).toBeVisible();
  await row.getByRole('button', { name: '编辑备注' }).click();
  await expect(page.locator('#alias-dialog')).toBeVisible();
  await page.locator('#alias-input').fill(alias);
  await page.getByRole('button', { name: '保存备注' }).click();
  await expect(row.getByText(alias, { exact: true })).toBeVisible();
  await expect(page.getByText('备注已保存，客户端将在下次通讯录同步时收到更新')).toBeVisible();

  await page.locator('#labelled-only').check();
  await expect(row).toBeVisible();
  const api = await page.evaluate(async ({ adminPath, deviceId }) => {
    const response = await fetch(`${adminPath}/api/devices?q=${encodeURIComponent(deviceId)}&page=1&pageSize=20`);
    return response.json();
  }, { adminPath, deviceId });
  expect(api.data.find(item => item.id === deviceId).alias).toBe(alias);

  page.once('dialog', dialog => dialog.accept());
  await row.getByRole('button', { name: '移除' }).click();
  await expect(row).toHaveCount(0);
  const removed = await page.evaluate(async ({ adminPath, deviceId }) => {
    const response = await fetch(`${adminPath}/api/devices?q=${encodeURIComponent(deviceId)}&page=1&pageSize=20`);
    return response.json();
  }, { adminPath, deviceId });
  expect(removed.data).toEqual([]);
  expect(errors).toEqual([]);
});

test('client inventory refreshes every five seconds and pauses while hidden', async ({ page, request }) => {
  const suffix = Date.now().toString(36);
  const firstId = `refresh-first-${suffix}`;
  const secondId = `refresh-second-${suffix}`;
  await reportClient(request, firstId);
  await loginAdmin(page);

  let listRequests = 0;
  page.on('request', req => {
    if (req.method() === 'GET' && req.url().includes(`${adminPath}/api/devices`)) listRequests += 1;
  });
  await page.getByRole('link', { name: '客户端管理' }).click();
  await expect(page.locator('#auto-refresh')).toBeChecked();
  await page.locator('#q').fill(suffix);
  await page.getByRole('button', { name: '搜索' }).click();
  await expect(page.locator(`[data-device-id="${firstId}"]`)).toBeVisible();

  await reportClient(request, secondId);
  await expect(page.locator(`[data-device-id="${secondId}"]`)).toBeVisible({ timeout: 7_000 });
  const beforeHidden = listRequests;
  await page.evaluate(() => {
    Object.defineProperty(document, 'hidden', { configurable: true, value: true });
    document.dispatchEvent(new Event('visibilitychange'));
  });
  await page.waitForTimeout(5_500);
  expect(listRequests).toBe(beforeHidden);
});

test('client inventory loads and manages devices beyond the first 200 rows', async ({ page, request }) => {
  test.setTimeout(60_000);
  const suffix = Date.now().toString(36);
  const ids = Array.from({ length: 205 }, (_, index) => `bulk-${suffix}-${String(index).padStart(3, '0')}`);
  const responses = await Promise.all(ids.map(id => request.post('/api/heartbeat', {
    data: { id, uuid: `${id}-uuid`, ver: 10, conns: [], modified_at: 0 },
  })));
  expect(responses.every(response => response.ok())).toBeTruthy();

  await loginAdmin(page);
  await page.getByRole('link', { name: '客户端管理' }).click();
  await page.locator('#q').fill(`bulk-${suffix}`);
  await page.getByRole('button', { name: '搜索' }).click();
  await expect(page.locator(`[data-device-id="${ids[204]}"]`)).toBeVisible({ timeout: 15_000 });
  await expect(page.locator('#stat-total')).toHaveText('205');
});

test('client management has no horizontal page overflow on mobile', async ({ page, request }) => {
  const suffix = Date.now().toString(36);
  await reportClient(request, `mobile-client-${suffix}`, `mobile-host-${suffix}`);
  await page.setViewportSize({ width: 390, height: 844 });
  await loginAdmin(page);
  await page.getByRole('navigation', { name: '移动端导航' }).getByRole('link', { name: '客户端', exact: true }).click();
  await expect(page.getByRole('heading', { name: '客户端管理' })).toBeVisible();
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
  expect(overflow).toBeFalsy();
  await expect(page.locator('#q')).toBeVisible();
  await expect(page.locator('#presence-filter')).toBeVisible();
});

test('personal address book manages peers tags favorites and confirmation dialogs', async ({ page }) => {
  const suffix = Date.now().toString(36);
  const peerId = `book-peer-${suffix}`;
  const alias = `入口电脑-${suffix}`;
  const renamedAlias = `入口电脑-更新-${suffix}`;
  const tag = `运维-${suffix}`;
  const errors = collectPageErrors(page);
  await loginAdmin(page);
  await page.getByRole('link', { name: '通讯录管理' }).click();
  await expect(page.getByRole('heading', { name: '通讯录管理' })).toBeVisible();
  await expect(page.getByText('后台收藏只用于此管理页面筛选，不会同步到 RustDesk 客户端本机的原生收藏栏。')).toBeVisible();

  await page.getByRole('button', { name: '新增客户端' }).click();
  await page.locator('#peer-id').fill(`cancelled-${suffix}`);
  await page.getByRole('dialog', { name: '新增客户端' }).getByRole('button', { name: '取消' }).click();
  await expect(page.locator('#peer-dialog')).not.toBeVisible();

  await page.getByRole('button', { name: '标签管理' }).click();
  await page.locator('#tag-name').fill(tag);
  await page.locator('#tag-color').fill('#176b87');
  await page.getByRole('dialog', { name: '标签管理' }).getByRole('button', { name: '新增标签' }).click();
  await expect(page.locator(`[data-tag-name="${tag}"]`)).toBeVisible();
  await page.locator('#tag-close').click();

  await page.getByRole('button', { name: '新增客户端' }).click();
  await page.locator('#peer-id').fill(peerId);
  await page.locator('#peer-alias').fill(alias);
  await page.locator('#peer-note').fill('桌面浏览器 CRUD 实测');
  await page.getByRole('group', { name: '客户端标签' }).getByText(tag, { exact: true }).click();
  await page.getByRole('dialog', { name: '新增客户端' }).getByRole('button', { name: '新增客户端' }).click();
  await expect(page.locator('#peer-dialog')).not.toBeVisible();
  await expect(page.locator('#address-book-status')).toContainText('通讯录已保存');
  await page.locator('#address-search').fill(peerId);
  await page.getByRole('button', { name: '搜索' }).click();
  const row = page.locator(`[data-peer-id="${peerId}"]`);
  await expect(row).toBeVisible();
  await expect(row.getByText(alias, { exact: true })).toBeVisible();
  await expect(row.getByText(tag, { exact: true })).toBeVisible();
  await expect(row.getByText('桌面浏览器 CRUD 实测', { exact: true })).toBeVisible();

  await row.getByRole('button', { name: '收藏', exact: true }).click();
  await expect(row.getByRole('button', { name: '取消收藏', exact: true })).toHaveAttribute('aria-pressed', 'true');
  await page.locator('#favorite-only').check();
  await expect(row).toBeVisible();
  await page.locator('#favorite-only').uncheck();

  await row.getByRole('button', { name: '编辑客户端' }).click();
  await expect(page.locator('#peer-id')).toBeDisabled();
  await page.locator('#peer-alias').fill(renamedAlias);
  await page.locator('#peer-note').fill('已通过页面更新');
  await page.getByRole('dialog', { name: '编辑客户端' }).getByRole('button', { name: '保存修改' }).click();
  await expect(row.getByText(renamedAlias, { exact: true })).toBeVisible();
  await expect(row.getByText('已通过页面更新', { exact: true })).toBeVisible();

  await row.getByRole('button', { name: '删除客户端' }).click();
  await expect(page.getByRole('dialog', { name: '删除通讯录客户端' })).toBeVisible();
  await page.locator('#confirm-cancel').click();
  await expect(row).toBeVisible();
  await row.getByRole('button', { name: '删除客户端' }).click();
  await page.getByRole('dialog', { name: '删除通讯录客户端' }).getByRole('button', { name: '删除客户端' }).click();
  await expect(row).toHaveCount(0);

  await page.getByRole('button', { name: '标签管理' }).click();
  const tagRow = page.locator(`[data-tag-name="${tag}"]`);
  await tagRow.getByRole('button', { name: `删除标签 ${tag}` }).click();
  await page.getByRole('dialog', { name: '删除标签' }).getByRole('button', { name: '删除标签' }).click();
  await expect(page.locator(`[data-tag-name="${tag}"]`)).toHaveCount(0);
  expect(errors).toEqual([]);
});

test('personal address book is usable without horizontal overflow at 390px', async ({ page }) => {
  const suffix = Date.now().toString(36);
  const peerId = `mobile-book-${suffix}`;
  const errors = collectPageErrors(page);
  await page.setViewportSize({ width: 390, height: 844 });
  await loginAdmin(page);
  await page.getByRole('navigation', { name: '移动端导航' }).getByRole('link', { name: '通讯录', exact: true }).click();
  await expect(page.getByRole('heading', { name: '通讯录管理' })).toBeVisible();
  await page.getByRole('button', { name: '新增客户端' }).click();
  await page.locator('#peer-id').fill(peerId);
  await page.locator('#peer-alias').fill('移动端电脑');
  await page.getByRole('dialog', { name: '新增客户端' }).getByRole('button', { name: '新增客户端' }).click();
  await expect(page.locator('#peer-dialog')).not.toBeVisible();
  await expect(page.locator('#address-book-status')).toContainText('通讯录已保存');
  await page.locator('#address-search').fill(peerId);
  await page.getByRole('button', { name: '搜索' }).click();
  const row = page.locator(`[data-peer-id="${peerId}"]`);
  await expect(row).toBeVisible();
  await row.getByRole('button', { name: '收藏', exact: true }).click();
  await expect(row.getByRole('button', { name: '取消收藏', exact: true })).toBeVisible();
  await row.getByRole('button', { name: '编辑客户端' }).click();
  await page.locator('#peer-alias').fill('移动端电脑-更新');
  await page.getByRole('dialog', { name: '编辑客户端' }).getByRole('button', { name: '保存修改' }).click();
  await expect(row.getByText('移动端电脑-更新', { exact: true })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth)).toBeFalsy();
  await row.getByRole('button', { name: '删除客户端' }).click();
  await page.getByRole('dialog', { name: '删除通讯录客户端' }).getByRole('button', { name: '删除客户端' }).click();
  await expect(row).toHaveCount(0);
  expect(errors).toEqual([]);
});

test('personal address book paginates all 205 matching peers', async ({ page }) => {
  test.setTimeout(90_000);
  const suffix = Date.now().toString(36);
  await loginAdmin(page);
  const session = await page.request.get(`${adminPath}/api/session`);
  expect(session.ok()).toBeTruthy();
  const { csrf } = await session.json();
  const responses = [];
  for (let index = 0; index < 205; index += 1) {
    const id = `browser-book-${suffix}-${String(index).padStart(3, '0')}`;
    responses.push(await page.request.post(`${adminPath}/api/address-book/peers`, {
      headers: { 'X-CSRF-Token': csrf },
      data: { id, alias: `分页-${String(index).padStart(3, '0')}`, tags: [] },
    }));
  }
  expect(responses.every(response => response.ok())).toBeTruthy();
  await page.getByRole('link', { name: '通讯录管理' }).click();
  await page.locator('#address-search').fill(`browser-book-${suffix}`);
  await page.getByRole('button', { name: '搜索' }).click();
  await expect(page.locator('#address-page-info')).toContainText('共 205');
  for (let pageNumber = 2; pageNumber <= 5; pageNumber += 1) {
    await page.locator('#address-next').click();
    await expect(page.locator('#address-page-info')).toContainText(`第 ${pageNumber}/5 页`);
  }
  await expect(page.locator(`[data-peer-id="browser-book-${suffix}-204"]`)).toBeVisible();
});
