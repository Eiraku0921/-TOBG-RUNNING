<?php
// NEXT PR の「自己ベスト / シーズンベスト」で選んだ方を覚える（ホームでページを読み直さずに切り替えたとき）。
// 受け取る値: {"view": "pb" | "sb"}。覚えた表示はホームと NEXT PR で共通（pr_user_settings）
require_once __DIR__ . '/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/next_pr_lib.php';

header('Content-Type: application/json; charset=utf-8');

function respond_next_pr_view(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id'], $_SESSION['user_name'])) {
    respond_next_pr_view(401, ['success' => false, 'message' => 'ログインが必要です。']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_next_pr_view(405, ['success' => false, 'message' => '不正なアクセスです。']);
}

$request = json_decode((string)file_get_contents('php://input'), true);
$view = is_array($request) ? (string)($request['view'] ?? '') : '';
if ($view !== 'pb' && $view !== 'sb') {
    respond_next_pr_view(400, ['success' => false, 'message' => '送られてきた内容が正しくありません。']);
}

try {
    ensure_next_pr_tables($pdo);
    next_pr_resolve_best_view($pdo, (int)$_SESSION['user_id'], $view);
} catch (PDOException $e) {
    error_log('save next_pr view failed: ' . $e->getMessage());
    respond_next_pr_view(500, ['success' => false, 'message' => '表示の保存に失敗しました。']);
}

respond_next_pr_view(200, ['success' => true, 'view' => $view]);
