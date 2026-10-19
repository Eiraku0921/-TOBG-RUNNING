<?php

// 合同練習（クラブ画面の「合同練習」・ホームの NEXT SESSION・チーム紹介の公開分）。
//
// データの持ち方
//   club_sessions              募集（主催者・日時・集合場所・定員・公開範囲・中止したか）
//   club_session_participants  参加者（1人1回。参加日時とひとこと）
//   club_session_notices       誰に何を知らせたか（参加 → 主催者 / 中止・連絡 → 参加者）。この行の id を通知の番号に使う
// 合同練習を消すと、参加者と知らせの記録も外部キーで一緒に消える。グループに公開した募集は、グループを消すと消える。
//
// 決まり（仕様書）
//   主催できるのはログインした会員。ただし OGMRC の生徒（ＯＧＭ会員の権限）は主催できない（参加はできる）
//   「公式」はコーチ（ＯＧＭコーチ陣・ＯＧＭ代表）か管理者の主催
//   定員は なし / 3〜30人。定員に達したら参加できない（同時に押されても超えないよう、参加の処理の中で数え直す）

require_once __DIR__ . '/member_role_lib.php';
require_once __DIR__ . '/member_club_lib.php';
require_once __DIR__ . '/member_group_lib.php';
require_once __DIR__ . '/notification_lib.php';

const CLUB_SESSION_VISIBILITY_PUBLIC = 'public';
const CLUB_SESSION_VISIBILITY_TOBG = 'tobg';
const CLUB_SESSION_VISIBILITY_GROUP = 'group';
const CLUB_SESSION_VISIBILITY_LABELS = [
    CLUB_SESSION_VISIBILITY_PUBLIC => 'すべての人に公開',
    CLUB_SESSION_VISIBILITY_TOBG => 'TOBG 社会人クラブ',
    CLUB_SESSION_VISIBILITY_GROUP => '自分のグループ',
];
const CLUB_SESSION_CAPACITY_MIN = 3;
const CLUB_SESSION_CAPACITY_MAX = 30;
const CLUB_SESSION_TITLE_MAX = 60;
const CLUB_SESSION_PLACE_MAX = 100;
const CLUB_SESSION_MENU_MAX = 1000;
const CLUB_SESSION_NOTE_MAX = 200;
const CLUB_SESSION_IMAGE_MAX_BYTES = 10 * 1024 * 1024;
const CLUB_SESSION_IMAGE_DIR = 'uploads/club_sessions';
const NOTIFICATION_SOURCE_CLUB_SESSION = 'club_session';

