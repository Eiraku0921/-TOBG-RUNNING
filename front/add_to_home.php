<?php
require_once __DIR__ . '/../back/session_bootstrap.php';
if (session_status() === PHP_SESSION_NONE) {
    tobg_start_session();
}

// ホーム画面への追加方法（iPhone / Android）。ログインしていなくても見られる。
// 手順の画像は images/home_guide/{ios|android}-{手順番号}.{png|jpg|webp} を置くとそれを使い、
// 無い手順は、操作する場所に印を付けた簡略図（SVG）を出す。

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function guide_screenshot_path(string $platform, int $step): ?string
{
    foreach (['png', 'jpg', 'webp'] as $ext) {
        $relative = 'images/home_guide/' . $platform . '-' . $step . '.' . $ext;
        $absolute = __DIR__ . '/../' . $relative;
        if (is_file($absolute)) {
            return '../' . $relative . '?v=' . (int)filemtime($absolute);
        }
    }
    return null;
}

// 簡略図の部品。スマホの枠の中を 120×200 の座標で描く
function guide_phone(string $inner): string
{
    return '<svg class="guide-figure-svg" viewBox="0 0 140 236" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
        . '<rect x="4" y="4" width="132" height="228" rx="18" fill="#FFFFFF" stroke="#2B2723" stroke-width="3"/>'
        . '<rect x="52" y="12" width="36" height="7" rx="3.5" fill="#2B2723"/>'
        . '<g transform="translate(10 26)">' . $inner . '</g>'
        . '</svg>';
}

// 操作する場所の印（アクセント色の丸）
function guide_mark(float $cx, float $cy, float $r = 11): string
{
    return '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" stroke="#D9661E" stroke-width="3"/>';
}

// 本文の代わりの灰色の線
function guide_lines(float $y, int $count): string
{
    $widths = [96, 80, 90, 64, 86];
    $svg = '';
    for ($i = 0; $i < $count; $i++) {
        $svg .= '<rect x="8" y="' . ($y + $i * 14) . '" width="' . $widths[$i % 5] . '" height="6" rx="3" fill="#EFE7D8"/>';
    }
    return $svg;
}

function guide_ios_share_icon(float $x, float $y, string $color = '#2B2723'): string
{
    return '<g transform="translate(' . $x . ' ' . $y . ')" fill="none" stroke="' . $color . '" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
        . '<path d="M-5 -1 H-7 V9 H7 V-1 H5"/><path d="M0 -8 V4"/><path d="M-3.5 -4.5 L0 -8 L3.5 -4.5"/></g>';
}

