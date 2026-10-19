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
$comment_text = trim((string)($_POST['comment_text'] ?? ''));
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

if ($gallery_id <= 0 || $comment_text === '') {
    $_SESSION['gallery_error'] = 'コメントを入力してください。';
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

    $insert_stmt = $pdo->prepare(
        'INSERT INTO gallery_comments (gallery_id, user_id, comment_text) VALUES (:gallery_id, :user_id, :comment_text)'
    );
    $insert_stmt->execute([
        ':gallery_id' => $gallery_id,
        ':user_id' => $user_id,
        ':comment_text' => $comment_text,
    ]);

    $_SESSION['gallery_flash'] = 'コメントを追加しました。';
} catch (PDOException $e) {
    $_SESSION['gallery_error'] = 'コメントの保存に失敗しました。';
}

header('Location: ' . $return_path);
exit;
