<?php
require_once __DIR__ . '/../back/session_bootstrap.php';
tobg_start_session();
if (!isset($_SESSION['user_id'], $_SESSION['user_name'])) {
    header('Location: ../mypage/login.php');
    exit;
}
require_once __DIR__ . '/../back/db_connect.php';
require_once __DIR__ . '/../back/training_diary_lib.php';

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$posts = [];
$display_name = 'メンバー';
$is_logged_in = isset($_SESSION['user_id'], $_SESSION['user_name']);
$current_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$comment_summary = [];
$like_summary = [];
// この人の日記にコメントを書けるか（OGM の生徒の日記は、コーチ陣・コーチ代表者・管理者と本人だけ）
$can_comment_here = false;

if ($user_id <= 0) {
    header('Location: training_diary.php');
    exit;
}

try {
    ensure_training_diary_table($pdo);
    ensure_training_diary_interaction_tables($pdo);
    ensure_training_diary_weight_sets_table($pdo);
    ensure_training_diary_running_tables($pdo);
    ensure_training_diary_race_entries_table($pdo);
    ensure_training_diary_jog_table($pdo);
    cleanup_old_training_diaries($pdo);
    $comment_summary = fetch_training_diary_comment_summary($pdo);
    $like_summary = fetch_training_diary_like_summary($pdo);

    ensure_member_role_column($pdo);
    $name_stmt = $pdo->prepare('SELECT name, role FROM members WHERE id = :id LIMIT 1');
    $name_stmt->execute([':id' => $user_id]);
    $name_row = $name_stmt->fetch(PDO::FETCH_ASSOC);
    if (!empty($name_row['name'])) {
        $display_name = (string)$name_row['name'];
    }
    $can_comment_here = $is_logged_in
        && member_can_comment_on_diary((string)($_SESSION['user_role'] ?? ''), (string)($name_row['role'] ?? ''), $user_id === $current_user_id);

    $stmt = $pdo->prepare(
                "SELECT
                        d.id,
                        d.user_id,
                        COALESCE(m.name, 'メンバー') AS user_name,
                        d.practice_date,
                        d.menu AS menu_text,
                        d.training_type,
                        d.record_time AS time_text,
                        d.comment AS comment_text,
                        d.image_path,
                        d.video_path,
                        d.created_at,
                        d.updated_at
                 FROM training_diaries d
                 LEFT JOIN members m ON m.id = d.user_id
                 WHERE d.user_id = :user_id
                     AND d.is_public = 1
                     AND d.practice_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                 ORDER BY d.practice_date DESC, d.id DESC"
    );
    $stmt->execute([':user_id' => $user_id]);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($posts[0]['user_name'])) {
        $display_name = (string)$posts[0]['user_name'];
    }
} catch (PDOException $e) {
    $posts = [];
}

