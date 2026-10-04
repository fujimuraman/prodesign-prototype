# サイト差し替え時の検索対策チェックリスト（株式会社プロデザイン）

旧サイト（WordPress）から新サイトへ差し替えても「プロデザイン」の検索で今までどおり（今まで以上に）見つかるようにするための手順書です。
差し替えは **父（代表）の GO が出てから**。それまで本番サーバー・旧サイト・DNS には一切手を入れません。

- 正規のURL: `https://prodesign.co.jp/`（https・www なし。旧サイトと同じ）
- 新サイトのページ: `/` `/business.html` `/works.html` `/company.html` `/news.html` `/contact.html` と記事 `/post/<日付-番号>`
- 検証用の道具（どれも読むだけ。書き込みはしない）
  - `python tools/test_redirects.py [URL]` … 旧URL 923本が 301 で正しい先へ飛び、飛び先が 200 になるか
  - `python tools/check_seo.py [URL]` … title / description / canonical / OGP / 構造化データ / sitemap / robots / **noindex が無いこと**
  - `python tools/visual_diff.py` … 見た目が変わっていないか（ピクセル比較）
  - URL を省くとローカル（`php -S 127.0.0.1:8790 _dev_router.php`）を検査。本番は `https://prodesign.co.jp` を付ける

---

## 0. いまのうちに確認しておくこと（差し替えの1か月前まで）

- [ ] **Google Search Console に prodesign.co.jp が登録されているか**、誰の Google アカウントか（父／旧サイトの制作会社）
  - 旧サイトのトップには所有権確認用のタグ（`google-site-verification`）が**入っていません**。Google アナリティクス（旧 UA-131039623-1）経由か、DNS か、未登録のどれかです
  - 登録が無い・分からない場合は、**差し替え前に**「ドメイン プロパティ」（DNS の TXT レコードで確認する方式）で登録する。この方式ならサイトを入れ替えても所有権が切れません
  - HTML タグ方式で確認する場合は、発行されたタグを `seo.php` の head 生成に1行足す（クロコに依頼）
- [ ] Google ビジネスプロフィール（Google マップの会社情報）があるか、管理者は誰か。ウェブサイト欄が `https://prodesign.co.jp/` になっているか
- [ ] Bing Webmaster Tools（任意）。Search Console からインポートできる
- [ ] 会社概要の「設立 2014年3月 起業」と沿革の「2014.05 起業」が食い違っている。どちらが正しいか父に確認（構造化データには年だけ「2014」を入れてある）

## 1. 差し替え直前（前日〜当日朝）: 今の状態を記録する

あとで「下がった／上がった」を比べるための記録です。スクリーンショットで残す。

- [ ] Google で次の語を検索し、順位と表示（タイトル・説明文）を記録: `プロデザイン` / `株式会社プロデザイン` / `プロデザイン 横手` / `プロデザイン 秋田` / `ProDesign 秋田` / `自動機 設計 秋田` / `機械設計 アウトソーシング 秋田`
- [ ] `site:prodesign.co.jp` の件数と、出てくるURLの一覧
- [ ] Search Console（見られる場合）: 「ページ」のインデックス登録数、「検索パフォーマンス」の過去3か月（クエリ・ページ別）をエクスポート、「リンク」の上位リンク元
- [ ] 旧サイトのサイトマップ `https://prodesign.co.jp/sitemap.xml` を保存（`tools/legacy_urls.json` に全URLを控えてあるが、直前に記事が増えていないか確認。増えていたら `legacy_map.php` の `posts` に1行足す）
- [ ] ローカルで最終確認: `python tools/test_redirects.py` と `python tools/check_seo.py` が `ALL OK`

## 2. 差し替え当日

設置そのものは `DEPLOY_SAKURA.md` の手順どおり。その直後に以下を順に。

- [ ] **転送の全件テスト**: `python tools/test_redirects.py https://prodesign.co.jp` → `RESULT: ALL OK`（旧URL 923本＋http/www の正規化＋404）
  - 「リダイレクトが繰り返されました」と出る／トップが開かない時は、`.htaccess` の「http → https」の3行を `#` で止めて再アップロードし、さくらのコントロールパネル（ドメイン/SSL →「HTTPSに転送する」）で設定する
- [ ] **SEO 要素のテスト**: `python tools/check_seo.py https://prodesign.co.jp` → `RESULT: ALL OK`
  - ここで **noindex が1つでも出たら公開を止める**（プレビュー用の静的コピーがそのまま出ている＝ `.htaccess` の書き換えが効いていない）
