<?php

function send_notification_email(string $to, string $subject, string $body): bool
{
    $from_address = 'info@tobg.sadist.jp';

    mb_language('Japanese');
    mb_internal_encoding('UTF-8');

    $headers = "From: TOBG <{$from_address}>";

    // 送れなかったときの警告は画面に出さずログに残す（画面に出ると、そのあとのページ移動が止まる）
    $sent = @mb_send_mail($to, $subject, $body, $headers);
    if (!$sent) {
        $mail_error = error_get_last();
        error_log("send_notification_email failed: to={$to} subject={$subject}" . ($mail_error !== null ? ' (' . $mail_error['message'] . ')' : ''));
    }

    return $sent;
}

function send_admin_notification_email(string $subject, string $body): bool
{
    return send_notification_email('haruzo0921@icloud.com', $subject, $body);
}
