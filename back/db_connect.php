<?php
// ロリポップのデータベース接続設定
// 接続先とパスワードは back/db_config.php に書く（Git に上げないファイル。見本は db_config.example.php）
$db_config_file = __DIR__ . '/db_config.php';
if (!is_file($db_config_file)) {
    error_log('db_connect: back/db_config.php がありません');
    die('データベースの設定ファイルがありません。管理者にお問い合わせください。');
}
$db_config = require $db_config_file;
$host = (string)$db_config['host'];
$dbname = (string)$db_config['dbname'];
$username = (string)$db_config['username'];
$password = (string)$db_config['password'];
unset($db_config, $db_config_file);


// 文字コードの設定（絵文字なども扱えるutf8mb4）
$charset = 'utf8mb4';

// PDO接続のオプション設定
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // エラー時に例外を投げる
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // データを連想配列で受け取る
    PDO::ATTR_EMULATE_PREPARES   => false,                  // SQLインジェクション対策（安全のため）
];

// データベースへ接続実行
try {
    $dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (\PDOException $e) {
    // 接続エラーが起きた場合、プログラムを止める。エラーの中身（接続先やユーザー名が入る）は画面に出さずログにだけ残す
    error_log('db_connect: ' . $e->getMessage());
    die('データベース接続エラーが発生しました。時間をおいて再度お試しください。');
}
unset($password);

// 日時のズレ防止（サーバー側のタイムゾーン設定に依存しないようにする）
// 権限などの理由でこの設定に失敗しても、接続自体は継続させる
try {
    $pdo->exec("SET time_zone = '+09:00'");
} catch (\PDOException $e) {
    // タイムゾーン設定に失敗しても致命的エラーにはしない
}