- [ ] ブラウザで確認: トップ・事業内容・施工実績・企業情報・ニュース・お問い合わせ・記事1本・`/admin`。ページのソースに `noindex` が無いこと、`<link rel="canonical" href="https://prodesign.co.jp/...">` があること
- [ ] `https://prodesign.co.jp/robots.txt` に `Sitemap: https://prodesign.co.jp/sitemap.xml` があり、`Disallow: /` が**無い**こと
- [ ] `https://prodesign.co.jp/sitemap.xml` が開き、固定6ページ＋全記事が載っていること
- [ ] 旧URLを手でいくつか: `/company/` → `/company.html`、`/post-878/` → `/post/2024-02-22-878`、`/business_01/` → `/business.html#design`、`/feed/` → `/feed.xml`
- [ ] 存在しないURL（例 `/abc`）が「ページが見つかりません」を **404** で返す（200 ではない）こと
- [ ] 退避した旧 WordPress（`/old/`）が検索に出ないようにしてある（`DEPLOY_SAKURA.md`「旧サイトの退避先を検索に出さない」）
- [ ] **Search Console**
  - [ ] 「サイトマップ」に `https://prodesign.co.jp/sitemap.xml` を送信（旧サイトのサイトマップ登録が残っていれば、そのまま＝同じURL）
  - [ ] 「URL 検査」で主要URLを1つずつ検査 →「インデックス登録をリクエスト」: `/` `/business.html` `/works.html` `/company.html` `/news.html` `/contact.html` と新しい記事2〜3本
  - [ ] 旧URLも1つ検査（例 `/company/`）し、「ページにリダイレクトがあります」と出て転送先が新URLになっていること
- [ ] リッチリザルト テスト（https://search.google.com/test/rich-results）でトップと記事1本を確認（組織・パンくず・記事が読めること）
- [ ] SNS のカード確認（任意）: LINE / X / Facebook に URL を貼ってタイトル・画像が出るか

## 3. 差し替え直後 1〜4 週間

| 時期 | やること |
|---|---|
| 翌日 | `site:prodesign.co.jp` と `プロデザイン` 検索。サーバーのエラーログに 404 が出ていないか |
| 3日後・1週間後 | Search Console「ページ」: 「見つかりませんでした（404）」「リダイレクト エラー」「noindex タグによって除外」に新サイトのURLが出ていないか。出た旧URLは `legacy_map.php`（または `legacy.php`）に転送を足す |
| 1週間後 | 「検索パフォーマンス」で `プロデザイン` 系クエリの表示回数・掲載順位が差し替え前と同等以上か。新URL（`.html`）がインデックスされ始めているか |
| 2週間後 | 検索結果のタイトル・説明文が新しいものに変わっているか。旧URLが新URLに置き換わっているか |
| 4週間後 | 1. の記録と同じ語で検索して順位を比較。Search Console の 404 一覧を最終確認 |

- 301 転送は **最低1年は外さない**（`legacy.php` / `legacy_map.php` / `.htaccess` を消さない）
- 一時的に順位が上下するのは普通。2〜4週間で落ち着く。4週間たっても `プロデザイン` で出ない場合は Search Console の「ページ」の理由を確認

## 4. 外部に載っている URL の確認（差し替え後 1 週間以内）

301 で転送されるので放置しても届きますが、直接新URLにしておくと確実です。

- [ ] Google ビジネスプロフィールのウェブサイト欄（`https://prodesign.co.jp/`）、営業時間（平日 9:00〜18:00）、電話・住所がサイトと一致しているか
- [ ] 名刺・会社案内・メール署名の URL（トップなら変更不要）
- [ ] 外部の掲載先（中小機構 J-GoodTech、あきた企業活性化センター、秋田県の企業ガイド、商工会、取引先サイトのリンク集 など）で下層ページ（`/company/` など）に張られているもの
- [ ] 旧サイトの Google アナリティクス（UA-131039623-1）は旧方式で、すでに計測が止まっている。新サイトは GA4 に対応済み（下の「5. アクセス解析（GA4）」）

## 5. アクセス解析（Google アナリティクス 4）を有効にする

新サイトは測定IDを1か所に書くだけで解析タグが出る作りです（空のままなら何も出ません）。差し替え前に 1〜2 を済ませておくと当日が楽です。

