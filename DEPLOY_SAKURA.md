# さくらのレンタルサーバーへの設置手順（管理者向け）

新サイトは「静的HTML＋PHP（SQLite）」で動きます。WordPress は不要です。

## 必要なもの
- さくらのレンタルサーバー（スタンダード以上）のコントロールパネル、または FTP/SFTP/SSH の接続情報
- PHP 8.1 以上（コントロールパネル「スクリプト設定 → PHPのバージョン」で 8.x を選ぶ）
- ドメイン prodesign.co.jp がこのサーバーに向いていること（現状どおり）

## 置くファイル（このリポジトリの内容）
```
index.html business.html works.html company.html news.html contact.html admin.html
css/ js/ images/ templates/news_post.html
.htaccess  api.php  page.php  post.php  lib.php  config.php  schema.sql
tools/import_seed.php  news/（初回移行用）  data_seed/history.json（初回移行用）
```
置かないもの: `post/`（GitHub Pages 用の静的コピー）、`_*.py`、`*.md`、`config.local.php`、`.git`

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
