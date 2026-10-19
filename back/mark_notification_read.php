<?php
require_once __DIR__ . '/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/notification_lib.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'], $_SESSION['user_name'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'ログインが必要です。']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '不正なアクセスです。']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$action = trim((string)($_POST['action'] ?? ''));

try {
    if ($action === 'read') {
        $notification_id = (int)($_POST['notification_id'] ?? 0);
        if ($notification_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '通知の指定が不正です。']);
            exit;
        }
        mark_notification_read($pdo, $user_id, $notification_id);
    } elseif ($action === 'read_all') {
        mark_all_notifications_read($pdo, $user_id);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '操作の指定が不正です。']);
        exit;
    }

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '既読の更新に失敗しました。']);
}
