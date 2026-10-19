<?php
// マイグループ画面から、グループのアイコン（写真）を設定・解除する。結果と最新のグループ一覧を JSON で返す
require_once __DIR__ . '/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/member_group_lib.php';

header('Content-Type: application/json; charset=utf-8');

function respond_group_icon(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id'], $_SESSION['user_name'])) {
    respond_group_icon(401, ['success' => false, 'message' => 'ログインが必要です。']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_group_icon(405, ['success' => false, 'message' => '不正なアクセスです。']);
}
// 写真が大きすぎてサーバーの上限を超えると、POST の中身が空で届く
if (empty($_POST) && empty($_FILES)) {
    respond_group_icon(200, ['success' => false, 'message' => '写真は5MB以内にしてください。']);
}

$user_id = (int)$_SESSION['user_id'];
$group_id = (int)($_POST['group_id'] ?? 0);

try {
    ensure_member_group_tables($pdo);
    if (($_POST['action'] ?? '') === 'remove') {
        remove_member_group_icon($pdo, $user_id, $group_id);
        $message = 'アイコンを外しました。';
    } else {
        set_member_group_icon($pdo, $user_id, $group_id, (array)($_FILES['icon'] ?? []));
        $message = 'アイコンを変更しました。';
    }
    respond_group_icon(200, ['success' => true, 'message' => $message, 'groups' => fetch_member_groups_for_user($pdo, $user_id)]);
} catch (PDOException $e) {
    // PDOException は RuntimeException の仲間なので先に捕まえ、DB のエラー文は画面に出さない
    error_log('member_group_icon failed: ' . $e->getMessage());
    respond_group_icon(500, ['success' => false, 'message' => '保存に失敗しました。時間を置いて再度お試しください。']);
} catch (RuntimeException $e) {
    respond_group_icon(200, ['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('member_group_icon failed: ' . $e->getMessage());
    respond_group_icon(500, ['success' => false, 'message' => '保存に失敗しました。時間を置いて再度お試しください。']);
}
