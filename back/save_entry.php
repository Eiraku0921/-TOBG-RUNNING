<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/entry_event_lib.php';
$cleanup_lib = __DIR__ . '/tournament_cleanup_lib.php';
if (file_exists($cleanup_lib)) {
    require_once $cleanup_lib;
}
if (!function_exists('cleanup_expired_tournament_data')) {
    function cleanup_expired_tournament_data(PDO $pdo): void
    {
        $pdo->exec("DELETE m FROM matches m INNER JOIN (SELECT event_name, MAX(event_date) AS event_date FROM tournaments GROUP BY event_name) t ON t.event_name = m.match_title WHERE t.event_date < CURDATE()");
        $pdo->exec("DELETE FROM tournaments WHERE event_date < CURDATE()");
    }
}

function ensure_tournament_entry_deadline_column(PDO $pdo): void
{
    $stmt = $pdo->query("SHOW COLUMNS FROM tournaments LIKE 'entry_deadline'");
    $has_entry_deadline = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$has_entry_deadline) {
        $pdo->exec("ALTER TABLE tournaments ADD COLUMN entry_deadline DATE NULL AFTER event_date");
    }
}

function ensure_tournament_deadline_lead_days_column(PDO $pdo): void
{
    $stmt = $pdo->query("SHOW COLUMNS FROM tournaments LIKE 'deadline_lead_days'");
    $has_lead_days = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$has_lead_days) {
        $pdo->exec("ALTER TABLE tournaments ADD COLUMN deadline_lead_days TINYINT UNSIGNED NOT NULL DEFAULT 3 AFTER entry_deadline");
    }
}

if (session_status() === PHP_SESSION_NONE) {
    tobg_start_session();
}

if (!isset($_SESSION['user_id'], $_SESSION['user_name'])) {
    header('Location: ../mypage/login.php');
    exit;
}

