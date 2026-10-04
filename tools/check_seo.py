# -*- coding: utf-8 -*-
"""SEO 要素の機械チェック（読むだけ）。

  ローカル:  php -S 127.0.0.1:8790 _dev_router.php  を起動してから
             python tools/check_seo.py                       （既定で http://127.0.0.1:8790。静的コピーも検査）
  本番:      python tools/check_seo.py https://prodesign.co.jp （差し替え当日に実行）

検査内容
  - 全ページ（固定6ページ＋sitemap.xml に載っている全記事）:
      title（社名と ProDesign を含む・重複なし）/ description（重複なし・長さ）/ canonical（本番の絶対URL）/
      OGP / Twitter Card / アイコン / 構造化データ（JSON として妥当か、必要な型と項目があるか）/
      h1 が1つ・見出しの階層が飛んでいない / img に alt・width・height があるか /
      ★ noindex が入っていないこと（本番出力の必須条件）
  - sitemap.xml / feed.xml が XML として妥当、robots.txt に Sitemap 行と Disallow がある
  - Google アナリティクス 4: config.php の ga4_id が設定されていれば公開ページ（＋404ページ）にタグが出ていること、
      未設定なら出ていないこと。更新ページ（/admin）と静的コピー（プレビュー）には常に出ていないこと
      （ローカルは config を読んで期待値を決める。本番は --ga4=G-XXXXXXX か --ga4=none で期待値を渡す。省略時は検出結果の表示と一貫性の確認だけ）
  - リポジトリの静的コピー（GitHub Pages プレビュー用）には noindex,nofollow が入り、canonical が本番URLを指すこと
"""
import json, os, re, sys, urllib.request, urllib.error, html as htmlmod
import xml.etree.ElementTree as ET

BASE = (sys.argv[1] if len(sys.argv) > 1 and sys.argv[1].startswith('http') else 'http://127.0.0.1:8790').rstrip('/')
LOCAL = '127.0.0.1' in BASE or 'localhost' in BASE
PROD = 'https://prodesign.co.jp'
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
errors, warns = [], []
GA_RE = re.compile(r'googletagmanager\.com/gtag/js\?id=(G-[A-Z0-9]+)')


def ga_expected():
    """期待する測定ID。'' は「出ていないこと」、None は「期待値なし（検出結果の一貫性だけ見る）」"""
    for a in sys.argv[1:]:
        if a.startswith('--ga4='):
            v = a.split('=', 1)[1]
            return '' if v in ('', 'none') else v
    if LOCAL:
        import subprocess
        code = "$c=require 'config.php'; if(is_file('config.local.php')) $c=array_merge($c, require 'config.local.php'); echo $c['ga4_id'] ?? '';"
        try:
            return subprocess.run(['php', '-r', code], cwd=ROOT, capture_output=True, text=True, timeout=30).stdout.strip()
        except Exception as e:
            warn('ga4', 'config を読めない: %s' % e)
    return None


def ga_ids(doc):
    ids = set(GA_RE.findall(doc))
    cfg = set(re.findall(r"gtag\('config','(G-[A-Z0-9]+)'\)", doc))
    return ids, cfg


def fetch(path):
    req = urllib.request.Request(BASE + path, headers={'User-Agent': 'pd-seo-check'})
    try:
        r = urllib.request.urlopen(req, timeout=30)
        return r.status, r.read().decode('utf-8', 'replace'), r.headers
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace'), e.headers


def err(where, msg): errors.append('%s: %s' % (where, msg))
def warn(where, msg): warns.append('%s: %s' % (where, msg))


def meta(doc, attr, name):
    m = re.search(r'<meta\s+%s="%s"\s+content="([^"]*)"' % (attr, re.escape(name)), doc)
    return htmlmod.unescape(m.group(1)) if m else None


def jsonld(doc, where):
    nodes = []
    for raw in re.findall(r'<script type="application/ld\+json">(.*?)</script>', doc, re.S):
        try:
            d = json.loads(raw)
        except Exception as e:
            err(where, 'JSON-LD が JSON として不正: %s' % e); continue
        if d.get('@context') != 'https://schema.org': err(where, 'JSON-LD の @context が違う')
        nodes += d.get('@graph', [d])
    return nodes


def types(n):
    t = n.get('@type'); return t if isinstance(t, list) else [t]


