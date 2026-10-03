const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');

const adminPath = process.env.RUSTDESK_ADMIN_PATH || '/ops-x9';

async function loginAdmin(page) {
  await loginUser(page, 'admin', 'admin123');
}

async function loginUser(page, username, password) {
  await page.goto(adminPath);
  await page.locator('#lu').fill(username);
  await page.locator('#lp').fill(password);
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

function recordLatestUpdateLifecycle(id, uuid, action, events) {
  const container = process.env.RUSTDESK_API_CONTAINER;
  if (!container) throw new Error('RUSTDESK_API_CONTAINER is required for update lifecycle E2E');
  const encoded = Buffer.from(JSON.stringify(events), 'utf8').toString('base64');
  const php = [
    "require '/var/www/html/lib.php';",
    '$db=open_database();',
    "$row=db_one($db,'SELECT command_id FROM device_update_commands WHERE device_id=:id AND uuid=:uuid AND action=:action ORDER BY created_at DESC,command_id DESC LIMIT 1',['id'=>getenv('TEST_DEVICE_ID'),'uuid'=>getenv('TEST_DEVICE_UUID'),'action'=>getenv('TEST_COMMAND_ACTION')]);",
    "if(!$row)throw new RuntimeException('missing command');",
    "$events=json_decode((string)base64_decode((string)getenv('TEST_UPDATE_EVENTS'),true),true,32,JSON_THROW_ON_ERROR);",
    '$now=time();',
    "foreach($events as $offset=>$event){db_exec($db,'INSERT OR REPLACE INTO device_update_events(command_id,device_id,uuid,from_version,to_version,from_build_seq,to_build_seq,status,source,error_code,started_at,finished_at) VALUES(:command,:id,:uuid,:from_version,:to_version,:from_build,:to_build,:status,:source,:error,:started,:finished)',['command'=>$row['command_id'],'id'=>getenv('TEST_DEVICE_ID'),'uuid'=>getenv('TEST_DEVICE_UUID'),'from_version'=>$event['from_version']??null,'to_version'=>$event['to_version']??null,'from_build'=>$event['from_build_seq']??null,'to_build'=>$event['to_build_seq']??null,'status'=>$event['status'],'source'=>'remote_command','error'=>$event['error_code']??null,'started'=>$now+((int)$offset),'finished'=>isset($event['finished'])?$now+((int)$offset):null]);}",
    "$last=end($events);db_exec($db,'UPDATE device_update_commands SET status=:status,last_error=:error,updated_at=:updated WHERE command_id=:command',['status'=>$last['status'],'error'=>$last['error_code']??null,'updated'=>$now+count($events),'command'=>$row['command_id']]);",
    "echo $row['command_id'];",
  ].join('');
  return execFileSync('docker', [
    'exec', '-e', `TEST_DEVICE_ID=${id}`, '-e', `TEST_DEVICE_UUID=${uuid}`,
    '-e', `TEST_COMMAND_ACTION=${action}`, '-e', `TEST_UPDATE_EVENTS=${encoded}`,
    container, 'php', '-r', php,
  ], { encoding: 'utf8' }).trim();
}

function updateCommandSqlSnapshot(id, uuid) {
  const container = process.env.RUSTDESK_API_CONTAINER;
  if (!container) throw new Error('RUSTDESK_API_CONTAINER is required for persisted update-command E2E');
  const php = [
    "require '/var/www/html/lib.php';",
    '$db=open_database();',
    "$rows=db_all($db,'SELECT action,target_version,target_build_seq,status FROM device_update_commands WHERE device_id=:id AND uuid=:uuid ORDER BY action',['id'=>getenv('TEST_DEVICE_ID'),'uuid'=>getenv('TEST_DEVICE_UUID')]);",
    'echo json_encode($rows,JSON_THROW_ON_ERROR);',
  ].join('');
  const output = execFileSync('docker', [
    'exec', '-e', `TEST_DEVICE_ID=${id}`, '-e', `TEST_DEVICE_UUID=${uuid}`,
    container, 'php', '-r', php,
  ], { encoding: 'utf8' });
  return JSON.parse(output.trim());
}

function addressBookSqlSnapshot(uid, peerIds) {
  const container = process.env.RUSTDESK_API_CONTAINER;
  if (!container) throw new Error('RUSTDESK_API_CONTAINER is required for persisted address-book E2E');
  const php = [
    "require '/var/www/html/lib.php';",
    '$db=open_database();',
    '$uid=(int)getenv(\'TEST_UID\');',
    '$ids=json_decode((string)base64_decode((string)getenv(\'TEST_PEER_IDS\'),true),true,16,JSON_THROW_ON_ERROR);',
    '$profile=db_one($db,\'SELECT guid FROM ab_profiles WHERE uid=:uid AND personal=1\',[\'uid\'=>$uid]);',
    '$profileRows=$profile?db_all($db,\'SELECT id FROM ab_profile_peers WHERE guid=:guid ORDER BY id\',[\'guid\'=>$profile[\'guid\']]):[];',
    '$legacyRows=db_all($db,\'SELECT id FROM rustdesk_peers WHERE uid=:uid ORDER BY id\',[\'uid\'=>$uid]);',
    '$book=db_one($db,\'SELECT payload FROM address_books WHERE uid=:uid\',[\'uid\'=>$uid]);',
    '$payload=$book?json_decode((string)$book[\'payload\'],true,64,JSON_THROW_ON_ERROR):[];',
    '$only=static fn(array $rows)=>array_values(array_filter(array_map(static fn($row)=>(string)$row[\'id\'],$rows),static fn($id)=>in_array($id,$ids,true)));',
    '$payloadIds=array_values(array_filter(array_map(static fn($peer)=>(string)($peer[\'id\']??\'\'),is_array($payload[\'peers\']??null)?$payload[\'peers\']:[]),static fn($id)=>in_array($id,$ids,true)));sort($payloadIds);',
    'echo json_encode([\'profile\'=>$only($profileRows),\'legacy\'=>$only($legacyRows),\'payload\'=>$payloadIds],JSON_THROW_ON_ERROR);',
  ].join('');
  const encodedIds = Buffer.from(JSON.stringify(peerIds), 'utf8').toString('base64');
  const output = execFileSync('docker', [
    'exec', '-e', `TEST_UID=${uid}`, '-e', `TEST_PEER_IDS=${encodedIds}`,
    container, 'php', '-r', php,
  ], { encoding: 'utf8' });
  return JSON.parse(output.trim());
}

function addressBookTagSqlSnapshot(uid) {
  const container = process.env.RUSTDESK_API_CONTAINER;
  if (!container) throw new Error('RUSTDESK_API_CONTAINER is required for persisted address-book E2E');
  const php = [
    "require '/var/www/html/lib.php';",
    '$db=open_database();',
    '$uid=(int)getenv(\'TEST_UID\');',
    '$profile=db_one($db,\'SELECT guid FROM ab_profiles WHERE uid=:uid AND personal=1\',[\'uid\'=>$uid]);',
    '$profileTags=$profile?array_column(db_all($db,\'SELECT name FROM ab_profile_tags WHERE guid=:guid ORDER BY name\',[\'guid\'=>$profile[\'guid\']]),\'name\'):[];',
    '$legacyTags=array_column(db_all($db,\'SELECT tag FROM rustdesk_tags WHERE uid=:uid ORDER BY tag\',[\'uid\'=>$uid]),\'tag\');',
    '$book=db_one($db,\'SELECT payload FROM address_books WHERE uid=:uid\',[\'uid\'=>$uid]);',
    '$payload=$book?json_decode((string)$book[\'payload\'],true,64,JSON_THROW_ON_ERROR):[];',
    '$bookTags=is_array($payload[\'tags\']??null)?$payload[\'tags\']:[];sort($bookTags);',
    'echo json_encode([\'profile\'=>$profileTags,\'legacy\'=>$legacyTags,\'payload\'=>$bookTags],JSON_THROW_ON_ERROR);',
  ].join('');
  const output = execFileSync('docker', ['exec', '-e', `TEST_UID=${uid}`, container, 'php', '-r', php], { encoding: 'utf8' });
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
  const dialog = page.getByRole('dialog', { name: '分配通讯录' });
  await expect(dialog).toBeVisible();
  const users = dialog.locator('#assignment-users input[type="checkbox"]');
  await expect(users.first()).toBeVisible();
  await users.first().check();
  await dialog.getByRole('button', { name: '保存分配' }).click();
  await expect(page.getByText('通讯录分配已保存')).toBeVisible();
  await expect(row).toContainText('通讯录分配：1 个用户');
  const api = await page.evaluate(async ({ adminPath, deviceId }) => (await fetch(`${adminPath}/api/devices?q=${encodeURIComponent(deviceId)}&page=1&pageSize=20`)).json(), { adminPath, deviceId });
  expect(api.data.find(item => item.id === deviceId).address_book_user_ids.length).toBe(1);
});

