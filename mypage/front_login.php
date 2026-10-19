<?php
require_once __DIR__ . '/../back/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/../back/db_connect.php';
require_once __DIR__ . '/../back/training_diary_lib.php';
require_once __DIR__ . '/../back/gallery_lib.php';
require_once __DIR__ . '/../back/member_avatar_lib.php';
require_once __DIR__ . '/../back/member_role_lib.php';
require_once __DIR__ . '/../back/inquiry_lib.php';
require_once __DIR__ . '/../back/announcement_lib.php';
require_once __DIR__ . '/../back/mail_lib.php';
require_once __DIR__ . '/../back/entry_status_lib.php';
require_once __DIR__ . '/../back/tournament_notification_lib.php';
require_once __DIR__ . '/../back/notification_lib.php';
require_once __DIR__ . '/../back/web_push_lib.php';
require_once __DIR__ . '/../back/records_lib.php';
require_once __DIR__ . '/../back/password_reset_lib.php';
require_once __DIR__ . '/../back/admin_dashboard_lib.php';
require_once __DIR__ . '/../back/member_club_lib.php';
require_once __DIR__ . '/../back/training_summary_lib.php';
require_once __DIR__ . '/../back/admin_menu_lib.php';
require_once __DIR__ . '/../back/pickup_member_lib.php';
// 大会の表の列の用意・同じ大会の確認・陸連登録者の大会追加（クラブ画面の窓と共通）
require_once __DIR__ . '/../back/tournament_add_lib.php';
$cleanup_lib = __DIR__ . '/../back/tournament_cleanup_lib.php';
if (file_exists($cleanup_lib)) {
    require_once $cleanup_lib;
}
if (!function_exists('cleanup_expired_tournament_data')) {
    function cleanup_expired_tournament_data(PDO $pdo): void
    {
        $pdo->exec("DELETE m FROM matches m INNER JOIN (SELECT event_name, MAX(event_date) AS event_date FROM tournaments GROUP BY event_name) t ON t.event_name = m.match_title WHERE t.event_date < CURDATE()");
        $pdo->exec("DELETE FROM tournaments WHERE event_date < CURDATE()");
    }
}

