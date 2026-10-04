<?php
// SEO 共通部品: 各ページの <head>（title / description / canonical / OGP / Twitter Card / アイコン / 構造化データ）を1か所で生成する。
//   本番（page.php / post.php）      … seo_head($key, $ctx, false)  → noindex は絶対に出さない
//   プレビュー（tools/export_static.php）… seo_head($key, $ctx, true)   → noindex,nofollow を出す（canonical は本番URL）
// 画面に見える内容は一切変えない。ここに書く事実（住所・電話・代表者など）は company.html / contact.html に載っているものだけ。
declare(strict_types=1);

const SEO_START = '<!-- SEO:START（seo.php が自動生成。ここは直接編集しない） -->';
const SEO_END   = '<!-- SEO:END -->';

function site_url(): string { return rtrim((string)(cfg()['site_url'] ?? 'https://prodesign.co.jp'), '/'); }
function abs_url(string $path): string {
  if (preg_match('#^https?://#', $path)) return $path;
  return site_url() . '/' . ltrim($path, '/');
}

/** ページごとの title / description（父の文言方針: 「完全オーダーメイド自動機」を前面に。「3D CADを駆使」と所属団体は書かない） */
function seo_pages(): array {
  return [
    'index' => [
      'path'  => '/',
      'name'  => 'ホーム',
      'title' => '株式会社プロデザイン（ProDesign）｜自動機・治具の設計製作、機械設計のアウトソーシング、コンサルティング｜秋田県横手市',
      'desc'  => '株式会社プロデザイン（ProDesign）は秋田県横手市の機械設計・製造会社です。御社だけの完全オーダーメイド自動機・省力化機や治具の設計製作から、機械設計業務のアウトソーシング、ものづくり企業へのコンサルティングまで一貫して対応します。',
      'type'  => 'WebPage', 'hero' => 'images/slide01.jpg',
    ],
    'business' => [
      'path'  => '/business.html',
      'name'  => '事業内容',
      'title' => '事業内容｜株式会社プロデザイン（ProDesign）秋田県横手市の自動機設計・製造',
      'desc'  => '株式会社プロデザイン（秋田県横手市）の事業内容。完全オーダーメイド自動機の設計・製造・現地調整、機械設計のアウトソーシング、専門家支援・セミナーの3つの事業で、製造現場の自動化・省力化と原価低減を支えます。',
      'type'  => 'WebPage', 'hero' => 'images/slide01.jpg',
    ],
    'works' => [
      'path'  => '/works.html',
      'name'  => '施工実績',
      'title' => '施工実績｜株式会社プロデザイン（ProDesign）秋田県横手市の自動機・省力化装置',
      'desc'  => '株式会社プロデザイン（秋田県横手市）の施工実績。自動ネジ締め機、箔研磨装置、基板レーザー印字装置、完成モーター検査ラインなど、お客様ごとに設計製造した完全オーダーメイド自動機・省力化装置の納入事例をご紹介します。',
      'type'  => 'CollectionPage', 'hero' => 'images/slide02.jpg',
    ],
    'company' => [
      'path'  => '/company.html',
      'name'  => '企業情報',
      'title' => '企業情報｜株式会社プロデザイン（ProDesign）秋田県横手市',
      'desc'  => '株式会社プロデザイン（ProDesign）の企業情報。代表取締役 藤原久良のメッセージ、経営理念、会社概要、沿革・事業実績、所在地（秋田県横手市大屋寺内）とアクセスをご案内します。完全オーダーメイド自動機の設計製造と機械設計の会社です。',
      'type'  => 'AboutPage', 'hero' => 'images/slide03.jpg',
    ],
    'news' => [
      'path'  => '/news.html',
      'name'  => 'ニュース',
      'title' => 'ニュース｜株式会社プロデザイン（ProDesign）秋田県横手市',
      'desc'  => '株式会社プロデザイン（秋田県横手市）のニュース。完全オーダーメイド自動機・装置の納入実績、ものづくり企業への専門家支援の活動報告、プロデザインからのお知らせを掲載しています。',
      'type'  => 'CollectionPage', 'hero' => 'images/slide02.jpg',
    ],
    'contact' => [
      'path'  => '/contact.html',
      'name'  => 'お問い合わせ',
      'title' => 'お問い合わせ｜株式会社プロデザイン（ProDesign）秋田県横手市',
      'desc'  => '株式会社プロデザイン（秋田県横手市）へのお問い合わせ。完全オーダーメイド自動機・治具の設計製作、機械設計のアウトソーシング、コンサルティングのご相談は、電話 0182-32-1121 またはフォームからお気軽にどうぞ。',
      'type'  => 'ContactPage', 'hero' => 'images/slide03.jpg',
    ],
  ];
}