function guide_figure(string $platform, int $step): string
{
    $ink = '#2B2723';
    $muted = '#8A8478';
    $accent = '#D9661E';
    $text = static fn(float $x, float $y, string $s, int $size = 8, string $color = '#2B2723', string $anchor = 'start', int $weight = 700): string
        => '<text x="' . $x . '" y="' . $y . '" font-size="' . $size . '" font-weight="' . $weight . '" fill="' . $color . '" text-anchor="' . $anchor . '">' . h($s) . '</text>';

    if ($platform === 'ios') {
        switch ($step) {
            case 1: // Safari で開く（アドレスバーは下）
                return guide_phone(
                    guide_lines(12, 5)
                    . '<circle cx="60" cy="118" r="20" fill="none" stroke="' . $accent . '" stroke-width="3"/>'
                    . '<path d="M60 102 L65 118 L60 134 L55 118 Z" fill="' . $accent . '"/>'
                    . $text(60, 152, 'Safari', 9, $ink, 'middle')
                    . '<rect x="6" y="166" width="108" height="16" rx="8" fill="#F1E6D3"/>'
                    . $text(60, 177, 'tobg.sadist.jp', 7, $muted, 'middle', 600)
                );
            case 2: // 下の5つのアイコンの左から3番目（共有）
                $icons = '';
                foreach ([12, 36, 60, 84, 108] as $index => $x) {
                    $icons .= $index === 2
                        ? guide_ios_share_icon($x, 186, $ink)
                        : '<rect x="' . ($x - 5) . '" y="181" width="10" height="10" rx="2" fill="none" stroke="' . $muted . '" stroke-width="1.6"/>';
                }
                return guide_phone(
                    guide_lines(12, 5)
                    . '<rect x="6" y="150" width="108" height="16" rx="8" fill="#F1E6D3"/>'
                    . $text(60, 161, 'tobg.sadist.jp', 7, $muted, 'middle', 600)
                    . $icons
                    . guide_mark(60, 186, 12)
                );
            case 3: // 共有メニューの「ホーム画面に追加」
                $rows = '';
                foreach (['コピー', 'リーディングリストに追加', 'ブックマークを追加', 'ホーム画面に追加', 'ページを検索'] as $index => $label) {
                    $y = 86 + $index * 20;
                    $is_target = $label === 'ホーム画面に追加';
                    $rows .= '<rect x="8" y="' . $y . '" width="104" height="18" rx="4" fill="' . ($is_target ? '#FDF3E8' : '#FFFFFF') . '"' . ($is_target ? ' stroke="' . $accent . '" stroke-width="2"' : '') . '/>'
                        . $text(14, $y + 12, $label, 7, $is_target ? $ink : $muted, 'start', $is_target ? 800 : 600);
                    if ($is_target) {
                        $rows .= '<rect x="96" y="' . ($y + 3) . '" width="12" height="12" rx="2.5" fill="none" stroke="' . $ink . '" stroke-width="1.5"/>'
                            . '<path d="M102 ' . ($y + 6) . ' V' . ($y + 12) . ' M99 ' . ($y + 9) . ' H105" stroke="' . $ink . '" stroke-width="1.5" stroke-linecap="round"/>';
                    }
                }
                return guide_phone(
                    '<rect x="0" y="40" width="120" height="160" rx="12" fill="#FAF6EF"/>'
                    . $text(60, 58, 'TOBG-RUNNING', 8, $ink, 'middle')
                    . $text(60, 72, '下にスクロール ↓', 7, $accent, 'middle')
                    . $rows
                );
            default: // 名前を確認して「追加」
                return guide_phone(
                    $text(8, 14, 'キャンセル', 7, $muted, 'start', 600)
                    . $text(112, 14, '追加', 8, $accent, 'end')
                    . guide_mark(104, 11, 12)
                    . '<rect x="8" y="34" width="104" height="44" rx="8" fill="#FAF6EF"/>'
                    . '<rect x="14" y="40" width="32" height="32" rx="7" fill="#F1E6D3"/>'
                    . $text(30, 60, 'T', 12, $accent, 'middle', 900)
                    . $text(52, 54, 'TOBG-RUNNING', 7, $ink)
                    . '<rect x="52" y="60" width="52" height="5" rx="2.5" fill="#EFE7D8"/>'
                );
        }
    }

    switch ($step) {
        case 1: // Chrome で開く（アドレスバーは上）
            return guide_phone(
                '<rect x="6" y="0" width="108" height="16" rx="8" fill="#F1E6D3"/>'
                . $text(60, 11, 'tobg.sadist.jp', 7, $muted, 'middle', 600)
                . '<circle cx="60" cy="96" r="20" fill="none" stroke="' . $accent . '" stroke-width="3"/>'
                . '<circle cx="60" cy="96" r="7" fill="' . $accent . '"/>'
                . $text(60, 132, 'Chrome', 9, $ink, 'middle')
                . guide_lines(150, 2)
            );
        case 2: // 右上の縦三点
            return guide_phone(
                '<rect x="4" y="0" width="94" height="16" rx="8" fill="#F1E6D3"/>'
                . $text(50, 11, 'tobg.sadist.jp', 7, $muted, 'middle', 600)
                . '<circle cx="110" cy="3" r="1.8" fill="' . $ink . '"/><circle cx="110" cy="8" r="1.8" fill="' . $ink . '"/><circle cx="110" cy="13" r="1.8" fill="' . $ink . '"/>'
                . guide_mark(110, 8, 10)
                . guide_lines(30, 5)
            );
        case 3: // メニューの「アプリをインストール」
            $rows = '';
            foreach (['新しいタブ', '履歴', 'ブックマーク', 'アプリをインストール', '設定'] as $index => $label) {
                $y = 22 + $index * 20;
                $is_target = $label === 'アプリをインストール';
                $rows .= '<rect x="34" y="' . $y . '" width="84" height="18" rx="4" fill="' . ($is_target ? '#FDF3E8' : '#FFFFFF') . '"' . ($is_target ? ' stroke="' . $accent . '" stroke-width="2"' : '') . '/>'
                    . $text(40, $y + 12, $label, 7, $is_target ? $ink : $muted, 'start', $is_target ? 800 : 600);
            }
            return guide_phone(
                '<rect x="4" y="0" width="94" height="16" rx="8" fill="#F1E6D3"/>'
                . guide_lines(30, 5)
                . '<rect x="30" y="16" width="90" height="110" rx="8" fill="#FFFFFF" stroke="#EFE7D8" stroke-width="1.5"/>'
                . $rows
            );
        default: // 「インストール」
            return guide_phone(
                guide_lines(12, 3)
                . '<rect x="0" y="60" width="120" height="100" rx="12" fill="#FFFFFF" stroke="#EFE7D8" stroke-width="1.5"/>'
                . $text(10, 80, 'アプリをインストール', 8, $ink)
                . '<rect x="10" y="90" width="24" height="24" rx="6" fill="#F1E6D3"/>'
                . $text(22, 106, 'T', 10, $accent, 'middle', 900)
                . $text(40, 105, 'TOBG-RUNNING', 7, $ink)
                . $text(48, 146, 'キャンセル', 7, $muted, 'end', 600)
                . '<rect x="58" y="134" width="54" height="18" rx="9" fill="' . $accent . '"/>'
                . $text(85, 146, 'インストール', 7, '#FFFFFF', 'middle')
            );
    }
}

