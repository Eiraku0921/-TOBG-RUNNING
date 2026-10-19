<?php
require_once __DIR__ . '/../back/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/../back/db_connect.php';
require_once __DIR__ . '/../back/member_avatar_lib.php';
require_once __DIR__ . '/../back/password_reset_lib.php';
require_once __DIR__ . '/../back/login_throttle_lib.php';
require_once __DIR__ . '/../back/remember_login_lib.php';

try {
    ensure_member_avatar_column($pdo);
} catch (Throwable $e) {
    // 列未作成時はログイン機能を優先
}

try {
    ensure_member_email_column($pdo);
    ensure_member_password_column($pdo);
    ensure_password_reset_tokens_table($pdo);
    ensure_login_attempts_table($pdo);
} catch (Throwable $e) {
    // テーブル作成に失敗してもログイン画面の表示は継続
}

$error = '';
$reset_error = '';
$register_notice_password = isset($_SESSION['register_notice_password']) ? (string)$_SESSION['register_notice_password'] : '';
unset($_SESSION['register_notice_password']);
$login_flash_alert = isset($_SESSION['login_flash_alert']) ? (string)$_SESSION['login_flash_alert'] : '';
unset($_SESSION['login_flash_alert']);
$post_diary_flag = (($_POST['post_diary'] ?? $_GET['post_diary'] ?? '') === '1');
// ホームのマイグループ（未ログインの人への紹介）から来たとき、ログイン後にマイグループのその画面（参加・作成）を開く
$open_group_pane = (string)($_POST['open_group'] ?? $_GET['open_group'] ?? '');
if (!in_array($open_group_pane, ['list', 'join', 'create'], true)) {
    $open_group_pane = '';
}
$action = (string)($_POST['action'] ?? 'login');
$reset_pending_member_id = (int)($_SESSION['password_reset_pending_member_id'] ?? 0);
$reset_identifier_value = '';
$reset_sent_message = "メールアドレスにパスワードリセットのリンクを送りました！！\n届かない場合は迷惑メールフォルダに入っているかもしれません。ご確認ください。";

