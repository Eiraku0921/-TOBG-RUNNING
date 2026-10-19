<?php
// 陸連登録者の大会の操作（クラブ画面の大会タブから送る）。終わったら元のページに戻し、結果はヘッダーのトーストで出す
//   action=add             大会情報を追加する（「＋ 大会情報を追加」の窓）
//   action=update          大会情報を編集する（「⋯」→ 編集。自分で申し込み管理を行う大会だけ）
//   action=request_delete  削除を管理者に申請する（「⋯」→ 削除を申請。同上）
//   action=entry_status    申し込みを承認・未承認・閲覧にする（「⋯」→ 申し込みを承認。同上）
// 処理は back/tournament_add_lib.php
require_once __DIR__ . '/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/tournament_add_lib.php';
require_once __DIR__ . '/redirect_lib.php';

$return_to = safe_return_location((string)($_POST['return_to'] ?? ''), '../front/club.php?tab=races');

if (!isset($_SESSION['user_id'], $_SESSION['user_name'])) {
    header('Location: ../mypage/login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $return_to);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$action = (string)($_POST['action'] ?? 'add');
try {
    if ($action === 'add') {
        $added = add_member_tournament($pdo, $user_id, $_POST);
        $_SESSION['club_flash'] = member_tournament_added_message($added['self_managed']);
    } elseif ($action === 'update') {
        update_member_tournament($pdo, $user_id, $_POST);
        $_SESSION['club_flash'] = '大会情報を更新しました。';
    } elseif ($action === 'request_delete') {
        request_member_tournament_deletion($pdo, $user_id, (string)$_SESSION['user_name'], (int)($_POST['tournament_id'] ?? 0));
        $_SESSION['club_flash'] = '大会の削除を管理者に申し込みました。管理者の確認後に削除されます。';
    } elseif ($action === 'entry_status') {
        update_owned_entry_status($pdo, $user_id, (int)($_POST['match_id'] ?? 0), trim((string)($_POST['status'] ?? '')));
        $_SESSION['club_flash'] = '申し込みの状態を更新しました。';
    } else {
        throw new RuntimeException('操作の指定が正しくありません。');
    }
} catch (PDOException $e) {
    error_log('save tournament failed: ' . $e->getMessage());
    $_SESSION['club_error'] = '保存に失敗しました。時間をおいてもう一度お試しください。';
} catch (RuntimeException $e) {
    $_SESSION['club_error'] = $e->getMessage();
}

header('Location: ' . $return_to);
exit;
