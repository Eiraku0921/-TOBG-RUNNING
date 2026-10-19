<?php

require_once __DIR__ . '/member_avatar_lib.php';
require_once __DIR__ . '/member_role_lib.php';

function ensure_training_diary_table(PDO $pdo): void
{
    $sql = "
        CREATE TABLE IF NOT EXISTS training_diaries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            practice_date DATE NOT NULL,
            menu VARCHAR(255) NOT NULL,
            record_time TEXT NOT NULL,
            comment TEXT NULL,
            image_path VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_practice_date (practice_date),
            INDEX idx_user_id (user_id),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    $pdo->exec($sql);

    $columns_stmt = $pdo->query('SHOW COLUMNS FROM training_diaries');
    $columns = $columns_stmt->fetchAll(PDO::FETCH_ASSOC);
    $has_updated_at = false;
    $has_video_path = false;
    $has_is_public = false;
    $has_intensity = false;
    $has_training_type = false;
    $training_type_needs_widen = false;
    $record_time_nullable = false;

    foreach ($columns as $column) {
        if (($column['Field'] ?? '') === 'updated_at') {
            $has_updated_at = true;
        }
        if (($column['Field'] ?? '') === 'video_path') {
            $has_video_path = true;
        }
        if (($column['Field'] ?? '') === 'is_public') {
            $has_is_public = true;
        }
        if (($column['Field'] ?? '') === 'intensity') {
            $has_intensity = true;
        }
        if (($column['Field'] ?? '') === 'training_type') {
            $has_training_type = true;
            if (preg_match('/varchar\((\d+)\)/i', (string)($column['Type'] ?? ''), $matches) && (int)$matches[1] < 20) {
                $training_type_needs_widen = true;
            }
        }
        if (($column['Field'] ?? '') === 'record_time' && strtoupper((string)($column['Null'] ?? '')) === 'YES') {
            $record_time_nullable = true;
        }
    }

    if (!$has_updated_at) {
        $pdo->exec("ALTER TABLE training_diaries ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    }
    if (!$has_video_path) {
        $pdo->exec("ALTER TABLE training_diaries ADD COLUMN video_path VARCHAR(255) NULL AFTER image_path");
    }
    if (!$has_is_public) {
        $pdo->exec("ALTER TABLE training_diaries ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 1 AFTER comment");
    }
    if (!$has_intensity) {
        $pdo->exec("ALTER TABLE training_diaries ADD COLUMN intensity VARCHAR(20) NOT NULL DEFAULT 'normal' AFTER is_public");
    }
    if (!$has_training_type) {
        $pdo->exec("ALTER TABLE training_diaries ADD COLUMN training_type VARCHAR(20) NOT NULL DEFAULT 'short_sprint' AFTER menu");
    } elseif ($training_type_needs_widen) {
        $pdo->exec("ALTER TABLE training_diaries MODIFY COLUMN training_type VARCHAR(20) NOT NULL DEFAULT 'short_sprint'");
    }
    if (!$record_time_nullable) {
        $pdo->exec("ALTER TABLE training_diaries MODIFY COLUMN record_time TEXT NULL");
    }
}

function training_diary_type_options(): array
{
    return [
        'short_sprint' => 'ショートスプ',
        'long_sprint' => 'ロングスプ',
        'jog' => 'ジョグ',
        'weight' => 'ウエイト',
        'race' => '大会',
    ];
}

// ---- ジョグ（距離 km を1行ずつ。例: 朝 5.0km ＋ 夜 3.5km） ----

// ジョグで選べる距離（仕様書: 整数 0〜42 と小数第1位 0〜9 を選ぶので、0.1〜42.9km）
const TRAINING_DIARY_JOG_MAX_KM = 42.9;

// 日記を消すと、ジョグの行も外部キーで一緒に消える（ON DELETE CASCADE）
function ensure_training_diary_jog_table(PDO $pdo): void
{
    ensure_training_diary_table($pdo);
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS training_diary_jog_entries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            diary_id INT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            distance_km DECIMAL(4,1) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_training_diary_jog_entries_diary (diary_id),
            CONSTRAINT fk_training_diary_jog_entries_diary FOREIGN KEY (diary_id)
                REFERENCES training_diaries (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function fetch_training_diary_jog_entries(PDO $pdo, int $diary_id): array
{
    $stmt = $pdo->prepare(
        'SELECT distance_km FROM training_diary_jog_entries
         WHERE diary_id = :diary_id
         ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([':diary_id' => $diary_id]);
    return array_map(
        static fn(array $row): array => ['distance_km' => (float)$row['distance_km']],
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    );
}

// ジョグの距離の文字。1行なら「8.5km」、複数行なら「5.0km ＋ 3.5km（合計 8.5km）」（js/diary_timeline.js と同じ書き方）
function format_training_diary_jog_distances(array $entries): string
{
    $list = array_values(array_filter(
        array_map(static fn(array $entry): float => (float)($entry['distance_km'] ?? 0), $entries),
        static fn(float $km): bool => $km > 0
    ));
    if (count($list) === 0) {
        return '';
    }
    $parts = array_map(static fn(float $km): string => number_format($km, 1) . 'km', $list);
    if (count($list) === 1) {
        return $parts[0];
    }
    return implode(' ＋ ', $parts) . '（合計 ' . number_format(array_sum($list), 1) . 'km）';
}

// $entries は [['distance_km' => 8.5], ...]（値の範囲は呼ぶ側で確かめておく）
function insert_training_diary_jog_entries(PDO $pdo, int $diary_id, array $entries): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO training_diary_jog_entries (diary_id, sort_order, distance_km)
         VALUES (:diary_id, :sort_order, :distance_km)'
    );
    foreach (array_values($entries) as $sort_order => $entry) {
        $stmt->execute([
            ':diary_id' => $diary_id,
            ':sort_order' => $sort_order,
            ':distance_km' => $entry['distance_km'],
        ]);
    }
}

function training_diary_is_running_type(string $training_type): bool
{
    return in_array($training_type, ['short_sprint', 'long_sprint'], true);
}

// 風速を計測する種目。フォーム（JS）にもこの一覧を渡して、入力欄の出し分けに使う
const TRAINING_DIARY_WIND_EVENTS = ['100m', '200m', '走幅跳', '三段跳'];

// 追い風がこれを超えると公認記録にならない（m/s）
const TRAINING_DIARY_WIND_LIMIT = 2.0;

function ensure_training_diary_race_entries_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS training_diary_race_entries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            diary_id INT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            event_name VARCHAR(50) NOT NULL,
            result_label VARCHAR(30) NOT NULL,
            wind_mps DECIMAL(3,1) NULL,
            is_official TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_training_diary_race_entries_diary (diary_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // 風速・公認の列が無い頃に作られたテーブルへの追加
    $columns = [
        'wind_mps' => "ALTER TABLE training_diary_race_entries ADD COLUMN wind_mps DECIMAL(3,1) NULL AFTER result_label",
        'is_official' => "ALTER TABLE training_diary_race_entries ADD COLUMN is_official TINYINT(1) NOT NULL DEFAULT 1 AFTER wind_mps",
    ];
    foreach ($columns as $column_name => $alter_sql) {
        if (!$pdo->query('SHOW COLUMNS FROM training_diary_race_entries LIKE ' . $pdo->quote($column_name))->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec($alter_sql);
        }
    }
}

function training_diary_event_uses_wind(string $event_name): bool
{
    return in_array($event_name, TRAINING_DIARY_WIND_EVENTS, true);
}

// 公認かどうかは風速から自動で決める（利用者は選べない）。
// 風を計測する種目は「追い風2.0以下」なら公認、2.0超え・計測なしは非公認。風を計測しない種目は公認
function training_diary_race_entry_is_official(string $event_name, ?float $wind_mps): bool
{
    if (!training_diary_event_uses_wind($event_name)) {
        return true;
    }
    return $wind_mps !== null && $wind_mps <= TRAINING_DIARY_WIND_LIMIT;
}

// 歴代記録の対象になる記録か。非公認、または風を計測する種目で「追い風2.0超え・計測なし」なら対象外
function training_diary_race_entry_counts_for_records(string $event_name, ?float $wind_mps, bool $is_official): bool
{
    if (!$is_official) {
        return false;
    }
    if (!training_diary_event_uses_wind($event_name)) {
        return true;
    }
    return $wind_mps !== null && $wind_mps <= TRAINING_DIARY_WIND_LIMIT;
}

function fetch_training_diary_race_entries(PDO $pdo, int $diary_id): array
{
    $stmt = $pdo->prepare(
        "SELECT id, diary_id, sort_order, event_name, result_label, wind_mps, is_official
         FROM training_diary_race_entries
         WHERE diary_id = :diary_id
         ORDER BY sort_order ASC, id ASC"
    );
    $stmt->execute([':diary_id' => $diary_id]);

    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($entries as &$entry) {
        $entry['wind_mps'] = $entry['wind_mps'] !== null ? (float)$entry['wind_mps'] : null;
        $entry['is_official'] = (int)$entry['is_official'] === 1;
        $entry['uses_wind'] = training_diary_event_uses_wind((string)$entry['event_name']);
        $entry['counts_for_records'] = training_diary_race_entry_counts_for_records(
            (string)$entry['event_name'],
            $entry['wind_mps'],
            $entry['is_official']
        );
        $entry['notes_label'] = format_training_diary_race_entry_notes($entry);
    }
    unset($entry);

    return $entries;
}

function insert_training_diary_race_entries(PDO $pdo, int $diary_id, array $entries): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO training_diary_race_entries (diary_id, sort_order, event_name, result_label, wind_mps, is_official)
         VALUES (:diary_id, :sort_order, :event_name, :result_label, :wind_mps, :is_official)"
    );

    $sort_order = 0;
    foreach ($entries as $entry) {
        $stmt->execute([
            ':diary_id' => $diary_id,
            ':sort_order' => $sort_order,
            ':event_name' => $entry['event_name'],
            ':result_label' => $entry['result_label'],
            ':wind_mps' => $entry['wind_mps'],
            ':is_official' => $entry['is_official'] ? 1 : 0,
        ]);
        $sort_order++;
    }
}

// 風速・公認の表示用の文字（例: 「+1.2m/s・公認」「風計測なし・非公認」）。風を計測しない種目は公認/非公認だけ
function format_training_diary_race_entry_notes(array $entry): string
{
    $notes = [];
    if (!empty($entry['uses_wind'])) {
        $notes[] = $entry['wind_mps'] === null
            ? '風計測なし'
            : sprintf('%+.1fm/s', (float)$entry['wind_mps']);
    }
    $notes[] = !empty($entry['is_official']) ? '公認' : '非公認';
    return implode('・', $notes);
}

function training_diary_weight_body_parts(): array
{
    return ['胸', '背中', '腕', '下半身'];
}

function ensure_training_diary_weight_sets_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS training_diary_weight_sets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            diary_id INT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            body_part VARCHAR(20) NOT NULL,
            exercise_name VARCHAR(100) NOT NULL,
            reps SMALLINT UNSIGNED NULL,
            sets SMALLINT UNSIGNED NULL,
            weight_kg DECIMAL(5,2) NULL,
            is_bodyweight TINYINT(1) NOT NULL DEFAULT 0,
            weight_note VARCHAR(50) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_training_diary_weight_sets_diary (diary_id),
            INDEX idx_training_diary_weight_sets_part (body_part)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function fetch_training_diary_weight_sets(PDO $pdo, int $diary_id): array
{
    $stmt = $pdo->prepare(
        "SELECT id, diary_id, sort_order, body_part, exercise_name, reps, sets, weight_kg, is_bodyweight, weight_note
         FROM training_diary_weight_sets
         WHERE diary_id = :diary_id
         ORDER BY sort_order ASC, id ASC"
    );
    $stmt->execute([':diary_id' => $diary_id]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['reps'] = $row['reps'] !== null ? (int)$row['reps'] : null;
        $row['sets'] = $row['sets'] !== null ? (int)$row['sets'] : null;
        $row['weight_kg'] = $row['weight_kg'] !== null ? (float)$row['weight_kg'] : null;
        $row['is_bodyweight'] = (int)$row['is_bodyweight'] === 1;
    }
    unset($row);

    return $rows;
}

function insert_training_diary_weight_sets(PDO $pdo, int $diary_id, array $sets): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO training_diary_weight_sets
            (diary_id, sort_order, body_part, exercise_name, reps, sets, weight_kg, is_bodyweight, weight_note)
         VALUES
            (:diary_id, :sort_order, :body_part, :exercise_name, :reps, :sets, :weight_kg, :is_bodyweight, :weight_note)"
    );

    $sort_order = 0;
    foreach ($sets as $set) {
        $stmt->execute([
            ':diary_id' => $diary_id,
            ':sort_order' => $sort_order,
            ':body_part' => $set['body_part'],
            ':exercise_name' => $set['exercise_name'],
            ':reps' => $set['reps'],
            ':sets' => $set['sets'],
            ':weight_kg' => $set['weight_kg'],
            ':is_bodyweight' => $set['is_bodyweight'] ? 1 : 0,
            ':weight_note' => $set['weight_note'],
        ]);
        $sort_order++;
    }
}