$redirect_self = function () use ($post_diary_flag): void {
    header('Location: login.php' . ($post_diary_flag ? '?post_diary=1' : ''));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    $identifier = trim((string)($_POST['identifier'] ?? ''));
    $input_password = trim((string)($_POST['password'] ?? ''));

    if ($identifier !== '' && $input_password !== '') {
        try {
            if (is_login_throttled($pdo, LOGIN_THROTTLE_SCOPE_LOGIN, $identifier)) {
                throw new RuntimeException(login_throttle_message());
            }

            $user = find_member_by_login_identifier($pdo, $identifier);
            // password 設定済みならそれで、未設定なら初期パスワード（生年月日）で照合
            $is_authenticated = $user && member_password_matches($user, $input_password);

            if ($is_authenticated) {
                clear_login_failures($pdo, LOGIN_THROTTLE_SCOPE_LOGIN, $identifier);

                // セッションの作り方（管理者の扱いを含む）は自動ログインと共通
                establish_member_session($user);

                // ログイン状態を保持する（ログアウトすれば消える）。
                // スマホ・タブレットは常に90日、PC はチェックしたときだけ2日。PC でチェックしなければセッションの3時間で切れる
                $remember_expires_at = remember_login_expires_at(($_POST['remember'] ?? '') === '1');
                if ($remember_expires_at !== null) {
                    try {
                        issue_remember_token($pdo, (int)$user['id'], $remember_expires_at);
                    } catch (Throwable $e) {
                        // 保持トークンの発行に失敗しても、今回のログインは成功させる
                        error_log('issue_remember_token failed: ' . $e->getMessage());
                    }
                }

                // 招待リンクから来ていたら、ログインした流れでそのグループに参加させ、ホームのそのグループのタイムラインへ
                $pending_group_code = (string)($_SESSION['pending_group_join_code'] ?? '');
                unset($_SESSION['pending_group_join_code']);
                if ($pending_group_code !== '') {
                    require_once __DIR__ . '/../back/member_group_lib.php';
                    try {
                        ensure_member_group_tables($pdo);
                        $joined_group_name = join_member_group_by_code($pdo, (int)$user['id'], $pending_group_code);
                        $_SESSION['training_flash'] = '「' . $joined_group_name . '」に参加しました。';
                    } catch (PDOException $e) {
                        error_log('join group after login failed: ' . $e->getMessage());
                        $_SESSION['training_error'] = 'グループへの参加に失敗しました。マイグループの「参加する」から、もう一度お試しください。';
                    } catch (RuntimeException $e) {
                        $_SESSION['training_error'] = $e->getMessage();
                    }
                    header('Location: ../front/training_diary.php'); // グループはみんなのページで扱う（知らせはトーストで出る）
                    exit;
                }

                if ($open_group_pane !== '') {
                    header('Location: ../front/training_diary.php?open_group=' . $open_group_pane);
                    exit;
                }

                header('Location: front_login.php' . ($post_diary_flag ? '?post_diary=1#training-diary' : ''));
                exit;
            } else {
                record_login_failure($pdo, LOGIN_THROTTLE_SCOPE_LOGIN, $identifier);
                $error = '名前・メールアドレスまたはパスワードが間違っています。';
            }
        } catch (PDOException $e) {
            // PDOException は RuntimeException の子クラスなので先に捕まえる
            $error = 'データベースエラーが発生しました。';
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    } else {
        $error = 'すべての項目を入力してください。';
    }
} else if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reset_request') {
    $reset_identifier_value = trim((string)($_POST['reset_identifier'] ?? ''));
    unset($_SESSION['password_reset_pending_member_id']);
    $reset_pending_member_id = 0;

    if ($reset_identifier_value === '') {
        $reset_error = '名前またはメールアドレスを入力してください。';
    } else {
        try {
            $member = find_member_by_login_identifier($pdo, $reset_identifier_value);
            $member_email = $member ? trim((string)($member['email'] ?? '')) : '';

            if (!$member && strpos($reset_identifier_value, '@') !== false) {
                $reset_error = 'このメールアドレスは登録されていません。メールアドレスが未登録の方は、名前（フルネーム・スペースなし）を入力してください。';
            } else if (!$member) {
                $reset_error = '該当する会員が見つかりませんでした。登録済みの名前（スペースなし）で入力してください。';
            } else if ($member_email === '') {
                // メール未登録: 本人確認のうえメールアドレスを登録してもらう
                $_SESSION['password_reset_pending_member_id'] = (int)$member['id'];
                $reset_pending_member_id = (int)$member['id'];
            } else {
                $token = create_password_reset_token($pdo, (int)$member['id']);
                if (send_password_reset_email($member_email, (string)$member['name'], $token)) {
                    $_SESSION['login_flash_alert'] = $reset_sent_message;
                    $redirect_self();
                }
                $reset_error = 'メールの送信に失敗しました。時間をおいて再度お試しください。';
            }
        } catch (Throwable $e) {
            $reset_error = 'パスワードリセットの受付に失敗しました。';
        }
    }
} else if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reset_register_email') {
    $reset_email = trim((string)($_POST['reset_email'] ?? ''));
    $reset_birth_date = trim((string)($_POST['reset_birth_date'] ?? ''));

    try {
        $member = null;
        if ($reset_pending_member_id > 0) {
            $stmt = $pdo->prepare('SELECT * FROM members WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $reset_pending_member_id]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $birth_digits = str_replace('-', '', $reset_birth_date);
        $birthday_code = $member ? (string)$member['birthday_code'] : '';
        $reset_throttle_key = 'member:' . $reset_pending_member_id;

        if (!$member || trim((string)($member['email'] ?? '')) !== '') {
            // 手続き中に別経路でメールが登録された場合も上書きさせない
            unset($_SESSION['password_reset_pending_member_id']);
            $reset_pending_member_id = 0;
            $reset_error = 'もう一度、名前を入力してやり直してください。';
        } else if (is_login_throttled($pdo, LOGIN_THROTTLE_SCOPE_RESET_VERIFY, $reset_throttle_key)) {
            $reset_error = login_throttle_message();
        } else if (!filter_var($reset_email, FILTER_VALIDATE_EMAIL)) {
            $reset_error = 'メールアドレスの形式が不正です。';
        } else if (!preg_match('/^\d{8}$/', $birth_digits)) {
            $reset_error = '生年月日を入力してください。';
        } else if ($birthday_code === ''
            || !hash_equals($birthday_code, substr($birth_digits, -strlen($birthday_code)))) {
            // 本人確認: 登録時の birthday_code は生年月日の末尾桁
            record_login_failure($pdo, LOGIN_THROTTLE_SCOPE_RESET_VERIFY, $reset_throttle_key);
            $reset_error = '生年月日が登録内容と一致しません。';
        } else if (is_email_used_by_other_member($pdo, $reset_email, (int)$member['id'])) {
            $reset_error = 'このメールアドレスは他の会員が使用しています。';
        } else {
            $update = $pdo->prepare('UPDATE members SET email = :email WHERE id = :id');
            $update->execute([':email' => $reset_email, ':id' => (int)$member['id']]);

            clear_login_failures($pdo, LOGIN_THROTTLE_SCOPE_RESET_VERIFY, $reset_throttle_key);
            $token = create_password_reset_token($pdo, (int)$member['id']);
            unset($_SESSION['password_reset_pending_member_id']);

            if (send_password_reset_email($reset_email, (string)$member['name'], $token)) {
                $_SESSION['login_flash_alert'] = "メールアドレスを登録しました。\n" . $reset_sent_message;
            } else {
                $_SESSION['login_flash_alert'] = "メールアドレスを登録しましたが、メールの送信に失敗しました。\n時間をおいて再度パスワードリセットをお試しください。";
            }
            $redirect_self();
        }
    } catch (Throwable $e) {
        $reset_error = 'メールアドレスの登録に失敗しました。';
    }
}

$reset_panel_open = ($reset_error !== '' || $reset_pending_member_id > 0);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ログイン | TOBGマイページ</title>
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
        .reset-panel {
            margin-top: 18px;
            padding-top: 16px;
            border-top: 1px solid var(--color-border);
        }
        .reset-title {
            font-size: 1rem;
            font-weight: 800;
            color: var(--color-text-primary);
            margin-bottom: 8px;
        }
        .reset-note {
            color: var(--color-text-secondary);
            font-size: 0.85rem;
            margin-bottom: 12px;
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
            <h1 class="login-title">メンバーログイン</h1>
            <p class="login-note">名前またはメールアドレスと、パスワードを入力してください。</p>

            <?php if ($error): ?>
                <div class="alert alert-danger py-2 px-3" role="alert" style="font-size: 0.9rem;">
                    <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST" class="mt-3">
                <input type="hidden" name="action" value="login">
                <?php if ($post_diary_flag): ?>
                    <input type="hidden" name="post_diary" value="1">
                <?php endif; ?>
                <?php if ($open_group_pane !== ''): ?>
                    <input type="hidden" name="open_group" value="<?php echo htmlspecialchars($open_group_pane, ENT_QUOTES, 'UTF-8'); ?>">
                <?php endif; ?>
                <div class="mb-3">
                    <label for="identifier" class="form-label fw-bold">名前 または メールアドレス</label>
                    <input type="text" id="identifier" name="identifier" class="form-control" placeholder="例: 藤蔭太郎 / you@example.com" autocomplete="username" required>
                    <div class="form-text">名前はフルネーム・スペースなしで入力してください。</div>
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label fw-bold">パスワード</label>
                    <div class="input-group">
                        <input type="password" id="password" name="password" class="form-control" autocomplete="current-password" required>
                        <?php $password_toggle_target = 'password'; require __DIR__ . '/../includes/password_toggle_button.php'; ?>
                    </div>
                </div>

                <?php if (!remember_login_is_mobile_device()): ?>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1">
                        <label class="form-check-label" for="remember">ログイン状態を保持する（<?php echo REMEMBER_LOGIN_DAYS_PC; ?>日間）</label>
                        <div class="form-text">共用のパソコンではチェックしないでください。</div>
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary submit-btn">ログインする</button>
            </form>

            <div class="text-center mt-3">
                <a href="register.php<?php echo $post_diary_flag ? '?post_diary=1' : ''; ?>">新規会員設定はこちら</a>
            </div>
            <div class="text-center mt-2">
                <a href="#reset-panel" data-bs-toggle="collapse" role="button" aria-expanded="<?php echo $reset_panel_open ? 'true' : 'false'; ?>" aria-controls="reset-panel" style="font-size: 0.9rem;">パスワードを忘れた方はこちら</a>
            </div>

            <div id="reset-panel" class="collapse<?php echo $reset_panel_open ? ' show' : ''; ?>">
                <div class="reset-panel">
                    <h2 class="reset-title">パスワードリセット</h2>

                    <?php if ($reset_error !== ''): ?>
                        <div class="alert alert-danger py-2 px-3" role="alert" style="font-size: 0.9rem;">
                            <?php echo htmlspecialchars($reset_error, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($reset_pending_member_id > 0): ?>
                        <p class="reset-note">メールアドレスが未登録です。<br>リセット用リンクを受け取るメールアドレスを登録してください。<br>本人確認のため、生年月日も入力してください。</p>
                        <form action="login.php" method="POST">
                            <input type="hidden" name="action" value="reset_register_email">
                            <?php if ($post_diary_flag): ?>
                                <input type="hidden" name="post_diary" value="1">
                            <?php endif; ?>
                            <div class="mb-3">
                                <label for="reset_email" class="form-label fw-bold">メールアドレス</label>
                                <input type="email" id="reset_email" name="reset_email" class="form-control" placeholder="you@example.com" autocomplete="email" required>
                            </div>
                            <div class="mb-3">
                                <label for="reset_birth_date" class="form-label fw-bold">生年月日</label>
                                <input type="date" id="reset_birth_date" name="reset_birth_date" class="form-control" required>
                            </div>
                            <button type="submit" class="btn btn-primary submit-btn">登録してリセットリンクを送信</button>
                        </form>
                    <?php else: ?>
                        <p class="reset-note">登録済みのメールアドレスにパスワード再設定用のリンクをお送りします。<br>メールアドレスが未登録の方は、名前を入力してください。</p>
                        <form action="login.php" method="POST">
                            <input type="hidden" name="action" value="reset_request">
                            <?php if ($post_diary_flag): ?>
                                <input type="hidden" name="post_diary" value="1">
                            <?php endif; ?>
                            <div class="mb-3">
                                <label for="reset_identifier" class="form-label fw-bold">名前 または メールアドレス</label>
                                <input type="text" id="reset_identifier" name="reset_identifier" class="form-control" value="<?php echo htmlspecialchars($reset_identifier_value, ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <button type="submit" class="btn btn-primary submit-btn">リセットリンクを送信</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../includes/password_toggle_script.php'; ?>
    <?php if ($register_notice_password !== ''): ?>
        <script>
            alert('あなたのパスワードは <?php echo htmlspecialchars($register_notice_password, ENT_QUOTES, 'UTF-8'); ?> です！');
        </script>
    <?php endif; ?>
    <?php if ($login_flash_alert !== ''): ?>
        <script>
            alert(<?php echo json_encode($login_flash_alert, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
        </script>
    <?php endif; ?>
</body>
</html>
