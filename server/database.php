<?php
declare(strict_types=1);

function database_config(): array {
    $driver = getenv('FRUITSCAN_DB_DRIVER');
    if ($driver === 'sqlite') return ['driver'=>'sqlite'];
    $path = getenv('FRUITSCAN_DB_CONFIG') ?: __DIR__ . '/database.json';
    if (!is_file($path)) {
        if ($driver === 'mysql') throw new RuntimeException('MySQL configuration is missing.');
        return ['driver'=>'sqlite'];
    }
    return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

function mysql_connection(array $config, bool $selectDatabase = true): PDO {
    $name = $config['database'] ?? 'fruitscan';
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name)) throw new RuntimeException('Invalid database name.');
    $dsn = 'mysql:host='.($config['host'] ?? '127.0.0.1').';port='.(int)($config['port'] ?? 3306).';charset=utf8mb4';
    if ($selectDatabase) $dsn .= ';dbname='.$name;
    $connection = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_STRINGIFY_FETCHES=>false,
    ]);
    $connection->exec("SET time_zone = '+00:00'");
    return $connection;
}

function create_mysql_schema(PDO $connection): void {
    $statements = [
        "CREATE TABLE IF NOT EXISTS users (id BIGINT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(250) NOT NULL, email VARCHAR(250) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, role VARCHAR(20) NOT NULL DEFAULT 'user', status VARCHAR(20) NOT NULL DEFAULT 'active', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, session_version INT NOT NULL DEFAULT 1)",
        "CREATE TABLE IF NOT EXISTS detections (id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT, image_path TEXT NOT NULL, fruit_class VARCHAR(250) NOT NULL, prediction VARCHAR(20) NOT NULL, confidence DOUBLE, probabilities TEXT, model_version TEXT, is_demo INT NOT NULL DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE, INDEX(user_id,created_at))",
        "CREATE TABLE IF NOT EXISTS fruit_classes (id BIGINT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(250) NOT NULL UNIQUE, description TEXT NOT NULL, image_path TEXT NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'active', created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS system_settings (setting_key VARCHAR(250) PRIMARY KEY, setting_value TEXT NOT NULL)",
        "CREATE TABLE IF NOT EXISTS feedback (id BIGINT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(250), email VARCHAR(250), message TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS reset_tokens (id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT, token_hash VARCHAR(64) UNIQUE, expires_at BIGINT, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)",
        "CREATE TABLE IF NOT EXISTS rate_limits (bucket VARCHAR(64) PRIMARY KEY, hits INT, expires_at BIGINT)",
    ];
    foreach ($statements as $sql) $connection->exec($sql.' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
}

function database_is_mysql(): bool { return db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'; }
