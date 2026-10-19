<?php
// マイグループ画面（ホームのモーダル）からの操作を受け付け、結果と最新のグループ一覧を JSON で返す
require_once __DIR__ . '/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/member_group_lib.php';

header('Content-Type: application/json; charset=utf-8');

function respond_member_group(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id'], $_SESSION['user_name'])) {
    respond_member_group(401, ['success' => false, 'message' => 'ログインが必要です。']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_member_group(405, ['success' => false, 'message' => '不正なアクセスです。']);
}

$request = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($request)) {
    respond_member_group(400, ['success' => false, 'message' => '送られてきた内容が正しくありません。']);
}

$user_id = (int)$_SESSION['user_id'];
$action = (string)($request['action'] ?? '');
$group_id = (int)($request['group_id'] ?? 0);
$result = ['success' => true];

try {
    ensure_member_group_tables($pdo);

    if ($action === 'list') {
        // 一覧だけ返す
    } elseif ($action === 'create') {
        $result['group_id'] = create_member_group($pdo, $user_id, (string)($request['name'] ?? ''));
        $result['message'] = 'グループを作成しました。';
    } elseif ($action === 'invite') {
        // 招待画面を開いたとき。期限が切れていれば新しいコードを発行する
        member_group_require_owned_active($pdo, $group_id, $user_id);
        member_group_refresh_invite_code($pdo, $group_id);
        $result['group_id'] = $group_id;
    } elseif ($action === 'join') {
        $group_name = join_member_group_by_code($pdo, $user_id, (string)($request['code'] ?? ''));
        $result['message'] = '「' . $group_name . '」に参加しました。';
    } elseif ($action === 'rename') {
        rename_member_group($pdo, $user_id, $group_id, (string)($request['name'] ?? ''));
        $result['message'] = 'グループ名を変更しました。';
    } elseif ($action === 'leave') {
        leave_member_group($pdo, $user_id, $group_id);
        $result['message'] = 'グループから脱退しました。';
    } elseif ($action === 'request_delete') {
        request_member_group_deletion($pdo, $user_id, $group_id);
        $result['message'] = '削除を申請しました。管理者の承認をお待ちください。';
    } else {
        respond_member_group(400, ['success' => false, 'message' => '操作の指定が正しくありません。']);
    }

    $result['groups'] = fetch_member_groups_for_user($pdo, $user_id);
} catch (PDOException $e) {
    // PDOException は RuntimeException の仲間なので先に捕まえ、DB のエラー文は画面に出さない
    error_log('member_group_action failed: ' . $e->getMessage());
    respond_member_group(500, ['success' => false, 'message' => '処理に失敗しました。時間を置いて再度お試しください。']);
} catch (RuntimeException $e) {
    // 入力の間違いなど、利用者に見せてよい理由
    respond_member_group(200, ['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('member_group_action failed: ' . $e->getMessage());
    respond_member_group(500, ['success' => false, 'message' => '処理に失敗しました。時間を置いて再度お試しください。']);
}

respond_member_group(200, $result);
