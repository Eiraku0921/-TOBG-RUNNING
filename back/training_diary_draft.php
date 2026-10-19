<?php
// 練習日記の下書き（登録シートから fetch で呼ぶ。結果は JSON）
//   action=save    フォームの値で下書きを上書き保存する
//   action=delete  下書きを消す
require_once __DIR__ . '/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/training_diary_lib.php';
require_once __DIR__ . '/training_diary_draft_lib.php';

header('Content-Type: application/json; charset=UTF-8');

$respond = static function (int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
};

if (!isset($_SESSION['user_id'], $_SESSION['user_name'])) {
    $respond(401, ['ok' => false, 'message' => 'ログインしてください。']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(405, ['ok' => false, 'message' => '操作の指定が正しくありません。']);
}

$user_id = (int)$_SESSION['user_id'];
$action = (string)($_POST['action'] ?? '');
try {
    if ($action === 'save') {
        save_training_diary_draft($pdo, $user_id, $_POST);
        $respond(200, ['ok' => true, 'message' => '下書きを保存しました。']);
    }
    if ($action === 'delete') {
        delete_training_diary_draft($pdo, $user_id);
        $respond(200, ['ok' => true, 'message' => '下書きを削除しました。']);
    }
    $respond(400, ['ok' => false, 'message' => '操作の指定が正しくありません。']);
} catch (PDOException $e) {
    error_log('training diary draft failed: ' . $e->getMessage());
    $respond(500, ['ok' => false, 'message' => '下書きの保存に失敗しました。時間をおいてもう一度お試しください。']);
}
