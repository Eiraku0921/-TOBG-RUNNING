<?php
require_once __DIR__ . '/session_bootstrap.php';
tobg_start_session();

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/password_reset_lib.php';
require_once __DIR__ . '/login_throttle_lib.php';

if (!isset($_SESSION['user_id'], $_SESSION['user_name'])) {
    header('Location: ../mypage/login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$redirect_url = '../mypage/front_login.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirect_url);
    exit;
}

$current_password = (string)($_POST['current_password'] ?? '');
$new_password = (string)($_POST['new_password'] ?? '');
$confirm_password = (string)($_POST['confirm_password'] ?? '');
$throttle_key = 'member:' . $user_id;

try {
    ensure_member_password_column($pdo);
    ensure_login_attempts_table($pdo);

    $stmt = $pdo->prepare('SELECT * FROM members WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $user_id]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        throw new RuntimeException('会員情報が見つかりません。');
    }
    // メール未登録だと「名前＋生年月日」でメール登録→リセットできてしまうため、先に登録してもらう
    if (trim((string)($member['email'] ?? '')) === '') {
        throw new RuntimeException('パスワードを変更する前に、メールアドレスを登録してください。');
    }
    if (is_login_throttled($pdo, LOGIN_THROTTLE_SCOPE_CHANGE_PASSWORD, $throttle_key)) {
        throw new RuntimeException(login_throttle_message());
    }
    if (!member_password_matches($member, $current_password)) {
        record_login_failure($pdo, LOGIN_THROTTLE_SCOPE_CHANGE_PASSWORD, $throttle_key);
        throw new RuntimeException('現在のパスワードが正しくありません。');
    }
    if (mb_strlen($new_password) < MEMBER_PASSWORD_MIN_LENGTH) {
        throw new RuntimeException('新しいパスワードは' . MEMBER_PASSWORD_MIN_LENGTH . '文字以上で入力してください。');
    }
    if ($new_password !== $confirm_password) {
        throw new RuntimeException('確認用パスワードが一致しません。');
    }
    // 生年月日（8桁）そのままは初期パスワードと変わらないため不可
    $birthday_code = (string)($member['birthday_code'] ?? '');
    if ($birthday_code !== '' && preg_match('/^\d{8}$/', $new_password)
        && substr($new_password, -strlen($birthday_code)) === $birthday_code) {
        throw new RuntimeException('生年月日をそのままパスワードにすることはできません。');
    }

    $update = $pdo->prepare('UPDATE members SET password = :password WHERE id = :id');
    $update->execute([
        ':password' => password_hash($new_password, PASSWORD_DEFAULT),
        ':id' => $user_id,
    ]);
    clear_login_failures($pdo, LOGIN_THROTTLE_SCOPE_CHANGE_PASSWORD, $throttle_key);

    // ほかの端末の「ログイン状態を保持する」はすべて無効にする。
    // いま使っている端末で保持していた場合だけ、新しいトークンを発行し直してログアウトさせない
    require_once __DIR__ . '/remember_login_lib.php';
    // 発行し直すトークンは、元の期限のまま（パスワードを変えても保持の期間は延ばさない）
    $remember_expires_at = current_remember_token_expires_at($pdo);
    revoke_member_remember_tokens($pdo, $user_id);
    if ($remember_expires_at !== null) {
        issue_remember_token($pdo, $user_id, $remember_expires_at);
    }

    session_regenerate_id(true);
    $_SESSION['profile_flash'] = 'パスワードを変更しました。次回から新しいパスワードでログインしてください。';
} catch (PDOException $e) {
    // PDOException は RuntimeException の子クラスなので先に捕まえる
    $_SESSION['password_change_error'] = 'パスワードの変更に失敗しました。';
} catch (RuntimeException $e) {
    $_SESSION['password_change_error'] = $e->getMessage();
} catch (Throwable $e) {
    $_SESSION['password_change_error'] = 'パスワードの変更に失敗しました。';
}

header('Location: ' . $redirect_url);
exit;
