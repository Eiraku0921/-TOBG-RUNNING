<?php
require_once __DIR__ . '/../back/session_bootstrap.php';
// セッションは開始するが、ログインを必須にしない（閲覧は誰でも可能）
tobg_start_session();
require_once __DIR__ . '/../back/db_connect.php';
require_once __DIR__ . '/../back/training_diary_lib.php';
require_once __DIR__ . '/../back/member_avatar_lib.php';

require_once __DIR__ . '/../back/member_group_lib.php';
require_once __DIR__ . '/../back/pickup_member_lib.php';

$posts = [];
$is_logged_in = isset($_SESSION['user_id'], $_SESSION['user_name']);
$post_target_url = $is_logged_in ? '../mypage/front_login.php' : '../mypage/login.php';
$current_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// 招待リンク（?join_group=コード）から来たとき。ログインしていなければコードを覚えてログイン画面へ
// （ログイン後は login.php が自動で参加させる）。ログイン中なら、マイグループの「参加する」をコード入力済みで開く
$group_join_code = substr((string)preg_replace('/\D/', '', (string)($_GET['join_group'] ?? '')), 0, 6);
if ($group_join_code !== '' && !$is_logged_in) {
    $_SESSION['pending_group_join_code'] = $group_join_code;
    header('Location: ../mypage/login.php');
    exit;
}

// 自分のグループと、上のチップで選んだグループ（0 = すべて。メンバーでないグループは選べない）
$my_groups = [];
$selected_group = null;
if ($is_logged_in) {
    try {
        ensure_member_group_tables($pdo);
        $my_groups = fetch_member_groups_for_user($pdo, $current_user_id);
        $requested_group_id = (int)($_GET['group'] ?? 0);
        foreach ($my_groups as $my_group) {
            if ($my_group['id'] === $requested_group_id) {
                $selected_group = $my_group;
            }
        }
    } catch (PDOException $e) {
        error_log('everyone page groups failed: ' . $e->getMessage());
        $my_groups = [];
    }
}

try {
    $posts = fetch_public_training_diary_feed($pdo, $current_user_id, (string)($_SESSION['user_role'] ?? ''), $selected_group['id'] ?? 0);
} catch (PDOException $e) {
    $posts = [];
}
// 管理者が選んだピックアップの会員の投稿に印を付ける（「ピックアップ」タブで使う）
try {
    $pickup_ids = fetch_pickup_member_ids($pdo);
} catch (PDOException $e) {
    $pickup_ids = [];
}
foreach ($posts as &$post) {
    $post['is_pickup'] = in_array((int)$post['user_id'], $pickup_ids, true);
}
unset($post);
// 日記 / ランキング（選んでいるグループはそのまま）。
// 「みんなの目標」は画面から外した（仕組みは back/group_goal_lib.php・includes/group_goal.php に残してある）。古いリンクの ?view=goals は日記を出す
$everyone_view = in_array((string)($_GET['view'] ?? ''), ['diary', 'ranking'], true) ? (string)$_GET['view'] : 'diary';
$everyone_url = static function (array $params) use ($selected_group): string {
    $base = $selected_group !== null ? ['group' => $selected_group['id']] : [];
    return 'training_diary.php?' . http_build_query($base + $params);
};
$ranking_rows = [];
$ranking_period = null;
if ($everyone_view === 'ranking') {
    require_once __DIR__ . '/../back/training_summary_lib.php';
    $ranking_period = distance_ranking_period((string)($_GET['rank_period'] ?? 'week'), (int)($_GET['rank_offset'] ?? 0));
    try {
        $ranking_rows = fetch_distance_ranking($pdo, $ranking_period['from'], $ranking_period['to'], $selected_group !== null ? (int)$selected_group['id'] : null);
    } catch (PDOException $e) {
        error_log('everyone page: ranking failed: ' . $e->getMessage());
    }
}

// ページ右上の「今日の投稿 ◯件」: 表示している範囲で、今日投稿された日記（「今日の投稿」タブと同じ数え方）
$today_post_count = count(array_filter($posts, static fn(array $post): bool => strncmp((string)($post['created_at'] ?? ''), date('Y-m-d'), 10) === 0));

