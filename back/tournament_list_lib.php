<?php

// 会員に見せる大会（ホームの「大会のお知らせ」）。
// 表示締切日 = 応募締切日（なければ開催日）から「表示締切」の日数を引いた日。過ぎた大会は出さない（クラブ画面の大会タブ・ホームで共通）。
// 会員の締切日 = 表示締切日（締切の前日リマインドの通知と同じ日。back/tournament_notification_lib.php）。
// 締切までの日数 = 会員の締切日 − 今日。3日以内は強調、当日は「今日まで」

const TOURNAMENT_DEADLINE_SOON_DAYS = 3;

// 大会1件を見せる場所（サイトのルートからのパス）。クラブ画面の大会タブの、その大会のカード（includes/club_races.php の id="race-◯"）。
// 通知・メール・ホームのリンクはここへ向ける。古いリンクの front/tournament_detail.php もここへ転送する
function tournament_page_path(int $tournament_id): string
{
    return 'front/club.php?tab=races#race-' . $tournament_id;
}

// $limit が 0 なら全部
function fetch_open_tournaments(PDO $pdo, int $limit = 3, ?DateTimeImmutable $today = null): array
{
    $today = $today ?? new DateTimeImmutable('today');
    $stmt = $pdo->prepare(
        'SELECT *
         FROM tournaments
         WHERE event_date >= :today
           AND DATE_SUB(COALESCE(entry_deadline, event_date), INTERVAL COALESCE(deadline_lead_days, 3) DAY) >= :today2
         ORDER BY event_date ASC, id ASC'
        . ($limit > 0 ? ' LIMIT ' . $limit : '')
    );
    $stmt->execute([':today' => $today->format('Y-m-d'), ':today2' => $today->format('Y-m-d')]);

    $tournaments = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $deadline = $row['entry_deadline'] !== null && $row['entry_deadline'] !== '' ? new DateTimeImmutable((string)$row['entry_deadline']) : null;
        // 会員の締切日: 応募締切日（なければ開催日）から「表示締切」の日数を引いた日。この日を過ぎると一覧から消える
        $lead_days = $row['deadline_lead_days'] !== null && $row['deadline_lead_days'] !== '' ? (int)$row['deadline_lead_days'] : 3;
        $member_deadline = ($deadline ?? new DateTimeImmutable((string)$row['event_date']))->modify('-' . $lead_days . ' days');
        $days_left = (int)$today->diff($member_deadline)->format('%r%a');
        $deadline_label = $days_left === 0 ? '今日まで' : 'あと' . $days_left . '日';
        $tournaments[] = [
            'id' => (int)$row['id'],
            'event_name' => (string)$row['event_name'],
            'event_date' => (string)$row['event_date'],
            'location' => (string)($row['location'] ?? ''),
            'details' => (string)($row['details'] ?? ''),
            'official_link' => (string)($row['official_link'] ?? ''),
            'entry_deadline' => $deadline !== null ? $deadline->format('Y-m-d') : null,
            'deadline_lead_days' => $lead_days,
            // 追加した会員と、その人が申し込み管理を行うか（クラブ画面の「⋯」の編集・削除の申請・申込の承認に使う）
            'owner_user_id' => (int)($row['owner_user_id'] ?? 0),
            'self_managed' => (int)($row['self_managed'] ?? 0) === 1,
            'owner_notify_email' => (string)($row['owner_notify_email'] ?? ''),
            'member_deadline' => $member_deadline->format('Y-m-d'),
            'deadline_label' => $deadline_label,
            'deadline_soon' => $days_left <= TOURNAMENT_DEADLINE_SOON_DAYS,
            // 開催日までの日数（ホームの ENTRY カードの「◯ DAYS TO GO」）
            'days_to_event' => (int)$today->diff(new DateTimeImmutable((string)$row['event_date']))->format('%r%a'),
        ];
    }
    return $tournaments;
}

// 大会ごとの申し込み（クラブ画面の「申し込んでいる人」と「申し込み済み」）。キーは大会名（申し込みは大会名で保存されている）
// 戻り値: [大会名 => [['id', 'user_name', 'events' => [['name', 'record'], …], 'status'], …]]
function fetch_tournament_entries_by_title(PDO $pdo): array
{
    $by_title = [];
    $rows = $pdo->query(
        'SELECT id, match_title, user_name, event_name1, pb_time1, event_name2, pb_time2, event_name3, pb_time3, status
         FROM matches ORDER BY created_at ASC, id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $events = [];
        for ($i = 1; $i <= 3; $i++) {
            $name = trim((string)($row['event_name' . $i] ?? ''));
            if ($name !== '') {
                $events[] = ['name' => $name, 'record' => trim((string)($row['pb_time' . $i] ?? ''))];
            }
        }
        $by_title[(string)$row['match_title']][] = [
            'id' => (int)$row['id'],
            'user_name' => (string)$row['user_name'],
            'events' => $events,
            'status' => trim((string)($row['status'] ?? '')),
        ];
    }
    return $by_title;
}
