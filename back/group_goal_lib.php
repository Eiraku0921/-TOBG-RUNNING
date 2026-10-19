<?php

// みんなの目標（グループ）: 各メンバーが今月の目標距離を宣言し、その合計がグループの目標になる。
//   宣言は 10〜500km の 10km 刻み（仕様書）。1人がグループごと・月ごとに1つ（宣言し直すと上書き）
//   今月の距離は、ランキングと同じく、全体公開かそのグループに公開した日記だけを数える（back/training_summary_lib.php）

require_once __DIR__ . '/member_group_lib.php';
require_once __DIR__ . '/training_summary_lib.php';

const GROUP_PLEDGE_MIN_KM = 10;
const GROUP_PLEDGE_MAX_KM = 500;
const GROUP_PLEDGE_STEP_KM = 10;

function ensure_group_pledges_table(PDO $pdo): void
{
    ensure_member_group_tables($pdo);
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS member_group_pledges (
            group_id INT NOT NULL,
            user_id INT NOT NULL,
            month CHAR(7) NOT NULL,
            pledge_km SMALLINT UNSIGNED NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (group_id, user_id, month),
            CONSTRAINT fk_member_group_pledges_group FOREIGN KEY (group_id) REFERENCES member_groups (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

// 今月の宣言を保存する（メンバーだけ・範囲と刻みを確かめる）。誤りは RuntimeException（画面に出す文）
function save_group_pledge(PDO $pdo, int $group_id, int $user_id, int $pledge_km, ?DateTimeImmutable $today = null): void
{
    if (!member_group_is_member($pdo, $group_id, $user_id)) {
        throw new RuntimeException('このグループのメンバーだけが目標を宣言できます。');
    }
    if ($pledge_km < GROUP_PLEDGE_MIN_KM || $pledge_km > GROUP_PLEDGE_MAX_KM || $pledge_km % GROUP_PLEDGE_STEP_KM !== 0) {
        throw new RuntimeException('目標は ' . GROUP_PLEDGE_MIN_KM . '〜' . GROUP_PLEDGE_MAX_KM . 'km（' . GROUP_PLEDGE_STEP_KM . 'km刻み）で選んでください。');
    }
    $month = ($today ?? new DateTimeImmutable('today'))->format('Y-m');
    $stmt = $pdo->prepare(
        'INSERT INTO member_group_pledges (group_id, user_id, month, pledge_km) VALUES (:group_id, :user_id, :month, :km)
         ON DUPLICATE KEY UPDATE pledge_km = VALUES(pledge_km)'
    );
    $stmt->execute([':group_id' => $group_id, ':user_id' => $user_id, ':month' => $month, ':km' => $pledge_km]);
}

// 今月のみんなの目標（合計・達成率・残り・メンバー別）
function fetch_group_goal(PDO $pdo, int $group_id, int $user_id, ?DateTimeImmutable $today = null): array
{
    ensure_group_pledges_table($pdo);
    $today = $today ?? new DateTimeImmutable('today');
    $period = distance_ranking_period('month', 0, $today);

    // メンバー全員の今月の距離（0km の人も入る）
    $members = fetch_distance_ranking($pdo, $period['from'], $period['to'], $group_id);

    $stmt = $pdo->prepare('SELECT user_id, pledge_km FROM member_group_pledges WHERE group_id = :group_id AND month = :month');
    $stmt->execute([':group_id' => $group_id, ':month' => $today->format('Y-m')]);
    $pledges = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pledges[(int)$row['user_id']] = (int)$row['pledge_km'];
    }

    $total_km = 0.0;
    $goal_km = 0;
    foreach ($members as &$member) {
        $member['pledge_km'] = $pledges[$member['user_id']] ?? null;
        $member['percent'] = $member['pledge_km'] ? (int)min(100, floor($member['km'] / $member['pledge_km'] * 100)) : null;
        $total_km += $member['km'];
        $goal_km += $member['pledge_km'] ?? 0;
    }
    unset($member);
    // 達成率の高い順（未宣言は最後）
    usort($members, static fn(array $a, array $b): int => [$b['percent'] ?? -1, $b['km']] <=> [$a['percent'] ?? -1, $a['km']]);

    $days_in_month = (int)$today->format('t');
    $days_left = $days_in_month - (int)$today->format('j') + 1; // 今日も含める
    $remaining_km = max(0.0, $goal_km - $total_km);
    return [
        'month_label' => $today->format('n') . '月',
        'total_km' => round($total_km, 1),
        'goal_km' => $goal_km,
        'percent' => $goal_km > 0 ? (int)min(100, floor($total_km / $goal_km * 100)) : null,
        'remaining_km' => round($remaining_km, 1),
        'days_left' => $days_left,
        'per_day_km' => round($remaining_km / max(1, $days_left), 1),
        'my_pledge_km' => $pledges[$user_id] ?? null,
        'members' => $members,
    ];
}
