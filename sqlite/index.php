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
function request_body(): string
{
    static $raw = null;
    if ($raw === null) {
        $value = file_get_contents('php://input', false, null, 0, 4194305);
        $raw = $value === false ? '' : $value;
    }
    if (strlen($raw) > 4194304) fail(413, '请求内容过大');
    return $raw;
}
function json_body(): array
{
    $raw = request_body();
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
    if ((!isset($data['client_arch']) || $data['client_arch'] === '') && isset($data['arch']) && is_string($data['arch'])) $data['client_arch'] = $data['arch'];
    $allowed = ['distribution' => ['desktop','mobile','sos','installed','portable','msi','appimage','linux_package','unknown'], 'install_mode' => ['installed','portable','live','unknown']];
    $preserve = ['client_id','client_uuid','product','edition','version','build_number','source_commit','channel','platform','client_arch','executable_name','os_version'];
    foreach (['platform', 'distribution', 'install_mode', 'client_arch', 'executable_name', 'product', 'edition', 'version', 'build_number', 'build_seq', 'source_commit', 'channel', 'client_id', 'client_uuid', 'os_version', 'last_update_check', 'last_update_status', 'last_update_error', 'last_update_source'] as $key) {
        if ($key === 'build_seq' && array_key_exists($key, $data) && is_numeric($data[$key])) { $runtime[$key] = (int)$data[$key]; continue; }
        if (array_key_exists($key, $data) && is_string($data[$key]) && strlen($data[$key]) <= 256) {
            $value = trim($data[$key]);
            if (!in_array($key, $preserve, true)) $value = strtolower($value);
            $runtime[$key] = isset($allowed[$key]) ? (in_array(strtolower($value), $allowed[$key], true) ? strtolower($value) : 'unknown') : $value;
        }
    }
    foreach (['enable_check_update', 'allow_auto_update', 'enable_scheduled_update'] as $key) {
        if (array_key_exists($key, $data) && is_bool($data[$key])) $runtime[$key] = $data[$key];
    }
    if (array_key_exists('scheduled_update_interval_hours', $data) && is_numeric($data['scheduled_update_interval_hours'])) {
        $hours=(int)$data['scheduled_update_interval_hours']; if($hours>=1&&$hours<=168)$runtime['scheduled_update_interval_hours']=$hours;
    }
    if (array_key_exists('update_policy_revision', $data) && is_numeric($data['update_policy_revision'])) {
        $revision=(int)$data['update_policy_revision']; if($revision>=0)$runtime['update_policy_revision']=$revision;
    }
    return $runtime;
}
function release_identity(array $report, array $runtime): array
{
    $pick = static function (array $keys) use ($report, $runtime) {
        foreach ([$runtime, $report] as $source) foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) continue;
            $value = $source[$key];
            if ($value === null || $value === '' || $value === []) continue;
            return $value;
        }
        return null;
    };
    $text = static function (array $keys) use ($pick): string {
        $value = $pick($keys);
        return is_scalar($value) ? trim((string)$value) : '';
    };
    $seq = $pick(['build_seq']);
    return ['client_id'=>$text(['client_id']),'client_uuid'=>$text(['client_uuid']),'product'=>$text(['product']),'edition'=>$text(['edition']),'version'=>$text(['version']),'build_number'=>$text(['build_number']),'build_seq'=>is_numeric($seq)?(int)$seq:null,'channel'=>$text(['channel']),'platform'=>$text(['platform']),'arch'=>$text(['arch','client_arch']),'distribution'=>$text(['distribution']),'install_mode'=>$text(['install_mode']),'source_commit'=>$text(['source_commit']),'os'=>$text(['os']),'os_version'=>$text(['os_version'])];
}
function canonical_release_identity(PDO $db, array $release): array
{
    $buildNumber = trim((string)($release['build_number'] ?? ''));
    $sourceCommit = trim((string)($release['source_commit'] ?? ''));
    if ($buildNumber === '' && $sourceCommit === '') return $release;
    $rows = db_all($db, 'SELECT version,build_seq,manifest FROM update_releases WHERE active=1 ORDER BY published_at DESC');
    foreach ($rows as $row) {
        $manifest = decoded_payload($row['manifest'] ?? null);
        if ($buildNumber !== '' && (string)($manifest['build_number'] ?? '') !== $buildNumber) continue;
        if ($sourceCommit !== '' && (string)($manifest['source_commit'] ?? '') !== $sourceCommit) continue;
        $release['version'] = (string)($manifest['version'] ?? $row['version'] ?? $release['version'] ?? '');
        $release['build_seq'] = (int)($manifest['build_seq'] ?? $row['build_seq'] ?? $release['build_seq'] ?? 0);
        $release['build_number'] = (string)($manifest['build_number'] ?? $buildNumber);
        $release['source_commit'] = (string)($manifest['source_commit'] ?? $sourceCommit);
        break;
    }
    return $release;
}
function remember_release_identity(PDO $db, array $data, bool $allowPackageIdentity = false): void
{
    $text = static function (array $data, string $key, int $max): string {
        $value = $data[$key] ?? '';
        return is_string($value) && strlen($value) <= $max ? $value : '';
    };
    $uuid = $text($data, 'client_uuid', 256); if ($uuid === '') $uuid = $text($data, 'uuid', 256);
    $id = $text($data, 'client_id', 128); if ($id === '') $id = $text($data, 'id', 128);
    $rows = $uuid !== '' ? db_all($db, 'SELECT id,uuid,payload,runtime_payload FROM device_reports WHERE uuid=:uuid', ['uuid'=>$uuid]) : [];
    if (count($rows) !== 1 && $id !== '') $rows = db_all($db, 'SELECT id,uuid,payload,runtime_payload FROM device_reports WHERE id=:id', ['id'=>$id]);
    if (count($rows) !== 1) return;
    $row = $rows[0]; $payload = decoded_payload($row['payload']); $runtime = decoded_payload($row['runtime_payload'] ?? null);
    foreach (['client_id','client_uuid','product','edition','version','build_number','build_seq','channel','platform','arch','distribution','install_mode','source_commit','os','os_version','target_key','package_kind'] as $key) {
        if (!$allowPackageIdentity && in_array($key, ['target_key','package_kind'], true)) continue;
        if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') continue;
        if (in_array($key, ['distribution','install_mode','platform','os'], true) && isset($payload[$key]) && $payload[$key] !== '' && $payload[$key] !== null) continue;
        if ($key === 'build_seq') { if (!is_numeric($data[$key])) continue; $payload[$key] = (int)$data[$key]; continue; }
        if (!is_string($data[$key]) || strlen($data[$key]) > 256) continue;
        $payload[$key] = $data[$key];
    }
    if (($payload['client_arch'] ?? '') === '' && ($payload['arch'] ?? '') !== '') $payload['client_arch'] = $payload['arch'];
    $runtime = array_merge($runtime, report_runtime_payload($payload));
    db_exec($db, 'UPDATE device_reports SET payload=:payload, runtime_payload=:runtime WHERE id=:id AND uuid=:uuid', ['payload'=>json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'runtime'=>json_encode($runtime, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'id'=>$row['id'],'uuid'=>$row['uuid']]);
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
        'enabled' => (bool)$u['enabled'], 'address_book_scope' => (string)($u['address_book_scope'] ?? 'self'), 'create_time' => (int)$u['create_time']];
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
    if ($book) {
        $payload = decoded_payload($book['payload']);
        $profile = db_one($db, 'SELECT guid FROM ab_profiles WHERE uid=:uid AND personal=1', ['uid'=>$uid]);
        if ($profile) {
            $peers = []; foreach (($payload['peers'] ?? []) as $peer) if (is_array($peer) && isset($peer['id'])) $peers[(string)$peer['id']] = $peer;
            foreach (db_all($db, 'SELECT id,payload FROM ab_profile_peers WHERE guid=:guid ORDER BY id', ['guid'=>$profile['guid']]) as $row) { $peer=decoded_payload($row['payload']); $peer['id']=$row['id']; $peers[(string)$row['id']]=array_replace($peers[(string)$row['id']]??[], $peer); }
            $payload['peers'] = array_values($peers);
            $tags = array_values(array_unique(array_merge(is_array($payload['tags']??null)?$payload['tags']:[], array_column(db_all($db, 'SELECT name FROM ab_profile_tags WHERE guid=:guid ORDER BY name', ['guid'=>$profile['guid']]), 'name'))));
            $payload['tags'] = $tags;
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            if ($encoded !== (string)$book['payload']) {
                $updatedAt = time();
                save_exact_address_book($db, $uid, $payload, $updatedAt);
                $book['payload'] = $encoded;
                // RustDesk uses this value to decide whether its local address book cache is stale.
                $book['updated_at'] = $updatedAt;
            }
        }
        return ['updated_at' => date('Y-m-d H:i:s', (int)$book['updated_at']), 'data' => $book['payload']];
    }
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
function version_tuple(string $version): array
{
    if (!preg_match('/^(\d+)\.(\d+)\.(\d+)/', trim($version), $m)) return [0, 0, 0];
    return [(int)$m[1], (int)$m[2], (int)$m[3]];
}
function compare_release(array $a, array $b): int
{
    $av = version_tuple((string)($a['version'] ?? '')); $bv = version_tuple((string)($b['version'] ?? ''));
    return $av <=> $bv ?: ((int)($a['build_seq'] ?? 0) <=> (int)($b['build_seq'] ?? 0));
}
function update_mode(string $mode): string { return in_array($mode, ['disabled','notify','download','auto_install'], true) ? $mode : 'notify'; }
function update_target_candidates(array $client): array
{
    $targetKey = text_field($client, 'target_key', 128);
    if ($targetKey !== '') return [$targetKey];
    $platform = strtolower(text_field($client, 'platform', 32));
    $arch = strtolower(text_field($client, 'arch', 32));
    $edition = strtolower(text_field($client, 'edition', 32));
    $packageKind = strtolower(text_field($client, 'package_kind', 16));
    if ($platform === '' || $arch === '') return [];
    $allowedKinds = match ($platform) {
        'windows' => ['exe', 'msi'],
        'macos' => ['dmg'],
        'linux' => ['appimage'],
        'android' => ['apk'],
        default => [],
    };
    if ($packageKind === '' || !in_array($packageKind, $allowedKinds, true)) return [];
    $keys = [];
    if ($edition !== '') $keys[] = "$platform-$arch-$packageKind-$edition";
    $keys[] = "$platform-$arch-$packageKind";
    return $keys;
}
function manifest_supports_client(array $manifest, array $client, bool $allowUnknownPackageKind = false): bool
{
    foreach (['product', 'edition'] as $field) {
        $wanted = text_field($client, $field, 64);
        if ($wanted !== '' && isset($manifest[$field]) && (string)$manifest[$field] !== $wanted
            && !($field === 'edition' && (string)$manifest[$field] === 'multi')) return false;
    }
    $candidates = update_target_candidates($client);
    $targets = is_array($manifest['targets'] ?? null) ? $manifest['targets'] : [];
    if (!$candidates && $allowUnknownPackageKind && text_field($client, 'package_kind', 16) === '') {
        $product = text_field($client, 'product', 64);
        $platform = strtolower(text_field($client, 'platform', 32));
        $arch = strtolower(text_field($client, 'arch', 32));
        $edition = strtolower(text_field($client, 'edition', 32));
        if ($product !== '' && $platform !== '' && $arch !== '' && $edition !== '') {
            $requiredKinds = match ($platform) {
                'windows' => ['exe', 'msi'], 'macos' => ['dmg'], 'linux' => ['appimage'], 'android' => ['apk'], default => [],
            };
            foreach ($requiredKinds as $kind) {
                $specific = "$platform-$arch-$kind-$edition";
                $generic = "$platform-$arch-$kind";
                if (!isset($targets[$specific]) && !isset($targets[$generic])) return false;
            }
            return $requiredKinds !== [];
        }
        return false;
    }
    if (!$candidates) return text_field($client, 'target_key', 128) === ''
        && text_field($client, 'platform', 32) === '' && text_field($client, 'arch', 32) === '';
    foreach ($candidates as $key) if (isset($targets[$key]) && is_array($targets[$key])) return true;
    return false;
}
function public_update_manifest(PDO $db, string $channel, ?string $targetVersion = null, ?int $targetBuild = null, array $client = [], bool $allowUnknownPackageKind = false): ?array
{
    $best = null;
    foreach (db_all($db, 'SELECT version,build_seq,channel,manifest FROM update_releases WHERE channel=:channel AND active=1', ['channel'=>$channel]) as $row) {
        $manifest = decoded_payload($row['manifest']); $manifest['version'] ??= $row['version']; $manifest['build_seq'] ??= (int)$row['build_seq']; $manifest['channel'] ??= $row['channel'];
        if ($targetVersion !== null && $targetVersion !== '' && compare_release($manifest, ['version'=>$targetVersion,'build_seq'=>$targetBuild ?? PHP_INT_MAX]) > 0) continue;
        if ($client && !manifest_supports_client($manifest, $client, $allowUnknownPackageKind)) continue;
        if ($best === null || compare_release($manifest, $best) > 0) $best = $manifest;
    }
    return $best;
}
function update_policy(PDO $db, string $id, string $uuid, string $channel): array
{
    $policy = db_one($db, 'SELECT * FROM device_update_policies WHERE id=:id AND uuid=:uuid', ['id'=>$id,'uuid'=>$uuid])
        ?: ['id'=>$id,'uuid'=>$uuid,'mode'=>'notify','channel'=>$channel,'target_version'=>null,'target_build_seq'=>null,'auto_install'=>0,'enable_check_update'=>0,'allow_auto_update'=>0,'enable_scheduled_update'=>0,'scheduled_update_interval_hours'=>5,'policy_revision'=>0,'updated_at'=>0];
    return normalize_update_policy($policy);
}
function normalize_update_policy(array $policy): array
{
    $policy['auto_install'] = (bool)($policy['auto_install'] ?? false);
    $policy['enable_check_update'] = (bool)($policy['enable_check_update'] ?? false);
    $policy['allow_auto_update'] = (bool)($policy['allow_auto_update'] ?? false);
    $policy['enable_scheduled_update'] = (bool)($policy['enable_scheduled_update'] ?? false);
    $policy['scheduled_update_interval_hours'] = (int)($policy['scheduled_update_interval_hours'] ?? 5);
    $policy['policy_revision'] = (int)($policy['policy_revision'] ?? 0);
    $policy['updated_at'] = (int)($policy['updated_at'] ?? 0);
    if (isset($policy['target_build_seq'])) $policy['target_build_seq'] = (int)$policy['target_build_seq'];
    return $policy;
}
function update_policy_payload(array $policy, string $id, string $uuid): array
{
    return [
        'client_id'=>$id,'client_uuid'=>$uuid,'policy_revision'=>(int)$policy['policy_revision'],
        'enable_check_update'=>(bool)$policy['enable_check_update'],'allow_auto_update'=>(bool)$policy['allow_auto_update'],
        'enable_scheduled_update'=>(bool)$policy['enable_scheduled_update'],'scheduled_update_interval_hours'=>(int)$policy['scheduled_update_interval_hours'],
        'mode'=>(string)$policy['mode'],'channel'=>(string)$policy['channel'],
        'target_version'=>$policy['target_version'] ?? null,'target_build_seq'=>$policy['target_build_seq'] ?? null,
        'updated_at'=>(int)$policy['updated_at'],
    ];
}
function sync_reported_update_policy(PDO $db, string $id, string $uuid, array $runtime): void
{
    foreach (['enable_check_update','allow_auto_update','enable_scheduled_update','scheduled_update_interval_hours','update_policy_revision'] as $field) {
        if (!array_key_exists($field, $runtime)) return;
    }
    $reportedRevision = (int)$runtime['update_policy_revision'];
    $existing = db_one($db, 'SELECT * FROM device_update_policies WHERE id=:id AND uuid=:uuid', ['id'=>$id,'uuid'=>$uuid]);
    if ($existing && (int)$existing['policy_revision'] !== $reportedRevision) return;
    $reported = [
        'enable_check_update'=>(int)(bool)$runtime['enable_check_update'],
        'allow_auto_update'=>(int)(bool)$runtime['allow_auto_update'],
        'enable_scheduled_update'=>(int)(bool)$runtime['enable_scheduled_update'],
        'scheduled_update_interval_hours'=>(int)$runtime['scheduled_update_interval_hours'],
    ];
    if ($existing
        && (int)$existing['enable_check_update'] === $reported['enable_check_update']
        && (int)$existing['allow_auto_update'] === $reported['allow_auto_update']
        && (int)$existing['enable_scheduled_update'] === $reported['enable_scheduled_update']
        && (int)$existing['scheduled_update_interval_hours'] === $reported['scheduled_update_interval_hours']) return;
    $revision = $existing ? $reportedRevision + 1 : $reportedRevision;
    db_upsert($db, 'device_update_policies', [
        'id'=>$id,'uuid'=>$uuid,'mode'=>$existing['mode'] ?? 'notify','channel'=>$existing['channel'] ?? 'stable',
        'target_version'=>$existing['target_version'] ?? null,'target_build_seq'=>$existing['target_build_seq'] ?? null,
        'auto_install'=>(int)($existing['auto_install'] ?? false),
        'enable_check_update'=>$reported['enable_check_update'],'allow_auto_update'=>$reported['allow_auto_update'],
        'enable_scheduled_update'=>$reported['enable_scheduled_update'],'scheduled_update_interval_hours'=>$reported['scheduled_update_interval_hours'],
        'policy_revision'=>$revision,'updated_by'=>null,'updated_at'=>time(),
    ], ['id','uuid'], ['mode','channel','target_version','target_build_seq','auto_install','enable_check_update','allow_auto_update','enable_scheduled_update','scheduled_update_interval_hours','policy_revision','updated_by','updated_at']);
}
function update_policy_boolean(array $data, string $field, bool $default): bool
{
    if (!array_key_exists($field, $data)) return $default;
    if (!is_bool($data[$field]) && !in_array($data[$field], [0,1,'0','1'], true)) fail(422, "$field 必须是布尔值");
    return (bool)$data[$field];
}
function scheduled_update_interval(array $data, int $default): int
{
    if (!array_key_exists('scheduled_update_interval_hours', $data)) return $default;
    $value = $data['scheduled_update_interval_hours'];
    if (!((is_int($value) && $value >= 1 && $value <= 168) || (is_string($value) && ctype_digit($value) && (int)$value >= 1 && (int)$value <= 168))) fail(422, 'scheduled_update_interval_hours 必须在 1 到 168 小时之间');
    return (int)$value;
}
function update_batch_devices(PDO $db, array $data): array
{
    if (($data['all'] ?? false) === true) return db_all($db, 'SELECT id,uuid FROM device_reports UNION SELECT id,uuid FROM device_deployments ORDER BY id,uuid');
    $devices = $data['devices'] ?? null;
    if (!is_array($devices) || !$devices || count($devices) > 1000) fail(422, 'devices 必须包含 1 到 1000 个客户端');
    $resolved = [];
    foreach ($devices as $device) {
        if (!is_array($device)) fail(422, '客户端身份格式错误');
        $id = text_field($device, 'id', 128, text_field($device, 'client_id', 128));
        $uuid = text_field($device, 'uuid', 256, text_field($device, 'client_uuid', 256));
        if ($id === '' || $uuid === '') fail(422, '设备 ID 和 UUID 不能为空');
        [$id, $uuid] = device_update_identity($db, $id, $uuid);
        $resolved[$id."\0".$uuid] = ['id'=>$id,'uuid'=>$uuid];
    }
    return array_values($resolved);
}
function patch_scheduled_update_policy(PDO $db, array $actor, string $id, string $uuid, array $data): array
{
    $existing = db_one($db, 'SELECT * FROM device_update_policies WHERE id=:id AND uuid=:uuid', ['id'=>$id,'uuid'=>$uuid]);
    $enabled = update_policy_boolean($data, 'enable_scheduled_update', (bool)($existing['enable_scheduled_update'] ?? false));
    $interval = scheduled_update_interval($data, (int)($existing['scheduled_update_interval_hours'] ?? 5));
    if ($existing && (bool)($existing['enable_scheduled_update'] ?? false) === $enabled
        && (int)($existing['scheduled_update_interval_hours'] ?? 5) === $interval) {
        return update_policy($db, $id, $uuid, (string)($existing['channel'] ?? 'stable'));
    }
    $revision = ((int)($existing['policy_revision'] ?? 0)) + 1;
    db_upsert($db, 'device_update_policies', [
        'id'=>$id,'uuid'=>$uuid,'mode'=>$existing['mode'] ?? 'notify','channel'=>$existing['channel'] ?? 'stable',
        'target_version'=>$existing['target_version'] ?? null,'target_build_seq'=>$existing['target_build_seq'] ?? null,
        'auto_install'=>(int)($existing['auto_install'] ?? false),'enable_check_update'=>(int)($existing['enable_check_update'] ?? false),
        'allow_auto_update'=>(int)($existing['allow_auto_update'] ?? false),'enable_scheduled_update'=>(int)$enabled,
        'scheduled_update_interval_hours'=>$interval,'policy_revision'=>$revision,'updated_by'=>(int)$actor['id'],'updated_at'=>time(),
    ], ['id','uuid'], ['mode','channel','target_version','target_build_seq','auto_install','enable_check_update','allow_auto_update','enable_scheduled_update','scheduled_update_interval_hours','policy_revision','updated_by','updated_at']);
    return update_policy($db, $id, $uuid, (string)($existing['channel'] ?? 'stable'));
}
function resolve_update_identity(PDO $db, string $id, string $uuid): array
{
    $exact = db_one($db, 'SELECT id,uuid FROM device_reports WHERE id=:id AND uuid=:uuid', ['id'=>$id,'uuid'=>$uuid]);
    if ($exact) return [(string)$exact['id'], (string)$exact['uuid']];
    if ($id !== 'RustDesk Yan') return [$id, $uuid];
    $matches = db_all($db, 'SELECT id,uuid FROM device_reports WHERE uuid=:uuid LIMIT 2', ['uuid'=>$uuid]);
    if (count($matches) === 1) return [(string)$matches[0]['id'], (string)$matches[0]['uuid']];
    return [$id, $uuid];
}
function strict_base64(string $value, int $bytes): ?string
{
    $decoded = base64_decode($value, true);
    return $decoded !== false && strlen($decoded) === $bytes ? $decoded : null;
}
function update_device_auth(PDO $db, string $method, string $path, string $id, string $uuid, string $commandId = ''): array
{
    [$id, $uuid] = resolve_update_identity($db, $id, $uuid);
    $headerId = trim((string)($_SERVER['HTTP_X_RUSTDESK_DEVICE_ID'] ?? ''));
    $headerKey = trim((string)($_SERVER['HTTP_X_RUSTDESK_DEVICE_PUBLIC_KEY'] ?? ''));
    $timestampText = trim((string)($_SERVER['HTTP_X_RUSTDESK_DEVICE_TIMESTAMP'] ?? ''));
    $nonce = trim((string)($_SERVER['HTTP_X_RUSTDESK_DEVICE_NONCE'] ?? ''));
    $signatureText = trim((string)($_SERVER['HTTP_X_RUSTDESK_DEVICE_SIGNATURE'] ?? ''));
    if ($headerId === '' || $headerKey === '' || $timestampText === '' || $nonce === '' || $signatureText === '') fail(401, '缺少设备签名凭据');
    if ($headerId !== $id || !preg_match('/^-?[0-9]{1,20}$/', $timestampText) || !preg_match('/^[A-Za-z0-9._~-]{16,128}$/', $nonce)) fail(401, '设备签名凭据无效');
    $timestamp = (int)$timestampText;
    if (abs(time() - $timestamp) > 300) fail(401, '设备签名已过期');
    $suppliedKey = strict_base64($headerKey, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
    $signature = strict_base64($signatureText, SODIUM_CRYPTO_SIGN_BYTES);
    if ($suppliedKey === null || $signature === null) fail(401, '设备签名凭据无效');
    $trusted = database_driver() === 'sqlite'
        ? db_one($db, 'SELECT public_key FROM device_update_keys WHERE device_id=:id AND uuid=:uuid', ['id'=>$id,'uuid'=>$uuid])
        : null;
    $deployment = db_one($db, 'SELECT pk FROM device_deployments WHERE id=:id AND uuid=:uuid', ['id'=>$id,'uuid'=>$uuid]);
    $registeredText = (string)($trusted['public_key'] ?? $deployment['pk'] ?? '');
    $registeredKey = $registeredText === '' ? null : strict_base64($registeredText, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
    if ($registeredText !== '' && $registeredKey === null) fail(401, '设备公钥未登记或不匹配');
    if ($registeredKey !== null && !hash_equals($registeredKey, $suppliedKey)) fail(401, '设备公钥未登记或不匹配');
    if ($registeredKey === null) {
        if (database_driver() !== 'sqlite') fail(401, '设备公钥未登记或不匹配');
        $report = db_one($db, 'SELECT last_seen,last_heartbeat FROM device_reports WHERE id=:id AND uuid=:uuid', ['id'=>$id,'uuid'=>$uuid]);
        $latest = max((int)($report['last_seen'] ?? 0), (int)($report['last_heartbeat'] ?? 0));
        if (!$report || $latest < time() - 90) fail(401, '设备公钥未登记或不匹配');
    }
    $canonical = "rustdesk-update-auth-v1\n"
        .'method='.strtoupper($method)."\n"
        .'path='.$path."\n"
        .'client_id='.$id."\n"
        .'client_uuid='.$uuid."\n"
        .'timestamp='.$timestampText."\n"
        .'nonce='.$nonce."\n"
        .'command_id='.$commandId."\n"
        .'body_sha256='.hash('sha256', request_body())."\n";
    $verificationKey = $registeredKey ?? $suppliedKey;
    if (!sodium_crypto_sign_verify_detached($signature, $canonical, $verificationKey)) fail(401, '设备签名校验失败');
    try {
        txn($db, function () use ($db, $id, $uuid, $nonce, $headerKey, $suppliedKey) {
            $now = time();
            if (database_driver() === 'sqlite') {
                db_insert_ignore($db, 'device_update_keys', ['device_id'=>$id,'uuid'=>$uuid,'public_key'=>$headerKey,'first_seen_at'=>$now,'last_seen_at'=>$now]);
                $bound = db_one($db, 'SELECT public_key FROM device_update_keys WHERE device_id=:id AND uuid=:uuid', ['id'=>$id,'uuid'=>$uuid]);
                $boundKey = strict_base64((string)($bound['public_key'] ?? ''), SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
                if ($boundKey === null || !hash_equals($boundKey, $suppliedKey)) fail(401, '设备公钥未登记或不匹配');
                db_exec($db, 'UPDATE device_update_keys SET last_seen_at=:seen WHERE device_id=:id AND uuid=:uuid', ['seen'=>$now,'id'=>$id,'uuid'=>$uuid]);
            }
            db_exec($db, 'DELETE FROM device_update_nonces WHERE created_at<:expired', ['expired'=>time()-600]);
            db_exec($db, 'INSERT INTO device_update_nonces(device_id,uuid,nonce,created_at) VALUES(:id,:uuid,:nonce,:created)', ['id'=>$id,'uuid'=>$uuid,'nonce'=>$nonce,'created'=>time()]);
        });
    } catch (PDOException $error) {
        if ((string)$error->getCode() === '23000') fail(409, '设备签名 nonce 已使用');
        throw $error;
    }
    return [$id, $uuid];
}
function device_update_identity(PDO $db, string $id, string $uuid): array
{
    [$resolvedId, $resolvedUuid] = resolve_update_identity($db, $id, $uuid);
    $known = db_one($db, 'SELECT id,uuid FROM device_reports WHERE id=:id AND uuid=:uuid UNION SELECT id,uuid FROM device_deployments WHERE id=:id AND uuid=:uuid LIMIT 1', ['id'=>$resolvedId,'uuid'=>$resolvedUuid]);
    if (!$known) fail(404, '客户端 ID 或 UUID 不匹配');
    return [(string)$known['id'], (string)$known['uuid']];
}
function command_status_label(string $status): string
{
    return match ($status) {
        'pending' => '等待客户端接收', 'accepted' => '客户端已接收', 'received' => '客户端已接收', 'checking' => '正在检查',
        'started' => '正在处理', 'downloaded' => '下载完成', 'installing' => '正在安装',
        'installed' => '安装完成', 'completed' => '执行完成', 'no_update' => '已是最新版本', 'deferred' => '已推迟',
        'failed' => '执行失败', 'rolled_back' => '已回滚', 'rollback_failed' => '回滚失败',
        'expired' => '已过期', default => $status,
    };
}
function public_update_command(array $row): array
{
    foreach (['target_build_seq','created_at','expires_at','updated_at'] as $field) if (isset($row[$field])) $row[$field] = (int)$row[$field];
    $row['status_label'] = command_status_label((string)$row['status']);
    return $row;
}
function expire_update_commands(PDO $db, string $id, string $uuid): void
{
    db_exec($db, "UPDATE device_update_commands SET status='expired',updated_at=:now WHERE device_id=:id AND uuid=:uuid AND expires_at<=:now AND status='pending'", ['now'=>time(),'id'=>$id,'uuid'=>$uuid]);
}
function update_command_client(PDO $db, string $id, string $uuid): array
{
    $report = db_one($db, 'SELECT payload FROM device_reports WHERE id=:id AND uuid=:uuid', ['id'=>$id,'uuid'=>$uuid]);
    $deployment = db_one($db, 'SELECT payload FROM device_deployments WHERE id=:id AND uuid=:uuid', ['id'=>$id,'uuid'=>$uuid]);
    $client = merge_json_objects(decoded_payload($deployment['payload'] ?? null), decoded_payload($report['payload'] ?? null));
    if (text_field($client, 'arch', 32) === '') $client['arch'] = text_field($client, 'client_arch', 32);
    return $client;
}
function create_update_command(PDO $db, array $actor, string $id, array $data): array
{
    $uuid = text_field($data, 'uuid', 256);
    if ($id === '' || $uuid === '') fail(422, '设备 ID 和 UUID 不能为空');
    [$id, $uuid] = device_update_identity($db, $id, $uuid);
    $action = text_field($data, 'action', 16);
    if (!in_array($action, ['check','install'], true)) fail(422, 'action 必须是 check 或 install');
    $targetVersion = array_key_exists('target_version', $data) ? text_field($data, 'target_version', 32) : '';
    if ($targetVersion !== '' && !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/', $targetVersion)) fail(422, '目标版本格式无效');
    $targetBuild = null;
    if (array_key_exists('target_build_seq', $data) && $data['target_build_seq'] !== null && $data['target_build_seq'] !== '') {
        $value = $data['target_build_seq'];
        if (!((is_int($value) && $value >= 0) || (is_string($value) && ctype_digit($value)))) fail(422, 'target_build_seq 必须是非负整数');
        $targetBuild = (int)$value;
    }
    if (($targetVersion === '') !== ($targetBuild === null)) fail(422, '目标版本和 build_seq 必须同时指定');
    $policy = update_policy($db, $id, $uuid, 'stable');
    $channel = (string)($policy['channel'] ?: 'stable');
    $client = update_command_client($db, $id, $uuid);
    if ($targetVersion !== '') {
        $release = db_one($db, 'SELECT version,build_seq,manifest FROM update_releases WHERE channel=:channel AND version=:version AND build_seq=:build AND active=1', ['channel'=>$channel,'version'=>$targetVersion,'build'=>$targetBuild]);
        if ($release && !manifest_supports_client(decoded_payload($release['manifest']), $client, true)) $release = null;
    } else {
        $manifest = public_update_manifest($db, $channel, null, null, $client, true);
        $release = $manifest ? ['version'=>$manifest['version'] ?? '', 'build_seq'=>$manifest['build_seq'] ?? 0] : null;
    }
    if (!$release && $action === 'install') fail(409, '当前更新通道没有兼容的可安装版本');
    if ($release) { $targetVersion = (string)$release['version']; $targetBuild = (int)$release['build_seq']; }
    $expiresIn = $data['expires_in'] ?? 3600;
    if (!((is_int($expiresIn) && $expiresIn >= 30 && $expiresIn <= 86400) || (is_string($expiresIn) && ctype_digit($expiresIn) && (int)$expiresIn >= 30 && (int)$expiresIn <= 86400))) fail(422, 'expires_in 必须在 30 到 86400 秒之间');
    $now = time(); $expiresAt = $now + (int)$expiresIn; $commandId = bin2hex(random_bytes(16));
    txn($db, function () use ($db, $actor, $id, $uuid, $action, $channel, $targetVersion, $targetBuild, $now, $expiresAt, $commandId) {
        db_exec($db, 'INSERT INTO device_update_commands(command_id,device_id,uuid,action,channel,target_version,target_build_seq,status,last_error,created_at,expires_at,updated_at,updated_by) VALUES(:command,:id,:uuid,:action,:channel,:version,:build,\'pending\',NULL,:created,:expires,:updated,:actor)', [
            'command'=>$commandId,'id'=>$id,'uuid'=>$uuid,'action'=>$action,'channel'=>$channel,'version'=>$targetVersion !== '' ? $targetVersion : null,'build'=>$targetBuild,'created'=>$now,'expires'=>$expiresAt,'updated'=>$now,'actor'=>(int)$actor['id'],
        ]);
        admin_event($db,(int)$actor['id'],'send_update_'.$action,0);
    });
    $row=db_one($db,'SELECT * FROM device_update_commands WHERE command_id=:command',['command'=>$commandId]);
    return public_update_command($row ?: []);
}
function update_command_request(PDO $db, string $id, string $uuid, string $commandId): ?array
{
    if ($commandId === '') return null;
    if (!preg_match('/^[0-9a-f]{32}$/', $commandId)) fail(422, '更新命令 ID 格式错误');
    $command = db_one($db, 'SELECT * FROM device_update_commands WHERE command_id=:command AND device_id=:id AND uuid=:uuid', ['command'=>$commandId,'id'=>$id,'uuid'=>$uuid]);
    if (!$command || !in_array((string)$command['action'], ['check','install'], true)) fail(403, '更新命令与客户端不匹配');
    if ((string)$command['status'] === 'pending' && (int)$command['expires_at'] <= time()) { expire_update_commands($db, $id, $uuid); fail(410, '更新命令已过期'); }
    if ($command['target_version'] !== null && !db_one($db, 'SELECT version FROM update_releases WHERE channel=:channel AND version=:version AND build_seq=:build', ['channel'=>$command['channel'],'version'=>$command['target_version'],'build'=>(int)$command['target_build_seq']])) fail(409, '更新命令锁定的版本不存在');
    return $command;
}
function update_check_response(PDO $db, array $data, ?array $command = null, bool $rememberPackageIdentity = false): array
{
    $id = text_field($data, 'client_id', 128, text_field($data, 'id', 128)); $uuid = text_field($data, 'client_uuid', 256, text_field($data, 'uuid', 256));
    if ($id === '' || $uuid === '') fail(422, '缺少客户端 ID 或 UUID');
    remember_release_identity($db, $data, $rememberPackageIdentity);
    [$id, $uuid] = resolve_update_identity($db, $id, $uuid);
    $version = text_field($data, 'version', 32, '0.0.0'); $build = (int)($data['build_seq'] ?? 0); $channel = text_field($data, 'channel', 32, 'stable'); $policy = update_policy($db, $id, $uuid, $channel); $channel = (string)($policy['channel'] ?: $channel);
    if ($command !== null) {
        $channel = (string)$command['channel'];
        $manifest = $command['target_version'] === null
            ? ((string)$command['action'] === 'check' ? public_update_manifest($db, $channel, null, null, $data) : null)
            : decoded_payload((db_one($db, 'SELECT manifest FROM update_releases WHERE channel=:channel AND version=:version AND build_seq=:build', ['channel'=>$channel,'version'=>$command['target_version'],'build'=>(int)$command['target_build_seq']])['manifest'] ?? null));
        if ($manifest) { $manifest['version'] ??= $command['target_version']; $manifest['build_seq'] ??= (int)$command['target_build_seq']; $manifest['channel'] ??= $channel; }
    } else {
        $manifest = public_update_manifest($db, $channel, $policy['target_version'] ?? null, isset($policy['target_build_seq']) ? (int)$policy['target_build_seq'] : null, $data);
    }
    $mode = update_mode((string)$policy['mode']);
    $base = rtrim((string)(getenv('RUSTDESK_UPDATE_BASE_URL') ?: ''), '/'); $empty = ['update_available'=>false,'mode'=>$mode,'auto_install'=>(bool)$policy['auto_install'],'enable_check_update'=>(bool)$policy['enable_check_update'],'allow_auto_update'=>(bool)$policy['allow_auto_update'],'enable_scheduled_update'=>(bool)$policy['enable_scheduled_update'],'scheduled_update_interval_hours'=>(int)$policy['scheduled_update_interval_hours'],'channel'=>$channel,'current_version'=>$version,'current_build_seq'=>$build,'policy_revision'=>(int)$policy['policy_revision']];
    if (!$manifest) return $empty;
    foreach (['product','edition'] as $field) if (isset($manifest[$field]) && text_field($data, $field, 64) !== (string)$manifest[$field]
        && !($field === 'edition' && (string)$manifest[$field] === 'multi')) return $empty;
    $targetVersion=(string)($manifest['version']??'0.0.0'); $targetBuild=(int)($manifest['build_seq']??0); $latest=array_merge($empty,['target_version'=>$targetVersion,'target_build_seq'=>$targetBuild]); if (compare_release(['version'=>$targetVersion,'build_seq'=>$targetBuild],['version'=>$version,'build_seq'=>$build]) <= 0) return $latest;
    $url = '';
    foreach (update_target_candidates($data) as $key) if (isset($manifest['targets'][$key]['primary'])) { $url=(string)$manifest['targets'][$key]['primary']; break; }
    return array_merge($latest, ['update_available'=>true,'url'=>$url,'manifest_url'=>$base.'/rd/update/v1/manifest/'.rawurlencode($channel).'.json','manifest'=>$manifest]);
}
function update_policy_stream(PDO $db): never
{
    $id = text_field($_GET, 'client_id', 128, text_field($_GET, 'id', 128));
    $uuid = text_field($_GET, 'client_uuid', 256, text_field($_GET, 'uuid', 256));
    if ($id === '' || $uuid === '') fail(422, '缺少客户端 ID 或 UUID');
    [$id, $uuid] = resolve_update_identity($db, $id, $uuid);
    $authenticated = trim((string)($_SERVER['HTTP_X_RUSTDESK_DEVICE_SIGNATURE'] ?? '')) !== '';
    if ($authenticated) [$id, $uuid] = update_device_auth($db, 'GET', '/rd/update/v1/policy/stream', $id, $uuid);
    $afterRevisionText = trim((string)($_GET['after_revision'] ?? ''));
    $lastEventId = trim((string)($_SERVER['HTTP_LAST_EVENT_ID'] ?? ''));
    if ($afterRevisionText !== '' && !ctype_digit($afterRevisionText)) fail(422, 'after_revision 必须是非负整数');
    if ($lastEventId !== '' && !ctype_digit($lastEventId) && !preg_match('/^[0-9a-f]{32}$/', $lastEventId)) fail(422, 'Last-Event-ID 格式错误');
    $afterRevision = $afterRevisionText !== '' ? (int)$afterRevisionText : ($lastEventId !== '' && ctype_digit($lastEventId) ? (int)$lastEventId : -1);
    $channel = text_field($_GET, 'channel', 32, 'stable');
    if (!in_array($channel, ['stable','beta'], true)) fail(422, '更新通道无效');

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    echo "retry: 1000\n\n";
    expire_update_commands($db,$id,$uuid);
    $policy = update_policy($db, $id, $uuid, $channel);
    if ((int)$policy['policy_revision'] > $afterRevision) {
        $payload = update_policy_payload($policy, $id, $uuid);
        echo 'id: '.$policy['policy_revision']."\n";
        echo "event: update-policy\n";
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n\n";
    }
    $commands = $authenticated ? db_all($db, "SELECT * FROM device_update_commands WHERE device_id=:id AND uuid=:uuid AND status='pending' AND expires_at>:now ORDER BY created_at,command_id LIMIT 20", ['id'=>$id,'uuid'=>$uuid,'now'=>time()]) : [];
    foreach ($commands as $command) {
        $payload=['command_id'=>$command['command_id'],'action'=>$command['action'],'client_id'=>$id,'client_uuid'=>$uuid,'target_version'=>$command['target_version'] ?? null,'target_build_seq'=>isset($command['target_build_seq'])?(int)$command['target_build_seq']:null,'created_at'=>(int)$command['created_at'],'expires_at'=>(int)$command['expires_at']];
        echo 'id: '.$command['command_id']."\n";
        echo "event: update-command\n";
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n\n";
    }
    exit;
}
function require_stream_internal_request(): void
{
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (!in_array($remote, ['127.0.0.1', '::1'], true)) fail(404, 'Not Found');
}
function update_policy_stream_auth(PDO $db): never
{
    require_stream_internal_request();
    $id = text_field($_GET, 'client_id', 128, text_field($_GET, 'id', 128));
    $uuid = text_field($_GET, 'client_uuid', 256, text_field($_GET, 'uuid', 256));
    if ($id === '' || $uuid === '') fail(422, '缺少客户端 ID 或 UUID');
    [$id, $uuid] = resolve_update_identity($db, $id, $uuid);
    $authenticated = trim((string)($_SERVER['HTTP_X_RUSTDESK_DEVICE_SIGNATURE'] ?? '')) !== '';
    if ($authenticated) [$id, $uuid] = update_device_auth($db, 'GET', '/rd/update/v1/policy/stream', $id, $uuid);
    $afterRevision = trim((string)($_GET['after_revision'] ?? ''));
    $lastEventId = trim((string)($_SERVER['HTTP_LAST_EVENT_ID'] ?? ''));
    if ($afterRevision !== '' && !ctype_digit($afterRevision)) fail(422, 'after_revision 必须是非负整数');
    if ($lastEventId !== '' && !ctype_digit($lastEventId) && !preg_match('/^[0-9a-f]{32}$/', $lastEventId)) fail(422, 'Last-Event-ID 格式错误');
    $channel = text_field($_GET, 'channel', 32, 'stable');
    if (!in_array($channel, ['stable', 'beta'], true)) fail(422, '更新通道无效');
    reply(['client_id'=>$id, 'client_uuid'=>$uuid, 'authenticated'=>$authenticated]);
}
function update_policy_stream_snapshot(PDO $db): never
{
    require_stream_internal_request();
    $data = json_body();
    $streams = $data['streams'] ?? null;
    if (!is_array($streams) || !array_is_list($streams) || count($streams) > 5000) fail(422, 'streams 必须是最多 5000 项的数组');
    $normalized = [];
    foreach ($streams as $stream) {
        if (!is_array($stream)) fail(422, 'stream 格式错误');
        $connectionId = $stream['connection_id'] ?? '';
        $id = $stream['client_id'] ?? '';
        $uuid = $stream['client_uuid'] ?? '';
        $afterRevision = $stream['after_revision'] ?? -1;
        $authenticated = $stream['authenticated'] ?? false;
        $channel = $stream['channel'] ?? 'stable';
        if (!is_string($connectionId) || !preg_match('/^[A-Za-z0-9._~-]{1,64}$/', $connectionId)) fail(422, 'connection_id 格式错误');
        if (!is_string($id) || $id === '' || strlen($id) > 128 || !is_string($uuid) || $uuid === '' || strlen($uuid) > 256) fail(422, '客户端身份格式错误');
        if (!is_int($afterRevision) || $afterRevision < -1 || !is_bool($authenticated) || !is_string($channel) || !in_array($channel, ['stable', 'beta'], true)) fail(422, 'stream 状态格式错误');
        [$id, $uuid] = resolve_update_identity($db, $id, $uuid);
        $normalized[$connectionId] = ['id'=>$id, 'uuid'=>$uuid, 'after_revision'=>$afterRevision, 'authenticated'=>$authenticated, 'channel'=>$channel];
    }
    $now = time();
    db_exec($db, "UPDATE device_update_commands SET status='expired',updated_at=:now WHERE status='pending' AND expires_at<=:now", ['now'=>$now]);
    $policies = [];
    foreach (db_all($db, 'SELECT * FROM device_update_policies') as $policy) {
        $policies[(string)$policy['id']."\0".(string)$policy['uuid']] = normalize_update_policy($policy);
    }
    $commands = [];
    foreach (db_all($db, "SELECT * FROM device_update_commands WHERE status='pending' AND expires_at>:now ORDER BY created_at,command_id", ['now'=>$now]) as $command) {
        $commands[(string)$command['device_id']."\0".(string)$command['uuid']][] = $command;
    }
    $result = [];
    foreach ($normalized as $connectionId=>$stream) {
        $key = $stream['id']."\0".$stream['uuid'];
        $policy = $policies[$key] ?? normalize_update_policy(['id'=>$stream['id'],'uuid'=>$stream['uuid'],'mode'=>'notify','channel'=>$stream['channel'],'target_version'=>null,'target_build_seq'=>null,'auto_install'=>0,'enable_check_update'=>0,'allow_auto_update'=>0,'enable_scheduled_update'=>0,'scheduled_update_interval_hours'=>5,'policy_revision'=>0,'updated_at'=>0]);
        $events = [];
        if ((int)$policy['policy_revision'] > $stream['after_revision']) {
            $events[] = ['id'=>(string)$policy['policy_revision'], 'type'=>'update-policy', 'data'=>update_policy_payload($policy, $stream['id'], $stream['uuid'])];
        }
        if ($stream['authenticated']) foreach (array_slice($commands[$key] ?? [], 0, 20) as $command) {
            $events[] = ['id'=>(string)$command['command_id'], 'type'=>'update-command', 'data'=>[
                'command_id'=>$command['command_id'],'action'=>$command['action'],'client_id'=>$stream['id'],'client_uuid'=>$stream['uuid'],
                'target_version'=>$command['target_version'] ?? null,'target_build_seq'=>isset($command['target_build_seq'])?(int)$command['target_build_seq']:null,
                'created_at'=>(int)$command['created_at'],'expires_at'=>(int)$command['expires_at'],
            ]];
        }
        $result[$connectionId] = $events;
    }
    reply(['streams'=>$result]);
}
function publish_token_auth(): void
{
    $expected = (string)(getenv('RUSTDESK_UPDATE_PUBLISH_TOKEN') ?: '');
    if ($expected === '' || !preg_match('/^Bearer\s+([^\s]{32,256})$/', trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? '')), $match)
        || !hash_equals($expected, $match[1])) fail(401, '缺少有效发布凭据');
}
function configured_update_keys(): array
{
    $raw = (string)(getenv('RUSTDESK_UPDATE_KEYS_JSON') ?: '{"schema":1,"keys":[]}');
    try { $keys = json_decode($raw, true, 16, JSON_THROW_ON_ERROR); }
    catch (Throwable) { fail(503, '更新公钥配置无效'); }
    return is_array($keys) ? $keys : ['schema'=>1,'keys'=>[]];
}
function validate_update_manifest(array $manifest): array
{
    $version = text_field($manifest, 'version', 32);
    $build = $manifest['build_seq'] ?? null;
    $channel = text_field($manifest, 'channel', 32);
    $product = text_field($manifest, 'product', 64);
    $edition = text_field($manifest, 'edition', 32);
    if (version_tuple($version) === [0,0,0] || !is_numeric($build) || (int)$build < 1
        || !in_array($channel, ['stable','beta'], true) || $product === '' || $edition === '') fail(422, '更新清单版本身份无效');
    $targets = $manifest['targets'] ?? null;
    if (!is_array($targets) || !$targets) fail(422, '更新清单 targets 不能为空');
    $configuredKeyIds = [];
    foreach (configured_update_keys()['keys'] ?? [] as $key) {
        if (is_array($key) && is_string($key['id'] ?? null) && $key['id'] !== '') $configuredKeyIds[$key['id']] = true;
    }
    $prefix = rtrim((string)(getenv('RUSTDESK_UPDATE_DOWNLOAD_PREFIX') ?: 'https://download.yan.life/rustdesk/'), '/') . '/';
    foreach ($targets as $key => $target) {
        if (!is_string($key) || !preg_match('/^(windows|macos|linux|android)-[a-z0-9_]+-(exe|msi|dmg|appimage|apk)(?:-[a-z0-9_-]+)?$/', $key) || !is_array($target)) fail(422, '更新 target key 无效');
        $primary = $target['primary'] ?? ''; $mirrors = $target['mirrors'] ?? [];
        if (!is_string($primary) || !str_starts_with($primary, $prefix) || !filter_var($primary, FILTER_VALIDATE_URL)) fail(422, '更新下载地址无效');
        if (!is_array($mirrors) || count($mirrors) > 4) fail(422, '更新镜像列表无效');
        foreach ($mirrors as $mirror) if (!is_string($mirror) || !str_starts_with($mirror, 'https://') || !filter_var($mirror, FILTER_VALIDATE_URL)) fail(422, '更新镜像地址无效');
        if (!isset($target['size']) || !is_numeric($target['size']) || (int)$target['size'] < 1) fail(422, '更新文件大小无效');
        if (!is_string($target['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/i', $target['sha256'])) fail(422, '更新 SHA-256 无效');
        $signatureKeyId = text_field($target, 'signature_key_id', 128);
        if ($signatureKeyId === '') fail(422, '更新签名 key id 缺失');
        if (!isset($configuredKeyIds[$signatureKeyId])) fail(422, '更新签名 key id 未配置');
        $signature = base64_decode((string)($target['signature'] ?? ''), true);
        if ($signature === false || strlen($signature) !== 64) fail(422, '更新签名无效');
    }
    $manifest['build_seq'] = (int)$build;
    return $manifest;
}
function canonical_update_manifest_json(array $manifest): string
{
    $normalize = static function (mixed $value) use (&$normalize): mixed {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map($normalize, $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $normalize($item);
        return $value;
    };
    return json_encode($normalize($manifest), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
function enqueue_release_checks(PDO $db, array $manifest, int $updatedBy): int
{
    $created = 0; $now = time();
    $devices = db_all($db, 'SELECT id,uuid FROM device_reports UNION SELECT id,uuid FROM device_deployments');
    foreach ($devices as $device) {
        $id=(string)$device['id']; $uuid=(string)$device['uuid']; $client=update_command_client($db,$id,$uuid);
        $policy=update_policy($db,$id,$uuid,'stable');
        if ((string)$policy['channel'] !== (string)$manifest['channel'] || !manifest_supports_client($manifest,$client,true)) continue;
        $current=['version'=>text_field($client,'version',32,'0.0.0'),'build_seq'=>(int)($client['build_seq']??0)];
        if (compare_release($manifest,$current) <= 0) continue;
        $duplicate=db_one($db,"SELECT command_id FROM device_update_commands WHERE device_id=:id AND uuid=:uuid AND action='check' AND target_version=:version AND target_build_seq=:build AND status IN ('pending','accepted','received','checking','started','deferred') LIMIT 1",['id'=>$id,'uuid'=>$uuid,'version'=>$manifest['version'],'build'=>(int)$manifest['build_seq']]);
        if ($duplicate) continue;
        db_exec($db,"INSERT INTO device_update_commands(command_id,device_id,uuid,action,channel,target_version,target_build_seq,status,last_error,created_at,expires_at,updated_at,updated_by) VALUES(:command,:id,:uuid,'check',:channel,:version,:build,'pending',NULL,:created,:expires,:updated,:actor)",[
            'command'=>bin2hex(random_bytes(16)),'id'=>$id,'uuid'=>$uuid,'channel'=>$manifest['channel'],'version'=>$manifest['version'],'build'=>(int)$manifest['build_seq'],'created'=>$now,'expires'=>$now+86400,'updated'=>$now,'actor'=>$updatedBy,
        ]);
        $created++;
    }
    return $created;
}
function publish_update_manifest(PDO $db, array $manifest, int $updatedBy = 0): array
{
    $manifest = validate_update_manifest($manifest);
    $version=(string)$manifest['version']; $build=(int)$manifest['build_seq']; $channel=(string)$manifest['channel'];
    $encoded = canonical_update_manifest_json($manifest);
    $driver = database_driver();
    if ($driver === 'sqlite') $db->exec('BEGIN IMMEDIATE'); else $db->beginTransaction();
    $commit = static function () use ($db, $driver): void {
        if ($driver === 'sqlite') $db->exec('COMMIT'); else $db->commit();
    };
    $rollback = static function () use ($db, $driver): void {
        if ($driver === 'sqlite') $db->exec('ROLLBACK'); elseif ($db->inTransaction()) $db->rollBack();
    };
    try {
        $lock = $driver === 'mysql' ? ' FOR UPDATE' : '';
        $sameBuild = db_all($db, 'SELECT manifest FROM update_releases WHERE channel=:channel AND build_seq=:build'.$lock, ['channel'=>$channel,'build'=>$build]);
        if ($sameBuild) {
            foreach ($sameBuild as $row) {
                if (canonical_update_manifest_json(decoded_payload($row['manifest'])) !== $encoded) fail(409, '相同 build_seq 已发布不同清单');
            }
            $commit();
            return ['ok'=>true,'version'=>$version,'build_seq'=>$build,'channel'=>$channel,'idempotent'=>true,'notified_clients'=>0];
        }
        $latest = db_one($db, 'SELECT build_seq FROM update_releases WHERE channel=:channel ORDER BY build_seq DESC LIMIT 1'.$lock, ['channel'=>$channel]);
        if ($latest && (int)$latest['build_seq'] > $build) fail(409, 'build_seq 低于已发布清单');
        db_exec($db, 'INSERT INTO update_releases(version,build_seq,channel,manifest,published_at,active) VALUES(:version,:build,:channel,:manifest,:published_at,1)', ['version'=>$version,'build'=>$build,'channel'=>$channel,'manifest'=>$encoded,'published_at'=>time()]);
        $notifiedClients=enqueue_release_checks($db,$manifest,$updatedBy);
        $commit();
        return ['ok'=>true,'version'=>$version,'build_seq'=>$build,'channel'=>$channel,'notified_clients'=>$notifiedClients];
    } catch (Throwable $error) {
        try {
            $rollback();
        } catch (Throwable $rollbackError) {
            error_log('Update manifest rollback failed: '.$rollbackError->getMessage());
        }
        throw $error;
    }
}
function update_command_status_advances(string $current, string $next): bool
{
    if ($current === $next) return true;
    if (in_array($current, ['completed','failed','expired','no_update','rolled_back','rollback_failed'], true)) return false;
    $rank = ['pending'=>0,'accepted'=>1,'received'=>1,'checking'=>2,'started'=>2,'deferred'=>2,'downloaded'=>3,'installing'=>4,'installed'=>5,'completed'=>6,'failed'=>6,'expired'=>6,'no_update'=>6,'rolled_back'=>6,'rollback_failed'=>6];
    return ($rank[$next] ?? -1) >= ($rank[$current] ?? PHP_INT_MAX);
}
function record_update_event(PDO $db, array $data): void
{
    $id=text_field($data,'client_id',128,text_field($data,'id',128)); $uuid=text_field($data,'client_uuid',256,text_field($data,'uuid',256)); $status=text_field($data,'status',32);
    if($id===''||$uuid===''||!in_array($status,['accepted','received','checking','started','downloaded','installing','installed','completed','no_update','failed','deferred','expired','rolled_back','rollback_failed'],true))fail(422,'升级事件参数错误');
    [$id,$uuid]=resolve_update_identity($db,$id,$uuid);
    $commandId=text_field($data,'command_id',32);
    if($commandId!==''&&!preg_match('/^[0-9a-f]{32}$/',$commandId))fail(422,'command_id 格式错误');
    if($commandId!==''){
        $command=db_one($db,'SELECT command_id,action FROM device_update_commands WHERE command_id=:command AND device_id=:id AND uuid=:uuid',['command'=>$commandId,'id'=>$id,'uuid'=>$uuid]);
        if(!$command)fail(422,'更新命令与客户端不匹配');
        $reportedAction=text_field($data,'command_action',16);
        if($reportedAction!==''&&$reportedAction!==(string)$command['action'])fail(422,'command_action 与更新命令不匹配');
    }
    txn($db,function()use($db,$data,$id,$uuid,$status,$commandId){
        $values=['command'=>$commandId!==''?$commandId:null,'id'=>$id,'uuid'=>$uuid,'fv'=>text_field($data,'from_version',32)?:null,'tv'=>text_field($data,'to_version',32)?:null,'fb'=>array_key_exists('from_build_seq',$data)?(int)$data['from_build_seq']:null,'tb'=>array_key_exists('to_build_seq',$data)?(int)$data['to_build_seq']:null,'status'=>$status,'source'=>text_field($data,'source',64)?:null,'error'=>text_field($data,'error_code',128)?:null,'started'=>(int)($data['started_at']??time()),'finished'=>array_key_exists('finished_at',$data)?(int)$data['finished_at']:null];
        if($commandId==='')db_exec($db,'INSERT INTO device_update_events(command_id,device_id,uuid,from_version,to_version,from_build_seq,to_build_seq,status,source,error_code,started_at,finished_at) VALUES(:command,:id,:uuid,:fv,:tv,:fb,:tb,:status,:source,:error,:started,:finished)',$values);
        else{
            $verb=database_driver()==='mysql'?'INSERT IGNORE':'INSERT OR IGNORE';
            db_exec($db,"$verb INTO device_update_events(command_id,device_id,uuid,from_version,to_version,from_build_seq,to_build_seq,status,source,error_code,started_at,finished_at) VALUES(:command,:id,:uuid,:fv,:tv,:fb,:tb,:status,:source,:error,:started,:finished)",$values);
        }
        if($commandId!==''){
            $lock=database_driver()==='mysql'?' FOR UPDATE':'';
            $current=db_one($db,'SELECT status FROM device_update_commands WHERE command_id=:command'.$lock,['command'=>$commandId]);
            if($current&&update_command_status_advances((string)$current['status'],$status))db_exec($db,'UPDATE device_update_commands SET status=:status,last_error=:error,updated_at=:updated WHERE command_id=:command',['status'=>$status,'error'=>text_field($data,'error_code',128)?:null,'updated'=>time(),'command'=>$commandId]);
        }
    });
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
function assignment_users(PDO $db): array
{
    return db_all($db, 'SELECT id,username,is_admin,enabled FROM rustdesk_users WHERE delete_time=0 AND enabled=1 ORDER BY username,id');
}
function assignment_peer_payload(PDO $db, string $id, string $uuid): array
{
    $report = db_one($db, 'SELECT payload FROM device_reports WHERE id=:id AND uuid=:uuid', ['id'=>$id, 'uuid'=>$uuid]);
    $deployment = db_one($db, 'SELECT payload FROM device_deployments WHERE id=:id AND uuid=:uuid', ['id'=>$id, 'uuid'=>$uuid]);
    if (!$report && !$deployment) fail(404, '设备不存在');
    $info = decoded_payload($report['payload'] ?? null);
    $deploy = decoded_payload($deployment['payload'] ?? null);
    return [
        'id'=>$id,
        'username'=>(string)($info['username'] ?? $deploy['device_username'] ?? ''),
        'hostname'=>(string)($info['hostname'] ?? $deploy['device_name'] ?? $deploy['hostname'] ?? ''),
        'platform'=>(string)($info['platform'] ?? $info['os'] ?? $deploy['platform'] ?? $deploy['os'] ?? ''),
        'alias'=>'', 'tags'=>[], 'hash'=>(string)($info['hash'] ?? $deploy['hash'] ?? ''),
    ];
}
function normalize_assignment_users(PDO $db, mixed $value, bool $allowEmpty = false): array
{
    if (!is_array($value) || !array_is_list($value) || (!$allowEmpty && count($value) < 1) || count($value) > 100) fail(422, '通讯录用户列表格式错误');
    $ids = [];
    foreach ($value as $raw) {
        if ((is_string($raw) && !ctype_digit($raw)) || (!is_int($raw) && !is_string($raw))) fail(422, '用户 ID 格式错误');
        $id = (int)$raw; if ($id < 1) fail(422, '用户 ID 格式错误'); $ids[$id] = true;
    }
    $users = [];
    foreach (assignment_users($db) as $user) if (isset($ids[(int)$user['id']])) $users[(int)$user['id']] = $user;
    if (count($users) !== count($ids)) fail(404, '指定用户不存在或已禁用');
    return array_values($users);
}
function address_book_user(PDO $db, int $uid): array
{
    $user = db_one($db, 'SELECT id,username,is_admin,enabled FROM rustdesk_users WHERE id=:id AND delete_time=0 AND enabled=1', ['id'=>$uid]);
    if (!$user) fail(404, '通讯录用户不存在');
    return $user;
}
function assert_address_book_scope(array $actor, int $uid): void
{
    $scope = (string)($actor['address_book_scope'] ?? ((bool)($actor['is_admin'] ?? false) ? 'all' : 'self'));
    if ((int)$actor['id'] !== $uid && $scope !== 'all') fail(403, '无权访问该用户通讯录');
}
function address_book_target(PDO $db, array $actor, mixed $rawUid = null): array
{
    if ($rawUid === null || $rawUid === '') $uid = (int)$actor['id'];
    elseif ((is_int($rawUid) && $rawUid > 0) || (is_string($rawUid) && ctype_digit($rawUid) && (int)$rawUid > 0)) $uid = (int)$rawUid;
    else fail(422, 'user_id 格式错误');
    assert_address_book_scope($actor, $uid);
    return address_book_user($db, $uid);
}
function address_book_peer_ids(mixed $value): array
{
    if (!is_array($value) || !array_is_list($value) || count($value) < 1 || count($value) > 200) fail(422, '至少选择一个通讯录客户端');
    $ids = [];
    foreach ($value as $raw) {
        if (!is_string($raw)) fail(422, '客户端 ID 列表格式错误');
        $id = address_book_peer_id($raw); $ids[$id] = true;
    }
    return array_keys($ids);
}
function address_book_user_counts_batch(PDO $db, array $users): array
{
    if (!$users) return [];
    $params = []; $holders = []; $peersByUser = [];
    foreach ($users as $index=>$user) {
        $uid = (int)$user['id']; $key = 'uid_' . $index;
        $params[$key] = $uid; $holders[] = ':' . $key; $peersByUser[$uid] = [];
    }
    $in = implode(',', $holders);
    foreach (db_all($db, 'SELECT uid,id FROM rustdesk_peers WHERE uid IN (' . $in . ')', $params) as $row) {
        $peersByUser[(int)$row['uid']][(string)$row['id']] = true;
    }
    foreach (db_all($db, 'SELECT a.uid,p.id FROM ab_profile_peers p JOIN ab_profiles a ON a.guid=p.guid WHERE a.personal=1 AND a.uid IN (' . $in . ')', $params) as $row) {
        $peersByUser[(int)$row['uid']][(string)$row['id']] = true;
    }
    foreach (db_all($db, 'SELECT uid,payload FROM address_books WHERE uid IN (' . $in . ')', $params) as $row) {
        $book = decoded_payload($row['payload']); $uid = (int)$row['uid'];
        foreach (($book['peers'] ?? []) as $peer) if (is_array($peer) && is_string($peer['id'] ?? null) && $peer['id'] !== '') $peersByUser[$uid][$peer['id']] = true;
    }
    $peerIds = [];
    foreach ($peersByUser as $peers) foreach (array_keys($peers) as $id) $peerIds[$id] = true;
    $lastHeartbeats = [];
    foreach (array_chunk(array_keys($peerIds), 500) as $chunkIndex=>$chunk) {
        $params = []; $holders = [];
        foreach ($chunk as $index=>$id) { $key = 'peer_' . $chunkIndex . '_' . $index; $holders[] = ':' . $key; $params[$key] = $id; }
        foreach (db_all($db, 'SELECT id,MAX(last_heartbeat) AS last_heartbeat FROM device_reports WHERE id IN (' . implode(',', $holders) . ') GROUP BY id', $params) as $row) {
            $lastHeartbeats[(string)$row['id']] = (int)$row['last_heartbeat'];
        }
    }
    $counts = []; $now = time();
    foreach ($peersByUser as $uid=>$peers) {
        $online = 0;
        foreach (array_keys($peers) as $id) if (device_presence($lastHeartbeats[$id] ?? 0, $now) === 'online') $online++;
        $counts[$uid] = ['address_book_count'=>count($peers), 'address_book_online_count'=>$online];
    }
    return $counts;
}

function ensure_address_book_tag_catalogs(PDO $db, array $actor, array $profile, array $tags): void
{
    $book = exact_address_book($db, (int)$actor['id']);
    foreach ($tags as $tag) {
        db_insert_ignore($db, 'ab_profile_tags', ['guid'=>$profile['guid'],'name'=>$tag,'color'=>0]);
        db_insert_ignore($db, 'rustdesk_tags', ['uid'=>(int)$actor['id'],'tag'=>$tag]);
        if (!in_array($tag, $book['tags'], true)) $book['tags'][] = $tag;
    }
    save_exact_address_book($db, (int)$actor['id'], $book, time());
}
function address_book_export(PDO $db, int $uid): array
{
    $user = address_book_user($db, $uid);
    $profile = db_one($db, 'SELECT * FROM ab_profiles WHERE uid=:uid AND personal=1', ['uid'=>$uid]);
    $state = admin_address_book_state($db, $user, $profile ?? ['guid'=>'']);
    return ['user_id'=>$uid, 'username'=>$user['username'], 'tags'=>$state['tags'], 'peers'=>array_values($state['peers'])];
}
function address_book_import(PDO $db, int $uid, array $book): int
{
    address_book_user($db, $uid);
    $profile = personal_profile($db, ['id'=>$uid,'username'=>'import']);
    $count = 0;
    $tags = address_book_tags($book['tags'] ?? []);
    db_exec($db, 'DELETE FROM ab_profile_tags WHERE guid=:guid', ['guid'=>$profile['guid']]);
    foreach ($tags as $tag) db_upsert($db, 'ab_profile_tags', ['guid'=>$profile['guid'], 'name'=>$tag, 'color'=>0], ['guid','name'], ['color']);
    foreach ($tags as $tag) db_insert_ignore($db, 'rustdesk_tags', ['uid'=>$uid, 'tag'=>$tag]);
    $existingBook = exact_address_book($db, $uid);
    $existingBook['tags'] = $tags;
    foreach (($book['peers'] ?? []) as $peer) {
        if (!is_array($peer) || !isset($peer['id'])) continue;
        $id = address_book_peer_id($peer['id']); $peer['id'] = $id;
        $payload = validate_admin_peer_payload($peer);
        sync_admin_book_peer($db, ['id'=>$uid], $profile, $id, $payload); $count++;
        foreach ($existingBook['peers'] as $index=>$existing) if (is_array($existing) && (string)($existing['id'] ?? '') === $id) { $existingBook['peers'][$index] = array_replace($existing, $payload); continue 2; }
        $existingBook['peers'][] = $payload;
    }
    save_exact_address_book($db, $uid, $existingBook, time());
    return $count;
}
function parse_address_book_input(string $raw, string $format): array
{
    $format = strtolower($format);
    if ($format === 'json') {
        try { $book = json_decode($raw, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
        catch (JsonException) { fail(422, '导入内容必须为有效 JSON'); }
        if (!is_array($book)) fail(422, '导入内容必须为 JSON 对象');
        return $book;
    }
    if ($format !== 'csv') fail(422, '导入格式必须为 JSON 或 CSV');
    $stream = fopen('php://temp', 'r+'); fwrite($stream, $raw); rewind($stream); $header = fgetcsv($stream);
    if (!$header) fail(422, 'CSV 内容为空');
    $book = ['tags'=>[], 'peers'=>[]];
    while (($row = fgetcsv($stream)) !== false) {
        if (count($row) < 1 || trim((string)$row[0]) === '') continue;
        $item = []; foreach ($header as $i=>$key) $item[(string)$key] = $row[$i] ?? '';
        $item['tags'] = isset($item['tags']) && trim((string)$item['tags']) !== '' ? array_values(array_filter(array_map('trim', explode(',', (string)$item['tags'])))) : [];
        $book['peers'][] = $item; foreach ($item['tags'] as $tag) if (!in_array($tag, $book['tags'], true)) $book['tags'][] = $tag;
    }
    fclose($stream); return $book;
}
function address_book_import_plan(PDO $db, int $uid, array $book): array
{
    address_book_user($db, $uid); $current = address_book_export($db, $uid); $existing = [];
    foreach ($current['peers'] as $peer) if (is_array($peer) && isset($peer['id'])) $existing[(string)$peer['id']] = $peer;
    $plan = ['add'=>[], 'update'=>[], 'already_present'=>[], 'invalid'=>[], 'tags'=>address_book_tags($book['tags'] ?? [])];
    foreach (($book['peers'] ?? []) as $peer) {
        if (!is_array($peer) || !isset($peer['id'])) { $plan['invalid'][] = ['reason'=>'缺少设备 ID']; continue; }
        try { $id = address_book_peer_id((string)$peer['id']); $payload = validate_admin_peer_payload($peer); $payload['id'] = $id; }
        catch (Throwable $e) { $plan['invalid'][] = ['id'=>(string)($peer['id'] ?? ''), 'reason'=>$e->getMessage()]; continue; }
        if (isset($existing[$id])) $plan['update'][] = $payload; else $plan['add'][] = $payload;
    }
    $plan['already_present'] = $plan['update'];
    $canonical = json_encode(['tags'=>$plan['tags'],'peers'=>array_values(array_merge($plan['add'],$plan['update']))], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $plan['snapshot'] = hash('sha256', json_encode($current, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $plan['input_snapshot'] = hash('sha256', $canonical);
    return $plan;
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
        $id = (string)$id;
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
function enrich_admin_address_book_peer(PDO $db, array $peer): array
{
    $id = (string)($peer['id'] ?? '');
    $reportRow = db_one($db, 'SELECT uuid,payload,runtime_payload,network_payload,last_seen,last_heartbeat FROM device_reports WHERE id=:id ORDER BY last_heartbeat DESC,last_seen DESC LIMIT 1', ['id'=>$id]);
    $deploymentRow = db_one($db, 'SELECT uuid,payload,uid,updated_at FROM device_deployments WHERE id=:id ORDER BY updated_at DESC LIMIT 1', ['id'=>$id]);
    $report = decoded_payload($reportRow['payload'] ?? null); $runtime = decoded_payload($reportRow['runtime_payload'] ?? null); $network = decoded_payload($reportRow['network_payload'] ?? null); $deploy = decoded_payload($deploymentRow['payload'] ?? null);
    $release = canonical_release_identity($db, release_identity($report, $runtime)); $publicIp = is_public_ip((string)($network['public_ip'] ?? '')) ? (string)$network['public_ip'] : '';
    $geo = $publicIp !== '' ? (is_array($network['geo'] ?? null) && $network['geo'] !== [] ? $network['geo'] : public_ip_geo($publicIp)) : [];
    $lastHeartbeat = (int)($reportRow['last_heartbeat'] ?? 0);
    return array_merge($peer, [
        'uuid'=>(string)($reportRow['uuid'] ?? $deploymentRow['uuid'] ?? ''),
        'hostname'=>(string)($report['hostname'] ?? $deploy['device_name'] ?? $peer['hostname'] ?? ''),
        'username'=>(string)($report['username'] ?? $deploy['device_username'] ?? $peer['username'] ?? ''),
        'platform'=>$release['platform'] !== '' ? $release['platform'] : (string)($report['platform'] ?? $report['os'] ?? $peer['platform'] ?? ''),
        'os'=>(string)($report['os'] ?? ''), 'os_version'=>(string)($release['os_version'] ?? $report['os_version'] ?? ''),
        'version'=>$release['version'] !== '' ? $release['version'] : (string)($report['version'] ?? ''), 'version_text'=>$release['version'],
        'distribution'=>$release['distribution'] !== '' ? $release['distribution'] : (string)($deploy['distribution'] ?? ''),
        'install_mode'=>$release['install_mode'] !== '' ? $release['install_mode'] : (string)($deploy['install_mode'] ?? ''),
        'build_number'=>(string)($release['build_number'] ?? ''), 'build_seq'=>(int)($release['build_seq'] ?? 0), 'channel'=>(string)($release['channel'] ?? ''), 'arch'=>(string)($release['arch'] ?? ''),
        'public_ip'=>$publicIp, 'private_ips'=>is_array($network['private_ips'] ?? null) ? $network['private_ips'] : [], 'geo'=>$geo,
        'enable_check_update'=>array_key_exists('enable_check_update', $runtime) ? (bool)$runtime['enable_check_update'] : null,
        'allow_auto_update'=>array_key_exists('allow_auto_update', $runtime) ? (bool)$runtime['allow_auto_update'] : null,
        'enable_scheduled_update'=>array_key_exists('enable_scheduled_update', $runtime) ? (bool)$runtime['enable_scheduled_update'] : null,
        'scheduled_update_interval_hours'=>isset($runtime['scheduled_update_interval_hours']) ? (int)$runtime['scheduled_update_interval_hours'] : null,
        'last_seen'=>(int)($reportRow['last_seen'] ?? 0), 'last_heartbeat'=>$lastHeartbeat, 'presence'=>device_presence($lastHeartbeat, time()),
        'deployed'=>$deploymentRow !== null, 'address_book_user_ids'=>[],
    ]);
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
    if (in_array($path, ['/favicon.ico','/.well-known/appspecific/com.chrome.devtools.json'], true)) { method('GET'); http_response_code(204); exit; }
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
    if ($path === '/rd/update/v1/keys.json') {
        method('GET'); reply(configured_update_keys());
    }
    if (preg_match('#^/rd/update/v1/manifest/([A-Za-z0-9._~-]+)\.json$#', $path, $match)) {
        method('GET'); $manifest=public_update_manifest($db,$match[1]); if(!$manifest)fail(404,'更新清单不存在'); header('Cache-Control: public, max-age=60'); reply($manifest);
    }
    if ($path === '/_rustdesk-stream-internal/auth') { method('GET'); update_policy_stream_auth($db); }
    if ($path === '/_rustdesk-stream-internal/snapshot') { method('POST'); update_policy_stream_snapshot($db); }
    if ($path === '/rd/update/v1/policy/stream') { method('GET'); update_policy_stream($db); }
    if ($path === '/rd/update/v1/check') {
        method('POST'); $data=json_body(); $id=text_field($data,'client_id',128,text_field($data,'id',128)); $uuid=text_field($data,'client_uuid',256,text_field($data,'uuid',256));
        if($id===''||$uuid==='')fail(422,'缺少客户端 ID 或 UUID'); [$id,$uuid]=resolve_update_identity($db,$id,$uuid); $commandId=trim((string)($_SERVER['HTTP_X_RUSTDESK_UPDATE_COMMAND_ID']??''));
	        if($commandId!=='')update_device_auth($db,'POST','/rd/update/v1/check',$id,$uuid,$commandId); $command=update_command_request($db,$id,$uuid,$commandId); reply(update_check_response($db,$data,$command,$commandId!==''));
    }
    if ($path === '/rd/update/v1/publish') { method('POST'); publish_token_auth(); reply(publish_update_manifest($db,json_body()),201); }
    if ($path === '/rd/update/v1/events') {
        method('POST'); $data=json_body(); $id=text_field($data,'client_id',128,text_field($data,'id',128)); $uuid=text_field($data,'client_uuid',256,text_field($data,'uuid',256));
        if($id===''||$uuid==='')fail(422,'缺少客户端 ID 或 UUID'); [$id,$uuid]=resolve_update_identity($db,$id,$uuid); $commandId=text_field($data,'command_id',32);
        if($commandId!=='')update_device_auth($db,'POST','/rd/update/v1/events',$id,$uuid,$commandId); record_update_event($db,$data); reply(['ok'=>true],201);
    }
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
    if ($adminApi && $path === '/admin/api/update/releases') {
        $actor=admin_user($db);
        if($_SERVER['REQUEST_METHOD']==='GET'){ $rows=db_all($db,'SELECT version,build_seq,channel,published_at,active FROM update_releases ORDER BY published_at DESC'); reply(['data'=>array_map(static fn($row)=>['version'=>$row['version'],'build_seq'=>(int)$row['build_seq'],'channel'=>$row['channel'],'published_at'=>(int)$row['published_at'],'active'=>(bool)$row['active']],$rows)]); }
        method('POST'); csrf_check(); $d=json_body(); if(!isset($d['manifest'])||!is_array($d['manifest']))fail(422,'manifest 必须是 JSON 对象'); $manifest=$d['manifest']; $manifest['version']=text_field($d,'version',32); $manifest['build_seq']=$d['build_seq']??0; $manifest['channel']=text_field($d,'channel',32,'stable');
        $published=publish_update_manifest($db,$manifest,(int)$actor['id']); admin_event($db,(int)$actor['id'],'publish_update',0); reply($published,201);
    }
    if ($adminApi && $path === '/admin/api/update/commands/batch') {
        $actor=admin_user($db); method('POST'); csrf_check(); $d=json_body(); $action=text_field($d,'action',16); if(!in_array($action,['check','install'],true))fail(422,'action 必须是 check 或 install'); $devices=update_batch_devices($db,$d);
        $created=[]; $errors=[];
        foreach($devices as $device){
            try{$created[]=create_update_command($db,$actor,(string)$device['id'],['uuid'=>$device['uuid'],'action'=>$action,'expires_in'=>$d['expires_in']??3600]);}
            catch(RequestError $error){$errors[]=['id'=>$device['id'],'uuid'=>$device['uuid'],'error'=>$error->getMessage()];}
        }
        reply(['ok'=>count($errors)===0,'created'=>count($created),'failed'=>count($errors),'commands'=>$created,'errors'=>$errors],201);
    }
    if ($adminApi && preg_match('#^/admin/api/update/commands/([^/]+)$#',$path,$match)) {
        $actor=admin_user($db); $id=rawurldecode($match[1]);
        if($_SERVER['REQUEST_METHOD']==='GET'){
            $uuid=text_field($_GET,'uuid',256); if($uuid==='')fail(422,'设备 UUID 不能为空');
            [$id,$uuid]=device_update_identity($db,$id,$uuid); expire_update_commands($db,$id,$uuid);
            $rows=db_all($db,'SELECT * FROM device_update_commands WHERE device_id=:id AND uuid=:uuid ORDER BY created_at DESC,command_id DESC LIMIT 50',['id'=>$id,'uuid'=>$uuid]);
            reply(['data'=>array_map('public_update_command',$rows)]);
        }
        method('POST'); csrf_check(); reply(create_update_command($db,$actor,$id,json_body()),201);
    }
    if ($adminApi && $path === '/admin/api/update/policies/batch') {
        $actor=admin_user($db); method('PATCH'); csrf_check(); $d=json_body();
        update_policy_boolean($d,'enable_scheduled_update',false); scheduled_update_interval($d,5);
        $devices=update_batch_devices($db,$d); $saved=[];
        txn($db,function()use($db,$actor,$devices,$d,&$saved){
            foreach($devices as $device)$saved[]=patch_scheduled_update_policy($db,$actor,(string)$device['id'],(string)$device['uuid'],$d);
            admin_event($db,(int)$actor['id'],'update_scheduled_policy_batch',0);
        });
        reply(['ok'=>true,'updated'=>count($saved),'policies'=>$saved]);
    }
    if ($adminApi && preg_match('#^/admin/api/update/policies(?:/([^/]+))?$#',$path,$match)) {
        $actor=admin_user($db); $id=isset($match[1])?rawurldecode($match[1]):'';
        if($id===''&&$_SERVER['REQUEST_METHOD']==='GET'){ reply(['data'=>db_all($db,'SELECT * FROM device_update_policies ORDER BY updated_at DESC')]); }
        if($id!==''&&$_SERVER['REQUEST_METHOD']==='GET'){
            $uuid=text_field($_GET,'uuid',256); if($uuid==='')fail(422,'设备 UUID 不能为空');
            $channel=text_field($_GET,'channel',32,'stable'); if(!in_array($channel,['stable','beta'],true))fail(422,'更新通道无效');
            reply(update_policy($db,$id,$uuid,$channel));
        }
        method('PATCH'); csrf_check(); $d=json_body(); $uuid=text_field($d,'uuid',256); if($id===''||$uuid==='')fail(422,'设备 ID 和 UUID 不能为空'); $requestedMode=text_field($d,'mode',32,'notify'); $immediate=$requestedMode==='immediate'; $mode=update_mode($immediate?'auto_install':$requestedMode); $channel=text_field($d,'channel',32,'stable'); $existing=db_one($db,'SELECT * FROM device_update_policies WHERE id=:id AND uuid=:uuid',['id'=>$id,'uuid'=>$uuid]); $revision=((int)($existing['policy_revision']??0))+1;
        if(!in_array($channel,['stable','beta'],true))fail(422,'更新通道无效');
        if(array_key_exists('target_build_seq',$d)&&$d['target_build_seq']!==null){ $build=$d['target_build_seq']; $valid=(is_int($build)&&$build>=0)||(is_string($build)&&ctype_digit($build)); if(!$valid)fail(422,'build_seq 必须是非负整数'); }
        if(array_key_exists('auto_install',$d)&&!is_bool($d['auto_install'])&&!in_array($d['auto_install'],[0,1,'0','1'],true))fail(422,'auto_install 必须是布尔值');
        foreach(['enable_check_update','allow_auto_update','enable_scheduled_update'] as $field)if(array_key_exists($field,$d)&&!is_bool($d[$field])&&!in_array($d[$field],[0,1,'0','1'],true))fail(422,"$field 必须是布尔值");
        $enableCheckUpdate=(int)(array_key_exists('enable_check_update',$d)?$d['enable_check_update']:($existing['enable_check_update']??false));
        $allowAutoUpdate=(int)(array_key_exists('allow_auto_update',$d)?$d['allow_auto_update']:($existing['allow_auto_update']??false));
        $enableScheduledUpdate=(int)update_policy_boolean($d,'enable_scheduled_update',(bool)($existing['enable_scheduled_update']??false));
        $scheduledInterval=scheduled_update_interval($d,(int)($existing['scheduled_update_interval_hours']??5));
        $saved=[];
        txn($db,function()use($db,$id,$uuid,$mode,$channel,$d,$revision,$actor,$enableCheckUpdate,$allowAutoUpdate,$enableScheduledUpdate,$scheduledInterval,&$saved){
            db_upsert($db,'device_update_policies',['id'=>$id,'uuid'=>$uuid,'mode'=>$mode,'channel'=>$channel,'target_version'=>array_key_exists('target_version',$d)?text_field($d,'target_version',32):null,'target_build_seq'=>array_key_exists('target_build_seq',$d)&&$d['target_build_seq']!==null?(int)$d['target_build_seq']:null,'auto_install'=>(int)((bool)($d['auto_install']??false)||$mode==='auto_install'),'enable_check_update'=>$enableCheckUpdate,'allow_auto_update'=>$allowAutoUpdate,'enable_scheduled_update'=>$enableScheduledUpdate,'scheduled_update_interval_hours'=>$scheduledInterval,'policy_revision'=>$revision,'updated_by'=>(int)$actor['id'],'updated_at'=>time()],['id','uuid'],['mode','channel','target_version','target_build_seq','auto_install','enable_check_update','allow_auto_update','enable_scheduled_update','scheduled_update_interval_hours','policy_revision','updated_by','updated_at']);
            $saved=update_policy($db,$id,$uuid,$channel); admin_event($db,(int)$actor['id'],'update_device_policy',0);
        });
        $command=null;
        if($immediate){
            $command=create_update_command($db,$actor,$id,['uuid'=>$uuid,'action'=>'install','target_version'=>array_key_exists('target_version',$d)?$d['target_version']??null:null,'target_build_seq'=>array_key_exists('target_build_seq',$d)?$d['target_build_seq']??null:null]);
        }
        reply(['ok'=>true]+$saved+($command!==null?['immediate_command'=>$command,'message'=>'更新策略已保存，已下发立即更新命令']:[]));
    }
    if ($adminApi && preg_match('#^/admin/api/update/events(?:/([^/]+))?$#',$path,$match)) { admin_user($db); $id=isset($match[1])?rawurldecode($match[1]):''; $where=$id?' WHERE device_id=:id':''; reply(['data'=>db_all($db,'SELECT * FROM device_update_events'.$where.' ORDER BY started_at DESC LIMIT 200',$id?['id'=>$id]:[])]); }
    if ($adminApi && preg_match('#^/admin/api/users(?:/([1-9][0-9]*))?$#', $path, $match)) {
        $actor = admin_user($db); $id = isset($match[1]) ? (int)$match[1] : 0;
        if (!$id && $_SERVER['REQUEST_METHOD'] === 'GET') {
            [$limit, $offset] = pagination(); $search = $_GET['q'] ?? '';
            if (!is_string($search) || strlen($search) > 128) fail(422, '搜索内容过长');
            $args = ['q' => $search]; $where = 'delete_time=0 AND instr(username,:q)>0';
            if ((string)($actor['address_book_scope'] ?? 'self') !== 'all') { $where .= ' AND id=:actor_id'; $args['actor_id'] = (int)$actor['id']; }
            $total = db_one($db, 'SELECT COUNT(*) AS n FROM rustdesk_users WHERE ' . $where, $args)['n'];
            $list = db_all($db, 'SELECT * FROM rustdesk_users WHERE ' . $where . ' ORDER BY id LIMIT :limit OFFSET :offset', $args + ['limit' => $limit, 'offset' => $offset]);
            $counts = address_book_user_counts_batch($db, $list); $data = [];
            foreach ($list as $row) $data[] = public_user($row) + ($counts[(int)$row['id']] ?? ['address_book_count'=>0,'address_book_online_count'=>0]);
            reply(['data' => $data, 'total' => (int)$total]);
        }
        method(...($id ? ['PATCH', 'DELETE'] : ['POST'])); csrf_check();
        if ((string)($actor['address_book_scope'] ?? 'self') !== 'all') fail(403, '用户管理需要跨用户通讯录权限');
        $d = $_SERVER['REQUEST_METHOD'] === 'DELETE' ? [] : json_body();
        if (!$id) {
            $name = username($d); $hash = new_password($d);
            if (isset($d['enabled']) && !is_bool($d['enabled'])) fail(422, 'enabled 必须为布尔值');
            $newId = txn($db, function () use ($db, $name, $hash, $d, $actor) {
                if (db_one($db, 'SELECT id FROM rustdesk_users WHERE username=:name', ['name' => $name])) fail(409, '用户名已存在，包括已删除账号');
                $scope = $d['address_book_scope'] ?? 'self'; if (!is_string($scope) || !in_array($scope, ['self','all'], true)) fail(422, '通讯录权限格式错误');
                db_exec($db, 'INSERT INTO rustdesk_users(username,password,create_time,delete_time,is_admin,enabled,address_book_scope,auth_version) VALUES(:name,:password,:at,0,0,:enabled,:scope,0)', ['name' => $name, 'password' => $hash, 'at' => time(), 'enabled' => (int)($d['enabled'] ?? true), 'scope'=>$scope]);
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
                $scope = $d['address_book_scope'] ?? ($target['address_book_scope'] ?? 'self'); if (!is_string($scope) || !in_array($scope, ['self','all'], true)) fail(422, '通讯录权限格式错误');
                if (!$isAdmin) $scope = 'self';
                db_exec($db, 'UPDATE rustdesk_users SET username=:name,password=:password,enabled=:enabled,is_admin=:admin,address_book_scope=:scope WHERE id=:id', ['name' => $name, 'password' => $hash, 'enabled' => (int)$enabled, 'admin' => (int)$isAdmin, 'scope'=>$scope, 'id' => $id]);
            }
            revoke($db, $id); admin_event($db, (int)$actor['id'], $delete ? 'delete_user' : 'update_user', $id);
        }); reply(['ok' => true, 'reauthenticate' => $id === (int)$actor['id']]);
    }
    if ($adminApi && $path === '/admin/api/address-book') {
        method('GET'); $actor = admin_user($db); [$limit,$offset] = pagination();
        $target = address_book_target($db, $actor, $_GET['user_id'] ?? null);
        $q = $_GET['q'] ?? ''; $tag = $_GET['tag'] ?? '';
        if (!is_string($q) || strlen($q) > 128) fail(422, '搜索内容过长');
        if (!is_string($tag) || strlen($tag) > 256) fail(422, '标签筛选错误');
        $favoriteFilter = $_GET['favorite'] ?? '';
        if (!is_string($favoriteFilter) || !in_array($favoriteFilter, ['', '0', '1', 'false', 'true'], true)) fail(422, '收藏筛选错误');
        $profile = db_one($db, 'SELECT * FROM ab_profiles WHERE uid=:uid AND personal=1', ['uid'=>$target['id']]);
        $state = admin_address_book_state($db, $target, $profile ?? ['guid'=>'']);
        foreach ($state['peers'] as $peerId=>&$peer) {
            $peer = enrich_admin_address_book_peer($db, $peer);
        }
        unset($peer);
        $presenceFilter = $_GET['presence'] ?? 'all';
        if (!is_string($presenceFilter) || !in_array($presenceFilter, ['all','online','recent','offline','unreported'], true)) fail(422, '在线状态筛选错误');
        $all = array_values($state['peers']);
        usort($all, fn($a,$b)=>(int)$b['favorite']<=>(int)$a['favorite'] ?: strnatcasecmp((string)($a['alias'] ?? $a['hostname'] ?? $a['id']), (string)($b['alias'] ?? $b['hostname'] ?? $b['id'])) ?: strcmp((string)$a['id'], (string)$b['id']));
        $summary = ['total'=>count($all),'favorites'=>0,'labelled'=>0,'tags'=>count($state['tags'])];
        foreach ($all as $peer) {
            if ($peer['favorite']) $summary['favorites']++;
            if (is_string($peer['alias'] ?? null) && $peer['alias'] !== '') $summary['labelled']++;
        }
        $filtered = array_values(array_filter($all, function($peer) use ($q,$tag,$favoriteFilter,$presenceFilter) {
            if ($favoriteFilter === '1' || $favoriteFilter === 'true') if (!$peer['favorite']) return false;
            if ($favoriteFilter === '0' || $favoriteFilter === 'false') if ($peer['favorite']) return false;
            if ($tag !== '' && !in_array($tag, $peer['tags'], true)) return false;
            if ($presenceFilter !== 'all' && ($peer['presence'] ?? 'unreported') !== $presenceFilter) return false;
            if ($q !== '') {
                $haystack = json_encode($peer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                if (stripos($haystack, $q) === false) return false;
            }
            return true;
        }));
        reply([
            'user'=>['id'=>(int)$target['id'],'username'=>$target['username']],
            'profile'=>$profile ? ['guid'=>$profile['guid'],'name'=>$profile['name'],'owner'=>$profile['owner'],'note'=>$profile['note'],'rule'=>(int)$profile['rule']] : null,
            'summary'=>$summary, 'tags'=>$state['tags'], 'total'=>count($filtered), 'data'=>array_slice($filtered,$offset,$limit),
        ]);
    }
    if ($adminApi && $path === '/admin/api/address-book/peers') {
        method('POST'); $actor = admin_user($db); csrf_check(); $d = json_body();
        $target = address_book_target($db, $actor, $_GET['user_id'] ?? null);
        if (!array_key_exists('id', $d) || !is_string($d['id'])) fail(422, '设备 ID 不能为空');
        $id = address_book_peer_id($d['id']);
        $favorite = $d['favorite'] ?? false; if (!is_bool($favorite)) fail(422, 'favorite 必须为布尔值');
        txn($db, function() use ($db,$target,$d,$id,$favorite) {
            $profile = personal_profile($db, $target); $state = admin_address_book_state($db, $target, $profile);
            if (isset($state['peers'][$id])) fail(409, '联系人已存在');
            $payload = validate_admin_peer_payload($d); $payload['id'] = $id;
            sync_admin_book_peer($db, $target, $profile, $id, $payload);
            if ($favorite) db_upsert($db, 'admin_peer_favorites', ['uid'=>(int)$target['id'],'id'=>$id,'created_at'=>time()], ['uid','id'], ['created_at']);
        });
        reply(['ok'=>true,'id'=>$id,'sync'=>'next_address_book_pull'], 201);
    }
    if ($adminApi && preg_match('#^/admin/api/address-book/peers/([^/]+)$#', $path, $match)) {
        $actor = admin_user($db); csrf_check(); $id = address_book_peer_id($match[1]);
        $target = address_book_target($db, $actor, $_GET['user_id'] ?? null);
        if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            txn($db, function() use ($db,$target,$id) {
                $profile = personal_profile($db, $target);
                if (!delete_admin_book_peer($db, $target, $profile, $id)) fail(404, '联系人不存在');
            });
            reply(['ok'=>true,'id'=>$id,'sync'=>'next_address_book_pull']);
        }
        method('PATCH'); $d = json_body();
        if (array_key_exists('id', $d) && $d['id'] !== $id) fail(422, '不能修改设备 ID');
        txn($db, function() use ($db,$target,$id,$d) {
            $profile = personal_profile($db, $target); $state = admin_address_book_state($db, $target, $profile);
            if (!isset($state['peers'][$id])) fail(404, '联系人不存在');
            $existing = $state['peers'][$id]; unset($existing['favorite']);
            $payload = validate_admin_peer_payload($d, $existing); $payload['id'] = $id;
            sync_admin_book_peer($db, $target, $profile, $id, $payload);
        });
        reply(['ok'=>true,'id'=>$id,'sync'=>'next_address_book_pull']);
    }
    if ($adminApi && preg_match('#^/admin/api/address-book/favorites/([^/]+)$#', $path, $match)) {
        method('PATCH'); $actor = admin_user($db); csrf_check(); $id = address_book_peer_id($match[1]); $d = json_body();
        $target = address_book_target($db, $actor, $_GET['user_id'] ?? null);
        if (!array_key_exists('favorite', $d) || !is_bool($d['favorite'])) fail(422, 'favorite 必须为布尔值');
        txn($db, function() use ($db,$target,$id,$d) {
            $profile = db_one($db, 'SELECT * FROM ab_profiles WHERE uid=:uid AND personal=1', ['uid'=>$target['id']]);
            $state = admin_address_book_state($db, $target, $profile ?? ['guid'=>'']);
            if (!isset($state['peers'][$id])) fail(404, '联系人不存在');
            if ($d['favorite']) db_upsert($db, 'admin_peer_favorites', ['uid'=>(int)$target['id'],'id'=>$id,'created_at'=>time()], ['uid','id'], ['created_at']);
            else db_exec($db, 'DELETE FROM admin_peer_favorites WHERE uid=:uid AND id=:id', ['uid'=>$target['id'],'id'=>$id]);
        });
        reply(['ok'=>true,'id'=>$id,'favorite'=>$d['favorite'],'sync'=>'admin_only']);
    }
    if ($adminApi && $path === '/admin/api/address-book/assignment-options') {
        method('GET'); $actor = admin_user($db);
        $available = (string)($actor['address_book_scope'] ?? 'self') === 'all' ? assignment_users($db) : [$actor];
        $availableIds = array_fill_keys(array_map(static fn($user)=>(int)$user['id'], $available), true);
        $users = array_map(static fn($user) => ['id'=>(int)$user['id'],'username'=>(string)$user['username'],'is_admin'=>(bool)$user['is_admin']], $available);
        $assignments = [];
        foreach (db_all($db, 'SELECT p.id,a.uid FROM ab_profile_peers p JOIN ab_profiles a ON a.guid=p.guid WHERE a.personal=1 AND a.uid IN (SELECT id FROM rustdesk_users WHERE delete_time=0 AND enabled=1)') as $row) {
            if (isset($availableIds[(int)$row['uid']])) $assignments[(string)$row['id']][] = (int)$row['uid'];
        }
        reply(['users'=>$users,'assignments'=>$assignments]);
    }
    if ($adminApi && preg_match('#^/admin/api/address-book/users/([0-9]+)$#', $path, $match)) {
        method('GET'); $actor = admin_user($db); assert_address_book_scope($actor, (int)$match[1]); $user = address_book_user($db, (int)$match[1]);
        $book = address_book_export($db, (int)$match[1]);
        reply(['user'=>['id'=>(int)$user['id'],'username'=>$user['username']], 'tags'=>$book['tags'], 'total'=>count($book['peers']), 'data'=>array_values($book['peers'])]);
    }
    if ($adminApi && $path === '/admin/api/address-book/export') {
        method('GET'); $actor = admin_user($db); $uid = (int)($_GET['user_id'] ?? 0); if ($uid < 1) fail(422, 'user_id 格式错误'); assert_address_book_scope($actor, $uid);
        $book = address_book_export($db, $uid); $format = $_GET['format'] ?? 'json';
        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="address-book.csv"');
            $out = fopen('php://output', 'wb'); fputcsv($out, ['id','alias','hostname','username','platform','tags']);
            foreach ($book['peers'] as $peer) fputcsv($out, [$peer['id'] ?? '',$peer['alias'] ?? '',$peer['hostname'] ?? '',$peer['username'] ?? '',$peer['platform'] ?? '',implode(',', $peer['tags'] ?? [])]);
            fclose($out); exit;
        }
        reply($book);
    }
    if ($adminApi && preg_match('#^/admin/api/address-book/import(?:/(preview|apply))?$#', $path, $match)) {
        method('POST'); $actor = admin_user($db); csrf_check(); $uid = (int)($_GET['user_id'] ?? $actor['id']); assert_address_book_scope($actor, $uid);
        $body = json_body(); $format = strtolower((string)($body['format'] ?? $_GET['format'] ?? 'json')); $raw = $body['data'] ?? null;
        if ($format === 'json' && is_array($raw)) $book = $raw; else { if (!is_string($raw)) fail(422, '导入数据不能为空'); $book = parse_address_book_input($raw, $format); }
        $plan = address_book_import_plan($db, $uid, $book);
        if (($match[1] ?? 'preview') !== 'apply') reply(['ok'=>true,'plan'=>$plan,'apply_required'=>true]);
        $current = address_book_export($db, $uid); $currentSnapshot = hash('sha256', json_encode($current, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        if ($currentSnapshot !== $plan['snapshot']) fail(409, '导入预览已过期，请重新预览');
        $count = txn($db, fn() => address_book_import($db, $uid, $book)); reply(['ok'=>true,'imported'=>$count,'plan'=>$plan]);
    }
    if ($adminApi && $path === '/admin/api/address-book/batch') {
        method('POST'); $actor = admin_user($db); csrf_check();
        $source = address_book_target($db, $actor, $_GET['user_id'] ?? null); $d = json_body();
        $action = $d['action'] ?? ''; if (!is_string($action) || !in_array($action, ['remove','add_tags','remove_tags','copy','move'], true)) fail(422, '批量操作类型错误');
        $ids = address_book_peer_ids($d['peer_ids'] ?? null);
        $tags = in_array($action, ['add_tags','remove_tags'], true) ? address_book_tags($d['tags'] ?? null) : [];
        if (in_array($action, ['add_tags','remove_tags'], true) && count($tags) < 1) fail(422, '至少选择一个标签');
        $target = null;
        if (in_array($action, ['copy','move'], true)) {
            $target = address_book_target($db, $actor, $d['target_user_id'] ?? null);
            if ((int)$target['id'] === (int)$source['id']) fail(422, '目标用户不能与来源用户相同');
        }
        $changed = txn($db, function() use ($db,$source,$target,$action,$ids,$tags) {
            $sourceProfile = personal_profile($db, $source); $sourceState = admin_address_book_state($db, $source, $sourceProfile);
            foreach ($ids as $id) if (!isset($sourceState['peers'][$id])) fail(404, '通讯录客户端不存在：' . $id);
            $targetProfile = $target ? personal_profile($db, $target) : null;
            $targetState = $target ? admin_address_book_state($db, $target, $targetProfile) : null;
            foreach ($ids as $id) {
                if ($action === 'remove') { delete_admin_book_peer($db, $source, $sourceProfile, $id); continue; }
                $payload = $sourceState['peers'][$id]; unset($payload['favorite']);
                if ($action === 'add_tags') $payload['tags'] = array_values(array_unique(array_merge(address_book_tags($payload['tags'] ?? []), $tags)));
                elseif ($action === 'remove_tags') $payload['tags'] = array_values(array_filter(address_book_tags($payload['tags'] ?? []), fn($tag)=>!in_array($tag, $tags, true)));
                elseif (!isset($targetState['peers'][$id])) {
                    sync_admin_book_peer($db, $target, $targetProfile, $id, $payload);
                    ensure_address_book_tag_catalogs($db, $target, $targetProfile, address_book_tags($payload['tags'] ?? []));
                    $targetState['peers'][$id] = $payload;
                }
                if (in_array($action, ['add_tags','remove_tags'], true)) sync_admin_book_peer($db, $source, $sourceProfile, $id, $payload);
                if ($action === 'move') delete_admin_book_peer($db, $source, $sourceProfile, $id);
            }
            if ($action === 'add_tags') ensure_address_book_tag_catalogs($db, $source, $sourceProfile, $tags);
            return count($ids);
        });
        admin_event($db, (int)$actor['id'], 'batch_address_book_' . $action, (int)$source['id']);
        reply(['ok'=>true,'action'=>$action,'changed'=>$changed,'sync'=>'next_address_book_pull']);
    }
    if ($adminApi && ($path === '/admin/api/devices/address-book' || $path === '/admin/api/devices/address-book/preview')) {
        method('POST'); $actor = admin_user($db); csrf_check(); $d = json_body();
        $mode = $d['mode'] ?? 'add'; $preview = $path === '/admin/api/devices/address-book/preview' || $mode === 'preview'; $requestedPreview = $mode === 'preview';
        if ($preview && $mode === 'preview') $mode = (string)($d['preview_mode'] ?? 'add');
        if (!is_string($mode) || !in_array($mode, ['add','remove','replace','preview'], true)) fail(422, '通讯录操作模式错误');
        if (!isset($d['devices']) || !is_array($d['devices']) || !array_is_list($d['devices']) || count($d['devices']) < 1 || count($d['devices']) > 200) fail(422, '至少选择一个客户端');
        $devices = [];
        foreach ($d['devices'] as $item) {
            if (!is_array($item)) fail(422, '客户端选择格式错误');
            $id = address_book_peer_id($item['id'] ?? ''); $uuid = text_field($item, 'uuid', 256);
            if ($uuid === '') fail(422, '客户端 UUID 不能为空');
            $key = $id . "\0" . $uuid; $devices[$key] = ['id'=>$id,'uuid'=>$uuid];
        }
        $users = normalize_assignment_users($db, $d['user_ids'] ?? [], $mode === 'replace');
        foreach ($users as $target) assert_address_book_scope($actor, (int)$target['id']);
        $crossUserScope = (string)($actor['address_book_scope'] ?? 'self') === 'all';
        if ($preview) {
            $snapshot=[]; $add=[]; $already=[]; $remove=[]; $skipped=[]; $invalid=[];
            foreach ($devices as $device) foreach ($users as $target) {
                $exists = db_one($db, 'SELECT p.id FROM ab_profile_peers p JOIN ab_profiles a ON a.guid=p.guid WHERE a.uid=:uid AND a.personal=1 AND p.id=:id UNION SELECT id FROM rustdesk_peers WHERE uid=:uid AND id=:id', ['uid'=>$target['id'],'id'=>$device['id']]);
                if (!db_one($db, 'SELECT id FROM device_reports WHERE id=:id AND uuid=:uuid UNION SELECT id FROM device_deployments WHERE id=:id AND uuid=:uuid', ['id'=>$device['id'],'uuid'=>$device['uuid']])) { $invalid[]=['id'=>$device['id'],'uuid'=>$device['uuid'],'user_id'=>(int)$target['id'],'type'=>'device_missing']; continue; }
                $entry=['id'=>$device['id'],'uuid'=>$device['uuid'],'user_id'=>(int)$target['id'],'assigned'=>(bool)$exists]; $snapshot[]=$entry;
                if ($mode === 'remove') { if ($exists) $remove[]=$entry; else $skipped[]=$entry; }
                else { if ($exists) $already[]=$entry; else $add[]=$entry; }
            }
            if ($mode === 'replace') {
                $replaceTargets = $crossUserScope ? assignment_users($db) : [$actor];
                foreach ($devices as $device) foreach ($replaceTargets as $target) {
                    if (in_array((int)$target['id'], array_map(static fn($u)=>(int)$u['id'],$users), true)) continue;
                    $exists=db_one($db,'SELECT p.id FROM ab_profile_peers p JOIN ab_profiles a ON a.guid=p.guid WHERE a.uid=:uid AND a.personal=1 AND p.id=:id UNION SELECT id FROM rustdesk_peers WHERE uid=:uid AND id=:id',['uid'=>$target['id'],'id'=>$device['id']]);
                    if ($exists) $remove[]=['id'=>$device['id'],'uuid'=>$device['uuid'],'user_id'=>(int)$target['id'],'assigned'=>true];
                }
            }
            reply(['ok'=>true,'mode'=>$requestedPreview ? 'preview' : $mode,'devices'=>count($devices),'users'=>array_map(static fn($user)=>(int)$user['id'],$users),'snapshot'=>$snapshot,'plan'=>['add'=>$add,'already_assigned'=>$already,'remove'=>$remove,'skipped'=>$skipped,'invalid'=>$invalid],'add'=>$add,'new'=>$add,'already_assigned'=>$already,'remove'=>$remove,'skipped'=>$skipped,'invalid'=>$invalid,'conflicts'=>$remove,'apply_required'=>true]);
        }
        // Keep the established add/remove/replace calls backward compatible; clients
        // using preview can opt into an explicit apply=true acknowledgement.
        txn($db, function () use ($db, $actor, $devices, $users, $mode, $crossUserScope) {
            if ($mode === 'replace') {
                foreach ($devices as $device) {
                    foreach ($crossUserScope ? assignment_users($db) : [$actor] as $target) {
                        $profile = personal_profile($db, $target);
                        delete_admin_book_peer($db, $target, $profile, $device['id']);
                    }
                }
            }
            foreach ($devices as $device) {
                $payload = assignment_peer_payload($db, $device['id'], $device['uuid']);
                foreach ($users as $target) {
                    $profile = personal_profile($db, $target);
                    if ($mode === 'remove') {
                        delete_admin_book_peer($db, $target, $profile, $device['id']);
                    } else {
                        $existing = db_one($db, 'SELECT payload FROM ab_profile_peers WHERE guid=:guid AND id=:id', ['guid'=>$profile['guid'],'id'=>$device['id']]);
                        $current = decoded_payload($existing['payload'] ?? null);
                        $payload['alias'] = (string)($current['alias'] ?? '');
                        $payload['tags'] = address_book_tags($current['tags'] ?? []);
                        $payload = array_replace($current, $payload);
                        sync_admin_book_peer($db, $target, $profile, $device['id'], $payload);
                    }
                }
            }
            admin_event($db, (int)$actor['id'], 'assign_devices_to_address_book', 0);
        });
        reply(['ok'=>true,'mode'=>$mode,'devices'=>count($devices),'users'=>array_map(static fn($user)=>(int)$user['id'],$users),'sync'=>'next_address_book_pull']);
    }
    if ($adminApi && $path === '/admin/api/address-book/tags') {
        method('POST'); $actor = admin_user($db); csrf_check(); $d = json_body();
        $target = address_book_target($db, $actor, $_GET['user_id'] ?? null);
        $name = address_book_tag_name($d['name'] ?? null); $color = $d['color'] ?? 0;
        if (!is_int($color) || $color < 0) fail(422, 'color 字段格式错误');
        txn($db, function() use ($db,$target,$name,$color) {
            $profile = personal_profile($db, $target); $state = admin_address_book_state($db, $target, $profile);
            foreach ($state['tags'] as $tag) if ($tag['name'] === $name) fail(409, '标签已存在');
            sync_admin_book_tags($db, $target, $profile, null, $name, $color, false);
        });
        reply(['ok'=>true,'name'=>$name,'color'=>$color,'sync'=>'next_address_book_pull'], 201);
    }
    if ($adminApi && preg_match('#^/admin/api/address-book/tags/([^/]+)$#', $path, $match)) {
        $actor = admin_user($db); csrf_check(); $old = address_book_tag_name(rawurldecode($match[1]));
        $target = address_book_target($db, $actor, $_GET['user_id'] ?? null);
        if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            txn($db, function() use ($db,$target,$old) {
                $profile = personal_profile($db, $target); $state = admin_address_book_state($db, $target, $profile); $exists = false;
                foreach ($state['tags'] as $tag) if ($tag['name'] === $old) { $exists = true; break; }
                if (!$exists) fail(404, '标签不存在');
                sync_admin_book_tags($db, $target, $profile, $old, null, null, true);
            });
            reply(['ok'=>true,'name'=>$old,'sync'=>'next_address_book_pull']);
        }
        method('PATCH'); $d = json_body(); $new = array_key_exists('name',$d) ? address_book_tag_name($d['name']) : $old;
        $color = $d['color'] ?? null; if ($color !== null && (!is_int($color) || $color < 0)) fail(422, 'color 字段格式错误');
        txn($db, function() use ($db,$target,$old,$new,$color) {
            $profile = personal_profile($db, $target); $state = admin_address_book_state($db, $target, $profile); $existing = null;
            foreach ($state['tags'] as $tag) if ($tag['name'] === $old) { $existing = $tag; break; }
            if ($existing === null) fail(404, '标签不存在');
            if ($new !== $old) foreach ($state['tags'] as $tag) if ($tag['name'] === $new) fail(409, '标签已存在');
            sync_admin_book_tags($db, $target, $profile, $old, $new, $color ?? (int)$existing['color'], false);
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
            $addressBookAssignments=[];
            foreach(db_all($db,'SELECT p.id,a.uid FROM ab_profile_peers p JOIN ab_profiles a ON a.guid=p.guid WHERE a.personal=1') as $assignment){
                $addressBookAssignments[(string)$assignment['id']][]=(int)$assignment['uid'];
            }
            foreach(db_all($db,'SELECT id,uid FROM rustdesk_peers') as $assignment){
                $key=(string)$assignment['id']; $uid=(int)$assignment['uid'];
                if (!isset($addressBookAssignments[$key]) || !in_array($uid,$addressBookAssignments[$key],true)) $addressBookAssignments[$key][]=$uid;
            }
            foreach ($addressBookAssignments as &$assignedUsers) sort($assignedUsers, SORT_NUMERIC);
            unset($assignedUsers);
            if ((string)($actor['address_book_scope'] ?? 'self') !== 'all') {
                foreach ($addressBookAssignments as $peerId=>$assignedUsers) {
                    $addressBookAssignments[$peerId] = in_array((int)$actor['id'], $assignedUsers, true) ? [(int)$actor['id']] : [];
                }
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
                $aliasEntry=$aliases[$reportRow['id']]??null; $lastHeartbeat=(int)$reportRow['last_heartbeat']; $release=canonical_release_identity($db, release_identity($report,$runtime));
                $inventory[]=['id'=>$reportRow['id'],'uuid'=>$reportRow['uuid'],'owner_id'=>$deployment&&$deployment['uid']!==null?(int)$deployment['uid']:null,
                    'hostname'=>$report['hostname']??($deploy['device_name']??''),'username'=>$report['username']??($deploy['device_username']??''),
                    'platform'=>$release['platform']!==''?$release['platform']:($report['os']??($deploy['platform']??'')),'os'=>$release['os']!==''?$release['os']:($report['os']??''),'os_version'=>$release['os_version'],'cpu'=>$report['cpu']??'','memory'=>$report['memory']??'','version'=>$release['version']!==''?$release['version']:($report['version']??''),
                    'client_id'=>$release['client_id'],'client_uuid'=>$release['client_uuid'],'product'=>$release['product'],'edition'=>$release['edition'],'build_number'=>$release['build_number'],'build_seq'=>$release['build_seq'],'source_commit'=>$release['source_commit'],'channel'=>$release['channel'],'arch'=>$release['arch'],
                    'last_update_check'=>$runtime['last_update_check']??'','last_update_status'=>$runtime['last_update_status']??'','last_update_error'=>$runtime['last_update_error']??'','last_update_source'=>$runtime['last_update_source']??'',
                    'distribution'=>$release['distribution'],'install_mode'=>$release['install_mode'],'client_arch'=>$release['arch'],'executable_name'=>$runtime['executable_name']??($report['executable_name']??''),
                    'public_ip'=>$publicIp,'private_ips'=>$network['private_ips']??[],'geo'=>$publicGeo,
                    'version_text'=>$release['version'],'heartbeat_version'=>decoded_payload($reportRow['heartbeat_payload']??null)['ver']??null,
                    'heartbeat_payload'=>decoded_payload($reportRow['heartbeat_payload']??null),
                    'runtime_payload'=>$runtime,'network_payload'=>$network,
                    'last_seen'=>(int)$reportRow['last_seen'],'last_heartbeat'=>$lastHeartbeat,
                    'updated_at'=>$deployment?(int)$deployment['updated_at']:(int)$reportRow['last_seen'],'presence'=>device_presence($lastHeartbeat,$now),
                    'deployed'=>$deployment!==null,'alias'=>$aliasEntry['value']??null,
                    'address_book_user_ids'=>$addressBookAssignments[$reportRow['id']]??[],
                    'alias_owner_id'=>$aliasEntry?(int)$actor['id']:null,'alias_owner_name'=>$aliasEntry?$actor['username']:null,
                    '_search'=>json_encode([$reportRow['id'],$reportRow['uuid'],$report,$deploy,$aliasEntry['value']??''],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)];
            }
            foreach($deployments as $deployment){
                $identity=$deployment['id']."\0".$deployment['uuid'];if(isset($reportedIds[$identity]))continue; $deploy=decoded_payload($deployment['payload']); $aliasEntry=$aliases[$deployment['id']]??null;
                $inventory[]=['id'=>$deployment['id'],'uuid'=>$deployment['uuid'],'owner_id'=>$deployment['uid']===null?null:(int)$deployment['uid'],
                    'hostname'=>$deploy['device_name']??($deploy['hostname']??''),'username'=>$deploy['device_username']??($deploy['username']??''),
                    'platform'=>$deploy['platform']??($deploy['os']??''),'os'=>$deploy['os']??'','os_version'=>$deploy['os_version']??'','cpu'=>$deploy['cpu']??'','memory'=>$deploy['memory']??'','version'=>$deploy['version']??'',
                    'distribution'=>$deploy['distribution']??'','install_mode'=>$deploy['install_mode']??'','client_arch'=>$deploy['client_arch']??'','executable_name'=>$deploy['executable_name']??'',
                    'public_ip'=>'','private_ips'=>[],'geo'=>[],'version_text'=>$deploy['version']??'','heartbeat_version'=>null,
                    'last_seen'=>null,'last_heartbeat'=>0,'updated_at'=>(int)$deployment['updated_at'],'presence'=>'unreported','deployed'=>true,
                    'runtime_payload'=>[],'network_payload'=>[],
                    'alias'=>$aliasEntry['value']??null,'address_book_user_ids'=>$addressBookAssignments[$deployment['id']]??[],
                    'alias_owner_id'=>$aliasEntry?(int)$actor['id']:null,'alias_owner_name'=>$aliasEntry?$actor['username']:null,
                    '_search'=>json_encode([$deployment['id'],$deployment['uuid'],$deploy,$aliasEntry['value']??''],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)];
            }
            if($q!=='')$inventory=array_values(array_filter($inventory,fn($row)=>stripos($row['_search'],$q)!==false));
            foreach (['platform','distribution','install_mode','version','network'] as $filter) {
                $value = $_GET[$filter] ?? ''; if (!is_string($value) || strlen($value) > 128) fail(422, '设备筛选参数错误');
                if ($value !== '') $inventory = array_values(array_filter($inventory, function($row) use ($filter,$value) {
                    if ($filter === 'network') return $value === 'public' ? ($row['public_ip'] !== '') : ($value === 'private' ? count($row['private_ips']) > 0 : true);
                    return stripos((string)($row[$filter] ?? ''), $value) !== false;
                }));
            }
            $userFilter = $_GET['user_id'] ?? ''; if ($userFilter !== '' && (!ctype_digit((string)$userFilter) || (int)$userFilter < 1)) fail(422, '通讯录用户筛选错误');
            if ($userFilter !== '') $inventory = array_values(array_filter($inventory, fn($row)=>in_array((int)$userFilter, $row['address_book_user_ids'], true)));
            $summary=['total'=>count($inventory),'online'=>0,'recent'=>0,'offline'=>0,'unreported'=>0,'labelled'=>0];
            foreach($inventory as $row){$summary[$row['presence']]++;if(is_string($row['alias'])&&$row['alias']!=='')$summary['labelled']++;}
            if($presenceFilter!==''&&$presenceFilter!=='all')$inventory=array_values(array_filter($inventory,fn($row)=>$row['presence']===$presenceFilter));
            if($labelledFilter)$inventory=array_values(array_filter($inventory,fn($row)=>is_string($row['alias'])&&$row['alias']!==''));
            usort($inventory,fn($a,$b)=>((int)($b['alias']!==null&&$b['alias']!=='')<=>((int)($a['alias']!==null&&$a['alias']!==''))) ?: strcasecmp((string)($a['alias']??$a['hostname']??''),(string)($b['alias']??$b['hostname']??'')) ?: strcmp($a['id'].'|'.$a['uuid'],$b['id'].'|'.$b['uuid']));
            $total=count($inventory); $data=array_slice($inventory,$offset,$limit); foreach($data as &$row)unset($row['_search']); unset($row);
            reply(['total'=>$total,'summary'=>$summary,'data'=>$data]);
        }
        method('DELETE'); csrf_check();
        if ($id === '') {
            $devices = update_batch_devices($db, json_body()); $references = [];
            foreach ($devices as $device) {
                foreach (db_all($db, 'SELECT a.uid,u.username FROM ab_profile_peers p JOIN ab_profiles a ON a.guid=p.guid JOIN rustdesk_users u ON u.id=a.uid WHERE a.personal=1 AND p.id=:id ORDER BY a.uid', ['id'=>$device['id']]) as $row) {
                    $references[] = ['id'=>$device['id'],'uuid'=>$device['uuid'],'user_id'=>(int)$row['uid'],'username'=>$row['username']];
                }
            }
            if ($references) reply(['error'=>'所选客户端仍被通讯录引用，请先移除通讯录分配','references'=>$references], 409);
            txn($db, function() use ($db,$devices,$actor) {
                foreach ($devices as $device) {
                    db_exec($db,'DELETE FROM device_deployments WHERE id=:id AND uuid=:uuid',$device);
                    if (database_driver() === 'sqlite') db_exec($db,'DELETE FROM device_update_keys WHERE device_id=:id AND uuid=:uuid',$device);
                    db_exec($db,'DELETE FROM device_reports WHERE id=:id AND uuid=:uuid',$device);
                    admin_event($db,(int)$actor['id'],'delete_device',0);
                }
            });
            reply(['ok'=>true,'removed'=>count($devices)]);
        }
        $uuid=device_uuid($db,$id,text_field($_GET,'uuid',256));
        $references = db_all($db, 'SELECT a.uid,u.username FROM ab_profile_peers p JOIN ab_profiles a ON a.guid=p.guid JOIN rustdesk_users u ON u.id=a.uid WHERE a.personal=1 AND p.id=:id ORDER BY a.uid', ['id'=>$id]);
        if ($references) reply(['error'=>'设备仍被通讯录引用，请先移除通讯录分配','references'=>array_map(static fn($row)=>['user_id'=>(int)$row['uid'],'username'=>$row['username']],$references)], 409);
        txn($db,function()use($db,$id,$uuid,$actor){db_exec($db,'DELETE FROM device_deployments WHERE id=:id AND uuid=:uuid',['id'=>$id,'uuid'=>$uuid]);if(database_driver()==='sqlite')db_exec($db,'DELETE FROM device_update_keys WHERE device_id=:id AND uuid=:uuid',['id'=>$id,'uuid'=>$uuid]);db_exec($db,'DELETE FROM device_reports WHERE id=:id AND uuid=:uuid',['id'=>$id,'uuid'=>$uuid]);admin_event($db,(int)$actor['id'],'delete_device',0);}); reply(['ok'=>true]);
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
            txn($db, function() use ($db,$id,$uuid,$d,$existing,$runtime,$network) {
                db_upsert($db,'device_reports',[
                    'id'=>$id,'uuid'=>$uuid,'payload'=>json_encode(merge_json_objects(decoded_payload($existing['payload'] ?? null), $d),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
                    'last_seen'=>time(),'last_heartbeat'=>0,'heartbeat_payload'=>'{}',
                    'runtime_payload'=>json_object_text($runtime),'network_payload'=>json_object_text($network),
                ],['id','uuid'],['payload','last_seen','runtime_payload','network_payload']);
                sync_reported_update_policy($db,$id,$uuid,$runtime);
            });
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
