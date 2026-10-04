<?php
// 旧サイト（WordPress）の URL を新サイトの URL へ 301 転送する。該当が無ければ 404 ページを返す。
// .htaccess が「実在しないパス」「/?p=878 のような旧クエリ」「/news/ 以下」をここへ回す（_dev_router.php も同じ）。
// 対応表は legacy_map.php。検証は tools/test_redirects.py。
declare(strict_types=1);

/** @return array{0:int,1:?string} [ステータス, 転送先パス]（301 / 410 / 404） */
function legacy_resolve(string $path, array $q): array {
  static $map = null;
  if ($map === null) $map = require __DIR__ . '/legacy_map.php';
  $raw = '/' . ltrim(rawurldecode($path), '/');
  $raw = preg_replace('#/{2,}#', '/', $raw);
  $p = strtolower($raw);
  $post = fn($id) => isset($map['posts'][(int)$id]) ? '/post/' . $map['posts'][(int)$id] : '/news.html';

  // ---- 旧クエリ形式（/?p=878 など）
  if ($p === '/' || $p === '/index.php' || $p === '/index.html') {
    if (isset($q['p']) && ctype_digit((string)$q['p'])) {
      $id = (int)$q['p'];
      if (isset($map['page_ids'][$id])) return [301, $map['pages'][$map['page_ids'][$id]]];
      return [301, $post($id)];
    }
    if (isset($q['page_id']) && ctype_digit((string)$q['page_id'])) {
      $id = (int)$q['page_id'];
      return [301, isset($map['page_ids'][$id]) ? $map['pages'][$map['page_ids'][$id]] : '/'];
    }
    if (isset($q['feed'])) return [301, '/feed.xml'];
    foreach (['cat', 's', 'm', 'author', 'paged', 'attachment_id', 'tag', 'category_name'] as $k) if (isset($q[$k])) return [301, '/news.html'];
    return [301, '/'];
  }
  // ---- 旧投稿 /post-878/（末尾の feed や添付ページも記事へ）
  if (preg_match('#^/post-(\d+)(?:/.*)?$#', $p, $m)) return [301, $post($m[1])];
  // ---- 記事URLの末尾スラッシュ・静的コピーの index.html を正規形へ
  if (preg_match('#^/post/([\w-]+)/(?:index\.html)?$#', $raw, $m)) return [301, '/post/' . $m[1]];
  if ($p === '/post' || $p === '/post/') return [301, '/news.html'];
  // ---- 旧固定ページ（/company/ 、/business_01/feed/ 、/business_01/b-flow01/ など配下もまとめて）
  if (preg_match('#^/(business_0[123]|business|works|company|contact|news)(?:\.html?|\.php)?(?:/.*)?$#', $p, $m)) return [301, $map['pages'][$m[1]]];
  // ---- フィード
  if (preg_match('#^/(feed|comments/feed|rss|rss2|atom|rdf)(?:/.*)?$#', $p) || in_array($p, ['/rss.xml', '/atom.xml', '/index.rdf', '/feed.php'], true)) return [301, '/feed.xml'];
  // ---- 一覧（カテゴリ・タグ・投稿者・年月・ページ送り）
  if (preg_match('#^/(category|tag|author|archives|page)(?:/.*)?$#', $p)) return [301, '/news.html'];
  if (preg_match('#^/(19|20)\d\d(?:/.*)?$#', $p)) return [301, '/news.html'];
  // ---- 旧サイトマップ
  if (preg_match('#^/(sitemap[\w-]*\.(?:html|xml|xml\.gz)|wp-sitemap[\w-]*\.xml)$#', $p)) return [301, '/sitemap.xml'];
  // ---- 旧画像
  if (preg_match('#^/wp-content/uploads/(.+)$#', $raw, $m)) {
    $f = $m[1];
    if (isset($map['uploads'][$f])) return [301, $map['uploads'][$f]];
    $base = preg_replace('/-\d+x\d+(\.\w+)$/', '$1', $f); // 一覧に無いサイズ違い
    if (isset($map['uploads'][$base])) return [301, $map['uploads'][$base]];
    return [301, '/news.html'];
  }
  if (preg_match('#^/wp-content/themes/prod/images/(.+)$#', $raw, $m)) return [301, $map['theme_images'][$m[1]] ?? '/'];
  // ---- 旧 添付ファイルページ（/b-flow01/ など）
  $slug = trim($p, '/');
  if ($slug !== '' && isset($map['attachments'][$slug])) return [301, $map['attachments'][$slug]];
  // ---- WordPress の内部ファイル（もう存在しない。検索に載せるものではないので 410）
  if (preg_match('#^/(wp-admin|wp-includes|wp-json|wp-content)(?:/.*)?$#', $p) || preg_match('#^/(wp-[\w-]+|xmlrpc)\.php$#', $p)) return [410, null];
  return [404, null];
}

function legacy_not_found(int $status = 404): void {
  http_response_code($status);
  header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex');
  $f = __DIR__ . '/templates/notfound.html';
  if (!is_file($f)) { echo 'Not found'; return; }
  require_once __DIR__ . '/lib.php';
  // どの階層のURLでも CSS・画像が効くように <base> を入れる
  $html = str_replace('<head>', "<head>\n<base href=\"/\">", file_get_contents($f));
  echo seo_apply($html, seo_head('notfound'));
}

/** リクエストを処理する（.htaccess / _dev_router.php から呼ばれる） */
function legacy_handle(): void {
  // .htaccess の ErrorDocument 経由で来た時は元のURLが REDIRECT_URL に入る
  $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
  $path = (string)(parse_url($uri, PHP_URL_PATH) ?? '/');
  $q = []; parse_str((string)(parse_url($uri, PHP_URL_QUERY) ?? ''), $q);
  [$status, $to] = legacy_resolve($path, $q);
  if ($status === 301 && $to !== null) {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $abs = preg_match('/^(www\.)?prodesign\.co\.jp$/', $host) ? 'https://prodesign.co.jp' : '';
    header('Location: ' . $abs . $to, true, 301); header('Cache-Control: public, max-age=3600');
    return;
  }
  legacy_not_found($status);
}

if (PHP_SAPI !== 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) legacy_handle();
