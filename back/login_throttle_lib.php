<?php

const LOGIN_THROTTLE_MAX_FAILURES = 5;
const LOGIN_THROTTLE_WINDOW_MINUTES = 15;

// 用途ごとに失敗回数を分けて数える
const LOGIN_THROTTLE_SCOPE_LOGIN = 'login';
const LOGIN_THROTTLE_SCOPE_RESET_VERIFY = 'reset_verify';
const LOGIN_THROTTLE_SCOPE_CHANGE_PASSWORD = 'change_password';

function ensure_login_attempts_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            scope VARCHAR(32) NOT NULL,
            identifier VARCHAR(255) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_login_attempts_lookup (scope, identifier, attempted_at),
            INDEX idx_login_attempts_attempted_at (attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function normalize_login_throttle_identifier(string $identifier): string
{
    $identifier = preg_replace('/\s+/u', '', $identifier) ?? $identifier;
    return mb_strtolower($identifier, 'UTF-8');
}

function login_throttle_client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/**
 * 直近の失敗回数が上限に達していればロック中とみなす。
 */
function is_login_throttled(PDO $pdo, string $scope, string $identifier): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE scope = :scope AND identifier = :identifier
           AND attempted_at > (NOW() - INTERVAL ' . LOGIN_THROTTLE_WINDOW_MINUTES . ' MINUTE)'
    );
    $stmt->execute([
        ':scope' => $scope,
        ':identifier' => normalize_login_throttle_identifier($identifier),
    ]);

    return (int)$stmt->fetchColumn() >= LOGIN_THROTTLE_MAX_FAILURES;
}

function record_login_failure(PDO $pdo, string $scope, string $identifier): void
{
    $stmt = $pdo->prepare('INSERT INTO login_attempts (scope, identifier, ip) VALUES (:scope, :identifier, :ip)');
    $stmt->execute([
        ':scope' => $scope,
        ':identifier' => normalize_login_throttle_identifier($identifier),
        ':ip' => login_throttle_client_ip(),
    ]);

    // 古い記録はついでに掃除する
    $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
}

function clear_login_failures(PDO $pdo, string $scope, string $identifier): void
{
    $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE scope = :scope AND identifier = :identifier');
    $stmt->execute([
        ':scope' => $scope,
        ':identifier' => normalize_login_throttle_identifier($identifier),
    ]);
}

function login_throttle_message(): string
{
    return 'ログインに続けて失敗したため、' . LOGIN_THROTTLE_WINDOW_MINUTES . '分間ロックしています。時間をおいて再度お試しください。';
}
