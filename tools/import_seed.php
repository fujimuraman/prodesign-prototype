<?php
// 初回移行: news/*.md と data_seed/history.json（旧 data/history.json）を SQLite に取り込む
// 使い方:  php tools/import_seed.php   （記事が0件の時だけ取り込む。--force で上書き）
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib.php';
$root = dirname(__DIR__);
$force = in_array('--force', $argv ?? [], true);
$n = (int)row('SELECT COUNT(*) AS n FROM posts')['n'];
if ($n > 0 && !$force) { echo "posts already exist ($n). use --force to overwrite\n"; exit(0); }
$pdo = db(); $pdo->beginTransaction();
$pdo->exec('DELETE FROM posts'); $pdo->exec('DELETE FROM history');
$t = time(); $cnt = 0;
foreach (glob("$root/news/*.md") as $f) {
  $text = file_get_contents($f); $meta = []; $body = $text;
  $text = str_replace("\r\n", "\n", $text);
  if (str_starts_with($text, "\xEF\xBB\xBF")) $text = substr($text, 3);
  if (preg_match('/^---\s*\n(.*?)\n---\s*\n?(.*)$/s', $text, $m)) {
    foreach (explode("\n", $m[1]) as $line) { if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $meta[strtolower(trim($k))] = trim($v); } }
    $body = trim($m[2]);
  }
  $slug = pathinfo($f, PATHINFO_FILENAME);
  $date = str_replace('-', '.', $meta['date'] ?? substr($slug, 0, 10));
  $img = $meta['image'] ?? '';
  if ($img !== '' && !str_starts_with($img, 'http') && !str_starts_with($img, 'images/') && !str_starts_with($img, '/')) $img = 'images/news/' . $img;
  q('INSERT INTO posts(slug,title,date,category,image,body,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)', [$slug, $meta['title'] ?? $slug, $date, $meta['category'] ?? 'お知らせ', $img ?: null, $body, $t, $t]);
  $cnt++;
}
$hist = json_decode(file_get_contents("$root/data_seed/history.json"), true) ?: [];
foreach ($hist as $i => $r) q('INSERT INTO history(year,wareki,text,sort) VALUES(?,?,?,?)', [$r['year'] ?? '', $r['wareki'] ?? '', $r['text'] ?? '', $i]);
$pdo->commit();
echo "imported posts=$cnt history=" . count($hist) . "\n";
