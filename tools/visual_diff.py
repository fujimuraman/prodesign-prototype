# -*- coding: utf-8 -*-
"""見た目が変わっていないことの検証（Playwright でフルページ撮影 → ピクセル差分）

  撮影:  python tools/visual_diff.py shoot http://127.0.0.1:8790 out_dir [--static]
  比較:  python tools/visual_diff.py diff dir_before dir_after

- PC幅 1366px とスマホ幅 375px で、index/business/works/company/news/contact と記事ページを撮る
- スライドショー（setInterval）を止め、スクロール表示（.reveal）を全部出した状態で撮るので、毎回同じ絵になる
- Google マップの iframe は外部要因で揺れるので空にして撮る
- background-attachment:fixed（CTA の背景）はフルページ撮影だと描画されたりされなかったりするので、撮影時だけ scroll にそろえる
- ブラウザは開始時の PID を記録し、終了時に「開始時に無かった PID」かつ「この実行の子プロセス」だけ止める
  （同じ時間帯に常駐スクレイパーが起動したブラウザを巻き添えにしない）
"""
import subprocess, sys, os, json

PAGES = [("index", "/"), ("business", "/business.html"), ("works", "/works.html"), ("company", "/company.html"),
         ("news", "/news.html"), ("contact", "/contact.html"),
         ("post878", "/post/2024-02-22-878"), ("post433", "/post/2019-10-03-433")]
WIDTHS = [("pc", 1366, 900), ("sp", 375, 812)]
NAMES = ("chrome-headless-shell.exe", "chromium.exe", "chrome.exe", "chrome-headless-shell", "chromium", "chrome")


def browser_pids():
    """いま動いている chrome-headless-shell / chromium の PID 一覧（開始時の記録用）"""
    import psutil
    pids = set()
    for pr in psutil.process_iter(["pid", "name"]):
        try:
            if (pr.info["name"] or "").lower() in NAMES:
                pids.add(pr.info["pid"])
        except Exception:
            pass
    return pids


def own_browser_pids():
    """この Python プロセスの子孫にあたるブラウザだけ（＝自分が起動したものだけ）"""
    import psutil
    out = set()
    try:
        for c in psutil.Process().children(recursive=True):
            try:
                if c.name().lower() in NAMES:
                    out.add(c.pid)
            except Exception:
                pass
    except Exception:
        pass
    return out


def kill_new(before, own):
    """開始時に無かった PID のうち、自分が起動したと確認できたものだけ止める。
    他のプログラム（常駐スクレイパー等）が同じ時間帯に起動したブラウザには触らない。"""
    import psutil
    left = (browser_pids() - before) & own
    for pid in left:
        try:
            psutil.Process(pid).kill()
        except Exception:
            pass
    return left


def shoot(base, out, static=False):
    from playwright.sync_api import sync_playwright
    os.makedirs(out, exist_ok=True)
    before = browser_pids()
    own = set()
    print("browser pids before:", len(before))
    try:
        with sync_playwright() as p:
            b = p.chromium.launch()
            own |= own_browser_pids()
            for wname, w, h in WIDTHS:
                ctx = b.new_context(viewport={"width": w, "height": h}, device_scale_factor=1)
                ctx.add_init_script("window.setInterval = function(){ return 0; };")
                ctx.route("**/*", lambda r: r.abort() if ("google.com/maps" in r.request.url or "maps.g" in r.request.url) else r.continue_())
                for name, path in PAGES:
                    if static:
                        path = "/index.html" if path == "/" else path
                        if path.startswith("/post/"):
                            path = path + "/index.html"
                    pg = ctx.new_page()
                    pg.goto(base.rstrip("/") + path, wait_until="networkidle")
                    pg.evaluate("document.fonts.ready")
                    pg.evaluate("""async () => {
                        document.querySelectorAll('iframe').forEach(f => { f.removeAttribute('src'); f.srcdoc = ''; });
                        // background-attachment:fixed はフルページ撮影で描画が揺れる（撮影側の都合）ので、撮影時だけ scroll にそろえる
                        const st = document.createElement('style'); st.textContent = '*{background-attachment:scroll !important}'; document.head.appendChild(st);
                        const H = document.documentElement.scrollHeight;
                        for (let y = 0; y <= H; y += 400) { window.scrollTo(0, y); await new Promise(r => setTimeout(r, 60)); }
                        document.querySelectorAll('.reveal').forEach(e => e.classList.add('is-visible'));
                        document.querySelectorAll('img').forEach(i => { i.loading = 'eager'; });
                        await Promise.all([...document.images].map(i => i.complete ? 0 : new Promise(r => { i.onload = i.onerror = r; })));
                        window.scrollTo(0, 0);
                        await new Promise(r => setTimeout(r, 2500));
                    }""")
                    f = os.path.join(out, f"{name}_{wname}.png")
                    pg.screenshot(path=f, full_page=True, animations="disabled")
                    print("shot", f)
                    pg.close()
                    own |= own_browser_pids()
                ctx.close()
            b.close()
    finally:
        left = kill_new(before, own)
        print("own browser pids:", len(own), "/ stopped leftovers (own only):", sorted(left))


def diff(a, b):
    from PIL import Image, ImageChops
    res, ok = {}, True
    for name, _ in PAGES:
        for wname, _, _ in WIDTHS:
            fn = f"{name}_{wname}.png"
            fa, fb = os.path.join(a, fn), os.path.join(b, fn)
            if not (os.path.exists(fa) and os.path.exists(fb)):
                res[fn] = "missing"; ok = False; continue
            ia, ib = Image.open(fa).convert("RGB"), Image.open(fb).convert("RGB")
            if ia.size != ib.size:
                res[fn] = f"SIZE DIFF {ia.size} vs {ib.size}"; ok = False; continue
            d = ImageChops.difference(ia, ib)
            bbox = d.getbbox()
            if bbox is None:
                res[fn] = f"identical {ia.size}"
            else:
                n = sum(1 for px in d.convert("L").getdata() if px)
                res[fn] = f"DIFF pixels={n} bbox={bbox} size={ia.size}"; ok = False
                d.point(lambda v: 255 if v else 0).save(os.path.join(b, fn.replace(".png", "_diff.png")))
    for k, v in res.items():
        print(f"{k:22s} {v}")
    print("RESULT:", "ALL IDENTICAL" if ok else "DIFFERENCES FOUND")
    return ok


if __name__ == "__main__":
    if len(sys.argv) >= 4 and sys.argv[1] == "shoot":
        shoot(sys.argv[2], sys.argv[3], "--static" in sys.argv)
    elif len(sys.argv) >= 4 and sys.argv[1] == "diff":
        sys.exit(0 if diff(sys.argv[2], sys.argv[3]) else 1)
    else:
        print(__doc__)
