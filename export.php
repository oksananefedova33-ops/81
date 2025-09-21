<?php
declare(strict_types=1);
set_time_limit(0);
ini_set('memory_limit', '512M');

// Определяем тип ответа в зависимости от действия
$action = $_REQUEST['action'] ?? 'export';
if ($action === 'export') {
    // Для экспорта не устанавливаем заголовок JSON
} else {
    header('Content-Type: application/json; charset=utf-8');
}

// Проверяем включение удаленного управления
$withRemoteControl = isset($_REQUEST['remote']) && $_REQUEST['remote'] === '1';
$remoteToken = $_REQUEST['token'] ?? '';

if ($action === 'export') {
    exportSite($withRemoteControl, $remoteToken);
} else {
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}

function exportSite($withRemoteControl = false, $providedToken = '') {
    try {
        $db = dirname(__DIR__) . '/data/zerro_blog.db';
        $pdo = new PDO('sqlite:' . $db);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Получаем или генерируем API токен для remote control
        $apiToken = null;
        $editorDomain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        if ($withRemoteControl) {
            if ($providedToken) {
                $apiToken = $providedToken;
            } else {
                // Получаем токен из БД
                $stmt = $pdo->query("SELECT value FROM remote_api_settings WHERE key='api_token'");
                $apiToken = $stmt->fetchColumn();
                
                if (!$apiToken) {
                    // Генерируем новый токен
                    $apiToken = bin2hex(random_bytes(32));
                    $pdo->prepare("INSERT OR REPLACE INTO remote_api_settings (key, value) VALUES ('api_token', ?)")
                        ->execute([$apiToken]);
                }
            }
        }
        
        // Создаем временную директорию для экспорта
        $exportDir = __DIR__ . '/temp_export_' . time();
        @mkdir($exportDir, 0777, true);
        @mkdir($exportDir . '/assets', 0777, true);
        @mkdir($exportDir . '/assets/uploads', 0777, true);
        @mkdir($exportDir . '/assets/js', 0777, true);
        @mkdir($exportDir . '/api', 0777, true);
        
        // Получаем все страницы
        $pages = getPages($pdo);
        $languages = getLanguages($pdo);
        $translations = getTranslations($pdo);
        $usedFiles = [];
        
        // Генерируем CSS и JavaScript
        generateAssets($exportDir, $withRemoteControl);
        
        // Если включен remote control, добавляем необходимые файлы
        if ($withRemoteControl) {
            // Создаем endpoint для обработки удаленных команд
            generateRemoteEndpoint($exportDir, $apiToken);
            
            // Создаем client bridge script
            generateClientBridge($exportDir, $apiToken, $editorDomain);
        }
        
        // Генерируем страницы
        foreach ($pages as $page) {
            // Основной язык (русский)
            $html = generatePageHTML($pdo, $page, 'ru', null, $usedFiles, $languages, $withRemoteControl, $apiToken, $editorDomain);
            $filename = getPageFilename($page, 'ru');
            file_put_contents($exportDir . '/' . $filename, $html);
            
            // Другие языки
            if (!empty($languages) && !empty($translations)) {
                foreach ($languages as $lang) {
                    if ($lang === 'ru') continue;
                    
                    $pageTrans = $translations[$page['id']][$lang] ?? [];
                    $html = generatePageHTML($pdo, $page, $lang, $pageTrans, $usedFiles, $languages, $withRemoteControl, $apiToken, $editorDomain);
                    $filename = getPageFilename($page, $lang);
                    file_put_contents($exportDir . '/' . $filename, $html);
                }
            }
        }
        
        // Копируем используемые файлы
        copyUsedFiles($usedFiles, $exportDir);
        
        // Создаем конфигурационные файлы
        generateHtaccess($exportDir, $withRemoteControl);
        generateNginxConfig($exportDir, $withRemoteControl);
        generateReadme($exportDir, $languages, $withRemoteControl, $apiToken);
        generateRobots($exportDir);
        generateSitemap($exportDir, $pages, $languages);
        
        // Создаем ZIP архив
        $zipFile = createZipArchive($exportDir);
        
        // Удаляем временную директорию
        deleteDirectory($exportDir);
        
        // Отправляем архив
        $zipName = $withRemoteControl ? 
            'website_remote_' . date('Y-m-d_His') . '.zip' : 
            'website_export_' . date('Y-m-d_His') . '.zip';
            
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipName . '"');
        header('Content-Length: ' . filesize($zipFile));
        readfile($zipFile);
        unlink($zipFile);
        exit;
        
    } catch (Exception $e) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function generateRemoteEndpoint($exportDir, $apiToken) {
    $endpoint = <<<'PHP'
<?php
// Remote Control Endpoint
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-Token, X-Action, X-File-Url, X-Old-Url, X-New-Url, X-File-Name, X-Link-Url');
header('Content-Type: application/json; charset=utf-8');

$API_TOKEN = '{{API_TOKEN}}';

// Получаем заголовки
$headers = function_exists('getallheaders') ? getallheaders() : [];
$token = $headers['X-API-Token'] ?? $headers['x-api-token'] ?? '';
$action = $headers['X-Action'] ?? $headers['x-action'] ?? '';

// Проверка токена
if ($token !== $API_TOKEN) {
    http_response_code(401);
    die(json_encode(['error' => 'Invalid token']));
}

// Поиск файла в кнопках filebtn
function searchFileInButtons($fileUrl) {
    $found = [];
    $htmlFiles = glob('../*.html');
    
    foreach ($htmlFiles as $file) {
        $content = @file_get_contents($file);
        if ($content === false) continue;
        
        // Ищем кнопки filebtn с этим файлом
        if (preg_match_all('/<div[^>]+class="[^"]*\bfilebtn\b[^"]*"[^>]*>.*?<a[^>]+href="([^"]*)"[^>]*>/is', $content, $matches)) {
            foreach ($matches[1] as $href) {
                if (strpos($href, $fileUrl) !== false || $href === $fileUrl) {
                    $found[] = basename($file);
                    break;
                }
            }
        }
    }
    
    return array_unique($found);
}

// Поиск ссылки в кнопках linkbtn
function searchLinkInButtons($linkUrl) {
    $found = [];
    $htmlFiles = glob('../*.html');
    
    foreach ($htmlFiles as $file) {
        $content = @file_get_contents($file);
        if ($content === false) continue;
        
        // Ищем кнопки linkbtn с этой ссылкой
        if (preg_match_all('/<div[^>]+class="[^"]*\blinkbtn\b[^"]*"[^>]*>.*?<a[^>]+href="([^"]*)"[^>]*>/is', $content, $matches)) {
            foreach ($matches[1] as $href) {
                if ($href === $linkUrl) {
                    $found[] = basename($file);
                    break;
                }
            }
        }
    }
    
    return array_unique($found);
}

// Замена файла в кнопках
function replaceFileInButtons($oldUrl, $newUrl, $fileName = '') {
    $count = 0;
    $htmlFiles = glob('../*.html');
    
    foreach ($htmlFiles as $file) {
        $content = @file_get_contents($file);
        if ($content === false) continue;
        
        $modified = false;
        
        // Замена в кнопках filebtn
        $content = preg_replace_callback(
            '/(<div[^>]+class="[^"]*\bfilebtn\b[^"]*"[^>]*>.*?<a[^>]+)href="([^"]*)"([^>]*)(>.*?<\/a>.*?<\/div>)/is',
            function($matches) use ($oldUrl, $newUrl, $fileName, &$modified) {
                if (strpos($matches[2], $oldUrl) !== false || $matches[2] === $oldUrl) {
                    $modified = true;
                    $newAttrs = $matches[3];
                    
                    // Обновляем атрибут download если указано имя файла
                    if ($fileName) {
                        if (preg_match('/download="[^"]*"/', $newAttrs)) {
                            $newAttrs = preg_replace('/download="[^"]*"/', 'download="' . htmlspecialchars($fileName) . '"', $newAttrs);
                        } else {
                            $newAttrs .= ' download="' . htmlspecialchars($fileName) . '"';
                        }
                    }
                    
                    // Обновляем текст кнопки если нужно
                    $buttonContent = $matches[4];
                    if ($fileName && preg_match('/>(.*?)<\/a>/', $buttonContent, $textMatch)) {
                        $oldFileName = basename($oldUrl);
                        if (strpos($textMatch[1], $oldFileName) !== false) {
                            $newText = str_replace($oldFileName, $fileName, $textMatch[1]);
                            $buttonContent = str_replace($textMatch[1], $newText, $buttonContent);
                        }
                    }
                    
                    return $matches[1] . 'href="' . htmlspecialchars($newUrl) . '"' . $newAttrs . $buttonContent;
                }
                return $matches[0];
            },
            $content
        );
        
        if ($modified) {
            @file_put_contents($file, $content);
            $count++;
        }
    }
    
    return $count;
}

// Замена ссылки в кнопках
function replaceLinkInButtons($oldUrl, $newUrl) {
    $count = 0;
    $htmlFiles = glob('../*.html');
    
    foreach ($htmlFiles as $file) {
        $content = @file_get_contents($file);
        if ($content === false) continue;
        
        $modified = false;
        
        // Замена в кнопках linkbtn
        $content = preg_replace_callback(
            '/(<div[^>]+class="[^"]*\blinkbtn\b[^"]*"[^>]*>.*?<a[^>]+)href="([^"]*)"([^>]*>)/is',
            function($matches) use ($oldUrl, $newUrl, &$modified) {
                if ($matches[2] === $oldUrl) {
                    $modified = true;
                    return $matches[1] . 'href="' . htmlspecialchars($newUrl) . '"' . $matches[3];
                }
                return $matches[0];
            },
            $content
        );
        
        if ($modified) {
            @file_put_contents($file, $content);
            $count++;
        }
    }
    
    return $count;
}

// Получение списка всех файлов в кнопках
function listFilesInButtons() {
    $files = [];
    $htmlFiles = glob('../*.html');
    
    foreach ($htmlFiles as $file) {
        $content = @file_get_contents($file);
        if ($content === false) continue;
        
        // Ищем все кнопки filebtn
        if (preg_match_all('/<div[^>]+class="[^"]*\bfilebtn\b[^"]*"[^>]*>.*?<a[^>]+href="([^"]*)"[^>]*(?:download="([^"]*)")?[^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $url = $match[1];
                $fileName = $match[2] ?? basename($url);
                $text = strip_tags($match[3]);
                
                if (!isset($files[$url])) {
                    $files[$url] = [
                        'url' => $url,
                        'name' => $fileName,
                        'text' => trim($text),
                        'pages' => []
                    ];
                }
                
                $files[$url]['pages'][] = basename($file);
            }
        }
    }
    
    // Убираем дубликаты страниц
    foreach ($files as &$file) {
        $file['pages'] = array_unique($file['pages']);
        $file['count'] = count($file['pages']);
    }
    
    return array_values($files);
}

// Получение списка всех ссылок в кнопках
function listLinksInButtons() {
    $links = [];
    $htmlFiles = glob('../*.html');
    
    foreach ($htmlFiles as $file) {
        $content = @file_get_contents($file);
        if ($content === false) continue;
        
        // Ищем все кнопки linkbtn
        if (preg_match_all('/<div[^>]+class="[^"]*\blinkbtn\b[^"]*"[^>]*>.*?<a[^>]+href="([^"]*)"[^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $url = $match[1];
                $text = strip_tags($match[2]);
                
                if (!isset($links[$url])) {
                    $links[$url] = [
                        'url' => $url,
                        'text' => trim($text),
                        'pages' => []
                    ];
                }
                
                $links[$url]['pages'][] = basename($file);
            }
        }
    }
    
    // Убираем дубликаты страниц
    foreach ($links as &$link) {
        $link['pages'] = array_unique($link['pages']);
        $link['count'] = count($link['pages']);
    }
    
    return array_values($links);
}

// Обработка действий
switch($action) {
    case 'ping':
        echo json_encode(['status' => 'ok', 'message' => 'Connection established']);
        break;

    case 'list-files':
        $files = listFilesInButtons();
        echo json_encode(['files' => $files, 'total' => count($files)]);
        break;
        
    case 'list-links':
        $links = listLinksInButtons();
        echo json_encode(['links' => $links, 'total' => count($links)]);
        break;

    case 'search-file':
        $fileUrl = $headers['X-File-Url'] ?? $headers['x-file-url'] ?? '';
        $found = searchFileInButtons($fileUrl);
        echo json_encode(['found' => $found]);
        break;

    case 'replace-file':
        $oldUrl = $headers['X-Old-Url'] ?? $headers['x-old-url'] ?? '';
        $newUrl = $headers['X-New-Url'] ?? $headers['x-new-url'] ?? '';
        $fileName = $headers['X-File-Name'] ?? $headers['x-file-name'] ?? '';
        $result = replaceFileInButtons($oldUrl, $newUrl, $fileName);
        echo json_encode(['success' => true, 'replaced' => $result]);
        break;

    case 'search-link':
        $linkUrl = $headers['X-Link-Url'] ?? $headers['x-link-url'] ?? '';
        $found = searchLinkInButtons($linkUrl);
        echo json_encode(['found' => $found]);
        break;

    case 'replace-link':
        $oldUrl = $headers['X-Old-Url'] ?? $headers['x-old-url'] ?? '';
        $newUrl = $headers['X-New-Url'] ?? $headers['x-new-url'] ?? '';
        $result = replaceLinkInButtons($oldUrl, $newUrl);
        echo json_encode(['success' => true, 'replaced' => $result]);
        break;

    default:
        echo json_encode(['error' => 'Unknown action']);
}
PHP;

    // Заменяем токен
    $endpoint = str_replace('{{API_TOKEN}}', $apiToken, $endpoint);
    
    // Сохраняем файл
    file_put_contents($exportDir . '/api/remote-check.php', $endpoint);
    
    // Создаем index.php для защиты папки api
    $indexProtection = '<?php header("HTTP/1.0 403 Forbidden"); die("Access denied");';
    file_put_contents($exportDir . '/api/index.php', $indexProtection);
}

