<?php
// ローカル開発用ルーター（.htaccess の書き換えを php -S で再現）:  php -S 127.0.0.1:8790 _dev_router.php
// .htaccess を変えたら、ここも同じ順番・同じ内容に揃えること（tools/test_redirects.py がこのルーターで全旧URLを検証する）
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$qs = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
$root = __DIR__;

// 0) 正規ホストへ統一（http→https、www あり→なし）。本番ドメインの Host で来た時だけ（テスト用。ローカルの 127.0.0.1 では素通し）
$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$https = (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || !empty($_SERVER['HTTP_X_SAKURA_FORWARDED_FOR']) || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
if ($host === 'www.prodesign.co.jp' || ($host === 'prodesign.co.jp' && !$https)) {
  header('Location: https://prodesign.co.jp' . $_SERVER['REQUEST_URI'], true, 301); exit;
}
// 1) /index.html・/index.php → /
if (preg_match('#^/index\.(html|php)$#i', $uri)) { header('Location: /' . ($qs !== '' ? '?' . $qs : ''), true, 301); exit; }
// 2) 旧サイトのクエリ形式（/?p=878 など）
if ($uri === '/' && preg_match('/(^|&)(p|page_id|cat|s|m|author|feed|attachment_id|paged|tag|category_name)=/i', $qs)) { require "$root/legacy.php"; legacy_handle(); return true; }
// 3) 試作時のURL: /post-xxx.html → /post/xxx
if (preg_match('#^/post-([\w-]+)\.html$#', $uri, $m)) { header('Location: /post/' . $m[1], true, 301); exit; }
// 4) 記事ページ（末尾スラッシュ付きは legacy.php が正規形へ 301）
if (preg_match('#^/post/([\w-]+)$#', $uri, $m)) { $_GET['slug'] = $m[1]; require "$root/post.php"; return true; }
// 5) API
if (preg_match('#^/api/(.*)$#', $uri, $m)) { $_GET['r'] = $m[1]; require "$root/api.php"; return true; }
// 6) サイトマップ・フィード
if ($uri === '/sitemap.xml') { require "$root/sitemap.php"; return true; }
if ($uri === '/feed.xml') { require "$root/feed.php"; return true; }
// 7) 各ページ（head の差し替え＋DBの内容の差し込み）
if ($uri === '/') { $_GET['p'] = 'index'; require "$root/page.php"; return true; }
if (preg_match('#^/(business|works|company|news|contact)\.html$#', $uri, $m)) { $_GET['p'] = $m[1]; require "$root/page.php"; return true; }
// 8) 更新ページ
if ($uri === '/admin' || $uri === '/admin/') { readfile("$root/admin.html"); return true; }
// 9) 旧サイトの /news/ 以下・/post/ 以下（実在フォルダと名前が重なるので先に legacy.php へ）
if (preg_match('#^/(news|post)(/.*)?$#', $uri)) { require "$root/legacy.php"; legacy_handle(); return true; }
// 10) 直接見せないファイル（.htaccess の FilesMatch と同じ）
if (preg_match('#(^/(config(\.local)?|lib|seo|legacy_map)\.php$|\.(md|py|sql|sqlite|log|toml)$|^/(data|templates|data_seed|\.git)/)#', $uri)) { require "$root/legacy.php"; legacy_not_found(404); return true; }
// 11) 実在する静的ファイル
if ($uri !== '/' && is_file($root . $uri)) return false;
// 12) それ以外は旧URLの転送表を引く。無ければ 404 ページ
require "$root/legacy.php"; legacy_handle(); return true;
