<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require __DIR__.'/../server/database.php';
$root = dirname(__DIR__);
$configPath = $root.'/server/database.json';
$lock = $root.'/storage/database-migration.lock';
$sourcePath = $root.'/storage/fruitscan.sqlite';
$resume = in_array('--resume-local-root', $argv, true);
if (is_file($configPath)) throw new RuntimeException('Database configuration already exists; refusing to overwrite it.');
if (!is_file($sourcePath)) throw new RuntimeException('Source SQLite database is missing.');
$config = ['driver'=>'mysql','host'=>'127.0.0.1','port'=>3306,'database'=>'fruitscan','username'=>'fruitscan_app','password'=>bin2hex(random_bytes(24))];
if ($resume) { $config['username']=getenv('FRUITSCAN_MYSQL_ADMIN') ?: 'root'; $config['password']=getenv('FRUITSCAN_MYSQL_ADMIN_PASSWORD') ?: ''; }
$admin = mysql_connection(['username'=>getenv('FRUITSCAN_MYSQL_ADMIN') ?: 'root','password'=>getenv('FRUITSCAN_MYSQL_ADMIN_PASSWORD') ?: ''], false);
if (!$resume && $admin->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='fruitscan'")->fetchColumn()) throw new RuntimeException('Database fruitscan already exists; refusing to overwrite it.');
if (!$resume && $admin->query("SELECT COUNT(*) FROM mysql.user WHERE User='fruitscan_app'")->fetchColumn()) throw new RuntimeException('Application database user already exists; refusing to change it.');
$handle = fopen($lock, 'x');
if (!$handle) throw new RuntimeException('Another migration is in progress.');
fclose($handle);
$source = new PDO('sqlite:'.$sourcePath, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$source->exec('PRAGMA busy_timeout=10000');
$locked = false;
try {
    $backup = $root.'/storage/fruitscan-before-mysql-'.gmdate('Ymd-His').'.sqlite';
    $source->exec('VACUUM INTO '.$source->quote($backup));
    $source->exec('BEGIN IMMEDIATE');
    $locked = true;
    if (!$resume) $admin->exec('CREATE DATABASE fruitscan CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
    $admin->exec('USE fruitscan');
    create_mysql_schema($admin);
    $admin->beginTransaction();
    $tables=['users'=>'id','fruit_classes'=>'id','system_settings'=>'setting_key','feedback'=>'id','reset_tokens'=>'id','rate_limits'=>'bucket','detections'=>'id'];
    foreach ($tables as $table=>$key) {
        $rows=$source->query("SELECT * FROM $table ORDER BY $key")->fetchAll();
        foreach ($resume ? [] : $rows as $row) {
            $columns=implode(',',array_map(fn($column)=>'`'.$column.'`',array_keys($row)));
            $stmt=$admin->prepare("INSERT INTO `$table` ($columns) VALUES (".implode(',',array_fill(0,count($row),'?')).')');
            $stmt->execute(array_values($row));
        }
        $copied=$admin->query("SELECT * FROM `$table` ORDER BY `$key`")->fetchAll();
        if (count($rows)!==count($copied)) throw new RuntimeException("Row count mismatch: $table");
        foreach ($rows as $index=>$row) foreach ($row as $column=>$value) {
            $actual=$copied[$index][$column];
            if (($value===null)!==($actual===null) || ($value!==null && (string)$value!==(string)$actual)) throw new RuntimeException("Value mismatch: $table.$column");
        }
        echo "$table: ".count($rows)." rows copied and verified\n";
    }
    $admin->commit();
    if (!$resume) {
        $admin->exec("CREATE USER 'fruitscan_app'@'localhost' IDENTIFIED BY ".$admin->quote($config['password']));
        $admin->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON fruitscan.* TO 'fruitscan_app'@'localhost'");
    }
    mysql_connection($config)->query('SELECT COUNT(*) FROM users')->fetchColumn();
    file_put_contents($configPath.'.tmp',json_encode($config,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    if (!rename($configPath.'.tmp',$configPath)) throw new RuntimeException('Could not activate database configuration.');
    echo "Activated MariaDB database: fruitscan\nBackup: $backup\nOriginal SQLite database retained.\n";
} finally {
    if ($admin->inTransaction()) $admin->rollBack();
    if ($locked) $source->exec('ROLLBACK');
    unlink($lock);
}
