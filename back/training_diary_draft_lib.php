<?php

// 練習日記の下書き（登録シートの「下書きを保存」）。会員ごとに1件だけ持ち、保存するたびに上書きする。
// 投稿すると消す。どの端末で開いても続きから書けるよう、ブラウザではなくサーバーに置く。
//
// 下書きは「投稿前の入力欄の状態」をそのまま戻すためのもので、検索や集計には使わない。
// そのため、日付・強度・タイトル・ひとことは列に分け、種目の一覧（ウエイト・ランニング・大会・ジョグ）と
// 公開範囲のグループは、投稿フォームが送るのと同じ形の JSON を1列にまとめる。写真・動画は下書きに残さない。

const TRAINING_DIARY_DRAFT_TYPES = ['short_sprint', 'long_sprint', 'jog', 'weight', 'race'];
const TRAINING_DIARY_DRAFT_ENTRY_KEYS = ['weight_sets', 'running_sets', 'race_entries', 'jog_entries'];
const TRAINING_DIARY_DRAFT_ENTRIES_MAX = 50;

function ensure_training_diary_drafts_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS training_diary_drafts (
            user_id INT NOT NULL PRIMARY KEY,
            practice_date DATE NULL,
            intensity VARCHAR(20) NOT NULL DEFAULT 'normal',
            training_type VARCHAR(20) NOT NULL DEFAULT 'short_sprint',
            menu_text VARCHAR(255) NOT NULL DEFAULT '',
            comment_text TEXT NULL,
            is_public TINYINT(1) NOT NULL DEFAULT 1,
            entries_json TEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

// 送られてきた種目の JSON を、配列であることと件数だけ確かめて取り出す（中身の検査は投稿するときに行う）
function training_diary_draft_decode_list(string $json): array
{
    $list = json_decode($json, true);
    if (!is_array($list) || !array_is_list($list)) {
        return [];
    }
    return array_slice(array_values(array_filter($list, 'is_array')), 0, TRAINING_DIARY_DRAFT_ENTRIES_MAX);
}

// 登録シートのフォームの値（$_POST）から下書きを保存する
function save_training_diary_draft(PDO $pdo, int $user_id, array $input): void
{
    ensure_training_diary_drafts_table($pdo);

    $date = (string)($input['practice_date'] ?? '');
    $date_obj = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $practice_date = ($date_obj !== false && $date_obj->format('Y-m-d') === $date) ? $date : null;

    $intensity = (string)($input['intensity'] ?? 'normal');
    if (!array_key_exists($intensity, training_diary_intensity_options())) {
        $intensity = 'normal';
    }
    $training_type = (string)($input['training_type'] ?? 'short_sprint');
    if (!in_array($training_type, TRAINING_DIARY_DRAFT_TYPES, true)) {
        $training_type = 'short_sprint';
    }

    $entries = [];
    foreach (TRAINING_DIARY_DRAFT_ENTRY_KEYS as $key) {
        $entries[$key] = training_diary_draft_decode_list((string)($input[$key . '_json'] ?? '[]'));
    }
    $entries['group_ids'] = array_values(array_unique(array_filter(
        array_map('intval', (array)($input['group_ids'] ?? [])),
        static fn(int $id): bool => $id > 0
    )));

    $stmt = $pdo->prepare(
        'INSERT INTO training_diary_drafts (user_id, practice_date, intensity, training_type, menu_text, comment_text, is_public, entries_json)
         VALUES (:user_id, :practice_date, :intensity, :training_type, :menu_text, :comment_text, :is_public, :entries_json)
         ON DUPLICATE KEY UPDATE practice_date = VALUES(practice_date), intensity = VALUES(intensity), training_type = VALUES(training_type),
            menu_text = VALUES(menu_text), comment_text = VALUES(comment_text), is_public = VALUES(is_public), entries_json = VALUES(entries_json)'
    );
    $stmt->execute([
        ':user_id' => $user_id,
        ':practice_date' => $practice_date,
        ':intensity' => $intensity,
        ':training_type' => $training_type,
        ':menu_text' => mb_substr(trim((string)($input['menu_text'] ?? '')), 0, 255),
        ':comment_text' => trim((string)($input['comment_text'] ?? '')),
        ':is_public' => !empty($input['is_public']) ? 1 : 0,
        ':entries_json' => json_encode($entries, JSON_UNESCAPED_UNICODE),
    ]);
}

// 下書き（なければ null）。登録シートが開いたときに入力欄へ戻す形で返す
function fetch_training_diary_draft(PDO $pdo, int $user_id): ?array
{
    ensure_training_diary_drafts_table($pdo);
    $stmt = $pdo->prepare('SELECT * FROM training_diary_drafts WHERE user_id = :user_id LIMIT 1');
    $stmt->execute([':user_id' => $user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $entries = json_decode((string)$row['entries_json'], true);
    $entries = is_array($entries) ? $entries : [];
    $draft = [
        'practice_date' => $row['practice_date'],
        'intensity' => (string)$row['intensity'],
        'training_type' => (string)$row['training_type'],
        'menu_text' => (string)$row['menu_text'],
        'comment_text' => (string)($row['comment_text'] ?? ''),
        'is_public' => (int)$row['is_public'] === 1,
        'group_ids' => array_map('intval', (array)($entries['group_ids'] ?? [])),
        'updated_at' => (string)$row['updated_at'],
    ];
    foreach (TRAINING_DIARY_DRAFT_ENTRY_KEYS as $key) {
        $draft[$key] = is_array($entries[$key] ?? null) ? $entries[$key] : [];
    }
    return $draft;
}

function delete_training_diary_draft(PDO $pdo, int $user_id): void
{
    ensure_training_diary_drafts_table($pdo);
    $pdo->prepare('DELETE FROM training_diary_drafts WHERE user_id = :user_id')->execute([':user_id' => $user_id]);
}
