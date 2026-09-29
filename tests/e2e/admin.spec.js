const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');

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

async function reportRichClient(request, id) {
  const uuid = `${id}-uuid`;
  await expect((await request.post('/api/heartbeat', { data: { id, uuid, ver: 150, conns: [], modified_at: 0 } })).ok()).toBeTruthy();
  const response = await request.post('/api/sysinfo', { data: {
    id, uuid, hostname: `${id}-host`, username: 'mobile-user', os: 'windows 11', cpu: 'Test CPU', memory: '16 GB', version: '1.5.0',
    platform: 'windows', distribution: 'sos', install_mode: 'portable', client_arch: 'x64', executable_name: 'RustDesk.exe',
    network: { private_ips: ['192.168.1.20', '10.0.0.8', 'fd12:3456:789a::20'] },
  } });
  expect(response.ok()).toBeTruthy();
}

function persistNetworkPayload(id, uuid, network) {
  const container = process.env.RUSTDESK_API_CONTAINER;
  if (!container) throw new Error('RUSTDESK_API_CONTAINER is required for persisted Geo E2E');
  const encoded = Buffer.from(JSON.stringify(network), 'utf8').toString('base64');
  const php = [
    "require '/var/www/html/lib.php';",
    '$db=open_database();',
    "$payload=base64_decode((string)getenv('TEST_NETWORK_PAYLOAD'),true);",
    "if($payload===false)throw new RuntimeException('invalid payload');",
    "db_exec($db,'UPDATE device_reports SET network_payload=:payload WHERE id=:id AND uuid=:uuid',['payload'=>$payload,'id'=>getenv('TEST_DEVICE_ID'),'uuid'=>getenv('TEST_DEVICE_UUID')]);",
    "echo (string)db_one($db,'SELECT network_payload FROM device_reports WHERE id=:id AND uuid=:uuid',['id'=>getenv('TEST_DEVICE_ID'),'uuid'=>getenv('TEST_DEVICE_UUID')])['network_payload'];",
  ].join('');
  const output = execFileSync('docker', [
    'exec', '-e', `TEST_DEVICE_ID=${id}`, '-e', `TEST_DEVICE_UUID=${uuid}`,
    '-e', `TEST_NETWORK_PAYLOAD=${encoded}`, container, 'php', '-r', php,
  ], { encoding: 'utf8' });
  return JSON.parse(output.trim());
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

test('client inventory keeps runtime and network details aligned with persisted report data', async ({ page, request }) => {
  const suffix = Date.now().toString(36);
  const deviceId = `rich-${suffix}`;
  await reportRichClient(request, deviceId);
  const adminPath = process.env.RUSTDESK_ADMIN_PATH || '/ops-x9';
  await loginAdmin(page);
  await page.goto(`${adminPath}/devices`);
  await page.locator('#q').fill(deviceId);
  await page.getByRole('button', { name: '搜索' }).click();
  const row = page.locator(`[data-device-id="${deviceId}"]`);
  await expect(row).toContainText('SOS');
  await expect(row).toContainText('1.5.0');
  await expect(row).not.toContainText('个内网地址');
  await row.getByRole('button', { name: '查看客户端详情' }).click();
  await expect(page.locator('#details-dialog')).toContainText('Windows');
  await expect(page.locator('#details-dialog')).toContainText('SOS');
  await expect(page.locator('#details-dialog')).toContainText('Test CPU');
  await expect(page.locator('#details-dialog')).toContainText('16 GB');
  await expect(page.locator('#details-dialog')).toContainText('10.0.0.8');
  await expect(page.locator('#details-dialog')).toContainText('fd12:3456:789a::20');
  await page.locator('#details-close').click();
  const api = await page.evaluate(async ({ adminPath, deviceId }) => (await fetch(`${adminPath}/api/devices?q=${encodeURIComponent(deviceId)}&page=1&pageSize=20`)).json(), { adminPath, deviceId });
  const device = api.data.find(item => item.id === deviceId);
  expect(device.distribution).toBe('sos');
  expect(device.install_mode).toBe('portable');
  expect(device.private_ips).toEqual(['192.168.1.20', '10.0.0.8', 'fd12:3456:789a::20']);
  await request.post('/api/sysinfo', { data: { id: deviceId, uuid: `${deviceId}-uuid`, hostname: `${deviceId}-host`, os: 'windows 11', version: '1.5.0' } });
  const legacyReadback = await page.evaluate(async ({ adminPath, deviceId }) => (await fetch(`${adminPath}/api/devices?q=${encodeURIComponent(deviceId)}&page=1&pageSize=20`)).json(), { adminPath, deviceId });
  const preserved = legacyReadback.data.find(item => item.id === deviceId);
  expect(preserved.distribution).toBe('sos');
  expect(preserved.private_ips).toEqual(['192.168.1.20', '10.0.0.8', 'fd12:3456:789a::20']);
});

test('client inventory formats domestic and foreign IP locations without repeating China', async ({ page, request }) => {
  test.skip(!process.env.RUSTDESK_API_CONTAINER, 'requires direct SQL assertions in the API container');
  const deviceId = `geo-format-${Date.now().toString(36)}`;
  const uuid = `${deviceId}-uuid`;
  await reportClient(request, deviceId);
  const guangdong = { public_ip: '223.5.5.5', geo: { country_code: 'CN', country: '中国', region: '广东', city: '深圳' } };
  expect(persistNetworkPayload(deviceId, uuid, guangdong)).toEqual(guangdong);
  await loginAdmin(page);
  await page.goto(`${adminPath}/devices`);
  await page.locator('#q').fill(deviceId);
  await page.getByRole('button', { name: '搜索' }).click();
  const row = page.locator(`[data-device-id="${deviceId}"]`);
  await expect(row).toContainText('223.5.5.5');
  await expect(row).toContainText('广东 · 深圳');
  await expect(row).not.toContainText('中国');
  await expect(row).not.toContainText('个内网地址');
  let api = await page.evaluate(async ({ adminPath, deviceId }) => (await fetch(`${adminPath}/api/devices?q=${encodeURIComponent(deviceId)}&page=1&pageSize=20`)).json(), { adminPath, deviceId });
  expect(api.data.find(item => item.id === deviceId).geo).toEqual(guangdong.geo);

  const shanghai = { public_ip: '223.6.6.6', geo: { country_code: 'CN', country: '中国', region: '上海', city: '上海' } };
  expect(persistNetworkPayload(deviceId, uuid, shanghai)).toEqual(shanghai);
  await page.locator('#refresh').click();
  await expect(row.getByText('上海', { exact: true })).toBeVisible();
  await expect(row).not.toContainText('上海 · 上海');

  const london = { public_ip: '81.2.69.160', geo: { country_code: 'GB', country: '英国', region: 'England', city: 'London' } };
  expect(persistNetworkPayload(deviceId, uuid, london)).toEqual(london);
  await page.locator('#refresh').click();
  await expect(row).toContainText('英国 · England · London');
  await row.getByRole('button', { name: '查看客户端详情' }).click();
  await expect(page.locator('#details-dialog')).toContainText('英国 · England · London');
  api = await page.evaluate(async ({ adminPath, deviceId }) => (await fetch(`${adminPath}/api/devices?q=${encodeURIComponent(deviceId)}&page=1&pageSize=20`)).json(), { adminPath, deviceId });
  expect(api.data.find(item => item.id === deviceId).geo).toEqual(london.geo);
});

test('client inventory keeps delimiter-bearing composite identities distinct across refresh', async ({ page, request }) => {
  const suffix = Date.now().toString(36);
  const devices = [
    { id: `${suffix}|left`, uuid: 'right', hostname: 'delimiter-first' },
    { id: suffix, uuid: 'left|right', hostname: 'delimiter-second' },
  ];
  for (const device of devices) {
    await expect((await request.post('/api/sysinfo', { data: { ...device, version: '1.5.0' } })).ok()).toBeTruthy();
  }
  await loginAdmin(page);
  await page.goto(`${adminPath}/devices`);
  await page.locator('#q').fill(suffix);
  await page.getByRole('button', { name: '搜索' }).click();
  await expect(page.locator('#rows tr')).toHaveCount(2);
  await expect(page.getByText('delimiter-first', { exact: true })).toBeVisible();
  await expect(page.getByText('delimiter-second', { exact: true })).toBeVisible();
  await page.locator('#refresh').click();
  await expect(page.locator('#rows tr')).toHaveCount(2);
  const keys = await page.locator('#rows tr').evaluateAll(rows => rows.map(row => row.dataset.deviceKey));
  expect(new Set(keys).size).toBe(2);
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

test('long inventory keeps its scroll anchor and focused control when a row changes during refresh', async ({ page, request }) => {
  const suffix = Date.now().toString(36);
  const ids = Array.from({ length: 45 }, (_, index) => `stable-${suffix}-${String(index).padStart(2, '0')}`);
  await Promise.all(ids.map(id => reportClient(request, id)));
  const deviceId = ids[22];
  await loginAdmin(page);
  await page.getByRole('link', { name: '客户端管理' }).click();
  await page.locator('#q').fill(`stable-${suffix}`);
  await page.getByRole('button', { name: '搜索' }).click();
  const row = page.locator(`[data-device-id="${deviceId}"]`);
  const details = row.getByRole('button', { name: '查看客户端详情' });
  await row.scrollIntoViewIfNeeded();
  await details.focus();
  const beforeScroll = await page.evaluate(() => window.scrollY);
  await row.evaluate(element => { element.dataset.persistenceProbe = 'same-node'; });
  await request.post('/api/sysinfo', { data: { id: deviceId, uuid: `${deviceId}-uuid`, hostname: 'changed-during-refresh', os: 'linux', version: '1.5.0' } });
  await page.waitForTimeout(5_500);
  await expect(row).toHaveAttribute('data-persistence-probe', 'same-node');
  await expect(row).toContainText('changed-during-refresh');
  await expect(details).toBeFocused();
  expect(Math.abs((await page.evaluate(() => window.scrollY)) - beforeScroll)).toBeLessThanOrEqual(1);
  await details.click();
  await expect(page.locator('#details-dialog')).toContainText('changed-during-refresh');
  await page.locator('#details-close').click();
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

for (const width of [320, 390, 768, 1024, 1440]) test(`client management has no horizontal page overflow at ${width}px`, async ({ page, request }) => {
  const suffix = Date.now().toString(36);
  const deviceId = `mobile-client-${suffix}`;
  if (width === 390) await reportRichClient(request, deviceId);
  else await reportClient(request, deviceId, `mobile-host-${suffix}`);
  await page.setViewportSize({ width, height: 844 });
  await loginAdmin(page);
  if (width < 768) await page.getByRole('navigation', { name: '移动端导航' }).getByRole('link', { name: '客户端', exact: true }).click();
  else await page.getByRole('link', { name: '客户端管理', exact: true }).click();
  await expect(page.getByRole('heading', { name: '客户端管理' })).toBeVisible();
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
  expect(overflow).toBeFalsy();
  await expect(page.locator('#q')).toBeVisible();
  await expect(page.locator('#presence-filter')).toBeVisible();
  await page.locator('#q').fill(`mobile-client-${suffix}`);
  await page.getByRole('button', { name: '搜索' }).click();
  await page.locator(`[data-device-id="${deviceId}"]`).getByRole('button', { name: '查看客户端详情' }).click();
  await expect(page.locator('#details-dialog')).toBeVisible();
  if (width === 390) await expect(page.locator('#details-dialog')).toContainText('fd12:3456:789a::20');
  await expect(page.locator('#details-close')).toBeVisible();
  await page.locator('#details-close').click();
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