/** 会社の事実（company.html / contact.html に記載のものだけ） */
function seo_org(): array {
  $u = site_url();
  return [
    '@type' => ['Organization', 'LocalBusiness'],
    '@id' => $u . '/#organization',
    'name' => '株式会社プロデザイン',
    'alternateName' => ['ProDesign', 'プロデザイン'],
    'legalName' => '株式会社プロデザイン',
    'url' => $u . '/',
    'logo' => ['@type' => 'ImageObject', '@id' => $u . '/#logo', 'url' => $u . '/images/logo.png', 'width' => 800, 'height' => 200, 'caption' => '株式会社プロデザイン（ProDesign）'],
    'image' => $u . '/images/ogp.png',
    'description' => '機械設計業務のアウトソーシング、オーダーメイドの自動機・省力化機の設計製造、企業コンサルティング',
    'telephone' => '+81-182-32-1121',
    'faxNumber' => '+81-182-32-1145',
    'email' => 'info@prodesign.co.jp',
    'address' => [
      '@type' => 'PostalAddress', 'postalCode' => '013-0052', 'addressCountry' => 'JP',
      'addressRegion' => '秋田県', 'addressLocality' => '横手市', 'streetAddress' => '大屋寺内字堀ノ内61',
    ],
    'founder' => ['@type' => 'Person', 'name' => '藤原 久良', 'jobTitle' => '代表取締役'],
    'foundingDate' => '2014',
    'areaServed' => ['@type' => 'Country', 'name' => '日本'],
    'openingHoursSpecification' => [[
      '@type' => 'OpeningHoursSpecification',
      'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
      'opens' => '09:00', 'closes' => '18:00',
    ]],
    'hasMap' => 'https://www.google.com/maps/search/?api=1&query=%E7%A7%8B%E7%94%B0%E7%9C%8C%E6%A8%AA%E6%89%8B%E5%B8%82%E5%A4%A7%E5%B1%8B%E5%AF%BA%E5%86%85%E5%AD%97%E5%A0%80%E3%83%8E%E5%86%8561',
    'contactPoint' => [[
      '@type' => 'ContactPoint', 'contactType' => 'customer service', 'telephone' => '+81-182-32-1121',
      'email' => 'info@prodesign.co.jp', 'areaServed' => 'JP', 'availableLanguage' => ['ja'],
    ]],
  ];
}
function seo_website(): array {
  $u = site_url();
  return [
    '@type' => 'WebSite', '@id' => $u . '/#website', 'url' => $u . '/',
    'name' => '株式会社プロデザイン', 'alternateName' => ['ProDesign', 'プロデザイン'],
    'inLanguage' => 'ja', 'publisher' => ['@id' => $u . '/#organization'],
  ];
}
/** 事業内容ページの3事業（business.html の本文にある内容だけ） */
function seo_services(): array {
  $u = site_url(); $org = ['@id' => $u . '/#organization']; $area = ['@type' => 'Country', 'name' => '日本'];
  $s = [
    ['design', '機械設計', '試作品・開発品の機械設計をアウトソーシング。部品毎の製造コスト・組立コストまで含めた、現場で「使える」設計を提供します。納品形式はDXFデータ、設計後のフォローも一貫対応します。'],
    ['manufacturing', '機械設計・製造', '設計から製造、組立、現地調整まで、御社だけの完全オーダーメイド自動機をワンストップで提供。お客様の生産ラインに最適化した装置を造り上げます。'],
    ['consulting', '専門家支援・セミナー', '設計業務の進捗管理支援、生産性改善コンサルティング、技術セミナーを提供。製造業の経営課題から技術指導まで、実践的に支援します。'],
  ];
  return array_map(fn($x) => [
    '@type' => 'Service', '@id' => $u . '/business.html#' . $x[0], 'name' => $x[1], 'serviceType' => $x[1],
    'description' => $x[2], 'url' => $u . '/business.html#' . $x[0], 'provider' => $org, 'areaServed' => $area,
  ], $s);
}
function seo_breadcrumb(array $items): array {
  $list = []; $i = 1;
  foreach ($items as [$name, $url]) $list[] = ['@type' => 'ListItem', 'position' => $i++, 'name' => $name, 'item' => $url];
  return ['@type' => 'BreadcrumbList', '@id' => end($items)[1] . '#breadcrumb', 'itemListElement' => $list];
}
function seo_jsonld(array $graph): string {
  $json = json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
  return '<script type="application/ld+json">' . $json . '</script>';
}

