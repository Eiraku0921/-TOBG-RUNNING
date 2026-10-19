<?php
require_once __DIR__ . '/../back/session_bootstrap.php';
if (session_status() === PHP_SESSION_NONE) {
    tobg_start_session();
}

// グループ機能の使い方（G21）。文字を減らし、アイコンと短い言葉で見せる。ログインしていなくても見られる。
// お知らせの「使い方を見る」ボタンと、ホームのマイグループ画面の「使い方」から来る。

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// 線のアイコン（24×24 の座標）
function guide_icon(string $paths): string
{
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
}

$icon_users = guide_icon('<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>');
$icon_plus = guide_icon('<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>');
$icon_share = guide_icon('<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>');
$icon_user_check = guide_icon('<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/>');
$icon_link = guide_icon('<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>');
$icon_keyboard = guide_icon('<rect x="2" y="6" width="20" height="12" rx="2"/><line x1="6" y1="10" x2="6" y2="10"/><line x1="10" y1="10" x2="10" y2="10"/><line x1="14" y1="10" x2="14" y2="10"/><line x1="18" y1="10" x2="18" y2="10"/><line x1="7" y1="14" x2="17" y2="14"/>');
$icon_globe = guide_icon('<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>');
$icon_lock = guide_icon('<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>');
$icon_mail = guide_icon('<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>');
$icon_logout = guide_icon('<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>');
$icon_clipboard = guide_icon('<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><polyline points="9 14 11 16 15 12"/>');
$icon_arrow = guide_icon('<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>');

