<?php
// 投稿済みの練習日記の公開範囲（みんなの練習日記・グループ）を変更する。自分の日記だけ変更できる
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
$is_public = (int)($_POST['is_public'] ?? 0) === 1 ? 1 : 0;
$posted_group_ids = (array)($_POST['group_ids'] ?? []);

if ($diary_id <= 0) {
    $_SESSION['training_error'] = '変更する投稿が不正です。';
    header('Location: ../mypage/front_login.php#training-diary');
    exit;
}

// 変更後も、その日記の詳細を開いた状態で戻す
$return_path = '../mypage/front_login.php?open_diary=' . $diary_id . '#training-diary';

try {
    ensure_training_diary_table($pdo);
    ensure_member_group_tables($pdo);

    $pdo->beginTransaction();

    // 自分の日記か確かめつつ、ほかの更新と重ならないように行をロックする
    $find_stmt = $pdo->prepare('SELECT id FROM training_diaries WHERE id = :id AND user_id = :user_id LIMIT 1 FOR UPDATE');
    $find_stmt->execute([':id' => $diary_id, ':user_id' => $user_id]);
    if (!$find_stmt->fetchColumn()) {
        $pdo->rollBack();
        $_SESSION['training_error'] = '変更できる投稿が見つかりませんでした。';
        header('Location: ../mypage/front_login.php#training-diary');
        exit;
    }

    $pdo->prepare('UPDATE training_diaries SET is_public = :is_public WHERE id = :id AND user_id = :user_id')
        ->execute([':is_public' => $is_public, ':id' => $diary_id, ':user_id' => $user_id]);

    replace_training_diary_groups($pdo, $diary_id, $user_id, $posted_group_ids);

    $pdo->commit();
    $_SESSION['training_flash'] = '公開範囲を変更しました。';
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('update diary scope failed: ' . $e->getMessage());
    $_SESSION['training_error'] = '公開範囲の変更に失敗しました。';
}

header('Location: ' . $return_path);
exit;
