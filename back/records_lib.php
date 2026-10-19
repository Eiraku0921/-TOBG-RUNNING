<?php

// 歴代記録ページの集計。
// 管理者が登録した記録（records テーブル）と、練習日記の大会記録のうち公認・追い風2.0以下のもの
// （training_diary_race_entries）を合わせて、種目ごとにタイム・距離で順位を付ける。
// 日記の記録は records にコピーせず表示のたびに集計するので、日記の編集・削除がそのまま反映される。

require_once __DIR__ . '/training_diary_lib.php';

// 距離・高さを競う種目（数値が大きいほど上位）。それ以外はタイム（小さいほど上位）
const RECORD_FIELD_EVENTS = ['走幅跳', '三段跳', '走高跳', '棒高跳', '砲丸投', '円盤投', 'やり投'];
const RECORD_MARATHON_EVENTS = ['ハーフマラソン', 'フルマラソン'];

// 練習日記の種目名 → 歴代記録ページの種目名
const RECORD_EVENT_NAME_FROM_DIARY = [
    '1000m' => '1000m(非公認)',
    'ハーフ' => 'ハーフマラソン',
    'フル' => 'フルマラソン',
];

// 管理者が手入力する歴代記録にも風速を持たせる（風を計測しない種目・風速不明の昔の記録は NULL）
function ensure_records_wind_column(PDO $pdo): void
{
    if (!$pdo->query("SHOW COLUMNS FROM records LIKE 'wind_mps'")->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE records ADD COLUMN wind_mps DECIMAL(3,1) NULL AFTER record_time");
    }
}

// 管理者フォームの風速の選択肢（向かい風5.0〜追い風2.0。2.0を超える追い風は公認にならないので選べない）
function record_wind_options(): array
{
    $options = [];
    for ($tenths = (int)round(TRAINING_DIARY_WIND_LIMIT * 10); $tenths >= -50; $tenths--) {
        $options[] = sprintf('%+.1f', $tenths / 10);
    }
    return $options;
}

// 管理者フォームから来た風速を検証して返す。風を計測しない種目・未入力は null
function parse_admin_record_wind(string $event_type, string $raw): ?float
{
    $raw = trim($raw);
    if ($raw === '' || !training_diary_event_uses_wind($event_type)) {
        return null;
    }
    if (!in_array($raw, record_wind_options(), true)) {
        throw new RuntimeException('風速の指定が不正です（追い風2.0を超える記録は歴代記録に登録できません）。');
    }
    return (float)$raw;
}

function format_record_time_with_wind(string $record_time, $wind_mps): string
{
    return $wind_mps === null || $wind_mps === '' ? $record_time : $record_time . sprintf(' (%+.1f)', (float)$wind_mps);
}

function record_event_is_field(string $event): bool
{
    return in_array($event, RECORD_FIELD_EVENTS, true);
}

// 記録の文字を比較用の数値にする（タイムは秒、距離は m）。読めない書き方なら null
// 例: "11.33" "2:47" "4:28.19" "4:28:19"(分:秒:1/100秒) "2:31:05"(マラソンは 時:分:秒) "6m50cm" "6m50" "6.50m"
function parse_record_performance(string $event, string $text): ?float
{
    $value = trim(mb_convert_kana($text, 'as'));
    $value = preg_replace('/\s*\(.*\)$/u', '', $value); // 末尾の「(+1.2)」などの風速表記は除く

    if (record_event_is_field($event)) {
        if (preg_match('/^(\d+)m(\d{1,2})(cm)?$/u', $value, $m)) {
            return (int)$m[1] + (int)$m[2] / 100;
        }
        if (preg_match('/^(\d+(?:\.\d+)?)m?$/u', $value, $m)) {
            return (float)$m[1];
        }
        return null;
    }

    if (preg_match('/^(\d+):(\d{1,2}):(\d{1,2})$/', $value, $m)) {
        return in_array($event, RECORD_MARATHON_EVENTS, true)
            ? (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)$m[3]
            : (int)$m[1] * 60 + (int)$m[2] + (int)$m[3] / 100;
    }
    if (preg_match('/^(\d+):(\d{1,2}(?:\.\d+)?)$/', $value, $m)) {
        return (int)$m[1] * 60 + (float)$m[2];
    }
    if (preg_match('/^\d+(?:\.\d+)?$/', $value)) {
        return (float)$value;
    }
    return null;
}

