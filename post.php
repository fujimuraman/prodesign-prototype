<?php
// 記事ページ /post/<slug>（templates/news_post.html を雛形に生成）。記事ごとの title / description / canonical / OGP / 構造化データを出す。
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

/** @return array{0:int,1:string} [ステータス, HTML] */
function render_post(string $slug, bool $preview = false): array {
  $tpl = file_get_contents(__DIR__ . '/templates/news_post.html');
  $tpl = str_replace('<head>', "<head>\n<base href=\"/\">", $tpl);
  $p = preg_match('/^[\w-]{1,80}$/', $slug) ? row('SELECT * FROM posts WHERE slug=?', [$slug]) : null;
  if (!$p) {
    $tpl = seo_apply($tpl, seo_head('notfound'));
    return [404, strtr($tpl, ['{{TITLE}}' => '記事が見つかりません', '{{DATE}}' => '', '{{DATE_ISO}}' => '', '{{CAT}}' => 'NEWS', '{{HERO}}' => '', '{{BODY}}' => '<p>この記事は削除されたか、URLが間違っています。</p>'])];
  }
  $img = img_url($p['image']);
  $hero = $img ? '<div class="article__hero" role="img" aria-label="' . h($p['title']) . '" style="background-image:url(\'' . h($img) . '\');"></div>' : '';
  $tpl = seo_apply($tpl, seo_head('post', ['post' => $p], $preview));
  return [200, strtr($tpl, ['{{TITLE}}' => h($p['title']), '{{DATE}}' => h($p['date']), '{{DATE_ISO}}' => h(substr(post_date_iso($p), 0, 10)), '{{CAT}}' => h($p['category']), '{{HERO}}' => $hero, '{{BODY}}' => md_to_html($p['body'])])];
}

if (!defined('PD_NO_OUTPUT')) {
  [$status, $html] = render_post((string)($_GET['slug'] ?? ''), false);
  http_response_code($status);
  header('Content-Type: text/html; charset=utf-8');
  header($status === 200 ? 'Cache-Control: public, max-age=60' : 'Cache-Control: no-store');
  echo $html;
}
