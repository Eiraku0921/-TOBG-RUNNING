<?php
require_once __DIR__ . '/../back/session_bootstrap.php';
tobg_start_session();
require_once __DIR__ . '/../back/db_connect.php';
require_once __DIR__ . '/../back/gallery_lib.php';

$posts = [];
$comment_summary = [];
$like_summary = [];
$selected_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$is_logged_in = isset($_SESSION['user_id'], $_SESSION['user_name']);
$gallery_flash = (string)($_SESSION['gallery_flash'] ?? '');
$gallery_error = (string)($_SESSION['gallery_error'] ?? '');
unset($_SESSION['gallery_flash'], $_SESSION['gallery_error']);

try {
    ensure_gallery_table($pdo);
    ensure_gallery_interaction_tables($pdo);

    $comment_summary = fetch_gallery_comment_summary($pdo);
    $like_summary = fetch_gallery_like_summary($pdo);

    $stmt = $pdo->query(
        "SELECT g.id, g.user_id, g.event_date, g.title, g.body, g.image_path_1, g.image_path_2, g.video_path_1, g.video_path_2, g.created_at, COALESCE(m.name, 'メンバー') AS user_name
         FROM gallery_posts g
         LEFT JOIN members m ON m.id = g.user_id
         ORDER BY COALESCE(g.event_date, DATE(g.created_at)) DESC, g.id DESC"
    );
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $posts = [];
}

