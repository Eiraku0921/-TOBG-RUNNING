<?php
// オンボーディング画面（G22。サービス紹介）。未ログインの人がログイン画面へのリンクを押したとき、その手前で一度だけ出す。
// 出すかどうかは、ブラウザの localStorage の onboarding_seen で決める（includes/site_header.php の処理が、まだ見ていない人だけをここへ回す）。
// スキップ・はじめる・ログイン画面へ進む、のどれで抜けても「見た」と記録して、押したログインのリンクの行き先（?next=）へ進む。
// この画面自体にはログインの機能はない（案内だけ）。
require_once __DIR__ . '/../back/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/../back/redirect_lib.php';

// 抜けた先: 押したログインのリンクの行き先（同じサイトの、ログイン画面のときだけ）。それ以外はふつうのログイン画面
$welcome_next = safe_return_location((string)($_GET['next'] ?? ''), 'login.php');
if ($welcome_next !== 'login.php' && !preg_match('#^/(?:[A-Za-z0-9_.-]+/)*mypage/login\.php(?:[?\#].*)?$#', $welcome_next)) {
    $welcome_next = 'login.php';
}

// ログイン中の人には出さない（ログイン状態の判定を、ブラウザの記録より先に行う）
if (isset($_SESSION['user_id'], $_SESSION['user_name'])) {
    header('Location: ../index.php');
    exit;
}

$e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
// 3枚のスライド（会員が実際に使う順: 練習日記 → グループ → NEXT PR）。アイコンは輪郭線（線の太さ 1.6、角は丸）
$welcome_slides = [
    [
        'title' => '練習日記をつける',
        'text' => '距離・時間・ペースは選ぶだけで入力でき、手入力はほとんど不要。「大会」を選べばNEXT PRの自己ベストにも自動で反映されます。写真や動画の添付、みんなの日記へのいいね・コメントもできます。',
        'icon' => '<path d="M6 3h11a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6z"/><path d="M6 3a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2"/><path d="M9 8h6"/><path d="M9 12h6"/><path d="M9 16h3"/>',
    ],
    [
        'title' => '仲間とつながる',
        'text' => '気の合うメンバーで「グループ」を作成。招待コードやリンクで誰でも誘えます。投稿ごとに公開範囲をチップで選べるので、全体公開・自分だけ・グループ限定を自由に組み合わせられます。',
        'icon' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7"/><path d="M18 14a6.5 6.5 0 0 1 3.5 6"/>',
    ],
    [
        'title' => '自己ベストを管理',
        'text' => '陸上・筋トレの種目ごとに自己ベスト・シーズンベストを記録。シーズン目標と長期目標を設定すれば、現在値からの進捗が自動で計算され、成長をひと目で確認できます。',
        'icon' => '<circle cx="12" cy="14" r="7"/><path d="M12 14v-3.5"/><path d="M10 3h4"/><path d="M12 3v4"/><path d="M18.5 7.5 20 6"/>',
    ],
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TOBG-RUNNING へようこそ</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;700;800;900&family=Zen+Kaku+Gothic+New:wght@400;500;700;900&display=swap" rel="stylesheet">
    <link href="../css/theme.css?v=<?php echo (int)@filemtime(__DIR__ . '/../css/theme.css'); ?>" rel="stylesheet">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#F4EEE4">
    <link rel="apple-touch-icon" href="../icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" href="../icons/favicon.png">
    <style>
        * {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: var(--color-page-bg);
            color: var(--color-text-primary);
            font-family: var(--font-base);
        }
        /* スマホは画面いっぱい、PC は真ん中のカード */
        .welcome {
            position: relative;
            display: flex;
            flex-direction: column;
            width: 100%;
            max-width: 420px;
            min-height: min(720px, calc(100dvh - 32px));
            padding: 18px 20px 22px;
            border-radius: var(--card-radius);
            background: var(--color-card-bg);
            box-shadow: var(--card-shadow);
        }
        .welcome-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .welcome-logo {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--navy);
            font-family: var(--font-num);
            font-size: 14px;
            font-weight: 900;
            letter-spacing: .06em;
        }
        .welcome-logo img {
            width: 26px;
            height: 26px;
            border-radius: 7px;
        }
        /* スキップ・ログイン画面へ進む: 控えめな文字のリンク */
        .welcome-text-link {
            padding: 6px 4px;
            border: 0;
            background: none;
            color: var(--color-text-secondary);
            font: inherit;
            font-size: 13px;
            text-decoration: none;
            cursor: pointer;
        }
        .welcome-text-link:hover {
            color: var(--color-text-primary);
        }
        /* スライド: 横に並べて、1枚ずつぴたっと止まる（指でも横にずらせる） */
        .welcome-slides {
            display: flex;
            flex: 1;
            margin: 0 -20px;
            overflow-x: auto;
            scroll-snap-type: x mandatory;
            scroll-behavior: smooth;
            scrollbar-width: none;
        }
        .welcome-slides::-webkit-scrollbar {
            display: none;
        }
        .welcome-slide {
            flex: 0 0 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 380px;
            padding: 24px 20px;
            scroll-snap-align: center;
            text-align: center;
        }
        .welcome-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 76px;
            height: 76px;
            margin-bottom: 22px;
            border-radius: 50%;
            background: var(--color-accent-bg);
            color: var(--color-accent);
        }
        .welcome-icon svg {
            width: 36px;
            height: 36px;
        }
        .welcome-title {
            margin: 0 0 12px;
            color: var(--color-text-primary);
            font-size: 17px;
            font-weight: 900;
        }
        .welcome-text {
            max-width: 252px;
            margin: 0;
            color: var(--color-text-secondary);
            font-size: 13px;
            line-height: 1.8;
        }
        /* いまのスライドの位置（選択中は塗りつぶし） */
        .welcome-dots {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin: 6px 0 18px;
        }
        .welcome-dot {
            width: 7px;
            height: 7px;
            padding: 0;
            border: 0;
            border-radius: 50%;
            background: #E8DFCB;
            cursor: pointer;
        }
        .welcome-dot.is-active {
            background: var(--color-accent);
        }
        .welcome-next {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 46px;
            border: 0;
            border-radius: 10px;
            background: var(--color-accent);
            color: #FFFFFF;
            font: inherit;
            font-size: 15px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }
        .welcome-next:hover {
            background: var(--color-accent-hover);
            color: #FFFFFF;
        }
        /* 画面には出さず、読み上げだけに使う見出し */
        .visually-hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0 0 0 0);
            white-space: nowrap;
        }
        .welcome-bottom {
            margin-top: 8px;
            text-align: center;
        }
        @media (max-width: 575.98px) {
            body {
                padding: 0;
            }
            .welcome {
                max-width: none;
                min-height: 100vh;
                min-height: 100dvh;
                border-radius: 0;
                box-shadow: none;
            }
        }
    </style>
