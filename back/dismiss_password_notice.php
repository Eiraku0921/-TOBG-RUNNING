<?php
// マイページの「初期パスワードのまま」の案内を、今後表示しないようにする
require_once __DIR__ . '/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/password_reset_lib.php';

if (!isset($_SESSION['user_id'], $_SESSION['user_name'])) {
    header('Location: ../mypage/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        dismiss_member_password_notice($pdo, (int)$_SESSION['user_id']);
    } catch (PDOException $e) {
        $_SESSION['profile_error'] = '設定の保存に失敗しました。時間を置いて再度お試しください。';
    }
}

header('Location: ../mypage/front_login.php');
exit;
