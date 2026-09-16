<?php
// ローカル開発用ルーター（.htaccess の書き換えを php -S で再現）:  php -S 127.0.0.1:8790 _dev_router.php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = __DIR__;
if (preg_match('#^/post-([\w-]+)\.html$#', $uri, $m)) { header('Location: /post/' . $m[1], true, 301); exit; }
if (preg_match('#^/post/([\w-]+)/?$#', $uri, $m)) { $_GET['slug'] = $m[1]; require "$root/post.php"; return true; }
if (preg_match('#^/api/(.*)$#', $uri, $m)) { $_GET['r'] = $m[1]; require "$root/api.php"; return true; }
if ($uri === '/' || $uri === '/index.html') { $_GET['p'] = 'index'; require "$root/page.php"; return true; }
if (preg_match('#^/(news|company)\.html$#', $uri, $m)) { $_GET['p'] = $m[1]; require "$root/page.php"; return true; }
if ($uri === '/admin' || $uri === '/admin/') { readfile("$root/admin.html"); return true; }
if (is_file($root . $uri)) return false; // 静的ファイル
http_response_code(404); echo 'Not found';
