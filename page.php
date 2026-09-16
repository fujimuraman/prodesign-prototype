<?php
// 静的HTML（index / news / company）にDBの内容を差し込んで出力（.htaccess から page.php?p=... に書き換え）
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
$p = preg_replace('/[^a-z]/', '', (string)($_GET['p'] ?? 'index')) ?: 'index';
$file = __DIR__ . "/$p.html";
if (!in_array($p, ['index', 'news', 'company'], true) || !is_file($file)) { http_response_code(404); echo 'Not found'; exit; }
$html = file_get_contents($file);
try {
  if ($p === 'news') {
    $list = all_posts();
    $inner = $list ? implode("\n", array_map('news_card', $list)) : '<p class="hint" style="padding:40px 0;color:#888">お知らせはまだありません。</p>';
    $html = preg_replace('/(<div class="news-grid">)[\s\S]*?(\r?\n    <\/div>\r?\n  <\/div>\r?\n<\/main>)/', '$1' . "\n" . str_replace(['\\', '$'], ['\\\\', '\\$'], $inner) . '$2', $html, 1);
  } elseif ($p === 'index') {
    $items = implode("\n", array_map(fn($q) => '      <li class="news__item">' . "\n" . '        <time class="news__date">' . h($q['date']) . '</time>' . "\n" . '        <span class="news__cat">' . h($q['category']) . '</span>' . "\n" . '        <a href="/post/' . h($q['slug']) . '" class="news__link">' . h($q['title']) . '</a>' . "\n      </li>", array_slice(all_posts(), 0, 4)));
    $html = preg_replace('/(<ul class="news__list">)[\s\S]*?(\r?\n    <\/ul>)/', '$1' . "\n" . str_replace(['\\', '$'], ['\\\\', '\\$'], $items) . '$2', $html, 1);
  } else {
    $rowsH = rows('SELECT year,wareki,text FROM history ORDER BY sort, id');
    $hist = '      <div class="history">' . "\n" . implode("\n", array_map(fn($r) => '        <div class="history__item"><div class="history__year">' . h($r['year']) . '<small>' . h($r['wareki']) . '</small></div><div class="history__body">' . h($r['text']) . '</div></div>', $rowsH)) . "\n      </div>\n";
    $html = preg_replace('/(<!-- HISTORY:START[^\n]*-->\r?\n)[\s\S]*?(      <!-- HISTORY:END -->)/', '$1' . str_replace(['\\', '$'], ['\\\\', '\\$'], $hist) . '$2', $html, 1);
  }
} catch (Throwable $e) { error_log('page inject error: ' . $e->getMessage()); }
header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: public, max-age=60');
echo $html;
