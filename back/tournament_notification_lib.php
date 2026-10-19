<?php

require_once __DIR__ . '/tournament_cleanup_lib.php';
require_once __DIR__ . '/tournament_list_lib.php';
require_once __DIR__ . '/member_avatar_lib.php';
require_once __DIR__ . '/mail_lib.php';
require_once __DIR__ . '/notification_lib.php';
require_once __DIR__ . '/web_push_lib.php';

// 会員向けの申込締切（この日を過ぎると申込めない）。save_entry.php / entry_form.php と同じ計算
const TOURNAMENT_MEMBER_DEADLINE_SQL = 'DATE_SUB(COALESCE(entry_deadline, event_date), INTERVAL COALESCE(deadline_lead_days, 3) DAY)';

function fetch_tournament_for_mail(PDO $pdo, int $tournament_id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, event_date, event_name, location, official_link, ' . TOURNAMENT_MEMBER_DEADLINE_SQL . ' AS member_deadline
         FROM tournaments WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $tournament_id]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
    return $tournament ?: null;
}

function build_tournament_mail_body(array $member_row, array $tournament, string $lead_text): string
{
    $body = (string)$member_row['name'] . "さん\n\n"
        . $lead_text . "\n\n"
        . "大会名: " . (string)$tournament['event_name'] . "\n"
        . "大会日: " . date('Y年m月d日', strtotime((string)$tournament['event_date'])) . "\n"
        . "会場: " . (string)$tournament['location'] . "\n"
        . "申込締切: " . date('Y年m月d日', strtotime((string)$tournament['member_deadline'])) . "\n";
    if (!empty($tournament['official_link'])) {
        $body .= "公式サイト: " . (string)$tournament['official_link'] . "\n";
    }
    $body .= "\n詳細・申し込みはこちら\nhttps://tobg.sadist.jp/" . tournament_page_path((int)$tournament['id']);
    return $body;
}

// 陸連登録者1人に知らせる。プッシュ通知を受け取っている人にはプッシュで送り、メールは送らない。
// プッシュが届かなかった人にだけ、メールアドレスがあればメールで送る
function notify_jaaf_member(PDO $pdo, array $member_row, array $push_message, string $mail_subject, string $mail_body): void
{
    if (push_to_member($pdo, (int)$member_row['id'], $push_message)) {
        return;
    }
    $email = trim((string)($member_row['email'] ?? ''));
    if ($email !== '') {
        send_notification_email($email, $mail_subject, $mail_body);
    }
}

// 新しい大会が追加されたことを、陸連登録者へ1人ずつ通知する（追加した本人は除く）
function notify_jaaf_members_of_new_tournament(PDO $pdo, int $tournament_id, int $added_by_user_id): void
{
    try {
        $tournament = fetch_tournament_for_mail($pdo, $tournament_id);
        if ($tournament === null) {
            return;
        }

        ensure_member_email_column($pdo);
        foreach (fetch_jaaf_members($pdo, $added_by_user_id) as $member_row) {
            notify_jaaf_member(
                $pdo,
                $member_row,
                [
                    'title' => '新しい大会情報が追加されました',
                    'body' => (string)$tournament['event_name'] . '（' . date('n月j日', strtotime((string)$tournament['event_date'])) . '）',
                    'url' => tournament_page_path((int)$tournament['id']),
                    'tag' => 'tournament-' . (int)$tournament['id'],
                ],
                '【TOBG】新しい大会情報が追加されました',
                build_tournament_mail_body($member_row, $tournament, '新しい大会情報が追加されました。')
            );
        }
    } catch (Throwable $e) {
        // 通知に失敗しても大会の追加自体は成功扱いにする
        error_log('notify_jaaf_members_of_new_tournament failed: ' . $e->getMessage());
    }
}

// ---- 申込締切の前日リマインド ----

// どの大会のリマインドを送ったかの記録。同じ大会に二重に送らないために使う
function ensure_tournament_deadline_reminders_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS tournament_deadline_reminders (
            tournament_id INT NOT NULL PRIMARY KEY,
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

// 申込締切の前日（前日を逃した場合は締切当日まで）を迎えた大会について、
// 陸連登録者へベルの通知と、プッシュ通知（受け取っていない人にはメール）を送る。
// cron からもページ表示時からも呼ばれるが、1大会につき1回しか送らない
function send_due_tournament_deadline_reminders(PDO $pdo): int
{
    ensure_tournament_deadline_reminders_table($pdo);

    $due_stmt = $pdo->query(
        'SELECT t.id
         FROM tournaments t
         LEFT JOIN tournament_deadline_reminders r ON r.tournament_id = t.id
         WHERE r.tournament_id IS NULL
           AND CURDATE() BETWEEN DATE_SUB(' . TOURNAMENT_MEMBER_DEADLINE_SQL . ', INTERVAL 1 DAY) AND ' . TOURNAMENT_MEMBER_DEADLINE_SQL
    );
    $due_ids = array_map('intval', $due_stmt->fetchAll(PDO::FETCH_COLUMN));

    $sent_count = 0;
    foreach ($due_ids as $tournament_id) {
        // 先に「送信済み」を記録できた処理だけが送る（cron とページ表示が同時に動いても二重送信しない）
        $claim_stmt = $pdo->prepare('INSERT IGNORE INTO tournament_deadline_reminders (tournament_id) VALUES (:id)');
        $claim_stmt->execute([':id' => $tournament_id]);
        if ($claim_stmt->rowCount() !== 1) {
            continue;
        }

        $tournament = fetch_tournament_for_mail($pdo, $tournament_id);
        if ($tournament === null) {
            continue;
        }

        $deadline_label = date('n月j日', strtotime((string)$tournament['member_deadline']));
        create_notification(
            $pdo,
            NOTIFICATION_SOURCE_TOURNAMENT_DEADLINE,
            $tournament_id,
            (string)$tournament['event_name'] . 'の申込締切は' . $deadline_label . 'です',
            tournament_page_path($tournament_id),
            NOTIFICATION_AUDIENCE_JAAF
        );

        try {
            ensure_member_email_column($pdo);
            foreach (fetch_jaaf_members($pdo) as $member_row) {
                notify_jaaf_member(
                    $pdo,
                    $member_row,
                    [
                        'title' => '申込締切が近づいています',
                        'body' => (string)$tournament['event_name'] . 'の申込締切は' . $deadline_label . 'です。',
                        'url' => tournament_page_path($tournament_id),
                        'tag' => 'deadline-' . $tournament_id,
                    ],
                    '【TOBG】' . (string)$tournament['event_name'] . 'の申込締切が近づいています',
                    build_tournament_mail_body($member_row, $tournament, '申込締切が近づいています。まだの方はお早めにお申し込みください。')
                );
            }
        } catch (Throwable $e) {
            error_log('send_due_tournament_deadline_reminders mail failed: ' . $e->getMessage());
        }
        $sent_count++;
    }

    return $sent_count;
}