function generateClientBridge($exportDir, $apiToken, $editorDomain) {
    // Читаем оригинальный client-bridge.js
    $bridgeFile = __DIR__ . '/modules/remote-control/client-bridge.js';
    if (file_exists($bridgeFile)) {
        $bridgeContent = file_get_contents($bridgeFile);
    } else {
        // Если файл не найден, используем базовую версию
        $bridgeContent = getDefaultClientBridge();
    }
    
    // Заменяем плейсхолдеры
    $bridgeContent = str_replace('{{API_TOKEN}}', $apiToken, $bridgeContent);
    $bridgeContent = str_replace('{{EDITOR_DOMAIN}}', $editorDomain, $bridgeContent);
    
    // Сохраняем в assets/js
    file_put_contents($exportDir . '/assets/js/remote-bridge.js', $bridgeContent);
}

function getDefaultClientBridge() {
    return <<<'JS'
// Remote Control Bridge
(function(){
    'use strict';
    
    const API_TOKEN = '{{API_TOKEN}}';
    const EDITOR_DOMAIN = 'http://{{EDITOR_DOMAIN}}';
    
    // Слушаем команды от редактора через postMessage
    window.addEventListener('message', function(event) {
        // Проверяем источник
        if (event.origin !== EDITOR_DOMAIN) return;
        
        const data = event.data;
        if (!data || data.token !== API_TOKEN) return;
        
        // Обрабатываем команды
        handleRemoteCommand(data);
    });
    
    function handleRemoteCommand(data) {
        console.log('Remote command received:', data.action);
        
        switch(data.action) {
            case 'ping':
                respondToEditor({ success: true, message: 'pong' });
                break;
                
            case 'reload':
                location.reload();
                break;
                
            case 'search-file':
                searchFile(data.fileUrl);
                break;
                
            case 'replace-file':
                replaceFile(data.oldUrl, data.newUrl, data.fileName);
                break;
        }
    }
    
    function searchFile(fileUrl) {
        const found = [];
        
        // Ищем все элементы с этим файлом
        document.querySelectorAll('[href*="' + fileUrl + '"], [src*="' + fileUrl + '"]').forEach(el => {
            found.push({
                tag: el.tagName,
                id: el.id || '',
                class: el.className || ''
            });
        });
        
        respondToEditor({ 
            action: 'search-file-result',
            found: found 
        });
    }
    
    function replaceFile(oldUrl, newUrl, fileName) {
        let replaced = 0;
        
        // Заменяем в href
        document.querySelectorAll('[href*="' + oldUrl + '"]').forEach(el => {
            el.href = el.href.replace(oldUrl, newUrl);
            if (fileName && el.hasAttribute('download')) {
                el.download = fileName;
            }
            replaced++;
        });
        
        // Заменяем в src
        document.querySelectorAll('[src*="' + oldUrl + '"]').forEach(el => {
            el.src = el.src.replace(oldUrl, newUrl);
            replaced++;
        });
        
        respondToEditor({ 
            action: 'replace-file-result',
            replaced: replaced,
            success: true 
        });
    }
    
    function respondToEditor(data) {
        if (window.parent && window.parent !== window) {
            window.parent.postMessage({
                ...data,
                token: API_TOKEN
            }, EDITOR_DOMAIN);
        }
    }
    
    // Автоматическая регистрация сайта при загрузке
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', registerSite);
    } else {
        registerSite();
    }
    
    function registerSite() {
        // Отправляем информацию о сайте в редактор
        fetch(EDITOR_DOMAIN + '/editor/modules/remote-control/api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=register&domain=' + window.location.hostname + '&token=' + API_TOKEN
        }).catch(function() {
            // Игнорируем ошибки регистрации
        });
    }
})();
JS;
}

