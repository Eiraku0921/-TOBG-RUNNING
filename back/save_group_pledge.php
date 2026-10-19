<?php
// みんなの目標: 今月の目標距離を宣言する（みんなのページの「目標を更新する」）。終わったら元のページに戻し、結果はトーストで出す
require_once __DIR__ . '/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/group_goal_lib.php';
require_once __DIR__ . '/redirect_lib.php';

$group_id = (int)($_POST['group_id'] ?? 0);
$return_to = safe_return_location((string)($_POST['return_to'] ?? ''), '../front/training_diary.php?group=' . $group_id . '&view=goals');

if (!isset($_SESSION['user_id'], $_SESSION['user_name'])) {
    header('Location: ../mypage/login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $return_to);
    exit;
}

try {
    ensure_group_pledges_table($pdo);
    save_group_pledge($pdo, $group_id, (int)$_SESSION['user_id'], (int)($_POST['pledge_km'] ?? 0));
    $_SESSION['club_flash'] = '今月の目標を宣言しました。';
} catch (PDOException $e) {
    error_log('save group pledge failed: ' . $e->getMessage());
    $_SESSION['club_error'] = '目標の保存に失敗しました。';
} catch (RuntimeException $e) {
    $_SESSION['club_error'] = $e->getMessage();
}

header('Location: ' . $return_to);
exit;
