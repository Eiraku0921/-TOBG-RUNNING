<?php
require_once __DIR__ . '/../back/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/../back/db_connect.php';
require_once __DIR__ . '/../back/next_pr_lib.php';
require_once __DIR__ . '/../back/training_summary_lib.php';

// 未ログインの人には見本の画面を出す（入力・保存はできないので、送られてきたらログイン画面へ）
$is_demo = !isset($_SESSION['user_id'], $_SESSION['user_name']);
if ($is_demo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Location: login.php');
    exit;
}

$user_id = $is_demo ? 0 : (int)$_SESSION['user_id'];
$this_year = (int)date('Y');

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function next_pr_url(array $params, string $anchor = ''): string
{
    return 'next_pr.php?' . http_build_query($params) . ($anchor !== '' ? '#' . $anchor : '');
}

// 陸上の記録のプルダウン（種目の形式に合わせて 秒/分/時間/m などの欄が並ぶ）。
// $require_nonzero なら「すべて0」を保存させない（目標用）。そうでなければ「すべて0＝入れない」（基準用）
function render_time_parts(array $event, string $name, array $initial_parts, string $label_id, string $label, bool $require_nonzero): string
{
    $attrs = $require_nonzero
        ? ' data-goal-parts data-error="' . h($label . 'を選んでください（すべて0にはできません）。') . '"'
        : '';
    $html = '<div class="goal-parts" role="group" aria-labelledby="' . h($label_id) . '"' . $attrs . '>';
    foreach (next_pr_goal_parts_config($event) as $part_index => $part) {
        $html .= '<select class="form-select form-select-sm goal-part" name="' . h($name) . '[]" aria-label="' . h($label . ($part_index + 1)) . '">';
        for ($i = 0; $i <= $part['max']; $i++) {
            $text = $part['pad'] ? sprintf('%02d', $i) : (string)$i;
            $html .= '<option value="' . $i . '"' . ($i === (int)($initial_parts[$part_index] ?? 0) ? ' selected' : '') . '>' . $text . '</option>';
        }
        $html .= '</select>';
        if ($part['unit'] !== '') {
            $html .= '<span class="goal-part-unit">' . h($part['unit']) . '</span>';
        }
    }
    return $html . '</div>';
}

// 筋トレの重量プルダウン。「2.5kg刻み / 5kg刻み」を切り替えると、下のスクリプトが一覧を作り直す。
// $initial があればいちばん近い選択肢を選んでおき、無ければ「重量を選択」から始める
function render_weight_picker(string $name, string $id, ?float $initial, string $error_message, bool $required = true): string
{
    $step = NEXT_PR_WEIGHT_BASE_STEP;
    $selected = $initial !== null && $initial > 0 ? next_pr_nearest_weight_option($initial, $step) : null;

    $html = '<div class="weight-picker" data-weight-picker>';
    $html .= '<div class="weight-step" role="radiogroup" aria-label="重量の刻み">';
    foreach (NEXT_PR_WEIGHT_STEPS as $step_option) {
        $step_text = rtrim(rtrim(sprintf('%.1f', $step_option), '0'), '.');
        $radio_id = $id . 'Step' . str_replace('.', '_', $step_text);
        $html .= '<input type="radio" class="btn-check" name="' . h($id) . '_step" id="' . h($radio_id) . '" value="' . h(sprintf('%.1f', $step_option)) . '"' . ($step_option === $step ? ' checked' : '') . '>';
        $html .= '<label class="weight-step-btn" for="' . h($radio_id) . '">' . h($step_text) . 'kg刻み</label>';
    }
    $html .= '</div>';
    $html .= '<div class="input-with-unit">';
    $html .= '<select class="form-select" id="' . h($id) . '" name="' . h($name) . '"' . ($required ? ' required' : '') . ' data-error="' . h($error_message) . '">';
    $html .= '<option value="">' . ($required ? '重量を選択' : '入れない') . '</option>';
    foreach (next_pr_weight_options($step) as $weight) {
        $html .= '<option value="' . sprintf('%.1f', $weight) . '"' . ($selected !== null && abs($weight - $selected) < 0.001 ? ' selected' : '') . '>' . sprintf('%.1f', $weight) . '</option>';
    }
    $html .= '</select><span class="input-unit">kg</span></div>';
    $html .= '<div class="invalid-feedback d-block" data-feedback-for="' . h($id) . '"></div>';
    $html .= '</div>';
    return $html;
}

$events = [];
try {
    ensure_next_pr_tables($pdo);
    $events = fetch_pr_events($pdo);
} catch (PDOException $e) {
    error_log('next_pr setup failed: ' . $e->getMessage());
}

