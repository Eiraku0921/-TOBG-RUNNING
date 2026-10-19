<?php
require_once __DIR__ . '/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/training_diary_lib.php';

$is_ajax = (($_POST['ajax'] ?? '') === '1');

if (!isset($_SESSION['user_id'], $_SESSION['user_name'])) {
    if ($is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'ログインが必要です。']);
        exit;
    }
    header('Location: ../mypage/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../front/training_diary.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$is_admin = (string)($_SESSION['user_role'] ?? '') === 'admin';
$diary_id = isset($_POST['diary_id']) ? (int)$_POST['diary_id'] : 0;
$comment_id = isset($_POST['comment_id']) ? (int)$_POST['comment_id'] : 0;
$return_url = trim((string)($_POST['return_url'] ?? ''));

if ($return_url === '' && isset($_SERVER['HTTP_REFERER'])) {
    $return_url = (string)$_SERVER['HTTP_REFERER'];
}

$return_path = '../front/training_diary.php#diary-' . $diary_id;
if ($return_url !== '') {
    $parsed = parse_url($return_url);
    $candidate = (string)(($parsed['path'] ?? '') . (isset($parsed['query']) ? '?' . $parsed['query'] : '') . (isset($parsed['fragment']) ? '#' . $parsed['fragment'] : ''));
    if ($candidate !== '' && str_starts_with($candidate, '/TOBG/')) {
        $return_path = '..' . substr($candidate, strlen('/TOBG'));
    } elseif ($candidate !== '' && str_starts_with($candidate, '/front/')) {
        $return_path = '..' . $candidate;
    } elseif ($candidate !== '' && str_starts_with($candidate, '/mypage/')) {
        $return_path = '..' . $candidate;
    }
}

function respond_delete_comment_error(bool $is_ajax, string $message, string $return_path): void
{
    if ($is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }
    $_SESSION['training_error'] = $message;
    header('Location: ' . $return_path);
    exit;
}

if ($diary_id <= 0 || $comment_id <= 0) {
    respond_delete_comment_error($is_ajax, '削除対象が不正です。', $return_path);
}

try {
    ensure_training_diary_table($pdo);
    ensure_training_diary_interaction_tables($pdo);

    if ($is_admin) {
        $delete_stmt = $pdo->prepare(
            'DELETE FROM training_diary_comments WHERE id = :id AND diary_id = :diary_id'
        );
        $delete_stmt->execute([
            ':id' => $comment_id,
            ':diary_id' => $diary_id,
        ]);
    } else {
        $delete_stmt = $pdo->prepare(
            'DELETE FROM training_diary_comments WHERE id = :id AND diary_id = :diary_id AND user_id = :user_id'
        );
        $delete_stmt->execute([
            ':id' => $comment_id,
            ':diary_id' => $diary_id,
            ':user_id' => $user_id,
        ]);
    }

    if ($delete_stmt->rowCount() < 1) {
        $message = $is_admin ? '削除できるコメントが見つかりませんでした。' : '自分のコメントのみ削除できます。';
        respond_delete_comment_error($is_ajax, $message, $return_path);
    }

    // 消したコメントの通知（日記の持ち主のベル）も消す
    require_once __DIR__ . '/notification_lib.php';
    delete_notification_for_source($pdo, NOTIFICATION_SOURCE_DIARY_COMMENT, $comment_id);

    if ($is_ajax) {
        $count_stmt = $pdo->prepare('SELECT COUNT(*) FROM training_diary_comments WHERE diary_id = :diary_id');
        $count_stmt->execute([':diary_id' => $diary_id]);
        $comment_count = (int)$count_stmt->fetchColumn();

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'comment_id' => $comment_id, 'comment_count' => $comment_count]);
        exit;
    }

    $_SESSION['training_flash'] = 'コメントを削除しました。';
} catch (PDOException $e) {
    respond_delete_comment_error($is_ajax, 'コメント削除に失敗しました。', $return_path);
}

header('Location: ' . $return_path);
exit;