<?php
// クラブ画面（下タブの「クラブ」）。ログインした人の所属クラブ（会員設定）で中身が変わる。
//   TOBG  : 大会 / 合同練習 / ランキング / 歴代記録（最初は大会）
//   OGMRC : メニュー / 大会 / 合同練習 / ランキング / 歴代記録（最初はメニュー）
//   所属なし・未ログイン: チーム紹介（「チームを知ろう。」）
// 大会の申し込みだけは陸連登録者に限る（所属クラブとは別）。
require_once __DIR__ . '/../back/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/../back/db_connect.php';
require_once __DIR__ . '/../back/member_club_lib.php';
require_once __DIR__ . '/../back/tournament_list_lib.php';
require_once __DIR__ . '/../back/records_lib.php';
require_once __DIR__ . '/../back/tournament_cleanup_lib.php';
require_once __DIR__ . '/../back/tournament_add_lib.php';

$e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$weekdays = ['日', '月', '火', '水', '木', '金', '土'];

$is_logged_in = isset($_SESSION['user_id'], $_SESSION['user_name']);
$user_id = $is_logged_in ? (int)$_SESSION['user_id'] : 0;
$user_name = $is_logged_in ? (string)$_SESSION['user_name'] : '';

$club = null;
try {
    $club = $is_logged_in ? fetch_member_club($pdo, $user_id) : null;
} catch (PDOException $ex) {
    error_log('club page: member club failed: ' . $ex->getMessage());
}

// 切り替えスイッチの中身（所属クラブで変わる）
$club_tabs = [];
if ($club === MEMBER_CLUB_OGMRC) {
    $club_tabs['menu'] = 'メニュー';
}
if ($club !== null) {
    $club_tabs += ['races' => '大会', 'sessions' => '合同練習', 'ranking' => 'ランキング', 'records' => '歴代記録'];
}
$tab = (string)($_GET['tab'] ?? '');
if (!array_key_exists($tab, $club_tabs)) {
    $tab = $club === MEMBER_CLUB_OGMRC ? 'menu' : 'races';
}
$club_url = static fn(array $params): string => 'club.php?' . http_build_query($params);

// ---- 合同練習（クラブの「合同練習」と、チーム紹介の「すべての人に公開の合同練習」） ----
$viewer = ['user_id' => 0, 'club' => null, 'role' => '', 'group_ids' => [], 'can_host' => false, 'is_official_host' => false];
$sessions = [];
$session_groups = [];
if ($tab === 'sessions' || $club === null) {
    try {
        require_once __DIR__ . '/../back/club_session_lib.php';
        $viewer = club_session_viewer($pdo, $user_id);
        $sessions = fetch_club_sessions($pdo, $viewer, $club === null);
        // 募集フォームの「自分のグループ」に出すグループ（参加中で、削除申請中でないもの）
        if ($club !== null && $viewer['can_host']) {
            $session_groups = array_values(array_filter(
                fetch_member_groups_for_user($pdo, $user_id),
                static fn(array $group): bool => in_array((int)$group['id'], $viewer['group_ids'], true)
            ));
        }
    } catch (PDOException $ex) {
        error_log('club page: sessions failed: ' . $ex->getMessage());
    }
}

// ---- メニュー（OGMRC。コーチのひとこと・次の練習日・この先の予定。コーチと管理者は入力もできる） ----
$ogmrc_menu = null;
$ogmrc_can_edit = false;
if ($club === MEMBER_CLUB_OGMRC && $tab === 'menu') {
    require_once __DIR__ . '/../back/ogmrc_menu_lib.php';
    try {
        $role_stmt = $pdo->prepare('SELECT role FROM members WHERE id = :id LIMIT 1');
        $role_stmt->execute([':id' => $user_id]);
        $ogmrc_can_edit = ogmrc_menu_can_edit((string)$role_stmt->fetchColumn());
        $ogmrc_menu = fetch_ogmrc_menu($pdo);
    } catch (PDOException $ex) {
        error_log('club page: ogmrc menu failed: ' . $ex->getMessage());
    }
}