foreach ($posts as &$post) {
    $diary_id = (int)($post['id'] ?? 0);
    $post['comment_count'] = (int)($comment_summary[$diary_id] ?? 0);
    $post['like_count'] = (int)($like_summary[$diary_id] ?? 0);
    $post['comments'] = $diary_id > 0 ? fetch_training_diary_comments($pdo, $diary_id) : [];
    $post['liked_by_me'] = false;
    $post['weight_sets'] = ($diary_id > 0 && ($post['training_type'] ?? 'short_sprint') === 'weight')
        ? fetch_training_diary_weight_sets($pdo, $diary_id)
        : [];
    $post['running_sets'] = ($diary_id > 0 && training_diary_is_running_type((string)($post['training_type'] ?? 'short_sprint')))
        ? fetch_training_diary_running_sets($pdo, $diary_id)
        : [];
    $post['race_entries'] = ($diary_id > 0 && ($post['training_type'] ?? 'short_sprint') === 'race')
        ? fetch_training_diary_race_entries($pdo, $diary_id)
        : [];
    $post['jog_entries'] = ($diary_id > 0 && ($post['training_type'] ?? 'short_sprint') === 'jog')
        ? fetch_training_diary_jog_entries($pdo, $diary_id)
        : [];

    if ($current_user_id > 0 && $diary_id > 0) {
        $like_check_stmt = $pdo->prepare('SELECT id FROM training_diary_likes WHERE diary_id = :diary_id AND user_id = :user_id LIMIT 1');
        $like_check_stmt->execute([
            ':diary_id' => $diary_id,
            ':user_id' => $current_user_id,
        ]);
        $post['liked_by_me'] = (bool)$like_check_stmt->fetchColumn();
    }
}
unset($post);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?>さんの練習日記 | TOBG</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;700;800;900&family=Zen+Kaku+Gothic+New:wght@400;500;700;900&display=swap" rel="stylesheet">
    <link href="../css/theme.css?v=<?php echo (int)@filemtime(__DIR__ . '/../css/theme.css'); ?>" rel="stylesheet">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#F4EEE4">
    <meta name="apple-mobile-web-app-title" content="TOBG-RUNNING">
    <link rel="apple-touch-icon" href="../icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" href="../icons/favicon.png">
    <style>
        body { background: var(--color-page-bg); }
        .member-wrap { max-width: var(--content-max-width); margin: 0 auto; padding: 24px var(--page-gutter) 40px; }
        .member-title { font-size: 1.55rem; font-weight: 800; color: #0f172a; margin-bottom: 14px; }
        .member-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .line-post {
            background: #ffffff;
            border: 1px solid #dce6f2;
            border-radius: 16px;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
            padding: 14px;
            aspect-ratio: 1 / 1.1;
            overflow-y: auto;
        }
        .line-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 8px;
        }
        .line-head-actions {
            display: flex;
            align-items: flex-start;
            justify-content: flex-end;
            gap: 8px;
            flex-shrink: 0;
            margin-left: auto;
        }
        .post-date { color: #64748b; font-size: 0.85rem; margin-bottom: 6px; }
        .menu-tag {
            display: inline-block;
            background: #e2e8f0;
            color: #334155;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 0.82rem;
            margin-bottom: 8px;
        }
        .post-time { white-space: pre-wrap; color: #0f172a; font-weight: 600; margin-bottom: 8px; }
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
        .post-comment { white-space: pre-wrap; color: #334155; margin-bottom: 8px; }
        .post-image {
            width: min(100%, 260px);
            max-height: 220px;
            object-fit: cover;
            border-radius: 12px;
            border: 1px solid #dbe3ef;
        }
        .comment-toggle-btn,
        .like-toggle-btn {
            border-radius: 999px;
            border: 1px solid #d6dde8;
            background: #f8fafc;
            color: #334155;
            font-weight: 700;
            padding: 6px 10px;
            font-size: 0.84rem;
            line-height: 1;
        }
        .comment-toggle-btn:hover,
        .like-toggle-btn:hover {
            background: #eef4fb;
            color: #0f172a;
        }
        .like-toggle-btn.active {
            border-color: var(--color-accent);
            background: var(--color-hero-from);
            color: var(--color-hero-text);
        }
        .like-count {
            font-size: 0.9rem;
            font-weight: 800;
            color: #334155;
            min-width: 24px;
            text-align: center;
        }
        .comment-panel {
            margin-top: 12px;
            margin-bottom: 10px;
            position: relative;
            border-radius: 14px;
            border: 1px solid #d8e3ef;
            background: #f8fbff;
            padding: 12px;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.85);
        }
        .comment-panel[hidden] {
            display: none !important;
        }
        .comment-bubble-arrow {
            position: absolute;
            right: 8px;
            top: -8px;
            width: 0;
            height: 0;
            border-left: 8px solid transparent;
            border-right: 8px solid transparent;
            border-bottom: 8px solid #d8e3ef;
            filter: drop-shadow(0 -1px 0 rgba(255, 255, 255, 0.9));
        }
        .comment-bubble-arrow::after {
            content: "";
            position: absolute;
            left: -7px;
            top: 1px;
            border-left: 7px solid transparent;
            border-right: 7px solid transparent;
            border-bottom: 7px solid #f8fbff;
        }
        .comment-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            max-height: 260px;
            overflow-y: auto;
            padding-right: 4px;
        }
        .comment-item {
            position: relative;
            border-radius: 12px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            padding: 10px 12px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
        }
        .comment-item form {
            margin: 0;
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
        .comment-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 4px;
            color: #64748b;
            font-size: 0.8rem;
            font-weight: 700;
        }
        .comment-coach-only {
            margin: 8px 0 0;
            color: var(--color-text-muted);
            font-size: 0.78rem;
        }
        .comment-form {
            margin-top: 10px;
            display: grid;
            gap: 8px;
        }
        .comment-input {
            border-radius: 12px;
            border: 1px solid #cfd8e4;
            min-height: 76px;
            resize: vertical;
            padding: 10px 12px;
        }
        @media (max-width: 768px) {
            .member-grid {
                grid-template-columns: 1fr;
            }
            .line-post {
                aspect-ratio: auto;
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

<div class="member-wrap">
    <?php if (!empty($_SESSION['training_error'])): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars((string)$_SESSION['training_error'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['training_error']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['training_flash'])): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars((string)$_SESSION['training_flash'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['training_flash']); ?></div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h1 class="member-title mb-0"><?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?>さんの直近30日の練習</h1>
        <a href="training_diary.php" class="btn btn-outline-secondary">戻る</a>
    </div>

    <?php if (empty($posts)): ?>
        <div class="alert alert-secondary">この30日の投稿はありません。</div>
    <?php else: ?>
        <div class="member-grid">
            <?php foreach ($posts as $post): ?>
                <article class="line-post" id="diary-<?php echo (int)$post['id']; ?>">
                    <div class="line-head">
                        <div class="post-date">
                            練習日: <?php echo htmlspecialchars(date('Y/m/d', strtotime((string)$post['practice_date'])), ENT_QUOTES, 'UTF-8'); ?>
                            <br>
                            投稿: <?php echo htmlspecialchars(date('Y/m/d H:i', strtotime((string)$post['created_at'])), ENT_QUOTES, 'UTF-8'); ?>
                            <?php if (!empty($post['updated_at']) && strtotime((string)$post['updated_at']) > strtotime((string)$post['created_at'])): ?>
                                <br>更新: <?php echo htmlspecialchars(date('Y/m/d H:i', strtotime((string)$post['updated_at'])), ENT_QUOTES, 'UTF-8'); ?>
                            <?php endif; ?>
                        </div>
                        <div class="line-head-actions">
                            <?php if ($is_logged_in): ?>
                                <button type="button" class="comment-toggle-btn" data-comment-toggle="<?php echo (int)$post['id']; ?>">コメント <?php echo (int)$post['comment_count']; ?></button>
                                <form method="POST" action="../back/toggle_training_diary_like.php" class="m-0 d-inline">
                                    <input type="hidden" name="diary_id" value="<?php echo (int)$post['id']; ?>">
                                    <input type="hidden" name="return_url" value="<?php echo htmlspecialchars((string)($_SERVER['REQUEST_URI'] ?? '/front/training_member.php'), ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" class="like-toggle-btn <?php echo !empty($post['liked_by_me']) ? 'active' : ''; ?>">
                                        👍 <span class="like-count"><?php echo (int)$post['like_count']; ?></span>
                                    </button>
                                </form>
                            <?php else: ?>
                                <a href="../mypage/login.php" class="comment-toggle-btn text-decoration-none">コメント <?php echo (int)$post['comment_count']; ?></a>
                                <a href="../mypage/login.php" class="like-toggle-btn text-decoration-none">👍 <span class="like-count"><?php echo (int)$post['like_count']; ?></span></a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="comment-panel" data-comment-panel="<?php echo (int)$post['id']; ?>" hidden>
                        <div class="comment-bubble-arrow" aria-hidden="true"></div>
                        <div class="comment-list">
                            <?php if (empty($post['comments'])): ?>
                                <div class="text-secondary small">まだコメントはありません。</div>
                            <?php else: ?>
                                <?php foreach ($post['comments'] as $comment): ?>
                                    <div class="comment-item">
                                        <div class="comment-meta">
                                            <span><?php echo htmlspecialchars((string)$comment['user_name'], ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars(date('Y/m/d H:i', strtotime((string)$comment['created_at'])), ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php // 自分のコメントには、名前の行の右端に削除ボタンを置く ?>
                                            <?php if ((int)($comment['user_id'] ?? 0) === $current_user_id): ?>
                                                <form method="POST" action="../back/delete_training_diary_comment.php" class="m-0" onsubmit="return confirm('自分のコメントを削除しますか？');">
                                                    <input type="hidden" name="diary_id" value="<?php echo (int)$post['id']; ?>">
                                                    <input type="hidden" name="comment_id" value="<?php echo (int)$comment['id']; ?>">
                                                    <input type="hidden" name="return_url" value="<?php echo htmlspecialchars((string)($_SERVER['REQUEST_URI'] ?? '/front/training_member.php'), ENT_QUOTES, 'UTF-8'); ?>">
                                                    <button type="submit" class="comment-delete-btn" aria-label="自分のコメントを削除">削除</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                        <div><?php echo nl2br(htmlspecialchars((string)$comment['comment_text'], ENT_QUOTES, 'UTF-8')); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <?php if ($is_logged_in && !$can_comment_here): ?>
                            <p class="comment-coach-only">OGMの生徒の練習日記には、コーチだけがコメントできます。</p>
                        <?php elseif ($is_logged_in): ?>
                            <form method="POST" action="../back/save_training_diary_comment.php" class="comment-form" onsubmit="return validateCommentForm(this);">
                                <input type="hidden" name="diary_id" value="<?php echo (int)$post['id']; ?>">
                                <input type="hidden" name="return_url" value="<?php echo htmlspecialchars((string)($_SERVER['REQUEST_URI'] ?? '/front/training_member.php'), ENT_QUOTES, 'UTF-8'); ?>">
                                <textarea name="comment_text" class="comment-input" rows="3" maxlength="500" placeholder="コメントを追加"></textarea>
                                <button type="submit" class="btn btn-primary btn-sm align-self-end">送信</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <div class="menu-tag"><?php echo htmlspecialchars((string)$post['menu_text'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php if (($post['training_type'] ?? 'short_sprint') === 'weight'): ?>
                        <?php if (!empty($post['weight_sets'])): ?>
                            <div class="table-responsive mb-2">
                                <table class="table table-sm mb-0">
                                    <thead><tr><th>部位</th><th>種目</th><th>回数</th><th>セット</th><th>重量</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($post['weight_sets'] as $set): ?>
                                        <?php
                                        if (!empty($set['is_bodyweight'])) {
                                            $load_label = '自重';
                                        } elseif ($set['weight_kg'] !== null) {
                                            $load_label = $set['weight_kg'] . 'kg';
                                        } else {
                                            $load_label = $set['weight_note'] !== null ? $set['weight_note'] : '-';
                                        }
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string)$set['body_part'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars((string)$set['exercise_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo $set['reps'] !== null ? (int)$set['reps'] . '回' : '-'; ?></td>
                                            <td><?php echo $set['sets'] !== null ? (int)$set['sets'] . 'セット' : '-'; ?></td>
                                            <td><?php echo htmlspecialchars((string)$load_label, ENT_QUOTES, 'UTF-8'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-secondary small mb-2">ウエイト種目の記録がありません。</div>
                        <?php endif; ?>
                    <?php elseif (!empty($post['running_sets'])): ?>
                        <?php foreach ($post['running_sets'] as $rset): ?>
                            <div class="running-set-block mb-2">
                                <div class="running-set-header">
                                    <?php
                                    $header = $rset['workout_type'] . ' ／ ' . $rset['distance_label'] . ' × ' . (int)$rset['reps'] . '本';
                                    if ((int)$rset['sets'] > 1) {
                                        $header .= ' × ' . (int)$rset['sets'] . 'set';
                                    }
                                    if (!empty($rset['target_time_label'])) {
                                        $header .= ' ／ 設定 ' . $rset['target_time_label'];
                                    }
                                    echo htmlspecialchars($header, ENT_QUOTES, 'UTF-8');
                                    ?>
                                </div>
                                <?php if (!empty($rset['laps'])): ?>
                                    <div class="running-set-laps">
                                        <?php foreach ($rset['laps'] as $lap): ?>
                                            <?php $lap_label = $lap['lap_time_seconds'] !== null ? $lap['lap_time_seconds'] . '秒' : (string)($lap['lap_time_label'] ?? '-'); ?>
                                            <span class="running-lap-chip"><?php echo (int)$lap['lap_number']; ?>本目: <?php echo htmlspecialchars($lap_label, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php elseif (($post['training_type'] ?? 'short_sprint') === 'race'): ?>
                        <?php if (!empty($post['race_entries'])): ?>
                            <div class="table-responsive mb-2">
                                <table class="table table-sm mb-0">
                                    <thead><tr><th>種目</th><th>タイム・記録</th><th>風・公認</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($post['race_entries'] as $race_entry): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string)$race_entry['event_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars((string)$race_entry['result_label'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars((string)$race_entry['notes_label'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-secondary small mb-2">出場種目の記録がありません。</div>
                        <?php endif; ?>
                    <?php elseif (($post['training_type'] ?? 'short_sprint') === 'jog'): ?>
                        <div class="post-time">ジョグ <?php echo htmlspecialchars(format_training_diary_jog_distances($post['jog_entries']) ?: '-', ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php else: ?>
                        <div class="post-time"><?php echo nl2br(htmlspecialchars((string)$post['time_text'], ENT_QUOTES, 'UTF-8')); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($post['comment_text'])): ?>
                        <div class="post-comment"><?php echo nl2br(htmlspecialchars((string)$post['comment_text'], ENT_QUOTES, 'UTF-8')); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($post['image_path'])): ?>
                        <a href="../<?php echo htmlspecialchars((string)$post['image_path'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
                            <img src="../<?php echo htmlspecialchars((string)$post['image_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="練習写真" class="post-image">
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($post['video_path'])): ?>
                        <video class="post-image mt-2" src="../<?php echo htmlspecialchars((string)$post['video_path'], ENT_QUOTES, 'UTF-8'); ?>" autoplay muted playsinline loop controls></video>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
    (function () {
        document.querySelectorAll('[data-comment-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                var diaryId = button.getAttribute('data-comment-toggle');
                var panel = document.querySelector('[data-comment-panel="' + diaryId + '"]');
                if (!panel) {
                    return;
                }
                var isHidden = panel.hasAttribute('hidden');
                if (isHidden) {
                    panel.removeAttribute('hidden');
                } else {
                    panel.setAttribute('hidden', 'hidden');
                }
            });
        });
    }());

    function validateCommentForm(form) {
        var textarea = form.querySelector('textarea[name="comment_text"]');
        if (!textarea || textarea.value.trim() === '') {
            alert('コメントを入力してください。');
            if (textarea) {
                textarea.focus();
            }
            return false;
        }
        return true;
    }
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