function generatePageHTML($pdo, $page, $lang, $translations, &$usedFiles, $allLanguages, $withRemoteControl = false, $apiToken = null, $editorDomain = null) {
    $data = json_decode($page['data_json'], true) ?: [];
    $dataTablet = json_decode($page['data_tablet'], true) ?: [];
    $dataMobile = json_decode($page['data_mobile'], true) ?: [];
    
    // Получаем мета-данные с учетом перевода
    $title = $page['meta_title'] ?: $page['name'];
    $description = $page['meta_description'] ?: '';
    
    if ($translations && $lang !== 'ru') {
        if (isset($translations['meta_title'])) {
            $title = $translations['meta_title'];
        }
        if (isset($translations['meta_description'])) {
            $description = $translations['meta_description'];
        }
    }
    
    // Получаем цвет фона страницы
    $bgColor = $data['bgColor'] ?? '#0e141b';
    $pageHeight = $data['height'] ?? 2000;
    
    // Все страницы в корне, поэтому путь к assets всегда относительный
    $assetsPath = 'assets';
    
    // Начало HTML
    $html = <<<HTML
<!DOCTYPE html>
<html lang="{$lang}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$title}</title>
    <meta name="description" content="{$description}">
    <link rel="stylesheet" href="{$assetsPath}/style.css">
HTML;

    // Добавляем мета-теги для remote control
    if ($withRemoteControl && $apiToken) {
        $html .= "\n    <!-- Remote Control Configuration -->";
        $html .= "\n    <meta name=\"remote-control\" content=\"enabled\">";
        $html .= "\n    <meta name=\"editor-domain\" content=\"{$editorDomain}\">";
    }
    
    $html .= <<<HTML

</head>
<body style="background-color: {$bgColor};">
    <div class="wrap" style="min-height: {$pageHeight}px;">
HTML;
    
    // Генерируем элементы
    foreach ($data['elements'] ?? [] as $element) {
        $html .= generateElement($element, $lang, $translations, $usedFiles, $dataTablet, $dataMobile, $page, $allLanguages);
    }
    
    $html .= "\n    </div>\n";
    
    // Добавляем скрипты
    $html .= "    <script src=\"{$assetsPath}/js/main.js\"></script>\n";
    
    // Добавляем remote control script
    if ($withRemoteControl && $apiToken) {
        $html .= "    <!-- Remote Control Bridge -->\n";
        $html .= "    <script src=\"{$assetsPath}/js/remote-bridge.js\"></script>\n";
    }
    
    $html .= <<<HTML
</body>
</html>
HTML;
    
    return $html;
}

// Модифицируем generateHtaccess для поддержки API endpoint
function generateHtaccess($exportDir, $withRemoteControl = false) {
    $htaccess = <<<HTACCESS
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /

# Защита от доступа к служебным файлам
<FilesMatch "\\.(htaccess|htpasswd|ini|log|sh)$">
    Order Allow,Deny
    Deny from all
</FilesMatch>

HTACCESS;

    if ($withRemoteControl) {
        $htaccess .= <<<HTACCESS

# Remote Control API endpoint
RewriteRule ^api/remote-check$ api/remote-check.php [L]

HTACCESS;
    }

    $htaccess .= <<<HTACCESS

# Убираем .html из URL для всех страниц включая языковые версии
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^([^/]+)/?$ $1.html [L]

# Редирект с .html на без .html
RewriteCond %{THE_REQUEST} \\s/+([^/]+)\\.html[\\s?] [NC]
RewriteRule ^ /%1 [R=301,L]

# Кеширование статических файлов
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpg "access plus 1 month"
    ExpiresByType image/jpeg "access plus 1 month"
    ExpiresByType image/gif "access plus 1 month"
    ExpiresByType image/png "access plus 1 month"
    ExpiresByType image/webp "access plus 1 month"
    ExpiresByType text/css "access plus 1 week"
    ExpiresByType text/javascript "access plus 1 week"
    ExpiresByType application/javascript "access plus 1 week"
</IfModule>

# Сжатие
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript
</IfModule>
</IfModule>
HTACCESS;
    
    file_put_contents($exportDir . '/.htaccess', $htaccess);
}