$guide_steps = [
    'ios' => [
        ['Safariでサイトを開く', 'iPhoneでは、Safari以外のブラウザ（ChromeやLINEの中のブラウザなど）からは追加できません。このページをSafariで開き直してください。'],
        ['共有ボタンをタップ', '画面の下に並ぶ5つのアイコンのうち、左から3番目の共有ボタン（四角から上向きの矢印が出ているアイコン）をタップします。見当たらないときは、「…」ボタンの中にあります。'],
        ['「ホーム画面に追加」を選ぶ', '共有メニューを下にスクロールし、「＋」のアイコンの「ホーム画面に追加」を選びます。'],
        ['名前を確認して「追加」', '名前は「TOBG-RUNNING」になっています（自由に変えられます）。右上の「追加」をタップすると、ホーム画面にアイコンができます。'],
    ],
    'android' => [
        ['Chromeでサイトを開く', 'このページをChromeで開きます。'],
        ['メニューをタップ', '画面右上のメニュー（縦に3つ並んだ点）をタップします。'],
        ['「アプリをインストール」を選ぶ', 'メニューの中の「アプリをインストール」を選びます。機種によっては「ホーム画面に追加」と表示されます。'],
        ['「インストール」をタップ', '確認の画面で「インストール」をタップすると、ホーム画面にアイコンができます。'],
    ],
];
$platform_labels = ['ios' => 'iPhone', 'android' => 'Android'];

