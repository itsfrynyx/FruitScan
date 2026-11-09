<?php
require __DIR__ . '/../server/bootstrap.php';
if (PHP_SAPI !== 'cli') exit;
[$script,$email,$name] = array_pad($argv,3,'');
if (!filter_var($email,FILTER_VALIDATE_EMAIL) || !$name) exit("Usage: php scripts/admin.php admin@example.com \"Admin Name\"\nSet FRUITSCAN_ADMIN_PASSWORD in the environment first (10+ characters).\n");
$password=getenv('FRUITSCAN_ADMIN_PASSWORD');
if (!$password || strlen($password)<10 || strlen($password)>72 || str_contains($password, "\0")) { fwrite(STDERR,"Set FRUITSCAN_ADMIN_PASSWORD to a password of 10–72 bytes.\n"); exit(1); }
$upsert=database_is_mysql()?"ON DUPLICATE KEY UPDATE role='admin',status='active',password_hash=VALUES(password_hash),session_version=session_version+1":"ON CONFLICT(email) DO UPDATE SET role='admin',status='active',password_hash=excluded.password_hash,session_version=users.session_version+1";
query("INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,'admin') ".$upsert,[$name,strtolower($email),password_hash($password,PASSWORD_DEFAULT)]);
echo "Administrator account is ready.\n";
