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
$gallery_id = isset($_POST['gallery_id']) ? (int)$_POST['gallery_id'] : 0;
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

if ($gallery_id <= 0) {
    $_SESSION['gallery_error'] = 'いいね対象が不正です。';
    header('Location: ' . $return_path);
    exit;
}

try {
    ensure_gallery_table($pdo);
    ensure_gallery_interaction_tables($pdo);

    $find_stmt = $pdo->prepare('SELECT id FROM gallery_posts WHERE id = :id LIMIT 1');
    $find_stmt->execute([':id' => $gallery_id]);
    if (!$find_stmt->fetchColumn()) {
        $_SESSION['gallery_error'] = '対象の投稿が見つかりませんでした。';
        header('Location: ' . $return_path);
        exit;
    }

    $exists_stmt = $pdo->prepare('SELECT id FROM gallery_likes WHERE gallery_id = :gallery_id AND user_id = :user_id LIMIT 1');
    $exists_stmt->execute([
        ':gallery_id' => $gallery_id,
        ':user_id' => $user_id,
    ]);

    $existing_like_id = $exists_stmt->fetchColumn();

    if ($existing_like_id) {
        $delete_stmt = $pdo->prepare('DELETE FROM gallery_likes WHERE id = :id AND user_id = :user_id');
        $delete_stmt->execute([
            ':id' => (int)$existing_like_id,
            ':user_id' => $user_id,
        ]);
        $_SESSION['gallery_flash'] = 'いいねを取り消しました。';
    } else {
        $insert_stmt = $pdo->prepare('INSERT INTO gallery_likes (gallery_id, user_id) VALUES (:gallery_id, :user_id)');
        $insert_stmt->execute([
            ':gallery_id' => $gallery_id,
            ':user_id' => $user_id,
        ]);
        $_SESSION['gallery_flash'] = 'いいねしました。';
    }
} catch (PDOException $e) {
    $_SESSION['gallery_error'] = 'いいねの更新に失敗しました。';
}

header('Location: ' . $return_path);
exit;