// 最初に開くタブは端末に合わせる（Android なら Android、それ以外は iPhone）
$user_agent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$initial_platform = stripos($user_agent, 'Android') !== false ? 'android' : 'ios';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ホーム画面への追加方法 | TOBG</title>
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
        .guide-wrap { max-width: 760px; margin: 0 auto; padding: 10px var(--page-gutter) 40px; }
        .guide-card {
            background: var(--color-card-bg);
            border-radius: var(--card-radius);
            padding: 22px 24px;
            margin-bottom: 10px;
        }
        .guide-title {
            margin: 0 0 6px;
            font-size: 1.4rem;
            font-weight: 900;
            color: var(--color-text-primary);
        }
        .guide-lead {
            margin: 0;
            color: var(--color-text-secondary);
            font-size: 0.9rem;
            line-height: 1.7;
        }
        .guide-installed {
            margin: 12px 0 0;
            padding: 8px 12px;
            border-radius: var(--button-radius);
            background: var(--color-accent-bg);
            color: var(--color-text-primary);
            font-size: 0.85rem;
            font-weight: 700;
        }

        /* iPhone / Android の切り替え（NEXT PR と同じセグメント） */
        .segment {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            padding: 3px;
            border-radius: 999px;
            background: var(--color-tab-bg);
            margin: 16px 0 0;
        }
        .segment-btn {
            border: 0;
            border-radius: 999px;
            padding: 8px 0;
            background: transparent;
            color: var(--color-text-secondary);
            font-weight: 700;
            font-size: 0.92rem;
        }
        .segment-btn[aria-selected="true"] {
            background: var(--color-card-bg);
            color: var(--color-text-primary);
            box-shadow: 0 1px 3px rgba(43, 39, 35, 0.12);
        }

        /* 手順 */
        .guide-steps {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .guide-step {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 150px;
            gap: 18px;
            align-items: center;
            padding: 18px 0;
        }
        .guide-step + .guide-step {
            border-top: 1px solid var(--color-border);
        }
        .guide-step-head {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 6px;
        }
        .guide-step-num {
            flex-shrink: 0;
            width: 30px;
            height: 30px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: var(--color-accent-bg);
            color: var(--color-accent);
            font-weight: 800;
        }
        .guide-step-title {
            margin: 0;
            font-size: 1rem;
            font-weight: 800;
            color: var(--color-text-primary);
        }
        .guide-step-text {
            margin: 0;
            padding-left: 40px;
            color: var(--color-text-secondary);
            font-size: 0.88rem;
            line-height: 1.75;
        }
        .guide-figure {
            margin: 0;
            display: flex;
            justify-content: center;
        }
        .guide-figure-svg {
            width: 120px;
            height: auto;
        }
        .guide-figure img {
            width: 150px;
            height: auto;
            border-radius: 14px;
            border: 1px solid var(--color-border);
        }
        .guide-back {
            display: inline-block;
            margin-top: 6px;
            color: var(--color-text-secondary);
            font-size: 0.88rem;
            font-weight: 700;
            text-decoration: none;
        }
        .guide-back:hover {
            color: var(--color-text-primary);
        }

        @media (max-width: 576px) {
            .guide-card {
                padding: 18px 16px;
            }
            .guide-step {
                grid-template-columns: minmax(0, 1fr) 96px;
                gap: 12px;
            }
            .guide-step-text {
                padding-left: 0;
            }
            .guide-figure-svg {
                width: 92px;
            }
            .guide-figure img {
                width: 96px;
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

<main class="guide-wrap">
    <section class="guide-card" aria-labelledby="guideTitle">
        <h1 class="guide-title" id="guideTitle">ホーム画面への追加方法</h1>
        <p class="guide-lead">追加すると、アプリのように開けて通知も届きやすくなります。</p>
        <p class="guide-installed" id="guideInstalled" hidden>いまホーム画面のアイコンから開いています。追加は完了しています。</p>

        <div class="segment" role="tablist" aria-label="端末の種類">
            <?php foreach ($platform_labels as $platform => $label): ?>
                <button type="button" class="segment-btn" role="tab" id="guideTab-<?php echo h($platform); ?>" aria-controls="guidePanel-<?php echo h($platform); ?>" aria-selected="<?php echo $platform === $initial_platform ? 'true' : 'false'; ?>" data-guide-tab><?php echo h($label); ?></button>
            <?php endforeach; ?>
        </div>
    </section>

    <?php foreach ($guide_steps as $platform => $steps): ?>
        <section class="guide-card" role="tabpanel" id="guidePanel-<?php echo h($platform); ?>" aria-labelledby="guideTab-<?php echo h($platform); ?>" <?php echo $platform === $initial_platform ? '' : 'hidden'; ?>>
            <ol class="guide-steps">
                <?php foreach ($steps as $index => [$step_title, $step_text]): ?>
                    <?php $step_number = $index + 1; ?>
                    <?php $screenshot = guide_screenshot_path($platform, $step_number); ?>
                    <li class="guide-step">
                        <div>
                            <div class="guide-step-head">
                                <span class="guide-step-num" aria-hidden="true"><?php echo $step_number; ?></span>
                                <h2 class="guide-step-title"><span class="visually-hidden">手順<?php echo $step_number; ?>：</span><?php echo h($step_title); ?></h2>
                            </div>
                            <p class="guide-step-text"><?php echo h($step_text); ?></p>
                        </div>
                        <figure class="guide-figure">
                            <?php if ($screenshot !== null): ?>
                                <img src="<?php echo h($screenshot); ?>" alt="<?php echo h($platform_labels[$platform] . 'の手順' . $step_number . '：' . $step_title); ?>" loading="lazy">
                            <?php else: ?>
                                <?php echo guide_figure($platform, $step_number); ?>
                            <?php endif; ?>
                        </figure>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
    <?php endforeach; ?>

    <a class="guide-back" href="../index.php">← ホームへ戻る</a>
</main>

<script>
(() => {
    document.querySelectorAll('[data-guide-tab]').forEach((tab) => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('[data-guide-tab]').forEach((other) => {
                const selected = other === tab;
                other.setAttribute('aria-selected', selected ? 'true' : 'false');
                document.getElementById(other.getAttribute('aria-controls')).hidden = !selected;
            });
        });
    });

    // ホーム画面のアイコンから開いているときは「追加済み」と出す
    const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    document.getElementById('guideInstalled').hidden = !standalone;
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
