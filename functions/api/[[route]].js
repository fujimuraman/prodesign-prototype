// 更新ページ API ルーター  /api/...
import {
  json, err, now, esc, randomHex, sha256, hashPassword, verifyPassword, genCode, normEmail, validEmail, validPassword,
  issueCode, consumeCode, cookieHeader, readCookie, createSession, getSession, destroySession, requireAdmin, audit,
  sendMail, codeMail, mdToHtml, excerpt, imgUrl,
} from "../_lib.js";

const allowed = (env, email) => (env.ALLOWED_EMAILS || "").split(",").map((s) => normEmail(s)).filter(Boolean).includes(email);
const ONE_MB = 1024 * 1024;

async function readJson(request) {
  if (request.headers.get("x-requested-with") !== "pd-admin") throw err("不正なリクエストです", 400);
  try { return await request.json(); } catch { throw err("JSONが不正です", 400); }
}

export async function onRequest(context) {
  const { request, env, params } = context;
  const route = "/" + (params.route || []).join("/");
  const m = request.method;
  try {
    // ---------- 公開（読み取り） ----------
    if (m === "GET" && route === "/posts") {
      const rows = (await env.DB.prepare("SELECT slug,title,date,category,image,body,updated_at FROM posts ORDER BY date DESC, slug DESC").all()).results;
      return json({ ok: true, posts: rows.map((p) => ({ ...p, image: imgUrl(p.image), excerpt: excerpt(p.body) })) }, 200, { "cache-control": "public, max-age=60" });
    }
    if (m === "GET" && route === "/history") {
      const rows = (await env.DB.prepare("SELECT id,year,wareki,text,sort FROM history ORDER BY sort, id").all()).results;
      return json({ ok: true, history: rows }, 200, { "cache-control": "public, max-age=60" });
    }

    // ---------- 認証 ----------
    if (m === "GET" && route === "/auth/me") {
      const s = await getSession(env, request);
      const count = (await env.DB.prepare("SELECT COUNT(*) AS n FROM admins WHERE active=1").first()).n;
      return json({ ok: true, user: s ? { email: s.email } : null, hasAdmin: count > 0, dev: env.DEV_MAIL === "1" });
    }

    if (m === "POST" && route === "/auth/register") {
      const b = await readJson(request);
      const email = normEmail(b.email);
      if (!validEmail(email)) return err("メールアドレスの形式が正しくありません");
      if (!allowed(env, email)) return err("このメールアドレスは登録できません。管理者にお問い合わせください。", 403);
      const pe = validPassword(b.password); if (pe) return err(pe);
      const exists = await env.DB.prepare("SELECT id, active FROM admins WHERE email=?").bind(email).first();
      if (exists && exists.active) return err("このメールアドレスは登録済みです。ログインしてください。");
      const { hash, salt } = await hashPassword(b.password);
      if (exists) await env.DB.prepare("UPDATE admins SET pass_hash=?, salt=?, updated_at=? WHERE id=?").bind(hash, salt, now(), exists.id).run();
      else await env.DB.prepare("INSERT INTO admins(email,pass_hash,salt,active,created_at,updated_at) VALUES(?,?,?,0,?,?)").bind(email, hash, salt, now(), now()).run();
      const code = await issueCode(env, email, "register");
      const mail = codeMail(env, "登録", code);
      const r = await sendMail(env, email, mail.subject, mail.text);
      await audit(env, email, "register.request");
      return json({ ok: true, next: "code", ...(r.dev ? { devCode: code } : {}) });
    }
    if (m === "POST" && route === "/auth/register/confirm") {
      const b = await readJson(request); const email = normEmail(b.email);
      const c = await consumeCode(env, email, "register", b.code); if (!c.ok) return err(c.error);
      await env.DB.prepare("UPDATE admins SET active=1, updated_at=? WHERE email=?").bind(now(), email).run();
      const a = await env.DB.prepare("SELECT id FROM admins WHERE email=?").bind(email).first();
      const s = await createSession(env, request, a.id);
      await audit(env, email, "register.confirm");
      return json({ ok: true, user: { email } }, 200, { "set-cookie": cookieHeader("pd_session", s.token, s.maxAge, request) });
    }

    if (m === "POST" && route === "/auth/login") {
      const b = await readJson(request); const email = normEmail(b.email);
      const a = await env.DB.prepare("SELECT * FROM admins WHERE email=? AND active=1").bind(email).first();
      // 存在しないアドレスでも同じ応答（アドレスの有無を漏らさない）
      if (!a) { await audit(env, email, "login.unknown"); return err("メールアドレスまたはパスワードが違います", 401); }
      if (a.locked_until > now()) return err(`ログインが一時的にロックされています。${Math.ceil((a.locked_until - now()) / 60)}分後にお試しください。`, 423);
      if (!(await verifyPassword(String(b.password || ""), a.salt, a.pass_hash))) {
        const fc = a.failed_count + 1;
        await env.DB.prepare("UPDATE admins SET failed_count=?, locked_until=? WHERE id=?").bind(fc, fc >= 10 ? now() + 900 : 0, a.id).run();
        await audit(env, email, "login.badpass");
        return err("メールアドレスまたはパスワードが違います", 401);
      }
      await env.DB.prepare("UPDATE admins SET failed_count=0, locked_until=0 WHERE id=?").bind(a.id).run();
      // 信頼済み端末なら2段階目を省略
      const trust = await getSession(env, request, "trust");
      if (trust && trust.admin_id === a.id) {
        const s = await createSession(env, request, a.id);
        await audit(env, email, "login.trusted");
        return json({ ok: true, user: { email } }, 200, { "set-cookie": cookieHeader("pd_session", s.token, s.maxAge, request) });
      }
      const code = await issueCode(env, email, "login");
      const mail = codeMail(env, "ログイン", code);
      const r = await sendMail(env, email, mail.subject, mail.text);
      await audit(env, email, "login.code_sent");
      return json({ ok: true, next: "code", ...(r.dev ? { devCode: code } : {}) });
    }
    if (m === "POST" && route === "/auth/login/verify") {
      const b = await readJson(request); const email = normEmail(b.email);
      const a = await env.DB.prepare("SELECT id FROM admins WHERE email=? AND active=1").bind(email).first();
      if (!a) return err("ログインをやり直してください", 401);
      const c = await consumeCode(env, email, "login", b.code); if (!c.ok) return err(c.error);
      const s = await createSession(env, request, a.id);
      const headers = new Headers({ "set-cookie": cookieHeader("pd_session", s.token, s.maxAge, request) });
      if (b.remember) { const t = await createSession(env, request, a.id, "trust"); headers.append("set-cookie", cookieHeader("pd_trust", t.token, t.maxAge, request)); }
      await audit(env, email, "login.ok");
      headers.set("content-type", "application/json; charset=utf-8"); headers.set("cache-control", "no-store");
      return new Response(JSON.stringify({ ok: true, user: { email } }), { status: 200, headers });
    }
    if (m === "POST" && route === "/auth/logout") {
      await destroySession(env, request);
      return json({ ok: true }, 200, { "set-cookie": cookieHeader("pd_session", "", 0, request) });
    }

    if (m === "POST" && route === "/auth/forgot") {
      const b = await readJson(request); const email = normEmail(b.email);
      const a = await env.DB.prepare("SELECT id FROM admins WHERE email=? AND active=1").bind(email).first();
      let devCode;
      if (a) {
        const code = await issueCode(env, email, "reset");
        const mail = codeMail(env, "パスワード再設定", code);
        const r = await sendMail(env, email, mail.subject, mail.text);
        if (r.dev) devCode = code;
        await audit(env, email, "reset.request");
      }
      // 登録の有無に関わらず同じ応答
      return json({ ok: true, next: "code", ...(devCode ? { devCode } : {}) });
    }
    if (m === "POST" && route === "/auth/reset") {
      const b = await readJson(request); const email = normEmail(b.email);
      const pe = validPassword(b.password); if (pe) return err(pe);
      const c = await consumeCode(env, email, "reset", b.code); if (!c.ok) return err(c.error);
      const a = await env.DB.prepare("SELECT id FROM admins WHERE email=? AND active=1").bind(email).first();
      if (!a) return err("やり直してください");
      const { hash, salt } = await hashPassword(b.password);
      await env.DB.prepare("UPDATE admins SET pass_hash=?, salt=?, failed_count=0, locked_until=0, updated_at=? WHERE id=?").bind(hash, salt, now(), a.id).run();
      await env.DB.prepare("DELETE FROM sessions WHERE admin_id=?").bind(a.id).run(); // 他端末のログインも解除
      await sendMail(env, email, `【${env.SITE_NAME} 更新ページ】パスワードが変更されました`, `更新ページのパスワードが再設定されました。\n心当たりがない場合は、至急「パスワードを忘れた方」から再設定してください。`).catch(() => {});
      await audit(env, email, "reset.done");
      return json({ ok: true });
    }

    // ---------- ログイン後: 設定 ----------
    if (m === "POST" && route === "/auth/change-password") {
      const s = await requireAdmin(env, request); const b = await readJson(request);
      const a = await env.DB.prepare("SELECT * FROM admins WHERE id=?").bind(s.admin_id).first();
      if (!(await verifyPassword(String(b.current || ""), a.salt, a.pass_hash))) return err("現在のパスワードが違います", 401);
      const pe = validPassword(b.password); if (pe) return err(pe);
      const { hash, salt } = await hashPassword(b.password);
      await env.DB.prepare("UPDATE admins SET pass_hash=?, salt=?, updated_at=? WHERE id=?").bind(hash, salt, now(), a.id).run();
      await audit(env, a.email, "password.change");
      return json({ ok: true });
    }
    if (m === "POST" && route === "/auth/change-email") {
      const s = await requireAdmin(env, request); const b = await readJson(request);
      const a = await env.DB.prepare("SELECT * FROM admins WHERE id=?").bind(s.admin_id).first();
      const newEmail = normEmail(b.newEmail);
      if (!validEmail(newEmail)) return err("新しいメールアドレスの形式が正しくありません");
      if (newEmail === a.email) return err("現在と同じメールアドレスです");
      if (!(await verifyPassword(String(b.password || ""), a.salt, a.pass_hash))) return err("パスワードが違います", 401);
      const dup = await env.DB.prepare("SELECT id FROM admins WHERE email=?").bind(newEmail).first();
      if (dup) return err("そのメールアドレスは既に使われています");
      const code = await issueCode(env, a.email, "change_email", { newEmail });
      const mail = codeMail(env, "メールアドレス変更", code);
      const r = await sendMail(env, newEmail, mail.subject, mail.text + `\n\n（新しいメールアドレス ${newEmail} に届いています）`);
      await audit(env, a.email, "email.change_request", newEmail);
      return json({ ok: true, next: "code", ...(r.dev ? { devCode: code } : {}) });
    }
    if (m === "POST" && route === "/auth/change-email/confirm") {
      const s = await requireAdmin(env, request); const b = await readJson(request);
      const a = await env.DB.prepare("SELECT * FROM admins WHERE id=?").bind(s.admin_id).first();
      const c = await consumeCode(env, a.email, "change_email", b.code); if (!c.ok) return err(c.error);
      const newEmail = c.payload && c.payload.newEmail; if (!newEmail) return err("やり直してください");
      await env.DB.prepare("UPDATE admins SET email=?, updated_at=? WHERE id=?").bind(newEmail, now(), a.id).run();
      await sendMail(env, a.email, `【${env.SITE_NAME} 更新ページ】メールアドレスが変更されました`, `更新ページのログイン用メールアドレスが ${a.email} から ${newEmail} に変更されました。\n心当たりがない場合は管理者（薫）にご連絡ください。`).catch(() => {});
      await audit(env, a.email, "email.changed", newEmail);
      return json({ ok: true, user: { email: newEmail } });
    }

    // ---------- 記事 ----------
    if (m === "POST" && route === "/posts") {
      const s = await requireAdmin(env, request); const b = await readJson(request);
      const title = String(b.title || "").trim(), date = String(b.date || "").replace(/-/g, "."), cat = String(b.category || "お知らせ"), body = String(b.body || "");
      if (!title || !/^\d{4}\.\d{2}\.\d{2}$/.test(date) || !body.trim()) return err("タイトル・日付・本文は必須です");
      let slug = String(b.slug || "").trim();
      const t = now();
      if (slug) {
        const cur = await env.DB.prepare("SELECT slug FROM posts WHERE slug=?").bind(slug).first();
        if (!cur) return err("記事が見つかりません", 404);
        await env.DB.prepare("UPDATE posts SET title=?, date=?, category=?, image=?, body=?, updated_at=? WHERE slug=?").bind(title, date, cat, b.image || null, body, t, slug).run();
      } else {
        slug = date.replace(/\./g, "-") + "-" + randomHex(3);
        await env.DB.prepare("INSERT INTO posts(slug,title,date,category,image,body,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)").bind(slug, title, date, cat, b.image || null, body, t, t).run();
      }
      await audit(env, s.email, "post.save", slug);
      return json({ ok: true, slug });
    }
    if (m === "DELETE" && route.startsWith("/posts/")) {
      const s = await requireAdmin(env, request);
      const slug = decodeURIComponent(route.slice(7));
      const cur = await env.DB.prepare("SELECT image FROM posts WHERE slug=?").bind(slug).first();
      if (!cur) return err("記事が見つかりません", 404);
      await env.DB.prepare("DELETE FROM posts WHERE slug=?").bind(slug).run();
      if (cur.image && cur.image.startsWith("/img/")) await env.IMG.delete(cur.image.slice(5)).catch(() => {});
      await audit(env, s.email, "post.delete", slug);
      return json({ ok: true });
    }
    if (m === "POST" && route === "/upload") {
      const s = await requireAdmin(env, request);
      const ct = request.headers.get("content-type") || "";
      if (!/^image\/(jpeg|png|gif|webp)$/.test(ct)) return err("画像は JPEG / PNG / GIF / WebP のみアップロードできます");
      const buf = await request.arrayBuffer();
      if (buf.byteLength > 4 * ONE_MB) return err("画像が大きすぎます（4MBまで）。縮小してからお試しください。");
      const ext = ct.split("/")[1].replace("jpeg", "jpg");
      const name = `${new Date().toISOString().slice(0, 10)}-${randomHex(4)}.${ext}`;
      await env.IMG.put(name, buf, { metadata: { ct } });
      await audit(env, s.email, "upload", name);
      return json({ ok: true, url: "/img/" + name });
    }

    // ---------- 沿革・事業実績 ----------
    if (m === "PUT" && route === "/history") {
      const s = await requireAdmin(env, request); const b = await readJson(request);
      const rows = Array.isArray(b.history) ? b.history : [];
      const stmts = [env.DB.prepare("DELETE FROM history")];
      rows.forEach((r, i) => { const y = String(r.year || "").trim(), t = String(r.text || "").trim(); if (y || t) stmts.push(env.DB.prepare("INSERT INTO history(year,wareki,text,sort) VALUES(?,?,?,?)").bind(y, String(r.wareki || "").trim(), t, i)); });
      await env.DB.batch(stmts);
      await audit(env, s.email, "history.save", rows.length);
      return json({ ok: true });
    }

    return err("Not found", 404);
  } catch (e) {
    if (e instanceof Response) return e;
    console.log("API error", route, e && e.stack);
    return err(e && e.message ? e.message : "サーバーエラーが発生しました", 500);
  }
}
