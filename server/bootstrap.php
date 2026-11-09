<?php
declare(strict_types=1);
const ROOT = __DIR__ . '/..';
define('STORAGE', getenv('FRUITSCAN_STORAGE_DIR') ?: ROOT . '/storage');
date_default_timezone_set('UTC');
ini_set('display_errors', '0');
if (!is_dir(STORAGE)) mkdir(STORAGE, 0700, true);
require_once __DIR__ . '/database.php';
function db(): PDO {
    static $db;
    if ($db) return $db;
    $config = database_config();
    if (($config['driver'] ?? '') === 'mysql') {
        $db = mysql_connection($config);
        return $db;
    }
    $db = new PDO('sqlite:' . STORAGE . '/fruitscan.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL;');
    $db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT UNIQUE NOT NULL, password_hash TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'user', status TEXT NOT NULL DEFAULT 'active', created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE IF NOT EXISTS detections (id INTEGER PRIMARY KEY, user_id INTEGER REFERENCES users(id) ON DELETE CASCADE, image_path TEXT NOT NULL, fruit_class TEXT NOT NULL, prediction TEXT NOT NULL, confidence REAL, probabilities TEXT, model_version TEXT, is_demo INTEGER NOT NULL DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE IF NOT EXISTS fruit_classes (id INTEGER PRIMARY KEY, name TEXT UNIQUE NOT NULL, description TEXT NOT NULL, image_path TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'active', created_at TEXT DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE IF NOT EXISTS system_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS feedback (id INTEGER PRIMARY KEY, name TEXT, email TEXT, message TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE IF NOT EXISTS reset_tokens (id INTEGER PRIMARY KEY, user_id INTEGER REFERENCES users(id) ON DELETE CASCADE, token_hash TEXT UNIQUE, expires_at INTEGER);
    CREATE TABLE IF NOT EXISTS rate_limits (bucket TEXT PRIMARY KEY, hits INTEGER, expires_at INTEGER);");
    $columns = $db->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('session_version', $columns, true)) $db->exec('ALTER TABLE users ADD COLUMN session_version INTEGER NOT NULL DEFAULT 1');
    return $db;
}
function query(string $sql, array $params = []): PDOStatement { $stmt = db()->prepare($sql); $stmt->execute($params); return $stmt; }
function respond(array $data, int $code = 200): never { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function fail(string $message, int $code = 400): never { respond(['success' => false, 'error' => $message], $code); }
function input(): array { $value = json_decode(file_get_contents('php://input'), true); return is_array($value) ? $value : []; }
function clean(mixed $value, int $max = 250): string { return mb_substr(trim(is_string($value) ? $value : ''), 0, $max); }
function current_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $user = query('SELECT id,name,email,role,status,created_at,session_version FROM users WHERE id=?', [$_SESSION['user_id']])->fetch();
    if (!$user || $user['status'] !== 'active' || (int)($user['session_version']) !== (int)($_SESSION['session_version'] ?? 0)) { unset($_SESSION['user_id']); return null; }
    unset($user['session_version']);
    return $user;
}
function require_user(bool $admin = false): array { $u = current_user(); if (!$u) fail('Please sign in to continue.', 401); if ($admin && $u['role'] !== 'admin') fail('Administrator access is required.', 403); return $u; }
function rate_limit(string $key, int $max, int $window = 900): void {
    $bucket = hash('sha256', $key . ($_SERVER['REMOTE_ADDR'] ?? 'cli'));
    query('DELETE FROM rate_limits WHERE expires_at < ?', [time()]);
    $upsert = database_is_mysql() ? 'ON DUPLICATE KEY UPDATE hits=hits+1' : 'ON CONFLICT(bucket) DO UPDATE SET hits=hits+1';
    query('INSERT INTO rate_limits(bucket,hits,expires_at) VALUES(?,1,?) '.$upsert, [$bucket, time()+$window]);
    if ((int)query('SELECT hits FROM rate_limits WHERE bucket=?', [$bucket])->fetchColumn() > $max) fail('Too many attempts. Please try again in a few minutes.', 429);
}
function setting(string $key, string $default = ''): string { $v = query('SELECT setting_value FROM system_settings WHERE setting_key=?', [$key])->fetchColumn(); return $v === false ? $default : $v; }
function model_config(): array {
    $file = getenv('FRUITSCAN_MODEL_MANIFEST') ?: ROOT . '/server/model.json';
    $config = is_file($file) ? json_decode(file_get_contents($file), true) : [];
    return is_array($config) ? $config : [];
}
function prediction_configuration(): array {
    // An explicitly empty environment URL keeps isolated tests and demo deployments in demo mode.
    $environmentUrl = getenv('FRUITSCAN_MODEL_URL');
    if ($environmentUrl !== false) return ['url' => $environmentUrl, 'token' => getenv('FRUITSCAN_MODEL_TOKEN') ?: ''];
    $file = ROOT . '/server/model-service.json';
    $config = is_file($file) ? json_decode(file_get_contents($file), true) : [];
    return is_array($config) ? $config : [];
}
function is_demo(): bool { return empty(prediction_configuration()['url']); }
function validate_password(string $password): void {
    if (strlen($password) < 10 || strlen($password) > 72 || str_contains($password, "\0")) fail('Use a password with 10–72 characters. Accented characters and emoji can count as more than one.');
}
function delete_detection(array $row): void {
    query('DELETE FROM detections WHERE id=?', [$row['id']]);
    $path = STORAGE . '/' . basename($row['image_path']);
    if (is_file($path)) unlink($path);
}
function create_reset(int $userId): string {
    $token = bin2hex(random_bytes(32));
    query('DELETE FROM reset_tokens WHERE user_id=? OR expires_at<?', [$userId, time()]);
    query('INSERT INTO reset_tokens(user_id,token_hash,expires_at) VALUES(?,?,?)', [$userId, hash('sha256', $token), time()+1800]);
    return $token;
}