def check_page(path, kind, doc, where, expect_noindex=False):
    head = doc.split('</head>')[0]
    body = doc.split('</head>', 1)[-1]
    titles = re.findall(r'<title>(.*?)</title>', head, re.S)
    if len(titles) != 1: err(where, 'title が %d 個' % len(titles))
    title = htmlmod.unescape(titles[0]) if titles else ''
    if '株式会社プロデザイン' not in title or 'ProDesign' not in title: err(where, 'title に社名（株式会社プロデザイン / ProDesign）が無い: ' + title)
    desc = meta(head, 'name', 'description')
    if not desc: err(where, 'description が無い')
    elif kind != 'post' and not (80 <= len(desc) <= 140): warn(where, 'description の長さ %d 字' % len(desc))
    for ng in ('3D CAD', '３Ｄ', '3DCAD'):
        if kind != 'post' and ((desc and ng in desc) or ng in title): err(where, '文言方針に反する語: ' + ng)
    robots = meta(head, 'name', 'robots') or ''
    if expect_noindex:
        if 'noindex' not in robots: err(where, 'プレビュー用の静的コピーなのに noindex が無い')
    else:
        if 'noindex' in doc.lower().split('</head>')[0]: err(where, '★ noindex が入っている（本番では絶対に不可）')
    canon = re.search(r'<link rel="canonical" href="([^"]+)"', head)
    want = PROD + path
    if not canon: err(where, 'canonical が無い')
    elif canon.group(1) != want: err(where, 'canonical が %s（期待 %s）' % (canon.group(1), want))
    for p in ('og:title', 'og:description', 'og:url', 'og:image', 'og:site_name', 'og:locale', 'og:type'):
        if not meta(head, 'property', p): err(where, p + ' が無い')
    if meta(head, 'property', 'og:url') != want: err(where, 'og:url が canonical と違う')
    if not (meta(head, 'property', 'og:image') or '').startswith(PROD + '/'): err(where, 'og:image が本番の絶対URLでない')
    if meta(head, 'name', 'twitter:card') != 'summary_large_image': err(where, 'twitter:card が無い')
    if 'rel="icon"' not in head or 'rel="apple-touch-icon"' not in head: err(where, 'favicon / apple-touch-icon の指定が無い')
    if 'rel="preload" as="image"' not in head: warn(where, 'ヒーロー画像の preload が無い')
    # ---- 構造化データ
    nodes = jsonld(head, where)
    by = {}
    for n in nodes:
        for t in types(n): by.setdefault(t, []).append(n)
    org = (by.get('Organization') or [None])[0]
    if not org or 'LocalBusiness' not in types(org): err(where, 'Organization / LocalBusiness が無い')
    else:
        for k in ('name', 'alternateName', 'url', 'logo', 'address', 'telephone', 'founder'):
            if k not in org: err(where, 'Organization.%s が無い' % k)
        if org.get('name') != '株式会社プロデザイン': err(where, 'Organization.name が違う')
    ws = (by.get('WebSite') or [None])[0]
    if not ws or ws.get('name') != '株式会社プロデザイン' or 'ProDesign' not in (ws.get('alternateName') or []): err(where, 'WebSite（name / alternateName）が無い')
    if kind != 'index':
        bc = (by.get('BreadcrumbList') or [None])[0]
        if not bc or len(bc.get('itemListElement', [])) < 2: err(where, 'BreadcrumbList が無い')
        elif bc['itemListElement'][-1].get('item') != want: err(where, 'パンくずの最後が自分のURLでない')
    if kind == 'business' and len(by.get('Service', [])) != 3: err(where, 'Service が3つ無い')
    if kind == 'post':
        a = (by.get('Article') or [None])[0]
        if not a: err(where, 'Article が無い')
        else:
            for k in ('headline', 'datePublished', 'dateModified', 'image', 'author', 'publisher'):
                if not a.get(k): err(where, 'Article.%s が無い' % k)
            for k in ('datePublished', 'dateModified'):
                if a.get(k) and not re.match(r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$', a[k]): err(where, 'Article.%s の形式が不正: %s' % (k, a[k]))
    # ---- 見出し・画像・ランドマーク
    hs = [int(x) for x in re.findall(r'<h([1-6])[\s>]', body)]
    if hs.count(1) != 1: err(where, 'h1 が %d 個' % hs.count(1))
    prev = 0
    for lv in hs:
        if prev and lv > prev + 1: err(where, '見出しの階層が飛んでいる（h%d → h%d）' % (prev, lv)); break
        prev = lv
    for tag in ('<main', '<header', '<footer', '<nav class="nav" aria-label='):
        if tag not in body: err(where, tag + ' が無い')
    if body.count('<main') != 1: err(where, '<main> が %d 個' % body.count('<main'))
    for img in re.findall(r'<img\b[^>]*>', body):
        if 'alt="' not in img: err(where, 'alt の無い img: ' + img[:80])
        elif 'alt=""' in img: warn(where, 'alt が空の img: ' + img[:80])
        if 'width=' not in img or 'height=' not in img: warn(where, 'width/height の無い img（記事本文の画像）: ' + img[:80])
    return title, desc


def main():
    pages = [('/', 'index'), ('/business.html', 'business'), ('/works.html', 'works'), ('/company.html', 'company'), ('/news.html', 'news'), ('/contact.html', 'contact')]
    # ---- sitemap
    st, sm, hd = fetch('/sitemap.xml')
    locs = []
    if st != 200: err('sitemap.xml', 'status %s' % st)
    else:
        try:
            root = ET.fromstring(sm.encode('utf-8'))
            ns = {'s': 'http://www.sitemaps.org/schemas/sitemap/0.9'}
            for u in root.findall('s:url', ns):
                loc = u.find('s:loc', ns).text; lm = u.find('s:lastmod', ns)
                if lm is None or not re.match(r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$', lm.text or ''): err('sitemap.xml', 'lastmod が無い/不正: ' + loc)
                if not loc.startswith(PROD + '/'): err('sitemap.xml', '本番URLでない: ' + loc)
                locs.append(loc[len(PROD):])
        except Exception as e:
            err('sitemap.xml', 'XML として不正: %s' % e)
    for p, _ in pages:
        if p not in locs: err('sitemap.xml', p + ' が載っていない')
    posts = [l for l in locs if l.startswith('/post/')]
    if len(locs) != len(set(locs)): err('sitemap.xml', 'URL が重複')
    # ---- 各ページ
    seen_t, seen_d = {}, {}
    for path, kind in pages + [(p, 'post') for p in posts]:
        st, doc, hd = fetch(path)
        if st != 200: err(path, 'status %s' % st); continue
        if 'noindex' in (hd.get('X-Robots-Tag') or ''): err(path, '★ X-Robots-Tag に noindex')
        t, d = check_page(path, kind, doc, path)
        if t in seen_t: err(path, 'title が %s と重複' % seen_t[t])
        seen_t[t] = path
        if d in seen_d: err(path, 'description が %s と重複' % seen_d[d])
        seen_d[d] = path
    # ---- Google アナリティクス 4
    exp = ga_expected(); found = {}
    st404, doc404, _ = fetch('/this-page-does-not-exist')
    docs = [(path, fetch(path)[1]) for path, _ in pages + [(p, 'post') for p in posts]] + [('404ページ', doc404)]
    for where, doc in docs:
        ids, cfgids = ga_ids(doc)
        if ids != cfgids: err(where, 'GA4 の読み込みIDと config のIDが不一致: %s / %s' % (sorted(ids), sorted(cfgids)))
        if len(ids) > 1: err(where, 'GA4 のタグが複数: %s' % sorted(ids))
        cur = next(iter(ids)) if ids else ''
        found[cur] = found.get(cur, 0) + 1
        if exp is not None and cur != exp: err(where, 'GA4: 期待 %s / 実際 %s' % (exp or '（タグなし）', cur or '（タグなし）'))
    if len(found) > 1: err('ga4', 'ページによって GA4 の有無・IDが違う: %s' % found)
    st, adm, _ = fetch('/admin')
    if st == 200 and ('googletagmanager' in adm or 'gtag(' in adm): err('/admin', '更新ページに GA4 のタグが出ている')
    ga_state = 'あり（%s）' % next(iter(found)) if (len(found) == 1 and next(iter(found))) else 'なし' if len(found) == 1 else '不一致'
    ga_state += ' / 期待値: ' + ('指定なし' if exp is None else (exp or 'タグなし'))
    # ---- feed / robots
    st, fd, _ = fetch('/feed.xml')
    if st != 200: err('feed.xml', 'status %s' % st)
    else:
        try:
            r = ET.fromstring(fd.encode('utf-8'))
            n = len(r.findall('./channel/item'))
            if n == 0: warn('feed.xml', '記事が0件')
        except Exception as e:
            err('feed.xml', 'XML として不正: %s' % e)
    st, rb, _ = fetch('/robots.txt')
    if st != 200: err('robots.txt', 'status %s' % st)
    else:
        if 'Sitemap: %s/sitemap.xml' % PROD not in rb: err('robots.txt', 'Sitemap 行が無い')
        for d in ('/admin', '/api/', '/tools/', '/data/'):
            if 'Disallow: ' + d not in rb: err('robots.txt', 'Disallow %s が無い' % d)
        if re.search(r'^Disallow:\s*/\s*$', rb, re.M): err('robots.txt', '★ サイト全体を Disallow している')
    # ---- 静的コピー（プレビュー用）は noindex
    n_static = 0
    if LOCAL:
        for path, kind in pages:
            f = os.path.join(ROOT, 'index.html' if path == '/' else path.lstrip('/'))
            sdoc = open(f, encoding='utf-8').read()
            if 'googletagmanager' in sdoc: err('static:' + os.path.basename(f), 'プレビュー用の静的コピーに GA4 のタグが入っている')
            check_page(path, kind, sdoc, 'static:' + os.path.basename(f), expect_noindex=True); n_static += 1
        for p in posts:
            f = os.path.join(ROOT, p.lstrip('/'), 'index.html')
            if not os.path.exists(f): err('static:' + p, '静的コピーが無い（php tools/export_static.php を実行）'); continue
            sdoc = open(f, encoding='utf-8').read()
            if 'googletagmanager' in sdoc: err('static:' + p, 'プレビュー用の静的コピーに GA4 のタグが入っている')
            check_page(p, 'post', sdoc, 'static:' + p, expect_noindex=True); n_static += 1
    print('base:', BASE)
    print('検査したページ: %d（固定 %d ＋ 記事 %d）、静的コピー: %d' % (len(pages) + len(posts), len(pages), len(posts), n_static))
    print('GA4 タグ:', ga_state)
    for w in warns: print('  WARN', w)
    if errors:
        print('FAILED: %d' % len(errors))
        for e in errors: print('  NG', e)
        return 1
    print('RESULT: ALL OK（警告 %d 件）' % len(warns))
    return 0


if __name__ == '__main__':
    sys.exit(main())