$diary_posts_json = json_encode($posts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>みんなの練習日記 | TOBG</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;700;800;900&family=Zen+Kaku+Gothic+New:wght@400;500;700;900&display=swap" rel="stylesheet">
    <link href="../css/theme.css?v=<?php echo (int)@filemtime(__DIR__ . '/../css/theme.css'); ?>" rel="stylesheet">
    <link href="../css/diary_timeline.css?v=<?php echo (int)@filemtime(__DIR__ . '/../css/diary_timeline.css'); ?>" rel="stylesheet">
    <link href="../css/ranking.css?v=<?php echo (int)@filemtime(__DIR__ . '/../css/ranking.css'); ?>" rel="stylesheet">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#F4EEE4">
    <meta name="apple-mobile-web-app-title" content="TOBG-RUNNING">
    <link rel="apple-touch-icon" href="../icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" href="../icons/favicon.png">
    <style>
        body { background: var(--color-page-bg); }
        .timeline-wrap { max-width: var(--content-max-width); margin: 0 auto; padding: 24px var(--page-gutter) 40px; }
        .timeline-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
        }
        .timeline-head-main {
            min-width: 0;
        }
        .timeline-title { font-size: 1.7rem; font-weight: 800; color: var(--color-header); margin-bottom: 8px; }
        .timeline-note { color: #64748b; margin-bottom: 0; }
        .diary-post-btn {
            white-space: nowrap;
            font-weight: 700;
            border-radius: 999px;
            padding: 8px 14px;
            font-size: 0.9rem;
            background: var(--color-accent);
            border-color: var(--color-accent);
            color: var(--color-accent-text);
        }
        .diary-post-btn:hover,
        .diary-post-btn:focus,
        .diary-post-btn:active {
            background: var(--color-accent-hover);
            border-color: var(--color-accent-hover);
            color: var(--color-accent-text);
        }
        .td-calendar-card {
            background: var(--color-card-bg);
            border-radius: var(--card-radius);
            box-shadow: var(--card-shadow);
            padding: 16px;
            margin-bottom: 14px;
            border-top: 4px solid var(--color-accent);
        }
        .td-calendar-weekdays {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            text-align: center;
            font-weight: 800;
            font-size: 0.84rem;
            margin-bottom: 8px;
        }
        .td-calendar-weekdays span {
            color: #475569;
        }
        .td-calendar-weekdays span:first-child {
            color: #ef4444;
        }
        .td-calendar-weekdays span:last-child {
            color: #2563eb;
        }
        .td-calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 8px;
        }
        .td-calendar-cell {
            min-height: 68px;
            border-radius: 12px;
            border: 1px solid #e5e9f0;
            background: #ffffff;
            padding: 6px 4px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            cursor: default;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05);
            transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
        }
        .td-calendar-cell.is-empty {
            background: transparent;
            border-color: transparent;
            box-shadow: none;
        }
        .td-calendar-cell.has-posts {
            cursor: pointer;
            border-color: var(--color-cell-border-filled);
            background: var(--color-card-bg);
        }
        .td-calendar-cell.has-posts:hover {
            transform: translateY(-2px);
            border-color: var(--color-accent);
            box-shadow: 0 6px 14px rgba(46, 64, 87, 0.12);
        }
        /* 練習記録がある日でも「今日」の枠を優先する */
        .td-calendar-cell.is-today {
            border-color: var(--color-today-border);
            background: var(--color-today-bg);
            box-shadow: none;
        }
        .td-calendar-cell.is-selected {
            background: var(--color-hero-from);
            border-color: var(--color-accent);
            box-shadow: 0 0 0 1px var(--color-accent);
        }
        .td-calendar-date {
            font-weight: 800;
            color: #334155;
            font-size: 0.86rem;
        }
        .td-calendar-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 24px;
            height: 22px;
            padding: 0 7px;
            border-radius: 999px;
            background: var(--color-accent);
            color: var(--color-accent-text);
            font-size: 0.74rem;
            font-weight: 800;
        }
        @media (max-width: 576px) {
            .td-calendar-cell {
                min-height: 50px;
            }
            .td-calendar-date {
                font-size: 0.74rem;
            }
            .td-calendar-count {
                font-size: 0.66rem;
                height: 18px;
                min-width: 18px;
            }
        }
        .td-person-block {
            border: 1px solid #dce6f2;
            border-radius: 14px;
            margin-bottom: 10px;
            overflow: hidden;
        }
        .td-person-block:last-child {
            margin-bottom: 0;
        }
        .td-person-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 12px;
            background: #ffffff;
        }
        .td-person-row-main {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
            flex: 1;
            background: none;
            border: 0;
            padding: 0;
            text-align: left;
            cursor: pointer;
        }
        .td-person-row-main:hover .td-person-name {
            text-decoration: underline;
        }
        .td-person-name {
            color: #1d4ed8;
            font-weight: 800;
        }
        .td-person-toggle-icon {
            display: inline-block;
            font-size: 0.7rem;
            color: #64748b;
            margin-right: 4px;
            transition: transform 0.2s ease;
        }
        .td-person-row-main[aria-expanded="true"] .td-person-toggle-icon {
            transform: rotate(90deg);
        }
        .td-person-row-meta {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }
        .td-comment-count-text {
            color: #64748b;
            font-size: 0.82rem;
            font-weight: 700;
            white-space: nowrap;
        }
        .avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #93c5fd;
            color: #1e3a8a;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            flex-shrink: 0;
        }
        .avatar-image {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            object-fit: cover;
            border: 1px solid #dbe3ef;
            background: #ffffff;
            flex-shrink: 0;
        }
        .menu-tag {
            display: inline-block;
            background: #e2e8f0;
            color: #334155;
            border-radius: 999px;
            padding: 3px 10px;
            font-size: 0.78rem;
            margin-left: 16px;
        }
        .menu-tag.intensity-point {
            background: #fecaca;
            color: #7f1d1d;
        }
        .menu-tag.intensity-race {
            background: #3b82f6;
            color: #ffffff;
        }
        .menu-tag.intensity-normal {
            background: #fef08a;
            color: #713f12;
        }
        .menu-tag.intensity-light {
            background: #bbf7d0;
            color: #14532d;
        }
        .menu-tag.intensity-weight {
            background: #ddd6fe;
            color: #4c1d95;
        }
        .type-badge {
            display: inline-block;
            background: #eef2ff;
            color: #3730a3;
            border-radius: 999px;
            padding: 3px 10px;
            font-size: 0.78rem;
            font-weight: 700;
            margin-left: 16px;
            white-space: nowrap;
        }
        .td-person-detail {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.25s ease;
            background: #f8fbff;
            border-top: 1px solid #e2e8f0;
        }
        .td-person-detail.is-open {
            max-height: 3000px;
        }
        .td-person-detail-inner {
            padding: 12px;
        }
        .post-time { white-space: pre-wrap; color: #0f172a; font-weight: 600; margin-bottom: 8px; }
        .running-set-block {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 8px 12px;
        }
        .running-set-header {
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 6px;
        }
        .running-set-laps {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .running-lap-chip {
            background: #ffffff;
            border: 1px solid #dbe4ef;
            border-radius: 999px;
            padding: 2px 10px;
            font-size: 0.82rem;
            color: #334155;
        }
        .post-comment { white-space: pre-wrap; color: #334155; margin-bottom: 8px; }
        .post-image {
            width: min(100%, 260px);
            max-height: 220px;
            object-fit: cover;
            border-radius: 12px;
            border: 1px solid #dbe3ef;
        }
        .post-meta-line {
            color: #64748b;
            font-size: 0.82rem;
            margin-bottom: 8px;
        }
        .member-link-line {
            margin-bottom: 10px;
        }
        .member-link-line a {
            font-size: 0.82rem;
            font-weight: 700;
        }
        .like-toggle-btn {
            border-radius: 999px;
            border: 1px solid #d6dde8;
            background: #f8fafc;
            color: #334155;
            font-weight: 700;
            padding: 6px 10px;
            font-size: 0.84rem;
            line-height: 1;
        }
        .like-toggle-btn:hover {
            background: #eef4fb;
            color: #0f172a;
        }
        .like-toggle-btn.active {
            border-color: var(--color-accent);
            background: var(--color-hero-from);
            color: var(--color-hero-text);
        }
        .like-count {
            font-size: 0.9rem;
            font-weight: 800;
            color: #334155;
            min-width: 20px;
            text-align: center;
            display: inline-block;
        }
        .comment-panel {
            margin-top: 10px;
            border-radius: 14px;
            border: 1px solid #d8e3ef;
            background: #ffffff;
            padding: 12px;
        }
        .comment-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            max-height: 260px;
            overflow-y: auto;
            padding-right: 4px;
        }
        .comment-item {
            position: relative;
            border-radius: 12px;
            background: #f8fbff;
            border: 1px solid #e2e8f0;
            padding: 10px 12px;
        }
        .comment-delete-btn {
            flex-shrink: 0;
            padding: 1px 10px;
            border: 1px solid rgba(185, 28, 28, 0.35);
            border-radius: 999px;
            background: transparent;
            color: #b91c1c;
            font-size: 0.72rem;
            font-weight: 700;
        }
        .comment-delete-btn:hover {
            background: #fef2f2;
        }
        .comment-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 4px;
            color: #64748b;
            font-size: 0.8rem;
            font-weight: 700;
        }
        .comment-coach-only {
            margin: 8px 0 0;
            color: var(--color-text-muted);
            font-size: 0.78rem;
        }
        .comment-form {
            margin-top: 10px;
            display: grid;
            gap: 8px;
        }
        .comment-input {
            border-radius: 12px;
            border: 1px solid #cfd8e4;
            min-height: 66px;
            resize: vertical;
            padding: 10px 12px;
        }
        /* カレンダーの下のタイムライン（ページと一緒に伸びる。中だけのスクロールはしない） */
        .td-timeline-card {
            background: var(--color-card-bg);
            border-radius: var(--card-radius);
            box-shadow: var(--card-shadow);
            padding: 16px;
            margin-bottom: 14px;
        }
        .td-timeline-title {
            margin: 0 0 4px;
            font-size: 1rem;
            font-weight: 800;
            color: var(--color-text-primary);
        }
        @media (max-width: 768px) {
            .timeline-head {
                flex-direction: column;
                align-items: stretch;
            }
            .diary-post-btn {
                align-self: flex-end;
            }
        }

        /* ==================== みんな（仕様書の画面） ==================== */
        /* 左に「CLUB PULSE」、右に今日の投稿の件数 */
        .everyone-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin: 4px 0 14px;
        }
        .everyone-today {
            display: inline-flex;
            align-items: baseline;
            gap: 2px;
            margin: 0;
            padding: 6px 14px;
            border-radius: 999px;
            background: var(--orange-soft);
            color: var(--orange-deep);
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }
        .everyone-today strong {
            margin-left: 6px;
            font-family: var(--font-num);
            font-size: 18px;
            font-weight: 800;
            line-height: 1;
        }
        .everyone-eyebrow {
            margin: 0;
            color: var(--orange-deep);
            font-family: var(--font-num);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.12em;
        }
        /* 見る範囲のチップ（1行で横にスクロール）。選択中は --navy 地＋白、未選択は白地＋枠 */
        .everyone-chips {
            display: flex;
            gap: 8px;
            margin: 0 calc(-1 * var(--page-gutter)) 14px;
            padding: 0 var(--page-gutter) 4px;
            overflow-x: auto;
            scrollbar-width: none;
        }
        .everyone-chips::-webkit-scrollbar {
            display: none;
        }
        .everyone-chip {
            flex-shrink: 0;
            padding: 7px 16px;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: var(--surface);
            color: var(--navy);
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
        }
        .everyone-chip.is-active {
            border-color: var(--navy);
            background: var(--navy);
            color: #FFFFFF;
        }
        .everyone-chip-add {
            border-style: dashed;
            color: var(--sub);
        }
        /* 選んだグループのカード: 角丸12pxの正方形のアイコン、名前・人数、右に「＋ 招待」 */
        .everyone-group {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 18px;
            padding: 14px 16px;
            border: 1px solid var(--line);
            border-radius: var(--card-radius);
            background: var(--surface);
        }
        .everyone-group-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: var(--wis-soft);
            color: var(--wis);
            font-size: 16px;
            font-weight: 900;
            object-fit: cover;
        }
        .everyone-group-text {
            flex: 1;
            min-width: 0;
        }
        .everyone-group-name {
            display: block;
            overflow: hidden;
            color: var(--navy);
            font-size: 15px;
            font-weight: 900;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .everyone-group-sub {
            color: var(--sub);
            font-size: 12px;
        }
        .everyone-group-invite {
            flex-shrink: 0;
            padding: 6px 14px;
            border: 0;
            border-radius: 999px;
            background: var(--orange-soft);
            color: var(--orange-deep);
            font-size: 13px;
            font-weight: 700;
        }
        /* 「練習日記」とタイムライン／カレンダーの切り替え */
        .everyone-section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 10px;
        }
        .everyone-section-title {
            margin: 0;
            color: var(--orange-deep);
            font-size: 13px;
            font-weight: 700;
        }
        .everyone-view {
            display: inline-flex;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--surface);
        }
        .everyone-view-btn {
            padding: 4px 10px;
            border: 0;
            background: transparent;
            color: var(--sub);
            font-size: 11px;
            font-weight: 700;
        }
        .everyone-view-btn[aria-selected="true"] {
            background: var(--orange-soft);
            color: var(--orange-deep);
        }
        /* すべて / 今日の投稿 / 自分の投稿 / ピックアップ（選択中は下にオレンジの線） */
        .everyone-filter {
            display: flex;
            gap: 20px;
            margin-bottom: 14px;
            border-bottom: 1px solid var(--line);
            overflow-x: auto; /* 狭い画面では折り返さず、横にずらして見る */
            scrollbar-width: none;
        }
        .everyone-filter::-webkit-scrollbar {
            display: none;
        }
        .everyone-filter-btn {
            flex-shrink: 0;
            white-space: nowrap;
        }
        .everyone-filter-btn {
            position: relative;
            padding: 8px 0;
            border: 0;
            background: none;
            color: var(--sub);
            font-size: 14px;
            font-weight: 700;
        }
        .everyone-filter-btn[aria-selected="true"] {
            color: var(--navy);
        }
        .everyone-filter-btn[aria-selected="true"]::after {
            content: "";
            position: absolute;
            right: 0;
            bottom: 0; /* タブの列は横スクロールなので、はみ出すと切れる。下線は枠の内側に置く */
            left: 0;
            height: 3px;
            border-radius: 999px;
            background: var(--orange);
        }
        .everyone-calendar-note {
            margin: 10px 0 0;
            color: var(--sub);
            font-size: 11px;
        }
        .everyone-tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 18px;
            padding: 4px;
            border-radius: 999px;
            background: var(--beige2);
        }
        .everyone-tab {
            flex: 1 1 0;
            padding: 8px 4px;
            border-radius: 999px;
            color: var(--sub);
            font-size: 13px;
            font-weight: 700;
            text-align: center;
            text-decoration: none;
            white-space: nowrap;
        }
        .everyone-tab.is-active {
            background: var(--surface);
            box-shadow: var(--shadow-float);
            color: var(--navy);
        }
        .everyone-empty {
            padding: 16px;
            border-radius: var(--card-radius);
            background: var(--beige2);
            color: var(--sub);
            font-size: 13px;
        }
    </style>
