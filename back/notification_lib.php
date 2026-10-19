<?php

// サイト全体の通知（ヘッダーのベル）。
// 通知は1件ずつ持ち、誰が読んだかは notification_reads に記録する。
// recipient_user_id が空の通知は全員（audience で陸連登録者だけに絞ることもある）、入っていればその会員だけに出す。
// 同じ出来事から通知が二重にできないよう、(source_type, source_id) を一意にしている。

const NOTIFICATION_SOURCE_ANNOUNCEMENT = 'announcement';
const NOTIFICATION_SOURCE_TOURNAMENT = 'tournament';
const NOTIFICATION_SOURCE_RECORD = 'record';
const NOTIFICATION_SOURCE_TOURNAMENT_DEADLINE = 'tournament_deadline';
const NOTIFICATION_SOURCE_DIARY_COMMENT = 'diary_comment';
const NOTIFICATION_SOURCE_MEMBER_REGISTER = 'member_register'; // 新規会員登録（管理者だけに出す。source_id は会員の id）
const NOTIFICATION_SOURCE_TOURNAMENT_DELETE_REQUEST = 'tournament_delete_request'; // 大会の削除の申請（管理者だけに出す。source_id は大会の id）

// 通知を見せる相手。jaaf は陸連登録者だけ、admin は管理者だけ
const NOTIFICATION_AUDIENCE_ALL = 'all';
const NOTIFICATION_AUDIENCE_JAAF = 'jaaf';
const NOTIFICATION_AUDIENCE_ADMIN = 'admin';

// ベルに出す件数と期間。古い通知は既読にしなくても自然に消える
const NOTIFICATION_LIST_LIMIT = 20;
const NOTIFICATION_LIST_DAYS = 30;

