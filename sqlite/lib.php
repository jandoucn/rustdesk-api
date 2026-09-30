<?php
declare(strict_types=1);

function ip_matches_proxy_rule(string $ip, string $rule): bool
{
    $addressBytes = @inet_pton(trim($ip));
    if ($addressBytes === false) return false;
    if (!str_contains($rule, '/')) {
        $ruleBytes = @inet_pton(trim($rule));
        return $ruleBytes !== false && hash_equals($addressBytes, $ruleBytes);
    }
    [$network, $prefixText] = array_pad(explode('/', trim($rule), 2), 2, '');
    if ($prefixText === '' || !ctype_digit($prefixText)) return false;
    $networkBytes = @inet_pton($network);
    if ($networkBytes === false || strlen($addressBytes) !== strlen($networkBytes)) return false;
    $prefix = (int)$prefixText;
    $maximum = strlen($addressBytes) * 8;
    if ($prefix > $maximum) return false;
    $wholeBytes = intdiv($prefix, 8);
    if ($wholeBytes > 0 && !hash_equals(substr($addressBytes, 0, $wholeBytes), substr($networkBytes, 0, $wholeBytes))) return false;
    $remainingBits = $prefix % 8;
    if ($remainingBits === 0) return true;
    $mask = (0xff << (8 - $remainingBits)) & 0xff;
    return (ord($addressBytes[$wholeBytes]) & $mask) === (ord($networkBytes[$wholeBytes]) & $mask);
}
function is_public_ip(string $ip): bool
{
    $ip = trim($ip);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return false;
    if (in_array($ip, ['192.0.0.9', '192.0.0.10'], true)) return true;
    $special = [
        '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4',
        '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64',
        '100:0:0:1::/64', '2001::/23', '2001:db8::/32',
        '2002::/16', '3fff::/20', '5f00::/16', 'ff00::/8',
    ];
    foreach ($special as $range) if (ip_matches_proxy_rule($ip, $range)) return false;
    return true;
}
function forwarded_public_ip(string $remote, string $realIp, string $forwardedFor, array $trustedRules): string
{
    $remote = trim($remote);
    $trusted = static function (string $ip) use ($trustedRules): bool {
        foreach ($trustedRules as $rule) if (ip_matches_proxy_rule($ip, trim((string)$rule))) return true;
        return false;
    };
    if (!$trusted($remote)) return is_public_ip($remote) ? $remote : '';

    $forwardedFor = trim($forwardedFor);
    if ($forwardedFor !== '') {
        $chain = array_map('trim', explode(',', $forwardedFor));
        foreach ($chain as $hop) if ($hop === '' || filter_var($hop, FILTER_VALIDATE_IP) === false) return '';
        $chain[] = $remote;
        while ($chain && $trusted((string)end($chain))) array_pop($chain);
        $candidate = $chain ? (string)end($chain) : '';
        return is_public_ip($candidate) ? $candidate : '';
    }
    $realIp = trim($realIp);
    return is_public_ip($realIp) ? $realIp : '';
}