// Модифицируем generateNginxConfig
function generateNginxConfig($exportDir, $withRemoteControl = false) {
    $nginx = <<<NGINX
# Nginx конфигурация для экспортированного сайта
# Добавьте эти правила в блок server {} вашей конфигурации

NGINX;

    if ($withRemoteControl) {
        $nginx .= <<<NGINX

# Remote Control API endpoint
location /api/remote-check {
    try_files \$uri /api/remote-check.php;
    include fastcgi_params;
    fastcgi_pass unix:/var/run/php/php-fpm.sock;
    fastcgi_param SCRIPT_FILENAME \$document_root/api/remote-check.php;
}

NGINX;
    }

    $nginx .= <<<NGINX

# Убираем .html из URL
location / {
    try_files \$uri \$uri.html \$uri/ =404;
}

# Редирект с .html на без .html
location ~ \\.html$ {
    if (\$request_uri ~ ^(.*)\\.html$) {
        return 301 \$1;
    }
}

# Кеширование статических файлов
location ~* \\.(jpg|jpeg|png|gif|webp|ico|css|js)$ {
    expires 30d;
    add_header Cache-Control "public, immutable";
}

# Сжатие
gzip on;
gzip_comp_level 6;
gzip_types text/plain text/css text/xml text/javascript application/javascript application/json application/xml+rss;
gzip_vary on;

# Защита от доступа к служебным файлам
location ~ /\\. {
    deny all;
}

location ~ \\.(htaccess|htpasswd|ini|log|sh)$ {
    deny all;
}
NGINX;
    
    file_put_contents($exportDir . '/nginx.conf.example', $nginx);
}

// Модифицируем generateReadme
function generateReadme($exportDir, $languages, $withRemoteControl = false, $apiToken = null) {
    $langList = implode(', ', $languages);
    $readme = <<<README
# Экспортированный сайт

## Структура файлов

Все страницы находятся в корневой папке с языковыми суффиксами:
- `index.html` - главная страница (русский язык)
- `index-en.html` - главная страница (английский язык)
- `about.html` - страница "О нас" (русский язык)
- `about-en.html` - страница "О нас" (английский язык)
- и т.д.

## Языковые версии

Поддерживаемые языки: {$langList}

Русский язык является основным и не имеет суффикса в именах файлов.
Остальные языки добавляют суффикс `-код_языка` к имени файла.

## Структура папок

```
/
├── assets/
│   ├── style.css         # Основные стили
│   ├── js/
│   │   ├── main.js       # JavaScript для адаптивности
README;

    if ($withRemoteControl) {
        $readme .= <<<README

│   │   └── remote-bridge.js # Система удаленного управления
│   └── uploads/          # Загруженные файлы
├── api/
│   └── remote-check.php  # API endpoint для удаленного управления
README;
    } else {
        $readme .= <<<README

│   └── uploads/          # Загруженные файлы
README;
    }

    $readme .= <<<README

├── index.html            # Главная страница (RU)
├── index-en.html         # Главная страница (EN)
├── .htaccess             # Конфигурация Apache
├── nginx.conf.example    # Пример конфигурации Nginx
├── robots.txt            # Для поисковых роботов
└── sitemap.xml           # Карта сайта
```

## Установка на хостинг

### Apache
Файл `.htaccess` уже настроен. Просто загрузите все файлы на хостинг.

### Nginx
Используйте настройки из файла `nginx.conf.example`, добавив их в конфигурацию сервера.
README;

    if ($withRemoteControl) {
        $maskedToken = substr($apiToken, 0, 8) . '...' . substr($apiToken, -4);
        $readme .= <<<README


## Удаленное управление

⚠️ **ВАЖНО**: Этот сайт поддерживает удаленное управление из редактора.

### Информация о подключении:
- **Статус**: Активно
- **API Endpoint**: `/api/remote-check` (или `/api/remote-check.php`)
- **Токен**: `{$maskedToken}` (полный токен сохранен в системе)

### Безопасность:
1. **Ограничьте доступ по IP** - настройте файрвол или .htaccess для разрешения доступа только с IP редактора
2. **Используйте HTTPS** - всегда используйте защищенное соединение
3. **Регулярно меняйте токен** - обновляйте токен каждые 30-60 дней
4. **Мониторинг** - следите за логами доступа к API

### Отключение удаленного управления:
Для отключения удаленного управления:
1. Удалите папку `/api/`
2. Удалите файл `/assets/js/remote-bridge.js`
3. Удалите соответствующую строку подключения скрипта из всех HTML файлов

### Проверка соединения:
Вы можете проверить работу удаленного управления, отправив POST запрос:
```bash
curl -X POST https://ваш-сайт.com/api/remote-check \\
  -H "X-API-Token: ваш_токен" \\
  -H "X-Action: ping"
```
README;
    }

    $readme .= <<<README


## Особенности

1. **Красивые URL**: расширение .html автоматически скрывается
2. **Адаптивность**: сайт адаптирован для мобильных устройств и планшетов
3. **Многоязычность**: встроенный переключатель языков
4. **SEO-оптимизация**: sitemap.xml с поддержкой hreflang
5. **Производительность**: настроено кеширование и сжатие
README;

    if ($withRemoteControl) {
        $readme .= <<<README

6. **Удаленное управление**: возможность обновления контента из редактора
README;
    }

    $readme .= <<<README


## Поддержка

Сайт создан с помощью конструктора Zerro Blog.
README;
    
    file_put_contents($exportDir . '/README.md', $readme);
}

// Добавляем остальные функции из оригинального файла без изменений
function getPages($pdo) {
    $sql = "SELECT p.id, p.name, p.data_json, p.data_tablet, p.data_mobile, 
                   p.meta_title, p.meta_description, u.slug,
                   CASE WHEN p.id=(SELECT MIN(id) FROM pages) THEN 1 ELSE 0 END AS is_home
            FROM pages p
            LEFT JOIN urls u ON u.page_id = p.id
            ORDER BY p.id";
    
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function getLanguages($pdo) {
    $stmt = $pdo->query("SELECT data_json FROM pages");
    $languages = ['ru'];
    
    while ($row = $stmt->fetch()) {
        $data = json_decode($row['data_json'], true);
        foreach ($data['elements'] ?? [] as $element) {
            if ($element['type'] === 'langbadge' && !empty($element['langs'])) {
                $langs = explode(',', $element['langs']);
                $languages = array_merge($languages, array_map('trim', $langs));
            }
        }
    }
    
    return array_unique($languages);
}

function getTranslations($pdo) {
    $trans = [];
    
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='translations'")->fetchAll();
    if (empty($tables)) {
        return $trans;
    }
    
    $stmt = $pdo->query("SELECT * FROM translations");
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pageId = $row['page_id'];
        $lang = $row['lang'];
        $elementId = $row['element_id'];
        $field = $row['field'];
        
        if (!isset($trans[$pageId])) $trans[$pageId] = [];
        if (!isset($trans[$pageId][$lang])) $trans[$pageId][$lang] = [];
        
        $trans[$pageId][$lang][$elementId . '_' . $field] = $row['content'];
    }
    
    return $trans;
}

