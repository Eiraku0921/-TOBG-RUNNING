<?php

function ensure_inquiries_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS inquiries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            sender_name VARCHAR(100) NULL,
            is_anonymous TINYINT(1) NOT NULL DEFAULT 0,
            message TEXT NOT NULL,
            wants_team_membership TINYINT(1) NOT NULL DEFAULT 0,
            applicant_name VARCHAR(100) NULL,
            applicant_furigana VARCHAR(150) NULL,
            applicant_birthdate DATE NULL,
            applicant_postal_code VARCHAR(10) NULL,
            applicant_prefecture VARCHAR(50) NULL,
            applicant_address VARCHAR(255) NULL,
            applicant_address_detail VARCHAR(255) NULL,
            applicant_phone VARCHAR(20) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_inquiries_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    ensure_inquiry_team_application_columns($pdo);
}

function ensure_inquiry_team_application_columns(PDO $pdo): void
{
    $columns = [
        'wants_team_membership' => "ALTER TABLE inquiries ADD COLUMN wants_team_membership TINYINT(1) NOT NULL DEFAULT 0",
        'applicant_name' => "ALTER TABLE inquiries ADD COLUMN applicant_name VARCHAR(100) NULL",
        'applicant_furigana' => "ALTER TABLE inquiries ADD COLUMN applicant_furigana VARCHAR(150) NULL",
        'applicant_birthdate' => "ALTER TABLE inquiries ADD COLUMN applicant_birthdate DATE NULL",
        'applicant_postal_code' => "ALTER TABLE inquiries ADD COLUMN applicant_postal_code VARCHAR(10) NULL",
        'applicant_prefecture' => "ALTER TABLE inquiries ADD COLUMN applicant_prefecture VARCHAR(50) NULL",
        'applicant_address' => "ALTER TABLE inquiries ADD COLUMN applicant_address VARCHAR(255) NULL",
        'applicant_address_detail' => "ALTER TABLE inquiries ADD COLUMN applicant_address_detail VARCHAR(255) NULL",
        'applicant_phone' => "ALTER TABLE inquiries ADD COLUMN applicant_phone VARCHAR(20) NULL",
    ];

    foreach ($columns as $column_name => $alter_sql) {
        $stmt = $pdo->query('SHOW COLUMNS FROM inquiries LIKE ' . $pdo->quote($column_name));
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec($alter_sql);
        }
    }
}
