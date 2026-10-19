<?php
require_once __DIR__ . '/../back/session_bootstrap.php';
require_once __DIR__ . '/../back/member_role_lib.php';
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    tobg_start_session();
}
$header_db = __DIR__ . '/../back/db_connect.php';
$header_avatar_lib = __DIR__ . '/../back/member_avatar_lib.php';
$header_access_lib = __DIR__ . '/../back/access_counter_lib.php';

$site_user_name = $_SESSION['user_name'] ?? '';
$site_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$site_user_role = isset($_SESSION['user_role']) ? (string)$_SESSION['user_role'] : '';
$site_is_admin = $site_user_role === 'admin';
$site_user_avatar_path = isset($_SESSION['user_avatar_path']) ? (string)$_SESSION['user_avatar_path'] : '';
$access_count_24h = null;

if ($site_user_id > 0 && file_exists($header_db) && file_exists($header_avatar_lib)) {
    try {
        require_once $header_db;
        require_once $header_avatar_lib;
        ensure_member_avatar_column($pdo);

        $avatar_stmt = $pdo->prepare('SELECT avatar_image_path FROM members WHERE id = :id LIMIT 1');
        $avatar_stmt->execute([':id' => $site_user_id]);
        $site_user_avatar_path = normalize_member_avatar_path((string)($avatar_stmt->fetchColumn() ?: ''));
        $_SESSION['user_avatar_path'] = $site_user_avatar_path;
    } catch (Throwable $site_error) {
        // ヘッダー表示優先でフォールバック
    }
}

if (file_exists($header_db) && file_exists($header_access_lib)) {
    try {
        require_once $header_db;
        require_once $header_access_lib;
        ensure_access_counter_table($pdo);

        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        $page_path = (string)(parse_url($request_uri, PHP_URL_PATH) ?? '');
        $page_path = $page_path !== '' ? mb_substr($page_path, 0, 255) : '/';

        $session_id = session_id();
        $remote_ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';

        log_access_hit($pdo, $session_id, $page_path, $remote_ip, $user_agent);
        $counter_value = increment_and_get_24h_access_counter($pdo);

        if ($site_user_id > 0 && $site_user_name !== '' && $site_is_admin) {
            $access_count_24h = $counter_value;
        }
    } catch (Throwable $site_error) {
        $access_count_24h = null;
    }
}

$document_root = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/')) : '';
$app_dir = str_replace('\\', '/', dirname(__DIR__));
$base_path = '';

if ($document_root !== '' && strpos($app_dir, $document_root) === 0) {
    $base_path = substr($app_dir, strlen($document_root));
}

$base_path = '/' . trim((string)$base_path, '/');
if ($base_path === '/') {
    $base_path = '';
}

$mypage_url = $site_user_name !== ''
    ? $base_path . '/mypage/front_login.php'
    : $base_path . '/mypage/login.php';
$records_url = $base_path . '/front/records.php';
$gallery_url = $base_path . '/front/gallery.php';
$team_details_url = $base_path . '/front/team_details.php';
$training_diary_url = $base_path . '/front/training_diary.php';
$contact_url = $base_path . '/front/contact.php';
$home_url = $base_path === '' ? '/' : $base_path . '/index.php';
$site_is_guest = $site_user_id <= 0 || $site_user_name === '';

// 申込締切の前日リマインド。cron が無くても送れるよう、誰かがページを開いたときにも確認する
// （送信済みは DB に記録しているので、cron と両方で動いても二重には送らない）
$header_tournament_notification_lib = __DIR__ . '/../back/tournament_notification_lib.php';
if (file_exists($header_db) && file_exists($header_tournament_notification_lib)) {
    try {
        require_once $header_db;
        require_once $header_tournament_notification_lib;
        send_due_tournament_deadline_reminders($pdo);
    } catch (Throwable $site_error) {
        error_log('deadline reminder check failed: ' . $site_error->getMessage());
    }
}

// ベルの通知（ログイン中のみ）。読み込めなくてもヘッダーは表示する
$site_notifications = [];
$site_unread_count = 0;
$header_notification_lib = __DIR__ . '/../back/notification_lib.php';
if (!$site_is_guest && file_exists($header_db) && file_exists($header_notification_lib)) {
    try {
        require_once $header_db;
        require_once $header_notification_lib;
        require_once __DIR__ . '/../back/tournament_cleanup_lib.php';
        $site_notifications = fetch_user_notifications($pdo, $site_user_id, member_id_is_jaaf_registered($pdo, $site_user_id), $site_is_admin);
        foreach ($site_notifications as $site_notification) {
            if (!$site_notification['is_read']) {
                $site_unread_count++;
            }
        }
    } catch (Throwable $site_error) {
        $site_notifications = [];
        $site_unread_count = 0;
    }
}
$notification_read_url = $base_path . '/back/mark_notification_read.php';