// ---- 保存・削除（処理後は GET に戻す） ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $event_id = (int)($_POST['event_id'] ?? 0);
    $event = $events[$event_id] ?? null;
    $anchor = '';

    try {
        if ($event === null) {
            throw new RuntimeException('種目の指定が不正です。');
        }

        if ($action === 'add_event') {
            add_next_pr_user_event($pdo, $user_id, $event_id);
            $_SESSION['next_pr_flash'] = $event['label'] . 'を追加しました。';
        } elseif ($action === 'remove_event') {
            // 記録がある種目は、記録があるからタブに出ているので外せない
            if (in_array($event_id, fetch_next_pr_event_ids_with_records($pdo, $user_id), true)) {
                throw new RuntimeException($event['label'] . 'は記録があるため削除できません。');
            }
            remove_next_pr_user_event($pdo, $user_id, $event_id);
            $_SESSION['next_pr_flash'] = $event['label'] . 'を削除しました。';
            header('Location: next_pr.php');
            exit;
        } elseif ($action === 'add_strength_record') {
            if ($event['category'] !== NEXT_PR_CATEGORY_STRENGTH) {
                throw new RuntimeException('陸上の記録は練習日記から自動で反映されます。');
            }
            $record_date = (string)($_POST['record_date'] ?? '');
            $date = DateTime::createFromFormat('!Y-m-d', $record_date);
            if ($date === false || $date->format('Y-m-d') !== $record_date || $record_date > date('Y-m-d')) {
                throw new RuntimeException('日付を正しく入力してください（今日より後の日付は選べません）。');
            }
            $weight = next_pr_parse_weight_choice((string)($_POST['weight'] ?? ''));
            if ($weight === null) {
                throw new RuntimeException('重量を選んでください（2.5kg刻み・' . NEXT_PR_WEIGHT_MAX_KG . 'kgまで）。');
            }
            add_next_pr_user_event($pdo, $user_id, $event_id);
            add_strength_record($pdo, $user_id, $event_id, $record_date, $weight);
            $_SESSION['next_pr_flash'] = '記録を追加しました。';
            $anchor = 'records';
        } elseif ($action === 'save_self_record') {
            // 陸上の自己申告の自己ベスト。練習日記の「大会」以外で出した記録を、鉛筆からいつでも入れ直せる（1種目1件、上書き）
            if ($event['category'] !== NEXT_PR_CATEGORY_TRACK) {
                throw new RuntimeException('筋トレは「記録を追加」から記録してください。');
            }
            $record_date = (string)($_POST['record_date'] ?? '');
            $date = DateTime::createFromFormat('!Y-m-d', $record_date);
            $oldest_date = date('Y-m-d', strtotime('-' . NEXT_PR_KEEP_YEARS . ' years'));
            if ($date === false || $date->format('Y-m-d') !== $record_date || $record_date > date('Y-m-d') || $record_date < $oldest_date) {
                throw new RuntimeException('日付は' . date('Y年n月j日', strtotime($oldest_date)) . '〜今日の間で選んでください。');
            }
            $value = next_pr_parts_to_value($event, (array)($_POST['self_parts'] ?? []));
            if ($value === null) {
                throw new RuntimeException('記録を選んでください（すべて0にはできません）。');
            }
            add_next_pr_user_event($pdo, $user_id, $event_id);
            save_next_pr_self_record($pdo, $user_id, $event_id, $record_date, $value);
            $_SESSION['next_pr_flash'] = '自己ベストを保存しました。';
        } elseif ($action === 'delete_strength_record') {
            delete_strength_record($pdo, $user_id, (int)($_POST['record_id'] ?? 0));
            $_SESSION['next_pr_flash'] = '記録を削除しました。';
            $anchor = 'records';
        } elseif ($action === 'save_goal' || $action === 'delete_goal') {
            $goal_type = (string)($_POST['goal_type'] ?? '');
            if (!in_array($goal_type, [NEXT_PR_GOAL_SEASON, NEXT_PR_GOAL_LONG], true)) {
                throw new RuntimeException('目標の指定が不正です。');
            }
            $anchor = 'goals';
            if ($action === 'delete_goal') {
                delete_next_pr_goal($pdo, $user_id, $event_id, $goal_type);
                $_SESSION['next_pr_flash'] = '目標を削除しました。';
            } else {
                // 筋トレは重量プルダウン（2.5kg / 5kg 刻み）、陸上は種目ごとの欄のプルダウン
                $goal = $event['display_format'] === 'weight'
                    ? next_pr_parse_weight_choice((string)($_POST['goal_weight'] ?? ''))
                    : next_pr_parts_to_value($event, (array)($_POST['goal_parts'] ?? []));
                if ($goal === null) {
                    throw new RuntimeException(next_pr_input_hint($event)['label'] . 'を選んでください（すべて0にはできません）。');
                }
                $target_year = $this_year;
                if ($goal_type === NEXT_PR_GOAL_LONG) {
                    $target_year = (int)($_POST['target_year'] ?? 0);
                    if ($target_year < $this_year || $target_year > $this_year + NEXT_PR_LONG_GOAL_MAX_YEARS) {
                        throw new RuntimeException('目標年は' . $this_year . '年〜' . ($this_year + NEXT_PR_LONG_GOAL_MAX_YEARS) . '年から選んでください。');
                    }
                }
                // 長期目標は基準を本人が選ぶ（自己ベスト / 直近2年のベスト / 自分で入力）。シーズン目標は今年のシーズンベスト
                $baseline_type = NEXT_PR_BASELINE_PB;
                $baseline_from = null;
                if ($goal_type === NEXT_PR_GOAL_LONG) {
                    $baseline_type = (string)($_POST['baseline_type'] ?? NEXT_PR_BASELINE_PB);
                    if (!array_key_exists($baseline_type, NEXT_PR_BASELINE_LABELS)) {
                        throw new RuntimeException('基準にする記録を選んでください。');
                    }
                    // 「自分で入力」は、目標を決めた今日からの記録で測る
                    $baseline_from = $baseline_type === NEXT_PR_BASELINE_MANUAL ? date('Y-m-d') : null;
                }
                // 基準（start_value）は、選んだ基準の今の記録を自動で記録する。
                // 「自分で入力」のときと、基準にする記録がまだ無いときは、利用者が入れた記録を使う（記録が無いときは入れなくてもよい）
                $current_best = $baseline_type === NEXT_PR_BASELINE_MANUAL
                    ? null
                    : next_pr_goal_reference_best($event, fetch_next_pr_results($pdo, $user_id, $event), $goal_type, $this_year, $baseline_type);
                $start = $current_best['value'] ?? null;
                if ($start === null) {
                    if ($event['display_format'] === 'weight') {
                        $start_raw = trim((string)($_POST['start_weight'] ?? ''));
                        $start = $start_raw === '' ? null : next_pr_parse_weight_choice($start_raw);
                        if ($start_raw !== '' && $start === null) {
                            throw new RuntimeException('目標を決めた時点の重量を選び直してください。');
                        }
                    } else {
                        // すべて0は「入れない」。0以外があれば記録として読む
                        $start = next_pr_parts_to_value($event, (array)($_POST['start_parts'] ?? []));
                    }
                    if ($start === null && $baseline_type === NEXT_PR_BASELINE_MANUAL) {
                        throw new RuntimeException('基準にする記録を入力してください。');
                    }
                    if ($start !== null && !next_pr_is_better($event, $goal, $start)) {
                        throw new RuntimeException(
                            next_pr_higher_is_better($event)
                                ? '目標を決めた時点の記録は、目標より低い値にしてください。'
                                : '目標を決めた時点のタイムは、目標より遅いタイムにしてください。'
                        );
                    }
                }
                save_next_pr_goal($pdo, $user_id, $event_id, $goal_type, $target_year, $goal, $start, $baseline_type, $baseline_from);
                $_SESSION['next_pr_flash'] = '目標を保存しました。';
            }
        } elseif ($action === 'save_photo') {
            // 種目ごとの写真（ホームの NEXT PR のカードの背景）。photo は枠に合わせて切り抜いた写真、
            // original は切り抜く前の元写真（「位置を調整」でやり直したときは送られず、今の元写真を使う）。js/np_photo_crop.js から送る
            save_next_pr_photo($pdo, $user_id, $event_id, $_FILES['photo'] ?? [], $_FILES['original'] ?? null);
            $_SESSION['next_pr_flash'] = $event['label'] . 'の背景を設定しました。';
        } elseif ($action === 'delete_photo') {
            delete_next_pr_photo($pdo, $user_id, $event_id);
            $_SESSION['next_pr_flash'] = $event['label'] . 'の写真を外しました。';
        } else {
            throw new RuntimeException('操作の指定が不正です。');
        }
    } catch (RuntimeException $e) {
        $_SESSION['next_pr_error'] = $e->getMessage();
    } catch (PDOException $e) {
        error_log('next_pr save failed: ' . $e->getMessage());
        $_SESSION['next_pr_error'] = '保存に失敗しました。時間を置いて再度お試しください。';
    }

    header('Location: ' . next_pr_url($event !== null ? ['event' => $event_id] : [], $anchor));
    exit;
}

$flash = (string)($_SESSION['next_pr_flash'] ?? '');
$error = (string)($_SESSION['next_pr_error'] ?? '');
unset($_SESSION['next_pr_flash'], $_SESSION['next_pr_error']);

// ---- 表示データ ----
$tab_event_ids = [];
$event_ids_with_records = [];
$load_error = '';
try {
    $tab_event_ids = empty($events) ? [] : fetch_next_pr_tab_event_ids($pdo, $user_id);
    $event_ids_with_records = empty($events) ? [] : fetch_next_pr_event_ids_with_records($pdo, $user_id);
} catch (PDOException $e) {
    $load_error = '記録の読み込みに失敗しました。時間を置いて再度お試しください。';
}

$event_id = (int)($_GET['event'] ?? 0);
if (!in_array($event_id, $tab_event_ids, true)) {
    $event_id = $tab_event_ids[0] ?? 0;
}
$event = $events[$event_id] ?? null;
$is_strength = $event !== null && $event['category'] === NEXT_PR_CATEGORY_STRENGTH;
// この種目の写真（ホームのカードの背景）と、「位置を調整」に使う元写真。無ければ見本の写真
$event_photo = null;
$event_photo_original = null;
if ($event !== null) {
    try {
        $event_photo_paths = fetch_next_pr_photo_paths($pdo, $user_id, $event_id);
        $event_photo = $event_photo_paths['photo'];
        $event_photo_original = $event_photo_paths['original'];
    } catch (PDOException $e) {
        error_log('next_pr photo failed: ' . $e->getMessage());
    }
}

// 練習のまとめ（ホームと同じカード）。読めなければその欄を出さない
$week_summary = null;
try {
    $week_summary = fetch_training_week_summary($pdo, $user_id);
} catch (PDOException $e) {
    error_log('next_pr week summary failed: ' . $e->getMessage());
}

$results = [];
$goals = [NEXT_PR_GOAL_SEASON => null, NEXT_PR_GOAL_LONG => null];
$self_record = null; // 陸上の自己申告の自己ベスト（鉛筆の入力画面に最初から入れておく）
if ($event !== null) {
    try {
        $results = next_pr_mark_bests($event, fetch_next_pr_results($pdo, $user_id, $event));
        $goals = fetch_next_pr_goals($pdo, $user_id, $event_id);
        if ($event['category'] === NEXT_PR_CATEGORY_TRACK) {
            $self_record = fetch_next_pr_self_record($pdo, $user_id, $event_id);
        }
    } catch (PDOException $e) {
        $load_error = '記録の読み込みに失敗しました。時間を置いて再度お試しください。';
    }
}

// 自己ベスト / シーズンベスト。切り替えたときはそれを覚え、指定が無いときは最後に選んだ方（ホームと共通）
$view = 'pb';
try {
    $view = next_pr_resolve_best_view($pdo, $user_id, isset($_GET['view']) ? (string)$_GET['view'] : null);
} catch (PDOException $e) {
    $view = (string)($_GET['view'] ?? 'pb') === 'sb' ? 'sb' : 'pb';
}
$result_years = array_values(array_unique(array_merge([$this_year], array_column($results, 'year'))));
rsort($result_years);
$season = (int)($_GET['season'] ?? $this_year);
if (!in_array($season, $result_years, true)) {
    $season = $this_year;
}
$personal_best = $event !== null ? next_pr_best($event, $results) : null;
// 今年のシーズンベストを見ていて、今年まだ記録が無い（初戦の前）ときは、去年のシーズンベストを出す
$shown_best = null;
if ($event !== null) {
    if ($view !== 'sb') {
        $shown_best = $personal_best;
    } elseif ($season === $this_year) {
        $shown_best = next_pr_current_season_best($event, $results, $season);
    } else {
        $shown_best = next_pr_best($event, $results, $season);
    }
}