// ---- ランキング（同じクラブの会員の、みんなの練習日記に公開した記録） ----
$ranking_rows = [];
$ranking_period = null;
if ($club !== null && $tab === 'ranking') {
    require_once __DIR__ . '/../back/training_summary_lib.php';
    $ranking_period = distance_ranking_period((string)($_GET['rank_period'] ?? 'month'), (int)($_GET['rank_offset'] ?? 0));
    try {
        ensure_member_club_column($pdo);
        $ranking_rows = fetch_distance_ranking($pdo, $ranking_period['from'], $ranking_period['to'], null, $club);
    } catch (PDOException $ex) {
        error_log('club page: ranking failed: ' . $ex->getMessage());
    }
}

// ---- 大会 ----
$is_jaaf = false;
$races = [];
$entries_by_title = [];
// 所属なし・未ログインのチーム紹介の画面にも、TOBG の大会を出す（未ログインには申し込んでいる人を出さない）
if ($club === null || $tab === 'races') {
    try {
        $is_jaaf = $is_logged_in && member_id_is_jaaf_registered($pdo, $user_id);
        // 大会追加の窓の「通知を受け取るメールアドレス」に、会員情報のメールアドレスを最初から入れる
        $race_owner_email = '';
        if ($is_jaaf) {
            try {
                $email_stmt = $pdo->prepare('SELECT email FROM members WHERE id = :id LIMIT 1');
                $email_stmt->execute([':id' => $user_id]);
                $race_owner_email = (string)($email_stmt->fetchColumn() ?: '');
            } catch (PDOException $ex) {
                $race_owner_email = ''; // 読めなくても、大会の一覧はそのまま出す
            }
        }
        $races = fetch_open_tournaments($pdo, 0);
        $entries_by_title = $is_logged_in ? fetch_tournament_entries_by_title($pdo) : [];
    } catch (PDOException $ex) {
        error_log('club page: races failed: ' . $ex->getMessage());
    }
}

// ---- 歴代記録 ----
$record_gender = (string)($_GET['gender'] ?? 'male') === 'female' ? 'female' : 'male';
$record_events = array_merge(RECORD_TRACK_EVENTS, RECORD_FIELD_EVENTS);
$record_event = in_array((string)($_GET['event'] ?? ''), $record_events, true) ? (string)$_GET['event'] : $record_events[0];
$record_rows = [];
if ($club !== null && $tab === 'records') {
    $record_tables = fetch_record_tables($pdo);
    $record_key = $record_gender . '_' . (in_array($record_event, RECORD_FIELD_EVENTS, true) ? 'field' : 'track');
    $record_rows = $record_tables[$record_key][$record_event] ?? [];
}
// 名前は空白を詰めて比べる（自分の行を強調するため）
$same_person = static fn(string $a, string $b): bool => $a !== '' && preg_replace('/\s+/u', '', $a) === preg_replace('/\s+/u', '', $b);

$page_return_to = (string)($_SERVER['REQUEST_URI'] ?? '');
$session_base = '../';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>クラブ | TOBG</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;700;800;900&family=Zen+Kaku+Gothic+New:wght@400;500;700;900&display=swap" rel="stylesheet">
    <link href="../css/theme.css?v=<?php echo (int)@filemtime(__DIR__ . '/../css/theme.css'); ?>" rel="stylesheet">
    <link href="../css/club.css?v=<?php echo (int)@filemtime(__DIR__ . '/../css/club.css'); ?>" rel="stylesheet">
    <link href="../css/ranking.css?v=<?php echo (int)@filemtime(__DIR__ . '/../css/ranking.css'); ?>" rel="stylesheet">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#F4EEE4">
    <meta name="apple-mobile-web-app-title" content="TOBG-RUNNING">
    <link rel="apple-touch-icon" href="../icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" href="../icons/favicon.png">
</head>
<body>
<?php require_once __DIR__ . '/../includes/site_header.php'; ?>

