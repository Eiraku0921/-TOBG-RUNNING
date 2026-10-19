<?php
require_once __DIR__ . '/session_bootstrap.php';
tobg_start_session();

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/member_avatar_lib.php';
require_once __DIR__ . '/password_reset_lib.php';

if (!isset($_SESSION['user_id'], $_SESSION['user_name'])) {
    header('Location: ../mypage/login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../mypage/front_login.php');
    exit;
}

$email = trim((string)($_POST['email'] ?? ''));

if ($email === '') {
    $_SESSION['profile_error'] = 'メールアドレスを入力してください。';
} else if (mb_strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['profile_error'] = 'メールアドレスの形式が不正です。';
} else {
    try {
        ensure_member_email_column($pdo);

        if (is_email_used_by_other_member($pdo, $email, $user_id)) {
            $_SESSION['profile_error'] = 'このメールアドレスは他の会員が使用しています。';
        } else {
            $stmt = $pdo->prepare('UPDATE members SET email = :email WHERE id = :id');
            $stmt->execute([':email' => $email, ':id' => $user_id]);
            $_SESSION['profile_flash'] = 'メールアドレスを保存しました。';
        }
    } catch (Throwable $e) {
        $_SESSION['profile_error'] = 'メールアドレスの登録に失敗しました。';
    }
}

header('Location: ../mypage/front_login.php');
exit;
