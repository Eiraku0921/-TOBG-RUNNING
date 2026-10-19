<?php
// PC の右の補助欄（幅の広い画面だけ。見た目は css/site_layout.css）。
// 練習のまとめ（今週）・次の合同練習・お知らせを、見るだけの小さなカードで出す。
// 本文のカード（週の目標の変更や参加ボタン）とは別の作りにして、同じページに並んでもぶつからないようにする。
//
// includes/site_header.php から読み込む。使う値: $pdo, $base_path, $site_user_id, $site_active_tab
require_once __DIR__ . '/../back/training_summary_lib.php';
require_once __DIR__ . '/../back/club_session_lib.php';
require_once __DIR__ . '/../back/announcement_lib.php';

$rail_e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$rail_weekdays = ['日', '月', '火', '水', '木', '金', '土'];

// NEXT PR の画面は本文に練習のまとめがあるので、補助欄には出さない
$rail_week = null;
if ($site_active_tab !== 'next_pr') {
    try {
        $rail_week = fetch_training_week_summary($pdo, $site_user_id);
    } catch (PDOException $site_error) {
        error_log('side rail week failed: ' . $site_error->getMessage());
    }
}
$rail_session = null;
try {
    $rail_sessions = fetch_club_sessions($pdo, club_session_viewer($pdo, $site_user_id), false, 1);
    $rail_session = $rail_sessions[0] ?? null;
} catch (PDOException $site_error) {
    error_log('side rail session failed: ' . $site_error->getMessage());
}
$rail_news = [];
try {
    $rail_news = fetch_published_announcements($pdo, 4);
} catch (PDOException $site_error) {
    $rail_news = []; // お知らせの表がまだ無いときなど
}
?>
<aside class="site-rail" aria-label="サイド情報">
    <?php if ($rail_week !== null): ?>
        <?php $rail_week_max = max(1.0, ...array_map(static fn(array $day): float => (float)$day['km'], $rail_week['days'])); ?>
        <section class="rail-sec">
            <p class="rail-eb">練習のまとめ</p>
            <a class="rail-week" href="<?php echo $rail_e($base_path . '/mypage/next_pr.php'); ?>">
                <span class="rail-week-head">YOUR WEEK <span><?php echo $rail_e(date('m.d', strtotime($rail_week['week_start'])) . '–' . date('m.d', strtotime($rail_week['week_end']))); ?></span></span>
                <span class="rail-week-stats">
                    <span><b><?php echo $rail_e(number_format((float)$rail_week['week_km'], 1)); ?></b><small>今週の km</small></span>
                    <span><b><?php echo (int)$rail_week['week_count']; ?></b><small>今週の練習</small></span>
                    <span><b><?php echo $rail_e(number_format((float)$rail_week['month_km'], 1)); ?></b><small>今月の km</small></span>
                </span>
                <?php // 月〜日の日別の距離（今日はオレンジ、先の日は薄く） ?>
                <span class="rail-week-chart" role="img" aria-label="今週の日別の距離">
                    <?php foreach ($rail_week['days'] as $rail_day): ?>
                        <?php $rail_day_class = $rail_day['date'] === $rail_week['today'] ? ' is-today' : ($rail_day['date'] > $rail_week['today'] ? ' is-future' : ''); ?>
                        <span class="rail-week-day<?php echo $rail_day_class; ?>">
                            <i style="height: <?php echo (int)round(max(4, (float)$rail_day['km'] / $rail_week_max * 100)); ?>%"></i>
                            <small><?php echo $rail_weekdays[(int)date('w', strtotime($rail_day['date']))]; ?></small>
                        </span>
                    <?php endforeach; ?>
                </span>
            </a>
        </section>
    <?php endif; ?>

    <section class="rail-sec">
        <div class="rail-sec-head">
            <p class="rail-eb">次の合同練習</p>
            <a class="rail-more" href="<?php echo $rail_e($base_path . '/front/club.php?tab=sessions'); ?>">一覧 →</a>
        </div>
        <?php if ($rail_session === null): ?>
            <p class="rail-card rail-empty">予定されている合同練習はありません。</p>
        <?php else: ?>
            <?php $rail_session_time = strtotime((string)$rail_session['session_date']); ?>
            <a class="rail-card rail-session" href="<?php echo $rail_e($base_path . '/front/club.php?tab=sessions#session-' . (int)$rail_session['id']); ?>">
                <span class="rail-session-date"><?php echo $rail_e(date('n/j', $rail_session_time) . '（' . $rail_weekdays[(int)date('w', $rail_session_time)] . '）' . substr((string)$rail_session['meet_time'], 0, 5)); ?></span>
                <span class="rail-session-title"><?php echo $rail_e((string)$rail_session['title']); ?></span>
                <span class="rail-session-place"><?php echo $rail_e((string)$rail_session['meet_place']); ?></span>
                <span class="rail-session-foot">
                    <span>参加 <?php echo (int)$rail_session['participant_count']; ?><?php echo $rail_session['capacity'] !== null ? ' / ' . (int)$rail_session['capacity'] : ''; ?>人</span>
                    <?php if ($rail_session['is_canceled']): ?>
                        <span class="rail-chip is-canceled">中止</span>
                    <?php elseif ($rail_session['is_joined']): ?>
                        <span class="rail-chip">参加予定 ✓</span>
                    <?php endif; ?>
                </span>
            </a>
        <?php endif; ?>
    </section>

    <section class="rail-sec">
        <p class="rail-eb">お知らせ</p>
        <?php if (empty($rail_news)): ?>
            <p class="rail-card rail-empty">新しいお知らせはありません。</p>
        <?php else: ?>
            <ul class="rail-card rail-news">
                <?php foreach ($rail_news as $rail_item): ?>
                    <li>
                        <a href="<?php echo $rail_e(($base_path === '' ? '/' : $base_path . '/index.php') . '#newsTitle'); ?>">
                            <span class="rail-news-title"><?php if ((int)($rail_item['is_important'] ?? 0) === 1): ?><span class="rail-important">重要</span><?php endif; ?><?php echo $rail_e((string)$rail_item['title']); ?></span>
                            <span class="rail-news-date"><?php echo $rail_e(date('n/j', strtotime((string)$rail_item['created_at']))); ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</aside>
