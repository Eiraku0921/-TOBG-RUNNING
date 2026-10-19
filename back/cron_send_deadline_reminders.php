<?php
// 申込締切の前日リマインドを送る（cron から1日1回実行する）。
// 例: 毎朝8時 → 0 8 * * * /usr/local/bin/php /home/users/.../web/back/cron_send_deadline_reminders.php
// ブラウザからは実行させない（URLを叩かれてメールが送られるのを防ぐ）
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/tournament_notification_lib.php';

$sent_count = send_due_tournament_deadline_reminders($pdo);
echo date('Y-m-d H:i:s') . " deadline reminders sent: {$sent_count}\n";