function getPageFilename($page, $lang = 'ru') {
    $basename = '';
    
    if ($page['is_home']) {
        $basename = 'index';
    } elseif (!empty($page['slug'])) {
        $basename = $page['slug'];
    } else {
        $basename = 'page_' . $page['id'];
    }
    
    if ($lang !== 'ru') {
        $basename .= '-' . $lang;
    }
    
    return $basename . '.html';
}

function generateElement($element, $lang, $translations, &$usedFiles, $dataTablet, $dataMobile, $page, $allLanguages) {
    $type = $element['type'] ?? '';
    $id = $element['id'] ?? '';
    
    // Получаем адаптивные стили
    $tabletElement = null;
    $mobileElement = null;
    
    foreach ($dataTablet['elements'] ?? [] as $te) {
        if (($te['id'] ?? '') === $id) {
            $tabletElement = $te;
            break;
        }
    }
    
    foreach ($dataMobile['elements'] ?? [] as $me) {
        if (($me['id'] ?? '') === $id) {
            $mobileElement = $me;
            break;
        }
    }
    
    // Базовые стили
    $left = $element['left'] ?? 0;
    $top = $element['top'] ?? 0;
    $width = $element['width'] ?? 30;
    $height = $element['height'] ?? 25;
    $zIndex = $element['z'] ?? 1;
    $radius = $element['radius'] ?? 0;
    $rotate = $element['rotate'] ?? 0;
    $opacity = $element['opacity'] ?? 1;
    
    // Пересчитываем вертикальные единицы
    $DESKTOP_W = 1200;
    $EDITOR_H = 1500;
    $topVW = round(($top / $DESKTOP_W) * 100, 4);
    $heightVW = ($type === 'text') ? null : round(((($height / 100) * $EDITOR_H) / $DESKTOP_W) * 100, 4);

    $style = sprintf(
        'left:%s%%;top:%svw;width:%s%%;%sz-index:%d;border-radius:%dpx;transform:rotate(%sdeg);opacity:%s',
        $left,
        $topVW,
        $width,
        ($type === 'text' ? 'height:auto;' : 'height:' . $heightVW . 'vw;'),
        $zIndex,
        $radius,
        $rotate,
        $opacity
    );
    
    if (!empty($element['shadow'])) {
        $style .= ';box-shadow:' . $element['shadow'];
    }
    
    $html = '';
    
    switch ($type) {
        case 'text':
            $content = $element['html'] ?? $element['text'] ?? '';
            if ($translations && isset($translations[$id . '_html'])) {
                $content = $translations[$id . '_html'];
            } elseif ($translations && isset($translations[$id . '_text'])) {
                $content = $translations[$id . '_text'];
            }
            
            $fontSize = $element['fontSize'] ?? 20;
            $color = $element['color'] ?? '#e8f2ff';
            $bg = $element['bg'] ?? 'transparent';
            $padding = $element['padding'] ?? 8;
            $textAlign = $element['textAlign'] ?? 'left';
            $fontWeight = $element['fontWeight'] ?? 'normal';
            $lineHeight = $element['lineHeight'] ?? '1.5';
            
            $textStyle = sprintf(
                'font-size:%dpx;color:%s;background:%s;padding:%dpx;text-align:%s;font-weight:%s;line-height:%s;min-height:30px;word-wrap:break-word;overflow-wrap:break-word',
                $fontSize,
                $color,
                $bg,
                $padding,
                $textAlign,
                $fontWeight,
                $lineHeight
            );
            
            $html = sprintf(
                '<div class="el text" style="%s;%s" id="%s" data-type="text" data-tablet=\'%s\' data-mobile=\'%s\'>%s</div>',
                $style,
                $textStyle,
                $id,
                json_encode($tabletElement ?: [], JSON_HEX_APOS),
                json_encode($mobileElement ?: [], JSON_HEX_APOS),
                $content
            );
            break;
            
        case 'image':
            $src = processMediaPath($element['src'] ?? '', $usedFiles);
            $alt = $element['alt'] ?? '';
            $objectFit = $element['objectFit'] ?? 'contain';
            
            if (!empty($element['html'])) {
                $html = sprintf(
                    '<div class="el image" style="%s" id="%s" data-type="image" data-tablet=\'%s\' data-mobile=\'%s\'>%s</div>',
                    $style,
                    $id,
                    json_encode($tabletElement ?: [], JSON_HEX_APOS),
                    json_encode($mobileElement ?: [], JSON_HEX_APOS),
                    processHtmlContent($element['html'], $usedFiles)
                );
            } else {
                $html = sprintf(
                    '<div class="el image" style="%s" id="%s" data-type="image" data-tablet=\'%s\' data-mobile=\'%s\'><img src="%s" alt="%s" style="width:100%%;height:100%%;object-fit:%s;"></div>',
                    $style,
                    $id,
                    json_encode($tabletElement ?: [], JSON_HEX_APOS),
                    json_encode($mobileElement ?: [], JSON_HEX_APOS),
                    $src,
                    htmlspecialchars($alt),
                    $objectFit
                );
            }
            break;
            
        case 'video':
            $src = processMediaPath($element['src'] ?? '', $usedFiles);
            $poster = isset($element['poster']) ? processMediaPath($element['poster'], $usedFiles) : '';
            
            if (!empty($element['html'])) {
                $html = sprintf(
                    '<div class="el video" style="%s" id="%s" data-type="video" data-tablet=\'%s\' data-mobile=\'%s\'>%s</div>',
                    $style,
                    $id,
                    json_encode($tabletElement ?: [], JSON_HEX_APOS),
                    json_encode($mobileElement ?: [], JSON_HEX_APOS),
                    processHtmlContent($element['html'], $usedFiles)
                );
            } else {
                $controls = ($element['controls'] ?? true) ? 'controls' : '';
                $autoplay = ($element['autoplay'] ?? false) ? 'autoplay' : '';
                $loop = ($element['loop'] ?? false) ? 'loop' : '';
                $muted = ($element['muted'] ?? false) ? 'muted' : '';
                $posterAttr = $poster ? 'poster="' . $poster . '"' : '';
                
                $html = sprintf(
                    '<div class="el video" style="%s" id="%s" data-type="video" data-tablet=\'%s\' data-mobile=\'%s\'><video src="%s" %s %s %s %s %s style="width:100%%;height:100%%;object-fit:cover;"></video></div>',
                    $style,
                    $id,
                    json_encode($tabletElement ?: [], JSON_HEX_APOS),
                    json_encode($mobileElement ?: [], JSON_HEX_APOS),
                    $src,
                    $controls,
                    $autoplay,
                    $loop,
                    $muted,
                    $posterAttr
                );
            }
            break;
            
        case 'box':
            $bg = $element['bg'] ?? 'rgba(95,179,255,0.12)';
            $border = $element['border'] ?? '1px solid rgba(95,179,255,0.35)';
            $blur = isset($element['blur']) ? 'backdrop-filter:blur(' . $element['blur'] . 'px);' : '';
            
            $boxStyle = sprintf(
                'background:%s;border:%s;%s',
                $bg,
                $border,
                $blur
            );
            
            $html = sprintf(
                '<div class="el box" style="%s;%s" id="%s" data-type="box" data-tablet=\'%s\' data-mobile=\'%s\'></div>',
                $style,
                $boxStyle,
                $id,
                json_encode($tabletElement ?: [], JSON_HEX_APOS),
                json_encode($mobileElement ?: [], JSON_HEX_APOS)
            );
            break;
            
        case 'linkbtn':
            $text = $element['text'] ?? 'Кнопка';
            if ($translations && isset($translations[$id . '_text'])) {
                $text = $translations[$id . '_text'];
            }
            
            $url = $element['url'] ?? '#';
            $bg = $element['bg'] ?? '#3b82f6';
            $color = $element['color'] ?? '#ffffff';
            $fontSize = $element['fontSize'] ?? 16;
            $target = ($element['target'] ?? '_blank');
            
            $btnStyle = 'display:flex;align-items:center;justify-content:center;width:100%;height:100%;';
            $btnStyle .= sprintf('background:%s;color:%s;border-radius:inherit;text-decoration:none;font-weight:600;font-size:%dpx;transition:all 0.3s;', $bg, $color, $fontSize);
            
            $html = sprintf(
                '<div class="el linkbtn" style="%s" id="%s" data-type="linkbtn" data-tablet=\'%s\' data-mobile=\'%s\'>
                    <a href="%s" style="%s" target="%s">%s</a>
                </div>',
                $style,
                $id,
                json_encode($tabletElement ?: [], JSON_HEX_APOS),
                json_encode($mobileElement ?: [], JSON_HEX_APOS),
                $url,
                $btnStyle,
                $target,
                htmlspecialchars($text)
            );
            break;
            
        case 'filebtn':
            $text = $element['text'] ?? 'Скачать файл';
            if ($translations && isset($translations[$id . '_text'])) {
                $text = $translations[$id . '_text'];
            }
            
            $fileUrl = processMediaPath($element['fileUrl'] ?? '#', $usedFiles);
            $fileName = $element['fileName'] ?? '';
            $bg = $element['bg'] ?? '#10b981';
            $color = $element['color'] ?? '#ffffff';
            $fontSize = $element['fontSize'] ?? 16;
            
            $btnStyle = 'display:flex;align-items:center;justify-content:center;width:100%;height:100%;gap:8px;';
            $btnStyle .= sprintf('background:%s;color:%s;border-radius:inherit;text-decoration:none;font-weight:600;font-size:%dpx;transition:all 0.3s;', $bg, $color, $fontSize);
            
            $icon = getFileIcon($fileName);
            
            $html = sprintf(
                '<div class="el filebtn" style="%s" id="%s" data-type="filebtn" data-tablet=\'%s\' data-mobile=\'%s\'>
                    <a href="%s" download="%s" style="%s">%s %s</a>
                </div>',
                $style,
                $id,
                json_encode($tabletElement ?: [], JSON_HEX_APOS),
                json_encode($mobileElement ?: [], JSON_HEX_APOS),
                $fileUrl,
                $fileName,
                $btnStyle,
                $icon,
                htmlspecialchars($text)
            );
            break;
            
        case 'langbadge':
            $langs = !empty($element['langs']) ? explode(',', $element['langs']) : $allLanguages;
            $langs = array_map('trim', $langs);
            $badgeColor = $element['badgeColor'] ?? '#2ea8ff';
            $fontSize = $element['fontSize'] ?? 14;

            $langMap = [
                'en' => '🇬🇧 English',
                'zh-Hans' => '🇨🇳 中文',
                'es' => '🇪🇸 Español',
                'hi' => '🇮🇳 हिन्दी',
                'ar' => '🇸🇦 العربية',
                'pt' => '🇵🇹 Português',
                'ru' => '🇷🇺 Русский',
                'de' => '🇩🇪 Deutsch',
                'fr' => '🇫🇷 Français',
                'it' => '🇮🇹 Italiano',
                'ja' => '🇯🇵 日本語',
                'ko' => '🇰🇷 한국어',
            ];

            $currentLang = $lang;
            $currentDisplay = $langMap[$currentLang] ?? ('🌐 ' . strtoupper($currentLang));
            $currentParts = explode(' ', $currentDisplay, 2);
            $currentFlag = $currentParts[0] ?? '🌐';
            $currentName = $currentParts[1] ?? strtoupper($currentLang);

            $optionsHtml = '';
            foreach ($langs as $l) {
                $l = trim($l);
                if ($l === '') continue;
                $display = $langMap[$l] ?? ('🌐 ' . strtoupper($l));
                $parts = explode(' ', $display, 2);
                $flag = $parts[0] ?? '🌐';
                $name = $parts[1] ?? strtoupper($l);

                $pageFilename = getPageFilename($page, $l);
                $active = ($l == $currentLang) ? ' active' : '';
                $optionsHtml .= sprintf(
                    '<a class="lang-option%s" href="%s"><span class="lang-flag">%s</span><span class="lang-name">%s</span></a>',
                    $active,
                    htmlspecialchars($pageFilename, ENT_QUOTES, 'UTF-8'),
                    $flag,
                    htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
                );
            }

            $chipStyle = sprintf(' style="background:%s;border:1px solid %s;color:#fff;font-size:%dpx;"',
                $badgeColor,
                $badgeColor,
                $fontSize
            );

            $html = sprintf(
                '<div class="el langbadge" style="%s" id="%s" data-type="langbadge" data-tablet=\'%s\' data-mobile=\'%s\'>' .
                '<div class="lang-selector" onclick="this.querySelector(\'.lang-dropdown\').classList.toggle(\'show\')">' .
                '<div class="lang-chip"%s><span class="lang-flag">%s</span><span class="lang-name">%s</span></div>' .
                '<div class="lang-dropdown">%s</div>' .
                '</div>' .
                '</div>',
                $style,
                $id,
                json_encode($tabletElement ?: [], JSON_HEX_APOS),
                json_encode($mobileElement ?: [], JSON_HEX_APOS),
                $chipStyle,
                $currentFlag,
                htmlspecialchars($currentName, ENT_QUOTES, 'UTF-8'),
                $optionsHtml
            );
            break;
            
        case 'embed':
            $embedCode = $element['embedCode'] ?? '';
            if ($translations && isset($translations[$id . '_embedCode'])) {
                $embedCode = $translations[$id . '_embedCode'];
            }
            
            $html = sprintf(
                '<div class="el embed" style="%s" id="%s" data-type="embed" data-tablet=\'%s\' data-mobile=\'%s\'>%s</div>',
                $style,
                $id,
                json_encode($tabletElement ?: [], JSON_HEX_APOS),
                json_encode($mobileElement ?: [], JSON_HEX_APOS),
                $embedCode
            );
            break;
            
        default:
            $html = sprintf(
                '<div class="el %s" style="%s" id="%s" data-type="%s" data-tablet=\'%s\' data-mobile=\'%s\'></div>',
                $type,
                $style,
                $id,
                $type,
                json_encode($tabletElement ?: [], JSON_HEX_APOS),
                json_encode($mobileElement ?: [], JSON_HEX_APOS)
            );
            break;
    }
    
    return $html;
}

