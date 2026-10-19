<?php

function ensure_gallery_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS gallery_posts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            event_date DATE NULL,
            title VARCHAR(255) NOT NULL,
            body TEXT NULL,
            image_path_1 VARCHAR(255) NULL,
            image_path_2 VARCHAR(255) NULL,
            video_path_1 VARCHAR(255) NULL,
            video_path_2 VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_gallery_user (user_id),
            INDEX idx_gallery_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function ensure_gallery_interaction_tables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS gallery_comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            gallery_id INT NOT NULL,
            user_id INT NOT NULL,
            comment_text TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_gallery_comments_gallery (gallery_id),
            INDEX idx_gallery_comments_user (user_id),
            INDEX idx_gallery_comments_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS gallery_likes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            gallery_id INT NOT NULL,
            user_id INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_gallery_like_user (gallery_id, user_id),
            INDEX idx_gallery_likes_gallery (gallery_id),
            INDEX idx_gallery_likes_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function fetch_gallery_like_summary(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT gallery_id, COUNT(*) AS like_count
         FROM gallery_likes
         GROUP BY gallery_id"
    );

    $summary = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $summary[(int)($row['gallery_id'] ?? 0)] = (int)($row['like_count'] ?? 0);
    }

    return $summary;
}

function fetch_gallery_comment_summary(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT gallery_id, COUNT(*) AS comment_count
         FROM gallery_comments
         GROUP BY gallery_id"
    );

    $summary = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $summary[(int)($row['gallery_id'] ?? 0)] = (int)($row['comment_count'] ?? 0);
    }

    return $summary;
}

function fetch_gallery_comments(PDO $pdo, int $gallery_id): array
{
    $stmt = $pdo->prepare(
        "SELECT c.id, c.gallery_id, c.user_id, c.comment_text, c.created_at, c.updated_at, COALESCE(m.name, 'メンバー') AS user_name
         FROM gallery_comments c
         LEFT JOIN members m ON m.id = c.user_id
         WHERE c.gallery_id = :gallery_id
         ORDER BY c.created_at ASC, c.id ASC"
    );
    $stmt->execute([':gallery_id' => $gallery_id]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
