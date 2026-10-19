<?php
// ホームの次の合同練習（NEXT SESSION）。ログイン中と未ログインで共通（未ログインは「すべての人に公開」の募集だけ）。
// 使う変数: $home_next_sessions, $home_session_viewer, $is_logged_in
?>
<?php if (!empty($home_next_sessions)): ?>
    <?php // 次の合同練習（クラブ画面と同じカード）。「予定一覧 →」でクラブの合同練習へ。ログイン中は右の補助欄にも出るので、補助欄が出る幅では本文から隠す ?>
    <div<?php echo $is_logged_in ? ' class="home-rail-dup"' : ''; ?>>
    <div class="home-section-head">
        <h2 class="home-section-eyebrow">NEXT SESSION <span>/ 合同練習</span></h2>
        <a class="home-pill-link" href="front/club.php?tab=sessions">予定一覧 →</a>
    </div>
    <?php
    $sessions = $home_next_sessions;
    $viewer = $home_session_viewer;
    $session_can_post = false;
    $session_base = '';
    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $weekdays = ['日', '月', '火', '水', '木', '金', '土'];
    $page_return_to = (string)($_SERVER['REQUEST_URI'] ?? '');
    require __DIR__ . '/club_sessions.php';
    ?>
    </div>
<?php endif; ?>