</head>
<body>
<?php
$shared_header = __DIR__ . '/../includes/site_header.php';
if (file_exists($shared_header)) {
    require_once $shared_header;
} else {
    echo '<div style="background:var(--color-header);color:#fff;padding:12px 16px;font-weight:700;">TEAM OFFICIAL</div>';
}
?>

<div class="timeline-wrap">
    <?php // 投稿などの完了の知らせは、ヘッダーのトースト（includes/site_header.php）で出す ?>
    <header class="everyone-head">
        <p class="everyone-eyebrow">CLUB PULSE</p>
        <?php // 大きな見出しの文は出さない（ページの見出しは読み上げ用にだけ残す） ?>
        <h1 class="visually-hidden">みんなの練習日記</h1>
        <?php // 右上: 今日の投稿の件数 ?>
        <p class="everyone-today">今日の投稿<strong><?php echo (int)$today_post_count; ?></strong>件</p>
    </header>

    <?php // 見る範囲: すべて（全体公開）か、自分のグループ。右端の「＋ グループ」でマイグループ（作成・参加・管理）を開く ?>
    <nav class="everyone-chips" aria-label="見る範囲">
        <a class="everyone-chip<?php echo $selected_group === null ? ' is-active' : ''; ?>" href="training_diary.php"<?php echo $selected_group === null ? ' aria-current="page"' : ''; ?>>すべて</a>
        <?php foreach ($my_groups as $chip_group): ?>
            <?php $chip_selected = $selected_group !== null && $selected_group['id'] === $chip_group['id']; ?>
            <a class="everyone-chip<?php echo $chip_selected ? ' is-active' : ''; ?>" href="training_diary.php?group=<?php echo (int)$chip_group['id']; ?>"<?php echo $chip_selected ? ' aria-current="page"' : ''; ?>><?php echo htmlspecialchars($chip_group['name'], ENT_QUOTES, 'UTF-8'); ?></a>
        <?php endforeach; ?>
        <button type="button" class="everyone-chip everyone-chip-add" data-bs-toggle="modal" data-bs-target="<?php echo $is_logged_in ? '#groupModal' : '#groupGuestModal'; ?>">＋ グループ</button>
    </nav>

    <?php if ($selected_group !== null): ?>
        <?php // 選んだグループ: アイコン（写真か、名前の1文字）、名前、人数、招待 ?>
        <section class="everyone-group" aria-label="グループ">
            <?php if ($selected_group['icon_path'] !== ''): ?>
                <img class="everyone-group-icon" src="../<?php echo htmlspecialchars($selected_group['icon_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="">
            <?php else: ?>
                <span class="everyone-group-icon" aria-hidden="true"><?php echo htmlspecialchars(mb_substr($selected_group['name'], 0, 1), ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
            <span class="everyone-group-text">
                <span class="everyone-group-name"><?php echo htmlspecialchars($selected_group['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="everyone-group-sub">メンバー <?php echo (int)$selected_group['member_count']; ?>人</span>
            </span>
            <?php if ($selected_group['status'] === MEMBER_GROUP_STATUS_ACTIVE): ?>
                <button type="button" class="everyone-group-invite" data-open-group-invite="<?php echo (int)$selected_group['id']; ?>">＋ 招待</button>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php // 日記 / ランキング（ベージュの枠の中に均等に並べ、選択中は白く浮かせる） ?>
    <nav class="everyone-tabs" aria-label="みんなの画面">
        <?php foreach (['diary' => '日記', 'ranking' => 'ランキング'] as $view_key => $view_label): ?>
            <a class="everyone-tab<?php echo $view_key === $everyone_view ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($everyone_url(['view' => $view_key]), ENT_QUOTES, 'UTF-8'); ?>"<?php echo $view_key === $everyone_view ? ' aria-current="page"' : ''; ?>><?php echo $view_label; ?></a>
        <?php endforeach; ?>
    </nav>

    <?php // 日記の部分は、ほかのタブのときは隠すだけ（カレンダー・タイムラインの JS はそのまま動く） ?>
    <div class="everyone-diary"<?php echo $everyone_view === 'diary' ? '' : ' hidden'; ?>>
    <?php // 練習日記: タイムライン（初期）とカレンダーの切り替え ?>
    <div class="everyone-section-head">
        <h2 class="everyone-section-title" id="everyoneDiaryTitle">練習日記</h2>
        <div class="everyone-view" role="tablist" aria-label="表示の切り替え">
            <button type="button" class="everyone-view-btn" role="tab" aria-selected="true" aria-controls="tdTimelinePanel" data-everyone-view="timeline">タイムライン</button>
            <button type="button" class="everyone-view-btn" role="tab" aria-selected="false" aria-controls="tdCalendarPanel" data-everyone-view="calendar">カレンダー</button>
        </div>
    </div>

    <?php // 絞り込み: すべて / 今日の投稿 / 自分の投稿（ログイン中だけ）/ ピックアップ（管理者が会員一覧で選んだ人の投稿）。
          // タイムラインとカレンダーの両方に効く ?>
    <div class="everyone-filter" role="tablist" aria-label="投稿の絞り込み">
        <button type="button" class="everyone-filter-btn" role="tab" aria-selected="true" data-timeline-filter="all">すべて</button>
        <button type="button" class="everyone-filter-btn" role="tab" aria-selected="false" data-timeline-filter="today">今日の投稿</button>
        <?php if ($is_logged_in): ?>
            <button type="button" class="everyone-filter-btn" role="tab" aria-selected="false" data-timeline-filter="mine">自分の投稿</button>
        <?php endif; ?>
        <button type="button" class="everyone-filter-btn" role="tab" aria-selected="false" data-timeline-filter="pickup">ピックアップ</button>
    </div>

    <div id="tdTimelinePanel" role="tabpanel" aria-labelledby="everyoneDiaryTitle">
        <div class="tl-list" id="tdTimelineList"></div>
    </div>

    <div id="tdCalendarPanel" role="tabpanel" aria-labelledby="everyoneDiaryTitle" hidden>
        <div class="td-calendar-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="tdCalendarPrev">&lsaquo; 前月</button>
                <div class="fw-bold" id="tdCalendarLabel"></div>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="tdCalendarNext">次月 &rsaquo;</button>
            </div>
            <div class="td-calendar-weekdays">
                <span>日</span><span>月</span><span>火</span><span>水</span><span>木</span><span>金</span><span>土</span>
            </div>
            <div class="td-calendar-grid" id="tdCalendarGrid"></div>
            <p class="everyone-calendar-note">日付の下の数字は、その日に練習した人数です。日付をタップすると、練習した人と内容が見られます。</p>
        </div>
    </div>
    </div>

    <?php if ($everyone_view === 'ranking'): ?>
        <?php
        $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $ranking_url = static fn(array $params): string => $everyone_url(['view' => 'ranking'] + $params);
        $ranking_note = $selected_group !== null
            ? '全体公開か、このグループに公開した記録で集計しています（メンバー全員）。'
            : 'みんなの練習日記に公開した記録で集計しています。';
        $ranking_viewer = $current_user_id;
        $ranking_base = '../';
        require __DIR__ . '/../includes/distance_ranking.php';
        ?>
    <?php endif; ?>
</div>

<?php
// マイグループの画面（作成・招待・参加・管理）。ログイン前の人には機能の紹介
$group_modal_base = '../';
$group_modal_join_code = $is_logged_in ? $group_join_code : '';
require __DIR__ . '/../includes/group_modal.php';
?>

<div class="modal fade" id="tdDayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="tdDayModalTitle">練習日記の詳細</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="tdDayModalBody"></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/diary_timeline.js?v=<?php echo (int)@filemtime(__DIR__ . '/../js/diary_timeline.js'); ?>"></script>
<script>
    (function () {
        var diaryPosts = <?php echo $diary_posts_json !== false ? $diary_posts_json : '[]'; ?>;
        var isLoggedIn = <?php echo $is_logged_in ? 'true' : 'false'; ?>;
        var currentUserId = <?php echo (int)$current_user_id; ?>;

        var calendarGrid = document.getElementById('tdCalendarGrid');
        var calendarLabel = document.getElementById('tdCalendarLabel');
        var prevBtn = document.getElementById('tdCalendarPrev');
        var nextBtn = document.getElementById('tdCalendarNext');
        var dayModalEl = document.getElementById('tdDayModal');
        var dayModalTitle = document.getElementById('tdDayModalTitle');
        var dayModalBody = document.getElementById('tdDayModalBody');
        var dayModalInstance = typeof bootstrap !== 'undefined' ? bootstrap.Modal.getOrCreateInstance(dayModalEl) : null;
        // 投稿が0件のときの案内は、下のタイムラインに出す

        var today = new Date();
        var viewYear = today.getFullYear();
        var viewMonth = today.getMonth();
        var selectedDateKey = null;
        // 表示期間（サーバー側と同じ最初の月）より前には戻れないようにする
        var minViewYear = <?php echo (int)date('Y', strtotime(public_training_diary_visible_from())); ?>;
        var minViewMonth = <?php echo (int)date('n', strtotime(public_training_diary_visible_from())) - 1; ?>;

        function pad2(value) {
            return String(value).padStart(2, '0');
        }

        function formatDateKey(y, m, d) {
            return y + '-' + pad2(m + 1) + '-' + pad2(d);
        }

        function escapeHtml(value) {
            var div = document.createElement('div');
            div.textContent = value == null ? '' : String(value);
            return div.innerHTML;
        }

        var trainingTypeBadges = {
            short_sprint: { icon: '🏃', label: 'ショートスプ' },
            long_sprint: { icon: '🏃‍♂️', label: 'ロングスプ' },
            weight: { icon: '🏋', label: 'ウエイト' },
            race: { icon: '🏆', label: '大会' }
        };

        function formatDateTime(value) {
            if (!value) {
                return '';
            }
            var date = new Date(String(value).replace(' ', 'T'));
            if (isNaN(date.getTime())) {
                return String(value);
            }
            return date.getFullYear() + '/' + pad2(date.getMonth() + 1) + '/' + pad2(date.getDate()) + ' ' + pad2(date.getHours()) + ':' + pad2(date.getMinutes());
        }

        // カレンダーに出す投稿（練習日ごと）。上の絞り込みを選び直すと入れ替える
        var postsByDate = {};
        function setCalendarPosts(posts) {
            postsByDate = {};
            posts.forEach(function (post) {
                var key = post.practice_date;
                if (!postsByDate[key]) {
                    postsByDate[key] = [];
                }
                postsByDate[key].push(post);
            });
        }
        setCalendarPosts(diaryPosts);

        function findPostById(diaryId) {
            for (var i = 0; i < diaryPosts.length; i++) {
                if (String(diaryPosts[i].id) === String(diaryId)) {
                    return diaryPosts[i];
                }
            }
            return null;
        }

        function countUniquePeople(posts) {
            var seen = {};
            posts.forEach(function (post) {
                seen[post.user_id] = true;
            });
            return Object.keys(seen).length;
        }

        function renderCalendar() {
            calendarLabel.textContent = viewYear + '年' + (viewMonth + 1) + '月';
            prevBtn.disabled = viewYear < minViewYear || (viewYear === minViewYear && viewMonth <= minViewMonth);
            calendarGrid.innerHTML = '';

            var firstDay = new Date(viewYear, viewMonth, 1);
            var startWeekday = firstDay.getDay();
            var daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();
            var todayKey = formatDateKey(today.getFullYear(), today.getMonth(), today.getDate());

            for (var i = 0; i < startWeekday; i++) {
                var blank = document.createElement('div');
                blank.className = 'td-calendar-cell is-empty';
                calendarGrid.appendChild(blank);
            }

            for (var day = 1; day <= daysInMonth; day++) {
                var dateKey = formatDateKey(viewYear, viewMonth, day);
                var dayPosts = postsByDate[dateKey] || [];
                var cell = document.createElement('div');
                cell.className = 'td-calendar-cell' + (dateKey === todayKey ? ' is-today' : '');
                if (dayPosts.length > 0) {
                    cell.classList.add('has-posts');
                }
                if (dateKey === selectedDateKey) {
                    cell.classList.add('is-selected');
                }

                var dateLabel = document.createElement('div');
                dateLabel.className = 'td-calendar-date';
                dateLabel.textContent = String(day);
                cell.appendChild(dateLabel);

                if (dayPosts.length > 0) {
                    var countBadge = document.createElement('span');
                    countBadge.className = 'td-calendar-count';
                    countBadge.textContent = String(countUniquePeople(dayPosts));
                    cell.appendChild(countBadge);

                    cell.addEventListener('click', function (clickedDateKey) {
                        return function () {
                            openDayModal(clickedDateKey);
                        };
                    }(dateKey));
                }

                calendarGrid.appendChild(cell);
            }
        }

        function openDayModal(dateKey) {
            selectedDateKey = dateKey;
            renderDayPanel(dateKey);
            renderCalendar();
            if (dayModalInstance) {
                dayModalInstance.show();
            }
        }

        function renderDayPanel(dateKey) {
            var dayPosts = postsByDate[dateKey] || [];
            var dateObj = new Date(dateKey + 'T00:00:00');
            dayModalTitle.textContent = (dateObj.getFullYear() + '/' + pad2(dateObj.getMonth() + 1) + '/' + pad2(dateObj.getDate())) + ' に練習した人（' + dayPosts.length + '件）';

            dayModalBody.innerHTML = '';
            dayPosts.forEach(function (post) {
                dayModalBody.appendChild(buildPersonBlock(post));
            });
        }

        function buildPersonBlock(post) {
            var block = document.createElement('div');
            block.className = 'td-person-block';

            var row = document.createElement('div');
            row.className = 'td-person-row';

            var mainBtn = document.createElement('button');
            mainBtn.type = 'button';
            mainBtn.className = 'td-person-row-main';
            mainBtn.setAttribute('aria-expanded', 'false');

            var avatarEl;
            if (post.avatar_path) {
                avatarEl = document.createElement('img');
                avatarEl.className = 'avatar-image';
                avatarEl.src = '../' + post.avatar_path;
                avatarEl.alt = '';
            } else {
                avatarEl = document.createElement('span');
                avatarEl.className = 'avatar';
                avatarEl.textContent = (post.user_name || '').substr(0, 1);
            }
            mainBtn.appendChild(avatarEl);

            var typeBadge = trainingTypeBadges[post.training_type];
            var typeBadgeHtml = typeBadge
                ? '<span class="type-badge">' + typeBadge.icon + ' ' + typeBadge.label + '</span>'
                : '';

            var nameWrap = document.createElement('span');
            nameWrap.innerHTML = '<span class="td-person-toggle-icon">▶</span>' +
                '<span class="td-person-name">' + escapeHtml(post.user_name) + '</span>' +
                '<span class="menu-tag intensity-' + escapeHtml(post.intensity || 'normal') + '">' + escapeHtml(post.menu_text) + '</span>' +
                typeBadgeHtml;
            mainBtn.appendChild(nameWrap);

            var metaWrap = document.createElement('div');
            metaWrap.className = 'td-person-row-meta';

            var commentText = document.createElement('span');
            commentText.className = 'td-comment-count-text';
            commentText.textContent = 'コメント ' + post.comment_count;
            metaWrap.appendChild(commentText);

            var likeBtn = document.createElement('button');
            likeBtn.type = 'button';
            likeBtn.className = 'like-toggle-btn' + (post.liked_by_me ? ' active' : '');
            likeBtn.innerHTML = '👍 <span class="like-count">' + post.like_count + '</span>';
            if (!isLoggedIn) {
                likeBtn.addEventListener('click', function () {
                    window.location.href = '../mypage/login.php';
                });
            } else {
                likeBtn.addEventListener('click', function () {
                    handleLikeClick(post.id, likeBtn);
                });
            }
            metaWrap.appendChild(likeBtn);

            row.appendChild(mainBtn);
            row.appendChild(metaWrap);

            var detail = document.createElement('div');
            detail.className = 'td-person-detail';
            detail.setAttribute('data-detail-id', post.id);

            mainBtn.addEventListener('click', function () {
                var isOpen = detail.classList.contains('is-open');
                if (isOpen) {
                    detail.classList.remove('is-open');
                    mainBtn.setAttribute('aria-expanded', 'false');
                } else {
                    detail.classList.add('is-open');
                    mainBtn.setAttribute('aria-expanded', 'true');
                    if (detail.childElementCount === 0) {
                        detail.appendChild(buildPersonDetail(post));
                    }
                }
            });

            block.appendChild(row);
            block.appendChild(detail);

            return block;
        }

        function buildWeightSetsTable(weightSets) {
            var wrap = document.createElement('div');
            wrap.className = 'table-responsive mb-2';

            if (!weightSets || weightSets.length === 0) {
                wrap.className = 'text-secondary small mb-2';
                wrap.textContent = 'ウエイト種目の記録がありません。';
                return wrap;
            }

            var table = document.createElement('table');
            table.className = 'table table-sm mb-0';
            var rowsHtml = weightSets.map(function (set) {
                var loadLabel;
                if (set.is_bodyweight) {
                    loadLabel = '自重';
                } else if (set.weight_kg !== null && set.weight_kg !== undefined) {
                    loadLabel = set.weight_kg + 'kg';
                } else {
                    loadLabel = set.weight_note || '-';
                }
                return '<tr>' +
                    '<td>' + escapeHtml(set.body_part) + '</td>' +
                    '<td>' + escapeHtml(set.exercise_name) + '</td>' +
                    '<td>' + (set.reps !== null && set.reps !== undefined ? escapeHtml(set.reps) + '回' : '-') + '</td>' +
                    '<td>' + (set.sets !== null && set.sets !== undefined ? escapeHtml(set.sets) + 'セット' : '-') + '</td>' +
                    '<td>' + escapeHtml(loadLabel) + '</td>' +
                    '</tr>';
            }).join('');
            table.innerHTML = '<thead><tr><th>部位</th><th>種目</th><th>回数</th><th>セット</th><th>重量</th></tr></thead><tbody>' + rowsHtml + '</tbody>';
            wrap.appendChild(table);
            return wrap;
        }

        function buildRaceEntriesTable(raceEntries) {
            var wrap = document.createElement('div');
            wrap.className = 'table-responsive mb-2';

            if (!raceEntries || raceEntries.length === 0) {
                wrap.className = 'text-secondary small mb-2';
                wrap.textContent = '出場種目の記録がありません。';
                return wrap;
            }

            var table = document.createElement('table');
            table.className = 'table table-sm mb-0';
            var rowsHtml = raceEntries.map(function (entry) {
                return '<tr>' +
                    '<td>' + escapeHtml(entry.event_name) + '</td>' +
                    '<td>' + escapeHtml(entry.result_label) + '</td>' +
                    '<td>' + escapeHtml(entry.notes_label || '') + '</td>' +
                    '</tr>';
            }).join('');
            table.innerHTML = '<thead><tr><th>種目</th><th>タイム・記録</th><th>風・公認</th></tr></thead><tbody>' + rowsHtml + '</tbody>';
            wrap.appendChild(table);
            return wrap;
        }

        function buildRunningSetsBlock(runningSets) {
            var wrap = document.createElement('div');

            if (!runningSets || runningSets.length === 0) {
                wrap.className = 'text-secondary small mb-2';
                wrap.textContent = 'ランニング種目の記録がありません。';
                return wrap;
            }

            wrap.innerHTML = runningSets.map(function (set) {
                var headerText = set.workout_type + ' ／ ' + set.distance_label + ' × ' + set.reps + '本' +
                    (set.sets > 1 ? ' × ' + set.sets + 'set' : '') +
                    (set.target_time_label ? ' ／ 設定 ' + set.target_time_label : '');

                var lapsHtml = '';
                if (set.laps && set.laps.length > 0) {
                    lapsHtml = '<div class="running-set-laps">' + set.laps.map(function (lap) {
                        var label = lap.lap_time_seconds !== null && lap.lap_time_seconds !== undefined
                            ? lap.lap_time_seconds + '秒'
                            : (lap.lap_time_label || '-');
                        return '<span class="running-lap-chip">' + lap.lap_number + '本目: ' + escapeHtml(label) + '</span>';
                    }).join('') + '</div>';
                }

                return '<div class="running-set-block mb-2">' +
                    '<div class="running-set-header">' + escapeHtml(headerText) + '</div>' +
                    lapsHtml +
                    '</div>';
            }).join('');

            return wrap;
        }

        function buildPersonDetail(post) {
            var inner = document.createElement('div');
            inner.className = 'td-person-detail-inner';

            var metaLine = document.createElement('div');
            metaLine.className = 'post-meta-line';
            var metaText = '練習日: ' + post.practice_date.replace(/-/g, '/') + ' ／ 投稿: ' + formatDateTime(post.created_at);
            if (post.updated_at && post.updated_at !== post.created_at) {
                metaText += ' ／ 更新: ' + formatDateTime(post.updated_at);
            }
            metaLine.textContent = metaText;
            inner.appendChild(metaLine);

            var memberLine = document.createElement('div');
            memberLine.className = 'member-link-line';
            memberLine.innerHTML = '<a href="training_member.php?user_id=' + encodeURIComponent(post.user_id) + '">この人の他の記録も見る</a>';
            inner.appendChild(memberLine);

            if (post.training_type === 'weight') {
                inner.appendChild(buildWeightSetsTable(post.weight_sets));
            } else if (post.training_type === 'race') {
                inner.appendChild(buildRaceEntriesTable(post.race_entries));
            } else if (post.training_type === 'jog' && window.DiaryTimeline) {
                inner.appendChild(DiaryTimeline.buildJogBlock(post.jog_entries));
            } else if (post.running_sets && post.running_sets.length > 0) {
                inner.appendChild(buildRunningSetsBlock(post.running_sets));
            } else {
                var timeEl = document.createElement('div');
                timeEl.className = 'post-time';
                timeEl.textContent = post.time_text || '';
                inner.appendChild(timeEl);
            }

            if (post.comment_text) {
                var commentEl = document.createElement('div');
                commentEl.className = 'post-comment';
                commentEl.textContent = post.comment_text;
                inner.appendChild(commentEl);
            }

            if (post.image_path) {
                var imgLink = document.createElement('a');
                imgLink.href = '../' + post.image_path;
                imgLink.target = '_blank';
                imgLink.rel = 'noopener noreferrer';
                var img = document.createElement('img');
                img.className = 'post-image';
                img.src = '../' + post.image_path;
                img.alt = '練習写真';
                imgLink.appendChild(img);
                inner.appendChild(imgLink);
            }

            if (post.video_path) {
                var video = document.createElement('video');
                video.className = 'post-image mt-2';
                video.src = '../' + post.video_path;
                video.autoplay = true;
                video.muted = true;
                video.playsInline = true;
                video.loop = true;
                video.controls = true;
                inner.appendChild(video);
            }

            inner.appendChild(buildCommentPanel(post));

            return inner;
        }

        function buildCommentPanel(post) {
            var panel = document.createElement('div');
            panel.className = 'comment-panel';
            panel.setAttribute('data-comment-panel', post.id);

            var list = document.createElement('div');
            list.className = 'comment-list';
            renderCommentList(list, post);
            panel.appendChild(list);

            // OGM の生徒の日記には、コーチ陣・コーチ代表者・管理者と本人だけが書ける（can_comment はサーバーが決める）
            if (isLoggedIn && !post.can_comment) {
                var coachOnly = document.createElement('p');
                coachOnly.className = 'comment-coach-only';
                coachOnly.textContent = 'OGMの生徒の練習日記には、コーチだけがコメントできます。';
                panel.appendChild(coachOnly);
            } else if (isLoggedIn) {
                var form = document.createElement('form');
                form.className = 'comment-form';

                var textarea = document.createElement('textarea');
                textarea.className = 'comment-input';
                textarea.rows = 3;
                textarea.maxLength = 500;
                textarea.placeholder = 'コメントを追加';

                var submitBtn = document.createElement('button');
                submitBtn.type = 'submit';
                submitBtn.className = 'btn btn-primary btn-sm align-self-end';
                submitBtn.textContent = '送信';

                form.appendChild(textarea);
                form.appendChild(submitBtn);

                form.addEventListener('submit', function (event) {
                    event.preventDefault();
                    var text = textarea.value.trim();
                    if (text === '') {
                        alert('コメントを入力してください。');
                        textarea.focus();
                        return;
                    }
                    submitComment(post, text, list, textarea);
                });

                panel.appendChild(form);
            }

            return panel;
        }

        function renderCommentList(list, post) {
            list.innerHTML = '';
            if (!post.comments || post.comments.length === 0) {
                var empty = document.createElement('div');
                empty.className = 'text-secondary small';
                empty.textContent = 'まだコメントはありません。';
                list.appendChild(empty);
                return;
            }

            post.comments.forEach(function (comment) {
                list.appendChild(buildCommentItem(post, comment));
            });
        }

        function buildCommentItem(post, comment) {
            var item = document.createElement('div');
            var isOwn = String(comment.user_id) === String(currentUserId);
            item.className = 'comment-item';

            var meta = document.createElement('div');
            meta.className = 'comment-meta';
            var metaText = document.createElement('span');
            metaText.textContent = comment.user_name + ' / ' + formatDateTime(comment.created_at);
            meta.appendChild(metaText);

            // 自分のコメントには、名前の行の右端に削除ボタンを置く
            if (isOwn) {
                var deleteBtn = document.createElement('button');
                deleteBtn.type = 'button';
                deleteBtn.className = 'comment-delete-btn';
                deleteBtn.textContent = '削除';
                deleteBtn.setAttribute('aria-label', '自分のコメントを削除');
                deleteBtn.addEventListener('click', function () {
                    if (!confirm('自分のコメントを削除しますか？')) {
                        return;
                    }
                    deleteComment(post, comment, item);
                });
                meta.appendChild(deleteBtn);
            }
            item.appendChild(meta);

            var body = document.createElement('div');
            body.textContent = comment.comment_text;
            item.appendChild(body);

            return item;
        }

        function updateCommentCountDisplays(post) {
            // タイムラインのコメント数も合わせる
            document.querySelectorAll('[data-comment-count-for="' + post.id + '"]').forEach(function (countEl) {
                countEl.textContent = post.comment_count;
            });
            var rows = dayModalBody.querySelectorAll('.td-person-block');
            rows.forEach(function (block) {
                var detail = block.querySelector('[data-detail-id="' + post.id + '"]');
                if (!detail) {
                    return;
                }
                var countText = block.querySelector('.td-comment-count-text');
                if (countText) {
                    countText.textContent = 'コメント ' + post.comment_count;
                }
            });
        }

        function submitComment(post, text, list, textarea) {
            var body = new URLSearchParams();
            body.set('diary_id', post.id);
            body.set('comment_text', text);
            body.set('ajax', '1');

            fetch('../back/save_training_diary_comment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data.success) {
                        alert(data.message || 'コメントの送信に失敗しました。');
                        return;
                    }
                    post.comments.push(data.comment);
                    post.comment_count = data.comment_count;
                    renderCommentList(list, post);
                    textarea.value = '';
                    updateCommentCountDisplays(post);
                })
                .catch(function () {
                    alert('通信エラーが発生しました。');
                });
        }

        function deleteComment(post, comment, itemEl) {
            var body = new URLSearchParams();
            body.set('diary_id', post.id);
            body.set('comment_id', comment.id);
            body.set('ajax', '1');

            fetch('../back/delete_training_diary_comment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data.success) {
                        alert(data.message || 'コメントの削除に失敗しました。');
                        return;
                    }
                    post.comments = post.comments.filter(function (c) { return String(c.id) !== String(comment.id); });
                    post.comment_count = data.comment_count;
                    itemEl.remove();
                    if (post.comments.length === 0) {
                        var list = itemEl.parentElement;
                        if (list) {
                            renderCommentList(list, post);
                        }
                    }
                    updateCommentCountDisplays(post);
                })
                .catch(function () {
                    alert('通信エラーが発生しました。');
                });
        }

        function handleLikeClick(diaryId, likeBtn) {
            var post = findPostById(diaryId);
            if (!post) {
                return;
            }

            var body = new URLSearchParams();
            body.set('diary_id', diaryId);
            body.set('ajax', '1');

            fetch('../back/toggle_training_diary_like.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data.success) {
                        alert(data.message || 'いいねの更新に失敗しました。');
                        return;
                    }
                    post.liked_by_me = data.liked;
                    post.like_count = data.like_count;
                    likeBtn.classList.toggle('active', data.liked);
                    var countEl = likeBtn.querySelector('.like-count');
                    if (countEl) {
                        countEl.textContent = data.like_count;
                    }
                })
                .catch(function () {
                    alert('通信エラーが発生しました。');
                });
        }

        if (dayModalEl) {
            dayModalEl.addEventListener('hidden.bs.modal', function () {
                selectedDateKey = null;
                renderCalendar();
            });
        }

        prevBtn.addEventListener('click', function () {
            if (viewYear < minViewYear || (viewYear === minViewYear && viewMonth <= minViewMonth)) {
                return;
            }
            viewMonth -= 1;
            if (viewMonth < 0) {
                viewMonth = 11;
                viewYear -= 1;
            }
            renderCalendar();
        });

        nextBtn.addEventListener('click', function () {
            viewMonth += 1;
            if (viewMonth > 11) {
                viewMonth = 0;
                viewYear += 1;
            }
            renderCalendar();
        });

        renderCalendar();

        // ---- タイムライン（各投稿の組み立ては js/diary_timeline.js） ----
        // 絞り込み: すべて / 今日の投稿（今日投稿したもの）/ 自分の投稿 / ピックアップ。選び直すたびに、タイムラインとカレンダーを作り直す
        var timelineListEl = document.getElementById('tdTimelineList');
        var scopeLabel = <?php echo json_encode($selected_group !== null ? $selected_group['name'] : '全体公開', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
        var todayKey = <?php echo json_encode(date('Y-m-d')); ?>;
        var timelineFilters = {
            all: function () { return true; },
            today: function (post) { return String(post.created_at || '').slice(0, 10) === todayKey; },
            mine: function (post) { return Number(post.user_id) === currentUserId; },
            pickup: function (post) { return !!post.is_pickup; }
        };
        var timelineEmptyText = {
            all: 'まだ投稿がありません。<br>「記録する」から最初の投稿をしてみましょう。',
            today: '今日の投稿はまだありません。',
            mine: 'あなたの投稿はまだありません。<br>「記録する」から記録してみましょう。',
            pickup: 'ピックアップの投稿はまだありません。'
        };

        function renderTimeline(filterKey) {
            timelineListEl.innerHTML = '';
            if (!window.DiaryTimeline) {
                timelineListEl.innerHTML = '<p class="tl-empty">タイムラインを読み込めませんでした。<br>ページを読み込み直してください。</p>';
                return;
            }
            DiaryTimeline.mount({
                listEl: timelineListEl,
                posts: diaryPosts.filter(timelineFilters[filterKey] || timelineFilters.all),
                basePath: '../',
                isLoggedIn: isLoggedIn,
                scopeLabel: scopeLabel,
                emptyHtml: timelineEmptyText[filterKey] || timelineEmptyText.all,
                escapeHtml: escapeHtml,
                handleLikeClick: handleLikeClick,
                buildCommentPanel: buildCommentPanel,
                buildWeightSetsTable: buildWeightSetsTable,
                buildRaceEntriesTable: buildRaceEntriesTable,
                buildRunningSetsBlock: buildRunningSetsBlock
            });
        }

        document.querySelectorAll('[data-timeline-filter]').forEach(function (button) {
            button.addEventListener('click', function () {
                document.querySelectorAll('[data-timeline-filter]').forEach(function (other) {
                    other.setAttribute('aria-selected', other === button ? 'true' : 'false');
                });
                var filterKey = button.getAttribute('data-timeline-filter');
                renderTimeline(filterKey);
                setCalendarPosts(diaryPosts.filter(timelineFilters[filterKey] || timelineFilters.all));
                renderCalendar();
            });
        });
        renderTimeline('all');

        // ---- タイムライン / カレンダーの切り替え（初期はタイムライン） ----
        document.querySelectorAll('[data-everyone-view]').forEach(function (button) {
            button.addEventListener('click', function () {
                var view = button.getAttribute('data-everyone-view');
                document.querySelectorAll('[data-everyone-view]').forEach(function (other) {
                    other.setAttribute('aria-selected', other === button ? 'true' : 'false');
                });
                document.getElementById('tdTimelinePanel').hidden = view !== 'timeline';
                document.getElementById('tdCalendarPanel').hidden = view !== 'calendar';
            });
        });
    })();
</script>
</body>
</html>
