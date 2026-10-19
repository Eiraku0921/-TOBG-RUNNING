<?php

date_default_timezone_set('Asia/Tokyo');

if (!function_exists('tobg_start_session')) {
    function tobg_start_session(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $lifetime_seconds = 60 * 60 * 3;
            $is_https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
                || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
                || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
            ini_set('session.gc_maxlifetime', (string)$lifetime_seconds);
            session_set_cookie_params([
                'lifetime' => $lifetime_seconds,
                'path' => '/',
                'secure' => $is_https,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }

        // セッション（3時間）が切れていても「ログイン状態を保持する」のクッキーがあれば自動でログインする
        // （スマホ・タブレットは90日、PC はチェックしたときだけ2日）
        if (empty($_SESSION['user_id']) && !empty($_COOKIE['tobg_remember'])) {
            require_once __DIR__ . '/remember_login_lib.php';
            try_remember_login();
        } elseif (!empty($_SESSION['user_id'])) {
            // 権限は管理者があとから変えることがあるので、毎回 DB の最新を使う
            require_once __DIR__ . '/remember_login_lib.php';
            refresh_member_session();
        }
    }
}
