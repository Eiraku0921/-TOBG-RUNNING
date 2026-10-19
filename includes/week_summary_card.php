<?php
// 練習のまとめカード（YOUR WEEK）。ホームと NEXT PR で同じものを使う。
// 中身（数字・日別グラフ・週の目標）は js/week_summary.js がデータから描き、見た目は css/week_summary.css。
//
// 使う変数:
//   $week_summary            fetch_training_week_summary() の戻り値
//   $week_summary_base       画像・処理へのパスの頭（ホームは ''、mypage/ のページは '../'）
//   $week_summary_event_key  選んでいる NEXT PR の種目（表示の初期値を決める。無ければ null）

$week_summary_default_mode = in_array($week_summary_event_key ?? null, TRAINING_SUMMARY_LONG_EVENTS, true) ? 'long' : 'short';
$week_summary_range = date('m.d', strtotime($week_summary['week_start'])) . '–' . date('m.d', strtotime($week_summary['week_end']));
$week_summary_data = [
    'today' => $week_summary['today'],
    'weekCount' => $week_summary['week_count'],
    'monthCount' => $week_summary['month_count'],
    'weekReps' => $week_summary['week_reps'],
    'weekKm' => $week_summary['week_km'],
    'monthKm' => $week_summary['month_km'],
    'days' => $week_summary['days'],
    'goals' => $week_summary['goals'],
    'goalRanges' => TRAINING_WEEKLY_GOAL_RANGES,
];
?>
<section class="week-card" aria-labelledby="weekCardTitle" data-week-summary
    data-default-mode="<?php echo $week_summary_default_mode; ?>"
    data-save-url="<?php echo htmlspecialchars($week_summary_base . 'back/save_weekly_goal.php', ENT_QUOTES, 'UTF-8'); ?>">
    <div class="week-card-head">
        <h2 class="week-card-eyebrow" id="weekCardTitle">YOUR WEEK <span class="week-card-range"><?php echo htmlspecialchars($week_summary_range, ENT_QUOTES, 'UTF-8'); ?></span></h2>
        <div class="week-mode" role="group" aria-label="まとめの表示">
            <button type="button" class="week-mode-btn" data-week-mode="short" aria-pressed="false">短距離</button>
            <button type="button" class="week-mode-btn" data-week-mode="long" aria-pressed="false">長距離</button>
            <button type="button" class="week-mode-btn" data-week-mode="both" aria-pressed="false">両方</button>
        </div>
    </div>
    <div data-week-body></div>
    <script type="application/json" data-week-data><?php echo json_encode($week_summary_data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?></script>
</section>
