<?php
// クラブ画面の「歴代記録」。男子・女子と種目チップで切り替える。1位に「クラブ記録」、自分の行は強調。
// 記録は管理者の直接入力と、練習日記の強度「大会」の自動照合（back/records_lib.php の fetch_record_tables）
// 使う変数: $record_gender, $record_event, $record_rows, $user_name, $e, $club_url, $same_person
?>
<section class="club-panel" aria-label="歴代記録">
    <nav class="club-mini-seg" aria-label="男子・女子">
        <?php foreach (['male' => '男子', 'female' => '女子'] as $gender_key => $gender_label): ?>
            <a class="club-mini-seg-btn<?php echo $gender_key === $record_gender ? ' is-active' : ''; ?>" href="<?php echo $e($club_url(['tab' => 'records', 'gender' => $gender_key, 'event' => $record_event])); ?>"><?php echo $gender_label; ?></a>
        <?php endforeach; ?>
    </nav>
    <nav class="club-chips" aria-label="種目">
        <?php foreach (array_merge(RECORD_TRACK_EVENTS, RECORD_FIELD_EVENTS) as $chip_event): ?>
            <a class="club-chip<?php echo $chip_event === $record_event ? ' is-active' : ''; ?>" href="<?php echo $e($club_url(['tab' => 'records', 'gender' => $record_gender, 'event' => $chip_event])); ?>"<?php echo $chip_event === $record_event ? ' aria-current="page"' : ''; ?>><?php echo $e($chip_event); ?></a>
        <?php endforeach; ?>
    </nav>

    <ol class="club-record-list">
        <?php foreach ($record_rows as $row): ?>
            <?php
            $is_empty = !empty($row['__empty']);
            $is_me = !$is_empty && $same_person((string)($row['name'] ?? ''), $user_name);
            $rank = (int)($row['rank_num'] ?? 0);
            $match_date = !empty($row['match_date']) ? date('Y/n/j', strtotime((string)$row['match_date'])) : '';
            ?>
            <li class="club-record<?php echo $is_me ? ' is-me' : ''; ?><?php echo $is_empty ? ' is-empty' : ''; ?>">
                <span class="club-record-rank<?php echo $rank <= 3 && !$is_empty ? ' is-top' : ''; ?>"><?php echo sprintf('%02d', $rank); ?></span>
                <span class="club-record-main">
                    <span class="club-record-name">
                        <?php echo $is_empty ? '記録なし' : $e((string)$row['name']); ?>
                        <?php if ($rank === 1 && !$is_empty): ?><span class="club-record-badge">クラブ記録</span><?php endif; ?>
                    </span>
                    <?php if (!$is_empty): ?>
                        <span class="club-record-meta"><?php echo $e(trim($match_date . '・' . (string)($row['match_name'] ?? ''), '・')); ?></span>
                    <?php endif; ?>
                </span>
                <span class="club-record-time"><?php echo $is_empty ? '' : $e((string)$row['record_time']); ?></span>
            </li>
        <?php endforeach; ?>
    </ol>
</section>
