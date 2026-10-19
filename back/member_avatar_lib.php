<?php

function ensure_member_avatar_column(PDO $pdo): void
{
    $stmt = $pdo->query("SHOW COLUMNS FROM members LIKE 'avatar_image_path'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$column) {
        $pdo->exec("ALTER TABLE members ADD COLUMN avatar_image_path VARCHAR(255) NULL");
    }
}

function ensure_member_gender_column(PDO $pdo): void
{
    $stmt = $pdo->query("SHOW COLUMNS FROM members LIKE 'gender'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$column) {
        $pdo->exec("ALTER TABLE members ADD COLUMN gender VARCHAR(10) NULL AFTER name");
    }
}

function ensure_member_email_column(PDO $pdo): void
{
    $stmt = $pdo->query("SHOW COLUMNS FROM members LIKE 'email'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$column) {
        $pdo->exec("ALTER TABLE members ADD COLUMN email VARCHAR(255) NULL AFTER name");
    }
}

// 年会費の支払い状況を持つ列の候補（既存DBで別名の列があればそれを使う）
const MEMBER_ANNUAL_FEE_COLUMN_CANDIDATES = ['annual_fee_paid', 'membership_fee_paid', 'fee_paid', 'yearly_fee_paid', 'annual_fee_status'];

// 年会費の列が無ければ annual_fee_paid（0=未払い / 1=支払い済み）を追加し、使う列名を返す
function ensure_member_annual_fee_column(PDO $pdo): string
{
    $columns = array_column($pdo->query('SHOW COLUMNS FROM members')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    foreach (MEMBER_ANNUAL_FEE_COLUMN_CANDIDATES as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    $pdo->exec("ALTER TABLE members ADD COLUMN annual_fee_paid TINYINT(1) NOT NULL DEFAULT 0");
    return 'annual_fee_paid';
}

function member_annual_fee_is_paid($raw_value): bool
{
    $normalized = strtolower(trim((string)$raw_value));
    return in_array($normalized, ['1', 'true', 'yes', '済', '支払済', 'paid'], true);
}

function normalize_member_avatar_path(string $path): string
{
    $normalized = str_replace('\\', '/', trim($path));
    $normalized = ltrim($normalized, '/');

    if ($normalized === '' || strpos($normalized, '..') !== false) {
        return '';
    }

    return $normalized;
}