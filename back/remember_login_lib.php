<?php

// ログイン状態の保持（スマホ・タブレットは90日、PC はチェックしたときだけ2日。どちらも発行時から数え、使っても延びない）。
// クッキーには「識別子:合言葉」を入れ、DB（remember_tokens）には識別子と合言葉のハッシュだけを保存する。
// 自動ログインのたびに合言葉を入れ替え、盗まれた古いクッキーを使えなくする。
// ログアウト時はその端末のトークンを、パスワード変更・再設定時はその会員の全トークンを無効にする。

require_once __DIR__ . '/member_avatar_lib.php';

const REMEMBER_LOGIN_COOKIE = 'tobg_remember';
const REMEMBER_LOGIN_DAYS_MOBILE = 90;
const REMEMBER_LOGIN_DAYS_PC = 2;

// スマホ・タブレット（自分専用の端末）は常にログイン状態を保持し、PC だけ保持するかを選べるようにする。
// PC で保持しないときは、セッション（3時間。session_bootstrap.php）が切れたらログアウトになる。
// 判定はブラウザの User-Agent で行う（iPad は PC と同じ名乗りをすることがあるが、その場合は選べる側になるだけ）
function remember_login_is_mobile_device(): bool
{
    $user_agent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    return (bool)preg_match('/iPhone|iPad|iPod|Android|Mobile/i', $user_agent);
}

function ensure_remember_tokens_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS remember_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            selector CHAR(24) NOT NULL,
            validator_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME NULL,
            UNIQUE KEY uniq_remember_tokens_selector (selector),
            INDEX idx_remember_tokens_user (user_id),
            INDEX idx_remember_tokens_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function remember_request_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function set_remember_cookie(string $value, int $expires_at): void
{
    setcookie(REMEMBER_LOGIN_COOKIE, $value, [
        'expires' => $expires_at,
        'path' => '/',
        'secure' => remember_request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if ($expires_at > time()) {
        $_COOKIE[REMEMBER_LOGIN_COOKIE] = $value;
    } else {
        unset($_COOKIE[REMEMBER_LOGIN_COOKIE]);
    }
}

function clear_remember_cookie(): void
{
    set_remember_cookie('', time() - 3600);
}

// クッキーの「識別子:合言葉」を分ける。形が違えば null
function parse_remember_cookie(): ?array
{
    $raw = (string)($_COOKIE[REMEMBER_LOGIN_COOKIE] ?? '');
    if (!preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $raw, $m)) {
        return null;
    }
    return ['selector' => $m[1], 'validator' => $m[2]];
}

// ログインした端末に合わせた保持の期限（UNIX 時刻）。保持しないときは null
function remember_login_expires_at(bool $remember_checked): ?int
{
    if (remember_login_is_mobile_device()) {
        return time() + REMEMBER_LOGIN_DAYS_MOBILE * 86400;
    }
    return $remember_checked ? time() + REMEMBER_LOGIN_DAYS_PC * 86400 : null;
}

// いまの端末のトークンの期限（パスワード変更で発行し直すとき、期限を延ばさないために使う）。無ければ null
function current_remember_token_expires_at(PDO $pdo): ?int
{
    $cookie = parse_remember_cookie();
    if ($cookie === null) {
        return null;
    }
    ensure_remember_tokens_table($pdo);
    $stmt = $pdo->prepare('SELECT validator_hash, expires_at FROM remember_tokens WHERE selector = :selector AND expires_at > NOW() LIMIT 1');
    $stmt->execute([':selector' => $cookie['selector']]);
    $token = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$token || !hash_equals((string)$token['validator_hash'], hash('sha256', $cookie['validator']))) {
        return null;
    }
    return (int)strtotime((string)$token['expires_at']);
}

// ログイン状態を保持するトークンを、$expires_at（UNIX 時刻）まで有効で発行してクッキーに保存する
function issue_remember_token(PDO $pdo, int $user_id, int $expires_at): void
{
    ensure_remember_tokens_table($pdo);
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));

    $stmt = $pdo->prepare(
        'INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at)
         VALUES (:user_id, :selector, :validator_hash, :expires_at)'
    );
    $stmt->execute([
        ':user_id' => $user_id,
        ':selector' => $selector,
        ':validator_hash' => hash('sha256', $validator),
        ':expires_at' => date('Y-m-d H:i:s', $expires_at),
    ]);
    set_remember_cookie($selector . ':' . $validator, $expires_at);
}

