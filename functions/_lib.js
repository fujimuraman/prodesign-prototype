// 共通ライブラリ（Cloudflare Pages Functions）
// - パスワード: PBKDF2-SHA256 / 認証コード: 6桁・10分・5回まで / セッション: HttpOnly Cookie
// - メール送信: Resend（RESEND_API_KEY）。DEV_MAIL="1" のときは送らずコードを応答に含める（開発専用）

export const now = () => Math.floor(Date.now() / 1000);
const enc = new TextEncoder();

export function json(data, status = 200, headers = {}) {
  return new Response(JSON.stringify(data), { status, headers: { "content-type": "application/json; charset=utf-8", "cache-control": "no-store", ...headers } });
}
export const err = (message, status = 400) => json({ ok: false, error: message }, status);

export function esc(s) {
  return String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
}

// ---------- crypto ----------
export function randomHex(bytes = 32) {
  const a = new Uint8Array(bytes); crypto.getRandomValues(a);
  return [...a].map((b) => b.toString(16).padStart(2, "0")).join("");
}
export async function sha256(s) {
  const d = await crypto.subtle.digest("SHA-256", enc.encode(s));
  return [...new Uint8Array(d)].map((b) => b.toString(16).padStart(2, "0")).join("");
}
export async function hashPassword(password, saltHex) {
  const salt = saltHex || randomHex(16);
  const key = await crypto.subtle.importKey("raw", enc.encode(password), "PBKDF2", false, ["deriveBits"]);
  const bits = await crypto.subtle.deriveBits({ name: "PBKDF2", hash: "SHA-256", salt: enc.encode(salt), iterations: 120000 }, key, 256);
  const hash = [...new Uint8Array(bits)].map((b) => b.toString(16).padStart(2, "0")).join("");
  return { hash, salt };
}
export async function verifyPassword(password, saltHex, hashHex) {
  const { hash } = await hashPassword(password, saltHex);
  return timingSafeEqual(hash, hashHex);
}
export function timingSafeEqual(a, b) {
  if (typeof a !== "string" || typeof b !== "string" || a.length !== b.length) return false;
  let r = 0; for (let i = 0; i < a.length; i++) r |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return r === 0;
}
export const genCode = () => String(Math.floor(100000 + Math.random() * 900000));
export const normEmail = (e) => String(e || "").trim().toLowerCase();
export const validEmail = (e) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e);
export function validPassword(p) {
  if (typeof p !== "string" || p.length < 8) return "パスワードは8文字以上にしてください";
  if (!/[0-9]/.test(p) || !/[a-zA-Z]/.test(p)) return "パスワードは英字と数字を両方含めてください";
  return null;
}

// ---------- codes ----------
export async function issueCode(env, email, purpose, payload) {
  const code = genCode();
  const t = now();
  await env.DB.prepare("DELETE FROM codes WHERE email=? AND purpose=?").bind(email, purpose).run();
  await env.DB.prepare("INSERT INTO codes(email,purpose,code_hash,payload,attempts,expires_at,created_at) VALUES(?,?,?,?,0,?,?)")
    .bind(email, purpose, await sha256(code + ":" + email), payload ? JSON.stringify(payload) : null, t + 600, t).run();
  return code;
}
// 戻り値: {ok:true,payload} / {ok:false,error}
export async function consumeCode(env, email, purpose, code) {
  const row = await env.DB.prepare("SELECT * FROM codes WHERE email=? AND purpose=?").bind(email, purpose).first();
  if (!row) return { ok: false, error: "認証コードが見つかりません。もう一度やり直してください。" };
  if (row.expires_at < now()) { await env.DB.prepare("DELETE FROM codes WHERE id=?").bind(row.id).run(); return { ok: false, error: "認証コードの有効期限（10分）が切れました。もう一度やり直してください。" }; }
  if (row.attempts >= 5) { await env.DB.prepare("DELETE FROM codes WHERE id=?").bind(row.id).run(); return { ok: false, error: "認証コードの入力回数が上限に達しました。もう一度やり直してください。" }; }
  const ok = timingSafeEqual(await sha256(String(code || "").trim() + ":" + email), row.code_hash);
  if (!ok) { await env.DB.prepare("UPDATE codes SET attempts=attempts+1 WHERE id=?").bind(row.id).run(); return { ok: false, error: "認証コードが違います。" }; }
  await env.DB.prepare("DELETE FROM codes WHERE id=?").bind(row.id).run();
  return { ok: true, payload: row.payload ? JSON.parse(row.payload) : null };
}

