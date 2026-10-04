# さくらのレンタルサーバーへの設置手順（管理者向け）

新サイトは「静的HTML＋PHP（SQLite）」で動きます。WordPress は不要です。

## 必要なもの
- さくらのレンタルサーバー（スタンダード以上）のコントロールパネル、または FTP/SFTP/SSH の接続情報
- PHP 8.1 以上（コントロールパネル「スクリプト設定 → PHPのバージョン」で 8.x を選ぶ）
- ドメイン prodesign.co.jp がこのサーバーに向いていること（現状どおり）

## 置くファイル（このリポジトリの内容）
```
index.html business.html works.html company.html news.html contact.html admin.html
css/ js/ images/（images/icons/ も） templates/news_post.html templates/notfound.html
.htaccess  api.php  page.php  post.php  lib.php  config.php  schema.sql
seo.php  legacy.php  legacy_map.php  sitemap.php  feed.php  robots.txt  favicon.ico   ← 検索対策（下の「検索対策」参照）
tools/import_seed.php  news/（初回移行用）  data_seed/history.json（初回移行用）
```
置かないもの: `post/`（GitHub Pages 用の静的コピー）、`_*.py`、`*.md`、`config.local.php`、`.git`、`tools/` の `import_seed.php` 以外（検証用スクリプト）

※ リポジトリの `*.html` はプレビュー（GitHub Pages）用に `noindex` が入った静的コピーを兼ねています。本番では `.htaccess` が全ページを `page.php` に通し、head を本番用（noindex なし）に差し替えて出すので、**そのままアップロードして構いません**。ただし `.htaccess` を置き忘れると noindex のまま公開されるので、設置後に必ず `python tools/check_seo.py https://prodesign.co.jp` で確認します（下記）。

## 手順
1. **旧サイトのバックアップ**: コントロールパネル → バックアップ、または FTP で `www/` を丸ごとダウンロード
2. **旧サイトを退避**: `www/` の WordPress 一式を `www/old/` などに移動（`https://prodesign.co.jp/old/` で見られる状態にしておく）。WordPress の `.htaccess` は必ず外す
3. **新サイトをアップロード**: 上記ファイルを `www/` 直下へ
4. **`config.php` を確認**: `allowed_emails`（登録を許可するメール）、`from_email`（info@prodesign.co.jp）、`dev_mail => false`
5. **書き込み権限**: `www/data/` と `www/uploads/` は PHP が自動作成します。作成されない場合は手動で作り、パーミッション 705 または 755
6. **初回移行**（旧ニュース25件と事業実績17件を取り込む）: SSH で `php tools/import_seed.php` を実行。SSH が使えない場合はブラウザで `https://prodesign.co.jp/tools/import_seed.php` を一度だけ開く（実行後、`tools/` フォルダは削除する）
7. **動作確認**: `https://prodesign.co.jp/` `/news.html` `/company.html` `/post/2024-02-22-878` `/admin`
8. **管理者登録**: `/admin` → 「はじめての方（新規登録）」 → メール（`allowed_emails` のもの）とパスワード → 認証コード
9. `tools/` と `news/` `data_seed/` は移行後に削除してよい
10. **検索対策の確認**（必須）: 下の「検索対策」の手順と `SEO_SWITCH_CHECKLIST.md` の「2. 差し替え当日」を実施

## 検索対策（差し替えで検索から消えないために）

詳しい手順と前後の監視は `SEO_SWITCH_CHECKLIST.md`。ここは設置作業に関わる要点だけ。

- **旧URLの転送**: 旧 WordPress の URL（`/company/` `/post-878/` `/?p=878` `/feed/` `/wp-content/uploads/…` など 923本）は、`.htaccess` → `legacy.php` が新URLへ 301 転送します。対応表は `legacy_map.php`。`.htaccess` `legacy.php` `legacy_map.php` の3つは必ず置き、**最低1年は消さない**
- **http→https・www なしへの統一**: `.htaccess` の先頭で 301。さくらでは https のリクエストに `X-Sakura-Forwarded-For` が付くのでそれで判定しています。設置直後にトップが「リダイレクトが繰り返されました」になる場合は、`.htaccess` の「# http → https」の3行を `#` で止め、コントロールパネル（ドメイン/SSL → 対象ドメイン →「HTTPSに転送する」）で設定してください
- **設置直後に実行する確認**（どちらも読むだけ）
  ```
  python tools/test_redirects.py https://prodesign.co.jp   # 旧URL全件が 301 → 200。RESULT: ALL OK
  python tools/check_seo.py https://prodesign.co.jp        # title/canonical/構造化データ/sitemap/robots、noindex が無いこと
  ```
