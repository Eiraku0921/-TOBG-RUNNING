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
$comment_text = trim((string)($_POST['comment_text'] ?? ''));
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

function respond_comment_error(bool $is_ajax, string $message, string $return_path): void
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

if ($diary_id <= 0 || $comment_text === '') {
    respond_comment_error($is_ajax, 'コメントを入力してください。', $return_path);
}

try {
    ensure_training_diary_table($pdo);
    ensure_training_diary_interaction_tables($pdo);

    require_once __DIR__ . '/member_role_lib.php';
    ensure_member_role_column($pdo);
    $find_stmt = $pdo->prepare(
        'SELECT d.user_id, owner.role AS owner_role, commenter.role AS commenter_role
         FROM training_diaries d
         LEFT JOIN members owner ON owner.id = d.user_id
         LEFT JOIN members commenter ON commenter.id = :commenter_id
         WHERE d.id = :id
         LIMIT 1'
    );
    $find_stmt->execute([':id' => $diary_id, ':commenter_id' => $user_id]);
    $target_diary = $find_stmt->fetch(PDO::FETCH_ASSOC);

    // グループ限定の日記は、見られる人（本人・グループのメンバー）だけがコメントできる
    ensure_member_group_tables($pdo);
    if (!$target_diary || !member_can_view_diary($pdo, $user_id, $diary_id)) {
        respond_comment_error($is_ajax, '対象の日記が見つかりませんでした。', $return_path);
    }
    // OGM の生徒の日記には、コーチ陣・コーチ代表者・管理者と本人だけが書ける（画面で入力欄を隠していても、ここで必ず確かめる）
    $commenter_role = (string)($_SESSION['user_role'] ?? '') === MEMBER_ROLE_ADMIN ? MEMBER_ROLE_ADMIN : (string)$target_diary['commenter_role'];
    if (!member_can_comment_on_diary($commenter_role, (string)$target_diary['owner_role'], (int)$target_diary['user_id'] === $user_id)) {
        respond_comment_error($is_ajax, 'OGMの生徒の練習日記には、コーチだけがコメントできます。', $return_path);
    }

    $insert_stmt = $pdo->prepare(
        'INSERT INTO training_diary_comments (diary_id, user_id, comment_text) VALUES (:diary_id, :user_id, :comment_text)'
    );
    $insert_stmt->execute([
        ':diary_id' => $diary_id,
        ':user_id' => $user_id,
        ':comment_text' => $comment_text,
    ]);
    $new_comment_id = (int)$pdo->lastInsertId();

    ensure_member_email_column($pdo);
    require_once __DIR__ . '/member_role_lib.php';
    ensure_member_role_column($pdo);
    $owner_stmt = $pdo->prepare(
        'SELECT d.user_id AS owner_user_id, d.menu, d.practice_date, m.email, m.name AS owner_name, m.role AS owner_role
         FROM training_diaries d
         LEFT JOIN members m ON m.id = d.user_id
         WHERE d.id = :diary_id
         LIMIT 1'
    );
    $owner_stmt->execute([':diary_id' => $diary_id]);
    $diary_owner = $owner_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $owner_user_id = (int)($diary_owner['owner_user_id'] ?? 0);
    $owner_email = trim((string)($diary_owner['email'] ?? ''));
    $pushed = false;

    // 日記の持ち主のベルとプッシュ通知に知らせる（自分の日記に自分でコメントしたときは出さない）。
    // リンク先はマイページで、その日記の詳細（コメント欄）が開いた状態になる
    if ($owner_user_id > 0 && $owner_user_id !== $user_id) {
        require_once __DIR__ . '/notification_lib.php';
        require_once __DIR__ . '/web_push_lib.php';

        // OGM会員の日記に OGMコーチがコメントしたときは「コーチ」と表示する（区分はセッションではなく DB の最新を使う）
        $commenter_role_stmt = $pdo->prepare('SELECT role FROM members WHERE id = :id LIMIT 1');
        $commenter_role_stmt->execute([':id' => $user_id]);
        $is_coach = is_ogm_coach_comment((string)$commenter_role_stmt->fetchColumn(), (string)($diary_owner['owner_role'] ?? ''));
        $commenter_label = ($is_coach ? 'コーチ ' : '') . (string)$_SESSION['user_name'] . 'さん';

        $diary_title = mb_strimwidth(trim((string)($diary_owner['menu'] ?? '')), 0, 40, '…', 'UTF-8');
        $diary_link = 'mypage/front_login.php?open_diary=' . $diary_id . '#training-diary';
        create_notification(
            $pdo,
            NOTIFICATION_SOURCE_DIARY_COMMENT,
            $new_comment_id,
            $commenter_label . 'が練習日記「' . $diary_title . '」にコメントしました',
            $diary_link,
            NOTIFICATION_AUDIENCE_ALL,
            $owner_user_id,
            $comment_text
        );
        $pushed = push_to_member($pdo, $owner_user_id, [
            'title' => $is_coach ? 'コーチからコメントが届きました' : '練習日記にコメントが届きました',
            'body' => $commenter_label . '：' . mb_strimwidth($comment_text, 0, 120, '…', 'UTF-8'),
            'url' => $diary_link,
            'tag' => 'diary-comment-' . $diary_id,
        ]);
    }

    // プッシュ通知が届いた人にはメールを送らない（アプリの通知で十分なため）
    if ($owner_user_id > 0 && $owner_user_id !== $user_id && $owner_email !== '' && !$pushed) {
        require_once __DIR__ . '/mail_lib.php';
        $notify_body = (string)$_SESSION['user_name'] . "さんから練習日記にコメントが届きました。\n\n"
            . "【日記】" . (string)($diary_owner['menu'] ?? '') . "（" . (string)($diary_owner['practice_date'] ?? '') . "）\n"
            . "【コメント】\n" . $comment_text . "\n\n"
            . "マイページから確認してください。\nhttps://tobg.sadist.jp/mypage/front_login.php#training-diary";
        send_notification_email($owner_email, '【TOBG】練習日記にコメントが届きました', $notify_body);
    }

    if ($is_ajax) {
        $count_stmt = $pdo->prepare('SELECT COUNT(*) FROM training_diary_comments WHERE diary_id = :diary_id');
        $count_stmt->execute([':diary_id' => $diary_id]);
        $comment_count = (int)$count_stmt->fetchColumn();

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'comment' => [
                'id' => $new_comment_id,
                'user_id' => $user_id,
                'user_name' => (string)$_SESSION['user_name'],
                'comment_text' => $comment_text,
                'created_at' => date('Y-m-d H:i:s'),
            ],
            'comment_count' => $comment_count,
        ]);
        exit;
    }

    $_SESSION['training_flash'] = 'コメントを追加しました。';
} catch (PDOException $e) {
    respond_comment_error($is_ajax, 'コメントの保存に失敗しました。', $return_path);
}

header('Location: ' . $return_path);
exit;