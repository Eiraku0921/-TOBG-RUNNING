<?php
require_once __DIR__ . '/session_bootstrap.php';
tobg_start_session();

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/member_avatar_lib.php';

if (!isset($_SESSION['user_id'], $_SESSION['user_name'])) {
    header('Location: ../mypage/login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../mypage/front_login.php');
    exit;
}

if (!isset($_FILES['profile_icon']) || !is_array($_FILES['profile_icon'])) {
    $_SESSION['profile_error'] = '画像ファイルを選択してください。';
    header('Location: ../mypage/front_login.php');
    exit;
}

$file = $_FILES['profile_icon'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $_SESSION['profile_error'] = '画像アップロードに失敗しました。';
    header('Location: ../mypage/front_login.php');
    exit;
}

$max_size = 5 * 1024 * 1024;
if ((int)($file['size'] ?? 0) <= 0 || (int)$file['size'] > $max_size) {
    $_SESSION['profile_error'] = '画像サイズは5MB以内にしてください。';
    header('Location: ../mypage/front_login.php');
    exit;
}

$tmp_name = (string)($file['tmp_name'] ?? '');
if ($tmp_name === '' || !is_uploaded_file($tmp_name)) {
    $_SESSION['profile_error'] = 'アップロードファイルを確認できません。';
    header('Location: ../mypage/front_login.php');
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = $finfo ? (string)finfo_file($finfo, $tmp_name) : '';
if ($finfo) {
    finfo_close($finfo);
}

$allowed = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
];

if (!isset($allowed[$mime])) {
    $_SESSION['profile_error'] = '対応形式は JPG / PNG / WEBP / GIF です。';
    header('Location: ../mypage/front_login.php');
    exit;
}

$ext = $allowed[$mime];
$upload_dir = __DIR__ . '/../uploads/member_icons';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$file_name = 'member_' . $user_id . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$target_abs = $upload_dir . '/' . $file_name;
$target_rel = 'uploads/member_icons/' . $file_name;

if (!move_uploaded_file($tmp_name, $target_abs)) {
    $_SESSION['profile_error'] = '画像の保存に失敗しました。';
    header('Location: ../mypage/front_login.php');
    exit;
}

try {
    ensure_member_avatar_column($pdo);

    $old_stmt = $pdo->prepare('SELECT avatar_image_path FROM members WHERE id = :id LIMIT 1');
    $old_stmt->execute([':id' => $user_id]);
    $old_path = normalize_member_avatar_path((string)($old_stmt->fetchColumn() ?: ''));

    $update_stmt = $pdo->prepare('UPDATE members SET avatar_image_path = :path WHERE id = :id');
    $update_stmt->execute([
        ':path' => $target_rel,
        ':id' => $user_id,
    ]);

    $_SESSION['user_avatar_path'] = $target_rel;
    $_SESSION['profile_flash'] = 'プロフィールアイコンを更新しました。';

    if ($old_path !== '' && strpos($old_path, 'uploads/member_icons/') === 0) {
        $old_abs = __DIR__ . '/../' . $old_path;
        if (is_file($old_abs)) {
            @unlink($old_abs);
        }
    }
} catch (Throwable $e) {
    if (is_file($target_abs)) {
        @unlink($target_abs);
    }
    $_SESSION['profile_error'] = 'プロフィールアイコンの保存中にエラーが発生しました。';
}

header('Location: ../mypage/front_login.php');
exit;
