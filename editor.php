<!doctype html>
<html lang="ru"><head>
<meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Редактор — Zerro Blog</title>
<link rel="stylesheet" href="assets/editor.css?v=<?php echo date('YmdHis'); ?>"/>
<link rel="stylesheet" href="/editor/assets/resize-nav.css?v=20250903-232413">
<!-- +++ Ручка вертикального ресайза сцены -->
<link rel="stylesheet" href="/editor/assets/resize-stage.css?v=<?php echo date('YmdHis'); ?>">
    <link rel="stylesheet" href="/ui/modules/button-link/button-link.css?v=<?php echo date('YmdHis'); ?>" />
</head><body>
<div class="topbar">
  <button class="btn" id="btnAddText">Текст</button>
  <button class="btn" id="btnAddBox">Блок</button>
  <button class="btn" id="btnAddImage">Картинка</button>
  <button class="btn" id="btnAddVideo">Видео</button>
  <span class="sep"></span>
  <button class="btn" id="btnSave">Сохранить</button>
  <button class="btn" id="btnExport">Экспорт</button>
  <span class="sep"></span>
  <button class="btn ghost" data-device="desktop">Desktop</button>
  <button class="btn ghost" data-device="tablet">Tablet</button>
  <button class="btn ghost" data-device="mobile">Mobile</button>
  <label class="label" style="margin-left:8px"><input type="checkbox" id="snapChk"> Привязка к сетке</label>
  <span style="flex:1"></span>
  <a class="btn" href="/index.php" target="_blank">Открыть сайт</a>
</div>
<div id="seoBar" class="seo-bar"></div>

<div class="wrap">
  <div class="panel">
    <div class="label">Навигация по страницам</div>
    <div class="muted">Подсказка: двойной клик по названию — переименовать.</div>
    <div id="pages" class="pages" style="margin-top:8px"></div>
    <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
  <button id="btnNewPage" class="btn">+ Новая страница</button>
  <button id="btnPurgeHome" class="btn danger">Очистить главную</button>
</div>
  </div>

  <div class="panel">
    <div class="device-toolbar"></div>
    <div class="device-frame">
      <div id="stage" class="stage"></div>
    </div>
  </div>

  <div class="panel">
    <div id="props" class="props props-scroll" style="max-height:calc(100vh - 200px);overflow:auto;padding-right:6px"></div>
  </div>
</div>
<link rel="stylesheet" href="/ui/rte-mini/rte-mini.css?v=<?php echo date('YmdHis'); ?>" />
<script defer src="/ui/rte-mini/rte-mini.js?v=<?php echo date('YmdHis'); ?>"></script>

<script src="assets/editor.js?v=<?php echo date('YmdHis'); ?>"></script>
<!-- +++ Ручка вертикального ресайза сцены -->
<script src="/editor/assets/resize-stage.js?v=<?php echo date('YmdHis'); ?>"></script>
<script src="assets/nav_override.js?v=<?php echo date('YmdHis'); ?>"></script>

<script src="assets/seo_override.js?v=fix-20250903-9"></script>

<script src="seo-topbar.js?v=<?php echo date('YmdHis'); ?>"></script>

<style id="seo-topbar-override">#seoBar.seo-bar{display:block;margin:8px 12px 6px 12px;padding:0!important;background:transparent!important;border:0!important;box-shadow:none!important;border-radius:0!important}#seoBar .label{margin:0 0 4px;color:#9fb2c6}#seoBar .row>*{margin:0!important;padding:0!important}#seoBar .form-group,#seoBar .group,#seoBar .field{margin:0!important;border:0!important;background:transparent!important;box-shadow:none!important}#seoBar input,#seoBar textarea{width:100%;background:#0f1723;color:#ffffff;border:1px solid #2d4263;border-radius:10px;padding:10px}#seoBar input::placeholder,#seoBar textarea::placeholder{color:#9fb2c6;opacity:.85}#seoBar input:focus,#seoBar textarea:focus{outline:none;border-color:#3a78f2;box-shadow:0 0 0 2px rgba(58,120,242,.2)}#seoBar .seo-count{font-weight:600;padding-left:4px}#seoBar .seo-count.ok{color:#8ec07c}#seoBar .seo-count.over{color:#ff6464}#seoBar *:where(.hint,.recommendation){display:none!important}</style>
<script src="assets/resize-nav.js?v=20250903-230849"></script>
    <script defer src="/ui/modules/button-link/button-link.js?v=<?php echo date('YmdHis'); ?>"></script>

    <!-- Модуль замены ссылок -->

<link rel="stylesheet" href="/ui/link-replacer/link-replacer.css?v=<?php echo date('YmdHis'); ?>">
<script src="/ui/link-replacer/link-replacer.js?v=<?php echo date('YmdHis'); ?>"></script>
<!-- Модуль кнопка-файл -->
<link rel="stylesheet" href="/ui/button-file/button-file.css?v=<?php echo date('YmdHis'); ?>">
<script src="/ui/button-file/button-file.js?v=<?php echo date('YmdHis'); ?>"></script>
<script src="/ui/button-file/file-manager.js?v=<?php echo date('YmdHis'); ?>"></script>
<link rel="stylesheet" href="/ui/langs/langs.css?v=<?php echo date('YmdHis'); ?>">
<script src="/ui/langs/langs.js?v=<?php echo date('YmdHis'); ?>"></script>
<!-- Модуль переводов -->
<link rel="stylesheet" href="/ui/translations/translations.css?v=<?php echo date('YmdHis'); ?>">
<script src="/ui/translations/translations.js?v=<?php echo date('YmdHis'); ?>"></script>
<!-- Модуль Telegram уведомлений -->
<link rel="stylesheet" href="/ui/tg-notify/tg-notify.css?v=<?php echo date('YmdHis'); ?>">
<script src="/ui/tg-notify/tg-notify.js?v=<?php echo date('YmdHis'); ?>"></script>
<!-- Добавить перед </body> -->
<link rel="stylesheet" href="/editor/modules/remote-control/remote-control.css?v=<?php echo date('YmdHis'); ?>">
<script src="/editor/modules/remote-control/remote-control.js?v=<?php echo date('YmdHis'); ?>"></script>
</body></html>
