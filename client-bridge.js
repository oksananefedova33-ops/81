// Этот код будет встроен в экспортированные страницы
(function(){
    'use strict';
    
    const API_TOKEN = '{{API_TOKEN}}'; // Будет заменено при экспорте
    const EDITOR_DOMAIN = '{{EDITOR_DOMAIN}}'; // Домен редактора
    
    // Обработчик запросов от редактора
    function handleRemoteCommand(event) {
        // Проверяем источник
        if (event.origin !== EDITOR_DOMAIN) return;
        
        const data = event.data;
        if (!data || data.token !== API_TOKEN) return;
        
        switch(data.action) {
            case 'ping':
                respondToEditor({ success: true, message: 'pong' });
                break;
                
            case 'search-file':
                searchFile(data.fileUrl);
                break;
                
            case 'replace-file':
                replaceFile(data.oldUrl, data.newUrl, data.fileName);
                break;
                
            case 'search-link':
                searchLink(data.linkUrl);
                break;
                
            case 'replace-link':
                replaceLink(data.oldUrl, data.newUrl);
                break;
        }
    }
    
    function searchFile(fileUrl) {
        const found = [];
        
        // Ищем все кнопки-файлы
        document.querySelectorAll('.el.filebtn a, .el.Filebtn a').forEach(btn => {
            if (btn.href.includes(fileUrl)) {
                found.push({
                    page: window.location.pathname,
                    element: btn.closest('.el').id
                });
            }
        });
        
        respondToEditor({ 
            action: 'search-file-result',
            found: found 
        });
    }
    
    function replaceFile(oldUrl, newUrl, fileName) {
        let replaced = 0;
        
        document.querySelectorAll('.el.filebtn a, .el.Filebtn a').forEach(btn => {
            if (btn.href.includes(oldUrl)) {
                btn.href = newUrl;
                btn.download = fileName;
                // Обновляем текст если нужно
                if (fileName && btn.textContent.includes(oldUrl.split('/').pop())) {
                    btn.textContent = btn.textContent.replace(oldUrl.split('/').pop(), fileName);
                }
                replaced++;
            }
        });
        
        respondToEditor({ 
            action: 'replace-file-result',
            replaced: replaced,
            success: true 
        });
    }
    
    function searchLink(linkUrl) {
        const found = [];
        
        document.querySelectorAll('.el.linkbtn a, .el.Linkbtn a').forEach(btn => {
            if (btn.href === linkUrl) {
                found.push({
                    page: window.location.pathname,
                    element: btn.closest('.el').id,
                    text: btn.textContent
                });
            }
        });
        
        respondToEditor({ 
            action: 'search-link-result',
            found: found 
        });
    }
    
    function replaceLink(oldUrl, newUrl) {
        let replaced = 0;
        
        document.querySelectorAll('.el.linkbtn a, .el.Linkbtn a').forEach(btn => {
            if (btn.href === oldUrl) {
                btn.href = newUrl;
                replaced++;
            }
        });
        
        respondToEditor({ 
            action: 'replace-link-result',
            replaced: replaced,
            success: true 
        });
    }
    
    function respondToEditor(data) {
        window.parent.postMessage({
            ...data,
            token: API_TOKEN
        }, EDITOR_DOMAIN);
    }
    
    // Альтернативный метод через HTTP заголовки для не-iframe сценариев
    async function handleHttpRequest() {
        const token = getMeta('x-api-token');
        const action = getMeta('x-action');
        
        if (!token || token !== API_TOKEN) return;
        
        const response = {
            timestamp: new Date().toISOString()
        };
        
        switch(action) {
            case 'ping':
                response.status = 'ok';
                break;
                
            case 'search-file':
                const fileUrl = getMeta('x-file-url');
                response.found = searchFileLocal(fileUrl);
                break;
                
            case 'replace-file':
                const oldFile = getMeta('x-old-url');
                const newFile = getMeta('x-new-url');
                const fileName = getMeta('x-file-name');
                response.replaced = replaceFileLocal(oldFile, newFile, fileName);
                break;
        }
        
        // Отправляем ответ через специальный endpoint
        sendBeacon('/api/remote-response', JSON.stringify(response));
    }
    
    function getMeta(name) {
        const meta = document.querySelector(`meta[name="${name}"]`);
        return meta ? meta.content : null;
    }
    
    function searchFileLocal(fileUrl) {
        const found = [];
        document.querySelectorAll('.el.filebtn a').forEach(btn => {
            if (btn.href.includes(fileUrl)) {
                found.push(window.location.pathname);
            }
        });
        return found;
    }
    
    function replaceFileLocal(oldUrl, newUrl, fileName) {
        let count = 0;
        document.querySelectorAll('.el.filebtn a').forEach(btn => {
            if (btn.href.includes(oldUrl)) {
                btn.href = newUrl;
                btn.download = fileName;
                count++;
            }
        });
        return count;
    }
    
    function sendBeacon(url, data) {
        if (navigator.sendBeacon) {
            navigator.sendBeacon(url, data);
        } else {
            fetch(url, {
                method: 'POST',
                body: data,
                keepalive: true
            });
        }
    }
    
    // Слушаем команды
    window.addEventListener('message', handleRemoteCommand);
    
    // Проверяем HTTP заголовки при загрузке
    document.addEventListener('DOMContentLoaded', handleHttpRequest);
})();