function ensure_notification_tables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            source_type VARCHAR(20) NOT NULL,
            source_id INT NOT NULL,
            title VARCHAR(200) NOT NULL,
            link_path VARCHAR(255) NULL,
            audience VARCHAR(10) NOT NULL DEFAULT 'all',
            recipient_user_id INT NULL,
            body TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_notifications_source (source_type, source_id),
            INDEX idx_notifications_created (created_at),
            INDEX idx_notifications_recipient (recipient_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    // audience 列が無い頃に作られたテーブルへの追加
    if (!$pdo->query("SHOW COLUMNS FROM notifications LIKE 'audience'")->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN audience VARCHAR(10) NOT NULL DEFAULT 'all' AFTER link_path");
    }
    // 個人宛ての通知（recipient_user_id）が無い頃に作られたテーブルへの追加
    if (!$pdo->query("SHOW COLUMNS FROM notifications LIKE 'recipient_user_id'")->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN recipient_user_id INT NULL AFTER audience, ADD INDEX idx_notifications_recipient (recipient_user_id)");
    }
    // 通知の中身（コメント本文など。ベルのアコーディオンで表示する）が無い頃に作られたテーブルへの追加
    if (!$pdo->query("SHOW COLUMNS FROM notifications LIKE 'body'")->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN body TEXT NULL AFTER recipient_user_id");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS notification_reads (
            notification_id INT NOT NULL,
            user_id INT NOT NULL,
            read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (notification_id, user_id),
            INDEX idx_notification_reads_user (user_id),
            CONSTRAINT fk_notification_reads_notification
                FOREIGN KEY (notification_id) REFERENCES notifications (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

// 通知を作る。同じ出来事の通知がすでにあれば何もしない。$recipient_user_id を渡すとその会員だけの通知になる。
// $body（コメント本文など）を渡すと、ベルで通知を開いたときに中身として表示する。
// 通知の失敗で本来の操作（お知らせ追加など）を失敗させないよう、例外はログに残して握りつぶす
function create_notification(PDO $pdo, string $source_type, int $source_id, string $title, ?string $link_path, string $audience = NOTIFICATION_AUDIENCE_ALL, ?int $recipient_user_id = null, ?string $body = null): bool
{
    try {
        ensure_notification_tables($pdo);
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO notifications (source_type, source_id, title, link_path, audience, recipient_user_id, body)
             VALUES (:source_type, :source_id, :title, :link_path, :audience, :recipient_user_id, :body)'
        );
        $stmt->execute([
            ':source_type' => $source_type,
            ':source_id' => $source_id,
            ':title' => mb_substr($title, 0, 200),
            ':link_path' => $link_path,
            ':audience' => $audience,
            ':recipient_user_id' => $recipient_user_id,
            ':body' => $body !== null ? mb_substr($body, 0, 1000) : null,
        ]);
        // 新しく作れたときだけ true（同じ出来事の通知がすでにあれば false）。プッシュ通知を送るかの判断に使う
        return $stmt->rowCount() === 1;
    } catch (Throwable $e) {
        error_log('create_notification failed: ' . $e->getMessage());
        return false;
    }
}

function delete_notification_for_source(PDO $pdo, string $source_type, int $source_id): void
{
    try {
        ensure_notification_tables($pdo);
        $stmt = $pdo->prepare('DELETE FROM notifications WHERE source_type = :source_type AND source_id = :source_id');
        $stmt->execute([':source_type' => $source_type, ':source_id' => $source_id]);
    } catch (Throwable $e) {
        error_log('delete_notification_for_source failed: ' . $e->getMessage());
    }
}

// ベルに出す通知（新しい順）と、その会員が既読かどうか。
// 全員向け（陸連登録者向けは登録者だけ）と、その会員宛ての通知を出す。
// お知らせの通知は、元のお知らせのボタン（link_url / link_label）も一緒に返す。
// 元のお知らせが削除・非公開になっていたら出さない
// （削除時に通知も消しているが、その仕組みより前に消したお知らせの通知が残っていても出ないようにする）
function fetch_user_notifications(PDO $pdo, int $user_id, bool $is_jaaf_registered, bool $is_admin = false): array
{
    ensure_notification_tables($pdo);
    require_once __DIR__ . '/announcement_lib.php';
    ensure_announcement_link_column($pdo);
    $stmt = $pdo->prepare(
        'SELECT n.id, n.source_type, n.title, n.link_path, n.body, n.created_at, (r.notification_id IS NOT NULL) AS is_read,
                a.link_url, a.link_label, COALESCE(a.is_important, 0) AS is_important
         FROM notifications n
         LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = :user_id
         LEFT JOIN announcements a ON n.source_type = :announcement_join_type AND a.id = n.source_id
         WHERE n.created_at >= DATE_SUB(NOW(), INTERVAL ' . NOTIFICATION_LIST_DAYS . ' DAY)
           AND (
                (n.recipient_user_id IS NULL AND (
                    n.audience = :audience_all
                    OR (n.audience = :audience_jaaf AND :is_jaaf = 1)
                    OR (n.audience = :audience_admin AND :is_admin = 1)
                ))
                OR n.recipient_user_id = :recipient_user_id
           )
           AND (n.source_type <> :announcement_type OR a.is_published = 1)
         ORDER BY n.created_at DESC, n.id DESC
         LIMIT ' . NOTIFICATION_LIST_LIMIT
    );
    $stmt->execute([
        ':user_id' => $user_id,
        ':audience_all' => NOTIFICATION_AUDIENCE_ALL,
        ':audience_jaaf' => NOTIFICATION_AUDIENCE_JAAF,
        ':audience_admin' => NOTIFICATION_AUDIENCE_ADMIN,
        ':is_jaaf' => $is_jaaf_registered ? 1 : 0,
        ':is_admin' => $is_admin ? 1 : 0,
        ':recipient_user_id' => $user_id,
        ':announcement_type' => NOTIFICATION_SOURCE_ANNOUNCEMENT,
        ':announcement_join_type' => NOTIFICATION_SOURCE_ANNOUNCEMENT,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['is_read'] = (bool)$row['is_read'];
        $row['is_important'] = (int)$row['is_important'] === 1;
    }
    unset($row);
    return $rows;
}

// 「返信する」ボタンの行き先。コメントの通知だけ、通常のリンク先に reply=1 を付けて
// マイページで日記を開いたときにコメント入力欄へすぐ移れるようにする。それ以外の通知は null
function notification_reply_path(array $notification): ?string
{
    $link_path = (string)($notification['link_path'] ?? '');
    if (($notification['source_type'] ?? '') !== NOTIFICATION_SOURCE_DIARY_COMMENT || $link_path === '') {
        return null;
    }
    [$path_and_query, $fragment] = array_pad(explode('#', $link_path, 2), 2, null);
    $separator = strpos($path_and_query, '?') === false ? '?' : '&';
    return $path_and_query . $separator . 'reply=1' . ($fragment !== null ? '#' . $fragment : '');
}

function mark_notification_read(PDO $pdo, int $user_id, int $notification_id): void
{
    ensure_notification_tables($pdo);
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO notification_reads (notification_id, user_id)
         SELECT id, :user_id FROM notifications WHERE id = :notification_id'
    );
    $stmt->execute([':user_id' => $user_id, ':notification_id' => $notification_id]);
}

// ベルに出る期間の通知をすべて既読にする（他の会員宛ての通知は対象にしない）
function mark_all_notifications_read(PDO $pdo, int $user_id): void
{
    ensure_notification_tables($pdo);
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO notification_reads (notification_id, user_id)
         SELECT id, :user_id FROM notifications
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL ' . NOTIFICATION_LIST_DAYS . ' DAY)
           AND (recipient_user_id IS NULL OR recipient_user_id = :recipient_user_id)'
    );
    $stmt->execute([':user_id' => $user_id, ':recipient_user_id' => $user_id]);
}

