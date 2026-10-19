<?php

// 練習日記のグループ公開（会員が自分で作るグループ）。
//
// データの持ち方
//   member_groups                グループ（名前・作成者・招待コード・状態）。groups は MySQL の予約語なので member_ を付ける
//   member_group_members         グループのメンバー（作成者も1人のメンバーとして入れる）
//   member_group_delete_requests 削除申請（作成者は即削除できず、管理者が承認・却下する）。グループを消しても履歴は残す
//   training_diary_groups        日記をどのグループに公開したか（1つの日記を複数のグループに公開できる）
// グループのアイコンは作成者が写真をアップロードして決める（uploads/group_icons に正方形に縮めて保存）。
// グループを消すと、日記との紐付けも消える。そのグループにだけ公開していた日記は、本人のカレンダーだけに残る。

require_once __DIR__ . '/notification_lib.php';

const MEMBER_GROUP_NAME_MAX = 30;
const MEMBER_GROUP_INVITE_DAYS = 7;
const MEMBER_GROUP_STATUS_ACTIVE = 'active';
const MEMBER_GROUP_STATUS_DELETE_REQUESTED = 'delete_requested';
const MEMBER_GROUP_REQUEST_PENDING = 'pending';
const MEMBER_GROUP_REQUEST_APPROVED = 'approved';
const MEMBER_GROUP_REQUEST_REJECTED = 'rejected';
const NOTIFICATION_SOURCE_GROUP_DELETE_REQUEST = 'group_delete_request';
const MEMBER_GROUP_ICON_DIR = 'uploads/group_icons';
const MEMBER_GROUP_ICON_MAX_BYTES = 5 * 1024 * 1024;
const MEMBER_GROUP_ICON_SIZE = 256;

