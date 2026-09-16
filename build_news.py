#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
NEWS ビルドスクリプト（依存ライブラリなし・Python 3.8+）

  news/ フォルダの Markdown 記事 → news.html（一覧）/ post-*.html（個別）/ index.html のニュース欄 を生成する。

  記事ファイルの書き方は NEWS_HOWTO.md を参照。
  実行:  python build_news.py
  GitHub に news/*.md を追加すると GitHub Actions が自動で実行する（.github/workflows/build-news.yml）。
"""
import re, html, sys
from pathlib import Path
from datetime import date

ROOT = Path(__file__).resolve().parent
NEWS_DIR = ROOT / "news"
TEMPLATE_LIST = ROOT / "templates" / "news_list.html"
TEMPLATE_POST = ROOT / "templates" / "news_post.html"
DEFAULT_CATS = ["お知らせ", "実績", "専門家支援", "セミナー", "メディア"]


def parse_front_matter(text):
    """--- で囲まれた先頭ブロックを dict に。本文はその後ろ。"""
    m = re.match(r"^﻿?---\s*\n(.*?)\n---\s*\n?(.*)$", text, re.S)
    if not m:
        return {}, text
    meta = {}
    for line in m.group(1).splitlines():
        if ":" in line:
            k, v = line.split(":", 1)
            meta[k.strip().lower()] = v.strip().strip('"').strip("'")
    return meta, m.group(2)


def md_to_html(md):
    """最小限の Markdown → HTML（段落・改行・見出し・箇条書き・画像・リンク・太字）。"""
    out = []
    paras = re.split(r"\n\s*\n", md.strip())
    for p in paras:
        lines = p.strip().splitlines()
        if not lines:
            continue
        if all(re.match(r"^\s*[-*・●]\s*", l) for l in lines):
            items = "".join(f"<li>{inline(re.sub(r'^\s*[-*・●]\s*', '', l))}</li>" for l in lines)
            out.append(f"<ul>{items}</ul>")
        elif re.match(r"^#{1,3}\s", lines[0]):
            lvl = len(lines[0]) - len(lines[0].lstrip("#"))
            out.append(f"<h{lvl+1}>{inline(lines[0].lstrip('#').strip())}</h{lvl+1}>")
        elif re.match(r"^!\[.*?\]\(.*?\)\s*$", lines[0]) and len(lines) == 1:
            out.append(inline(lines[0]))
        else:
            out.append("<p>" + "<br>".join(inline(l) for l in lines) + "</p>")
    return "\n".join(out)


def inline(s):
    s = html.escape(s, quote=False)
    s = re.sub(r"!\[(.*?)\]\((.*?)\)", r'<img src="\2" alt="\1" loading="lazy">', s)
    s = re.sub(r"\[(.*?)\]\((.*?)\)", r'<a href="\2" target="_blank" rel="noopener">\1</a>', s)
    s = re.sub(r"\*\*(.+?)\*\*", r"<strong>\1</strong>", s)
    s = re.sub(r"(?<![\"'=])(https?://[^\s<]+)", r'<a href="\1" target="_blank" rel="noopener">\1</a>', s)
    return s


def strip_tags(h):
    return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", "", h)).strip()


def load_posts():
    posts = []
    for f in sorted(NEWS_DIR.glob("*.md")):
        if f.name.lower().startswith("readme"):
            continue
        meta, body = parse_front_matter(f.read_text(encoding="utf-8"))
        slug = f.stem
        m = re.match(r"^(\d{4})-(\d{2})-(\d{2})", slug)
        d = meta.get("date") or (f"{m.group(1)}.{m.group(2)}.{m.group(3)}" if m else date.today().strftime("%Y.%m.%d"))
        d = d.replace("-", ".").replace("/", ".")
        title = meta.get("title") or slug
        cat = meta.get("category") or "お知らせ"
        img = meta.get("image", "").strip()
        if img and not img.startswith(("http", "images/")):
            img = "images/news/" + img
        body_html = md_to_html(body)
        excerpt = strip_tags(body_html)
        posts.append(dict(slug=slug, date=d, title=title, cat=cat, img=img, body=body_html,
                          excerpt=(excerpt[:120] + "…") if len(excerpt) > 120 else excerpt))
    posts.sort(key=lambda p: (p["date"], p["slug"]), reverse=True)
    return posts


def render(tpl, **kw):
    for k, v in kw.items():
        tpl = tpl.replace("{{" + k + "}}", v)
    return tpl


def main():
    posts = load_posts()
    if not posts:
        sys.exit("news/ に記事がありません")
    tpl_list = TEMPLATE_LIST.read_text(encoding="utf-8")
    tpl_post = TEMPLATE_POST.read_text(encoding="utf-8")

    # 一覧
    cards = []
    for p in posts:
        img = (f'<div class="news-card__img" style="background-image:url(\'{p["img"]}\');"></div>' if p["img"]
               else '<div class="news-card__img news-card__img--placeholder"></div>')
        cards.append(f"""      <a href="post-{p['slug']}.html" class="news-card">
        {img}
        <div class="news-card__body">
          <div class="news-card__meta">
            <time class="news-card__date">{p['date']}</time>
            <span class="news-card__cat">{html.escape(p['cat'])}</span>
          </div>
          <h3 class="news-card__title">{html.escape(p['title'])}</h3>
          <p class="news-card__desc">{html.escape(p['excerpt'])}</p>
          <span class="news-card__more">続きを読む <span class="arrow">→</span></span>
        </div>
      </a>""")
    (ROOT / "news.html").write_text(render(tpl_list, CARDS="\n".join(cards)), encoding="utf-8")

    # 個別ページ
    for p in posts:
        hero = f'<div class="article__hero" style="background-image:url(\'{p["img"]}\');"></div>' if p["img"] else ""
        out = render(tpl_post, TITLE=html.escape(p["title"]), DATE=p["date"], CAT=html.escape(p["cat"]),
                     DESC=html.escape(p["excerpt"]), HERO=hero, BODY=p["body"])
        (ROOT / f"post-{p['slug']}.html").write_text(out, encoding="utf-8")

    # index.html のニュース欄（最新4件）
    idx = ROOT / "index.html"
    s = idx.read_text(encoding="utf-8")
    items = "\n".join(f"""      <li class="news__item">
        <time class="news__date">{p['date']}</time>
        <span class="news__cat">{html.escape(p['cat'])}</span>
        <a href="post-{p['slug']}.html" class="news__link">{html.escape(p['title'])}</a>
      </li>""" for p in posts[:4])
    new_s = re.sub(r'(<ul class="news__list">)\n.*?\n(    </ul>)', lambda m: f"{m.group(1)}\n{items}\n{m.group(2)}", s, count=1, flags=re.S)
    if new_s != s:
        idx.write_text(new_s, encoding="utf-8")

    # 古い post-XXX.html（md に対応しないもの）は残す（外部リンク保護）
    print(f"OK: {len(posts)} 記事 → news.html / post-*.html / index.html")


if __name__ == "__main__":
    main()
