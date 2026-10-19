<?php
// NEXT PR の見本の画面（未ログインの人向け。mypage/next_pr.php から読み込む）。
// 本物と同じ見た目（写真のカード・目標・記録一覧）で、数字はすべて見本。入力のボタン（鉛筆・カメラ・種目の追加）は出さない。
// 使う変数: $this_year
$demo_photo = next_pr_default_photo_url();
?>
<section class="np-demo-intro" aria-labelledby="npDemoTitle">
    <p class="np-demo-badge">見本</p>
    <h2 class="np-demo-title" id="npDemoTitle">自己ベストと目標を、ひと目で。</h2>
    <p class="np-demo-text">ログインすると、陸上や筋トレの自己ベスト・シーズンベストを記録し、目標までの進み具合を確かめられます。下は見本の画面です。</p>
    <div class="np-demo-actions">
        <a class="btn btn-primary" href="login.php">ログインして使う</a>
        <a class="btn btn-outline-secondary" href="register.php">新規会員登録</a>
    </div>
</section>

<!-- 種目（見本。押しても切り替わらない） -->
<div class="event-tabs" aria-hidden="true">
    <span class="event-tab is-active">100m</span>
    <span class="event-tab">5000m</span>
    <span class="event-tab">ベンチプレス</span>
</div>

<!-- 自己ベストのカード（本物と同じ写真のカード。目標も中に入る） -->
<section class="pr-card np-photo-card is-sample" aria-label="自己ベストの見本" style="--np-photo: url('../<?php echo h($demo_photo); ?>')">
    <div class="best-head">
        <div class="best-tabs" aria-hidden="true">
            <span class="best-tab is-active">自己ベスト</span>
            <span class="best-tab">シーズンベスト</span>
        </div>
    </div>
    <div class="best-value">11.82<span class="best-value-unit">秒</span></div>
    <div class="best-meta"><?php echo $this_year; ?>年6月8日 県選手権（+1.2m/s・公認）</div>
    <div class="goal-grid">
        <section class="pr-card" aria-label="シーズン目標の見本">
            <div class="goal-label">シーズン目標 <?php echo $this_year; ?>年</div>
            <div class="goal-value">11.60秒</div>
            <div class="goal-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="56" aria-label="目標の達成度"><div class="goal-progress-fill" style="width: 56%;"></div></div>
        </section>
        <section class="pr-card" aria-label="長期目標の見本">
            <div class="goal-label">長期目標 〜<?php echo $this_year + 2; ?>年</div>
            <div class="goal-value">11.30秒</div>
            <div class="goal-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="35" aria-label="目標の達成度"><div class="goal-progress-fill" style="width: 35%;"></div></div>
        </section>
    </div>
</section>

<!-- 記録一覧（見本） -->
<section class="pr-card" aria-labelledby="npDemoRecordsTitle">
    <h2 class="section-title" id="npDemoRecordsTitle">記録一覧</h2>
    <p class="np-demo-note">練習日記で「大会」を選んで記録すると、ここに自動で並びます。</p>
    <ul class="result-list">
        <li class="result-item">
            <span class="result-date"><?php echo $this_year; ?>/6/8</span>
            <span class="result-title">県選手権<span class="result-notes">+1.2m/s・公認</span></span>
            <span class="result-value">11.82秒 <span class="result-badge result-badge-pb">自己ベスト</span></span>
        </li>
        <li class="result-item">
            <span class="result-date"><?php echo $this_year; ?>/5/11</span>
            <span class="result-title">春季記録会<span class="result-notes">+0.4m/s・公認</span></span>
            <span class="result-value">11.95秒</span>
        </li>
        <li class="result-item">
            <span class="result-date"><?php echo $this_year - 1; ?>/9/21</span>
            <span class="result-title">秋季記録会<span class="result-notes">-0.6m/s・公認</span></span>
            <span class="result-value">12.04秒</span>
        </li>
    </ul>
</section>

<section class="np-demo-intro is-bottom" aria-label="ログインの案内">
    <p class="np-demo-text">自分の記録で使ってみましょう。</p>
    <div class="np-demo-actions">
        <a class="btn btn-primary" href="login.php">ログインして使う</a>
        <a class="btn btn-outline-secondary" href="register.php">新規会員登録</a>
    </div>
</section>
