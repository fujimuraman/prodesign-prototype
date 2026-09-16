<?php
// 共通ライブラリ（PHP 8.x / SQLite / mail()）
declare(strict_types=1);
mb_internal_encoding('UTF-8');

function cfg(): array {
  static $c = null;
  if ($c === null) { $c = require __DIR__ . '/config.php'; if (is_file(__DIR__ . '/config.local.php')) $c = array_merge($c, require __DIR__ . '/config.local.php'); }
  return $c;
}

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $dir = cfg()['data_dir'];
  if (!is_dir($dir)) { @mkdir($dir, 0755, true); @file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"); }
  $pdo = new PDO('sqlite:' . $dir . '/prodesign.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
  $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON;');
  $pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
  return $pdo;
}
function q(string $sql, array $p = []): PDOStatement { $st = db()->prepare($sql); $st->execute($p); return $st; }
function row(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r === false ? null : $r; }
function rows(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }

// ---------- 出力 ----------
function json_out(array $data, int $status = 200, array $headers = []): never {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
  foreach ($headers as $h) header($h, false);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
}
function fail(string $msg, int $status = 400): never { json_out(['ok' => false, 'error' => $msg], $status); }
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ---------- 入力 ----------
function read_json(): array {
  if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'pd-admin') fail('不正なリクエストです', 400);
  $j = json_decode(file_get_contents('php://input') ?: '', true);
  if (!is_array($j)) fail('JSONが不正です', 400);
  return $j;
}
function norm_email(?string $e): string { return mb_strtolower(trim((string)$e)); }
function valid_email(string $e): bool { return (bool)filter_var($e, FILTER_VALIDATE_EMAIL); }
function valid_password(?string $p): ?string {
  if (!is_string($p) || strlen($p) < 8) return 'パスワードは8文字以上にしてください';
  if (!preg_match('/[0-9]/', $p) || !preg_match('/[a-zA-Z]/', $p)) return 'パスワードは英字と数字を両方含めてください';
  return null;
}
function allowed_email(string $e): bool { return in_array($e, array_map('norm_email', cfg()['allowed_emails']), true); }
function client_ua(): string { return mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200); }

// ---------- 認証コード ----------
function issue_code(string $email, string $purpose, ?array $payload = null): string {
  $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
  q('DELETE FROM codes WHERE email=? AND purpose=?', [$email, $purpose]);
  q('INSERT INTO codes(email,purpose,code_hash,payload,attempts,expires_at,created_at) VALUES(?,?,?,?,0,?,?)',
    [$email, $purpose, hash('sha256', $code . ':' . $email), $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null, time() + 600, time()]);
  return $code;
}
function consume_code(string $email, string $purpose, ?string $code): array {
  $r = row('SELECT * FROM codes WHERE email=? AND purpose=?', [$email, $purpose]);
  if (!$r) return ['ok' => false, 'error' => '認証コードが見つかりません。もう一度やり直してください。'];
  if ((int)$r['expires_at'] < time()) { q('DELETE FROM codes WHERE id=?', [$r['id']]); return ['ok' => false, 'error' => '認証コードの有効期限（10分）が切れました。もう一度やり直してください。']; }
  if ((int)$r['attempts'] >= 5) { q('DELETE FROM codes WHERE id=?', [$r['id']]); return ['ok' => false, 'error' => '認証コードの入力回数が上限に達しました。もう一度やり直してください。']; }
  if (!hash_equals($r['code_hash'], hash('sha256', trim((string)$code) . ':' . $email))) { q('UPDATE codes SET attempts=attempts+1 WHERE id=?', [$r['id']]); return ['ok' => false, 'error' => '認証コードが違います。']; }
  q('DELETE FROM codes WHERE id=?', [$r['id']]);
  return ['ok' => true, 'payload' => $r['payload'] ? json_decode($r['payload'], true) : null];
}