- **旧サイトの退避先を検索に出さない**: `www/old/` に置いた旧 WordPress は、同じ内容が2か所にあると重複とみなされるので検索に出しません。次のどちらか（上がおすすめ）
  1. さくらのコントロールパネル「ファイルマネージャー」で `old/` に **アクセス制限（パスワード）** を掛ける（父とクロコだけ見られれば十分）
  2. `www/old/.htaccess` に次の1行を入れる（noindex）。この場合 `robots.txt` で `/old/` を Disallow に**しない**（Disallow にすると Google が noindex を読めない）
     ```
     Header set X-Robots-Tag "noindex, nofollow"
     ```
  なお WordPress はフォルダを移すと内部リンクが元のURL（`/company/` など）を指したままになります。それらは新サイトへ転送されるので、旧サイトは「トップと各ページを目で見て確認する用」と割り切ってください
- **`news/` フォルダ**（初回移行用の md）は、移行が終わったら削除してください。残っていても `/news/` は新しいニュース一覧へ転送され、md は外から見えません
- **sitemap / robots / RSS**: `https://prodesign.co.jp/sitemap.xml`（記事を足すと自動で増える）、`/robots.txt`、`/feed.xml`。Search Console への送信は `SEO_SWITCH_CHECKLIST.md`
- **アクセス解析（Google アナリティクス 4）**: GA4 プロパティを作成 → 測定ID（`G-…`）を `config.php` の `'ga4_id'` に記入してアップロード → `python tools/check_seo.py https://prodesign.co.jp --ga4=G-…` で確認 → GA4 の「リアルタイム」に自分のアクセスが出ることを確認。空のままなら解析タグは出ません。公開ページ（404 含む）にだけ出て、`/admin`・API・プレビューには出ません。お問い合わせ送信（`contact_mailto`）と電話リンク（`tel_click`）のクリックも記録します。詳しくは `SEO_SWITCH_CHECKLIST.md` の 5
- **CSS/JS を直した時**: 各 HTML と `templates/*.html` の `?v=20261004a` を新しい日付に揃えて上げ（ブラウザのキャッシュ対策）、`php tools/export_static.php` を実行
- **画像を同じファイル名で差し替えた時**: ブラウザに最大1か月キャッシュされます。すぐ反映したい時はファイル名を変えるか、CSS/JS と同じく `?v=日付` を付けます
- **title / description を直したい時**: `seo.php` の `seo_pages()` を編集（HTML の `<!-- SEO:START -->`〜`<!-- SEO:END -->` は自動生成なので直接は書かない）。編集後 `php tools/export_static.php` でプレビュー用の静的コピーも更新
- **旧サイトで記事が増えた時**（差し替えまでに）: `news/` に md を足し、`legacy_map.php` の `posts` に `旧ID => '日付-旧ID'` を1行足す

## メールが届かない場合
- さくらでは `mail()` は使えますが、差出人（`from_email`）はそのサーバーで受信設定されているドメインのアドレスにしてください（info@prodesign.co.jp なら OK）
- 迷惑メールフォルダを確認。改善しない場合は SPF レコード（`v=spf1 a:www****.sakura.ne.jp mx ~all`）をさくらのDNS設定で確認

## 更新のしかた（サイトの見た目を変えるとき）
- HTML/CSS/JS を編集して FTP で上書きするだけ。記事・沿革は DB（`data/prodesign.sqlite`）にあるので上書きしても消えません
- `data/` と `uploads/` は定期的にバックアップ（コントロールパネルのバックアップ機能で可）

## ローカルで試す（開発者向け）
```
php tools/import_seed.php
php -S 127.0.0.1:8790 _dev_router.php
```
`config.local.php` に `'dev_mail' => true` を書くとメールを送らずに認証コードが画面に出る（本番には置かない）
