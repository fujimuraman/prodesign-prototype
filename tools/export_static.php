<?php
// 静的書き出し（GitHub Pages のプレビュー用）: DBの内容を各ページに焼き込み、記事ページを post/<slug>/index.html に生成する。
// PHPサーバー（本番）では page.php / post.php が同じ内容を動的に出すので、この書き出しは「見え方を揃える」ための静的コピー。
//
// ★ 検索対策の出し分け（重要）
//   - ここで書き出すファイルには <meta name="robots" content="noindex,nofollow"> が入る（プレビューが検索に載って
//     本番と重複コンテンツにならないようにするため）。canonical は本番URL（https://prodesign.co.jp/...）を指す。
//   - 本番では .htaccess が全ページを page.php / post.php に通し、head を本番用（noindex なし）に差し替えて出す。
//     さらに page.php は出力前に noindex を取り除く。tools/check_seo.py で「本番出力に noindex が無い」ことを検証できる。
//
// 使い方:  php tools/export_static.php
declare(strict_types=1);
error_reporting(E_ALL & ~E_WARNING); // CLI では header() の警告を抑制
define('PD_NO_OUTPUT', true);
$root = dirname(__DIR__);
require_once "$root/lib.php";
require_once "$root/page.php";
require_once "$root/post.php";

// 1) 固定ページ（page.php と同じ差し込み＋プレビュー用の head）
foreach (array_keys(seo_pages()) as $p) {
  $html = render_page($p, true);
  if ($html === null) { echo "skip $p.html (not found)\n"; continue; }
  // 静的コピーでは記事リンクを post/<slug>/ に（GitHub Pages はディレクトリの index.html を返す）
  $html = preg_replace('#href="/post/([\w-]+)"#', 'href="post/$1/"', $html);
  if (!str_contains($html, 'noindex,nofollow')) { fwrite(STDERR, "ERROR: noindex missing in $p.html\n"); exit(1); }
  file_put_contents("$root/$p.html", $html);
  echo "wrote $p.html\n";
}
// 2) 記事ページ
$old = glob("$root/post/*"); foreach ($old as $d) { if (is_dir($d)) { @unlink("$d/index.html"); @rmdir($d); } }
$n = 0;
foreach (all_posts() as $post) {
  [$status, $html] = render_post($post['slug'], true);
  if ($status !== 200) continue;
  $html = str_replace('<base href="/">', '<base href="../../">', $html); // 相対パスで動くように
  $html = preg_replace('#href="/post/([\w-]+)"#', 'href="../$1/"', $html);
  if (!str_contains($html, 'noindex,nofollow')) { fwrite(STDERR, "ERROR: noindex missing in post {$post['slug']}\n"); exit(1); }
  @mkdir("$root/post/{$post['slug']}", 0755, true);
  file_put_contents("$root/post/{$post['slug']}/index.html", $html); $n++;
}
echo "wrote $n post pages\n";