<main class="club-wrap">
<?php if ($club === null): ?>
    <?php // ---- 所属なし・未ログイン: チーム紹介 ---- ?>
    <header class="club-head">
        <p class="club-eyebrow">CLUB</p>
        <h1 class="club-title">チームを知ろう。</h1>
    </header>
    <div class="club-team-cards">
        <a class="club-team-card" href="team_details.php">
            <span class="club-team-mark is-tobg" aria-hidden="true">T</span>
            <span class="club-team-text">
                <span class="club-team-name">TOBG 社会人クラブ</span>
                <span class="club-team-sub">藤陵OB・OGが立ち上げた社会人ランナーのクラブ</span>
            </span>
            <span class="club-team-arrow" aria-hidden="true">›</span>
        </a>
        <a class="club-team-card" href="team_details.php">
            <span class="club-team-mark is-ogmrc" aria-hidden="true">O</span>
            <span class="club-team-text">
                <span class="club-team-name">OGMRC 小中学生クラブ</span>
                <span class="club-team-sub">コーチのメニューで練習する小中学生のクラブ</span>
            </span>
            <span class="club-team-arrow" aria-hidden="true">›</span>
        </a>
    </div>
    <?php if (!empty($sessions)): ?>
        <h2 class="club-section-title">すべての人に公開の合同練習</h2>
        <?php $session_can_post = false; require __DIR__ . '/../includes/club_sessions.php'; ?>
    <?php endif; ?>
    <?php // TOBG の大会（未ログインでも見られる。申し込みはログインした陸連登録者だけ） ?>
    <h2 class="club-section-title">TOBG の大会</h2>
    <?php $races_guest = !$is_logged_in; require __DIR__ . '/../includes/club_races.php'; ?>
    <a class="club-join-btn" href="contact.php?membership=1">入会について問い合わせる</a>
    <?php if (!$is_logged_in): ?>
        <p class="club-note">会員の方は<a href="../mypage/login.php">ログイン</a>すると、所属クラブの画面が開きます。</p>
    <?php else: ?>
        <p class="club-note">所属クラブが登録されていません。所属の登録は管理者にお問い合わせください。</p>
    <?php endif; ?>
<?php else: ?>
    <header class="club-head">
        <p class="club-eyebrow">CLUB / <?php echo $e(MEMBER_CLUB_LABELS[$club]); ?></p>
        <h1 class="club-title">チームの今を、次の力に。</h1>
    </header>

    <?php // 切り替えスイッチ（ベージュの枠の中にボタンを均等に並べ、選択中は白く浮かせる） ?>
    <nav class="club-seg" aria-label="クラブの画面">
        <?php foreach ($club_tabs as $tab_key => $tab_label): ?>
            <a class="club-seg-btn<?php echo $tab_key === $tab ? ' is-active' : ''; ?>" href="<?php echo $e($club_url(['tab' => $tab_key])); ?>"<?php echo $tab_key === $tab ? ' aria-current="page"' : ''; ?>><?php echo $e($tab_label); ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($tab === 'menu'): ?>
        <?php if ($ogmrc_menu === null): ?>
            <p class="club-empty">メニューを読み込めませんでした。時間をおいて開き直してください。</p>
        <?php else: ?>
            <?php require __DIR__ . '/../includes/ogmrc_menu.php'; ?>
        <?php endif; ?>
    <?php elseif ($tab === 'sessions'): ?>
        <?php $session_can_post = true; require __DIR__ . '/../includes/club_sessions.php'; ?>
    <?php elseif ($tab === 'races'): ?>
        <?php require __DIR__ . '/../includes/club_races.php'; ?>
    <?php elseif ($tab === 'records'): ?>
        <?php require __DIR__ . '/../includes/club_records.php'; ?>
    <?php elseif ($tab === 'ranking'): ?>
        <?php
        $ranking_url = static fn(array $params): string => $club_url(['tab' => 'ranking'] + $params);
        $ranking_note = MEMBER_CLUB_LABELS[$club] . 'の会員の、みんなの練習日記に公開した記録で集計しています。';
        $ranking_viewer = $user_id;
        $ranking_base = '../';
        require __DIR__ . '/../includes/distance_ranking.php';
        ?>
    <?php endif; ?>
<?php endif; ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