test('client inventory removal clears the address-book UI and client payload', async ({ page, request }) => {
  const deviceId = `browser-remove-${Date.now().toString(36)}`;
  await reportClient(request, deviceId, `${deviceId}-host`);
  await loginAdmin(page);
  await page.goto(`${adminPath}/devices`);
  await page.locator('#q').fill(deviceId);
  await page.getByRole('button', { name: '搜索' }).click();
  const row = page.locator(`[data-device-id="${deviceId}"]`);
  await expect(row).toBeVisible();
  await row.locator('input[type="checkbox"]').check();
  await page.getByRole('button', { name: /加入通讯录（1）/ }).click();
  const assignment = page.getByRole('dialog', { name: '分配通讯录' });
  const targetUser = assignment.locator('#assignment-users input[type="checkbox"]').first();
  await targetUser.check();
  await assignment.getByRole('button', { name: '保存分配' }).click();
  await expect(page.getByText('通讯录分配已保存')).toBeVisible();
  await expect(row).toContainText('通讯录分配：1 个用户');

  await row.locator('input[type="checkbox"]').check();
  await page.getByRole('button', { name: /加入通讯录（1）/ }).click();
  const removeDialog = page.getByRole('dialog', { name: '分配通讯录' });
  await removeDialog.locator('#assignment-users input[type="checkbox"]').first().uncheck();
  await removeDialog.getByRole('button', { name: '保存分配' }).click();
  await expect(page.getByText('通讯录分配已保存')).toBeVisible();
  await expect(row).toContainText('通讯录分配：0 个用户');

  await page.goto(`${adminPath}/address-book`);
  await page.locator('#address-search').fill(deviceId);
  await page.getByRole('button', { name: '搜索' }).click();
  await expect(page.locator(`[data-peer-id="${deviceId}"]`)).toHaveCount(0);
  const api = await page.evaluate(async ({ adminPath, deviceId }) => {
    const response = await fetch(`${adminPath}/api/address-book/peers?q=${encodeURIComponent(deviceId)}&page=1&pageSize=20`);
    return response.json();
  }, { adminPath, deviceId });
  const peers = Array.isArray(api.data) ? api.data : (Array.isArray(api.peers) ? api.peers : []);
  expect(peers.some(item => item.id === deviceId)).toBeFalsy();
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
  const secondRow = page.locator(`[data-device-id="multi-b-${suffix}"]`);
  await expect(secondRow).toBeVisible();
  await secondRow.locator('input[type="checkbox"]').check();
  await expect(page.locator('#address-book-action')).toHaveText('加入通讯录（2）');
  await expect(page.locator('#address-book-action')).toBeEnabled();
  await page.locator('#address-book-action').click();
  const assignment = page.getByRole('dialog', { name: '分配通讯录' });
  await assignment.locator('#assignment-users input[type="checkbox"]').first().check();
  await assignment.getByRole('button', { name: '保存分配' }).click();
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

test('address book uses inventory columns, telemetry and an explicit refresh', async ({ page, request }) => {
  const deviceId = `address-columns-${Date.now().toString(36)}`;
  await reportRichClient(request, deviceId);
  await loginAdmin(page);
  await page.goto(`${adminPath}/address-book`);
  await page.getByRole('button', { name: '新增客户端' }).click();
  await page.locator('#peer-id').fill(deviceId);
  await page.locator('#peer-alias').fill('带标签客户端');
  await page.getByRole('button', { name: '新增客户端' }).last().click();
  const row = page.locator(`[data-peer-id="${deviceId}"]`);
  await expect(row).toBeVisible();
  await expect(page.locator('thead')).toContainText('版本 / 发行形态');
  await expect(page.locator('thead')).toContainText('网络');
  await expect(page.locator('thead')).toContainText('最近活动');
  await expect(row).toContainText('Windows');
  await expect(row).toContainText('1.5.0');
  await page.locator('#refresh-address-book').click();
  await expect(row).toBeVisible();
  await page.setViewportSize({ width: 390, height: 844 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth)).toBeFalsy();
});

test('address-book search queued during the initial load wins over the stale response', async ({ page }) => {
  const suffix = Date.now().toString(36);
  const matchingId = `address-race-${suffix}`;
  await loginAdmin(page);
  const session = await page.request.get(`${adminPath}/api/session`);
  const { csrf } = await session.json();
  expect((await page.request.post(`${adminPath}/api/address-book/peers`, {
    headers: { 'X-CSRF-Token': csrf }, data: { id: matchingId, alias: '竞态搜索客户端', tags: [] },
  })).ok()).toBeTruthy();

  let releaseInitial;
  let initialStarted;
  const initialStartedPromise = new Promise(resolve => { initialStarted = resolve; });
  const releaseInitialPromise = new Promise(resolve => { releaseInitial = resolve; });
  let addressBookRequests = 0;
  await page.route('**/api/address-book?*', async route => {
    addressBookRequests += 1;
    if (addressBookRequests === 1) {
      initialStarted();
      await releaseInitialPromise;
    }
    await route.continue();
  });

  await page.goto(`${adminPath}/address-book`);
  await initialStartedPromise;
  await page.locator('#address-search').fill(matchingId);
  await page.getByRole('button', { name: '搜索' }).click();
  releaseInitial();
  await expect(page.locator(`[data-peer-id="${matchingId}"]`)).toBeVisible();
  await expect(page.locator('#address-page-info')).toContainText('共 1');
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

test('client details edits a per-device update policy and persists after reload', async ({ page, request }) => {
  const deviceId = `policy-ui-${Date.now().toString(36)}`;
  const uuid = `${deviceId}-uuid`;
  await reportClient(request, deviceId, `${deviceId}-host`);
  await expect((await request.post('/api/sysinfo', { data: {
    id: deviceId, uuid, hostname: `${deviceId}-host`, platform: 'windows', version: '1.5.0',
    enable_check_update: true, allow_auto_update: false, enable_scheduled_update: true,
    scheduled_update_interval_hours: 9, update_policy_revision: 0,
  } })).ok()).toBeTruthy();
  await loginAdmin(page);
  await page.goto(`${adminPath}/devices`);
  await page.locator('#q').fill(deviceId);
  await page.getByRole('button', { name: '搜索' }).click();
  const row = page.locator(`[data-device-id="${deviceId}"]`);
  await row.getByRole('button', { name: '查看客户端详情' }).click();
  const dialog = page.locator('#details-dialog');
  await expect(dialog).toBeVisible();
  await expect(dialog.getByText('更新策略', { exact: true })).toBeVisible();
  await expect(dialog.locator('#enable-check-update')).toBeChecked();
  await expect(dialog.locator('#allow-auto-update')).not.toBeChecked();
  await expect(dialog.locator('#enable-scheduled-update')).toBeChecked();
  await expect(dialog.locator('#scheduled-update-hours')).toHaveValue('9');
  await dialog.locator('#enable-check-update').uncheck();
  await dialog.locator('#allow-auto-update').check();
  await dialog.locator('#scheduled-update-hours').fill('12');
  await dialog.locator('#update-mode').selectOption('disabled');
  await dialog.locator('#update-channel').selectOption('beta');
  await dialog.locator('#update-version').fill('1.6.0');
  await dialog.locator('#update-build').fill('2026100102');
  await dialog.getByRole('button', { name: '保存更新策略' }).click();
  await expect(page.getByText('客户端更新策略已保存')).toBeVisible();
  await dialog.locator('#details-close').click();
  await row.getByRole('button', { name: '查看客户端详情' }).click();
  await expect(dialog.locator('#update-mode')).toHaveValue('disabled');
  await expect(dialog.locator('#update-channel')).toHaveValue('beta');
  await expect(dialog.locator('#update-version')).toHaveValue('1.6.0');
  await expect(dialog.locator('#update-build')).toHaveValue('2026100102');
  await expect(dialog.locator('#enable-check-update')).not.toBeChecked();
  await expect(dialog.locator('#allow-auto-update')).toBeChecked();
  await expect(dialog.locator('#enable-scheduled-update')).toBeChecked();
  await expect(dialog.locator('#scheduled-update-hours')).toHaveValue('12');
  await dialog.locator('#update-build').fill('bad');
  await dialog.getByRole('button', { name: '保存更新策略' }).click();
  await expect(dialog.locator('#update-policy-error')).toContainText('build_seq');
  await dialog.locator('#update-build').fill('2026100102');
  await dialog.locator('#scheduled-update-hours').fill('169');
  await dialog.getByRole('button', { name: '保存更新策略' }).click();
  await expect(dialog.locator('#update-policy-error')).toContainText('1 到 168');
  await page.setViewportSize({ width: 390, height: 844 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth)).toBeFalsy();
});

test('selected clients can be removed in bulk after confirmation', async ({ page, request }) => {
  const suffix = Date.now().toString(36);
  const ids = [`batch-remove-ui-${suffix}-1`, `batch-remove-ui-${suffix}-2`];
  await Promise.all(ids.map(id => reportClient(request, id)));
  await loginAdmin(page);
  await page.goto(`${adminPath}/devices`);
  await page.locator('#q').fill(`batch-remove-ui-${suffix}`);
  await page.getByRole('button', { name: '搜索' }).click();
  await expect(page.locator('#rows tr')).toHaveCount(2);
  await page.locator('#select-all-devices').check();
  const remove = page.locator('#batch-remove-devices');
  await expect(remove).toBeEnabled();
  page.once('dialog', dialog => dialog.dismiss());
  await remove.click();
  await expect(page.locator('#rows tr')).toHaveCount(2);
  await page.setViewportSize({ width: 390, height: 844 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth)).toBeFalsy();
  await expect(remove).toBeVisible();
  page.once('dialog', dialog => dialog.accept());
  await remove.click();
  await expect(page.locator('#status')).toContainText('已移除 2 台客户端');
  await expect(page.locator('#rows tr')).toHaveCount(0);
  await page.reload();
  await page.locator('#q').fill(`batch-remove-ui-${suffix}`);
  await page.getByRole('button', { name: '搜索' }).click();
  await expect(page.locator('#rows tr')).toHaveCount(0);
});

test('selected clients receive batch check commands and scheduled-update policy', async ({ page, request }) => {
  const suffix = Date.now().toString(36);
  const ids = [`batch-update-ui-${suffix}-1`, `batch-update-ui-${suffix}-2`];
  await Promise.all(ids.map(id => reportClient(request, id)));
  await loginAdmin(page);
  await page.goto(`${adminPath}/devices`);
  await page.locator('#q').fill(`batch-update-ui-${suffix}`);
  await page.getByRole('button', { name: '搜索' }).click();
  await expect(page.locator('#rows tr')).toHaveCount(2);
  await page.locator('#select-all-devices').check();
  await expect(page.locator('#batch-check-update')).toBeEnabled();
  await page.locator('#batch-scheduled-enabled').check();
  await page.locator('#batch-scheduled-hours').fill('7');
  await page.locator('#batch-scheduled-selected').click();
  await expect(page.locator('#status')).toContainText('定时检查策略已更新：2 台客户端');
  await page.locator('#batch-check-update').click();
  await expect(page.locator('#status')).toContainText('检查更新命令已发送：成功 2，失败 0');
  const persisted = await page.evaluate(async ({ adminPath, ids }) => Promise.all(ids.map(async id => {
    const uuid = `${id}-uuid`;
    const policy = await (await fetch(`${adminPath}/api/update/policies/${encodeURIComponent(id)}?uuid=${encodeURIComponent(uuid)}`)).json();
    const commands = await (await fetch(`${adminPath}/api/update/commands/${encodeURIComponent(id)}?uuid=${encodeURIComponent(uuid)}`)).json();
    return { policy, commands: commands.data };
  })), { adminPath, ids });
  expect(persisted.every(item => item.policy.enable_scheduled_update && item.policy.scheduled_update_interval_hours === 7)).toBeTruthy();
  expect(persisted.every(item => item.commands.some(command => command.action === 'check' && command.status === 'pending'))).toBeTruthy();
  await expect(page.locator('#batch-check-all')).toBeEnabled();
  await expect(page.locator('#batch-install-all')).toBeEnabled();
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
  await row.getByRole('button', { name: '编辑备注' }).click();
  const aliasDialog = page.locator('#alias-dialog');
  await expect(aliasDialog).toBeVisible();
  await page.mouse.click(6, 6);
  await expect(aliasDialog).toBeHidden();
  await page.goto(adminPath);
  await page.locator('#cu').click();
  const createDialog = page.locator('#cd');
  await expect(createDialog).toBeVisible();
  await page.mouse.click(6, 6);
  await expect(createDialog).toBeHidden();
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

test('client details streams update logs and refreshes reported build identity', async ({ page, request }) => {
  const deviceId = `update-command-${Date.now().toString(36)}`;
  const uuid = `${deviceId}-uuid`;
  const buildSeq = Date.now();
  await expect((await request.post('/api/heartbeat', { data: { id: deviceId, uuid, ver: 150, conns: [] } })).ok()).toBeTruthy();
  await expect((await request.post('/api/sysinfo', { data: {
    id: deviceId, uuid, hostname: `${deviceId}-host`, product: 'rustdesk-yan', edition: 'standard',
    version: '9.9.9', build_number: '20261001.6', build_seq: buildSeq - 1, channel: 'stable',
    platform: 'Windows', arch: 'x86_64', install_mode: 'installed',
  } })).ok()).toBeTruthy();
  await loginAdmin(page);
  await page.evaluate(async ({ adminPath, buildSeq }) => {
    const session = await (await fetch(`${adminPath}/api/session`)).json();
    const asset = { primary: `https://download.yan.life/rustdesk/stable/e2e-${buildSeq}/rustdesk.exe`, mirrors: [], size: 12, sha256: 'a'.repeat(64), signature: btoa('s'.repeat(64)), signature_key_id: 'yan-release-2026' };
    const response = await fetch(`${adminPath}/api/update/releases`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': session.csrf }, body: JSON.stringify({ version: '9.9.9', build_seq: buildSeq, channel: 'stable', manifest: { product: 'rustdesk-yan', edition: 'multi', source_commit: 'e2e-update-command', targets: { 'windows-x86_64-exe-standard': asset, 'windows-x86_64-msi-standard': { ...asset, primary: `https://download.yan.life/rustdesk/stable/e2e-${buildSeq}/rustdesk.msi` } } } }) });
    if (!response.ok) throw new Error(await response.text());
  }, { adminPath, buildSeq });
  const publishedCommands = updateCommandSqlSnapshot(deviceId, uuid);
  expect(publishedCommands).toEqual([
    { action: 'check', target_version: '9.9.9', target_build_seq: buildSeq, status: 'pending' },
  ]);
  await page.goto(`${adminPath}/devices`);
  await page.locator('#q').fill(deviceId);
  await page.getByRole('button', { name: '搜索' }).click();
  const row = page.locator(`[data-device-id="${deviceId}"]`);
  await expect(row.getByRole('button', { name: '立即检查更新' })).toHaveCount(0);
  await expect(row.getByRole('button', { name: '立即安装更新' })).toHaveCount(0);
  await row.getByRole('button', { name: '查看客户端详情' }).click();
  const dialog = page.locator('#details-dialog');
  await dialog.getByRole('button', { name: '立即检查更新' }).click();
  await expect(page.locator('#status')).toContainText('检查命令已发送');
  await page.setViewportSize({ width: 390, height: 844 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth)).toBeFalsy();
  await dialog.getByRole('button', { name: '立即安装更新' }).click();
  await expect(page.locator('#status')).toContainText('安装命令已发送');
  await expect(page.locator('#details-dialog')).toContainText('最近更新命令');
  await expect(page.locator('#details-dialog')).toContainText('等待客户端接收');
  await expect(dialog.locator('#update-command-log')).toBeVisible();
  const persistedCommands = updateCommandSqlSnapshot(deviceId, uuid);
  expect(persistedCommands).toHaveLength(publishedCommands.length + 2);
  expect(persistedCommands.filter(command => command.action === 'check')).toHaveLength(2);
  expect(persistedCommands.filter(command => command.action === 'install')).toHaveLength(1);
  for (const command of persistedCommands) {
    expect(command).toEqual({ action: command.action, target_version: '9.9.9', target_build_seq: buildSeq, status: 'pending' });
  }
  recordLatestUpdateLifecycle(deviceId, uuid, 'check', [
    { status: 'checking', from_version: '9.9.9', from_build_seq: buildSeq - 1, to_version: '9.9.9', to_build_seq: buildSeq },
    { status: 'completed', from_version: '9.9.9', from_build_seq: buildSeq - 1, to_version: '9.9.9', to_build_seq: buildSeq, finished: true },
  ]);
  recordLatestUpdateLifecycle(deviceId, uuid, 'install', [
    { status: 'started', from_version: '9.9.9', from_build_seq: buildSeq - 1, to_version: '9.9.9', to_build_seq: buildSeq },
    { status: 'downloaded', from_version: '9.9.9', from_build_seq: buildSeq - 1, to_version: '9.9.9', to_build_seq: buildSeq },
    { status: 'installing', from_version: '9.9.9', from_build_seq: buildSeq - 1, to_version: '9.9.9', to_build_seq: buildSeq },
  ]);
  await expect(dialog.locator('#update-command-log')).toContainText('检查前 9.9.9', { timeout: 5000 });
  await expect(dialog.locator('#update-command-log')).toContainText(`构建 ${buildSeq - 1} -> ${buildSeq}`);
  await expect(dialog.locator('#update-command-log')).toContainText('下载完成');
  await expect(dialog.locator('#update-command-log')).toContainText('正在安装');

  await expect((await request.post('/api/sysinfo', { data: {
    id: deviceId, uuid, hostname: `${deviceId}-host`, product: 'rustdesk-yan', edition: 'standard',
    version: '9.9.9', build_number: '20261002.1', build_seq: buildSeq, channel: 'stable',
    platform: 'Windows', arch: 'x86_64', install_mode: 'installed',
  } })).ok()).toBeTruthy();
  await expect(dialog.locator('#details-body')).toContainText('20261002.1', { timeout: 5000 });
  await expect(dialog.locator('#details-body')).toContainText(String(buildSeq));
  expect(await dialog.locator('#update-command-log').evaluate(node => node.scrollWidth <= node.clientWidth)).toBeTruthy();

  recordLatestUpdateLifecycle(deviceId, uuid, 'install', [
    { status: 'failed', from_version: '9.9.9', from_build_seq: buildSeq - 1, to_version: '9.9.9', to_build_seq: buildSeq, error_code: 'checksum_mismatch', finished: true },
  ]);
  await expect(dialog.locator('#update-command-log')).toContainText('执行失败', { timeout: 5000 });
  await expect(dialog.locator('#update-command-log')).toContainText('checksum_mismatch');
  await dialog.locator('#details-close').click();
  await row.getByRole('button', { name: '查看客户端详情' }).click();
  await expect(dialog.locator('#update-command-log')).toContainText('checksum_mismatch');
  await dialog.locator('#update-command-log').focus();
  await expect(dialog.locator('#update-command-log')).toBeFocused();
  await page.route(`${adminPath}/api/update/events/**`, route => route.abort());
  await dialog.locator('#details-close').click();
  await row.getByRole('button', { name: '查看客户端详情' }).click();
  await expect(dialog.locator('#update-command-log')).toContainText('读取失败', { timeout: 5000 });
  await page.unroute(`${adminPath}/api/update/events/**`);
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
  for (const id of ids) await reportClient(request, id);
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
  for (const id of ids) {
    const response = await request.post('/api/heartbeat', {
      data: { id, uuid: `${id}-uuid`, ver: 10, conns: [], modified_at: 0 },
    });
    expect(response.ok()).toBeTruthy();
  }

  await loginAdmin(page);
  await page.getByRole('link', { name: '客户端管理' }).click();
  await page.locator('#q').fill(`bulk-${suffix}`);
  await page.getByRole('button', { name: '搜索' }).click();
  await expect(page.locator(`[data-device-id="${ids[204]}"]`)).toBeVisible({ timeout: 15_000 });
  await expect(page.locator('#stat-total')).toHaveText('205');
});

test('client inventory select-all checks every visible device row', async ({ page, request }) => {
  const suffix = Date.now().toString(36);
  const ids = Array.from({ length: 3 }, (_, index) => `select-all-${suffix}-${index}`);
  for (const id of ids) await reportClient(request, id);

  await loginAdmin(page);
  await page.getByRole('link', { name: '客户端管理' }).click();
  await page.locator('#q').fill(`select-all-${suffix}`);
  await page.getByRole('button', { name: '搜索' }).click();

  const rows = page.locator('#rows tr');
  await expect(rows).toHaveCount(ids.length);
  const selectAll = page.locator('#select-all-devices');
  await selectAll.check();
  await expect(rows.locator('input[type="checkbox"]:checked')).toHaveCount(ids.length);
  await expect(page.locator('#address-book-action')).toHaveText(`加入通讯录（${ids.length}）`);
  await selectAll.uncheck();
  await expect(rows.locator('input[type="checkbox"]:checked')).toHaveCount(0);
  await expect(page.locator('#address-book-action')).toHaveText('加入通讯录');
  await rows.nth(0).locator('input[type="checkbox"]').check();
  await expect(selectAll).not.toBeChecked();
  await expect(selectAll).toHaveJSProperty('indeterminate', true);
  await rows.nth(0).locator('input[type="checkbox"]').uncheck();
  await expect(selectAll).not.toBeChecked();
  await expect(selectAll).toHaveJSProperty('indeterminate', false);
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

test('user address-book shortcut and batch actions persist for the selected user', async ({ page, request }) => {
  const suffix = Date.now().toString(36);
  const sourceName = `e2e-source-${suffix}`;
  const targetName = `e2e-target-${suffix}`;
  const firstId = `e2e-first-${suffix}`;
  const secondId = `e2e-second-${suffix}`;
  const errors = collectPageErrors(page);
  await reportClient(request, firstId);
  await reportClient(request, secondId);
  await loginAdmin(page);
  const session = await page.request.get(`${adminPath}/api/session`);
  const { csrf } = await session.json();
  const sourceResponse = await page.request.post(`${adminPath}/api/users`, { headers: { 'X-CSRF-Token': csrf }, data: { username: sourceName, password: '1', enabled: true } });
  const targetResponse = await page.request.post(`${adminPath}/api/users`, { headers: { 'X-CSRF-Token': csrf }, data: { username: targetName, password: '1', enabled: true } });
  const sourceId = (await sourceResponse.json()).id;
  const targetId = (await targetResponse.json()).id;
  for (const id of [firstId, secondId]) {
    expect((await page.request.post(`${adminPath}/api/address-book/peers?user_id=${sourceId}`, {
      headers: { 'X-CSRF-Token': csrf }, data: { id, alias: `${id}-alias`, tags: [] },
    })).ok()).toBeTruthy();
  }

  await page.reload();
  await page.locator('#q').fill(sourceName);
  await page.getByRole('button', { name: '搜索' }).click();
  const userRow = page.locator('tbody tr').filter({ hasText: sourceName });
  await expect(userRow.getByRole('link', { name: '2 个客户端' })).toBeVisible();
  await userRow.getByRole('link', { name: '2 个客户端' }).click();
  await expect(page).toHaveURL(new RegExp(`address-book\\?user_id=${sourceId}`));
  await expect(page.locator('#address-book-owner')).toHaveValue(sourceName);
  await expect(page.locator('#address-book-rows tr')).toHaveCount(2);

  await page.locator(`[data-peer-id="${firstId}"] input[type="checkbox"]`).check();
  await page.locator(`[data-peer-id="${secondId}"] input[type="checkbox"]`).check();
  await page.locator('#batch-action').selectOption('add_tags');
  await page.locator('#batch-tags').fill('批量标签');
  await page.getByRole('button', { name: '应用批量操作' }).click();
  await expect(page.locator('#address-book-status')).toContainText('已更新 2 个客户端');
  await expect(page.locator(`[data-peer-id="${firstId}"]`)).toContainText('批量标签');

  await page.locator(`[data-peer-id="${firstId}"] input[type="checkbox"]`).check();
  await page.locator('#batch-action').selectOption('copy');
  await page.locator('#batch-target').selectOption(String(targetId));
  await page.getByRole('button', { name: '应用批量操作' }).click();
  await page.locator('#address-book-owner').fill(targetName);
  await page.locator('#address-book-owner').press('Enter');
  await expect(page.locator(`[data-peer-id="${firstId}"]`)).toContainText('批量标签');

  await page.locator('#address-book-owner').fill(sourceName);
  await page.locator('#address-book-owner').press('Enter');
  await page.locator(`[data-peer-id="${secondId}"] input[type="checkbox"]`).check();
  await page.locator('#batch-action').selectOption('move');
  await page.locator('#batch-target').selectOption(String(targetId));
  await page.getByRole('button', { name: '应用批量操作' }).click();
  await expect(page.locator(`[data-peer-id="${secondId}"]`)).toHaveCount(0);

  await page.locator('#address-book-owner').fill(targetName);
  await page.locator('#address-book-owner').press('Enter');
  await expect(page.locator(`[data-peer-id="${secondId}"]`)).toBeVisible();
  await page.locator(`[data-peer-id="${secondId}"] input[type="checkbox"]`).check();
  await page.locator('#batch-action').selectOption('remove');
  await page.getByRole('button', { name: '应用批量操作' }).click();
  await page.getByRole('dialog', { name: '批量移除通讯录客户端' }).getByRole('button', { name: '确认移除' }).click();
  await expect(page.locator(`[data-peer-id="${secondId}"]`)).toHaveCount(0);

  await page.reload();
  await expect(page.locator(`[data-peer-id="${firstId}"]`)).toContainText('批量标签');
  const persisted = await page.evaluate(async ({ adminPath, targetId }) => (await fetch(`${adminPath}/api/address-book?user_id=${targetId}&page=1&pageSize=20`)).json(), { adminPath, targetId });
  expect(persisted.data.map(peer => peer.id)).toContain(firstId);
  expect(persisted.data.find(peer => peer.id === firstId).tags).toContain('批量标签');
  expect(addressBookSqlSnapshot(sourceId, [firstId, secondId])).toEqual({ profile: [firstId], legacy: [firstId], payload: [firstId] });
  expect(addressBookSqlSnapshot(targetId, [firstId, secondId])).toEqual({ profile: [firstId], legacy: [firstId], payload: [firstId] });
  expect(errors).toEqual([]);
});

test('self-scoped administrator cannot self-promote and replaces only personal client assignments', async ({ page, request }) => {
  const suffix = Date.now().toString(36);
  const username = `self-admin-${suffix}`;
  const peerId = `self-peer-${suffix}`;
  const deviceId = `self-device-${suffix}`;
  await reportClient(request, deviceId);
  await loginAdmin(page);
  const adminSession = await page.request.get(`${adminPath}/api/session`);
  const { csrf, user: rootUser } = await adminSession.json();
  const created = await page.request.post(`${adminPath}/api/users`, {
    headers: { 'X-CSRF-Token': csrf },
    data: { username, password: '1', enabled: true, address_book_scope: 'self' },
  });
  expect(created.ok()).toBeTruthy();
  const userId = (await created.json()).id;
  expect((await page.request.patch(`${adminPath}/api/users/${userId}`, {
    headers: { 'X-CSRF-Token': csrf },
    data: { is_admin: true, enabled: true, address_book_scope: 'self' },
  })).ok()).toBeTruthy();
  expect((await page.request.post(`${adminPath}/api/devices/address-book`, {
    headers: { 'X-CSRF-Token': csrf },
    data: { devices: [{ id: deviceId, uuid: `${deviceId}-uuid` }], user_ids: [rootUser.id], mode: 'add' },
  })).ok()).toBeTruthy();

  await page.context().clearCookies();
  await loginUser(page, username, '1');
  const selfSession = await page.request.get(`${adminPath}/api/session`);
  const { csrf: selfCsrf } = await selfSession.json();
  const scopedOptions = await (await page.request.get(`${adminPath}/api/address-book/assignment-options`)).json();
  expect(scopedOptions.users.map(user => user.id)).toEqual([userId]);
  expect(scopedOptions.assignments[deviceId] || []).toEqual([]);
  const scopedInventory = await (await page.request.get(`${adminPath}/api/devices?q=${encodeURIComponent(deviceId)}&page=1&pageSize=20`)).json();
  expect(scopedInventory.data.find(device => device.id === deviceId).address_book_user_ids).toEqual([]);
  const promote = await page.request.patch(`${adminPath}/api/users/${userId}`, {
    headers: { 'X-CSRF-Token': selfCsrf }, data: { address_book_scope: 'all' },
  });
  expect(promote.status()).toBe(403);
  await page.goto(adminPath);
  await expect(page.getByRole('button', { name: '新建用户' })).toBeHidden();
  await expect(page.getByRole('button', { name: '编辑' })).toHaveCount(0);

  await page.goto(`${adminPath}/devices`);
  await page.locator('#q').fill(deviceId);
  await page.getByRole('button', { name: '搜索' }).click();
  const deviceRow = page.locator(`[data-device-id="${deviceId}"]`);
  await deviceRow.getByRole('checkbox', { name: `选择客户端 ${deviceId}` }).check();
  await page.getByRole('button', { name: /加入通讯录（1）/ }).click();
  const assignment = page.getByRole('dialog', { name: '分配通讯录' });
  await expect(assignment.locator('#assignment-users input[type="checkbox"]')).toHaveCount(1);
  await assignment.locator('#assignment-users input[type="checkbox"]').check();
  await assignment.getByRole('button', { name: '保存分配' }).click();
  await expect(page.getByText('通讯录分配已保存')).toBeVisible();
  expect(addressBookSqlSnapshot(rootUser.id, [deviceId])).toEqual({ profile: [deviceId], legacy: [deviceId], payload: [deviceId] });
  expect(addressBookSqlSnapshot(userId, [deviceId])).toEqual({ profile: [deviceId], legacy: [deviceId], payload: [deviceId] });

  await deviceRow.getByRole('checkbox', { name: `选择客户端 ${deviceId}` }).check();
  await page.getByRole('button', { name: /加入通讯录（1）/ }).click();
  await assignment.locator('#assignment-users input[type="checkbox"]').uncheck();
  await assignment.getByRole('button', { name: '保存分配' }).click();
  await expect(page.getByText('通讯录分配已保存')).toBeVisible();
  expect(addressBookSqlSnapshot(rootUser.id, [deviceId])).toEqual({ profile: [deviceId], legacy: [deviceId], payload: [deviceId] });
  expect(addressBookSqlSnapshot(userId, [deviceId])).toEqual({ profile: [], legacy: [], payload: [] });

  await page.goto(`${adminPath}/address-book`);
  await expect(page.locator('#address-book-owner-panel')).toBeHidden();
  await page.getByRole('button', { name: '新增客户端' }).click();
  await page.locator('#peer-id').fill(peerId);
  await page.locator('#peer-alias').fill('自管通讯录客户端');
  await page.getByRole('button', { name: '新增客户端' }).last().click();
  await expect(page.locator(`[data-peer-id="${peerId}"]`)).toBeVisible();
  await expect(page.locator('#address-book-status')).toContainText('通讯录已保存');

  const options = await page.request.get(`${adminPath}/api/address-book/assignment-options`);
  expect(options.ok()).toBeTruthy();
  expect((await options.json()).users.map(user => user.id)).toEqual([userId]);
  const forbidden = await page.request.get(`${adminPath}/api/address-book?user_id=${rootUser.id}`);
  expect(forbidden.status()).toBe(403);
  expect(addressBookSqlSnapshot(userId, [peerId])).toEqual({ profile: [peerId], legacy: [peerId], payload: [peerId] });
});

test('cross-user copy persists through the 390px browser workflow', async ({ page }) => {
  const suffix = Date.now().toString(36);
  const sourceName = `mobile-source-${suffix}`;
  const targetName = `mobile-target-${suffix}`;
  const peerId = `mobile-copy-${suffix}`;
  await page.setViewportSize({ width: 390, height: 844 });
  await loginAdmin(page);
  const session = await page.request.get(`${adminPath}/api/session`);
  const { csrf } = await session.json();
  const sourceResponse = await page.request.post(`${adminPath}/api/users`, { headers: { 'X-CSRF-Token': csrf }, data: { username: sourceName, password: '1', enabled: true } });
  const targetResponse = await page.request.post(`${adminPath}/api/users`, { headers: { 'X-CSRF-Token': csrf }, data: { username: targetName, password: '1', enabled: true } });
  const sourceId = (await sourceResponse.json()).id;
  const targetId = (await targetResponse.json()).id;
  expect((await page.request.post(`${adminPath}/api/address-book/peers?user_id=${sourceId}`, {
    headers: { 'X-CSRF-Token': csrf }, data: { id: peerId, alias: '移动端跨用户复制', tags: ['mobile'] },
  })).ok()).toBeTruthy();
  await page.goto(`${adminPath}/address-book?user_id=${sourceId}`);
  await expect(page.locator('#address-book-owner')).toBeVisible();
  await expect(page.locator('#batch-action')).toBeVisible();
  await page.locator(`[data-peer-id="${peerId}"] input[type="checkbox"]`).check();
  await page.locator('#batch-action').selectOption('copy');
  await page.locator('#batch-target').selectOption(String(targetId));
  await page.getByRole('button', { name: '应用批量操作' }).click();
  await expect(page.locator('#address-book-status')).toContainText('操作成功：已更新 1 个客户端');
  await page.locator('#address-book-owner').fill(targetName);
  await page.locator('#address-book-owner').press('Enter');
  await expect(page.locator(`[data-peer-id="${peerId}"]`)).toContainText('移动端跨用户复制');
  await page.reload();
  await expect(page.locator(`[data-peer-id="${peerId}"]`)).toContainText('移动端跨用户复制');
  const persisted = await page.evaluate(async ({ adminPath, targetId }) => (await fetch(`${adminPath}/api/address-book?user_id=${targetId}&page=1&pageSize=20`)).json(), { adminPath, targetId });
  expect(persisted.data.find(peer => peer.id === peerId).alias).toBe('移动端跨用户复制');
  expect(addressBookSqlSnapshot(targetId, [peerId])).toEqual({ profile: [peerId], legacy: [peerId], payload: [peerId] });
  const targetTags = addressBookTagSqlSnapshot(targetId);
  expect(targetTags.profile).toContain('mobile');
  expect(targetTags.legacy).toContain('mobile');
  expect(targetTags.payload).toContain('mobile');
  expect(await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth)).toBeFalsy();
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
  await expect(row.locator('.cell-title', { hasText: '移动端电脑-更新' })).toBeVisible();
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
