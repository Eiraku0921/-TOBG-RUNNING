<?php
require_once __DIR__ . '/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/db_connect.php';
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

if (!isset($_SESSION['user_id'], $_SESSION['user_name'])) {
    header('Location: ../mypage/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../mypage/front_login.php#entry-history');
    exit;
}

// 取り消したあとに戻るページ（クラブ画面から取り消したときはクラブ画面へ。指定が無ければマイページの応募履歴）
require_once __DIR__ . '/redirect_lib.php';
$entry_return_location = safe_return_location((string)($_POST['return_to'] ?? ''), '../mypage/front_login.php#entry-history');

$user_name = (string)$_SESSION['user_name'];
$match_id = isset($_POST['match_id']) ? (int)$_POST['match_id'] : 0;

if ($match_id <= 0) {
    $_SESSION['entry_error'] = '削除対象の応募が不正です。';
    header('Location: ' . $entry_return_location);
    exit;
}

try {
    cleanup_expired_tournament_data($pdo);
    ensure_tournament_entry_deadline_column($pdo);
    ensure_tournament_deadline_lead_days_column($pdo);

    $find_stmt = $pdo->prepare(
        "SELECT
            m.id,
            m.user_name,
            t.event_date,
            DATEDIFF(DATE_SUB(COALESCE(t.entry_deadline, t.event_date), INTERVAL t.deadline_lead_days DAY), CURDATE()) AS days_left
         FROM matches m
         LEFT JOIN (
            SELECT event_name, MAX(event_date) AS event_date, MAX(entry_deadline) AS entry_deadline, MAX(COALESCE(deadline_lead_days, 3)) AS deadline_lead_days
            FROM tournaments
            GROUP BY event_name
         ) t ON t.event_name = m.match_title
         WHERE m.id = :id AND m.user_name = :user_name
         LIMIT 1"
    );
    $find_stmt->execute([
        ':id' => $match_id,
        ':user_name' => $user_name,
    ]);
    $entry = $find_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$entry) {
        $_SESSION['entry_error'] = '削除できる応募が見つかりませんでした。';
        header('Location: ' . $entry_return_location);
        exit;
    }

    $days_left = isset($entry['days_left']) ? (int)$entry['days_left'] : null;
    if ($days_left === null || $days_left < 0) {
        $_SESSION['entry_error'] = '変更締切を過ぎているため応募を削除できません。';
        header('Location: ' . $entry_return_location);
        exit;
    }

    $delete_stmt = $pdo->prepare('DELETE FROM matches WHERE id = :id AND user_name = :user_name');
    $delete_stmt->execute([
        ':id' => $match_id,
        ':user_name' => $user_name,
    ]);

    if ($delete_stmt->rowCount() < 1) {
        $_SESSION['entry_error'] = '応募削除に失敗しました。';
        header('Location: ' . $entry_return_location);
        exit;
    }
    $_SESSION['entry_flash'] = '申し込みを取り消しました。';
} catch (PDOException $e) {
    $_SESSION['entry_error'] = '応募削除中にエラーが発生しました。';
}

header('Location: ' . $entry_return_location);
exit;
