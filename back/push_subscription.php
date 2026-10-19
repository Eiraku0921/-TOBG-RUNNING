<?php
// プッシュ通知の購読（この端末に通知を送ってよいか）を保存・削除する。ヘッダーの通知パネルから呼ぶ
require_once __DIR__ . '/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/web_push_lib.php';

header('Content-Type: application/json; charset=utf-8');

function respond_push_subscription(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

// テスト通知: この端末だけに送り、結果（届かなければその理由）を返す。
// アプリを開いている間はバナーが出ない端末があるので、ホーム画面に戻る時間として少し待ってから送る
const PUSH_TEST_DELAY_SECONDS = 5;

function send_push_test(PDO $pdo, int $user_id, string $endpoint): array
{
    ensure_web_push_tables($pdo);
    $stmt = $pdo->prepare('SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = :user_id AND endpoint = :endpoint LIMIT 1');
    $stmt->execute([':user_id' => $user_id, ':endpoint' => $endpoint]);
    $subscription = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$subscription) {
        return ['success' => false, 'message' => 'この端末の通知の登録がサーバーにありません。「オフにする」を押してから、もう一度「プッシュ通知を有効にする」を押してください。'];
    }

    // 待っている間にアプリが閉じられて接続が切れても、最後まで送る。待つ間ほかのページが止まらないようセッションは先に閉じる
    ignore_user_abort(true);
    session_write_close();
    sleep(PUSH_TEST_DELAY_SECONDS);

    try {
        $result = send_web_push_with_status($pdo, $subscription, [
            'title' => 'テスト通知',
            'body' => 'この通知が見えていれば、プッシュ通知は正しく届いています。',
            'url' => web_push_absolute_url('index.php'),
            'tag' => 'push-test',
        ], get_vapid_keys($pdo));
    } catch (Throwable $e) {
        error_log('push test failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'サーバーで通知を作れませんでした（' . $e->getMessage() . '）。'];
    }

    $status = $result['status'];
    if ($result['delivered']) {
        return ['success' => true, 'message' => '通知サービスへの送信に成功しました。それでも通知が出ない場合は、端末の通知設定（iPhone は「設定」→「通知」→「TOBG-RUNNING」で「ロック画面」「通知センター」「バナー」がオンか、集中モードがオフか）を確認してください。'];
    }
    if ($status === 0) {
        return ['success' => false, 'message' => 'サーバーから通知サービスにつながりませんでした（' . ($result['error'] !== '' ? $result['error'] : '理由不明') . '）。'];
    }
    if ($status === 404 || $status === 410) {
        return ['success' => false, 'message' => 'この端末の通知の登録が切れていました（' . $status . '）。「オフにする」を押してから、もう一度「プッシュ通知を有効にする」を押してください。'];
    }
    return ['success' => false, 'message' => '通知サービスに受け付けてもらえませんでした（' . $status . '）。'];
}

if (!isset($_SESSION['user_id'], $_SESSION['user_name'])) {
    respond_push_subscription(401, ['success' => false, 'message' => 'ログインが必要です。']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_push_subscription(405, ['success' => false, 'message' => '不正なアクセスです。']);
}

$request = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($request)) {
    respond_push_subscription(400, ['success' => false, 'message' => '送られてきた内容が正しくありません。']);
}

$action = (string)($request['action'] ?? '');
$endpoint = (string)($request['endpoint'] ?? '');
// 購読先は、ブラウザのプッシュサービス（https）だけを受け付ける
if (!preg_match('#^https://[^\s]+$#', $endpoint) || strlen($endpoint) > 2000) {
    respond_push_subscription(400, ['success' => false, 'message' => '購読先が正しくありません。']);
}

$user_id = (int)$_SESSION['user_id'];
try {
    if ($action === 'subscribe') {
        $p256dh = (string)($request['keys']['p256dh'] ?? '');
        $auth = (string)($request['keys']['auth'] ?? '');
        // 端末の公開鍵は 65 バイト、認証用の秘密は 16 バイト
        if (strlen(base64url_decode($p256dh)) !== 65 || strlen(base64url_decode($auth)) !== 16) {
            respond_push_subscription(400, ['success' => false, 'message' => '購読の鍵が正しくありません。']);
        }
        save_push_subscription($pdo, $user_id, $endpoint, $p256dh, $auth, (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    } elseif ($action === 'unsubscribe') {
        delete_push_subscription($pdo, $user_id, $endpoint);
    } elseif ($action === 'test') {
        respond_push_subscription(200, send_push_test($pdo, $user_id, $endpoint));
    } else {
        respond_push_subscription(400, ['success' => false, 'message' => '操作の指定が正しくありません。']);
    }
} catch (Throwable $e) {
    error_log('push_subscription failed: ' . $e->getMessage());
    respond_push_subscription(500, ['success' => false, 'message' => '保存に失敗しました。']);
}

respond_push_subscription(200, ['success' => true]);
