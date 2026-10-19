<?php
// ログアウト処理用のファイル

require_once __DIR__ . '/../back/session_bootstrap.php';
require_once __DIR__ . '/../back/remember_login_lib.php';

// セッションを開始する
tobg_start_session();

// この端末の「ログイン状態を保持する」トークンを無効にする（クッキーも消す）
if (!empty($_COOKIE[REMEMBER_LOGIN_COOKIE])) {
    try {
        require_once __DIR__ . '/../back/db_connect.php';
        forget_current_remember_token($GLOBALS['pdo'] ?? $pdo);
    } catch (Throwable $e) {
        clear_remember_cookie();
    }
}

// セッション変数をすべて解除する
$_SESSION = array();

// セッションを破棄する
session_destroy();

// ログイン画面へリダイレクト
header('Location: login.php');
exit();
