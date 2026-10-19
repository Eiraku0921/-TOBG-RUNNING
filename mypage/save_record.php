<?php
require_once __DIR__ . '/../back/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/../back/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
    try {
        $stmt = $pdo->prepare("INSERT INTO records 
            (event_category, event_type, sort_order, rank_num, name, record_time, match_date, match_name) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->execute([
            $_POST['event_category'],
            $_POST['event_type'],
            $_POST['sort_order'],
            $_POST['rank_num'],
            $_POST['name'],
            $_POST['record_time'],
            $_POST['match_date'],
            $_POST['match_name']
        ]);
        
        echo "記録を登録しました！<br><a href='/TOBG/front/records.php'>記録一覧へ戻る</a>";
    } catch (PDOException $e) {
        echo "エラー: " . $e->getMessage();
    }
} else {
    echo "不正なアクセスです。";
}
?>