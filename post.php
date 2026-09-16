<?php
// 記事ページ /post/<slug>（templates/news_post.html を雛形に生成）
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
$slug = (string)($_GET['slug'] ?? '');
if (!preg_match('/^[\w-]{1,80}$/', $slug)) { http_response_code(404); echo 'Not found'; exit; }
$tpl = file_get_contents(__DIR__ . '/templates/news_post.html');
$tpl = str_replace('<head>', "<head>\n<base href=\"/\">", $tpl);
$p = row('SELECT * FROM posts WHERE slug=?', [$slug]);
header('Content-Type: text/html; charset=utf-8');
if (!$p) {
  http_response_code(404);
  echo strtr($tpl, ['{{TITLE}}' => '記事が見つかりません', '{{DATE}}' => '', '{{CAT}}' => 'NEWS', '{{DESC}}' => '', '{{HERO}}' => '', '{{BODY}}' => '<p>この記事は削除されたか、URLが間違っています。</p>']);
  exit;
}
$scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) ? 'https' : 'http';
$origin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$img = img_url($p['image']);
$hero = $img ? '<div class="article__hero" style="background-image:url(\'' . h($img) . '\');"></div>' : '';
$ogImg = $img ? (str_starts_with($img, 'http') ? $img : $origin . '/' . ltrim($img, '/')) : '';
$og = '<meta property="og:title" content="' . h($p['title']) . '">' . "\n" . '<meta property="og:type" content="article">' . "\n" . '<meta property="og:url" content="' . h($origin . '/post/' . $slug) . '">' . "\n" . ($ogImg ? '<meta property="og:image" content="' . h($ogImg) . '">' . "\n" : '') . '</head>';
$tpl = str_replace('</head>', $og, $tpl);
header('Cache-Control: public, max-age=60');
echo strtr($tpl, ['{{TITLE}}' => h($p['title']), '{{DATE}}' => h($p['date']), '{{CAT}}' => h($p['category']), '{{DESC}}' => h(excerpt($p['body'], 150)), '{{HERO}}' => $hero, '{{BODY}}' => md_to_html($p['body'])]);