function installation_config_path(): string {
    return (string)(getenv('RUSTDESK_INSTALL_CONFIG') ?: '/var/www/data/install.json');
}
function installation_config(): array {
    $path=installation_config_path(); if(!is_file($path))return [];
    try{$value=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);return is_array($value)?$value:[];}catch(Throwable){return [];}
}
function write_installation_config(array $config): void {
    $path=installation_config_path();$dir=dirname($path);if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('cannot create installation config directory');
    $temporary=$path.'.tmp.'.bin2hex(random_bytes(6));$json=json_encode($config,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
    if(file_put_contents($temporary,$json,LOCK_EX)===false)throw new RuntimeException('cannot write installation config');@chmod($temporary,0600);
    if(!rename($temporary,$path)){@unlink($temporary);throw new RuntimeException('cannot publish installation config');}
}
function database_driver(?array $settings=null): string {
    $settings??=installation_config();
    $v = strtolower(trim((string)($settings['database']??($GLOBALS['database_driver_override']??(getenv('RUSTDESK_DB_DRIVER') ?: 'sqlite')))));
    if (!in_array($v, ['sqlite','mysql'], true)) throw new RuntimeException('RUSTDESK_DB_DRIVER must be sqlite or mysql');
    return $v;
}
function database_path(?string $path=null): string {
    $path=$path ?: (getenv('RUSTDESK_DB') ?: '/var/www/data/rustdesk.db');
    if (!str_starts_with($path, '/')) throw new RuntimeException('Database path must be absolute');
    $parts=[]; foreach(explode('/',$path) as $p){if($p===''||$p==='.')continue;if($p==='..')array_pop($parts);else $parts[]=$p;}
    $path='/'.implode('/',$parts); $real=realpath($path) ?: ((realpath(dirname($path)) ?: dirname($path)).'/'.basename($path)); $root=realpath(__DIR__) ?: __DIR__;
    if($real===$root||str_starts_with($real,$root.'/')) throw new RuntimeException('database must be outside the web root');
    return $path;
}
function configure_pdo(PDO $db): void {
    $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC); $db->setAttribute(PDO::ATTR_EMULATE_PREPARES,$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql');
}
function open_database(?string $path=null,?array $settings=null): PDO {
    $settings??=installation_config();
    if(isset($settings['database']))$GLOBALS['database_driver_override']=$settings['database'];
    if(database_driver($settings)==='sqlite'){
        $path=database_path($path??($settings['sqlite_path']??null)); $dir=dirname($path); if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException("cannot create database directory: $dir");
        $db=new PDO('sqlite:'.$path); configure_pdo($db); $db->exec('PRAGMA foreign_keys=ON'); $db->exec('PRAGMA busy_timeout=5000'); @chmod($path,0600); migrate_database($db,$dir); return $db;
    }
    $host=$settings['mysql_host']??(getenv('RUSTDESK_DB_HOST')?:'mysql'); $port=(int)($settings['mysql_port']??(getenv('RUSTDESK_DB_PORT')?:3306)); $name=$settings['mysql_database']??(getenv('RUSTDESK_DB_NAME')?:'rustdesk');
    if(!preg_match('/^[A-Za-z0-9_]+$/',$name))throw new RuntimeException('Invalid MySQL database name');
    $user=$settings['mysql_user']??(getenv('RUSTDESK_DB_USER')?:'rustdesk');$password=$settings['mysql_password']??(string)(getenv('RUSTDESK_DB_PASSWORD')?:'');
    $db=new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",$user,$password); configure_pdo($db);
    $meta=db_one($db,"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='app_meta'");
    if(!$meta || !db_one($db,"SELECT value FROM app_meta WHERE `key`='schema_version' AND value='9'")) {
        ensure_schema($db); migrate_device_deployment_identity($db); db_insert_ignore($db,'app_meta',['key'=>'migrated_at','value'=>(string)time()]); db_upsert($db,'app_meta',['key'=>'schema_version','value'=>'9'],['key'],['value']);
    }
    return $db;
}
function db_query(PDO $db,string $sql,array $params=[]): PDOStatement {
    $s=$db->prepare($sql); foreach($params as $k=>$v){$n=is_int($k)?$k+1:':'.ltrim((string)$k,':');$t=is_int($v)?PDO::PARAM_INT:($v===null?PDO::PARAM_NULL:PDO::PARAM_STR);$s->bindValue($n,$v,$t);} $s->execute(); return $s;
}
function db_one(PDO $db,string $sql,array $params=[]): ?array {$r=db_query($db,$sql,$params)->fetch();return $r===false?null:$r;}
function db_all(PDO $db,string $sql,array $params=[]): array {return db_query($db,$sql,$params)->fetchAll();}
function db_exec(PDO $db,string $sql,array $params=[]): void {db_query($db,$sql,$params);}
function txn(PDO $db,callable $cb): mixed {$db->beginTransaction();try{$r=$cb($db);$db->commit();return $r;}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}}
function has_administrator(PDO $db): bool
{
    return (int)(db_one($db,'SELECT COUNT(*) AS n FROM rustdesk_users WHERE is_admin=1 AND enabled=1 AND delete_time=0')['n']??0)>0;
}
final class InstallationConflict extends RuntimeException {}
function create_initial_administrator(PDO $db,string $name,string $hash,callable $beforeCommit): void
{
    txn($db,function()use($db,$name,$hash,$beforeCommit){
        if(has_administrator($db))fail(409,'系统已经完成初始化');
        $matches=db_all($db,'SELECT id FROM rustdesk_users WHERE username=:name ORDER BY id',['name'=>$name]);
        if(count($matches)>1)throw new InstallationConflict('管理员用户名存在重复记录，请先清理旧数据库中的同名用户');
        if($matches){
            $id=(int)$matches[0]['id'];
            db_exec($db,'UPDATE rustdesk_users SET password=:password,delete_time=0,is_admin=1,enabled=1,auth_version=auth_version+1 WHERE id=:id',['password'=>$hash,'id'=>$id]);
            db_exec($db,'DELETE FROM rustdesk_token WHERE uid=:id',['id'=>$id]);
        }else{
            db_exec($db,'INSERT INTO rustdesk_users(username,password,create_time,delete_time,is_admin,enabled,auth_version) VALUES(:name,:password,:at,0,1,1,0)',['name'=>$name,'password'=>$hash,'at'=>time()]);
        }
        $beforeCommit();
    });
}
function db_upsert(PDO $db,string $table,array $values,array $keys,array $updates): void {
    $cols=array_keys($values);$q=implode(',',array_map(fn($x)=>"`$x`",$cols));$b=implode(',',array_map(fn($x)=>":$x",$cols));
    if(database_driver()==='mysql'){$u=implode(',',array_map(fn($x)=>"`$x`=VALUES(`$x`)",$updates));db_exec($db,"INSERT INTO `$table`($q) VALUES($b) ON DUPLICATE KEY UPDATE $u",$values);}
    else{$k=implode(',',array_map(fn($x)=>"`$x`",$keys));$u=implode(',',array_map(fn($x)=>"`$x`=excluded.`$x`",$updates));db_exec($db,"INSERT INTO `$table`($q) VALUES($b) ON CONFLICT($k) DO UPDATE SET $u",$values);}
}
function db_insert_ignore(PDO $db,string $table,array $values): void {
    $cols=array_keys($values);$q=implode(',',array_map(fn($x)=>"`$x`",$cols));$b=implode(',',array_map(fn($x)=>":$x",$cols));$verb=database_driver()==='mysql'?'INSERT IGNORE':'INSERT OR IGNORE';db_exec($db,"$verb INTO `$table`($q) VALUES($b)",$values);
}
function table_columns(PDO $db,string $table): array {
    return database_driver()==='sqlite'?array_column(db_all($db,"PRAGMA table_info(`$table`)"),'name'):array_column(db_all($db,'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t',['t'=>$table]),'COLUMN_NAME');
}
function device_presence(int $lastHeartbeat, int $now): string {
    if($lastHeartbeat<=0)return 'unreported';$age=max(0,$now-$lastHeartbeat);if($age<=20)return 'online';if($age<=90)return 'recent';return 'offline';
}
function ensure_schema(PDO $db): void {
    if(database_driver()==='mysql'){
        $sql=[
        'CREATE TABLE IF NOT EXISTS rustdesk_users (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,username VARCHAR(128) NOT NULL,password VARCHAR(255) NOT NULL,create_time BIGINT NOT NULL DEFAULT 0,delete_time BIGINT NOT NULL DEFAULT 0,is_admin TINYINT(1) NOT NULL DEFAULT 0,enabled TINYINT(1) NOT NULL DEFAULT 1,auth_version BIGINT NOT NULL DEFAULT 0,UNIQUE KEY users_name(username)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS rustdesk_token (access_token VARCHAR(128) PRIMARY KEY,username VARCHAR(128) NOT NULL,uid BIGINT UNSIGNED NOT NULL,id VARCHAR(128) NOT NULL,uuid VARCHAR(256),login_time BIGINT NOT NULL DEFAULT 0,expire_time BIGINT NOT NULL DEFAULT 0,auth_version BIGINT NOT NULL DEFAULT 0,KEY token_uid(uid)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS rustdesk_peers (deviceid BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,uid BIGINT UNSIGNED NOT NULL,id VARCHAR(128) NOT NULL,username VARCHAR(255),hostname VARCHAR(255),alias VARCHAR(255),platform VARCHAR(128),tags TEXT,hash VARCHAR(255),UNIQUE KEY peer_uid_id(uid,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS rustdesk_tags (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,uid BIGINT UNSIGNED NOT NULL,tag VARCHAR(256) NOT NULL,UNIQUE KEY tag_uid(uid,tag)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS app_meta (`key` VARCHAR(128) PRIMARY KEY,value TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS address_books (uid BIGINT UNSIGNED PRIMARY KEY,payload LONGTEXT NOT NULL,updated_at BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS device_reports (id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,uuid VARCHAR(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,payload LONGTEXT NOT NULL,last_seen BIGINT NOT NULL,last_heartbeat BIGINT NOT NULL DEFAULT 0,heartbeat_payload LONGTEXT NOT NULL,runtime_payload LONGTEXT NULL,network_payload LONGTEXT NULL,PRIMARY KEY(id,uuid)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS audit_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,device_id VARCHAR(128) NOT NULL,uuid VARCHAR(256) NOT NULL,kind VARCHAR(32) NOT NULL,nonce VARCHAR(256),payload LONGTEXT NOT NULL,created_at BIGINT NOT NULL,UNIQUE KEY audit_nonce(device_id,uuid,kind,nonce)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS admin_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,actor_id BIGINT UNSIGNED NOT NULL,action VARCHAR(64) NOT NULL,target_id BIGINT UNSIGNED,created_at BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS login_limits (bucket CHAR(64) PRIMARY KEY,attempts INT NOT NULL,last_attempt BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS ab_profiles (guid VARCHAR(128) PRIMARY KEY,uid BIGINT UNSIGNED NOT NULL,name VARCHAR(255) NOT NULL,owner VARCHAR(128) NOT NULL,note TEXT NOT NULL,rule INT NOT NULL DEFAULT 3,personal TINYINT(1) NOT NULL DEFAULT 1,created_at BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS ab_profile_peers (guid VARCHAR(128) NOT NULL,id VARCHAR(128) NOT NULL,payload LONGTEXT NOT NULL,updated_at BIGINT NOT NULL,PRIMARY KEY(guid,id),FOREIGN KEY(guid) REFERENCES ab_profiles(guid) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS ab_profile_tags (guid VARCHAR(128) NOT NULL,name VARCHAR(256) NOT NULL,color BIGINT NOT NULL DEFAULT 0,PRIMARY KEY(guid,name),FOREIGN KEY(guid) REFERENCES ab_profiles(guid) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS admin_peer_favorites (uid BIGINT UNSIGNED NOT NULL,id VARCHAR(128) NOT NULL,created_at BIGINT NOT NULL,PRIMARY KEY(uid,id),KEY favorites_peer(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS device_deployments (id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,uuid VARCHAR(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,pk TEXT NOT NULL,uid BIGINT UNSIGNED,payload LONGTEXT NOT NULL,updated_at BIGINT NOT NULL,PRIMARY KEY(id,uuid)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS switch_grants (id VARCHAR(128) NOT NULL,verifier VARCHAR(256) NOT NULL,timestamp BIGINT NOT NULL,signature VARCHAR(512) NOT NULL,updated_at BIGINT NOT NULL,PRIMARY KEY(id,verifier)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS audit_notes (guid VARCHAR(256) PRIMARY KEY,note TEXT NOT NULL,updated_at BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS record_chunks (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,upload_key VARCHAR(512) NOT NULL,operation VARCHAR(64) NOT NULL,filename VARCHAR(512) NOT NULL,chunk_size BIGINT NOT NULL,payload LONGBLOB NOT NULL,created_at BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS update_releases (version VARCHAR(32) NOT NULL,build_seq BIGINT UNSIGNED NOT NULL,channel VARCHAR(32) NOT NULL,manifest LONGTEXT NOT NULL,published_at BIGINT NOT NULL,active TINYINT(1) NOT NULL DEFAULT 1,PRIMARY KEY(version,build_seq,channel)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS device_update_policies (id VARCHAR(128) NOT NULL,uuid VARCHAR(256) NOT NULL,mode VARCHAR(32) NOT NULL DEFAULT \'notify\',channel VARCHAR(32) NOT NULL DEFAULT \'stable\',target_version VARCHAR(32) NULL,target_build_seq BIGINT UNSIGNED NULL,auto_install TINYINT(1) NOT NULL DEFAULT 0,policy_revision BIGINT UNSIGNED NOT NULL DEFAULT 1,updated_by BIGINT UNSIGNED NULL,updated_at BIGINT NOT NULL,PRIMARY KEY(id,uuid)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS device_update_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,device_id VARCHAR(128) NOT NULL,uuid VARCHAR(256) NOT NULL,from_version VARCHAR(32) NULL,to_version VARCHAR(32) NULL,from_build_seq BIGINT UNSIGNED NULL,to_build_seq BIGINT UNSIGNED NULL,status VARCHAR(32) NOT NULL,source VARCHAR(64) NULL,error_code VARCHAR(128) NULL,started_at BIGINT NOT NULL,finished_at BIGINT NULL,KEY update_event_device(device_id,uuid,started_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'];
        foreach($sql as $s)$db->exec($s);
        foreach(['rustdesk_users'=>['is_admin'=>'TINYINT(1) NOT NULL DEFAULT 0','enabled'=>'TINYINT(1) NOT NULL DEFAULT 1','auth_version'=>'BIGINT NOT NULL DEFAULT 0'],'rustdesk_token'=>['auth_version'=>'BIGINT NOT NULL DEFAULT 0'],'device_reports'=>['last_heartbeat'=>'BIGINT NOT NULL DEFAULT 0','heartbeat_payload'=>"LONGTEXT NOT NULL DEFAULT ('{}')",'runtime_payload'=>'LONGTEXT NULL','network_payload'=>'LONGTEXT NULL']] as $t=>$fs){$existing=table_columns($db,$t);foreach($fs as $f=>$def)if(!in_array($f,$existing,true))$db->exec("ALTER TABLE `$t` ADD COLUMN `$f` $def");}
        $tables=['rustdesk_users','rustdesk_token','rustdesk_peers','rustdesk_tags','app_meta','address_books','device_reports','audit_events','admin_events','login_limits','ab_profiles','ab_profile_peers','ab_profile_tags','admin_peer_favorites','device_deployments','switch_grants','audit_notes','record_chunks','update_releases','device_update_policies','device_update_events'];
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        try { foreach($tables as $table)$db->exec("ALTER TABLE `$table` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); }
        finally { $db->exec('SET FOREIGN_KEY_CHECKS=1'); }
        $db->exec('ALTER TABLE rustdesk_users MODIFY username VARCHAR(128) NOT NULL, MODIFY password VARCHAR(255) NOT NULL, MODIFY create_time BIGINT NOT NULL DEFAULT 0, MODIFY delete_time BIGINT NOT NULL DEFAULT 0');
        $db->exec('ALTER TABLE rustdesk_token MODIFY access_token VARCHAR(128) NOT NULL, MODIFY username VARCHAR(128) NOT NULL, MODIFY id VARCHAR(128) NOT NULL, MODIFY uuid VARCHAR(256) NULL, MODIFY login_time BIGINT NOT NULL DEFAULT 0, MODIFY expire_time BIGINT NOT NULL DEFAULT 0');
        $db->exec('ALTER TABLE rustdesk_peers MODIFY id VARCHAR(128) NOT NULL, MODIFY username VARCHAR(255) NULL, MODIFY hostname VARCHAR(255) NULL, MODIFY alias VARCHAR(255) NULL, MODIFY platform VARCHAR(128) NULL, MODIFY tags TEXT NULL, MODIFY hash VARCHAR(255) NULL');
        $db->exec('ALTER TABLE rustdesk_tags MODIFY tag VARCHAR(256) NOT NULL');
        $db->exec('ALTER TABLE device_reports MODIFY id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, MODIFY uuid VARCHAR(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
        $db->exec('ALTER TABLE device_deployments MODIFY id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, MODIFY uuid VARCHAR(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
        return;
    }
    $sql=[
    'CREATE TABLE IF NOT EXISTS rustdesk_users (id INTEGER PRIMARY KEY AUTOINCREMENT,username TEXT NOT NULL,password TEXT NOT NULL,create_time INTEGER NOT NULL DEFAULT 0,delete_time INTEGER NOT NULL DEFAULT 0,is_admin INTEGER NOT NULL DEFAULT 0,enabled INTEGER NOT NULL DEFAULT 1,auth_version INTEGER NOT NULL DEFAULT 0)',
    'CREATE TABLE IF NOT EXISTS rustdesk_token (access_token TEXT NOT NULL,username TEXT NOT NULL,uid INTEGER NOT NULL,id TEXT NOT NULL,uuid TEXT,login_time INTEGER NOT NULL DEFAULT 0,expire_time INTEGER NOT NULL DEFAULT 0,auth_version INTEGER NOT NULL DEFAULT 0)',
    'CREATE TABLE IF NOT EXISTS rustdesk_peers (deviceid INTEGER PRIMARY KEY AUTOINCREMENT,uid INTEGER NOT NULL,id TEXT NOT NULL,username TEXT,hostname TEXT,alias TEXT,platform TEXT,tags TEXT,hash TEXT)',
    'CREATE TABLE IF NOT EXISTS rustdesk_tags (id INTEGER PRIMARY KEY AUTOINCREMENT,uid INTEGER NOT NULL,tag TEXT NOT NULL)',
    'CREATE TABLE IF NOT EXISTS app_meta (key TEXT PRIMARY KEY,value TEXT NOT NULL)','CREATE TABLE IF NOT EXISTS address_books (uid INTEGER PRIMARY KEY,payload TEXT NOT NULL,updated_at INTEGER NOT NULL)','CREATE TABLE IF NOT EXISTS device_reports (id TEXT NOT NULL,uuid TEXT NOT NULL,payload TEXT NOT NULL,last_seen INTEGER NOT NULL,last_heartbeat INTEGER NOT NULL DEFAULT 0,heartbeat_payload TEXT NOT NULL DEFAULT "{}",runtime_payload TEXT,network_payload TEXT,PRIMARY KEY(id,uuid))',
    'CREATE TABLE IF NOT EXISTS audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT,device_id TEXT NOT NULL,uuid TEXT NOT NULL,kind TEXT NOT NULL,nonce TEXT,payload TEXT NOT NULL,created_at INTEGER NOT NULL)','CREATE UNIQUE INDEX IF NOT EXISTS audit_events_nonce ON audit_events(device_id,uuid,kind,nonce) WHERE nonce IS NOT NULL AND nonce <> ""',
    'CREATE TABLE IF NOT EXISTS admin_events (id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INTEGER NOT NULL,action TEXT NOT NULL,target_id INTEGER,created_at INTEGER NOT NULL)','CREATE TABLE IF NOT EXISTS login_limits (bucket TEXT PRIMARY KEY,attempts INTEGER NOT NULL,last_attempt INTEGER NOT NULL)',
    'CREATE TABLE IF NOT EXISTS ab_profiles (guid TEXT PRIMARY KEY,uid INTEGER NOT NULL,name TEXT NOT NULL,owner TEXT NOT NULL,note TEXT NOT NULL DEFAULT "",rule INTEGER NOT NULL DEFAULT 3,personal INTEGER NOT NULL DEFAULT 1,created_at INTEGER NOT NULL)','CREATE TABLE IF NOT EXISTS ab_profile_peers (guid TEXT NOT NULL,id TEXT NOT NULL,payload TEXT NOT NULL,updated_at INTEGER NOT NULL,PRIMARY KEY(guid,id),FOREIGN KEY(guid) REFERENCES ab_profiles(guid) ON DELETE CASCADE)','CREATE TABLE IF NOT EXISTS ab_profile_tags (guid TEXT NOT NULL,name TEXT NOT NULL,color INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(guid,name),FOREIGN KEY(guid) REFERENCES ab_profiles(guid) ON DELETE CASCADE)',
    'CREATE TABLE IF NOT EXISTS admin_peer_favorites (uid INTEGER NOT NULL,id TEXT NOT NULL,created_at INTEGER NOT NULL,PRIMARY KEY(uid,id))','CREATE INDEX IF NOT EXISTS admin_peer_favorites_peer ON admin_peer_favorites(id)',
    'CREATE TABLE IF NOT EXISTS device_deployments (id TEXT NOT NULL,uuid TEXT NOT NULL,pk TEXT NOT NULL,uid INTEGER,payload TEXT NOT NULL,updated_at INTEGER NOT NULL,PRIMARY KEY(id,uuid))','CREATE TABLE IF NOT EXISTS switch_grants (id TEXT NOT NULL,verifier TEXT NOT NULL,timestamp INTEGER NOT NULL,signature TEXT NOT NULL,updated_at INTEGER NOT NULL,PRIMARY KEY(id,verifier))','CREATE TABLE IF NOT EXISTS audit_notes (guid TEXT PRIMARY KEY,note TEXT NOT NULL,updated_at INTEGER NOT NULL)','CREATE TABLE IF NOT EXISTS record_chunks (id INTEGER PRIMARY KEY AUTOINCREMENT,upload_key TEXT NOT NULL,operation TEXT NOT NULL,filename TEXT NOT NULL,chunk_size INTEGER NOT NULL,payload BLOB NOT NULL,created_at INTEGER NOT NULL)',
    'CREATE TABLE IF NOT EXISTS update_releases (version TEXT NOT NULL,build_seq INTEGER NOT NULL,channel TEXT NOT NULL,manifest TEXT NOT NULL,published_at INTEGER NOT NULL,active INTEGER NOT NULL DEFAULT 1,PRIMARY KEY(version,build_seq,channel))',
    'CREATE TABLE IF NOT EXISTS device_update_policies (id TEXT NOT NULL,uuid TEXT NOT NULL,mode TEXT NOT NULL DEFAULT "notify",channel TEXT NOT NULL DEFAULT "stable",target_version TEXT,target_build_seq INTEGER,auto_install INTEGER NOT NULL DEFAULT 0,policy_revision INTEGER NOT NULL DEFAULT 1,updated_by INTEGER,updated_at INTEGER NOT NULL,PRIMARY KEY(id,uuid))',
    'CREATE TABLE IF NOT EXISTS device_update_events (id INTEGER PRIMARY KEY AUTOINCREMENT,device_id TEXT NOT NULL,uuid TEXT NOT NULL,from_version TEXT,to_version TEXT,from_build_seq INTEGER,to_build_seq INTEGER,status TEXT NOT NULL,source TEXT,error_code TEXT,started_at INTEGER NOT NULL,finished_at INTEGER)'];
    foreach($sql as $s)$db->exec($s);
    foreach(['rustdesk_users'=>['is_admin'=>0,'enabled'=>1,'auth_version'=>0],'rustdesk_token'=>['auth_version'=>0],'device_reports'=>['last_heartbeat'=>0]] as $t=>$fs){$existing=table_columns($db,$t);foreach($fs as $f=>$d)if(!in_array($f,$existing,true))$db->exec("ALTER TABLE `$t` ADD COLUMN `$f` INTEGER NOT NULL DEFAULT $d");}
    $reportColumns=table_columns($db,'device_reports');
    if(!in_array('heartbeat_payload',$reportColumns,true))$db->exec('ALTER TABLE device_reports ADD COLUMN heartbeat_payload TEXT NOT NULL DEFAULT "{}"');
    if(!in_array('runtime_payload',$reportColumns,true))$db->exec('ALTER TABLE device_reports ADD COLUMN runtime_payload TEXT');
    if(!in_array('network_payload',$reportColumns,true))$db->exec('ALTER TABLE device_reports ADD COLUMN network_payload TEXT');
}
function migrate_device_deployment_identity(PDO $db): void {
    if(database_driver()==='mysql'){
        $primary=db_all($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='device_deployments' AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION");
        if(array_column($primary,'COLUMN_NAME')!==['id','uuid'])$db->exec('ALTER TABLE device_deployments DROP PRIMARY KEY, ADD PRIMARY KEY(id,uuid)');
        return;
    }
    $primary=[];foreach(db_all($db,'PRAGMA table_info(device_deployments)') as $column)if((int)$column['pk']>0)$primary[(int)$column['pk']]=$column['name'];ksort($primary);
    if(array_values($primary)===['id','uuid'])return;
    $db->exec('CREATE TABLE device_deployments_v8 (id TEXT NOT NULL,uuid TEXT NOT NULL,pk TEXT NOT NULL,uid INTEGER,payload TEXT NOT NULL,updated_at INTEGER NOT NULL,PRIMARY KEY(id,uuid))');
    $db->exec('INSERT INTO device_deployments_v8(id,uuid,pk,uid,payload,updated_at) SELECT id,uuid,pk,uid,payload,updated_at FROM device_deployments');
    $db->exec('DROP TABLE device_deployments');$db->exec('ALTER TABLE device_deployments_v8 RENAME TO device_deployments');
}
function backup_database(PDO $db,string $target): void {
    if(database_driver()!=='sqlite')throw new RuntimeException('Online backup is only available for SQLite');$target=database_path($target);if(file_exists($target))throw new RuntimeException('Backup destination exists');$db->exec('VACUUM INTO '.$db->quote($target));$copy=new PDO('sqlite:'.$target);configure_pdo($copy);if($copy->query('PRAGMA integrity_check')->fetchColumn()!=='ok')throw new RuntimeException('Backup integrity check failed');@chmod($target,0600);
}
function migrate_database(PDO $db,string $dir): void {
    configure_pdo($db);$meta=db_one($db,"SELECT name FROM sqlite_master WHERE type='table' AND name='app_meta'");if($meta&&db_one($db,"SELECT value FROM app_meta WHERE `key`='schema_version' AND value='9'"))return;$legacy=db_one($db,"SELECT name FROM sqlite_master WHERE type='table' AND name='rustdesk_users'");if($legacy)backup_database($db,$dir.'/rustdesk.before-v9.'.bin2hex(random_bytes(6)).'.db');txn($db,function(PDO $db){ensure_schema($db);migrate_device_deployment_identity($db);db_insert_ignore($db,'app_meta',['key'=>'migrated_at','value'=>(string)time()]);db_upsert($db,'app_meta',['key'=>'schema_version','value'=>'9'],['key'],['value']);});
}
