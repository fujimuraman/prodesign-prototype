<?php
// /feed.xml（.htaccess から書き換え）: 新着記事の RSS 2.0。旧サイトの /feed/ はここへ 301。
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
$u = site_url();
$posts = [];
try { $posts = array_slice(all_posts(), 0, 20); } catch (Throwable $e) { error_log('feed: ' . $e->getMessage()); }
$x = fn(?string $s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$rfc = fn(int $t) => date(DATE_RSS, $t);
$latest = time();
if ($posts) { $latest = 0; foreach ($posts as $p) $latest = max($latest, post_modified_ts($p)); }
header('Content-Type: application/rss+xml; charset=utf-8'); header('Cache-Control: public, max-age=3600'); header('X-Robots-Tag: noindex');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n<channel>\n";
echo '  <title>' . $x('株式会社プロデザイン（ProDesign）ニュース') . "</title>\n";
echo '  <link>' . $x($u . '/news.html') . "</link>\n";
echo '  <description>' . $x('株式会社プロデザイン（秋田県横手市）のニュース。完全オーダーメイド自動機・装置の納入実績、専門家支援の活動報告、お知らせ。') . "</description>\n";
echo "  <language>ja</language>\n";
echo '  <lastBuildDate>' . $rfc($latest) . "</lastBuildDate>\n";
echo '  <atom:link href="' . $x($u . '/feed.xml') . '" rel="self" type="application/rss+xml" />' . "\n";
foreach ($posts as $p) {
  $link = $u . '/post/' . $p['slug'];
  echo "  <item>\n";
  echo '    <title>' . $x($p['title']) . "</title>\n";
  echo '    <link>' . $x($link) . "</link>\n";
  echo '    <guid isPermaLink="true">' . $x($link) . "</guid>\n";
  echo '    <pubDate>' . $rfc((int)strtotime(post_date_iso($p))) . "</pubDate>\n";
  echo '    <category>' . $x($p['category']) . "</category>\n";
  echo '    <description>' . $x(excerpt($p['body'], 200)) . "</description>\n";
  echo "  </item>\n";
}
echo "</channel>\n</rss>\n";