/** 記事の日付（"2024.02.22"）→ ISO 8601 */
function post_date_iso(array $p): string {
  $d = preg_replace('/[^0-9]+/', '-', trim((string)$p['date']));
  $t = strtotime($d . ' 00:00:00 +0900');
  return $t ? date('Y-m-d\TH:i:sP', $t) : date('Y-m-d\TH:i:sP');
}
/** 記事の最終更新（取り込み後に編集されていなければ記事の日付、編集されていれば更新時刻） */
function post_modified_ts(array $p): int {
  $pub = strtotime(preg_replace('/[^0-9]+/', '-', trim((string)$p['date'])) . ' 00:00:00 +0900') ?: time();
  $c = (int)($p['created_at'] ?? 0); $u = (int)($p['updated_at'] ?? 0);
  return ($u > $c + 60) ? max($u, $pub) : $pub;
}
function post_image_abs(array $p): string {
  $img = img_url($p['image'] ?? '');
  return $img === '' ? '' : abs_url($img);
}
function local_image_size(string $absOrRel): ?array {
  $rel = preg_replace('#^https?://[^/]+#', '', $absOrRel);
  $f = __DIR__ . '/' . ltrim($rel, '/');
  if (!is_file($f)) return null;
  $s = @getimagesize($f);
  return $s ? [(int)$s[0], (int)$s[1]] : null;
}

/**
 * <head> に入れる SEO ブロックを返す。
 * $key: index/business/works/company/news/contact/post/notfound
 * $ctx: post の時は ['post' => 記事の行]
 * $preview: true は GitHub Pages のプレビュー用（noindex を付ける）。本番では必ず false。
 */
