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
  page.on('console', message => { if (message.type() === 'error' && !message.text().includes('status of 409')) errors.push(message.text()); });
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
  await expect(row).toBeVisible();
  await expect(page.getByText(/仍被通讯录引用/)).toBeVisible();
  expect(errors).toEqual([]);
});

test('client inventory assigns one selected client to a chosen address-book user', async ({ page, request }) => {
  const deviceId = `browser-assign-${Date.now().toString(36)}`;
  await reportClient(request, deviceId, `${deviceId}-host`);
  await loginAdmin(page);
  await page.goto(`${adminPath}/devices`);
  await page.locator('#q').fill(deviceId);
  await page.getByRole('button', { name: '搜索' }).click();
  const row = page.locator(`[data-device-id="${deviceId}"]`);
  await expect(row).toBeVisible();
  await row.getByRole('checkbox', { name: `选择客户端 ${deviceId}` }).check();
  await page.getByRole('button', { name: /加入通讯录（1）/ }).click();
  const dialog = page.getByRole('dialog', { name: '加入通讯录' });
  await expect(dialog).toBeVisible();
  const users = dialog.locator('#assignment-users input[type="checkbox"]');
  await expect(users.first()).toBeVisible();
  await users.first().check();
  await dialog.getByRole('button', { name: '应用操作' }).click();
  await expect(page.getByRole('dialog', { name: '确认通讯录变更' })).toBeVisible();
  await page.getByRole('dialog', { name: '确认通讯录变更' }).getByRole('button', { name: '确认应用' }).click();
  await expect(row).toContainText('通讯录分配：1 个用户');
  const api = await page.evaluate(async ({ adminPath, deviceId }) => (await fetch(`${adminPath}/api/devices?q=${encodeURIComponent(deviceId)}&page=1&pageSize=20`)).json(), { adminPath, deviceId });
  expect(api.data.find(item => item.id === deviceId).address_book_user_ids.length).toBe(1);
});

test('client inventory exposes advanced filters, ownership split and assignment preview', async ({ page, request }) => {
  const deviceId = `advanced-${Date.now().toString(36)}`;
  await reportRichClient(request, deviceId);
  await loginAdmin(page);
  await page.goto(`${adminPath}/devices`);
  await page.locator('#q').fill(deviceId);
  await page.getByRole('button', { name: '搜索' }).click();
  await expect(page.locator('#platform-filter')).toBeVisible();
  await expect(page.locator('#version-filter')).toBeVisible();
  await expect(page.locator('#assigned-user-filter')).toBeVisible();
  await expect(page.locator(`[data-device-id="${deviceId}"]`)).toContainText('通讯录分配：');
  await page.locator('#platform-filter').selectOption('windows');
  await page.locator('#version-filter').fill('1.5.0');
  await expect(page.locator(`[data-device-id="${deviceId}"]`)).toBeVisible();
});

test('client inventory previews a multi-device assignment before applying it', async ({ page, request }) => {
  const suffix = Date.now().toString(36);
  await reportClient(request, `multi-a-${suffix}`);
  await reportClient(request, `multi-b-${suffix}`);
  await loginAdmin(page);
  await page.goto(`${adminPath}/devices`);
  await page.locator('#q').fill(`multi-a-` + suffix);
  await page.getByRole('button', { name: '搜索' }).click();
  await expect(page.locator('#rows tr')).toHaveCount(1);
  await page.locator('#rows tr input[type="checkbox"]').check();
  await page.locator('#q').fill(`multi-b-` + suffix);
  await page.getByRole('button', { name: '搜索' }).click();
  await expect(page.locator('#rows tr')).toHaveCount(1);
  await page.locator('#rows tr input[type="checkbox"]').check();
  await expect(page.getByRole('button', { name: /加入通讯录（2）/ })).toBeEnabled();
  await page.getByRole('button', { name: /加入通讯录（2）/ }).click();
  const assignment = page.getByRole('dialog', { name: '加入通讯录' });
  await assignment.locator('#assignment-users input[type="checkbox"]').first().check();
  await assignment.getByRole('button', { name: '应用操作' }).click();
  const preview = page.getByRole('dialog', { name: '确认通讯录变更' });
  await expect(preview).toContainText('新增');
  await preview.getByRole('button', { name: '确认应用' }).click();
  await expect(page.getByText('通讯录分配已保存')).toBeVisible();
});