// 記録一覧: 直近1年（既定）か、年ごと
$period = (string)($_GET['period'] ?? 'recent');
if ($period !== 'recent' && !in_array((int)$period, $result_years, true)) {
    $period = 'recent';
}
$one_year_ago = date('Y-m-d', strtotime('-1 year'));
$listed_results = array_reverse(array_values(array_filter($results, function (array $r) use ($period, $one_year_ago): bool {
    return $period === 'recent' ? $r['date'] >= $one_year_ago : $r['year'] === (int)$period;
})));

$base_params = ['event' => $event_id, 'view' => $view, 'season' => $season, 'period' => $period];

$input_hint = $event !== null ? next_pr_input_hint($event) : null;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NEXT PR | TOBG</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;700;800;900&family=M+PLUS+Rounded+1c:wght@400;500;700;800&family=Outfit:wght@500;600;700&family=Zen+Kaku+Gothic+New:wght@400;500;700;900&display=swap" rel="stylesheet">
    <link href="../css/theme.css?v=<?php echo (int)@filemtime(__DIR__ . '/../css/theme.css'); ?>" rel="stylesheet">
    <link href="../css/next_pr_cards.css?v=<?php echo (int)@filemtime(__DIR__ . '/../css/next_pr_cards.css'); ?>" rel="stylesheet">
    <link href="../css/week_summary.css?v=<?php echo (int)@filemtime(__DIR__ . '/../css/week_summary.css'); ?>" rel="stylesheet">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#F4EEE4">
    <meta name="apple-mobile-web-app-title" content="TOBG-RUNNING">
    <link rel="apple-touch-icon" href="../icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" href="../icons/favicon.png">
    <style>
        .pr-wrap { max-width: var(--content-max-width); margin: 0 auto; padding: 10px var(--page-gutter) 32px; }
        .pr-title {
            margin: 6px 0 10px;
            /* 「N」をヘッダーのロゴ「T」の真下にそろえる（値はヘッダー側で計算している） */
            padding-left: var(--site-logo-text-inset, 0px);
            font-size: 1.4rem;
            font-weight: 900;
            letter-spacing: 0.04em;
        }

        /* 種目がまだ無いときの案内と「例」の画面（押せない。「例」は点線とバッジだけで示し、薄くしない） */
        .onboarding-title {
            margin: 0 0 8px;
            font-size: 1.05rem;
            font-weight: 800;
        }
        .onboarding-note {
            margin: 8px 0 0;
            color: var(--color-text-muted);
            font-size: 0.78rem;
        }
        .pr-sample {
            position: relative;
            padding: 22px 12px 2px;
            border: 2px dashed var(--color-border);
            border-radius: var(--card-radius);
            pointer-events: none;
            user-select: none;
        }
        .pr-sample-badge {
            position: absolute;
            top: -11px;
            left: 12px;
            padding: 1px 10px;
            border-radius: 999px;
            background: var(--color-text-secondary);
            color: var(--color-card-bg);
            font-size: 0.75rem;
            font-weight: 800;
        }

        /* 記録一覧 */
        .section-title {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin: 0;
            font-size: 1.05rem;
            font-weight: 800;
        }
        .section-title::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--color-accent);
        }
        .period-radios {
            display: flex;
            flex-wrap: wrap;
            gap: 4px 14px;
            margin: 12px 0 4px;
            font-size: 0.85rem;
        }
        .result-list {
            list-style: none;
            margin: 8px 0 0;
            padding: 0;
        }
        .result-item {
            display: grid;
            grid-template-columns: 92px minmax(0, 1fr) auto;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-top: 1px solid var(--color-border);
        }
        .result-date {
            color: var(--color-text-muted);
            font-size: 0.8rem;
            font-weight: 600;
        }
        .result-title {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 0.9rem;
        }
        .result-notes {
            display: block;
            color: var(--color-text-secondary);
            font-size: 0.72rem;
        }
        .result-value {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
            font-weight: 800;
            white-space: nowrap;
        }
        .result-badge {
            padding: 1px 8px;
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 700;
        }
        .result-badge-pb {
            background: var(--color-badge-pb-bg);
            color: var(--color-badge-pb-text);
        }
        .result-badge-sb {
            background: var(--color-badge-sb-bg);
            color: var(--color-badge-sb-text);
        }
        .result-delete {
            border: 0;
            background: transparent;
            color: var(--color-text-muted);
            font-size: 0.9rem;
            line-height: 1;
            padding: 2px 4px;
        }
        .result-delete:hover {
            color: #b91c1c;
        }

        /* モーダル共通 */
        .pr-note {
            color: var(--color-text-secondary);
            font-size: 0.85rem;
            line-height: 1.7;
        }
        .input-with-unit {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .input-with-unit .form-control {
            max-width: 180px;
        }
        .input-unit {
            color: var(--color-text-secondary);
            font-weight: 700;
        }

        /* 種目の削除 */
        .remove-event-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .remove-event-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 0;
            border-top: 1px solid var(--color-border);
        }
        .remove-event-name {
            font-weight: 700;
        }
        .remove-event-locked {
            color: var(--color-text-muted);
            font-size: 0.78rem;
        }
        .remove-event-btn {
            padding: 3px 12px;
            border: 1px solid rgba(185, 28, 28, 0.35);
            border-radius: 999px;
            background: transparent;
            color: #b91c1c;
            font-size: 0.8rem;
            font-weight: 700;
        }
        .remove-event-btn:hover {
            background: #fef2f2;
        }

        /* 長期目標の基準の選択 */
        .goal-baseline .form-check {
            margin-bottom: 4px;
        }
        .goal-baseline-value {
            color: var(--color-text-secondary);
            font-size: 0.85rem;
        }

        /* 記録がまだ無いときの「目標を決めた時点の記録」 */
        .goal-start-input {
            padding-top: 12px;
            border-top: 1px solid var(--color-border);
        }
        .goal-start-note {
            margin-top: 6px;
            color: var(--color-text-muted);
            font-size: 0.75rem;
            line-height: 1.6;
        }

        /* 筋トレの重量プルダウン（2.5kg / 5kg 刻みの切り替えつき） */
        .weight-step {
            display: inline-flex;
            padding: 3px;
            border-radius: 999px;
            background: var(--color-tab-bg);
            margin-bottom: 8px;
        }
        .weight-step-btn {
            padding: 4px 12px;
            border-radius: 999px;
            color: var(--color-text-secondary);
            font-size: 0.8rem;
            font-weight: 700;
            cursor: pointer;
        }
        .btn-check:checked + .weight-step-btn {
            background: var(--color-card-bg);
            color: var(--color-text-primary);
            box-shadow: 0 1px 3px rgba(43, 39, 35, 0.12);
        }
        .btn-check:focus-visible + .weight-step-btn {
            outline: 2px solid var(--color-accent);
        }
        .weight-picker .form-select {
            max-width: 160px;
        }

        /* 目標のプルダウン（種目ごとに欄の数・単位が変わる） */
        .goal-parts {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 4px;
        }
        .goal-part {
            width: auto;
            min-width: 62px;
        }
        .goal-part-unit {
            color: var(--color-text-secondary);
            font-size: 0.85rem;
            font-weight: 700;
            margin-right: 2px;
        }

        /* 目標年のステッパー（− 2028年 +） */
        .year-stepper-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .year-stepper {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .year-stepper-btn {
            width: 32px;
            height: 32px;
            border: 1px solid var(--color-border);
            border-radius: var(--button-radius);
            background: var(--color-card-bg);
            color: var(--color-text-primary);
            font-weight: 800;
            line-height: 1;
        }
        .year-stepper-btn:disabled {
            opacity: 0.35;
        }
        .year-stepper-value {
            min-width: 64px;
            text-align: center;
            font-weight: 800;
        }

        /* 自己ベストのカード: この種目の写真を背景に、文字とボタンは白（ホームの NEXT PR のカードと同じ写真） */
        .np-photo-card {
            display: flex;
            flex-direction: column;
            min-height: 232px;
            overflow: hidden;
            /* ホームの NEXT PR のカードと同じ: 上は写真の色のまま、下のほうにだけ薄い影 */
            background:
                linear-gradient(180deg, rgba(31, 42, 68, 0) 45%, rgba(31, 42, 68, .45) 100%),
                var(--np-photo) center / cover no-repeat,
                var(--navy);
            color: #FFFFFF;
        }
        /* 見本の写真（集合写真）は、顔が記録と目標の枠の間に来るよう少し下寄りを見せる */
        .np-photo-card.is-sample {
            background-position: 0 0, center 60%, 0 0;
        }
        .np-photo-card .best-value,
        .np-photo-card .best-value-unit,
        .np-photo-card .best-meta,
        .np-photo-card .best-fallback-note,
        .np-photo-card .pr-empty {
            color: #FFFFFF;
            text-shadow: 0 1px 3px rgba(0, 0, 0, .55), 0 2px 12px rgba(0, 0, 0, .4);
        }
        .np-photo-card .best-meta,
        .np-photo-card .pr-empty {
            font-weight: 700;
        }
        /* 自己ベスト / シーズンベスト: 選んでいない方は地を透明に、選んでいる方は白地に紺の文字 */
        .np-photo-card .best-tab {
            border-color: rgba(255, 255, 255, .8);
            background: transparent;
            color: #FFFFFF;
            text-shadow: 0 1px 4px rgba(0, 0, 0, .55);
        }
        .np-photo-card .best-tab:hover {
            color: #FFFFFF;
        }
        .np-photo-card .best-tab.is-active {
            border-color: #FFFFFF;
            background: #FFFFFF;
            color: var(--navy);
            text-shadow: none;
        }
        /* 写真の上の鉛筆（自己ベスト・目標）とカメラ: 白。写真の明るいところでも見えるよう薄い影を付ける */
        .np-photo-card .goal-edit-btn {
            color: #FFFFFF;
            filter: drop-shadow(0 1px 3px rgba(0, 0, 0, .6));
        }
        .np-photo-card .goal-edit-btn:hover {
            background: rgba(255, 255, 255, .18);
            color: #FFFFFF;
        }
        .np-photo-card .goal-grid {
            margin-top: auto;
            margin-bottom: 0;
            padding-top: 14px;
        }
        .np-photo-card .goal-grid .pr-card {
            margin-bottom: 0;
            padding: 12px 14px;
            border: 1px solid rgba(255, 255, 255, .7);
            border-radius: 14px;
            background: transparent;
            color: #FFFFFF;
            text-shadow: 0 1px 3px rgba(0, 0, 0, .6), 0 2px 10px rgba(0, 0, 0, .4);
        }
        .np-photo-card .goal-label,
        .np-photo-card .goal-empty {
            color: #FFFFFF;
            font-weight: 700;
        }
        /* 見出しは1行（鉛筆の分を空けて、入りきらなければ…で省く） */
        .np-photo-card .goal-label {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .np-photo-card .goal-value {
            margin: 4px 0 8px;
            color: var(--chart);
        }
        .np-photo-card .goal-progress {
            height: 8px;
            background: rgba(255, 255, 255, .35);
            box-shadow: 0 1px 4px rgba(0, 0, 0, .3);
        }
        @media (min-width: 992px) {
            .np-photo-card {
                min-height: 270px;
            }
        }
        /* 背景写真のメニュー（写真を選ぶ・位置を調整）と、読み込めなかったときの文 */
        .pr-photo-menu {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 14px;
        }
        .pr-photo-menu .btn {
            flex: 1 1 140px;
        }
        .pr-photo-error {
            margin: 10px 0 0;
            color: var(--coral);
            font-size: 0.85rem;
            font-weight: 700;
        }
        /* 位置を調整の枠（縦横比は JS が自己ベストのカードに合わせる）。ドラッグで動かすので、指のスクロールはさせない */
        .pr-crop-frame {
            position: relative;
            width: 100%;
            aspect-ratio: 16 / 9;
            overflow: hidden;
            border-radius: 14px;
            background: #1B1F2A;
            cursor: grab;
            touch-action: none;
            user-select: none;
        }
        .pr-crop-frame.is-dragging {
            cursor: grabbing;
        }
        /* 隙間を埋める、ぼかして薄暗くした同じ写真（枠いっぱい） */
        .pr-crop-backdrop {
            position: absolute;
            inset: -10%;
            width: 120%;
            height: 120%;
            object-fit: cover;
            filter: blur(16px) brightness(.75);
            pointer-events: none;
        }
        .pr-crop-img {
            position: absolute;
            top: 0;
            left: 0;
            max-width: none;
            transform-origin: 0 0;
            pointer-events: none;
            user-select: none;
            -webkit-user-drag: none;
        }
        /* 三分割のガイド線（薄い白）と、枠の内側の線 */
        .pr-crop-grid {
            position: absolute;
            inset: 0;
            pointer-events: none;
            background:
                linear-gradient(to right, transparent calc(33.333% - .5px), rgba(255, 255, 255, .45) calc(33.333% - .5px), rgba(255, 255, 255, .45) calc(33.333% + .5px), transparent calc(33.333% + .5px), transparent calc(66.667% - .5px), rgba(255, 255, 255, .45) calc(66.667% - .5px), rgba(255, 255, 255, .45) calc(66.667% + .5px), transparent calc(66.667% + .5px)),
                linear-gradient(to bottom, transparent calc(33.333% - .5px), rgba(255, 255, 255, .45) calc(33.333% - .5px), rgba(255, 255, 255, .45) calc(33.333% + .5px), transparent calc(33.333% + .5px), transparent calc(66.667% - .5px), rgba(255, 255, 255, .45) calc(66.667% - .5px), rgba(255, 255, 255, .45) calc(66.667% + .5px), transparent calc(66.667% + .5px));
            box-shadow: inset 0 0 0 2px rgba(255, 255, 255, .85);
            border-radius: inherit;
        }
        .pr-crop-zoom {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 14px;
            font-size: 0.85rem;
            font-weight: 700;
        }
        .pr-crop-zoom span {
            flex-shrink: 0;
        }
        /* 見本の画面（未ログイン）: 上と下の案内のカード */
        .np-demo-intro {
            margin-bottom: 14px;
            padding: 18px 20px;
            border-radius: var(--card-radius);
            background: var(--surface);
            box-shadow: var(--card-shadow);
        }
        .np-demo-intro.is-bottom {
            margin-top: 6px;
            text-align: center;
        }
        .np-demo-badge {
            display: inline-block;
            margin: 0 0 8px;
            padding: 2px 12px;
            border-radius: 999px;
            background: var(--orange-soft);
            color: var(--orange-deep);
            font-size: 12px;
            font-weight: 800;
        }
        .np-demo-title {
            margin: 0 0 6px;
            color: var(--navy);
            font-size: 1.2rem;
            font-weight: 900;
        }
        .np-demo-text,
        .np-demo-note {
            margin: 0;
            color: var(--sub);
            font-size: 0.88rem;
            line-height: 1.7;
        }
        .np-demo-note {
            margin-bottom: 6px;
            font-size: 0.8rem;
        }
        .np-demo-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 12px;
        }
        .np-demo-intro.is-bottom .np-demo-actions {
            justify-content: center;
        }
        .np-demo-actions .btn {
            flex: 0 1 auto;
            min-width: 150px;
            border-radius: 999px;
            font-weight: 700;
        }
        /* 写真の窓: いまの写真（または選んだ写真）を横長で見せる */
        .pr-photo-preview {
            display: block;
            width: 100%;
            aspect-ratio: 16 / 9;
            border-radius: 14px;
            object-fit: cover;
            background: var(--beige2);
        }

        @media (max-width: 576px) {
            .result-item {
                grid-template-columns: 76px minmax(0, 1fr) auto;
                gap: 8px;
            }

        }
    </style>
</head>
<body class="theme-v2 next-pr-page">
<?php
$shared_header = __DIR__ . '/../includes/site_header.php';
if (file_exists($shared_header)) {
    require_once $shared_header;
}
?>

<main class="pr-wrap">
    <h1 class="pr-title">NEXT PERSONAL RECORD</h1>

    <?php if ($is_demo): ?>
        <?php require __DIR__ . '/../includes/next_pr_demo.php'; ?>
    <?php else: ?>
    <!-- 種目タブ -->
    <nav class="event-tabs" aria-label="種目">
        <?php foreach ($tab_event_ids as $tab_event_id): ?>
            <a class="event-tab<?php echo $tab_event_id === $event_id ? ' is-active' : ''; ?>" href="<?php echo h(next_pr_url(['event' => $tab_event_id])); ?>" <?php echo $tab_event_id === $event_id ? 'aria-current="page"' : ''; ?>><?php echo h($events[$tab_event_id]['label']); ?></a>
        <?php endforeach; ?>
        <button type="button" class="event-add-btn" data-bs-toggle="modal" data-bs-target="#addEventModal">＋ 種目を追加</button>
        <?php if (!empty($tab_event_ids)): ?>
            <button type="button" class="event-add-btn" data-bs-toggle="modal" data-bs-target="#removeEventModal">－ 種目を削除</button>
        <?php endif; ?>
    </nav>

    <?php foreach (array_filter([$load_error, $error]) as $message): ?>
        <div class="alert alert-danger py-2" role="alert"><?php echo h($message); ?></div>
    <?php endforeach; ?>
    <?php if ($flash !== ''): ?>
        <div class="alert alert-success py-2" role="alert"><?php echo h($flash); ?></div>
    <?php endif; ?>

    <?php if ($event === null): ?>
        <!-- 種目がまだ無い人向けの案内と、画面の例（押せない） -->
        <section class="pr-card" aria-labelledby="onboardingTitle">
            <h2 class="onboarding-title" id="onboardingTitle">まだ種目がありません</h2>
            <p class="pr-empty mt-0">
                陸上競技や筋トレの自己ベストを記録して、目標までの進捗を確認できる機能です。<br>
                「＋ 種目を追加」から、最初の種目を選んでください。
            </p>
            <p class="onboarding-note">陸上の記録は、練習日記の練習強度で「大会」を選んで記録すると自動で反映されます。</p>
        </section>

        <div class="pr-sample" aria-hidden="true">
            <span class="pr-sample-badge">例</span>
            <div class="pr-card">
                <div class="best-tabs">
                    <span class="best-tab is-active">自己ベスト</span>
                    <span class="best-tab">シーズンベスト</span>
                </div>
                <div class="best-value">11.82<span class="best-value-unit">秒</span></div>
                <div class="best-meta"><?php echo $this_year; ?>年6月8日 県選手権（100m）</div>
            </div>
            <div class="goal-grid">
                <div class="pr-card">
                    <div class="goal-label">シーズン目標（<?php echo $this_year; ?>年）</div>
                    <div class="goal-value">11.60秒</div>
                    <div class="goal-progress"><div class="goal-progress-fill" style="width: 56%;"></div></div>
                </div>
                <div class="pr-card">
                    <div class="goal-label">長期目標（<?php echo $this_year + 2; ?>年まで）</div>
                    <div class="goal-value">11.30秒</div>
                    <div class="goal-progress"><div class="goal-progress-fill" style="width: 35%;"></div></div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- 自己ベスト / シーズンベスト -->
        <?php // 背景はこの種目の写真（ホームの NEXT PR のカードと同じ。入れていなければ見本の写真）。写真はカメラのボタンから ?>
        <section class="pr-card np-photo-card<?php echo $event_photo === null ? ' is-sample' : ''; ?>" aria-label="ベスト記録" style="--np-photo: url('../<?php echo h($event_photo ?? next_pr_default_photo_url()); ?>')">
            <div class="best-head">
                <div class="best-tabs">
                    <a class="best-tab<?php echo $view === 'pb' ? ' is-active' : ''; ?>" href="<?php echo h(next_pr_url(['view' => 'pb'] + $base_params)); ?>">自己ベスト</a>
                    <a class="best-tab<?php echo $view === 'sb' ? ' is-active' : ''; ?>" href="<?php echo h(next_pr_url(['view' => 'sb'] + $base_params)); ?>">シーズンベスト</a>
                    <?php if ($view === 'sb'): ?>
                        <form method="GET" action="next_pr.php">
                            <?php foreach (['event' => $event_id, 'view' => 'sb', 'period' => $period] as $name => $value): ?>
                                <input type="hidden" name="<?php echo h($name); ?>" value="<?php echo h((string)$value); ?>">
                            <?php endforeach; ?>
                            <select class="form-select form-select-sm season-select" name="season" aria-label="シーズン" onchange="this.form.submit()">
                                <?php foreach ($result_years as $year): ?>
                                    <option value="<?php echo $year; ?>" <?php echo $year === $season ? 'selected' : ''; ?>><?php echo $year; ?>年</option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="best-actions">
                    <?php // 写真: この種目のホームのカードの背景を入れる・変える ?>
                    <button type="button" class="goal-edit-btn best-edit-btn best-photo-btn" data-bs-toggle="modal" data-bs-target="#prPhotoModal" aria-label="ホームのカードの写真を変更">
                        <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    </button>
                    <?php // 陸上は鉛筆から自己申告の自己ベストをいつでも入れ直せる。筋トレは「＋ 記録を追加」から ?>
                    <?php if (!$is_strength): ?>
                        <button type="button" class="goal-edit-btn best-edit-btn" data-bs-toggle="modal" data-bs-target="#selfRecordModal" aria-label="自己ベストを入力">
                            <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                        </button>
                    <?php endif; ?>
                    <?php if ($is_strength): ?>
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#strengthRecordModal">＋ 記録を追加</button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($shown_best !== null): ?>
                <?php $best_text = next_pr_format_value($event, $shown_best['value']); ?>
                <div class="best-value"><?php echo h($best_text['text']); ?><?php if ($best_text['unit'] !== ''): ?><span class="best-value-unit"><?php echo h($best_text['unit']); ?></span><?php endif; ?></div>
                <div class="best-meta">
                    <?php echo h(date('Y年n月j日', strtotime($shown_best['date']))); ?>
                    <?php if ($is_strength): ?>
                        トレーニング
                    <?php else: ?>
                        <?php echo h($shown_best['title']); ?><?php if ($shown_best['notes'] !== ''): ?>（<?php echo h($shown_best['notes']); ?>）<?php endif; ?>
                    <?php endif; ?>
                    <?php if ($view === 'sb' && $shown_best['year'] !== $season): ?>
                        <span class="best-fallback-note"><?php echo (int)$shown_best['year']; ?>年のシーズンベスト（<?php echo (int)$season; ?>年はまだ記録がありません）</span>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p class="pr-empty">
                    <?php echo $view === 'sb' ? h($season . '年のベスト記録はまだありません。') : 'ベスト記録はまだありません。'; ?><br>
                    <?php if ($is_strength): ?>
                        練習日記の練習タイプで「ウエイト」を選んで、この種目の重量を記録すると、ここに反映されます。<br>
                        回数は問わず、挙げた重量が記録になります（「＋ 記録を追加」から手入力もできます）。
                    <?php else: ?>
                        練習日記の練習強度で「大会」を選んで記録すると、ここに反映されます。<br>
                        公認で、追い風2.0以下の記録がベストになります。
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <!-- 目標（写真の上の透けた枠2つ。ホームの NEXT PR のカードと同じ見せ方） -->
            <div class="goal-grid" id="goals">
                <?php
                $goal_cards = [
                    NEXT_PR_GOAL_SEASON => 'シーズン目標 ' . $this_year . '年',
                    NEXT_PR_GOAL_LONG => '長期目標',
                ];
                ?>
                <?php foreach ($goal_cards as $goal_type => $goal_title): ?>
                    <?php $goal = $goals[$goal_type]; ?>
                    <section class="pr-card" aria-label="<?php echo h($goal_title); ?>">
                        <div class="goal-label">
                            <?php echo h($goal_title); ?><?php if ($goal_type === NEXT_PR_GOAL_LONG && $goal !== null): ?> 〜<?php echo (int)$goal['target_year']; ?>年<?php endif; ?>
                        </div>
                        <button type="button" class="goal-edit-btn" data-bs-toggle="modal" data-bs-target="#goalModal-<?php echo h($goal_type); ?>" aria-label="<?php echo h($goal_title); ?>を編集">
                            <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                        </button>
                        <?php if ($goal !== null): ?>
                            <?php $goal_reference = next_pr_goal_reference_best($event, $results, $goal_type, $this_year, $goal['baseline_type'], $goal['baseline_from']); ?>
                            <?php $progress = next_pr_goal_progress($event, $goal['start'], $goal_reference['value'] ?? null, $goal['goal']); ?>
                            <div class="goal-value"><?php echo h(next_pr_format_text($event, $goal['goal'])); ?></div>
                            <div class="goal-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo (int)($progress ?? 0); ?>" aria-label="目標の達成度">
                                <div class="goal-progress-fill" style="width: <?php echo (int)($progress ?? 0); ?>%;"></div>
                            </div>
                            <?php // 目標の数字と達成度のバーだけ（説明文は置かない。仕様書） ?>
                        <?php else: ?>
                            <p class="goal-empty">未設定</p>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- 練習のまとめ（ホームと同じカード。includes/week_summary_card.php） -->
        <?php if ($week_summary !== null): ?>
            <p class="week-card-heading">練習のまとめ</p>
            <?php
            $week_summary_base = '../';
            $week_summary_event_key = $event['event_key'] ?? null;
            require __DIR__ . '/../includes/week_summary_card.php';
            ?>
        <?php endif; ?>

        <!-- 記録一覧 -->
        <section class="pr-card" id="records" aria-labelledby="resultListTitle">
            <h2 class="section-title" id="resultListTitle">記録一覧</h2>
            <form method="GET" action="next_pr.php" class="period-radios" aria-label="表示する期間">
                <?php foreach (['event' => $event_id, 'view' => $view, 'season' => $season] as $name => $value): ?>
                    <input type="hidden" name="<?php echo h($name); ?>" value="<?php echo h((string)$value); ?>">
                <?php endforeach; ?>
                <?php // 年をキーにするので + でつなぐ（array_merge は数字のキーを振り直してしまう） ?>
                <?php $period_options = ['recent' => '直近1年'] + array_combine($result_years, array_map(fn($y) => $y . '年', $result_years)); ?>
                <?php foreach ($period_options as $period_value => $period_label): ?>
                    <div class="form-check form-check-inline m-0">
                        <input class="form-check-input" type="radio" name="period" id="period<?php echo h((string)$period_value); ?>" value="<?php echo h((string)$period_value); ?>" <?php echo (string)$period_value === (string)$period ? 'checked' : ''; ?> onchange="this.form.submit()">
                        <label class="form-check-label" for="period<?php echo h((string)$period_value); ?>"><?php echo h($period_label); ?></label>
                    </div>
                <?php endforeach; ?>
            </form>

            <?php if (empty($listed_results)): ?>
                <p class="pr-empty">この期間の記録はありません。</p>
            <?php else: ?>
                <ul class="result-list">
                    <?php foreach ($listed_results as $result): ?>
                        <li class="result-item">
                            <span class="result-date"><?php echo h(date('Y/n/j', strtotime($result['date']))); ?></span>
                            <span class="result-title">
                                <?php echo h($result['title']); ?>
                                <?php if ($result['notes'] !== ''): ?><span class="result-notes"><?php echo h($result['notes']); ?></span><?php endif; ?>
                            </span>
                            <span class="result-value">
                                <?php echo h(next_pr_format_text($event, $result['value'])); ?>
                                <?php if ($result['is_pb']): ?>
                                    <span class="result-badge result-badge-pb">自己ベスト</span>
                                <?php elseif ($result['is_sb']): ?>
                                    <span class="result-badge result-badge-sb">シーズンベスト</span>
                                <?php endif; ?>
                                <?php // 筋トレの記録だけ消せる（陸上の自己申告は、鉛筆から入れ直して直す） ?>
                                <?php if ($result['manual'] && ($result['manual_kind'] ?? '') !== 'self'): ?>
                                    <form method="POST" action="next_pr.php" class="m-0" onsubmit="return confirm('この記録を削除しますか？');">
                                        <input type="hidden" name="action" value="delete_strength_record">
                                        <input type="hidden" name="event_id" value="<?php echo (int)$event_id; ?>">
                                        <input type="hidden" name="record_id" value="<?php echo (int)$result['id']; ?>">
                                        <button type="submit" class="result-delete" aria-label="この記録を削除">✕</button>
                                    </form>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <?php endif; // 見本の画面ではないとき ?>
</main>

<?php if ($is_demo): ?>
    <?php // 見本の画面では、入力の窓や記録の処理は読み込まない ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    <?php return; ?>
<?php endif; ?>

<!-- 種目を追加 -->
<?php
$np_modal_events = $events;
$np_modal_tab_event_ids = $tab_event_ids;
$np_modal_form_action = 'next_pr.php';
$np_modal_added_url = static fn(int $added_event_id): string => next_pr_url(['event' => $added_event_id]);
$np_modal_initial_category = $is_strength ? NEXT_PR_CATEGORY_STRENGTH : NEXT_PR_CATEGORY_TRACK;
require __DIR__ . '/../includes/next_pr_add_event_modal.php';
?>

<!-- 種目を削除（間違えて追加した種目をタブから外す） -->
<div class="modal fade" id="removeEventModal" tabindex="-1" aria-labelledby="removeEventModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5 fw-bold" id="removeEventModalTitle">種目を削除</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
            </div>
            <div class="modal-body">
                <p class="pr-note">
                    間違えて追加した種目をタブから外せます。<br>
                    記録がある種目は削除できません。
                </p>
                <ul class="remove-event-list">
                    <?php foreach ($tab_event_ids as $tab_event_id): ?>
                        <li class="remove-event-item">
                            <span class="remove-event-name"><?php echo h($events[$tab_event_id]['label']); ?></span>
                            <?php if (in_array($tab_event_id, $event_ids_with_records, true)): ?>
                                <span class="remove-event-locked">記録があるため削除できません</span>
                            <?php else: ?>
                                <form method="POST" action="next_pr.php" class="m-0" onsubmit="return confirm('<?php echo h($events[$tab_event_id]['label']); ?>を削除しますか？\n（目標を設定していた場合は、追加し直すと元に戻ります）');">
                                    <input type="hidden" name="action" value="remove_event">
                                    <input type="hidden" name="event_id" value="<?php echo (int)$tab_event_id; ?>">
                                    <button type="submit" class="remove-event-btn">削除</button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php if ($event !== null): ?>
    <!-- 種目ごとの背景写真（ホームの NEXT PR のカードと、このページの自己ベストのカードの背景）。入れていなければ見本の写真を使う。
         写真を選ぶと「位置を調整」の窓が開き、枠の中だけを切り抜いて保存する（動きは js/np_photo_crop.js） -->
    <div class="modal fade" id="prPhotoModal" tabindex="-1" aria-labelledby="prPhotoModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title fs-5 fw-bold" id="prPhotoModalTitle"><?php echo h($event['label']); ?>の背景写真</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-secondary mb-2">ホームの NEXT PR のカードと、このページの自己ベストのカードの背景になります。<?php echo $event_photo === null ? 'いまは見本の写真です。' : ''; ?></p>
                    <img class="pr-photo-preview" src="../<?php echo h($event_photo ?? next_pr_default_photo_url()); ?>" alt="">
                    <input class="visually-hidden" type="file" id="prPhotoInput" accept="image/*" data-pr-photo-input aria-label="写真を選ぶ">
                    <div class="pr-photo-menu">
                        <button type="button" class="btn btn-primary" data-pr-photo-choose><?php echo $event_photo === null ? '写真を選ぶ' : '写真を選び直す'; ?></button>
                        <?php if ($event_photo_original !== null): ?>
                            <button type="button" class="btn btn-outline-secondary" data-pr-photo-adjust>位置を調整</button>
                        <?php endif; ?>
                    </div>
                    <p class="pr-photo-error" role="alert" data-pr-photo-error hidden></p>
                    <?php if ($event_photo !== null): ?>
                        <form method="POST" action="next_pr.php" class="mt-2 text-center" onsubmit="return confirm('写真を外して、見本の写真に戻しますか？');">
                            <input type="hidden" name="action" value="delete_photo">
                            <input type="hidden" name="event_id" value="<?php echo (int)$event_id; ?>">
                            <button type="submit" class="btn btn-link text-danger btn-sm">写真を外す（見本の写真に戻す）</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 位置を調整: 写真をドラッグして位置を、バーで大きさを決める。枠の縦横比は自己ベストのカードと同じ -->
    <div class="modal fade" id="prCropModal" tabindex="-1" aria-labelledby="prCropModalTitle" aria-hidden="true" data-bs-backdrop="static"
        data-event-id="<?php echo (int)$event_id; ?>"
        data-post-url="next_pr.php"
        data-return-url="<?php echo h(next_pr_url(['event' => $event_id])); ?>"
        data-original-url="<?php echo $event_photo_original !== null ? h('../' . $event_photo_original) : ''; ?>">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title fs-5 fw-bold" id="prCropModalTitle">位置を調整</h2>
                </div>
                <div class="modal-body">
                    <p class="small text-secondary mb-2">写真をドラッグして位置を、下のバーで大きさを決めてください。枠の中が背景になります。</p>
                    <div class="pr-crop-frame" data-crop-frame>
                        <?php // 写真を枠より小さくしたときの隙間は、同じ写真をぼかしたもので埋める（保存する写真も同じ見た目） ?>
                        <img class="pr-crop-backdrop" alt="" aria-hidden="true" data-crop-backdrop>
                        <img class="pr-crop-img" alt="" data-crop-img>
                        <span class="pr-crop-grid" aria-hidden="true"></span>
                    </div>
                    <label class="pr-crop-zoom">
                        <span>大きさ</span>
                        <input type="range" class="form-range" min="1" max="3" step="0.01" value="1" data-crop-zoom aria-label="写真の大きさ">
                    </label>
                    <p class="pr-photo-error" role="alert" data-crop-error hidden></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-crop-cancel>やめる</button>
                    <button type="button" class="btn btn-primary" data-crop-save>この位置で決定</button>
                </div>
            </div>
        </div>
    </div>
    <?php if ($is_strength): ?>
        <!-- 筋トレの記録を手入力で追加（練習日記の外で挙げた重量など） -->
        <div class="modal fade" id="strengthRecordModal" tabindex="-1" aria-labelledby="strengthRecordModalTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" method="POST" action="next_pr.php" novalidate data-pr-validate>
                    <div class="modal-header">
                        <h2 class="modal-title fs-5 fw-bold" id="strengthRecordModalTitle">記録を追加</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_strength_record">
                        <input type="hidden" name="event_id" value="<?php echo (int)$event_id; ?>">
                        <p class="pr-note">挙げた重量を記録します（回数は問いません）。練習日記のウエイトの記録は自動で反映されるので、ここでは日記の外で挙げた重量を入れてください。</p>
                        <div class="mb-3">
                            <label class="form-label fw-bold" for="strengthEventName">種目</label>
                            <input type="text" class="form-control" id="strengthEventName" value="<?php echo h($event['label']); ?>" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold" for="strengthRecordDate">日付</label>
                            <input type="date" class="form-control" id="strengthRecordDate" name="record_date" value="<?php echo h(date('Y-m-d')); ?>" max="<?php echo h(date('Y-m-d')); ?>" required data-error="日付を入力してください。">
                            <div class="invalid-feedback"></div>
                        </div>
                        <div>
                            <label class="form-label fw-bold" for="strengthWeight">重量（kg）</label>
                            <?php // 初期値は今の自己ベスト（無ければ「重量を選択」から） ?>
                            <?php echo render_weight_picker('weight', 'strengthWeight', $personal_best['value'] ?? null, '重量を選んでください。'); ?>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary">保存する</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$is_strength): ?>
        <!-- 陸上: 自己申告の自己ベスト（練習日記の大会以外で出した記録。1種目1件で、いつでも入れ直せる） -->
        <div class="modal fade" id="selfRecordModal" tabindex="-1" aria-labelledby="selfRecordModalTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" method="POST" action="next_pr.php" novalidate data-pr-validate>
                    <div class="modal-header">
                        <h2 class="modal-title fs-5 fw-bold" id="selfRecordModalTitle"><?php echo h($event['label']); ?>の自己ベストを入力</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="save_self_record">
                        <input type="hidden" name="event_id" value="<?php echo (int)$event_id; ?>">
                        <p class="pr-note">
                            練習日記に載せていない自己ベストを入力できます（記録一覧に「自己申告」として残ります）。<br>
                            何度でも入れ直せます。保存すると、前に入れた記録は書き換わります。<br>
                            練習日記の大会記録の方が良いときは、そちらが自己ベストになります。
                        </p>
                        <div class="mb-3">
                            <label class="form-label fw-bold" for="selfRecordDate">記録した日</label>
                            <?php // 記録の保存期間（5年）より前の日付は選べない。前に入れた記録があれば、その日付と記録から始める ?>
                            <input type="date" class="form-control" id="selfRecordDate" name="record_date" value="<?php echo h($self_record['date'] ?? date('Y-m-d')); ?>" min="<?php echo h(date('Y-m-d', strtotime('-' . NEXT_PR_KEEP_YEARS . ' years'))); ?>" max="<?php echo h(date('Y-m-d')); ?>" required data-error="日付を選んでください。">
                            <div class="invalid-feedback"></div>
                        </div>
                        <div>
                            <span class="form-label fw-bold d-block" id="selfRecordLabel">自己ベスト</span>
                            <?php echo render_time_parts($event, 'self_parts', next_pr_value_to_parts($event, $self_record['value'] ?? 0.0), 'selfRecordLabel', '自己ベスト', true); ?>
                            <div class="invalid-feedback d-block" data-feedback-for-parts></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary">保存する</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- 目標の設定（シーズン / 長期） -->
    <?php foreach ([NEXT_PR_GOAL_SEASON => 'シーズン目標（' . $this_year . '年）', NEXT_PR_GOAL_LONG => '長期目標'] as $goal_type => $goal_title): ?>
        <?php $goal = $goals[$goal_type]; ?>
        <?php // シーズン目標は今年のシーズンベスト、長期目標は本人が選んだ基準（最初は今の設定、無ければ自己ベスト） ?>
        <?php $is_long_goal = $goal_type === NEXT_PR_GOAL_LONG; ?>
        <?php $selected_baseline = $is_long_goal ? ($goal['baseline_type'] ?? NEXT_PR_BASELINE_PB) : NEXT_PR_BASELINE_PB; ?>
        <?php $goal_reference = next_pr_goal_reference_best($event, $results, $goal_type, $this_year, $selected_baseline, $goal['baseline_from'] ?? null); ?>
        <?php // シーズン目標で、今年の初戦の前に去年のシーズンベストを使うときは、その名前で出す ?>
        <?php $goal_reference_name = $goal_type === NEXT_PR_GOAL_SEASON
            ? (($goal_reference['year'] ?? $this_year) === $this_year ? '今年のシーズンベスト' : '去年（' . ($this_year - 1) . '年）のシーズンベスト')
            : NEXT_PR_BASELINE_LABELS[$selected_baseline]; ?>
        <?php
        // 長期目標の基準の選択肢と、それぞれの今の記録（「自分で入力」は記録を出さない）
        $baseline_options = [];
        if ($is_long_goal) {
            foreach (NEXT_PR_BASELINE_LABELS as $baseline_key => $baseline_label) {
                $option_best = $baseline_key === NEXT_PR_BASELINE_MANUAL ? null : next_pr_goal_reference_best($event, $results, $goal_type, $this_year, $baseline_key);
                $baseline_options[$baseline_key] = ['label' => $baseline_label, 'best' => $option_best];
            }
        }
        ?>
        <div class="modal fade" id="goalModal-<?php echo h($goal_type); ?>" tabindex="-1" aria-labelledby="goalModalTitle-<?php echo h($goal_type); ?>" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" method="POST" action="next_pr.php" novalidate data-pr-validate>
                    <div class="modal-header">
                        <h2 class="modal-title fs-5 fw-bold" id="goalModalTitle-<?php echo h($goal_type); ?>"><?php echo h($event['label'] . 'の' . $goal_title); ?></h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="event_id" value="<?php echo (int)$event_id; ?>">
                        <input type="hidden" name="goal_type" value="<?php echo h($goal_type); ?>">
                        <?php if ($is_long_goal): ?>
                            <p class="pr-note">選んだ基準の今の記録が「設定時」の記録になり、そこからの進み具合を達成度で表示します。</p>
                        <?php else: ?>
                            <p class="pr-note">
                                <?php if ($goal_reference !== null): ?>
                                    現在の<?php echo h($goal_reference_name); ?>（<?php echo h(next_pr_format_text($event, $goal_reference['value'])); ?>）が、基準として自動で記録されます。
                                <?php else: ?>
                                    まだ今年の記録がないため、目標を決めた時点の記録を下で入れられます。
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                        <?php if ($is_strength): ?>
                            <div class="mb-3">
                                <label class="form-label fw-bold" for="goalWeight-<?php echo h($goal_type); ?>"><?php echo h($input_hint['label']); ?></label>
                                <?php // 筋トレは重量プルダウン（初期値は今の目標、無ければ今の自己ベスト） ?>
                                <?php echo render_weight_picker('goal_weight', 'goalWeight-' . $goal_type, $goal['goal'] ?? ($goal_reference['value'] ?? ($personal_best['value'] ?? null)), '目標重量を選んでください。'); ?>
                            </div>
                        <?php else: ?>
                        <div class="mb-3">
                            <span class="form-label fw-bold d-block" id="goalValueLabel-<?php echo h($goal_type); ?>"><?php echo h($input_hint['label']); ?></span>
                            <?php // 手入力なし: 種目の形式に合わせたプルダウン（初期値は今の目標、無ければ今の自己ベスト） ?>
                            <?php echo render_time_parts($event, 'goal_parts', next_pr_value_to_parts($event, $goal['goal'] ?? ($goal_reference['value'] ?? ($personal_best['value'] ?? 0.0))), 'goalValueLabel-' . $goal_type, $input_hint['label'], true); ?>
                            <div class="invalid-feedback d-block" data-feedback-for-parts></div>
                        </div>
                        <?php endif; ?>

                        <?php if ($is_long_goal): ?>
                            <?php // 長期目標: 何を基準に測るかを選ぶ（20代の自己ベストを今超えるのは難しい、といった場合に） ?>
                            <fieldset class="mb-3 goal-baseline" data-goal-baseline>
                                <legend class="form-label fw-bold fs-6">基準にする記録</legend>
                                <?php foreach ($baseline_options as $baseline_key => $baseline_option): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="baseline_type" id="goalBaseline-<?php echo h($baseline_key); ?>" value="<?php echo h($baseline_key); ?>" data-has-record="<?php echo $baseline_option['best'] !== null ? '1' : '0'; ?>" <?php echo $baseline_key === $selected_baseline ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="goalBaseline-<?php echo h($baseline_key); ?>">
                                            <?php echo h($baseline_option['label']); ?>
                                            <?php if ($baseline_key !== NEXT_PR_BASELINE_MANUAL): ?>
                                                <span class="goal-baseline-value">（<?php echo $baseline_option['best'] !== null ? h(next_pr_format_text($event, $baseline_option['best']['value'])) : '記録なし'; ?>）</span>
                                            <?php else: ?>
                                                <span class="goal-baseline-value">（今日からの記録で測る）</span>
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </fieldset>
                        <?php endif; ?>

                        <?php if ($is_long_goal || $goal_reference === null): ?>
                            <?php // 記録の入力欄。シーズン目標は今年の記録が無いときだけ（任意）。
                            // 長期目標は「自分で入力」のとき（必須）と、選んだ基準に記録が無いとき（任意）に、下のスクリプトが出す ?>
                            <?php $start_label = $is_strength ? '目標を決めた時点の重量（任意）' : '目標を決めた時点のタイム・記録（任意）'; ?>
                            <?php $start_initial = ($goal['baseline_type'] ?? '') === NEXT_PR_BASELINE_MANUAL || $goal_reference === null ? ($goal['start'] ?? null) : null; ?>
                            <div class="mb-3 goal-start-input" data-goal-start<?php echo $is_long_goal ? ' hidden' : ''; ?>>
                                <?php if ($is_strength): ?>
                                    <label class="form-label fw-bold" for="goalStartWeight-<?php echo h($goal_type); ?>" data-goal-start-label><?php echo h($start_label); ?></label>
                                    <?php echo render_weight_picker('start_weight', 'goalStartWeight-' . $goal_type, $start_initial, '', false); ?>
                                <?php else: ?>
                                    <span class="form-label fw-bold d-block" id="goalStartLabel-<?php echo h($goal_type); ?>" data-goal-start-label><?php echo h($start_label); ?></span>
                                    <?php echo render_time_parts($event, 'start_parts', next_pr_value_to_parts($event, $start_initial ?? 0.0), 'goalStartLabel-' . $goal_type, $start_label, false); ?>
                                <?php endif; ?>
                                <div class="goal-start-note" data-goal-start-note>
                                    <?php echo $is_strength ? '「入れない」' : 'すべて0'; ?>のままなら、記録ができてから達成度を表示します。<br>
                                    記録ができたあとは、<?php echo h($goal_reference_name); ?>が自動で使われます。
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if ($goal_type === NEXT_PR_GOAL_LONG): ?>
                            <?php $target_year = (int)($goal['target_year'] ?? ($this_year + 2)); ?>
                            <div class="year-stepper-row" data-year-stepper data-min="<?php echo $this_year; ?>" data-max="<?php echo $this_year + NEXT_PR_LONG_GOAL_MAX_YEARS; ?>">
                                <span class="form-label fw-bold mb-0" id="targetYearLabel">目標年</span>
                                <div class="year-stepper" role="group" aria-labelledby="targetYearLabel">
                                    <button type="button" class="year-stepper-btn" data-step="-1" aria-label="1年前にする">−</button>
                                    <span class="year-stepper-value" aria-live="polite"><span data-year-text><?php echo $target_year; ?></span>年</span>
                                    <button type="button" class="year-stepper-btn" data-step="1" aria-label="1年後にする">＋</button>
                                </div>
                                <input type="hidden" name="target_year" value="<?php echo $target_year; ?>">
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php // Enter キーで送信すると最初のボタンが使われるので、保存を先に置き、見た目は row-reverse で右に寄せる ?>
                    <div class="modal-footer flex-row-reverse justify-content-between">
                        <button type="submit" name="action" value="save_goal" class="btn btn-primary">保存する</button>
                        <?php if ($goal !== null): ?>
                            <button type="submit" name="action" value="delete_goal" class="btn btn-outline-secondary" formnovalidate data-skip-validate onclick="return confirm('この目標を削除しますか？');">削除</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/np_photo_crop.js?v=<?php echo (int)@filemtime(__DIR__ . '/../js/np_photo_crop.js'); ?>"></script>
<script src="../js/week_summary.js?v=<?php echo (int)@filemtime(__DIR__ . '/../js/week_summary.js'); ?>"></script>
<script>
(() => {
    // 長期目標の基準: 「自分で入力」なら記録の入力欄を必須で出し、記録が無い基準なら任意で出す
    document.querySelectorAll('[data-goal-baseline]').forEach((fieldset) => {
        const form = fieldset.closest('form');
        const startBlock = form.querySelector('[data-goal-start]');
        if (!startBlock) {
            return;
        }
        const label = startBlock.querySelector('[data-goal-start-label]');
        const note = startBlock.querySelector('[data-goal-start-note]');
        const optionalLabel = label.textContent;
        const render = () => {
            const checked = fieldset.querySelector('input[name="baseline_type"]:checked');
            const isManual = checked && checked.value === 'manual';
            const hasRecord = checked && checked.dataset.hasRecord === '1';
            startBlock.hidden = !isManual && hasRecord;
            label.textContent = isManual ? optionalLabel.replace('目標を決めた時点の', '基準にする').replace('（任意）', '（必須）') : optionalLabel;
            note.hidden = isManual;
        };
        fieldset.querySelectorAll('input[name="baseline_type"]').forEach((radio) => radio.addEventListener('change', render));
        render();
    });

    // 目標年のステッパー（範囲外のボタンは押せなくする）
    document.querySelectorAll('[data-year-stepper]').forEach((stepper) => {
        const min = Number(stepper.dataset.min);
        const max = Number(stepper.dataset.max);
        const input = stepper.querySelector('input[name="target_year"]');
        const text = stepper.querySelector('[data-year-text]');
        const buttons = stepper.querySelectorAll('[data-step]');
        const render = () => {
            const year = Number(input.value);
            text.textContent = String(year);
            buttons.forEach((button) => {
                const next = year + Number(button.dataset.step);
                button.disabled = next < min || next > max;
            });
        };
        buttons.forEach((button) => {
            button.addEventListener('click', () => {
                const next = Number(input.value) + Number(button.dataset.step);
                if (next >= min && next <= max) {
                    input.value = String(next);
                    render();
                }
            });
        });
        render();
    });

    // 筋トレの重量プルダウン: 刻み（2.5kg / 5kg）を変えたら一覧を作り直し、今の値にいちばん近い重量を選び直す
    const weightMax = <?php echo json_encode(NEXT_PR_WEIGHT_MAX_KG); ?>;
    document.querySelectorAll('[data-weight-picker]').forEach((picker) => {
        const select = picker.querySelector('select');
        picker.querySelectorAll('input[type="radio"]').forEach((radio) => {
            radio.addEventListener('change', () => {
                const step = Number(radio.value);
                const current = Number(select.value);
                select.querySelectorAll('option:not([value=""])').forEach((option) => option.remove());
                for (let weight = step; weight <= weightMax + 0.001; weight += step) {
                    const option = document.createElement('option');
                    option.value = weight.toFixed(1);
                    option.textContent = weight.toFixed(1);
                    select.appendChild(option);
                }
                if (current > 0) {
                    const nearest = Math.min(weightMax, Math.max(step, Math.round(current / step) * step));
                    select.value = nearest.toFixed(1);
                }
            });
        });
    });

    // 入力チェック（サーバー側でも同じ正規表現で確認している）
    const toHalfWidth = (value) => value.replace(/[０-９．：]/g, (c) => String.fromCharCode(c.charCodeAt(0) - 0xFEE0)).trim();
    document.querySelectorAll('form[data-pr-validate]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (event.submitter && event.submitter.hasAttribute('data-skip-validate')) {
                return;
            }
            let firstInvalid = null;
            form.querySelectorAll('input[required], select[required]').forEach((input) => {
                const value = toHalfWidth(input.value);
                let valid = value !== '';
                if (valid && input.dataset.pattern) {
                    valid = new RegExp(input.dataset.pattern).test(value) && parseFloat(value.replace(/:.*/, '')) >= 0;
                }
                if (valid && input.dataset.max) {
                    valid = parseFloat(value) > 0 && parseFloat(value) <= Number(input.dataset.max);
                }
                if (valid && input.type === 'date' && input.max) {
                    valid = value <= input.max;
                }
                if (valid && input.type === 'date' && input.min) {
                    valid = value >= input.min;
                }
                input.classList.toggle('is-invalid', !valid);
                const feedback = form.querySelector('[data-feedback-for="' + input.id + '"]') || input.parentElement.querySelector('.invalid-feedback');
                if (feedback) {
                    feedback.textContent = valid ? '' : (input.dataset.error || '入力内容を確認してください。');
                }
                if (!valid && !firstInvalid) {
                    firstInvalid = input;
                }
            });
            // 目標のプルダウン: すべて0のままは保存させない
            form.querySelectorAll('[data-goal-parts]').forEach((group) => {
                const selects = group.querySelectorAll('select');
                const valid = Array.from(selects).some((select) => Number(select.value) > 0);
                const feedback = form.querySelector('[data-feedback-for-parts]');
                if (feedback) {
                    feedback.textContent = valid ? '' : group.dataset.error;
                }
                selects.forEach((select) => select.classList.toggle('is-invalid', !valid));
                if (!valid && !firstInvalid) {
                    firstInvalid = selects[0];
                }
            });
            if (firstInvalid) {
                event.preventDefault();
                firstInvalid.focus();
            }
        });
    });

    // ホームの「＋ 種目を追加」や鉛筆から来たときは、?open= のモーダルを開いた状態にする
    const openModalId = new URLSearchParams(location.search).get('open');
    const openModal = openModalId && /^[A-Za-z_-]+$/.test(openModalId) ? document.getElementById(openModalId) : null;
    if (openModal && openModal.classList.contains('modal')) {
        bootstrap.Modal.getOrCreateInstance(openModal).show();
    }
})();
</script>
</body>
</html>
