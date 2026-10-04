<?php
// /sitemap.xml（.htaccess から書き換え）: 固定ページ＋DBの全記事を lastmod 付きで出力
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
$u = site_url();
$posts = [];
try { $posts = all_posts(); } catch (Throwable $e) { error_log('sitemap: ' . $e->getMessage()); }
$latest = 0; foreach ($posts as $p) $latest = max($latest, post_modified_ts($p));
$mt = fn(string $f) => (int)@filemtime(__DIR__ . '/' . $f);
$iso = fn(int $t) => date('Y-m-d\TH:i:sP', $t ?: time());
$urls = [
  [$u . '/',              max($mt('index.html'), $latest)],
  [$u . '/business.html', $mt('business.html')],
  [$u . '/works.html',    $mt('works.html')],
  [$u . '/company.html',  $mt('company.html')],
  [$u . '/news.html',     max($mt('news.html'), $latest)],
  [$u . '/contact.html',  $mt('contact.html')],
];
foreach ($posts as $p) $urls[] = [$u . '/post/' . $p['slug'], post_modified_ts($p)];
header('Content-Type: application/xml; charset=utf-8'); header('Cache-Control: public, max-age=3600'); header('X-Robots-Tag: noindex');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as [$loc, $t]) echo '  <url><loc>' . h($loc) . '</loc><lastmod>' . $iso((int)$t) . '</lastmod></url>' . "\n";
echo '</urlset>' . "\n";
