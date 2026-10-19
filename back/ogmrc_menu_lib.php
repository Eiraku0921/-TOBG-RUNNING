<?php

// OGMRC（小中学生クラブ）のメニュー（クラブ画面の「メニュー」）。コーチ・管理者が入力し、OGMRC の会員が見る。
//   ogmrc_coach_notes  コーチのひとこと（新しいものを上に出す。配信から3日以内は「NEW」）
//   ogmrc_practices    練習予定。今日以降で一番近い練習（大会の日を除く）を「次の練習日」として4マスで出し、残りを「この先の予定」に並べる

require_once __DIR__ . '/member_role_lib.php';

const OGMRC_NOTE_NEW_DAYS = 3;
const OGMRC_NOTE_MAX = 1000;
const OGMRC_PRACTICE_TEXT_MAX = 500;

function ensure_ogmrc_menu_tables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ogmrc_coach_notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            author_user_id INT NOT NULL,
            body TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ogmrc_coach_notes_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ogmrc_practices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            practice_date DATE NOT NULL,
            start_time TIME NULL,
            place VARCHAR(100) NULL,
            title VARCHAR(60) NOT NULL,
            is_race TINYINT(1) NOT NULL DEFAULT 0,
            warmup TEXT NULL,
            drill TEXT NULL,
            main_set TEXT NULL,
            cool_down TEXT NULL,
            created_by INT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_ogmrc_practices_date (practice_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

// メニューを書けるのはコーチ（ＯＧＭコーチ陣・ＯＧＭ代表）と管理者
function ogmrc_menu_can_edit(string $role): bool
{
    return member_role_is_coach($role) || $role === MEMBER_ROLE_ADMIN;
}

// メニューの画面に出すもの: 最新のひとこと、次の練習日、この先の予定
function fetch_ogmrc_menu(PDO $pdo, ?DateTimeImmutable $today = null): array
{
    ensure_ogmrc_menu_tables($pdo);
    $today = $today ?? new DateTimeImmutable('today');

    $note = $pdo->query(
        "SELECT n.id, n.body, n.created_at, COALESCE(m.name, 'コーチ') AS author_name
         FROM ogmrc_coach_notes n LEFT JOIN members m ON m.id = n.author_user_id
         ORDER BY n.created_at DESC, n.id DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($note !== null) {
        $note['is_new'] = strtotime((string)$note['created_at']) >= $today->modify('-' . OGMRC_NOTE_NEW_DAYS . ' days')->getTimestamp();
    }

    $stmt = $pdo->prepare('SELECT * FROM ogmrc_practices WHERE practice_date >= :today ORDER BY practice_date ASC, start_time ASC, id ASC');
    $stmt->execute([':today' => $today->format('Y-m-d')]);
    $practices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $next = null;
    $upcoming = [];
    foreach ($practices as $practice) {
        if ($next === null && (int)$practice['is_race'] === 0) {
            $next = $practice;
            continue;
        }
        $upcoming[] = $practice;
    }
    return ['note' => $note, 'next' => $next, 'upcoming' => $upcoming];
}

function add_ogmrc_note(PDO $pdo, int $author_user_id, string $body): void
{
    $body = trim($body);
    if ($body === '' || mb_strlen($body) > OGMRC_NOTE_MAX) {
        throw new RuntimeException('ひとことは' . OGMRC_NOTE_MAX . '文字以内で入力してください。');
    }
    $pdo->prepare('INSERT INTO ogmrc_coach_notes (author_user_id, body) VALUES (:author, :body)')
        ->execute([':author' => $author_user_id, ':body' => $body]);
}

// 練習予定を追加する（$practice_id を渡すとその予定を書き換える）
function save_ogmrc_practice(PDO $pdo, int $user_id, array $input, int $practice_id = 0): void
{
    $date = (string)($input['practice_date'] ?? '');
    $date_obj = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if ($date_obj === false || $date_obj->format('Y-m-d') !== $date) {
        throw new RuntimeException('日付を選んでください。');
    }
    $time = (string)($input['start_time'] ?? '');
    if ($time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
        throw new RuntimeException('時間の形式が正しくありません。');
    }
    $title = trim((string)($input['title'] ?? ''));
    if ($title === '' || mb_strlen($title) > 60) {
        throw new RuntimeException('内容（予定の一覧に出す一言）は60文字以内で入力してください。');
    }
    $texts = [];
    foreach (['place' => 100, 'warmup' => OGMRC_PRACTICE_TEXT_MAX, 'drill' => OGMRC_PRACTICE_TEXT_MAX, 'main_set' => OGMRC_PRACTICE_TEXT_MAX, 'cool_down' => OGMRC_PRACTICE_TEXT_MAX] as $field => $max) {
        $value = trim((string)($input[$field] ?? ''));
        if (mb_strlen($value) > $max) {
            throw new RuntimeException('入力が長すぎます（' . $max . '文字まで）。');
        }
        $texts[$field] = $value !== '' ? $value : null;
    }
    $params = [
        ':date' => $date,
        ':time' => $time !== '' ? $time . ':00' : null,
        ':place' => $texts['place'],
        ':title' => $title,
        ':is_race' => !empty($input['is_race']) ? 1 : 0,
        ':warmup' => $texts['warmup'],
        ':drill' => $texts['drill'],
        ':main_set' => $texts['main_set'],
        ':cool_down' => $texts['cool_down'],
    ];
    if ($practice_id > 0) {
        $params[':id'] = $practice_id;
        $pdo->prepare(
            'UPDATE ogmrc_practices SET practice_date = :date, start_time = :time, place = :place, title = :title, is_race = :is_race,
                warmup = :warmup, drill = :drill, main_set = :main_set, cool_down = :cool_down WHERE id = :id'
        )->execute($params);
        return;
    }
    $params[':created_by'] = $user_id;
    $pdo->prepare(
        'INSERT INTO ogmrc_practices (practice_date, start_time, place, title, is_race, warmup, drill, main_set, cool_down, created_by)
         VALUES (:date, :time, :place, :title, :is_race, :warmup, :drill, :main_set, :cool_down, :created_by)'
    )->execute($params);
}

function delete_ogmrc_practice(PDO $pdo, int $practice_id): void
{
    $pdo->prepare('DELETE FROM ogmrc_practices WHERE id = :id')->execute([':id' => $practice_id]);
}
