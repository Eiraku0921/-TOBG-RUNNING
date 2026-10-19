<?php
require_once __DIR__ . '/back/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/back/db_connect.php';
require_once __DIR__ . '/back/training_diary_lib.php';
$is_logged_in = isset($_SESSION['user_id'], $_SESSION['user_name']);
$login_name = $is_logged_in ? $_SESSION['user_name'] : '';
$current_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// ログイン中は、タイトル部分の代わりに自分の NEXT PR（自己ベスト・目標）を出す
$home_next_pr = null;
if ($is_logged_in) {
    try {
        require_once __DIR__ . '/back/next_pr_lib.php';
        $home_next_pr = fetch_next_pr_summary($pdo, $current_user_id, (int)($_GET['pr_event'] ?? 0), isset($_GET['pr_view']) ? (string)$_GET['pr_view'] : null);
    } catch (PDOException $e) {
        error_log('home next_pr failed: ' . $e->getMessage());
        $home_next_pr = null; // 読めなければ通常のタイトルを出す
    }
}

// ログイン中のホーム: 練習のまとめ（YOUR WEEK）。読めなければその欄を出さない
$home_week_summary = null;
if ($is_logged_in) {
    require_once __DIR__ . '/back/training_summary_lib.php';
    try {
        $home_week_summary = fetch_training_week_summary($pdo, $current_user_id);
    } catch (PDOException $e) {
        error_log('home week summary failed: ' . $e->getMessage());
    }
}

// 受付中の大会（ENTRY）。未ログインのホームにも出す
$home_open_tournaments = [];
require_once __DIR__ . '/back/tournament_list_lib.php';
try {
    $home_open_tournaments = fetch_open_tournaments($pdo, 3);
} catch (PDOException $e) {
    error_log('home tournaments failed: ' . $e->getMessage());
}

// 次の合同練習（見られるもののうち一番近い1件。NEXT SESSION）。未ログインは「すべての人に公開」の募集だけ
$home_next_sessions = [];
$home_session_viewer = null;
try {
    require_once __DIR__ . '/back/club_session_lib.php';
    $home_session_viewer = club_session_viewer($pdo, $is_logged_in ? $current_user_id : 0);
    $home_next_sessions = fetch_club_sessions($pdo, $home_session_viewer, !$is_logged_in, 1);
} catch (PDOException $e) {
    error_log('home next session failed: ' . $e->getMessage());
}

// ---- グループ公開 ----
// 招待リンク（index.php?join_group=コード）から来たとき、ログインしていなければコードを覚えてログイン画面へ。
// ログイン後は login.php が自動で参加させる。ログイン中なら、マイグループの「参加する」画面をコード入力済みで開く
require_once __DIR__ . '/back/member_group_lib.php';
$invite_join_code = preg_replace('/\D/', '', (string)($_GET['join_group'] ?? ''));
if ($invite_join_code !== '' && !$is_logged_in) {
    $_SESSION['pending_group_join_code'] = substr($invite_join_code, 0, 6);
    header('Location: mypage/login.php');
    exit;
}
// グループはみんなのページで扱う。前に送った招待リンク（index.php?join_group=）や、ログイン後に開くマイグループ（?open_group=）は、そちらへ送る
if ($invite_join_code !== '') {
    header('Location: front/training_diary.php?join_group=' . substr($invite_join_code, 0, 6));
    exit;
}
$legacy_open_group = (string)($_GET['open_group'] ?? '');
if (in_array($legacy_open_group, ['list', 'join', 'create'], true)) {
    header('Location: front/training_diary.php?open_group=' . $legacy_open_group);
    exit;
}

// ホーム上部のお知らせ（以前のグループ参加の知らせなど）
$home_flash = (string)($_SESSION['home_flash'] ?? '');
$home_error = (string)($_SESSION['home_error'] ?? '');
unset($_SESSION['home_flash'], $_SESSION['home_error']);

