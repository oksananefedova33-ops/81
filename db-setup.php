<?php
declare(strict_types=1);

$db = dirname(dirname(dirname(__DIR__))) . '/data/zerro_blog.db';
$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Таблица зарегистрированных доменов
$pdo->exec("CREATE TABLE IF NOT EXISTS remote_sites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    domain TEXT NOT NULL UNIQUE,
    api_token TEXT NOT NULL,
    site_name TEXT,
    last_check TEXT,
    status TEXT DEFAULT 'active',
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

// История изменений
$pdo->exec("CREATE TABLE IF NOT EXISTS remote_changes_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    domain TEXT NOT NULL,
    change_type TEXT NOT NULL, -- 'file' или 'link'
    old_value TEXT,
    new_value TEXT,
    page_url TEXT,
    element_id TEXT,
    status TEXT, -- 'success', 'failed'
    error_message TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

// API токен для удаленного управления
$pdo->exec("CREATE TABLE IF NOT EXISTS remote_api_settings (
    key TEXT PRIMARY KEY,
    value TEXT
)");

// Генерируем токен если его нет
$stmt = $pdo->query("SELECT value FROM remote_api_settings WHERE key='api_token'");
if (!$stmt->fetch()) {
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO remote_api_settings (key, value) VALUES ('api_token', ?)")
        ->execute([$token]);
}

echo "✅ Таблицы для удаленного управления созданы!";