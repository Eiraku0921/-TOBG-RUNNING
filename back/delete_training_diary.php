<?php
require_once __DIR__ . '/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/training_diary_lib.php';
require_once __DIR__ . '/member_group_lib.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../mypage/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../mypage/front_login.php#training-diary');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$diary_id = isset($_POST['diary_id']) ? (int)$_POST['diary_id'] : 0;

if ($diary_id <= 0) {
    $_SESSION['training_error'] = '削除対象が不正です。';
    header('Location: ../mypage/front_login.php#training-diary');
    exit;
}

try {
    ensure_training_diary_table($pdo);
    ensure_training_diary_weight_sets_table($pdo);
    ensure_training_diary_running_tables($pdo);
    ensure_training_diary_race_entries_table($pdo);
    ensure_member_group_tables($pdo);
    cleanup_old_training_diaries($pdo);

    $pdo->beginTransaction();

    $find_stmt = $pdo->prepare('SELECT image_path, video_path FROM training_diaries WHERE id = :id AND user_id = :user_id LIMIT 1');
    $find_stmt->execute([
        ':id' => $diary_id,
        ':user_id' => $user_id,
    ]);
    $row = $find_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $_SESSION['training_error'] = '削除できる投稿が見つかりませんでした。';
        header('Location: ../mypage/front_login.php#training-diary');
        exit;
    }

    $delete_stmt = $pdo->prepare('DELETE FROM training_diaries WHERE id = :id AND user_id = :user_id');
    $delete_stmt->execute([
        ':id' => $diary_id,
        ':user_id' => $user_id,
    ]);

    if ($delete_stmt->rowCount() < 1) {
        $pdo->rollBack();
        $_SESSION['training_error'] = 'DB削除に失敗しました。';
        header('Location: ../mypage/front_login.php#training-diary');
        exit;
    }

    $delete_weight_sets_stmt = $pdo->prepare('DELETE FROM training_diary_weight_sets WHERE diary_id = :diary_id');
    $delete_weight_sets_stmt->execute([':diary_id' => $diary_id]);

    $delete_running_laps_stmt = $pdo->prepare(
        'DELETE FROM training_diary_running_laps WHERE running_set_id IN (SELECT id FROM training_diary_running_sets WHERE diary_id = :diary_id)'
    );
    $delete_running_laps_stmt->execute([':diary_id' => $diary_id]);

    $delete_running_sets_stmt = $pdo->prepare('DELETE FROM training_diary_running_sets WHERE diary_id = :diary_id');
    $delete_running_sets_stmt->execute([':diary_id' => $diary_id]);

    $delete_race_entries_stmt = $pdo->prepare('DELETE FROM training_diary_race_entries WHERE diary_id = :diary_id');
    $delete_race_entries_stmt->execute([':diary_id' => $diary_id]);

    // どのグループに公開していたかの紐付けも消す
    $pdo->prepare('DELETE FROM training_diary_groups WHERE diary_id = :diary_id')->execute([':diary_id' => $diary_id]);

    $pdo->commit();

    $upload_root = realpath(__DIR__ . '/../uploads/training_diary');
    foreach (['image_path', 'video_path'] as $path_key) {
        if (!empty($row[$path_key])) {
            $file_full_path = realpath(__DIR__ . '/../' . ltrim((string)$row[$path_key], '/'));
            if ($file_full_path && $upload_root && strpos($file_full_path, $upload_root) === 0 && is_file($file_full_path)) {
                @unlink($file_full_path);
            }
        }
    }

    $_SESSION['training_flash'] = '練習日記を削除しました。';
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['training_error'] = '削除中にエラーが発生しました。';
}

header('Location: ../mypage/front_login.php#training-diary');
exit;
