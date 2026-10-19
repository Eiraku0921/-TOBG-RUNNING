<?php

// 練習のまとめ（ホーム・NEXT PR の「YOUR WEEK」）と、週の目標。
// 数字はすべて練習日記から計算し、手で入れるのは週の目標だけ（仕様書の計算ルール）:
//   練習回数 = 期間内の練習日記の件数（1つの日記に種目がいくつあっても1回）
//   本数     = ショートスプ・ロングスプの「本数 × セット」の合計
//   距離     = ジョグは選んだ km、スプリントは「距離（m）× 本数 × セット ÷ 1000」。ウエイトと大会は含めない
// 週は月曜〜日曜。日付は PHP 側（Asia/Tokyo）で決める（練習日記の練習日も PHP の日付で保存しているため）

require_once __DIR__ . '/training_diary_lib.php';

// 週の目標の種類と、選べる値（仕様書: 回数は 1〜21 回の 1 刻み、距離は 5〜300km の 5km 刻み）
const TRAINING_WEEKLY_GOAL_COUNT = 'count';
const TRAINING_WEEKLY_GOAL_DISTANCE = 'distance';
const TRAINING_WEEKLY_GOAL_RANGES = [
    TRAINING_WEEKLY_GOAL_COUNT => ['min' => 1, 'max' => 21, 'step' => 1],
    TRAINING_WEEKLY_GOAL_DISTANCE => ['min' => 5, 'max' => 300, 'step' => 5],
];

// 長距離として扱う種目（まとめカードの表示を自動で決めるとき、選んでいる種目がこれなら長距離）
const TRAINING_SUMMARY_LONG_EVENTS = ['1500m', '5000m', '10000m', '10km', 'ハーフ', 'フル'];

function ensure_training_weekly_goals_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS training_weekly_goals (
            user_id INT NOT NULL,
            goal_kind VARCHAR(10) NOT NULL,
            goal_value DECIMAL(5,1) NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, goal_kind)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

// その会員の週の目標（種類 => 値）。決めていない種類は null
function fetch_training_weekly_goals(PDO $pdo, int $user_id): array
{
    $goals = [TRAINING_WEEKLY_GOAL_COUNT => null, TRAINING_WEEKLY_GOAL_DISTANCE => null];
    $stmt = $pdo->prepare('SELECT goal_kind, goal_value FROM training_weekly_goals WHERE user_id = :user_id');
    $stmt->execute([':user_id' => $user_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (array_key_exists((string)$row['goal_kind'], $goals)) {
            $goals[(string)$row['goal_kind']] = (float)$row['goal_value'];
        }
    }
    return $goals;
}

// 選べる値（範囲内・刻みに合う）なら保存して true。そうでなければ保存せず false
function save_training_weekly_goal(PDO $pdo, int $user_id, string $kind, float $value): bool
{
    $range = TRAINING_WEEKLY_GOAL_RANGES[$kind] ?? null;
    if ($range === null || $value < $range['min'] || $value > $range['max']
        || fmod($value - $range['min'], $range['step']) !== 0.0) {
        return false;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO training_weekly_goals (user_id, goal_kind, goal_value) VALUES (:user_id, :kind, :value)
         ON DUPLICATE KEY UPDATE goal_value = VALUES(goal_value)'
    );
    $stmt->execute([':user_id' => $user_id, ':kind' => $kind, ':value' => $value]);
    return true;
}

// ランニングの距離の文字を m にする。「400m」「200+200」「1.5km」「ハーフ」「４００ｍ」など。読めなければ null
// （null の種目は距離に数えない。0km として誤った数字を出さないため）
function training_diary_distance_label_meters(string $label): ?float
{
    $label = strtolower(trim(mb_convert_kana($label, 'as', 'UTF-8')));
    if ($label === '') {
        return null;
    }
    if (str_contains($label, 'ハーフ')) {
        return 21097.5;
    }
    if (str_contains($label, 'フル')) {
        return 42195.0;
    }
    $meters = 0.0;
    foreach (explode('+', $label) as $part) {
        if (!preg_match('/^\s*(\d+(?:\.\d+)?)\s*(km|m)?\s*$/', $part, $m)) {
            return null;
        }
        $meters += (float)$m[1] * (($m[2] ?? '') === 'km' ? 1000 : 1);
    }
    return $meters > 0 ? $meters : null;
}

// 日記ごとの距離（km）と本数。$where は training_diaries（別名 d）の条件、$params はその値。
//   距離: ジョグは選んだ km、スプリントは「距離（m）× 本数 × セット ÷ 1000」（距離の文字が読めない種目は数えない）
//   本数: スプリントの「本数 × セット」
// 戻り値: [日記ID => ['user_id', 'practice_date', 'km', 'reps']]（距離も本数も無い日記も入る＝練習回数に数える）
function fetch_diary_distances(PDO $pdo, string $where, array $params): array
{
    $diaries = [];
    $stmt = $pdo->prepare("SELECT d.id, d.user_id, d.practice_date FROM training_diaries d WHERE $where");
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $diaries[(int)$row['id']] = ['user_id' => (int)$row['user_id'], 'practice_date' => (string)$row['practice_date'], 'km' => 0.0, 'reps' => 0];
    }
    if (empty($diaries)) {
        return [];
    }

    $running = $pdo->prepare(
        "SELECT r.diary_id, r.distance_label, r.reps, r.sets
         FROM training_diary_running_sets r
         INNER JOIN training_diaries d ON d.id = r.diary_id
         WHERE $where"
    );
    $running->execute($params);
    foreach ($running->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $diary_id = (int)$row['diary_id'];
        $times = max(1, (int)$row['reps']) * max(1, (int)$row['sets']);
        $diaries[$diary_id]['reps'] += $times;
        $meters = training_diary_distance_label_meters((string)$row['distance_label']);
        if ($meters !== null) {
            $diaries[$diary_id]['km'] += $meters * $times / 1000;
        }
    }

    $jog = $pdo->prepare(
        "SELECT j.diary_id, SUM(j.distance_km) AS km
         FROM training_diary_jog_entries j
         INNER JOIN training_diaries d ON d.id = j.diary_id
         WHERE $where
         GROUP BY j.diary_id"
    );
    $jog->execute($params);
    foreach ($jog->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $diaries[(int)$row['diary_id']]['km'] += (float)$row['km'];
    }
    return $diaries;
}

