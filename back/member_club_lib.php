<?php

// 会員の所属クラブ（クラブ画面の中身を決める）。管理者が会員一覧で設定する。
//   tobg  : TOBG 社会人クラブ  → 合同練習 / 大会 / 歴代記録 / ランキング
//   ogmrc : OGMRC 小中学生クラブ → メニュー / 合同練習 / 大会 / 歴代記録 / ランキング
//   NULL  : 所属なし → チーム紹介（未ログインと同じ）
// 陸連登録の有無とは別。大会の申し込みだけは陸連登録者に限る。

require_once __DIR__ . '/member_role_lib.php';

const MEMBER_CLUB_TOBG = 'tobg';
const MEMBER_CLUB_OGMRC = 'ogmrc';
const MEMBER_CLUB_LABELS = [
    MEMBER_CLUB_TOBG => 'TOBG 社会人クラブ',
    MEMBER_CLUB_OGMRC => 'OGMRC 小中学生クラブ',
];

// 所属クラブの列を用意する。列を足した最初の1回だけ、今の権限から初期値を入れる
// （ＯＧＭ の権限なら OGMRC、それ以外は TOBG。入れておかないと、管理者が設定するまで全員が「所属なし」になるため）
function ensure_member_club_column(PDO $pdo): void
{
    if ($pdo->query("SHOW COLUMNS FROM members LIKE 'club'")->fetch()) {
        return;
    }
    ensure_member_role_column($pdo);
    $pdo->exec("ALTER TABLE members ADD COLUMN club VARCHAR(10) NULL DEFAULT NULL AFTER role");
    $ogm_roles = [MEMBER_ROLE_OGM_LEADER, MEMBER_ROLE_OGM_COACH, MEMBER_ROLE_OGM_MEMBER];
    $placeholders = implode(',', array_fill(0, count($ogm_roles), '?'));
    $stmt = $pdo->prepare("UPDATE members SET club = CASE WHEN role IN ($placeholders) THEN ? ELSE ? END");
    $stmt->execute(array_merge($ogm_roles, [MEMBER_CLUB_OGMRC, MEMBER_CLUB_TOBG]));
}

// その会員の所属クラブ（'tobg' / 'ogmrc'）。所属なし・会員でなければ null
function fetch_member_club(PDO $pdo, int $user_id): ?string
{
    if ($user_id <= 0) {
        return null;
    }
    ensure_member_club_column($pdo);
    $stmt = $pdo->prepare('SELECT club FROM members WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $user_id]);
    $club = $stmt->fetchColumn();
    return is_string($club) && array_key_exists($club, MEMBER_CLUB_LABELS) ? $club : null;
}

// 管理者の会員一覧から送られた値（'tobg' / 'ogmrc' / 'none'）を、保存する値にする。不正な値は null（＝変更しない）
function member_club_from_form(string $value): array
{
    if ($value === 'none') {
        return ['valid' => true, 'club' => null];
    }
    if (array_key_exists($value, MEMBER_CLUB_LABELS)) {
        return ['valid' => true, 'club' => $value];
    }
    return ['valid' => false, 'club' => null];
}
