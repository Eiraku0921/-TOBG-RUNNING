<?php
// ホームの大会の欄（ENTRY カード）。ログイン中と未ログインで共通。使う変数: $home_open_tournaments
?>
<?php // 大会（仕様書の ENTRY カード）: 「ENTRY / 締切 10.18」、大会名、右に開催日までの日数（DAYS TO GO）。押すとクラブ画面のその大会へ ?>
<section class="home-entries" aria-labelledby="homeTournamentsTitle">
    <h2 class="visually-hidden" id="homeTournamentsTitle">大会のお知らせ</h2>
    <?php if (empty($home_open_tournaments)): ?>
        <p class="home-entry-empty">エントリー受付中の大会はありません。<a href="front/club.php?tab=races">大会の一覧 →</a></p>
    <?php else: ?>
        <?php foreach ($home_open_tournaments as $home_tournament): ?>
            <a class="home-entry" href="<?php echo htmlspecialchars(tournament_page_path((int)$home_tournament['id']), ENT_QUOTES, 'UTF-8'); ?>">
                <span class="home-entry-main">
                    <span class="home-entry-eyebrow">ENTRY
                        / 締切 <?php echo date('n.j', strtotime($home_tournament['member_deadline'])); ?>
                    </span>
                    <span class="home-entry-name"><?php echo htmlspecialchars($home_tournament['event_name'], ENT_QUOTES, 'UTF-8'); ?> エントリー受付中</span>
                    <?php // 会員の締切日まで3日以内のときは「あと2日」「今日まで」を赤で添える ?>
                    <?php if ($home_tournament['deadline_soon']): ?>
                        <span class="home-entry-deadline<?php echo $home_tournament['deadline_soon'] ? ' is-soon' : ''; ?>"><?php echo htmlspecialchars($home_tournament['deadline_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                </span>
                <span class="home-entry-count" aria-label="開催まで<?php echo (int)$home_tournament['days_to_event']; ?>日">
                    <span class="home-entry-days"><?php echo (int)$home_tournament['days_to_event']; ?></span>
                    <span class="home-entry-days-label" aria-hidden="true">DAYS TO GO</span>
                </span>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
