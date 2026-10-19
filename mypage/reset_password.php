<?php
require_once __DIR__ . '/../back/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/../back/db_connect.php';
require_once __DIR__ . '/../back/member_avatar_lib.php';
require_once __DIR__ . '/../back/password_reset_lib.php';

$error = '';
$token = trim((string)($_POST['token'] ?? $_GET['token'] ?? ''));
$token_row = null;

try {
    ensure_member_password_column($pdo);
    ensure_password_reset_tokens_table($pdo);
    $token_row = find_valid_password_reset_token($pdo, $token);
} catch (Throwable $e) {
    $error = 'データベースエラーが発生しました。';
}

if ($token_row && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = (string)($_POST['new_password'] ?? '');
    $confirm_password = (string)($_POST['confirm_password'] ?? '');

    if (mb_strlen($new_password) < MEMBER_PASSWORD_MIN_LENGTH) {
        $error = 'パスワードは' . MEMBER_PASSWORD_MIN_LENGTH . '文字以上で入力してください。';
    } else if ($new_password !== $confirm_password) {
        $error = '確認用パスワードが一致しません。';
    } else {
        try {
            reset_member_password($pdo, (int)$token_row['id'], (int)$token_row['member_id'], $new_password);
            $_SESSION['login_flash_alert'] = "パスワードを再設定しました！\n新しいパスワードでログインしてください。";
            header('Location: login.php');
            exit;
        } catch (Throwable $e) {
            $error = 'パスワードの再設定に失敗しました。';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <title>パスワード再設定 | TOBGマイページ</title>
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
        body {
            min-height: 100vh;
            background: var(--color-page-bg);
        }
        .login-wrap {
            min-height: calc(100vh - 84px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 16px;
        }
        .login-card {
            width: 100%;
            max-width: 440px;
            background: var(--color-card-bg);
            border-radius: var(--card-radius);
            box-shadow: var(--card-shadow);
            padding: 28px 22px 24px;
        }
        .login-title {
            font-size: 1.35rem;
            font-weight: 800;
            color: var(--color-text-primary);
            text-align: center;
            margin-bottom: 18px;
        }
        .login-note {
            color: var(--color-text-secondary);
            font-size: 0.92rem;
            text-align: center;
            margin-bottom: 18px;
        }
        .submit-btn {
            width: 100%;
            font-weight: 700;
            padding: 10px 14px;
        }
    </style>
</head>
<body class="theme-v2">
    <?php
    $shared_header = __DIR__ . '/../includes/site_header.php';
    if (file_exists($shared_header)) {
        require_once $shared_header;
    } else {
        echo '<div style="background:var(--color-header);color:#fff;padding:12px 16px;font-weight:700;">TEAM OFFICIAL</div>';
    }
    ?>

    <main class="login-wrap">
        <div class="login-card">
            <h1 class="login-title">パスワード再設定</h1>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger py-2 px-3" role="alert" style="font-size: 0.9rem;">
                    <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <?php if ($token_row): ?>
                <p class="login-note">新しいパスワードを入力してください（<?php echo MEMBER_PASSWORD_MIN_LENGTH; ?>文字以上）。</p>
                <form action="reset_password.php" method="POST">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="mb-3">
                        <label for="new_password" class="form-label fw-bold">新しいパスワード</label>
                        <div class="input-group">
                            <input type="password" id="new_password" name="new_password" class="form-control" minlength="<?php echo MEMBER_PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password" required>
                            <?php $password_toggle_target = 'new_password'; require __DIR__ . '/../includes/password_toggle_button.php'; ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label fw-bold">新しいパスワード（確認）</label>
                        <div class="input-group">
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" minlength="<?php echo MEMBER_PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password" required>
                            <?php $password_toggle_target = 'confirm_password'; require __DIR__ . '/../includes/password_toggle_button.php'; ?>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary submit-btn">パスワードを再設定する</button>
                </form>
            <?php elseif ($error === ''): ?>
                <p class="login-note">このリンクは無効か、有効期限が切れています。<br>ログイン画面からもう一度パスワードリセットを行ってください。</p>
            <?php endif; ?>

            <div class="text-center mt-3">
                <a href="login.php">ログイン画面へ戻る</a>
            </div>
        </div>
    </main>
    <?php require __DIR__ . '/../includes/password_toggle_script.php'; ?>
</body>
</html>