function ensure_club_session_tables(PDO $pdo): void
{
    ensure_member_group_tables($pdo);
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS club_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            host_user_id INT NOT NULL,
            title VARCHAR(60) NOT NULL,
            session_date DATE NOT NULL,
            meet_time TIME NOT NULL,
            meet_place VARCHAR(100) NOT NULL,
            menu TEXT NULL,
            note VARCHAR(200) NULL,
            image_path VARCHAR(255) NULL,
            capacity TINYINT UNSIGNED NULL,
            visibility VARCHAR(10) NOT NULL,
            group_id INT NULL,
            is_official TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(10) NOT NULL DEFAULT 'open',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_club_sessions_date (session_date),
            INDEX idx_club_sessions_host (host_user_id),
            CONSTRAINT fk_club_sessions_group FOREIGN KEY (group_id) REFERENCES member_groups (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS club_session_participants (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id INT NOT NULL,
            user_id INT NOT NULL,
            comment VARCHAR(100) NULL,
            joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_club_session_participants (session_id, user_id),
            CONSTRAINT fk_club_session_participants_session FOREIGN KEY (session_id) REFERENCES club_sessions (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS club_session_notices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id INT NOT NULL,
            recipient_user_id INT NOT NULL,
            kind VARCHAR(10) NOT NULL,
            body VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_club_session_notices_session (session_id),
            CONSTRAINT fk_club_session_notices_session FOREIGN KEY (session_id) REFERENCES club_sessions (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

// 見ている人の情報（主催できるか、どの公開範囲が見られるか）
function club_session_viewer(PDO $pdo, int $user_id): array
{
    $viewer = ['user_id' => $user_id, 'club' => null, 'role' => '', 'group_ids' => [], 'can_host' => false, 'is_official_host' => false];
    if ($user_id <= 0) {
        return $viewer;
    }
    $viewer['club'] = fetch_member_club($pdo, $user_id);
    $stmt = $pdo->prepare('SELECT role FROM members WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $user_id]);
    $viewer['role'] = (string)$stmt->fetchColumn();
    $viewer['group_ids'] = member_group_postable_ids($pdo, $user_id);
    $viewer['can_host'] = $viewer['role'] !== MEMBER_ROLE_OGM_MEMBER;
    $viewer['is_official_host'] = member_role_is_coach($viewer['role']) || $viewer['role'] === MEMBER_ROLE_ADMIN;
    return $viewer;
}

// その人が見られる募集か（主催者・すべての人に公開・TOBG の会員・そのグループのメンバー）
function club_session_is_visible(array $session, array $viewer): bool
{
    if ($viewer['user_id'] > 0 && (int)$session['host_user_id'] === $viewer['user_id']) {
        return true;
    }
    switch ($session['visibility']) {
        case CLUB_SESSION_VISIBILITY_PUBLIC:
            return true;
        case CLUB_SESSION_VISIBILITY_TOBG:
            return $viewer['club'] === MEMBER_CLUB_TOBG;
        case CLUB_SESSION_VISIBILITY_GROUP:
            return in_array((int)$session['group_id'], $viewer['group_ids'], true);
    }
    return false;
}

// これからの合同練習（今日以降・日付順）のうち、見ている人が見られるもの。中止したものも出す（参加者が気づけるように）
// $only_public を true にすると、すべての人に公開のものだけ（チーム紹介の画面用）
function fetch_club_sessions(PDO $pdo, array $viewer, bool $only_public = false, int $limit = 0): array
{
    ensure_club_session_tables($pdo);
    $stmt = $pdo->prepare(
        "SELECT s.*, h.name AS host_name, g.name AS group_name,
            (SELECT COUNT(*) FROM club_session_participants p WHERE p.session_id = s.id) AS participant_count
         FROM club_sessions s
         LEFT JOIN members h ON h.id = s.host_user_id
         LEFT JOIN member_groups g ON g.id = s.group_id
         WHERE s.session_date >= :today
         ORDER BY s.session_date ASC, s.meet_time ASC, s.id ASC"
    );
    $stmt->execute([':today' => date('Y-m-d')]);

    $sessions = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($only_public ? $row['visibility'] !== CLUB_SESSION_VISIBILITY_PUBLIC : !club_session_is_visible($row, $viewer)) {
            continue;
        }
        $sessions[] = $row;
        if ($limit > 0 && count($sessions) >= $limit) {
            break;
        }
    }
    if (empty($sessions)) {
        return [];
    }

    // 参加者（名前・参加日時・ひとこと）をまとめて読む
    $ids = array_map(static fn(array $s): int => (int)$s['id'], $sessions);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $p_stmt = $pdo->prepare(
        "SELECT p.session_id, p.user_id, p.comment, p.joined_at, COALESCE(m.name, 'メンバー') AS name
         FROM club_session_participants p
         LEFT JOIN members m ON m.id = p.user_id
         WHERE p.session_id IN ($placeholders)
         ORDER BY p.joined_at ASC, p.id ASC"
    );
    $p_stmt->execute($ids);
    $participants = [];
    foreach ($p_stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $participants[(int)$p['session_id']][] = $p;
    }

    foreach ($sessions as &$session) {
        $id = (int)$session['id'];
        $session['participants'] = $participants[$id] ?? [];
        $session['participant_count'] = count($session['participants']);
        $session['is_host'] = $viewer['user_id'] > 0 && (int)$session['host_user_id'] === $viewer['user_id'];
        $session['is_joined'] = $viewer['user_id'] > 0 && in_array($viewer['user_id'], array_map(static fn(array $p): int => (int)$p['user_id'], $session['participants']), true);
        $session['is_full'] = $session['capacity'] !== null && $session['participant_count'] >= (int)$session['capacity'];
        $session['is_canceled'] = $session['status'] === 'canceled';
        $session['visibility_label'] = $session['visibility'] === CLUB_SESSION_VISIBILITY_GROUP && $session['group_name'] !== null
            ? (string)$session['group_name']
            : (CLUB_SESSION_VISIBILITY_LABELS[$session['visibility']] ?? '');
    }
    unset($session);
    return $sessions;
}

// 募集する。入力の誤りは RuntimeException（画面に出す文）で返す。作った募集の id を返す
function create_club_session(PDO $pdo, array $viewer, array $input, ?string $image_path): int
{
    if (!$viewer['can_host']) {
        throw new RuntimeException('小中学生クラブの会員は合同練習を主催できません（参加はできます）。');
    }
    $title = trim((string)($input['title'] ?? ''));
    $date = (string)($input['session_date'] ?? '');
    $time = (string)($input['meet_time'] ?? '');
    $place = trim((string)($input['meet_place'] ?? ''));
    $menu = trim((string)($input['menu'] ?? ''));
    $note = trim((string)($input['note'] ?? ''));
    $capacity_raw = (string)($input['capacity'] ?? '');
    $visibility = (string)($input['visibility'] ?? '');
    $group_id = (int)($input['group_id'] ?? 0);

    if ($title === '' || mb_strlen($title) > CLUB_SESSION_TITLE_MAX) {
        throw new RuntimeException('タイトルは' . CLUB_SESSION_TITLE_MAX . '文字以内で入力してください。');
    }
    $date_obj = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if ($date_obj === false || $date_obj->format('Y-m-d') !== $date || $date < date('Y-m-d')) {
        throw new RuntimeException('日付は今日以降の日を選んでください。');
    }
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
        throw new RuntimeException('集合時間を選んでください。');
    }
    if ($place === '' || mb_strlen($place) > CLUB_SESSION_PLACE_MAX) {
        throw new RuntimeException('集合場所は' . CLUB_SESSION_PLACE_MAX . '文字以内で入力してください。');
    }
    if (mb_strlen($menu) > CLUB_SESSION_MENU_MAX || mb_strlen($note) > CLUB_SESSION_NOTE_MAX) {
        throw new RuntimeException('練習内容は' . CLUB_SESSION_MENU_MAX . '文字、ひとことは' . CLUB_SESSION_NOTE_MAX . '文字以内にしてください。');
    }
    $capacity = null;
    if ($capacity_raw !== '' && $capacity_raw !== 'none') {
        $capacity = (int)$capacity_raw;
        if ($capacity < CLUB_SESSION_CAPACITY_MIN || $capacity > CLUB_SESSION_CAPACITY_MAX) {
            throw new RuntimeException('定員は「なし」か ' . CLUB_SESSION_CAPACITY_MIN . '〜' . CLUB_SESSION_CAPACITY_MAX . '人で選んでください。');
        }
    }
    if (!array_key_exists($visibility, CLUB_SESSION_VISIBILITY_LABELS)) {
        throw new RuntimeException('公開範囲を選んでください。');
    }
    if ($visibility === CLUB_SESSION_VISIBILITY_GROUP && !in_array($group_id, $viewer['group_ids'], true)) {
        throw new RuntimeException('公開するグループを選んでください（参加しているグループだけ選べます）。');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO club_sessions
            (host_user_id, title, session_date, meet_time, meet_place, menu, note, image_path, capacity, visibility, group_id, is_official)
         VALUES
            (:host, :title, :date, :time, :place, :menu, :note, :image, :capacity, :visibility, :group_id, :official)'
    );
    $stmt->execute([
        ':host' => $viewer['user_id'],
        ':title' => $title,
        ':date' => $date,
        ':time' => $time . ':00',
        ':place' => $place,
        ':menu' => $menu !== '' ? $menu : null,
        ':note' => $note !== '' ? $note : null,
        ':image' => $image_path,
        ':capacity' => $capacity,
        ':visibility' => $visibility,
        ':group_id' => $visibility === CLUB_SESSION_VISIBILITY_GROUP ? $group_id : null,
        ':official' => $viewer['is_official_host'] ? 1 : 0,
    ]);
    return (int)$pdo->lastInsertId();
}

// 1件を読む（行をロックしたいときは $for_update）
function fetch_club_session(PDO $pdo, int $session_id, bool $for_update = false): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM club_sessions WHERE id = :id LIMIT 1' . ($for_update ? ' FOR UPDATE' : ''));
    $stmt->execute([':id' => $session_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// 参加する。定員は、募集の行をロックしてから数え直す（同時に押されても定員を超えない）
function join_club_session(PDO $pdo, array $viewer, int $session_id, string $comment = ''): array
{
    $pdo->beginTransaction();
    try {
        $session = fetch_club_session($pdo, $session_id, true);
        if ($session === null || !club_session_is_visible($session, $viewer) || $viewer['user_id'] <= 0) {
            throw new RuntimeException('この合同練習には参加できません。');
        }
        if ($session['status'] === 'canceled') {
            throw new RuntimeException('この合同練習は中止になりました。');
        }
        if ((int)$session['host_user_id'] === $viewer['user_id']) {
            throw new RuntimeException('主催した合同練習には参加の登録は要りません。');
        }
        if ($session['session_date'] < date('Y-m-d')) {
            throw new RuntimeException('終わった合同練習には参加できません。');
        }
        $count_stmt = $pdo->prepare('SELECT COUNT(*) FROM club_session_participants WHERE session_id = :id');
        $count_stmt->execute([':id' => $session_id]);
        if ($session['capacity'] !== null && (int)$count_stmt->fetchColumn() >= (int)$session['capacity']) {
            throw new RuntimeException('満員です。');
        }
        $insert = $pdo->prepare('INSERT IGNORE INTO club_session_participants (session_id, user_id, comment) VALUES (:id, :user, :comment)');
        $comment = mb_substr(trim($comment), 0, 100);
        $insert->execute([':id' => $session_id, ':user' => $viewer['user_id'], ':comment' => $comment !== '' ? $comment : null]);
        $pdo->commit();
        return $session;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function leave_club_session(PDO $pdo, int $user_id, int $session_id): void
{
    $stmt = $pdo->prepare('DELETE FROM club_session_participants WHERE session_id = :id AND user_id = :user');
    $stmt->execute([':id' => $session_id, ':user' => $user_id]);
}

// 主催者だけ: 中止する（参加者に知らせる）。参加者の user_id の一覧を返す
function cancel_club_session(PDO $pdo, int $user_id, int $session_id): array
{
    $session = fetch_club_session($pdo, $session_id);
    if ($session === null || (int)$session['host_user_id'] !== $user_id) {
        throw new RuntimeException('中止できるのは主催者だけです。');
    }
    $pdo->prepare("UPDATE club_sessions SET status = 'canceled' WHERE id = :id")->execute([':id' => $session_id]);
    return club_session_participant_ids($pdo, $session_id);
}

function club_session_participant_ids(PDO $pdo, int $session_id): array
{
    $stmt = $pdo->prepare('SELECT user_id FROM club_session_participants WHERE session_id = :id');
    $stmt->execute([':id' => $session_id]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// 知らせる（ベル＋プッシュ）。誰に何を知らせたかを club_session_notices に残し、その行の id を通知の番号にする
//   kind: 'join'（参加 → 主催者） / 'cancel'（中止 → 参加者） / 'message'（主催者からの連絡 → 参加者）
function notify_club_session(PDO $pdo, array $session, int $recipient_user_id, string $kind, string $title, ?string $body = null): void
{
    try {
        $stmt = $pdo->prepare('INSERT INTO club_session_notices (session_id, recipient_user_id, kind, body) VALUES (:session, :user, :kind, :body)');
        $stmt->execute([':session' => (int)$session['id'], ':user' => $recipient_user_id, ':kind' => $kind, ':body' => $body]);
        $notice_id = (int)$pdo->lastInsertId();
        $link = 'front/club.php?tab=sessions#session-' . (int)$session['id'];
        create_notification($pdo, NOTIFICATION_SOURCE_CLUB_SESSION, $notice_id, $title, $link, NOTIFICATION_AUDIENCE_ALL, $recipient_user_id, $body);
        require_once __DIR__ . '/web_push_lib.php';
        push_to_member($pdo, $recipient_user_id, [
            'title' => '合同練習「' . mb_strimwidth((string)$session['title'], 0, 30, '…', 'UTF-8') . '」',
            'body' => $body !== null && $body !== '' ? mb_strimwidth($body, 0, 120, '…', 'UTF-8') : $title,
            'url' => $link,
            'tag' => 'club-session-' . (int)$session['id'],
        ]);
    } catch (Throwable $e) {
        // 知らせに失敗しても、参加・中止そのものは成功させる
        error_log('notify_club_session failed: ' . $e->getMessage());
    }
}

// 写真（任意・1枚・10MBまで。JPG / PNG / WEBP）。選ばれていなければ null
function save_club_session_image(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || (int)($file['size'] ?? 0) > CLUB_SESSION_IMAGE_MAX_BYTES) {
        throw new RuntimeException('写真は10MBまでのJPG・PNG・WEBPを選んでください。');
    }
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
    $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if ($extension === null) {
        throw new RuntimeException('写真は10MBまでのJPG・PNG・WEBPを選んでください。');
    }
    $dir = __DIR__ . '/../' . CLUB_SESSION_IMAGE_DIR;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $name = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('写真の保存に失敗しました。');
    }
    return CLUB_SESSION_IMAGE_DIR . '/' . $name;
}

// 合同練習を削除する（主催者と管理者だけ。中止と違い、カードごと消えて元に戻せない）。
// 中止していなければ、参加予定だった人に「取り消された」と知らせてから消す（知らせの記録が合同練習につながっているため、先に送る）。
// 参加者・連絡の記録は表のしくみ（ON DELETE CASCADE）で一緒に消え、写真のファイルも消す。知らせた人数を返す
function delete_club_session(PDO $pdo, array $viewer, int $session_id): int
{
    $session = fetch_club_session($pdo, $session_id);
    if ($session === null) {
        throw new RuntimeException('この合同練習は見つかりませんでした。');
    }
    $is_host = $viewer['user_id'] > 0 && (int)$session['host_user_id'] === $viewer['user_id'];
    if (!$is_host && $viewer['role'] !== MEMBER_ROLE_ADMIN) {
        throw new RuntimeException('削除できるのは、主催者と管理者だけです。');
    }

    $notified = 0;
    if ($session['status'] !== 'canceled') {
        foreach (club_session_participant_ids($pdo, $session_id) as $participant_id) {
            if ($participant_id === $viewer['user_id']) {
                continue;
            }
            notify_club_session($pdo, $session, $participant_id, 'delete',
                '合同練習「' . $session['title'] . '」（' . date('n/j', strtotime((string)$session['session_date'])) . '）は取り消されました');
            $notified++;
        }
    }

    $pdo->prepare('DELETE FROM club_sessions WHERE id = :id')->execute([':id' => $session_id]);

    // 写真のファイル（合同練習の写真のフォルダの中のものだけ）
    $image_path = (string)($session['image_path'] ?? '');
    if (preg_match('#^' . preg_quote(CLUB_SESSION_IMAGE_DIR, '#') . '/[A-Za-z0-9_]+\.(jpg|png|webp)$#', $image_path)) {
        $file = __DIR__ . '/../' . $image_path;
        if (is_file($file)) {
            @unlink($file);
        }
    }
    return $notified;
}