$make_steps = [[$icon_plus, '作成'], [$icon_share, '招待を共有'], [$icon_user_check, '参加完了']];
$join_ways = [[$icon_link, 'リンクをタップ'], [$icon_keyboard, 'コードを入力']];
$notes = [
    [$icon_mail, 'メンバーは招待だけ', 'あとから追加・削除はできません'],
    [$icon_logout, '脱退はいつでも', '自分で抜けられます'],
    [$icon_clipboard, '削除は申請制', '管理者の承認後に削除されます'],
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>グループ機能 | TOBG</title>
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
        body { background: var(--color-page-bg); }
        .gg-wrap { max-width: 760px; margin: 0 auto; padding: 10px var(--page-gutter) 40px; }
        .gg-card {
            background: var(--color-card-bg);
            border-radius: var(--card-radius);
            padding: 22px 24px;
            margin-bottom: 10px;
        }
        .gg-block-title {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin: 0 0 16px;
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--color-text-primary);
        }
        .gg-block-title::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--color-accent);
        }
        /* アイコンは薄いアクセント色の丸に、アクセント色の線で描く */
        .gg-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: var(--color-accent-bg);
            color: var(--color-accent);
        }
        .gg-icon svg {
            width: 26px;
            height: 26px;
        }

        /* 見出しブロック */
        .gg-hero {
            text-align: center;
            padding: 28px 24px;
        }
        .gg-hero .gg-icon {
            width: 84px;
            height: 84px;
        }
        .gg-hero .gg-icon svg {
            width: 40px;
            height: 40px;
        }
        .gg-hero-title {
            margin: 14px 0 6px;
            font-size: 1.5rem;
            font-weight: 900;
        }
        .gg-hero-lead {
            margin: 0;
            color: var(--color-text-secondary);
            font-size: 0.92rem;
        }

        /* つくる（矢印でつなぐ）・はいる */
        .gg-flow {
            display: flex;
            align-items: flex-start;
            justify-content: center;
            gap: 6px;
        }
        .gg-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            flex: 1;
            max-width: 140px;
            text-align: center;
        }
        .gg-step-caption {
            font-size: 0.82rem;
            font-weight: 700;
            line-height: 1.4;
        }
        .gg-step-num {
            display: block;
            color: var(--color-text-muted);
            font-size: 0.7rem;
            font-weight: 700;
        }
        .gg-arrow {
            flex-shrink: 0;
            margin-top: 18px;
            color: #D9C9AE;
        }
        .gg-arrow svg {
            width: 20px;
            height: 20px;
        }
        .gg-or {
            align-self: center;
            margin: 0 4px 24px;
            color: var(--color-text-muted);
            font-size: 0.78rem;
            font-weight: 700;
        }

        /* 公開範囲のチップ（投稿画面と同じ見た目のミニチュア） */
        .gg-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 14px 12px;
        }
        .gg-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border-radius: 999px;
            background: var(--color-tab-bg);
            color: var(--color-text-secondary);
            font-size: 0.85rem;
            font-weight: 700;
        }
        .gg-chip svg {
            width: 16px;
            height: 16px;
        }
        .gg-chip.is-selected {
            background: var(--color-accent);
            color: var(--color-accent-text);
        }

        /* おぼえておくこと */
        .gg-notes {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .gg-note {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 10px 0;
        }
        .gg-note + .gg-note {
            border-top: 1px solid var(--color-border);
        }
        .gg-note .gg-icon {
            width: 46px;
            height: 46px;
        }
        .gg-note .gg-icon svg {
            width: 22px;
            height: 22px;
        }
        .gg-note-title {
            display: block;
            font-weight: 800;
        }
        .gg-note-sub {
            display: block;
            color: var(--color-text-secondary);
            font-size: 0.8rem;
        }
        .gg-back {
            display: inline-block;
            margin-top: 6px;
            color: var(--color-text-secondary);
            font-size: 0.88rem;
            font-weight: 700;
            text-decoration: none;
        }
        .gg-back:hover {
            color: var(--color-text-primary);
        }

        @media (max-width: 576px) {
            .gg-card {
                padding: 18px 14px;
            }
            .gg-icon {
                width: 48px;
                height: 48px;
            }
            .gg-icon svg {
                width: 22px;
                height: 22px;
            }
            .gg-arrow {
                margin-top: 14px;
            }
            .gg-arrow svg {
                width: 16px;
                height: 16px;
            }
        }
    </style>
</head>
<body>
<?php
$shared_header = __DIR__ . '/../includes/site_header.php';
if (file_exists($shared_header)) {
    require_once $shared_header;
}
?>

<main class="gg-wrap">
    <section class="gg-card gg-hero" aria-labelledby="ggTitle">
        <span class="gg-icon"><?php echo $icon_users; ?></span>
        <h1 class="gg-hero-title" id="ggTitle">グループ機能</h1>
        <p class="gg-hero-lead">仲間だけに練習日記を見せられます</p>
    </section>

    <section class="gg-card" aria-labelledby="ggMake">
        <h2 class="gg-block-title" id="ggMake">つくる</h2>
        <ol class="gg-flow list-unstyled m-0">
            <?php foreach ($make_steps as $index => [$icon, $caption]): ?>
                <?php if ($index > 0): ?>
                    <li class="gg-arrow" aria-hidden="true"><?php echo $icon_arrow; ?></li>
                <?php endif; ?>
                <li class="gg-step">
                    <span class="gg-icon"><?php echo $icon; ?></span>
                    <span class="gg-step-caption"><span class="gg-step-num">STEP <?php echo $index + 1; ?></span><?php echo h($caption); ?></span>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>

    <section class="gg-card" aria-labelledby="ggJoin">
        <h2 class="gg-block-title" id="ggJoin">はいる</h2>
        <ul class="gg-flow list-unstyled m-0">
            <?php foreach ($join_ways as $index => [$icon, $caption]): ?>
                <?php if ($index > 0): ?>
                    <li class="gg-or" aria-hidden="true">または</li>
                <?php endif; ?>
                <li class="gg-step">
                    <span class="gg-icon"><?php echo $icon; ?></span>
                    <span class="gg-step-caption"><?php echo h($caption); ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="gg-card" aria-labelledby="ggScope">
        <h2 class="gg-block-title" id="ggScope">公開範囲をえらぶ</h2>
        <?php // 投稿画面の公開範囲のチップと同じ見た目（押せない見本） ?>
        <div class="gg-chips" aria-label="公開範囲の例：みんなの練習日記と自分のカレンダーを選択中、グループは未選択">
            <span class="gg-chip is-selected"><?php echo $icon_globe; ?>みんなの練習日記</span>
            <span class="gg-chip is-selected"><?php echo $icon_lock; ?>自分のカレンダーのみ</span>
            <span class="gg-chip"><?php echo $icon_users; ?>朝ラン仲間</span>
        </div>
    </section>

    <section class="gg-card" aria-labelledby="ggNotes">
        <h2 class="gg-block-title" id="ggNotes">おぼえておくこと</h2>
        <ul class="gg-notes">
            <?php foreach ($notes as [$icon, $title, $sub]): ?>
                <li class="gg-note">
                    <span class="gg-icon"><?php echo $icon; ?></span>
                    <span><span class="gg-note-title"><?php echo h($title); ?></span><span class="gg-note-sub"><?php echo h($sub); ?></span></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <a class="gg-back" href="../index.php">← ホームへ戻る</a>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
