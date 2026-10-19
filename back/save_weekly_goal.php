<?php
// 週の目標（練習のまとめカードの「✎ → − ＋ → 保存」）を保存し、結果を JSON で返す
// 受け取る値: {"kind": "count" | "distance", "value": 数値}
require_once __DIR__ . '/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/training_summary_lib.php';

header('Content-Type: application/json; charset=utf-8');

function respond_weekly_goal(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id'], $_SESSION['user_name'])) {
    respond_weekly_goal(401, ['success' => false, 'message' => 'ログインが必要です。']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_weekly_goal(405, ['success' => false, 'message' => '不正なアクセスです。']);
}

$request = json_decode((string)file_get_contents('php://input'), true);
$kind = is_array($request) ? (string)($request['kind'] ?? '') : '';
$value = is_array($request) && is_numeric($request['value'] ?? null) ? (float)$request['value'] : null;
if (!array_key_exists($kind, TRAINING_WEEKLY_GOAL_RANGES) || $value === null) {
    respond_weekly_goal(400, ['success' => false, 'message' => '送られてきた内容が正しくありません。']);
}

try {
    ensure_training_weekly_goals_table($pdo);
    if (!save_training_weekly_goal($pdo, (int)$_SESSION['user_id'], $kind, $value)) {
        $range = TRAINING_WEEKLY_GOAL_RANGES[$kind];
        respond_weekly_goal(400, [
            'success' => false,
            'message' => "目標は {$range['min']}〜{$range['max']} の範囲（{$range['step']} 刻み）で選んでください。",
        ]);
    }
} catch (PDOException $e) {
    error_log('save weekly goal failed: ' . $e->getMessage());
    respond_weekly_goal(500, ['success' => false, 'message' => '目標の保存に失敗しました。']);
}

respond_weekly_goal(200, ['success' => true, 'kind' => $kind, 'value' => $value]);