function ensure_member_group_tables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS member_groups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(" . MEMBER_GROUP_NAME_MAX . ") NOT NULL,
            owner_user_id INT NOT NULL,
            invite_code CHAR(6) NULL,
            invite_expires_at DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_member_groups_invite_code (invite_code),
            INDEX idx_member_groups_owner (owner_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS member_group_members (
            group_id INT NOT NULL,
            user_id INT NOT NULL,
            joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (group_id, user_id),
            INDEX idx_member_group_members_user (user_id),
            CONSTRAINT fk_member_group_members_group FOREIGN KEY (group_id) REFERENCES member_groups (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS member_group_delete_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            group_id INT NOT NULL,
            group_name VARCHAR(" . MEMBER_GROUP_NAME_MAX . ") NOT NULL,
            requester_user_id INT NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'pending',
            requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME NULL,
            processed_by INT NULL,
            INDEX idx_member_group_delete_requests_status (status, requested_at),
            INDEX idx_member_group_delete_requests_group (group_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS training_diary_groups (
            diary_id INT NOT NULL,
            group_id INT NOT NULL,
            PRIMARY KEY (diary_id, group_id),
            INDEX idx_training_diary_groups_group (group_id),
            CONSTRAINT fk_training_diary_groups_group FOREIGN KEY (group_id) REFERENCES member_groups (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    if (!$pdo->query("SHOW COLUMNS FROM member_groups LIKE 'icon_path'")->fetch()) {
        $pdo->exec('ALTER TABLE member_groups ADD COLUMN icon_path VARCHAR(255) NULL AFTER name');
    }
    cleanup_member_groups_for_missing_members($pdo);
}

// 会員がデータベースから消えたとき（サイトに退会の機能はないが、phpMyAdmin などで消した場合）の片付け。
//   ・その会員をグループのメンバーから外す
//   ・代表者がいなくなったグループは、いちばん前から参加しているメンバーに代表者を引き継ぐ
//   ・メンバーが0人になったグループは消す（アイコンの写真も消す）
function cleanup_member_groups_for_missing_members(PDO $pdo): void
{
    $pdo->exec(
        'DELETE gm FROM member_group_members gm
         LEFT JOIN members m ON m.id = gm.user_id
         WHERE m.id IS NULL'
    );

    $orphans = $pdo->query(
        'SELECT g.id, g.icon_path,
                (SELECT gm.user_id FROM member_group_members gm WHERE gm.group_id = g.id ORDER BY gm.joined_at, gm.user_id LIMIT 1) AS next_owner_id
         FROM member_groups g
         LEFT JOIN members owner ON owner.id = g.owner_user_id
         WHERE owner.id IS NULL'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($orphans as $orphan) {
        if ($orphan['next_owner_id'] !== null) {
            $pdo->prepare('UPDATE member_groups SET owner_user_id = :owner WHERE id = :id')
                ->execute([':owner' => (int)$orphan['next_owner_id'], ':id' => (int)$orphan['id']]);
        } else {
            $pdo->prepare('DELETE FROM member_groups WHERE id = :id')->execute([':id' => (int)$orphan['id']]);
            member_group_delete_icon_file((string)($orphan['icon_path'] ?? ''));
        }
    }
}

// ---- 招待コード ----

// 6桁の数字。使われていないものが出るまで引き直す（期限切れのコードも、表に残っている間は重ならないようにする）
function member_group_new_invite_code(PDO $pdo): string
{
    $check = $pdo->prepare('SELECT 1 FROM member_groups WHERE invite_code = :code LIMIT 1');
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $check->execute([':code' => $code]);
        if (!$check->fetchColumn()) {
            return $code;
        }
    }
    throw new RuntimeException('招待コードを作れませんでした。時間を置いて再度お試しください。');
}

// 招待コードが無いか期限切れなら、新しく発行する（作成者が招待画面を開いたときに呼ぶ）
function member_group_refresh_invite_code(PDO $pdo, int $group_id): void
{
    $stmt = $pdo->prepare('SELECT invite_code, invite_expires_at FROM member_groups WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $group_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }
    if (!empty($row['invite_code']) && strtotime((string)$row['invite_expires_at']) > time()) {
        return;
    }
    $update = $pdo->prepare('UPDATE member_groups SET invite_code = :code, invite_expires_at = :expires WHERE id = :id');
    $update->execute([
        ':code' => member_group_new_invite_code($pdo),
        ':expires' => date('Y-m-d H:i:s', time() + MEMBER_GROUP_INVITE_DAYS * 86400),
        ':id' => $group_id,
    ]);
}

// ---- 一覧・権限 ----

// 自分が作った・参加しているグループ（作った順）。作成者のときだけ招待コードを返す
function fetch_member_groups_for_user(PDO $pdo, int $user_id): array
{
    $stmt = $pdo->prepare(
        "SELECT g.id, g.name, g.icon_path, g.owner_user_id, g.status, g.invite_code, g.invite_expires_at,
                COALESCE(owner.name, 'メンバー') AS owner_name,
                (SELECT COUNT(*) FROM member_group_members c WHERE c.group_id = g.id) AS member_count
         FROM member_group_members m
         INNER JOIN member_groups g ON g.id = m.group_id
         LEFT JOIN members owner ON owner.id = g.owner_user_id
         WHERE m.user_id = :user_id
         ORDER BY g.created_at, g.id"
    );
    $stmt->execute([':user_id' => $user_id]);

    $groups = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $is_owner = (int)$row['owner_user_id'] === $user_id;
        $groups[] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'icon_path' => (string)($row['icon_path'] ?? ''),
            'is_owner' => $is_owner,
            'owner_name' => (string)$row['owner_name'],
            'member_count' => (int)$row['member_count'],
            'status' => (string)$row['status'],
            'invite_code' => $is_owner ? (string)($row['invite_code'] ?? '') : '',
            'invite_expires_at' => $is_owner ? (string)($row['invite_expires_at'] ?? '') : '',
        ];
    }
    return $groups;
}

// 公開範囲に選べるグループ（参加中で、削除申請中でないもの）のID
function member_group_postable_ids(PDO $pdo, int $user_id): array
{
    $stmt = $pdo->prepare(
        'SELECT g.id FROM member_group_members m
         INNER JOIN member_groups g ON g.id = m.group_id
         WHERE m.user_id = :user_id AND g.status = :active'
    );
    $stmt->execute([':user_id' => $user_id, ':active' => MEMBER_GROUP_STATUS_ACTIVE]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function member_group_is_member(PDO $pdo, int $group_id, int $user_id): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM member_group_members WHERE group_id = :group_id AND user_id = :user_id LIMIT 1');
    $stmt->execute([':group_id' => $group_id, ':user_id' => $user_id]);
    return (bool)$stmt->fetchColumn();
}

// 作成者のグループ（削除申請中でないもの）。違えば例外
function member_group_require_owned_active(PDO $pdo, int $group_id, int $user_id): array
{
    $stmt = $pdo->prepare('SELECT id, name, owner_user_id, status FROM member_groups WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $group_id]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$group || (int)$group['owner_user_id'] !== $user_id) {
        throw new RuntimeException('このグループは操作できません。');
    }
    if ((string)$group['status'] !== MEMBER_GROUP_STATUS_ACTIVE) {
        throw new RuntimeException('削除申請中のグループは操作できません。');
    }
    return $group;
}

// 日記を見てよいか（全体公開、本人、または日記を公開したグループのメンバー）。コメント・いいねの前に確かめる
function member_can_view_diary(PDO $pdo, int $user_id, int $diary_id): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM training_diaries d
         WHERE d.id = :diary_id
           AND (
                d.is_public = 1
                OR d.user_id = :owner_id
                OR EXISTS (
                    SELECT 1 FROM training_diary_groups dg
                    INNER JOIN member_group_members m ON m.group_id = dg.group_id
                    WHERE dg.diary_id = d.id AND m.user_id = :member_id
                )
           )
         LIMIT 1'
    );
    $stmt->execute([':diary_id' => $diary_id, ':owner_id' => $user_id, ':member_id' => $user_id]);
    return (bool)$stmt->fetchColumn();
}

// ---- 作成・参加・名前変更・脱退 ----

function member_group_normalize_name(string $raw): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $raw));
    if ($name === '') {
        throw new RuntimeException('グループ名を入力してください。');
    }
    if (mb_strlen($name, 'UTF-8') > MEMBER_GROUP_NAME_MAX) {
        throw new RuntimeException('グループ名は' . MEMBER_GROUP_NAME_MAX . '文字以内にしてください。');
    }
    return $name;
}

