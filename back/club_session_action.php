<?php
// 合同練習の操作（クラブ画面から送る）。終わったら元のページに戻し、結果はヘッダーのトーストで出す（club_flash / club_error）
//   action=create  募集する（主催）           action=join    参加する（ひとこと任意）
//   action=leave   参加を取り消す              action=cancel  募集を中止する（主催者だけ。参加者に知らせる）
//   action=message 参加者に連絡する（主催者だけ）
//   action=delete  合同練習を削除する（主催者と管理者だけ。中止していなければ参加者に知らせる）
require_once __DIR__ . '/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/club_session_lib.php';
require_once __DIR__ . '/redirect_lib.php';

$return_to = safe_return_location((string)($_POST['return_to'] ?? ''), '../front/club.php?tab=sessions');
$finish = static function (string $key, string $message) use ($return_to): void {
    $_SESSION[$key] = $message;
    header('Location: ' . $return_to);
    exit;
};

if (!isset($_SESSION['user_id'], $_SESSION['user_name'])) {
    header('Location: ../mypage/login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $return_to);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$user_name = (string)$_SESSION['user_name'];
$action = (string)($_POST['action'] ?? '');
$session_id = (int)($_POST['session_id'] ?? 0);

try {
    ensure_club_session_tables($pdo);
    $viewer = club_session_viewer($pdo, $user_id);

    if ($action === 'create') {
        $image_path = save_club_session_image($_FILES['image'] ?? []);
        create_club_session($pdo, $viewer, $_POST, $image_path);
        $finish('club_flash', '合同練習を募集しました。');
    }

    if ($action === 'join') {
        $session = join_club_session($pdo, $viewer, $session_id, (string)($_POST['comment'] ?? ''));
        notify_club_session($pdo, $session, (int)$session['host_user_id'], 'join',
            $user_name . 'さんが合同練習「' . $session['title'] . '」に参加します',
            trim((string)($_POST['comment'] ?? '')) ?: null);
        $finish('club_flash', '参加を登録しました。');
    }

    if ($action === 'leave') {
        leave_club_session($pdo, $user_id, $session_id);
        $finish('club_flash', '参加を取り消しました。');
    }

    if ($action === 'cancel') {
        $session = fetch_club_session($pdo, $session_id);
        foreach (cancel_club_session($pdo, $user_id, $session_id) as $participant_id) {
            notify_club_session($pdo, $session, $participant_id, 'cancel',
                '合同練習「' . $session['title'] . '」（' . date('n/j', strtotime($session['session_date'])) . '）は中止になりました');
        }
        $finish('club_flash', '募集を中止し、参加者に知らせました。');
    }

    if ($action === 'delete') {
        $notified = delete_club_session($pdo, $viewer, $session_id);
        $finish('club_flash', $notified > 0 ? '合同練習を削除し、参加予定だった' . $notified . '人に知らせました。' : '合同練習を削除しました。');
    }

    if ($action === 'message') {
        $session = fetch_club_session($pdo, $session_id);
        $body = trim((string)($_POST['body'] ?? ''));
        if ($session === null || (int)$session['host_user_id'] !== $user_id) {
            throw new RuntimeException('参加者に連絡できるのは主催者だけです。');
        }
        if ($body === '' || mb_strlen($body) > 500) {
            throw new RuntimeException('連絡の内容は500文字以内で入力してください。');
        }
        $participant_ids = club_session_participant_ids($pdo, $session_id);
        foreach ($participant_ids as $participant_id) {
            notify_club_session($pdo, $session, $participant_id, 'message', '合同練習「' . $session['title'] . '」の主催者から連絡があります', $body);
        }
        $finish('club_flash', empty($participant_ids) ? 'まだ参加者がいません。' : '参加者' . count($participant_ids) . '人に連絡しました。');
    }

    $finish('club_error', '操作の指定が正しくありません。');
} catch (RuntimeException $e) {
    if ($e instanceof PDOException) {
        error_log('club session action failed: ' . $e->getMessage());
        $finish('club_error', '保存に失敗しました。時間をおいてもう一度お試しください。');
    }
    $finish('club_error', $e->getMessage());
}
