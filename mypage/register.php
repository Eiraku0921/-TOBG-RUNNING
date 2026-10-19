<?php
require_once __DIR__ . '/../back/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/../back/db_connect.php';
require_once __DIR__ . '/../back/password_reset_lib.php';

$error = '';
$form_name = '';
$form_birth_date = '';
$form_email = '';
$post_diary_flag = (($_POST['post_diary'] ?? $_GET['post_diary'] ?? '') === '1');

function find_first_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $birth_date = trim((string)($_POST['birth_date'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    // パスワード欄はメールアドレス入力時だけ表示されるので、メール未入力なら無視する
    $new_password = $email !== '' ? (string)($_POST['password'] ?? '') : '';
    $birth_digits = str_replace('-', '', $birth_date);
    $form_name = $name;
    $form_birth_date = $birth_date;
    $form_email = $email;

    if ($name === '' || $birth_date === '') {
        $error = '名前と生年月日を入力してください。';
    } else if (preg_match('/\s/u', $name)) {
        $error = '名前はスペースなしで入力してください。';
    } else if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) {
        $error = '生年月日の形式が不正です。';
    } else if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'メールアドレスの形式が不正です。';
    } else if ($new_password !== '' && mb_strlen($new_password) < MEMBER_PASSWORD_MIN_LENGTH) {
        $error = 'パスワードは' . MEMBER_PASSWORD_MIN_LENGTH . '文字以上で入力してください。';
    } else if ($new_password !== '' && $new_password === $birth_digits) {
        $error = '生年月日をそのままパスワードにすることはできません。';
    } else {
        try {
            ensure_member_password_column($pdo);

            $exists_stmt = $pdo->prepare('SELECT id FROM members WHERE name = :name LIMIT 1');
            $exists_stmt->execute([':name' => $name]);
            $exists = $exists_stmt->fetch(PDO::FETCH_ASSOC);

            if ($exists) {
                $error = '同じ名前の会員がすでに存在します。ログイン画面からログインしてください。';
            } else if ($email !== '' && is_email_used_by_other_member($pdo, $email, 0)) {
                $error = 'このメールアドレスは他の会員が使用しています。';
            } else {
                $columns_stmt = $pdo->query('SHOW COLUMNS FROM members');
                $member_columns = array_column($columns_stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
                $jaaf_id_col = find_first_column($member_columns, ['jaaf_id', 'jaafid', 'jaaf_no']);
                $number_plate_col = find_first_column($member_columns, ['number_plate', 'bib_number', 'zekken_no']);
                $email_col = find_first_column($member_columns, ['email', 'email_address', 'mail']);

                // 初期パスワード（ログインで入力する値）は生年月日の下4桁（MMDD）
                $password_code = substr($birth_digits, -4);

                $insert_columns = ['name', 'birthday_code'];
                $insert_placeholders = [':name', ':birthday_code'];
                $params = [
                    ':name' => $name,
                    ':birthday_code' => $password_code,
                ];

                if ($jaaf_id_col !== null) {
                    $insert_columns[] = $jaaf_id_col;
                    $insert_placeholders[] = ':jaaf_id';
                    $params[':jaaf_id'] = '0000';
                }

                if ($number_plate_col !== null) {
                    $insert_columns[] = $number_plate_col;
                    $insert_placeholders[] = ':number_plate';
                    $params[':number_plate'] = '0000';
                }

                if ($email_col !== null && $email !== '') {
                    $insert_columns[] = $email_col;
                    $insert_placeholders[] = ':email';
                    $params[':email'] = $email;
                }

                // パスワードを設定した場合は password で照合され、生年月日ではログインできなくなる
                if ($new_password !== '') {
                    $insert_columns[] = 'password';
                    $insert_placeholders[] = ':password';
                    $params[':password'] = password_hash($new_password, PASSWORD_DEFAULT);
                }

                if (in_array('role', $member_columns, true)) {
                    $insert_columns[] = 'role';
                    $insert_placeholders[] = ':role';
                    $params[':role'] = 'member';
                }

                $sql = 'INSERT INTO members (' . implode(', ', $insert_columns) . ') VALUES (' . implode(', ', $insert_placeholders) . ')';
                $insert_stmt = $pdo->prepare($sql);
                $insert_stmt->execute($params);

                // 管理者に知らせる（ベルの通知とプッシュ通知）。知らせられなくても登録は完了させる
                try {
                    require_once __DIR__ . '/../back/notification_lib.php';
                    notify_admins_of_new_member($pdo, (int)$pdo->lastInsertId(), $name);
                } catch (Throwable $notify_error) {
                    error_log('register notify failed: ' . $notify_error->getMessage());
                }

                if ($new_password !== '') {
                    $_SESSION['login_flash_alert'] = "会員登録が完了しました！\n設定したパスワードでログインしてください。";
                } else {
                    $_SESSION['register_notice_password'] = $password_code;
                }
                header('Location: login.php' . ($post_diary_flag ? '?post_diary=1' : ''));
                exit;
            }
        } catch (PDOException $e) {
            $error = '新規登録に失敗しました。';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>新規会員設定 | TOBGマイページ</title>
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
        .register-wrap {
            min-height: calc(100vh - 84px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 16px;
        }
        .register-card {
            width: 100%;
            max-width: 460px;
            background: var(--color-card-bg);
            border-radius: var(--card-radius);
            box-shadow: var(--card-shadow);
            padding: 28px 22px 24px;
        }
        .register-title {
            font-size: 1.35rem;
            font-weight: 800;
            color: var(--color-text-primary);
            text-align: center;
            margin-bottom: 18px;
        }
        .register-note {
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
        .email-label-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }
        .optional-badge {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--color-text-secondary);
            background: var(--color-pill-bg);
            border-radius: 999px;
            padding: 2px 10px;
        }
        .help-circle-btn {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            border: 1px solid var(--color-border);
            background: var(--color-card-bg);
            color: var(--color-text-secondary);
            font-size: 0.8rem;
            font-weight: 800;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            cursor: pointer;
        }
        .help-circle-btn:hover,
        .help-circle-btn:focus-visible {
            background: var(--color-pill-bg);
            color: var(--color-text-primary);
        }
        .merit-list {
            list-style: none;
            padding: 0;
            margin: 0 0 16px;
        }
        .merit-list li {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 16px;
        }
        .merit-list li:last-child {
            margin-bottom: 0;
        }
        .merit-icon {
            flex-shrink: 0;
            width: 20px;
            height: 20px;
            margin-top: 2px;
            color: var(--color-text-primary);
        }
        .merit-list strong {
            display: block;
            color: var(--color-text-primary);
        }
        .merit-list li span {
            display: block;
            color: var(--color-text-secondary);
            font-size: 0.9rem;
        }
        .merit-note {
            color: var(--color-text-muted);
            font-size: 0.82rem;
            border-top: 1px solid var(--color-border);
            padding-top: 12px;
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

<main class="register-wrap">
    <div class="register-card">
        <h1 class="register-title">新規会員設定</h1>
        <p class="register-note">陸連登録なしの方もご利用できます！</p>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger py-2 px-3" role="alert" style="font-size: 0.9rem;">
                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form action="register.php" method="POST" class="mt-3" id="register-form">
            <?php if ($post_diary_flag): ?>
                <input type="hidden" name="post_diary" value="1">
            <?php endif; ?>
            <div class="mb-3">
                <label for="name" class="form-label fw-bold">名前（スペースなし）</label>
                <input type="text" id="name" name="name" class="form-control" value="<?php echo htmlspecialchars($form_name, ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>

            <div class="mb-3">
                <label for="birth_date" class="form-label fw-bold">生年月日</label>
                <input type="date" id="birth_date" name="birth_date" class="form-control" value="<?php echo htmlspecialchars($form_birth_date, ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>

            <div class="mb-3">
                <div class="email-label-row">
                    <label for="email" class="form-label fw-bold mb-0">メールアドレス</label>
                    <span class="optional-badge">任意</span>
                    <button type="button" class="help-circle-btn" data-bs-toggle="modal" data-bs-target="#emailMeritModal" aria-label="メールアドレスを登録するメリット">?</button>
                </div>
                <input type="email" id="email" name="email" class="form-control" placeholder="you@example.com" value="<?php echo htmlspecialchars($form_email, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-text">未入力でも登録できます。<br>あとからマイページでも登録できます。</div>
            </div>

            <div class="mb-3" id="register-password-group" hidden>
                <div class="email-label-row">
                    <label for="password" class="form-label fw-bold mb-0">パスワード</label>
                    <span class="optional-badge">任意</span>
                </div>
                <div class="input-group">
                    <input type="password" id="password" name="password" class="form-control" minlength="<?php echo MEMBER_PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password" disabled>
                    <?php $password_toggle_target = 'password'; require __DIR__ . '/../includes/password_toggle_button.php'; ?>
                </div>
                <div class="form-text">未入力の場合は、生年月日がパスワードになります。<br>設定した場合は、生年月日ではログインできなくなります。<br><?php echo MEMBER_PASSWORD_MIN_LENGTH; ?>文字以上で入力してください。</div>
            </div>

            <button type="submit" class="btn btn-primary submit-btn">送信</button>
        </form>

        <div class="text-center mt-3">
            <a href="login.php">ログイン画面へ戻る</a>
        </div>
    </div>
</main>

<div class="modal fade" id="emailMeritModal" tabindex="-1" aria-labelledby="emailMeritModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5 fw-bold" id="emailMeritModalLabel">メールアドレスを登録するメリット</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
            </div>
            <div class="modal-body">
                <ul class="merit-list">
                    <li>
                        <svg class="merit-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/><circle cx="12" cy="16" r="1"/></svg>
                        <div>
                            <strong>パスワードを忘れても安心</strong>
                            <span>登録したアドレスに再設定用のリンクが届き、自分で再設定できます。</span>
                        </div>
                    </li>
                    <li>
                        <svg class="merit-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                        <div>
                            <strong>お知らせをメールで受け取れる</strong>
                            <span>大会情報やクラブからの連絡を、サイトを開かなくても確認できます。</span>
                        </div>
                    </li>
                    <li>
                        <svg class="merit-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        <div>
                            <strong>アカウントを守りやすくなる</strong>
                            <span>生年月日とは別のパスワードを設定して、なりすましを防げます。</span>
                        </div>
                    </li>
                </ul>
                <p class="merit-note mb-0">メールアドレスは他の会員には公開されません。<br>登録は任意で、あとから追加もできます。</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary w-100" data-bs-dismiss="modal">閉じる</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    (() => {
        const form = document.getElementById('register-form');
        if (!form) {
            return;
        }

        const emailInput = document.getElementById('email');
        const passwordGroup = document.getElementById('register-password-group');
        const passwordInput = document.getElementById('password');

        // メールアドレスに「@」が入ったらパスワード欄を表示し、消えたら非表示にして値もクリアする
        const syncPasswordField = () => {
            const hasAt = emailInput.value.includes('@');
            passwordGroup.hidden = !hasAt;
            passwordInput.disabled = !hasAt;
            if (!hasAt) {
                passwordInput.value = '';
                passwordInput.type = 'password';
            }
        };
        if (emailInput && passwordGroup && passwordInput) {
            emailInput.addEventListener('input', syncPasswordField);
            syncPasswordField();
        }

        form.addEventListener('submit', (event) => {
            const nameInput = document.getElementById('name');
            const birthDateInput = document.getElementById('birth_date');

            const nameValue = nameInput ? nameInput.value.trim() : '';
            const birthDateValue = birthDateInput ? birthDateInput.value.trim() : '';

            if (nameValue === '' || birthDateValue === '') {
                event.preventDefault();
                window.alert('名前と生年月日を入力してください。');
                return;
            }

            const passwordSet = passwordInput && !passwordInput.disabled && passwordInput.value !== '';
            const confirmed = window.confirm(
                '名前: ' + nameValue + '\n'
                + '入力生年月日: ' + birthDateValue + '\n'
                + 'パスワード: ' + (passwordSet ? '設定したパスワード' : '生年月日（月日の4桁）') + '\n\n'
                + 'この内容で確定しますか？'
            );

            if (!confirmed) {
                event.preventDefault();
            }
        });
    })();
</script>
<?php require __DIR__ . '/../includes/password_toggle_script.php'; ?>
</body>
</html>
