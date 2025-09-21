<?php
/**
 * Remote Control Module API
 * /editor/modules/remote-control/api.php
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// Подключаемся к БД
$db = dirname(dirname(dirname(__DIR__))) . '/data/zerro_blog.db';

try {
    $pdo = new PDO('sqlite:' . $db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(Exception $e) {
    die(json_encode(['ok' => false, 'error' => 'Database connection failed']));
}

// Определяем действие
$action = $_REQUEST['action'] ?? '';

// Обработка GET запросов
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    switch($action) {
        case 'getApiToken':
            echo json_encode(getApiToken($pdo));
            break;
            
        case 'getSites':
            echo json_encode(getSites($pdo));
            break;
            
        case 'getHistory':
            $limit = intval($_REQUEST['limit'] ?? 50);
            echo json_encode(getHistory($pdo, $limit));
            break;
            
        default:
            echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    }
    exit;
}

// Обработка POST запросов
switch($action) {
    case 'regenerateToken':
        echo json_encode(regenerateToken($pdo));
        break;
        
    case 'addSite':
        $domain = $_POST['domain'] ?? '';
        $name = $_POST['name'] ?? '';
        echo json_encode(addSite($pdo, $domain, $name));
        break;
        
    case 'deleteSite':
        $id = intval($_POST['id'] ?? 0);
        echo json_encode(deleteSite($pdo, $id));
        break;
        
    case 'checkConnection':
        $domain = $_POST['domain'] ?? '';
        echo json_encode(checkConnection($pdo, $domain));
        break;
        
    case 'searchFiles':
        $domains = json_decode($_POST['domains'] ?? '[]', true);
        $fileUrl = $_POST['fileUrl'] ?? '';
        echo json_encode(searchFiles($pdo, $domains, $fileUrl));
        break;
        
    case 'searchLinks':
        $domains = json_decode($_POST['domains'] ?? '[]', true);
        $linkUrl = $_POST['linkUrl'] ?? '';
        echo json_encode(searchLinks($pdo, $domains, $linkUrl));
        break;
        
    case 'replaceFile':
        $domains = json_decode($_POST['domains'] ?? '[]', true);
        $oldUrl = $_POST['oldUrl'] ?? '';
        $newUrl = $_POST['newUrl'] ?? '';
        $fileName = $_POST['fileName'] ?? '';
        echo json_encode(replaceFile($pdo, $domains, $oldUrl, $newUrl, $fileName));
        break;
        
    case 'replaceLink':
        $domains = json_decode($_POST['domains'] ?? '[]', true);
        $oldUrl = $_POST['oldUrl'] ?? '';
        $newUrl = $_POST['newUrl'] ?? '';
        echo json_encode(replaceLink($pdo, $domains, $oldUrl, $newUrl));
        break;
        
    case 'listFiles':
        $domains = json_decode($_POST['domains'] ?? '[]', true);
        echo json_encode(listFiles($pdo, $domains));
        break;
        
    case 'listLinks':
        $domains = json_decode($_POST['domains'] ?? '[]', true);
        echo json_encode(listLinks($pdo, $domains));
        break;
        
    case 'clearHistory':
        $days = intval($_POST['days'] ?? 30);
        echo json_encode(clearHistory($pdo, $days));
        break;
        
    case 'register':
        $domain = $_POST['domain'] ?? '';
        $token = $_POST['token'] ?? '';
        echo json_encode(registerSite($pdo, $domain, $token));
        break;
        
    default:
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}

// ========== ФУНКЦИИ ==========

function getApiToken($pdo) {
    $stmt = $pdo->query("SELECT value FROM remote_api_settings WHERE key='api_token'");
    $token = $stmt->fetchColumn();
    
    if (!$token) {
        $token = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT OR REPLACE INTO remote_api_settings (key, value) VALUES ('api_token', ?)")
            ->execute([$token]);
    }
    
    return ['ok' => true, 'token' => $token];
}

function regenerateToken($pdo) {
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT OR REPLACE INTO remote_api_settings (key, value) VALUES ('api_token', ?)")
        ->execute([$token]);
    
    // Обновляем токен на всех сайтах
    $pdo->prepare("UPDATE remote_sites SET api_token = ?")->execute([$token]);
    
    return ['ok' => true, 'token' => $token];
}

function getSites($pdo) {
    $stmt = $pdo->query("SELECT * FROM remote_sites ORDER BY created_at DESC");
    $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return ['ok' => true, 'sites' => $sites];
}

function addSite($pdo, $domain, $name) {
    if (empty($domain)) {
        return ['ok' => false, 'error' => 'Domain is required'];
    }
    
    // Очищаем домен от протокола и слешей
    $domain = preg_replace('~^https?://~', '', $domain);
    $domain = rtrim($domain, '/');
    
    // Получаем текущий API токен
    $stmt = $pdo->query("SELECT value FROM remote_api_settings WHERE key='api_token'");
    $token = $stmt->fetchColumn();
    
    if (!$token) {
        $tokenResult = getApiToken($pdo);
        $token = $tokenResult['token'];
    }
    
    try {
        $stmt = $pdo->prepare("INSERT INTO remote_sites (domain, site_name, api_token) VALUES (?, ?, ?)");
        $stmt->execute([$domain, $name ?: $domain, $token]);
        return ['ok' => true, 'id' => $pdo->lastInsertId()];
    } catch (Exception $e) {
        return ['ok' => false, 'error' => 'Site already exists or database error'];
    }
}

function deleteSite($pdo, $id) {
    $stmt = $pdo->prepare("DELETE FROM remote_sites WHERE id = ?");
    $stmt->execute([$id]);
    return ['ok' => true];
}

function checkConnection($pdo, $domain) {
    // Получаем токен для домена
    $stmt = $pdo->prepare("SELECT api_token FROM remote_sites WHERE domain = ?");
    $stmt->execute([$domain]);
    $token = $stmt->fetchColumn();
    
    if (!$token) {
        return ['ok' => false, 'error' => 'Site not found in database'];
    }
    
    // Проверяем соединение
    $url = 'https://' . $domain . '/api/remote-check.php';
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'X-API-Token: ' . $token,
            'X-Action: ping'
        ],
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if ($data && isset($data['status']) && $data['status'] === 'ok') {
            // Обновляем время последней проверки
            $stmt = $pdo->prepare("UPDATE remote_sites SET last_check = datetime('now'), status = 'active' WHERE domain = ?");
            $stmt->execute([$domain]);
            return ['ok' => true, 'message' => 'Connection successful'];
        }
    }
    
    return ['ok' => false, 'error' => 'Connection failed (HTTP ' . $httpCode . ')'];
}

function searchFiles($pdo, $domains, $fileUrl) {
    $results = [];
    
    foreach ($domains as $domain) {
        $result = sendRemoteCommand($pdo, $domain, 'search-file', [
            'X-File-Url' => $fileUrl
        ]);
        
        if ($result['ok']) {
            $results[$domain] = $result['data']['found'] ?? [];
        } else {
            $results[$domain] = ['error' => $result['error']];
        }
    }
    
    return ['ok' => true, 'results' => $results];
}

function searchLinks($pdo, $domains, $linkUrl) {
    $results = [];
    
    foreach ($domains as $domain) {
        $result = sendRemoteCommand($pdo, $domain, 'search-link', [
            'X-Link-Url' => $linkUrl
        ]);
        
        if ($result['ok']) {
            $results[$domain] = $result['data']['found'] ?? [];
        } else {
            $results[$domain] = ['error' => $result['error']];
        }
    }
    
    return ['ok' => true, 'results' => $results];
}

function listFiles($pdo, $domains) {
    $results = [];
    
    foreach ($domains as $domain) {
        $result = sendRemoteCommand($pdo, $domain, 'list-files', []);
        
        if ($result['ok']) {
            $results[$domain] = $result['data']['files'] ?? [];
        } else {
            $results[$domain] = ['error' => $result['error']];
        }
    }
    
    return ['ok' => true, 'results' => $results];
}

function listLinks($pdo, $domains) {
    $results = [];
    
    foreach ($domains as $domain) {
        $result = sendRemoteCommand($pdo, $domain, 'list-links', []);
        
        if ($result['ok']) {
            $results[$domain] = $result['data']['links'] ?? [];
        } else {
            $results[$domain] = ['error' => $result['error']];
        }
    }
    
    return ['ok' => true, 'results' => $results];
}

function replaceFile($pdo, $domains, $oldUrl, $newUrl, $fileName) {
    $results = [];
    
    foreach ($domains as $domain) {
        $result = sendRemoteCommand($pdo, $domain, 'replace-file', [
            'X-Old-Url' => $oldUrl,
            'X-New-Url' => $newUrl,
            'X-File-Name' => $fileName
        ]);
        
        if ($result['ok']) {
            $replaced = $result['data']['replaced'] ?? 0;
            $results[$domain] = [
                'success' => true,
                'message' => 'Replaced in ' . $replaced . ' buttons',
                'replaced' => $replaced
            ];
            
            // Записываем в историю
            logChange($pdo, $domain, 'file', $oldUrl, $newUrl, 'success');
        } else {
            $results[$domain] = [
                'success' => false,
                'message' => $result['error']
            ];
            
            // Записываем ошибку в историю
            logChange($pdo, $domain, 'file', $oldUrl, $newUrl, 'failed', $result['error']);
        }
    }
    
    return ['ok' => true, 'results' => $results];
}

function replaceLink($pdo, $domains, $oldUrl, $newUrl) {
    $results = [];
    
    foreach ($domains as $domain) {
        $result = sendRemoteCommand($pdo, $domain, 'replace-link', [
            'X-Old-Url' => $oldUrl,
            'X-New-Url' => $newUrl
        ]);
        
        if ($result['ok']) {
            $replaced = $result['data']['replaced'] ?? 0;
            $results[$domain] = [
                'success' => true,
                'message' => 'Replaced in ' . $replaced . ' buttons',
                'replaced' => $replaced
            ];
            
            // Записываем в историю
            logChange($pdo, $domain, 'link', $oldUrl, $newUrl, 'success');
        } else {
            $results[$domain] = [
                'success' => false,
                'message' => $result['error']
            ];
            
            // Записываем ошибку в историю
            logChange($pdo, $domain, 'link', $oldUrl, $newUrl, 'failed', $result['error']);
        }
    }
    
    return ['ok' => true, 'results' => $results];
}

function sendRemoteCommand($pdo, $domain, $action, $headers = []) {
    // Получаем токен для домена
    $stmt = $pdo->prepare("SELECT api_token FROM remote_sites WHERE domain = ?");
    $stmt->execute([$domain]);
    $token = $stmt->fetchColumn();
    
    if (!$token) {
        return ['ok' => false, 'error' => 'Site not found'];
    }
    
    // Отправляем команду
    $url = 'https://' . $domain . '/api/remote-check.php';
    
    $requestHeaders = [
        'X-API-Token: ' . $token,
        'X-Action: ' . $action
    ];
    
    foreach ($headers as $key => $value) {
        $requestHeaders[] = $key . ': ' . $value;
    }
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if ($data) {
            return ['ok' => true, 'data' => $data];
        }
    }
    
    return ['ok' => false, 'error' => $error ?: 'HTTP ' . $httpCode];
}

function logChange($pdo, $domain, $type, $oldValue, $newValue, $status, $error = null) {
    $stmt = $pdo->prepare("
        INSERT INTO remote_changes_log (domain, change_type, old_value, new_value, status, error_message, created_at)
        VALUES (?, ?, ?, ?, ?, ?, datetime('now'))
    ");
    $stmt->execute([$domain, $type, $oldValue, $newValue, $status, $error]);
}

function getHistory($pdo, $limit = 50) {
    $stmt = $pdo->prepare("
        SELECT * FROM remote_changes_log 
        ORDER BY created_at DESC 
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return ['ok' => true, 'history' => $history];
}

function clearHistory($pdo, $days) {
    $stmt = $pdo->prepare("
        DELETE FROM remote_changes_log 
        WHERE created_at < datetime('now', '-' || ? || ' days')
    ");
    $stmt->execute([$days]);
    
    return ['ok' => true, 'deleted' => $stmt->rowCount()];
}

function registerSite($pdo, $domain, $token) {
    // Авторегистрация сайта при первом подключении
    try {
        $stmt = $pdo->prepare("
            INSERT OR IGNORE INTO remote_sites (domain, api_token, site_name, last_check, status)
            VALUES (?, ?, ?, datetime('now'), 'active')
        ");
        $stmt->execute([$domain, $token, $domain]);
        return ['ok' => true];
    } catch (Exception $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
?>