<?php

// ピックアップの会員（みんなのページの「ピックアップ」タブに、この人たちの投稿を並べる）。
// 管理者がマイページの会員一覧で選ぶ。選ばれている間だけ1行あり、外すと行を消す。

function ensure_pickup_members_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS pickup_members (
            user_id INT NOT NULL PRIMARY KEY,
            picked_by INT NOT NULL,
            picked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

// ピックアップの会員の id（新しく選んだ順）
function fetch_pickup_member_ids(PDO $pdo): array
{
    ensure_pickup_members_table($pdo);
    return array_map('intval', $pdo->query('SELECT user_id FROM pickup_members ORDER BY picked_at DESC, user_id ASC')->fetchAll(PDO::FETCH_COLUMN));
}

// 選ぶ（$picked が true）・外す。もう同じ状態なら何もしない
function set_pickup_member(PDO $pdo, int $user_id, bool $picked, int $admin_user_id): void
{
    ensure_pickup_members_table($pdo);
    if ($picked) {
        $pdo->prepare('INSERT IGNORE INTO pickup_members (user_id, picked_by) VALUES (:user_id, :picked_by)')
            ->execute([':user_id' => $user_id, ':picked_by' => $admin_user_id]);
        return;
    }
    $pdo->prepare('DELETE FROM pickup_members WHERE user_id = :user_id')->execute([':user_id' => $user_id]);
}