// 公開されている練習日記。サイト案内の統計（NP欄が出ないとき＝ログイン前など）と、PC の「みんなの練習日記」（新しい3件）に使う
$diary_posts = [];
try {
    $diary_posts = fetch_public_training_diary_feed($pdo, $current_user_id, (string)($_SESSION['user_role'] ?? ''));
} catch (PDOException $e) {
    $diary_posts = [];
}
// PC の「みんなの練習日記」に渡すのは、投稿が新しい順の3件だけ（ページに全部を埋め込まない）
$home_pulse_posts = $diary_posts;
usort($home_pulse_posts, static fn(array $a, array $b): int => [(string)$b['created_at'], (int)$b['id']] <=> [(string)$a['created_at'], (int)$a['id']]);
$home_pulse_posts = array_slice($home_pulse_posts, 0, 3);

// ホームの統計。どれもカレンダーと同じ「公開されている練習日記」を、練習日で数える
$this_month_prefix = date('Y-m-');
$month_diary_count = 0;
$month_active_user_ids = [];
foreach ($diary_posts as $post) {
    if (strpos((string)($post['practice_date'] ?? ''), $this_month_prefix) === 0) {
        $month_diary_count++;
        $month_active_user_ids[(int)$post['user_id']] = true;
    }
}
$month_active_member_count = count($month_active_user_ids);

// 開催日を過ぎた大会は自動で削除されるため、数えられるのは「今月の今日以降」の大会だけ
$month_upcoming_tournament_count = 0;
try {
    $month_upcoming_tournament_count = (int)$pdo->query(
        'SELECT COUNT(*) FROM tournaments WHERE event_date BETWEEN CURDATE() AND LAST_DAY(CURDATE())'
    )->fetchColumn();
} catch (PDOException $e) {
    $month_upcoming_tournament_count = 0;
}

