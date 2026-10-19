<?php

// 陸連登録者が大会情報を追加する（マイページの「大会の新規追加」と、クラブ画面の「＋ 大会情報を追加」の窓で共通）。
// 追加すると、陸連登録者への通知（メール・プッシュ）とベルの通知を出す。
// 「自分で申し込み管理を行う」を選ぶと、その大会の編集・削除・申込承認を本人が行い、申込の通知も本人宛てに届く。

require_once __DIR__ . '/tournament_cleanup_lib.php';
require_once __DIR__ . '/tournament_notification_lib.php';
require_once __DIR__ . '/notification_lib.php';

const TOURNAMENT_DUPLICATE_MESSAGE = 'この大会はすでに投稿されています。（同じ開催日・同じ大会名の大会が登録済みです）';
const TOURNAMENT_LEAD_DAYS_MIN = 1;
const TOURNAMENT_LEAD_DAYS_MAX = 5;
const TOURNAMENT_LEAD_DAYS_DEFAULT = 3;

// 大会の表の列（あとから足した列）。同じ関数をほかのファイルでも用意しているので、二重に作らないようにする
if (!function_exists('ensure_tournament_link_column')) {
    function ensure_tournament_link_column(PDO $pdo): void
    {
        if (!$pdo->query("SHOW COLUMNS FROM tournaments LIKE 'official_link'")->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec('ALTER TABLE tournaments ADD COLUMN official_link VARCHAR(500) NULL');
        }
    }
}
if (!function_exists('ensure_tournament_entry_deadline_column')) {
    function ensure_tournament_entry_deadline_column(PDO $pdo): void
    {
        if (!$pdo->query("SHOW COLUMNS FROM tournaments LIKE 'entry_deadline'")->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec('ALTER TABLE tournaments ADD COLUMN entry_deadline DATE NULL AFTER event_date');
        }
    }
}
if (!function_exists('ensure_tournament_deadline_lead_days_column')) {
    function ensure_tournament_deadline_lead_days_column(PDO $pdo): void
    {
        if (!$pdo->query("SHOW COLUMNS FROM tournaments LIKE 'deadline_lead_days'")->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec('ALTER TABLE tournaments ADD COLUMN deadline_lead_days TINYINT UNSIGNED NOT NULL DEFAULT 3 AFTER entry_deadline');
        }
    }
}

/**
 * 大会名の比較用キー（全角英数・スペースを半角に揃え、空白を除去、小文字化）。
 */
function normalize_tournament_name_key(string $name): string
{
    $name = mb_convert_kana($name, 'as', 'UTF-8');
    $name = preg_replace('/\s+/u', '', $name) ?? $name;
    return mb_strtolower($name, 'UTF-8');
}

/**
 * 同じ開催日・同じ大会名（表記ゆれ込み）の大会が既に登録されているか。
 * 編集時は自分自身を $exclude_id で除外する。
 */
function tournament_duplicate_exists(PDO $pdo, string $event_name, string $event_date, int $exclude_id = 0): bool
{
    $stmt = $pdo->prepare('SELECT event_name FROM tournaments WHERE event_date = :event_date AND id <> :exclude_id');
    $stmt->execute([':event_date' => $event_date, ':exclude_id' => $exclude_id]);

    $target_key = normalize_tournament_name_key($event_name);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $existing_name) {
        if (normalize_tournament_name_key((string)$existing_name) === $target_key) {
            return true;
        }
    }
    return false;
}

