<?php

require_once __DIR__ . '/mail_lib.php';

// 申込ステータスを更新し、未承認→承認に変わったときだけ申込者へメール通知する
function update_match_status_and_notify(PDO $pdo, int $match_id, string $next_status): void
{
    $stmt = $pdo->prepare(
        'SELECT m.status, m.match_title, m.user_name,
                m.event_name1, m.event_name2, m.event_name3,
                (SELECT MAX(t.event_date) FROM tournaments t WHERE t.event_name = m.match_title) AS event_date
         FROM matches m
         WHERE m.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $match_id]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$match) {
        throw new RuntimeException('対象の申込みが見つかりません。');
    }

    $update_stmt = $pdo->prepare('UPDATE matches SET status = :status WHERE id = :id');
    $update_stmt->execute([':status' => $next_status, ':id' => $match_id]);

    $previous_status = trim((string)$match['status']);
    $was_approved = in_array($previous_status, ['承認', '承認済'], true);
    if ($next_status !== '承認' || $was_approved) {
        return;
    }

    // 申込みは名前で保存されているので、名前から会員を探す
    $member_stmt = $pdo->prepare('SELECT id, email FROM members WHERE name = :name LIMIT 1');
    $member_stmt->execute([':name' => (string)$match['user_name']]);
    $applicant = $member_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$applicant) {
        return;
    }

    // プッシュ通知を受け取っている人にはプッシュで知らせ、メールは送らない
    require_once __DIR__ . '/web_push_lib.php';
    $pushed = push_to_member($pdo, (int)$applicant['id'], [
        'title' => '試合申し込みが承認されました',
        'body' => '「' . (string)$match['match_title'] . '」への申し込みが承認されました。',
        'url' => 'mypage/front_login.php#entry-history',
        'tag' => 'entry-approved-' . $match_id,
    ]);
    $email = trim((string)($applicant['email'] ?? ''));
    if ($pushed || $email === '') {
        return;
    }

    $event_date_text = !empty($match['event_date'])
        ? date('Y年m月d日', strtotime((string)$match['event_date']))
        : '未設定';

    $body = (string)$match['user_name'] . "さん\n\n"
        . "「" . (string)$match['match_title'] . "」への試合申し込みが承認されました。\n\n"
        . "大会日: " . $event_date_text . "\n";
    foreach (['event_name1', 'event_name2', 'event_name3'] as $index => $column) {
        $event_name = trim((string)($match[$column] ?? ''));
        if ($event_name !== '') {
            $body .= "種目" . ($index + 1) . ": " . $event_name . "\n";
        }
    }
    $body .= "\n申込内容はマイページから確認できます。\n\nhttps://tobg.sadist.jp/mypage/front_login.php#entry-history";

    send_notification_email($email, '【TOBG】試合申し込みが承認されました', $body);
}