// Остальные вспомогательные функции без изменений
function getFileIcon($fileName) {
    if (!$fileName) return '📄';
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    
    $icons = [
        'zip' => '📦', 'rar' => '📦', '7z' => '📦',
        'pdf' => '📕',
        'doc' => '📘', 'docx' => '📘',
        'xls' => '📗', 'xlsx' => '📗',
        'ppt' => '📙', 'pptx' => '📙',
        'mp3' => '🎵', 'wav' => '🎵',
        'mp4' => '🎬', 'avi' => '🎬',
        'jpg' => '🖼️', 'jpeg' => '🖼️', 'png' => '🖼️',
        'txt' => '📝',
    ];
    
    return $icons[$ext] ?? '📄';
}

function processMediaPath($path, &$usedFiles) {
    if (!$path || $path === '#') return $path;
    
    if (preg_match('/^https?:\/\//', $path)) {
        return $path;
    }
    
    if (strpos($path, 'data:') === 0) {
        return $path;
    }
    
    if (strpos($path, '/editor/uploads/') === 0 || strpos($path, 'editor/uploads/') === 0) {
        $filename = basename($path);
        $sourcePath = dirname(__DIR__) . '/' . ltrim($path, '/');
        
        if (file_exists($sourcePath)) {
            $usedFiles[] = [
                'source' => $sourcePath,
                'dest' => 'assets/uploads/' . $filename
            ];
            return 'assets/uploads/' . $filename;
        }
    }
    
    return $path;
}