function find_first_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        cleanup_expired_tournament_data($pdo);
        ensure_tournament_entry_deadline_column($pdo);
        ensure_tournament_deadline_lead_days_column($pdo);
        if (function_exists('ensure_tournament_owner_columns')) {
            ensure_tournament_owner_columns($pdo);
        }

        $col_stmt = $pdo->query('SHOW COLUMNS FROM members');
        $member_columns = array_column($col_stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $jaaf_id_col = find_first_column($member_columns, ['jaaf_id', 'jaafid', 'jaaf_no']);
        $number_plate_col = find_first_column($member_columns, ['number_plate', 'bib_number', 'zekken_no']);

        if ($jaaf_id_col !== null && $number_plate_col !== null) {
            $profile_sql = "SELECT {$jaaf_id_col} AS jaaf_id_value, {$number_plate_col} AS number_plate_value FROM members WHERE id = ? LIMIT 1";
            $profile_stmt = $pdo->prepare($profile_sql);
            $profile_stmt->execute([(int)$_SESSION['user_id']]);
            $profile = $profile_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            if (trim((string)($profile['jaaf_id_value'] ?? '')) === '0000' && trim((string)($profile['number_plate_value'] ?? '')) === '0000') {
                $_SESSION['entry_error'] = 'JAAF未登録の会員は試合申し込みを利用できません。';
                header('Location: ../mypage/front_login.php#entry-history');
                exit;
            }
        }

        $match_title = trim((string)($_POST['match_title'] ?? ''));
        $tournament_stmt = $pdo->prepare(
            "SELECT
                id,
                event_date,
                DATE_SUB(COALESCE(entry_deadline, event_date), INTERVAL COALESCE(deadline_lead_days, 3) DAY) AS member_deadline,
                owner_user_id,
                self_managed,
                owner_notify_email
             FROM tournaments
             WHERE event_name = :event_name
             ORDER BY event_date DESC, id DESC
             LIMIT 1"
        );
        $tournament_stmt->execute([':event_name' => $match_title]);
        $tournament = $tournament_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        if (empty($tournament) || empty($tournament['member_deadline']) || strtotime((string)$tournament['member_deadline']) < strtotime(date('Y-m-d'))) {
            $_SESSION['entry_error'] = 'この大会は受付期間外のため申し込みできません。';
            header('Location: ../front/club.php?tab=races');
            exit;
        }
    } catch (PDOException $e) {
        $_SESSION['entry_error'] = '申し込み権限の確認に失敗しました。';
        header('Location: ../mypage/front_login.php#entry-history');
        exit;
    }

    // 種目は一覧から、応募記録は種目の形式どおりに選ばれているか確かめる（違えば申し込みフォームに戻して理由を出す）
    try {
        $entry_events = validate_entry_events($_POST);
    } catch (RuntimeException $e) {
        $_SESSION['entry_error'] = $e->getMessage();
        header('Location: ../front/entry_form.php?tournament_id=' . (int)$tournament['id']);
        exit;
    }
    $_POST = array_merge($_POST, array_map(static fn(?string $value): string => (string)$value, $entry_events));

    $event_name2 = trim($_POST['event_name2'] ?? '');
    $pb_time2 = trim($_POST['pb_time2'] ?? '');
    $target_time2 = trim($_POST['target_time2'] ?? '');
    $event_name3 = trim($_POST['event_name3'] ?? '');
    $pb_time3 = trim($_POST['pb_time3'] ?? '');
    $target_time3 = trim($_POST['target_time3'] ?? '');

    // status と created_at は応募者入力ではなくシステム側で固定管理する
    $sql = "INSERT INTO matches (
                match_title,
                jaaf_id,
                number_plate,
                user_name,
                event_name1,
                pb_time1,
                target_time1,
                event_name2,
                pb_time2,
                target_time2,
                event_name3,
                pb_time3,
                target_time3,
                status,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '未承認', NOW())";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $match_title,
        $_POST['jaaf_id'] ?? '',
        $_POST['number_plate'] ?? '',
        (string)$_SESSION['user_name'],
        $_POST['event_name1'] ?? '',
        $_POST['pb_time1'] ?? '',
        $_POST['target_time1'] ?? '',
        $event_name2 !== '' ? $event_name2 : null,
        $pb_time2 !== '' ? $pb_time2 : null,
        $target_time2 !== '' ? $target_time2 : null,
        $event_name3 !== '' ? $event_name3 : null,
        $pb_time3 !== '' ? $pb_time3 : null,
        $target_time3 !== '' ? $target_time3 : null,
    ]);

    require_once __DIR__ . '/mail_lib.php';

    $member_deadline_text = !empty($tournament['member_deadline'])
        ? date('Y年m月d日', strtotime((string)$tournament['member_deadline']))
        : '未設定';

    $notify_body = (string)$_SESSION['user_name'] . "さんから試合の申し込みが届きました！！\n"
        . $match_title . "の締切期限は" . $member_deadline_text . "です\n\n"
        . "種目1: " . ($_POST['event_name1'] ?? '') . "（" . ($_POST['pb_time1'] ?? '') . "）\n";
    if ($event_name2 !== '') {
        $notify_body .= "種目2: " . $event_name2 . "（" . $pb_time2 . "）\n";
    }
    if ($event_name3 !== '') {
        $notify_body .= "種目3: " . $event_name3 . "（" . $pb_time3 . "）\n";
    }
    $notify_body .= "\nマイページの管理画面から確認してください。\n\nhttps://tobg.sadist.jp/";

    send_admin_notification_email('【TOBG】新しい試合参加申請', $notify_body);

    if ((int)($tournament['self_managed'] ?? 0) === 1) {
        $owner_notify_email = trim((string)($tournament['owner_notify_email'] ?? ''));
        if ($owner_notify_email !== '') {
            send_notification_email($owner_notify_email, '【TOBG】新しい試合参加申請', $notify_body);
        }
    }

    // 登録後は完了画面へ遷移
    $completed_match_title = urlencode((string)($_POST['match_title'] ?? ''));
    header('Location: ../front/entry_complete.php?match_title=' . $completed_match_title);
    exit;
}
?>