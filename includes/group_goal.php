<?php
// みんなの目標（みんなのページで、グループを選んだとき）。計算は back/group_goal_lib.php の fetch_group_goal()。
// 使う変数: $group_goal（fetch_group_goal() の戻り値）、$goal_group_id、$goal_viewer（user_id）、$e、$page_return_to、$goal_base（画像のパスの頭）
$goal_my_pledge = $group_goal['my_pledge_km'];
$goal_initial = $goal_my_pledge ?? 50; // 初めて宣言する人は 50km から選び始める
?>
<section class="group-goal" aria-label="みんなの目標">
    <?php // TEAM GOAL（濃いカード） ?>
    <div class="goal-team">
        <p class="goal-eyebrow">TEAM GOAL / <?php echo $e($group_goal['month_label']); ?></p>
        <?php if ($group_goal['goal_km'] > 0): ?>
            <p class="goal-lead">みんなの目標を足して、<?php echo (int)$group_goal['goal_km']; ?> km。</p>
        <?php else: ?>
            <p class="goal-lead">まだ誰も目標を宣言していません。最初の一人になろう</p>
        <?php endif; ?>
        <div class="goal-team-row">
            <p class="goal-team-total"><strong><?php echo $e(number_format($group_goal['total_km'], 1)); ?></strong><span> / <?php echo (int)$group_goal['goal_km']; ?> km</span></p>
            <?php if ($group_goal['percent'] !== null): ?>
                <p class="goal-team-percent"><?php echo (int)$group_goal['percent']; ?>%</p>
            <?php endif; ?>
        </div>
        <div class="goal-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo (int)($group_goal['percent'] ?? 0); ?>" aria-label="グループの達成率">
            <span style="width: <?php echo (int)($group_goal['percent'] ?? 0); ?>%;"></span>
        </div>
        <div class="goal-team-stats">
            <p><strong><?php echo $e(number_format($group_goal['remaining_km'], 1)); ?></strong><span>あと km</span></p>
            <p><strong><?php echo (int)$group_goal['days_left']; ?></strong><span>残り日数</span></p>
            <p><strong><?php echo $e(number_format($group_goal['per_day_km'], 1)); ?></strong><span>1日あたり</span></p>
        </div>
    </div>

    <?php // YOUR PLEDGE（−／＋ は 10km 刻み） ?>
    <form class="goal-pledge" method="POST" action="<?php echo $e($goal_base . 'back/save_group_pledge.php'); ?>" data-goal-pledge>
        <input type="hidden" name="group_id" value="<?php echo (int)$goal_group_id; ?>">
        <input type="hidden" name="return_to" value="<?php echo $e($page_return_to); ?>">
        <input type="hidden" name="pledge_km" value="<?php echo (int)$goal_initial; ?>" data-goal-value>
        <p class="goal-pledge-eyebrow">YOUR PLEDGE</p>
        <div class="goal-pledge-head">
            <span>あなたの<?php echo $e($group_goal['month_label']); ?>の目標</span>
            <?php if ($goal_my_pledge !== null): ?>
                <span class="goal-pledge-badge">宣言済み <?php echo (int)$goal_my_pledge; ?> km</span>
            <?php endif; ?>
        </div>
        <div class="goal-stepper">
            <button type="button" class="goal-step" data-goal-step="-<?php echo GROUP_PLEDGE_STEP_KM; ?>" aria-label="<?php echo GROUP_PLEDGE_STEP_KM; ?>km 減らす">−</button>
            <span class="goal-step-value" aria-live="polite"><strong data-goal-display><?php echo (int)$goal_initial; ?></strong> km</span>
            <button type="button" class="goal-step" data-goal-step="<?php echo GROUP_PLEDGE_STEP_KM; ?>" aria-label="<?php echo GROUP_PLEDGE_STEP_KM; ?>km 増やす">＋</button>
        </div>
        <button type="submit" class="goal-submit" data-goal-submit<?php echo $goal_my_pledge !== null ? ' disabled' : ''; ?>><?php echo $goal_my_pledge !== null ? '目標を更新する' : 'この目標を宣言する'; ?></button>
        <p class="goal-pledge-note">メンバーが宣言した距離の合計が、グループの目標になります。</p>
    </form>

    <?php // MEMBERS / 達成率順 ?>
    <p class="goal-members-title">MEMBERS <span>/ 達成率順</span></p>
    <ul class="goal-members">
        <?php foreach ($group_goal['members'] as $goal_member): ?>
            <?php $goal_is_me = $goal_member['user_id'] === $goal_viewer; ?>
            <li class="goal-member<?php echo $goal_is_me ? ' is-me' : ''; ?>">
                <div class="goal-member-row">
                    <?php if ($goal_member['avatar_path'] !== ''): ?>
                        <img class="goal-member-avatar" src="<?php echo $e($goal_base . $goal_member['avatar_path']); ?>" alt="">
                    <?php else: ?>
                        <span class="goal-member-avatar" aria-hidden="true"><?php echo $e(mb_substr($goal_member['name'], 0, 1)); ?></span>
                    <?php endif; ?>
                    <span class="goal-member-name"><?php echo $e($goal_member['name']); ?><?php echo $goal_is_me ? '（あなた）' : ''; ?></span>
                    <span class="goal-member-km"><strong><?php echo $e(number_format($goal_member['km'], 1)); ?></strong><?php echo $goal_member['pledge_km'] !== null ? '/' . (int)$goal_member['pledge_km'] . 'km' : 'km'; ?></span>
                </div>
                <?php if ($goal_member['pledge_km'] !== null): ?>
                    <div class="goal-bar is-light"><span style="width: <?php echo (int)$goal_member['percent']; ?>%;"></span></div>
                    <p class="goal-member-percent"><?php echo (int)$goal_member['percent']; ?>%</p>
                <?php else: ?>
                    <p class="goal-member-percent is-none">未宣言</p>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<script>
    // YOUR PLEDGE: −／＋ で 10km ずつ（10〜500km）。宣言済みの値から変えたときだけ「目標を更新する」を押せる
    (function () {
        var form = document.querySelector('[data-goal-pledge]');
        if (!form) {
            return;
        }
        var input = form.querySelector('[data-goal-value]');
        var display = form.querySelector('[data-goal-display]');
        var submit = form.querySelector('[data-goal-submit]');
        var saved = <?php echo $goal_my_pledge !== null ? (int)$goal_my_pledge : 'null'; ?>;
        var MIN = <?php echo GROUP_PLEDGE_MIN_KM; ?>, MAX = <?php echo GROUP_PLEDGE_MAX_KM; ?>;
        function render() {
            var value = Number(input.value);
            display.textContent = String(value);
            form.querySelector('[data-goal-step^="-"]').disabled = value <= MIN;
            form.querySelector('[data-goal-step]:not([data-goal-step^="-"])').disabled = value >= MAX;
            submit.disabled = saved !== null && value === saved;
        }
        form.querySelectorAll('[data-goal-step]').forEach(function (button) {
            button.addEventListener('click', function () {
                input.value = String(Math.min(MAX, Math.max(MIN, Number(input.value) + Number(button.getAttribute('data-goal-step')))));
                render();
            });
        });
        render();
    })();
</script>
