<?php

// お知らせの本文の下に出すボタン。ボタンの文字（announcements.link_label）と行き先（announcements.link_url）は管理者が自由に書く。
// 行き先は、https:// などで始まる URL か、サイト内のページの場所（例 front/group_guide.php）だけを受け付ける
// （javascript: などの危険なリンクや、打ち間違いでおかしな場所に飛ばないようにするため）。
// 以前は決まったページの中から選ぶ形（announcements.link_key）だったので、あれば link_url に移してから列を消す。

const ANNOUNCEMENT_LINK_LABEL_MAX = 30;
const ANNOUNCEMENT_LINK_URL_MAX = 500;
const ANNOUNCEMENT_LINK_DEFAULT_LABEL = '詳細';

// 行き先の入力欄に出す候補（サイト内のよく使うページ）
const ANNOUNCEMENT_LINK_SUGGESTIONS = [
    'front/add_to_home.php' => 'ホーム画面への追加方法',
    'front/group_guide.php' => 'グループ機能の使い方',
];

// 以前の「決まったページ」のキーと、その行き先（移すときだけ使う）
const ANNOUNCEMENT_LEGACY_LINK_PATHS = [
    'home_guide' => 'front/add_to_home.php',
    'group_guide' => 'front/group_guide.php',
];

function announcement_column_exists(PDO $pdo, string $column_name): bool
{
    return (bool)$pdo->query('SHOW COLUMNS FROM announcements LIKE ' . $pdo->quote($column_name))->fetch(PDO::FETCH_ASSOC);
}

function ensure_announcement_link_column(PDO $pdo): void
{
    if (!announcement_column_exists($pdo, 'link_label')) {
        $pdo->exec('ALTER TABLE announcements ADD COLUMN link_label VARCHAR(' . ANNOUNCEMENT_LINK_LABEL_MAX . ') NULL AFTER body');
    }
    if (!announcement_column_exists($pdo, 'link_url')) {
        $pdo->exec('ALTER TABLE announcements ADD COLUMN link_url VARCHAR(' . ANNOUNCEMENT_LINK_URL_MAX . ') NULL AFTER link_label');
    }
    // 重要なお知らせ（ベル・ホーム・PC の補助欄で、タイトルの前に赤い「重要」の札を出す）
    if (!announcement_column_exists($pdo, 'is_important')) {
        $pdo->exec('ALTER TABLE announcements ADD COLUMN is_important TINYINT(1) NOT NULL DEFAULT 0 AFTER link_url');
    }
    // 以前の link_key を link_url に移して、列を消す（1回だけ）
    if (announcement_column_exists($pdo, 'link_key')) {
        $migrate = $pdo->prepare('UPDATE announcements SET link_url = :url WHERE link_key = :key AND link_url IS NULL');
        foreach (ANNOUNCEMENT_LEGACY_LINK_PATHS as $legacy_key => $legacy_path) {
            $migrate->execute([':url' => $legacy_path, ':key' => $legacy_key]);
        }
        $pdo->exec('ALTER TABLE announcements DROP COLUMN link_key');
    }
}

// 行き先の入力を確かめる。空なら null（ボタンなし）。受け付けない形なら例外
function normalize_announcement_link_url(string $raw): ?string
{
    $url = trim($raw);
    if ($url === '') {
        return null;
    }
    if (mb_strlen($url, 'UTF-8') > ANNOUNCEMENT_LINK_URL_MAX) {
        throw new RuntimeException('ボタンの行き先は' . ANNOUNCEMENT_LINK_URL_MAX . '文字以内で入力してください。');
    }
    if (preg_match('#^https?://#i', $url)) {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('ボタンの行き先の URL が正しくありません。');
        }
        return $url;
    }
    // サイト内のページ: 「/」で始まっても受け付け、「//」で始まるもの（別のサイト扱いになる）や「:」を含むものは受け付けない
    $path = ltrim($url, '/');
    if ($path === '' || str_starts_with($url, '//') || !preg_match('#^[A-Za-z0-9_\-./?=&%\#]+$#', $path) || str_contains($path, '..')) {
        throw new RuntimeException('ボタンの行き先は「https://」で始まる URL か、サイト内のページ（例 front/group_guide.php）を入力してください。');
    }
    // 「tobg.sadist.jp/…」のように https:// を付け忘れた URL は、サイト内のページと取り違えるので受け付けない
    $first_segment = (string)preg_split('#[/?\#]#', $path)[0];
    if (preg_match('/\.[a-z]{2,}$/i', $first_segment) && !preg_match('/\.(php|html?)$/i', $first_segment)) {
        throw new RuntimeException('サイトの外の URL は「https://」から入力してください（例 https://' . $path . '）。');
    }
    return $path;
}

// ボタンの文字。ボタンなしなら null、空欄なら既定の文字。長すぎるときは例外
function normalize_announcement_link_label(?string $link_url, string $raw): ?string
{
    if ($link_url === null) {
        return null;
    }
    $label = trim(preg_replace('/\s+/u', ' ', $raw));
    if ($label === '') {
        return ANNOUNCEMENT_LINK_DEFAULT_LABEL;
    }
    if (mb_strlen($label, 'UTF-8') > ANNOUNCEMENT_LINK_LABEL_MAX) {
        throw new RuntimeException('ボタンの文字は' . ANNOUNCEMENT_LINK_LABEL_MAX . '文字以内で入力してください。');
    }
    return $label;
}

// 表示用のボタン。ボタンなしなら null。
// href はサイト外ならそのまま、サイト内ならサイトの一番上からの場所（呼ぶ側で必要なら ../ などを付ける）。external はサイト外か
function announcement_link(?string $link_url, ?string $link_label = null): ?array
{
    $link_url = trim((string)$link_url);
    if ($link_url === '') {
        return null;
    }
    $label = trim((string)$link_label);
    return [
        'label' => $label !== '' ? $label : ANNOUNCEMENT_LINK_DEFAULT_LABEL,
        'href' => $link_url,
        'external' => (bool)preg_match('#^https?://#i', $link_url),
    ];
}

// 公開中のお知らせ（表示順 → 新しい順）。$limit が 0 なら全部。ホームと PC の右の補助欄で使う
function fetch_published_announcements(PDO $pdo, int $limit = 0): array
{
    ensure_announcement_link_column($pdo);
    $sql = "SELECT id, title, body, link_url, link_label, is_important, display_order, created_at
         FROM announcements
         WHERE is_published = 1
           AND (publish_start IS NULL OR publish_start <= NOW())
           AND (publish_end IS NULL OR publish_end >= NOW())
         ORDER BY display_order ASC, created_at DESC, id DESC";
    if ($limit > 0) {
        $sql .= ' LIMIT ' . $limit;
    }
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
