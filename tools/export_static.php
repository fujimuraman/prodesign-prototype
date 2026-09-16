<?php
// 静的書き出し（GitHub Pages のプロトタイプ用）: DBの内容を index.html / news.html / company.html に焼き込み、
// 記事ページを post/<slug>/index.html に生成する。PHPサーバーでは page.php / post.php が同じ内容を動的に出すので、
// この書き出しは「見え方を揃える」ための静的コピー。
// 使い方:  php tools/export_static.php
declare(strict_types=1);
error_reporting(E_ALL & ~E_WARNING); // CLI では header() の警告を抑制
require_once dirname(__DIR__) . '/lib.php';
$root = dirname(__DIR__);

// 1) index / news / company（page.php と同じ差し込みをファイルに書く）
$_SERVER['REQUEST_METHOD'] = 'GET';
foreach (['index', 'news', 'company'] as $p) {
  $_GET['p'] = $p;
  ob_start(); include "$root/page.php"; $html = ob_get_clean();
  // 静的コピーでは記事リンクを post/<slug>/ に（GitHub Pages はディレクトリの index.html を返す）
  $html = preg_replace('#href="/post/([\w-]+)"#', 'href="post/$1/"', $html);
  file_put_contents("$root/$p.html", $html);
  echo "wrote $p.html\n";
}
// 2) 記事ページ
$old = glob("$root/post/*"); foreach ($old as $d) { if (is_dir($d)) { @unlink("$d/index.html"); @rmdir($d); } }
$n = 0;
foreach (all_posts() as $post) {
  $_GET['slug'] = $post['slug'];
  ob_start(); include "$root/post.php"; $html = ob_get_clean();
  $html = str_replace('<base href="/">', '<base href="../../">', $html); // 相対パスで動くように
  $html = preg_replace('#href="/post/([\w-]+)"#', 'href="../$1/"', $html);
  @mkdir("$root/post/{$post['slug']}", 0755, true);
  file_put_contents("$root/post/{$post['slug']}/index.html", $html); $n++;
}
echo "wrote $n post pages\n";