$home_announcements = [];
try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS announcements (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(150) NOT NULL,
            body TEXT NOT NULL,
            display_order INT NOT NULL DEFAULT 1,
            is_published TINYINT(1) NOT NULL DEFAULT 1,
            publish_start DATETIME NULL,
            publish_end DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_announcements_order (display_order),
            INDEX idx_announcements_published (is_published),
            INDEX idx_announcements_period (publish_start, publish_end)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    require_once __DIR__ . '/back/announcement_lib.php';
    $home_announcements = fetch_published_announcements($pdo);
} catch (PDOException $e) {
    $home_announcements = [];
}
// お知らせの日付は「今日・昨日・3日前」で出す（30日以上前は 7/16 のように日付）
$home_news_ago = static function (string $datetime): string {
    $days = (int)floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', strtotime($datetime)))) / 86400);
    if ($days <= 0) {
        return '今日';
    }
    if ($days === 1) {
        return '昨日';
    }
    return $days < 30 ? $days . '日前' : date('n/j', strtotime($datetime));
};
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TOBG-RUNNING</title>
    <meta property="og:title" content="TOBG-RUNNING">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;700;800;900&family=M+PLUS+Rounded+1c:wght@400;500;700;800&family=Outfit:wght@500;600;700&family=Zen+Kaku+Gothic+New:wght@400;500;700;900&display=swap" rel="stylesheet">
    <link href="css/theme.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/theme.css'); ?>" rel="stylesheet">
    <link href="css/next_pr_cards.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/next_pr_cards.css'); ?>" rel="stylesheet">
    <link href="css/week_summary.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/week_summary.css'); ?>" rel="stylesheet">
    <link href="css/club.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/club.css'); ?>" rel="stylesheet">
    <link href="css/diary_timeline.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/diary_timeline.css'); ?>" rel="stylesheet">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#F4EEE4">
    <meta name="apple-mobile-web-app-title" content="TOBG-RUNNING">
    <link rel="apple-touch-icon" href="icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" href="icons/favicon.png">
    <style>
        body {
            background-color: var(--color-page-bg);
            color: var(--color-text-primary);
        }
        .home-wrap { max-width: var(--content-max-width); margin: 0 auto; padding: 10px var(--page-gutter) 24px; }
        /* あいさつ（仕様書: 英字の小見出し 11px Archivo・字間0.12em・--orange-deep、h1 26px 900） */
        /* PC の「みんなの練習日記」（新しい3件）は、左メニューが出る幅（992px 以上）だけ */
        .home-pulse {
            display: none;
        }
        @media (min-width: 992px) {
            .home-pulse {
                display: block;
            }
            .home-pulse .tl-item {
                margin-bottom: 10px;
            }
        }
        /* 練習のまとめ・合同練習・お知らせは、右の補助欄が出る幅（1200px 以上）では補助欄だけに出す */
        @media (min-width: 1200px) {
            body:has(.site-rail) .home-rail-dup {
                display: none;
            }
        }
        .home-greeting {
            margin: 6px 0 18px;
        }
        .home-eyebrow {
            margin: 0 0 4px;
            color: var(--orange-deep);
            font-family: var(--font-num);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.12em;
        }
        .home-greeting-title {
            margin: 0;
            color: var(--navy);
            font-size: 26px;
            font-weight: 900;
            line-height: 1.3;
        }
        /* 大会（仕様書の ENTRY カード）: 薄いオレンジの地・角丸20px。左に「ENTRY / 締切」と大会名、右に開催までの日数 */
        .home-entries {
            margin-bottom: 18px;
        }
        .home-entry {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 18px;
            border-radius: var(--card-radius);
            background: var(--orange-soft);
            color: var(--navy);
            text-decoration: none;
        }
        .home-entry + .home-entry {
            margin-top: 10px;
        }
        .home-entry:hover {
            color: var(--navy);
            filter: brightness(0.98);
        }
        .home-entry-main {
            flex: 1;
            min-width: 0;
        }
        .home-entry-eyebrow {
            display: block;
            margin-bottom: 6px;
            color: var(--orange-deep);
            font-family: var(--font-num);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.12em;
        }
        .home-entry-name {
            display: block;
            font-size: 17px;
            font-weight: 900;
            line-height: 1.4;
        }
        /* 締切が3日以内（赤）・今日・未入力のときだけ出す小さな札 */
        .home-entry-deadline {
            display: inline-block;
            margin-top: 6px;
            padding: 2px 10px;
            border-radius: 999px;
            background: var(--surface);
            color: var(--orange-deep);
            font-size: 11px;
            font-weight: 700;
        }
        .home-entry-deadline.is-soon {
            background: var(--coral);
            color: #FFFFFF;
        }
        .home-entry-count {
            flex-shrink: 0;
            text-align: right;
        }
        .home-entry-days {
            display: block;
            color: var(--orange-deep);
            font-family: var(--font-num);
            font-size: 34px;
            font-weight: 800;
            font-variant-numeric: tabular-nums;
            line-height: 1;
        }
        .home-entry-days-label {
            display: block;
            margin-top: 4px;
            color: var(--sub);
            font-family: var(--font-num);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.1em;
        }
        .home-entry-empty {
            margin: 0 0 18px;
            padding: 14px 18px;
            border-radius: var(--card-radius);
            background: var(--orange-soft);
            color: var(--sub);
            font-size: 13px;
        }
        .home-entry-empty a {
            color: var(--orange-deep);
            font-weight: 700;
            text-decoration: none;
        }
        /* ---- 情報のまとまりごとの白いカード（間にページ背景の余白を挟む） ---- */
        .home-card {
            background: var(--color-card-bg);
            border-radius: var(--card-radius);
            padding: 24px;
            margin-bottom: 10px;
        }
        /* ---- NEXT PR（ログイン中）: 種目ごとの写真を背景にしたカード。写真の上は白い文字と半透明の枠 ---- */
        .home-np-eyebrow {
            margin: 0 0 8px;
            color: var(--orange-deep);
            font-family: var(--font-num);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .12em;
        }
        .home-next-pr .event-tabs {
            margin-bottom: 8px;
        }
        .np-hero {
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 10px;
            min-height: 216px; /* スマホでも写真が見えるよう、ほんの少し高さを取る（PC は下で広げる） */
            margin-bottom: 14px;
            padding: 16px 14px;
            overflow: hidden;
            border-radius: var(--card-radius);
            /* 文字以外は写真をそのまま見せる。下のほうにだけ薄い影をかけて、目標の文字を読みやすくする */
            background:
                linear-gradient(180deg, rgba(31, 42, 68, 0) 45%, rgba(31, 42, 68, .45) 100%),
                var(--np-photo) center / cover no-repeat, /* 会員の写真は枠に合わせて切り抜いてあるので、真ん中を基準に */
                var(--navy);
            box-shadow: 0 10px 26px rgba(31, 42, 68, .18);
            color: #FFFFFF;
        }
        /* 見本の写真（images/np_default.webp。集合写真）: 横長の PC でも顔が記録の数字と目標の枠の間に来るよう、少し下寄りを見せる */
        .np-hero.is-sample {
            background-position: 0 0, center 72%, 0 0; /* スマホ: カードの形が PC と違うので、顔が目標の枠にかからない位置 */
        }
        @media (min-width: 768px) {
            .np-hero.is-sample {
                background-position: 0 0, center 60%, 0 0;
            }
        }
        /* 自己ベスト / シーズンベスト: 半透明の丸。選択中は白地に紺の文字 */
        .np-hero-tabs .best-tab {
            padding: 4px 12px;
            border-color: rgba(255, 255, 255, .8);
            background: transparent;
            color: #FFFFFF;
            font-size: 12px;
            text-shadow: 0 1px 4px rgba(0, 0, 0, .55);
        }
        .np-hero-tabs .best-tab:hover {
            color: #FFFFFF;
        }
        .np-hero-tabs .best-tab.is-active {
            border-color: #FFFFFF;
            background: #FFFFFF;
            color: var(--navy);
        }
        /* ベスト記録: 大きな数字と、右に日付と大会名 */
        .np-hero-best {
            display: flex;
            align-items: baseline;
            flex-wrap: wrap;
            gap: 4px 12px;
            min-height: 56px;
            text-shadow: 0 1px 3px rgba(0, 0, 0, .55), 0 2px 14px rgba(0, 0, 0, .45);
        }
        .np-hero-value {
            font-family: var(--font-np-num);
            font-size: 48px;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
            letter-spacing: -.03em;
            line-height: 1;
        }
        .np-hero-value small {
            margin-left: 3px;
            font-family: var(--font-np);
            font-size: 16px;
            font-weight: 700;
            letter-spacing: 0;
        }
        .np-hero-meta {
            font-size: 12px;
            font-weight: 700;
        }
        .np-hero-note {
            display: block;
            font-size: 11px;
            opacity: .85;
        }
        .np-hero-none {
            align-self: center;
            font-size: 14px;
            font-weight: 700;
        }
        /* 目標: 半透明の枠2つ。目標の値は明るいオレンジ、バーは白の薄い地にオレンジ */
        .np-hero-goals {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
            margin-top: auto; /* 目標の枠はカードの下に寄せる */
        }
        .np-hero-goal {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 0;
            padding: 10px 12px;
            border: 1px solid rgba(255, 255, 255, .7);
            border-radius: 14px;
            background: transparent;
            text-shadow: 0 1px 3px rgba(0, 0, 0, .6), 0 2px 10px rgba(0, 0, 0, .4);
        }
        .np-hero-goal-label {
            overflow: hidden;
            font-size: 11px;
            font-weight: 700;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .np-hero-goal-value {
            color: var(--chart);
            font-family: var(--font-np-num);
            font-size: 20px;
            font-weight: 600;
            line-height: 1.1;
        }
        .np-hero-goal-empty {
            font-size: 13px;
            font-weight: 700;
            opacity: .85;
        }
        .np-hero-bar {
            display: block;
            height: 6px;
            overflow: hidden;
            border-radius: 999px;
            background: rgba(255, 255, 255, .35);
            box-shadow: 0 1px 4px rgba(0, 0, 0, .3);
        }
        .np-hero-bar span {
            display: block;
            height: 100%;
            border-radius: 999px;
            background: var(--orange);
        }
        /* 種目がまだ無い人: 見本の写真に案内と、NEXT PR のページへのボタン */
        .np-hero.is-empty {
            align-items: flex-start;
            padding: 20px;
        }
        .np-hero-empty-title {
            margin: 40px 0 0;
            font-size: 20px;
            font-weight: 900;
        }
        .np-hero-empty-text {
            margin: 0;
            font-size: 13px;
            font-weight: 700;
        }
        .np-hero-empty-btn {
            margin-top: 6px;
            padding: 8px 16px;
            border-radius: 999px;
            background: var(--orange);
            color: #FFFFFF;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
        }
        .np-hero-empty-btn:hover {
            background: var(--orange-deep);
            color: #FFFFFF;
        }
        @media (min-width: 768px) {
            .np-hero {
                padding: 18px 20px;
            }
            .np-hero-value {
                font-size: 60px;
            }
            .np-hero-goal-value {
                font-size: 24px;
            }
        }
        /* PC（左メニューが出る幅）: カードを高くして写真を広く見せる。目標の枠はカードの下に寄せる */
        @media (min-width: 992px) {
            .np-hero {
                min-height: 290px;
                padding: 20px 24px;
            }
            .np-hero-goals {
                gap: 12px;
                margin-top: auto;
            }
            .np-hero-goal {
                padding: 12px 16px;
            }
            .np-hero-value {
                font-size: 60px;
            }
            .np-hero-meta {
                font-size: 14px;
            }
            .np-hero-goal-label {
                font-size: 12px;
            }
            .np-hero-goal-value {
                font-size: 24px;
            }
            .np-hero-tabs .best-tab {
                padding: 5px 14px;
                font-size: 13px;
            }
        }
        /* ---- 未ログインの紹介カード: 見本の写真を背景に、白い文字で ---- */
        .home-card.home-hero-photo {
            background:
                linear-gradient(180deg, rgba(31, 42, 68, .35) 0%, rgba(31, 42, 68, .8) 100%),
                url('images/np_default.webp?v=<?php echo (int)@filemtime(__DIR__ . '/images/np_default.webp'); ?>') center 55% / cover no-repeat,
                var(--navy);
            color: #FFFFFF;
        }
        .home-hero-photo .hero-title,
        .home-hero-photo .hero-copy,
        .home-hero-photo .home-stat-value,
        .home-hero-photo .home-stat-label,
        .home-hero-photo .home-stat-note {
            color: #FFFFFF;
            text-shadow: 0 1px 8px rgba(0, 0, 0, .35);
        }
        /* ---- タイトル紹介文＋統計 ---- */
        .hero-title {
            font-size: 1.5rem;
            line-height: 1.4;
            font-weight: 800;
            color: var(--color-text-primary);
            margin-bottom: 12px;
        }
        .hero-copy {
            color: var(--color-text-secondary);
            font-size: 0.88rem;
            line-height: 1.8;
            margin-bottom: 20px;
        }
        .home-stats {
            display: flex;
            flex-wrap: wrap;
            gap: 12px 40px;
        }
        .home-stat-value {
            color: var(--color-accent);
            font-size: 1.6rem;
            font-weight: 800;
            line-height: 1.1;
        }
        .home-stat-label {
            color: var(--color-text-secondary);
            font-size: 0.78rem;
            font-weight: 600;
            margin-top: 4px;
        }
        .home-stat-note {
            color: var(--color-text-muted);
            font-size: 0.7rem;
        }
        /* ---- セクション見出し（帯は使わず、左にアクセントの丸だけ） ---- */
        .section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .section-title {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin: 0;
            font-size: 1.05rem;
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
        .section-note {
            color: var(--color-text-secondary);
            font-size: 0.8rem;
            line-height: 1.7;
            margin: 8px 0 16px;
        }
        /* 練習日記の投稿ボタン（白地にオレンジの枠線と文字） */
        .post-diary-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid var(--color-accent);
            border-radius: var(--button-radius);
            background: var(--color-card-bg);
            color: var(--color-accent);
            font-weight: 700;
        }
        .post-diary-btn:hover,
        .post-diary-btn:focus-visible {
            border-color: var(--color-accent);
            background: var(--color-accent-bg);
            color: var(--color-accent);
        }
        /* PC だけ・スマホだけに出す文字 */
        .sp-only {
            display: none;
        }
        @media (max-width: 768px) {
            .pc-only {
                display: none;
            }
            .sp-only {
                display: inline;
            }
        }
        /* スマホ: 統計3つを横1列に並べてタイトル欄を低くし、カレンダーの説明文は省く */
        @media (max-width: 768px) {
            .hero-copy {
                margin-bottom: 14px;
            }
            .home-stats {
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 8px;
            }
            .home-stat-value {
                font-size: 1.35rem;
            }
            .home-stat-label {
                font-size: 0.7rem;
                margin-top: 2px;
            }
            .home-stat-note {
                font-size: 0.62rem;
                line-height: 1.3;
            }
            .section-head {
                margin-bottom: 14px;
            }
        }
        @media (max-width: 576px) {
            .home-card {
                padding: 18px 14px;
            }
            .hero-title {
                font-size: 1.25rem;
            }
        }
        /* ---- お知らせ（NOTICE）: 1枚のカードに、タイトル・何日前・右にボタン ---- */
        .home-news {
            margin: 12px 0 16px; /* 上の合同練習のカードとの間を、ほかの欄の間とそろえる */
        }
        .news-card {
            margin: 0;
            padding: 4px 16px;
            border: 1px solid var(--line);
            border-radius: 20px;
            background: var(--surface);
        }
        .news-list {
            list-style: none;
        }
        .news-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 0;
        }
        .news-item + .news-item {
            border-top: 1px solid var(--line);
        }
        .news-main {
            flex: 1;
            min-width: 0;
        }
        .news-main > summary {
            display: block;
            cursor: pointer;
            list-style: none;
        }
        .news-main > summary::-webkit-details-marker {
            display: none;
        }
        .news-title {
            display: block;
            color: var(--navy);
            font-size: 15px;
            font-weight: 700;
            line-height: 1.5;
            overflow-wrap: anywhere;
        }
        .news-main > summary:hover .news-title {
            color: var(--orange-deep);
        }
        /* 重要なお知らせの札（タイトルの前。赤い枠と赤い文字） */
        .news-important {
            display: inline-block;
            margin-right: 6px;
            padding: 0 7px;
            border: 1px solid var(--coral);
            border-radius: 4px;
            color: var(--coral);
            font-size: 11px;
            font-weight: 700;
            line-height: 1.6;
            vertical-align: 2px;
        }
        .news-ago {
            display: block;
            margin-top: 2px;
            color: var(--sub);
            font-size: 12px;
            font-weight: 700;
        }
        .news-body {
            margin: 8px 0 0;
            color: var(--sub);
            font-size: 13px;
            line-height: 1.7;
            overflow-wrap: anywhere;
        }
        /* 右のボタン（「追加方法を見る」など）: 薄いオレンジの丸 */
        .news-link {
            flex-shrink: 0;
            align-self: flex-start;
            padding: 7px 14px;
            border-radius: 999px;
            background: var(--orange-soft);
            color: var(--orange-deep);
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
        }
        .news-link:hover {
            color: var(--orange-deep);
            filter: brightness(.97);
        }
        .news-empty {
            padding: 16px;
            color: var(--sub);
            font-size: 13px;
        }
        .home-flash {
            margin: 10px 0 0;
        }
        /* ---- Instagram ---- */
        .insta-card {
            text-align: center;
        }
        .insta-card .section-note {
            margin-bottom: 14px;
        }
        .home-footer {
            color: var(--color-text-muted);
            font-size: 0.78rem;
            text-align: center;
            padding: 8px 16px 24px;
        }
        .home-footer-links {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 8px 18px;
            margin-bottom: 10px;
        }
        .home-footer-links a {
            color: var(--sub);
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
        }
        .home-footer-links a:hover {
            color: var(--navy);
        }
        /* NEXT SESSION などの見出し（英字の小見出し＋右に丸いリンク） */
        .home-section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin: 4px 0 10px;
        }
        .home-section-eyebrow {
            margin: 0;
            color: var(--orange-deep);
            font-family: var(--font-num);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.12em;
        }
        .home-section-eyebrow span {
            font-family: var(--font-base);
            letter-spacing: 0;
        }
        .home-pill-link {
            padding: 6px 14px;
            border-radius: 999px;
            background: var(--orange-soft);
            color: var(--orange-deep);
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <?php
    $shared_header = __DIR__ . '/includes/site_header.php';
    if (file_exists($shared_header)) {
        require_once $shared_header;
    } else {
        echo '<div style="background:var(--color-header);color:#fff;padding:12px 16px;font-weight:700;">TEAM OFFICIAL</div>';
    }
    ?>

    <div class="home-wrap">
        <?php // ログイン中のホームは仕様書の順: あいさつ → NP欄 → 練習のまとめ → 大会のお知らせ（合同練習は機能ができたら入れる） ?>
        <?php if ($is_logged_in): ?>
            <header class="home-greeting">
                <p class="home-eyebrow"><?php echo htmlspecialchars(strtoupper(date('D, M d')) . ' / ' . date('Y'), ENT_QUOTES, 'UTF-8'); ?></p>
                <?php
                // あいさつは時間で変える（日本時間。朝4時〜10時台は「おはよう」、11時〜17時台は「こんにちは」、それ以外は「こんばんは」）
                $home_hour = (int)date('G');
                $home_greeting = $home_hour >= 4 && $home_hour < 11 ? 'おはよう' : ($home_hour >= 11 && $home_hour < 18 ? 'こんにちは' : 'こんばんは');
                ?>
                <h1 class="home-greeting-title"><?php echo $home_greeting; ?>、<?php echo htmlspecialchars((string)$login_name, ENT_QUOTES, 'UTF-8'); ?>さん。</h1>
            </header>
        <?php endif; ?>
        <?php if ($home_next_pr !== null): ?>
            <?php require __DIR__ . '/includes/home_next_pr.php'; ?>
            <?php // PC だけ: みんなの練習日記の新しい3件（押すとみんなのページへ）。中身は js/diary_timeline.js の renderPreview ?>
            <section class="home-pulse" aria-labelledby="homePulseTitle">
                <div class="home-section-head">
                    <h2 class="home-section-eyebrow" id="homePulseTitle">CLUB PULSE <span>/ みんなの練習日記</span></h2>
                    <a class="home-pill-link" href="front/training_diary.php">もっと見る →</a>
                </div>
                <div class="tl-list" id="homePulseList"></div>
            </section>
            <?php if ($home_week_summary !== null): ?>
                <div class="home-rail-dup">
                <?php
                $week_summary = $home_week_summary;
                $week_summary_base = '';
                $week_summary_event_key = $home_next_pr['event']['event_key'] ?? null;
                require __DIR__ . '/includes/week_summary_card.php';
                ?>
                </div>
            <?php endif; ?>
            <?php require __DIR__ . '/includes/home_entries.php'; ?>
            <?php require __DIR__ . '/includes/home_next_session.php'; ?>
        <?php else: ?>
        <?php // 未ログインの紹介カード: 見本の写真（images/np_default.webp）を背景にする ?>
        <section class="home-card home-hero-photo" aria-labelledby="homeTitle">
            <?php // スマホは幅が狭いので短いタイトルにする ?>
            <h1 class="hero-title" id="homeTitle"><span class="pc-only">藤蔭OB発「TOBG-RUNNING」のコミュニティサイト</span><span class="sp-only">ランニングコミュニティサイト</span></h1>
            <p class="hero-copy">
                新規ログインのみで誰でも練習ログを更新できます。<span class="pc-only"><br>
                下記のカレンダーからみんなの練習日記をチェックしてみてください。</span>
            </p>
            <div class="home-stats">
                <div class="home-stat">
                    <div class="home-stat-value"><?php echo (int)$month_diary_count; ?></div>
                    <div class="home-stat-label">今月の練習日記数</div>
                </div>
                <div class="home-stat">
                    <div class="home-stat-value"><?php echo (int)$month_active_member_count; ?></div>
                    <div class="home-stat-label">アクティブ会員数</div>
                    <div class="home-stat-note">今月1件以上投稿した人</div>
                </div>
                <div class="home-stat">
                    <div class="home-stat-value"><?php echo (int)$month_upcoming_tournament_count; ?></div>
                    <div class="home-stat-label">今月の開催大会数</div>
                    <div class="home-stat-note">今日以降に開催する大会</div>
                </div>
            </div>
        </section>
        <?php // 紹介カードのあとは 大会 → 合同練習 → お知らせ → Instagram の順 ?>
        <?php require __DIR__ . '/includes/home_entries.php'; ?>
        <?php require __DIR__ . '/includes/home_next_session.php'; ?>
        <?php endif; ?>

        <?php // みんなの練習日記（タイムライン・カレンダー・グループ）は、みんなのページ（front/training_diary.php）にある ?>

        <?php // お知らせ（NOTICE）。ログイン中は右の補助欄にも出るので、補助欄が出る幅では本文から隠す ?>
        <?php // 1枚のカードに「タイトル・何日前・右にボタン」を並べる。本文はタイトルを押すと開く ?>
        <section class="home-news<?php echo $is_logged_in ? ' home-rail-dup' : ''; ?>" aria-labelledby="newsTitle">
            <div class="home-section-head">
                <h2 class="home-section-eyebrow" id="newsTitle">NOTICE <span>/ お知らせ</span></h2>
            </div>
            <?php if (empty($home_announcements)): ?>
                <p class="news-card news-empty">現在表示中のお知らせはありません。</p>
            <?php else: ?>
                <ul class="news-card news-list">
                    <?php foreach ($home_announcements as $notice): ?>
                        <?php $notice_body = trim((string)$notice['body']); ?>
                        <?php $notice_link = announcement_link($notice['link_url'] ?? null, $notice['link_label'] ?? null); ?>
                        <li class="news-item">
                            <?php if ($notice_body !== ''): ?>
                                <details class="news-main">
                                    <summary>
                                        <span class="news-title"><?php if ((int)($notice['is_important'] ?? 0) === 1): ?><span class="news-important">重要</span><?php endif; ?><?php echo htmlspecialchars((string)$notice['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span class="news-ago"><?php echo htmlspecialchars($home_news_ago((string)$notice['created_at']), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </summary>
                                    <p class="news-body"><?php echo nl2br(htmlspecialchars($notice_body, ENT_QUOTES, 'UTF-8')); ?></p>
                                </details>
                            <?php else: ?>
                                <div class="news-main">
                                    <span class="news-title"><?php if ((int)($notice['is_important'] ?? 0) === 1): ?><span class="news-important">重要</span><?php endif; ?><?php echo htmlspecialchars((string)$notice['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="news-ago"><?php echo htmlspecialchars($home_news_ago((string)$notice['created_at']), ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ($notice_link !== null): ?>
                                <a class="news-link" href="<?php echo htmlspecialchars($notice_link['href'], ENT_QUOTES, 'UTF-8'); ?>"<?php echo $notice_link['external'] ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>><?php echo htmlspecialchars($notice_link['label'], ENT_QUOTES, 'UTF-8'); ?></a>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="home-card insta-card" aria-labelledby="instaTitle">
            <h2 class="section-title" id="instaTitle">Instagramでも発信中</h2>
            <p class="section-note">ぜひフォローして、雰囲気をチェックしてみてください。</p>
            <a class="btn btn-primary btn-sm" href="https://www.instagram.com/teamtobgrunning?igsh=a3U3bXE5Z2RyNTlh&utm_source=qr" target="_blank" rel="noopener noreferrer">Instagramをフォローする</a>
        </section>
    </div>

    <footer class="home-footer">
        <?php // ヘッダーのハンバーガーメニューを無くしたので、下タブに無いページへの入口をここに置く（ログイン前の人も使える） ?>
        <nav class="home-footer-links" aria-label="サイトのページ">
            <a href="front/team_details.php">チーム詳細</a>
            <a href="front/records.php">歴代記録</a>
            <a href="front/gallery.php">ギャラリー</a>
            <a href="front/contact.php">お問い合わせ</a>
        </nav>
        &copy; 2026 Official Track & Field Team. All Rights Reserved.
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/week_summary.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/week_summary.js'); ?>" defer></script>
    <script src="js/diary_timeline.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/diary_timeline.js'); ?>"></script>
    <script>
    // PC の「みんなの練習日記」: 新しい3件を見るだけのカードで出す
    (function () {
        var listEl = document.getElementById('homePulseList');
        if (!listEl) {
            return;
        }
        // タイムラインの JS が読み込めなかった（古いまま・通信の失敗）ときは、見出しだけが空で残らないよう欄ごと隠す
        if (!window.DiaryTimeline || typeof window.DiaryTimeline.renderPreview !== 'function') {
            listEl.closest('.home-pulse').hidden = true;
            return;
        }
        DiaryTimeline.renderPreview({
            listEl: listEl,
            posts: <?php echo json_encode($home_pulse_posts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            basePath: '',
            linkUrl: 'front/training_diary.php',
            escapeHtml: function (text) {
                var div = document.createElement('div');
                div.textContent = text === null || text === undefined ? '' : String(text);
                return div.innerHTML;
            },
            count: 3
        });
    })();
    </script>
</body>
</html>
