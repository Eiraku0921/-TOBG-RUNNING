<?php

const PASSWORD_RESET_TOKEN_TTL_SECONDS = 3600;
const PASSWORD_RESET_URL = 'https://tobg.sadist.jp/mypage/reset_password.php';
const MEMBER_PASSWORD_MIN_LENGTH = 8;

function ensure_member_password_column(PDO $pdo): void
{
    $stmt = $pdo->query("SHOW COLUMNS FROM members LIKE 'password'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$column) {
        $pdo->exec("ALTER TABLE members ADD COLUMN password VARCHAR(255) NULL");
    }
}

function ensure_password_reset_tokens_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_password_reset_token_hash (token_hash),
            INDEX idx_password_reset_member (member_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/**
 * 名前またはメールアドレスで会員を1件特定する。
 * メールアドレスが複数会員で重複している場合は特定できないため null を返す。
 */
function find_member_by_login_identifier(PDO $pdo, string $identifier): ?array
{
    if (strpos($identifier, '@') !== false) {
        $stmt = $pdo->prepare('SELECT * FROM members WHERE email = :email LIMIT 2');
        $stmt->execute([':email' => $identifier]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM members WHERE name = :name LIMIT 2');
        $stmt->execute([':name' => $identifier]);
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return count($rows) === 1 ? $rows[0] : null;
}

/**
 * 会員の現在のパスワードと一致するか。
 * password 設定済みならそれで照合し、未設定なら初期パスワード（birthday_code）で照合する。
 */
function member_password_matches(array $member, string $input_password): bool
{
    $password_hash = (string)($member['password'] ?? '');
    if ($password_hash !== '') {
        return password_verify($input_password, $password_hash);
    }

    // 「永楽晴三」は password 必須
    if (($member['name'] ?? '') === '永楽晴三') {
        return false;
    }

    return preg_match('/^\d{4,6}$/', $input_password) === 1
        && hash_equals((string)($member['birthday_code'] ?? ''), $input_password);
}

function member_uses_initial_password(array $member): bool
{
    return (string)($member['password'] ?? '') === '';
}

// 「初期パスワードのまま」の案内を、会員が「今後表示しない」を押した日時
function ensure_member_password_notice_column(PDO $pdo): void
{
    if (!$pdo->query("SHOW COLUMNS FROM members LIKE 'password_notice_dismissed_at'")->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN password_notice_dismissed_at DATETIME NULL");
    }
}

// 初期パスワードのままで、案内を消していない会員にだけ案内を出す
function member_should_see_initial_password_notice(array $member): bool
{
    return member_uses_initial_password($member) && empty($member['password_notice_dismissed_at']);
}

function dismiss_member_password_notice(PDO $pdo, int $member_id): void
{
    ensure_member_password_notice_column($pdo);
    $stmt = $pdo->prepare('UPDATE members SET password_notice_dismissed_at = NOW() WHERE id = :id AND password_notice_dismissed_at IS NULL');
    $stmt->execute([':id' => $member_id]);
}

function is_email_used_by_other_member(PDO $pdo, string $email, int $member_id): bool
{
    $stmt = $pdo->prepare('SELECT id FROM members WHERE email = :email AND id <> :id LIMIT 1');
    $stmt->execute([':email' => $email, ':id' => $member_id]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * リセット用トークンを発行し、生トークンを返す（DBにはハッシュのみ保存）。
 * 同じ会員の未使用トークンは無効化する。
 */
function create_password_reset_token(PDO $pdo, int $member_id): string
{
    $token = bin2hex(random_bytes(32));

    $pdo->beginTransaction();
    try {
        $invalidate = $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE member_id = :member_id AND used_at IS NULL');
        $invalidate->execute([':member_id' => $member_id]);

        $insert = $pdo->prepare(
            'INSERT INTO password_reset_tokens (member_id, token_hash, expires_at)
             VALUES (:member_id, :token_hash, :expires_at)'
        );
        $insert->execute([
            ':member_id' => $member_id,
            ':token_hash' => hash('sha256', $token),
            ':expires_at' => date('Y-m-d H:i:s', time() + PASSWORD_RESET_TOKEN_TTL_SECONDS),
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $token;
}

/**
 * 有効な（未使用・期限内の）トークンに対応する会員IDとトークンIDを返す。
 */
function find_valid_password_reset_token(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, member_id FROM password_reset_tokens
         WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([':token_hash' => hash('sha256', $token)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function reset_member_password(PDO $pdo, int $token_id, int $member_id, string $new_password): void
{
    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare('UPDATE members SET password = :password WHERE id = :id');
        $update->execute([
            ':password' => password_hash($new_password, PASSWORD_DEFAULT),
            ':id' => $member_id,
        ]);

        $mark_used = $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :id');
        $mark_used->execute([':id' => $token_id]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // パスワードを再設定したら、その会員の「ログイン状態を保持する」をすべての端末で無効にする。
    // （テーブル作成は MySQL では暗黙にコミットされるので、トランザクションの外で行う）
    require_once __DIR__ . '/remember_login_lib.php';
    revoke_member_remember_tokens($pdo, $member_id);
}

function send_password_reset_email(string $to, string $member_name, string $token): bool
{
    require_once __DIR__ . '/mail_lib.php';

    $url = PASSWORD_RESET_URL . '?token=' . $token;
    $minutes = (int)(PASSWORD_RESET_TOKEN_TTL_SECONDS / 60);

    $body = "{$member_name} さん\n\n"
        . "TOBGマイページのパスワード再設定のリクエストを受け付けました。\n"
        . "以下のリンクから新しいパスワードを設定してください。\n\n"
        . "{$url}\n\n"
        . "※このリンクの有効期限は{$minutes}分です。\n"
        . "※お心当たりがない場合は、このメールを破棄してください。\n";

    return send_notification_email($to, '【TOBG】パスワード再設定のご案内', $body);
}
