<?php
require_once __DIR__ . '/../back/session_bootstrap.php';
if (session_status() === PHP_SESSION_NONE) {
    tobg_start_session();
}
$match_title = trim((string)($_GET['match_title'] ?? ''));
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>申し込み完了 | TOBG</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;700;800;900&family=Zen+Kaku+Gothic+New:wght@400;500;700;900&display=swap" rel="stylesheet">
    <link href="../css/theme.css?v=<?php echo (int)@filemtime(__DIR__ . '/../css/theme.css'); ?>" rel="stylesheet">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#F4EEE4">
    <meta name="apple-mobile-web-app-title" content="TOBG-RUNNING">
    <link rel="apple-touch-icon" href="../icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" href="../icons/favicon.png">
    <style>
        body { background: var(--color-page-bg); }
        .page-wrap { max-width: var(--content-max-width); margin: 0 auto; padding: 24px var(--page-gutter) 40px; }
        .complete-card { border: 0; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08); }
        .complete-title { font-size: 1.8rem; font-weight: 800; color: #0f172a; }
        .complete-sub { color: #475569; line-height: 1.8; }
        .action-row { display: flex; flex-wrap: wrap; gap: 10px; justify-content: flex-end; }
    </style>
</head>
<body>
<?php
$shared_header = __DIR__ . '/../includes/site_header.php';
if (file_exists($shared_header)) {
    require_once $shared_header;
} else {
    echo '<div style="background:var(--color-header);color:#fff;padding:12px 16px;font-weight:700;">TEAM OFFICIAL</div>';
}
?>

<div class="page-wrap">
    <div class="card complete-card">
        <div class="card-body p-4 p-md-5">
            <h1 class="complete-title mb-3">申し込みが完了しました</h1>

            <p class="complete-sub mb-2">ご応募ありがとうございました。管理者側で確認後、ステータスが更新されます。</p>

            <?php if ($match_title !== ''): ?>
                <p class="complete-sub mb-4">対象大会: <strong><?php echo htmlspecialchars($match_title, ENT_QUOTES, 'UTF-8'); ?></strong></p>
            <?php else: ?>
                <p class="complete-sub mb-4">対象大会: <strong>大会名を確認中</strong></p>
            <?php endif; ?>

            <div class="action-row">
                <a href="club.php?tab=races" class="btn btn-outline-secondary">大会一覧へ戻る</a>
                <a href="../mypage/front_login.php#entry-history" class="btn btn-primary">マイページの応募履歴へ</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