// ---------- セッション（HttpOnly Cookie + DB） ----------
function cookie_set(string $name, string $value, int $maxAge): void {
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  setcookie($name, $value, ['expires' => $maxAge > 0 ? time() + $maxAge : 1, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
}
function create_session(int $adminId, string $kind = 'session'): string {
  $token = bin2hex(random_bytes(32));
  $days = $kind === 'trust' ? cfg()['trust_days'] : cfg()['session_days'];
  q('INSERT INTO sessions(token_hash,admin_id,kind,expires_at,created_at,ua) VALUES(?,?,?,?,?,?)', [hash('sha256', $token), $adminId, $kind, time() + $days * 86400, time(), client_ua()]);
  cookie_set($kind === 'trust' ? 'pd_trust' : 'pd_session', $token, $days * 86400);
  return $token;
}
function get_session(string $kind = 'session'): ?array {
  $token = $_COOKIE[$kind === 'trust' ? 'pd_trust' : 'pd_session'] ?? '';
  if ($token === '' || !preg_match('/^[0-9a-f]{64}$/', $token)) return null;
  $r = row('SELECT s.admin_id, s.expires_at, a.email, a.active FROM sessions s JOIN admins a ON a.id=s.admin_id WHERE s.token_hash=? AND s.kind=?', [hash('sha256', $token), $kind]);
  if (!$r || (int)$r['expires_at'] < time() || !(int)$r['active']) return null;
  return $r;
}
function destroy_session(): void {
  $token = $_COOKIE['pd_session'] ?? '';
  if ($token !== '') q('DELETE FROM sessions WHERE token_hash=?', [hash('sha256', $token)]);
  cookie_set('pd_session', '', 0);
}
function require_admin(): array {
  $s = get_session();
  if (!$s) json_out(['ok' => false, 'error' => 'ログインしてください', 'auth' => false], 401);
  return $s;
}
function audit(?string $email, string $action, $detail = null): void {
  try { q('INSERT INTO audit(at,email,action,detail) VALUES(?,?,?,?)', [time(), $email, $action, $detail === null ? null : mb_substr((string)$detail, 0, 500)]); } catch (Throwable $e) {}
}

// ---------- メール ----------
function send_mail(string $to, string $subject, string $text): bool {
  $c = cfg();
  if (!empty($c['dev_mail'])) {
    @file_put_contents($c['data_dir'] . '/mail.log', date('c') . " TO:$to SUBJ:$subject\n$text\n---\n", FILE_APPEND);
    return true; // dev
  }
  $from = $c['from_email'];
  $headers = 'From: ' . mb_encode_mimeheader($c['from_name'], 'UTF-8') . " <$from>\r\n" . "Reply-To: $from\r\n" . "MIME-Version: 1.0\r\n" . "Content-Type: text/plain; charset=UTF-8\r\n" . "Content-Transfer-Encoding: 8bit\r\n" . "X-Mailer: PHP/" . PHP_VERSION;
  $ok = @mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $text, $headers, '-f' . $from);
  if (!$ok) throw new RuntimeException('メールの送信に失敗しました。しばらくしてからもう一度お試しください。');
  return false;
}
function code_mail(string $purposeLabel, string $code): array {
  $site = cfg()['site_name'];
  return [
    "【{$site} 更新ページ】{$purposeLabel}の認証コード: {$code}",
    "{$purposeLabel}の認証コードです。\n\n　　{$code}\n\n更新ページの入力欄にこの6桁を入力してください。有効期限は10分です。\n心当たりがない場合は、このメールを破棄してください（誰かがあなたのメールアドレスを入力した可能性があります。パスワードは漏れていません）。\n\n{$site} 更新ページ",
  ];
}

// ---------- Markdown（簡易） ----------
function inline_md(string $s): string {
  $s = h($s);
  $s = preg_replace('/!\[(.*?)\]\((.*?)\)/u', '<img src="$2" alt="$1" loading="lazy">', $s);
  $s = preg_replace('/\[(.*?)\]\((.*?)\)/u', '<a href="$2" target="_blank" rel="noopener">$1</a>', $s);
  $s = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s);
  $s = preg_replace('/(?<!["\'=])(https?:\/\/[^\s<]+)/u', '<a href="$1" target="_blank" rel="noopener">$1</a>', $s);
  return $s;
}
function md_to_html(string $md): string {
  $out = [];
  foreach (preg_split('/\n\s*\n/u', trim(str_replace("\r\n", "\n", $md))) as $p) {
    $lines = explode("\n", trim($p)); if ($lines[0] === '') continue;
    $allList = true; foreach ($lines as $l) if (!preg_match('/^\s*[-*・●]/u', $l)) { $allList = false; break; }
    if ($allList) { $out[] = '<ul>' . implode('', array_map(fn($l) => '<li>' . inline_md(preg_replace('/^\s*[-*・●]\s*/u', '', $l)) . '</li>', $lines)) . '</ul>'; continue; }
    if (preg_match('/^(#{1,3})\s+(.*)$/u', $lines[0], $m)) { $lvl = strlen($m[1]) + 1; $out[] = "<h$lvl>" . inline_md($m[2]) . "</h$lvl>"; continue; }
    if (count($lines) === 1 && preg_match('/^!\[.*?\]\(.*?\)\s*$/u', $lines[0])) { $out[] = inline_md($lines[0]); continue; }
    $out[] = '<p>' . implode('<br>', array_map('inline_md', $lines)) . '</p>';
  }
  return implode("\n", $out);
}
function excerpt(string $md, int $n = 120): string {
  $t = trim(preg_replace('/\s+/u', ' ', strip_tags(md_to_html($md))));
  return mb_strlen($t) > $n ? mb_substr($t, 0, $n) . '…' : $t;
}
function img_url(?string $image): string {
  if (!$image) return '';
  if (str_starts_with($image, '/') || str_starts_with($image, 'http') || str_starts_with($image, 'images/')) return $image;
  return 'images/news/' . $image;
}
function all_posts(): array { return rows('SELECT slug,title,date,category,image,body,updated_at FROM posts ORDER BY date DESC, slug DESC'); }
function news_card(array $p): string {
  $img = $p['image'] ? '<div class="news-card__img" style="background-image:url(\'' . h(img_url($p['image'])) . '\');"></div>' : '<div class="news-card__img news-card__img--placeholder"></div>';
  return '      <a href="/post/' . h($p['slug']) . '" class="news-card">' . "\n        $img\n" .
    '        <div class="news-card__body">' . "\n" . '          <div class="news-card__meta">' . "\n" .
    '            <time class="news-card__date">' . h($p['date']) . '</time>' . "\n" . '            <span class="news-card__cat">' . h($p['category']) . '</span>' . "\n" . '          </div>' . "\n" .
    '          <h3 class="news-card__title">' . h($p['title']) . '</h3>' . "\n" . '          <p class="news-card__desc">' . h(excerpt($p['body'])) . '</p>' . "\n" .
    '          <span class="news-card__more">続きを読む <span class="arrow">→</span></span>' . "\n        </div>\n      </a>";
}