// グループを作る（作成者もメンバーに入れ、招待コードを発行する）。作ったグループのIDを返す
function create_member_group(PDO $pdo, int $owner_user_id, string $raw_name): int
{
    $name = member_group_normalize_name($raw_name);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO member_groups (name, owner_user_id, invite_code, invite_expires_at) VALUES (:name, :owner, :code, :expires)'
        );
        $stmt->execute([
            ':name' => $name,
            ':owner' => $owner_user_id,
            ':code' => member_group_new_invite_code($pdo),
            ':expires' => date('Y-m-d H:i:s', time() + MEMBER_GROUP_INVITE_DAYS * 86400),
        ]);
        $group_id = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO member_group_members (group_id, user_id) VALUES (:group_id, :user_id)')
            ->execute([':group_id' => $group_id, ':user_id' => $owner_user_id]);
        $pdo->commit();
        return $group_id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// 招待コードで参加する。参加したグループ名を返す（すでにメンバーでもそのまま成功にする）
function join_member_group_by_code(PDO $pdo, int $user_id, string $raw_code): string
{
    $code = preg_replace('/\D/', '', $raw_code);
    if (strlen($code) !== 6) {
        throw new RuntimeException('招待コードは6桁の数字で入力してください。');
    }
    $stmt = $pdo->prepare('SELECT id, name, status, invite_expires_at FROM member_groups WHERE invite_code = :code LIMIT 1');
    $stmt->execute([':code' => $code]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$group || strtotime((string)$group['invite_expires_at']) <= time()) {
        throw new RuntimeException('招待コードが正しくないか、有効期限（' . MEMBER_GROUP_INVITE_DAYS . '日）が切れています。');
    }
    if ((string)$group['status'] !== MEMBER_GROUP_STATUS_ACTIVE) {
        throw new RuntimeException('このグループは削除申請中のため参加できません。');
    }
    $pdo->prepare('INSERT IGNORE INTO member_group_members (group_id, user_id) VALUES (:group_id, :user_id)')
        ->execute([':group_id' => (int)$group['id'], ':user_id' => $user_id]);
    return (string)$group['name'];
}

function rename_member_group(PDO $pdo, int $user_id, int $group_id, string $raw_name): void
{
    member_group_require_owned_active($pdo, $group_id, $user_id);
    $pdo->prepare('UPDATE member_groups SET name = :name WHERE id = :id')
        ->execute([':name' => member_group_normalize_name($raw_name), ':id' => $group_id]);
}

