<?php
require_once __DIR__ . '/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/gallery_lib.php';

if (!isset($_SESSION['user_id'], $_SESSION['user_name'])) {
    header('Location: ../mypage/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../front/gallery.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$is_admin = (string)($_SESSION['user_role'] ?? '') === 'admin';
$gallery_id = isset($_POST['gallery_id']) ? (int)$_POST['gallery_id'] : 0;
$comment_id = isset($_POST['comment_id']) ? (int)$_POST['comment_id'] : 0;
$return_url = trim((string)($_POST['return_url'] ?? ''));

if ($return_url === '' && isset($_SERVER['HTTP_REFERER'])) {
    $return_url = (string)$_SERVER['HTTP_REFERER'];
}

$return_path = '../front/gallery.php#gallery-' . $gallery_id;
if ($return_url !== '') {
    $parsed = parse_url($return_url);
    $candidate = (string)(($parsed['path'] ?? '') . (isset($parsed['query']) ? '?' . $parsed['query'] : '') . (isset($parsed['fragment']) ? '#' . $parsed['fragment'] : ''));
    if ($candidate !== '' && str_starts_with($candidate, '/TOBG/')) {
        $return_path = '..' . substr($candidate, strlen('/TOBG'));
    } elseif ($candidate !== '' && str_starts_with($candidate, '/front/')) {
        $return_path = '..' . $candidate;
    }
}

if ($gallery_id <= 0 || $comment_id <= 0) {
    $_SESSION['gallery_error'] = '削除対象が不正です。';
    header('Location: ' . $return_path);
    exit;
}

try {
    ensure_gallery_table($pdo);
    ensure_gallery_interaction_tables($pdo);

    if ($is_admin) {
        $delete_stmt = $pdo->prepare('DELETE FROM gallery_comments WHERE id = :id AND gallery_id = :gallery_id');
        $delete_stmt->execute([
            ':id' => $comment_id,
            ':gallery_id' => $gallery_id,
        ]);
    } else {
        $delete_stmt = $pdo->prepare('DELETE FROM gallery_comments WHERE id = :id AND gallery_id = :gallery_id AND user_id = :user_id');
        $delete_stmt->execute([
            ':id' => $comment_id,
            ':gallery_id' => $gallery_id,
            ':user_id' => $user_id,
        ]);
    }

    if ($delete_stmt->rowCount() < 1) {
        $_SESSION['gallery_error'] = $is_admin ? '削除できるコメントが見つかりませんでした。' : '自分のコメントのみ削除できます。';
    } else {
        $_SESSION['gallery_flash'] = 'コメントを削除しました。';
    }
} catch (PDOException $e) {
    $_SESSION['gallery_error'] = 'コメント削除に失敗しました。';
}

header('Location: ' . $return_path);
exit;