// ---------- sessions ----------
const SESSION_DAYS = 30, TRUST_DAYS = 30;
export function cookieHeader(name, value, maxAgeSec, request) {
  const secure = new URL(request.url).protocol === "https:" ? "; Secure" : "";
  return `${name}=${value}; Path=/; HttpOnly; SameSite=Lax; Max-Age=${maxAgeSec}${secure}`;
}
export function readCookie(request, name) {
  const c = request.headers.get("cookie") || "";
  const m = c.match(new RegExp("(?:^|;\\s*)" + name + "=([^;]+)"));
  return m ? m[1] : null;
}
export async function createSession(env, request, adminId, kind = "session") {
  const token = randomHex(32);
  const days = kind === "trust" ? TRUST_DAYS : SESSION_DAYS;
  await env.DB.prepare("INSERT INTO sessions(token_hash,admin_id,kind,expires_at,created_at,ua) VALUES(?,?,?,?,?,?)")
    .bind(await sha256(token), adminId, kind, now() + days * 86400, now(), (request.headers.get("user-agent") || "").slice(0, 200)).run();
  return { token, maxAge: days * 86400 };
}
export async function getSession(env, request, kind = "session") {
  const token = readCookie(request, kind === "trust" ? "pd_trust" : "pd_session");
  if (!token) return null;
  const row = await env.DB.prepare("SELECT s.admin_id, s.expires_at, a.email, a.active FROM sessions s JOIN admins a ON a.id=s.admin_id WHERE s.token_hash=? AND s.kind=?")
    .bind(await sha256(token), kind).first();
  if (!row || row.expires_at < now() || !row.active) return null;
  return row;
}
export async function destroySession(env, request) {
  const token = readCookie(request, "pd_session");
  if (token) await env.DB.prepare("DELETE FROM sessions WHERE token_hash=?").bind(await sha256(token)).run();
}
export async function requireAdmin(env, request) {
  const s = await getSession(env, request, "session");
  if (!s) throw new Response(JSON.stringify({ ok: false, error: "ログインしてください", auth: false }), { status: 401, headers: { "content-type": "application/json; charset=utf-8" } });
  return s;
}

// ---------- audit ----------
export async function audit(env, email, action, detail) {
  try { await env.DB.prepare("INSERT INTO audit(at,email,action,detail) VALUES(?,?,?,?)").bind(now(), email || null, action, detail ? String(detail).slice(0, 500) : null).run(); } catch (_) {}
}

// ---------- mail ----------
export async function sendMail(env, to, subject, text) {
  if (env.DEV_MAIL === "1" || !env.RESEND_API_KEY) {
    console.log("[DEV_MAIL]", to, subject, text);
    return { dev: true };
  }
  const r = await fetch("https://api.resend.com/emails", {
    method: "POST",
    headers: { Authorization: "Bearer " + env.RESEND_API_KEY, "content-type": "application/json" },
    body: JSON.stringify({ from: env.FROM_EMAIL || "noreply@example.com", to: [to], subject, text }),
  });
  if (!r.ok) { const t = await r.text(); console.log("mail error", r.status, t); throw new Error("メールの送信に失敗しました。しばらくしてからもう一度お試しください。"); }
  return { dev: false };
}
export function codeMail(env, purposeLabel, code) {
  return {
    subject: `【${env.SITE_NAME || "ProDesign"} 更新ページ】${purposeLabel}の認証コード: ${code}`,
    text: `${purposeLabel}の認証コードです。\n\n　　${code}\n\n更新ページの入力欄にこの6桁を入力してください。有効期限は10分です。\n心当たりがない場合は、このメールを破棄してください（誰かがあなたのメールアドレスを入力した可能性があります。パスワードは漏れていません）。\n\n${env.SITE_NAME || "ProDesign"} 更新ページ`,
  };
}

// ---------- markdown（build_news.py と同じ最小ルール） ----------
export function inlineMd(s) {
  s = esc(s);
  s = s.replace(/!\[(.*?)\]\((.*?)\)/g, '<img src="$2" alt="$1" loading="lazy">');
  s = s.replace(/\[(.*?)\]\((.*?)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
  s = s.replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>");
  s = s.replace(/(?<![\"'=])(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>');
  return s;
}
export function mdToHtml(md) {
  return String(md || "").trim().split(/\n\s*\n/).map((p) => {
    const lines = p.trim().split("\n");
    if (!lines[0]) return "";
    if (lines.every((l) => /^\s*[-*・●]/.test(l))) return "<ul>" + lines.map((l) => "<li>" + inlineMd(l.replace(/^\s*[-*・●]\s*/, "")) + "</li>").join("") + "</ul>";
    if (/^#{1,3}\s/.test(lines[0])) { const lvl = lines[0].match(/^#+/)[0].length + 1; return `<h${lvl}>${inlineMd(lines[0].replace(/^#+\s*/, ""))}</h${lvl}>`; }
    if (lines.length === 1 && /^!\[.*?\]\(.*?\)\s*$/.test(lines[0])) return inlineMd(lines[0]);
    return "<p>" + lines.map(inlineMd).join("<br>") + "</p>";
  }).join("\n");
}
export function excerpt(md, n = 120) {
  const t = mdToHtml(md).replace(/<[^>]+>/g, "").replace(/\s+/g, " ").trim();
  return t.length > n ? t.slice(0, n) + "…" : t;
}
export const imgUrl = (image) => !image ? "" : (image.startsWith("/") || image.startsWith("http") || image.startsWith("images/")) ? image : "images/news/" + image;
