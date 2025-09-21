<?php
/**
 * Установщик модуля Remote Control
 * Запустите этот файл один раз для создания необходимых таблиц
 */

declare(strict_types=1);

$db = dirname(dirname(dirname(__DIR__))) . '/data/zerro_blog.db';
$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "📦 Установка модуля Remote Control...<br><br>";

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
echo "✅ Таблица remote_sites создана<br>";

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
echo "✅ Таблица remote_changes_log создана<br>";

// API токен для удаленного управления
$pdo->exec("CREATE TABLE IF NOT EXISTS remote_api_settings (
    key TEXT PRIMARY KEY,
    value TEXT
)");
echo "✅ Таблица remote_api_settings создана<br>";

// Генерируем токен если его нет
$stmt = $pdo->query("SELECT value FROM remote_api_settings WHERE key='api_token'");
$existingToken = $stmt->fetchColumn();

if (!$existingToken) {
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO remote_api_settings (key, value) VALUES ('api_token', ?)")
        ->execute([$token]);
    echo "<br>🔑 Сгенерирован API токен: <code style='background:#111925;padding:4px 8px;border-radius:4px;color:#2ea8ff;'>" . $token . "</code><br>";
    echo "<br>⚠️ <strong>Сохраните этот токен!</strong> Он понадобится при экспорте сайтов с удаленным управлением.<br>";
} else {
    echo "<br>🔑 API токен уже существует: <code style='background:#111925;padding:4px 8px;border-radius:4px;color:#2ea8ff;'>" . substr($existingToken, 0, 8) . "...</code><br>";
}

// Добавляем индексы для производительности
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_remote_sites_domain ON remote_sites(domain)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_remote_sites_status ON remote_sites(status)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_remote_changes_log_domain ON remote_changes_log(domain)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_remote_changes_log_created ON remote_changes_log(created_at)");
echo "<br>✅ Индексы созданы<br>";

echo "<br><hr>";
echo "<h3>✅ Установка завершена!</h3>";
echo "<p>Модуль Remote Control готов к использованию.</p>";
echo "<p>Теперь вы можете:</p>";
echo "<ul>";
echo "<li>Экспортировать сайты с поддержкой удаленного управления</li>";
echo "<li>Управлять содержимым экспортированных сайтов из редактора</li>";
echo "<li>Массово заменять файлы и ссылки на нескольких сайтах</li>";
echo "<li>Отслеживать историю всех изменений</li>";
echo "</ul>";
echo "<br><a href='/editor/' style='padding:10px 20px;background:#2ea8ff;color:white;text-decoration:none;border-radius:8px;display:inline-block;'>Вернуться в редактор</a>";
?>