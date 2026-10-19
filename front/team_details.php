<?php
require_once __DIR__ . '/../back/session_bootstrap.php';
if (session_status() === PHP_SESSION_NONE) {
    tobg_start_session();
}

// 「ラベル: 本文」の項目。ラベルは太字にして読みやすくする
$team_features = [
    ['入会審査なし', 'サイトでログインすればどなたでも練習日記を投稿・閲覧できます'],
    ['全体活動はありません', '決まった練習日や集合場所はなく、それぞれのペースでランニングを楽しむスタイルです'],
    ['JAAF(日本陸上競技連盟)登録が可能', '大会出場などで登録を希望する方は登録できます（任意）'],
    ['年会費', 'JAAF登録者は年会費3,500円がかかります（登録は任意のため、登録しない方は年会費もかかりません）'],
    ['居住地', '福岡県内に住所があることが入会条件です（実家住まい・一人暮らしのどちらでも構いません）'],
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>チーム詳細 | TOBG</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;700;800;900&family=Zen+Kaku+Gothic+New:wght@400;500;700;900&display=swap" rel="stylesheet">
    <link href="../css/theme.css?v=<?php echo (int)@filemtime(__DIR__ . '/../css/theme.css'); ?>" rel="stylesheet">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#F4EEE4">
    <meta name="apple-mobile-web-app-title" content="TOBG-RUNNING">
    <link rel="apple-touch-icon" href="../icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" href="../icons/favicon.png">
    <style>
        body { background-color: var(--color-page-bg); color: var(--color-text-primary); }
        .page-wrap { max-width: var(--content-max-width); margin: 0 auto; padding: 10px var(--page-gutter) 24px; }

        /* 情報のまとまりごとの白いカード（間にページ背景の余白を挟む） */
        .td-card {
            background: var(--color-card-bg);
            border-radius: var(--card-radius);
            padding: 20px 24px;
            margin-bottom: 10px;
        }

        /* 見出し: 帯は使わず、左にアクセントの丸だけ */
        .section-title {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
            font-weight: 800;
            color: var(--color-text-primary);
        }
        .section-title::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--color-accent);
            flex-shrink: 0;
        }
        .page-title-card {
            padding-top: 16px;
            padding-bottom: 16px;
        }
        .page-title {
            font-size: 1.3rem;
        }
        .page-lead {
            margin: 6px 0 0;
            color: var(--color-text-secondary);
            font-size: 0.85rem;
        }

        /* 項目は罫線で区切った1つのリスト */
        .sub-title {
            font-size: 1.02rem;
        }
        .sub-title + .info-list {
            margin-top: 10px;
        }
        .info-list + .sub-title {
            margin-top: 22px;
        }
        .info-list {
            list-style: none;
            margin: 0;
            padding: 0;
            font-size: 0.9rem;
            line-height: 1.7;
        }
        .info-list li {
            padding: 10px 0;
            border-top: 1px solid var(--color-border);
        }
        .info-list li:last-child {
            border-bottom: 1px solid var(--color-border);
        }
        .info-label {
            font-weight: 800;
        }
        .dm-link {
            color: var(--color-text-primary);
            font-weight: 800;
            text-decoration: underline;
            text-underline-offset: 2px;
        }

        /* Instagram: 白いカード。アイコンとボタンだけアクセント色 */
        .insta-card {
            text-align: center;
        }
        .insta-icon {
            color: var(--color-accent);
            margin-bottom: 6px;
        }
        .insta-title {
            justify-content: center;
            font-size: 1.02rem;
        }
        .insta-title::before {
            display: none;
        }
        .insta-note {
            margin: 6px 0 14px;
            color: var(--color-text-secondary);
            font-size: 0.85rem;
        }

        @media (max-width: 576px) {
            .td-card {
                padding: 16px 14px;
            }
            .page-title {
                font-size: 1.15rem;
            }
        }
    </style>
</head>
<body class="theme-v2">
<?php
$shared_header = __DIR__ . '/../includes/site_header.php';
if (file_exists($shared_header)) {
    require_once $shared_header;
} else {
    echo '<div style="background:var(--color-header);color:#fff;padding:12px 16px;font-weight:700;">TEAM OFFICIAL</div>';
}
?>

<div class="page-wrap">
    <section class="td-card page-title-card">
        <h1 class="section-title page-title">チーム詳細</h1>
        <p class="page-lead">ホームに掲載している紹介内容を、こちらでまとめて確認できます。</p>
    </section>

    <section class="td-card" aria-label="チーム紹介とチームの特徴">
        <h2 class="section-title sub-title">チーム紹介</h2>
        <ul class="info-list">
            <li>TOBG-RUNNINGは、藤蔭高校OB・OGの有志が立ち上げたランニングクラブチームです。</li>
            <li>大学生・社会人問わず、運動を始めたい方</li>
            <li>大会を目指すというより、まずは楽しく続けたい方</li>
            <li>一人だと三日坊主になりがちな方</li>
            <li>陸上OB・OGじゃなくても、走ることに興味がある方</li>
        </ul>

        <h2 class="section-title sub-title">チームの特徴</h2>
        <ul class="info-list">
            <?php foreach ($team_features as [$feature_label, $feature_text]): ?>
                <li><span class="info-label"><?php echo htmlspecialchars($feature_label, ENT_QUOTES, 'UTF-8'); ?>:</span> <?php echo htmlspecialchars($feature_text, ENT_QUOTES, 'UTF-8'); ?></li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="td-card insta-card" aria-labelledby="instaTitle">
        <div class="insta-icon" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>
        </div>
        <h2 class="section-title insta-title" id="instaTitle">最新情報はInstagramで発信中</h2>
        <p class="insta-note">ぜひフォローして、雰囲気をチェックしてみてください。</p>
        <a class="btn btn-primary btn-sm" href="https://www.instagram.com/teamtobgrunning?igsh=a3U3bXE5Z2RyNTlh&utm_source=qr" target="_blank" rel="noopener noreferrer">Instagramをフォローする</a>
    </section>

    <section class="td-card" aria-labelledby="joinTitle">
        <h2 class="section-title sub-title" id="joinTitle">チーム所属に興味がある方へ</h2>
        <ul class="info-list">
            <li>全体活動がない分、気軽なペースで参加できます。<br>まずは練習日記を覗いてみる・Instagramをフォローしてみる、それだけでも構いません。</li>
            <li>チーム所属をご希望の方は、このサイトの<a class="dm-link" href="contact.php">問い合わせ</a>に送ってください。</li>
        </ul>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