// 練習日記の記録を歴代記録ページの書き方にそろえる（例: "4:28:19"→"4:28.19"、"6m50cm"→"6m50"）
function format_diary_result_for_records(string $event, string $result_label, ?float $wind_mps): string
{
    $label = $result_label;
    if (record_event_is_field($event)) {
        $label = preg_replace('/^(\d+)m(\d{1,2})cm$/u', '$1m$2', $label);
    } elseif (!in_array($event, RECORD_MARATHON_EVENTS, true)) {
        $label = preg_replace('/^(\d+):(\d{2}):(\d{2})$/', '$1:$2.$3', $label);
    }
    if ($wind_mps !== null) {
        $label .= sprintf(' (%+.1f)', $wind_mps);
    }
    return $label;
}

// 歴代記録に載せてよい練習日記の大会記録。
// 公開範囲（全体公開・自分のカレンダーのみ・グループ）に関係なく、大会の記録はすべて照合・入れ替えの対象にする。
// 性別が未登録の会員は男女に分けられないので除く
function fetch_diary_record_rows(PDO $pdo): array
{
    ensure_training_diary_race_entries_table($pdo);

    $stmt = $pdo->query(
        "SELECT e.event_name, e.result_label, e.wind_mps, e.is_official, d.practice_date, m.name, m.gender
         FROM training_diary_race_entries e
         INNER JOIN training_diaries d ON d.id = e.diary_id
         INNER JOIN members m ON m.id = d.user_id
         WHERE e.is_official = 1
           AND m.gender IN ('male', 'female')"
    );

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $entry) {
        $diary_event = (string)$entry['event_name'];
        $wind_mps = $entry['wind_mps'] !== null ? (float)$entry['wind_mps'] : null;
        if (!training_diary_race_entry_counts_for_records($diary_event, $wind_mps, (int)$entry['is_official'] === 1)) {
            continue;
        }

        $event = RECORD_EVENT_NAME_FROM_DIARY[$diary_event] ?? $diary_event;
        $performance = parse_record_performance($event, (string)$entry['result_label']);
        if ($performance === null) {
            continue;
        }

        $rows[] = [
            'gender' => (string)$entry['gender'],
            'discipline' => record_event_is_field($event) ? 'field' : 'track',
            'event' => $event,
            'name' => (string)$entry['name'],
            'record_time' => format_diary_result_for_records($event, (string)$entry['result_label'], $wind_mps),
            'match_date' => (string)$entry['practice_date'],
            'match_name' => '練習日記より',
            'performance' => $performance,
        ];
    }
    return $rows;
}

// 1種目分の記録から TOP5 を作る。
// タイム・距離が読める記録は数値で並べ、同じ人は一番良い記録だけ残す。
// 読めない書き方の管理者登録は、その後ろに管理者が付けた順位の順で並べる
function build_record_top5(string $event, array $rows): array
{
    $is_field = record_event_is_field($event);
    $best_by_person = [];
    $unparsed = [];

    foreach ($rows as $row) {
        if (!isset($row['performance'])) {
            $row['performance'] = parse_record_performance($event, (string)($row['record_time'] ?? ''));
        }
        if ($row['performance'] === null) {
            $unparsed[] = $row;
            continue;
        }
        $person_key = preg_replace('/\s+/u', '', (string)($row['name'] ?? ''));
        $current = $best_by_person[$person_key] ?? null;
        $is_better = $current === null
            || ($is_field ? $row['performance'] > $current['performance'] : $row['performance'] < $current['performance']);
        if ($is_better) {
            $best_by_person[$person_key] = $row;
        }
    }

    $ranked = array_values($best_by_person);
    usort($ranked, function (array $a, array $b) use ($is_field): int {
        $order = $is_field ? $b['performance'] <=> $a['performance'] : $a['performance'] <=> $b['performance'];
        return $order !== 0 ? $order : strcmp((string)($a['match_date'] ?? ''), (string)($b['match_date'] ?? ''));
    });
    usort($unparsed, fn(array $a, array $b): int => (int)($a['rank_num'] ?? 0) <=> (int)($b['rank_num'] ?? 0));

    $top = array_slice(array_merge($ranked, $unparsed), 0, 5);
    foreach ($top as $index => &$row) {
        $row['rank_num'] = $index + 1;
    }
    unset($row);

    for ($rank = count($top) + 1; $rank <= 5; $rank++) {
        $top[] = [
            'rank_num' => $rank,
            'name' => 'なし',
            'record_time' => 'なし',
            'match_name' => 'なし',
            'match_date' => null,
            '__empty' => true,
        ];
    }
    return $top;
}

