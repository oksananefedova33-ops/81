<?php
// Remote Control Endpoint
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-Token, X-Action, X-File-Url, X-Old-Url, X-New-Url, X-File-Name, X-Link-Url');
header('Content-Type: application/json; charset=utf-8');

$API_TOKEN = 'c4e1df60c31d4280fcaed8e17367a0aafb7bac0fa730f24b2a7cc6a7d6d1372b';

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
    // Обработка путей для файлов с редактора
    if (strpos($newUrl, 'assets/uploads/') === 0) {
        // Путь уже правильный для экспортированного сайта
    } elseif (strpos($newUrl, '/editor/uploads/') === 0) {
        // Преобразуем путь редактора в путь экспортированного сайта
        $fileName = basename($newUrl);
        $newUrl = 'assets/uploads/' . $fileName;
    }
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