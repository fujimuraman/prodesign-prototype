// 記事ページ /post/<slug>（templates/news_post.html を雛形にサーバー側で生成）
import { esc, mdToHtml, excerpt, imgUrl } from "../_lib.js";

export async function onRequestGet({ request, env, params }) {
  const slug = params.slug;
  const p = await env.DB.prepare("SELECT * FROM posts WHERE slug=?").bind(slug).first();
  const url = new URL(request.url);
  const tplRes = await env.ASSETS.fetch(new Request(url.origin + "/templates/news_post.html"));
  let tpl = await tplRes.text();
  if (!p) {
    tpl = tpl.replace(/\{\{TITLE\}\}/g, "記事が見つかりません").replace(/\{\{DATE\}\}/g, "").replace(/\{\{CAT\}\}/g, "NEWS").replace(/\{\{DESC\}\}/g, "")
      .replace("{{HERO}}", "").replace("{{BODY}}", "<p>この記事は削除されたか、URLが間違っています。</p>");
    return new Response(tpl, { status: 404, headers: { "content-type": "text/html; charset=utf-8" } });
  }
  const img = imgUrl(p.image);
  const hero = img ? `<div class="article__hero" style="background-image:url('${esc(img)}');"></div>` : "";
  // 相対パス（images/..., css/...）を /post/ 配下でも解決できるようにする
  tpl = tpl.replace("<head>", '<head>\n<base href="/">');
  tpl = tpl.replace(/\{\{TITLE\}\}/g, esc(p.title)).replace(/\{\{DATE\}\}/g, esc(p.date)).replace(/\{\{CAT\}\}/g, esc(p.category))
    .replace(/\{\{DESC\}\}/g, esc(excerpt(p.body, 150))).replace("{{HERO}}", hero).replace("{{BODY}}", mdToHtml(p.body));
  // OGP
  tpl = tpl.replace("</head>", `<meta property="og:title" content="${esc(p.title)}">\n<meta property="og:type" content="article">\n<meta property="og:url" content="${esc(url.origin + "/post/" + slug)}">\n${img ? `<meta property="og:image" content="${esc(new URL(img, url.origin).href)}">\n` : ""}</head>`);
  return new Response(tpl, { headers: { "content-type": "text/html; charset=utf-8", "cache-control": "public, max-age=60" } });
}
