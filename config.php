<?php
// ProDesign 更新ページ 設定（さくらのレンタルサーバー等、PHP 8.x + SQLite + mail() が使える環境向け）
return [
  'site_name'      => '株式会社プロデザイン',
  // 登録を許可する管理者メール（ここに無いアドレスは登録できない）
  'allowed_emails' => ['his-fujiwara@prodesign.co.jp', 'fujimuraman@gmail.com'],
  // 認証メールの差出人（ドメインのメールアドレスにすると迷惑メール判定されにくい）
  'from_email'     => 'info@prodesign.co.jp',
  'from_name'      => 'ProDesign 更新ページ',
  // データ置き場（SQLite）とアップロード画像
  'data_dir'       => __DIR__ . '/data',
  'upload_dir'     => __DIR__ . '/uploads',
  'upload_url'     => '/uploads',
  // 開発用: true にするとメールを送らず data/mail.log に書き出し、認証コードをAPI応答に含める。本番では必ず false
  'dev_mail'       => false,
  'session_days'   => 30,
  'trust_days'     => 30,
];