// 練習のまとめ（今週・今月の数字と、月〜日の日別）
function fetch_training_week_summary(PDO $pdo, int $user_id, ?DateTimeImmutable $today = null): array
{
    ensure_training_diary_table($pdo);
    ensure_training_diary_running_tables($pdo);
    ensure_training_diary_jog_table($pdo);
    ensure_training_weekly_goals_table($pdo);

    $today = $today ?? new DateTimeImmutable('today');
    $week_start = $today->modify('monday this week');
    $week_end = $week_start->modify('+6 days');
    $month_start = $today->modify('first day of this month');
    // 月の初めの週は、週の始まりが先月になることがあるので、早いほうから読む
    $from = min($week_start, $month_start)->format('Y-m-d');
    $to = max($week_end, $today)->format('Y-m-d');

    $diaries = fetch_diary_distances($pdo, 'd.user_id = :user_id AND d.practice_date BETWEEN :from AND :to', [':user_id' => $user_id, ':from' => $from, ':to' => $to]);

    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $date = $week_start->modify("+{$i} days")->format('Y-m-d');
        $days[$date] = ['date' => $date, 'km' => 0.0, 'reps' => 0, 'count' => 0];
    }
    $summary = ['week_count' => 0, 'month_count' => 0, 'week_reps' => 0, 'week_km' => 0.0, 'month_km' => 0.0];
    $today_date = $today->format('Y-m-d');
    $month_start_date = $month_start->format('Y-m-d');
    foreach ($diaries as $diary) {
        $date = $diary['practice_date'];
        $km = $diary['km'];
        $reps = $diary['reps'];
        if (isset($days[$date])) {
            $days[$date]['km'] += $km;
            $days[$date]['reps'] += $reps;
            $days[$date]['count']++;
            $summary['week_count']++;
            $summary['week_reps'] += $reps;
            $summary['week_km'] += $km;
        }
        if ($date >= $month_start_date && $date <= $today_date) {
            $summary['month_count']++;
            $summary['month_km'] += $km;
        }
    }

    return [
        'week_start' => $week_start->format('Y-m-d'),
        'week_end' => $week_end->format('Y-m-d'),
        'today' => $today_date,
        'week_count' => $summary['week_count'],
        'month_count' => $summary['month_count'],
        'week_reps' => $summary['week_reps'],
        'week_km' => round($summary['week_km'], 1),
        'month_km' => round($summary['month_km'], 1),
        'days' => array_values(array_map(static function (array $day): array {
            $day['km'] = round($day['km'], 1);
            return $day;
        }, $days)),
        'goals' => fetch_training_weekly_goals($pdo, $user_id),
    ];
}