test('address book exposes presence filters and import/export controls on mobile', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await loginAdmin(page);
  await page.goto(`${adminPath}/address-book`);
  await expect(page.locator('#address-presence-filter')).toBeVisible();
  await expect(page.locator('#address-export-csv')).toBeVisible();
  await expect(page.locator('#address-export-json')).toBeVisible();
  await expect(page.locator('#address-import')).toBeAttached();
  expect(await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth)).toBeFalsy();
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
  await expect(row).not.toContainText(`${deviceId}-uuid`);
  await expect(row).not.toContainText('UUID');
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

  persistNetworkPayload(deviceId, `${deviceId}-uuid`, { public_ip: '172.22.0.1', geo: { city: 'stale' }, private_ips: preserved.private_ips });
  await page.reload();
  await page.locator('#q').fill(deviceId);
  await page.getByRole('button', { name: '搜索' }).click();
  const refreshedRow = page.locator(`[data-device-id="${deviceId}"]`);
  await expect(refreshedRow).toContainText('公网 IP 未上报');
  await expect(refreshedRow).not.toContainText('172.22.0.1');
  await expect(refreshedRow).not.toContainText('stale');
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

  const london = { public_ip: '81.2.69.160', geo: { country_code: 'GB', country: '英国', region: 'England', city: 'London', timezone: 'Europe/London' } };
  expect(persistNetworkPayload(deviceId, uuid, london)).toEqual(london);
  await page.locator('#refresh').click();
  await expect(row).toContainText('英国 · England · London');
  await row.getByRole('button', { name: '查看客户端详情' }).click();
  await expect(page.locator('#details-dialog')).toContainText('英国 · England · London');
  await expect(page.locator('#details-dialog')).toContainText('Europe/London');
  api = await page.evaluate(async ({ adminPath, deviceId }) => (await fetch(`${adminPath}/api/devices?q=${encodeURIComponent(deviceId)}&page=1&pageSize=20`)).json(), { adminPath, deviceId });
  expect(api.data.find(item => item.id === deviceId).geo).toEqual(london.geo);
});

test('client list omits the operating-system line and details close on outside focus', async ({ page, request }) => {
  const deviceId = `osline-${Date.now().toString(36)}`;
  const uuid = `${deviceId}-uuid`;
  await expect((await request.post('/api/heartbeat', { data: { id: deviceId, uuid, ver: 10, conns: [] } })).ok()).toBeTruthy();
  await expect((await request.post('/api/sysinfo', { data: {
    id: deviceId, uuid, hostname: `${deviceId}-host`, platform: 'windows', distribution: 'desktop', install_mode: 'installed',
    os: 'windows / Windows Server 2016 Datacenter - 10 (14393)', version: '1.5.0',
    client_id: 'RustDesk Yan', client_uuid: uuid, product: 'rustdesk-yan', edition: 'custom',
    build_number: '20260930.2', build_seq: 2026093002, channel: 'stable', arch: 'x86_64',
    source_commit: 'commit-sha', os_version: 'Windows 11',
  } })).ok()).toBeTruthy();
  await page.setViewportSize({ width: 1440, height: 900 });
  await loginAdmin(page);
  await page.goto(`${adminPath}/devices`);
  await page.locator('#q').fill(deviceId);
  await page.getByRole('button', { name: '搜索' }).click();
  const row = page.locator(`[data-device-id="${deviceId}"]`);
  await expect(row).toContainText('Windows · 安装版');
  await expect(row).toContainText('客户端：1.5.0');
  await expect(row).not.toContainText('系统版本');
  await expect(row).not.toContainText('Windows Server 2016');
  await row.getByRole('button', { name: '查看客户端详情' }).click();
  const dialog = page.locator('#details-dialog');
  await expect(dialog).toBeVisible();
  await expect(dialog).toContainText('Windows Server 2016 Datacenter');
  await expect(dialog).toContainText('客户端身份');
  await expect(dialog).toContainText('RustDesk Yan');
  await expect(dialog).toContainText('构建号');
  await expect(dialog).toContainText('20260930.2');
  await expect(dialog).toContainText('构建序号');
  await expect(dialog).toContainText('2026093002');
  await expect(dialog).toContainText('源码提交');
  await expect(dialog).toContainText('commit-sha');
  await expect(dialog).not.toContainText('原始遥测');
  await expect(dialog).not.toContainText('Heartbeat payload');
  await expect(dialog).not.toContainText('Runtime payload');
  await expect(dialog.locator('#details-close')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth)).toBeFalsy();
  expect(await dialog.evaluate(node => {
    const box = node.getBoundingClientRect();
    const close = node.querySelector('#details-close').getBoundingClientRect();
    return box.top >= 0 && box.bottom <= window.innerHeight + 1 && close.top >= box.top && close.bottom <= window.innerHeight + 1 && close.width > 0;
  })).toBeTruthy();
  await page.mouse.click(6, 6);
  await expect(dialog).toBeHidden();
  await row.getByRole('button', { name: '查看客户端详情' }).click();
  await dialog.locator('#details-close').click();
  await expect(dialog).toBeHidden();
  await page.setViewportSize({ width: 390, height: 844 });
  await row.getByRole('button', { name: '查看客户端详情' }).click();
  await expect(dialog).toBeVisible();
  await expect(dialog.locator('#details-close')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth)).toBeFalsy();
  await page.mouse.click(4, 4);
  await expect(dialog).toBeHidden();
});

