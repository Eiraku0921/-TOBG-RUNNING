<?php
// 走行距離のランキング（みんなのページ・クラブ画面で共通）。集計は back/training_summary_lib.php の fetch_distance_ranking()。
// 使う変数:
//   $ranking_rows     fetch_distance_ranking() の戻り値
//   $ranking_period   distance_ranking_period() の戻り値
//   $ranking_url      callable: 期間の切り替えのリンク（['rank_period' => ..., 'rank_offset' => ...] を受け取る）
//   $ranking_note     集計の説明（例「みんなの練習日記に公開した記録で集計」）
//   $ranking_viewer   見ている人の user_id、$ranking_base 画像のパスの頭、$e
$ranking_me = null;
$ranking_above = null; // 自分より1つ上の順位の人（「◯位まで、あと ◯ km」）
foreach ($ranking_rows as $index => $ranking_row) {
    if ($ranking_row['user_id'] === $ranking_viewer) {
        $ranking_me = $ranking_row;
        for ($j = $index - 1; $j >= 0; $j--) {
            if ($ranking_rows[$j]['km'] > $ranking_row['km']) {
                $ranking_above = $ranking_rows[$j];
                break;
            }
        }
    }
}
$is_month = $ranking_period['period'] === 'month';
$week_label = $is_month ? distance_ranking_period('week')['label'] : $ranking_period['label'];
?>
<section class="ranking" aria-label="ランキング">
    <?php // 週間／月間（白く浮かせる切り替え）。月間は過去1年まで月を戻せる ?>
    <nav class="ranking-seg" aria-label="期間">
        <a class="ranking-seg-btn<?php echo $is_month ? '' : ' is-active'; ?>" href="<?php echo $e($ranking_url(['rank_period' => 'week'])); ?>">週間 <span><?php echo $e($week_label); ?></span></a>
        <a class="ranking-seg-btn<?php echo $is_month ? ' is-active' : ''; ?>" href="<?php echo $e($ranking_url(['rank_period' => 'month'])); ?>">月間</a>
    </nav>
    <?php if ($is_month): ?>
        <div class="ranking-month-nav">
            <?php if ($ranking_period['offset'] < 11): ?>
                <a href="<?php echo $e($ranking_url(['rank_period' => 'month', 'rank_offset' => $ranking_period['offset'] + 1])); ?>">‹ 前の月</a>
            <?php else: ?><span></span><?php endif; ?>
            <strong><?php echo $e($ranking_period['label']); ?></strong>
            <?php if ($ranking_period['offset'] > 0): ?>
                <a href="<?php echo $e($ranking_url(['rank_period' => 'month', 'rank_offset' => $ranking_period['offset'] - 1])); ?>">次の月 ›</a>
            <?php else: ?><span></span><?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($ranking_viewer > 0): ?>
        <div class="ranking-me">
            <div>
                <p class="ranking-me-eyebrow">YOUR RANK</p>
                <p class="ranking-me-text">
                    <?php if ($ranking_me === null || $ranking_me['km'] <= 0): ?>
                        まだ記録がありません。走ったら日記に残そう
                    <?php elseif ($ranking_above === null): ?>
                        トップです！ この調子
                    <?php else: ?>
                        <?php echo (int)$ranking_above['rank']; ?>位まで、あと <?php echo $e(number_format($ranking_above['km'] - $ranking_me['km'], 1)); ?> km
                    <?php endif; ?>
                </p>
            </div>
            <?php if ($ranking_me !== null && $ranking_me['km'] > 0): ?>
                <p class="ranking-me-rank"><strong><?php echo (int)$ranking_me['rank']; ?></strong>位<span><?php echo count($ranking_rows); ?>人中</span></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($ranking_rows)): ?>
        <p class="ranking-empty">この期間の記録はまだありません。</p>
    <?php else: ?>
        <ol class="ranking-list">
            <?php foreach ($ranking_rows as $ranking_row): ?>
                <?php $is_me = $ranking_row['user_id'] === $ranking_viewer; ?>
                <li class="ranking-row<?php echo $is_me ? ' is-me' : ''; ?>">
                    <span class="ranking-rank<?php echo $ranking_row['rank'] <= 3 || $is_me ? ' is-top' : ''; ?>"><?php echo sprintf('%02d', (int)$ranking_row['rank']); ?></span>
                    <?php if ($ranking_row['avatar_path'] !== ''): ?>
                        <img class="ranking-avatar" src="<?php echo $e($ranking_base . $ranking_row['avatar_path']); ?>" alt="">
                    <?php else: ?>
                        <span class="ranking-avatar" aria-hidden="true"><?php echo $e(mb_substr($ranking_row['name'], 0, 1)); ?></span>
                    <?php endif; ?>
                    <span class="ranking-name"><?php echo $e($ranking_row['name']); ?><?php echo $is_me ? '（あなた）' : ''; ?></span>
                    <span class="ranking-km"><?php echo $e(number_format($ranking_row['km'], 1)); ?><small>km</small></span>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
    <p class="ranking-note"><?php echo $e($ranking_note); ?></p>
</section>
