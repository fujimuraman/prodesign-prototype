<?php
// 更新ページ API  /api/<route>（.htaccess で api.php?r=<route> に書き換え）
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

$route = '/' . trim((string)($_GET['r'] ?? ''), '/');
$m = $_SERVER['REQUEST_METHOD'];
$c = cfg();
try {
  // ---------- 公開（読み取り） ----------
  if ($m === 'GET' && $route === '/posts') {
    $list = array_map(fn($p) => $p + ['image' => img_url($p['image']), 'excerpt' => excerpt($p['body'])], all_posts());
    json_out(['ok' => true, 'posts' => $list]);
  }
  if ($m === 'GET' && $route === '/history') json_out(['ok' => true, 'history' => rows('SELECT id,year,wareki,text,sort FROM history ORDER BY sort, id')]);

  // ---------- 認証 ----------
  if ($m === 'GET' && $route === '/auth/me') {
    $s = get_session();
    $n = (int)row('SELECT COUNT(*) AS n FROM admins WHERE active=1')['n'];
    json_out(['ok' => true, 'user' => $s ? ['email' => $s['email']] : null, 'hasAdmin' => $n > 0, 'dev' => !empty($c['dev_mail'])]);
  }
  if ($m === 'POST' && $route === '/auth/register') {
    $b = read_json(); $email = norm_email($b['email'] ?? '');
    if (!valid_email($email)) fail('メールアドレスの形式が正しくありません');
    if (!allowed_email($email)) fail('このメールアドレスは登録できません。管理者にお問い合わせください。', 403);
    if ($e = valid_password($b['password'] ?? null)) fail($e);
    $ex = row('SELECT id, active FROM admins WHERE email=?', [$email]);
    if ($ex && (int)$ex['active']) fail('このメールアドレスは登録済みです。ログインしてください。');
    $hash = password_hash($b['password'], PASSWORD_DEFAULT);
    if ($ex) q('UPDATE admins SET pass_hash=?, updated_at=? WHERE id=?', [$hash, time(), $ex['id']]);
    else q('INSERT INTO admins(email,pass_hash,active,created_at,updated_at) VALUES(?,?,0,?,?)', [$email, $hash, time(), time()]);
    $code = issue_code($email, 'register'); [$sub, $txt] = code_mail('登録', $code);
    $dev = send_mail($email, $sub, $txt); audit($email, 'register.request');
    json_out(['ok' => true, 'next' => 'code'] + ($dev ? ['devCode' => $code] : []));
  }
  if ($m === 'POST' && $route === '/auth/register/confirm') {
    $b = read_json(); $email = norm_email($b['email'] ?? '');
    $r = consume_code($email, 'register', $b['code'] ?? ''); if (!$r['ok']) fail($r['error']);
    q('UPDATE admins SET active=1, updated_at=? WHERE email=?', [time(), $email]);
    $a = row('SELECT id FROM admins WHERE email=?', [$email]);
    create_session((int)$a['id']); audit($email, 'register.confirm');
    json_out(['ok' => true, 'user' => ['email' => $email]]);
  }
  if ($m === 'POST' && $route === '/auth/login') {
    $b = read_json(); $email = norm_email($b['email'] ?? '');
    $a = row('SELECT * FROM admins WHERE email=? AND active=1', [$email]);
    if (!$a) { audit($email, 'login.unknown'); fail('メールアドレスまたはパスワードが違います', 401); }
    if ((int)$a['locked_until'] > time()) fail('ログインが一時的にロックされています。' . (int)ceil(((int)$a['locked_until'] - time()) / 60) . '分後にお試しください。', 423);
    if (!password_verify((string)($b['password'] ?? ''), $a['pass_hash'])) {
      $fc = (int)$a['failed_count'] + 1;
      q('UPDATE admins SET failed_count=?, locked_until=? WHERE id=?', [$fc, $fc >= 10 ? time() + 900 : 0, $a['id']]);
      audit($email, 'login.badpass'); fail('メールアドレスまたはパスワードが違います', 401);
    }
    q('UPDATE admins SET failed_count=0, locked_until=0 WHERE id=?', [$a['id']]);
    $trust = get_session('trust');
    if ($trust && (int)$trust['admin_id'] === (int)$a['id']) { create_session((int)$a['id']); audit($email, 'login.trusted'); json_out(['ok' => true, 'user' => ['email' => $email]]); }
    $code = issue_code($email, 'login'); [$sub, $txt] = code_mail('ログイン', $code);
    $dev = send_mail($email, $sub, $txt); audit($email, 'login.code_sent');
    json_out(['ok' => true, 'next' => 'code'] + ($dev ? ['devCode' => $code] : []));
  }
  if ($m === 'POST' && $route === '/auth/login/verify') {
    $b = read_json(); $email = norm_email($b['email'] ?? '');
    $a = row('SELECT id FROM admins WHERE email=? AND active=1', [$email]); if (!$a) fail('ログインをやり直してください', 401);
    $r = consume_code($email, 'login', $b['code'] ?? ''); if (!$r['ok']) fail($r['error']);
    create_session((int)$a['id']);
    if (!empty($b['remember'])) create_session((int)$a['id'], 'trust');
    audit($email, 'login.ok'); json_out(['ok' => true, 'user' => ['email' => $email]]);
  }
  if ($m === 'POST' && $route === '/auth/logout') { destroy_session(); json_out(['ok' => true]); }
  if ($m === 'POST' && $route === '/auth/forgot') {
    $b = read_json(); $email = norm_email($b['email'] ?? '');
    $a = row('SELECT id FROM admins WHERE email=? AND active=1', [$email]); $devCode = null;
    if ($a) { $code = issue_code($email, 'reset'); [$sub, $txt] = code_mail('パスワード再設定', $code); if (send_mail($email, $sub, $txt)) $devCode = $code; audit($email, 'reset.request'); }
    json_out(['ok' => true, 'next' => 'code'] + ($devCode ? ['devCode' => $devCode] : []));
  }
  if ($m === 'POST' && $route === '/auth/reset') {
    $b = read_json(); $email = norm_email($b['email'] ?? '');
    if ($e = valid_password($b['password'] ?? null)) fail($e);
    $r = consume_code($email, 'reset', $b['code'] ?? ''); if (!$r['ok']) fail($r['error']);
    $a = row('SELECT id FROM admins WHERE email=? AND active=1', [$email]); if (!$a) fail('やり直してください');
    q('UPDATE admins SET pass_hash=?, failed_count=0, locked_until=0, updated_at=? WHERE id=?', [password_hash($b['password'], PASSWORD_DEFAULT), time(), $a['id']]);
    q('DELETE FROM sessions WHERE admin_id=?', [$a['id']]);
    try { send_mail($email, "【{$c['site_name']} 更新ページ】パスワードが変更されました", "更新ページのパスワードが再設定されました。\n心当たりがない場合は、至急「パスワードを忘れた方」から再設定してください。"); } catch (Throwable $e) {}
    audit($email, 'reset.done'); json_out(['ok' => true]);
  }

  // ---------- ログイン後: 設定 ----------
  if ($m === 'POST' && $route === '/auth/change-password') {
    $s = require_admin(); $b = read_json(); $a = row('SELECT * FROM admins WHERE id=?', [$s['admin_id']]);
    if (!password_verify((string)($b['current'] ?? ''), $a['pass_hash'])) fail('現在のパスワードが違います', 401);
    if ($e = valid_password($b['password'] ?? null)) fail($e);
    q('UPDATE admins SET pass_hash=?, updated_at=? WHERE id=?', [password_hash($b['password'], PASSWORD_DEFAULT), time(), $a['id']]);
    audit($a['email'], 'password.change'); json_out(['ok' => true]);
  }
  if ($m === 'POST' && $route === '/auth/change-email') {
    $s = require_admin(); $b = read_json(); $a = row('SELECT * FROM admins WHERE id=?', [$s['admin_id']]);
    $new = norm_email($b['newEmail'] ?? '');
    if (!valid_email($new)) fail('新しいメールアドレスの形式が正しくありません');
    if ($new === $a['email']) fail('現在と同じメールアドレスです');
    if (!password_verify((string)($b['password'] ?? ''), $a['pass_hash'])) fail('パスワードが違います', 401);
    if (row('SELECT id FROM admins WHERE email=?', [$new])) fail('そのメールアドレスは既に使われています');
    $code = issue_code($a['email'], 'change_email', ['newEmail' => $new]); [$sub, $txt] = code_mail('メールアドレス変更', $code);
    $dev = send_mail($new, $sub, $txt . "\n\n（新しいメールアドレス {$new} に届いています）"); audit($a['email'], 'email.change_request', $new);
    json_out(['ok' => true, 'next' => 'code'] + ($dev ? ['devCode' => $code] : []));
  }
  if ($m === 'POST' && $route === '/auth/change-email/confirm') {
    $s = require_admin(); $b = read_json(); $a = row('SELECT * FROM admins WHERE id=?', [$s['admin_id']]);
    $r = consume_code($a['email'], 'change_email', $b['code'] ?? ''); if (!$r['ok']) fail($r['error']);
    $new = $r['payload']['newEmail'] ?? ''; if ($new === '') fail('やり直してください');
    q('UPDATE admins SET email=?, updated_at=? WHERE id=?', [$new, time(), $a['id']]);
    try { send_mail($a['email'], "【{$c['site_name']} 更新ページ】メールアドレスが変更されました", "更新ページのログイン用メールアドレスが {$a['email']} から {$new} に変更されました。\n心当たりがない場合は管理者にご連絡ください。"); } catch (Throwable $e) {}
    audit($a['email'], 'email.changed', $new); json_out(['ok' => true, 'user' => ['email' => $new]]);
  }

  // ---------- 記事 ----------
  if ($m === 'POST' && $route === '/posts') {
    $s = require_admin(); $b = read_json();
    $title = trim((string)($b['title'] ?? '')); $date = str_replace('-', '.', (string)($b['date'] ?? '')); $cat = (string)($b['category'] ?? 'お知らせ'); $body = (string)($b['body'] ?? '');
    if ($title === '' || !preg_match('/^\d{4}\.\d{2}\.\d{2}$/', $date) || trim($body) === '') fail('タイトル・日付・本文は必須です');
    $image = trim((string)($b['image'] ?? '')) ?: null; $slug = trim((string)($b['slug'] ?? '')); $t = time();
    if ($slug !== '') {
      if (!row('SELECT slug FROM posts WHERE slug=?', [$slug])) fail('記事が見つかりません', 404);
      q('UPDATE posts SET title=?, date=?, category=?, image=?, body=?, updated_at=? WHERE slug=?', [$title, $date, $cat, $image, $body, $t, $slug]);
    } else {
      $slug = str_replace('.', '-', $date) . '-' . bin2hex(random_bytes(3));
      q('INSERT INTO posts(slug,title,date,category,image,body,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)', [$slug, $title, $date, $cat, $image, $body, $t, $t]);
    }
    audit($s['email'], 'post.save', $slug); json_out(['ok' => true, 'slug' => $slug]);
  }
  if ($m === 'DELETE' && str_starts_with($route, '/posts/')) {
    $s = require_admin(); $slug = rawurldecode(substr($route, 7));
    $cur = row('SELECT image FROM posts WHERE slug=?', [$slug]); if (!$cur) fail('記事が見つかりません', 404);
    q('DELETE FROM posts WHERE slug=?', [$slug]);
    if ($cur['image'] && str_starts_with($cur['image'], $c['upload_url'] . '/')) { $f = $c['upload_dir'] . '/' . basename($cur['image']); if (is_file($f)) @unlink($f); }
    audit($s['email'], 'post.delete', $slug); json_out(['ok' => true]);
  }
  if ($m === 'POST' && $route === '/upload') {
    $s = require_admin();
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'pd-admin') fail('不正なリクエストです');
    $ct = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$ct] ?? null;
    if (!$ext) fail('画像は JPEG / PNG / GIF / WebP のみアップロードできます');
    $buf = file_get_contents('php://input');
    if (strlen($buf) > 4 * 1024 * 1024) fail('画像が大きすぎます（4MBまで）。縮小してからお試しください。');
    if (!@getimagesizefromstring($buf)) fail('画像ファイルとして読み取れませんでした');
    if (!is_dir($c['upload_dir'])) { @mkdir($c['upload_dir'], 0755, true); @file_put_contents($c['upload_dir'] . '/.htaccess', "php_flag engine off\nRemoveHandler .php .phtml .php3 .php4 .php5 .phar\n<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py)$\">\nRequire all denied\n</FilesMatch>\n"); }
    $name = date('Y-m-d') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    file_put_contents($c['upload_dir'] . '/' . $name, $buf);
    audit($s['email'], 'upload', $name); json_out(['ok' => true, 'url' => $c['upload_url'] . '/' . $name]);
  }

  // ---------- 沿革・事業実績 ----------
  if ($m === 'PUT' && $route === '/history') {
    $s = require_admin(); $b = read_json(); $list = is_array($b['history'] ?? null) ? $b['history'] : [];
    $pdo = db(); $pdo->beginTransaction();
    try {
      $pdo->exec('DELETE FROM history'); $i = 0;
      foreach ($list as $r) { $y = trim((string)($r['year'] ?? '')); $t = trim((string)($r['text'] ?? '')); if ($y === '' && $t === '') continue; q('INSERT INTO history(year,wareki,text,sort) VALUES(?,?,?,?)', [$y, trim((string)($r['wareki'] ?? '')), $t, $i++]); }
      $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    audit($s['email'], 'history.save', count($list)); json_out(['ok' => true]);
  }

  fail('Not found', 404);
} catch (Throwable $e) {
  error_log('API error ' . $route . ': ' . $e->getMessage());
  fail($e instanceof RuntimeException ? $e->getMessage() : 'サーバーエラーが発生しました', 500);
}
