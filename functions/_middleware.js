// 静的ページに D1 の内容を差し込む（news.html の一覧 / index.html の最新4件 / company.html の事業実績）
// 旧URL post-<slug>.html → /post/<slug> にリダイレクト
import { esc, excerpt, imgUrl } from "./_lib.js";

const NO_CACHE = { "cache-control": "public, max-age=60" };

async function posts(env) {
  const rows = (await env.DB.prepare("SELECT slug,title,date,category,image,body FROM posts ORDER BY date DESC, slug DESC").all()).results;
  return rows;
}
function card(p) {
  const img = p.image ? `<div class="news-card__img" style="background-image:url('${esc(imgUrl(p.image))}');"></div>` : `<div class="news-card__img news-card__img--placeholder"></div>`;
  return `      <a href="/post/${esc(p.slug)}" class="news-card">
        ${img}
        <div class="news-card__body">
          <div class="news-card__meta">
            <time class="news-card__date">${esc(p.date)}</time>
            <span class="news-card__cat">${esc(p.category)}</span>
          </div>
          <h3 class="news-card__title">${esc(p.title)}</h3>
          <p class="news-card__desc">${esc(excerpt(p.body))}</p>
          <span class="news-card__more">続きを読む <span class="arrow">→</span></span>
        </div>
      </a>`;
}

export async function onRequest(context) {
  const { request, env, next } = context;
  const url = new URL(request.url);
  const p = url.pathname;

  // 旧URL互換（Pages は /post-x.html → /post-x にも正規化するので両方受ける）
  const old = p.match(/^\/post-([\w-]+?)(?:\.html)?$/);
  if (old) return Response.redirect(url.origin + "/post/" + old[1], 301);

  // Pages は /news.html を /news に正規化する。どちらのパスでも差し込む
  const isIndex = p === "/" || p === "/index" || p === "/index.html";
  const isNews = p === "/news" || p === "/news.html";
  const isCompany = p === "/company" || p === "/company.html";
  if (!(isIndex || isNews || isCompany) || !env.DB) return next();

  const res = await next();
  if (!(res.headers.get("content-type") || "").includes("text/html")) return res;
  let html = await res.text();
  try {
    if (isNews) {
      const list = await posts(env);
      html = html.replace(/(<div class="news-grid">)[\s\S]*?(\r?\n    <\/div>\r?\n  <\/div>\r?\n<\/main>)/, (_, a, b) => a + "\n" + (list.length ? list.map(card).join("\n") : `<p class="hint" style="padding:40px 0;color:#888">お知らせはまだありません。</p>`) + b);
    } else if (isIndex) {
      const list = (await posts(env)).slice(0, 4);
      const items = list.map((q) => `      <li class="news__item">
        <time class="news__date">${esc(q.date)}</time>
        <span class="news__cat">${esc(q.category)}</span>
        <a href="/post/${esc(q.slug)}" class="news__link">${esc(q.title)}</a>
      </li>`).join("\n");
      html = html.replace(/(<ul class="news__list">)[\s\S]*?(\r?\n    <\/ul>)/, (_, a, b) => a + "\n" + items + b);
    } else if (isCompany) {
      const rows = (await env.DB.prepare("SELECT year,wareki,text FROM history ORDER BY sort, id").all()).results;
      const h = '      <div class="history">\n' + rows.map((r) => `        <div class="history__item"><div class="history__year">${esc(r.year)}<small>${esc(r.wareki || "")}</small></div><div class="history__body">${esc(r.text)}</div></div>`).join("\n") + "\n      </div>\n";
      html = html.replace(/(<!-- HISTORY:START[^\n]*-->\r?\n)[\s\S]*?(      <!-- HISTORY:END -->)/, (_, a, b) => a + h + b);
    }
  } catch (e) { console.log("middleware inject error", e && e.message); }
  return new Response(html, { status: res.status, headers: { "content-type": "text/html; charset=utf-8", ...NO_CACHE } });
}
