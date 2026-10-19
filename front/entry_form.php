<?php
require_once __DIR__ . '/../back/session_bootstrap.php';
if (session_status() === PHP_SESSION_NONE) {
    tobg_start_session();
}

if (!isset($_SESSION['user_id'], $_SESSION['user_name'])) {
    header('Location: ../mypage/login.php');
    exit;
}

require_once __DIR__ . '/../back/db_connect.php';
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

function ensure_tournament_entry_deadline_column(PDO $pdo): void
{
    $stmt = $pdo->query("SHOW COLUMNS FROM tournaments LIKE 'entry_deadline'");
    $has_entry_deadline = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$has_entry_deadline) {
        $pdo->exec("ALTER TABLE tournaments ADD COLUMN entry_deadline DATE NULL AFTER event_date");
    }
}

function ensure_tournament_deadline_lead_days_column(PDO $pdo): void
{
    $stmt = $pdo->query("SHOW COLUMNS FROM tournaments LIKE 'deadline_lead_days'");
    $has_lead_days = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$has_lead_days) {
        $pdo->exec("ALTER TABLE tournaments ADD COLUMN deadline_lead_days TINYINT UNSIGNED NOT NULL DEFAULT 3 AFTER entry_deadline");
    }
}

$tournament_id = isset($_GET['tournament_id']) ? (int)$_GET['tournament_id'] : 0;
$tournament = null;
$error_message = '';
$is_entry_allowed = true;

function find_first_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