// 走行距離のランキング（みんなのページ・クラブ画面で共通）。日記の公開範囲を守り、ほかの人に見せてよい日記だけを数える:
//   $group_id を指定: そのグループのメンバー全員（0km の人も並べる）。全体公開か、そのグループに公開した日記を数える
//   指定なし        : 全体公開の日記だけを数え、距離のある人を並べる。$club を指定すると、そのクラブの会員だけ
// 同じ距離の人は同じ順位。戻り値: [['rank', 'user_id', 'name', 'avatar_path', 'km'], …]（距離の多い順）
function fetch_distance_ranking(PDO $pdo, string $from, string $to, ?int $group_id = null, ?string $club = null): array
{
    ensure_training_diary_table($pdo);
    ensure_training_diary_running_tables($pdo);
    ensure_training_diary_jog_table($pdo);

    $params = [':from' => $from, ':to' => $to];
    if ($group_id !== null) {
        $where = 'd.practice_date BETWEEN :from AND :to
            AND d.user_id IN (SELECT gm.user_id FROM member_group_members gm WHERE gm.group_id = :group_id)
            AND (d.is_public = 1 OR EXISTS (SELECT 1 FROM training_diary_groups dg WHERE dg.diary_id = d.id AND dg.group_id = :group_id2))';
        $params[':group_id'] = $group_id;
        $params[':group_id2'] = $group_id;
    } else {
        $where = 'd.practice_date BETWEEN :from AND :to AND d.is_public = 1';
        if ($club !== null) {
            $where .= ' AND d.user_id IN (SELECT m.id FROM members m WHERE m.club = :club)';
            $params[':club'] = $club;
        }
    }

    $km_by_user = [];
    foreach (fetch_diary_distances($pdo, $where, $params) as $diary) {
        $km_by_user[$diary['user_id']] = ($km_by_user[$diary['user_id']] ?? 0) + $diary['km'];
    }
    if ($group_id !== null) {
        // グループは、まだ走っていないメンバーも 0km で並べる
        $member_stmt = $pdo->prepare('SELECT user_id FROM member_group_members WHERE group_id = :group_id');
        $member_stmt->execute([':group_id' => $group_id]);
        foreach ($member_stmt->fetchAll(PDO::FETCH_COLUMN) as $member_id) {
            $km_by_user[(int)$member_id] = $km_by_user[(int)$member_id] ?? 0.0;
        }
    } else {
        $km_by_user = array_filter($km_by_user, static fn(float $km): bool => $km > 0);
    }
    if (empty($km_by_user)) {
        return [];
    }

    $ids = array_keys($km_by_user);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $name_stmt = $pdo->prepare("SELECT id, name, avatar_image_path FROM members WHERE id IN ($placeholders)");
    $name_stmt->execute($ids);
    $people = [];
    foreach ($name_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $people[(int)$row['id']] = $row;
    }

    $rows = [];
    foreach ($km_by_user as $member_id => $km) {
        $rows[] = [
            'user_id' => $member_id,
            'name' => (string)($people[$member_id]['name'] ?? 'メンバー'),
            'avatar_path' => normalize_member_avatar_path((string)($people[$member_id]['avatar_image_path'] ?? '')),
            'km' => round($km, 1),
        ];
    }
    usort($rows, static fn(array $a, array $b): int => [$b['km'], $a['name']] <=> [$a['km'], $b['name']]);
    $rank = 0;
    $previous_km = null;
    foreach ($rows as $index => &$row) {
        if ($previous_km === null || $row['km'] < $previous_km) {
            $rank = $index + 1;
            $previous_km = $row['km'];
        }
        $row['rank'] = $rank;
    }
    unset($row);
    return $rows;
}

// ランキングの期間。'week' は月曜〜日曜、'month' は月初〜月末。$offset で前の週・月へ（月は過去1年＝11か月前まで）
function distance_ranking_period(string $period, int $offset = 0, ?DateTimeImmutable $today = null): array
{
    $today = $today ?? new DateTimeImmutable('today');
    if ($period === 'month') {
        $offset = max(0, min(11, $offset));
        $start = $today->modify('first day of this month')->modify("-{$offset} months");
        $end = $start->modify('last day of this month');
        $label = $start->format('n') . '月';
    } else {
        $offset = max(0, min(51, $offset));
        $start = $today->modify('monday this week')->modify('-' . (7 * $offset) . ' days');
        $end = $start->modify('+6 days');
        $label = $start->format('m.d') . '–' . $end->format('m.d');
    }
    return ['period' => $period === 'month' ? 'month' : 'week', 'offset' => $offset, 'from' => $start->format('Y-m-d'), 'to' => $end->format('Y-m-d'), 'label' => $label];
}
