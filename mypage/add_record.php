<?php
require_once __DIR__ . '/../back/session_bootstrap.php';
tobg_start_session();
// 1つ上の階層にあるデータベース接続ファイルを読み込む
require_once __DIR__ . '/../back/db_connect.php';

// 管理者のみアクセス可能
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    die("アクセス権限がありません。");
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>記録の追加 | TOBG</title>
    <!-- 1つ上の階層のcssフォルダを参照 -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;700;800;900&family=Zen+Kaku+Gothic+New:wght@400;500;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/theme.css?v=<?php echo (int)@filemtime(__DIR__ . '/../css/theme.css'); ?>">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#F4EEE4">
    <meta name="apple-mobile-web-app-title" content="TOBG-RUNNING">
    <link rel="apple-touch-icon" href="../icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" href="../icons/favicon.png">
    <link rel="stylesheet" href="../css/style.css?v=<?php echo (int)@filemtime(__DIR__ . '/../css/style.css'); ?>">
</head>
<body>
    <div class="container" style="max-width: 500px; margin-top: 50px;">
        <h2>記録を追加</h2>
        <form action="/TOBG/mypage/save_record.php" method="POST">
            <div class="form-group">
                <label>種目区分 (トラック/フィールド)</label>
                <input type="text" name="event_category" placeholder="トラック" required>
            </div>
            <div class="form-group">
                <label>種目名</label>
                <input type="text" name="event_type" placeholder="100m" required>
            </div>
            <div class="form-group">
                <label>表示順 (数値)</label>
                <input type="number" name="sort_order" required>
            </div>
            <div class="form-group">
                <label>順位</label>
                <input type="number" name="rank_num" required>
            </div>
            <div class="form-group">
                <label>選手名</label>
                <input type="text" name="name" required>
            </div>
            <div class="form-group">
                <label>記録</label>
                <input type="text" name="record_time" required>
            </div>
            <div class="form-group">
                <label>日付</label>
                <input type="date" name="match_date" required>
            </div>
            <div class="form-group">
                <label>大会名</label>
                <input type="text" name="match_name" required>
            </div>
            <button type="submit" style="margin-top: 15px;">登録する</button>
        </form>
    </div>
</body>
</html>