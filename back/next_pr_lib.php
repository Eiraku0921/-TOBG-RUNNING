<?php

// NEXT PR: 種目ごとの自己ベスト（PR）・シーズンベスト・目標。陸上と筋トレの2カテゴリを扱う。
//
// データの持ち方
//   pr_events        種目マスタ（カテゴリ・グループ・表示形式）。下の NEXT_PR_EVENT_SEED から作る
//   pr_user_events   会員がタブに追加した種目
//   strength_records 筋トレの記録（このページで手入力）
//   pr_goals         目標（goal_value と、設定時の自己ベスト start_value）
//   pr_event_photos  種目ごとの写真（会員1人・1種目に1枚。ホームの NEXT PR のカードの背景。無ければ NEXT_PR_PHOTO_DEFAULT）。
//                    photo_path は背景の枠に合わせて切り抜いた写真、original_path は「位置を調整」でやり直すための元写真
// 陸上の記録は練習日記の「大会」（training_diary_race_entries）をそのまま読み、コピーしない。
// 筋トレも練習日記の「ウエイト」（training_diary_weight_sets）をそのまま読む。大事なのは挙げた重量なので、
// 回数は問わない（80kg×1回でも80kg×5回でも、記録は80kg）。
// ベストに数えるのは、陸上は歴代記録と同じ「公認・追い風2.0以下」、筋トレはすべての記録。シーズンは1月〜12月。
// 記録は5年分を残し、それより古いものは消す（陸上は大会の日記の保存期間、筋トレは下のクリーンアップ）。

require_once __DIR__ . '/training_diary_lib.php';

// 種目ごとの写真（ホームの NEXT PR のカードの背景）
const NEXT_PR_PHOTO_DIR = 'uploads/pr_photos';
const NEXT_PR_PHOTO_MAX_BYTES = 10 * 1024 * 1024;
const NEXT_PR_PHOTO_DEFAULT = 'images/np_default.webp'; // 未ログイン・写真を入れていない種目で使う

const NEXT_PR_CATEGORY_TRACK = 'track';
const NEXT_PR_CATEGORY_STRENGTH = 'strength';
const NEXT_PR_CATEGORY_LABELS = [NEXT_PR_CATEGORY_TRACK => '陸上競技', NEXT_PR_CATEGORY_STRENGTH => '筋トレ'];

const NEXT_PR_GOAL_SEASON = 'season';
const NEXT_PR_GOAL_LONG = 'long_term';

// 長期目標の基準（どの記録から測るか）。20代の自己ベストを30代で超えるのは難しい、といった場合に本人が選ぶ
const NEXT_PR_BASELINE_PB = 'pb';         // 全期間の自己ベスト
const NEXT_PR_BASELINE_RECENT = 'recent'; // 直近 NEXT_PR_RECENT_YEARS 年のベスト
const NEXT_PR_BASELINE_MANUAL = 'manual'; // 自分で入れた記録から。目標を決めた日以降のベストで測る
const NEXT_PR_RECENT_YEARS = 2;
const NEXT_PR_BASELINE_LABELS = [
    NEXT_PR_BASELINE_PB => '自己ベスト',
    NEXT_PR_BASELINE_RECENT => '直近' . NEXT_PR_RECENT_YEARS . '年のベスト',
    NEXT_PR_BASELINE_MANUAL => '自分で入力',
];
const NEXT_PR_LONG_GOAL_MAX_YEARS = 10;
const NEXT_PR_KEEP_YEARS = TRAINING_DIARY_RACE_KEEP_YEARS;

// 表示形式
//   sprint   : 秒.1/100秒（例 11.82秒）
//   middle   : 分:秒（例 19:20、端数があれば 4:28.19）
//   marathon : 時間:分:秒（例 2:31:05）
//   distance : m（例 6m50）。距離・高さは大きいほど良い
//   weight   : kg 小数1桁（例 80.0kg）。重いほど良い
const NEXT_PR_HIGHER_IS_BETTER_FORMATS = ['distance', 'weight'];

// 種目マスタの元データ: [種目キー, 表示名, カテゴリ, グループ, 表示形式]。並び順はこの順。
// 陸上の種目キーは練習日記の種目名と同じにする（日記の記録と突き合わせるため）
const NEXT_PR_EVENT_SEED = [
    ['100m', '100m', 'track', '短距離', 'sprint'],
    ['200m', '200m', 'track', '短距離', 'sprint'],
    ['400m', '400m', 'track', '短距離', 'sprint'],
    ['800m', '800m', 'track', '中長距離', 'middle'],
    ['1000m', '1000m', 'track', '中長距離', 'middle'],
    ['1500m', '1500m', 'track', '中長距離', 'middle'],
    ['5000m', '5000m', 'track', '中長距離', 'middle'],
    ['10000m', '10000m', 'track', '中長距離', 'middle'],
    ['ハーフ', 'ハーフマラソン', 'track', '駅伝・ロード', 'marathon'],
    ['フル', 'フルマラソン', 'track', '駅伝・ロード', 'marathon'],
    ['走幅跳', '走幅跳', 'track', '跳躍', 'distance'],
    ['三段跳', '三段跳', 'track', '跳躍', 'distance'],
    ['走高跳', '走高跳', 'track', '跳躍', 'distance'],
    ['棒高跳', '棒高跳', 'track', '跳躍', 'distance'],
    ['砲丸投', '砲丸投', 'track', '投擲', 'distance'],
    ['円盤投', '円盤投', 'track', '投擲', 'distance'],
    ['やり投', 'やり投', 'track', '投擲', 'distance'],
    ['ベンチプレス', 'ベンチプレス', 'strength', '上半身', 'weight'],
    ['インクラインベンチプレス', 'インクラインベンチプレス', 'strength', '上半身', 'weight'],
    ['ショルダープレス', 'ショルダープレス', 'strength', '上半身', 'weight'],
    ['懸垂', '懸垂（加重）', 'strength', '上半身', 'weight'],
    ['ベントオーバーロウ', 'ベントオーバーロウ', 'strength', '上半身', 'weight'],
    ['スクワット', 'スクワット', 'strength', '下半身', 'weight'],
    ['デッドリフト', 'デッドリフト', 'strength', '下半身', 'weight'],
    ['レッグプレス', 'レッグプレス', 'strength', '下半身', 'weight'],
    ['パワークリーン', 'パワークリーン', 'strength', '全身', 'weight'],
    ['スナッチ', 'スナッチ', 'strength', '全身', 'weight'],
];

