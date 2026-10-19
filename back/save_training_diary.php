<?php
require_once __DIR__ . '/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/training_diary_lib.php';
require_once __DIR__ . '/member_group_lib.php';
require_once __DIR__ . '/redirect_lib.php';
require_once __DIR__ . '/training_diary_draft_lib.php';

if (!isset($_SESSION['user_id'], $_SESSION['user_name'])) {
    header('Location: ../mypage/login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// 投稿した画面（登録シートを開いていたページ）に戻す（同じサイトの中のパスだけ。back/redirect_lib.php）
$diary_return_location = safe_return_location((string)($_POST['return_to'] ?? ''), '../mypage/front_login.php#training-diary');

try {
    ensure_training_diary_table($pdo);
    ensure_training_diary_weight_sets_table($pdo);
    ensure_training_diary_running_tables($pdo);
    ensure_training_diary_race_entries_table($pdo);
    cleanup_old_training_diaries($pdo);
} catch (PDOException $e) {
    $_SESSION['training_error'] = '練習日記の初期化に失敗しました。';
    header('Location: ' . $diary_return_location);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $diary_return_location);
    exit;
}

$practice_date = trim((string)($_POST['practice_date'] ?? ''));
$menu_text = trim((string)($_POST['menu_text'] ?? ''));
$comment_text = trim((string)($_POST['comment_text'] ?? ''));
$is_public = (int)($_POST['is_public'] ?? 0) === 1 ? 1 : 0;
$intensity = trim((string)($_POST['intensity'] ?? 'normal'));
if (!array_key_exists($intensity, training_diary_intensity_options())) {
    $intensity = 'normal';
}
$training_type = trim((string)($_POST['training_type'] ?? 'short_sprint'));
if (!array_key_exists($training_type, training_diary_type_options())) {
    $training_type = 'short_sprint';
}
if ($intensity === 'race') {
    $training_type = 'race';
}
$image_path = null;
$video_path = null;

function parse_weight_sets_json(string $raw_json): array
{
    $decoded = json_decode($raw_json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $allowed_parts = training_diary_weight_body_parts();
    $sets = [];

    foreach ($decoded as $item) {
        if (!is_array($item)) {
            continue;
        }

        $body_part = trim((string)($item['body_part'] ?? ''));
        $exercise_name = trim((string)($item['exercise_name'] ?? ''));

        if (!in_array($body_part, $allowed_parts, true) || $exercise_name === '') {
            continue;
        }

        $reps = isset($item['reps']) && $item['reps'] !== '' && $item['reps'] !== null ? (int)$item['reps'] : null;
        if ($reps !== null && ($reps < 1 || $reps > 500)) {
            $reps = null;
        }

        $sets_count = isset($item['sets']) && $item['sets'] !== '' && $item['sets'] !== null ? (int)$item['sets'] : null;
        if ($sets_count !== null && ($sets_count < 1 || $sets_count > 100)) {
            $sets_count = null;
        }

        $is_bodyweight = !empty($item['is_bodyweight']);
        $weight_kg = null;
        $weight_note = null;

        if (!$is_bodyweight) {
            if (isset($item['weight_kg']) && $item['weight_kg'] !== '' && is_numeric($item['weight_kg'])) {
                $weight_kg = round((float)$item['weight_kg'], 2);
                if ($weight_kg < 0 || $weight_kg > 999) {
                    $weight_kg = null;
                }
            } else {
                $weight_note = mb_substr(trim((string)($item['weight_note'] ?? '')), 0, 50);
                if ($weight_note === '') {
                    $weight_note = null;
                }
            }
        }

        $sets[] = [
            'body_part' => $body_part,
            'exercise_name' => mb_substr($exercise_name, 0, 100),
            'reps' => $reps,
            'sets' => $sets_count,
            'weight_kg' => $weight_kg,
            'is_bodyweight' => $is_bodyweight,
            'weight_note' => $weight_note,
        ];
    }

    return $sets;
}

$weight_sets = $training_type === 'weight'
    ? parse_weight_sets_json((string)($_POST['weight_sets_json'] ?? ''))
    : [];

function parse_running_sets_json(string $raw_json): array
{
    $decoded = json_decode($raw_json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $sets = [];

    foreach ($decoded as $item) {
        if (!is_array($item)) {
            continue;
        }

        $workout_type = mb_substr(trim((string)($item['workout_type'] ?? '')), 0, 20);
        $distance_label = trim((string)($item['distance_label'] ?? ''));

        if ($workout_type === '' || $distance_label === '') {
            continue;
        }

        $reps = isset($item['reps']) && is_numeric($item['reps']) ? (int)$item['reps'] : 1;
        if ($reps < 1 || $reps > 50) {
            $reps = 1;
        }

        $sets_count = isset($item['sets']) && is_numeric($item['sets']) ? (int)$item['sets'] : 1;
        if ($sets_count < 1 || $sets_count > 20) {
            $sets_count = 1;
        }

        $target_time_label = mb_substr(trim((string)($item['target_time_label'] ?? '')), 0, 30);
        if ($target_time_label === '') {
            $target_time_label = null;
        }

        $laps = [];
        $raw_laps = is_array($item['laps'] ?? null) ? $item['laps'] : [];
        foreach ($raw_laps as $raw_lap) {
            $lap_time_seconds = null;
            $lap_time_label = null;

            if (is_numeric($raw_lap)) {
                $lap_time_seconds = round((float)$raw_lap, 2);
                if ($lap_time_seconds < 0 || $lap_time_seconds > 99999) {
                    $lap_time_seconds = null;
                }
            } elseif (is_string($raw_lap) && trim($raw_lap) !== '') {
                $lap_time_label = mb_substr(trim($raw_lap), 0, 20);
            }

            $laps[] = [
                'lap_time_seconds' => $lap_time_seconds,
                'lap_time_label' => $lap_time_label,
            ];
        }

        $sets[] = [
            'workout_type' => $workout_type,
            'distance_label' => mb_substr($distance_label, 0, 30),
            'target_time_label' => $target_time_label,
            'reps' => $reps,
            'sets' => $sets_count,
            'laps' => $laps,
        ];
    }

    return $sets;
}

$running_sets = training_diary_is_running_type($training_type)
    ? parse_running_sets_json((string)($_POST['running_sets_json'] ?? ''))
    : [];

function parse_race_entries_json(string $raw_json): array
{
    $decoded = json_decode($raw_json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $entries = [];

    foreach ($decoded as $item) {
        if (!is_array($item)) {
            continue;
        }

        $event_name = mb_substr(trim((string)($item['event_name'] ?? '')), 0, 50);
        $result_label = mb_substr(trim((string)($item['result_label'] ?? '')), 0, 30);

        if ($event_name === '' || $result_label === '') {
            continue;
        }

        // 風速は「+1.2」「-0.5」の形だけ受け付ける。空（計測なし）や風を計測しない種目は null
        $wind_mps = null;
        $wind_raw = trim((string)($item['wind_mps'] ?? ''));
        if (training_diary_event_uses_wind($event_name) && preg_match('/^[+-]?\d(\.\d)?$/', $wind_raw)) {
            $wind_mps = (float)$wind_raw;
        }

        $entries[] = [
            'event_name' => $event_name,
            'result_label' => $result_label,
            'wind_mps' => $wind_mps,
            // 公認/非公認は送られてきた値を使わず、風速から決め直す
            'is_official' => training_diary_race_entry_is_official($event_name, $wind_mps),
        ];
    }

    return $entries;
}

$race_entries = $training_type === 'race'
    ? parse_race_entries_json((string)($_POST['race_entries_json'] ?? ''))
    : [];

// ジョグ: [{"distance_km": 8.5}, ...]。0.1〜42.9km の小数第1位まで（範囲外や数字でないものは捨てる）
function parse_jog_entries_json(string $raw_json): array
{
    $decoded = json_decode($raw_json, true);
    if (!is_array($decoded)) {
        return [];
    }
    $entries = [];
    foreach ($decoded as $item) {
        $raw_km = is_array($item) ? ($item['distance_km'] ?? null) : null;
        if (!is_numeric($raw_km)) {
            continue;
        }
        $km = round((float)$raw_km, 1);
        if ($km <= 0 || $km > TRAINING_DIARY_JOG_MAX_KM) {
            continue;
        }
        $entries[] = ['distance_km' => $km];
    }
    return $entries;
}

$jog_entries = $training_type === 'jog'
    ? parse_jog_entries_json((string)($_POST['jog_entries_json'] ?? ''))
    : [];

function get_video_duration_seconds(string $file_path): ?float
{
    $ffprobe = 'ffprobe';
    $cmd = $ffprobe . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($file_path);
    $output = @shell_exec($cmd);

    if (!is_string($output) || trim($output) === '') {
        return null;
    }

    $seconds = (float)trim($output);
    return $seconds > 0 ? $seconds : null;
}

function resolve_upload_extension(array $file, string $mime_type): ?string
{
    $mime_map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/quicktime' => 'mov',
    ];

    if (isset($mime_map[$mime_type])) {
        return $mime_map[$mime_type];
    }

    // MIME判定できない環境向けの拡張子フォールバック
    $name = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'webm', 'mov'];

    if (in_array($ext, $allowed, true)) {
        return $ext === 'jpeg' ? 'jpg' : $ext;
    }

    return null;
}

function upload_training_media(string $field_name, string $expected_type): ?string
{
    if (!isset($_FILES[$field_name]) || !is_array($_FILES[$field_name]) || (int)$_FILES[$field_name]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES[$field_name];
    $upload_error = (int)$file['error'];

    if ($upload_error !== UPLOAD_ERR_OK) {
        if ($upload_error === UPLOAD_ERR_INI_SIZE || $upload_error === UPLOAD_ERR_FORM_SIZE) {
            $_SESSION['training_error'] = 'ファイルサイズがサーバー上限を超えています。';
        } else {
            $_SESSION['training_error'] = 'ファイルのアップロードに失敗しました。';
        }
        header('Location: ' . $diary_return_location);
        exit;
    }

    $max_size = $expected_type === 'video' ? 60 * 1024 * 1024 : 40 * 1024 * 1024;
    if ((int)$file['size'] > $max_size) {
        $_SESSION['training_error'] = $expected_type === 'video'
            ? '動画ファイルサイズは60MB以内にしてください。'
            : '画像ファイルサイズは40MB以内にしてください。';
        header('Location: ' . $diary_return_location);
        exit;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime_type = (string)$finfo->file((string)$file['tmp_name']);
    $extension = resolve_upload_extension($file, $mime_type);
    if ($extension === null) {
        $_SESSION['training_error'] = 'JPG・PNG・WEBP・MP4・WEBM・MOVのみ対応しています。';
        header('Location: ' . $diary_return_location);
        exit;
    }

    $is_video_upload = strpos($mime_type, 'video/') === 0 || in_array($extension, ['mp4', 'webm', 'mov'], true);
    $is_image_upload = strpos($mime_type, 'image/') === 0 || in_array($extension, ['jpg', 'png', 'webp'], true);

    if ($expected_type === 'image' && !$is_image_upload) {
        $_SESSION['training_error'] = '写真欄には画像ファイルを選択してください。';
        header('Location: ' . $diary_return_location);
        exit;
    }
    if ($expected_type === 'video' && !$is_video_upload) {
        $_SESSION['training_error'] = '動画欄には動画ファイルを選択してください。';
        header('Location: ' . $diary_return_location);
        exit;
    }

    if ($is_video_upload) {
        $duration = get_video_duration_seconds((string)$file['tmp_name']);
        // 案内は「15秒以内」だが、少しはみ出した動画も投稿できるよう、実際は20秒まで受け付ける
        if ($duration !== null && $duration > 20.0) {
            $_SESSION['training_error'] = '動画は15秒以内にしてください。';
            header('Location: ' . $diary_return_location);
            exit;
        }
    }

    $upload_dir = __DIR__ . '/../uploads/training_diary';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0775, true);
    }

    $filename = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '_' . $field_name . '.' . $extension;
    $destination = $upload_dir . '/' . $filename;

    if (!move_uploaded_file((string)$file['tmp_name'], $destination)) {
        $_SESSION['training_error'] = 'ファイル保存に失敗しました。';
        header('Location: ' . $diary_return_location);
        exit;
    }

    return 'uploads/training_diary/' . $filename;
}

if ($practice_date === '' || $menu_text === '') {
    $_SESSION['training_error'] = '日付、練習タイトルは必須です。';
    header('Location: ' . $diary_return_location);
    exit;
}

if (training_diary_is_running_type($training_type) && count($running_sets) === 0) {
    $_SESSION['training_error'] = 'ランニング種目を1つ以上追加してください。';
    header('Location: ' . $diary_return_location);
    exit;
}

if ($training_type === 'weight' && count($weight_sets) === 0) {
    $_SESSION['training_error'] = 'ウエイト種目を1つ以上追加してください。';
    header('Location: ' . $diary_return_location);
    exit;
}

if ($training_type === 'race' && count($race_entries) === 0) {
    $_SESSION['training_error'] = '出場種目を1つ以上追加してください。';
    header('Location: ' . $diary_return_location);
    exit;
}

if ($training_type === 'jog' && count($jog_entries) === 0) {
    $_SESSION['training_error'] = 'ジョグの距離を1つ以上追加してください。';
    header('Location: ' . $diary_return_location);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $practice_date)) {
    $_SESSION['training_error'] = '日付形式が不正です。';
    header('Location: ' . $diary_return_location);
    exit;
}

$image_path = upload_training_media('training_image', 'image');
$video_path = upload_training_media('training_video', 'video');

try {
    // 表の準備（CREATE TABLE）はトランザクションの前に済ませる（MySQL は途中で表を作ると、そこで確定してしまう）
    ensure_training_diary_jog_table($pdo);
    ensure_member_group_tables($pdo);
    // 日記と種目（とグループの紐付け）はまとめて保存する。途中で失敗したら、種目のない日記を残さない
    $pdo->beginTransaction();

    $insert_sql = "
        INSERT INTO training_diaries (
            user_id,
            practice_date,
            menu,
            training_type,
            record_time,
            comment,
            is_public,
            intensity,
            image_path,
            video_path,
            created_at
        ) VALUES (
            :user_id,
            :practice_date,
            :menu,
            :training_type,
            :record_time,
            :comment,
            :is_public,
            :intensity,
            :image_path,
            :video_path,
            NOW()
        )
    ";

    $stmt = $pdo->prepare($insert_sql);
    $stmt->execute([
        ':user_id' => $user_id,
        ':practice_date' => $practice_date,
        ':menu' => $menu_text,
        ':training_type' => $training_type,
        ':record_time' => null,
        ':comment' => $comment_text !== '' ? $comment_text : null,
        ':is_public' => $is_public,
        ':intensity' => $intensity,
        ':image_path' => $image_path,
        ':video_path' => $video_path,
    ]);
    $diary_id = (int)$pdo->lastInsertId();

    if ($training_type === 'weight') {
        insert_training_diary_weight_sets($pdo, $diary_id, $weight_sets);
    } elseif (training_diary_is_running_type($training_type)) {
        insert_training_diary_running_sets($pdo, $diary_id, $running_sets);
    } elseif ($training_type === 'race') {
        insert_training_diary_race_entries($pdo, $diary_id, $race_entries);
    } elseif ($training_type === 'jog') {
        insert_training_diary_jog_entries($pdo, $diary_id, $jog_entries);
    }

    // 公開範囲で選んだグループ（参加中のものだけ保存される）
    $posted_group_ids = (array)($_POST['group_ids'] ?? []);
    if (!empty($posted_group_ids)) {
        save_training_diary_groups($pdo, $diary_id, $user_id, $posted_group_ids);
    }

    $pdo->commit();
    $_SESSION['training_flash'] = '練習日記を投稿しました。';
    // 投稿したので下書きは消す（消せなくても投稿は済んでいるので、記録だけ残す）
    try {
        delete_training_diary_draft($pdo, $user_id);
    } catch (PDOException $draft_error) {
        error_log('delete training diary draft failed: ' . $draft_error->getMessage());
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('save training diary failed: ' . $e->getMessage());
    $_SESSION['training_error'] = '練習日記の保存に失敗しました。';
}

header('Location: ' . $diary_return_location);
exit;
