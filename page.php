<?php
// 静的HTML（index / business / works / company / news / contact）を出力する（.htaccess から page.php?p=... に書き換え）。
//  - <head> の SEO ブロック（title / description / canonical / OGP / 構造化データ）を本番用に差し替える（noindex は絶対に出さない）
//  - index / news / company には DB の内容（ニュース・沿革）を差し込む
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

function render_page(string $p, bool $preview = false): ?string {
  $file = __DIR__ . "/$p.html";
  if (!isset(seo_pages()[$p]) || !is_file($file)) return null;
  $html = file_get_contents($file);
  $esc = fn(string $s) => str_replace(['\\', '$'], ['\\\\', '\\$'], $s);
  try {
    if ($p === 'news') {
      $list = all_posts();
      $inner = $list ? implode("\n", array_map('news_card', $list)) : '<p class="hint" style="padding:40px 0;color:#888">お知らせはまだありません。</p>';
      $html = preg_replace('/(<div class="news-grid">)[\s\S]*?(\r?\n    <\/div>\r?\n  <\/div>\r?\n<\/main>)/', '$1' . "\n" . $esc($inner) . '$2', $html, 1);
    } elseif ($p === 'index') {
      $items = implode("\n", array_map(fn($q) => '      <li class="news__item">' . "\n" . '        <time class="news__date" datetime="' . h(substr(post_date_iso($q), 0, 10)) . '">' . h($q['date']) . '</time>' . "\n" . '        <span class="news__cat">' . h($q['category']) . '</span>' . "\n" . '        <a href="/post/' . h($q['slug']) . '" class="news__link">' . h($q['title']) . '</a>' . "\n      </li>", array_slice(all_posts(), 0, 4)));
      $html = preg_replace('/(<ul class="news__list">)[\s\S]*?(\r?\n    <\/ul>)/', '$1' . "\n" . $esc($items) . '$2', $html, 1);
    } elseif ($p === 'company') {
      $rowsH = rows('SELECT year,wareki,text FROM history ORDER BY sort, id');
      $hist = '      <div class="history">' . "\n" . implode("\n", array_map(fn($r) => '        <div class="history__item"><div class="history__year">' . h($r['year']) . '<small>' . h($r['wareki']) . '</small></div><div class="history__body">' . h($r['text']) . '</div></div>', $rowsH)) . "\n      </div>\n";
      $html = preg_replace('/(<!-- HISTORY:START[^\n]*-->\r?\n)[\s\S]*?(      <!-- HISTORY:END -->)/', '$1' . $esc($hist) . '$2', $html, 1);
    }
  } catch (Throwable $e) { error_log('page inject error: ' . $e->getMessage()); }
  if (!$preview) $html = seo_strip_noindex($html);
  return seo_apply($html, seo_head($p, [], $preview));
}

// 直接呼ばれた時だけ出力する（tools/export_static.php からは render_page() を使う）
if (!defined('PD_NO_OUTPUT')) {
  $p = preg_replace('/[^a-z]/', '', (string)($_GET['p'] ?? 'index')) ?: 'index';
  $html = render_page($p, false);
  if ($html === null) { require_once __DIR__ . '/legacy.php'; legacy_not_found(404); exit; }
  header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: public, max-age=60');
  echo $html;
}
