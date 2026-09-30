<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';
error_reporting(E_ALL);
ini_set('display_errors', '0');
header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');

final class RequestError extends RuntimeException
{
    public function __construct(public int $status, string $message) { parent::__construct($message); }
}
function fail(int $status, string $message): never { throw new RequestError($status, $message); }
function reply(mixed $value, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}
function json_body(): array
{
    $raw = file_get_contents('php://input', false, null, 0, 4194305);
    if (strlen($raw) > 4194304) fail(413, '请求内容过大');
    try { $obj = json_decode($raw, false, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
    catch (JsonException) { fail(400, 'JSON 格式错误'); }
    if (!$obj instanceof stdClass) fail(400, '请求必须为 JSON 对象');
    return json_decode($raw, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
}
function json_object_text(mixed $value): string
{
    return json_encode(is_array($value) ? $value : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
function report_runtime_payload(array $data): array
{
    $runtime = [];
    $allowed = ['distribution' => ['desktop','mobile','sos','installed','portable','msi','appimage','linux_package','unknown'], 'install_mode' => ['installed','portable','live','unknown']];
    foreach (['platform', 'distribution', 'install_mode', 'client_arch', 'executable_name'] as $key) {
        if (array_key_exists($key, $data) && is_string($data[$key]) && strlen($data[$key]) <= 128) {
            $value = strtolower(trim($data[$key]));
            $runtime[$key] = isset($allowed[$key]) ? (in_array($value, $allowed[$key], true) ? $value : 'unknown') : $value;
        }
    }
    return $runtime;
}
function report_network_payload(array $data): array
{
    $network = is_array($data['network'] ?? null) ? $data['network'] : [];
    $private = $network['private_ips'] ?? ($data['private_ips'] ?? []);
    if (!is_array($private)) $private = [];
    $private = array_slice(array_values(array_unique(array_filter(array_map(static function ($ip) {
        if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) return null;
        $normalized = strtolower($ip);
        if ($normalized === '::1' || str_starts_with($normalized, '127.') || str_starts_with($normalized, '169.254.') || str_starts_with($normalized, 'fe80:')) return null;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $n = ip2long($ip); $ranges = [[ip2long('10.0.0.0'), ip2long('10.255.255.255')], [ip2long('172.16.0.0'), ip2long('172.31.255.255')], [ip2long('192.168.0.0'), ip2long('192.168.255.255')]];
            foreach ($ranges as [$start, $end]) if ($n >= $start && $n <= $end) return $ip;
            return null;
        }
        return str_starts_with($normalized, 'fc') || str_starts_with($normalized, 'fd') ? $ip : null;
    }, $private)))), 0, 16);
    return ['private_ips' => $private];
}
function request_public_ip(): string
{
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $rules = (string)(getenv('RUSTDESK_TRUSTED_PROXY_IPS') ?: '');
    $trusted = array_values(array_filter(array_map('trim', explode(',', $rules))));
    return forwarded_public_ip($remote, (string)($_SERVER['HTTP_X_REAL_IP'] ?? ''), (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''), $trusted);
}
function public_ip_geo(string $ip): array
{
    static $readers = [], $cache = [];
    $database = (string)(getenv('RUSTDESK_GEOIP_DATABASE') ?: '/var/www/data/GeoLite2-City.mmdb');
    if ($ip === '' || !is_file($database) || !class_exists('MaxMind\\Db\\Reader')) return [];
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return [];
    $cacheKey = $database."\0".$ip;
    if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];
    try {
        $reader = $readers[$database] ??= new MaxMind\Db\Reader($database); $record = $reader->get($ip);
        if (!is_array($record)) return $cache[$cacheKey] = [];
        $name = static fn($node) => is_array($node) ? (string)($node['names']['zh-CN'] ?? $node['names']['en'] ?? '') : '';
        $location = is_array($record['location'] ?? null) ? $record['location'] : [];
        $subdivision = is_array($record['subdivisions'][0] ?? null) ? $record['subdivisions'][0] : [];
        return $cache[$cacheKey] = array_filter([
            'country_code'=>(string)($record['country']['iso_code'] ?? ''),'country'=>$name($record['country'] ?? []),
            'region_code'=>(string)($subdivision['iso_code'] ?? ''),'region'=>$name($subdivision),'city'=>$name($record['city'] ?? []),
            'timezone'=>(string)($location['time_zone'] ?? ''),'latitude'=>$location['latitude'] ?? null,'longitude'=>$location['longitude'] ?? null,
        ], static fn($value) => $value !== '' && $value !== null);
    } catch (Throwable $error) { error_log('GeoLite lookup failed: '.$error->getMessage()); return $cache[$cacheKey] = []; }
}
function merge_json_objects(array $existing, array $incoming): array
{
    foreach ($incoming as $key => $value) {
        $existingObject = isset($existing[$key]) && is_array($existing[$key]) && !array_is_list($existing[$key]);
        $incomingObject = is_array($value) && (!array_is_list($value) || ($value === [] && $existingObject));
        if ($existingObject && $incomingObject) {
            $existing[$key] = merge_json_objects($existing[$key], $value);
        } else {
            $existing[$key] = $value;
        }
    }
    return $existing;
}
function refresh_public_network(array $network): array
{
    $network['public_ip'] = request_public_ip();
    $geo = public_ip_geo($network['public_ip']);
    if ($geo) $network['geo'] = $geo; else unset($network['geo']);
    return $network;
}
function device_uuid(PDO $db, string $id, string $provided): string
{
    if ($provided !== '') {
        $exists = db_one($db, 'SELECT uuid FROM device_reports WHERE id=:id AND uuid=:uuid', ['id'=>$id, 'uuid'=>$provided])
            ?? db_one($db, 'SELECT uuid FROM device_deployments WHERE id=:id AND uuid=:uuid', ['id'=>$id, 'uuid'=>$provided]);
        if (!$exists) fail(404, '设备不存在');
        return $provided;
    }
    $rows=db_all($db,'SELECT uuid FROM device_reports WHERE id=:id UNION SELECT uuid FROM device_deployments WHERE id=:id',['id'=>$id]);
    if(count($rows)===0)fail(404,'设备不存在');if(count($rows)>1)fail(409,'同一设备 ID 存在多个 UUID，请指定 UUID');return (string)$rows[0]['uuid'];
}
function text_field(array $data, string $key, int $max = 256, string $default = ''): string
{
    $value = $data[$key] ?? $default;
    if (!is_string($value) || strlen($value) > $max) fail(422, $key . ' 字段格式错误');
    return $value;
}
function username(array $data): string
{
    $name = trim(text_field($data, 'username', 128));
    if ($name === '' || preg_match('/[\x00-\x1f\x7f]/', $name)) fail(422, '用户名不能为空或包含控制字符');
    return $name;
}
function new_password(array $data): string
{
    $password = text_field($data, 'password', 72);
    if ($password === '' || str_contains($password, "\0")) fail(422, '密码不能为空、超过 72 字节或包含空字符');
    return password_hash($password, PASSWORD_DEFAULT);
}
function password_matches(string $plain, string $stored): bool
{
    if (password_get_info($stored)['algo'] !== null) return password_verify($plain, $stored);
    return preg_match('/^[0-9a-f]{32}$/i', $stored) === 1
        && hash_equals(strtolower($stored), md5($plain . 'rustdesk'));
}
function public_user(array $u): array
{
    return ['id' => (int)$u['id'], 'username' => $u['username'], 'is_admin' => (bool)$u['is_admin'],
        'enabled' => (bool)$u['enabled'], 'create_time' => (int)$u['create_time']];
}
function method(string ...$allowed): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'], $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed)); fail(405, '请求方法不支持');
    }
}
function admin_path(): string
{
    $config=installation_config();
    $value = rtrim(trim((string)($config['admin_path']??(getenv('RUSTDESK_ADMIN_PATH') ?: '/ops-console'))), '/');
    if (!preg_match('#^/[A-Za-z0-9._~-]+(?:/[A-Za-z0-9._~-]+)*$#', $value)
        || str_contains($value, '..') || $value === '/api' || $value === '/index.php') return '/ops-console';
    return $value;
}
function validated_admin_path(array $data): string
{
    $value=rtrim(trim(text_field($data,'admin_path',256,'/ops-console')),'/');
    if(!preg_match('#^/[A-Za-z0-9._~-]+(?:/[A-Za-z0-9._~-]+)*$#',$value)||str_contains($value,'..')||in_array($value,['/admin','/api','/index.php','/setup'],true))fail(422,'后台路径格式错误或使用了保留路径');
    return $value;
}
function setup_mysql_existing(array $data): array
{
    $host=trim(text_field($data,'mysql_host',255,'mysql'));$port=$data['mysql_port']??3306;$database=trim(text_field($data,'mysql_database',64,'rustdesk'));$user=trim(text_field($data,'mysql_user',128,'rustdesk'));$password=text_field($data,'mysql_password',256);
    if($host===''||preg_match('/[\x00-\x20\x7f]/',$host)||is_bool($port)||!is_int($port)||$port<1||$port>65535||!preg_match('/^[A-Za-z0-9_]+$/',$database)||$user===''||preg_match('/[\x00-\x1f\x7f]/',$user))fail(422,'MySQL 连接参数格式错误');
    return ['database'=>'mysql','mysql_host'=>$host,'mysql_port'=>$port,'mysql_database'=>$database,'mysql_user'=>$user,'mysql_password'=>$password];
}
function setup_mysql_managed(array $data): array
{
    $url=rtrim((string)getenv('RUSTDESK_PROVISIONER_URL'),'/');$secret=(string)getenv('RUSTDESK_PROVISIONER_SECRET');
    if($url===''||$secret==='')fail(503,'当前部署未启用 MySQL 自动创建组件');
    $project=trim(text_field($data,'project_name',63,'rustdesk-api'));
    if(!preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/',$project))fail(422,'项目名称只能包含小写字母、数字和连字符');
    $payload=json_encode(['api_container'=>gethostname(),'project_name'=>$project],JSON_THROW_ON_ERROR);
    $context=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nAuthorization: Bearer $secret\r\n",'content'=>$payload,'timeout'=>180,'ignore_errors'=>true]]);
    $raw=@file_get_contents($url.'/v1/mysql',false,$context);$status=0;
    foreach($http_response_header??[] as $header)if(preg_match('#^HTTP/\S+\s+(\d+)#',$header,$m))$status=(int)$m[1];
    try{$result=json_decode((string)$raw,true,32,JSON_THROW_ON_ERROR);}catch(Throwable){$result=[];}
    if($status!==201||!is_array($result)||($result['database']??'')!=='mysql')fail(502,(string)($result['error']??'MySQL 容器创建失败'));
    return $result;
}
function setup_provisioner_complete(string $project='rustdesk-api'): void
{
    $url=rtrim((string)getenv('RUSTDESK_PROVISIONER_URL'),'/');$secret=(string)getenv('RUSTDESK_PROVISIONER_SECRET');
    if($url===''||$secret==='')return;
    $payload=json_encode(['project_name'=>$project],JSON_THROW_ON_ERROR);
    $context=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nAuthorization: Bearer $secret\r\n",'content'=>$payload,'timeout'=>5,'ignore_errors'=>true]]);
    @file_get_contents($url.'/v1/complete',false,$context);
}
function render_page(string $file): never
{
    $html = file_get_contents($file);
    if ($html === false) fail(500, '页面文件不存在');
    header('Content-Type: text/html; charset=utf-8');
    echo str_replace('__ADMIN_PATH__', admin_path(), $html);
    exit;
}
function session_begin(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('rd_admin');
    session_start(['use_strict_mode' => 1, 'use_only_cookies' => 1, 'cookie_httponly' => true,
        'cookie_samesite' => 'Strict', 'cookie_secure' => ($_SERVER['HTTPS'] ?? '') === 'on', 'cookie_path' => '/']);
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
function csrf_check(): void
{
    $supplied = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($supplied === '' || !hash_equals($_SESSION['csrf'] ?? '', $supplied)) fail(403, '页面已过期，请刷新后重试');
}
function admin_user(PDO $db, bool $required = true): ?array
{
    session_begin();
    $u = db_one($db, 'SELECT * FROM rustdesk_users WHERE id=:id AND delete_time=0 AND enabled=1 AND is_admin=1', ['id' => (int)($_SESSION['uid'] ?? 0)]);
    if (!$u || (int)$u['auth_version'] !== (int)($_SESSION['auth_version'] ?? -1)
        || (int)($_SESSION['expires'] ?? 0) <= time()) {
        unset($_SESSION['uid'], $_SESSION['auth_version'], $_SESSION['expires']);
        if ($required) fail(401, '请重新登录管理员账号');
        return null;
    }
    return $u;
}
function bearer(): string
{
    if (!preg_match('/^Bearer\s+([a-zA-Z0-9._~-]{32,128})$/i', trim($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $m)) fail(401, '缺少有效登录凭据');
    return $m[1];
}
function api_user(PDO $db): array
{
    $u = db_one($db, 'SELECT u.*, t.id AS client_id, t.uuid AS client_uuid, t.expire_time AS token_expiry FROM rustdesk_token t JOIN rustdesk_users u ON u.id=t.uid WHERE t.access_token=:token AND u.delete_time=0 AND u.enabled=1 AND t.auth_version=u.auth_version', ['token' => bearer()]);
    $migration = (int)(db_one($db, "SELECT value FROM app_meta WHERE `key`='migrated_at'")['value'] ?? 0);
    $expiry = $u ? ((int)$u['token_expiry'] ?: $migration + 7 * 86400) : 0;
    if (!$u || $expiry <= time()) fail(401, '登录已过期，请重新登录');
    return $u;
}
function revoke(PDO $db, int $id): void
{
    db_exec($db, 'UPDATE rustdesk_users SET auth_version=auth_version+1 WHERE id=:id', ['id' => $id]);
    db_exec($db, 'DELETE FROM rustdesk_token WHERE uid=:id', ['id' => $id]);
}
function admin_event(PDO $db, int $actor, string $action, int $target): void
{
    db_exec($db, 'INSERT INTO admin_events(actor_id,action,target_id,created_at) VALUES(:actor,:action,:target,:at)', ['actor' => $actor, 'action' => $action, 'target' => $target, 'at' => time()]);
}
function login_attempt(PDO $db, string $name): string
{
    $bucket = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $name);
    txn($db, function () use ($db, $bucket) {
        $row = db_one($db, 'SELECT * FROM login_limits WHERE bucket=:bucket', ['bucket' => $bucket]);
        $count = $row && (int)$row['last_attempt'] > time() - 900 ? (int)$row['attempts'] : 0;
        if ($count >= 15) { header('Retry-After: 900'); fail(429, '登录尝试过多，请稍后再试'); }
        db_upsert($db, 'login_limits', ['bucket'=>$bucket,'attempts'=>$count+1,'last_attempt'=>time()], ['bucket'], ['attempts','last_attempt']);
        db_exec($db, 'DELETE FROM login_limits WHERE last_attempt<:at', ['at' => time() - 86400]);
    });
    return $bucket;
}
function authenticate(PDO $db, array $data, bool $admin): array
{
    $name = username($data);
    $password = text_field($data, 'password', 4096);
    $bucket = login_attempt($db, $name);
    return txn($db, function () use ($db, $name, $password, $admin, $bucket) {
        $matches = db_all($db, 'SELECT * FROM rustdesk_users WHERE username=:name AND delete_time=0 AND enabled=1', ['name' => $name]);
        $u = count($matches) === 1 ? $matches[0] : null;
        if (!$u || ($admin && !(bool)$u['is_admin']) || !password_matches($password, $u['password'])) fail(401, '用户名或密码错误');
        if (password_get_info($u['password'])['algo'] === null && strlen($password) <= 72) {
            db_exec($db, 'UPDATE rustdesk_users SET password=:password WHERE id=:id', ['password' => password_hash($password, PASSWORD_DEFAULT), 'id' => $u['id']]);
        }
        db_exec($db, 'DELETE FROM login_limits WHERE bucket=:bucket', ['bucket' => $bucket]);
        return $u;
    });
}
function pagination(): array
{
    $page = max(1, min(100000, (int)($_GET['page'] ?? $_GET['current'] ?? 1)));
    $size = max(1, min(200, (int)($_GET['pageSize'] ?? 50)));
    return [$size, ($page - 1) * $size];
}
function read_book(PDO $db, int $uid): array
{
    $book = db_one($db, 'SELECT payload,updated_at FROM address_books WHERE uid=:uid', ['uid' => $uid]);
    if ($book) return ['updated_at' => date('Y-m-d H:i:s', (int)$book['updated_at']), 'data' => $book['payload']];
    $tags = array_column(db_all($db, 'SELECT tag FROM rustdesk_tags WHERE uid=:uid ORDER BY id', ['uid' => $uid]), 'tag');
    $peers = db_all($db, 'SELECT id,username,hostname,alias,platform,tags,hash FROM rustdesk_peers WHERE uid=:uid ORDER BY deviceid', ['uid' => $uid]);
    foreach ($peers as &$peer) $peer['tags'] = $peer['tags'] === '' || $peer['tags'] === null ? [] : explode(',', $peer['tags']);
    return ['updated_at' => '', 'data' => json_encode(['tags' => $tags, 'peers' => $peers], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
}
function write_book(PDO $db, int $uid, array $data): void
{
    $raw = text_field($data, 'data', 4000000);
    try { $book = json_decode($raw, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
    catch (JsonException) { fail(422, '地址簿 JSON 格式错误'); }
    if (!is_array($book) || !isset($book['tags'], $book['peers']) || !is_array($book['tags']) || !is_array($book['peers'])
        || !array_is_list($book['tags']) || !array_is_list($book['peers']) || count($book['peers']) > 10000) fail(422, '地址簿必须包含 tags 和 peers 数组');
    foreach ($book['tags'] as $tag) if (!is_string($tag) || strlen($tag) > 256) fail(422, '标签格式错误');
    foreach ($book['peers'] as $peer) {
        if (!is_array($peer) || text_field($peer, 'id', 128) === '') fail(422, '设备 ID 不能为空');
        foreach (['username', 'hostname', 'alias', 'platform', 'hash'] as $key) text_field($peer, $key, 4096);
        if (isset($peer['tags']) && (!is_array($peer['tags']) || !array_is_list($peer['tags']))) fail(422, '设备标签必须是数组');
        foreach ($peer['tags'] ?? [] as $tag) if (!is_string($tag) || strlen($tag) > 256) fail(422, '设备标签格式错误');
    }
    txn($db, function () use ($db, $uid, $book, $raw) {
        db_exec($db, 'DELETE FROM rustdesk_tags WHERE uid=:uid', ['uid' => $uid]);
        db_exec($db, 'DELETE FROM rustdesk_peers WHERE uid=:uid', ['uid' => $uid]);
        foreach ($book['tags'] as $tag) db_exec($db, 'INSERT INTO rustdesk_tags(uid,tag) VALUES(:uid,:tag)', ['uid' => $uid, 'tag' => $tag]);
        foreach ($book['peers'] as $p) db_exec($db, 'INSERT INTO rustdesk_peers(uid,id,username,hostname,alias,platform,tags,hash) VALUES(:uid,:id,:username,:hostname,:alias,:platform,:tags,:hash)', [
            'uid' => $uid, 'id' => $p['id'], 'username' => $p['username'] ?? '', 'hostname' => $p['hostname'] ?? '',
            'alias' => $p['alias'] ?? '', 'platform' => $p['platform'] ?? '', 'tags' => implode(',', $p['tags'] ?? []), 'hash' => $p['hash'] ?? '']);
        // Keep the exact payload: unknown fields and comma-containing tags survive.
        db_upsert($db, 'address_books', ['uid'=>$uid,'payload'=>$raw,'updated_at'=>time()], ['uid'], ['payload','updated_at']);
    });
}

function personal_profile(PDO $db, array $u): array
{
    $profile = db_one($db, 'SELECT * FROM ab_profiles WHERE uid=:uid AND personal=1', ['uid' => $u['id']]);
    if ($profile) return $profile;
    $guid = sprintf('%s-%s-%s-%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(6)));
    db_exec($db, 'INSERT INTO ab_profiles(guid,uid,name,owner,note,rule,personal,created_at) VALUES(:guid,:uid,:name,:owner,:note,3,1,:at)', ['guid'=>$guid,'uid'=>$u['id'],'name'=>'个人地址簿','owner'=>$u['username'],'note'=>'','at'=>time()]);
    return db_one($db, 'SELECT * FROM ab_profiles WHERE guid=:guid', ['guid'=>$guid]);
}
function profile_for(PDO $db, array $u, string $guid): array
{
    $profile = db_one($db, 'SELECT * FROM ab_profiles WHERE guid=:guid AND uid=:uid', ['guid'=>$guid,'uid'=>$u['id']]);
    if (!$profile) fail(404, '地址簿不存在或无权访问');
    return $profile;
}
function profile_peer_payload(array $row): array
{
    $payload = json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR);
    $payload['id'] = $row['id'];
    return $payload;
}
function decoded_payload(?string $raw): array
{
    if ($raw === null || $raw === '') return [];
    try { $value = json_decode($raw, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
    catch (JsonException) { return []; }
    return is_array($value) ? $value : [];
}
function sync_admin_device_alias(PDO $db, array $actor, string $id, string $uuid, string $alias): void
{
    $report = db_one($db, 'SELECT payload FROM device_reports WHERE id=:id AND uuid=:uuid', ['id'=>$id,'uuid'=>$uuid]);
    $deployment = db_one($db, 'SELECT payload FROM device_deployments WHERE id=:id AND uuid=:uuid', ['id'=>$id,'uuid'=>$uuid]);
    if (!$report && !$deployment) fail(404, '设备不存在');

    $info = decoded_payload($report['payload'] ?? null);
    $deploy = decoded_payload($deployment['payload'] ?? null);
    $profile = personal_profile($db, $actor);
    $existingProfile = db_one($db, 'SELECT payload FROM ab_profile_peers WHERE guid=:guid AND id=:id', ['guid'=>$profile['guid'],'id'=>$id]);
    $profilePayload = decoded_payload($existingProfile['payload'] ?? null);
    $profilePayload['id'] = $id;
    $profilePayload['alias'] = $alias;
    foreach (['username'=>($info['username'] ?? $deploy['device_username'] ?? ''),
              'hostname'=>($info['hostname'] ?? $deploy['device_name'] ?? ''),
              'platform'=>($info['os'] ?? $info['platform'] ?? $deploy['platform'] ?? '')] as $key=>$value) {
        if (!array_key_exists($key, $profilePayload) && is_string($value)) $profilePayload[$key] = $value;
    }
    $now = time();
    db_upsert($db, 'ab_profile_peers', [
        'guid'=>$profile['guid'], 'id'=>$id,
        'payload'=>json_encode($profilePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'updated_at'=>$now,
    ], ['guid','id'], ['payload','updated_at']);

    $legacy = db_one($db, 'SELECT * FROM rustdesk_peers WHERE uid=:uid AND id=:id ORDER BY deviceid LIMIT 1', ['uid'=>$actor['id'],'id'=>$id]);
    $legacyValues = [
        'uid'=>(int)$actor['id'], 'id'=>$id,
        'username'=>(string)($profilePayload['username'] ?? $legacy['username'] ?? ''),
        'hostname'=>(string)($profilePayload['hostname'] ?? $legacy['hostname'] ?? ''),
        'alias'=>$alias,
        'platform'=>(string)($profilePayload['platform'] ?? $legacy['platform'] ?? ''),
        'tags'=>is_array($profilePayload['tags'] ?? null) ? implode(',', $profilePayload['tags']) : (string)($legacy['tags'] ?? ''),
        'hash'=>(string)($profilePayload['hash'] ?? $legacy['hash'] ?? ''),
    ];
    if ($legacy) {
        db_exec($db, 'UPDATE rustdesk_peers SET username=:username,hostname=:hostname,alias=:alias,platform=:platform,tags=:tags,hash=:hash WHERE uid=:uid AND id=:id', $legacyValues);
    } else {
        db_exec($db, 'INSERT INTO rustdesk_peers(uid,id,username,hostname,alias,platform,tags,hash) VALUES(:uid,:id,:username,:hostname,:alias,:platform,:tags,:hash)', $legacyValues);
    }

    $bookRow = db_one($db, 'SELECT payload FROM address_books WHERE uid=:uid', ['uid'=>$actor['id']]);
    $book = decoded_payload($bookRow['payload'] ?? null);
    if (!isset($book['tags']) || !is_array($book['tags'])) {
        $book['tags'] = array_column(db_all($db, 'SELECT tag FROM rustdesk_tags WHERE uid=:uid ORDER BY id', ['uid'=>$actor['id']]), 'tag');
    }
    if (!isset($book['peers']) || !is_array($book['peers']) || !array_is_list($book['peers'])) {
        $book['peers'] = [];
        foreach (db_all($db, 'SELECT id,username,hostname,alias,platform,tags,hash FROM rustdesk_peers WHERE uid=:uid ORDER BY deviceid', ['uid'=>$actor['id']]) as $peer) {
            $peer['tags'] = $peer['tags'] === '' || $peer['tags'] === null ? [] : explode(',', $peer['tags']);
            $book['peers'][] = $peer;
        }
    }
    $found = false;
    foreach ($book['peers'] as &$peer) {
        if (is_array($peer) && (string)($peer['id'] ?? '') === $id) {
            $peer['alias'] = $alias;
            $found = true;
            break;
        }
    }
    unset($peer);
    if (!$found) {
        $book['peers'][] = [
            'id'=>$id, 'username'=>$legacyValues['username'], 'hostname'=>$legacyValues['hostname'],
            'alias'=>$alias, 'platform'=>$legacyValues['platform'],
            'tags'=>$legacyValues['tags'] === '' ? [] : explode(',', $legacyValues['tags']), 'hash'=>$legacyValues['hash'],
        ];
    }
    db_upsert($db, 'address_books', [
        'uid'=>(int)$actor['id'],
        'payload'=>json_encode($book, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'updated_at'=>$now,
    ], ['uid'], ['payload','updated_at']);
}
function address_book_peer_id(string $raw): string
{
    $id = trim(rawurldecode($raw));
    if ($id === '' || strlen($id) > 128 || preg_match('/[\x00-\x1f\x7f]/', $id)) fail(422, '设备 ID 格式错误');
    return $id;
}
function address_book_tag_name(mixed $value, string $field = 'name'): string
{
    if (!is_string($value)) fail(422, $field . ' 字段格式错误');
    $name = trim($value);
    if ($name === '' || strlen($name) > 256 || preg_match('/[\x00-\x1f\x7f]/', $name)) fail(422, '标签不能为空或包含控制字符');
    return $name;
}
function address_book_tags(mixed $value): array
{
    if ($value === null) return [];
    if (!is_array($value) || !array_is_list($value)) fail(422, 'tags 必须为数组');
    $out = [];
    foreach ($value as $tag) {
        $name = address_book_tag_name($tag, 'tags');
        if (!in_array($name, $out, true)) $out[] = $name;
    }
    return $out;
}
function legacy_peer_payload(array $row): array
{
    return [
        'id'=>(string)$row['id'], 'username'=>(string)($row['username'] ?? ''),
        'hostname'=>(string)($row['hostname'] ?? ''), 'alias'=>(string)($row['alias'] ?? ''),
        'platform'=>(string)($row['platform'] ?? ''),
        'tags'=>$row['tags'] === null || $row['tags'] === '' ? [] : explode(',', (string)$row['tags']),
        'hash'=>(string)($row['hash'] ?? ''),
    ];
}
function exact_address_book(PDO $db, int $uid): array
{
    $row = db_one($db, 'SELECT payload FROM address_books WHERE uid=:uid', ['uid'=>$uid]);
    $book = decoded_payload($row['payload'] ?? null);
    if (!isset($book['tags']) || !is_array($book['tags']) || !array_is_list($book['tags'])) {
        $book['tags'] = array_column(db_all($db, 'SELECT tag FROM rustdesk_tags WHERE uid=:uid ORDER BY id', ['uid'=>$uid]), 'tag');
    }
    if (!isset($book['peers']) || !is_array($book['peers']) || !array_is_list($book['peers'])) {
        $book['peers'] = array_map('legacy_peer_payload', db_all($db, 'SELECT id,username,hostname,alias,platform,tags,hash FROM rustdesk_peers WHERE uid=:uid ORDER BY deviceid', ['uid'=>$uid]));
    }
    return $book;
}
function save_exact_address_book(PDO $db, int $uid, array $book, int $now): void
{
    db_upsert($db, 'address_books', [
        'uid'=>$uid, 'payload'=>json_encode($book, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'updated_at'=>$now,
    ], ['uid'], ['payload','updated_at']);
}
function validate_admin_peer_payload(array $data, ?array $existing = null): array
{
    $payload = $existing ?? [];
    foreach ($data as $key=>$value) {
        if ($key === 'favorite') continue;
        if ($key === 'tags') { $payload['tags'] = address_book_tags($value); continue; }
        if (in_array($key, ['id','username','hostname','alias','platform','hash'], true)) {
            if (!is_string($value) || strlen($value) > ($key === 'id' ? 128 : 4096)
                || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) fail(422, $key . ' 字段格式错误');
        }
        $payload[$key] = $value;
    }
    $payload['tags'] = address_book_tags($payload['tags'] ?? []);
    return $payload;
}
function sync_admin_book_peer(PDO $db, array $actor, array $profile, string $id, array $payload): void
{
    $now = time();
    $payload['id'] = $id;
    $payload['tags'] = address_book_tags($payload['tags'] ?? []);
    db_upsert($db, 'ab_profile_peers', [
        'guid'=>$profile['guid'], 'id'=>$id,
        'payload'=>json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'updated_at'=>$now,
    ], ['guid','id'], ['payload','updated_at']);

    $legacy = db_one($db, 'SELECT * FROM rustdesk_peers WHERE uid=:uid AND id=:id ORDER BY deviceid LIMIT 1', ['uid'=>$actor['id'],'id'=>$id]);
    $values = [
        'uid'=>(int)$actor['id'], 'id'=>$id,
        'username'=>(string)($payload['username'] ?? $legacy['username'] ?? ''),
        'hostname'=>(string)($payload['hostname'] ?? $legacy['hostname'] ?? ''),
        'alias'=>(string)($payload['alias'] ?? $legacy['alias'] ?? ''),
        'platform'=>(string)($payload['platform'] ?? $legacy['platform'] ?? ''),
        'tags'=>implode(',', $payload['tags']), 'hash'=>(string)($payload['hash'] ?? $legacy['hash'] ?? ''),
    ];
    if ($legacy) db_exec($db, 'UPDATE rustdesk_peers SET username=:username,hostname=:hostname,alias=:alias,platform=:platform,tags=:tags,hash=:hash WHERE uid=:uid AND id=:id', $values);
    else db_exec($db, 'INSERT INTO rustdesk_peers(uid,id,username,hostname,alias,platform,tags,hash) VALUES(:uid,:id,:username,:hostname,:alias,:platform,:tags,:hash)', $values);

    $book = exact_address_book($db, (int)$actor['id']);
    $found = false;
    foreach ($book['peers'] as &$peer) {
        if (is_array($peer) && (string)($peer['id'] ?? '') === $id) {
            $peer = array_replace($peer, $payload);
            $found = true;
            break;
        }
    }
    unset($peer);
    if (!$found) $book['peers'][] = $payload;
    save_exact_address_book($db, (int)$actor['id'], $book, $now);
}
function delete_admin_book_peer(PDO $db, array $actor, array $profile, string $id): bool
{
    $exists = db_one($db, 'SELECT id FROM ab_profile_peers WHERE guid=:guid AND id=:id', ['guid'=>$profile['guid'],'id'=>$id])
        || db_one($db, 'SELECT id FROM rustdesk_peers WHERE uid=:uid AND id=:id', ['uid'=>$actor['id'],'id'=>$id]);
    if (!$exists) {
        $book = exact_address_book($db, (int)$actor['id']);
        foreach ($book['peers'] as $peer) if (is_array($peer) && (string)($peer['id'] ?? '') === $id) { $exists = true; break; }
    }
    if (!$exists) return false;
    db_exec($db, 'DELETE FROM ab_profile_peers WHERE guid=:guid AND id=:id', ['guid'=>$profile['guid'],'id'=>$id]);
    db_exec($db, 'DELETE FROM rustdesk_peers WHERE uid=:uid AND id=:id', ['uid'=>$actor['id'],'id'=>$id]);
    db_exec($db, 'DELETE FROM admin_peer_favorites WHERE uid=:uid AND id=:id', ['uid'=>$actor['id'],'id'=>$id]);
    $book = exact_address_book($db, (int)$actor['id']);
    $book['peers'] = array_values(array_filter($book['peers'], fn($peer)=>!is_array($peer) || (string)($peer['id'] ?? '') !== $id));
    save_exact_address_book($db, (int)$actor['id'], $book, time());
    return true;
}
function sync_admin_book_tags(PDO $db, array $actor, array $profile, ?string $old, ?string $new, ?int $color, bool $delete): void
{
    $now = time();
    if ($delete) db_exec($db, 'DELETE FROM ab_profile_tags WHERE guid=:guid AND name=:name', ['guid'=>$profile['guid'],'name'=>$old]);
    elseif ($old !== null && $new !== null && $old !== $new) {
        if (db_one($db, 'SELECT name FROM ab_profile_tags WHERE guid=:guid AND name=:name', ['guid'=>$profile['guid'],'name'=>$new])) fail(409, '标签已存在');
        db_exec($db, 'DELETE FROM ab_profile_tags WHERE guid=:guid AND name=:old', ['guid'=>$profile['guid'],'old'=>$old]);
        db_upsert($db, 'ab_profile_tags', ['guid'=>$profile['guid'],'name'=>$new,'color'=>$color ?? 0], ['guid','name'], ['color']);
    } elseif ($new !== null) {
        db_upsert($db, 'ab_profile_tags', ['guid'=>$profile['guid'],'name'=>$new,'color'=>$color ?? 0], ['guid','name'], ['color']);
    }

    $rows = db_all($db, 'SELECT id,payload FROM ab_profile_peers WHERE guid=:guid', ['guid'=>$profile['guid']]);
    foreach ($rows as $row) {
        $payload = decoded_payload($row['payload']);
        $tags = address_book_tags($payload['tags'] ?? []);
        if ($old !== null) {
            $updated = [];
            foreach ($tags as $tag) {
                if ($tag === $old) {
                    if (!$delete && $new !== null && !in_array($new, $updated, true)) $updated[] = $new;
                } elseif (!in_array($tag, $updated, true)) $updated[] = $tag;
            }
            $tags = $updated;
        }
        $payload['tags'] = $tags;
        $payload['id'] = $row['id'];
        db_exec($db, 'UPDATE ab_profile_peers SET payload=:payload,updated_at=:at WHERE guid=:guid AND id=:id', [
            'payload'=>json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'at'=>$now, 'guid'=>$profile['guid'], 'id'=>$row['id'],
        ]);
        db_exec($db, 'UPDATE rustdesk_peers SET tags=:tags WHERE uid=:uid AND id=:id', ['tags'=>implode(',', $tags),'uid'=>$actor['id'],'id'=>$row['id']]);
    }

    if ($old !== null) db_exec($db, 'DELETE FROM rustdesk_tags WHERE uid=:uid AND tag=:tag', ['uid'=>$actor['id'],'tag'=>$old]);
    if (!$delete && $new !== null) db_insert_ignore($db, 'rustdesk_tags', ['uid'=>(int)$actor['id'],'tag'=>$new]);
    $book = exact_address_book($db, (int)$actor['id']);
    if ($old === null && !$delete && $new !== null && !in_array($new, $book['tags'], true)) $book['tags'][] = $new;
    elseif ($old !== null) {
        $updated = [];
        foreach ($book['tags'] as $tag) {
            if ($tag === $old) {
                if (!$delete && $new !== null && !in_array($new, $updated, true)) $updated[] = $new;
            } elseif (!in_array($tag, $updated, true)) $updated[] = $tag;
        }
        $book['tags'] = $updated;
    }
    foreach ($book['peers'] as &$peer) {
        if (!is_array($peer)) continue;
        $tags = address_book_tags($peer['tags'] ?? []);
        if ($old !== null) {
            $updated = [];
            foreach ($tags as $tag) {
                if ($tag === $old) {
                    if (!$delete && $new !== null && !in_array($new, $updated, true)) $updated[] = $new;
                } elseif (!in_array($tag, $updated, true)) $updated[] = $tag;
            }
            $tags = $updated;
        }
        $peer['tags'] = $tags;
    }
    unset($peer);
    save_exact_address_book($db, (int)$actor['id'], $book, $now);
}
function admin_address_book_state(PDO $db, array $actor, array $profile): array
{
    $book = exact_address_book($db, (int)$actor['id']);
    $legacy = [];
    foreach (db_all($db, 'SELECT id,username,hostname,alias,platform,tags,hash FROM rustdesk_peers WHERE uid=:uid ORDER BY deviceid', ['uid'=>$actor['id']]) as $row) {
        $legacy[$row['id']] = legacy_peer_payload($row);
    }
    foreach ($book['peers'] as $peer) {
        if (!is_array($peer) || !isset($peer['id']) || !is_string($peer['id']) || $peer['id'] === '') continue;
        $peer['tags'] = address_book_tags($peer['tags'] ?? []);
        $legacy[$peer['id']] = array_replace($legacy[$peer['id']] ?? [], $peer);
    }
    $profilePeers = [];
    foreach (db_all($db, 'SELECT id,payload FROM ab_profile_peers WHERE guid=:guid ORDER BY id', ['guid'=>$profile['guid']]) as $row) {
        $payload = decoded_payload($row['payload']);
        $payload['id'] = $row['id'];
        $payload['tags'] = address_book_tags($payload['tags'] ?? []);
        $profilePeers[$row['id']] = $payload;
    }
    $peers = $legacy;
    foreach ($profilePeers as $id=>$payload) $peers[$id] = array_replace($legacy[$id] ?? [], $payload);

    $favorites = array_fill_keys(array_column(db_all($db, 'SELECT id FROM admin_peer_favorites WHERE uid=:uid', ['uid'=>$actor['id']]), 'id'), true);
    foreach ($peers as $id=>&$peer) {
        $peer['id'] = $id;
        $peer['tags'] = address_book_tags($peer['tags'] ?? []);
        $peer['favorite'] = isset($favorites[$id]);
    }
    unset($peer);

    $tags = [];
    foreach ($book['tags'] as $name) if (is_string($name) && $name !== '') $tags[$name] = ['name'=>$name,'color'=>0];
    foreach (db_all($db, 'SELECT tag FROM rustdesk_tags WHERE uid=:uid ORDER BY id', ['uid'=>$actor['id']]) as $row) {
        if (!isset($tags[$row['tag']])) $tags[$row['tag']] = ['name'=>$row['tag'],'color'=>0];
    }
    foreach ($peers as $peer) foreach ($peer['tags'] as $name) {
        if (!isset($tags[$name])) $tags[$name] = ['name'=>$name,'color'=>0];
    }
    foreach (db_all($db, 'SELECT name,color FROM ab_profile_tags WHERE guid=:guid ORDER BY name', ['guid'=>$profile['guid']]) as $row) {
        $tags[$row['name']] = ['name'=>$row['name'],'color'=>(int)$row['color']];
    }
    ksort($tags, SORT_NATURAL | SORT_FLAG_CASE);
    return ['book'=>$book,'peers'=>$peers,'tags'=>array_values($tags)];
}
function action_ok(): never { http_response_code(200); header('Content-Length: 0'); exit; }

try {
    $db = open_database();
    $path = $_GET['s'] ?? parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (!is_string($path)) fail(400, '路径错误');
    if (str_contains($path, '?')) {
        [$path, $embeddedQuery] = explode('?', $path, 2);
        parse_str($embeddedQuery, $embeddedParams);
        foreach ($embeddedParams as $key=>$value) if (!array_key_exists($key, $_GET)) $_GET[$key] = $value;
    }
    $path = rtrim($path, '/') ?: '/';
    $initialized=has_administrator($db);
    if(!$initialized){
        if($path==='/setup/assets/setup.css'||$path==='/setup/assets/setup.js'){
            method('GET');$file=$path==='/setup/assets/setup.css'?'setup.css':'setup.js';$content=file_get_contents(__DIR__.'/'.$file);if($content===false)fail(500,'初始化资源不存在');
            header('Content-Type: '.($file==='setup.css'?'text/css':'text/javascript').'; charset=utf-8');echo $content;exit;
        }
        if($path==='/setup/api/status'){
            method('GET');session_begin();reply(['initialized'=>false,'csrf'=>$_SESSION['csrf'],'defaults'=>['database'=>'sqlite','admin_path'=>admin_path(),'mysql_host'=>'mysql','mysql_port'=>3306,'mysql_database'=>'rustdesk','mysql_user'=>'rustdesk','project_name'=>'rustdesk-api'],'managed_mysql_available'=>(getenv('RUSTDESK_PROVISIONER_URL')!==false&&getenv('RUSTDESK_PROVISIONER_SECRET')!==false)]);
        }
        if($path==='/setup/api/install'){
            method('POST');session_begin();csrf_check();$data=json_body();$config=['admin_path'=>validated_admin_path($data)];
            $name=username($data);$plain=text_field($data,'password',72);$confirm=text_field($data,'password_confirm',72);if($plain!==$confirm)fail(422,'两次输入的管理员密码不一致');$hash=new_password(['password'=>$plain]);
            $driver=text_field($data,'database',16,'sqlite');if(!in_array($driver,['sqlite','mysql'],true))fail(422,'数据库类型错误');
            $lockPath=dirname(installation_config_path()).'/install.lock';if(!is_dir(dirname($lockPath))&&!mkdir(dirname($lockPath),0770,true)&&!is_dir(dirname($lockPath)))fail(500,'无法创建初始化目录');
            $lock=fopen($lockPath,'c+');if($lock===false||!flock($lock,LOCK_EX|LOCK_NB))fail(409,'初始化任务正在执行');
            $response=null;
            try{
                if(has_administrator($db))fail(409,'系统已经完成初始化');
                if($driver==='sqlite'){$config+=['database'=>'sqlite','sqlite_path'=>database_path()];$target=$db;}
                else{$mode=text_field($data,'mysql_mode',16,'managed');$mysql=$mode==='managed'?setup_mysql_managed($data):($mode==='existing'?setup_mysql_existing($data):fail(422,'MySQL 部署方式错误'));$config+=$mysql;$target=open_database(null,$config);}
                create_initial_administrator($target,$name,$hash,static fn()=>write_installation_config($config));
                $user=db_one($target,'SELECT * FROM rustdesk_users WHERE username=:name AND is_admin=1 AND enabled=1 AND delete_time=0',['name'=>$name]);
                session_regenerate_id(true);$_SESSION=['uid'=>(int)$user['id'],'auth_version'=>(int)$user['auth_version'],'expires'=>time()+28800,'csrf'=>bin2hex(random_bytes(32))];
                setup_provisioner_complete($driver==='mysql'&&($mode??'')==='managed'?text_field($data,'project_name',63,'rustdesk-api'):'rustdesk-api');
                $response=['ok'=>true,'admin_path'=>$config['admin_path'],'database'=>$driver];
            }finally{flock($lock,LOCK_UN);fclose($lock);}
            reply($response);
        }
        if($_SERVER['REQUEST_METHOD']==='GET'&&!str_starts_with($path,'/api/'))render_page(__DIR__.'/setup.html');
        fail(503,'系统尚未初始化，请先访问 /setup');
    }
    $admin = admin_path();
    $adminApi = false;
    if (isset($_GET['ac'])) fail(410, '旧 GET 管理入口已停用');
    if ($path === '/' || $path === '/index.php') { method('GET'); render_page(__DIR__ . '/home.html'); }
    if ($path === $admin . '/assets/app.css') {
        method('GET');
        $css = file_get_contents(__DIR__ . '/app.css');
        if ($css === false) fail(500, '样式文件不存在');
        header('Content-Type: text/css; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        echo $css;
        exit;
    }
    if ($path === $admin) { method('GET'); render_page(__DIR__ . '/admin.html'); }
    if ($path === $admin . '/devices') { method('GET'); render_page(__DIR__ . '/devices.html'); }
    if ($path === $admin . '/address-book') { method('GET'); render_page(__DIR__ . '/address-book.html'); }
    if (str_starts_with($path, $admin . '/api/')) {
        $adminApi = true;
        $path = '/admin' . substr($path, strlen($admin));
    }
    if ($adminApi && $path === '/admin/api/session') {
        method('GET'); $u = admin_user($db, false);
        reply(['user' => $u ? public_user($u) : null, 'csrf' => $_SESSION['csrf']]);
    }
    if ($adminApi && $path === '/admin/api/login') {
        method('POST'); session_begin(); csrf_check(); $u = authenticate($db, json_body(), true);
        session_regenerate_id(true);
        $_SESSION = ['uid' => (int)$u['id'], 'auth_version' => (int)$u['auth_version'], 'expires' => time() + 28800, 'csrf' => bin2hex(random_bytes(32))];
        admin_event($db, (int)$u['id'], 'login', (int)$u['id']);
        reply(['user' => public_user($u), 'csrf' => $_SESSION['csrf']]);
    }
    if ($adminApi && $path === '/admin/api/logout') {
        method('POST'); session_begin(); csrf_check(); $_SESSION = []; session_destroy(); reply(['ok' => true]);
    }
    if ($adminApi && $path === '/admin/api/me/password') {
        method('PATCH'); $actor = admin_user($db); csrf_check(); $d = json_body();
        if (!password_matches(text_field($d, 'old_password', 4096), $actor['password'])) fail(422, '原密码错误');
        $hash = new_password($d);
        txn($db, function () use ($db, $actor, $hash) {
            db_exec($db, 'UPDATE rustdesk_users SET password=:password WHERE id=:id', ['password' => $hash, 'id' => $actor['id']]);
            revoke($db, (int)$actor['id']); admin_event($db, (int)$actor['id'], 'change_password', (int)$actor['id']);
        });
        reply(['ok' => true, 'reauthenticate' => true]);
    }
    if ($adminApi && preg_match('#^/admin/api/users(?:/([1-9][0-9]*))?$#', $path, $match)) {
        $actor = admin_user($db); $id = isset($match[1]) ? (int)$match[1] : 0;
        if (!$id && $_SERVER['REQUEST_METHOD'] === 'GET') {
            [$limit, $offset] = pagination(); $search = $_GET['q'] ?? '';
            if (!is_string($search) || strlen($search) > 128) fail(422, '搜索内容过长');
            $args = ['q' => $search]; $where = 'delete_time=0 AND instr(username,:q)>0';
            $total = db_one($db, 'SELECT COUNT(*) AS n FROM rustdesk_users WHERE ' . $where, $args)['n'];
            $list = db_all($db, 'SELECT * FROM rustdesk_users WHERE ' . $where . ' ORDER BY id LIMIT :limit OFFSET :offset', $args + ['limit' => $limit, 'offset' => $offset]);
            reply(['data' => array_map('public_user', $list), 'total' => (int)$total]);
        }
        method(...($id ? ['PATCH', 'DELETE'] : ['POST'])); csrf_check();
        $d = $_SERVER['REQUEST_METHOD'] === 'DELETE' ? [] : json_body();
        if (!$id) {
            $name = username($d); $hash = new_password($d);
            if (isset($d['enabled']) && !is_bool($d['enabled'])) fail(422, 'enabled 必须为布尔值');
            $newId = txn($db, function () use ($db, $name, $hash, $d, $actor) {
                if (db_one($db, 'SELECT id FROM rustdesk_users WHERE username=:name', ['name' => $name])) fail(409, '用户名已存在，包括已删除账号');
                db_exec($db, 'INSERT INTO rustdesk_users(username,password,create_time,delete_time,is_admin,enabled,auth_version) VALUES(:name,:password,:at,0,0,:enabled,0)', ['name' => $name, 'password' => $hash, 'at' => time(), 'enabled' => (int)($d['enabled'] ?? true)]);
                $id = (int)$db->lastInsertId(); admin_event($db, (int)$actor['id'], 'create_user', $id); return $id;
            }); reply(['ok' => true, 'id' => $newId], 201);
        }
        txn($db, function () use ($db, $id, $d, $actor) {
            $target = db_one($db, 'SELECT * FROM rustdesk_users WHERE id=:id AND delete_time=0', ['id' => $id]);
            if (!$target) fail(404, '用户不存在');
            $delete = $_SERVER['REQUEST_METHOD'] === 'DELETE';
            $enabled = $d['enabled'] ?? (bool)$target['enabled'];
            $isAdmin = $d['is_admin'] ?? (bool)$target['is_admin'];
            if (!is_bool($enabled) || !is_bool($isAdmin)) fail(422, '账号状态和角色必须为布尔值');
            if ($id === (int)$actor['id'] && ($delete || !$enabled || !$isAdmin)) fail(422, '不能删除、禁用或降级当前管理员');
            if ((bool)$target['is_admin'] && ($delete || !$enabled || !$isAdmin)) {
                $count = (int)db_one($db, 'SELECT COUNT(*) AS n FROM rustdesk_users WHERE is_admin=1 AND enabled=1 AND delete_time=0')['n'];
                if ($count <= 1) fail(422, '必须保留至少一个启用的管理员');
            }
            if ($delete) {
                db_exec($db, 'UPDATE rustdesk_users SET delete_time=:at WHERE id=:id', ['at' => time(), 'id' => $id]);
            } else {
                $name = array_key_exists('username', $d) ? username($d) : $target['username'];
                if ($name !== $target['username'] && db_one($db, 'SELECT id FROM rustdesk_users WHERE username=:name AND id<>:id', ['name' => $name, 'id' => $id])) fail(409, '用户名已存在');
                $hash = array_key_exists('password', $d) ? new_password($d) : $target['password'];
                db_exec($db, 'UPDATE rustdesk_users SET username=:name,password=:password,enabled=:enabled,is_admin=:admin WHERE id=:id', ['name' => $name, 'password' => $hash, 'enabled' => (int)$enabled, 'admin' => (int)$isAdmin, 'id' => $id]);
            }
            revoke($db, $id); admin_event($db, (int)$actor['id'], $delete ? 'delete_user' : 'update_user', $id);
        }); reply(['ok' => true, 'reauthenticate' => $id === (int)$actor['id']]);
    }
    if ($adminApi && $path === '/admin/api/address-book') {
        method('GET'); $actor = admin_user($db); [$limit,$offset] = pagination();
        $q = $_GET['q'] ?? ''; $tag = $_GET['tag'] ?? '';
        if (!is_string($q) || strlen($q) > 128) fail(422, '搜索内容过长');
        if (!is_string($tag) || strlen($tag) > 256) fail(422, '标签筛选错误');
        $favoriteFilter = $_GET['favorite'] ?? '';
        if (!is_string($favoriteFilter) || !in_array($favoriteFilter, ['', '0', '1', 'false', 'true'], true)) fail(422, '收藏筛选错误');
        $profile = db_one($db, 'SELECT * FROM ab_profiles WHERE uid=:uid AND personal=1', ['uid'=>$actor['id']]);
        $state = admin_address_book_state($db, $actor, $profile ?? ['guid'=>'']);
        $all = array_values($state['peers']);
        usort($all, fn($a,$b)=>(int)$b['favorite']<=>(int)$a['favorite'] ?: strnatcasecmp((string)($a['alias'] ?? $a['hostname'] ?? $a['id']), (string)($b['alias'] ?? $b['hostname'] ?? $b['id'])) ?: strcmp($a['id'],$b['id']));
        $summary = ['total'=>count($all),'favorites'=>0,'labelled'=>0,'tags'=>count($state['tags'])];
        foreach ($all as $peer) {
            if ($peer['favorite']) $summary['favorites']++;
            if (is_string($peer['alias'] ?? null) && $peer['alias'] !== '') $summary['labelled']++;
        }
        $filtered = array_values(array_filter($all, function($peer) use ($q,$tag,$favoriteFilter) {
            if ($favoriteFilter === '1' || $favoriteFilter === 'true') if (!$peer['favorite']) return false;
            if ($favoriteFilter === '0' || $favoriteFilter === 'false') if ($peer['favorite']) return false;
            if ($tag !== '' && !in_array($tag, $peer['tags'], true)) return false;
            if ($q !== '') {
                $haystack = json_encode($peer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                if (stripos($haystack, $q) === false) return false;
            }
            return true;
        }));
        reply([
            'profile'=>$profile ? ['guid'=>$profile['guid'],'name'=>$profile['name'],'owner'=>$profile['owner'],'note'=>$profile['note'],'rule'=>(int)$profile['rule']] : null,
            'summary'=>$summary, 'tags'=>$state['tags'], 'total'=>count($filtered), 'data'=>array_slice($filtered,$offset,$limit),
        ]);
    }
    if ($adminApi && $path === '/admin/api/address-book/peers') {
        method('POST'); $actor = admin_user($db); csrf_check(); $d = json_body();
        if (!array_key_exists('id', $d) || !is_string($d['id'])) fail(422, '设备 ID 不能为空');
        $id = address_book_peer_id($d['id']);
        $favorite = $d['favorite'] ?? false; if (!is_bool($favorite)) fail(422, 'favorite 必须为布尔值');
        txn($db, function() use ($db,$actor,$d,$id,$favorite) {
            $profile = personal_profile($db, $actor); $state = admin_address_book_state($db, $actor, $profile);
            if (isset($state['peers'][$id])) fail(409, '联系人已存在');
            $payload = validate_admin_peer_payload($d); $payload['id'] = $id;
            sync_admin_book_peer($db, $actor, $profile, $id, $payload);
            if ($favorite) db_upsert($db, 'admin_peer_favorites', ['uid'=>(int)$actor['id'],'id'=>$id,'created_at'=>time()], ['uid','id'], ['created_at']);
        });
        reply(['ok'=>true,'id'=>$id,'sync'=>'next_address_book_pull'], 201);
    }
    if ($adminApi && preg_match('#^/admin/api/address-book/peers/([^/]+)$#', $path, $match)) {
        $actor = admin_user($db); csrf_check(); $id = address_book_peer_id($match[1]);
        if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            txn($db, function() use ($db,$actor,$id) {
                $profile = personal_profile($db, $actor);
                if (!delete_admin_book_peer($db, $actor, $profile, $id)) fail(404, '联系人不存在');
            });
            reply(['ok'=>true,'id'=>$id,'sync'=>'next_address_book_pull']);
        }
        method('PATCH'); $d = json_body();
        if (array_key_exists('id', $d) && $d['id'] !== $id) fail(422, '不能修改设备 ID');
        txn($db, function() use ($db,$actor,$id,$d) {
            $profile = personal_profile($db, $actor); $state = admin_address_book_state($db, $actor, $profile);
            if (!isset($state['peers'][$id])) fail(404, '联系人不存在');
            $existing = $state['peers'][$id]; unset($existing['favorite']);
            $payload = validate_admin_peer_payload($d, $existing); $payload['id'] = $id;
            sync_admin_book_peer($db, $actor, $profile, $id, $payload);
        });
        reply(['ok'=>true,'id'=>$id,'sync'=>'next_address_book_pull']);
    }
    if ($adminApi && preg_match('#^/admin/api/address-book/favorites/([^/]+)$#', $path, $match)) {
        method('PATCH'); $actor = admin_user($db); csrf_check(); $id = address_book_peer_id($match[1]); $d = json_body();
        if (!array_key_exists('favorite', $d) || !is_bool($d['favorite'])) fail(422, 'favorite 必须为布尔值');
        txn($db, function() use ($db,$actor,$id,$d) {
            $profile = db_one($db, 'SELECT * FROM ab_profiles WHERE uid=:uid AND personal=1', ['uid'=>$actor['id']]);
            $state = admin_address_book_state($db, $actor, $profile ?? ['guid'=>'']);
            if (!isset($state['peers'][$id])) fail(404, '联系人不存在');
            if ($d['favorite']) db_upsert($db, 'admin_peer_favorites', ['uid'=>(int)$actor['id'],'id'=>$id,'created_at'=>time()], ['uid','id'], ['created_at']);
            else db_exec($db, 'DELETE FROM admin_peer_favorites WHERE uid=:uid AND id=:id', ['uid'=>$actor['id'],'id'=>$id]);
        });
        reply(['ok'=>true,'id'=>$id,'favorite'=>$d['favorite'],'sync'=>'admin_only']);
    }
    if ($adminApi && $path === '/admin/api/address-book/tags') {
        method('POST'); $actor = admin_user($db); csrf_check(); $d = json_body();
        $name = address_book_tag_name($d['name'] ?? null); $color = $d['color'] ?? 0;
        if (!is_int($color) || $color < 0) fail(422, 'color 字段格式错误');
        txn($db, function() use ($db,$actor,$name,$color) {
            $profile = personal_profile($db, $actor); $state = admin_address_book_state($db, $actor, $profile);
            foreach ($state['tags'] as $tag) if ($tag['name'] === $name) fail(409, '标签已存在');
            sync_admin_book_tags($db, $actor, $profile, null, $name, $color, false);
        });
        reply(['ok'=>true,'name'=>$name,'color'=>$color,'sync'=>'next_address_book_pull'], 201);
    }
    if ($adminApi && preg_match('#^/admin/api/address-book/tags/([^/]+)$#', $path, $match)) {
        $actor = admin_user($db); csrf_check(); $old = address_book_tag_name(rawurldecode($match[1]));
        if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            txn($db, function() use ($db,$actor,$old) {
                $profile = personal_profile($db, $actor); $state = admin_address_book_state($db, $actor, $profile); $exists = false;
                foreach ($state['tags'] as $tag) if ($tag['name'] === $old) { $exists = true; break; }
                if (!$exists) fail(404, '标签不存在');
                sync_admin_book_tags($db, $actor, $profile, $old, null, null, true);
            });
            reply(['ok'=>true,'name'=>$old,'sync'=>'next_address_book_pull']);
        }
        method('PATCH'); $d = json_body(); $new = array_key_exists('name',$d) ? address_book_tag_name($d['name']) : $old;
        $color = $d['color'] ?? null; if ($color !== null && (!is_int($color) || $color < 0)) fail(422, 'color 字段格式错误');
        txn($db, function() use ($db,$actor,$old,$new,$color) {
            $profile = personal_profile($db, $actor); $state = admin_address_book_state($db, $actor, $profile); $existing = null;
            foreach ($state['tags'] as $tag) if ($tag['name'] === $old) { $existing = $tag; break; }
            if ($existing === null) fail(404, '标签不存在');
            if ($new !== $old) foreach ($state['tags'] as $tag) if ($tag['name'] === $new) fail(409, '标签已存在');
            sync_admin_book_tags($db, $actor, $profile, $old, $new, $color ?? (int)$existing['color'], false);
        });
        reply(['ok'=>true,'old'=>$old,'name'=>$new,'color'=>$color,'sync'=>'next_address_book_pull']);
    }
    if ($adminApi && preg_match('#^/admin/api/devices/([^/]+)/alias$#', $path, $match)) {
        method('PATCH'); $actor = admin_user($db); csrf_check(); $id = rawurldecode($match[1]); $uuid=text_field($_GET,'uuid',256);
        if ($id === '' || strlen($id) > 128) fail(422, '设备 ID 格式错误');
        $uuid=device_uuid($db,$id,$uuid);
        $d = json_body();
        if (!array_key_exists('alias', $d) || !is_string($d['alias']) || strlen($d['alias']) > 255) fail(422, 'alias 字段格式错误');
        if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $d['alias'])) fail(422, 'alias 字段格式错误');
        txn($db, function () use ($db, $actor, $id, $uuid, $d) { sync_admin_device_alias($db, $actor, $id, $uuid, $d['alias']); });
        reply(['ok'=>true, 'alias'=>$d['alias'], 'sync'=>'next_address_book_pull']);
    }
    if ($adminApi && preg_match('#^/admin/api/devices(?:/([^/]+))?$#', $path, $match)) {
        $actor = admin_user($db); $id = isset($match[1]) ? rawurldecode($match[1]) : '';
        if ($id === '' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            [$limit,$offset]=pagination(); $q=$_GET['q']??''; if(!is_string($q)||strlen($q)>128) fail(422,'搜索内容过长');
            $presenceFilter=$_GET['presence']??($_GET['status']??'');
            if(!is_string($presenceFilter)||!in_array($presenceFilter,['','all','online','recent','offline','unreported'],true)) fail(422,'在线状态筛选错误');
            $labelledFilter=($_GET['labelled']??'')==='1';
            $profile=db_one($db,'SELECT guid FROM ab_profiles WHERE uid=:uid AND personal=1',['uid'=>$actor['id']]);
            $aliases=[];
            if($profile){
                foreach(db_all($db,'SELECT id,payload FROM ab_profile_peers WHERE guid=:guid',['guid'=>$profile['guid']]) as $peer){
                    $payload=decoded_payload($peer['payload']);
                    if(array_key_exists('alias',$payload)&&is_string($payload['alias']))$aliases[$peer['id']]=['value'=>$payload['alias'],'owned'=>true];
                }
            }
            foreach(db_all($db,'SELECT id,alias FROM rustdesk_peers WHERE uid=:uid ORDER BY deviceid',['uid'=>$actor['id']]) as $peer){
                if(!array_key_exists($peer['id'],$aliases))$aliases[$peer['id']]=['value'=>$peer['alias']===null?'':(string)$peer['alias'],'owned'=>true];
            }
            $deployments=[];
            foreach(db_all($db,'SELECT id,uuid,uid,payload,updated_at FROM device_deployments') as $deployment)$deployments[$deployment['id']."\0".$deployment['uuid']]=$deployment;
            $inventory=[]; $reportedIds=[]; $now=time();
            foreach(db_all($db,'SELECT id,uuid,payload,last_seen,last_heartbeat,heartbeat_payload,runtime_payload,network_payload FROM device_reports') as $reportRow){
                $identity=$reportRow['id']."\0".$reportRow['uuid'];$reportedIds[$identity]=true; $deployment=$deployments[$identity]??null;
                $report=decoded_payload($reportRow['payload']); $deploy=decoded_payload($deployment['payload']??null);
                $runtime=decoded_payload($reportRow['runtime_payload']??null); $network=decoded_payload($reportRow['network_payload']??null);
                $publicIp=is_public_ip((string)($network['public_ip']??''))?(string)$network['public_ip']:'';
                $publicGeo=[];
                if($publicIp!==''){
                    $storedGeo=$network['geo']??null;
                    $publicGeo=is_array($storedGeo)&&$storedGeo!==[]?$storedGeo:public_ip_geo($publicIp);
                }
                $aliasEntry=$aliases[$reportRow['id']]??null; $lastHeartbeat=(int)$reportRow['last_heartbeat'];
                $inventory[]=['id'=>$reportRow['id'],'uuid'=>$reportRow['uuid'],'owner_id'=>$deployment&&$deployment['uid']!==null?(int)$deployment['uid']:null,
                    'hostname'=>$report['hostname']??($deploy['device_name']??''),'username'=>$report['username']??($deploy['device_username']??''),
                    'platform'=>$report['platform']??($report['os']??($deploy['platform']??'')),'os'=>$report['os']??'','cpu'=>$report['cpu']??'','memory'=>$report['memory']??'','version'=>$report['version']??'',
                    'distribution'=>$runtime['distribution']??'','install_mode'=>$runtime['install_mode']??'','client_arch'=>$runtime['client_arch']??'','executable_name'=>$runtime['executable_name']??'',
                    'public_ip'=>$publicIp,'private_ips'=>$network['private_ips']??[],'geo'=>$publicGeo,
                    'version_text'=>$report['version']??'','heartbeat_version'=>decoded_payload($reportRow['heartbeat_payload']??null)['ver']??null,
                    'heartbeat_payload'=>decoded_payload($reportRow['heartbeat_payload']??null),
                    'last_seen'=>(int)$reportRow['last_seen'],'last_heartbeat'=>$lastHeartbeat,
                    'updated_at'=>$deployment?(int)$deployment['updated_at']:(int)$reportRow['last_seen'],'presence'=>device_presence($lastHeartbeat,$now),
                    'deployed'=>$deployment!==null,'alias'=>$aliasEntry['value']??null,
                    'alias_owner_id'=>$aliasEntry?(int)$actor['id']:null,'alias_owner_name'=>$aliasEntry?$actor['username']:null,
                    '_search'=>json_encode([$reportRow['id'],$reportRow['uuid'],$report,$deploy,$aliasEntry['value']??''],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)];
            }
            foreach($deployments as $deployment){
                $identity=$deployment['id']."\0".$deployment['uuid'];if(isset($reportedIds[$identity]))continue; $deploy=decoded_payload($deployment['payload']); $aliasEntry=$aliases[$deployment['id']]??null;
                $inventory[]=['id'=>$deployment['id'],'uuid'=>$deployment['uuid'],'owner_id'=>$deployment['uid']===null?null:(int)$deployment['uid'],
                    'hostname'=>$deploy['device_name']??($deploy['hostname']??''),'username'=>$deploy['device_username']??($deploy['username']??''),
                    'platform'=>$deploy['platform']??($deploy['os']??''),'os'=>$deploy['os']??'','cpu'=>$deploy['cpu']??'','memory'=>$deploy['memory']??'','version'=>$deploy['version']??'',
                    'distribution'=>$deploy['distribution']??'','install_mode'=>$deploy['install_mode']??'','client_arch'=>$deploy['client_arch']??'','executable_name'=>$deploy['executable_name']??'',
                    'public_ip'=>'','private_ips'=>[],'geo'=>[],'version_text'=>$deploy['version']??'','heartbeat_version'=>null,
                    'last_seen'=>null,'last_heartbeat'=>0,'updated_at'=>(int)$deployment['updated_at'],'presence'=>'unreported','deployed'=>true,
                    'alias'=>$aliasEntry['value']??null,'alias_owner_id'=>$aliasEntry?(int)$actor['id']:null,'alias_owner_name'=>$aliasEntry?$actor['username']:null,
                    '_search'=>json_encode([$deployment['id'],$deployment['uuid'],$deploy,$aliasEntry['value']??''],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)];
            }
            if($q!=='')$inventory=array_values(array_filter($inventory,fn($row)=>stripos($row['_search'],$q)!==false));
            $summary=['total'=>count($inventory),'online'=>0,'recent'=>0,'offline'=>0,'unreported'=>0,'labelled'=>0];
            foreach($inventory as $row){$summary[$row['presence']]++;if(is_string($row['alias'])&&$row['alias']!=='')$summary['labelled']++;}
            if($presenceFilter!==''&&$presenceFilter!=='all')$inventory=array_values(array_filter($inventory,fn($row)=>$row['presence']===$presenceFilter));
            if($labelledFilter)$inventory=array_values(array_filter($inventory,fn($row)=>is_string($row['alias'])&&$row['alias']!==''));
            usort($inventory,fn($a,$b)=>((int)($b['alias']!==null&&$b['alias']!=='')<=>((int)($a['alias']!==null&&$a['alias']!==''))) ?: strcasecmp((string)($a['alias']??$a['hostname']??''),(string)($b['alias']??$b['hostname']??'')) ?: strcmp($a['id'].'|'.$a['uuid'],$b['id'].'|'.$b['uuid']));
            $total=count($inventory); $data=array_slice($inventory,$offset,$limit); foreach($data as &$row)unset($row['_search']); unset($row);
            reply(['total'=>$total,'summary'=>$summary,'data'=>$data]);
        }
        method('DELETE'); csrf_check(); if($id==='') fail(404,'设备不存在');$uuid=device_uuid($db,$id,text_field($_GET,'uuid',256));
        txn($db,function()use($db,$id,$uuid,$actor){db_exec($db,'DELETE FROM device_deployments WHERE id=:id AND uuid=:uuid',['id'=>$id,'uuid'=>$uuid]);db_exec($db,'DELETE FROM device_reports WHERE id=:id AND uuid=:uuid',['id'=>$id,'uuid'=>$uuid]);admin_event($db,(int)$actor['id'],'delete_device',0);}); reply(['ok'=>true]);
    }
    if ($path === '/api/login') {
        method('POST'); $d = json_body(); $u = authenticate($db, $d, false);
        $token = bin2hex(random_bytes(32));
        db_exec($db, 'INSERT INTO rustdesk_token(access_token,username,uid,id,uuid,login_time,expire_time,auth_version) VALUES(:token,:name,:uid,:id,:uuid,:at,:expiry,:version)', ['token' => $token, 'name' => $u['username'], 'uid' => $u['id'], 'id' => text_field($d, 'id'), 'uuid' => text_field($d, 'uuid'), 'at' => time(), 'expiry' => time() + 2592000, 'version' => $u['auth_version']]);
        reply(['type' => 'access_token', 'access_token' => $token, 'user' => ['name' => $u['username']]]);
    }
    if ($path === '/api/currentUser') { method('POST'); $u = api_user($db); reply(['name' => $u['username']]); }
    if ($path === '/api/logout') { method('POST'); api_user($db); db_exec($db, 'DELETE FROM rustdesk_token WHERE access_token=:token', ['token' => bearer()]); reply(['data' => date('Y-m-d H:i')]); }
    if ($path === '/api/users' || $path === '/api/peers') {
        method('GET'); $u = api_user($db); [$limit, $offset] = pagination();
        if ($path === '/api/users') {
            $total = db_one($db, 'SELECT COUNT(*) AS n FROM rustdesk_users WHERE delete_time=0 AND enabled=1')['n'];
            $list = db_all($db, 'SELECT username AS name,is_admin FROM rustdesk_users WHERE delete_time=0 AND enabled=1 ORDER BY id LIMIT :limit OFFSET :offset', ['limit' => $limit, 'offset' => $offset]);
            $list = array_map(fn($row) => ['name'=>$row['name'],'display_name'=>'','avatar'=>'','email'=>'','note'=>'','status'=>1,'is_admin'=>(bool)$row['is_admin']], $list);
        } else {
            $total = db_one($db, 'SELECT COUNT(*) AS n FROM rustdesk_peers WHERE uid=:uid', ['uid' => $u['id']])['n'];
            $list = db_all($db, 'SELECT * FROM rustdesk_peers WHERE uid=:uid ORDER BY deviceid LIMIT :limit OFFSET :offset', ['uid' => $u['id'], 'limit' => $limit, 'offset' => $offset]);
            $list = array_map(fn($p) => ['id' => $p['id'], 'user_name' => $u['username'], 'info' => ['device_name' => $p['alias'], 'os' => $p['platform'], 'username' => $p['username']]], $list);
        } reply(['total' => (int)$total, 'data' => $list]);
    }
    if ($path === '/api/ab' || $path === '/api/ab/get') {
        method(...($path === '/api/ab' ? ['GET', 'POST'] : ['POST'])); $u = api_user($db);
        if ($path === '/api/ab' && $_SERVER['REQUEST_METHOD'] === 'POST') { write_book($db, (int)$u['id'], json_body()); reply(['ok' => true]); }
        reply(read_book($db, (int)$u['id']));
    }
    if ($path === '/api/login-options') { method('GET'); reply([]); }
    if ($path === '/api/ab/settings') {
        method('POST'); api_user($db); reply(['max_peer_one_ab' => 0]);
    }
    if ($path === '/api/ab/personal') {
        method('POST'); $u = api_user($db); $profile = personal_profile($db, $u); reply(['guid' => $profile['guid']]);
    }
    if ($path === '/api/ab/shared/profiles') {
        method('POST'); $u = api_user($db); [$limit, $offset] = pagination();
        $total = (int)db_one($db, 'SELECT COUNT(*) AS n FROM ab_profiles WHERE uid=:uid AND personal=0', ['uid'=>$u['id']])['n'];
        $rows = db_all($db, 'SELECT guid,name,owner,note,rule FROM ab_profiles WHERE uid=:uid AND personal=0 ORDER BY name LIMIT :limit OFFSET :offset', ['uid'=>$u['id'],'limit'=>$limit,'offset'=>$offset]);
        reply(['total'=>$total,'data'=>$rows]);
    }
    if ($path === '/api/ab/peers') {
        method('POST'); $u = api_user($db); $guid = text_field($_GET, 'ab', 128); $profile = profile_for($db, $u, $guid);
        [$limit, $offset] = pagination(); $total = (int)db_one($db, 'SELECT COUNT(*) AS n FROM ab_profile_peers WHERE guid=:guid', ['guid'=>$profile['guid']])['n'];
        $rows = db_all($db, 'SELECT id,payload FROM ab_profile_peers WHERE guid=:guid ORDER BY id LIMIT :limit OFFSET :offset', ['guid'=>$profile['guid'],'limit'=>$limit,'offset'=>$offset]);
        reply(['total'=>$total,'data'=>array_map('profile_peer_payload', $rows)]);
    }
    if (preg_match('#^/api/ab/tags/([^/]+)$#', $path, $m)) {
        method('POST'); $u = api_user($db); $profile = profile_for($db, $u, $m[1]);
        $rows = db_all($db, 'SELECT name,color FROM ab_profile_tags WHERE guid=:guid ORDER BY name', ['guid'=>$profile['guid']]);
        reply(array_map(fn($r) => ['name'=>$r['name'],'color'=>(int)$r['color']], $rows));
    }
    if (preg_match('#^/api/ab/peer/(add|update)/([^/]+)$#', $path, $m)) {
        method($m[1] === 'add' ? 'POST' : 'PUT'); $u = api_user($db); $profile = profile_for($db, $u, $m[2]); $d = json_body();
        $id = text_field($d, 'id', 128); if ($id === '') fail(422, '设备 ID 不能为空');
        if ($m[1] === 'add' && db_one($db, 'SELECT id FROM ab_profile_peers WHERE guid=:guid AND id=:id', ['guid'=>$profile['guid'],'id'=>$id])) fail(409, '设备已存在');
        $existing = db_one($db, 'SELECT payload FROM ab_profile_peers WHERE guid=:guid AND id=:id', ['guid'=>$profile['guid'],'id'=>$id]);
        $payload = $existing ? json_decode($existing['payload'], true, 64, JSON_THROW_ON_ERROR) : [];
        foreach ($d as $k=>$v) if ($k !== 'id') $payload[$k] = $v;
        $payload['id'] = $id;
        db_upsert($db,'ab_profile_peers',['guid'=>$profile['guid'],'id'=>$id,'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'updated_at'=>time()],['guid','id'],['payload','updated_at']);
        action_ok();
    }
    if (preg_match('#^/api/ab/peer/([^/]+)$#', $path, $m)) {
        method('DELETE'); $u = api_user($db); $profile = profile_for($db, $u, $m[1]); $ids = json_decode(file_get_contents('php://input'), true);
        if (!is_array($ids)) fail(400, '请求必须为 JSON 数组');
        foreach ($ids as $id) if (is_string($id)) db_exec($db, 'DELETE FROM ab_profile_peers WHERE guid=:guid AND id=:id', ['guid'=>$profile['guid'],'id'=>$id]);
        action_ok();
    }
    if (preg_match('#^/api/ab/tag/(add|rename|update)/([^/]+)$#', $path, $m)) {
        method($m[1] === 'add' ? 'POST' : 'PUT'); $u = api_user($db); $profile = profile_for($db, $u, $m[2]); $d = json_body();
        if ($m[1] === 'rename') {
            $old = text_field($d, 'old', 256); $new = text_field($d, 'new', 256); if ($old === '' || $new === '') fail(422, '标签不能为空');
            db_exec($db, 'UPDATE ab_profile_tags SET name=:new WHERE guid=:guid AND name=:old', ['new'=>$new,'guid'=>$profile['guid'],'old'=>$old]);
            db_exec($db, 'UPDATE ab_profile_peers SET payload=replace(payload,:old,:new) WHERE guid=:guid', ['old'=>$old,'new'=>$new,'guid'=>$profile['guid']]); action_ok();
        }
        $name = text_field($d, 'name', 256); if ($name === '') fail(422, '标签不能为空'); $color = (int)($d['color'] ?? 0);
        if ($m[1] === 'add' && db_one($db, 'SELECT name FROM ab_profile_tags WHERE guid=:guid AND name=:name', ['guid'=>$profile['guid'],'name'=>$name])) fail(409, '标签已存在');
        db_upsert($db,'ab_profile_tags',['guid'=>$profile['guid'],'name'=>$name,'color'=>$color],['guid','name'],['color']); action_ok();
    }
    if (preg_match('#^/api/ab/tag/([^/]+)$#', $path, $m)) {
        method('DELETE'); $u = api_user($db); $profile = profile_for($db, $u, $m[1]); $names = json_decode(file_get_contents('php://input'), true); if (!is_array($names)) fail(400, '请求必须为 JSON 数组');
        foreach ($names as $name) if (is_string($name)) db_exec($db, 'DELETE FROM ab_profile_tags WHERE guid=:guid AND name=:name', ['guid'=>$profile['guid'],'name'=>$name]); action_ok();
    }
    if ($path === '/api/device-group/accessible') {
        method('GET'); api_user($db); reply(['total'=>0,'data'=>[]]);
    }
    if ($path === '/api/sysinfo_ver') {
        method('POST'); header('Content-Type: text/plain; charset=utf-8'); echo '1'; exit;
    }
    if ($path === '/api/devices/deploy') {
        method('POST'); $u = api_user($db); $d = json_body(); $id=text_field($d,'id',128); $uuid=text_field($d,'uuid',256); $pk=text_field($d,'pk',1024); if ($id===''||$uuid===''||$pk==='') fail(422,'设备登记参数不完整');
        db_upsert($db,'device_deployments',['id'=>$id,'uuid'=>$uuid,'pk'=>$pk,'uid'=>$u['id'],'payload'=>json_encode($d,JSON_THROW_ON_ERROR),'updated_at'=>time()],['id','uuid'],['pk','uid','payload','updated_at']); reply(['result'=>'OK']);
    }
    if ($path === '/api/devices/cli') {
        method('POST'); $u=api_user($db); $d=json_body(); $id=text_field($d,'id',128);$uuid=text_field($d,'uuid',256); if($id===''||$uuid==='') fail(422,'设备 ID 或 UUID 不能为空');
        $existing=db_one($db,'SELECT payload FROM device_deployments WHERE id=:id AND uuid=:uuid',['id'=>$id,'uuid'=>$uuid]); $payload=$existing?json_decode($existing['payload'],true,64,JSON_THROW_ON_ERROR):[]; foreach($d as $k=>$v)$payload[$k]=$v;
        db_upsert($db,'device_deployments',['id'=>$id,'uuid'=>$uuid,'pk'=>text_field($payload,'pk',1024),'uid'=>$u['id'],'payload'=>json_encode($payload,JSON_THROW_ON_ERROR),'updated_at'=>time()],['id','uuid'],['pk','uid','payload','updated_at']); action_ok();
    }
    if ($path === '/api/audit') {
        method('PUT'); api_user($db); $d=json_body(); $guid=text_field($d,'guid',256); $note=text_field($d,'note',4096); if($guid==='') fail(422,'guid不能为空'); db_upsert($db,'audit_notes',['guid'=>$guid,'note'=>$note,'updated_at'=>time()],['guid'],['note','updated_at']); action_ok();
    }
    if ($path === '/api/switch-grant') {
        method('POST'); $d=json_body(); $id=text_field($d,'id',128); $v=text_field($d,'switch_code_verifier',256); $sig=text_field($d,'signature',512); $ts=(int)text_field($d,'timestamp',32); if($id===''||$v===''||$sig===''||abs(time()-$ts)>300) reply(['accepted'=>false,'server_time'=>time()]); db_upsert($db,'switch_grants',['id'=>$id,'verifier'=>$v,'timestamp'=>$ts,'signature'=>$sig,'updated_at'=>time()],['id','verifier'],['timestamp','signature','updated_at']); reply(['accepted'=>true]);
    }
    if ($path === '/api/oidc/auth') {
        method('POST'); fail(404, 'OIDC 未配置');
    }
    if ($path === '/api/oidc/auth-query') {
        method('GET'); fail(404, 'OIDC 未配置');
    }
    if ($path === '/api/record') {
        method('POST');
        $raw = file_get_contents('php://input', false, null, 0, 8388605);
        if (strlen($raw) > 8388604) fail(413, '录制分片过大');
        $operation = text_field($_GET, 'op', 32, text_field($_GET, 'action', 32, 'part'));
        $filename = text_field($_GET, 'filename', 512, text_field($_GET, 'name', 512, 'recording.bin'));
        $key = text_field($_GET, 'id', 256, text_field($_GET, 'session_id', 256, $filename));
        db_exec($db, 'INSERT INTO record_chunks(upload_key,operation,filename,chunk_size,payload,created_at) VALUES(:key,:op,:name,:size,:payload,:at)', ['key'=>$key,'op'=>$operation,'name'=>$filename,'size'=>strlen($raw),'payload'=>$raw,'at'=>time()]);
        reply(['ok'=>true]);
    }
    if ($path === '/api/sysinfo' || $path === '/api/heartbeat') {
        method('POST'); $d = json_body(); $id = text_field($d, 'id', 128); $uuid = text_field($d, 'uuid', 256);
        if ($id === '' || $uuid === '') fail(422, '缺少设备 ID 或 UUID');
        if ($path === '/api/sysinfo') {
            $existing = db_one($db, 'SELECT payload,runtime_payload,network_payload FROM device_reports WHERE id=:id AND uuid=:uuid', ['id'=>$id, 'uuid'=>$uuid]);
            $runtime = merge_json_objects(decoded_payload($existing['runtime_payload'] ?? null), report_runtime_payload($d));
            $incomingNetwork = report_network_payload($d);
            if (!array_key_exists('network', $d) && !array_key_exists('private_ips', $d)) unset($incomingNetwork['private_ips']);
            $network = refresh_public_network(merge_json_objects(decoded_payload($existing['network_payload'] ?? null), $incomingNetwork));
            db_upsert($db,'device_reports',[
                'id'=>$id,'uuid'=>$uuid,'payload'=>json_encode(merge_json_objects(decoded_payload($existing['payload'] ?? null), $d),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
                'last_seen'=>time(),'last_heartbeat'=>0,'heartbeat_payload'=>'{}',
                'runtime_payload'=>json_object_text($runtime),'network_payload'=>json_object_text($network),
            ],['id','uuid'],['payload','last_seen','runtime_payload','network_payload']);
            header('Content-Type: text/plain; charset=utf-8'); echo 'SYSINFO_UPDATED'; exit;
        }
        $known = db_one($db, 'SELECT id FROM device_reports WHERE id=:id AND uuid=:uuid', ['id' => $id, 'uuid' => $uuid]);
        $now=time();
        $existingNetwork = refresh_public_network(decoded_payload(db_one($db, 'SELECT network_payload FROM device_reports WHERE id=:id AND uuid=:uuid', ['id'=>$id, 'uuid'=>$uuid])['network_payload'] ?? null));
        db_upsert($db,'device_reports',[
            'id'=>$id,'uuid'=>$uuid,'payload'=>'{}','last_seen'=>$now,'last_heartbeat'=>$now,
            'heartbeat_payload'=>json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
            'runtime_payload'=>null,'network_payload'=>json_object_text($existingNetwork),
        ],['id','uuid'],['last_seen','last_heartbeat','heartbeat_payload','network_payload']);
        reply($known ? new stdClass() : ['sysinfo' => true]);
    }
    if (in_array($path, ['/api/audit/conn', '/api/audit/file', '/api/audit/alarm'], true)) {
        method('POST'); $d = json_body(); $id = text_field($d, 'id', 128); $uuid = text_field($d, 'uuid', 256);
        if ($id === '') fail(422, '缺少设备 ID');
        $nonce = $d['nonce'] ?? '';
        if (!is_string($nonce) && !is_int($nonce)) fail(422, 'nonce 格式错误');
        if (strlen((string)$nonce) > 256) fail(422, 'nonce 过长');
        db_insert_ignore($db,'audit_events',['device_id'=>$id,'uuid'=>$uuid,'kind'=>basename($path),'nonce'=>(string)$nonce,'payload'=>json_encode($d,JSON_THROW_ON_ERROR),'created_at'=>time()]);
        reply(['ok' => true]);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && !str_starts_with($path, '/api/')) render_page(__DIR__ . '/home.html');
    fail(404, '接口未实现');
} catch (RequestError $error) {
    reply(['error' => $error->getMessage()], $error->status);
} catch (InstallationConflict $error) {
    reply(['error' => $error->getMessage()], 409);
} catch (Throwable $error) {
    error_log('RustDesk API failure: ' . get_class($error) . ': ' . $error->getMessage());
    reply(['error' => '服务端处理失败，请检查数据库及服务器日志'], 500);
}