// 陸連登録者が大会情報を追加する。入力の誤りは RuntimeException（画面に出す文）。
// 追加した大会の id と、自分で申し込み管理を行うかを返す
function add_member_tournament(PDO $pdo, int $user_id, array $input): array
{
    ensure_tournament_link_column($pdo);
    ensure_tournament_entry_deadline_column($pdo);
    ensure_tournament_deadline_lead_days_column($pdo);
    ensure_tournament_owner_columns($pdo);

    if (!member_id_is_jaaf_registered($pdo, $user_id)) {
        throw new RuntimeException('陸協登録者のみ大会情報を登録・管理できます。');
    }

    $event_date = trim((string)($input['event_date'] ?? ''));
    $event_name = trim((string)($input['event_name'] ?? ''));
    $location = trim((string)($input['location'] ?? ''));
    $details = trim((string)($input['details'] ?? ''));
    $official_link = trim((string)($input['official_link'] ?? ''));
    $entry_deadline = trim((string)($input['entry_deadline'] ?? ''));
    $deadline_lead_days = (int)($input['deadline_lead_days'] ?? TOURNAMENT_LEAD_DAYS_DEFAULT);
    if ($deadline_lead_days < TOURNAMENT_LEAD_DAYS_MIN || $deadline_lead_days > TOURNAMENT_LEAD_DAYS_MAX) {
        $deadline_lead_days = TOURNAMENT_LEAD_DAYS_DEFAULT;
    }
    $self_managed = (int)($input['self_managed'] ?? 0) === 1;
    $owner_notify_email = trim((string)($input['owner_notify_email'] ?? ''));

    if ($event_date === '' || $event_name === '' || $location === '' || $details === '') {
        throw new RuntimeException('大会追加の必須項目を入力してください。');
    }
    if ($official_link !== '' && !preg_match('#^https?://#i', $official_link)) {
        throw new RuntimeException('大会概要リンクは https:// から始まる URL を入力してください。');
    }
    if ($self_managed && $owner_notify_email === '') {
        throw new RuntimeException('自分で申し込み管理を行う場合は、通知を受け取るメールアドレスを入力してください。');
    }
    if ($self_managed && filter_var($owner_notify_email, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('通知を受け取るメールアドレスの形式が正しくありません。');
    }
    if (tournament_duplicate_exists($pdo, $event_name, $event_date)) {
        throw new RuntimeException(TOURNAMENT_DUPLICATE_MESSAGE);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO tournaments (event_date, entry_deadline, deadline_lead_days, event_name, location, details, official_link, owner_user_id, self_managed, owner_notify_email)
         VALUES (:event_date, :entry_deadline, :deadline_lead_days, :event_name, :location, :details, :official_link, :owner_user_id, :self_managed, :owner_notify_email)'
    );
    $stmt->execute([
        ':event_date' => $event_date,
        ':entry_deadline' => $entry_deadline !== '' ? $entry_deadline : null,
        ':deadline_lead_days' => $deadline_lead_days,
        ':event_name' => $event_name,
        ':location' => $location,
        ':details' => $details,
        ':official_link' => $official_link !== '' ? $official_link : null,
        ':owner_user_id' => $user_id,
        ':self_managed' => $self_managed ? 1 : 0,
        ':owner_notify_email' => $self_managed ? $owner_notify_email : null,
    ]);
    $tournament_id = (int)$pdo->lastInsertId();

    notify_jaaf_members_of_new_tournament($pdo, $tournament_id, $user_id);
    create_notification(
        $pdo,
        NOTIFICATION_SOURCE_TOURNAMENT,
        $tournament_id,
        $event_name . 'の大会情報が追加されました',
        tournament_page_path($tournament_id)
    );
    return ['id' => $tournament_id, 'self_managed' => $self_managed];
}

// 追加したあとに出す文
function member_tournament_added_message(bool $self_managed): string
{
    return $self_managed
        ? '大会を追加しました。この大会の申し込み管理はあなたが行います。'
        : '大会を追加しました。申し込み管理は管理者が行います。';
}

// ---- 自分で申し込み管理を行う大会（追加した本人だけ） ----

// 本人が追加し、自分で申し込み管理を行う大会。そうでなければ RuntimeException
function fetch_self_managed_tournament(PDO $pdo, int $user_id, int $tournament_id, string $denied_message): array
{
    ensure_tournament_owner_columns($pdo);
    $stmt = $pdo->prepare('SELECT id, owner_user_id, self_managed, event_name FROM tournaments WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $tournament_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['owner_user_id'] !== $user_id || (int)$row['self_managed'] !== 1) {
        throw new RuntimeException($denied_message);
    }
    return $row;
}

// 大会情報を編集する（大会名を変えたら、申し込みの大会名も合わせる）
function update_member_tournament(PDO $pdo, int $user_id, array $input): void
{
    $tournament_id = (int)($input['tournament_id'] ?? 0);
    $own_row = fetch_self_managed_tournament($pdo, $user_id, $tournament_id, 'この大会を編集する権限がありません。');

    $event_date = trim((string)($input['event_date'] ?? ''));
    $event_name = trim((string)($input['event_name'] ?? ''));
    $location = trim((string)($input['location'] ?? ''));
    $details = trim((string)($input['details'] ?? ''));
    $official_link = trim((string)($input['official_link'] ?? ''));
    $entry_deadline = trim((string)($input['entry_deadline'] ?? ''));
    $deadline_lead_days = (int)($input['deadline_lead_days'] ?? TOURNAMENT_LEAD_DAYS_DEFAULT);
    if ($deadline_lead_days < TOURNAMENT_LEAD_DAYS_MIN || $deadline_lead_days > TOURNAMENT_LEAD_DAYS_MAX) {
        $deadline_lead_days = TOURNAMENT_LEAD_DAYS_DEFAULT;
    }
    $owner_notify_email = trim((string)($input['owner_notify_email'] ?? ''));

    if ($event_date === '' || $event_name === '' || $location === '' || $details === '') {
        throw new RuntimeException('大会編集の必須項目を入力してください。');
    }
    if ($official_link !== '' && !preg_match('#^https?://#i', $official_link)) {
        throw new RuntimeException('大会概要リンクは https:// から始まる URL を入力してください。');
    }
    if ($owner_notify_email !== '' && filter_var($owner_notify_email, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('通知を受け取るメールアドレスの形式が正しくありません。');
    }
    if (tournament_duplicate_exists($pdo, $event_name, $event_date, $tournament_id)) {
        throw new RuntimeException(TOURNAMENT_DUPLICATE_MESSAGE);
    }

    $pdo->prepare(
        'UPDATE tournaments SET event_date = :event_date, entry_deadline = :entry_deadline, deadline_lead_days = :deadline_lead_days, event_name = :event_name,
            location = :location, details = :details, official_link = :official_link, owner_notify_email = :owner_notify_email WHERE id = :id'
    )->execute([
        ':event_date' => $event_date,
        ':entry_deadline' => $entry_deadline !== '' ? $entry_deadline : null,
        ':deadline_lead_days' => $deadline_lead_days,
        ':event_name' => $event_name,
        ':location' => $location,
        ':details' => $details,
        ':official_link' => $official_link !== '' ? $official_link : null,
        ':owner_notify_email' => $owner_notify_email !== '' ? $owner_notify_email : null,
        ':id' => $tournament_id,
    ]);
    if ($event_name !== (string)$own_row['event_name']) {
        $pdo->prepare('UPDATE matches SET match_title = :new_title WHERE match_title = :old_title')
            ->execute([':new_title' => $event_name, ':old_title' => (string)$own_row['event_name']]);
    }
}

// 削除を管理者に申請する（この場では消さない）。管理者のベルの通知とメールで知らせ、どちらも届かなければ失敗にする
function request_member_tournament_deletion(PDO $pdo, int $user_id, string $user_name, int $tournament_id): void
{
    require_once __DIR__ . '/mail_lib.php';
    $own_row = fetch_self_managed_tournament($pdo, $user_id, $tournament_id, 'この大会の削除を申し込む権限がありません。');
    $title = $user_name . 'さんが「' . (string)$own_row['event_name'] . '」の削除を申請しています';
    $link = 'mypage/front_login.php#admin-tournaments';
    // 同じ大会の申請がすでに通知にあれば新しくは作らない（false でも通知は届いている）
    $notified = create_notification($pdo, NOTIFICATION_SOURCE_TOURNAMENT_DELETE_REQUEST, $tournament_id, $title, $link, NOTIFICATION_AUDIENCE_ADMIN)
        || notification_exists($pdo, NOTIFICATION_SOURCE_TOURNAMENT_DELETE_REQUEST, $tournament_id);
    $body = $title . "。\n\n内容を確認し、問題なければマイページの管理画面から削除してください。\n\nhttps://tobg.sadist.jp/" . $link;
    $mailed = send_admin_notification_email('【TOBG】大会削除の申し込みが届きました', $body);
    if (!$notified && !$mailed) {
        throw new RuntimeException('削除の申請を管理者に届けられませんでした。時間をおいてもう一度お試しください。');
    }
}

const TOURNAMENT_ENTRY_STATUSES = ['承認', '未承認', '閲覧'];

// 自分が管理する大会への申し込みの状態（承認・未承認・閲覧）を変え、申し込んだ人に知らせる
function update_owned_entry_status(PDO $pdo, int $user_id, int $match_id, string $status): void
{
    require_once __DIR__ . '/entry_status_lib.php';
    if ($match_id <= 0 || !in_array($status, TOURNAMENT_ENTRY_STATUSES, true)) {
        throw new RuntimeException('申し込みの状態の指定が正しくありません。');
    }
    $stmt = $pdo->prepare(
        'SELECT t.owner_user_id, t.self_managed
         FROM matches m
         INNER JOIN tournaments t ON t.event_name = m.match_title
         WHERE m.id = :match_id
         LIMIT 1'
    );
    $stmt->execute([':match_id' => $match_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['owner_user_id'] !== $user_id || (int)$row['self_managed'] !== 1) {
        throw new RuntimeException('この申し込みを承認する権限がありません。');
    }
    update_match_status_and_notify($pdo, $match_id, $status);
}