// クッキーのトークンが有効なら、その会員を返す（合言葉はここで入れ替える）。無効ならクッキーを消して null
function consume_remember_token(PDO $pdo): ?array
{
    $cookie = parse_remember_cookie();
    if ($cookie === null) {
        clear_remember_cookie();
        return null;
    }

    ensure_remember_tokens_table($pdo);
    $pdo->exec('DELETE FROM remember_tokens WHERE expires_at < NOW()');

    $stmt = $pdo->prepare('SELECT id, user_id, validator_hash, expires_at FROM remember_tokens WHERE selector = :selector LIMIT 1');
    $stmt->execute([':selector' => $cookie['selector']]);
    $token = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$token || !hash_equals((string)$token['validator_hash'], hash('sha256', $cookie['validator']))) {
        // 識別子は合っているのに合言葉が違う＝盗まれた古いクッキーの可能性があるので、そのトークンは消す
        if ($token) {
            $pdo->prepare('DELETE FROM remember_tokens WHERE id = :id')->execute([':id' => (int)$token['id']]);
        }
        clear_remember_cookie();
        return null;
    }

    $member_stmt = $pdo->prepare('SELECT * FROM members WHERE id = :id LIMIT 1');
    $member_stmt->execute([':id' => (int)$token['user_id']]);
    $member = $member_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) {
        $pdo->prepare('DELETE FROM remember_tokens WHERE id = :id')->execute([':id' => (int)$token['id']]);
        clear_remember_cookie();
        return null;
    }

    // 合言葉を入れ替える（有効期限は発行時に決めたまま延ばさない）
    $new_validator = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE remember_tokens SET validator_hash = :hash, last_used_at = NOW() WHERE id = :id')
        ->execute([':hash' => hash('sha256', $new_validator), ':id' => (int)$token['id']]);
    set_remember_cookie($cookie['selector'] . ':' . $new_validator, (int)strtotime((string)$token['expires_at']));

    return $member;
}

// ログアウト: この端末のトークンだけを消す
function forget_current_remember_token(PDO $pdo): void
{
    $cookie = parse_remember_cookie();
    if ($cookie !== null) {
        ensure_remember_tokens_table($pdo);
        $pdo->prepare('DELETE FROM remember_tokens WHERE selector = :selector')->execute([':selector' => $cookie['selector']]);
    }
    clear_remember_cookie();
}

// パスワード変更・再設定: その会員のトークンをすべて消す（ほかの端末の自動ログインも止まる）
function revoke_member_remember_tokens(PDO $pdo, int $user_id): void
{
    ensure_remember_tokens_table($pdo);
    $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = :user_id')->execute([':user_id' => $user_id]);
}

// セッションに入れる権限。「永楽晴三」は管理者（ログイン画面と同じ扱い）
function member_session_role(array $user): string
{
    if (($user['name'] ?? '') === '永楽晴三') {
        return 'admin';
    }
    $role = (string)($user['role'] ?? '');
    return $role !== '' ? $role : 'member';
}

// ログイン後のセッションを作る（通常のログインと自動ログインで共通）
function establish_member_session(array $user): void
{
    session_regenerate_id(true);
    unset($_SESSION['password_reset_pending_member_id']);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = member_session_role($user);
    $_SESSION['user_avatar_path'] = normalize_member_avatar_path((string)($user['avatar_image_path'] ?? ''));
}

// DB 接続は、ページ側の $pdo を使う。まだ無ければ接続して、ページ側でも使えるよう全体に置く
// （関数の中で require_once すると、ページ側の require_once が飛ばされて $pdo が無くなるため）
function session_bootstrap_pdo(): PDO
{
    if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
        require __DIR__ . '/db_connect.php';
        $GLOBALS['pdo'] = $pdo;
    }
    return $GLOBALS['pdo'];
}

// ログイン中の会員の権限を、毎回 DB の最新に合わせる。
// 管理者が会員一覧で権限を変えたとき、本人がログインし直さなくてもすぐ反映されるようにするため。
// 会員が削除されていたら、ログイン状態を解く
function refresh_member_session(): void
{
    try {
        $stmt = session_bootstrap_pdo()->prepare('SELECT name, role FROM members WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => (int)$_SESSION['user_id']]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$member) {
            unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_role'], $_SESSION['user_avatar_path']);
            return;
        }
        $_SESSION['user_role'] = member_session_role($member);
    } catch (Throwable $e) {
        // 読めなかったときは、セッションの権限のまま表示する
        error_log('refresh_member_session failed: ' . $e->getMessage());
    }
}

// セッションが切れていても、保持トークンのクッキーがあれば自動でログインする（全ページの tobg_start_session から呼ぶ）
function try_remember_login(): void
{
    try {
        $member = consume_remember_token(session_bootstrap_pdo());
        if ($member !== null) {
            establish_member_session($member);
        }
    } catch (Throwable $e) {
        // 自動ログインに失敗しても、ページは未ログインとして表示する
        error_log('try_remember_login failed: ' . $e->getMessage());
    }
}