// 歴代記録のトラック種目（男女とも同じ。表示はこの順。フィールド種目は上の RECORD_FIELD_EVENTS）
const RECORD_TRACK_EVENTS = ['100m', '200m', '400m', '800m', '1000m(非公認)', '1500m', '5000m', 'ハーフマラソン', 'フルマラソン'];

// 歴代記録の一覧（歴代記録のページとクラブ画面で共通）。
// 管理者が登録した記録（records）と、練習日記の大会記録（公認・追い風2.0以下）を合わせ、種目ごとに上位5人にする。
// 戻り値: ['male_track' => [種目 => 上位5人], 'male_field' => …, 'female_track' => …, 'female_field' => …]
function fetch_record_tables(PDO $pdo): array
{
    $tables = [];
    foreach (['male_track' => RECORD_TRACK_EVENTS, 'male_field' => RECORD_FIELD_EVENTS, 'female_track' => RECORD_TRACK_EVENTS, 'female_field' => RECORD_FIELD_EVENTS] as $key => $events) {
        $tables[$key] = array_fill_keys($events, []);
    }
    $categories = [
        'male_track' => ['トラック', '男子トラック', 'トラック男子'],
        'male_field' => ['フィールド', '男子フィールド', 'フィールド男子'],
        'female_track' => ['女子トラック', 'トラック女子'],
        'female_field' => ['女子フィールド', 'フィールド女子'],
    ];

    try {
        foreach ($pdo->query('SELECT * FROM records ORDER BY sort_order ASC, rank_num ASC')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $category = trim((string)($row['event_category'] ?? ''));
            $event = trim((string)($row['event_type'] ?? ''));
            // 1000mは非公認表記に統一して重複表示を防ぐ
            if (preg_match('/^1000\s*[mｍ]$/u', $event)) {
                $event = '1000m(非公認)';
            }
            if ($event === '') {
                continue;
            }
            // 管理者が風速を登録した記録は「10.95 (+1.2)」のように表示する（順位の計算では括弧内を無視する）
            $row['record_time'] = format_record_time_with_wind((string)($row['record_time'] ?? ''), $row['wind_mps'] ?? null);
            foreach ($categories as $key => $names) {
                if (in_array($category, $names, true)) {
                    $tables[$key][$event][] = $row;
                    break;
                }
            }
        }

        // 練習日記の大会記録（公認・追い風2.0以下）も同じ種目に加える
        try {
            foreach (fetch_diary_record_rows($pdo) as $diary_row) {
                $key = ($diary_row['gender'] === 'female' ? 'female_' : 'male_') . ($diary_row['discipline'] === 'field' ? 'field' : 'track');
                $tables[$key][$diary_row['event']][] = $diary_row;
            }
        } catch (PDOException $e) {
            // 日記の記録が読めなくても、管理者が登録した記録は表示する
            error_log('fetch_diary_record_rows failed: ' . $e->getMessage());
        }
    } catch (PDOException $e) {
        error_log('fetch_record_tables failed: ' . $e->getMessage());
    }

    foreach ($tables as $key => $by_event) {
        foreach ($by_event as $event => $rows) {
            $tables[$key][$event] = build_record_top5((string)$event, $rows);
        }
    }
    return $tables;
}