test('desktop release renders as installed and keeps UUID out of the inventory row', async ({ page, request }) => {
  const deviceId = `desktop-release-${Date.now().toString(36)}`;
  const uuid = `${deviceId}-internal-uuid`;
  await expect((await request.post('/api/heartbeat', { data: { id: deviceId, uuid, ver: 150, conns: [] } })).ok()).toBeTruthy();
  await expect((await request.post('/api/sysinfo', { data: {
    id: deviceId, uuid, hostname: 'desktop-release-host', platform: 'windows', os: 'Windows 11',
    version: '1.5.0', distribution: 'desktop',
  } })).ok()).toBeTruthy();
  await loginAdmin(page);
  await page.goto(`${adminPath}/devices`);
  await page.locator('#q').fill(deviceId);
  await page.getByRole('button', { name: '搜索' }).click();
  const row = page.locator(`[data-device-id="${deviceId}"]`);
  await expect(row).toContainText('Windows · 安装版');
  await expect(row).toContainText(deviceId);
  await expect(row).not.toContainText(uuid);
  await expect(row).not.toContainText('UUID');
});

test('inventory backfills GeoLite region and timezone for a stored public IP', async ({ page, request }) => {
  test.skip(!process.env.RUSTDESK_API_CONTAINER, 'requires the real MMDB in the API container');
  const deviceId = `geo-backfill-${Date.now().toString(36)}`;
  const uuid = `${deviceId}-uuid`;
  await reportClient(request, deviceId);
  expect(persistNetworkPayload(deviceId, uuid, { public_ip: '81.2.69.160' })).toEqual({ public_ip: '81.2.69.160' });
  await loginAdmin(page);
  await page.goto(`${adminPath}/devices`);
  await page.locator('#q').fill(deviceId);
  await page.getByRole('button', { name: '搜索' }).click();
  const row = page.locator(`[data-device-id="${deviceId}"]`);
  await expect(row).toContainText('81.2.69.160');
  await expect(row.locator('.network-location')).not.toBeEmpty();
  const api = await page.evaluate(async ({ adminPath, deviceId }) => (await fetch(`${adminPath}/api/devices?q=${encodeURIComponent(deviceId)}&page=1&pageSize=20`)).json(), { adminPath, deviceId });
  const device = api.data.find(item => item.id === deviceId);
  expect(device.geo.country_code).toBe('GB');
  expect(device.geo.timezone).toBe('Europe/London');
  await row.getByRole('button', { name: '查看客户端详情' }).click();
  await expect(page.locator('#details-dialog')).toContainText('Europe/London');
  await expect(page.locator('#details-dialog').getByText('地区', { exact: true })).toBeVisible();
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
