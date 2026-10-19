<?php
require_once __DIR__ . '/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/gallery_lib.php';

if (!isset($_SESSION['user_id'], $_SESSION['user_name'])) {
    header('Location: ../mypage/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../mypage/front_login.php#gallery-post');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$event_date = trim((string)($_POST['gallery_event_date'] ?? ''));
$title = trim((string)($_POST['gallery_title'] ?? ''));
$body = trim((string)($_POST['gallery_body'] ?? ''));

function gallery_resolve_extension(array $file, string $mime_type): ?string
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

    $name = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'webm', 'mov'];
    if (!in_array($ext, $allowed, true)) {
        return null;
    }

    return $ext === 'jpeg' ? 'jpg' : $ext;
}

function upload_gallery_media(string $field_name, string $type): ?string
{
    if (!isset($_FILES[$field_name]) || !is_array($_FILES[$field_name]) || (int)$_FILES[$field_name]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES[$field_name];
    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['gallery_error'] = 'ファイルのアップロードに失敗しました。';
        header('Location: ../mypage/front_login.php#gallery-post');
        exit;
    }

    $max_size = $type === 'video' ? 60 * 1024 * 1024 : 40 * 1024 * 1024;
    if ((int)$file['size'] > $max_size) {
        $_SESSION['gallery_error'] = $type === 'video'
            ? '動画ファイルサイズは60MB以内にしてください。'
            : '画像ファイルサイズは40MB以内にしてください。';
        header('Location: ../mypage/front_login.php#gallery-post');
        exit;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime_type = (string)$finfo->file((string)$file['tmp_name']);
    $ext = gallery_resolve_extension($file, $mime_type);

    if ($ext === null) {
        $_SESSION['gallery_error'] = 'JPG/PNG/WEBP/MP4/WEBM/MOVのみ対応しています。';
        header('Location: ../mypage/front_login.php#gallery-post');
        exit;
    }

    $is_image = strpos($mime_type, 'image/') === 0 || in_array($ext, ['jpg', 'png', 'webp'], true);
    $is_video = strpos($mime_type, 'video/') === 0 || in_array($ext, ['mp4', 'webm', 'mov'], true);

    if ($type === 'image' && !$is_image) {
        $_SESSION['gallery_error'] = '画像欄には画像ファイルを指定してください。';
        header('Location: ../mypage/front_login.php#gallery-post');
        exit;
    }

    if ($type === 'video' && !$is_video) {
        $_SESSION['gallery_error'] = '動画欄には動画ファイルを指定してください。';
        header('Location: ../mypage/front_login.php#gallery-post');
        exit;
    }

    $upload_dir = __DIR__ . '/../uploads/gallery';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0775, true);
    }

    $filename = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '_' . $field_name . '.' . $ext;
    $dest = $upload_dir . '/' . $filename;

    if (!move_uploaded_file((string)$file['tmp_name'], $dest)) {
        $_SESSION['gallery_error'] = 'ファイル保存に失敗しました。';
        header('Location: ../mypage/front_login.php#gallery-post');
        exit;
    }

    return 'uploads/gallery/' . $filename;
}

if ($title === '') {
    $_SESSION['gallery_error'] = 'タイトルは必須です。';
    header('Location: ../mypage/front_login.php#gallery-post');
    exit;
}

if ($event_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $event_date)) {
    $_SESSION['gallery_error'] = '日付形式が不正です。';
    header('Location: ../mypage/front_login.php#gallery-post');
    exit;
}

$image_1 = upload_gallery_media('gallery_image_1', 'image');
$image_2 = upload_gallery_media('gallery_image_2', 'image');
$video_1 = upload_gallery_media('gallery_video_1', 'video');
$video_2 = upload_gallery_media('gallery_video_2', 'video');

try {
    ensure_gallery_table($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO gallery_posts (user_id, event_date, title, body, image_path_1, image_path_2, video_path_1, video_path_2)
         VALUES (:user_id, :event_date, :title, :body, :image_1, :image_2, :video_1, :video_2)'
    );
    $stmt->execute([
        ':user_id' => $user_id,
        ':event_date' => $event_date !== '' ? $event_date : null,
        ':title' => $title,
        ':body' => $body !== '' ? $body : null,
        ':image_1' => $image_1,
        ':image_2' => $image_2,
        ':video_1' => $video_1,
        ':video_2' => $video_2,
    ]);

    $_SESSION['gallery_flash'] = 'ギャラリー投稿を保存しました。';
} catch (PDOException $e) {
    $_SESSION['gallery_error'] = 'ギャラリー投稿の保存に失敗しました。';
}

header('Location: ../mypage/front_login.php#gallery-post');
exit;