// 練習日記のウエイトの種目名のうち、NEXT PR の種目キーと名前が違うもの（日記の種目名 => 種目キー）。
// それ以外は、種目キーと同じ名前の種目だけを NEXT PR の記録として読む
const NEXT_PR_DIARY_EXERCISE_ALIASES = [
    'チンニング(懸垂)' => '懸垂',
];

// ---- テーブル ----

function ensure_next_pr_tables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS pr_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_key VARCHAR(50) NOT NULL,
            label VARCHAR(50) NOT NULL,
            category VARCHAR(10) NOT NULL,
            group_label VARCHAR(20) NOT NULL,
            display_format VARCHAR(10) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            UNIQUE KEY uniq_pr_events_key (event_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    // マスタはコードの一覧に合わせて毎回そろえる（表示名や並び順を変えたときも反映される）
    $seed_stmt = $pdo->prepare(
        'INSERT INTO pr_events (event_key, label, category, group_label, display_format, sort_order)
         VALUES (:event_key, :label, :category, :group_label, :display_format, :sort_order)
         ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category), group_label = VALUES(group_label),
             display_format = VALUES(display_format), sort_order = VALUES(sort_order)'
    );
    foreach (NEXT_PR_EVENT_SEED as $index => [$key, $label, $category, $group, $format]) {
        $seed_stmt->execute([
            ':event_key' => $key, ':label' => $label, ':category' => $category,
            ':group_label' => $group, ':display_format' => $format, ':sort_order' => $index,
        ]);
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS pr_user_events (
            user_id INT NOT NULL,
            event_id INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, event_id),
            CONSTRAINT fk_pr_user_events_event FOREIGN KEY (event_id) REFERENCES pr_events (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS strength_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            event_id INT NOT NULL,
            record_date DATE NOT NULL,
            weight_kg DECIMAL(5,1) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_strength_records_user_event (user_id, event_id, record_date),
            CONSTRAINT fk_strength_records_event FOREIGN KEY (event_id) REFERENCES pr_events (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $goals_existed = (bool)$pdo->query("SHOW TABLES LIKE 'pr_goals'")->fetch();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS pr_goals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            event_id INT NOT NULL,
            goal_type VARCHAR(10) NOT NULL,
            target_year SMALLINT NOT NULL,
            goal_value DECIMAL(9,2) NOT NULL,
            start_value DECIMAL(9,2) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_pr_goals (user_id, event_id, goal_type),
            CONSTRAINT fk_pr_goals_event FOREIGN KEY (event_id) REFERENCES pr_events (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    // 長期目標の基準の種類と、「自分で入力」のときにどの日以降の記録で測るか
    if (!$pdo->query("SHOW COLUMNS FROM pr_goals LIKE 'baseline_type'")->fetch()) {
        $pdo->exec("ALTER TABLE pr_goals ADD COLUMN baseline_type VARCHAR(10) NOT NULL DEFAULT 'pb' AFTER start_value, ADD COLUMN baseline_from DATE NULL AFTER baseline_type");
    }
    // 旧マイタイムの目標（personal_goals）があれば、pr_goals を作った最初の1回だけ移す
    if (!$goals_existed && $pdo->query("SHOW TABLES LIKE 'personal_goals'")->fetch()) {
        $pdo->exec(
            "INSERT IGNORE INTO pr_goals (user_id, event_id, goal_type, target_year, goal_value, start_value, created_at)
             SELECT g.user_id, e.id, IF(g.goal_type = 'long', 'long_term', g.goal_type), g.target_year, g.goal_time, g.start_time, g.created_at
             FROM personal_goals g
             INNER JOIN pr_events e ON e.event_key = g.event_name"
        );
    }

    // 陸上の自己申告の自己ベスト。練習日記の「大会」以外で出した記録を、鉛筆からいつでも入れ直せる（1人1種目1件、上書き）。
    // 以前は「記録が無い種目に1回だけ入れる初期値」（pr_initial_records）だったので、あれば名前を変えて引き継ぐ
    $self_records_exist = (bool)$pdo->query("SHOW TABLES LIKE 'pr_self_records'")->fetch();
    if (!$self_records_exist && $pdo->query("SHOW TABLES LIKE 'pr_initial_records'")->fetch()) {
        $pdo->exec('RENAME TABLE pr_initial_records TO pr_self_records');
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS pr_self_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            event_id INT NOT NULL,
            record_date DATE NOT NULL,
            value DECIMAL(9,2) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_pr_self_records (user_id, event_id),
            CONSTRAINT fk_pr_self_records_event FOREIGN KEY (event_id) REFERENCES pr_events (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    // 引き継いだ表は、一意の制約の名前と「更新日時」の列を新しい表にそろえる
    if ($pdo->query("SHOW INDEX FROM pr_self_records WHERE Key_name = 'uniq_pr_initial_records'")->fetch()) {
        $pdo->exec('ALTER TABLE pr_self_records RENAME INDEX uniq_pr_initial_records TO uniq_pr_self_records');
    }
    if (!$pdo->query("SHOW COLUMNS FROM pr_self_records LIKE 'updated_at'")->fetch()) {
        $pdo->exec('ALTER TABLE pr_self_records ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');
    }

    // 会員ごとの NEXT PR の表示設定。いまは自己ベスト / シーズンベストのどちらを最後に見ていたか（ホームと NEXT PR で共通）
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS pr_user_settings (
            user_id INT NOT NULL PRIMARY KEY,
            best_view VARCHAR(2) NOT NULL DEFAULT 'pb',
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // 種目ごとの写真（1人1種目1枚。入れ直すと上書きし、前のファイルは消す）
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS pr_event_photos (
            user_id INT NOT NULL,
            event_id INT NOT NULL,
            photo_path VARCHAR(255) NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, event_id),
            CONSTRAINT fk_pr_event_photos_event FOREIGN KEY (event_id) REFERENCES pr_events (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    // 切り抜く前の元写真（「位置を調整」でやり直すときに使う。長辺 1600px 程度に縮めたもの）
    if (!$pdo->query("SHOW COLUMNS FROM pr_event_photos LIKE 'original_path'")->fetch()) {
        $pdo->exec('ALTER TABLE pr_event_photos ADD COLUMN original_path VARCHAR(255) NULL AFTER photo_path');
    }

    // 筋トレの記録・陸上の自己申告の自己ベストも、大会の記録と同じく5年で消す
    $pdo->exec('DELETE FROM strength_records WHERE record_date < DATE_SUB(CURDATE(), INTERVAL ' . NEXT_PR_KEEP_YEARS . ' YEAR)');
    $pdo->exec('DELETE FROM pr_self_records WHERE record_date < DATE_SUB(CURDATE(), INTERVAL ' . NEXT_PR_KEEP_YEARS . ' YEAR)');
}

// 種目マスタ（並び順）。キーは種目ID
function fetch_pr_events(PDO $pdo): array
{
    $events = [];
    foreach ($pdo->query('SELECT id, event_key, label, category, group_label, display_format FROM pr_events ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['id'] = (int)$row['id'];
        $events[$row['id']] = $row;
    }
    return $events;
}

// 種目追加モーダル用: カテゴリ → グループ → 種目（マスタの並び順）
function next_pr_event_catalog(array $events): array
{
    $catalog = [NEXT_PR_CATEGORY_TRACK => [], NEXT_PR_CATEGORY_STRENGTH => []];
    foreach ($events as $event) {
        $catalog[$event['category']][$event['group_label']][] = $event;
    }
    return $catalog;
}

function next_pr_higher_is_better(array $event): bool
{
    return in_array($event['display_format'], NEXT_PR_HIGHER_IS_BETTER_FORMATS, true);
}

function next_pr_is_better(array $event, float $a, float $b): bool
{
    return next_pr_higher_is_better($event) ? $a > $b : $a < $b;
}

// ---- 記録の読み書きと表示 ----

// 練習日記に保存された陸上の記録の文字を数値にする（タイムは秒、距離は m）
function next_pr_parse_diary_result(array $event, string $label): ?float
{
    $label = trim($label);
    switch ($event['display_format']) {
        case 'sprint':
            return preg_match('/^(\d+)\.(\d{2})$/', $label, $m) ? (int)$m[1] + (int)$m[2] / 100 : null;
        case 'middle':
            return preg_match('/^(\d+):(\d{2}):(\d{2})$/', $label, $m) ? (int)$m[1] * 60 + (int)$m[2] + (int)$m[3] / 100 : null;
        case 'marathon':
            return preg_match('/^(\d+):(\d{2}):(\d{2})$/', $label, $m) ? (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)$m[3] : null;
        case 'distance':
            return preg_match('/^(\d+)m(\d{2})cm$/u', $label, $m) ? (int)$m[1] + (int)$m[2] / 100 : null;
        default:
            return null;
    }
}

const NEXT_PR_WEIGHT_MAX_KG = 170;

// 筋トレの重量はプルダウンで選ぶ。刻みは 2.5kg か 5kg（5kg も 2.5kg の倍数なので、保存できる値は 2.5kg 刻み）
const NEXT_PR_WEIGHT_STEPS = [2.5, 5.0];
const NEXT_PR_WEIGHT_BASE_STEP = 2.5;

// 重量プルダウンの選択肢（例: 2.5kg刻みなら 2.5, 5.0, 7.5 … 上限まで）
function next_pr_weight_options(float $step): array
{
    $options = [];
    $count = (int)floor(NEXT_PR_WEIGHT_MAX_KG / $step);
    for ($i = 1; $i <= $count; $i++) {
        $options[] = $i * $step;
    }
    return $options;
}

// 選んだ値にいちばん近い選択肢（今の目標・自己ベストをプルダウンの初期値にするとき使う）
function next_pr_nearest_weight_option(float $value, float $step): float
{
    $nearest = round($value / $step) * $step;
    return max($step, min(NEXT_PR_WEIGHT_MAX_KG, $nearest));
}

// フォームから来た重量を検証する。2.5kg の倍数で 0 より大きく上限以下のときだけ数値を返す
function next_pr_parse_weight_choice(string $raw): ?float
{
    $raw = trim($raw);
    if (!preg_match('/^\d{1,3}(\.\d)?$/', $raw)) {
        return null;
    }
    $value = (float)$raw;
    $in_steps = abs(fmod($value * 10, NEXT_PR_WEIGHT_BASE_STEP * 10)) < 0.0001;
    return $value > 0 && $value <= NEXT_PR_WEIGHT_MAX_KG && $in_steps ? $value : null;
}

// 陸上の目標入力のプルダウン（手入力なし）。各欄の最大値・0埋め・欄の後ろに付ける文字。練習日記の記録入力と同じ区切り方。
// 筋トレの重量は別の重量プルダウン（2.5kg / 5kg 刻み）で選ぶ
const NEXT_PR_GOAL_PARTS = [
    'sprint' => [['max' => 59, 'pad' => false, 'unit' => '.'], ['max' => 99, 'pad' => true, 'unit' => '秒']],
    'middle' => [['max' => 99, 'pad' => false, 'unit' => '分'], ['max' => 59, 'pad' => true, 'unit' => '秒'], ['max' => 99, 'pad' => true, 'unit' => '']],
    'marathon' => [['max' => 9, 'pad' => false, 'unit' => '時間'], ['max' => 59, 'pad' => true, 'unit' => '分'], ['max' => 59, 'pad' => true, 'unit' => '秒']],
    'distance' => [['max' => 99, 'pad' => false, 'unit' => 'm'], ['max' => 99, 'pad' => true, 'unit' => 'cm']],
];

function next_pr_goal_parts_config(array $event): array
{
    return NEXT_PR_GOAL_PARTS[$event['display_format']] ?? NEXT_PR_GOAL_PARTS['sprint'];
}

// 数値をプルダウンの各欄に分ける（目標の初期値に使う）
function next_pr_value_to_parts(array $event, float $value): array
{
    $hundredths = (int)round($value * 100);
    switch ($event['display_format']) {
        case 'sprint':
        case 'distance':
            return [intdiv($hundredths, 100), $hundredths % 100];
        case 'middle':
            return [intdiv($hundredths, 6000), intdiv($hundredths % 6000, 100), $hundredths % 100];
        case 'marathon':
            $total = (int)round($value);
            return [intdiv($total, 3600), intdiv($total % 3600, 60), $total % 60];
        default:
            $tenths = (int)round($value * 10);
            return [intdiv($tenths, 10), $tenths % 10];
    }
}

// プルダウンの各欄から数値を作る。欄の数が違う・範囲外・合計が0なら null
function next_pr_parts_to_value(array $event, array $parts): ?float
{
    $config = next_pr_goal_parts_config($event);
    $parts = array_values($parts);
    if (count($parts) !== count($config)) {
        return null;
    }
    $ints = [];
    foreach ($config as $index => $part) {
        $raw = (string)$parts[$index];
        if (!preg_match('/^\d{1,3}$/', $raw) || (int)$raw > $part['max']) {
            return null;
        }
        $ints[] = (int)$raw;
    }
    switch ($event['display_format']) {
        case 'sprint':
        case 'distance':
            $value = $ints[0] + $ints[1] / 100;
            break;
        case 'middle':
            $value = $ints[0] * 60 + $ints[1] + $ints[2] / 100;
            break;
        case 'marathon':
            $value = $ints[0] * 3600 + $ints[1] * 60 + $ints[2];
            break;
        default:
            $value = $ints[0] + $ints[1] / 10;
    }
    return $value > 0 ? $value : null;
}

// 入力欄の例（プレースホルダー）と、入力欄のラベル
function next_pr_input_hint(array $event): array
{
    switch ($event['display_format']) {
        case 'sprint':
            return ['label' => '目標タイム（秒）', 'placeholder' => '例: 11.50', 'unit' => '秒'];
        case 'middle':
            return ['label' => '目標タイム（分:秒）', 'placeholder' => '例: 19:20', 'unit' => ''];
        case 'marathon':
            return ['label' => '目標タイム（時間:分:秒）', 'placeholder' => '例: 2:59:00', 'unit' => ''];
        case 'distance':
            return ['label' => '目標記録（m）', 'placeholder' => '例: 6.50', 'unit' => 'm'];
        default:
            return ['label' => '目標重量（kg）', 'placeholder' => '例: 80.0', 'unit' => 'kg'];
    }
}

// 表示用の文字と単位（例: 11.82 秒 / 4:28.19 / 19:20 / 2:31:05 / 6m50 / 80.0 kg）
function next_pr_format_value(array $event, float $value): array
{
    $hundredths = (int)round($value * 100);
    switch ($event['display_format']) {
        case 'sprint':
            return ['text' => sprintf('%d.%02d', intdiv($hundredths, 100), $hundredths % 100), 'unit' => '秒'];
        case 'middle':
            $minutes = intdiv($hundredths, 6000);
            $seconds = intdiv($hundredths % 6000, 100);
            $fraction = $hundredths % 100;
            return ['text' => $fraction === 0 ? sprintf('%d:%02d', $minutes, $seconds) : sprintf('%d:%02d.%02d', $minutes, $seconds, $fraction), 'unit' => ''];
        case 'marathon':
            $total = (int)round($value);
            return ['text' => sprintf('%d:%02d:%02d', intdiv($total, 3600), intdiv($total % 3600, 60), $total % 60), 'unit' => ''];
        case 'distance':
            return ['text' => sprintf('%dm%02d', intdiv($hundredths, 100), $hundredths % 100), 'unit' => ''];
        default:
            return ['text' => sprintf('%.1f', $value), 'unit' => 'kg'];
    }
}

function next_pr_format_text(array $event, float $value): string
{
    $formatted = next_pr_format_value($event, $value);
    return $formatted['text'] . $formatted['unit'];
}

// その種目の記録として読む、練習日記のウエイトの種目名（種目キーと同じ名前 ＋ 別名）
function next_pr_diary_exercise_names(string $event_key): array
{
    $names = [$event_key];
    foreach (NEXT_PR_DIARY_EXERCISE_ALIASES as $diary_name => $alias_key) {
        if ($alias_key === $event_key) {
            $names[] = $diary_name;
        }
    }
    return $names;
}

// 練習日記のウエイトの記録のうち、その種目のもの（古い順）。重量を入れたもの（自重は除く）だけを読み、
// 1つの日記に同じ種目が何セットかあれば一番重いセットを1件にする。回数は補足に出すだけで、ベストには関係しない
function fetch_next_pr_diary_strength_results(PDO $pdo, int $user_id, array $event): array
{
    ensure_training_diary_table($pdo);
    ensure_training_diary_weight_sets_table($pdo);

    $names = next_pr_diary_exercise_names($event['event_key']);
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $stmt = $pdo->prepare(
        "SELECT w.id, w.weight_kg, w.reps, d.id AS diary_id, d.practice_date, d.menu
         FROM training_diary_weight_sets w
         INNER JOIN training_diaries d ON d.id = w.diary_id
         WHERE d.user_id = ?
           AND w.exercise_name IN ($placeholders)
           AND w.is_bodyweight = 0
           AND w.weight_kg > 0
           AND d.practice_date >= DATE_SUB(CURDATE(), INTERVAL " . NEXT_PR_KEEP_YEARS . " YEAR)
         ORDER BY d.practice_date ASC, d.id ASC, w.weight_kg DESC, w.id ASC"
    );
    $stmt->execute(array_merge([$user_id], $names));

    $results = [];
    $seen_diary_ids = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        // 並びが「日記ごとに重い順」なので、日記ごとの最初の行が一番重いセット
        $diary_id = (int)$row['diary_id'];
        if (isset($seen_diary_ids[$diary_id])) {
            continue;
        }
        $seen_diary_ids[$diary_id] = true;
        $results[] = [
            'id' => (int)$row['id'],
            'value' => round((float)$row['weight_kg'], 1),
            'date' => (string)$row['practice_date'],
            'year' => (int)substr((string)$row['practice_date'], 0, 4),
            'title' => (string)$row['menu'],
            'notes' => $row['reps'] !== null ? (int)$row['reps'] . '回' : '',
            'counts' => true,
            'manual' => false,
        ];
    }
    return $results;
}

// その会員の、その種目の記録（古い順）。陸上は練習日記の大会記録、筋トレは練習日記のウエイトと手入力の記録
function fetch_next_pr_results(PDO $pdo, int $user_id, array $event): array
{
    $results = [];

    if ($event['category'] === NEXT_PR_CATEGORY_STRENGTH) {
        $results = fetch_next_pr_diary_strength_results($pdo, $user_id, $event);
        $stmt = $pdo->prepare(
            'SELECT id, record_date, weight_kg FROM strength_records
             WHERE user_id = :user_id AND event_id = :event_id
             ORDER BY record_date ASC, id ASC'
        );
        $stmt->execute([':user_id' => $user_id, ':event_id' => $event['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $results[] = [
                'id' => (int)$row['id'],
                'value' => (float)$row['weight_kg'],
                'date' => (string)$row['record_date'],
                'year' => (int)substr((string)$row['record_date'], 0, 4),
                'title' => '手入力',
                'notes' => '',
                'counts' => true,
                'manual' => true,
                'manual_kind' => 'strength',
            ];
        }
        // 日記の記録と手入力の記録を、日付の古い順に混ぜる（同じ日は日記の記録が先）
        usort($results, fn(array $a, array $b): int => strcmp($a['date'], $b['date']));
        return $results;
    }

    ensure_training_diary_table($pdo);
    ensure_training_diary_race_entries_table($pdo);
    $stmt = $pdo->prepare(
        "SELECT e.id, e.result_label, e.wind_mps, e.is_official, d.practice_date, d.menu AS match_name
         FROM training_diary_race_entries e
         INNER JOIN training_diaries d ON d.id = e.diary_id
         WHERE d.user_id = :user_id
           AND e.event_name = :event_key
           AND d.practice_date >= DATE_SUB(CURDATE(), INTERVAL " . NEXT_PR_KEEP_YEARS . " YEAR)
         ORDER BY d.practice_date ASC, e.id ASC"
    );
    $stmt->execute([':user_id' => $user_id, ':event_key' => $event['event_key']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $value = next_pr_parse_diary_result($event, (string)$row['result_label']);
        if ($value === null) {
            continue;
        }
        $wind_mps = $row['wind_mps'] !== null ? (float)$row['wind_mps'] : null;
        $is_official = (int)$row['is_official'] === 1;
        $counts = training_diary_race_entry_counts_for_records($event['event_key'], $wind_mps, $is_official);

        // 風速・非公認の補足。ベストの対象外になる理由もここで分かるようにする
        $notes = [];
        if (training_diary_event_uses_wind($event['event_key'])) {
            $notes[] = $wind_mps === null ? '風計測なし' : sprintf('%+.1fm/s', $wind_mps);
        }
        if (!$is_official) {
            $notes[] = '非公認';
        } elseif (!$counts) {
            $notes[] = 'ベスト対象外';
        }

        $results[] = [
            'id' => (int)$row['id'],
            'value' => $value,
            'date' => (string)$row['practice_date'],
            'year' => (int)substr((string)$row['practice_date'], 0, 4),
            'title' => (string)$row['match_name'],
            'notes' => implode('・', $notes),
            'counts' => $counts,
            'manual' => false,
        ];
    }

    // 自己申告の自己ベスト（練習日記以外で出した記録を利用者が入れたもの）も並べ、ベストの計算に入れる
    $self_record = fetch_next_pr_self_record($pdo, $user_id, $event['id']);
    if ($self_record !== null) {
        $results[] = [
            'id' => $self_record['id'],
            'value' => $self_record['value'],
            'date' => $self_record['date'],
            'year' => (int)substr($self_record['date'], 0, 4),
            'title' => '自己申告',
            'notes' => '',
            'counts' => true,
            'manual' => true,
            'manual_kind' => 'self',
        ];
        usort($results, fn(array $a, array $b): int => strcmp($a['date'], $b['date']));
    }
    return $results;
}

// その会員・種目の自己申告の自己ベスト（1件）。無ければ null
function fetch_next_pr_self_record(PDO $pdo, int $user_id, int $event_id): ?array
{
    $stmt = $pdo->prepare('SELECT id, record_date, value FROM pr_self_records WHERE user_id = :user_id AND event_id = :event_id LIMIT 1');
    $stmt->execute([':user_id' => $user_id, ':event_id' => $event_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? ['id' => (int)$row['id'], 'date' => (string)$row['record_date'], 'value' => (float)$row['value']] : null;
}

// 自己申告の自己ベストを保存する。すでにあれば上書き（打ち間違えも鉛筆から入れ直して直す）
function save_next_pr_self_record(PDO $pdo, int $user_id, int $event_id, string $record_date, float $value): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO pr_self_records (user_id, event_id, record_date, value) VALUES (:user_id, :event_id, :record_date, :value)
         ON DUPLICATE KEY UPDATE record_date = VALUES(record_date), value = VALUES(value)'
    );
    $stmt->execute([':user_id' => $user_id, ':event_id' => $event_id, ':record_date' => $record_date, ':value' => $value]);
}

// 記録（古い順）に、その時点で自己ベスト・シーズンベストを更新したかの印を付ける
function next_pr_mark_bests(array $event, array $results): array
{
    $best = null;
    $season_best = [];
    foreach ($results as &$result) {
        $result['is_pb'] = false;
        $result['is_sb'] = false;
        if (!$result['counts']) {
            continue;
        }
        if ($best === null || next_pr_is_better($event, $result['value'], $best)) {
            $best = $result['value'];
            $result['is_pb'] = true;
        }
        $year = $result['year'];
        if (!isset($season_best[$year]) || next_pr_is_better($event, $result['value'], $season_best[$year])) {
            $season_best[$year] = $result['value'];
            $result['is_sb'] = true;
        }
    }
    unset($result);
    return $results;
}

// ベストの記録（$year を指定するとその年の中で）。対象の記録が無ければ null
function next_pr_best(array $event, array $results, ?int $year = null): ?array
{
    $best = null;
    foreach ($results as $result) {
        if (!$result['counts'] || ($year !== null && $result['year'] !== $year)) {
            continue;
        }
        if ($best === null || next_pr_is_better($event, $result['value'], $best['value'])) {
            $best = $result;
        }
    }
    return $best;
}

// 練習日記のウエイトに、重量を入れた記録がある種目のキーを返す副問い合わせ（タブの種目を集める SQL の中で使う）。
// 日記の種目名を NEXT_PR_DIARY_EXERCISE_ALIASES で種目キーに読み替える。使う値は $params に足す
function next_pr_diary_strength_keys_subquery(PDO $pdo, string $user_param, array &$params): string
{
    ensure_training_diary_weight_sets_table($pdo);
    $key_expr = 'w.exercise_name';
    if (!empty(NEXT_PR_DIARY_EXERCISE_ALIASES)) {
        $whens = '';
        $index = 0;
        foreach (NEXT_PR_DIARY_EXERCISE_ALIASES as $diary_name => $event_key) {
            $whens .= " WHEN :alias_name{$index} THEN :alias_key{$index}";
            $params[":alias_name{$index}"] = $diary_name;
            $params[":alias_key{$index}"] = $event_key;
            $index++;
        }
        $key_expr = "CASE w.exercise_name{$whens} ELSE w.exercise_name END";
    }
    return "SELECT {$key_expr} FROM training_diary_weight_sets w
            INNER JOIN training_diaries d ON d.id = w.diary_id
            WHERE d.user_id = {$user_param} AND w.is_bodyweight = 0 AND w.weight_kg > 0";
}

// タブに出す種目: 記録を持っている種目 ＋ 自分で追加した種目（マスタの並び順）
function fetch_next_pr_tab_event_ids(PDO $pdo, int $user_id): array
{
    $params = [':u1' => $user_id, ':u2' => $user_id, ':u3' => $user_id, ':u4' => $user_id, ':u5' => $user_id];
    $diary_strength_keys = next_pr_diary_strength_keys_subquery($pdo, ':u5', $params);
    $stmt = $pdo->prepare(
        "SELECT e.id FROM pr_events e
         WHERE e.id IN (SELECT event_id FROM pr_user_events WHERE user_id = :u1)
            OR e.id IN (SELECT event_id FROM strength_records WHERE user_id = :u2)
            OR e.id IN (SELECT event_id FROM pr_self_records WHERE user_id = :u4)
            OR e.event_key IN (
                SELECT r.event_name FROM training_diary_race_entries r
                INNER JOIN training_diaries d ON d.id = r.diary_id
                WHERE d.user_id = :u3
            )
            OR (e.category = 'strength' AND e.event_key IN ({$diary_strength_keys}))
         ORDER BY e.sort_order, e.id"
    );
    $stmt->execute($params);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// 記録を持っている種目（筋トレの記録、陸上の自己申告の自己ベスト、練習日記の大会記録・ウエイトの記録がある）。これらはタブから外せない
function fetch_next_pr_event_ids_with_records(PDO $pdo, int $user_id): array
{
    $params = [':u1' => $user_id, ':u2' => $user_id, ':u3' => $user_id, ':u4' => $user_id];
    $diary_strength_keys = next_pr_diary_strength_keys_subquery($pdo, ':u4', $params);
    $stmt = $pdo->prepare(
        "SELECT e.id FROM pr_events e
         WHERE e.id IN (SELECT event_id FROM strength_records WHERE user_id = :u1)
            OR e.id IN (SELECT event_id FROM pr_self_records WHERE user_id = :u3)
            OR e.event_key IN (
                SELECT r.event_name FROM training_diary_race_entries r
                INNER JOIN training_diaries d ON d.id = r.diary_id
                WHERE d.user_id = :u2
            )
            OR (e.category = 'strength' AND e.event_key IN ({$diary_strength_keys}))"
    );
    $stmt->execute($params);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// 間違えて追加した種目をタブから外す（目標などのデータは残すので、追加し直せば元に戻る）
function remove_next_pr_user_event(PDO $pdo, int $user_id, int $event_id): void
{
    $stmt = $pdo->prepare('DELETE FROM pr_user_events WHERE user_id = :user_id AND event_id = :event_id');
    $stmt->execute([':user_id' => $user_id, ':event_id' => $event_id]);
}

function add_next_pr_user_event(PDO $pdo, int $user_id, int $event_id): void
{
    $stmt = $pdo->prepare('INSERT IGNORE INTO pr_user_events (user_id, event_id) VALUES (:user_id, :event_id)');
    $stmt->execute([':user_id' => $user_id, ':event_id' => $event_id]);
}

function add_strength_record(PDO $pdo, int $user_id, int $event_id, string $record_date, float $weight_kg): void
{
    $stmt = $pdo->prepare('INSERT INTO strength_records (user_id, event_id, record_date, weight_kg) VALUES (:user_id, :event_id, :record_date, :weight_kg)');
    $stmt->execute([':user_id' => $user_id, ':event_id' => $event_id, ':record_date' => $record_date, ':weight_kg' => $weight_kg]);
}

// 自分の記録だけ消せる
function delete_strength_record(PDO $pdo, int $user_id, int $record_id): void
{
    $stmt = $pdo->prepare('DELETE FROM strength_records WHERE id = :id AND user_id = :user_id');
    $stmt->execute([':id' => $record_id, ':user_id' => $user_id]);
}

// ---- 目標 ----

function fetch_next_pr_goals(PDO $pdo, int $user_id, int $event_id): array
{
    $stmt = $pdo->prepare('SELECT goal_type, target_year, goal_value, start_value, baseline_type, baseline_from FROM pr_goals WHERE user_id = :user_id AND event_id = :event_id');
    $stmt->execute([':user_id' => $user_id, ':event_id' => $event_id]);

    $goals = [NEXT_PR_GOAL_SEASON => null, NEXT_PR_GOAL_LONG => null];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $goals[(string)$row['goal_type']] = [
            'target_year' => (int)$row['target_year'],
            'goal' => (float)$row['goal_value'],
            'start' => $row['start_value'] !== null ? (float)$row['start_value'] : null,
            'baseline_type' => array_key_exists((string)$row['baseline_type'], NEXT_PR_BASELINE_LABELS) ? (string)$row['baseline_type'] : NEXT_PR_BASELINE_PB,
            'baseline_from' => $row['baseline_from'] !== null ? (string)$row['baseline_from'] : null,
        ];
    }
    // シーズン目標は今年のものだけ有効（去年の目標は未設定として扱う）
    if ($goals[NEXT_PR_GOAL_SEASON] !== null && $goals[NEXT_PR_GOAL_SEASON]['target_year'] !== (int)date('Y')) {
        $goals[NEXT_PR_GOAL_SEASON] = null;
    }
    return $goals;
}

// 目標を保存する。start_value には保存した時点の基準の記録を入れる。
// baseline_type は長期目標の基準の種類（シーズン目標は使わない）、baseline_from は「自分で入力」のときに数え始める日
function save_next_pr_goal(PDO $pdo, int $user_id, int $event_id, string $goal_type, int $target_year, float $goal, ?float $start, string $baseline_type = NEXT_PR_BASELINE_PB, ?string $baseline_from = null): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO pr_goals (user_id, event_id, goal_type, target_year, goal_value, start_value, baseline_type, baseline_from)
         VALUES (:user_id, :event_id, :goal_type, :target_year, :goal_value, :start_value, :baseline_type, :baseline_from)
         ON DUPLICATE KEY UPDATE target_year = VALUES(target_year), goal_value = VALUES(goal_value), start_value = VALUES(start_value),
             baseline_type = VALUES(baseline_type), baseline_from = VALUES(baseline_from)'
    );
    $stmt->execute([
        ':user_id' => $user_id, ':event_id' => $event_id, ':goal_type' => $goal_type,
        ':target_year' => $target_year, ':goal_value' => $goal, ':start_value' => $start,
        ':baseline_type' => $baseline_type, ':baseline_from' => $baseline_from,
    ]);
}

function delete_next_pr_goal(PDO $pdo, int $user_id, int $event_id, string $goal_type): void
{
    $stmt = $pdo->prepare('DELETE FROM pr_goals WHERE user_id = :user_id AND event_id = :event_id AND goal_type = :goal_type');
    $stmt->execute([':user_id' => $user_id, ':event_id' => $event_id, ':goal_type' => $goal_type]);
}

// 今シーズンのシーズンベスト。今年まだ記録が無いとき（初戦の前）は、去年のシーズンベストを返す。
// どちらの年の記録かは、戻り値の 'year' で分かる（去年も無ければ null）
function next_pr_current_season_best(array $event, array $results, int $season_year): ?array
{
    return next_pr_best($event, $results, $season_year) ?? next_pr_best($event, $results, $season_year - 1);
}

// 指定した日以降の記録だけで見たベスト
function next_pr_best_since(array $event, array $results, string $from_date): ?array
{
    return next_pr_best($event, array_values(array_filter($results, static fn(array $result): bool => $result['date'] >= $from_date)));
}

// 目標の達成度に使う記録。目標を決めたときの基準（start_value）にも同じ記録を使う。
//   シーズン目標: 今シーズン（1月〜12月）のシーズンベスト。今年の初戦までは去年のシーズンベスト
//                 （昔の自己ベストで、今シーズンの目標が最初から達成にならないように）
//   長期目標: 本人が選んだ基準。自己ベスト / 直近2年のベスト / 自分で入力（目標を決めた日 $baseline_from 以降のベスト）
function next_pr_goal_reference_best(array $event, array $results, string $goal_type, int $season_year, string $baseline_type = NEXT_PR_BASELINE_PB, ?string $baseline_from = null): ?array
{
    if ($goal_type === NEXT_PR_GOAL_SEASON) {
        return next_pr_current_season_best($event, $results, $season_year);
    }
    if ($baseline_type === NEXT_PR_BASELINE_RECENT) {
        return next_pr_best_since($event, $results, date('Y-m-d', strtotime('-' . NEXT_PR_RECENT_YEARS . ' years')));
    }
    if ($baseline_type === NEXT_PR_BASELINE_MANUAL) {
        return next_pr_best_since($event, $results, $baseline_from ?? date('Y-m-d'));
    }
    return next_pr_best($event, $results);
}

// 進捗%。陸上のタイム（小さいほど良い）は (設定時 − 現在) ÷ (設定時 − 目標)、
// 筋トレ・距離（大きいほど良い）は (現在 − 設定時) ÷ (目標 − 設定時)。0〜100 に収める。
// 計算できない（今の記録が無い、設定時の記録が無い等）ときは null
function next_pr_goal_progress(array $event, ?float $start, ?float $current, float $goal): ?int
{
    if ($current === null) {
        return null;
    }
    if (!next_pr_is_better($event, $goal, $current)) {
        return 100; // もう目標に届いている
    }
    if ($start === null || !next_pr_is_better($event, $goal, $start)) {
        return null;
    }
    $progress = next_pr_higher_is_better($event)
        ? ($current - $start) / ($goal - $start) * 100
        : ($start - $current) / ($start - $goal) * 100;
    return (int)round(max(0, min(100, $progress)));
}

// 自己ベスト（'pb'）/ シーズンベスト（'sb'）のどちらを表示するか。
// 画面で切り替えたとき（$requested が 'pb' か 'sb'）はそれを覚え、指定が無いときは最後に覚えた方を使う（初めては自己ベスト）
function next_pr_resolve_best_view(PDO $pdo, int $user_id, ?string $requested): string
{
    $stmt = $pdo->prepare('SELECT best_view FROM pr_user_settings WHERE user_id = :user_id LIMIT 1');
    $stmt->execute([':user_id' => $user_id]);
    $saved = $stmt->fetchColumn();
    $saved = $saved === 'sb' ? 'sb' : 'pb';

    if ($requested !== 'pb' && $requested !== 'sb') {
        return $saved;
    }
    if ($requested !== $saved) {
        $save_stmt = $pdo->prepare(
            'INSERT INTO pr_user_settings (user_id, best_view) VALUES (:user_id, :best_view)
             ON DUPLICATE KEY UPDATE best_view = VALUES(best_view)'
        );
        $save_stmt->execute([':user_id' => $user_id, ':best_view' => $requested]);
    }
    return $requested;
}

// ホームに出す NEXT PR のまとめ（種目タブ・自己ベスト/シーズンベスト・目標）。
// $event_id がタブに無ければ先頭の種目。$view は画面で選んだ表示（'pb' / 'sb'。今年のシーズンベスト）で、
// null なら最後に選んだ表示を使う
function fetch_next_pr_summary(PDO $pdo, int $user_id, int $event_id, ?string $view): array
{
    ensure_next_pr_tables($pdo);
    $events = fetch_pr_events($pdo);
    $tab_event_ids = empty($events) ? [] : fetch_next_pr_tab_event_ids($pdo, $user_id);
    if (!in_array($event_id, $tab_event_ids, true)) {
        $event_id = $tab_event_ids[0] ?? 0;
    }
    $event = $events[$event_id] ?? null;
    $view = next_pr_resolve_best_view($pdo, $user_id, $view);

    $summary = [
        'events' => $events,
        'tab_event_ids' => $tab_event_ids,
        'event_id' => $event_id,
        'event' => $event,
        'view' => $view,
        'personal_best' => null,
        'season_best' => null,
        'shown_best' => null,
        'goals' => [NEXT_PR_GOAL_SEASON => null, NEXT_PR_GOAL_LONG => null],
        // 目標ごとの達成度に使う記録（シーズン目標は今年のシーズンベスト、長期目標は自己ベスト）
        'goal_references' => [NEXT_PR_GOAL_SEASON => null, NEXT_PR_GOAL_LONG => null],
        // カードの背景の写真（サイトの一番上からの場所）。入れていなければ null（表示する側で NEXT_PR_PHOTO_DEFAULT を使う）
        'photo_path' => null,
    ];
    if ($event === null) {
        return $summary;
    }
    $summary['photo_path'] = fetch_next_pr_photo($pdo, $user_id, $event_id);

    $results = fetch_next_pr_results($pdo, $user_id, $event);
    $summary['personal_best'] = next_pr_best($event, $results);
    // ホームは自己ベスト・シーズンベストを両方描いておき、ページを読み直さずに切り替える
    $summary['season_best'] = next_pr_current_season_best($event, $results, (int)date('Y'));
    $summary['shown_best'] = $view === 'sb' ? $summary['season_best'] : $summary['personal_best'];
    $summary['goals'] = fetch_next_pr_goals($pdo, $user_id, $event_id);
    foreach (array_keys($summary['goal_references']) as $goal_type) {
        $goal = $summary['goals'][$goal_type];
        $summary['goal_references'][$goal_type] = next_pr_goal_reference_best(
            $event, $results, $goal_type, (int)date('Y'),
            $goal['baseline_type'] ?? NEXT_PR_BASELINE_PB, $goal['baseline_from'] ?? null
        );
    }
    return $summary;
}

// ---- 種目ごとの写真 ----

// その会員・種目の写真の場所（無ければ null）
function fetch_next_pr_photo(PDO $pdo, int $user_id, int $event_id): ?string
{
    return fetch_next_pr_photo_paths($pdo, $user_id, $event_id)['photo'];
}

// 背景の写真（切り抜いたもの）と元写真の場所。無ければどちらも null
function fetch_next_pr_photo_paths(PDO $pdo, int $user_id, int $event_id): array
{
    $stmt = $pdo->prepare('SELECT photo_path, original_path FROM pr_event_photos WHERE user_id = :user_id AND event_id = :event_id LIMIT 1');
    $stmt->execute([':user_id' => $user_id, ':event_id' => $event_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $photo = (string)($row['photo_path'] ?? '');
    $original = (string)($row['original_path'] ?? '');
    return ['photo' => $photo !== '' ? $photo : null, 'original' => $original !== '' ? $original : null];
}

// 送られてきた写真のファイルを写真のフォルダに置き、その場所を返す。入力の誤りは RuntimeException（画面に出す文）
function next_pr_store_photo_file(array $file, int $user_id, int $event_id, string $suffix): string
{
    $message = '写真は10MBまでのJPG・PNG・WEBPを選んでください。';
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('写真を選んでください。');
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || (int)($file['size'] ?? 0) > NEXT_PR_PHOTO_MAX_BYTES) {
        throw new RuntimeException($message);
    }
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
    $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if ($extension === null) {
        throw new RuntimeException($message);
    }
    $dir = __DIR__ . '/../' . NEXT_PR_PHOTO_DIR;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $name = $user_id . '_' . $event_id . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . $suffix . '.' . $extension;
    if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('写真の保存に失敗しました。');
    }
    return NEXT_PR_PHOTO_DIR . '/' . $name;
}

// 写真を保存する。$file は背景の枠に合わせて切り抜いた写真、$original_file は切り抜く前の元写真。
// 元写真が送られてこない（「位置を調整」でやり直したとき）は、今の元写真をそのまま使う。前のファイルは消す
function save_next_pr_photo(PDO $pdo, int $user_id, int $event_id, array $file, ?array $original_file = null): void
{
    $has_original = $original_file !== null && ($original_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $old = fetch_next_pr_photo_paths($pdo, $user_id, $event_id);
    $photo_path = next_pr_store_photo_file($file, $user_id, $event_id, '');
    try {
        $original_path = $has_original ? next_pr_store_photo_file($original_file, $user_id, $event_id, '_orig') : $old['original'];
    } catch (RuntimeException $e) {
        next_pr_remove_photo_file($photo_path); // 元写真が保存できなければ、置いた写真も片付ける
        throw $e;
    }
    $pdo->prepare(
        'INSERT INTO pr_event_photos (user_id, event_id, photo_path, original_path) VALUES (:user_id, :event_id, :photo_path, :original_path)
         ON DUPLICATE KEY UPDATE photo_path = VALUES(photo_path), original_path = VALUES(original_path)'
    )->execute([':user_id' => $user_id, ':event_id' => $event_id, ':photo_path' => $photo_path, ':original_path' => $original_path]);
    next_pr_remove_photo_file($old['photo']);
    if ($has_original) {
        next_pr_remove_photo_file($old['original']);
    }
}

// 写真を外す（元写真も消す。ホームのカードは見本の写真に戻る）
function delete_next_pr_photo(PDO $pdo, int $user_id, int $event_id): void
{
    $old = fetch_next_pr_photo_paths($pdo, $user_id, $event_id);
    $pdo->prepare('DELETE FROM pr_event_photos WHERE user_id = :user_id AND event_id = :event_id')
        ->execute([':user_id' => $user_id, ':event_id' => $event_id]);
    next_pr_remove_photo_file($old['photo']);
    next_pr_remove_photo_file($old['original']);
}

// 写真のファイルを消す（写真のフォルダの中のファイルだけ）
function next_pr_remove_photo_file(?string $path): void
{
    if ($path === null || !preg_match('#^' . preg_quote(NEXT_PR_PHOTO_DIR, '#') . '/[A-Za-z0-9_]+\.(jpg|png|webp)$#', $path)) {
        return;
    }
    $file = __DIR__ . '/../' . $path;
    if (is_file($file)) {
        @unlink($file);
    }
}

// 見本の写真の場所（サイトの一番上から）。差し替えたときにブラウザが古い写真を使い続けないよう、更新日時の印を付ける
function next_pr_default_photo_url(): string
{
    return NEXT_PR_PHOTO_DEFAULT . '?v=' . (int)@filemtime(__DIR__ . '/../' . NEXT_PR_PHOTO_DEFAULT);
}
