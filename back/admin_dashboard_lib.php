<?php

// 管理画面（PC）の数字のカード。どれも既存のテーブルを数えるだけで、集計用のテーブルは持たない。
// 日付は PHP 側（Asia/Tokyo）で決めて渡す（練習日記の練習日も PHP の日付で保存しているため）

require_once __DIR__ . '/training_diary_lib.php';

// 申込で「まだ承認していない」とみなすステータス（空欄は申込直後の未承認として扱う）
const ADMIN_DASHBOARD_PENDING_ENTRY_STATUSES = ['', '未承認'];

function fetch_admin_dashboard_stats(PDO $pdo, ?DateTimeImmutable $today = null): array
{
    ensure_training_diary_table($pdo);
    ensure_training_diary_race_entries_table($pdo);

    $today = $today ?? new DateTimeImmutable('today');
    $today_date = $today->format('Y-m-d');
    $week_start = $today->modify('monday this week')->format('Y-m-d');
    $last_week_start = $today->modify('monday this week')->modify('-7 days')->format('Y-m-d');
    $seven_days_ago = $today->modify('-6 days')->format('Y-m-d'); // 今日を含めて7日
    $month_start = $today->format('Y-m-01');

    // 練習日記: 今日・直近7日に記録した人数、今週・先週の件数を1回で数える
    $diary_stmt = $pdo->prepare(
        'SELECT
            COUNT(DISTINCT CASE WHEN practice_date = :today THEN user_id END) AS today_people,
            COUNT(DISTINCT CASE WHEN practice_date >= :seven_days_ago THEN user_id END) AS week_people,
            SUM(practice_date >= :week_start) AS this_week_count,
            SUM(practice_date < :week_start2) AS last_week_count
         FROM training_diaries
         WHERE practice_date >= :last_week_start AND practice_date <= :today2'
    );
    $diary_stmt->execute([
        ':today' => $today_date,
        ':today2' => $today_date,
        ':seven_days_ago' => $seven_days_ago,
        ':week_start' => $week_start,
        ':week_start2' => $week_start,
        ':last_week_start' => $last_week_start,
    ]);
    $diary = $diary_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $member_total = (int)$pdo->query('SELECT COUNT(*) FROM members')->fetchColumn();

    // 大会の記録: 今月の件数と、出場した人数
    $race_stmt = $pdo->prepare(
        'SELECT COUNT(*) AS entry_count, COUNT(DISTINCT d.user_id) AS people
         FROM training_diary_race_entries e
         INNER JOIN training_diaries d ON d.id = e.diary_id
         WHERE d.practice_date >= :month_start AND d.practice_date <= :today'
    );
    $race_stmt->execute([':month_start' => $month_start, ':today' => $today_date]);
    $race = $race_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // 承認待ちの申込: 開催前（または開催日が未定）の大会のものだけ数える。終わった大会の分は対応できないため
    $placeholders = implode(',', array_fill(0, count(ADMIN_DASHBOARD_PENDING_ENTRY_STATUSES), '?'));
    $pending_stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM matches m
         LEFT JOIN (
            SELECT event_name, MAX(event_date) AS event_date FROM tournaments GROUP BY event_name
         ) t ON t.event_name = m.match_title
         WHERE COALESCE(TRIM(m.status), '') IN ($placeholders)
           AND (t.event_date IS NULL OR t.event_date >= ?)"
    );
    $pending_stmt->execute(array_merge(ADMIN_DASHBOARD_PENDING_ENTRY_STATUSES, [$today_date]));

    return [
        'today' => $today_date,
        'today_people' => (int)($diary['today_people'] ?? 0),
        'week_people' => (int)($diary['week_people'] ?? 0),
        'member_total' => $member_total,
        'this_week_diaries' => (int)($diary['this_week_count'] ?? 0),
        'last_week_diaries' => (int)($diary['last_week_count'] ?? 0),
        'month_race_entries' => (int)($race['entry_count'] ?? 0),
        'month_race_people' => (int)($race['people'] ?? 0),
        'pending_entries' => (int)$pending_stmt->fetchColumn(),
    ];
}