1. [ ] **GA4 プロパティを作る**（かおる／父の Google アカウントで https://analytics.google.com/ ）
   - 「管理」→「作成」→「プロパティ」: 名前「プロデザイン公式サイト」、タイムゾーン 日本、通貨 日本円
   - 「データ ストリーム」→「ウェブ」: URL `https://prodesign.co.jp`、ストリーム名「公式サイト」
   - 表示された **測定ID（`G-` で始まる英数字）** を控える
2. [ ] **測定IDを記入**: `config.php` の `'ga4_id' => ''` を `'ga4_id' => 'G-XXXXXXXXXX'` に書き換えて、サーバーの `config.php` を上書き（クロコに ID を伝えれば対応）
3. [ ] **タグが出ているか機械チェック**: `python tools/check_seo.py https://prodesign.co.jp --ga4=G-XXXXXXXXXX` → `GA4 タグ: あり` で `ALL OK`
   - 公開ページ（トップ〜お問い合わせ・記事・404）にだけ出て、更新ページ（`/admin`）・API・GitHub Pages のプレビューには出ません
4. [ ] **リアルタイムで確認**: 自分のスマホやパソコンで `https://prodesign.co.jp/` を開き、GA4 の「レポート」→「リアルタイム」に自分のアクセスが数十秒で出ること
5. [ ] **イベントの確認**（任意）: お問い合わせフォームで「送信する」を押すと `contact_mailto`、電話番号リンク（`tel:`）を押すと `tel_click` が記録されます。GA4 の「管理」→「イベント」で `contact_mailto` を「キーイベント」にすると問い合わせ件数として集計できます
   - 現在のページの電話番号はリンクではなく文字なので、`tel_click` は電話番号をリンクにした時から記録されます
6. [ ] Search Console と GA4 を連携（GA4「管理」→「サービス間のリンク設定」→「Search Console のリンク」）しておくと、検索語とアクセスを一緒に見られます
- やめる時は `ga4_id` を空に戻すだけです

---

## 付録A: 旧URL → 新URL の対応（主なもの）

全件（923本）は `tools/legacy_urls.json`、転送の仕組みは `legacy.php` と `legacy_map.php`。

| 旧URL | 新URL |
|---|---|
| `/` | `/`（同じ） |
| `/business_01/`（機械設計） | `/business.html#design` |
| `/business_02/`（機械設計・製造） | `/business.html#manufacturing` |
| `/business_03/`（専門家支援・セミナー） | `/business.html#consulting` |
| `/works/` | `/works.html` |
| `/company/` | `/company.html` |
| `/contact/` | `/contact.html` |
| `/news/`、`/news/page/2/` など | `/news.html` |
| `/post-878/`、`/?p=878`（記事25本すべて） | `/post/2024-02-22-878`（`/post/<日付>-<旧ID>`） |
| `/feed/`、`/comments/feed/`、`/?feed=rss2` | `/feed.xml` |
| `/category/…`、`/author/…`、`/2024/02/`、`/page/2/`、`/?s=…` | `/news.html` |
| `/sitemap.html`、`/sitemap-pt-post-2024-02.xml` など | `/sitemap.xml` |
| `/wp-content/uploads/…`（新サイトで使っている画像） | `/images/news/post-878.png` など新しい場所 |
| `/wp-content/uploads/…`（新サイトで使っていない画像） | その画像が載っていた記事・ページ |
| 添付ファイルページ（`/b-flow01/` など） | 載っていたページ（事業内容・施工実績・記事） |
| `/index.html`、`/index.php` | `/` |
| `http://…`、`www.prodesign.co.jp` | `https://prodesign.co.jp/…`（1回の 301） |
| `/wp-login.php`、`/wp-admin/`、`/xmlrpc.php`、`/wp-json/` | 410（もう無い。検索に載せるものではない） |

## 付録B: 検索対策として入れたもの（見た目は変えていない）

- 各ページ固有の title / description、canonical、OGP、Twitter Card、favicon・apple-touch-icon（`seo.php` が生成）
- 構造化データ（JSON-LD）: 会社（Organization / LocalBusiness）、サイト名（WebSite）、パンくず、事業内容の3事業（Service）、記事（Article）
- `sitemap.xml`（自動生成）、`robots.txt`、`feed.xml`（RSS）
- 旧URLの 301 転送、404 ページ（404 ステータス）、圧縮・ブラウザキャッシュ
- プレビュー（fujimuraman.github.io）は `noindex` ＋ canonical を本番に向け、検索で本番と競合しないようにしてある