function seo_head(string $key, array $ctx = [], bool $preview = false): string {
  $u = site_url(); $pages = seo_pages();
  $siteName = '株式会社プロデザイン（ProDesign）';
  $ogImage = $u . '/images/ogp.png'; $ogW = 1200; $ogH = 630; $ogType = 'website';
  $graph = [seo_org(), seo_website()];
  $extra = '';
  if ($key === 'post') {
    $p = $ctx['post'];
    $title = $p['title'] . '｜' . $siteName;
    $desc = excerpt($p['body'], 120);
    if ($desc === '') $desc = $p['title'] . '｜株式会社プロデザイン（秋田県横手市）のニュース。';
    $canonical = $u . '/post/' . $p['slug'];
    $hero = 'images/slide02.jpg'; $ogType = 'article';
    $pub = post_date_iso($p); $mod = date('Y-m-d\TH:i:sP', post_modified_ts($p));
    $img = post_image_abs($p);
    if ($img !== '') { $ogImage = $img; $sz = local_image_size($img); $ogW = $sz[0] ?? 0; $ogH = $sz[1] ?? 0; }
    $extra .= '<meta property="article:published_time" content="' . h($pub) . '">' . "\n" . '<meta property="article:modified_time" content="' . h($mod) . '">' . "\n" . '<meta property="article:section" content="' . h($p['category']) . '">' . "\n";
    $graph[] = [
      '@type' => 'WebPage', '@id' => $canonical . '#webpage', 'url' => $canonical, 'name' => $p['title'], 'inLanguage' => 'ja',
      'isPartOf' => ['@id' => $u . '/#website'], 'about' => ['@id' => $u . '/#organization'], 'breadcrumb' => ['@id' => $canonical . '#breadcrumb'],
    ];
    $article = [
      '@type' => 'Article', '@id' => $canonical . '#article', 'headline' => mb_substr($p['title'], 0, 110), 'description' => $desc,
      'datePublished' => $pub, 'dateModified' => $mod, 'articleSection' => $p['category'], 'inLanguage' => 'ja',
      'mainEntityOfPage' => ['@id' => $canonical . '#webpage'],
      'author' => ['@id' => $u . '/#organization'], 'publisher' => ['@id' => $u . '/#organization'],
      'image' => [$img !== '' ? $img : $u . '/images/ogp.png'],
    ];
    $graph[] = $article;
    $graph[] = seo_breadcrumb([['ホーム', $u . '/'], ['ニュース', $u . '/news.html'], [$p['title'], $canonical]]);
  } elseif ($key === 'notfound') {
    $title = 'ページが見つかりません｜' . $siteName;
    $desc = 'お探しのページは見つかりませんでした。株式会社プロデザイン（秋田県横手市）のトップページ、またはニュース一覧からお探しください。';
    $canonical = ''; $hero = 'images/slide02.jpg'; $graph = [];
  } else {
    $pg = $pages[$key] ?? $pages['index'];
    $title = $pg['title']; $desc = $pg['desc']; $canonical = $u . $pg['path']; $hero = $pg['hero'];
    $web = [
      '@type' => $pg['type'], '@id' => $canonical . '#webpage', 'url' => $canonical, 'name' => $title, 'description' => $desc, 'inLanguage' => 'ja',
      'isPartOf' => ['@id' => $u . '/#website'], 'about' => ['@id' => $u . '/#organization'],
    ];
    if ($key !== 'index') {
      $web['breadcrumb'] = ['@id' => $canonical . '#breadcrumb'];
      $graph[] = $web;
      $graph[] = seo_breadcrumb([['ホーム', $u . '/'], [$pg['name'], $canonical]]);
    } else {
      $graph[] = $web;
    }
    if ($key === 'business') foreach (seo_services() as $s) $graph[] = $s;
  }
  $o = SEO_START . "\n";
  $o .= '<title>' . h($title) . '</title>' . "\n";
  $o .= '<meta name="description" content="' . h($desc) . '">' . "\n";
  if ($preview || $key === 'notfound') $o .= '<meta name="robots" content="noindex,nofollow">' . "\n";
  else $o .= '<meta name="robots" content="index,follow,max-image-preview:large">' . "\n";
  if ($canonical !== '') {
    $o .= '<link rel="canonical" href="' . h($canonical) . '">' . "\n";
    $o .= '<meta property="og:type" content="' . $ogType . '">' . "\n";
    $o .= '<meta property="og:site_name" content="' . h($siteName) . '">' . "\n";
    $o .= '<meta property="og:locale" content="ja_JP">' . "\n";
    $o .= '<meta property="og:title" content="' . h($title) . '">' . "\n";
    $o .= '<meta property="og:description" content="' . h($desc) . '">' . "\n";
    $o .= '<meta property="og:url" content="' . h($canonical) . '">' . "\n";
    $o .= '<meta property="og:image" content="' . h($ogImage) . '">' . "\n";
    if ($ogW && $ogH) $o .= '<meta property="og:image:width" content="' . $ogW . '">' . "\n" . '<meta property="og:image:height" content="' . $ogH . '">' . "\n";
    $o .= '<meta property="og:image:alt" content="' . h($key === 'post' ? $ctx['post']['title'] : $siteName) . '">' . "\n";
    $o .= $extra;
    $o .= '<meta name="twitter:card" content="summary_large_image">' . "\n";
    $o .= '<meta name="twitter:title" content="' . h($title) . '">' . "\n";
    $o .= '<meta name="twitter:description" content="' . h($desc) . '">' . "\n";
    $o .= '<meta name="twitter:image" content="' . h($ogImage) . '">' . "\n";
  }
  $o .= '<link rel="icon" href="favicon.ico" sizes="48x48">' . "\n";
  $o .= '<link rel="icon" type="image/png" sizes="192x192" href="images/icons/icon-192.png">' . "\n";
  $o .= '<link rel="apple-touch-icon" sizes="180x180" href="images/icons/apple-touch-icon.png">' . "\n";
  $o .= '<link rel="alternate" type="application/rss+xml" title="' . h($siteName) . ' ニュース" href="' . h($u . '/feed.xml') . '">' . "\n";
  $o .= '<link rel="preload" as="image" href="' . h($hero) . '"' . ($key === 'index' ? ' fetchpriority="high"' : '') . '>' . "\n";
  if ($graph) $o .= seo_jsonld($graph) . "\n";
  $o .= SEO_END;
  return $o;
}

/** HTML の <head> にある SEO ブロック（または旧来の title＋description）を差し替える */
function seo_apply(string $html, string $block): string {
  $q = str_replace(['\\', '$'], ['\\\\', '\\$'], $block);
  $n = 0;
  $out = preg_replace('/<!-- SEO:START[^\n]*-->[\s\S]*?<!-- SEO:END -->/u', $q, $html, 1, $n);
  if ($n === 0) $out = preg_replace('/<title>[\s\S]*?<\/title>\s*<meta name="description"[^>]*>/u', $q, $html, 1, $n);
  if ($n === 0) $out = preg_replace('/<\/head>/i', $q . "\n</head>", $html, 1);
  return $out;
}
/** 本番出力の最終安全弁: noindex が紛れ込んでいたら取り除く（プレビュー用の静的コピーをそのまま読んだ場合に備える） */
function seo_strip_noindex(string $html): string {
  return preg_replace('/[ \t]*<meta\s+name=["\']robots["\']\s+content=["\'][^"\']*noindex[^"\']*["\']\s*\/?>\r?\n?/i', '', $html);
}
