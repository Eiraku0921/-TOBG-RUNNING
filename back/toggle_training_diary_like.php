<?php
require_once __DIR__ . '/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/training_diary_lib.php';
require_once __DIR__ . '/member_group_lib.php';

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
$diary_id = isset($_POST['diary_id']) ? (int)$_POST['diary_id'] : 0;
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

function respond_like_error(bool $is_ajax, string $message, string $return_path): void
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

if ($diary_id <= 0) {
    respond_like_error($is_ajax, 'いいね対象が不正です。', $return_path);
}

try {
    ensure_training_diary_table($pdo);
    ensure_training_diary_interaction_tables($pdo);

    $find_stmt = $pdo->prepare('SELECT id FROM training_diaries WHERE id = :id LIMIT 1');
    $find_stmt->execute([':id' => $diary_id]);

    if (!$find_stmt->fetchColumn()) {
        respond_like_error($is_ajax, '対象の日記が見つかりませんでした。', $return_path);
    }
    // グループ限定の日記は、見られる人（本人・グループのメンバー）だけがいいねできる
    ensure_member_group_tables($pdo);
    if (!member_can_view_diary($pdo, $user_id, $diary_id)) {
        respond_like_error($is_ajax, '対象の日記が見つかりませんでした。', $return_path);
    }

    $exists_stmt = $pdo->prepare('SELECT id FROM training_diary_likes WHERE diary_id = :diary_id AND user_id = :user_id LIMIT 1');
    $exists_stmt->execute([
        ':diary_id' => $diary_id,
        ':user_id' => $user_id,
    ]);

    $existing_like_id = $exists_stmt->fetchColumn();
    $liked = false;

    if ($existing_like_id) {
        $delete_stmt = $pdo->prepare('DELETE FROM training_diary_likes WHERE id = :id AND user_id = :user_id');
        $delete_stmt->execute([
            ':id' => (int)$existing_like_id,
            ':user_id' => $user_id,
        ]);
        $liked = false;
        $_SESSION['training_flash'] = 'いいねを取り消しました。';
    } else {
        $insert_stmt = $pdo->prepare('INSERT INTO training_diary_likes (diary_id, user_id) VALUES (:diary_id, :user_id)');
        $insert_stmt->execute([
            ':diary_id' => $diary_id,
            ':user_id' => $user_id,
        ]);
        $liked = true;
        $_SESSION['training_flash'] = 'いいねしました。';
    }

    $count_stmt = $pdo->prepare('SELECT COUNT(*) FROM training_diary_likes WHERE diary_id = :diary_id');
    $count_stmt->execute([':diary_id' => $diary_id]);
    $like_count = (int)$count_stmt->fetchColumn();

    if ($is_ajax) {
        unset($_SESSION['training_flash']);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'liked' => $liked, 'like_count' => $like_count]);
        exit;
    }
} catch (PDOException $e) {
    respond_like_error($is_ajax, 'いいねの更新に失敗しました。', $return_path);
}

header('Location: ' . $return_path);
exit;