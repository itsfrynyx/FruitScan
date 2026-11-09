<?php
if (PHP_SAPI !== 'cli') exit;
require __DIR__.'/../server/database.php';
$path=$argv[2]??'';
$testRoot=realpath(__DIR__.'/../test-results');
$parent=realpath(dirname($path));
if (!$testRoot || !$parent || !str_starts_with($parent.DIRECTORY_SEPARATOR,$testRoot.DIRECTORY_SEPARATOR)) throw new RuntimeException('Test config must be inside test-results.');
$adminConfig=['username'=>getenv('FRUITSCAN_MYSQL_ADMIN')?:'root','password'=>getenv('FRUITSCAN_MYSQL_ADMIN_PASSWORD')?:''];
$connection=mysql_connection($adminConfig,false);
if (($argv[1]??'')==='create') {
    $name='fruitscan_test_'.bin2hex(random_bytes(8));
    $connection->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
    $connection->exec("USE `$name`");
    create_mysql_schema($connection);
    file_put_contents($path,json_encode(['driver'=>'mysql','database'=>$name]+$adminConfig));
} elseif (($argv[1]??'')==='cleanup') {
    $config=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    $name=$config['database'];
    if (!preg_match('/^fruitscan_test_[a-f0-9]{16}$/D',$name)) throw new RuntimeException('Not an isolated test database.');
    $connection->exec("DROP DATABASE `$name`");
    unlink($path);
} else throw new RuntimeException('Unknown test operation.');
