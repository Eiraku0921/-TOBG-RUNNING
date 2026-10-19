<?php

// 会員一覧のプルダウンに表示する順序で定義（admin はここに含めず、管理者専用として扱う）
const MEMBER_ROLE_LABELS = [
    'jaaf' => '陸連登録者',
    'member' => '一般会員',
    'ogm_leader' => 'ＯＧＭ代表',
    'ogm_coach' => 'ＯＧＭコーチ陣',
    'ogm_member' => 'ＯＧＭ会員',
];

const MEMBER_ROLE_ADMIN = 'admin';
const MEMBER_ROLE_OGM_LEADER = 'ogm_leader';
const MEMBER_ROLE_OGM_COACH = 'ogm_coach';
const MEMBER_ROLE_OGM_MEMBER = 'ogm_member';

// OGM の生徒（ogm_member）の練習日記にコメントを書けるのは、コーチ陣・コーチ代表者・管理者と、日記を書いた本人だけ。
// ほかの会員は、コメントを読むことはできるが書き込めない。それ以外の会員の日記には、ログイン中なら誰でも書ける
function member_can_comment_on_diary(string $commenter_role, string $diary_owner_role, bool $is_own_diary): bool
{
    if ($diary_owner_role !== MEMBER_ROLE_OGM_MEMBER || $is_own_diary) {
        return true;
    }
    return in_array($commenter_role, [MEMBER_ROLE_OGM_COACH, MEMBER_ROLE_OGM_LEADER, MEMBER_ROLE_ADMIN], true);
}

// コーチとして扱う権限（みんなのページの「コーチの投稿」の絞り込みと札に使う）
function member_role_is_coach(string $role): bool
{
    return in_array($role, [MEMBER_ROLE_OGM_COACH, MEMBER_ROLE_OGM_LEADER], true);
}

// OGM会員の練習日記に OGMコーチがコメントしたときだけ「コーチ」と表示する
function is_ogm_coach_comment(string $commenter_role, string $diary_owner_role): bool
{
    return $commenter_role === MEMBER_ROLE_OGM_COACH && $diary_owner_role === MEMBER_ROLE_OGM_MEMBER;
}

/**
 * role 列を VARCHAR(20) で用意する。ENUM など新しい値が入らない型の場合は変換する。
 */
function ensure_member_role_column(PDO $pdo): void
{
    $stmt = $pdo->query("SHOW COLUMNS FROM members LIKE 'role'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$column) {
        $pdo->exec("ALTER TABLE members ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'member'");
        return;
    }

    $type = strtolower((string)$column['Type']);
    if (preg_match('/^varchar\((\d+)\)$/', $type, $m) && (int)$m[1] >= 20) {
        return;
    }

    $pdo->exec("ALTER TABLE members MODIFY COLUMN role VARCHAR(20) NOT NULL DEFAULT 'member'");
}

function member_role_label(string $role): string
{
    if ($role === MEMBER_ROLE_ADMIN) {
        return '管理者';
    }
    return MEMBER_ROLE_LABELS[$role] ?? MEMBER_ROLE_LABELS['member'];
}