foreach ($posts as &$post) {
    $gallery_id = (int)($post['id'] ?? 0);
    $post['comment_count'] = (int)($comment_summary[$gallery_id] ?? 0);
    $post['like_count'] = (int)($like_summary[$gallery_id] ?? 0);
    $post['comments'] = $gallery_id > 0 ? fetch_gallery_comments($pdo, $gallery_id) : [];
    $post['liked_by_me'] = false;

    if ($is_logged_in && $selected_user_id > 0 && $gallery_id > 0) {
        $like_check_stmt = $pdo->prepare('SELECT id FROM gallery_likes WHERE gallery_id = :gallery_id AND user_id = :user_id LIMIT 1');
        $like_check_stmt->execute([
            ':gallery_id' => $gallery_id,
            ':user_id' => $selected_user_id,
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
    <title>ギャラリー | TOBG</title>
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
        .gallery-wrap { max-width: var(--content-max-width); margin: 0 auto; padding: 20px var(--page-gutter) 40px; }
        .gallery-title { font-size: 1.7rem; font-weight: 800; color: #1f2937; margin-bottom: 12px; }
        .gallery-note { color: #64748b; font-size: 0.95rem; margin-bottom: 16px; }
        .gallery-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .gallery-card { background: #ffffff; border: 1px solid #d8e0ea; border-radius: 14px; padding: 14px; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08); }
        .gallery-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 8px;
        }
        .gallery-head-main {
            min-width: 0;
            flex: 1;
        }
        .gallery-head-actions {
            display: flex;
            align-items: flex-start;
            justify-content: flex-end;
            gap: 8px;
            flex-shrink: 0;
            margin-left: auto;
        }
        .gallery-meta { font-size: 0.83rem; color: #64748b; margin-bottom: 4px; }
        .gallery-heading { font-size: 1.02rem; font-weight: 800; color: #0f172a; margin-bottom: 8px; }
        .gallery-body { color: #334155; white-space: pre-wrap; font-size: 0.92rem; margin-bottom: 10px; }
        .gallery-media { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .gallery-media img, .gallery-media video { width: 100%; border-radius: 10px; border: 1px solid #dbe4ef; max-height: 230px; object-fit: cover; background: #f8fafc; }
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
            text-decoration: none;
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
            border-radius: 14px;
            border: 1px solid #d8e3ef;
            background: #f8fbff;
            padding: 12px;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.85);
        }
        .comment-panel[hidden] { display: none !important; }
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
        .comment-meta { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 4px; color: #64748b; font-size: 0.8rem; font-weight: 700; }
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
        .comment-text { white-space: pre-wrap; color: #334155; }
        .comment-form { margin-top: 10px; display: grid; gap: 8px; }
        .comment-input { border-radius: 12px; border: 1px solid #cfd8e4; min-height: 76px; resize: vertical; padding: 10px 12px; }
        .comment-alert {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 999px;
            padding: 7px 12px;
            background: #fff7ed;
            color: #9a3412;
            border: 1px solid #fdba74;
            font-size: 0.82rem;
            font-weight: 700;
            margin-top: 8px;
        }
        @media (max-width: 768px) {
            .gallery-grid { grid-template-columns: 1fr; }
            .gallery-media { grid-template-columns: 1fr; }
            .gallery-head {
                flex-direction: column;
                align-items: stretch;
            }
            .gallery-head-actions {
                justify-content: flex-end;
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
<div class="gallery-wrap">
    <h1 class="gallery-title">ギャラリー</h1>
    <p class="gallery-note">合同練習や大きな大会のログを写真・動画で残すページです。</p>

    <?php if ($gallery_flash !== ''): ?>
        <div class="alert alert-success py-2" role="alert"><?php echo htmlspecialchars($gallery_flash, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($gallery_error !== ''): ?>
        <div class="alert alert-danger py-2" role="alert"><?php echo htmlspecialchars($gallery_error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if (empty($posts)): ?>
        <div class="alert alert-secondary">まだギャラリー投稿がありません。</div>
    <?php else: ?>
        <div class="gallery-grid">
            <?php foreach ($posts as $post): ?>
                <article class="gallery-card" id="gallery-<?php echo (int)$post['id']; ?>">
                    <div class="gallery-head">
                        <div class="gallery-head-main">
                            <div class="gallery-meta">
                                投稿者: <?php echo htmlspecialchars((string)$post['user_name'], ENT_QUOTES, 'UTF-8'); ?>
                                /
                                日付: <?php echo !empty($post['event_date']) ? htmlspecialchars(date('Y/m/d', strtotime((string)$post['event_date'])), ENT_QUOTES, 'UTF-8') : htmlspecialchars(date('Y/m/d', strtotime((string)$post['created_at'])), ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <h2 class="gallery-heading"><?php echo htmlspecialchars((string)$post['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
                        </div>
                        <div class="gallery-head-actions">
                            <?php if ($is_logged_in): ?>
                                <button type="button" class="comment-toggle-btn" data-gallery-comment-toggle="<?php echo (int)$post['id']; ?>">コメント <?php echo (int)$post['comment_count']; ?></button>
                                <form method="POST" action="../back/toggle_gallery_like.php" class="m-0">
                                    <input type="hidden" name="gallery_id" value="<?php echo (int)$post['id']; ?>">
                                    <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? '../front/gallery.php', ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" class="like-toggle-btn <?php echo !empty($post['liked_by_me']) ? 'active' : ''; ?>">👍 <span class="like-count"><?php echo (int)$post['like_count']; ?></span></button>
                                </form>
                            <?php else: ?>
                                <a href="../mypage/login.php" class="comment-toggle-btn text-decoration-none">コメント <?php echo (int)$post['comment_count']; ?></a>
                                <a href="../mypage/login.php" class="like-toggle-btn text-decoration-none">👍 <span class="like-count"><?php echo (int)$post['like_count']; ?></span></a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!empty($post['body'])): ?>
                        <div class="gallery-body"><?php echo nl2br(htmlspecialchars((string)$post['body'], ENT_QUOTES, 'UTF-8')); ?></div>
                    <?php endif; ?>
                    <div class="gallery-media">
                        <?php foreach (['image_path_1', 'image_path_2'] as $image_field): ?>
                            <?php if (!empty($post[$image_field])): ?>
                                <a href="../<?php echo htmlspecialchars((string)$post[$image_field], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
                                    <img src="../<?php echo htmlspecialchars((string)$post[$image_field], ENT_QUOTES, 'UTF-8'); ?>" alt="ギャラリー画像">
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php foreach (['video_path_1', 'video_path_2'] as $video_field): ?>
                            <?php if (!empty($post[$video_field])): ?>
                                <video src="../<?php echo htmlspecialchars((string)$post[$video_field], ENT_QUOTES, 'UTF-8'); ?>" controls playsinline></video>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($is_logged_in): ?>
                        <div class="comment-panel" id="gallery-comment-panel-<?php echo (int)$post['id']; ?>" hidden>
                            <?php if (!empty($post['comments'])): ?>
                                <div class="comment-list mb-2">
                                    <?php foreach ($post['comments'] as $comment): ?>
                                        <div class="comment-item">
                                            <div class="comment-bubble-arrow" aria-hidden="true"></div>
                                            <div class="comment-meta">
                                                <span><?php echo htmlspecialchars((string)$comment['user_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <span><?php echo htmlspecialchars(date('Y/m/d H:i', strtotime((string)$comment['created_at'])), ENT_QUOTES, 'UTF-8'); ?></span>
                                            </div>
                                            <div class="comment-text"><?php echo nl2br(htmlspecialchars((string)$comment['comment_text'], ENT_QUOTES, 'UTF-8')); ?></div>
                                            <?php if ($selected_user_id === (int)$comment['user_id'] || (string)($_SESSION['user_role'] ?? '') === 'admin'): ?>
                                                <form method="POST" action="../back/delete_gallery_comment.php" onsubmit="return confirm('このコメントを削除します。よろしいですか？');" class="mt-2">
                                                    <input type="hidden" name="gallery_id" value="<?php echo (int)$post['id']; ?>">
                                                    <input type="hidden" name="comment_id" value="<?php echo (int)$comment['id']; ?>">
                                                    <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? '../front/gallery.php', ENT_QUOTES, 'UTF-8'); ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">削除</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-secondary mb-2">まだコメントはありません。</div>
                            <?php endif; ?>

                            <form method="POST" action="../back/save_gallery_comment.php" class="comment-form">
                                <input type="hidden" name="gallery_id" value="<?php echo (int)$post['id']; ?>">
                                <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? '../front/gallery.php', ENT_QUOTES, 'UTF-8'); ?>">
                                <textarea class="comment-input" name="comment_text" rows="3" placeholder="コメントを入力"></textarea>
                                <button type="submit" class="btn btn-primary btn-sm align-self-start">コメントする</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="comment-alert">いいねやコメントはログイン後に使えます。</div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<script>
    (function () {
        document.querySelectorAll('[data-gallery-comment-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                var galleryId = button.getAttribute('data-gallery-comment-toggle');
                var panel = document.getElementById('gallery-comment-panel-' + galleryId);
                if (!panel) {
                    return;
                }
                panel.hidden = !panel.hidden;
            });
        });
    })();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
