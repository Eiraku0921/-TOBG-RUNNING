<?php

function ensure_tournament_owner_columns(PDO $pdo): void
{
    $columns = [
        'owner_user_id' => 'ALTER TABLE tournaments ADD COLUMN owner_user_id INT NULL AFTER id',
        'self_managed' => 'ALTER TABLE tournaments ADD COLUMN self_managed TINYINT(1) NOT NULL DEFAULT 0 AFTER owner_user_id',
        'owner_notify_email' => 'ALTER TABLE tournaments ADD COLUMN owner_notify_email VARCHAR(255) NULL AFTER self_managed',
    ];

    foreach ($columns as $column_name => $alter_sql) {
        $stmt = $pdo->query('SHOW COLUMNS FROM tournaments LIKE ' . $pdo->quote($column_name));
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec($alter_sql);
        }
    }
}

function tournament_is_managed_by(array $tournament_row, int $user_id): bool
{
    return (int)($tournament_row['owner_user_id'] ?? 0) === $user_id
        && (int)($tournament_row['self_managed'] ?? 0) === 1;
}

function member_is_jaaf_registered(array $member_row, ?string $jaaf_id_col, ?string $number_plate_col, ?string $jaaf_registered_col): bool
{
    $jaaf_id_value = $jaaf_id_col !== null ? trim((string)($member_row[$jaaf_id_col] ?? '')) : '';
    $number_plate_value = $number_plate_col !== null ? trim((string)($member_row[$number_plate_col] ?? '')) : '';

    if ($jaaf_id_value === '0000' && $number_plate_value === '0000') {
        return false;
    }

    if ($jaaf_registered_col !== null && isset($member_row[$jaaf_registered_col])) {
        $registered_raw = strtolower(trim((string)$member_row[$jaaf_registered_col]));
        if (in_array($registered_raw, ['1', 'true', 'yes', '済', 'registered'], true)) {
            return true;
        }
    }

    return $jaaf_id_value !== '';
}

// members テーブルで陸連登録の判定に使う列名 [JAAFID, ゼッケン番号, 登録フラグ]（無い列は null）
function member_jaaf_columns(PDO $pdo): array
{
    $member_columns = array_column($pdo->query('SHOW COLUMNS FROM members')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $pick_column = static function (array $candidates) use ($member_columns): ?string {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $member_columns, true)) {
                return $candidate;
            }
        }
        return null;
    };

    return [
        $pick_column(['jaaf_id', 'jaafid', 'jaaf_no']),
        $pick_column(['number_plate', 'bib_number', 'zekken_no']),
        $pick_column(['jaaf_registered', 'jaaf_registration_status', 'jaaf_registered_flag']),
    ];
}

function member_id_is_jaaf_registered(PDO $pdo, int $user_id): bool
{
    $stmt = $pdo->prepare('SELECT * FROM members WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $user_id]);
    $member_row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$member_row) {
        return false;
    }
    [$jaaf_id_col, $number_plate_col, $jaaf_registered_col] = member_jaaf_columns($pdo);
    return member_is_jaaf_registered($member_row, $jaaf_id_col, $number_plate_col, $jaaf_registered_col);
}

// メールアドレスを登録している陸連登録者（$exclude_user_id の会員は除く）
// 陸連登録者の一覧（$exclude_user_id の会員は除く）。メールの有無は問わない（プッシュ通知だけの人にも届けるため）
function fetch_jaaf_members(PDO $pdo, int $exclude_user_id = 0): array
{
    $stmt = $pdo->prepare('SELECT * FROM members WHERE id <> :exclude_id');
    $stmt->execute([':exclude_id' => $exclude_user_id]);
    [$jaaf_id_col, $number_plate_col, $jaaf_registered_col] = member_jaaf_columns($pdo);

    $members = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $member_row) {
        if (member_is_jaaf_registered($member_row, $jaaf_id_col, $number_plate_col, $jaaf_registered_col)) {
            $members[] = $member_row;
        }
    }
    return $members;
}

function cleanup_expired_tournament_data(PDO $pdo): void
{
    $delete_matches_sql = "
        DELETE m
        FROM matches m
        INNER JOIN (
            SELECT event_name, MAX(event_date) AS event_date
            FROM tournaments
            GROUP BY event_name
        ) t ON t.event_name = m.match_title
        WHERE t.event_date < CURDATE()
    ";

    $delete_tournaments_sql = "
        DELETE FROM tournaments
        WHERE event_date < CURDATE()
    ";

    $pdo->exec($delete_matches_sql);
    $pdo->exec($delete_tournaments_sql);
}