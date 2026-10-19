<?php
require_once __DIR__ . '/session_bootstrap.php';
tobg_start_session();

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/member_avatar_lib.php';

if (!isset($_SESSION['user_id'], $_SESSION['user_name'])) {
    header('Location: ../mypage/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../mypage/front_login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

try {
    ensure_member_avatar_column($pdo);

    $old_stmt = $pdo->prepare('SELECT avatar_image_path FROM members WHERE id = :id LIMIT 1');
    $old_stmt->execute([':id' => $user_id]);
    $old_path = normalize_member_avatar_path((string)($old_stmt->fetchColumn() ?: ''));

    $update_stmt = $pdo->prepare('UPDATE members SET avatar_image_path = NULL WHERE id = :id');
    $update_stmt->execute([':id' => $user_id]);

    $_SESSION['user_avatar_path'] = '';
    $_SESSION['profile_flash'] = 'プロフィールアイコンを初期状態に戻しました。';

    if ($old_path !== '' && strpos($old_path, 'uploads/member_icons/') === 0) {
        $old_abs = __DIR__ . '/../' . $old_path;
        if (is_file($old_abs)) {
            @unlink($old_abs);
        }
    }
} catch (Throwable $e) {
    $_SESSION['profile_error'] = 'プロフィールアイコンの削除に失敗しました。';
}

header('Location: ../mypage/front_login.php');
exit;