// 脱退する（作成者は脱退できない）。自分の日記をそのグループに公開していた分も外す
function leave_member_group(PDO $pdo, int $user_id, int $group_id): void
{
    $stmt = $pdo->prepare('SELECT owner_user_id FROM member_groups WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $group_id]);
    $owner_id = $stmt->fetchColumn();
    if ($owner_id === false || !member_group_is_member($pdo, $group_id, $user_id)) {
        throw new RuntimeException('このグループには参加していません。');
    }
    if ((int)$owner_id === $user_id) {
        throw new RuntimeException('作成したグループからは脱退できません。不要な場合は削除を申請してください。');
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM member_group_members WHERE group_id = :group_id AND user_id = :user_id')
            ->execute([':group_id' => $group_id, ':user_id' => $user_id]);
        $pdo->prepare(
            'DELETE dg FROM training_diary_groups dg
             INNER JOIN training_diaries d ON d.id = dg.diary_id
             WHERE dg.group_id = :group_id AND d.user_id = :user_id'
        )->execute([':group_id' => $group_id, ':user_id' => $user_id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ---- 削除申請 ----

// 管理者のID（権限が admin の会員と、管理者として扱う「永楽晴三」）
function member_group_admin_user_ids(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT id FROM members WHERE role = 'admin' OR name = '永楽晴三'");
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// 作成者が削除を申請する。グループは「削除申請中」になり、管理者のベルに通知が届く
function request_member_group_deletion(PDO $pdo, int $user_id, int $group_id): void
{
    $group = member_group_require_owned_active($pdo, $group_id, $user_id);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE member_groups SET status = :status, invite_code = NULL, invite_expires_at = NULL WHERE id = :id')
            ->execute([':status' => MEMBER_GROUP_STATUS_DELETE_REQUESTED, ':id' => $group_id]);
        $pdo->prepare('INSERT INTO member_group_delete_requests (group_id, group_name, requester_user_id) VALUES (:group_id, :name, :user_id)')
            ->execute([':group_id' => $group_id, ':name' => (string)$group['name'], ':user_id' => $user_id]);
        $request_id = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $notification_title = 'グループ「' . (string)$group['name'] . '」の削除申請が届きました';
    $created = create_notification(
        $pdo,
        NOTIFICATION_SOURCE_GROUP_DELETE_REQUEST,
        $request_id,
        $notification_title,
        'mypage/front_login.php#admin-group-requests',
        NOTIFICATION_AUDIENCE_ADMIN
    );
    // 管理者の端末にもプッシュ通知（バナー）を送る（届かなくても申請自体は完了させる）
    if ($created) {
        require_once __DIR__ . '/web_push_lib.php';
        foreach (member_group_admin_user_ids($pdo) as $admin_user_id) {
            push_to_member($pdo, $admin_user_id, [
                'title' => 'グループの削除申請',
                'body' => $notification_title . '。',
                'url' => 'mypage/front_login.php#admin-group-requests',
                'tag' => 'group-delete-request-' . $request_id,
            ]);
        }
    }
}

// 管理者向け: 申請中の削除申請（古い順）
function fetch_pending_member_group_delete_requests(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        "SELECT r.id, r.group_id, r.group_name, r.requested_at, COALESCE(m.name, 'メンバー') AS requester_name,
                (SELECT COUNT(*) FROM member_group_members c WHERE c.group_id = r.group_id) AS member_count
         FROM member_group_delete_requests r
         LEFT JOIN members m ON m.id = r.requester_user_id
         WHERE r.status = :pending
         ORDER BY r.requested_at, r.id"
    );
    $stmt->execute([':pending' => MEMBER_GROUP_REQUEST_PENDING]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 管理者が申請を処理する。承認ならグループを消し（メンバー・日記の紐付けも消える）、却下なら通常の状態に戻す
function process_member_group_delete_request(PDO $pdo, int $admin_user_id, int $request_id, bool $approve): void
{
    $stmt = $pdo->prepare('SELECT group_id, status FROM member_group_delete_requests WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $request_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$request || (string)$request['status'] !== MEMBER_GROUP_REQUEST_PENDING) {
        throw new RuntimeException('この申請はすでに処理されています。');
    }
    $group_id = (int)$request['group_id'];

    $pdo->beginTransaction();
    try {
        if ($approve) {
            $icon_stmt = $pdo->prepare('SELECT icon_path FROM member_groups WHERE id = :id LIMIT 1');
            $icon_stmt->execute([':id' => $group_id]);
            $old_icon = (string)($icon_stmt->fetchColumn() ?: '');
            $pdo->prepare('DELETE FROM member_groups WHERE id = :id')->execute([':id' => $group_id]);
        } else {
            $pdo->prepare('UPDATE member_groups SET status = :status WHERE id = :id')
                ->execute([':status' => MEMBER_GROUP_STATUS_ACTIVE, ':id' => $group_id]);
        }
        $pdo->prepare('UPDATE member_group_delete_requests SET status = :status, processed_at = NOW(), processed_by = :admin WHERE id = :id')
            ->execute([
                ':status' => $approve ? MEMBER_GROUP_REQUEST_APPROVED : MEMBER_GROUP_REQUEST_REJECTED,
                ':admin' => $admin_user_id,
                ':id' => $request_id,
            ]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    if ($approve) {
        member_group_delete_icon_file($old_icon);
    }
    // 処理が済んだ申請の通知は、管理者のベルから消す
    delete_notification_for_source($pdo, NOTIFICATION_SOURCE_GROUP_DELETE_REQUEST, $request_id);
}

// ---- 管理者のグループ管理 ----

// 申請がなくても管理者が削除できる人数（これ以下のグループ）
const MEMBER_GROUP_ADMIN_DELETE_MAX_MEMBERS = 2;

// 管理者向け: 今あるすべてのグループ（作った順）と、代表者・メンバーの名前・申請中の削除申請・活動状況。日記などの中身は返さない
function fetch_all_member_groups_for_admin(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        "SELECT g.id, g.name, g.status, g.created_at,
                (SELECT COUNT(*) FROM training_diary_groups dg WHERE dg.group_id = g.id) AS diary_count,
                (SELECT MAX(d.created_at) FROM training_diary_groups dg
                 INNER JOIN training_diaries d ON d.id = dg.diary_id
                 WHERE dg.group_id = g.id) AS last_posted_at,
                COALESCE(owner.name, 'メンバー') AS owner_name,
                (SELECT COUNT(*) FROM member_group_members c WHERE c.group_id = g.id) AS member_count,
                (SELECT GROUP_CONCAT(COALESCE(m.name, 'メンバー') ORDER BY gm.joined_at, gm.user_id SEPARATOR '、')
                 FROM member_group_members gm
                 LEFT JOIN members m ON m.id = gm.user_id
                 WHERE gm.group_id = g.id) AS member_names,
                (SELECT r.id FROM member_group_delete_requests r
                 WHERE r.group_id = g.id AND r.status = :pending
                 ORDER BY r.id DESC LIMIT 1) AS pending_request_id
         FROM member_groups g
         LEFT JOIN members owner ON owner.id = g.owner_user_id
         ORDER BY g.created_at, g.id"
    );
    $stmt->execute([':pending' => MEMBER_GROUP_REQUEST_PENDING]);

    $groups = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $member_count = (int)$row['member_count'];
        $groups[] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'status' => (string)$row['status'],
            'created_at' => (string)$row['created_at'],
            'owner_name' => (string)$row['owner_name'],
            'member_count' => $member_count,
            'member_names' => (string)($row['member_names'] ?? ''),
            'pending_request_id' => $row['pending_request_id'] !== null ? (int)$row['pending_request_id'] : null,
            'admin_can_delete' => $member_count <= MEMBER_GROUP_ADMIN_DELETE_MAX_MEMBERS,
            // 活動状況: グループに公開された日記の数と、最後に投稿された日時（日記の中身は返さない）
            'diary_count' => (int)$row['diary_count'],
            'last_posted_at' => $row['last_posted_at'] !== null ? (string)$row['last_posted_at'] : null,
        ];
    }
    return $groups;
}

// 管理者が申請なしで削除する（メンバーが2人以下のグループだけ）。申請中の削除申請があれば「承認」として片付ける
function admin_delete_small_member_group(PDO $pdo, int $admin_user_id, int $group_id): string
{
    $pdo->beginTransaction();
    try {
        // 削除の直前に人数を数え直す（画面を開いている間に人が増えていたら削除しない）
        $stmt = $pdo->prepare(
            'SELECT g.name, g.icon_path, (SELECT COUNT(*) FROM member_group_members c WHERE c.group_id = g.id) AS member_count
             FROM member_groups g WHERE g.id = :id LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':id' => $group_id]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$group) {
            throw new RuntimeException('このグループはすでに削除されています。');
        }
        if ((int)$group['member_count'] > MEMBER_GROUP_ADMIN_DELETE_MAX_MEMBERS) {
            throw new RuntimeException('メンバーが' . (MEMBER_GROUP_ADMIN_DELETE_MAX_MEMBERS + 1) . '人以上のグループは、グループからの削除申請がないと削除できません。');
        }
        $pending_stmt = $pdo->prepare('SELECT id FROM member_group_delete_requests WHERE group_id = :group_id AND status = :pending');
        $pending_stmt->execute([':group_id' => $group_id, ':pending' => MEMBER_GROUP_REQUEST_PENDING]);
        $pending_ids = array_map('intval', $pending_stmt->fetchAll(PDO::FETCH_COLUMN));

        $pdo->prepare('DELETE FROM member_groups WHERE id = :id')->execute([':id' => $group_id]);
        $pdo->prepare('UPDATE member_group_delete_requests SET status = :status, processed_at = NOW(), processed_by = :admin WHERE group_id = :group_id AND status = :pending')
            ->execute([':status' => MEMBER_GROUP_REQUEST_APPROVED, ':admin' => $admin_user_id, ':group_id' => $group_id, ':pending' => MEMBER_GROUP_REQUEST_PENDING]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    member_group_delete_icon_file((string)($group['icon_path'] ?? ''));
    foreach ($pending_ids as $pending_id) {
        delete_notification_for_source($pdo, NOTIFICATION_SOURCE_GROUP_DELETE_REQUEST, $pending_id);
    }
    return (string)$group['name'];
}

// 管理者がグループ名を変える（不適切な名前などのとき）。削除申請中のグループも変えられる。変更前の名前を返す
function admin_rename_member_group(PDO $pdo, int $group_id, string $raw_name): string
{
    $stmt = $pdo->prepare('SELECT name FROM member_groups WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $group_id]);
    $old_name = $stmt->fetchColumn();
    if ($old_name === false) {
        throw new RuntimeException('このグループはすでに削除されています。');
    }
    $pdo->prepare('UPDATE member_groups SET name = :name WHERE id = :id')
        ->execute([':name' => member_group_normalize_name($raw_name), ':id' => $group_id]);
    return (string)$old_name;
}

// ---- 日記の公開範囲 ----

// 日記を公開するグループを保存する（参加中で削除申請中でないグループだけ受け付ける）
function save_training_diary_groups(PDO $pdo, int $diary_id, int $user_id, array $raw_group_ids): void
{
    $allowed = member_group_postable_ids($pdo, $user_id);
    $group_ids = array_values(array_intersect(array_unique(array_map('intval', $raw_group_ids)), $allowed));
    $insert = $pdo->prepare('INSERT IGNORE INTO training_diary_groups (diary_id, group_id) VALUES (:diary_id, :group_id)');
    foreach ($group_ids as $group_id) {
        $insert->execute([':diary_id' => $diary_id, ':group_id' => $group_id]);
    }
}

// 投稿後に公開するグループを選び直す。いま選べるグループ（参加中で削除申請中でないもの）の紐付けだけ入れ替え、
// 削除申請中のグループとの紐付けはそのまま残す（画面に出ないので選び直せないため。脱退したグループの紐付けは脱退時に消える）
function replace_training_diary_groups(PDO $pdo, int $diary_id, int $user_id, array $raw_group_ids): void
{
    $allowed = member_group_postable_ids($pdo, $user_id);
    if (!empty($allowed)) {
        $placeholders = implode(',', array_fill(0, count($allowed), '?'));
        $pdo->prepare("DELETE FROM training_diary_groups WHERE diary_id = ? AND group_id IN ($placeholders)")
            ->execute(array_merge([$diary_id], $allowed));
    }
    save_training_diary_groups($pdo, $diary_id, $user_id, $raw_group_ids);
}

// ---- アイコン ----

function member_group_delete_icon_file(string $icon_path): void
{
    // 決まったフォルダの中のファイルだけ消す
    if ($icon_path === '' || strpos($icon_path, MEMBER_GROUP_ICON_DIR . '/') !== 0 || strpos($icon_path, '..') !== false) {
        return;
    }
    $absolute = __DIR__ . '/../' . $icon_path;
    if (is_file($absolute)) {
        @unlink($absolute);
    }
}

// 写真を、真ん中を正方形に切り抜いて MEMBER_GROUP_ICON_SIZE に縮めて保存する。
// $target_base は拡張子なしのパスで、JPEG で保存できればそれ、できないサーバーでは PNG にする。使った拡張子を返す。
// サーバーで画像を加工できないときは null（呼ぶ側で元の画像のまま保存する）
function member_group_save_resized_icon(string $source_path, string $target_base): ?string
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagecopyresampled')) {
        return null;
    }
    $source = @imagecreatefromstring((string)file_get_contents($source_path));
    if ($source === false) {
        return null;
    }
    $width = imagesx($source);
    $height = imagesy($source);
    $side = min($width, $height);
    $icon = imagecreatetruecolor(MEMBER_GROUP_ICON_SIZE, MEMBER_GROUP_ICON_SIZE);
    // 透過の画像は白い背景にのせる
    imagefill($icon, 0, 0, imagecolorallocate($icon, 255, 255, 255));
    imagecopyresampled($icon, $source, 0, 0, (int)(($width - $side) / 2), (int)(($height - $side) / 2), MEMBER_GROUP_ICON_SIZE, MEMBER_GROUP_ICON_SIZE, $side, $side);
    if (function_exists('imagejpeg')) {
        $extension = 'jpg';
        $saved = imagejpeg($icon, $target_base . '.jpg', 85);
    } else {
        $extension = 'png';
        $saved = imagepng($icon, $target_base . '.png', 9);
    }
    imagedestroy($icon);
    imagedestroy($source);
    return $saved ? $extension : null;
}

// 作成者がグループのアイコンを写真で決める（$file は $_FILES の1件）。前のアイコンの画像は消す
function set_member_group_icon(PDO $pdo, int $user_id, int $group_id, array $file): void
{
    member_group_require_owned_active($pdo, $group_id, $user_id);

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('写真を選んでください。');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || (int)($file['size'] ?? 0) <= 0
        || (int)$file['size'] > MEMBER_GROUP_ICON_MAX_BYTES) {
        throw new RuntimeException('写真は5MB以内にしてください。');
    }
    $tmp_name = (string)($file['tmp_name'] ?? '');
    if ($tmp_name === '' || !is_uploaded_file($tmp_name)) {
        throw new RuntimeException('写真を受け取れませんでした。もう一度お試しください。');
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string)finfo_file($finfo, $tmp_name) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('対応している写真の形式は JPG / PNG / WEBP / GIF です。');
    }

    $upload_dir = __DIR__ . '/../' . MEMBER_GROUP_ICON_DIR;
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $base_name = 'group_' . $group_id . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
    $resized_extension = member_group_save_resized_icon($tmp_name, __DIR__ . '/../' . MEMBER_GROUP_ICON_DIR . '/' . $base_name);
    if ($resized_extension !== null) {
        $relative = MEMBER_GROUP_ICON_DIR . '/' . $base_name . '.' . $resized_extension;
    } else {
        $relative = MEMBER_GROUP_ICON_DIR . '/' . $base_name . '.' . $allowed[$mime];
        if (!move_uploaded_file($tmp_name, __DIR__ . '/../' . $relative)) {
            throw new RuntimeException('写真の保存に失敗しました。');
        }
    }

    $old_stmt = $pdo->prepare('SELECT icon_path FROM member_groups WHERE id = :id LIMIT 1');
    $old_stmt->execute([':id' => $group_id]);
    $old_icon = (string)($old_stmt->fetchColumn() ?: '');
    try {
        $pdo->prepare('UPDATE member_groups SET icon_path = :path WHERE id = :id')->execute([':path' => $relative, ':id' => $group_id]);
    } catch (Throwable $e) {
        member_group_delete_icon_file($relative);
        throw $e;
    }
    member_group_delete_icon_file($old_icon);
}

// アイコンを外して、標準の人のアイコンに戻す
function remove_member_group_icon(PDO $pdo, int $user_id, int $group_id): void
{
    member_group_require_owned_active($pdo, $group_id, $user_id);
    $old_stmt = $pdo->prepare('SELECT icon_path FROM member_groups WHERE id = :id LIMIT 1');
    $old_stmt->execute([':id' => $group_id]);
    $old_icon = (string)($old_stmt->fetchColumn() ?: '');
    $pdo->prepare('UPDATE member_groups SET icon_path = NULL WHERE id = :id')->execute([':id' => $group_id]);
    member_group_delete_icon_file($old_icon);
}
