<?php
require_once __DIR__ . '/session_bootstrap.php';
if (session_status() === PHP_SESSION_NONE) {
    tobg_start_session();
}
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/inquiry_lib.php';
require_once __DIR__ . '/mail_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../front/contact.php');
    exit;
}

$message = trim((string)($_POST['message'] ?? ''));
$is_anonymous = (int)($_POST['is_anonymous'] ?? 0) === 1;
$sender_name_input = trim((string)($_POST['sender_name'] ?? ''));
$session_user_name = isset($_SESSION['user_name']) ? trim((string)$_SESSION['user_name']) : '';
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$wants_team_membership = (int)($_POST['wants_team_membership'] ?? 0) === 1;

if ($message === '') {
    $_SESSION['contact_error'] = 'お問い合わせ内容を入力してください。';
    header('Location: ../front/contact.php');
    exit;
}

$display_name = null;
if (!$is_anonymous) {
    $display_name = $sender_name_input !== '' ? $sender_name_input : ($session_user_name !== '' ? $session_user_name : null);
    if ($display_name === null) {
        $is_anonymous = true;
    }
}

$applicant_name = null;
$applicant_furigana = null;
$applicant_birthdate = null;
$applicant_postal_code = null;
$applicant_prefecture = null;
$applicant_address = null;
$applicant_address_detail = null;
$applicant_phone = null;

if ($wants_team_membership) {
    $applicant_name = trim((string)($_POST['applicant_name'] ?? ''));
    $applicant_furigana = trim((string)($_POST['applicant_furigana'] ?? ''));
    $applicant_postal_code = trim((string)($_POST['applicant_postal_code'] ?? ''));
    $applicant_prefecture = trim((string)($_POST['applicant_prefecture'] ?? ''));
    $applicant_address = trim((string)($_POST['applicant_address'] ?? ''));
    $applicant_address_detail = trim((string)($_POST['applicant_address_detail'] ?? ''));
    $applicant_phone = trim((string)($_POST['applicant_phone'] ?? ''));

    $birth_year = (int)($_POST['birth_year'] ?? 0);
    $birth_month = (int)($_POST['birth_month'] ?? 0);
    $birth_day = (int)($_POST['birth_day'] ?? 0);
    if ($birth_year > 0 && $birth_month > 0 && $birth_day > 0 && checkdate($birth_month, $birth_day, $birth_year)) {
        $applicant_birthdate = sprintf('%04d-%02d-%02d', $birth_year, $birth_month, $birth_day);
    }

    if ($applicant_name === '' || $applicant_furigana === '' || $applicant_postal_code === '' || $applicant_prefecture === '' || $applicant_address === '' || $applicant_phone === '') {
        $_SESSION['contact_error'] = 'チーム所属の申し込みには、お名前・フリガナ・郵便番号・住所・電話番号の入力が必要です。';
        header('Location: ../front/contact.php');
        exit;
    }
}

try {
    ensure_inquiries_table($pdo);

    $stmt = $pdo->prepare(
        "INSERT INTO inquiries (
            user_id, sender_name, is_anonymous, message,
            wants_team_membership, applicant_name, applicant_furigana, applicant_birthdate,
            applicant_postal_code, applicant_prefecture, applicant_address, applicant_address_detail, applicant_phone,
            created_at
         ) VALUES (
            :user_id, :sender_name, :is_anonymous, :message,
            :wants_team_membership, :applicant_name, :applicant_furigana, :applicant_birthdate,
            :applicant_postal_code, :applicant_prefecture, :applicant_address, :applicant_address_detail, :applicant_phone,
            NOW()
         )"
    );
    $stmt->execute([
        ':user_id' => $user_id,
        ':sender_name' => $is_anonymous ? null : $display_name,
        ':is_anonymous' => $is_anonymous ? 1 : 0,
        ':message' => $message,
        ':wants_team_membership' => $wants_team_membership ? 1 : 0,
        ':applicant_name' => $wants_team_membership ? $applicant_name : null,
        ':applicant_furigana' => $wants_team_membership ? $applicant_furigana : null,
        ':applicant_birthdate' => $applicant_birthdate,
        ':applicant_postal_code' => $wants_team_membership ? $applicant_postal_code : null,
        ':applicant_prefecture' => $wants_team_membership ? $applicant_prefecture : null,
        ':applicant_address' => $wants_team_membership ? $applicant_address : null,
        ':applicant_address_detail' => $wants_team_membership && $applicant_address_detail !== '' ? $applicant_address_detail : null,
        ':applicant_phone' => $wants_team_membership ? $applicant_phone : null,
    ]);

    $notify_name = $is_anonymous ? '匿名の方' : (string)$display_name;
    $notify_body = $notify_name . "からお問い合わせが届きました。\n\n" . $message;
    if ($wants_team_membership) {
        $notify_body .= "\n\n【チーム所属の申し込み】\n" .
            "お名前: " . $applicant_name . "（" . $applicant_furigana . "）\n" .
            "生年月日: " . ($applicant_birthdate ?? '未入力') . "\n" .
            "郵便番号: " . $applicant_postal_code . "\n" .
            "住所: " . $applicant_prefecture . $applicant_address . ' ' . $applicant_address_detail . "\n" .
            "電話番号: " . $applicant_phone;
    }
    $notify_body .= "\n\nマイページの管理画面から確認してください。\n\nhttps://tobg.sadist.jp/";
    send_admin_notification_email('【TOBG】新しいお問い合わせが届きました', $notify_body);

    $_SESSION['contact_flash'] = '送信されました！';
} catch (PDOException $e) {
    $_SESSION['contact_error'] = '送信に失敗しました。時間を置いて再度お試しください。';
}

header('Location: ../front/contact.php');
exit;