function processHtmlContent($html, &$usedFiles) {
    $html = preg_replace_callback(
        '/(src|href)=["\']([^"\']+)["\']/i',
        function($matches) use (&$usedFiles) {
            $path = processMediaPath($matches[2], $usedFiles);
            return $matches[1] . '="' . $path . '"';
        },
        $html
    );
    
    $html = preg_replace_callback(
        '/background(-image)?:\s*url\(["\']?([^"\')]+)["\']?\)/i',
        function($matches) use (&$usedFiles) {
            $path = processMediaPath($matches[2], $usedFiles);
            return 'background' . $matches[1] . ':url(' . $path . ')';
        },
        $html
    );
    
    return $html;
}

function generateAssets($exportDir, $withRemoteControl = false) {
    // CSS файл (без изменений)
    $css = <<<CSS
* { 
    margin: 0; 
    padding: 0; 
    box-sizing: border-box; 
}

body {
    background: #0e141b;
    color: #e6f0fa;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
    margin: 0;
    overflow-x: hidden;
}

.wrap {
    position: relative;
    min-height: 100vh;
    overflow-x: hidden;
    width: 100%;
}

@supports (height: 100dvh) {
    .wrap { min-height: 100dvh; }
}

.el {
    position: absolute;
    box-sizing: border-box;
    transition: none;
}

.el.text {
    word-wrap: break-word;
    overflow-wrap: break-word;
    white-space: normal;
}

.el.text p,
.el.text h1,
.el.text h2,
.el.text h3,
.el.text h4,
.el.text h5,
.el.text h6,
.el.text ul,
.el.text ol {
    margin: 0;
    padding: 0;
}

.el.text li {
    margin: 0 0 0.35em;
}

.el.text p + p {
    margin-top: 0.35em;
}

.el.text a {
    color: inherit;
}

.el.image {
    overflow: hidden;
}

.el img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    object-position: center;
    border-radius: inherit;
    display: block;
}

.el.video {
    overflow: hidden;
}

.el video {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: inherit;
    display: block;
}

.el.box {
    pointer-events: none;
}

.el.linkbtn,
.el.filebtn {
    overflow: hidden;
    cursor: pointer;
}

.el.linkbtn a, 
.el.filebtn a {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s ease;
    gap: 8px;
}

.el.linkbtn a:hover, 
.el.filebtn a:hover {
    opacity: 0.9;
    transform: scale(1.02);
}

.el.linkbtn a:active,
.el.filebtn a:active {
    transform: scale(0.98);
}

.el.langbadge { 
    background: transparent !important; 
    border: none !important; 
    padding: 0 !important; 
}

.lang-selector { 
    position: relative; 
    cursor: pointer; 
    display: inline-block; 
}

.lang-chip { 
    padding: 8px 16px; 
    border-radius: 12px; 
    border: 1px solid #2ea8ff; 
    background: #0f1723; 
    color: #fff; 
    transition: all 0.3s ease; 
    display: inline-flex; 
    align-items: center; 
    gap: 8px; 
}

.lang-chip:hover { 
    background: #2ea8ff; 
    transform: scale(1.05); 
}

.lang-flag { 
    font-size: 20px; 
    line-height: 1; 
}

.lang-dropdown { 
    position: absolute; 
    top: calc(100% + 8px); 
    left: 0; 
    display: none; 
    min-width: 220px; 
    max-height: 280px; 
    overflow-y: auto; 
    background: rgba(12, 18, 26, 0.96); 
    border: 1px solid rgba(46,168,255,0.25); 
    border-radius: 12px; 
    padding: 10px; 
    box-shadow: 0 8px 24px rgba(46,168,255,0.2); 
    backdrop-filter: blur(8px); 
    z-index: 9999; 
}

.lang-dropdown.show { 
    display: block !important; 
}

.lang-option { 
    display: flex; 
    align-items: center; 
    gap: 8px; 
    padding: 8px 10px; 
    border-radius: 8px; 
    text-decoration: none; 
    color: #e8f2ff; 
    transition: background .2s ease; 
}

.lang-option:hover { 
    background: rgba(46, 168, 255, 0.12); 
}

.lang-option.active { 
    background: #2ea8ff; 
    color: #fff; 
}

.el.embed {
    overflow: hidden;
}

.el.embed iframe {
    width: 100%;
    height: 100%;
    border: none;
    border-radius: inherit;
}

@media (max-width: 768px) and (min-width: 481px) {
    .wrap {
        min-height: 100vh;
    }
    
    .el.text {
        font-size: calc(100% - 2px) !important;
    }
}

@media (max-width: 480px) {
    .wrap {
        min-height: 100vh;
    }
    
    .el {
        transition: none !important;
    }
    
    .el.text {
        font-size: max(14px, calc(100% - 4px)) !important;
        line-height: 1.4 !important;
    }
    
    .el.langbadge .lang-chip {
        font-size: 14px !important;
        padding: 6px 12px !important;
    }
}

