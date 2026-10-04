# -*- coding: utf-8 -*-
"""旧サイト（WordPress）の全URLが、新サイトで 404 にならないことを機械的に検証する。

  ローカル:  php -S 127.0.0.1:8790 _dev_router.php  を起動してから
             python tools/test_redirects.py                     （既定で http://127.0.0.1:8790）
  本番:      python tools/test_redirects.py https://prodesign.co.jp   （差し替え当日に実行。読むだけで書き込みはしない）

検証内容（tools/legacy_urls.json の全URL）
  - redirect: 301 が返り、Location が対応表どおりで、転送先が 200 を返す（転送は1回で終わる）
  - same:     そのまま 200
  - gone:     410（WordPress の内部ファイル。検索に載せるものではない）
  - 正規化:   http→https、www あり→なし が 301 1回で https://prodesign.co.jp/... になる
  - 存在しないURL が 404 ステータスで 404 ページを返す
"""
import json, os, sys, urllib.request, urllib.parse, urllib.error, collections

BASE = (sys.argv[1] if len(sys.argv) > 1 and sys.argv[1].startswith('http') else 'http://127.0.0.1:8790').rstrip('/')
LOCAL = '127.0.0.1' in BASE or 'localhost' in BASE
HERE = os.path.dirname(os.path.abspath(__file__))
PROD = 'https://prodesign.co.jp'


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


opener = urllib.request.build_opener(NoRedirect)


def get(path, headers=None, base=None):
    url = (base or BASE) + urllib.parse.quote(path, safe="/:?&=%#+,;@~!$'()*")
    req = urllib.request.Request(url, headers=dict({'User-Agent': 'pd-redirect-test'}, **(headers or {})))
    try:
        r = opener.open(req, timeout=30)
        return r.status, r.headers.get('Location'), r.read()
    except urllib.error.HTTPError as e:
        return e.code, e.headers.get('Location'), e.read()


def strip_origin(loc):
    if not loc:
        return loc
    for o in (BASE, PROD):
        if loc.startswith(o):
            return loc[len(o):] or '/'
    return loc


def main():
    urls = json.load(open(os.path.join(HERE, 'legacy_urls.json'), encoding='utf-8'))
    fails = []; counts = collections.Counter(); target_status = {}
    for u in urls:
        st, loc, _ = get(u['old'])
        kind = u['kind']
        if kind == 'same':
            ok = st == 200
            if not ok: fails.append((u['old'], 'expected 200, got %s' % st))
        elif kind == 'gone':
            ok = st == 410
            if not ok: fails.append((u['old'], 'expected 410, got %s' % st))
        else:
            got = strip_origin(loc)
            ok = st == 301 and got == u['expect']
            if not ok:
                fails.append((u['old'], 'expected 301 -> %s, got %s -> %s' % (u['expect'], st, got)))
            else:
                t = u['expect'].split('#')[0]
                if t not in target_status:
                    target_status[t] = get(t)[0]
                if target_status[t] != 200:
                    ok = False; fails.append((u['old'], 'target %s returned %s' % (t, target_status[t])))
        counts[(kind, 'ok' if ok else 'NG')] += 1

    # ---- 正規化（http→https、www→なし）: ローカルでは Host ヘッダーで本番ドメインを装って確認する
    norm = []
    sample = ['/', '/company/', '/post-878/', '/business.html', '/post/2024-02-22-878', '/?p=878']
    if LOCAL:
        cases = [('http + wwwなし', {'Host': 'prodesign.co.jp'}), ('http + wwwあり', {'Host': 'www.prodesign.co.jp'}),
                 ('https + wwwあり', {'Host': 'www.prodesign.co.jp', 'X-Forwarded-Proto': 'https'})]
        for name, hd in cases:
            for p in sample:
                st, loc, _ = get(p, hd)
                ok = st == 301 and loc == PROD + p
                norm.append((name, p, st, loc, ok))
        # https + wwwなし は転送ループにならない（そのまま処理される）
        st, loc, _ = get('/business.html', {'Host': 'prodesign.co.jp', 'X-Forwarded-Proto': 'https'})
        norm.append(('https + wwwなし（転送しない）', '/business.html', st, loc, st == 200))
        st, loc, _ = get('/business.html', {'Host': 'prodesign.co.jp', 'X-Sakura-Forwarded-For': '203.0.113.1'})
        norm.append(('さくらの https 印（転送しない）', '/business.html', st, loc, st == 200))
    else:
        for name, base in [('http + wwwなし', 'http://prodesign.co.jp'), ('http + wwwあり', 'http://www.prodesign.co.jp'), ('https + wwwあり', 'https://www.prodesign.co.jp')]:
            for p in sample:
                try:
                    st, loc, _ = get(p, base=base)
                except Exception as e:
                    st, loc = 0, str(e)
                norm.append((name, p, st, loc, st == 301 and loc == PROD + p))
    for name, p, st, loc, ok in norm:
        counts[('normalize', 'ok' if ok else 'NG')] += 1
        if not ok: fails.append(('%s %s' % (name, p), 'got %s -> %s' % (st, loc)))

    # ---- 404 が正しいステータスで返る
    for p in ['/this-page-does-not-exist', '/post/no-such-article', '/no/such/dir/page.html']:
        st, loc, body = get(p)
        ok = st == 404 and 'ページが見つかりません'.encode('utf-8') in body or (st == 404 and '記事が見つかりません'.encode('utf-8') in body)
        counts[('404', 'ok' if ok else 'NG')] += 1
        if not ok: fails.append((p, 'expected 404 page, got %s' % st))

    print('base:', BASE)
    print('旧URL 総数:', len(urls))
    for k in sorted(counts):
        print('  %-10s %-3s %d' % (k[0], k[1], counts[k]))
    dest = collections.Counter()
    for u in urls:
        if u['kind'] == 'redirect':
            e = u['expect']
            dest['記事ページ /post/<slug>' if e.startswith('/post/') else '画像 /images/...' if e.startswith('/images/') else e] += 1
    print('転送先の内訳:')
    for k, v in dest.most_common():
        print('  %5d  %s' % (v, k))
    if fails:
        print('FAILED: %d' % len(fails))
        for f in fails[:60]:
            print('  NG', f[0], '|', f[1])
        return 1
    print('RESULT: ALL OK')
    return 0


if __name__ == '__main__':
    sys.exit(main())