</head>
<body>
<main class="welcome" aria-labelledby="welcomeTitle" data-welcome data-next="<?php echo $e($welcome_next); ?>">
    <div class="welcome-top">
        <span class="welcome-logo"><img src="../icons/favicon.png" alt="">TOBG-RUNNING</span>
        <a class="welcome-text-link" href="<?php echo $e($welcome_next); ?>" data-welcome-leave>スキップ</a>
    </div>
    <h1 class="visually-hidden" id="welcomeTitle">TOBG-RUNNING でできること</h1>

    <div class="welcome-slides" data-welcome-slides>
        <?php foreach ($welcome_slides as $index => $slide): ?>
            <section class="welcome-slide" aria-label="<?php echo $index + 1; ?>枚目（全<?php echo count($welcome_slides); ?>枚）: <?php echo $e($slide['title']); ?>">
                <span class="welcome-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><?php echo $slide['icon']; ?></svg>
                </span>
                <h2 class="welcome-title"><?php echo $e($slide['title']); ?></h2>
                <p class="welcome-text"><?php echo $e($slide['text']); ?></p>
            </section>
        <?php endforeach; ?>
    </div>

    <div class="welcome-dots" role="tablist" aria-label="スライドの位置">
        <?php foreach ($welcome_slides as $index => $slide): ?>
            <button type="button" class="welcome-dot<?php echo $index === 0 ? ' is-active' : ''; ?>" role="tab" aria-selected="<?php echo $index === 0 ? 'true' : 'false'; ?>" aria-label="<?php echo $index + 1; ?>枚目へ" data-welcome-dot="<?php echo $index; ?>"></button>
        <?php endforeach; ?>
    </div>

    <?php // 次へ（最後の1枚では「はじめる」になり、ログイン画面へ進む）。JS が無いときはそのままログイン画面へ ?>
    <a class="welcome-next" href="<?php echo $e($welcome_next); ?>" role="button" data-welcome-next>次へ</a>
    <div class="welcome-bottom">
        <a class="welcome-text-link" href="<?php echo $e($welcome_next); ?>" data-welcome-leave>ログイン画面へ進む</a>
    </div>
</main>
<script>
(function () {
    var root = document.querySelector('[data-welcome]');
    var slides = root.querySelector('[data-welcome-slides]');
    var dots = root.querySelectorAll('[data-welcome-dot]');
    var nextBtn = root.querySelector('[data-welcome-next]');
    var count = dots.length;
    var current = 0;

    // どの道で抜けても「見た」と記録する（記録できないブラウザでも、ログイン画面へは進める）
    function markSeen() {
        try {
            localStorage.setItem('onboarding_seen', 'true');
        } catch (error) {
            // 何もしない
        }
    }
    root.querySelectorAll('[data-welcome-leave]').forEach(function (link) {
        link.addEventListener('click', markSeen);
    });

    function show(index) {
        current = Math.max(0, Math.min(count - 1, index));
        dots.forEach(function (dot, i) {
            dot.classList.toggle('is-active', i === current);
            dot.setAttribute('aria-selected', i === current ? 'true' : 'false');
        });
        nextBtn.textContent = current === count - 1 ? 'はじめる' : '次へ';
    }

    function goTo(index) {
        slides.scrollTo({ left: slides.clientWidth * index, behavior: 'smooth' });
        show(index);
    }

    // 指で横にずらしたときも、いまの位置に合わせる
    var scrollTimer = null;
    slides.addEventListener('scroll', function () {
        clearTimeout(scrollTimer);
        scrollTimer = setTimeout(function () {
            show(Math.round(slides.scrollLeft / slides.clientWidth));
        }, 60);
    });
    dots.forEach(function (dot) {
        dot.addEventListener('click', function () {
            goTo(parseInt(dot.getAttribute('data-welcome-dot'), 10));
        });
    });
    nextBtn.addEventListener('click', function (event) {
        if (current < count - 1) {
            event.preventDefault();
            goTo(current + 1);
            return;
        }
        markSeen(); // 「はじめる」はそのままログイン画面へ
    });
})();
</script>
</body>
</html>