try {
    cleanup_expired_tournament_data($pdo);

    $col_stmt = $pdo->query('SHOW COLUMNS FROM members');
    $member_columns = array_column($col_stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $jaaf_id_col = find_first_column($member_columns, ['jaaf_id', 'jaafid', 'jaaf_no']);
    $number_plate_col = find_first_column($member_columns, ['number_plate', 'bib_number', 'zekken_no']);

    if ($jaaf_id_col !== null && $number_plate_col !== null) {
        $profile_sql = "SELECT {$jaaf_id_col} AS jaaf_id_value, {$number_plate_col} AS number_plate_value FROM members WHERE id = ? LIMIT 1";
        $profile_stmt = $pdo->prepare($profile_sql);
        $profile_stmt->execute([(int)$_SESSION['user_id']]);
        $profile = $profile_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        if (trim((string)($profile['jaaf_id_value'] ?? '')) === '0000' && trim((string)($profile['number_plate_value'] ?? '')) === '0000') {
            $is_entry_allowed = false;
        }
    }

    ensure_tournament_entry_deadline_column($pdo);
    ensure_tournament_deadline_lead_days_column($pdo);

    $stmt = $pdo->prepare(
        "SELECT
            id,
            event_date,
            DATE_SUB(COALESCE(entry_deadline, event_date), INTERVAL COALESCE(deadline_lead_days, 3) DAY) AS member_deadline,
            event_name,
            location,
            details
         FROM tournaments
         WHERE id = ?"
    );
    $stmt->execute([$tournament_id]);
    $tournament = $stmt->fetch();
} catch (PDOException $e) {
    $error_message = '大会データの取得に失敗しました。';
}

if (!$tournament) {
    $error_message = $error_message ?: '指定された大会が見つかりません。';
}

$is_deadline_passed = !empty($tournament['member_deadline'])
    && strtotime((string)$tournament['member_deadline']) < strtotime(date('Y-m-d'));

$logged_in_name = $_SESSION['user_name'] ?? '';
// 応募記録の初期値にする NEXT PR の自己ベスト
require_once __DIR__ . '/../back/entry_event_lib.php';
$entry_pb_values = fetch_entry_pb_values($pdo, (int)$_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>大会詳細・申し込み | TOBG</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;700;800;900&family=Zen+Kaku+Gothic+New:wght@400;500;700;900&display=swap" rel="stylesheet">
    <link href="../css/theme.css?v=<?php echo (int)@filemtime(__DIR__ . '/../css/theme.css'); ?>" rel="stylesheet">
    <link href="../css/entry_event.css?v=<?php echo (int)@filemtime(__DIR__ . '/../css/entry_event.css'); ?>" rel="stylesheet">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#F4EEE4">
    <meta name="apple-mobile-web-app-title" content="TOBG-RUNNING">
    <link rel="apple-touch-icon" href="../icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" href="../icons/favicon.png">
    <style>
        body { background: var(--color-page-bg); }
        .page-wrap { max-width: var(--content-max-width); margin: 0 auto; padding: 24px var(--page-gutter) 40px; }
        .detail-card, .form-card { border: 0; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08); }
        .section-title { font-size: 1.7rem; font-weight: 800; color: #0f172a; margin: 24px 0 18px; }
        .detail-meta { color: #64748b; font-size: 0.95rem; }
        .detail-text { line-height: 1.8; color: #334155; white-space: pre-wrap; }
        .apply-deadline-note { color: #dc2626; font-weight: 800; font-size: 0.95rem; }
        .form-label { font-weight: 700; }
        .required-badge { font-size: 0.75rem; }
    </style>
</head>
<body>
<?php
$shared_header = __DIR__ . '/../includes/site_header.php';
if (file_exists($shared_header)) {
    require_once $shared_header;
} else {
    echo '<div style="background:var(--color-header);color:#fff;padding:12px 16px;font-weight:700;">TEAM OFFICIAL</div>';
}
?>

<div class="page-wrap">
    <?php if ($error_message): ?>
        <div class="alert alert-warning mb-4"><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php elseif (!$is_entry_allowed): ?>
        <div class="alert alert-warning mb-4">JAAF未登録の会員は試合申し込みを利用できません。</div>
        <a href="../mypage/front_login.php#training-diary" class="btn btn-outline-primary">マイページへ戻る</a>
    <?php else: ?>
        <h1 class="section-title">大会詳細・申し込み</h1>
        <div class="d-flex justify-content-end mb-3">
            <a href="club.php?tab=races" class="btn btn-outline-secondary btn-sm">大会一覧に戻る</a>
        </div>

        <div class="card detail-card mb-4">
            <div class="card-body p-4 p-md-5">
                <div class="detail-meta mb-2">
                    <?php echo htmlspecialchars(date('Y/m/d', strtotime($tournament['event_date'])), ENT_QUOTES, 'UTF-8'); ?>
                    ・
                    <?php echo htmlspecialchars($tournament['location'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <h2 class="h3 fw-bold mb-3"><?php echo htmlspecialchars($tournament['event_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                <div class="detail-text"><?php echo nl2br(htmlspecialchars($tournament['details'], ENT_QUOTES, 'UTF-8')); ?></div>
                <?php if (!empty($tournament['member_deadline'])): ?>
                    <div class="apply-deadline-note mt-3">応募期限は<?php echo htmlspecialchars(date('Y/m/d', strtotime((string)$tournament['member_deadline'])), ENT_QUOTES, 'UTF-8'); ?>までです！</div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($is_deadline_passed): ?>
            <div class="alert alert-warning mb-4">この大会は受付期間を過ぎたため、申し込みできません。</div>
        <?php else: ?>
        <div class="card form-card">
            <div class="card-header bg-white py-3 border-bottom">
                <h3 class="h5 mb-0 fw-bold text-dark">申し込みフォーム</h3>
            </div>
            <div class="card-body p-4 p-md-5">
                <form action="../back/save_entry.php" method="POST" onsubmit="return confirm('この内容で申し込みを確定します。よろしいですか？');">
                    <input type="hidden" name="match_title" value="<?php echo htmlspecialchars($tournament['event_name'], ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="alert alert-light border mb-4">
                        <div class="small text-secondary mb-2">大会名</div>
                        <div class="fw-bold"><?php echo htmlspecialchars($tournament['event_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label">ゼッケン番号</label>
                            <input type="text" class="form-control" name="number_plate" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">名前</label>
                            <input type="text" class="form-control" name="user_name" value="<?php echo htmlspecialchars($logged_in_name, ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                    </div>

                    <?php // 種目は 区分 → 種目 の2段階、応募記録は種目に合わせたプルダウン（NEXT PR の自己ベストがあれば最初から入れる） ?>
                    <div class="mt-4">
                        <?php require __DIR__ . '/../includes/entry_event_fields.php'; ?>
                    </div>

                    <div class="d-flex justify-content-end mt-4">
                        <button type="submit" class="btn btn-primary px-4 py-2 fw-bold">申し込みする</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