// 「2時間前」「昨日」「3日前」「1週間前」のような表示
function format_notification_time(string $created_at, ?int $now = null): string
{
    $now = $now ?? time();
    $time = strtotime($created_at);
    if ($time === false) {
        return '';
    }
    $diff = max(0, $now - $time);
    if ($diff < 60) {
        return 'たった今';
    }
    if ($diff < 3600) {
        return intdiv($diff, 60) . '分前';
    }
    if ($diff < 86400) {
        return intdiv($diff, 3600) . '時間前';
    }
    $days = intdiv($diff, 86400);
    if ($days === 1) {
        return '昨日';
    }
    if ($days < 7) {
        return $days . '日前';
    }
    return intdiv($days, 7) . '週間前';
}

// 権限が管理者の会員の id（管理者だけに出す通知を、管理者の端末へプッシュ通知でも送るときに使う）
// 同じ出来事（種類と元の id）の通知がすでにあるか
function notification_exists(PDO $pdo, string $source_type, int $source_id): bool
{
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM notifications WHERE source_type = :source_type AND source_id = :source_id LIMIT 1');
        $stmt->execute([':source_type' => $source_type, ':source_id' => $source_id]);
        return $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        error_log('notification_exists failed: ' . $e->getMessage());
        return false;
    }
}

function fetch_admin_user_ids(PDO $pdo): array
{
    $stmt = $pdo->prepare('SELECT id FROM members WHERE role = :role');
    $stmt->execute([':role' => 'admin']);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// 新規会員登録を管理者に知らせる（ベルの通知と、管理者の端末へのプッシュ通知）。
// 押すとマイページの会員一覧が開く。知らせられなくても登録そのものは止めない
function notify_admins_of_new_member(PDO $pdo, int $member_id, string $member_name): void
{
    $title = '新しい会員が登録されました：' . $member_name . 'さん';
    $link = 'mypage/front_login.php#admin-members';
    if (!create_notification($pdo, NOTIFICATION_SOURCE_MEMBER_REGISTER, $member_id, $title, $link, NOTIFICATION_AUDIENCE_ADMIN)) {
        return;
    }
    try {
        require_once __DIR__ . '/web_push_lib.php';
        foreach (fetch_admin_user_ids($pdo) as $admin_user_id) {
            push_to_member($pdo, $admin_user_id, [
                'title' => '新規会員登録',
                'body' => $title . '。',
                'url' => $link,
                'tag' => 'member-register-' . $member_id,
            ]);
        }
    } catch (Throwable $e) {
        error_log('notify_admins_of_new_member push failed: ' . $e->getMessage());
    }
}