if (!isset($_SESSION['user_id'], $_SESSION['user_name'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$user_name = (string)$_SESSION['user_name'];
$flash_message = '';
$training_flash = (string)($_SESSION['training_flash'] ?? '');
$training_error = (string)($_SESSION['training_error'] ?? '');
$profile_flash = (string)($_SESSION['profile_flash'] ?? '');
$profile_error = (string)($_SESSION['profile_error'] ?? '');
$password_change_error = (string)($_SESSION['password_change_error'] ?? '');
$admin_flash = (string)($_SESSION['admin_flash'] ?? '');
$admin_error = (string)($_SESSION['admin_error'] ?? '');
unset($_SESSION['training_flash'], $_SESSION['training_error']);
unset($_SESSION['profile_flash'], $_SESSION['profile_error'], $_SESSION['password_change_error']);
unset($_SESSION['admin_flash'], $_SESSION['admin_error']);

$is_admin = (string)($_SESSION['user_role'] ?? '') === 'admin';

// 練習日記の公開範囲に選べるグループ（参加中で、削除申請中でないもの）
require_once __DIR__ . '/../back/member_group_lib.php';
$diary_post_groups = [];
try {
    ensure_member_group_tables($pdo);
    $diary_post_groups = array_values(array_filter(
        fetch_member_groups_for_user($pdo, (int)$_SESSION['user_id']),
        static fn(array $group): bool => $group['status'] === MEMBER_GROUP_STATUS_ACTIVE
    ));
} catch (PDOException $e) {
    error_log('diary post groups failed: ' . $e->getMessage());
}

function find_first_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

function table_columns(PDO $pdo, string $table_name): array
{
    $stmt = $pdo->query('SHOW COLUMNS FROM ' . $table_name);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $names = [];
    foreach ($rows as $row) {
        if (!empty($row['Field'])) {
            $names[] = $row['Field'];
        }
    }
    return $names;
}

function ensure_announcements_table(PDO $pdo): void
{
    $sql = "
        CREATE TABLE IF NOT EXISTS announcements (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(150) NOT NULL,
            body TEXT NOT NULL,
            display_order INT NOT NULL DEFAULT 1,
            is_published TINYINT(1) NOT NULL DEFAULT 1,
            publish_start DATETIME NULL,
            publish_end DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_announcements_order (display_order),
            INDEX idx_announcements_published (is_published),
            INDEX idx_announcements_period (publish_start, publish_end)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    $pdo->exec($sql);
    ensure_announcement_link_column($pdo);
}

function normalize_record_event_category(string $gender, string $discipline): string
{
    if ($gender === 'female' && $discipline === 'track') {
        return '女子トラック';
    }
    if ($gender === 'female' && $discipline === 'field') {
        return '女子フィールド';
    }
    if ($gender === 'male' && $discipline === 'field') {
        return 'フィールド';
    }
    return 'トラック';
}

function build_record_sort_order(string $gender, string $discipline, string $event_type, array $track_events, array $field_events): int
{
    $event_list = $discipline === 'field' ? $field_events : $track_events;
    $index = array_search($event_type, $event_list, true);
    $index_value = $index === false ? 99 : (int)$index;

    $base = 100;
    if ($gender === 'male' && $discipline === 'field') {
        $base = 200;
    } else if ($gender === 'female' && $discipline === 'track') {
        $base = 300;
    } else if ($gender === 'female' && $discipline === 'field') {
        $base = 400;
    }

    return $base + $index_value;
}

$record_track_events = ['100m', '200m', '400m', '800m', '1000m(非公認)', '1500m', '5000m', 'ハーフマラソン', 'フルマラソン'];
$record_field_events = ['走幅跳', '三段跳', '走高跳', '棒高跳', '砲丸投', '円盤投', 'やり投'];
$record_event_options = [
    'male_track' => $record_track_events,
    'male_field' => $record_field_events,
    'female_track' => $record_track_events,
    'female_field' => $record_field_events,
];

if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_action'])) {
    $admin_action = trim((string)($_POST['admin_action'] ?? ''));
    $redirect_anchor = trim((string)($_POST['redirect_anchor'] ?? ''));
    $redirect_url = 'front_login.php' . ($redirect_anchor !== '' ? '#' . rawurlencode($redirect_anchor) : '');

    try {
        ensure_training_diary_table($pdo);
        ensure_training_diary_weight_sets_table($pdo);
        ensure_training_diary_running_tables($pdo);
        ensure_training_diary_race_entries_table($pdo);
        ensure_gallery_table($pdo);
        ensure_gallery_interaction_tables($pdo);
        ensure_announcements_table($pdo);
        ensure_tournament_link_column($pdo);
        ensure_tournament_entry_deadline_column($pdo);
        ensure_tournament_deadline_lead_days_column($pdo);
        ensure_inquiries_table($pdo);
        ensure_member_gender_column($pdo);
        ensure_member_email_column($pdo);

        if ($admin_action === 'update_match_status') {
            $match_id = (int)($_POST['match_id'] ?? 0);
            $next_status = trim((string)($_POST['status'] ?? ''));
            $allowed_status = ['承認', '未承認', '閲覧'];

            if ($match_id <= 0 || !in_array($next_status, $allowed_status, true)) {
                throw new RuntimeException('ステータス更新の入力が不正です。');
            }

            update_match_status_and_notify($pdo, $match_id, $next_status);
            $_SESSION['admin_flash'] = '申込ステータスを更新しました。';
        } else if ($admin_action === 'add_tournament') {
            $event_date = trim((string)($_POST['event_date'] ?? ''));
            $event_name = trim((string)($_POST['event_name'] ?? ''));
            $location = trim((string)($_POST['location'] ?? ''));
            $details = trim((string)($_POST['details'] ?? ''));
            $official_link = trim((string)($_POST['official_link'] ?? ''));
            $entry_deadline = trim((string)($_POST['entry_deadline'] ?? ''));
            $deadline_lead_days = (int)($_POST['deadline_lead_days'] ?? 3);
            if ($deadline_lead_days < 1 || $deadline_lead_days > 5) {
                $deadline_lead_days = 3;
            }

            if ($event_date === '' || $event_name === '' || $location === '' || $details === '') {
                throw new RuntimeException('大会追加の必須項目を入力してください。');
            }
            if (tournament_duplicate_exists($pdo, $event_name, $event_date)) {
                throw new RuntimeException(TOURNAMENT_DUPLICATE_MESSAGE);
            }

            $stmt = $pdo->prepare('INSERT INTO tournaments (event_date, entry_deadline, deadline_lead_days, event_name, location, details, official_link) VALUES (:event_date, :entry_deadline, :deadline_lead_days, :event_name, :location, :details, :official_link)');
            $stmt->execute([
                ':event_date' => $event_date,
                ':entry_deadline' => $entry_deadline !== '' ? $entry_deadline : null,
                ':deadline_lead_days' => $deadline_lead_days,
                ':event_name' => $event_name,
                ':location' => $location,
                ':details' => $details,
                ':official_link' => $official_link !== '' ? $official_link : null,
            ]);
            $new_tournament_id = (int)$pdo->lastInsertId();
            notify_jaaf_members_of_new_tournament($pdo, $new_tournament_id, $user_id);
            create_notification(
                $pdo,
                NOTIFICATION_SOURCE_TOURNAMENT,
                $new_tournament_id,
                $event_name . 'の大会情報が追加されました',
                tournament_page_path($new_tournament_id)
            );
            $_SESSION['admin_flash'] = '大会を追加しました。';
        } else if ($admin_action === 'update_tournament') {
            $tournament_id = (int)($_POST['tournament_id'] ?? 0);
            $event_date = trim((string)($_POST['event_date'] ?? ''));
            $event_name = trim((string)($_POST['event_name'] ?? ''));
            $location = trim((string)($_POST['location'] ?? ''));
            $details = trim((string)($_POST['details'] ?? ''));
            $official_link = trim((string)($_POST['official_link'] ?? ''));
            $entry_deadline = trim((string)($_POST['entry_deadline'] ?? ''));
            $deadline_lead_days = (int)($_POST['deadline_lead_days'] ?? 3);
            if ($deadline_lead_days < 1 || $deadline_lead_days > 5) {
                $deadline_lead_days = 3;
            }

            if ($tournament_id <= 0 || $event_date === '' || $event_name === '' || $location === '' || $details === '') {
                throw new RuntimeException('大会編集の入力が不正です。');
            }
            if (tournament_duplicate_exists($pdo, $event_name, $event_date, $tournament_id)) {
                throw new RuntimeException(TOURNAMENT_DUPLICATE_MESSAGE);
            }

            $stmt = $pdo->prepare('UPDATE tournaments SET event_date = :event_date, entry_deadline = :entry_deadline, deadline_lead_days = :deadline_lead_days, event_name = :event_name, location = :location, details = :details, official_link = :official_link WHERE id = :id');
            $stmt->execute([
                ':event_date' => $event_date,
                ':entry_deadline' => $entry_deadline !== '' ? $entry_deadline : null,
                ':deadline_lead_days' => $deadline_lead_days,
                ':event_name' => $event_name,
                ':location' => $location,
                ':details' => $details,
                ':official_link' => $official_link !== '' ? $official_link : null,
                ':id' => $tournament_id,
            ]);
            $_SESSION['admin_flash'] = '大会情報を更新しました。';
        } else if ($admin_action === 'delete_tournament') {
            $tournament_id = (int)($_POST['tournament_id'] ?? 0);
            if ($tournament_id <= 0) {
                throw new RuntimeException('削除対象の大会が不正です。');
            }

            $name_stmt = $pdo->prepare('SELECT event_name FROM tournaments WHERE id = :id LIMIT 1');
            $name_stmt->execute([':id' => $tournament_id]);
            $event_name = (string)($name_stmt->fetchColumn() ?: '');

            if ($event_name !== '') {
                $delete_match_stmt = $pdo->prepare('DELETE FROM matches WHERE match_title = :match_title');
                $delete_match_stmt->execute([':match_title' => $event_name]);
            }

            $delete_stmt = $pdo->prepare('DELETE FROM tournaments WHERE id = :id');
            $delete_stmt->execute([':id' => $tournament_id]);
            delete_notification_for_source($pdo, NOTIFICATION_SOURCE_TOURNAMENT, $tournament_id);
            delete_notification_for_source($pdo, NOTIFICATION_SOURCE_TOURNAMENT_DEADLINE, $tournament_id);
            $_SESSION['admin_flash'] = '大会および関連申込を削除しました。';
        } else if ($admin_action === 'manual_cleanup') {
            cleanup_expired_tournament_data($pdo);
            $_SESSION['admin_flash'] = '開催日超過データのクリーンアップを実行しました。';
        } else if ($admin_action === 'delete_diary') {
            $diary_id = (int)($_POST['diary_id'] ?? 0);
            if ($diary_id <= 0) {
                throw new RuntimeException('削除対象の投稿が不正です。');
            }

            $path_stmt = $pdo->prepare('SELECT image_path, video_path FROM training_diaries WHERE id = :id LIMIT 1');
            $path_stmt->execute([':id' => $diary_id]);
            $paths = $path_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $delete_stmt = $pdo->prepare('DELETE FROM training_diaries WHERE id = :id');
            $delete_stmt->execute([':id' => $diary_id]);

            $delete_weight_sets_stmt = $pdo->prepare('DELETE FROM training_diary_weight_sets WHERE diary_id = :diary_id');
            $delete_weight_sets_stmt->execute([':diary_id' => $diary_id]);

            $delete_running_laps_stmt = $pdo->prepare(
                'DELETE FROM training_diary_running_laps WHERE running_set_id IN (SELECT id FROM training_diary_running_sets WHERE diary_id = :diary_id)'
            );
            $delete_running_laps_stmt->execute([':diary_id' => $diary_id]);

            $delete_running_sets_stmt = $pdo->prepare('DELETE FROM training_diary_running_sets WHERE diary_id = :diary_id');
            $delete_running_sets_stmt->execute([':diary_id' => $diary_id]);

            $delete_race_entries_stmt = $pdo->prepare('DELETE FROM training_diary_race_entries WHERE diary_id = :diary_id');
            $delete_race_entries_stmt->execute([':diary_id' => $diary_id]);

            foreach (['image_path', 'video_path'] as $field) {
                $path = trim((string)($paths[$field] ?? ''));
                if ($path === '' || strpos($path, '..') !== false) {
                    continue;
                }
                $abs = __DIR__ . '/../' . ltrim(str_replace('\\', '/', $path), '/');
                if (is_file($abs)) {
                    @unlink($abs);
                }
            }

            $_SESSION['admin_flash'] = '練習日記投稿を削除しました。';
        } else if ($admin_action === 'delete_gallery_post') {
            $gallery_id = (int)($_POST['gallery_id'] ?? 0);
            if ($gallery_id <= 0) {
                throw new RuntimeException('削除対象のギャラリー投稿が不正です。');
            }

            $path_stmt = $pdo->prepare(
                'SELECT image_path_1, image_path_2, video_path_1, video_path_2 FROM gallery_posts WHERE id = :id LIMIT 1'
            );
            $path_stmt->execute([':id' => $gallery_id]);
            $paths = $path_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $pdo->beginTransaction();

            $delete_comment_stmt = $pdo->prepare('DELETE FROM gallery_comments WHERE gallery_id = :gallery_id');
            $delete_comment_stmt->execute([':gallery_id' => $gallery_id]);

            $delete_like_stmt = $pdo->prepare('DELETE FROM gallery_likes WHERE gallery_id = :gallery_id');
            $delete_like_stmt->execute([':gallery_id' => $gallery_id]);

            $delete_post_stmt = $pdo->prepare('DELETE FROM gallery_posts WHERE id = :id');
            $delete_post_stmt->execute([':id' => $gallery_id]);

            if ((int)$delete_post_stmt->rowCount() <= 0) {
                $pdo->rollBack();
                throw new RuntimeException('削除対象のギャラリー投稿が見つかりません。');
            }

            $pdo->commit();

            foreach (['image_path_1', 'image_path_2', 'video_path_1', 'video_path_2'] as $field) {
                $path = trim((string)($paths[$field] ?? ''));
                if ($path === '' || strpos($path, '..') !== false) {
                    continue;
                }
                $abs = __DIR__ . '/../' . ltrim(str_replace('\\', '/', $path), '/');
                if (is_file($abs)) {
                    @unlink($abs);
                }
            }

            $_SESSION['admin_flash'] = 'ギャラリー投稿を削除しました。';
        } else if ($admin_action === 'add_announcement') {
            $title = trim((string)($_POST['title'] ?? ''));
            $body = trim((string)($_POST['body'] ?? ''));
            $display_order = (int)($_POST['display_order'] ?? 1);
            if ($display_order < 1 || $display_order > 3) {
                $display_order = 1;
            }
            $is_published = (int)($_POST['is_published'] ?? 0) === 1 ? 1 : 0;

            if ($title === '' || $body === '') {
                throw new RuntimeException('お知らせ追加の必須項目を入力してください。');
            }
            // ボタン: 「ボタンあり」のときだけ、行き先（URL かサイト内のページ）とボタンの文字を保存する
            $link_url = null;
            if ((string)($_POST['link_enabled'] ?? '') === '1') {
                $link_url = normalize_announcement_link_url((string)($_POST['link_url'] ?? ''));
                if ($link_url === null) {
                    throw new RuntimeException('ボタンの行き先を入力してください。');
                }
            }
            $link_label = normalize_announcement_link_label($link_url, (string)($_POST['link_label'] ?? ''));
            $is_important = (string)($_POST['is_important'] ?? '') === '1' ? 1 : 0;
            ensure_announcement_link_column($pdo);
            $stmt = $pdo->prepare('INSERT INTO announcements (title, body, link_url, link_label, is_important, display_order, is_published) VALUES (:title, :body, :link_url, :link_label, :is_important, :display_order, :is_published)');
            $stmt->execute([
                ':title' => $title,
                ':body' => $body,
                ':link_url' => $link_url,
                ':link_label' => $link_label,
                ':is_important' => $is_important,
                ':display_order' => $display_order,
                ':is_published' => $is_published,
            ]);
            if ($is_published === 1) {
                $announcement_id = (int)$pdo->lastInsertId();
                if (create_notification($pdo, NOTIFICATION_SOURCE_ANNOUNCEMENT, $announcement_id, $title, 'index.php#newsTitle')) {
                    push_announcement_to_members($pdo, $announcement_id, $title, $body);
                }
            }
            $_SESSION['admin_flash'] = 'お知らせを追加しました。';
        } else if ($admin_action === 'update_announcement') {
            $announcement_id = (int)($_POST['announcement_id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $body = trim((string)($_POST['body'] ?? ''));
            $display_order = (int)($_POST['display_order'] ?? 1);
            if ($display_order < 1 || $display_order > 3) {
                $display_order = 1;
            }
            $is_published = (int)($_POST['is_published'] ?? 0) === 1 ? 1 : 0;

            if ($announcement_id <= 0 || $title === '' || $body === '') {
                throw new RuntimeException('お知らせ編集の入力が不正です。');
            }
            // ボタン: 「ボタンあり」のときだけ、行き先（URL かサイト内のページ）とボタンの文字を保存する
            $link_url = null;
            if ((string)($_POST['link_enabled'] ?? '') === '1') {
                $link_url = normalize_announcement_link_url((string)($_POST['link_url'] ?? ''));
                if ($link_url === null) {
                    throw new RuntimeException('ボタンの行き先を入力してください。');
                }
            }
            $link_label = normalize_announcement_link_label($link_url, (string)($_POST['link_label'] ?? ''));
            $is_important = (string)($_POST['is_important'] ?? '') === '1' ? 1 : 0;
            ensure_announcement_link_column($pdo);
            $stmt = $pdo->prepare('UPDATE announcements SET title = :title, body = :body, link_url = :link_url, link_label = :link_label, is_important = :is_important, display_order = :display_order, is_published = :is_published WHERE id = :id');
            $stmt->execute([
                ':title' => $title,
                ':body' => $body,
                ':link_url' => $link_url,
                ':link_label' => $link_label,
                ':is_important' => $is_important,
                ':display_order' => $display_order,
                ':is_published' => $is_published,
                ':id' => $announcement_id,
            ]);
            // 非公開→公開にしたときだけ通知が増える（公開中の編集では二重に作られない）。非公開にしたら通知も消す
            if ($is_published === 1) {
                // 非公開→公開にしたときだけ新しい通知ができるので、そのときだけプッシュ通知も送る
                if (create_notification($pdo, NOTIFICATION_SOURCE_ANNOUNCEMENT, $announcement_id, $title, 'index.php#newsTitle')) {
                    push_announcement_to_members($pdo, $announcement_id, $title, $body);
                }
            } else {
                delete_notification_for_source($pdo, NOTIFICATION_SOURCE_ANNOUNCEMENT, $announcement_id);
            }
            $_SESSION['admin_flash'] = 'お知らせを更新しました。';
        } else if ($admin_action === 'delete_announcement') {
            $announcement_id = (int)($_POST['announcement_id'] ?? 0);
            if ($announcement_id <= 0) {
                throw new RuntimeException('削除対象のお知らせが不正です。');
            }

            $stmt = $pdo->prepare('DELETE FROM announcements WHERE id = :id');
            $stmt->execute([':id' => $announcement_id]);
            delete_notification_for_source($pdo, NOTIFICATION_SOURCE_ANNOUNCEMENT, $announcement_id);
            $_SESSION['admin_flash'] = 'お知らせを削除しました。';
        } else if ($admin_action === 'admin_rename_group') {
            ensure_member_group_tables($pdo);
            $new_group_name = (string)($_POST['group_name'] ?? '');
            $old_group_name = admin_rename_member_group($pdo, (int)($_POST['group_id'] ?? 0), $new_group_name);
            $_SESSION['admin_flash'] = 'グループ名を「' . $old_group_name . '」から「' . member_group_normalize_name($new_group_name) . '」に変更しました。';
        } else if ($admin_action === 'admin_delete_group') {
            // 管理者による削除は、メンバーが2人以下のグループだけ（それ以外はグループからの削除申請が必要）
            ensure_member_group_tables($pdo);
            $deleted_group_name = admin_delete_small_member_group($pdo, (int)$_SESSION['user_id'], (int)($_POST['group_id'] ?? 0));
            $_SESSION['admin_flash'] = 'グループ「' . $deleted_group_name . '」を削除しました。';
        } else if ($admin_action === 'approve_group_delete' || $admin_action === 'reject_group_delete') {
            // グループの削除申請: 承認ならグループを消す（そのグループだけに公開していた日記は本人のカレンダーだけに残る）、却下なら通常に戻す
            $approve = $admin_action === 'approve_group_delete';
            ensure_member_group_tables($pdo);
            process_member_group_delete_request($pdo, (int)$_SESSION['user_id'], (int)($_POST['request_id'] ?? 0), $approve);
            $_SESSION['admin_flash'] = $approve ? 'グループを削除しました。' : '削除申請を却下しました。';
        } else if ($admin_action === 'delete_inquiry') {
            $inquiry_id = (int)($_POST['inquiry_id'] ?? 0);
            if ($inquiry_id <= 0) {
                throw new RuntimeException('削除対象のお問い合わせが不正です。');
            }

            $stmt = $pdo->prepare('DELETE FROM inquiries WHERE id = :id');
            $stmt->execute([':id' => $inquiry_id]);
            $_SESSION['admin_flash'] = 'お問い合わせを削除しました。';
        } else if ($admin_action === 'add_record_admin') {
            $gender = trim((string)($_POST['record_gender'] ?? 'male'));
            $discipline = trim((string)($_POST['record_discipline'] ?? 'track'));
            $event_type = trim((string)($_POST['event_type'] ?? ''));
            $rank_num = (int)($_POST['rank_num'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $record_time = trim((string)($_POST['record_time'] ?? ''));
            $match_date = trim((string)($_POST['match_date'] ?? ''));
            $match_name = trim((string)($_POST['match_name'] ?? ''));

            $valid_genders = ['male', 'female'];
            $valid_disciplines = ['track', 'field'];
            if (!in_array($gender, $valid_genders, true) || !in_array($discipline, $valid_disciplines, true)) {
                throw new RuntimeException('区分の入力が不正です。');
            }

            $event_key = $gender . '_' . $discipline;
            $event_list = $record_event_options[$event_key] ?? [];

            if (!in_array($event_type, $event_list, true)) {
                throw new RuntimeException('種目を選択してください。');
            }
            if ($rank_num <= 0 || $name === '' || $record_time === '' || $match_date === '' || $match_name === '') {
                throw new RuntimeException('歴代記録追加の必須項目を入力してください。');
            }
            $wind_mps = parse_admin_record_wind($event_type, (string)($_POST['wind_mps'] ?? ''));

            $event_category = normalize_record_event_category($gender, $discipline);
            $sort_order = build_record_sort_order($gender, $discipline, $event_type, $record_track_events, $record_field_events);

            ensure_records_wind_column($pdo);
            $stmt = $pdo->prepare(
                'INSERT INTO records (event_category, event_type, sort_order, rank_num, name, record_time, wind_mps, match_date, match_name)
                 VALUES (:event_category, :event_type, :sort_order, :rank_num, :name, :record_time, :wind_mps, :match_date, :match_name)'
            );
            $stmt->execute([
                ':event_category' => $event_category,
                ':event_type' => $event_type,
                ':sort_order' => $sort_order,
                ':rank_num' => $rank_num,
                ':name' => $name,
                ':record_time' => $record_time,
                ':wind_mps' => $wind_mps,
                ':match_date' => $match_date,
                ':match_name' => $match_name,
            ]);
            create_notification(
                $pdo,
                NOTIFICATION_SOURCE_RECORD,
                (int)$pdo->lastInsertId(),
                '歴代記録が更新されました（' . ($gender === 'female' ? '女子' : '男子') . $event_type . '・' . $rank_num . '位）',
                'front/records.php'
            );
            $_SESSION['admin_flash'] = '歴代記録を追加しました。';
        } else if ($admin_action === 'update_record_admin') {
            $record_id = (int)($_POST['record_id'] ?? 0);
            $gender = trim((string)($_POST['record_gender'] ?? 'male'));
            $discipline = trim((string)($_POST['record_discipline'] ?? 'track'));
            $event_type = trim((string)($_POST['event_type'] ?? ''));
            $rank_num = (int)($_POST['rank_num'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $record_time = trim((string)($_POST['record_time'] ?? ''));
            $match_date = trim((string)($_POST['match_date'] ?? ''));
            $match_name = trim((string)($_POST['match_name'] ?? ''));

            $valid_genders = ['male', 'female'];
            $valid_disciplines = ['track', 'field'];
            if ($record_id <= 0 || !in_array($gender, $valid_genders, true) || !in_array($discipline, $valid_disciplines, true)) {
                throw new RuntimeException('歴代記録更新の入力が不正です。');
            }

            $event_key = $gender . '_' . $discipline;
            $event_list = $record_event_options[$event_key] ?? [];

            if (!in_array($event_type, $event_list, true)) {
                throw new RuntimeException('種目を選択してください。');
            }
            if ($rank_num <= 0 || $name === '' || $record_time === '' || $match_date === '' || $match_name === '') {
                throw new RuntimeException('歴代記録更新の必須項目を入力してください。');
            }
            $wind_mps = parse_admin_record_wind($event_type, (string)($_POST['wind_mps'] ?? ''));

            $event_category = normalize_record_event_category($gender, $discipline);
            $sort_order = build_record_sort_order($gender, $discipline, $event_type, $record_track_events, $record_field_events);

            ensure_records_wind_column($pdo);
            $stmt = $pdo->prepare(
                'UPDATE records
                 SET event_category = :event_category,
                     event_type = :event_type,
                     sort_order = :sort_order,
                     rank_num = :rank_num,
                     name = :name,
                     record_time = :record_time,
                     wind_mps = :wind_mps,
                     match_date = :match_date,
                     match_name = :match_name
                 WHERE id = :id'
            );
            $stmt->execute([
                ':event_category' => $event_category,
                ':event_type' => $event_type,
                ':sort_order' => $sort_order,
                ':rank_num' => $rank_num,
                ':name' => $name,
                ':record_time' => $record_time,
                ':wind_mps' => $wind_mps,
                ':match_date' => $match_date,
                ':match_name' => $match_name,
                ':id' => $record_id,
            ]);
            $_SESSION['admin_flash'] = '歴代記録を更新しました。';
        } else if ($admin_action === 'delete_record_admin') {
            $record_id = (int)($_POST['record_id'] ?? 0);
            if ($record_id <= 0) {
                throw new RuntimeException('削除対象の歴代記録が不正です。');
            }

            $stmt = $pdo->prepare('DELETE FROM records WHERE id = :id');
            $stmt->execute([':id' => $record_id]);
            $_SESSION['admin_flash'] = '歴代記録を削除しました。';
        } else if ($admin_action === 'update_member_ids') {
            $member_id = (int)($_POST['member_id'] ?? 0);
            $jaaf_id_value = trim((string)($_POST['jaaf_id_value'] ?? ''));
            $number_plate_value = trim((string)($_POST['number_plate_value'] ?? ''));
            $gender_value = trim((string)($_POST['gender_value'] ?? ''));
            $email_value = trim((string)($_POST['email_value'] ?? ''));
            $role_value = trim((string)($_POST['role_value'] ?? ''));
            $annual_fee_value = trim((string)($_POST['annual_fee_value'] ?? ''));
            if ($annual_fee_value !== '' && !in_array($annual_fee_value, ['paid', 'unpaid'], true)) {
                throw new RuntimeException('年会費の指定が不正です。');
            }
            if ($role_value !== '' && !array_key_exists($role_value, MEMBER_ROLE_LABELS)) {
                throw new RuntimeException('権限の指定が不正です。');
            }
            if (!in_array($gender_value, ['male', 'female'], true)) {
                $gender_value = '';
            }
            if ($email_value !== '' && filter_var($email_value, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('メールアドレスの形式が不正です。');
            }

            if ($member_id <= 0) {
                throw new RuntimeException('更新対象の会員が不正です。');
            }

            $member_columns_for_update = table_columns($pdo, 'members');
            $update_jaaf_id_col = find_first_column($member_columns_for_update, ['jaaf_id', 'jaafid', 'jaaf_no']);
            $update_number_plate_col = find_first_column($member_columns_for_update, ['number_plate', 'bib_number', 'zekken_no']);
            $update_email_col = find_first_column($member_columns_for_update, ['email', 'email_address', 'mail']);

            $set_parts = [];
            $update_params = [':id' => $member_id];

            if ($update_jaaf_id_col !== null) {
                $set_parts[] = $update_jaaf_id_col . ' = :jaaf_id_value';
                $update_params[':jaaf_id_value'] = $jaaf_id_value !== '' ? $jaaf_id_value : null;
            }
            if ($update_number_plate_col !== null) {
                $set_parts[] = $update_number_plate_col . ' = :number_plate_value';
                $update_params[':number_plate_value'] = $number_plate_value !== '' ? $number_plate_value : null;
            }
            if ($gender_value !== '') {
                $set_parts[] = 'gender = :gender_value';
                $update_params[':gender_value'] = $gender_value;
            }
            if ($update_email_col !== null) {
                $set_parts[] = $update_email_col . ' = :email_value';
                $update_params[':email_value'] = $email_value !== '' ? $email_value : null;
            }
            if ($role_value !== '') {
                ensure_member_role_column($pdo);
                // 管理者の権限はこの画面から変更させない
                $set_parts[] = 'role = CASE WHEN role = :admin_role THEN role ELSE :role_value END';
                $update_params[':admin_role'] = MEMBER_ROLE_ADMIN;
                $update_params[':role_value'] = $role_value;
            }
            if ($annual_fee_value !== '') {
                $update_annual_fee_col = ensure_member_annual_fee_column($pdo);
                $set_parts[] = $update_annual_fee_col . ' = :annual_fee_value';
                $update_params[':annual_fee_value'] = $annual_fee_value === 'paid' ? 1 : 0;
            }
            // 所属クラブ（TOBG / OGMRC / 所属なし）。クラブ画面の中身がこれで決まる
            $club_value = trim((string)($_POST['club_value'] ?? ''));
            if ($club_value !== '') {
                $club = member_club_from_form($club_value);
                if (!$club['valid']) {
                    throw new RuntimeException('所属クラブの指定が不正です。');
                }
                ensure_member_club_column($pdo);
                $set_parts[] = 'club = :club_value';
                $update_params[':club_value'] = $club['club'];
            }

            // ピックアップ（みんなのページの「ピックアップ」タブに投稿を出す）。会員の表とは別の表に入れる
            $pickup_value = (string)($_POST['pickup_value'] ?? '');
            if (in_array($pickup_value, ['on', 'off'], true)) {
                set_pickup_member($pdo, $member_id, $pickup_value === 'on', $user_id);
            }

            if (empty($set_parts)) {
                throw new RuntimeException('JAAFID・ゼッケン番号・性別・メールアドレスのいずれも入力がありません。');
            }

            $update_stmt = $pdo->prepare('UPDATE members SET ' . implode(', ', $set_parts) . ' WHERE id = :id');
            $update_stmt->execute($update_params);
            $_SESSION['admin_flash'] = '会員情報を更新しました。';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['admin_error'] = $e->getMessage() !== '' ? $e->getMessage() : '管理操作中にエラーが発生しました。';
    }

    header('Location: ' . $redirect_url);
    exit;
}

$member = [];
$matches = [];
$annual_fee_col = null;
$jaaf_id_col = null;
$jaaf_registered_col = null;
$number_plate_col = null;
$email_col = null;
$my_diaries = [];
$member_avatar_path = '';
$admin_members = [];
$admin_member_columns = [];
$admin_member_display_columns = [];
$admin_member_jaaf_id_col = null;
$admin_member_number_plate_col = null;
$admin_member_email_col = null;
$admin_member_annual_fee_col = null;
$admin_tournaments = [];
$admin_entries = [];
$admin_diary_posts = [];
$admin_gallery_posts = [];
$admin_announcements = [];
$admin_records = [];
$admin_inquiries = [];
$admin_group_delete_requests = [];
$admin_member_groups = [];
$admin_pickup_ids = [];
$admin_dashboard = null;
$page_data_error = '';

try {
    ensure_training_diary_table($pdo);
    ensure_training_diary_interaction_tables($pdo);
    ensure_training_diary_weight_sets_table($pdo);
    ensure_training_diary_running_tables($pdo);
    ensure_training_diary_race_entries_table($pdo);
    ensure_training_diary_jog_table($pdo);
    ensure_gallery_table($pdo);
    ensure_gallery_interaction_tables($pdo);
    ensure_member_avatar_column($pdo);
    ensure_member_gender_column($pdo);
    ensure_member_email_column($pdo);
    ensure_tournament_link_column($pdo);
    ensure_tournament_entry_deadline_column($pdo);
    ensure_tournament_deadline_lead_days_column($pdo);
    if (function_exists('ensure_tournament_owner_columns')) {
        ensure_tournament_owner_columns($pdo);
    }
    cleanup_old_training_diaries($pdo);
    cleanup_expired_tournament_data($pdo);

    $member_columns = table_columns($pdo, 'members');
    $annual_fee_col = find_first_column($member_columns, MEMBER_ANNUAL_FEE_COLUMN_CANDIDATES);
    $jaaf_id_col = find_first_column($member_columns, ['jaaf_id', 'jaafid', 'jaaf_no']);
    $jaaf_registered_col = find_first_column($member_columns, ['jaaf_registered', 'jaaf_registration_status', 'jaaf_registered_flag']);
    $number_plate_col = find_first_column($member_columns, ['number_plate', 'bib_number', 'zekken_no']);
    $email_col = find_first_column($member_columns, ['email', 'email_address', 'mail']);

    ensure_member_password_notice_column($pdo);
    $member_stmt = $pdo->prepare('SELECT * FROM members WHERE id = :id LIMIT 1');
    $member_stmt->execute([':id' => $user_id]);
    $member = $member_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $member_avatar_path = normalize_member_avatar_path((string)($member['avatar_image_path'] ?? ''));
    $_SESSION['user_avatar_path'] = $member_avatar_path;

    $match_stmt = $pdo->prepare(
        "SELECT
            m.id,
            m.match_title,
            m.user_name,
            m.jaaf_id,
            m.number_plate,
            m.event_name1,
            m.pb_time1,
            m.event_name2,
            m.pb_time2,
            m.event_name3,
            m.pb_time3,
            m.status,
            m.created_at,
            t.event_date,
            DATE_SUB(COALESCE(t.entry_deadline, t.event_date), INTERVAL t.deadline_lead_days DAY) AS deadline_date,
            DATEDIFF(DATE_SUB(COALESCE(t.entry_deadline, t.event_date), INTERVAL t.deadline_lead_days DAY), CURDATE()) AS days_left
         FROM matches m
         LEFT JOIN (
            SELECT event_name, MAX(event_date) AS event_date, MAX(entry_deadline) AS entry_deadline, MAX(deadline_lead_days) AS deadline_lead_days
            FROM tournaments
            GROUP BY event_name
         ) t ON t.event_name = m.match_title
         WHERE m.user_name = :user_name
         ORDER BY m.created_at DESC, m.id DESC"
    );
    $match_stmt->execute([':user_name' => $user_name]);
    $matches = $match_stmt->fetchAll(PDO::FETCH_ASSOC);

    $diary_stmt = $pdo->prepare(
        "SELECT
            id,
            practice_date,
            menu AS menu_text,
            training_type,
            record_time AS time_text,
            comment AS comment_text,
            is_public,
            intensity,
            image_path,
            video_path,
            created_at,
            updated_at,
            (SELECT GROUP_CONCAT(g.name ORDER BY g.id SEPARATOR '、')
             FROM training_diary_groups dg
             INNER JOIN member_groups g ON g.id = dg.group_id
             WHERE dg.diary_id = training_diaries.id) AS group_names,
            (SELECT GROUP_CONCAT(dg.group_id)
             FROM training_diary_groups dg
             WHERE dg.diary_id = training_diaries.id) AS group_ids
         FROM training_diaries
         WHERE user_id = :user_id
         ORDER BY practice_date DESC, id DESC"
    );
    $diary_stmt->execute([':user_id' => $user_id]);
    $my_diaries = $diary_stmt->fetchAll(PDO::FETCH_ASSOC);

    $diary_comment_summary = fetch_training_diary_comment_summary($pdo);
    $diary_like_summary = fetch_training_diary_like_summary($pdo);

    foreach ($my_diaries as &$diary_row) {
        $diary_id = (int)($diary_row['id'] ?? 0);
        $diary_row['is_public'] = (int)($diary_row['is_public'] ?? 1) === 1;
        // 公開範囲の変更フォームで、いま公開しているグループを選んだ状態にするため
        $diary_row['group_ids'] = array_values(array_map('intval', array_filter(explode(',', (string)($diary_row['group_ids'] ?? '')))));
        $diary_row['comment_count'] = (int)($diary_comment_summary[$diary_id] ?? 0);
        $diary_row['like_count'] = (int)($diary_like_summary[$diary_id] ?? 0);
        $diary_row['comments'] = $diary_id > 0 ? fetch_training_diary_comments($pdo, $diary_id) : [];
        $diary_row['liked_by_me'] = false;
        $diary_row['weight_sets'] = ($diary_id > 0 && ($diary_row['training_type'] ?? 'short_sprint') === 'weight')
            ? fetch_training_diary_weight_sets($pdo, $diary_id)
            : [];
        $diary_row['running_sets'] = ($diary_id > 0 && training_diary_is_running_type((string)($diary_row['training_type'] ?? 'short_sprint')))
            ? fetch_training_diary_running_sets($pdo, $diary_id)
            : [];
        $diary_row['race_entries'] = ($diary_id > 0 && ($diary_row['training_type'] ?? 'short_sprint') === 'race')
            ? fetch_training_diary_race_entries($pdo, $diary_id)
            : [];
        // ジョグは距離の文字にしてから渡す（例: 5.0km ＋ 3.5km（合計 8.5km））
        $diary_row['jog_text'] = ($diary_id > 0 && ($diary_row['training_type'] ?? 'short_sprint') === 'jog')
            ? format_training_diary_jog_distances(fetch_training_diary_jog_entries($pdo, $diary_id))
            : '';

        if ($diary_id > 0) {
            $like_check_stmt = $pdo->prepare('SELECT id FROM training_diary_likes WHERE diary_id = :diary_id AND user_id = :user_id LIMIT 1');
            $like_check_stmt->execute([
                ':diary_id' => $diary_id,
                ':user_id' => $user_id,
            ]);
            $diary_row['liked_by_me'] = (bool)$like_check_stmt->fetchColumn();
        }
    }
    unset($diary_row);

    if ($is_admin) {
        ensure_announcements_table($pdo);
        ensure_inquiries_table($pdo);
        ensure_member_role_column($pdo);
        ensure_member_club_column($pdo);

        $admin_member_columns = table_columns($pdo, 'members');
        $admin_member_jaaf_id_col = find_first_column($admin_member_columns, ['jaaf_id', 'jaafid', 'jaaf_no']);
        $admin_member_number_plate_col = find_first_column($admin_member_columns, ['number_plate', 'bib_number', 'zekken_no']);
        $admin_member_email_col = find_first_column($admin_member_columns, ['email', 'email_address', 'mail']);
        $admin_member_annual_fee_col = ensure_member_annual_fee_column($pdo);
        $collect_member_columns = false;
        foreach ($admin_member_columns as $column_name) {
            if ($column_name === 'id') {
                $collect_member_columns = true;
            }
            if ($column_name === 'role') {
                break;
            }
            if ($collect_member_columns && $column_name !== 'gender' && $column_name !== 'password' && $column_name !== $admin_member_annual_fee_col) {
                $admin_member_display_columns[] = $column_name;
            }
        }
        if (empty($admin_member_display_columns)) {
            foreach (['id', 'name'] as $fallback_col) {
                if (in_array($fallback_col, $admin_member_columns, true)) {
                    $admin_member_display_columns[] = $fallback_col;
                }
            }
        }
        $member_all_stmt = $pdo->query('SELECT * FROM members ORDER BY id DESC');
        $admin_members = $member_all_stmt->fetchAll(PDO::FETCH_ASSOC);
        // みんなのページの「ピックアップ」に出す会員（会員一覧の「ピックアップ」の列）
        $admin_pickup_ids = fetch_pickup_member_ids($pdo);

        $admin_tournament_stmt = $pdo->query(
            "SELECT
                id,
                event_date,
                entry_deadline,
                deadline_lead_days,
                event_name,
                location,
                details,
                official_link,
                DATE_SUB(COALESCE(entry_deadline, event_date), INTERVAL deadline_lead_days DAY) AS display_deadline
             FROM tournaments
             ORDER BY event_date DESC, id DESC"
        );
        $admin_tournaments = $admin_tournament_stmt->fetchAll(PDO::FETCH_ASSOC);

        $admin_entry_stmt = $pdo->query(
            "SELECT
                m.id,
                m.match_title,
                m.user_name,
                m.jaaf_id,
                m.number_plate,
                m.event_name1,
                m.pb_time1,
                m.event_name2,
                m.pb_time2,
                m.event_name3,
                m.pb_time3,
                m.status,
                m.created_at,
                t.event_date
             FROM matches m
             LEFT JOIN (
                SELECT event_name, MAX(event_date) AS event_date
                FROM tournaments
                GROUP BY event_name
             ) t ON t.event_name = m.match_title
             ORDER BY m.created_at DESC, m.id DESC"
        );
        $admin_entries = $admin_entry_stmt->fetchAll(PDO::FETCH_ASSOC);

        $admin_diary_stmt = $pdo->query(
            "SELECT
                d.id,
                d.practice_date,
                d.menu,
                d.created_at,
                d.comment,
                COALESCE(m.name, 'メンバー') AS user_name
             FROM training_diaries d
             LEFT JOIN members m ON m.id = d.user_id
             ORDER BY d.created_at DESC, d.id DESC"
        );
        $admin_diary_posts = $admin_diary_stmt->fetchAll(PDO::FETCH_ASSOC);

        $admin_gallery_stmt = $pdo->query(
            "SELECT
                g.id,
                g.event_date,
                g.title,
                g.body,
                g.created_at,
                COALESCE(m.name, 'メンバー') AS user_name
             FROM gallery_posts g
             LEFT JOIN members m ON m.id = g.user_id
             ORDER BY g.created_at DESC, g.id DESC"
        );
        $admin_gallery_posts = $admin_gallery_stmt->fetchAll(PDO::FETCH_ASSOC);

        ensure_announcement_link_column($pdo);
        $admin_announcement_stmt = $pdo->query('SELECT id, title, body, link_url, link_label, is_important, display_order, is_published, created_at, updated_at FROM announcements ORDER BY display_order ASC, id DESC');
        $admin_announcements = $admin_announcement_stmt->fetchAll(PDO::FETCH_ASSOC);

        ensure_records_wind_column($pdo);
        $admin_record_stmt = $pdo->query(
            "SELECT
                id,
                event_category,
                event_type,
                sort_order,
                rank_num,
                name,
                record_time,
                wind_mps,
                match_date,
                match_name,
                CASE
                    WHEN event_category IN ('女子トラック', 'トラック女子') THEN 'female'
                    WHEN event_category IN ('女子フィールド', 'フィールド女子') THEN 'female'
                    ELSE 'male'
                END AS ui_gender,
                CASE
                    WHEN event_category IN ('フィールド', '男子フィールド', 'フィールド男子', '女子フィールド', 'フィールド女子') THEN 'field'
                    ELSE 'track'
                END AS ui_discipline
             FROM records
             ORDER BY
                CASE
                    WHEN event_category IN ('トラック', '男子トラック', 'トラック男子') THEN 1
                    WHEN event_category IN ('女子トラック', 'トラック女子') THEN 2
                    WHEN event_category IN ('フィールド', '男子フィールド', 'フィールド男子') THEN 3
                    WHEN event_category IN ('女子フィールド', 'フィールド女子') THEN 4
                    ELSE 9
                END ASC,
                CASE event_type
                    WHEN '100m' THEN 1
                    WHEN '200m' THEN 2
                    WHEN '400m' THEN 3
                    WHEN '800m' THEN 4
                    WHEN '1000m' THEN 5
                    WHEN '1000m(非公認)' THEN 5
                    WHEN '1500m' THEN 6
                    WHEN '5000m' THEN 7
                    WHEN 'ハーフマラソン' THEN 8
                    WHEN 'フルマラソン' THEN 9
                    WHEN '走幅跳' THEN 101
                    WHEN '三段跳' THEN 102
                    WHEN '走高跳' THEN 103
                    WHEN '棒高跳' THEN 104
                    WHEN '砲丸投' THEN 105
                    WHEN '円盤投' THEN 106
                    WHEN 'やり投' THEN 107
                    ELSE 999
                END ASC,
                rank_num ASC,
                id DESC"
        );
        $admin_records = $admin_record_stmt->fetchAll(PDO::FETCH_ASSOC);

        $admin_inquiry_stmt = $pdo->query(
            'SELECT id, sender_name, is_anonymous, message,
                    wants_team_membership, applicant_name, applicant_furigana, applicant_birthdate,
                    applicant_postal_code, applicant_prefecture, applicant_address, applicant_address_detail, applicant_phone,
                    created_at
             FROM inquiries ORDER BY created_at DESC, id DESC'
        );
        $admin_inquiries = $admin_inquiry_stmt->fetchAll(PDO::FETCH_ASSOC);
        $admin_group_delete_requests = fetch_pending_member_group_delete_requests($pdo);
        $admin_member_groups = fetch_all_member_groups_for_admin($pdo);
        // PC の管理画面の数字のカード。集計に失敗しても、管理画面のほかの部分は表示する
        try {
            $admin_dashboard = fetch_admin_dashboard_stats($pdo);
        } catch (PDOException $e) {
            error_log('admin dashboard stats failed: ' . $e->getMessage());
        }
    }
} catch (PDOException $e) {
    $member = [];
    $matches = [];
    $my_diaries = [];
    $member_avatar_path = '';
    $admin_members = [];
    $admin_member_columns = [];
    $admin_member_display_columns = [];
    $admin_tournaments = [];
    $admin_entries = [];
    $admin_diary_posts = [];
    $admin_gallery_posts = [];
    $admin_announcements = [];
    $admin_records = [];
    $admin_inquiries = [];
    $page_data_error = $is_admin ? $e->getMessage() : 'データの読み込みに失敗しました。時間を置いて再度お試しください。';
}

$jaaf_id_value = '';
if ($jaaf_id_col !== null && !empty($member[$jaaf_id_col])) {
    $jaaf_id_value = (string)$member[$jaaf_id_col];
} else if (!empty($matches[0]['jaaf_id'])) {
    $jaaf_id_value = (string)$matches[0]['jaaf_id'];
}

$member_email_value = '';
if ($email_col !== null && !empty($member[$email_col])) {
    $member_email_value = (string)$member[$email_col];
}
$member_has_initial_password = !empty($member) && member_uses_initial_password($member);
// 上部の注意書きは「今後表示しない」を押した会員には出さない（会員情報カードの印などはそのまま）
$show_initial_password_notice = !empty($member) && member_should_see_initial_password_notice($member);

$jaaf_registered = false;
if ($jaaf_registered_col !== null && isset($member[$jaaf_registered_col])) {
    $registered_raw = strtolower(trim((string)$member[$jaaf_registered_col]));
    $jaaf_registered = in_array($registered_raw, ['1', 'true', 'yes', '済', 'registered'], true);
    if (!$jaaf_registered && $jaaf_id_value !== '') {
        $jaaf_registered = true;
    }
} else {
    $jaaf_registered = $jaaf_id_value !== '';
}

$number_plate_value = '';
if ($number_plate_col !== null && !empty($member[$number_plate_col])) {
    $number_plate_value = (string)$member[$number_plate_col];
} else if (!empty($matches[0]['number_plate'])) {
    $number_plate_value = (string)$matches[0]['number_plate'];
}

$annual_fee_paid = false;
if ($annual_fee_col !== null && isset($member[$annual_fee_col])) {
    $annual_fee_paid = member_annual_fee_is_paid($member[$annual_fee_col]);
}

$is_diary_only_member = trim($jaaf_id_value) === '0000' && trim($number_plate_value) === '0000';
$entry_error = (string)($_SESSION['entry_error'] ?? '');
unset($_SESSION['entry_error']);

// ページ上部のプロフィール（名前・所属クラブ・会員区分）と、紺の数字カード（今月の練習日・今月の日記の件数・今週の km）
$profile_role = (string)($member['role'] ?? ($_SESSION['user_role'] ?? 'member'));
$profile_role_label = $profile_role === MEMBER_ROLE_ADMIN ? '管理者' : member_role_label($profile_role);
$profile_club_label = '所属クラブなし';
$month_prefix = date('Y-m');
$month_practice_days = count(array_unique(array_map(
    static fn(array $diary): string => (string)$diary['practice_date'],
    array_filter($my_diaries, static fn(array $diary): bool => strncmp((string)$diary['practice_date'], $month_prefix, 7) === 0)
)));
$month_diary_count = count(array_filter($my_diaries, static fn(array $diary): bool => strncmp((string)$diary['practice_date'], $month_prefix, 7) === 0));
$week_km = 0.0;
try {
    $profile_club = fetch_member_club($pdo, $user_id);
    if ($profile_club !== null) {
        $profile_club_label = MEMBER_CLUB_LABELS[$profile_club];
    }
    $week_km = (float)fetch_training_week_summary($pdo, $user_id)['week_km'];
} catch (PDOException $ex) {
    error_log('mypage profile summary failed: ' . $ex->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- iOS が JAAFID・ゼッケン番号を電話番号と誤認して下線リンクにするのを防ぐ -->
    <meta name="format-detection" content="telephone=no">
    <title>メンバーマイページ | TOBG</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;700;800;900&family=Zen+Kaku+Gothic+New:wght@400;500;700;900&display=swap" rel="stylesheet">
    <link href="../css/theme.css?v=<?php echo (int)@filemtime(__DIR__ . '/../css/theme.css'); ?>" rel="stylesheet">
    <link href="../css/mypage_profile.css?v=<?php echo (int)@filemtime(__DIR__ . '/../css/mypage_profile.css'); ?>" rel="stylesheet">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#F4EEE4">
    <meta name="apple-mobile-web-app-title" content="TOBG-RUNNING">
    <link rel="apple-touch-icon" href="../icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" href="../icons/favicon.png">
    <style>
        body {
            background: var(--color-page-bg);
        }
        .mypage-wrap {
            max-width: var(--content-max-width);
            margin: 0 auto;
            padding: 12px var(--page-gutter) 40px;
        }
        .card-clean {
            border: 0;
            border-radius: var(--card-radius);
            box-shadow: var(--card-shadow);
        }
        .deadline-red {
            color: #dc2626;
            font-weight: 700;
        }
        .deadline-normal {
            color: #334155;
            font-weight: 700;
        }
        .diary-time,
        .diary-comment {
            white-space: pre-wrap;
            line-height: 1.5;
        }
        .diary-image {
            width: 100%;
            max-width: 180px;
            border-radius: 8px;
            border: 1px solid #dce4ef;
        }
        /* ---- 会員情報カード ---- */
        .member-card .card-body {
            padding: 20px;
        }
        .member-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 18px;
        }
        .profile-icon-preview,
        .profile-icon-fallback {
            width: 64px;
            height: 64px;
            border-radius: 50%;
        }
        .profile-icon-preview {
            border: 1px solid #d8e1ec;
            object-fit: cover;
            background: #ffffff;
        }
        .profile-icon-fallback {
            background: var(--color-today-bg);
            color: var(--color-header);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 800;
            border: 1px solid var(--color-today-bg);
        }
        /* 項目名を上・値を下に置き、区切り線で並べる */
        .member-info-list {
            margin: 0;
        }
        .member-info-item {
            padding: 12px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        .member-info-item:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }
        .member-info-item dt {
            font-size: 0.78rem;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 2px;
        }
        .member-info-item dd {
            margin: 0;
            font-size: 1rem;
            font-weight: 700;
            color: #0f172a;
            overflow-wrap: anywhere;
        }
        .member-info-empty {
            font-weight: 600;
            color: #94a3b8;
        }
        .member-email-view {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .member-inline-link {
            flex-shrink: 0;
            border: 0;
            background: transparent;
            padding: 4px 0 4px 8px;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--color-header);
        }
        .member-email-form .form-control {
            min-width: 0;
            font-size: 1rem;
        }
        .fee-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 700;
        }
        .fee-badge--unpaid {
            background: #fee2e2;
            color: #b91c1c;
        }
        .fee-badge--paid {
            background: #dcfce7;
            color: #15803d;
        }
        #admin-member-table.is-fee-hidden .admin-fee-col {
            display: none;
        }
        /* 重要のお知らせの選択欄（赤い文字・薄い赤の地） */
        .admin-important-select.is-on {
            background-color: #FFF1EE;
            color: var(--coral);
            font-weight: 700;
        }
        /* ピックアップに選んでいる会員の選択欄（オレンジの薄い地） */
        .admin-pickup-select.is-on {
            background-color: var(--orange-soft);
            color: var(--orange-deep);
            font-weight: 700;
        }
        .admin-fee-select.is-unpaid {
            background-color: #fee2e2;
            color: #b91c1c;
        }
        .admin-fee-select.is-paid {
            background-color: #dcfce7;
            color: #15803d;
        }
        /* PC: 左にアイコン列、右に項目を2列のカードで並べる */
        @media (min-width: 768px) {
.member-card .card-body {
                padding: 20px 24px 24px;
            }
.member-card-head {
                padding-bottom: 14px;
                margin-bottom: 20px;
                border-bottom: 1px solid #e5e7eb;
            }
.member-info-list {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
            }
.member-info-item,
            .member-info-item:last-child {
                padding: 12px 16px;
                border: 1px solid #e5e7eb;
                border-radius: 12px;
            }
}
        /* 幅が狭めの PC・タブレットでは、メールアドレスが途中で折り返さないよう1列にする */
        @media (min-width: 768px) and (max-width: 991.98px) {
.member-info-list {
                grid-template-columns: minmax(0, 1fr);
            }
}
        /* ---- アイコン変更モーダル ---- */
        .icon-modal-preview {
            display: flex;
            justify-content: center;
            margin-bottom: 18px;
        }
        .icon-modal-preview .profile-icon-preview,
        .icon-modal-preview .profile-icon-fallback {
            width: 96px;
            height: 96px;
            font-size: 2.2rem;
        }
        .icon-choice-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #cbd5e1;
            border-radius: 999px;
            background: #ffffff;
            color: #1f2937;
            font-weight: 700;
            cursor: pointer;
            margin: 0;
        }
        .icon-choice-btn:hover {
            background: #f8fafc;
        }
        .icon-choice-btn--danger {
            color: #dc2626;
            border-color: #fecaca;
        }
        .icon-choice-btn--danger:hover {
            background: #fef2f2;
        }
        /* 2択の間隔を十分にあけて誤タップを防ぐ */
        .icon-choice-gap {
            height: 14px;
        }
        .profile-icon-filename {
            margin-top: 8px;
            font-size: 0.78rem;
            color: #64748b;
            text-align: center;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .password-change-pill {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 5px 16px;
            border: 1px solid var(--color-header);
            border-radius: 999px;
            background: var(--color-card-bg);
            color: var(--color-header);
            font-size: 0.85rem;
            font-weight: 700;
            white-space: nowrap;
            transition: background-color 0.15s, color 0.15s;
        }
        .password-change-pill:hover,
        .password-change-pill:focus-visible {
            background: var(--color-header);
            color: var(--color-header-text);
        }
        .password-change-pill-dot {
            position: absolute;
            top: -3px;
            right: -3px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--color-accent);
            border: 2px solid var(--color-card-bg);
        }
        /* 黄色の注意書きの上でも読めるよう、注意書きと同じ濃い茶色の枠線ボタンにする */
        .password-notice-dismiss {
            color: #664d03;
            border: 1px solid rgba(102, 77, 3, 0.4);
            background: transparent;
        }
        .password-notice-dismiss:hover {
            color: #664d03;
            background: rgba(102, 77, 3, 0.08);
        }
        .member-email-form {
            display: flex;
            align-items: center;
            gap: 8px;
            max-width: 420px;
        }
        /* 管理メニュー: 白いカードの中に、アイコン＋名前のタイルを並べる */
        .admin-menu-card {
            background: var(--color-card-bg);
            border-radius: var(--card-radius);
            padding: 18px 20px 20px;
            margin-bottom: 10px;
        }
        .admin-menu-title {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0 0 14px;
            font-size: 1rem;
            font-weight: 800;
            color: var(--color-text-primary);
        }
        .admin-menu-title::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--color-accent);
            flex-shrink: 0;
        }
        .admin-menu-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
        }
        .admin-menu-tile {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
            padding: 14px 14px 16px;
            border: 1px solid var(--color-border);
            border-radius: 10px;
            background: var(--color-card-bg);
            color: var(--color-text-primary);
            text-decoration: none;
            transition: border-color 0.15s ease, background-color 0.15s ease;
        }
        .admin-menu-tile:hover,
        .admin-menu-tile:focus-visible {
            border-color: var(--color-accent);
            background: var(--color-accent-bg);
            color: var(--color-text-primary);
        }
        .admin-menu-icon {
            width: 20px;
            height: 20px;
            color: var(--color-accent);
        }
        .admin-menu-label {
            font-size: 0.88rem;
            font-weight: 700;
            line-height: 1.4;
        }
        @media (max-width: 768px) {
.admin-menu-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
}
        /* 管理のダッシュボード（マイページの上）: 数字のカード4枚。1枚目は強調 */
        .mp-dash-title {
            margin: 0 0 10px;
            color: var(--orange-deep);
            font-family: var(--font-num);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .12em;
        }
        .mp-dash-title span {
            font-family: var(--font-base);
            letter-spacing: 0;
        }
        .admin-kpis {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr)); /* スマホは2列×2段、PC は4列 */
            gap: 10px;
            margin-bottom: 10px;
        }
        .admin-kpi {
            display: block;
            padding: 16px 18px;
            border-radius: var(--card-radius);
            background: var(--color-card-bg);
            color: var(--color-text-primary);
            text-decoration: none;
        }
        .admin-kpi p {
            margin: 0;
        }
        .admin-kpi-label {
            color: var(--color-text-secondary);
            font-size: 0.78rem;
            font-weight: 700;
        }
        .admin-kpi-value {
            margin-top: 6px !important;
            font-family: var(--font-num); /* 数字は Archivo */
            font-size: 2rem;
            font-weight: 800;
            font-variant-numeric: tabular-nums;
            line-height: 1.1;
        }
        .admin-kpi-unit {
            margin-left: 4px;
            color: var(--color-text-secondary);
            font-size: 0.85rem;
            font-weight: 700;
        }
        .admin-kpi-sub {
            margin-top: 6px !important;
            color: var(--color-text-muted);
            font-size: 0.75rem;
        }
        /* 1枚目は仕様書の「濃いカード」（ネイビーのグラデーション、白文字、補足 #C9CEDC、小見出し --chart） */
        .admin-kpi.is-primary {
            background: var(--dark-card);
            color: #FFFFFF;
        }
        .admin-kpi.is-primary .admin-kpi-label {
            color: var(--chart);
        }
        .admin-kpi.is-primary .admin-kpi-unit,
        .admin-kpi.is-primary .admin-kpi-sub {
            color: var(--dark-card-sub);
        }
        .admin-kpi.is-link {
            transition: box-shadow 0.15s ease;
        }
        .admin-kpi.is-link:hover,
        .admin-kpi.is-link:focus-visible {
            box-shadow: 0 0 0 2px var(--color-accent);
            color: var(--color-text-primary);
        }
        .admin-kpi.is-alert .admin-kpi-value {
            color: var(--coral);
        }
        .admin-kpis {
            margin-bottom: 20px;
        }
        @media (min-width: 992px) {
            .admin-kpis {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }
        @media (max-width: 575.98px) {
            .admin-kpi {
                padding: 14px;
            }
            .admin-kpi-value {
                font-size: 1.6rem;
            }
        }
        /* 選んでいる機能のタイル */
        .admin-menu-tile.is-active {
            border-color: var(--color-accent);
            background: var(--color-accent-bg);
        }
        /* ---- 管理画面の PC レイアウト（左に管理メニュー、右に上のバー・数字のカード・選んだ機能） ----
           上のバーと数字のカードは PC だけ。スマホ・タブレットは今までどおりタイルの下に機能を並べる */
        .admin-desk-topbar {
            display: none;
        }
        @media (min-width: 992px) {
/* PC の管理メニューは、全ページ共通の左メニュー（includes/site_header.php）に入れたので、ここのタイルは隠す。
   本文は真ん中寄せの固定幅（1180px）にせず、左メニューの右から画面の右端まで広げる */
body.has-admin-desk {
                --content-max-width: 100%;
            }
body.has-admin-desk .site-header-inner {
                max-width: none;
            }
.admin-desk-side {
                display: none;
            }
/* 右側は「上のバー・数字のカード・選んだ機能」。メニューへ移動したときに固定ヘッダーの下に隠れないように */
.admin-desk-main {
                scroll-margin-top: 120px;
            }
/* 上のバー: 左に今の画面、真ん中にアイコン・名前・役割（左右を同じ幅にして真ん中にそろえる） */
.admin-desk-topbar {
                display: grid;
                grid-template-columns: 1fr auto 1fr;
                align-items: center;
                gap: 12px;
                margin-bottom: 10px;
                padding: 12px 18px;
                border-radius: var(--card-radius);
                background: var(--color-card-bg);
            }
/* 名前とアイコンは左メニューのいちばん上にあるので、上のバーには「管理 › ○○」だけを出す */
.admin-desk-user {
                display: none !important;
            }
.admin-desk-crumb {
                display: flex;
                align-items: center;
                gap: 8px;
                margin: 0;
                color: var(--color-text-secondary);
                font-size: 0.85rem;
                font-weight: 700;
            }
.admin-desk-crumb [data-admin-crumb] {
                color: var(--color-text-primary);
            }
.admin-desk-user {
                display: flex;
                align-items: center;
                gap: 10px;
            }
.admin-desk-avatar {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 38px;
                height: 38px;
                border-radius: 50%;
                background: var(--color-accent-bg);
                color: var(--color-accent);
                font-size: 0.8rem;
                font-weight: 800;
                object-fit: cover;
                flex-shrink: 0;
            }
.admin-desk-user-text {
                display: flex;
                flex-direction: column;
                line-height: 1.3;
            }
.admin-desk-user-name {
                color: var(--color-text-primary);
                font-weight: 800;
            }
.admin-desk-user-role {
                color: var(--color-text-secondary);
                font-size: 0.75rem;
            }
}
        /* 管理の各機能は、メニューで選んだものだけを表示する（折りたたみの帯は並べない）。
           「大会の新規追加」などの中の折りたたみは対象外にするため、直下の details だけに当てる */
        .admin-section > details {
            display: none;
        }
        .admin-section > details.is-active {
            display: block;
        }
        /* メニューを押して移動したとき、見出しが上のヘッダーに隠れないよう少し下で止める
           （PC は全ページ共通で余白を取っているので、スマホ・タブレットだけ） */
        @media (max-width: 991.98px) {
            .admin-section > details {
                scroll-margin-top: 84px;
            }
        }
        /* 開いた機能の見出し。押すと折りたためるので、右端に「▲」を付ける */
        .admin-section > details > summary {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            color: var(--color-text-primary);
        }
        .admin-section > details > summary::after {
            content: "▲";
            margin-left: auto;
            color: var(--color-text-muted);
            font-size: 0.65rem;
        }
        .admin-section > details > summary::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--color-accent);
            flex-shrink: 0;
        }
        .admin-section details {
            scroll-margin-top: 120px; /* 移動したとき、固定ヘッダーの下に隠れないように */
            background: var(--color-card-bg);
            border-radius: var(--card-radius);
            margin-bottom: 14px;
            box-shadow: var(--card-shadow);
        }
        .admin-section summary {
            cursor: pointer;
            list-style: none;
            font-weight: 800;
            color: var(--color-header);
            padding: 12px 14px;
            border-bottom: 1px solid #eef2f7;
        }
        .admin-section summary::-webkit-details-marker {
            display: none;
        }
        .admin-section-body {
            padding: 14px;
        }
        /* ---- 管理の各機能: 見出しと、中の内容（追加フォーム・一覧など）を、それぞれ白いカードに分ける ---- */
        .admin-section > details {
            background: transparent;
            box-shadow: none;
        }
        .admin-section > details > summary {
            background: var(--color-card-bg);
            border-radius: var(--card-radius);
            border-bottom: 0;
            box-shadow: var(--card-shadow);
            margin-bottom: 10px;
            padding: 16px 18px;
            font-size: 1.1rem;
        }
        .admin-section > details > .admin-section-body {
            padding: 0;
        }
        /* 「大会の新規追加」の折りたたみも、ほかのカードと同じ間隔で並べる */
        .admin-section > details > .admin-section-body > details {
            margin-bottom: 10px;
        }
        .admin-panel {
            background: var(--color-card-bg);
            border-radius: var(--card-radius);
            box-shadow: var(--card-shadow);
            padding: 18px 20px;
            margin-bottom: 10px;
        }
        .admin-panel-title {
            margin: 0 0 12px;
            font-size: 1rem;
            font-weight: 800;
            color: var(--color-text-primary);
        }
        /* カードの見出しの右にボタンを置くとき（「手動クリーンアップ」など） */
        .admin-panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }
        .admin-panel-head .admin-panel-title {
            margin: 0;
        }
        /* 管理の表（Bootstrap の .table）も、グループ管理の表と同じく、
           見出し行は薄い文字だけにして、行の区切りは細い線にする */
        .admin-section .table {
            --bs-table-bg: transparent;
            --bs-table-border-color: var(--color-border);
            --bs-table-color: var(--color-text-primary);
            font-size: 0.88rem;
        }
        .admin-section .table > thead th {
            padding: 8px 10px;
            color: var(--color-text-secondary);
            font-size: 0.8rem;
            font-weight: 700;
            white-space: nowrap;
        }
        .admin-section .table > tbody > tr > td {
            padding: 10px;
        }
        .admin-section .table > tbody > tr:last-child > td {
            border-bottom: 0;
        }
        /* 表の中の保存・更新・削除ボタンも、グループ管理と同じ丸いボタンにする */
        .admin-section .table .btn-sm {
            --bs-btn-padding-x: 14px;
            --bs-btn-padding-y: 4px;
            --bs-btn-font-size: 0.82rem;
            --bs-btn-font-weight: 700;
            --bs-btn-border-radius: 999px;
            --bs-btn-bg: var(--color-card-bg);
            white-space: nowrap;
        }
        .admin-section .btn-outline-primary {
            --bs-btn-color: var(--color-accent);
            --bs-btn-border-color: var(--color-accent);
            --bs-btn-hover-bg: var(--color-accent);
            --bs-btn-hover-border-color: var(--color-accent);
            --bs-btn-hover-color: var(--color-accent-text);
            --bs-btn-active-bg: var(--color-accent-hover);
            --bs-btn-active-border-color: var(--color-accent-hover);
            --bs-btn-active-color: var(--color-accent-text);
            --bs-btn-focus-shadow-rgb: 217, 102, 30;
        }
        .admin-section .btn-outline-danger {
            --bs-btn-color: var(--coral);
            --bs-btn-border-color: var(--coral);
            --bs-btn-hover-bg: var(--coral);
            --bs-btn-hover-border-color: var(--coral);
            --bs-btn-hover-color: #FFFFFF;
            --bs-btn-active-bg: var(--coral);
            --bs-btn-active-border-color: var(--coral);
            --bs-btn-active-color: #FFFFFF;
        }
        .admin-empty {
            padding: 16px 0 8px;
            color: var(--color-text-muted);
            text-align: center;
        }
        .admin-empty svg {
            width: 22px;
            height: 22px;
            margin-bottom: 6px;
        }
        .admin-empty p {
            margin: 0;
            font-size: 0.88rem;
        }
        .agm-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.88rem;
        }
        .agm-table th {
            padding: 8px 10px;
            border-bottom: 1px solid var(--color-border);
            color: var(--color-text-secondary);
            font-size: 0.8rem;
            font-weight: 700;
            white-space: nowrap;
        }
        .agm-table td {
            padding: 14px 10px;
            border-bottom: 1px solid var(--color-border);
            color: var(--color-text-primary);
            vertical-align: top;
        }
        .agm-table tbody tr:last-child td {
            border-bottom: 0;
        }
        .agm-name {
            font-weight: 800;
        }
        .agm-date {
            font-weight: 700;
            white-space: nowrap;
        }
        .agm-sub {
            display: block;
            margin-top: 2px;
            color: var(--color-text-secondary);
            font-size: 0.75rem;
        }
        .agm-pill {
            display: inline-block;
            padding: 3px 14px;
            border-radius: 999px;
            background: var(--color-pill-bg);
            color: var(--color-text-secondary);
            font-size: 0.78rem;
            font-weight: 700;
            white-space: nowrap;
        }
        .agm-pill.is-pending {
            background: #FFF1EE; /* 仕様書のエラー欄の地 */
            color: var(--coral);
        }
        .agm-danger-btn,
        .agm-plain-btn {
            padding: 4px 16px;
            border-radius: 999px;
            background: var(--color-card-bg);
            font-size: 0.82rem;
            font-weight: 700;
            white-space: nowrap;
        }
        .agm-danger-btn {
            border: 1px solid var(--coral);
            color: var(--coral);
        }
        .agm-danger-btn:hover {
            background: var(--coral);
            color: #FFFFFF;
        }
        .agm-plain-btn {
            border: 1px solid var(--color-border);
            color: var(--color-text-secondary);
        }
        .agm-plain-btn:hover {
            color: var(--color-text-primary);
            border-color: var(--color-text-muted);
        }
        .agm-actions {
            display: flex;
            gap: 12px;
            margin: 0;
        }
        /* 「名前を変更」はグループ名の下の小さなオレンジの文字。押すと入力欄が開く */
        #admin-group-requests details.agm-rename {
            background: transparent;
            box-shadow: none;
            border-radius: 0;
            margin: 2px 0 0;
        }
        #admin-group-requests details.agm-rename > summary {
            display: inline;
            padding: 0;
            border: 0;
            color: var(--color-accent);
            font-size: 0.78rem;
            font-weight: 700;
        }
        .agm-rename-form {
            display: flex;
            gap: 6px;
            margin-top: 6px;
            min-width: 220px;
        }
        .tournament-add-details {
            border: 1px solid var(--color-accent);
            border-radius: var(--card-radius);
            overflow: hidden;
        }
        .tournament-add-summary {
            cursor: pointer;
            list-style: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 14px 16px;
            background: var(--color-accent);
            color: var(--color-accent-text);
            font-weight: 800;
            font-size: 1.05rem;
        }
        .tournament-add-summary:hover {
            background: var(--color-accent-hover);
        }
        .tournament-add-summary::-webkit-details-marker {
            display: none;
        }
        .tournament-add-summary-icon {
            display: inline-block;
            font-size: 0.85rem;
            transition: transform 0.2s ease;
        }
        .tournament-add-details[open] .tournament-add-summary-icon {
            transform: rotate(180deg);
        }
        .admin-status-select.status-承認 {
            background: #bbf7d0;
            border-color: #86efac;
            color: #14532d;
        }
        .admin-status-select.status-未承認 {
            background: #fecaca;
            border-color: #fca5a5;
            color: #7f1d1d;
        }
        .admin-status-select.status-閲覧 {
            background: #fef08a;
            border-color: #fde047;
            color: #713f12;
        }
        .admin-status-cell {
            width: 130px;
            min-width: 130px;
        }
        .admin-status-select {
            width: 120px;
            min-width: 120px;
        }
        .admin-update-cell {
            width: 78px;
            min-width: 78px;
            text-align: center;
            vertical-align: middle;
        }
        .admin-save-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 48px;
            white-space: nowrap;
        }
        .admin-small-note {
            color: #64748b;
            font-size: 0.85rem;
        }
        .admin-entry-table tbody tr.entry-group-tone-0 td { background: #f8fafc; }
        .admin-entry-table tbody tr.entry-group-tone-1 td { background: #f7fee7; }
        .admin-entry-table tbody tr.entry-group-tone-2 td { background: #fefce8; }
        .admin-entry-table tbody tr.entry-group-tone-3 td { background: #f5f3ff; }
        .admin-entry-table tbody tr.entry-group-tone-4 td { background: #f0f9ff; }
        .admin-entry-table tbody tr.entry-group-tone-5 td { background: #fff7ed; }
        .admin-entry-table tbody tr:hover td { filter: brightness(0.985); }
        .admin-entry-table tbody tr.entry-toggle-row {
            cursor: pointer;
        }
        .entry-toggle-cell {
            width: 32px;
            min-width: 32px;
        }
        .entry-toggle-icon {
            display: inline-block;
            font-size: 0.7rem;
            color: #64748b;
            transition: transform 0.2s ease;
        }
        .admin-entry-table tbody tr.entry-toggle-row[aria-expanded="true"] .entry-toggle-icon {
            transform: rotate(90deg);
        }
        .entry-detail-row td {
            padding-top: 0;
        }
        .entry-detail-box {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 10px 14px;
            margin: 0 0 8px;
        }
        .entry-detail-table th {
            color: #64748b;
            font-size: 0.8rem;
            font-weight: 700;
        }
        .admin-radio-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: nowrap;
            white-space: nowrap;
        }
        /* 「年会費を編集」ボタン: 薄いグレーだと読みにくいので、文字だけ少し濃くする */
        #adminFeeColumnToggle {
            --bs-btn-color: color-mix(in srgb, var(--color-text-secondary), var(--color-text-primary));
        }
        .admin-radio-label {
            color: var(--color-text-secondary);
            font-size: 0.8rem;
            font-weight: 700;
            margin-right: 2px;
        }
        .entry-status-badge {
            font-weight: 700;
            border: 1px solid transparent;
        }
        .entry-status-badge.status-approved {
            background: #bbf7d0;
            border-color: #86efac;
            color: #14532d;
        }
        .entry-status-badge.status-unapproved {
            background: #fecaca;
            border-color: #fca5a5;
            color: #7f1d1d;
        }
        .entry-status-badge.status-viewed {
            background: #fef08a;
            border-color: #fde047;
            color: #713f12;
        }
        .section-collapse-toggle {
            border: 1px solid var(--color-header);
            background: var(--color-card-bg);
            color: var(--color-header);
            min-height: 36px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 6px 12px;
            font-weight: 800;
            line-height: 1.2;
            transition: background 0.2s ease;
        }
        .tournament-owner-badge {
            display: inline-block;
            border-radius: 999px;
            padding: 2px 10px;
            font-size: 0.76rem;
            font-weight: 800;
        }
        .tournament-owner-badge.self {
            background: #dcfce7;
            color: #166534;
        }
        .tournament-owner-badge.admin {
            background: #e2e8f0;
            color: #334155;
        }
        .tournament-add-form .form-control,
        .tournament-add-form .form-select {
            font-size: 1rem;
            padding-top: 0.55rem;
            padding-bottom: 0.55rem;
        }
        .tournament-add-form .form-label {
            font-weight: 700;
            color: #334155;
        }
        .tournament-self-managed-check {
            background: #f8fafc;
            border: 1px solid #dbe3ef;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .tournament-self-managed-check .form-check-input {
            width: 1.4em;
            height: 1.4em;
            margin-top: 0;
            flex-shrink: 0;
        }
        .tournament-self-managed-check .form-check-label {
            font-weight: 700;
        }
        @media (max-width: 991.98px) {
.tournament-add-form .btn {
                font-size: 1.05rem;
            }
}
        .mobile-card-table {
            width: 100%;
        }
        @media (max-width: 767.98px) {
.mobile-card-table thead {
                display: none;
            }
.mobile-card-table,
            .mobile-card-table tbody,
            .mobile-card-table tr {
                display: block;
                width: 100%;
            }
.mobile-card-table tr > form {
                display: contents;
            }
.mobile-card-table td {
                display: block;
                width: 100%;
                border: none;
                padding: 6px 0;
            }
.mobile-card-table tbody tr {
                background: var(--color-card-bg);
                border: 1px solid var(--color-border);
                border-radius: var(--card-radius);
                padding: 12px 14px;
                margin-bottom: 12px;
            }
.mobile-card-table td[data-label]::before {
                content: attr(data-label);
                display: block;
                font-size: 0.72rem;
                font-weight: 700;
                color: var(--color-text-secondary);
                margin-bottom: 3px;
            }
.mobile-card-table td .form-control,
            .mobile-card-table td .form-select {
                font-size: 1rem;
            }
/* 管理の表に付けた余白（.admin-section .table）より、スマホのカード表示の余白を優先する */
.admin-section .mobile-card-table > tbody > tr > td {
                padding: 6px 0;
            }
.mobile-card-table td.mobile-actions-cell {
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
            }
.mobile-card-table td.mobile-actions-cell .btn {
                flex: 1 1 auto;
            }
}
        .section-collapse-toggle:hover {
            background: var(--color-today-bg);
            color: var(--color-header);
        }
        .section-collapse-icon {
            display: inline-block;
            font-size: 0.72rem;
            transform: rotate(0deg);
            transition: transform 0.2s ease;
        }
        .section-collapse-toggle[aria-expanded="true"] .section-collapse-icon {
            transform: rotate(180deg);
        }
        .btn-diary-bright {
            background: var(--color-accent);
            border-color: var(--color-accent);
            color: var(--color-accent-text);
            font-weight: 800;
        }
        .btn-diary-bright:hover,
        .btn-diary-bright:focus {
            background: var(--color-accent-hover);
            border-color: var(--color-accent-hover);
            color: var(--color-accent-text);
        }
        .btn-entry-action {
            background: var(--color-accent);
            border-color: var(--color-accent);
            color: var(--color-accent-text);
            font-weight: 800;
        }
        .btn-entry-action:hover,
        .btn-entry-action:focus {
            background: var(--color-accent-hover);
            border-color: var(--color-accent-hover);
            color: var(--color-accent-text);
        }
        .btn-diary-action {
            background: var(--color-card-bg);
            border-color: var(--color-header);
            color: var(--color-header);
            font-weight: 800;
        }
        .btn-diary-action:hover,
        .btn-diary-action:focus {
            background: var(--color-header);
            border-color: var(--color-header);
            color: var(--color-header-text);
        }
        .btn-gallery-action {
            background: var(--color-card-bg);
            border-color: var(--color-header);
            color: var(--color-header);
            font-weight: 800;
        }
        .btn-gallery-action:hover,
        .btn-gallery-action:focus {
            background: var(--color-header);
            border-color: var(--color-header);
            color: var(--color-header-text);
        }
        .intensity-point {
            background: #fecaca;
            color: #7f1d1d;
        }
        .intensity-race {
            background: #3b82f6;
            color: #ffffff;
        }
        .intensity-normal {
            background: #fef08a;
            color: #713f12;
        }
        .intensity-light {
            background: #bbf7d0;
            color: #14532d;
        }
        .intensity-weight {
            background: #ddd6fe;
            color: #4c1d95;
        }
        .diary-detail-meta {
            color: #64748b;
            font-size: 0.85rem;
            margin-bottom: 10px;
        }
        .diary-detail-menu {
            display: inline-block;
            background: #e2e8f0;
            color: #334155;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 0.82rem;
            margin-bottom: 8px;
        }
        .diary-detail-time,
        .diary-detail-comment {
            white-space: pre-wrap;
            margin-bottom: 10px;
        }
        .running-set-block {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 8px 12px;
        }
        .running-set-header {
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 6px;
        }
        .running-set-laps {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .running-lap-chip {
            background: #ffffff;
            border: 1px solid #dbe4ef;
            border-radius: 999px;
            padding: 2px 10px;
            font-size: 0.82rem;
            color: #334155;
        }
        .diary-detail-media img,
        .diary-detail-media video {
            width: 100%;
            max-width: 320px;
            border-radius: 10px;
            border: 1px solid #dbe3ef;
            margin-bottom: 10px;
        }
        .diary-detail-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        .diary-detail-comment-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            max-height: 220px;
            overflow-y: auto;
            margin-bottom: 10px;
        }
        .diary-detail-comment-item {
            border-radius: 12px;
            background: #f8fbff;
            border: 1px solid #e2e8f0;
            padding: 8px 10px;
        }
        .diary-detail-comment-item form {
            margin: 0;
        }
        .diary-detail-comment-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            color: #64748b;
            font-size: 0.78rem;
            font-weight: 700;
            margin-bottom: 3px;
        }
        .comment-delete-btn {
            flex-shrink: 0;
            padding: 1px 10px;
            border: 1px solid rgba(185, 28, 28, 0.35);
            border-radius: 999px;
            background: transparent;
            color: #b91c1c;
            font-size: 0.72rem;
            font-weight: 700;
        }
        .comment-delete-btn:hover {
            background: #fef2f2;
        }
        .diary-detail-intensity {
            display: inline-block;
            border-radius: 999px;
            padding: 3px 10px;
            font-size: 0.78rem;
            font-weight: 800;
            margin-bottom: 8px;
            margin-left: 8px;
        }
        /* 管理者だけ: 練習日記カレンダーの見出しの横の「閉じる ▲ / 開く ▼」 */
        .calendar-fold-toggle {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border: 1px solid var(--color-border);
            border-radius: 999px;
            background: var(--color-card-bg);
            color: var(--color-text-secondary);
            font-size: 0.75rem;
            font-weight: 700;
        }
        .calendar-fold-toggle:hover {
            color: var(--color-text-primary);
        }
        .calendar-fold-icon {
            font-size: 0.6rem;
        }
        /* 日記の詳細: 「公開範囲を変更」は小さなオレンジの文字。押すと下に変更フォームが開く */
        .diary-scope-edit-toggle {
            padding: 0;
            border: 0;
            background: none;
            color: var(--color-accent);
            font-size: 0.78rem;
            font-weight: 700;
            white-space: nowrap;
        }
        .diary-scope-edit {
            margin-bottom: 12px;
            padding: 12px;
            border: 1px solid var(--color-border);
            border-radius: var(--card-radius);
        }
        .diary-scope-edit-actions {
            display: flex;
            justify-content: flex-end;
            gap: 16px; /* 「やめる」と「保存」の押し間違いを防ぐため広め */
            margin-top: 12px;
        }
        /* マイページの一番下のメニュー（仕様書: 枠で囲まず、横線で区切った一覧） */
        .mypage-menu {
            margin-top: 24px;
            padding: 16px 18px;
            border-radius: var(--card-radius);
            background: var(--surface);
            box-shadow: var(--card-shadow);
        }
        .mypage-menu-title {
            margin: 0 0 4px;
            color: var(--navy);
            font-size: 17px;
            font-weight: 900;
        }
        .mypage-menu-list {
            margin: 0;
            padding: 0;
            list-style: none;
        }
        .mypage-menu-list li + li {
            border-top: 1px solid var(--line);
        }
        .mypage-menu-list a {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 2px;
            color: var(--navy);
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
        }
        .mypage-menu-list a span {
            color: var(--sub);
        }
        /* マイページのいちばん下のログアウト（白地・赤い枠と文字の丸いボタン） */
        .mypage-logout {
            margin: 28px 0 0;
            text-align: center;
        }
        .mypage-logout-btn {
            display: inline-block;
            min-width: 220px;
            padding: 12px 24px;
            border: 1.5px solid var(--coral);
            border-radius: 999px;
            background: var(--surface);
            color: var(--coral);
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
        }
        .mypage-logout-btn:hover {
            background: #FFF1EE;
            color: var(--coral);
        }
    </style>
</head>
<?php // 管理者は PC で、画面の左端に管理メニューを固定する（その分ページ全体を右へずらす） ?>
<body<?php echo $is_admin ? ' class="has-admin-desk"' : ''; ?>>
<?php
$shared_header = __DIR__ . '/../includes/site_header.php';
if (file_exists($shared_header)) {
    require_once $shared_header;
} else {
    echo '<div style="background:var(--color-header);color:#fff;padding:12px 16px;font-weight:700;">TEAM OFFICIAL</div>';
}
?>

<div class="mypage-wrap">
    <?php // プロフィール（MY PAGE、アイコン、名前、所属クラブ、会員区分）。アイコンを押すと変更の窓が開く ?>
    <?php $profile_initial = function_exists('mb_substr') ? mb_substr($user_name, 0, 2) : substr($user_name, 0, 2); ?>
    <p class="mp-eyebrow">MY PAGE</p>
    <header class="mp-profile">
        <button type="button" class="mp-avatar" data-bs-toggle="modal" data-bs-target="#profileIconModal" aria-label="アイコンを変更">
            <?php if ($member_avatar_path !== ''): ?>
                <img src="../<?php echo htmlspecialchars($member_avatar_path, ENT_QUOTES, 'UTF-8'); ?>" alt="">
            <?php else: ?>
                <span class="mp-avatar-initial"><?php echo htmlspecialchars((string)$profile_initial, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
            <span class="mp-avatar-camera" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
            </span>
        </button>
        <div class="mp-profile-text">
            <h1 class="mp-name"><?php echo htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p class="mp-club"><?php echo htmlspecialchars($profile_club_label, ENT_QUOTES, 'UTF-8'); ?></p>
            <span class="mp-role"><?php echo htmlspecialchars($profile_role_label, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    </header>

    <?php if ($is_admin && $admin_dashboard !== null): ?>
        <?php // 管理者: 会員用の数字の代わりに、管理のダッシュボード（チームの状況。back/admin_dashboard_lib.php で集計） ?>
        <p class="mp-dash-title" id="adminDashboard">DASHBOARD <span>/ チームの状況（<?php echo htmlspecialchars(date('n月j日', strtotime($admin_dashboard['today'])), ENT_QUOTES, 'UTF-8'); ?>）</span></p>
        <section class="admin-kpis" aria-label="チームの状況（<?php echo htmlspecialchars(date('n月j日', strtotime($admin_dashboard['today'])), ENT_QUOTES, 'UTF-8'); ?>）">
            <div class="admin-kpi is-primary">
                <p class="admin-kpi-label">今日、記録を残したメンバー</p>
                <p class="admin-kpi-value"><?php echo (int)$admin_dashboard['today_people']; ?><span class="admin-kpi-unit">/ <?php echo (int)$admin_dashboard['member_total']; ?> 人</span></p>
                <p class="admin-kpi-sub">直近7日では <?php echo (int)$admin_dashboard['week_people']; ?> 人</p>
            </div>
            <div class="admin-kpi">
                <p class="admin-kpi-label">今週の練習日記</p>
                <p class="admin-kpi-value"><?php echo (int)$admin_dashboard['this_week_diaries']; ?><span class="admin-kpi-unit">件</span></p>
                <p class="admin-kpi-sub">先週は <?php echo (int)$admin_dashboard['last_week_diaries']; ?> 件</p>
            </div>
            <div class="admin-kpi">
                <p class="admin-kpi-label">今月の大会記録</p>
                <p class="admin-kpi-value"><?php echo (int)$admin_dashboard['month_race_entries']; ?><span class="admin-kpi-unit">件</span></p>
                <p class="admin-kpi-sub"><?php echo (int)$admin_dashboard['month_race_people']; ?> 人が出場</p>
            </div>
            <?php // 押すと大会情報管理（申込一覧の承認）を開く ?>
            <a class="admin-kpi is-link<?php echo $admin_dashboard['pending_entries'] > 0 ? ' is-alert' : ''; ?>" href="#admin-tournaments" data-admin-open="admin-tournaments">
                <p class="admin-kpi-label">承認待ちの申込</p>
                <p class="admin-kpi-value"><?php echo (int)$admin_dashboard['pending_entries']; ?><span class="admin-kpi-unit">件</span></p>
                <p class="admin-kpi-sub">開催前の大会のみ ／ 大会情報管理を開く ›</p>
            </a>
        </section>
    <?php else: ?>
        <?php // 紺の数字カード: 今月の練習日（日記のある日の数）・今月の日記の件数・今週の km ?>
        <section class="mp-stats" aria-label="練習の数字">
            <div class="mp-stat">
                <span class="mp-stat-value"><?php echo (int)$month_practice_days; ?></span>
                <span class="mp-stat-label"><?php echo (int)date('n'); ?>月の練習日</span>
            </div>
            <div class="mp-stat">
                <span class="mp-stat-value"><?php echo (int)$month_diary_count; ?></span>
                <span class="mp-stat-label">日記の件数</span>
            </div>
            <div class="mp-stat">
                <span class="mp-stat-value"><?php echo htmlspecialchars(number_format($week_km, 1), ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="mp-stat-label">今週の km</span>
            </div>
        </section>
    <?php endif; ?>
    <?php if ($page_data_error !== ''): ?>
        <div class="alert alert-danger py-2" role="alert">ページデータの読み込み中にエラーが発生しました（データは失われていません）: <?php echo htmlspecialchars($page_data_error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($training_flash !== ''): ?>
        <div class="alert alert-success py-2" role="alert"><?php echo htmlspecialchars($training_flash, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($training_error !== ''): ?>
        <div class="alert alert-danger py-2" role="alert"><?php echo htmlspecialchars($training_error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($profile_flash !== ''): ?>
        <div class="alert alert-success py-2" role="alert"><?php echo htmlspecialchars($profile_flash, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($profile_error !== ''): ?>
        <div class="alert alert-danger py-2" role="alert"><?php echo htmlspecialchars($profile_error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($show_initial_password_notice): ?>
        <div class="alert alert-warning py-2 d-flex justify-content-between align-items-center flex-wrap gap-2" role="alert">
            <span>⚠ 現在のパスワードは初期設定（生年月日）のままです。変更すると安全です。</span>
            <div class="d-flex align-items-center gap-2">
                <form method="POST" action="../back/dismiss_password_notice.php" class="m-0" onsubmit="return confirm('この案内を今後表示しません。よろしいですか？\n（パスワードはマイページの「パスワード変更」からいつでも変更できます）');">
                    <button type="submit" class="btn btn-sm rounded-pill px-3 password-notice-dismiss">今後表示しない</button>
                </form>
                <button type="button" class="btn btn-sm btn-warning fw-bold rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#passwordChangeModal">パスワードを変更する</button>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($entry_error !== ''): ?>
        <div class="alert alert-warning py-2" role="alert"><?php echo htmlspecialchars($entry_error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php // 練習日記カレンダー: 日記のある日は強度の色で塗る（軽め=落とし、通常、筋トレ=ウエイト、高め=ポイント、大会）。1日に2件以上は右上に件数 ?>
    <section class="mp-cal" id="training-diary" aria-labelledby="mpCalTitle">
        <div class="mp-cal-head">
            <h2 class="mp-cal-title" id="mpCalTitle">練習日記カレンダー</h2>
            <?php // 管理者は管理画面を広く使えるよう、カレンダーを折りたためる（最初は表示。開閉はこのブラウザに覚えておく） ?>
            <?php if ($is_admin): ?>
                <button type="button" class="calendar-fold-toggle" data-bs-toggle="collapse" data-bs-target="#diaryCalendarCollapse" aria-controls="diaryCalendarCollapse" aria-expanded="true">
                    <span data-fold-text>閉じる</span><span class="calendar-fold-icon" aria-hidden="true">▲</span>
                </button>
            <?php endif; ?>
            <div class="mp-cal-nav">
                <button type="button" class="mp-cal-arrow" id="diaryCalendarPrev" aria-label="前の月">&lsaquo;</button>
                <span class="mp-cal-month" id="diaryCalendarLabel" aria-live="polite"></span>
                <button type="button" class="mp-cal-arrow" id="diaryCalendarNext" aria-label="次の月">&rsaquo;</button>
            </div>
        </div>
        <?php if ($is_admin): ?><div class="collapse show" id="diaryCalendarCollapse"><?php endif; ?>
        <div class="mp-cal-weekdays" aria-hidden="true">
            <span class="is-sun">日</span><span>月</span><span>火</span><span>水</span><span>木</span><span>金</span><span>土</span>
        </div>
        <div class="mp-cal-grid" id="diaryCalendarGrid"></div>
        <div class="mp-cal-legend" aria-label="色の意味">
            <span><i class="lv-light"></i>軽め</span>
            <span><i class="lv-normal"></i>通常</span>
            <span><i class="lv-weight"></i>筋トレ</span>
            <span><i class="lv-high"></i>高め</span>
            <span><i class="lv-race"></i>大会</span>
        </div>
        <?php if ($is_admin): ?></div><?php endif; ?>
    </section>

    <!-- 練習日記の詳細モーダル -->
    <div class="modal fade" id="diaryDetailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="diaryDetailDate">練習日記の詳細</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="diaryDetailBody">
                    <div class="text-secondary">読み込み中...</div>
                </div>
            </div>
        </div>
    </div>

    <!-- 練習日記の登録モーダル -->
    <?php // 練習日記の登録シート（#diaryPostModal）は、全ページ共通でヘッダーから読み込む（includes/diary_post_sheet.php） ?>

    <?php if ($is_admin): ?>
        <?php if ($admin_flash !== ''): ?>
            <div class="alert alert-success py-2" role="alert"><?php echo htmlspecialchars($admin_flash, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($admin_error !== ''): ?>
            <div class="alert alert-danger py-2" role="alert"><?php echo htmlspecialchars($admin_error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php
        // 管理メニュー（タイル）。押すと下の該当する欄を開いて、そこまで移動する（項目は PC の左メニューと共通。back/admin_menu_lib.php）
        $admin_menu_items = admin_menu_items();
        ?>
        <?php // PC は「左に管理メニュー、右に上のバー・数字のカード・選んだ機能」の2列。スマホ・タブレットは縦に並べる ?>
        <div class="admin-desk">
        <aside class="admin-desk-side">
        <section class="admin-menu-card" aria-labelledby="adminMenuTitle">
            <h2 class="admin-menu-title" id="adminMenuTitle">管理メニュー</h2>
            <nav class="admin-menu-grid" aria-label="管理メニュー">
                <?php foreach ($admin_menu_items as [$admin_menu_target, $admin_menu_label, $admin_menu_paths]): ?>
                    <a class="admin-menu-tile" href="#<?php echo $admin_menu_target; ?>" data-admin-target="<?php echo $admin_menu_target; ?>">
                        <svg class="admin-menu-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?php echo $admin_menu_paths; ?></svg>
                        <span class="admin-menu-label"><?php echo htmlspecialchars($admin_menu_label, ENT_QUOTES, 'UTF-8'); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </section>
        </aside>

        <div class="admin-desk-main">
        <?php // PC だけ: 上のバー（左に今の画面、真ん中にアイコン・名前・役割） ?>
        <div class="admin-desk-topbar">
            <p class="admin-desk-crumb">管理<span aria-hidden="true">›</span><span data-admin-crumb>ダッシュボード</span></p>
            <div class="admin-desk-user">
                <?php if ($member_avatar_path !== ''): ?>
                    <img class="admin-desk-avatar" src="../<?php echo htmlspecialchars($member_avatar_path, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                <?php else: ?>
                    <span class="admin-desk-avatar" aria-hidden="true"><?php echo htmlspecialchars(function_exists('mb_substr') ? mb_substr($user_name, 0, 2) : substr($user_name, 0, 2), ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
                <span class="admin-desk-user-text">
                    <span class="admin-desk-user-name"><?php echo htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="admin-desk-user-role"><?php echo htmlspecialchars(member_role_label((string)($_SESSION['user_role'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></span>
                </span>
            </div>
        </div>


        <section class="admin-section mb-4" id="admin-tools">
            <details id="admin-members">
                <summary>会員一覧</summary>
                <div class="admin-section-body">
                    <section class="admin-panel" aria-labelledby="adminMembersTitle">
                    <h3 class="admin-panel-title" id="adminMembersTitle">会員（<?php echo count($admin_members ?? []); ?>人）</h3>
                    <?php if (empty($admin_members) || empty($admin_member_display_columns)): ?>
                        <div class="admin-empty"><p>表示できる会員データがありません</p></div>
                    <?php else: ?>
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                        <div class="admin-radio-row" id="admin-member-jaaf-filter" role="radiogroup" aria-label="陸連登録状況で絞り込み">
                            <div class="form-check form-check-inline mb-0">
                                <input class="form-check-input" type="radio" name="member_jaaf_filter" id="memberJaafFilterAll" value="all" checked>
                                <label class="form-check-label" for="memberJaafFilterAll">すべて</label>
                            </div>
                            <div class="form-check form-check-inline mb-0">
                                <input class="form-check-input" type="radio" name="member_jaaf_filter" id="memberJaafFilterRegistered" value="registered">
                                <label class="form-check-label" for="memberJaafFilterRegistered">陸連登録者のみ</label>
                            </div>
                            <div class="form-check form-check-inline mb-0">
                                <input class="form-check-input" type="radio" name="member_jaaf_filter" id="memberJaafFilterUnregistered" value="unregistered">
                                <label class="form-check-label" for="memberJaafFilterUnregistered">陸連未登録のみ</label>
                            </div>
                        </div>
                        <?php // 年会費は年1回しか触らないので、普段は列ごと隠しておく ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="adminFeeColumnToggle" aria-controls="admin-member-table" aria-pressed="false">年会費を編集</button>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0 mobile-card-table is-fee-hidden" id="admin-member-table">
                                <thead>
                                    <?php $admin_member_column_labels = ['birthday_code' => 'birthday', 'number_plate' => 'number_p', 'email' => 'メール']; ?>
                                    <tr>
                                        <?php foreach ($admin_member_display_columns as $column_name): ?>
                                            <th><?php echo htmlspecialchars((string)($admin_member_column_labels[$column_name] ?? $column_name), ENT_QUOTES, 'UTF-8'); ?></th>
                                        <?php endforeach; ?>
                                        <th>権限</th>
                                        <th>所属</th>
                                        <th>ピックアップ</th>
                                        <th>性別</th>
                                        <th class="admin-fee-col">年会費</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($admin_members as $member_row): ?>
                                        <?php $member_row_id = (int)($member_row['id'] ?? 0); ?>
                                        <?php $member_gender_value = (string)($member_row['gender'] ?? ''); ?>
                                        <?php $member_jaaf_id_value = $admin_member_jaaf_id_col !== null ? trim((string)($member_row[$admin_member_jaaf_id_col] ?? '')) : ''; ?>
                                        <?php $member_is_jaaf_registered = $member_jaaf_id_value !== '' && $member_jaaf_id_value !== '0000'; ?>
                                        <tr data-jaaf-registered="<?php echo $member_is_jaaf_registered ? '1' : '0'; ?>">
                                            <form method="POST">
                                                <input type="hidden" name="admin_action" value="update_member_ids">
                                                <input type="hidden" name="member_id" value="<?php echo $member_row_id; ?>">
                                                <input type="hidden" name="redirect_anchor" value="admin-tools">
                                                <?php foreach ($admin_member_display_columns as $column_name): ?>
                                                    <?php if ($column_name === $admin_member_jaaf_id_col): ?>
                                                        <td data-label="JAAFID"><input type="text" class="form-control form-control-sm" name="jaaf_id_value" maxlength="30" value="<?php echo htmlspecialchars((string)($member_row[$column_name] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                    <?php elseif ($column_name === $admin_member_number_plate_col): ?>
                                                        <td data-label="ゼッケン番号"><input type="text" class="form-control form-control-sm" name="number_plate_value" maxlength="30" value="<?php echo htmlspecialchars((string)($member_row[$column_name] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                    <?php elseif ($column_name === $admin_member_email_col): ?>
                                                        <td data-label="メール"><input type="email" class="form-control form-control-sm" name="email_value" maxlength="255" value="<?php echo htmlspecialchars((string)($member_row[$column_name] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="you@example.com"></td>
                                                    <?php else: ?>
                                                        <td data-label="<?php echo htmlspecialchars((string)($admin_member_column_labels[$column_name] ?? $column_name), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)($member_row[$column_name] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                                <?php $member_role_value = (string)($member_row['role'] ?? 'member'); ?>
                                                <td data-label="権限">
                                                    <?php if ($member_role_value === MEMBER_ROLE_ADMIN): ?>
                                                        <?php echo htmlspecialchars(member_role_label($member_role_value), ENT_QUOTES, 'UTF-8'); ?>
                                                    <?php else: ?>
                                                        <select class="form-select form-select-sm" name="role_value" aria-label="権限">
                                                            <?php foreach (MEMBER_ROLE_LABELS as $role_key => $role_label): ?>
                                                                <option value="<?php echo htmlspecialchars($role_key, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($member_role_value === $role_key || (!array_key_exists($member_role_value, MEMBER_ROLE_LABELS) && $role_key === 'member')) ? 'selected' : ''; ?>><?php echo htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8'); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    <?php endif; ?>
                                                </td>
                                                <?php // 所属クラブ（クラブ画面の中身がこれで決まる。所属なしはチーム紹介になる） ?>
                                                <?php $member_club_value = (string)($member_row['club'] ?? ''); ?>
                                                <td data-label="所属">
                                                    <select class="form-select form-select-sm" name="club_value" aria-label="所属クラブ">
                                                        <?php foreach (MEMBER_CLUB_LABELS as $club_key => $club_label): ?>
                                                            <option value="<?php echo htmlspecialchars($club_key, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $member_club_value === $club_key ? 'selected' : ''; ?>><?php echo htmlspecialchars($club_label, ENT_QUOTES, 'UTF-8'); ?></option>
                                                        <?php endforeach; ?>
                                                        <option value="none" <?php echo array_key_exists($member_club_value, MEMBER_CLUB_LABELS) ? '' : 'selected'; ?>>所属なし</option>
                                                    </select>
                                                </td>
                                                <?php // ピックアップ: 選ぶと、みんなのページの「ピックアップ」タブにこの人の投稿が並ぶ ?>
                                                <?php $member_is_pickup = in_array($member_row_id, $admin_pickup_ids, true); ?>
                                                <td data-label="ピックアップ">
                                                    <select class="form-select form-select-sm admin-pickup-select<?php echo $member_is_pickup ? ' is-on' : ''; ?>" name="pickup_value" aria-label="ピックアップ">
                                                        <option value="off" <?php echo $member_is_pickup ? '' : 'selected'; ?>>—</option>
                                                        <option value="on" <?php echo $member_is_pickup ? 'selected' : ''; ?>>★ ピックアップ</option>
                                                    </select>
                                                </td>
                                                <td data-label="性別">
                                                    <div class="admin-radio-row">
                                                        <div class="form-check form-check-inline mb-0">
                                                            <input class="form-check-input" type="radio" name="gender_value" id="genderMale<?php echo $member_row_id; ?>" value="male" <?php echo $member_gender_value === 'male' ? 'checked' : ''; ?>>
                                                            <label class="form-check-label" for="genderMale<?php echo $member_row_id; ?>">男</label>
                                                        </div>
                                                        <div class="form-check form-check-inline mb-0">
                                                            <input class="form-check-input" type="radio" name="gender_value" id="genderFemale<?php echo $member_row_id; ?>" value="female" <?php echo $member_gender_value === 'female' ? 'checked' : ''; ?>>
                                                            <label class="form-check-label" for="genderFemale<?php echo $member_row_id; ?>">女</label>
                                                        </div>
                                                    </div>
                                                </td>
                                                <?php $member_fee_paid = $admin_member_annual_fee_col !== null && member_annual_fee_is_paid($member_row[$admin_member_annual_fee_col] ?? ''); ?>
                                                <td data-label="年会費" class="admin-status-cell admin-fee-col">
                                                    <?php if ($member_is_jaaf_registered): ?>
                                                        <select class="form-select form-select-sm admin-fee-select <?php echo $member_fee_paid ? 'is-paid' : 'is-unpaid'; ?>" name="annual_fee_value" aria-label="年会費">
                                                            <option value="unpaid" <?php echo $member_fee_paid ? '' : 'selected'; ?>>未払い</option>
                                                            <option value="paid" <?php echo $member_fee_paid ? 'selected' : ''; ?>>支払い済み</option>
                                                        </select>
                                                    <?php else: ?>
                                                        <?php // 陸連未登録者は年会費の対象外。値を送らないので保存しても変わらない ?>
                                                        <span class="text-secondary">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="mobile-actions-cell"><button type="submit" class="btn btn-sm btn-outline-primary">保存</button></td>
                                            </form>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    </section>
                </div>
            </details>

            <details id="admin-tournaments">
                <summary>大会情報管理</summary>
                <div class="admin-section-body">
                    <details class="mb-3">
                        <summary>大会の新規追加</summary>
                        <div class="admin-section-body">
                            <form method="POST" class="row g-3 tournament-add-form" data-tournament-deadline-scope>
                                <input type="hidden" name="admin_action" value="add_tournament">
                                <input type="hidden" name="redirect_anchor" value="admin-tournaments">
                                <div class="col-12 col-sm-6 col-lg-4">
                                    <label class="form-label small mb-1">大会名</label>
                                    <input type="text" class="form-control" name="event_name" placeholder="大会名" required>
                                </div>
                                <div class="col-12 col-sm-6 col-lg-4">
                                    <label class="form-label small mb-1">場所</label>
                                    <input type="text" class="form-control" name="location" placeholder="場所" required>
                                </div>
                                <div class="col-12 col-sm-6 col-lg-4">
                                    <label class="form-label small mb-1">大会開催日</label>
                                    <input type="date" class="form-control" name="event_date" required>
                                </div>
                                <div class="col-12 col-sm-6 col-lg-4">
                                    <label class="form-label small mb-1">応募締切日（未入力可）</label>
                                    <input type="date" class="form-control" name="entry_deadline">
                                </div>
                                <div class="col-12 col-sm-6 col-lg-4">
                                    <label class="form-label small mb-1">表示締切（何日前から表示するか）</label>
                                    <select class="form-select" name="deadline_lead_days" data-role="deadline-lead-days">
                                        <?php for ($lead_option = 1; $lead_option <= 5; $lead_option++): ?>
                                            <option value="<?php echo $lead_option; ?>" <?php echo $lead_option === 3 ? 'selected' : ''; ?>><?php echo $lead_option; ?>日前</option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-sm-6 col-lg-4">
                                    <label class="form-label small mb-1">表示締切日（自動計算）</label>
                                    <div class="form-control bg-light text-secondary" data-role="display-deadline">—</div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small mb-1">概要</label>
                                    <input type="text" class="form-control" name="details" placeholder="概要" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small mb-1">大会概要リンク（任意）</label>
                                    <input type="url" class="form-control" name="official_link" placeholder="https://...">
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary w-100 w-lg-auto py-2 fw-bold">追加</button>
                                </div>
                            </form>
                        </div>
                    </details>

                    <section class="admin-panel" aria-labelledby="adminTournamentsTitle">
                            <div class="admin-panel-head">
                                <h3 class="admin-panel-title" id="adminTournamentsTitle">大会の編集/削除</h3>
                                <form method="POST" class="m-0">
                                    <input type="hidden" name="admin_action" value="manual_cleanup">
                                    <input type="hidden" name="redirect_anchor" value="admin-tournaments">
                                    <button type="submit" class="btn btn-sm btn-outline-warning">開催日超過データを手動クリーンアップ</button>
                                </form>
                            </div>

                            <?php if (empty($admin_tournaments)): ?>
                                <div class="admin-empty"><p>登録された大会はありません</p></div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mobile-card-table">
                                        <thead>
                                            <tr>
                                                <th>開催日</th>
                                                <th>応募締切日</th>
                                                <th>表示締切</th>
                                                <th>表示締切日</th>
                                                <th>大会名</th>
                                                <th>場所</th>
                                                <th>概要</th>
                                                <th>大会概要リンク</th>
                                                <th>操作</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($admin_tournaments as $tournament_row): ?>
                                                <?php $tournament_lead_days = (int)($tournament_row['deadline_lead_days'] ?? 3); ?>
                                                <?php if ($tournament_lead_days < 1 || $tournament_lead_days > 5) { $tournament_lead_days = 3; } ?>
                                                <tr>
                                                    <form method="POST" data-tournament-deadline-scope>
                                                        <input type="hidden" name="tournament_id" value="<?php echo (int)$tournament_row['id']; ?>">
                                                        <input type="hidden" name="redirect_anchor" value="admin-tournaments">
                                                        <td data-label="開催日"><input type="date" class="form-control form-control-sm" name="event_date" value="<?php echo htmlspecialchars((string)$tournament_row['event_date'], ENT_QUOTES, 'UTF-8'); ?>" required></td>
                                                        <td data-label="応募締切日"><input type="date" class="form-control form-control-sm" name="entry_deadline" value="<?php echo htmlspecialchars((string)($tournament_row['entry_deadline'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                        <td data-label="表示締切">
                                                            <select class="form-select form-select-sm" name="deadline_lead_days" data-role="deadline-lead-days">
                                                                <?php for ($lead_option = 1; $lead_option <= 5; $lead_option++): ?>
                                                                    <option value="<?php echo $lead_option; ?>" <?php echo $lead_option === $tournament_lead_days ? 'selected' : ''; ?>><?php echo $lead_option; ?>日前</option>
                                                                <?php endfor; ?>
                                                            </select>
                                                        </td>
                                                        <td data-label="表示締切日"><div class="form-control form-control-sm bg-light text-secondary" data-role="display-deadline"><?php echo !empty($tournament_row['display_deadline']) ? htmlspecialchars(date('Y/m/d', strtotime((string)$tournament_row['display_deadline'])), ENT_QUOTES, 'UTF-8') : '—'; ?></div></td>
                                                        <td data-label="大会名"><input type="text" class="form-control form-control-sm" name="event_name" value="<?php echo htmlspecialchars((string)$tournament_row['event_name'], ENT_QUOTES, 'UTF-8'); ?>" required></td>
                                                        <td data-label="場所"><input type="text" class="form-control form-control-sm" name="location" value="<?php echo htmlspecialchars((string)$tournament_row['location'], ENT_QUOTES, 'UTF-8'); ?>" required></td>
                                                        <td data-label="概要"><input type="text" class="form-control form-control-sm" name="details" value="<?php echo htmlspecialchars((string)$tournament_row['details'], ENT_QUOTES, 'UTF-8'); ?>" required></td>
                                                        <td data-label="大会概要リンク"><input type="url" class="form-control form-control-sm" name="official_link" value="<?php echo htmlspecialchars((string)($tournament_row['official_link'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                        <td class="d-flex gap-1 mobile-actions-cell">
                                                            <button type="submit" name="admin_action" value="update_tournament" class="btn btn-sm btn-outline-primary">更新</button>
                                                            <button type="submit" name="admin_action" value="delete_tournament" class="btn btn-sm btn-outline-danger" onclick="return confirm('大会を削除しますか？関連申込も削除されます。');">削除</button>
                                                        </td>
                                                    </form>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                    </section>

                    <section class="admin-panel" id="admin-entry-status" aria-labelledby="adminEntryStatusTitle">
                            <h3 class="admin-panel-title mb-1" id="adminEntryStatusTitle">申込一覧の承認</h3>
                            <p class="admin-small-note mb-2">承認=黄緑 / 未承認=赤 / 閲覧=黄色</p>
                            <?php if (empty($admin_entries)): ?>
                                <div class="admin-empty"><p>申込データはありません</p></div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle admin-entry-table">
                                        <thead>
                                            <tr>
                                                <th></th>
                                                <th>大会名</th>
                                                <th>名前</th>
                                                <th>JAAFID</th>
                                                <th>ゼッケン番号</th>
                                                <th>大会日</th>
                                                <th>ステータス</th>
                                                <th>更新</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($admin_entries as $entry_row): ?>
                                                <?php $status_value = trim((string)($entry_row['status'] ?? '未承認')); ?>
                                                <?php $status_form_id = 'entry-status-form-' . (int)$entry_row['id']; ?>
                                                <?php $entry_group_index = (int)((int)sprintf('%u', crc32((string)($entry_row['match_title'] ?? ''))) % 6); ?>
                                                <?php
                                                    $entry_events = [];
                                                    for ($event_slot = 1; $event_slot <= 3; $event_slot++) {
                                                        $event_name_value = trim((string)($entry_row['event_name' . $event_slot] ?? ''));
                                                        $event_record_value = trim((string)($entry_row['pb_time' . $event_slot] ?? ''));
                                                        if ($event_name_value === '' && $event_record_value === '') {
                                                            continue;
                                                        }
                                                        $entry_events[] = [
                                                            'name' => $event_name_value,
                                                            'record' => $event_record_value,
                                                        ];
                                                    }
                                                ?>
                                                <tr class="entry-group-tone-<?php echo $entry_group_index; ?> entry-toggle-row" tabindex="0" role="button" aria-expanded="false">
                                                    <td class="entry-toggle-cell text-center"><span class="entry-toggle-icon">▶</span></td>
                                                    <td><?php echo htmlspecialchars((string)$entry_row['match_title'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td><?php echo htmlspecialchars((string)$entry_row['user_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td><?php echo htmlspecialchars((string)($entry_row['jaaf_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td><?php echo htmlspecialchars((string)($entry_row['number_plate'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td><?php echo htmlspecialchars((string)($entry_row['event_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td class="admin-status-cell">
                                                        <form id="<?php echo htmlspecialchars($status_form_id, ENT_QUOTES, 'UTF-8'); ?>" method="POST" class="m-0">
                                                            <input type="hidden" name="admin_action" value="update_match_status">
                                                            <input type="hidden" name="match_id" value="<?php echo (int)$entry_row['id']; ?>">
                                                            <input type="hidden" name="redirect_anchor" value="admin-entry-status">
                                                            <select name="status" class="form-select form-select-sm admin-status-select status-<?php echo htmlspecialchars($status_value, ENT_QUOTES, 'UTF-8'); ?>">
                                                                <option value="承認" <?php echo $status_value === '承認' ? 'selected' : ''; ?>>承認</option>
                                                                <option value="未承認" <?php echo $status_value === '未承認' ? 'selected' : ''; ?>>未承認</option>
                                                                <option value="閲覧" <?php echo $status_value === '閲覧' ? 'selected' : ''; ?>>閲覧</option>
                                                            </select>
                                                        </form>
                                                    </td>
                                                    <td class="admin-update-cell">
                                                        <button type="submit" form="<?php echo htmlspecialchars($status_form_id, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-primary admin-save-btn">保存</button>
                                                    </td>
                                                </tr>
                                                <tr class="entry-detail-row entry-group-tone-<?php echo $entry_group_index; ?> d-none">
                                                    <td colspan="8">
                                                        <div class="entry-detail-box">
                                                            <?php if (empty($entry_events)): ?>
                                                                <div class="text-secondary small">種目・応募記録の登録はありません。</div>
                                                            <?php else: ?>
                                                                <table class="table table-sm table-borderless mb-0 entry-detail-table">
                                                                    <thead>
                                                                        <tr>
                                                                            <th>種目</th>
                                                                            <th>応募記録</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        <?php foreach ($entry_events as $event_item): ?>
                                                                            <tr>
                                                                                <td><?php echo htmlspecialchars($event_item['name'] !== '' ? $event_item['name'] : '（未入力）', ENT_QUOTES, 'UTF-8'); ?></td>
                                                                                <td><?php echo htmlspecialchars($event_item['record'] !== '' ? $event_item['record'] : '（未入力）', ENT_QUOTES, 'UTF-8'); ?></td>
                                                                            </tr>
                                                                        <?php endforeach; ?>
                                                                    </tbody>
                                                                </table>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                    </section>
                </div>
            </details>

            <details id="admin-diaries">
                <summary>練習日記管理</summary>
                <div class="admin-section-body">
                    <section class="admin-panel" aria-labelledby="adminDiariesTitle">
                    <h3 class="admin-panel-title" id="adminDiariesTitle">投稿（<?php echo count($admin_diary_posts ?? []); ?>件）</h3>
                    <?php if (empty($admin_diary_posts)): ?>
                        <div class="admin-empty"><p>投稿データはありません</p></div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>投稿日時</th>
                                        <th>投稿者</th>
                                        <th>練習日</th>
                                        <th>メニュー</th>
                                        <th>コメント</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($admin_diary_posts as $diary_row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string)$diary_row['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars((string)$diary_row['user_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars((string)$diary_row['practice_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars((string)$diary_row['menu'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars((string)($diary_row['comment'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td>
                                                <form method="POST" class="m-0" onsubmit="return confirm('本当に削除しますか？');">
                                                    <input type="hidden" name="admin_action" value="delete_diary">
                                                    <input type="hidden" name="diary_id" value="<?php echo (int)$diary_row['id']; ?>">
                                                    <input type="hidden" name="redirect_anchor" value="admin-diaries">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">削除</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    </section>
                </div>
            </details>

            <details id="admin-gallery-posts">
                <summary>ギャラリー投稿管理</summary>
                <div class="admin-section-body">
                    <section class="admin-panel" aria-labelledby="adminGalleryTitle">
                    <h3 class="admin-panel-title" id="adminGalleryTitle">投稿（<?php echo count($admin_gallery_posts ?? []); ?>件）</h3>
                    <?php if (empty($admin_gallery_posts)): ?>
                        <div class="admin-empty"><p>投稿データはありません</p></div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>投稿日時</th>
                                        <th>投稿者</th>
                                        <th>日付</th>
                                        <th>タイトル</th>
                                        <th>本文</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($admin_gallery_posts as $gallery_row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string)$gallery_row['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars((string)$gallery_row['user_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars((string)($gallery_row['event_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars((string)$gallery_row['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars((string)($gallery_row['body'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td>
                                                <form method="POST" class="m-0" onsubmit="return confirm('このギャラリー投稿を削除しますか？');">
                                                    <input type="hidden" name="admin_action" value="delete_gallery_post">
                                                    <input type="hidden" name="gallery_id" value="<?php echo (int)$gallery_row['id']; ?>">
                                                    <input type="hidden" name="redirect_anchor" value="admin-gallery-posts">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">削除</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    </section>
                </div>
            </details>

            <details id="admin-records">
                <summary>歴代記録管理</summary>
                <div class="admin-section-body">
                    <section class="admin-panel" aria-labelledby="adminRecordAddTitle">
                            <h3 class="admin-panel-title" id="adminRecordAddTitle">歴代記録の追加</h3>
                            <form method="POST" class="row g-2 admin-record-form" data-role="add-record">
                                <input type="hidden" name="admin_action" value="add_record_admin">
                                <input type="hidden" name="redirect_anchor" value="admin-records">
                                <?php // 選択（区分・性別）は1行目にまとめ、文字の長さに合わせた幅をとる（狭い欄だと隣にはみ出して重なるため） ?>
                                <div class="col-12 col-md-auto">
                                    <div class="admin-radio-row" role="radiogroup" aria-labelledby="recordDisciplineLabelNew">
                                    <span class="admin-radio-label" id="recordDisciplineLabelNew">区分</span>
                                    <div class="form-check form-check-inline mb-0">
                                        <input class="form-check-input" type="radio" name="record_discipline" id="record_discipline_track_new" value="track" checked>
                                        <label class="form-check-label" for="record_discipline_track_new">トラック</label>
                                    </div>
                                    <div class="form-check form-check-inline mb-0">
                                        <input class="form-check-input" type="radio" name="record_discipline" id="record_discipline_field_new" value="field">
                                        <label class="form-check-label" for="record_discipline_field_new">フィールド</label>
                                    </div>
                                    </div>
                                </div>
                                <div class="col-12 col-md-auto">
                                    <div class="admin-radio-row" role="radiogroup" aria-labelledby="recordGenderLabelNew">
                                    <span class="admin-radio-label" id="recordGenderLabelNew">性別</span>
                                    <div class="form-check form-check-inline mb-0">
                                        <input class="form-check-input" type="radio" name="record_gender" id="record_gender_male_new" value="male" checked>
                                        <label class="form-check-label" for="record_gender_male_new">男</label>
                                    </div>
                                    <div class="form-check form-check-inline mb-0">
                                        <input class="form-check-input" type="radio" name="record_gender" id="record_gender_female_new" value="female">
                                        <label class="form-check-label" for="record_gender_female_new">女</label>
                                    </div>
                                    </div>
                                </div>
                                <div class="w-100"></div>
                                <div class="col-6 col-md-2">
                                    <select class="form-select form-select-sm" name="event_type" data-role="event-select" required>
                                        <?php foreach (($record_event_options['male_track'] ?? []) as $event_option): ?>
                                            <option value="<?php echo htmlspecialchars((string)$event_option, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$event_option, ENT_QUOTES, 'UTF-8'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6 col-md-1"><input type="number" class="form-control form-control-sm" name="rank_num" min="1" placeholder="順位" required></div>
                                <div class="col-12 col-md-3"><input type="text" class="form-control form-control-sm" name="name" placeholder="氏名" required></div>
                                <div class="col-6 col-md-2"><input type="text" class="form-control form-control-sm" name="record_time" placeholder="記録" required></div>
                                <div class="col-6 col-md-2">
                                    <select class="form-select form-select-sm" name="wind_mps" data-role="record-wind" aria-label="風速">
                                        <option value="">風速 未入力</option>
                                        <?php foreach (record_wind_options() as $wind_option): ?>
                                            <option value="<?php echo $wind_option; ?>" <?php echo $wind_option === '' ? 'selected' : ''; ?>><?php echo $wind_option; ?> m/s</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6 col-md-2"><input type="date" class="form-control form-control-sm" name="match_date" aria-label="日付" required></div>
                                <div class="col-12 col-md-6"><input type="text" class="form-control form-control-sm" name="match_name" placeholder="大会名" required></div>
                                <div class="col-12"><button type="submit" class="btn btn-sm btn-primary">追加</button></div>
                            </form>
                    </section>

                    <section class="admin-panel" aria-labelledby="adminRecordsTitle">
                    <h3 class="admin-panel-title" id="adminRecordsTitle">歴代記録（<?php echo count($admin_records ?? []); ?>件）</h3>
                    <?php if (empty($admin_records)): ?>
                        <div class="admin-empty"><p>歴代記録データはありません</p></div>
                    <?php else: ?>
                        <?php $record_all_events = array_values(array_unique(array_merge($record_track_events, $record_field_events))); ?>
                        <div class="row g-2 align-items-center mb-3" id="admin-record-event-filter">
                            <div class="col-12 col-md-4">
                                <label class="form-label small mb-1" for="adminRecordEventFilterSelect">種目で絞り込み</label>
                                <select class="form-select form-select-sm" id="adminRecordEventFilterSelect">
                                    <option value="all">すべて</option>
                                    <?php foreach ($record_all_events as $event_option): ?>
                                        <option value="<?php echo htmlspecialchars((string)$event_option, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$event_option, ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0" id="admin-record-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>区分</th>
                                        <th>性別</th>
                                        <th>種目</th>
                                        <th>順位</th>
                                        <th>氏名</th>
                                        <th>記録</th>
                                        <th>風速</th>
                                        <th>大会名</th>
                                        <th>日付</th>
                                        <th>更新</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($admin_records as $record_row): ?>
                                        <tr data-event-type="<?php echo htmlspecialchars((string)($record_row['event_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                            <form method="POST" class="admin-record-form" data-role="edit-record">
                                                <input type="hidden" name="admin_action" value="update_record_admin">
                                                <input type="hidden" name="record_id" value="<?php echo (int)$record_row['id']; ?>">
                                                <input type="hidden" name="redirect_anchor" value="admin-records">
                                                <td><?php echo (int)$record_row['id']; ?></td>
                                                <td>
                                                    <div class="admin-radio-row">
                                                    <div class="form-check form-check-inline mb-0">
                                                        <input class="form-check-input" type="radio" name="record_discipline" value="track" <?php echo (string)$record_row['ui_discipline'] === 'track' ? 'checked' : ''; ?>>
                                                        <label class="form-check-label">トラック</label>
                                                    </div>
                                                    <div class="form-check form-check-inline mb-0">
                                                        <input class="form-check-input" type="radio" name="record_discipline" value="field" <?php echo (string)$record_row['ui_discipline'] === 'field' ? 'checked' : ''; ?>>
                                                        <label class="form-check-label">フィールド</label>
                                                    </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="admin-radio-row">
                                                    <div class="form-check form-check-inline mb-0">
                                                        <input class="form-check-input" type="radio" name="record_gender" value="male" <?php echo (string)$record_row['ui_gender'] === 'male' ? 'checked' : ''; ?>>
                                                        <label class="form-check-label">男</label>
                                                    </div>
                                                    <div class="form-check form-check-inline mb-0">
                                                        <input class="form-check-input" type="radio" name="record_gender" value="female" <?php echo (string)$record_row['ui_gender'] === 'female' ? 'checked' : ''; ?>>
                                                        <label class="form-check-label">女</label>
                                                    </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php
                                                    $record_form_key = (string)($record_row['ui_gender'] ?? 'male') . '_' . (string)($record_row['ui_discipline'] ?? 'track');
                                                    $record_form_event_list = $record_event_options[$record_form_key] ?? ($record_event_options['male_track'] ?? []);
                                                    $record_selected_event = (string)($record_row['event_type'] ?? '');
                                                    if ($record_selected_event !== '' && !in_array($record_selected_event, $record_form_event_list, true)) {
                                                        $record_form_event_list[] = $record_selected_event;
                                                    }
                                                    ?>
                                                    <select class="form-select form-select-sm" name="event_type" data-role="event-select" data-selected-event="<?php echo htmlspecialchars($record_selected_event, ENT_QUOTES, 'UTF-8'); ?>" required>
                                                        <?php foreach ($record_form_event_list as $event_option): ?>
                                                            <option value="<?php echo htmlspecialchars((string)$event_option, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (string)$event_option === $record_selected_event ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$event_option, ENT_QUOTES, 'UTF-8'); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td><input type="number" class="form-control form-control-sm" name="rank_num" min="1" value="<?php echo (int)$record_row['rank_num']; ?>" required></td>
                                                <td><input type="text" class="form-control form-control-sm" name="name" value="<?php echo htmlspecialchars((string)$record_row['name'], ENT_QUOTES, 'UTF-8'); ?>" required></td>
                                                <td><input type="text" class="form-control form-control-sm" name="record_time" value="<?php echo htmlspecialchars((string)$record_row['record_time'], ENT_QUOTES, 'UTF-8'); ?>" required></td>
                                                <?php $record_wind_value = $record_row['wind_mps'] !== null ? sprintf('%+.1f', (float)$record_row['wind_mps']) : ''; ?>
                                                <td>
                                    <select class="form-select form-select-sm" name="wind_mps" data-role="record-wind" aria-label="風速">
                                        <option value="">風速 未入力</option>
                                        <?php foreach (record_wind_options() as $wind_option): ?>
                                            <option value="<?php echo $wind_option; ?>" <?php echo $wind_option === $record_wind_value ? 'selected' : ''; ?>><?php echo $wind_option; ?> m/s</option>
                                        <?php endforeach; ?>
                                    </select>
                                                </td>
                                                <td><input type="text" class="form-control form-control-sm" name="match_name" value="<?php echo htmlspecialchars((string)$record_row['match_name'], ENT_QUOTES, 'UTF-8'); ?>" required></td>
                                                <td><input type="date" class="form-control form-control-sm" name="match_date" value="<?php echo htmlspecialchars((string)$record_row['match_date'], ENT_QUOTES, 'UTF-8'); ?>" required></td>
                                                <td class="d-flex gap-1">
                                                    <button type="submit" class="btn btn-sm btn-outline-primary">更新</button>
                                                    <button type="submit" name="admin_action" value="delete_record_admin" class="btn btn-sm btn-outline-danger" onclick="return confirm('この歴代記録を削除しますか？');">削除</button>
                                                </td>
                                            </form>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    </section>
                </div>
            </details>

            <details id="admin-announcements">
                <summary>お知らせ管理</summary>
                <?php // ボタンの行き先の入力欄に出す候補（自由に入力もできる） ?>
                <datalist id="announcementLinkSuggestions">
                    <?php foreach (ANNOUNCEMENT_LINK_SUGGESTIONS as $suggestion_path => $suggestion_name): ?>
                        <option value="<?php echo htmlspecialchars($suggestion_path, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($suggestion_name, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </datalist>
                <div class="admin-section-body">
                    <section class="admin-panel" aria-labelledby="adminAnnouncementAddTitle">
                            <h3 class="admin-panel-title" id="adminAnnouncementAddTitle">お知らせの追加</h3>
                            <form method="POST" class="row g-2">
                                <input type="hidden" name="admin_action" value="add_announcement">
                                <input type="hidden" name="redirect_anchor" value="admin-announcements">
                                <div class="col-md-3"><input type="text" class="form-control form-control-sm" name="title" placeholder="タイトル" required></div>
                                <div class="col-md-3"><input type="text" class="form-control form-control-sm" name="body" placeholder="本文" required></div>
                                <?php // 重要: 選ぶとベル・ホーム・PC の補助欄で、タイトルの前に赤い「重要」の札が付く ?>
                                <div class="col-md-1">
                                    <select name="is_important" class="form-select form-select-sm admin-important-select" aria-label="重要かどうか">
                                        <option value="0" selected>通常</option>
                                        <option value="1">重要</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <select name="link_enabled" class="form-select form-select-sm" aria-label="ボタンの有無" data-announcement-link>
                                        <option value="" selected>ボタンなし</option>
                                        <option value="1">ボタンあり</option>
                                    </select>
                                    <div data-announcement-link-fields hidden>
                                        <input type="text" class="form-control form-control-sm mt-1" name="link_label" maxlength="<?php echo ANNOUNCEMENT_LINK_LABEL_MAX; ?>" placeholder="ボタンの文字（例 詳しく見る）" aria-label="ボタンの文字">
                                        <input type="text" class="form-control form-control-sm mt-1" name="link_url" maxlength="<?php echo ANNOUNCEMENT_LINK_URL_MAX; ?>" placeholder="行き先（https://… またはサイト内のページ）" aria-label="ボタンの行き先" list="announcementLinkSuggestions" autocomplete="off">
                                    </div>
                                </div>
                                <div class="col-md-1">
                                    <select name="display_order" class="form-select form-select-sm">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                    </select>
                                </div>
                                <div class="col-md-1">
                                    <select name="is_published" class="form-select form-select-sm">
                                        <option value="1">公開</option>
                                        <option value="0">非公開</option>
                                    </select>
                                </div>
                                <div class="col-md-1"><button type="submit" class="btn btn-sm btn-primary w-100">追加</button></div>
                            </form>
                    </section>

                    <section class="admin-panel" aria-labelledby="adminAnnouncementsTitle">
                    <h3 class="admin-panel-title" id="adminAnnouncementsTitle">お知らせ一覧（<?php echo count($admin_announcements ?? []); ?>件）</h3>
                    <?php if (empty($admin_announcements)): ?>
                        <div class="admin-empty"><p>お知らせはありません</p></div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>タイトル</th>
                                        <th>本文</th>
                                        <th>重要</th>
                                        <th>ボタン</th>
                                        <th>順序</th>
                                        <th>公開状態</th>
                                        <th>更新</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($admin_announcements as $announcement_row): ?>
                                        <?php $announcement_order = (int)$announcement_row['display_order']; ?>
                                        <?php if ($announcement_order < 1 || $announcement_order > 3) { $announcement_order = 1; } ?>
                                        <tr>
                                            <form method="POST">
                                                <input type="hidden" name="announcement_id" value="<?php echo (int)$announcement_row['id']; ?>">
                                                <input type="hidden" name="redirect_anchor" value="admin-announcements">
                                                <td><?php echo (int)$announcement_row['id']; ?></td>
                                                <td><input type="text" class="form-control form-control-sm" name="title" value="<?php echo htmlspecialchars((string)$announcement_row['title'], ENT_QUOTES, 'UTF-8'); ?>" required></td>
                                                <td><input type="text" class="form-control form-control-sm" name="body" value="<?php echo htmlspecialchars((string)$announcement_row['body'], ENT_QUOTES, 'UTF-8'); ?>" required></td>
                                                <?php $row_is_important = (int)($announcement_row['is_important'] ?? 0) === 1; ?>
                                                <td>
                                                    <select name="is_important" class="form-select form-select-sm admin-important-select<?php echo $row_is_important ? ' is-on' : ''; ?>" aria-label="重要かどうか">
                                                        <option value="0" <?php echo $row_is_important ? '' : 'selected'; ?>>通常</option>
                                                        <option value="1" <?php echo $row_is_important ? 'selected' : ''; ?>>重要</option>
                                                    </select>
                                                </td>
                                                <td>
                                                    <?php $row_has_link = trim((string)($announcement_row['link_url'] ?? '')) !== ''; ?>
                                                    <select name="link_enabled" class="form-select form-select-sm" aria-label="ボタンの有無" data-announcement-link>
                                                        <option value="" <?php echo $row_has_link ? '' : 'selected'; ?>>ボタンなし</option>
                                                        <option value="1" <?php echo $row_has_link ? 'selected' : ''; ?>>ボタンあり</option>
                                                    </select>
                                                    <div data-announcement-link-fields <?php echo $row_has_link ? '' : 'hidden'; ?>>
                                                        <input type="text" class="form-control form-control-sm mt-1" name="link_label" maxlength="<?php echo ANNOUNCEMENT_LINK_LABEL_MAX; ?>" value="<?php echo htmlspecialchars((string)($announcement_row['link_label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="ボタンの文字（例 詳しく見る）" aria-label="ボタンの文字">
                                                        <input type="text" class="form-control form-control-sm mt-1" name="link_url" maxlength="<?php echo ANNOUNCEMENT_LINK_URL_MAX; ?>" value="<?php echo htmlspecialchars((string)($announcement_row['link_url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="行き先（https://… またはサイト内のページ）" aria-label="ボタンの行き先" list="announcementLinkSuggestions" autocomplete="off">
                                                    </div>
                                                </td>
                                                <td>
                                                    <select name="display_order" class="form-select form-select-sm">
                                                        <?php for ($order_option = 1; $order_option <= 3; $order_option++): ?>
                                                            <option value="<?php echo $order_option; ?>" <?php echo $order_option === $announcement_order ? 'selected' : ''; ?>><?php echo $order_option; ?></option>
                                                        <?php endfor; ?>
                                                    </select>
                                                </td>
                                                <td>
                                                    <select name="is_published" class="form-select form-select-sm">
                                                        <option value="1" <?php echo (int)$announcement_row['is_published'] === 1 ? 'selected' : ''; ?>>公開</option>
                                                        <option value="0" <?php echo (int)$announcement_row['is_published'] === 0 ? 'selected' : ''; ?>>非公開</option>
                                                    </select>
                                                </td>
                                                <?php // 更新と削除の押し間違いを防ぐため、間を広めに空ける ?>
                                                <td class="d-flex gap-3">
                                                    <button type="submit" name="admin_action" value="update_announcement" class="btn btn-sm btn-outline-primary">更新</button>
                                                    <button type="submit" name="admin_action" value="delete_announcement" class="btn btn-sm btn-outline-danger" onclick="return confirm('このお知らせを削除しますか？');">削除</button>
                                                </td>
                                            </form>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    </section>
                </div>
            </details>

            <details id="admin-inquiries">
                <summary>お問い合わせ管理</summary>
                <div class="admin-section-body">
                    <section class="admin-panel" aria-labelledby="adminInquiriesTitle">
                    <h3 class="admin-panel-title" id="adminInquiriesTitle">お問い合わせ（<?php echo count($admin_inquiries ?? []); ?>件）</h3>
                    <?php if (empty($admin_inquiries)): ?>
                        <div class="admin-empty"><p>お問い合わせはありません</p></div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>日時</th>
                                        <th>送信者</th>
                                        <th>内容</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($admin_inquiries as $inquiry_row): ?>
                                        <?php $inquiry_is_anonymous = (int)($inquiry_row['is_anonymous'] ?? 0) === 1; ?>
                                        <?php $inquiry_sender = $inquiry_is_anonymous ? '匿名' : (string)($inquiry_row['sender_name'] ?? '匿名'); ?>
                                        <?php $inquiry_wants_membership = (int)($inquiry_row['wants_team_membership'] ?? 0) === 1; ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string)$inquiry_row['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($inquiry_sender, ENT_QUOTES, 'UTF-8'); ?>
                                                <?php if ($inquiry_wants_membership): ?>
                                                    <span class="badge bg-success ms-1">チーム所属希望</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="white-space:pre-wrap;">
                                                <?php echo htmlspecialchars((string)$inquiry_row['message'], ENT_QUOTES, 'UTF-8'); ?>
                                                <?php if ($inquiry_wants_membership): ?>
                                                    <details class="mt-2">
                                                        <summary class="small text-primary" style="cursor:pointer;">チーム所属の申し込み情報を見る</summary>
                                                        <div class="small mt-1">
                                                            <div>氏名: <?php echo htmlspecialchars((string)($inquiry_row['applicant_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>（<?php echo htmlspecialchars((string)($inquiry_row['applicant_furigana'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>）</div>
                                                            <div>生年月日: <?php echo htmlspecialchars((string)($inquiry_row['applicant_birthdate'] ?? '未入力'), ENT_QUOTES, 'UTF-8'); ?></div>
                                                            <div>郵便番号: 〒<?php echo htmlspecialchars((string)($inquiry_row['applicant_postal_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                                            <div>住所: <?php echo htmlspecialchars((string)($inquiry_row['applicant_prefecture'] ?? '') . (string)($inquiry_row['applicant_address'] ?? '') . ' ' . (string)($inquiry_row['applicant_address_detail'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                                            <div>電話番号: <?php echo htmlspecialchars((string)($inquiry_row['applicant_phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                                        </div>
                                                    </details>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <form method="POST" class="m-0" onsubmit="return confirm('このお問い合わせを削除しますか？');">
                                                    <input type="hidden" name="admin_action" value="delete_inquiry">
                                                    <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry_row['id']; ?>">
                                                    <input type="hidden" name="redirect_anchor" value="admin-inquiries">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">削除</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    </section>
                </div>
            </details>

            <details id="admin-group-requests">
                <?php // id はベル通知のリンク先なので、名前を「グループ管理」に変えても id は前のまま ?>
                <summary>グループ管理<?php if (!empty($admin_group_delete_requests)): ?>（削除申請 <?php echo count($admin_group_delete_requests); ?>件）<?php endif; ?></summary>
                <div class="admin-section-body">
                    <section class="admin-panel" aria-labelledby="agmRequestsTitle">
                        <h3 class="admin-panel-title" id="agmRequestsTitle">削除申請</h3>
                        <?php if (empty($admin_group_delete_requests)): ?>
                            <div class="admin-empty">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="8 12.5 11 15.5 16 9.5"/></svg>
                                <p>承認待ちの削除申請はありません</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="agm-table">
                                    <thead>
                                        <tr>
                                            <th>グループ名</th>
                                            <th>申請者（代表者）</th>
                                            <th>申請日</th>
                                            <th>人数</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($admin_group_delete_requests as $group_request): ?>
                                            <tr>
                                                <td class="agm-name"><?php echo htmlspecialchars((string)$group_request['group_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars((string)$group_request['requester_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="agm-date"><?php echo htmlspecialchars(date('Y/m/d', strtotime((string)$group_request['requested_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo (int)$group_request['member_count']; ?>人</td>
                                                <td>
                                                    <form method="POST" class="agm-actions">
                                                        <input type="hidden" name="request_id" value="<?php echo (int)$group_request['id']; ?>">
                                                        <input type="hidden" name="redirect_anchor" value="admin-group-requests">
                                                        <button type="submit" name="admin_action" value="approve_group_delete" class="agm-danger-btn" onclick="return confirm(<?php echo htmlspecialchars(json_encode('「' . (string)$group_request['group_name'] . '」を削除しますか？元に戻せません。', JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>);">承認して削除</button>
                                                        <button type="submit" name="admin_action" value="reject_group_delete" class="agm-plain-btn">却下</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="admin-panel" aria-labelledby="agmGroupsTitle">
                        <h3 class="admin-panel-title" id="agmGroupsTitle">すべてのグループ（<?php echo count($admin_member_groups); ?>件）</h3>
                        <?php if (empty($admin_member_groups)): ?>
                            <div class="admin-empty">
                                <p>グループはまだありません</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="agm-table">
                                    <thead>
                                        <tr>
                                            <th>グループ名</th>
                                            <th>代表者</th>
                                            <th>メンバー</th>
                                            <th>活動</th>
                                            <th>作成日</th>
                                            <th>状態</th>
                                            <th>削除</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($admin_member_groups as $admin_group): ?>
                                            <tr>
                                                <td>
                                                    <span class="agm-name"><?php echo htmlspecialchars($admin_group['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                    <?php // 不適切な名前などのとき、管理者が変えられる（保存の前に確認のアラートを出す） ?>
                                                    <details class="agm-rename">
                                                        <summary>名前を変更</summary>
                                                        <form method="POST" class="agm-rename-form" data-admin-group-rename data-old-name="<?php echo htmlspecialchars($admin_group['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                                            <input type="hidden" name="admin_action" value="admin_rename_group">
                                                            <input type="hidden" name="group_id" value="<?php echo (int)$admin_group['id']; ?>">
                                                            <input type="hidden" name="redirect_anchor" value="admin-group-requests">
                                                            <input type="text" class="form-control form-control-sm" name="group_name" maxlength="<?php echo MEMBER_GROUP_NAME_MAX; ?>" value="<?php echo htmlspecialchars($admin_group['name'], ENT_QUOTES, 'UTF-8'); ?>" required aria-label="新しいグループ名">
                                                            <button type="submit" class="agm-plain-btn">変更</button>
                                                        </form>
                                                    </details>
                                                </td>
                                                <td><?php echo htmlspecialchars($admin_group['owner_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td>
                                                    <?php echo (int)$admin_group['member_count']; ?>人
                                                    <span class="agm-sub"><?php echo htmlspecialchars($admin_group['member_names'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                </td>
                                                <td>
                                                    <?php // 活動状況（日記の中身は見ない） ?>
                                                    日記 <?php echo (int)$admin_group['diary_count']; ?>件
                                                    <span class="agm-sub">最終投稿 <?php echo $admin_group['last_posted_at'] !== null ? htmlspecialchars(date('Y/m/d', strtotime($admin_group['last_posted_at'])), ENT_QUOTES, 'UTF-8') : 'なし'; ?></span>
                                                </td>
                                                <td class="agm-date"><?php echo htmlspecialchars(date('Y/m/d', strtotime($admin_group['created_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td>
                                                    <?php if ($admin_group['pending_request_id'] !== null): ?>
                                                        <span class="agm-pill is-pending">削除申請中</span>
                                                    <?php else: ?>
                                                        <span class="agm-pill">通常</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($admin_group['pending_request_id'] !== null): ?>
                                                        <span class="agm-sub">削除申請から</span>
                                                    <?php elseif ($admin_group['admin_can_delete']): ?>
                                                        <form method="POST" class="m-0">
                                                            <input type="hidden" name="admin_action" value="admin_delete_group">
                                                            <input type="hidden" name="group_id" value="<?php echo (int)$admin_group['id']; ?>">
                                                            <input type="hidden" name="redirect_anchor" value="admin-group-requests">
                                                            <?php $admin_delete_message = '「' . $admin_group['name'] . '」はメンバーが' . $admin_group['member_count'] . '人のため、削除申請がなくても削除できます。' . "\n" . '本当に削除しますか？元に戻せません。'; ?>
                                                            <button type="submit" class="agm-danger-btn" onclick="return confirm(<?php echo htmlspecialchars(json_encode($admin_delete_message, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>);">削除</button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="agm-sub">申請が必要</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>
            </details>
        </section>
        </div><?php // .admin-desk-main ?>
        </div><?php // .admin-desk ?>
    <?php endif; ?>

    <?php $member_initial = function_exists('mb_substr') ? mb_substr($user_name, 0, 1) : substr($user_name, 0, 1); ?>
    <div class="card card-clean mb-4 member-card">
        <div class="card-body">
            <div class="member-card-head">
                <h2 class="h5 mb-0 fw-bold text-dark">会員情報</h2>
                <?php if (!empty($member)): ?>
                    <button type="button" class="password-change-pill" data-bs-toggle="modal" data-bs-target="#passwordChangeModal">
                        🔑 パスワード変更
                        <?php if ($member_has_initial_password): ?>
                            <span class="password-change-pill-dot" aria-label="初期パスワードのまま"></span>
                        <?php endif; ?>
                    </button>
                <?php endif; ?>
            </div>

            <div class="member-card-main">
            <dl class="member-info-list">
                <div class="member-info-item">
                    <dt>JAAFID</dt>
                    <dd>
                        <?php if ($jaaf_registered && $jaaf_id_value !== ''): ?>
                            <?php echo htmlspecialchars($jaaf_id_value, ENT_QUOTES, 'UTF-8'); ?>
                        <?php else: ?>
                            <span class="member-info-empty">未登録です</span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="member-info-item">
                    <dt>ゼッケン番号</dt>
                    <dd>
                        <?php if ($number_plate_value !== ''): ?>
                            <?php echo htmlspecialchars($number_plate_value, ENT_QUOTES, 'UTF-8'); ?>
                        <?php else: ?>
                            <span class="member-info-empty">未登録</span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="member-info-item">
                    <dt>メールアドレス</dt>
                    <dd>
                        <?php if ($member_email_value !== ''): ?>
                            <div class="member-email-view" id="memberEmailView">
                                <span><?php echo htmlspecialchars($member_email_value, ENT_QUOTES, 'UTF-8'); ?></span>
                                <button type="button" class="member-inline-link" data-member-email-edit>変更</button>
                            </div>
                        <?php endif; ?>
                        <form class="member-email-form" id="memberEmailForm" action="../back/save_member_email.php" method="POST"<?php echo $member_email_value !== '' ? ' hidden' : ''; ?>>
                            <input type="email" class="form-control form-control-sm" name="email" maxlength="255" placeholder="you@example.com" value="<?php echo htmlspecialchars($member_email_value, ENT_QUOTES, 'UTF-8'); ?>" required>
                            <button type="submit" class="btn btn-sm btn-primary text-nowrap"><?php echo $member_email_value !== '' ? '変更' : '保存'; ?></button>
                            <?php if ($member_email_value !== ''): ?>
                                <button type="button" class="member-inline-link text-secondary" data-member-email-cancel>キャンセル</button>
                            <?php endif; ?>
                        </form>
                        <?php if ($member_email_value === ''): ?>
                            <div class="small text-muted fw-normal mt-1">パスワードを忘れたときの再設定に使います。</div>
                        <?php endif; ?>
                    </dd>
                </div>
                <?php if (!$is_diary_only_member): ?>
                    <div class="member-info-item">
                        <dt>年会費</dt>
                        <dd>
                            <?php if ($annual_fee_paid): ?>
                                <span class="fee-badge fee-badge--paid">支払い済み</span>
                            <?php else: ?>
                                <span class="fee-badge fee-badge--unpaid">未払い</span>
                            <?php endif; ?>
                        </dd>
                    </div>
                <?php endif; ?>
            </dl>
            </div>
        </div>
    </div>

    <div class="modal fade" id="profileIconModal" tabindex="-1" aria-labelledby="profileIconModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title fs-6 fw-bold" id="profileIconModalLabel">アイコンを変更</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                </div>
                <div class="modal-body">
                    <div class="icon-modal-preview">
                        <?php if ($member_avatar_path !== ''): ?>
                            <img src="../<?php echo htmlspecialchars($member_avatar_path, ENT_QUOTES, 'UTF-8'); ?>" alt="プロフィールアイコン" class="profile-icon-preview" id="profileIconPreview">
                        <?php else: ?>
                            <span class="profile-icon-fallback" id="profileIconFallback"><?php echo htmlspecialchars((string)$member_initial, ENT_QUOTES, 'UTF-8'); ?></span>
                            <img src="" alt="プロフィールアイコン" class="profile-icon-preview" id="profileIconPreview" hidden>
                        <?php endif; ?>
                    </div>

                    <form action="../back/save_profile_icon.php" method="POST" enctype="multipart/form-data">
                        <input class="visually-hidden" id="profileIconInput" type="file" name="profile_icon" accept="image/jpeg,image/png,image/webp,image/gif" required>
                        <label for="profileIconInput" class="icon-choice-btn" id="profileIconChooseBtn">🖼 画像を選ぶ</label>
                        <div class="profile-icon-filename" id="profileIconFilename" hidden></div>
                        <button type="submit" class="btn btn-primary fw-bold rounded-pill w-100 mt-3" id="profileIconSubmit" hidden>この画像で更新</button>
                    </form>

                    <?php if ($member_avatar_path !== ''): ?>
                        <div class="icon-choice-gap"></div>
                        <form action="../back/delete_profile_icon.php" method="POST" onsubmit="return confirm('プロフィールアイコンを初期状態に戻します。よろしいですか？');">
                            <button type="submit" class="icon-choice-btn icon-choice-btn--danger">初期状態に戻す</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($member)): ?>
        <div class="modal fade" id="passwordChangeModal" tabindex="-1" aria-labelledby="passwordChangeModalLabel" aria-hidden="true" data-auto-open="<?php echo $password_change_error !== '' ? '1' : '0'; ?>">
          <div class="modal-dialog modal-dialog-centered">
           <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5 fw-bold" id="passwordChangeModalLabel">
                    🔑 パスワード変更（任意）
                    <?php if ($member_has_initial_password): ?>
                        <span class="badge text-bg-warning ms-1 align-middle">初期パスワードのまま</span>
                    <?php endif; ?>
                </h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
            </div>
            <div class="modal-body">
                <?php if ($password_change_error !== ''): ?>
                    <div class="alert alert-danger py-2" role="alert"><?php echo htmlspecialchars($password_change_error, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
                <?php if ($member_email_value === ''): ?>
                    <p class="mb-0 text-secondary">パスワードを変更する前に、上の「会員情報」でメールアドレスを登録してください。<br>パスワードを忘れたときの再設定に必要です。</p>
                <?php else: ?>
                    <p class="text-secondary small">
                        <?php if ($member_has_initial_password): ?>
                            現在のパスワード欄には、ログインに使っている生年月日を入力してください。変更後は生年月日ではログインできなくなります。
                        <?php else: ?>
                            現在のパスワードと、新しいパスワードを入力してください。
                        <?php endif; ?>
                    </p>
                    <form action="../back/change_password.php" method="POST" class="password-change-form">
                        <div class="mb-3">
                            <label for="current_password" class="form-label fw-bold">現在のパスワード</label>
                            <div class="input-group">
                                <input type="password" id="current_password" name="current_password" class="form-control" autocomplete="current-password" required>
                                <?php $password_toggle_target = 'current_password'; require __DIR__ . '/../includes/password_toggle_button.php'; ?>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="new_password" class="form-label fw-bold">新しいパスワード（<?php echo MEMBER_PASSWORD_MIN_LENGTH; ?>文字以上）</label>
                            <div class="input-group">
                                <input type="password" id="new_password" name="new_password" class="form-control" minlength="<?php echo MEMBER_PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password" required>
                                <?php $password_toggle_target = 'new_password'; require __DIR__ . '/../includes/password_toggle_button.php'; ?>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label fw-bold">新しいパスワード（確認）</label>
                            <div class="input-group">
                                <input type="password" id="confirm_password" name="confirm_password" class="form-control" minlength="<?php echo MEMBER_PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password" required>
                                <?php $password_toggle_target = 'confirm_password'; require __DIR__ . '/../includes/password_toggle_button.php'; ?>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary fw-bold rounded-pill w-100">パスワードを変更する</button>
                    </form>
                <?php endif; ?>
            </div>
           </div>
          </div>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <button
            type="button"
            class="section-collapse-toggle entry-theme"
            data-bs-toggle="collapse"
            data-bs-target="#entryHistoryCollapse"
            aria-expanded="false"
            aria-controls="entryHistoryCollapse"
            aria-label="あなたの応募履歴を開閉"
            id="entry-history"
        >
            <span>あなたの応募履歴</span>
            <span class="section-collapse-icon" aria-hidden="true">▼</span>
        </button>
        <?php if ($is_diary_only_member): ?>
            <button type="button" class="btn btn-secondary" disabled>試合申し込みは利用できません</button>
        <?php else: ?>
            <a href="../front/club.php?tab=races" class="btn btn-entry-action">新しく試合に申し込む</a>
        <?php endif; ?>
    </div>

    <?php if ($is_diary_only_member): ?>
        <div class="alert alert-info py-2" role="alert">JAAF未登録のため、練習日記投稿のみ利用できます。</div>
    <?php endif; ?>

    <div class="collapse" id="entryHistoryCollapse">
        <div class="card card-clean">
            <div class="card-body p-0">
                <?php if (empty($matches)): ?>
                    <div class="p-4 text-secondary">現在、応募履歴はありません。</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover align-middle mb-0">
                            <thead class="table-dark">
                                <?php
                                
                                ?>
                                <tr>
                                    <th>申込日</th>
                                    <th>大会名</th>
                                    <th>応募種目</th>
                                    <th>変更締切</th>
                                    <th>状況</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($matches as $row): ?>
                                    <?php
                                    $days_left = isset($row['days_left']) ? (int)$row['days_left'] : null;
                                    $deadline_text = '未設定';
                                    $deadline_class = 'deadline-normal';
                                    if (!empty($row['deadline_date'])) {
                                        $deadline_text = date('Y/m/d', strtotime((string)$row['deadline_date']));
                                        if ($days_left !== null) {
                                            if ($days_left < 0) {
                                                $deadline_text .= '（変更締切を過ぎています）';
                                                $deadline_class = 'deadline-red';
                                            } else if ($days_left === 0) {
                                                $deadline_text .= '（本日締切）';
                                                $deadline_class = 'deadline-red';
                                            } else {
                                                $deadline_text .= '（あと' . $days_left . '日）';
                                                $deadline_class = $days_left <= 3 ? 'deadline-red' : 'deadline-normal';
                                            }
                                        }
                                    }
                                    $can_edit = $days_left !== null && $days_left >= 0;
                                    ?>
                                <tr>
                                    <td><?php echo !empty($row['created_at']) ? htmlspecialchars(date('Y/m/d', strtotime((string)$row['created_at'])), ENT_QUOTES, 'UTF-8') : '-'; ?></td>
                                    <td><?php echo htmlspecialchars((string)($row['match_title'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <div>1) <?php echo htmlspecialchars((string)($row['event_name1'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>（<?php echo htmlspecialchars((string)($row['pb_time1'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>）</div>
                                        <?php if (!empty($row['event_name2']) || !empty($row['pb_time2'])): ?>
                                            <div>2) <?php echo htmlspecialchars((string)($row['event_name2'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>（<?php echo htmlspecialchars((string)($row['pb_time2'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>）</div>
                                        <?php endif; ?>
                                        <?php if (!empty($row['event_name3']) || !empty($row['pb_time3'])): ?>
                                            <div>3) <?php echo htmlspecialchars((string)($row['event_name3'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>（<?php echo htmlspecialchars((string)($row['pb_time3'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>）</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="<?php echo $deadline_class; ?>"><?php echo htmlspecialchars($deadline_text, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <?php
                                        $status = trim((string)($row['status'] ?? '未承認'));
                                        if ($status === '承認済') {
                                            $status = '承認';
                                        } else if ($status === '閲覧済') {
                                            $status = '閲覧';
                                        } else if ($status === '') {
                                            $status = '未承認';
                                        }

                                        $badge = 'status-unapproved';
                                        if ($status === '承認') {
                                            $badge = 'status-approved';
                                        } else if ($status === '閲覧') {
                                            $badge = 'status-viewed';
                                        }
                                        ?>
                                        <span class="badge entry-status-badge <?php echo $badge; ?>"><?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></span>
                                    </td>
                                    <td>
                                        <?php if ($can_edit): ?>
                                            <a href="edit_entry.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-outline-primary">編集</a>
                                        <?php else: ?>
                                            <span class="text-secondary">締切後</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <button
            type="button"
            class="section-collapse-toggle gallery-theme"
            data-bs-toggle="collapse"
            data-bs-target="#galleryPostCollapse"
            aria-expanded="false"
            aria-controls="galleryPostCollapse"
            aria-label="ギャラリー投稿フォームを開閉"
            id="gallery-post"
        >
            <span>ギャラリーに投稿する</span>
            <span class="section-collapse-icon" aria-hidden="true">▼</span>
        </button>
        <a href="../front/gallery.php" class="btn btn-gallery-action btn-sm">ギャラリーを見る</a>
    </div>

    <?php
    $gallery_flash = (string)($_SESSION['gallery_flash'] ?? '');
    $gallery_error = (string)($_SESSION['gallery_error'] ?? '');
    if ($gallery_flash !== '' || $gallery_error !== '') {
        unset($_SESSION['gallery_flash'], $_SESSION['gallery_error']);
    }
    ?>

    <?php if ($gallery_flash !== ''): ?>
        <div class="alert alert-success py-2" role="alert"><?php echo htmlspecialchars($gallery_flash, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($gallery_error !== ''): ?>
        <div class="alert alert-danger py-2" role="alert"><?php echo htmlspecialchars($gallery_error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="collapse mb-4" id="galleryPostCollapse">
        <div class="card card-clean">
            <div class="card-body">
                <form method="POST" action="../back/save_gallery_post.php" enctype="multipart/form-data">
                <div class="row g-3 mb-2">
                    <div class="col-md-6">
                        <label class="form-label fw-bold" for="gallery_event_date">日付（イベント開催日）</label>
                        <input class="form-control" type="date" id="gallery_event_date" name="gallery_event_date">
                        <div class="form-text">指定しない場合は投稿日が使用されます</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold" for="gallery_title">タイトル</label>
                        <input class="form-control" type="text" id="gallery_title" name="gallery_title" maxlength="255" required placeholder="例: 合同練習会 / 大会レポート">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold" for="gallery_body">説明（詳細情報）</label>
                    <textarea class="form-control" id="gallery_body" name="gallery_body" rows="3" placeholder="例: 〇〇大学との合同練習でペース走を実施。天気も良く楽しい時間になりました！"></textarea>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold" for="gallery_image_1">写真1つ目</label>
                        <div class="file-pick" data-file-pick>
                            <input class="file-pick-input" type="file" id="gallery_image_1" name="gallery_image_1" accept="image/jpeg,image/png,image/webp">
                            <label class="file-pick-box" for="gallery_image_1"><span class="file-pick-plus" aria-hidden="true">＋</span><span data-file-pick-text data-empty-text="写真を追加" data-filled-text="写真を変更">写真を追加</span></label>
                        </div>
                        <div class="form-text">JPG/PNG/WEBP・40MB以内</div>
                        <div class="d-flex align-items-center justify-content-between mt-2 gap-2 file-pick-meta">
                            <small class="text-secondary" id="gallery_image_1_name">未選択</small>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-clear-target="gallery_image_1">選択を削除</button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold" for="gallery_image_2">写真2つ目</label>
                        <div class="file-pick" data-file-pick>
                            <input class="file-pick-input" type="file" id="gallery_image_2" name="gallery_image_2" accept="image/jpeg,image/png,image/webp">
                            <label class="file-pick-box" for="gallery_image_2"><span class="file-pick-plus" aria-hidden="true">＋</span><span data-file-pick-text data-empty-text="写真を追加" data-filled-text="写真を変更">写真を追加</span></label>
                        </div>
                        <div class="form-text">JPG/PNG/WEBP・40MB以内</div>
                        <div class="d-flex align-items-center justify-content-between mt-2 gap-2 file-pick-meta">
                            <small class="text-secondary" id="gallery_image_2_name">未選択</small>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-clear-target="gallery_image_2">選択を削除</button>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold" for="gallery_video_1">動画1つ目（15秒以内）</label>
                        <div class="file-pick" data-file-pick>
                            <input class="file-pick-input" type="file" id="gallery_video_1" name="gallery_video_1" accept="video/mp4,video/webm,video/quicktime">
                            <label class="file-pick-box" for="gallery_video_1"><span class="file-pick-plus" aria-hidden="true">＋</span><span data-file-pick-text data-empty-text="動画を追加" data-filled-text="動画を変更">動画を追加</span></label>
                        </div>
                        <div class="form-text">MP4/WEBM/MOV・60MB以内</div>
                        <div class="d-flex align-items-center justify-content-between mt-2 gap-2 file-pick-meta">
                            <small class="text-secondary" id="gallery_video_1_name">未選択</small>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-clear-target="gallery_video_1">選択を削除</button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold" for="gallery_video_2">動画2つ目（15秒以内）</label>
                        <div class="file-pick" data-file-pick>
                            <input class="file-pick-input" type="file" id="gallery_video_2" name="gallery_video_2" accept="video/mp4,video/webm,video/quicktime">
                            <label class="file-pick-box" for="gallery_video_2"><span class="file-pick-plus" aria-hidden="true">＋</span><span data-file-pick-text data-empty-text="動画を追加" data-filled-text="動画を変更">動画を追加</span></label>
                        </div>
                        <div class="form-text">MP4/WEBM/MOV・60MB以内</div>
                        <div class="d-flex align-items-center justify-content-between mt-2 gap-2 file-pick-meta">
                            <small class="text-secondary" id="gallery_video_2_name">未選択</small>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-clear-target="gallery_video_2">選択を削除</button>
                        </div>
                    </div>
                </div>

                    <button type="submit" class="btn btn-primary">投稿する</button>
                </form>
            </div>
        </div>
    </div>

    <?php // メニュー（仕様書のマイページのメニュー）。ヘッダーのハンバーガーメニューを無くしたので、下タブに無いページへはここから行く ?>
    <?php
    $mypage_menu_links = [
        ['next_pr.php', 'NEXT PR（自己ベスト・目標）'],
        ['../front/club.php?tab=races', '大会・エントリー'],
        ['../front/records.php', 'チーム歴代記録'],
        ['../front/gallery.php', 'ギャラリー'],
        ['../front/team_details.php', 'チーム詳細'],
        ['../front/contact.php', 'お問い合わせ'],
    ];
    ?>
    <?php if (!$is_admin): ?>
        <?php // 管理者はメニューの欄を出さない ?>
        <nav class="mypage-menu" aria-labelledby="mypageMenuTitle">
            <h2 class="mypage-menu-title" id="mypageMenuTitle">メニュー</h2>
            <ul class="mypage-menu-list">
                <?php foreach ($mypage_menu_links as [$mypage_menu_url, $mypage_menu_label]): ?>
                    <li><a href="<?php echo htmlspecialchars($mypage_menu_url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($mypage_menu_label, ENT_QUOTES, 'UTF-8'); ?><span aria-hidden="true">›</span></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>
    <?php endif; ?>

    <?php // ログアウト（全員。マイページのいちばん下） ?>
    <div class="mypage-logout">
        <a class="mypage-logout-btn" href="logout.php" onclick="return confirm('ログアウトしますか？');">ログアウト</a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    (function () {
        var recordEventOptions = <?php echo json_encode($record_event_options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        // 風速を入力する種目（back/training_diary_lib.php の TRAINING_DIARY_WIND_EVENTS と同じ）
        var recordWindEvents = <?php echo json_encode(TRAINING_DIARY_WIND_EVENTS, JSON_UNESCAPED_UNICODE); ?>;

        function syncRecordWindField(eventSelect) {
            var scope = eventSelect.closest('tr') || eventSelect.closest('.admin-record-form');
            var windSelect = scope ? scope.querySelector('select[data-role="record-wind"]') : null;
            if (!windSelect) {
                return;
            }
            var usesWind = recordWindEvents.indexOf(eventSelect.value) !== -1;
            windSelect.hidden = !usesWind;
            if (!usesWind) {
                windSelect.value = '';
            }
        }

        // グループ管理: 管理者がグループ名を変えるときは、変更前と変更後を見せて本当に変えるか確認する
        document.querySelectorAll('form[data-admin-group-rename]').forEach(function (renameForm) {
            renameForm.addEventListener('submit', function (event) {
                var oldName = renameForm.getAttribute('data-old-name');
                var newName = renameForm.querySelector('input[name="group_name"]').value.trim();
                if (newName === '' || newName === oldName) {
                    event.preventDefault();
                    alert(newName === '' ? 'グループ名を入力してください。' : 'グループ名が変わっていません。');
                    return;
                }
                if (!confirm('グループ名を「' + oldName + '」から「' + newName + '」に変更しますか？\nグループのメンバー全員の画面で名前が変わります。本当によろしいですか？')) {
                    event.preventDefault();
                }
            });
        });

        // お知らせのボタン: 「ボタンあり」のときだけ「ボタンの文字」と「行き先」を出す
        document.querySelectorAll('select[data-announcement-link]').forEach(function (linkSelect) {
            var fields = linkSelect.parentElement.querySelector('[data-announcement-link-fields]');
            if (!fields) {
                return;
            }
            var urlInput = fields.querySelector('input[name="link_url"]');
            linkSelect.addEventListener('change', function () {
                var enabled = linkSelect.value === '1';
                fields.hidden = !enabled;
                urlInput.required = enabled;
                if (enabled) {
                    fields.querySelector('input[name="link_label"]').focus();
                }
            });
            urlInput.required = linkSelect.value === '1';
        });

        var recordEventSelects = document.querySelectorAll('select[name="event_type"][data-role="event-select"]');
        var recordRadios = document.querySelectorAll('input[name="record_gender"], input[name="record_discipline"]');

        if (!recordEventSelects.length) {
            return;
        }

        function getRecordScope(element) {
            return element.closest('tr') || element.closest('.admin-record-form') || document;
        }

        function buildKey(scope) {
            var genderInput = scope.querySelector('input[name="record_gender"]:checked');
            var disciplineInput = scope.querySelector('input[name="record_discipline"]:checked');
            var gender = genderInput ? genderInput.value : 'male';
            var discipline = disciplineInput ? disciplineInput.value : 'track';
            return gender + '_' + discipline;
        }

        function populateEventSelect(select, resetSelected) {
            var scope = getRecordScope(select);
            var selected = resetSelected ? '' : (select.getAttribute('data-selected-event') || select.value || '');
            var key = buildKey(scope);
            var items = recordEventOptions[key] || [];

            select.innerHTML = '';
            items.forEach(function (label) {
                var option = document.createElement('option');
                option.value = label;
                option.textContent = label;
                if (selected !== '' && selected === label) {
                    option.selected = true;
                }
                select.appendChild(option);
            });

            if (!select.value && items.length > 0) {
                select.value = items[0];
            }
            select.setAttribute('data-selected-event', select.value || '');
            syncRecordWindField(select);
        }

        recordEventSelects.forEach(function (select) {
            populateEventSelect(select, false);
            select.addEventListener('change', function () {
                syncRecordWindField(select);
            });
        });

        recordRadios.forEach(function (radio) {
            radio.addEventListener('change', function () {
                var scope = getRecordScope(radio);
                var selectsInScope = scope.querySelectorAll('select[name="event_type"][data-role="event-select"]');
                selectsInScope.forEach(function (select) {
                    populateEventSelect(select, true);
                });
            });
        });
    })();

    (function () {
        var scopes = document.querySelectorAll('[data-tournament-deadline-scope]');

        function computeDisplayDeadline(baseDateStr, leadDays) {
            if (!baseDateStr) {
                return '';
            }
            var parsed = new Date(baseDateStr + 'T00:00:00');
            if (isNaN(parsed.getTime())) {
                return '';
            }
            parsed.setDate(parsed.getDate() - leadDays);
            var y = parsed.getFullYear();
            var m = String(parsed.getMonth() + 1).padStart(2, '0');
            var d = String(parsed.getDate()).padStart(2, '0');
            return y + '/' + m + '/' + d;
        }

        scopes.forEach(function (scope) {
            var eventDateInput = scope.querySelector('input[name="event_date"]');
            var entryDeadlineInput = scope.querySelector('input[name="entry_deadline"]');
            var leadDaysSelect = scope.querySelector('[data-role="deadline-lead-days"]');
            var output = scope.querySelector('[data-role="display-deadline"]');

            if (!eventDateInput || !output) {
                return;
            }

            function update() {
                var base = (entryDeadlineInput && entryDeadlineInput.value) ? entryDeadlineInput.value : eventDateInput.value;
                var leadDays = leadDaysSelect ? parseInt(leadDaysSelect.value, 10) : 3;
                if (isNaN(leadDays)) {
                    leadDays = 3;
                }
                var result = computeDisplayDeadline(base, leadDays);
                output.textContent = result || '—';
            }

            eventDateInput.addEventListener('input', update);
            if (entryDeadlineInput) {
                entryDeadlineInput.addEventListener('input', update);
            }
            if (leadDaysSelect) {
                leadDaysSelect.addEventListener('change', update);
            }

            update();
        });
    })();

    (function () {
        var toggleRows = document.querySelectorAll('.admin-entry-table tbody tr.entry-toggle-row');

        toggleRows.forEach(function (row) {
            var detailRow = row.nextElementSibling;
            if (!detailRow || !detailRow.classList.contains('entry-detail-row')) {
                return;
            }

            function toggle() {
                var isOpen = row.getAttribute('aria-expanded') === 'true';
                row.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
                detailRow.classList.toggle('d-none', isOpen);
            }

            row.addEventListener('click', function (event) {
                if (event.target.closest('select, button, form, a, input')) {
                    return;
                }
                toggle();
            });

            row.addEventListener('keydown', function (event) {
                if (event.target.closest('select, button, form, a, input')) {
                    return;
                }
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    toggle();
                }
            });
        });
    })();

    // 練習日記の登録シートの動き（写真の選択・種目入力・投稿前のチェック）は js/diary_post_sheet.js（全ページ共通）

    (function () {
        var galleryImageIds = ['gallery_image_1', 'gallery_image_2'];
        var galleryVideoIds = ['gallery_video_1', 'gallery_video_2'];
        var allGalleryIds = galleryImageIds.concat(galleryVideoIds);
        var clearButtons = document.querySelectorAll('[data-clear-target]');

        function updateGalleryFileName(inputId) {
            var input = document.getElementById(inputId);
            var displayNode = document.getElementById(inputId + '_name');
            if (!input || !displayNode) {
                return;
            }
            var file = input.files && input.files[0] ? input.files[0] : null;
            displayNode.textContent = file ? file.name : '未選択';
        }

        function clearGalleryInput(inputId) {
            var input = document.getElementById(inputId);
            var displayNode = document.getElementById(inputId + '_name');
            if (!input) {
                return;
            }
            input.value = '';
            if (displayNode) {
                displayNode.textContent = '未選択';
            }
        }

        allGalleryIds.forEach(function (inputId) {
            var input = document.getElementById(inputId);
            if (!input) {
                return;
            }

            input.addEventListener('change', function () {
                updateGalleryFileName(inputId);

                if (galleryVideoIds.indexOf(inputId) === -1) {
                    return;
                }

                var file = input.files && input.files[0] ? input.files[0] : null;
                if (!file) {
                    return;
                }

                if (file.type.indexOf('video/') !== 0) {
                    return;
                }

                var url = URL.createObjectURL(file);
                var video = document.createElement('video');
                video.preload = 'metadata';
                video.onloadedmetadata = function () {
                    URL.revokeObjectURL(url);
                    // 案内は「15秒以内」だが、少しはみ出した動画も投稿できるよう、実際は20秒まで受け付ける
                    if (video.duration > 20) {
                        alert('動画は15秒以内にしてください。');
                        clearGalleryInput(inputId);
                    }
                };
                video.src = url;
            });
        });

        clearButtons.forEach(function (button) {
            var targetId = button.getAttribute('data-clear-target');
            if (allGalleryIds.indexOf(targetId) === -1) {
                return;
            }
            button.addEventListener('click', function () {
                clearGalleryInput(targetId);
            });
        });
    })();

    (function () {
        var trainingDiaryData = <?php echo json_encode($my_diaries, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var currentUserId = <?php echo (int)$user_id; ?>;
        // 公開範囲の変更で選べるグループ（投稿フォームと同じ）と、チップのアイコン
        var scopeGroups = <?php echo json_encode(array_map(static fn(array $group): array => ['id' => (int)$group['id'], 'name' => (string)$group['name']], $diary_post_groups), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var scopeIcons = <?php echo json_encode(['globe' => $scope_icon_globe ?? '', 'lock' => $scope_icon_lock ?? '', 'group' => $scope_icon_group ?? ''], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        var calendarGrid = document.getElementById('diaryCalendarGrid');
        var calendarLabel = document.getElementById('diaryCalendarLabel');
        var prevBtn = document.getElementById('diaryCalendarPrev');
        var nextBtn = document.getElementById('diaryCalendarNext');
        var detailModalEl = document.getElementById('diaryDetailModal');
        var detailBody = document.getElementById('diaryDetailBody');
        var detailDateTitle = document.getElementById('diaryDetailDate');

        if (!calendarGrid || !detailModalEl) {
            return;
        }

        var intensityLabels = {
            point: 'ポイント',
            race: '大会',
            normal: '通常',
            light: '落とし',
            weight: 'ウエイト'
        };

        var diaryById = {};
        var diaryByDate = {};
        trainingDiaryData.forEach(function (diary) {
            diaryById[String(diary.id)] = diary;
            if (!diaryByDate[diary.practice_date]) {
                diaryByDate[diary.practice_date] = [];
            }
            diaryByDate[diary.practice_date].push(diary);
        });

        var today = new Date();
        var viewYear = today.getFullYear();
        var viewMonth = today.getMonth();

        function pad2(value) {
            return String(value).padStart(2, '0');
        }

        function formatDateKey(year, month, day) {
            return year + '-' + pad2(month + 1) + '-' + pad2(day);
        }

        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text === null || text === undefined ? '' : String(text);
            return div.innerHTML;
        }

        function formatDateTime(value) {
            if (!value) {
                return '';
            }
            var date = new Date(String(value).replace(' ', 'T'));
            if (isNaN(date.getTime())) {
                return String(value);
            }
            return date.getFullYear() + '/' + pad2(date.getMonth() + 1) + '/' + pad2(date.getDate()) + ' ' + pad2(date.getHours()) + ':' + pad2(date.getMinutes());
        }

        function formatDateOnly(value) {
            if (!value) {
                return '';
            }
            var date = new Date(String(value) + 'T00:00:00');
            if (isNaN(date.getTime())) {
                return String(value);
            }
            return date.getFullYear() + '/' + pad2(date.getMonth() + 1) + '/' + pad2(date.getDate());
        }

        function buildReturnUrl(diaryId) {
            return window.location.pathname + '?open_diary=' + diaryId + '#training-diary';
        }

        // 強度をカレンダーの5色にまとめる（1日に何件かあるときは、この順で後ろのものの色にする）
        var calendarLevels = { light: 'light', normal: 'normal', weight: 'weight', point: 'high', race: 'race' };
        var calendarLevelOrder = ['light', 'normal', 'weight', 'high', 'race'];
        var calendarLevelLabels = { light: '軽め', normal: '通常', weight: '筋トレ', high: '高め', race: '大会' };

        function dayLevel(items) {
            var best = -1;
            items.forEach(function (diary) {
                var rank = calendarLevelOrder.indexOf(calendarLevels[diary.intensity] || 'normal');
                if (rank > best) {
                    best = rank;
                }
            });
            return calendarLevelOrder[best];
        }

        function isCurrentMonth() {
            return viewYear === today.getFullYear() && viewMonth === today.getMonth();
        }

        function renderCalendar() {
            calendarLabel.textContent = viewYear + '.' + (viewMonth + 1);
            calendarGrid.innerHTML = '';
            // 先の月には日記がないので、今月より先へは進めない
            nextBtn.disabled = isCurrentMonth();

            var firstDay = new Date(viewYear, viewMonth, 1);
            var startWeekday = firstDay.getDay();
            var daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();
            var todayKey = formatDateKey(today.getFullYear(), today.getMonth(), today.getDate());

            for (var i = 0; i < startWeekday; i++) {
                var blank = document.createElement('span');
                blank.className = 'mp-cal-cell is-blank';
                calendarGrid.appendChild(blank);
            }

            for (var day = 1; day <= daysInMonth; day++) {
                var dateKey = formatDateKey(viewYear, viewMonth, day);
                var items = diaryByDate[dateKey] || [];
                var classes = ['mp-cal-cell'];
                if (dateKey === todayKey) {
                    classes.push('is-today');
                } else if (dateKey > todayKey) {
                    classes.push('is-future');
                }

                var cell;
                if (items.length > 0) {
                    var level = dayLevel(items);
                    classes.push('lv-' + level);
                    cell = document.createElement('button');
                    cell.type = 'button';
                    cell.setAttribute('aria-label', (viewMonth + 1) + '月' + day + '日 ' + calendarLevelLabels[level] + ' 日記' + items.length + '件');
                    cell.addEventListener('click', (function (key, dayItems) {
                        return function () {
                            if (dayItems.length === 1) {
                                openDiaryDetail(dayItems[0].id);
                            } else {
                                openDayList(key, dayItems);
                            }
                        };
                    })(dateKey, items));
                    if (items.length > 1) {
                        var badge = document.createElement('span');
                        badge.className = 'mp-cal-badge';
                        badge.textContent = String(items.length);
                        cell.appendChild(badge);
                    }
                } else {
                    cell = document.createElement('span');
                }
                cell.className = classes.join(' ');
                var num = document.createElement('span');
                num.className = 'mp-cal-num';
                num.textContent = String(day);
                cell.insertBefore(num, cell.firstChild);
                calendarGrid.appendChild(cell);
            }
        }

        // 1日に2件以上の日記がある日: その日の日記を並べ、押したものの詳細を開く
        function openDayList(dateKey, items) {
            detailDateTitle.textContent = formatDateOnly(dateKey) + 'の練習';
            detailBody.innerHTML = '<div class="mp-day-list">' + items.map(function (diary) {
                var intensityKey = diary.intensity || 'normal';
                return '<button type="button" class="mp-day-item" data-diary-id="' + diary.id + '">' +
                    '<span class="diary-detail-intensity intensity-' + intensityKey + '">' + escapeHtml(intensityLabels[intensityKey] || intensityKey) + '</span>' +
                    '<span class="mp-day-item-menu">' + escapeHtml(diary.menu_text) + '</span>' +
                    '<span aria-hidden="true">›</span></button>';
            }).join('') + '</div>';
            detailBody.querySelectorAll('[data-diary-id]').forEach(function (button) {
                button.addEventListener('click', function () {
                    openDiaryDetail(button.getAttribute('data-diary-id'));
                });
            });
            bootstrap.Modal.getOrCreateInstance(detailModalEl).show();
        }

        function buildCommentsHtml(diary) {
            if (!diary.comments || diary.comments.length === 0) {
                return '<div class="text-secondary small">まだコメントはありません。</div>';
            }
            return diary.comments.map(function (comment) {
                var isOwn = String(comment.user_id) === String(currentUserId);
                var bodyHtml = escapeHtml(comment.comment_text).replace(/\n/g, '<br>');
                // 自分のコメントには、名前の行の右端に削除ボタンを置く
                var deleteHtml = '';
                if (isOwn) {
                    deleteHtml = '<form method="POST" action="../back/delete_training_diary_comment.php" class="m-0" onsubmit="return confirm(\'自分のコメントを削除しますか？\');">' +
                        '<input type="hidden" name="diary_id" value="' + diary.id + '">' +
                        '<input type="hidden" name="comment_id" value="' + comment.id + '">' +
                        '<input type="hidden" name="return_url" value="' + escapeHtml(buildReturnUrl(diary.id)) + '">' +
                        '<button type="submit" class="comment-delete-btn" aria-label="自分のコメントを削除">削除</button>' +
                        '</form>';
                }
                var metaHtml = '<div class="diary-detail-comment-meta"><span>' + escapeHtml(comment.user_name) + ' / ' + escapeHtml(formatDateTime(comment.created_at)) + '</span>' + deleteHtml + '</div>';
                return '<div class="diary-detail-comment-item">' + metaHtml + '<div>' + bodyHtml + '</div></div>';
            }).join('');
        }

        function buildWeightSetsHtml(weightSets) {
            if (!weightSets || weightSets.length === 0) {
                return '<div class="text-secondary small mb-2">ウエイト種目の記録がありません。</div>';
            }

            var rowsHtml = weightSets.map(function (set) {
                var loadLabel;
                if (set.is_bodyweight) {
                    loadLabel = '自重';
                } else if (set.weight_kg !== null && set.weight_kg !== undefined) {
                    loadLabel = set.weight_kg + 'kg';
                } else {
                    loadLabel = set.weight_note || '-';
                }
                return '<tr>' +
                    '<td>' + escapeHtml(set.body_part) + '</td>' +
                    '<td>' + escapeHtml(set.exercise_name) + '</td>' +
                    '<td>' + (set.reps !== null && set.reps !== undefined ? escapeHtml(set.reps) + '回' : '-') + '</td>' +
                    '<td>' + (set.sets !== null && set.sets !== undefined ? escapeHtml(set.sets) + 'セット' : '-') + '</td>' +
                    '<td>' + escapeHtml(loadLabel) + '</td>' +
                    '</tr>';
            }).join('');

            return '<div class="table-responsive mb-2"><table class="table table-sm diary-weight-sets-table mb-0">' +
                '<thead><tr><th>部位</th><th>種目</th><th>回数</th><th>セット</th><th>重量</th></tr></thead>' +
                '<tbody>' + rowsHtml + '</tbody></table></div>';
        }

        function buildRaceEntriesHtml(raceEntries) {
            if (!raceEntries || raceEntries.length === 0) {
                return '<div class="text-secondary small mb-2">出場種目の記録がありません。</div>';
            }

            var rowsHtml = raceEntries.map(function (entry) {
                return '<tr>' +
                    '<td>' + escapeHtml(entry.event_name) + '</td>' +
                    '<td>' + escapeHtml(entry.result_label) + '</td>' +
                    '<td>' + escapeHtml(entry.notes_label || '') + '</td>' +
                    '</tr>';
            }).join('');

            return '<div class="table-responsive mb-2"><table class="table table-sm diary-weight-sets-table mb-0">' +
                '<thead><tr><th>種目</th><th>タイム・記録</th><th>風・公認</th></tr></thead>' +
                '<tbody>' + rowsHtml + '</tbody></table></div>';
        }

        function buildRunningSetsHtml(runningSets) {
            if (!runningSets || runningSets.length === 0) {
                return '<div class="text-secondary small mb-2">ランニング種目の記録がありません。</div>';
            }

            return runningSets.map(function (set) {
                var headerText = set.workout_type + ' ／ ' + set.distance_label + ' × ' + set.reps + '本' +
                    (set.sets > 1 ? ' × ' + set.sets + 'set' : '') +
                    (set.target_time_label ? ' ／ 設定 ' + set.target_time_label : '');

                var lapsHtml = '';
                if (set.laps && set.laps.length > 0) {
                    lapsHtml = '<div class="running-set-laps">' + set.laps.map(function (lap) {
                        var label = lap.lap_time_seconds !== null && lap.lap_time_seconds !== undefined
                            ? lap.lap_time_seconds + '秒'
                            : (lap.lap_time_label || '-');
                        return '<span class="running-lap-chip">' + lap.lap_number + '本目: ' + escapeHtml(label) + '</span>';
                    }).join('') + '</div>';
                }

                return '<div class="running-set-block mb-2">' +
                    '<div class="running-set-header">' + escapeHtml(headerText) + '</div>' +
                    lapsHtml +
                    '</div>';
            }).join('');
        }

        // 投稿後に公開範囲を変えるフォーム（投稿フォームと同じチップ。いまの公開範囲を選んだ状態で開く）
        function buildScopeEditForm(diary) {
            var currentGroupIds = (diary.group_ids || []).map(String);
            var html = '<form method="POST" action="../back/update_training_diary_scope.php" class="diary-scope-edit" data-scope-edit hidden>';
            html += '<input type="hidden" name="diary_id" value="' + diary.id + '">';
            html += '<input type="hidden" name="is_public" value="0">';
            html += '<div class="diary-scope-chips">';
            html += '<input type="checkbox" class="btn-check" id="diaryScopeEditPublic" name="is_public" value="1" autocomplete="off"' + (diary.is_public ? ' checked' : '') + '>';
            html += '<label class="diary-scope-chip" for="diaryScopeEditPublic">' + scopeIcons.globe + 'みんなの練習日記</label>';
            html += '<span class="diary-scope-chip is-fixed" aria-label="自分のカレンダーのみ（常に選択）">' + scopeIcons.lock + '自分のカレンダーのみ</span>';
            scopeGroups.forEach(function (group) {
                var inputId = 'diaryScopeEditGroup' + group.id;
                var checked = currentGroupIds.indexOf(String(group.id)) !== -1;
                html += '<input type="checkbox" class="btn-check" id="' + inputId + '" name="group_ids[]" value="' + group.id + '" autocomplete="off"' + (checked ? ' checked' : '') + '>';
                html += '<label class="diary-scope-chip" for="' + inputId + '">' + scopeIcons.group + escapeHtml(group.name) + '</label>';
            });
            html += '</div>';
            html += '<p class="diary-scope-note">選んだ範囲すべてに表示されます。自分のカレンダーには必ず残ります。</p>';
            html += '<div class="diary-scope-edit-actions">';
            html += '<button type="button" class="btn btn-sm btn-outline-secondary" data-scope-edit-cancel>やめる</button>';
            html += '<button type="submit" class="btn btn-sm btn-primary">この範囲で保存</button>';
            html += '</div>';
            html += '</form>';
            return html;
        }

        function openDiaryDetail(diaryId) {
            var diary = diaryById[String(diaryId)];
            if (!diary) {
                return;
            }

            detailDateTitle.textContent = formatDateOnly(diary.practice_date) + 'の練習';

            var mediaHtml = '';
            if (diary.image_path) {
                var imageSrc = '../' + escapeHtml(diary.image_path);
                mediaHtml += '<div class="diary-detail-media"><a href="' + imageSrc + '" target="_blank" rel="noopener noreferrer"><img src="' + imageSrc + '" alt="練習写真"></a></div>';
            }
            if (diary.video_path) {
                var videoSrc = '../' + escapeHtml(diary.video_path);
                mediaHtml += '<div class="diary-detail-media"><video src="' + videoSrc + '" autoplay muted playsinline loop controls></video></div>';
            }

            var returnUrl = escapeHtml(buildReturnUrl(diary.id));
            var updatedHtml = '';
            if (diary.updated_at && diary.updated_at !== diary.created_at) {
                updatedHtml = '<br>更新: ' + escapeHtml(formatDateTime(diary.updated_at));
            }

            var html = '';
            // 公開範囲: みんなの練習日記・グループ（名前）・自分のカレンダー（自分のカレンダーには必ず載る）
            var scopeParts = [];
            if (diary.is_public) {
                scopeParts.push('みんなの練習日記');
            }
            if (diary.group_names) {
                scopeParts.push('グループ「' + diary.group_names + '」');
            }
            var scopeText = scopeParts.length > 0 ? '公開範囲：' + scopeParts.join('・') + '・自分のカレンダー' : '非公開（自分のカレンダーのみ）';
            html += '<div class="diary-detail-meta">投稿: ' + escapeHtml(formatDateTime(diary.created_at)) + updatedHtml + ' / ' + escapeHtml(scopeText) +
                ' <button type="button" class="diary-scope-edit-toggle" data-scope-edit-toggle aria-expanded="false">公開範囲を変更</button></div>';
            html += buildScopeEditForm(diary);
            var intensityKey = diary.intensity || 'normal';
            html += '<div class="diary-detail-menu">' + escapeHtml(diary.menu_text) + '</div>';
            html += '<span class="diary-detail-intensity intensity-' + intensityKey + '">' + escapeHtml(intensityLabels[intensityKey] || intensityKey) + '</span>';
            if (diary.training_type === 'weight') {
                html += buildWeightSetsHtml(diary.weight_sets);
            } else if (diary.training_type === 'race') {
                html += buildRaceEntriesHtml(diary.race_entries);
            } else if (diary.training_type === 'jog') {
                html += '<div class="diary-detail-time">ジョグ ' + escapeHtml(diary.jog_text || '-') + '</div>';
            } else if (diary.running_sets && diary.running_sets.length > 0) {
                html += buildRunningSetsHtml(diary.running_sets);
            } else {
                html += '<div class="diary-detail-time">' + escapeHtml(diary.time_text).replace(/\n/g, '<br>') + '</div>';
            }
            if (diary.comment_text) {
                html += '<div class="diary-detail-comment">' + escapeHtml(diary.comment_text).replace(/\n/g, '<br>') + '</div>';
            }
            html += mediaHtml;

            html += '<div class="diary-detail-actions">';
            html += '<form method="POST" action="../back/toggle_training_diary_like.php" class="m-0 d-inline">';
            html += '<input type="hidden" name="diary_id" value="' + diary.id + '">';
            html += '<input type="hidden" name="return_url" value="' + returnUrl + '">';
            html += '<button type="submit" class="like-toggle-btn' + (diary.liked_by_me ? ' active' : '') + '">👍 <span class="like-count">' + diary.like_count + '</span></button>';
            html += '</form>';
            html += '<span class="text-secondary small">コメント ' + diary.comment_count + '件</span>';
            html += '<form method="POST" action="../back/delete_training_diary.php" class="m-0 ms-auto" onsubmit="return confirm(\'この練習日記を削除します。よろしいですか？\');">';
            html += '<input type="hidden" name="diary_id" value="' + diary.id + '">';
            html += '<button type="submit" class="btn btn-sm btn-outline-danger">この投稿を削除</button>';
            html += '</form>';
            html += '</div>';

            html += '<div class="diary-detail-comment-list">' + buildCommentsHtml(diary) + '</div>';

            html += '<form method="POST" action="../back/save_training_diary_comment.php" class="comment-form" onsubmit="return window.validateDiaryCommentForm(this);">';
            html += '<input type="hidden" name="diary_id" value="' + diary.id + '">';
            html += '<input type="hidden" name="return_url" value="' + returnUrl + '">';
            html += '<textarea name="comment_text" class="form-control mb-2" rows="2" maxlength="500" placeholder="コメントを追加"></textarea>';
            html += '<button type="submit" class="btn btn-primary btn-sm">送信</button>';
            html += '</form>';

            detailBody.innerHTML = html;

            var scopeToggle = detailBody.querySelector('[data-scope-edit-toggle]');
            var scopeForm = detailBody.querySelector('[data-scope-edit]');
            if (scopeToggle && scopeForm) {
                scopeToggle.addEventListener('click', function () {
                    scopeForm.hidden = !scopeForm.hidden;
                    scopeToggle.setAttribute('aria-expanded', scopeForm.hidden ? 'false' : 'true');
                });
                scopeForm.querySelector('[data-scope-edit-cancel]').addEventListener('click', function () {
                    scopeForm.reset();
                    scopeForm.hidden = true;
                    scopeToggle.setAttribute('aria-expanded', 'false');
                });
            }

            bootstrap.Modal.getOrCreateInstance(detailModalEl).show();
        }

        window.validateDiaryCommentForm = function (form) {
            var textarea = form.querySelector('textarea[name="comment_text"]');
            if (!textarea || textarea.value.trim() === '') {
                alert('コメントを入力してください。');
                if (textarea) {
                    textarea.focus();
                }
                return false;
            }
            return true;
        };

        prevBtn.addEventListener('click', function () {
            viewMonth -= 1;
            if (viewMonth < 0) {
                viewMonth = 11;
                viewYear -= 1;
            }
            renderCalendar();
        });

        nextBtn.addEventListener('click', function () {
            if (isCurrentMonth()) {
                return;
            }
            viewMonth += 1;
            if (viewMonth > 11) {
                viewMonth = 0;
                viewYear += 1;
            }
            renderCalendar();
        });

        renderCalendar();

        var params = new URLSearchParams(window.location.search);
        var openDiaryId = params.get('open_diary');
        if (openDiaryId && diaryById[openDiaryId]) {
            var targetDiary = diaryById[openDiaryId];
            var practiceDate = new Date(targetDiary.practice_date + 'T00:00:00');
            if (!isNaN(practiceDate.getTime())) {
                viewYear = practiceDate.getFullYear();
                viewMonth = practiceDate.getMonth();
                renderCalendar();
            }
            // ベルの「返信する」から来たときは、詳細が開ききったらコメント入力欄にカーソルを置く
            if (params.get('reply') === '1') {
                detailModalEl.addEventListener('shown.bs.modal', function () {
                    var replyBox = detailBody.querySelector('textarea[name="comment_text"]');
                    if (replyBox) {
                        replyBox.scrollIntoView({ block: 'center' });
                        replyBox.focus();
                    }
                }, { once: true });
            }
            openDiaryDetail(openDiaryId);
            window.history.replaceState(null, '', window.location.pathname + '#training-diary');
        }

        var postDiaryParams = new URLSearchParams(window.location.search);
        if (postDiaryParams.get('post_diary') === '1') {
            var diaryPostModalEl = document.getElementById('diaryPostModal');
            if (diaryPostModalEl && typeof bootstrap !== 'undefined') {
                bootstrap.Modal.getOrCreateInstance(diaryPostModalEl).show();
            }
            window.history.replaceState(null, '', window.location.pathname + '#training-diary');
        }
    })();

    (function () {
        var checkbox = document.getElementById('memberSelfManaged');
        var emailWrap = document.getElementById('memberOwnerEmailWrap');
        var emailInput = document.getElementById('memberOwnerEmail');
        var addForm = document.getElementById('memberTournamentAddForm');
        if (!checkbox || !emailWrap || !emailInput) {
            return;
        }

        function syncEmailField() {
            emailWrap.hidden = !checkbox.checked;
            emailInput.required = checkbox.checked;
        }

        checkbox.addEventListener('change', syncEmailField);
        syncEmailField();

        if (addForm) {
            addForm.addEventListener('submit', function (event) {
                var eventNameInput = addForm.querySelector('input[name="event_name"]');
                var eventNameValue = eventNameInput ? eventNameInput.value.trim() : '';
                var confirmMessage = checkbox.checked
                    ? '「' + eventNameValue + '」を追加します。この大会の申し込み管理はあなたが行います。よろしいですか？'
                    : '「' + eventNameValue + '」を追加します。この大会の申し込み管理は管理者が行います。よろしいですか？';
                if (!window.confirm(confirmMessage)) {
                    event.preventDefault();
                }
            });
        }
    })();

    (function () {
        var filterRadios = document.querySelectorAll('#admin-member-jaaf-filter input[name="member_jaaf_filter"]');
        var table = document.getElementById('admin-member-table');
        if (!filterRadios.length || !table) {
            return;
        }

        function applyMemberJaafFilter() {
            var selected = document.querySelector('#admin-member-jaaf-filter input[name="member_jaaf_filter"]:checked');
            var mode = selected ? selected.value : 'all';
            var rows = table.querySelectorAll('tbody tr[data-jaaf-registered]');
            rows.forEach(function (row) {
                var isRegistered = row.getAttribute('data-jaaf-registered') === '1';
                var visible = mode === 'all'
                    || (mode === 'registered' && isRegistered)
                    || (mode === 'unregistered' && !isRegistered);
                row.hidden = !visible;
            });
        }

        filterRadios.forEach(function (radio) {
            radio.addEventListener('change', applyMemberJaafFilter);
        });
        applyMemberJaafFilter();
    })();

    (function () {
        var toggle = document.getElementById('adminFeeColumnToggle');
        var table = document.getElementById('admin-member-table');
        if (!toggle || !table) {
            return;
        }
        // 保存するとページが再読み込みされるので、開いた状態をタブを閉じるまで覚えておく
        var storageKey = 'tobgAdminFeeColumnOpen';

        function setFeeColumnOpen(isOpen) {
            table.classList.toggle('is-fee-hidden', !isOpen);
            toggle.setAttribute('aria-pressed', isOpen ? 'true' : 'false');
            toggle.textContent = isOpen ? '年会費を閉じる' : '年会費を編集';
            try {
                sessionStorage.setItem(storageKey, isOpen ? '1' : '0');
            } catch (e) {
                // 保存できない環境では毎回閉じた状態から始める
            }
        }

        var initialOpen = false;
        try {
            initialOpen = sessionStorage.getItem(storageKey) === '1';
        } catch (e) {
            initialOpen = false;
        }
        setFeeColumnOpen(initialOpen);

        toggle.addEventListener('click', function () {
            setFeeColumnOpen(table.classList.contains('is-fee-hidden'));
        });
    })();

    (function () {
        var select = document.getElementById('adminRecordEventFilterSelect');
        var table = document.getElementById('admin-record-table');
        if (!select || !table) {
            return;
        }

        function applyRecordEventFilter() {
            var selected = select.value || 'all';
            var rows = table.querySelectorAll('tbody tr[data-event-type]');
            rows.forEach(function (row) {
                var visible = selected === 'all' || row.getAttribute('data-event-type') === selected;
                row.hidden = !visible;
            });
        }

        select.addEventListener('change', applyRecordEventFilter);
        applyRecordEventFilter();
    })();
</script>
<?php require __DIR__ . '/../includes/password_toggle_script.php'; ?>
<script>
    (function () {
        var input = document.getElementById('profileIconInput');
        if (!input) {
            return;
        }
        var preview = document.getElementById('profileIconPreview');
        var fallback = document.getElementById('profileIconFallback');
        var submitBtn = document.getElementById('profileIconSubmit');
        var chooseBtn = document.getElementById('profileIconChooseBtn');
        var filename = document.getElementById('profileIconFilename');

        // 画像を選んだらアイコンをプレビューし、「この画像で更新」ボタンを出す
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            var hasFile = !!file;
            submitBtn.hidden = !hasFile;
            chooseBtn.textContent = hasFile ? '🖼 選び直す' : '🖼 画像を選ぶ';
            filename.hidden = !hasFile;
            filename.textContent = hasFile ? file.name : '';
            if (hasFile && preview) {
                preview.src = URL.createObjectURL(file);
                preview.hidden = false;
                if (fallback) {
                    fallback.hidden = true;
                }
            }
        });
    })();

    (function () {
        var view = document.getElementById('memberEmailView');
        var form = document.getElementById('memberEmailForm');
        if (!view || !form) {
            return;
        }
        // 登録済みのメールアドレスは表示のみ。「変更」を押したときだけ入力欄を出す
        var setEditing = function (editing) {
            view.hidden = editing;
            form.hidden = !editing;
            if (editing) {
                form.querySelector('input[name="email"]').focus();
            }
        };
        document.querySelector('[data-member-email-edit]').addEventListener('click', function () {
            setEditing(true);
        });
        document.querySelector('[data-member-email-cancel]').addEventListener('click', function () {
            form.reset();
            setEditing(false);
        });
    })();
</script>
<script>
    (function () {
        var modalEl = document.getElementById('passwordChangeModal');
        if (!modalEl || typeof bootstrap === 'undefined') {
            return;
        }
        // パスワード変更でエラーになって戻ってきたときは、モーダルを開いた状態にする
        if (modalEl.getAttribute('data-auto-open') === '1' || location.hash === '#password-change') {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    })();

    // 管理者のカレンダーの開閉: ボタンの文字を合わせ、閉じたかどうかをこのブラウザに覚えておく（次に開いたときも同じ状態）
    (function () {
        var collapseEl = document.getElementById('diaryCalendarCollapse');
        var toggle = document.querySelector('[data-bs-target="#diaryCalendarCollapse"]');
        if (!collapseEl || !toggle) {
            return;
        }
        var STORAGE_KEY = 'tobg.mypage.calendarFolded';
        var text = toggle.querySelector('[data-fold-text]');
        var icon = toggle.querySelector('.calendar-fold-icon');

        function render(isOpen) {
            text.textContent = isOpen ? '閉じる' : '開く';
            icon.textContent = isOpen ? '▲' : '▼';
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }
        function remember(isFolded) {
            try {
                if (isFolded) {
                    localStorage.setItem(STORAGE_KEY, '1');
                } else {
                    localStorage.removeItem(STORAGE_KEY);
                }
            } catch (e) {
                // 保存できないブラウザ（プライベートモードなど）では、毎回開いた状態で始める
            }
        }

        var folded = false;
        try {
            folded = localStorage.getItem(STORAGE_KEY) === '1';
        } catch (e) {
            folded = false;
        }
        if (folded) {
            collapseEl.classList.remove('show'); // 読み込み時はアニメーションなしで閉じておく
        }
        render(!folded);

        // 中のモーダルなどの同名イベントは対象にしない
        collapseEl.addEventListener('show.bs.collapse', function (event) {
            if (event.target === collapseEl) {
                render(true);
                remember(false);
            }
        });
        collapseEl.addEventListener('hide.bs.collapse', function (event) {
            if (event.target === collapseEl) {
                render(false);
                remember(true);
            }
        });
    })();

    // 管理メニュー: タイルで選んだ機能だけを表示して、そこまで移動する（ほかの機能は隠す）。
    // 保存後に戻ってきたとき（#admin-… 付きのアドレス）も、その機能を表示した状態にする
    (function () {
        // 保存後の戻り先が管理エリア全体（#admin-tools）のときは、そこで編集している会員一覧を表示する
        var aliases = { 'admin-tools': 'admin-members' };
        var sections = document.querySelectorAll('.admin-section > details');
        var crumb = document.querySelector('[data-admin-crumb]');

        // PC の上のバーの「管理 › ○○」を、開いている機能の名前にする（何も開いていなければダッシュボード）
        function setCrumb(tile) {
            if (!crumb) {
                return;
            }
            var label = tile ? tile.querySelector('.admin-menu-label') : null;
            crumb.textContent = label ? label.textContent : 'ダッシュボード';
        }

        // 左メニューの「ダッシュボード」: 機能を何も開いていないときに選択中にする
        function markDashboard(active) {
            document.querySelectorAll('[data-admin-dashboard]').forEach(function (item) {
                item.classList.toggle('is-active', active);
                if (active) {
                    item.setAttribute('aria-current', 'true');
                } else {
                    item.removeAttribute('aria-current');
                }
            });
        }

        function openAdminSection(id, scroll) {
            var requested = document.getElementById(aliases[id] || id);
            // 機能の中の欄（申込一覧の承認 #admin-entry-status など）が指定されたら、それを含む機能を開いてその欄へ移動する
            var target = requested && requested.tagName !== 'DETAILS' ? requested.closest('.admin-section details') : requested;
            if (!target) {
                return false;
            }
            var topLevel = target.closest('.admin-section > details');
            markDashboard(false);
            sections.forEach(function (section) {
                var active = section === topLevel;
                section.classList.toggle('is-active', active);
                section.open = active;
            });
            for (var el = target; el; el = el.parentElement ? el.parentElement.closest('details') : null) {
                el.open = true;
            }
            document.querySelectorAll('[data-admin-target]').forEach(function (tile) {
                var current = topLevel && tile.getAttribute('data-admin-target') === topLevel.id;
                tile.classList.toggle('is-active', current);
                if (current) {
                    tile.setAttribute('aria-current', 'true');
                    setCrumb(tile);
                } else {
                    tile.removeAttribute('aria-current');
                }
            });
            if (scroll) {
                requested.scrollIntoView({ behavior: 'auto', block: 'start' });
            }
            return true;
        }


        // 開いている機能を折りたたむ（タイルの選択も外し、アドレスの #admin-… も消す）
        function closeAdminSections() {
            sections.forEach(function (section) {
                section.classList.remove('is-active');
                section.open = false;
            });
            document.querySelectorAll('[data-admin-target]').forEach(function (tile) {
                tile.classList.remove('is-active');
                tile.removeAttribute('aria-current');
            });
            setCrumb(null);
            markDashboard(true);
            history.replaceState(null, '', window.location.pathname + window.location.search);
        }

        // 「ダッシュボード」: 開いている機能を閉じて、マイページの上のダッシュボードまで戻る
        document.querySelectorAll('[data-admin-dashboard]').forEach(function (item) {
            item.addEventListener('click', function (event) {
                event.preventDefault();
                closeAdminSections();
                var dashboard = document.getElementById('adminDashboard');
                if (dashboard) {
                    dashboard.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });

        // 開いた機能の見出しを押しても折りたたむ（中の「大会の新規追加」などの折りたたみはそのまま開閉できる）
        document.querySelectorAll('.admin-section > details > summary').forEach(function (summary) {
            summary.addEventListener('click', function (event) {
                event.preventDefault();
                closeAdminSections();
            });
        });

        // タイル: 押すとその機能を開き、開いている機能のタイルをもう一度押すと折りたたむ
        document.querySelectorAll('[data-admin-target]').forEach(function (tile) {
            tile.addEventListener('click', function (event) {
                event.preventDefault();
                if (tile.classList.contains('is-active')) {
                    closeAdminSections();
                    return;
                }
                var id = tile.getAttribute('data-admin-target');
                // 押した機能を開いて、その見出しまでなめらかにスクロールする（上の固定ヘッダーに隠れない位置まで）
                if (openAdminSection(id, false)) {
                    history.replaceState(null, '', '#' + id);
                    var opened = document.getElementById(id);
                    if (opened) {
                        opened.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                }
            });
        });

        // 数字のカード（承認待ちの申込）: 押すとその機能を開く（開いていれば開いたまま）
        document.querySelectorAll('[data-admin-open]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                var tile = document.querySelector('[data-admin-target="' + link.getAttribute('data-admin-open') + '"]');
                if (tile && !tile.classList.contains('is-active')) {
                    tile.click();
                }
            });
        });

        if (location.hash.indexOf('#admin-') === 0) {
            openAdminSection(location.hash.slice(1), true);
        } else {
            markDashboard(true);
        }
    })();
</script>
</body>
</html>