function ensure_training_diary_running_tables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS training_diary_running_sets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            diary_id INT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            workout_type VARCHAR(20) NOT NULL,
            distance_label VARCHAR(30) NOT NULL,
            target_time_label VARCHAR(30) NULL,
            reps SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            sets SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_training_diary_running_sets_diary (diary_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $columns_stmt = $pdo->query('SHOW COLUMNS FROM training_diary_running_sets');
    $columns = $columns_stmt->fetchAll(PDO::FETCH_ASSOC);
    $has_rest_label = false;
    $has_target_time_label = false;
    foreach ($columns as $column) {
        if (($column['Field'] ?? '') === 'rest_label') {
            $has_rest_label = true;
        }
        if (($column['Field'] ?? '') === 'target_time_label') {
            $has_target_time_label = true;
        }
    }
    if ($has_rest_label && !$has_target_time_label) {
        $pdo->exec('ALTER TABLE training_diary_running_sets CHANGE rest_label target_time_label VARCHAR(30) NULL');
    } elseif (!$has_target_time_label) {
        $pdo->exec('ALTER TABLE training_diary_running_sets ADD COLUMN target_time_label VARCHAR(30) NULL AFTER distance_label');
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS training_diary_running_laps (
            id INT AUTO_INCREMENT PRIMARY KEY,
            running_set_id INT NOT NULL,
            lap_number SMALLINT UNSIGNED NOT NULL,
            lap_time_seconds DECIMAL(6,2) NULL,
            lap_time_label VARCHAR(20) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_training_diary_running_laps_set (running_set_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function fetch_training_diary_running_sets(PDO $pdo, int $diary_id): array
{
    $stmt = $pdo->prepare(
        "SELECT id, diary_id, sort_order, workout_type, distance_label, target_time_label, reps, sets
         FROM training_diary_running_sets
         WHERE diary_id = :diary_id
         ORDER BY sort_order ASC, id ASC"
    );
    $stmt->execute([':diary_id' => $diary_id]);
    $sets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($sets) === 0) {
        return [];
    }

    $set_ids = array_map(static function ($set) {
        return (int)$set['id'];
    }, $sets);

    $placeholders = implode(',', array_fill(0, count($set_ids), '?'));
    $lap_stmt = $pdo->prepare(
        "SELECT running_set_id, lap_number, lap_time_seconds, lap_time_label
         FROM training_diary_running_laps
         WHERE running_set_id IN ($placeholders)
         ORDER BY running_set_id ASC, lap_number ASC"
    );
    $lap_stmt->execute($set_ids);
    $laps_by_set = [];
    foreach ($lap_stmt->fetchAll(PDO::FETCH_ASSOC) as $lap_row) {
        $running_set_id = (int)$lap_row['running_set_id'];
        if (!isset($laps_by_set[$running_set_id])) {
            $laps_by_set[$running_set_id] = [];
        }
        $laps_by_set[$running_set_id][] = [
            'lap_number' => (int)$lap_row['lap_number'],
            'lap_time_seconds' => $lap_row['lap_time_seconds'] !== null ? (float)$lap_row['lap_time_seconds'] : null,
            'lap_time_label' => $lap_row['lap_time_label'],
        ];
    }

    foreach ($sets as &$set) {
        $set['reps'] = (int)$set['reps'];
        $set['sets'] = (int)$set['sets'];
        $set['laps'] = $laps_by_set[(int)$set['id']] ?? [];
    }
    unset($set);

    return $sets;
}

function insert_training_diary_running_sets(PDO $pdo, int $diary_id, array $sets): void
{
    $set_stmt = $pdo->prepare(
        "INSERT INTO training_diary_running_sets
            (diary_id, sort_order, workout_type, distance_label, target_time_label, reps, sets)
         VALUES
            (:diary_id, :sort_order, :workout_type, :distance_label, :target_time_label, :reps, :sets)"
    );

    $lap_stmt = $pdo->prepare(
        "INSERT INTO training_diary_running_laps
            (running_set_id, lap_number, lap_time_seconds, lap_time_label)
         VALUES
            (:running_set_id, :lap_number, :lap_time_seconds, :lap_time_label)"
    );

    $sort_order = 0;
    foreach ($sets as $set) {
        $set_stmt->execute([
            ':diary_id' => $diary_id,
            ':sort_order' => $sort_order,
            ':workout_type' => $set['workout_type'],
            ':distance_label' => $set['distance_label'],
            ':target_time_label' => $set['target_time_label'],
            ':reps' => $set['reps'],
            ':sets' => $set['sets'],
        ]);
        $running_set_id = (int)$pdo->lastInsertId();

        $lap_number = 1;
        foreach ($set['laps'] as $lap) {
            if ($lap['lap_time_seconds'] === null && $lap['lap_time_label'] === null) {
                $lap_number++;
                continue;
            }
            $lap_stmt->execute([
                ':running_set_id' => $running_set_id,
                ':lap_number' => $lap_number,
                ':lap_time_seconds' => $lap['lap_time_seconds'],
                ':lap_time_label' => $lap['lap_time_label'],
            ]);
            $lap_number++;
        }

        $sort_order++;
    }
}

function training_diary_intensity_options(): array
{
    return [
        'point' => 'ポイント',
        'normal' => '通常',
        'light' => '落とし',
        'weight' => 'ウエイト',
        'race' => '大会',
    ];
}

function ensure_training_diary_interaction_tables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS training_diary_comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            diary_id INT NOT NULL,
            user_id INT NOT NULL,
            comment_text TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_training_diary_comments_diary (diary_id),
            INDEX idx_training_diary_comments_user (user_id),
            INDEX idx_training_diary_comments_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS training_diary_likes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            diary_id INT NOT NULL,
            user_id INT NULL,
            guest_session_id VARCHAR(128) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_training_diary_like_user (diary_id, user_id),
            UNIQUE KEY uniq_training_diary_like_guest (diary_id, guest_session_id),
            INDEX idx_training_diary_likes_diary (diary_id),
            INDEX idx_training_diary_likes_user (user_id),
            INDEX idx_training_diary_likes_guest (guest_session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $columns_stmt = $pdo->query('SHOW COLUMNS FROM training_diary_likes');
    $columns = $columns_stmt->fetchAll(PDO::FETCH_ASSOC);
    $has_guest_session_id = false;
    $user_id_nullable = false;

    foreach ($columns as $column) {
        if (($column['Field'] ?? '') === 'guest_session_id') {
            $has_guest_session_id = true;
        }
        if (($column['Field'] ?? '') === 'user_id' && strtoupper((string)($column['Null'] ?? '')) === 'YES') {
            $user_id_nullable = true;
        }
    }

    if (!$has_guest_session_id) {
        $pdo->exec('ALTER TABLE training_diary_likes ADD COLUMN guest_session_id VARCHAR(128) NULL AFTER user_id');
    }

    if (!$user_id_nullable) {
        $pdo->exec('ALTER TABLE training_diary_likes MODIFY COLUMN user_id INT NULL');
    }

    $index_stmt = $pdo->query('SHOW INDEX FROM training_diary_likes');
    $indexes = $index_stmt->fetchAll(PDO::FETCH_ASSOC);

    $index_columns = [];
    foreach ($indexes as $index_row) {
        $key_name = (string)($index_row['Key_name'] ?? '');
        $seq = (int)($index_row['Seq_in_index'] ?? 0);
        $column_name = (string)($index_row['Column_name'] ?? '');
        if (!isset($index_columns[$key_name])) {
            $index_columns[$key_name] = [];
        }
        $index_columns[$key_name][$seq] = $column_name;
    }

    $has_user_unique = false;
    $has_guest_unique = false;
    foreach ($index_columns as $cols) {
        ksort($cols);
        $ordered = array_values($cols);
        if ($ordered === ['diary_id', 'user_id']) {
            $has_user_unique = true;
        }
        if ($ordered === ['diary_id', 'guest_session_id']) {
            $has_guest_unique = true;
        }
    }

    if (!$has_user_unique) {
        $pdo->exec('ALTER TABLE training_diary_likes ADD UNIQUE KEY uniq_training_diary_like_user (diary_id, user_id)');
    }

    if (!$has_guest_unique) {
        $pdo->exec('ALTER TABLE training_diary_likes ADD UNIQUE KEY uniq_training_diary_like_guest (diary_id, guest_session_id)');
    }
}

function fetch_training_diary_like_summary(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT diary_id, COUNT(*) AS like_count
         FROM training_diary_likes
         GROUP BY diary_id"
    );

    $summary = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $summary[(int)($row['diary_id'] ?? 0)] = (int)($row['like_count'] ?? 0);
    }

    return $summary;
}

function fetch_training_diary_comment_summary(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT diary_id, COUNT(*) AS comment_count
         FROM training_diary_comments
         GROUP BY diary_id"
    );

    $summary = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $summary[(int)($row['diary_id'] ?? 0)] = (int)($row['comment_count'] ?? 0);
    }

    return $summary;
}

