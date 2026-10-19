<?php

// Web Push（プッシュ通知）の送信。外部ライブラリを使わず、PHP の openssl だけで実装している。
//   - 暗号化: RFC 8291（Message Encryption for Web Push, aes128gcm）
//   - 送信元の証明: RFC 8292（VAPID。ES256 の JWT）
// 購読（どの端末に送るか）は push_subscriptions、送信元の鍵ペアは push_vapid_keys に保存する。

const WEB_PUSH_SUBJECT = 'mailto:info@tobg.sadist.jp';
const WEB_PUSH_TTL_SECONDS = 86400;

// P-256 の公開鍵（非圧縮 65 バイト）を、openssl に渡せる形（SubjectPublicKeyInfo の PEM）にするための先頭部分
const WEB_PUSH_P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string
{
    $decoded = base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
    return $decoded === false ? '' : $decoded;
}

// ---- テーブル ----

function ensure_web_push_tables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS push_subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            endpoint TEXT NOT NULL,
            endpoint_hash CHAR(64) NOT NULL,
            p256dh VARCHAR(255) NOT NULL,
            auth VARCHAR(64) NOT NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_success_at DATETIME NULL,
            UNIQUE KEY uniq_push_subscriptions_endpoint (endpoint_hash),
            INDEX idx_push_subscriptions_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS push_vapid_keys (
            id TINYINT NOT NULL PRIMARY KEY,
            public_key VARCHAR(100) NOT NULL,
            private_key_pem TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

// ---- 鍵 ----

// P-256 の鍵ペアを作る。公開鍵は非圧縮の 65 バイト（0x04 || X || Y）
function web_push_create_ec_key(): array
{
    $options = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
    $key = openssl_pkey_new($options);
    if ($key === false) {
        // openssl.cnf が見つからない環境向けに、同梱の最小設定でもう一度作る
        $key = openssl_pkey_new($options + ['config' => __DIR__ . '/openssl_min.cnf']);
    }
    if ($key === false) {
        throw new RuntimeException('鍵を作れませんでした。');
    }
    $details = openssl_pkey_get_details($key);
    $public_raw = "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
    return ['key' => $key, 'public_raw' => $public_raw];
}

function web_push_public_key_from_raw(string $public_raw)
{
    $der = hex2bin(WEB_PUSH_P256_SPKI_PREFIX) . $public_raw;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $key = openssl_pkey_get_public($pem);
    if ($key === false) {
        throw new RuntimeException('購読の公開鍵が正しくありません。');
    }
    return $key;
}

// 送信元（このサイト）の VAPID 鍵。無ければ最初の1回だけ作って保存する
function get_vapid_keys(PDO $pdo): array
{
    ensure_web_push_tables($pdo);
    $row = $pdo->query('SELECT public_key, private_key_pem FROM push_vapid_keys WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $created = web_push_create_ec_key();
        if (!openssl_pkey_export($created['key'], $private_pem)
            && !openssl_pkey_export($created['key'], $private_pem, null, ['config' => __DIR__ . '/openssl_min.cnf'])) {
            throw new RuntimeException('鍵を保存できませんでした。');
        }
        $stmt = $pdo->prepare('INSERT IGNORE INTO push_vapid_keys (id, public_key, private_key_pem) VALUES (1, :public_key, :private_key_pem)');
        $stmt->execute([':public_key' => base64url_encode($created['public_raw']), ':private_key_pem' => $private_pem]);
        // 同時に作られた場合に備えて、保存されたほうを読み直す
        $row = $pdo->query('SELECT public_key, private_key_pem FROM push_vapid_keys WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    }
    return ['public_key' => (string)$row['public_key'], 'private_key_pem' => (string)$row['private_key_pem']];
}

// ---- RFC 8292: VAPID ----

// openssl_sign が返す DER の署名を、JWT（ES256）が求める r||s の 64 バイトにする
function web_push_der_signature_to_raw(string $der): string
{
    $offset = 2;
    if (ord($der[1]) & 0x80) {
        $offset += ord($der[1]) & 0x7f;
    }
    $parts = [];
    for ($i = 0; $i < 2; $i++) {
        $length = ord($der[$offset + 1]);
        $value = substr($der, $offset + 2, $length);
        $parts[] = str_pad(ltrim($value, "\0"), 32, "\0", STR_PAD_LEFT);
        $offset += 2 + $length;
    }
    return $parts[0] . $parts[1];
}

function web_push_vapid_authorization(string $endpoint, array $vapid_keys, ?int $now = null): string
{
    $url = parse_url($endpoint);
    $audience = $url['scheme'] . '://' . $url['host'] . (isset($url['port']) ? ':' . $url['port'] : '');
    $header = base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = base64url_encode(json_encode([
        'aud' => $audience,
        'exp' => ($now ?? time()) + 12 * 3600,
        'sub' => WEB_PUSH_SUBJECT,
    ], JSON_UNESCAPED_SLASHES));
    $signing_input = $header . '.' . $claims;
    if (!openssl_sign($signing_input, $der_signature, $vapid_keys['private_key_pem'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('VAPID の署名に失敗しました。');
    }
    $jwt = $signing_input . '.' . base64url_encode(web_push_der_signature_to_raw($der_signature));
    return 'vapid t=' . $jwt . ', k=' . $vapid_keys['public_key'];
}

// ---- RFC 8291: 暗号化（aes128gcm） ----

// $as_key / $salt はテストで決まった値を入れるときだけ渡す（普段は毎回ランダムに作る）
function web_push_encrypt(string $payload, string $ua_public_raw, string $auth_secret, ?array $as_key = null, ?string $salt = null): string
{
    $as_key = $as_key ?? web_push_create_ec_key();
    $salt = $salt ?? random_bytes(16);

    // 端末の公開鍵と、このときだけの鍵で共有の秘密を作る（ECDH）
    $ecdh_secret = openssl_pkey_derive(web_push_public_key_from_raw($ua_public_raw), $as_key['key'], 32);
    if ($ecdh_secret === false) {
        throw new RuntimeException('鍵の交換に失敗しました。');
    }

    $key_info = "WebPush: info\0" . $ua_public_raw . $as_key['public_raw'];
    $ikm = hash_hkdf('sha256', $ecdh_secret, 32, $key_info, $auth_secret);
    $content_key = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

    // 1レコードだけ送るので、最後のレコードを表す 0x02 を付けて暗号化する
    $ciphertext = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $content_key, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false) {
        throw new RuntimeException('暗号化に失敗しました。');
    }

    // ヘッダー: salt(16) || レコードサイズ(4) || 鍵の長さ(1) || このときだけの公開鍵(65)
    return $salt . pack('N', 4096) . chr(strlen($as_key['public_raw'])) . $as_key['public_raw'] . $ciphertext . $tag;
}

// ---- 購読 ----

function save_push_subscription(PDO $pdo, int $user_id, string $endpoint, string $p256dh, string $auth, string $user_agent): void
{
    ensure_web_push_tables($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, user_agent)
         VALUES (:user_id, :endpoint, :endpoint_hash, :p256dh, :auth, :user_agent)
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth), user_agent = VALUES(user_agent)'
    );
    $stmt->execute([
        ':user_id' => $user_id,
        ':endpoint' => $endpoint,
        ':endpoint_hash' => hash('sha256', $endpoint),
        ':p256dh' => $p256dh,
        ':auth' => $auth,
        ':user_agent' => mb_substr($user_agent, 0, 255),
    ]);
}

function delete_push_subscription(PDO $pdo, int $user_id, string $endpoint): void
{
    ensure_web_push_tables($pdo);
    $stmt = $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint_hash = :endpoint_hash AND user_id = :user_id');
    $stmt->execute([':endpoint_hash' => hash('sha256', $endpoint), ':user_id' => $user_id]);
}

// ---- 送信 ----

// 1つの端末に送る。届かなくなった購読（404 / 410）は消す。送れたら true
function send_web_push(PDO $pdo, array $subscription, array $message, array $vapid_keys): bool
{
    return send_web_push_with_status($pdo, $subscription, $message, $vapid_keys)['delivered'];
}

// 1台に送り、プッシュサービスの返事も返す（テスト通知で原因を見せるため）。
// 戻り値: delivered（届けられたか）、status（HTTP ステータス。つながらなければ 0）、error（つながらなかった理由）
function send_web_push_with_status(PDO $pdo, array $subscription, array $message, array $vapid_keys): array
{
    $endpoint = (string)$subscription['endpoint'];
    $body = web_push_encrypt(
        json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        base64url_decode((string)$subscription['p256dh']),
        base64url_decode((string)$subscription['auth'])
    );

    $curl = curl_init($endpoint);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: ' . WEB_PUSH_TTL_SECONDS,
            'Urgency: normal',
            'Authorization: ' . web_push_vapid_authorization($endpoint, $vapid_keys),
        ],
    ]);
    curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($curl);
    curl_close($curl);

    if ($status === 404 || $status === 410) {
        $pdo->prepare('DELETE FROM push_subscriptions WHERE id = :id')->execute([':id' => (int)$subscription['id']]);
        return ['delivered' => false, 'status' => $status, 'error' => ''];
    }
    if ($status >= 200 && $status < 300) {
        $pdo->prepare('UPDATE push_subscriptions SET last_success_at = NOW() WHERE id = :id')->execute([':id' => (int)$subscription['id']]);
        return ['delivered' => true, 'status' => $status, 'error' => ''];
    }
    error_log("send_web_push failed: status={$status} error={$curl_error} endpoint=" . parse_url($endpoint, PHP_URL_HOST));
    return ['delivered' => false, 'status' => $status, 'error' => $curl_error];
}

