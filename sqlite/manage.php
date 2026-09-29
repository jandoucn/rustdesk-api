<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/lib.php';

function cli_password(array $options): string
{
    if (!isset($options['password-stdin'])) throw new RuntimeException('Use --password-stdin; passwords are not accepted in command arguments');
    $password = rtrim((string)fgets(STDIN), "\r\n");
    if ($password === '' || strlen($password) > 72 || str_contains($password, "\0")) throw new RuntimeException('Password must be 1-72 bytes without NUL characters');
    return password_hash($password, PASSWORD_DEFAULT);
}
try {
    $options = getopt('', ['db:', 'migrate', 'backup:', 'init-admin:', 'promote-admin:', 'reset-admin:', 'password-stdin']);
    if (count(array_intersect(['migrate', 'backup', 'init-admin', 'promote-admin', 'reset-admin'], array_keys($options))) === 0) {
        echo "Usage: php manage.php --db=/absolute/path/rustdesk.db --migrate\n",
            "       --init-admin=name --password-stdin | --promote-admin=id | --reset-admin=id --password-stdin | --backup=/absolute/path/copy.db\n";
        exit;
    }
    if (isset($options['backup'])) {
        $source = database_path($options['db'] ?? null);
        $db = new PDO('sqlite:' . $source);
        configure_pdo($db);
        backup_database($db, (string)$options['backup']);
        echo "Backup verified\n";
        exit;
    }
    $db = open_database($options['db'] ?? null);
    if (isset($options['init-admin'])) {
        $name = trim((string)$options['init-admin']);
        if ($name === '' || strlen($name) > 128 || preg_match('/[\x00-\x1f\x7f]/', $name)) throw new RuntimeException('Invalid username');
        $hash = cli_password($options);
        txn($db, function () use ($db, $name, $hash) {
            if (db_one($db, 'SELECT id FROM rustdesk_users WHERE username=:name', ['name' => $name])) throw new RuntimeException('Username exists; use --promote-admin instead');
            db_exec($db, 'INSERT INTO rustdesk_users(username,password,create_time,is_admin,enabled,auth_version) VALUES(:name,:hash,:at,1,1,0)', ['name' => $name, 'hash' => $hash, 'at' => time()]);
        });
        echo "Administrator created\n";
    }
    foreach (['promote-admin', 'reset-admin'] as $action) {
        if (!isset($options[$action])) continue;
        if (!ctype_digit((string)$options[$action]) || (int)$options[$action] < 1) throw new RuntimeException('Invalid user ID');
        $id = (int)$options[$action];
        $hash = $action === 'reset-admin' ? cli_password($options) : null;
        txn($db, function () use ($db, $id, $hash) {
            if (!db_one($db, 'SELECT id FROM rustdesk_users WHERE id=:id AND delete_time=0', ['id' => $id])) throw new RuntimeException('User not found');
            db_exec($db, 'UPDATE rustdesk_users SET is_admin=1,enabled=1,auth_version=auth_version+1 WHERE id=:id', ['id' => $id]);
            if ($hash !== null) db_exec($db, 'UPDATE rustdesk_users SET password=:hash WHERE id=:id', ['hash' => $hash, 'id' => $id]);
            db_exec($db, 'DELETE FROM rustdesk_token WHERE uid=:id', ['id' => $id]);
        });
        echo "Administrator updated; sessions revoked\n";
    }
    if (isset($options['migrate'])) echo "Migration verified\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n"); exit(1);
}