function fetch_training_diary_comments(PDO $pdo, int $diary_id): array
{
    $stmt = $pdo->prepare(
        "SELECT c.id, c.diary_id, c.user_id, c.comment_text, c.created_at, c.updated_at, COALESCE(m.name, 'メンバー') AS user_name
         FROM training_diary_comments c
         LEFT JOIN members m ON m.id = c.user_id
         WHERE c.diary_id = :diary_id
         ORDER BY c.created_at ASC, c.id ASC"
    );
    $stmt->execute([':diary_id' => $diary_id]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 日記の保存期間。大会の日記は NEXT PR（自己ベスト）・歴代記録の元になるので長く残す
const TRAINING_DIARY_KEEP_YEARS = 3;
const TRAINING_DIARY_RACE_KEEP_YEARS = 5;

function cleanup_old_training_diaries(PDO $pdo): void
{
    ensure_training_diary_race_entries_table($pdo);

    // 消す日記の大会記録も一緒に消す（日記だけ消すと記録が行き場なく残るため）
    $pdo->exec(
        "DELETE e FROM training_diary_race_entries e
         INNER JOIN training_diaries d ON d.id = e.diary_id
         WHERE d.training_type = 'race'
           AND d.practice_date < DATE_SUB(CURDATE(), INTERVAL " . TRAINING_DIARY_RACE_KEEP_YEARS . " YEAR)"
    );

    $delete_sql = "
        DELETE FROM training_diaries
        WHERE (training_type = 'race' AND practice_date < DATE_SUB(CURDATE(), INTERVAL " . TRAINING_DIARY_RACE_KEEP_YEARS . " YEAR))
           OR (training_type <> 'race' AND practice_date < DATE_SUB(CURDATE(), INTERVAL " . TRAINING_DIARY_KEEP_YEARS . " YEAR))
    ";
    $pdo->exec($delete_sql);

    // 消した日記の、グループ公開の紐付けも消す（グループ機能の表があるときだけ）
    if ($pdo->query("SHOW TABLES LIKE 'training_diary_groups'")->fetch()) {
        $pdo->exec(
            'DELETE dg FROM training_diary_groups dg
             LEFT JOIN training_diaries d ON d.id = dg.diary_id
             WHERE d.id IS NULL'
        );
    }
}

// みんなの練習日記に表示する期間（当月を含む月数）。データ自体は cleanup_old_training_diaries の保存期間まで残る
const PUBLIC_TRAINING_DIARY_VISIBLE_MONTHS = 3;

// 表示する最初の日（例: 9月なら 7月1日）を Y-m-d で返す
function public_training_diary_visible_from(): string
{
    return date('Y-m-01', strtotime('first day of -' . (PUBLIC_TRAINING_DIARY_VISIBLE_MONTHS - 1) . ' month'));
}

// 公開されている練習日記（新しい順）。$current_user_role は見ている人の権限で、各投稿にコメントを書けるか（can_comment）を付ける。
// $group_id を指定すると、全体公開ではなく、そのグループに公開された日記だけを返す（メンバーかどうかは呼ぶ側で確かめる）
function fetch_public_training_diary_feed(PDO $pdo, int $current_user_id = 0, string $current_user_role = '', int $group_id = 0): array
{
    ensure_training_diary_table($pdo);
    ensure_training_diary_interaction_tables($pdo);
    ensure_training_diary_weight_sets_table($pdo);
    ensure_training_diary_running_tables($pdo);
    ensure_training_diary_race_entries_table($pdo);
    ensure_training_diary_jog_table($pdo);
    ensure_member_avatar_column($pdo);
    ensure_member_role_column($pdo);
    cleanup_old_training_diaries($pdo);

    $comment_summary = fetch_training_diary_comment_summary($pdo);
    $like_summary = fetch_training_diary_like_summary($pdo);

    $stmt = $pdo->prepare(
        "SELECT
            d.id,
            d.user_id,
            COALESCE(m.name, 'メンバー') AS user_name,
            d.practice_date,
            d.menu AS menu_text,
            d.training_type,
            d.intensity,
            d.record_time AS time_text,
            d.comment AS comment_text,
            d.image_path,
            d.video_path,
            d.created_at,
            d.updated_at,
            m.avatar_image_path,
            m.role AS owner_role
         FROM training_diaries d
         LEFT JOIN members m ON m.id = d.user_id
         WHERE " . ($group_id > 0
            ? 'EXISTS (SELECT 1 FROM training_diary_groups dg WHERE dg.diary_id = d.id AND dg.group_id = :group_id)'
            : 'd.is_public = 1') . "
           AND d.practice_date >= :visible_from
         ORDER BY d.practice_date DESC, d.id DESC"
    );
    $params = [':visible_from' => public_training_diary_visible_from()];
    if ($group_id > 0) {
        $params[':group_id'] = $group_id;
    }
    $stmt->execute($params);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($posts as &$post) {
        $diary_id = (int)($post['id'] ?? 0);
        $post['comment_count'] = (int)($comment_summary[$diary_id] ?? 0);
        $post['like_count'] = (int)($like_summary[$diary_id] ?? 0);
        $post['comments'] = $diary_id > 0 ? fetch_training_diary_comments($pdo, $diary_id) : [];
        $post['liked_by_me'] = false;
        $post['weight_sets'] = ($diary_id > 0 && ($post['training_type'] ?? 'short_sprint') === 'weight')
            ? fetch_training_diary_weight_sets($pdo, $diary_id)
            : [];
        $post['running_sets'] = ($diary_id > 0 && training_diary_is_running_type((string)($post['training_type'] ?? 'short_sprint')))
            ? fetch_training_diary_running_sets($pdo, $diary_id)
            : [];
        $post['race_entries'] = ($diary_id > 0 && ($post['training_type'] ?? 'short_sprint') === 'race')
            ? fetch_training_diary_race_entries($pdo, $diary_id)
            : [];
        $post['jog_entries'] = ($diary_id > 0 && ($post['training_type'] ?? 'short_sprint') === 'jog')
            ? fetch_training_diary_jog_entries($pdo, $diary_id)
            : [];
        $post['avatar_path'] = normalize_member_avatar_path((string)($post['avatar_image_path'] ?? ''));
        unset($post['avatar_image_path']);
        $post['can_comment'] = $current_user_id > 0
            && member_can_comment_on_diary($current_user_role, (string)($post['owner_role'] ?? ''), (int)$post['user_id'] === $current_user_id);
        // 「コーチの投稿」の絞り込みと札に使う（権限そのものは渡さない）
        $post['is_coach'] = member_role_is_coach((string)($post['owner_role'] ?? ''));
        unset($post['owner_role']);

        if ($current_user_id > 0 && $diary_id > 0) {
            $like_check_stmt = $pdo->prepare('SELECT id FROM training_diary_likes WHERE diary_id = :diary_id AND user_id = :user_id LIMIT 1');
            $like_check_stmt->execute([
                ':diary_id' => $diary_id,
                ':user_id' => $current_user_id,
            ]);
            $post['liked_by_me'] = (bool)$like_check_stmt->fetchColumn();
        }
    }
    unset($post);

    return array_values($posts);
}
