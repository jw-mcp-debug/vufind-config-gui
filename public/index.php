<?php

/**
 * vufind-config-gui – entry point (page and JSON API).
 *
 * Copyright (C) 2026 The vufind-config-gui contributors
 * License: GNU GPL v2 (see LICENSE)
 */

declare(strict_types=1);

use VuFindConfigGui\Api;
use VuFindConfigGui\HttpException;
use VuFindConfigGui\I18n;
use VuFindConfigGui\Security;
use VuFindConfigGui\Settings;
use VuFindConfigGui\Theme;

require dirname(__DIR__) . '/src/autoload.php';

$isApi = isset($_GET['api']);

try {
    $settings = Settings::load();
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "vufind-config-gui: configuration error\n\n" . $e->getMessage() . "\n";
    exit;
}

$lang = I18n::negotiate($settings->language, $_GET['lang'] ?? null, $_COOKIE[I18n::COOKIE] ?? null,
    (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
$i18n = new I18n($lang);
$security = new Security($settings, $_SERVER);
$security->sendHeaders($isApi);

if (!$security->hostAllowed()) {
    http_response_code(421);
    header('Content-Type: text/plain; charset=utf-8');
    echo $i18n->t('error.host_not_allowed', ['host' => (string)($_SERVER['HTTP_HOST'] ?? '')]) . "\n";
    exit;
}
if (!$security->authenticated()) {
    header('WWW-Authenticate: Basic realm="vufind-config-gui", charset="UTF-8"');
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo $i18n->t('error.auth_required') . "\n";
    exit;
}

if ($isApi) {
    header('Content-Type: application/json; charset=utf-8');
    $json = static fn (array $data) => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    try {
        if (!$security->apiRequestAllowed()) {
            throw new HttpException('error.forbidden_request', [], 403);
        }
        $inst = $settings->instance($_GET['inst'] ?? null);
        $body = [];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $body = json_decode(file_get_contents('php://input') ?: '{}', true);
            if (!is_array($body)) {
                throw new HttpException('error.bad_json');
            }
        }
        echo $json((new Api($settings, $inst, $i18n))->handle((string)$_GET['api'], $_GET, $body));
    } catch (HttpException $e) {
        http_response_code($e->getCode() ?: 400);
        echo $json(['error' => $i18n->t($e->getMessage(), $e->params)]);
    } catch (\Throwable $e) {
        http_response_code(500);
        error_log('vufind-config-gui: ' . $e);
        echo $json(['error' => $i18n->t('error.internal', ['message' => $e->getMessage()])]);
    }
    exit;
}

$h = static fn (string $s) => htmlspecialchars($s, ENT_QUOTES);
$t = static fn (string $key) => $h($i18n->t($key));
$langData = json_encode(['lang' => $lang, 'strings' => $i18n->all(), 'languages' => I18n::LANGUAGES],
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$theme = Theme::fromCookie($_COOKIE[Theme::COOKIE] ?? null);
$v = static fn (string $file) => $h($file) . '?v=' . (@filemtime(__DIR__ . '/assets/' . $file) ?: 0);
?><!doctype html>
<html lang="<?= $h($lang) ?>"<?= $theme !== Theme::AUTO ? ' data-theme="' . $h($theme) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $t('app.title') ?></title>
<link rel="stylesheet" href="assets/<?= $v('app.css') ?>">
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<script type="application/json" id="i18n-data"><?= $langData ?></script>
</head>
<body>
<header class="top">
  <div class="brand"><?= $t('app.brand') ?> <span><?= $t('app.brand_sub') ?></span></div>
  <select id="instance" class="instance" aria-label="<?= $t('header.instance') ?>"></select>
  <form id="testsearch" class="testsearch" autocomplete="off">
    <input id="tq" type="search" placeholder="<?= $t('header.testsearch') ?>" aria-label="<?= $t('header.testsearch_label') ?>">
    <select id="ttype" aria-label="<?= $t('header.searchtype') ?>">
      <option value="AllFields"><?= $t('searchtype.AllFields') ?></option><option value="Title"><?= $t('searchtype.Title') ?></option>
      <option value="Author"><?= $t('searchtype.Author') ?></option><option value="Subject"><?= $t('searchtype.Subject') ?></option>
    </select>
    <button type="submit"><?= $t('header.open') ?></button>
  </form>
  <div class="gsearch">
    <input id="gq" type="search" placeholder="<?= $t('header.globalsearch') ?>" aria-label="<?= $t('header.globalsearch_label') ?>" autocomplete="off">
    <div id="gresults" class="gresults" hidden></div>
  </div>
  <button id="clearcache" class="ghost" title="<?= $t('header.clearcache_title') ?>"><?= $t('header.clearcache') ?></button>
  <div class="settings">
    <button id="settingsbtn" class="ghost icon" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="settingsmenu" title="<?= $t('prefs.title') ?>" aria-label="<?= $t('prefs.title') ?>">
      <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
    </button>
    <div id="settingsmenu" class="settingsmenu" hidden>
      <fieldset class="themepick">
        <legend><?= $t('prefs.theme') ?></legend>
<?php foreach (Theme::CHOICES as $choice): ?>
        <label><input type="radio" name="theme" value="<?= $h($choice) ?>"<?= $choice === $theme ? ' checked' : '' ?>> <?= $t('prefs.theme_' . $choice) ?></label>
<?php endforeach; ?>
      </fieldset>
      <label class="langpick"><span><?= $t('header.language') ?></span>
        <select id="lang" class="lang">
<?php foreach (I18n::LANGUAGES as $code => $name): ?>
          <option value="<?= $h($code) ?>"<?= $code === $lang ? ' selected' : '' ?>><?= $h($name) ?></option>
<?php endforeach; ?>
        </select>
      </label>
    </div>
  </div>
</header>
<div class="layout">
  <nav id="files" class="files" aria-label="<?= $t('nav.files') ?>"></nav>
  <main id="main" class="main"><p class="empty"><?= $t('main.pick_file') ?></p></main>
</div>
<div id="toast" class="toast" role="status" aria-live="polite"></div>
<script src="assets/<?= $v('i18n.js') ?>"></script>
<script src="assets/<?= $v('app.js') ?>"></script>
<script src="assets/<?= $v('mcp.js') ?>"></script>
<script src="assets/<?= $v('ranking.js') ?>"></script>
</body>
</html>