@keyframes fadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

.el {
    animation: fadeIn 0.5s ease-out;
}

@media print {
    .el.langbadge {
        display: none !important;
    }
    
    .wrap {
        min-height: auto;
    }
}
CSS;
    
    file_put_contents($exportDir . '/assets/style.css', $css);
    
    // JavaScript файл
    $js = <<<JS
(function() {
    'use strict';
    const DESKTOP_W = 1200, TABLET_W = 768, MOBILE_W = 375, EDITOR_H = 1500;
    
    function applyResponsive() {
        const width = window.innerWidth;
        const elements = document.querySelectorAll('.el[data-tablet], .el[data-mobile]');
        
        elements.forEach(el => {
            try {
                let styles = {};
                let baseW = DESKTOP_W;
                
                if (width <= 480 && el.dataset.mobile) {
                    styles = JSON.parse(el.dataset.mobile);
                    baseW = MOBILE_W;
                } else if (width <= 768 && width > 480 && el.dataset.tablet) {
                    styles = JSON.parse(el.dataset.tablet);
                } else {
                    if (el.dataset.originalStyle) {
                        el.setAttribute('style', el.dataset.originalStyle);
                    }
                    return;
                }
                
                if (!el.dataset.originalStyle) {
                    el.dataset.originalStyle = el.getAttribute('style');
                }
                
                if (styles.left !== undefined) el.style.left = styles.left + '%';
                if (styles.top !== undefined) { 
                    el.style.top = ((styles.top / baseW) * 100).toFixed(4) + 'vw'; 
                }
                if (styles.width !== undefined) el.style.width = styles.width + '%';
                if (styles.height !== undefined && el.dataset.type !== 'text') {
                    el.style.height = ((((styles.height / 100) * EDITOR_H) / baseW) * 100).toFixed(4) + 'vw';
                }
                if (styles.fontSize !== undefined) {
                    const textEl = el.querySelector('a, span, div');
                    if (textEl) textEl.style.fontSize = styles.fontSize + 'px';
                }
                if (styles.padding !== undefined) {
                    const padTarget = el.querySelector('a, span, div') || el;
                    padTarget.style.padding = styles.padding + 'px';
                }
                if (styles.radius !== undefined) {
                    el.style.borderRadius = styles.radius + 'px';
                    const rEl = el.querySelector('a');
                    if (rEl) rEl.style.borderRadius = styles.radius + 'px';
                }
                if (styles.rotate !== undefined) {
                    el.style.transform = 'rotate(' + styles.rotate + 'deg)';
                }
            } catch(e) {
                console.error('Error applying responsive styles:', e);
            }
        });
    }
    
    function adjustWrapHeight() {
        const wrap = document.querySelector('.wrap');
        if (!wrap) return;
        
        const elements = document.querySelectorAll('.el');
        let maxBottom = 0;
        
        elements.forEach(el => {
            const rect = el.getBoundingClientRect();
            const bottom = el.offsetTop + rect.height;
            if (bottom > maxBottom) {
                maxBottom = bottom;
            }
        });
        
        if (maxBottom > 0) {
            wrap.style.minHeight = Math.max(maxBottom + 100, window.innerHeight) + 'px';
        }
    }
    
    function initSmoothScroll() {
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                const href = this.getAttribute('href');
                if (href === '#') return;
                
                e.preventDefault();
                const target = document.querySelector(href);
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    }
    
    function initLazyLoad() {
        if ('IntersectionObserver' in window) {
            const imageObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        if (img.dataset.src) {
                            img.src = img.dataset.src;
                            img.removeAttribute('data-src');
                            observer.unobserve(img);
                        }
                    }
                });
            });
            
            document.querySelectorAll('img[data-src]').forEach(img => {
                imageObserver.observe(img);
            });
        }
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        applyResponsive();
        adjustWrapHeight();
        initSmoothScroll();
        initLazyLoad();
    });
    
    let resizeTimeout;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(function() {
            applyResponsive();
            adjustWrapHeight();
        }, 250);
    });
    
    window.addEventListener('orientationchange', function() {
        setTimeout(function() {
            applyResponsive();
            adjustWrapHeight();
        }, 100);
    });
})();
JS;
    
    file_put_contents($exportDir . '/assets/js/main.js', $js);
}

function copyUsedFiles($usedFiles, $exportDir) {
    foreach ($usedFiles as $file) {
        if (file_exists($file['source'])) {
            $destPath = $exportDir . '/' . $file['dest'];
            $destDir = dirname($destPath);
            
            if (!is_dir($destDir)) {
                @mkdir($destDir, 0777, true);
            }
            
            @copy($file['source'], $destPath);
        }
    }
}

function generateRobots($exportDir) {
    $robots = <<<ROBOTS
User-agent: *
Allow: /
Disallow: /assets/uploads/
Disallow: /api/

Sitemap: /sitemap.xml
ROBOTS;
    
    file_put_contents($exportDir . '/robots.txt', $robots);
}

function generateSitemap($exportDir, $pages, $languages) {
    $sitemap = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $sitemap .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
    
    foreach ($pages as $page) {
        foreach ($languages as $lang) {
            $filename = getPageFilename($page, $lang);
            $loc = '/' . str_replace('.html', '', $filename);
            
            $sitemap .= "  <url>\n";
            $sitemap .= "    <loc>{$loc}</loc>\n";
            
            foreach ($languages as $altLang) {
                $altFilename = getPageFilename($page, $altLang);
                $href = '/' . str_replace('.html', '', $altFilename);
                $sitemap .= "    <xhtml:link rel=\"alternate\" hreflang=\"{$altLang}\" href=\"{$href}\"/>\n";
            }
            
            $sitemap .= "    <changefreq>weekly</changefreq>\n";
            $priority = $page['is_home'] ? '1.0' : '0.8';
            if ($lang !== 'ru') {
                $priority = $page['is_home'] ? '0.9' : '0.7';
            }
            $sitemap .= "    <priority>{$priority}</priority>\n";
            $sitemap .= "  </url>\n";
        }
    }
    
    $sitemap .= '</urlset>';
    
    file_put_contents($exportDir . '/sitemap.xml', $sitemap);
}

function createZipArchive($sourceDir) {
    $zipFile = sys_get_temp_dir() . '/export_' . time() . '.zip';
    $zip = new ZipArchive();
    
    if ($zip->open($zipFile, ZipArchive::CREATE) !== TRUE) {
        throw new Exception('Cannot create ZIP archive');
    }
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    
    foreach ($iterator as $file) {
        $filePath = $file->getRealPath();
        $relativePath = substr($filePath, strlen($sourceDir) + 1);
        $relativePath = str_replace('\\', '/', $relativePath);
        $zip->addFile($filePath, $relativePath);
    }
    
    $zip->close();
    return $zipFile;
}

function deleteDirectory($dir) {
    if (!is_dir($dir)) return;
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($iterator as $file) {
        if ($file->isDir()) {
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }
    
    rmdir($dir);
}
?>