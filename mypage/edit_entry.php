<?php
require_once __DIR__ . '/../back/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/../back/db_connect.php';
require_once __DIR__ . '/../back/entry_event_lib.php';
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

if (!isset($_SESSION['user_id'], $_SESSION['user_name'])) {
    header('Location: login.php');
    exit;
}

$user_name = (string)$_SESSION['user_name'];
$entry_id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
$error = '';
$entry = [];

function find_first_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

if ($entry_id <= 0) {
    header('Location: front_login.php');
    exit;
}

try {
    cleanup_expired_tournament_data($pdo);
    ensure_tournament_entry_deadline_column($pdo);
    ensure_tournament_deadline_lead_days_column($pdo);

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
            $_SESSION['entry_error'] = 'JAAF未登録の会員は応募編集を利用できません。';
            header('Location: front_login.php#entry-history');
            exit;
        }
    }

    $stmt = $pdo->prepare(
        "SELECT
            m.*,
            t.event_date,
            DATE_SUB(COALESCE(t.entry_deadline, t.event_date), INTERVAL t.deadline_lead_days DAY) AS deadline_date,
            DATEDIFF(DATE_SUB(COALESCE(t.entry_deadline, t.event_date), INTERVAL t.deadline_lead_days DAY), CURDATE()) AS days_left
         FROM matches m
         LEFT JOIN (
            SELECT event_name, MAX(event_date) AS event_date, MAX(entry_deadline) AS entry_deadline, MAX(COALESCE(deadline_lead_days, 3)) AS deadline_lead_days
            FROM tournaments
            GROUP BY event_name
         ) t ON t.event_name = m.match_title
         WHERE m.id = :id AND m.user_name = :user_name
         LIMIT 1"
    );
    $stmt->execute([
        ':id' => $entry_id,
        ':user_name' => $user_name,
    ]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    if (empty($entry)) {
        header('Location: front_login.php');
        exit;
    }

    $days_left = isset($entry['days_left']) ? (int)$entry['days_left'] : null;
    $can_edit = $days_left !== null && $days_left >= 0;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$can_edit) {
            $error = '変更締切を過ぎているため更新できません。';
        } else {
            // 種目は一覧から、応募記録は種目の形式どおりに選ばれているか確かめる
            try {
                $entry_events = validate_entry_events($_POST);
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
                $entry_events = null;
            }

            if ($entry_events !== null) {
                $update = $pdo->prepare(
                    "UPDATE matches
                     SET
                        event_name1 = :event_name1,
                        pb_time1 = :pb_time1,
                        event_name2 = :event_name2,
                        pb_time2 = :pb_time2,
                        event_name3 = :event_name3,
                        pb_time3 = :pb_time3,
                        status = '未承認'
                     WHERE id = :id AND user_name = :user_name"
                );
                $update->execute([
                    ':event_name1' => $entry_events['event_name1'],
                    ':pb_time1' => $entry_events['pb_time1'],
                    ':event_name2' => $entry_events['event_name2'],
                    ':pb_time2' => $entry_events['pb_time2'],
                    ':event_name3' => $entry_events['event_name3'],
                    ':pb_time3' => $entry_events['pb_time3'],
                    ':id' => $entry_id,
                    ':user_name' => $user_name,
                ]);

                header('Location: front_login.php');
                exit;
            }
        }
    }
} catch (PDOException $e) {
    $error = 'データ取得中にエラーが発生しました。';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>応募情報の編集 | TOBG</title>
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
        body {
            background: var(--color-page-bg);
        }
        .edit-wrap {
            max-width: var(--content-max-width);
            margin: 0 auto;
            padding: 28px var(--page-gutter) 40px;
        }
        .edit-card {
            border: 0;
            border-radius: 14px;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.1);
        }
        .entry-action-bar {
            border-top: 1px solid #e2e8f0;
            padding-top: 12px;
            margin-top: 8px;
        }
        .entry-action-group {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
        }
        .entry-delete-btn {
            margin-left: auto;
        }
        @media (max-width: 576px) {
            .entry-delete-btn {
                margin-left: 0;
            }
        }
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

<div class="edit-wrap">
    <div class="card edit-card">
        <div class="card-header bg-white py-3">
            <h1 class="h5 mb-0 fw-bold">応募情報の編集</h1>
        </div>
        <div class="card-body">
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if (!empty($entry)): ?>
                <?php
                $days_left = isset($entry['days_left']) ? (int)$entry['days_left'] : null;
                ?>
                <div class="mb-3 text-secondary">
                    <div>大会名: <?php echo htmlspecialchars((string)($entry['match_title'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div>
                        変更締切:
                        <?php
                        if (!empty($entry['deadline_date'])) {
                            echo htmlspecialchars(date('Y/m/d', strtotime((string)$entry['deadline_date'])), ENT_QUOTES, 'UTF-8');
                            if ($days_left !== null) {
                                if ($days_left < 0) {
                                    echo '（締切後）';
                                } else if ($days_left === 0) {
                                    echo '（本日まで）';
                                } else {
                                    echo '（あと' . $days_left . '日）';
                                }
                            }
                        } else {
                            echo '未設定';
                        }
                        ?>
                    </div>
                </div>

                <form method="POST" action="edit_entry.php?id=<?php echo (int)$entry_id; ?>">
                    <input type="hidden" name="id" value="<?php echo (int)$entry_id; ?>">
                    <input type="hidden" name="match_id" value="<?php echo (int)$entry_id; ?>">

                    <?php // 種目は 区分 → 種目 の2段階、応募記録は種目に合わせたプルダウン。今の内容を選んだ状態で出す ?>
                    <div class="mb-4">
                        <?php $entry_values = $entry; require __DIR__ . '/../includes/entry_event_fields.php'; ?>
                    </div>

                    <div class="entry-action-bar d-flex flex-wrap align-items-center gap-3">
                        <div class="entry-action-group">
                        <?php if ($days_left !== null && $days_left >= 0): ?>
                            <button type="submit" class="btn btn-primary">更新する</button>
                        <?php else: ?>
                            <button type="button" class="btn btn-secondary" disabled>締切後のため更新不可</button>
                        <?php endif; ?>
                        <a href="front_login.php" class="btn btn-outline-secondary">マイページへ戻る</a>
                        </div>

                        <?php if ($days_left !== null && $days_left >= 0): ?>
                            <button type="submit" class="btn btn-outline-danger entry-delete-btn" formnovalidate formaction="../back/delete_entry.php" formmethod="post" onclick="return confirm('この応募を削除します。よろしいですか？');">応募を削除</button>
                        <?php endif; ?>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
