<?php
// ホーム（ログイン中）のタイトル部分に出す NEXT PR。種目ごとの写真を背景にしたカード（css/next_pr_cards.css の .np-hero）。
// 写真はその種目で会員が入れたもの（NEXT PR のページのカメラのボタンから）。入れていなければ見本の写真（NEXT_PR_PHOTO_DEFAULT）。
// ホームは見るだけ: 鉛筆（記録・目標の入力）と「＋ 種目を追加」は NEXT PR のページにだけ置く。
// 使う変数: $home_next_pr（fetch_next_pr_summary の戻り値）

$np_e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$np_home_url = static function (array $params): string {
    return 'index.php?' . http_build_query($params);
};

$np_events = $home_next_pr['events'];
$np_event_id = $home_next_pr['event_id'];
$np_event = $home_next_pr['event'];
$np_view = $home_next_pr['view'];
$np_is_strength = $np_event !== null && $np_event['category'] === NEXT_PR_CATEGORY_STRENGTH;
$np_this_year = (int)date('Y');
// カードの背景の写真（サイトの一番上からの場所）
$np_photo = $home_next_pr['photo_path'] ?? null;
$np_photo_url = $np_photo !== null ? $np_photo : next_pr_default_photo_url();
$np_photo_style = "--np-photo: url('" . $np_e($np_photo_url) . "')";
// 見本の写真のときは、人の顔が見えるよう写真の見せる位置を変える（.np-hero.is-sample）
$np_photo_class = $np_photo === null ? ' is-sample' : '';
?>
<section class="home-next-pr" aria-labelledby="homeTitle">
    <p class="home-np-eyebrow" id="homeTitle">NEXT PERSONAL RECORD</p>

    <?php if ($np_event === null): ?>
        <!-- 種目がまだ無い人: 見本の写真のカードに案内と、NEXT PR のページへの入口 -->
        <div class="np-hero is-empty is-sample" style="<?php echo $np_photo_style; ?>">
            <p class="np-hero-empty-title">まだ種目がありません</p>
            <p class="np-hero-empty-text">自己ベストと目標までの進み具合を、ここで確認できます。</p>
            <a class="np-hero-empty-btn" href="mypage/next_pr.php">NEXT PR で種目を追加 →</a>
        </div>
    <?php else: ?>
        <!-- 種目のボタン（選ぶとホームのまま切り替わる。追加・削除は NEXT PR のページ） -->
        <nav class="event-tabs" aria-label="種目">
            <?php foreach ($home_next_pr['tab_event_ids'] as $np_tab_id): ?>
                <a class="event-tab<?php echo $np_tab_id === $np_event_id ? ' is-active' : ''; ?>" href="<?php echo $np_e($np_home_url(['pr_event' => $np_tab_id])); ?>" <?php echo $np_tab_id === $np_event_id ? 'aria-current="page"' : ''; ?>><?php echo $np_e($np_events[$np_tab_id]['label']); ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="np-hero<?php echo $np_photo_class; ?>" style="<?php echo $np_photo_style; ?>">
            <?php // 押しても画面は読み直さず、下の記録だけを入れ替える（選んだ方はサーバーに覚え、NEXT PR と共通。JS が無いときはリンクで動く） ?>
            <div class="best-tabs np-hero-tabs" role="group" aria-label="記録の種類" data-best-tabs data-save-url="back/save_next_pr_view.php">
                <a class="best-tab<?php echo $np_view === 'pb' ? ' is-active' : ''; ?>" href="<?php echo $np_e($np_home_url(['pr_event' => $np_event_id, 'pr_view' => 'pb'])); ?>" data-best-view="pb" aria-pressed="<?php echo $np_view === 'pb' ? 'true' : 'false'; ?>">自己ベスト</a>
                <a class="best-tab<?php echo $np_view === 'sb' ? ' is-active' : ''; ?>" href="<?php echo $np_e($np_home_url(['pr_event' => $np_event_id, 'pr_view' => 'sb'])); ?>" data-best-view="sb" aria-pressed="<?php echo $np_view === 'sb' ? 'true' : 'false'; ?>">シーズンベスト</a>
            </div>

            <?php foreach (['pb', 'sb'] as $np_panel_view): ?>
                <div class="np-hero-best" data-best-panel="<?php echo $np_panel_view; ?>"<?php echo $np_panel_view === $np_view ? '' : ' hidden'; ?>>
                    <?php $np_best = $np_panel_view === 'sb' ? $home_next_pr['season_best'] : $home_next_pr['personal_best']; ?>
                    <?php if ($np_best !== null): ?>
                        <?php $np_best_text = next_pr_format_value($np_event, $np_best['value']); ?>
                        <span class="np-hero-value"><?php echo $np_e($np_best_text['text']); ?><?php if ($np_best_text['unit'] !== ''): ?><small><?php echo $np_e($np_best_text['unit']); ?></small><?php endif; ?></span>
                        <span class="np-hero-meta">
                            <?php echo $np_e(date('Y年n月j日', strtotime($np_best['date']))); ?>
                            <?php if ($np_is_strength): ?>
                                トレーニング
                            <?php else: ?>
                                <?php echo $np_e($np_best['title']); ?>
                            <?php endif; ?>
                            <?php // 今年の初戦の前は、去年のシーズンベストを出していることを添える ?>
                            <?php if ($np_panel_view === 'sb' && $np_best['year'] !== $np_this_year): ?>
                                <span class="np-hero-note"><?php echo (int)$np_best['year']; ?>年のシーズンベスト</span>
                            <?php endif; ?>
                        </span>
                    <?php else: ?>
                        <span class="np-hero-none"><?php echo $np_panel_view === 'sb' ? $np_e($np_this_year . '年のベスト記録はまだありません') : 'ベスト記録はまだありません'; ?></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <!-- 目標（半透明の枠2つ）: 目標の値と達成度のバー -->
            <div class="np-hero-goals">
                <?php foreach ([NEXT_PR_GOAL_SEASON => 'シーズン目標', NEXT_PR_GOAL_LONG => '長期目標'] as $np_goal_type => $np_goal_title): ?>
                    <?php $np_goal = $home_next_pr['goals'][$np_goal_type]; ?>
                    <div class="np-hero-goal">
                        <span class="np-hero-goal-label">
                            <?php echo $np_e($np_goal_title); ?>
                            <?php if ($np_goal_type === NEXT_PR_GOAL_SEASON): ?>
                                <?php echo $np_this_year; ?>年
                            <?php elseif ($np_goal !== null): ?>
                                〜<?php echo (int)$np_goal['target_year']; ?>年
                            <?php endif; ?>
                        </span>
                        <?php if ($np_goal !== null): ?>
                            <?php $np_progress = (int)(next_pr_goal_progress($np_event, $np_goal['start'], $home_next_pr['goal_references'][$np_goal_type]['value'] ?? null, $np_goal['goal']) ?? 0); ?>
                            <span class="np-hero-goal-value"><?php echo $np_e(next_pr_format_text($np_event, $np_goal['goal'])); ?></span>
                            <span class="np-hero-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo $np_progress; ?>" aria-label="<?php echo $np_e($np_goal_title); ?>の達成度"><span style="width: <?php echo $np_progress; ?>%;"></span></span>
                        <?php else: ?>
                            <span class="np-hero-goal-empty">未設定</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</section>
<script>
    // 自己ベスト / シーズンベスト: ページを読み直さず、記録の表示だけを入れ替える（下の練習のまとめなどは描き直さない）。
    // 選んだ方は画面の裏でサーバーに覚えさせ、次に開いたとき（ホーム・NEXT PR）もその表示にする
    (function () {
        var tabs = document.querySelector('[data-best-tabs]');
        if (!tabs) {
            return;
        }
        tabs.addEventListener('click', function (event) {
            var tab = event.target.closest('[data-best-view]');
            if (!tab) {
                return;
            }
            event.preventDefault();
            var view = tab.getAttribute('data-best-view');
            if (tab.classList.contains('is-active')) {
                return;
            }
            tabs.querySelectorAll('[data-best-view]').forEach(function (other) {
                var active = other === tab;
                other.classList.toggle('is-active', active);
                other.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            document.querySelectorAll('[data-best-panel]').forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-best-panel') !== view;
            });
            fetch(tabs.getAttribute('data-save-url'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ view: view })
            }).catch(function () {
                // 覚えられなくても、今の表示は切り替わっている
            });
        });
    })();
</script>
