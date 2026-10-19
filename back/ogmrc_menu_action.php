<?php
// OGMRC のメニューの入力（コーチ・管理者だけ）。終わったらクラブ画面のメニューに戻し、結果はトーストで出す
//   action=note             コーチのひとことを配信する
//   action=save_practice    練習予定を追加する（practice_id があれば書き換える）
//   action=delete_practice  練習予定を消す
require_once __DIR__ . '/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/ogmrc_menu_lib.php';

$return_to = '../front/club.php?tab=menu';
if (!isset($_SESSION['user_id'], $_SESSION['user_name'])) {
    header('Location: ../mypage/login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $return_to);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
try {
    $role_stmt = $pdo->prepare('SELECT role FROM members WHERE id = :id LIMIT 1');
    $role_stmt->execute([':id' => $user_id]);
    if (!ogmrc_menu_can_edit((string)$role_stmt->fetchColumn())) {
        throw new RuntimeException('メニューを入力できるのは、コーチと管理者だけです。');
    }
    ensure_ogmrc_menu_tables($pdo);

    $action = (string)($_POST['action'] ?? '');
    if ($action === 'note') {
        add_ogmrc_note($pdo, $user_id, (string)($_POST['body'] ?? ''));
        $_SESSION['club_flash'] = 'ひとことを配信しました。';
    } elseif ($action === 'save_practice') {
        $practice_id = (int)($_POST['practice_id'] ?? 0);
        save_ogmrc_practice($pdo, $user_id, $_POST, $practice_id);
        $_SESSION['club_flash'] = $practice_id > 0 ? '練習予定を更新しました。' : '練習予定を追加しました。';
    } elseif ($action === 'delete_practice') {
        delete_ogmrc_practice($pdo, (int)($_POST['practice_id'] ?? 0));
        $_SESSION['club_flash'] = '練習予定を削除しました。';
    } else {
        throw new RuntimeException('操作の指定が正しくありません。');
    }
} catch (PDOException $e) {
    error_log('ogmrc menu action failed: ' . $e->getMessage());
    $_SESSION['club_error'] = '保存に失敗しました。時間をおいてもう一度お試しください。';
} catch (RuntimeException $e) {
    $_SESSION['club_error'] = $e->getMessage();
}

header('Location: ' . $return_to);
exit;
