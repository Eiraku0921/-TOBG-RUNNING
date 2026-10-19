<?php

function ensure_access_counter_table(PDO $pdo): void
{
    $sql = "
        CREATE TABLE IF NOT EXISTS access_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(128) NOT NULL,
            page_path VARCHAR(255) NOT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created_at (created_at),
            INDEX idx_page_path (page_path)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    $pdo->exec($sql);

    $counter_sql = "
        CREATE TABLE IF NOT EXISTS access_counter_state (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            window_start DATETIME NOT NULL,
            access_count INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    $pdo->exec($counter_sql);

    $init_stmt = $pdo->query('SELECT COUNT(*) FROM access_counter_state WHERE id = 1');
    $exists = (int)$init_stmt->fetchColumn() > 0;
    if (!$exists) {
        $insert_stmt = $pdo->prepare(
            'INSERT INTO access_counter_state (id, window_start, access_count) VALUES (1, NOW(), 0)'
        );
        $insert_stmt->execute();
    }
}

function log_access_hit(PDO $pdo, string $session_id, string $page_path, string $ip_address, string $user_agent): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO access_logs (session_id, page_path, ip_address, user_agent)
         VALUES (:session_id, :page_path, :ip_address, :user_agent)"
    );

    $stmt->execute([
        ':session_id' => $session_id,
        ':page_path' => $page_path,
        ':ip_address' => $ip_address !== '' ? $ip_address : null,
        ':user_agent' => $user_agent !== '' ? mb_substr($user_agent, 0, 255) : null,
    ]);
}

function count_access_last_hours(PDO $pdo, int $hours = 24): int
{
    $hours = max(1, $hours);

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM access_logs
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL :hours HOUR)"
    );
    $stmt->bindValue(':hours', $hours, PDO::PARAM_INT);
    $stmt->execute();

    return (int)$stmt->fetchColumn();
}

function increment_and_get_24h_access_counter(PDO $pdo): int
{
    $pdo->beginTransaction();

    try {
        $select_stmt = $pdo->query(
            'SELECT window_start, access_count FROM access_counter_state WHERE id = 1 FOR UPDATE'
        );
        $row = $select_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $insert_stmt = $pdo->prepare(
                'INSERT INTO access_counter_state (id, window_start, access_count) VALUES (1, NOW(), 0)'
            );
            $insert_stmt->execute();
            $row = [
                'window_start' => date('Y-m-d H:i:s'),
                'access_count' => 0,
            ];
        }

        $window_start = strtotime((string)$row['window_start']);
        $now = time();
        $count = (int)($row['access_count'] ?? 0);

        if ($window_start === false || ($now - $window_start) >= 86400) {
            $count = 0;
            $reset_stmt = $pdo->prepare(
                'UPDATE access_counter_state SET window_start = NOW(), access_count = 0 WHERE id = 1'
            );
            $reset_stmt->execute();
        }

        $count++;
        $update_stmt = $pdo->prepare('UPDATE access_counter_state SET access_count = :access_count WHERE id = 1');
        $update_stmt->execute([':access_count' => $count]);

        $pdo->commit();
        return $count;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}