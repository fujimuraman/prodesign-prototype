# -*- coding: utf-8 -*-
"""スマホ幅で横スクロール（はみ出し）が出ていないかの機械チェック。

  python tools/check_overflow.py [http://127.0.0.1:8790]

全ページ（index/business/works/company/news/contact、記事2本、404）を 320 / 360 / 375 / 414px で開き、
document.documentElement.scrollWidth > clientWidth なら NG とし、はみ出している要素を表示する。
ブラウザは「この実行の子プロセス」だけ後片付けする。
"""
import sys, os
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from visual_diff import browser_pids, own_browser_pids, kill_new

BASE = (sys.argv[1] if len(sys.argv) > 1 and sys.argv[1].startswith('http') else 'http://127.0.0.1:8790').rstrip('/')
PAGES = ['/', '/business.html', '/works.html', '/company.html', '/news.html', '/contact.html',
         '/post/2024-02-22-878', '/post/2019-10-03-433', '/this-page-does-not-exist']
WIDTHS = [320, 360, 375, 414]
JS = """() => {
  const de = document.documentElement, vw = de.clientWidth, out = [];
  if (de.scrollWidth > vw) {
    for (const el of document.querySelectorAll('body *')) {
      const r = el.getBoundingClientRect();
      if (r.width > 0 && r.right > vw + 0.5) {
        const cs = getComputedStyle(el);
        out.push((el.tagName.toLowerCase() + '.' + (el.className && el.className.baseVal === undefined ? el.className : '')).slice(0, 60)
          + ' right=' + Math.round(r.right) + ' w=' + Math.round(r.width) + ' pos=' + cs.position);
      }
    }
  }
  return {sw: de.scrollWidth, cw: vw, items: out.slice(0, 12)};
}"""


def main():
    from playwright.sync_api import sync_playwright
    before = browser_pids(); own = set(); ng = 0
    try:
        with sync_playwright() as p:
            b = p.chromium.launch(); own |= own_browser_pids()
            for w in WIDTHS:
                ctx = b.new_context(viewport={'width': w, 'height': 800}, device_scale_factor=1)
                ctx.route('**/*', lambda r: r.abort() if 'google.com/maps' in r.request.url else r.continue_())
                for path in PAGES:
                    pg = ctx.new_page()
                    pg.goto(BASE + path, wait_until='networkidle')
                    pg.evaluate("document.fonts.ready")
                    pg.evaluate("document.querySelectorAll('.reveal').forEach(e => e.classList.add('is-visible'))")
                    pg.wait_for_timeout(300)
                    r = pg.evaluate(JS)
                    ok = r['sw'] <= r['cw']
                    print('%4dpx %-28s %s scrollWidth=%d' % (w, path, 'OK ' if ok else 'NG ', r['sw']))
                    if not ok:
                        ng += 1
                        for it in r['items']:
                            print('        ', it)
                    pg.close(); own |= own_browser_pids()
                ctx.close()
            b.close()
    finally:
        print('stopped leftovers (own only):', sorted(kill_new(before, own)))
    print('RESULT:', 'ALL OK' if ng == 0 else 'NG %d' % ng)
    return 1 if ng else 0


if __name__ == '__main__':
    sys.exit(main())