// プッシュ通知の購読に使う、このサイトの公開鍵（ログイン中のみ）
$site_vapid_public_key = '';
$header_web_push_lib = __DIR__ . '/../back/web_push_lib.php';
if (!$site_is_guest && file_exists($header_db) && file_exists($header_web_push_lib)) {
    try {
        require_once $header_db;
        require_once $header_web_push_lib;
        $site_vapid_public_key = get_vapid_keys($pdo)['public_key'];
    } catch (Throwable $site_error) {
        $site_vapid_public_key = '';
    }
}
$push_subscription_url = $base_path . '/back/push_subscription.php';
?>
<style>
    /* ヘッダーの横の寸法。ページ側の見出しをロゴの「T」の位置にそろえるときも、
       --site-logo-text-inset（バーの左端からロゴの文字までの距離）を使う */
    :root {
        --site-header-pad-x: 24px;
        /* ロゴは「アイコン 30px ＋ すき間 8px ＋ 文字」なので、文字までの距離は 38px 足す */
        --site-logo-text-inset: calc(var(--site-header-pad-x) + 38px);
    }
    @media (max-width: 768px) {
        :root {
            --site-header-pad-x: 4px; /* 帯を無くしたので、本文のカードとほぼ同じ位置から始める */
        }
    }
    /* ヘッダーは角丸のバーにして、下のカードと同じ幅にそろえる。
       外側はページ背景色で塗り、スクロールしたカードがバーの上の隙間から透けないようにする */
    .site-header {
        position: sticky;
        top: 0;
        z-index: 1020;
        background: var(--color-page-bg);
        padding: 12px var(--page-gutter) 0;
    }
    .site-header-inner {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        position: relative;
        max-width: calc(var(--content-max-width) - 2 * var(--page-gutter)); /* 本文のカードと同じ幅 */
        margin: 0 auto;
        padding: 20px var(--site-header-pad-x);
        border-bottom: 1px solid var(--line); /* 本文との区切りの線 */
        /* 仕様書のヘッダーは帯にせず、アプリの地（ベージュ）にそのまま載せる */
        background: transparent;
    }
    .site-brand {
        display: flex;
        align-items: center;
        gap: 12px;
        min-width: 0;
    }
    /* ロゴ（仕様書）: サイトアイコン 30×30px（角丸8px）＋「TOBG-RUNNING」Archivo 900 15px 字間0.06em --navy */
    .site-brand-link {
        flex-shrink: 0;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        color: var(--navy);
        font-family: var(--font-num);
        font-size: 15px;
        font-weight: 900;
        letter-spacing: 0.06em;
        line-height: 1;
        text-decoration: none;
    }
    .site-brand-icon {
        width: 30px;
        height: 30px;
        border-radius: 8px;
        object-fit: cover;
    }
    @media (max-width: 768px) {
        /* スマホは幅が狭いので、管理者の表示回数は出さない */
        .site-access-count {
            display: none;
        }
    }
    /* 管理者だけに見えるページ表示回数 */
    .site-access-count {
        color: var(--color-text-secondary);
        font-size: 0.75rem;
        font-weight: 600;
        white-space: nowrap;
        cursor: help;
    }
    .site-access-count strong {
        color: var(--color-accent);
        font-size: 0.9rem;
        font-weight: 800;
        margin-left: 2px;
    }
    .site-brand-link:hover {
        color: var(--color-text-primary);
    }
    .site-user-actions {
        display: flex;
        align-items: center;
        gap: 14px;
        min-width: 0;
    }
    .site-user-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        min-width: 0;
        max-width: 50vw;
        padding: 4px 14px 4px 4px;
        border-radius: 999px;
        background: var(--color-card-bg);
        color: var(--color-text-primary);
        font-size: 0.95rem;
        font-weight: 600;
        text-decoration: none;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .site-user-pill-name {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    /* ピルの中のアイコンは少し小さくする */
    .site-user-pill .site-user-avatar,
    .site-user-pill .site-user-icon {
        width: 30px;
        height: 30px;
        background: var(--color-pill-bg);
    }
    .site-user-pill:hover {
        color: var(--color-text-primary);
        filter: brightness(0.97);
    }
    /* 自分のアイコン（写真がないとき）: 藤色の丸に白文字（仕様書） */
    .site-user-initials {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        width: 30px;
        height: 30px;
        border-radius: 50%;
        background: var(--wis);
        color: #FFFFFF;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0;
    }
    /* スマホは名前を出さず、36px の丸いアイコンだけにする */
    @media (max-width: 768px) {
        .site-user-actions {
            gap: 18px; /* ベルと自分のアイコンの間を広めに（押し間違いを防ぐ） */
        }
        .site-user-pill {
            padding: 0;
            background: none;
        }
        .site-user-pill-name {
            display: none;
        }
        .site-user-pill .site-user-avatar,
        .site-user-pill .site-user-initials {
            width: 36px;
            height: 36px;
        }
    }
    .site-login-btn {
        flex-shrink: 0;
        padding: 6px 14px;
        border-radius: var(--button-radius);
        background: var(--color-accent);
        color: var(--color-accent-text);
        font-size: 0.85rem;
        font-weight: 700;
        text-decoration: none;
        white-space: nowrap;
    }
    .site-login-btn:hover {
        background: var(--color-accent-hover);
        color: var(--color-accent-text);
    }
    .site-user-icon {
        width: 30px;
        height: 30px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        background: var(--color-pill-bg);
        color: var(--color-text-primary);
        flex-shrink: 0;
    }
    .site-user-avatar {
        width: 30px;
        height: 30px;
        border-radius: 999px;
        object-fit: cover;
        border: 1px solid var(--color-border);
        background: var(--color-card-bg);
        flex-shrink: 0;
    }
    /* ---- 通知ベル ---- */
    /* ベル（仕様書）: 36px の丸、白地、枠 --line */
    .site-bell-btn {
        position: relative;
        flex-shrink: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        border: 1px solid var(--line);
        border-radius: 50%;
        background: var(--surface);
        color: var(--navy);
        cursor: pointer;
    }
    .site-bell-btn svg {
        width: 18px;
        height: 18px;
    }
    .site-bell-btn:hover,
    .site-bell-btn[aria-expanded="true"] {
        border-color: var(--sub);
    }
    /* 未読の件数（ベルの右上の丸いバッジ。100件以上は「99+」） */
    .site-bell-count {
        position: absolute;
        top: -5px;
        right: -6px;
        min-width: 18px;
        height: 18px;
        padding: 0 5px;
        border-radius: 999px;
        background: var(--color-accent);
        box-shadow: 0 0 0 2px var(--color-page-bg); /* ベルの線と重なっても数字が読めるように縁取る */
        color: var(--color-accent-text);
        font-size: 0.68rem;
        font-weight: 700;
        line-height: 18px;
        text-align: center;
    }
    .site-notification-panel {
        position: absolute;
        right: 0;
        top: calc(100% + 8px);
        width: min(94vw, 360px);
        border-radius: var(--card-radius);
        border: 1px solid var(--color-border);
        background: var(--color-card-bg);
        box-shadow: 0 12px 32px rgba(43, 39, 35, 0.12);
        overflow: hidden;
        opacity: 0;
        visibility: hidden;
        transform: translateY(-6px);
        transition: opacity 0.18s ease, transform 0.18s ease, visibility 0.18s ease;
        z-index: 1030;
    }
    .site-notification-panel.is-open {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
    }
    .site-notification-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 12px 16px;
        border-bottom: 1px solid var(--color-border);
    }
    .site-notification-title {
        margin: 0;
        font-size: 0.92rem;
        font-weight: 800;
        color: var(--color-text-primary);
    }
    .site-notification-read-all {
        border: 0;
        background: transparent;
        padding: 0;
        color: var(--orange-deep);
        font-size: 0.78rem;
        font-weight: 600;
        cursor: pointer;
    }
    .site-notification-read-all:hover {
        text-decoration: underline;
    }
    .site-notification-read-all[hidden] {
        display: none;
    }
    .site-notification-list {
        list-style: none;
        margin: 0;
        padding: 0;
        max-height: min(70vh, 420px);
        overflow-y: auto;
    }
    .site-notification-item {
        position: relative;
    }
    .site-notification-item + .site-notification-item {
        border-top: 1px solid var(--color-border);
    }
    /* 「返信する」がある行は、右側をボタンの分だけ空ける */
    .site-notification-link.has-reply {
        padding-right: 92px;
    }
    /* アコーディオンで開く通知（ボタンの見た目をリンクの行にそろえる） */
    button.site-notification-link {
        width: 100%;
        border: 0;
        background: transparent;
        text-align: left;
        font: inherit;
        cursor: pointer;
    }
    .site-notification-time-row {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .site-notification-toggle-hint {
        display: inline-flex;
        align-items: center;
        gap: 3px;
        margin-top: 2px;
        color: var(--color-text-secondary);
        font-size: 0.72rem;
        font-weight: 700;
    }
    .site-notification-chevron {
        display: inline-block;
        font-size: 0.6rem;
        transition: transform 0.2s ease;
    }
    .site-notification-link[aria-expanded="true"] .site-notification-chevron {
        transform: rotate(180deg);
    }
    .site-notification-detail {
        padding: 0 16px 12px 28px;
    }
    .site-notification-body {
        margin: 0 0 8px;
        padding: 10px 12px;
        border-radius: var(--button-radius);
        background: var(--color-page-bg);
        color: var(--color-text-primary);
        font-size: 0.85rem;
        line-height: 1.6;
        overflow-wrap: anywhere;
    }
    .site-notification-open {
        color: var(--color-text-secondary);
        font-size: 0.78rem;
        font-weight: 700;
        text-decoration: none;
    }
    .site-notification-open:hover {
        color: var(--color-text-primary);
    }
    /* 返信ボタンは1行目の高さにそろえる（アコーディオンを開いても位置が動かない）。
       明るいオレンジに白文字は読みにくいので、白地に赤い枠と赤い文字（未読の行の薄いオレンジの地の上でも見える） */
    .site-notification-reply {
        position: absolute;
        right: 12px;
        top: 14px;
        padding: 5px 12px;
        border: 1px solid var(--coral);
        border-radius: var(--button-radius);
        background: var(--surface);
        color: var(--coral);
        font-size: 0.78rem;
        font-weight: 700;
        text-decoration: none;
        white-space: nowrap;
    }
    .site-notification-reply:hover {
        background: #FFF1EE;
        color: var(--coral);
    }
    /* お知らせのボタン（「追加方法を見る」など）。文字が長いときは省略する */
    .site-notification-link.has-link-action {
        padding-right: 150px;
    }
    .site-notification-reply.is-link-action {
        box-sizing: border-box;
        max-width: 132px;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .site-notification-link {
        position: relative;
        display: block;
        padding: 12px 16px 12px 28px;
        color: var(--color-text-primary);
        text-decoration: none;
    }
    .site-notification-link:hover {
        background: var(--color-page-bg);
        color: var(--color-text-primary);
    }
    /* 未読は薄いアクセント背景＋左に小さい点 */
    .site-notification-link.is-unread {
        background: var(--color-accent-bg);
    }
    .site-notification-link.is-unread::before {
        content: "";
        position: absolute;
        left: 12px;
        top: 19px;
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: var(--color-accent);
    }
    .site-notification-text {
        display: block;
        font-size: 0.88rem;
        line-height: 1.5;
    }
    /* 重要なお知らせの札（タイトルの前。赤い枠と赤い文字） */
    .site-important-badge {
        display: inline-block;
        margin-right: 6px;
        padding: 0 6px;
        border: 1px solid var(--coral);
        border-radius: 4px;
        color: var(--coral);
        font-size: 0.7rem;
        font-weight: 700;
        line-height: 1.5;
        vertical-align: 1px;
    }
    .site-notification-time {
        display: block;
        margin-top: 2px;
        color: var(--color-text-muted);
        font-size: 0.75rem;
    }
    /* プッシュ通知の設定（通知パネルの一番下） */
    .site-push-settings {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 10px 16px 12px;
        border-top: 1px solid var(--color-border);
        background: var(--color-page-bg);
    }
    .site-push-settings[hidden],
    .site-push-button[hidden] {
        display: none;
    }
    .site-push-status {
        margin: 0;
        color: var(--color-text-secondary);
        font-size: 0.75rem;
        line-height: 1.5;
    }
    .site-push-actions {
        display: flex;
        flex-shrink: 0;
        gap: 6px;
    }
    /* 「プッシュ通知を有効にする」は、通知の中のボタンと区別できるよう緑の地に白文字（「オンにする」が伝わる色） */
    .site-push-button {
        flex-shrink: 0;
        padding: 5px 12px;
        border: 0;
        border-radius: var(--button-radius);
        background: #2F8A5B;
        color: #FFFFFF;
        font-size: 0.78rem;
        font-weight: 700;
        white-space: nowrap;
    }
    .site-push-button.is-off {
        background: transparent;
        border: 1px solid var(--color-border);
        color: var(--color-text-secondary);
    }
    .site-notification-empty {
        margin: 0;
        padding: 20px 16px;
        color: var(--color-text-secondary);
        font-size: 0.85rem;
        text-align: center;
    }
    @media (min-width: 769px) {
        .site-header-inner {
            padding: 18px var(--site-header-pad-x);
        }
        /* PC はベル・名前を一回り大きく */
        .site-user-actions {
            gap: 16px;
        }
        .site-bell-btn {
            width: 40px;
            height: 40px;
        }
        .site-bell-btn svg {
            width: 20px;
            height: 20px;
        }
        .site-bell-count {
            top: -5px;
            right: -6px;
            min-width: 20px;
            height: 20px;
            line-height: 20px;
            font-size: 0.72rem;
        }
        .site-user-pill {
            padding: 5px 18px 5px 5px;
            font-size: 1.05rem;
            gap: 8px;
        }
        .site-user-pill .site-user-avatar,
        .site-user-pill .site-user-icon {
            width: 36px;
            height: 36px;
        }
        .site-user-pill .site-user-icon svg {
            width: 20px;
            height: 20px;
        }
    }
    @media (max-width: 768px) {
        /* スマホはブラウザのアドレスバーとの間を詰める */
        .site-header {
            padding-top: 6px;
        }
        .site-header-inner {
            padding: 10px var(--site-header-pad-x);
        }
        .site-notification-panel {
            right: 0;
        }
    }
    /* ---- 下タブ（スマホのみ）。白地・上に1px罫線、5項目（アイコン22px・ラベル10px）、真ん中は「＋」の丸 ---- */
    .site-tabbar {
        display: none;
    }
    @media (max-width: 767.98px) {
        body {
            padding-bottom: calc(100px + env(safe-area-inset-bottom)); /* 下タブに本文が隠れないように */
        }
        .site-tabbar {
            position: fixed;
            right: 0;
            bottom: 0;
            left: 0;
            z-index: 1030; /* 固定ヘッダーと同じ高さ。登録シート（モーダル）より下 */
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            align-items: end;
            padding: 8px 4px calc(36px + env(safe-area-inset-bottom)); /* 画面の下の端から離して押しやすくする */
            border-top: 1px solid var(--line);
            background: var(--surface);
        }
        .site-tab {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 3px;
            padding: 6px 0 2px;
            border: 0;
            background: none;
            color: var(--sub);
            font: inherit;
            font-size: 10px;
            font-weight: 700;
            text-decoration: none;
        }
        .site-tab svg {
            width: 22px;
            height: 22px;
        }
        /* 選択中: 文字はネイビー、上にオレンジの短い線（22×3px） */
        .site-tab.is-current {
            color: var(--navy);
        }
        .site-tab.is-current::before {
            content: "";
            position: absolute;
            top: -6px;
            left: 50%;
            width: 22px;
            height: 3px;
            border-radius: 999px;
            background: var(--orange);
            transform: translateX(-50%);
        }
        /* 「記録する」: 直径50pxのオレンジの丸が、上に26pxはみ出す */
        .site-tab-plus {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 50px;
            height: 50px;
            margin-top: -26px;
            border-radius: 50%;
            background: var(--orange);
            color: #FFFFFF;
            box-shadow: 0 6px 16px rgba(255, 122, 26, .4);
        }
        .site-tab-plus svg {
            width: 24px;
            height: 24px;
        }
    }
    /* ---- トースト: 画面下（スマホは下タブの上）中央、ネイビー地・白文字13px・丸。2.6秒で消える ---- */
    .site-toast {
        position: fixed;
        bottom: 24px;
        left: 50%;
        z-index: 1090;
        max-width: calc(100% - 32px);
        padding: 10px 18px;
        border-radius: 999px;
        background: var(--navy);
        color: #FFFFFF;
        font-size: 13px;
        font-weight: 700;
        box-shadow: var(--shadow-float);
        transform: translateX(-50%);
        transition: opacity 0.3s ease;
    }
    .site-toast.is-error {
        background: var(--coral);
    }
    .site-toast.is-hiding {
        opacity: 0;
    }
    @media (max-width: 767.98px) {
        .site-toast {
            bottom: calc(116px + env(safe-area-inset-bottom));
        }
    }

    /* ---- PC のヘッダーの真ん中（下タブと同じ行き先）。スマホは画面下の下タブを使うので出さない ---- */
    .site-header-nav {
        display: none;
    }
    @media (min-width: 768px) {
        .site-header-nav {
            display: flex;
            align-items: center;
            gap: 4px;
            margin: 0 auto;
        }
        .site-header-nav .site-tab {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            color: var(--sub);
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
        }
        .site-header-nav .site-tab svg {
            width: 18px;
            height: 18px;
        }
        .site-header-nav .site-tab:hover {
            color: var(--navy);
        }
        /* 選択中: 文字はネイビー、下にオレンジの短い線 */
        .site-header-nav .site-tab.is-current {
            color: var(--navy);
        }
        .site-header-nav .site-tab.is-current::after {
            content: "";
            position: absolute;
            bottom: 0;
            left: 50%;
            width: 22px;
            height: 3px;
            border-radius: 999px;
            background: var(--orange);
            transform: translateX(-50%);
        }
        .site-header-record {
            margin-left: 8px;
            padding: 8px 16px;
            border: 0;
            border-radius: 999px;
            background: var(--orange);
            color: #FFFFFF;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
        }
        .site-header-record:hover {
            background: var(--orange-deep);
            color: #FFFFFF;
        }
    }
</style>
<?php
// ---- 画面の切り替え（スマホは下タブ、PC はヘッダーの真ん中）。行き先と、いま開いているページ ----
// いま開いているページのタブ（スクリプトのフォルダとファイル名で決める）
$site_script_path = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
$site_script_name = basename($site_script_path);
$site_script_dir = basename(dirname($site_script_path));
$site_active_tab = '';
if ($site_script_dir === 'front' && in_array($site_script_name, ['training_diary.php', 'training_member.php'], true)) {
    $site_active_tab = 'everyone';
} elseif ($site_script_dir === 'front' && in_array($site_script_name, ['club.php', 'entry_form.php', 'records.php', 'team_details.php'], true)) {
    $site_active_tab = 'club';
} elseif ($site_script_dir === 'mypage' && $site_script_name === 'next_pr.php') {
    $site_active_tab = 'next_pr';
} elseif ($site_script_name === 'index.php' && !in_array($site_script_dir, ['front', 'mypage'], true)) {
    $site_active_tab = 'home';
}
$site_login_url = $base_path . '/mypage/login.php';
$site_tabs = [
    'home' => ['ホーム', $home_url, '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/>'],
    'everyone' => ['みんな', $training_diary_url, '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7"/><path d="M18 14a6.5 6.5 0 0 1 3.5 6"/>'],
    'next_pr' => ['NEXT PR', $base_path . '/mypage/next_pr.php', '<path d="M6 21V4h11l-2 4 2 4H6"/>'],
    'club' => ['クラブ', $base_path . '/front/club.php', '<path d="M12 3 4 6v6c0 4.5 3.4 8.2 8 9 4.6-.8 8-4.5 8-9V6z"/>'],
];
$site_tab_link = static function (string $key) use ($site_tabs, $site_active_tab): string {
    [$label, $url, $icon] = $site_tabs[$key];
    $current = $site_active_tab === $key;
    return '<a class="site-tab' . ($current ? ' is-current' : '') . '" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"'
        . ($current ? ' aria-current="page" data-tab-current' : '') . '>'
        . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $icon . '</svg>'
        . '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span></a>';
};
?>
<link href="<?php echo htmlspecialchars($base_path . '/css/site_layout.css?v=' . (int)@filemtime(__DIR__ . '/../css/site_layout.css'), ENT_QUOTES, 'UTF-8'); ?>" rel="stylesheet">
<header class="site-header">
    <div class="site-header-inner">
        <div class="site-brand">
            <?php // 仕様書: サイトアイコン 30px（角丸8px）＋「TOBG-RUNNING」。押すとホームへ ?>
            <a href="<?php echo htmlspecialchars($home_url, ENT_QUOTES, 'UTF-8'); ?>" class="site-brand-link" aria-label="TOBG-RUNNING ホーム">
                <img class="site-brand-icon" src="<?php echo htmlspecialchars($base_path . '/icons/favicon.png', ENT_QUOTES, 'UTF-8'); ?>" alt="" width="30" height="30">
                <span class="site-brand-text">TOBG-RUNNING</span>
            </a>
            <?php // $access_count_24h は管理者のときだけ値が入る（上の集計処理を参照） ?>
            <?php if ($access_count_24h !== null): ?>
                <span class="site-access-count" title="全ページの表示回数の合計です。集計開始から24時間たつと0に戻ります。">表示回数<strong><?php echo (int)$access_count_24h; ?></strong></span>
            <?php endif; ?>
        </div>

        <?php // PC だけ: 下タブと同じ行き先を、ヘッダーの真ん中に並べる（スマホは画面下の下タブ） ?>
        <nav class="site-header-nav" aria-label="メインメニュー（PC）">
            <?php echo $site_tab_link('home'); ?>
            <?php echo $site_tab_link('everyone'); ?>
            <?php echo $site_tab_link('next_pr'); ?>
            <?php echo $site_tab_link('club'); ?>
            <?php if ($site_is_guest): ?>
                <a class="site-header-record" href="<?php echo htmlspecialchars($site_login_url . '?post_diary=1', ENT_QUOTES, 'UTF-8'); ?>">＋ 記録する</a>
            <?php else: ?>
                <button type="button" class="site-header-record" data-bs-toggle="modal" data-bs-target="#diaryPostModal">＋ 記録する</button>
            <?php endif; ?>
        </nav>

        <div class="site-user-actions">
            <?php if (!$site_is_guest): ?>
                <button type="button" class="site-bell-btn" id="siteBellToggle" aria-controls="siteNotificationPanel" aria-expanded="false" aria-label="お知らせ<?php echo $site_unread_count > 0 ? '（未読' . $site_unread_count . '件）' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    <span class="site-bell-count" id="siteBellCount" aria-hidden="true"<?php echo $site_unread_count > 0 ? '' : ' hidden'; ?>><?php echo $site_unread_count > 99 ? '99+' : $site_unread_count; ?></span>
                </button>
                <a href="<?php echo htmlspecialchars($mypage_url, ENT_QUOTES, 'UTF-8'); ?>" class="site-user-pill" aria-label="マイページへ移動">
                    <?php if ($site_user_avatar_path !== ''): ?>
                        <img src="<?php echo htmlspecialchars($base_path . '/' . ltrim($site_user_avatar_path, '/'), ENT_QUOTES, 'UTF-8'); ?>" alt="" class="site-user-avatar">
                    <?php else: ?>
                        <?php // 写真がないときは、藤色の丸に名前の頭2文字 ?>
                        <span class="site-user-initials" aria-hidden="true"><?php echo htmlspecialchars(mb_substr($site_user_name, 0, 2), ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                    <span class="site-user-pill-name"><?php echo htmlspecialchars($site_user_name, ENT_QUOTES, 'UTF-8'); ?></span>
                </a>
            <?php else: ?>
                <a href="<?php echo htmlspecialchars($base_path . '/mypage/login.php', ENT_QUOTES, 'UTF-8'); ?>" class="site-login-btn">ログイン</a>
            <?php endif; ?>
        </div>

        <?php if (!$site_is_guest): ?>
            <section class="site-notification-panel" id="siteNotificationPanel" aria-labelledby="siteNotificationTitle" data-read-url="<?php echo htmlspecialchars($notification_read_url, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="site-notification-head">
                    <h2 class="site-notification-title" id="siteNotificationTitle">お知らせ</h2>
                    <button type="button" class="site-notification-read-all" id="siteNotificationReadAll"<?php echo $site_unread_count > 0 ? '' : ' hidden'; ?>>すべて既読にする</button>
                </div>
                <?php if (empty($site_notifications)): ?>
                    <p class="site-notification-empty">新しいお知らせはありません。</p>
                <?php else: ?>
                    <ul class="site-notification-list">
                        <?php foreach ($site_notifications as $site_notification): ?>
                            <?php $site_notification_href = $site_notification['link_path'] !== null && $site_notification['link_path'] !== '' ? $base_path . '/' . ltrim((string)$site_notification['link_path'], '/') : '#'; ?>
                            <?php $site_notification_reply_path = notification_reply_path($site_notification); ?>
                            <?php // お知らせにボタンが付いていれば、タイトルの横に出す（返信ボタンと同じ位置） ?>
                            <?php $site_notification_button = $site_notification['source_type'] === NOTIFICATION_SOURCE_ANNOUNCEMENT ? announcement_link($site_notification['link_url'] ?? null, $site_notification['link_label'] ?? null) : null; ?>
                            <?php $site_notification_body = trim((string)($site_notification['body'] ?? '')); ?>
                            <?php $site_notification_classes = 'site-notification-link' . ($site_notification['is_read'] ? '' : ' is-unread') . ($site_notification_reply_path !== null ? ' has-reply' : '') . ($site_notification_button !== null ? ' has-link-action' : ''); ?>
                            <li class="site-notification-item">
                                <?php if ($site_notification_body !== ''): ?>
                                    <?php // 中身（コメント本文）がある通知は、押すとその場で開くアコーディオンにする ?>
                                    <button type="button" class="<?php echo $site_notification_classes; ?> is-toggle" data-notification-id="<?php echo (int)$site_notification['id']; ?>" aria-expanded="false" aria-controls="siteNotificationDetail<?php echo (int)$site_notification['id']; ?>">
                                        <span class="site-notification-text"><?php if (!empty($site_notification['is_important'])): ?><span class="site-important-badge">重要</span><?php endif; ?><?php echo htmlspecialchars((string)$site_notification['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span class="site-notification-time-row">
                                            <span class="site-notification-time"><?php echo htmlspecialchars(format_notification_time((string)$site_notification['created_at']), ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span class="site-notification-toggle-hint">コメントを見る<span class="site-notification-chevron" aria-hidden="true">▼</span></span>
                                        </span>
                                    </button>
                                    <div class="site-notification-detail" id="siteNotificationDetail<?php echo (int)$site_notification['id']; ?>" hidden>
                                        <p class="site-notification-body"><?php echo nl2br(htmlspecialchars($site_notification_body, ENT_QUOTES, 'UTF-8')); ?></p>
                                        <?php if ($site_notification_href !== '#'): ?>
                                            <a class="site-notification-open" href="<?php echo htmlspecialchars($site_notification_href, ENT_QUOTES, 'UTF-8'); ?>">日記を開く ›</a>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <a href="<?php echo htmlspecialchars($site_notification_href, ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $site_notification_classes; ?>" data-notification-id="<?php echo (int)$site_notification['id']; ?>">
                                        <span class="site-notification-text"><?php if (!empty($site_notification['is_important'])): ?><span class="site-important-badge">重要</span><?php endif; ?><?php echo htmlspecialchars((string)$site_notification['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span class="site-notification-time"><?php echo htmlspecialchars(format_notification_time((string)$site_notification['created_at']), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </a>
                                <?php endif; ?>
                                <?php if ($site_notification_reply_path !== null): ?>
                                    <?php // リンクの中にボタンは置けないので、行の右側に別のリンクとして重ねる ?>
                                    <a href="<?php echo htmlspecialchars($base_path . '/' . ltrim($site_notification_reply_path, '/'), ENT_QUOTES, 'UTF-8'); ?>" class="site-notification-reply" data-reply-for="<?php echo (int)$site_notification['id']; ?>">返信する</a>
                                <?php elseif ($site_notification_button !== null): ?>
                                    <?php // サイト外の行き先はそのまま新しいタブで、サイト内はサイトの一番上からの場所で開く ?>
                                    <a href="<?php echo htmlspecialchars($site_notification_button['external'] ? $site_notification_button['href'] : $base_path . '/' . ltrim($site_notification_button['href'], '/'), ENT_QUOTES, 'UTF-8'); ?>"<?php echo $site_notification_button['external'] ? ' target="_blank" rel="noopener noreferrer"' : ''; ?> class="site-notification-reply is-link-action" data-reply-for="<?php echo (int)$site_notification['id']; ?>"><?php echo htmlspecialchars($site_notification_button['label'], ENT_QUOTES, 'UTF-8'); ?></a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($site_vapid_public_key !== ''): ?>
                    <?php // プッシュ通知の設定。端末の状態（未購読・購読中・拒否・非対応）は下のスクリプトが判定して出し分ける ?>
                    <div class="site-push-settings" id="sitePushSettings" data-vapid-key="<?php echo htmlspecialchars($site_vapid_public_key, ENT_QUOTES, 'UTF-8'); ?>" data-subscription-url="<?php echo htmlspecialchars($push_subscription_url, ENT_QUOTES, 'UTF-8'); ?>" hidden>
                        <p class="site-push-status" id="sitePushStatus"></p>
                        <div class="site-push-actions">
                            <button type="button" class="site-push-button is-off" id="sitePushTestButton" hidden>テスト通知</button>
                            <button type="button" class="site-push-button" id="sitePushButton" hidden></button>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

    </div>
</header>
<?php
// ---- PC の左メニュー（幅の広い画面だけ。見た目は css/site_layout.css） ----
// 上から: 自分（アイコン・名前・所属クラブ。押すとホーム）→ 画面の切り替え →「練習を記録する」→ 管理者だけ「管理メニュー」→ ログアウト
$site_side_club_label = '';
if (!$site_is_guest && file_exists($header_db)) {
    try {
        require_once $header_db;
        require_once __DIR__ . '/../back/member_club_lib.php';
        $site_side_club = fetch_member_club($pdo, $site_user_id);
        $site_side_club_label = $site_side_club !== null ? MEMBER_CLUB_LABELS[$site_side_club] : '';
    } catch (Throwable $site_error) {
        $site_side_club_label = '';
    }
}
if ($site_side_club_label === '' && !$site_is_guest) {
    $site_side_club_label = member_role_label($site_user_role);
}
// 管理メニューは、マイページの管理画面の各欄へのリンク。マイページを開いているときは、その場で欄を開く（mypage/front_login.php の処理）
$site_admin_menu = [];
if ($site_is_admin) {
    require_once __DIR__ . '/../back/admin_menu_lib.php';
    $site_admin_menu = admin_menu_items();
}
?>
<aside class="site-side" aria-label="メニュー（PC）">
    <?php if ($site_is_guest): ?>
        <a class="site-side-login" href="<?php echo htmlspecialchars($site_login_url, ENT_QUOTES, 'UTF-8'); ?>">ログイン</a>
    <?php else: ?>
        <?php // 自分（アイコン・名前・所属クラブ）。押すとホームへ（マイページはヘッダーの右上のアイコンから） ?>
        <a class="site-side-me" href="<?php echo htmlspecialchars($home_url, ENT_QUOTES, 'UTF-8'); ?>" aria-label="ホームへ">
            <?php if ($site_user_avatar_path !== ''): ?>
                <img src="<?php echo htmlspecialchars($base_path . '/' . ltrim($site_user_avatar_path, '/'), ENT_QUOTES, 'UTF-8'); ?>" alt="" class="site-side-avatar">
            <?php else: ?>
                <span class="site-side-avatar is-initials" aria-hidden="true"><?php echo htmlspecialchars(mb_substr($site_user_name, 0, 2), ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
            <span class="site-side-me-text">
                <strong><?php echo htmlspecialchars($site_user_name, ENT_QUOTES, 'UTF-8'); ?></strong>
                <small><?php echo htmlspecialchars($site_side_club_label, ENT_QUOTES, 'UTF-8'); ?></small>
            </span>
        </a>
    <?php endif; ?>
    <nav class="site-side-nav" aria-label="メインメニュー（PC）">
        <?php echo $site_tab_link('home'); ?>
        <?php echo $site_tab_link('everyone'); ?>
        <?php echo $site_tab_link('next_pr'); ?>
        <?php echo $site_tab_link('club'); ?>
    </nav>
    <?php // 練習を記録する（画面のメニューの下） ?>
    <?php $site_record_icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>'; ?>
    <?php if ($site_is_guest): ?>
        <a class="site-side-record" href="<?php echo htmlspecialchars($site_login_url . '?post_diary=1', ENT_QUOTES, 'UTF-8'); ?>"><?php echo $site_record_icon; ?>練習を記録する</a>
    <?php else: ?>
        <button type="button" class="site-side-record" data-bs-toggle="modal" data-bs-target="#diaryPostModal"><?php echo $site_record_icon; ?>練習を記録する</button>
    <?php endif; ?>
    <?php if (!empty($site_admin_menu)): ?>
        <p class="site-side-sec">管理メニュー</p>
        <nav class="site-side-admin" aria-label="管理メニュー（PC）">
            <?php // ダッシュボード（マイページの上のチームの状況）。マイページでは、開いている機能を閉じてそこへ戻る ?>
            <a class="site-side-admin-item" href="<?php echo htmlspecialchars($base_path . '/mypage/front_login.php#adminDashboard', ENT_QUOTES, 'UTF-8'); ?>" data-admin-dashboard>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>
                <span class="admin-menu-label">ダッシュボード</span>
            </a>
            <?php foreach ($site_admin_menu as [$site_admin_target, $site_admin_label, $site_admin_paths]): ?>
                <a class="site-side-admin-item" href="<?php echo htmlspecialchars($base_path . '/mypage/front_login.php#' . $site_admin_target, ENT_QUOTES, 'UTF-8'); ?>" data-admin-target="<?php echo $site_admin_target; ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?php echo $site_admin_paths; ?></svg>
                    <span class="admin-menu-label"><?php echo htmlspecialchars($site_admin_label, ENT_QUOTES, 'UTF-8'); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
    <?php if (!$site_is_guest): ?>
        <?php // ログアウトは左メニューのいちばん下（下に押し下げて、ほかのメニューと離す） ?>
        <a class="site-side-logout" href="<?php echo htmlspecialchars($base_path . '/mypage/logout.php', ENT_QUOTES, 'UTF-8'); ?>">ログアウト</a>
    <?php endif; ?>
</aside>
<?php
// ---- PC の右の補助欄（ログイン中。マイページの画面とログインまわりでは出さない。NEXT PR には出す） ----
$site_show_rail = !$site_is_guest && file_exists($header_db)
    && !($site_script_dir === 'mypage' && $site_script_name !== 'next_pr.php');
if ($site_show_rail) {
    try {
        require_once $header_db;
        require __DIR__ . '/side_rail.php';
    } catch (Throwable $site_error) {
        error_log('side rail failed: ' . $site_error->getMessage());
    }
}
?>
<?php // ---- 下タブ（スマホのみ）・練習日記の登録シート・トースト（全ページ共通） ---- ?>
<nav class="site-tabbar" aria-label="メインメニュー">
    <?php echo $site_tab_link('home'); ?>
    <?php echo $site_tab_link('everyone'); ?>
    <?php // 真ん中の「記録する」は画面を移動せず、下から登録シートを開く（未ログインはログイン画面へ） ?>
    <?php if ($site_is_guest): ?>
        <a class="site-tab site-tab-record" href="<?php echo htmlspecialchars($site_login_url . '?post_diary=1', ENT_QUOTES, 'UTF-8'); ?>">
    <?php else: ?>
        <button type="button" class="site-tab site-tab-record" data-bs-toggle="modal" data-bs-target="#diaryPostModal">
    <?php endif; ?>
        <span class="site-tab-plus" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg></span>
        <span>記録する</span>
    <?php echo $site_is_guest ? '</a>' : '</button>'; ?>
    <?php echo $site_tab_link('next_pr'); ?>
    <?php echo $site_tab_link('club'); ?>
</nav>
<?php
// 練習日記の登録シート（ログイン中のみ。どのページからでも同じシートで投稿できる）
if (!$site_is_guest && file_exists($header_db)) {
    try {
        require_once $header_db;
        $diary_sheet_base = $base_path === '/' ? '' : $base_path;
        require __DIR__ . '/diary_post_sheet.php';
    } catch (Throwable $site_error) {
        error_log('diary sheet failed: ' . $site_error->getMessage());
    }
}

// 投稿などの完了・エラーを、画面の下に短く出す（トースト）。マイページは自分のページ内に出すので、ここには来ない
// 練習日記（training_*）と大会の申し込み（entry_*。クラブ画面からの取り消しなど）の知らせ
$site_toast = null;
foreach (['training_error' => true, 'entry_error' => true, 'club_error' => true, 'training_flash' => false, 'entry_flash' => false, 'club_flash' => false] as $site_toast_key => $site_toast_is_error) {
    if ($site_toast === null && !empty($_SESSION[$site_toast_key])) {
        $site_toast = ['text' => (string)$_SESSION[$site_toast_key], 'error' => $site_toast_is_error];
    }
}
unset($_SESSION['training_flash'], $_SESSION['training_error'], $_SESSION['entry_flash'], $_SESSION['entry_error'], $_SESSION['club_flash'], $_SESSION['club_error']);
?>
<?php if ($site_toast !== null): ?>
    <div class="site-toast<?php echo $site_toast['error'] ? ' is-error' : ''; ?>" role="status" aria-live="polite" data-site-toast><?php echo htmlspecialchars($site_toast['text'], ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>
<script>
(() => {
    // 今いるタブをもう一度押したら、ページの一番上へ戻る
    document.querySelectorAll('[data-tab-current]').forEach((tab) => {
        tab.addEventListener('click', (event) => {
            event.preventDefault();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    });
    // トーストは 2.6 秒で消す
    const toast = document.querySelector('[data-site-toast]');
    if (toast) {
        setTimeout(() => toast.classList.add('is-hiding'), 2600);
        toast.addEventListener('transitionend', () => toast.remove());
    }
})();
</script>
<script>
(() => {
    // ヘッダーの開閉パネル（通知）
    const dropdowns = [
        { toggle: document.getElementById('siteBellToggle'), panel: document.getElementById('siteNotificationPanel') },
    ].filter((item) => item.toggle && item.panel);

    const setOpen = (item, isOpen) => {
        item.panel.classList.toggle('is-open', isOpen);
        item.toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    };
    const closeAll = () => dropdowns.forEach((item) => setOpen(item, false));

    dropdowns.forEach((item) => {
        item.toggle.addEventListener('click', () => {
            const willOpen = !item.panel.classList.contains('is-open');
            closeAll();
            setOpen(item, willOpen);
        });
    });

    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        if (dropdowns.some((item) => item.panel.contains(target) || item.toggle.contains(target))) {
            return;
        }
        closeAll();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeAll();
        }
    });

    // ---- 通知の既読 ----
    const notificationPanel = document.getElementById('siteNotificationPanel');
    if (!notificationPanel) {
        return;
    }
    const readUrl = notificationPanel.getAttribute('data-read-url');
    const bellToggle = document.getElementById('siteBellToggle');
    const bellCount = document.getElementById('siteBellCount');
    const readAllButton = document.getElementById('siteNotificationReadAll');

    const sendRead = (params) => {
        const body = new URLSearchParams(params);
        // keepalive: 通知をタップしてすぐ別ページへ移っても、既読の送信を最後まで行う
        return fetch(readUrl, { method: 'POST', body, credentials: 'same-origin', keepalive: true })
            .then((response) => response.ok)
            .catch(() => false);
    };

    const refreshUnreadState = () => {
        const unreadCount = notificationPanel.querySelectorAll('.site-notification-link.is-unread').length;
        const hasUnread = unreadCount > 0;
        if (bellCount) {
            bellCount.hidden = !hasUnread;
            bellCount.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
        }
        if (bellToggle) {
            bellToggle.setAttribute('aria-label', hasUnread ? 'お知らせ（未読' + unreadCount + '件）' : 'お知らせ');
        }
        if (readAllButton) {
            readAllButton.hidden = !hasUnread;
        }
    };

    // 中身のある通知（コメント）: 押すとその場で開閉し、開いたら既読にする（ページは移動しない）
    notificationPanel.querySelectorAll('button.site-notification-link.is-toggle').forEach((toggle) => {
        toggle.addEventListener('click', () => {
            const detail = document.getElementById(toggle.getAttribute('aria-controls'));
            const willOpen = toggle.getAttribute('aria-expanded') !== 'true';
            toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            if (detail) {
                detail.hidden = !willOpen;
            }
            if (willOpen && toggle.classList.contains('is-unread')) {
                toggle.classList.remove('is-unread');
                refreshUnreadState();
                sendRead({ action: 'read', notification_id: toggle.getAttribute('data-notification-id') });
            }
        });
    });

    // それ以外の通知: 既読にしてからリンク先へ移る
    notificationPanel.querySelectorAll('a.site-notification-link').forEach((link) => {
        link.addEventListener('click', (event) => {
            if (!link.classList.contains('is-unread')) {
                return;
            }
            link.classList.remove('is-unread');
            refreshUnreadState();
            const request = sendRead({ action: 'read', notification_id: link.getAttribute('data-notification-id') });
            // リンク先がない通知は、既読にするだけでページは移動しない
            if (link.getAttribute('href') === '#') {
                event.preventDefault();
                return;
            }
            event.preventDefault();
            request.finally(() => {
                window.location.href = link.href;
            });
        });
    });

    // 「返信する」・お知らせのボタン: その通知も既読にしてから、ボタンの行き先へ移る
    notificationPanel.querySelectorAll('.site-notification-reply').forEach((replyLink) => {
        replyLink.addEventListener('click', (event) => {
            const notificationId = replyLink.getAttribute('data-reply-for');
            const mainLink = notificationPanel.querySelector('.site-notification-link[data-notification-id="' + notificationId + '"]');
            if (!mainLink || !mainLink.classList.contains('is-unread')) {
                return;
            }
            mainLink.classList.remove('is-unread');
            refreshUnreadState();
            event.preventDefault();
            sendRead({ action: 'read', notification_id: notificationId }).finally(() => {
                window.location.href = replyLink.href;
            });
        });
    });

    if (readAllButton) {
        readAllButton.addEventListener('click', () => {
            sendRead({ action: 'read_all' }).then((ok) => {
                if (!ok) {
                    return;
                }
                notificationPanel.querySelectorAll('.site-notification-link.is-unread').forEach((link) => {
                    link.classList.remove('is-unread');
                });
                refreshUnreadState();
            });
        });
    }
})();
</script>
<?php if ($site_is_guest): ?>
<script>
// 未ログインの人がログイン画面へのリンクを押したとき、はじめてならその手前でサービス紹介（mypage/welcome.php）を出す。
// 見たかどうかはブラウザの localStorage の onboarding_seen で決める（記録できないブラウザでは出さずにそのままログイン画面へ）
(() => {
    let seen = true;
    try {
        seen = localStorage.getItem('onboarding_seen') === 'true';
    } catch (error) {
        seen = true;
    }
    if (seen) {
        return;
    }
    const welcomeUrl = <?php echo json_encode($base_path . '/mypage/welcome.php', JSON_UNESCAPED_SLASHES); ?>;
    document.addEventListener('click', (event) => {
        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || link.target === '_blank') {
            return;
        }
        const url = new URL(link.href, location.href);
        if (url.origin !== location.origin || !/\/mypage\/login\.php$/.test(url.pathname)) {
            return;
        }
        event.preventDefault();
        location.href = welcomeUrl + '?next=' + encodeURIComponent(url.pathname + url.search + url.hash);
    });
})();
</script>
<?php endif; ?>
<script>
// PWA: サービスワーカーを登録する（プッシュ通知の受け取りに使う）。
// サービスワーカーは HTTPS か手元の環境（localhost）でしか動かないので、そのときだけ登録する
(() => {
    if (!('serviceWorker' in navigator)) {
        return;
    }
    const isLocal = ['localhost', '127.0.0.1'].includes(location.hostname);
    if (location.protocol !== 'https:' && !isLocal) {
        return;
    }
    const basePath = <?php echo json_encode($base_path, JSON_UNESCAPED_SLASHES); ?>;
    const isGuest = <?php echo $site_is_guest ? 'true' : 'false'; ?>;
    navigator.serviceWorker.register(basePath + '/sw.js', { scope: basePath + '/' }).then(async (registration) => {
        // ログアウトした端末に前の会員宛ての通知が届かないよう、購読を解除する
        // （サーバーには次の送信時に「410 届け先なし」が返り、そこで購読が消える）
        if (isGuest && 'pushManager' in registration) {
            const subscription = await registration.pushManager.getSubscription();
            if (subscription) {
                await subscription.unsubscribe();
            }
        }
    }).catch(() => {
        // 登録に失敗しても、サイトはそのまま使える
    });
})();
</script>
<script>
// プッシュ通知の設定（通知パネルの一番下）。端末の状態に合わせて案内とボタンを出し分ける
(() => {
    const box = document.getElementById('sitePushSettings');
    if (!box) {
        return;
    }
    const statusText = document.getElementById('sitePushStatus');
    const button = document.getElementById('sitePushButton');
    const testButton = document.getElementById('sitePushTestButton');
    const vapidKey = box.dataset.vapidKey;
    const subscriptionUrl = box.dataset.subscriptionUrl;

    const isIos = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
    const isSecure = location.protocol === 'https:' || ['localhost', '127.0.0.1'].includes(location.hostname);
    const supported = isSecure && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

    const show = (text, buttonText, isOff, canTest) => {
        box.hidden = false;
        testButton.hidden = !canTest;
        statusText.textContent = text;
        button.hidden = !buttonText;
        button.textContent = buttonText || '';
        button.classList.toggle('is-off', Boolean(isOff));
    };

    if (!supported) {
        // iPhone・iPad の Safari は、ホーム画面に追加したアプリでだけ通知を受け取れる
        if (isIos && !isStandalone) {
            show('iPhone・iPadでは、ホーム画面に追加したアプリから通知を受け取れます（共有ボタン →「ホーム画面に追加」）。');
        }
        return;
    }

    // 公開鍵（base64url）を、ブラウザの購読に渡す形にする
    const keyToBytes = (base64url) => {
        const base64 = (base64url + '='.repeat((4 - base64url.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from(atob(base64), (c) => c.charCodeAt(0));
    };

    const postSubscription = (action, subscription) => {
        const json = subscription.toJSON();
        return fetch(subscriptionUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, endpoint: json.endpoint, keys: json.keys || {} }),
        }).then((response) => response.ok);
    };

    // 自分で「オフにする」を押した端末の印。ログアウトで外れた購読は、通知の許可が残っていればログイン時に自動で戻すが、
    // この印がある端末は戻さない（端末ごとに覚える。読めない環境では印なしとして扱う）
    const optOutKey = 'tobg-push-opted-out';
    const isOptedOut = () => {
        try {
            return localStorage.getItem(optOutKey) === '1';
        } catch (error) {
            return false;
        }
    };
    const setOptedOut = (optedOut) => {
        try {
            if (optedOut) {
                localStorage.setItem(optOutKey, '1');
            } else {
                localStorage.removeItem(optOutKey);
            }
        } catch (error) {
            // 覚えられなくても通知の設定そのものは変わる
        }
    };

    let registration = null;

    // この端末を購読し、サーバーにも登録する。保存できなければ購読を取り消して false
    const subscribeDevice = async () => {
        const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: keyToBytes(vapidKey),
        });
        if (await postSubscription('subscribe', subscription)) {
            return true;
        }
        await subscription.unsubscribe();
        return false;
    };

    const render = async () => {
        if (Notification.permission === 'denied') {
            show('通知がブラウザの設定で拒否されています。設定から許可すると受け取れます。');
            return;
        }
        const subscription = await registration.pushManager.getSubscription();
        if (subscription) {
            show('この端末でプッシュ通知を受け取っています。', 'オフにする', true, true);
        } else {
            show('アプリのように、この端末に通知を届けます。', 'プッシュ通知を有効にする', false);
        }
    };

    // テスト通知: サーバーが少し待ってからこの端末に送る。その間にホーム画面に戻ると、バナーで届くか確かめられる
    testButton.addEventListener('click', async () => {
        const current = await registration.pushManager.getSubscription();
        if (!current) {
            await render();
            return;
        }
        testButton.disabled = true;
        statusText.textContent = '5秒後にテスト通知を送ります。今のうちにホーム画面に戻ると、バナーで届くか確認できます。';
        try {
            const response = await fetch(subscriptionUrl, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'test', endpoint: current.toJSON().endpoint }),
            });
            const result = await response.json();
            alert(result.message || 'テスト通知の結果を受け取れませんでした。');
        } catch (error) {
            // アプリを閉じている間に通信が切れた場合など。通知自体はサーバーから送られている
        } finally {
            testButton.disabled = false;
            await render();
        }
    });

    button.addEventListener('click', async () => {
        button.disabled = true;
        try {
            const current = await registration.pushManager.getSubscription();
            if (current) {
                await postSubscription('unsubscribe', current);
                await current.unsubscribe();
                setOptedOut(true);
            } else {
                const permission = await Notification.requestPermission();
                if (permission === 'granted') {
                    if (await subscribeDevice()) {
                        setOptedOut(false);
                    } else {
                        alert('通知の設定を保存できませんでした。時間を置いて再度お試しください。');
                    }
                }
            }
        } catch (error) {
            alert('通知の設定に失敗しました。時間を置いて再度お試しください。');
        } finally {
            button.disabled = false;
            await render();
        }
    });

    navigator.serviceWorker.ready.then(async (ready) => {
        registration = ready;
        // すでに購読している端末は、念のためサーバー側にも登録し直す（別の会員でログインし直した場合など）
        const existing = await registration.pushManager.getSubscription();
        if (existing) {
            postSubscription('subscribe', existing).catch(() => {});
        } else if (Notification.permission === 'granted' && !isOptedOut()) {
            // ログアウトで外れた購読を、通知の許可が残っている端末ではログインしたときに自動で戻す（確認の画面は出ない）
            try {
                await subscribeDevice();
            } catch (error) {
                // 戻せなくても、通知パネルの「プッシュ通知を有効にする」から手で有効にできる
            }
        }
        await render();
    });
})();
</script>