// 会員のすべての端末に送る。1台でも届いたら true（メールを送るかどうかの判断に使う）。
// $message: ['title' => …, 'body' => …, 'url' => サイト内のパス, 'tag' => 同じ通知をまとめる名前]
function push_to_member(PDO $pdo, int $user_id, array $message): bool
{
    try {
        ensure_web_push_tables($pdo);
        $stmt = $pdo->prepare('SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = :user_id');
        $stmt->execute([':user_id' => $user_id]);
        $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($subscriptions)) {
            return false;
        }
        if (isset($message['url'])) {
            $message['url'] = web_push_absolute_url((string)$message['url']);
        }
        $vapid_keys = get_vapid_keys($pdo);
        $delivered = false;
        foreach ($subscriptions as $subscription) {
            $delivered = send_web_push($pdo, $subscription, $message, $vapid_keys) || $delivered;
        }
        return $delivered;
    } catch (Throwable $e) {
        // プッシュに失敗しても本来の操作（コメント投稿など）は止めない
        error_log('push_to_member failed: ' . $e->getMessage());
        return false;
    }
}

// 購読している全会員に送る（お知らせ配信用）
function push_to_all_members(PDO $pdo, array $message): void
{
    try {
        ensure_web_push_tables($pdo);
        $user_ids = $pdo->query('SELECT DISTINCT user_id FROM push_subscriptions')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($user_ids as $user_id) {
            push_to_member($pdo, (int)$user_id, $message);
        }
    } catch (Throwable $e) {
        error_log('push_to_all_members failed: ' . $e->getMessage());
    }
}

// お知らせを公開したとき、購読している全会員に送る
function push_announcement_to_members(PDO $pdo, int $announcement_id, string $title, string $body): void
{
    push_to_all_members($pdo, [
        'title' => 'お知らせ：' . mb_strimwidth($title, 0, 60, '…', 'UTF-8'),
        'body' => mb_strimwidth(preg_replace('/\s+/u', ' ', $body), 0, 120, '…', 'UTF-8'),
        'url' => 'index.php#newsTitle',
        'tag' => 'announcement-' . $announcement_id,
    ]);
}

// 通知を押したときに開く URL（サイト内のパスを、本番のアドレスに付ける）
function web_push_absolute_url(string $path): string
{
    if (preg_match('#^https?://#', $path)) {
        return $path;
    }
    return 'https://tobg.sadist.jp/' . ltrim($path, '/');
}
