<?php
// 試合申し込みの種目と応募記録（申し込みフォームと申し込みの編集で共通）。
// 種目は「区分 → 種目」の2段階のプルダウン、応募記録は種目の形式に合わせたプルダウンで選ぶ（手入力なし）。
// 応募記録は「どのタイムの組で走りたいか」を決める目安なので、選んだ種目には必ず記録を付ける。
// 画面の部品は includes/entry_event_fields.php、動きは js/entry_event_picker.js

// 応募記録の形式（保存する文字の形）
//   sprint   秒.1/100       例 11.23
//   lap      分:秒.1/100    例 52.34（1分未満は秒だけ）、1:05.32
//   middle   分:秒.1/100    例 4:28.19、31:05.40
//   marathon 時間:分:秒      例 2:31:05
//   distance m と cm        例 6m50
const ENTRY_RECORD_PATTERNS = [
    'sprint' => '/^[1-5]?\d\.\d{2}$/',
    'lap' => '/^(?:[1-3]:[0-5]\d|[1-5]?\d)\.\d{2}$/',
    'middle' => '/^[1-9]?\d:[0-5]\d\.\d{2}$/',
    'marathon' => '/^\d:[0-5]\d:[0-5]\d$/',
    'distance' => '/^[1-9]?\dm\d{2}$/',
];

// 区分 => [種目名 => 応募記録の形式]。プルダウンはこの並び順
const ENTRY_EVENT_GROUPS = [
    '短距離' => ['100m' => 'sprint', '200m' => 'sprint', '400m' => 'lap'],
    'ハードル' => ['100mH' => 'sprint', '110mH' => 'sprint', '400mH' => 'lap'],
    '中長距離' => ['800m' => 'middle', '1500m' => 'middle', '3000m' => 'middle', '5000m' => 'middle', '10000m' => 'middle', '3000mSC' => 'middle'],
    '跳躍' => ['走高跳' => 'distance', '棒高跳' => 'distance', '走幅跳' => 'distance', '三段跳' => 'distance'],
    '投てき' => ['砲丸投' => 'distance', '円盤投' => 'distance', 'ハンマー投' => 'distance', 'やり投' => 'distance'],
    'ロード' => ['10km' => 'middle', 'ハーフマラソン' => 'marathon', 'フルマラソン' => 'marathon'],
];

// NEXT PR の種目キーと名前が違う種目（申し込みの種目名 => NEXT PR の種目キー）。自己ベストを応募記録の初期値にするときに使う
const ENTRY_EVENT_NEXT_PR_KEYS = [
    'ハーフマラソン' => 'ハーフ',
    'フルマラソン' => 'フル',
];

const ENTRY_EVENT_SLOTS = 3;

// 種目の応募記録の形式。一覧に無い種目なら null
function entry_event_format(string $event_name): ?string
{
    foreach (ENTRY_EVENT_GROUPS as $events) {
        if (isset($events[$event_name])) {
            return $events[$event_name];
        }
    }
    return null;
}

// 送られた種目1〜3と応募記録を確かめ、保存する値にそろえる。種目1は必須、2・3は選んだときだけ。
// 戻り値: ['event_name1' => …, 'pb_time1' => …, …]（選ばなかった枠は null）。正しくなければ RuntimeException
function validate_entry_events(array $input): array
{
    $values = [];
    $chosen = [];
    for ($slot = 1; $slot <= ENTRY_EVENT_SLOTS; $slot++) {
        $event_name = trim((string)($input['event_name' . $slot] ?? ''));
        $record = trim((string)($input['pb_time' . $slot] ?? ''));
        if ($event_name === '') {
            if ($slot === 1) {
                throw new RuntimeException('種目1を選んでください。');
            }
            $values['event_name' . $slot] = null;
            $values['pb_time' . $slot] = null;
            continue;
        }
        $format = entry_event_format($event_name);
        if ($format === null) {
            throw new RuntimeException('種目' . $slot . 'の種目が正しくありません。一覧から選び直してください。');
        }
        if (in_array($event_name, $chosen, true)) {
            throw new RuntimeException('「' . $event_name . '」が2回選ばれています。');
        }
        if ($record === '' || preg_match(ENTRY_RECORD_PATTERNS[$format], $record) !== 1) {
            throw new RuntimeException('種目' . $slot . '（' . $event_name . '）の応募記録を選んでください。');
        }
        $chosen[] = $event_name;
        $values['event_name' . $slot] = $event_name;
        $values['pb_time' . $slot] = $record;
    }
    return $values;
}

// その会員の NEXT PR の自己ベスト（申し込みの種目名 => 値。タイムは秒、距離は m）。応募記録の初期値に使う。
// NEXT PR の表がまだ無いなどで読めなければ空（初期値なしで選んでもらう）
function fetch_entry_pb_values(PDO $pdo, int $user_id): array
{
    require_once __DIR__ . '/next_pr_lib.php';
    $values = [];
    try {
        $events_by_key = [];
        foreach (fetch_pr_events($pdo) as $event) {
            $events_by_key[$event['event_key']] = $event;
        }
        foreach (ENTRY_EVENT_GROUPS as $events) {
            foreach (array_keys($events) as $event_name) {
                $event = $events_by_key[ENTRY_EVENT_NEXT_PR_KEYS[$event_name] ?? $event_name] ?? null;
                if ($event === null) {
                    continue;
                }
                $best = next_pr_best($event, fetch_next_pr_results($pdo, $user_id, $event));
                if ($best !== null) {
                    $values[$event_name] = (float)$best['value'];
                }
            }
        }
    } catch (Throwable $e) {
        error_log('entry pb values failed: ' . $e->getMessage());
        return [];
    }
    return $values;
}
