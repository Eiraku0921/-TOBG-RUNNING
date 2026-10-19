<?php
// 合同練習のカード一覧（クラブ画面の「合同練習」と、チーム紹介の「すべての人に公開の合同練習」）と募集フォーム。
// 操作は back/club_session_action.php。
// 使う変数:
//   $sessions          fetch_club_sessions() の戻り値
//   $viewer            club_session_viewer() の戻り値
//   $session_can_post  募集ボタンとフォームを出すか（チーム紹介の画面では false）
//   $e, $weekdays, $page_return_to, $is_logged_in
//   $session_base      サイトの一番上へのパスの頭（front/ のページは '../'、ホームは ''）
$session_action_url = $session_base . 'back/club_session_action.php';
$session_hidden = static function (int $session_id, string $action) use ($e, $page_return_to): string {
    return '<input type="hidden" name="action" value="' . $e($action) . '">'
        . '<input type="hidden" name="session_id" value="' . $session_id . '">'
        . '<input type="hidden" name="return_to" value="' . $e($page_return_to) . '">';
};
?>
<section class="club-panel" aria-label="合同練習">
    <?php if ($session_can_post): ?>
        <?php if ($viewer['can_host']): ?>
            <div class="club-actions">
                <button type="button" class="club-outline-btn" data-bs-toggle="modal" data-bs-target="#sessionFormModal">＋ 合同練習を募集する</button>
            </div>
        <?php else: ?>
            <p class="club-info">小中学生クラブの会員は合同練習を主催できません（参加はできます）。</p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (empty($sessions)): ?>
        <p class="club-empty">これからの合同練習はまだありません。</p>
    <?php endif; ?>

    <?php foreach ($sessions as $session): ?>
        <?php
        $sid = (int)$session['id'];
        $s_time = strtotime($session['session_date']);
        $count_label = $session['capacity'] !== null
            ? $session['participant_count'] . ' / ' . (int)$session['capacity'] . '人'
            : $session['participant_count'] . '人';
        ?>
        <article class="club-session<?php echo $session['is_canceled'] ? ' is-canceled' : ''; ?>" id="session-<?php echo $sid; ?>">
            <?php if (!empty($session['image_path'])): ?>
                <img class="club-session-photo" src="<?php echo $e($session_base . (string)$session['image_path']); ?>" alt="">
            <?php endif; ?>
            <div class="club-session-body">
                <div class="club-session-row">
                    <span class="club-session-date">
                        <span class="club-session-weekday"><?php echo strtoupper(date('D', $s_time)); // 英字の曜日（SAT など） ?></span>
                        <span class="club-session-day"><?php echo date('j', $s_time); ?></span>
                        <span class="club-session-month"><?php echo date('n', $s_time); ?>月</span>
                    </span>
                    <span class="club-session-main">
                        <span class="club-session-title"><?php echo $e((string)$session['title']); ?></span>
                        <span class="club-session-badges">
                            <?php if ((int)$session['is_official'] === 1): ?><span class="club-badge is-official">公式</span><?php endif; ?>
                            <?php if ($session['is_host']): ?><span class="club-badge is-host">主催</span><?php endif; ?>
                            <?php if ($session['is_canceled']): ?><span class="club-badge is-canceled">中止</span><?php endif; ?>
                        </span>
                        <span class="club-session-meta"><?php echo $e(substr((string)$session['meet_time'], 0, 5)); ?> 集合 ・ <?php echo $e((string)$session['meet_place']); ?></span>
                        <span class="club-session-sub">主催 <?php echo $e((string)($session['host_name'] ?? 'メンバー')); ?> ・ <?php echo $e($session['visibility_label']); ?> ・ <?php echo $e($count_label); ?></span>
                    </span>
                </div>

                <?php // 「詳細 ▼」はカード右上。押し間違えないよう、参加のボタン（カード下）から離して置く ?>
                <details class="club-session-detail">
                    <summary>詳細 <span aria-hidden="true">▼</span></summary>
                    <dl class="club-session-dl">
                        <dt>日時</dt><dd><?php echo date('Y年n月j日', $s_time); ?>（<?php echo $weekdays[(int)date('w', $s_time)]; ?>） <?php echo $e(substr((string)$session['meet_time'], 0, 5)); ?> 集合</dd>
                        <dt>集合場所</dt><dd><?php echo $e((string)$session['meet_place']); ?></dd>
                        <?php if (!empty($session['menu'])): ?><dt>練習内容</dt><dd><?php echo nl2br($e((string)$session['menu'])); ?></dd><?php endif; ?>
                        <?php if (!empty($session['note'])): ?><dt>ひとこと</dt><dd><?php echo nl2br($e((string)$session['note'])); ?></dd><?php endif; ?>
                        <dt>主催</dt><dd><?php echo $e((string)($session['host_name'] ?? 'メンバー')); ?></dd>
                        <dt>公開範囲</dt><dd><?php echo $e($session['visibility_label']); ?></dd>
                        <dt>参加予定</dt><dd><?php echo empty($session['participants']) ? 'まだいません' : $e(implode('、', array_column($session['participants'], 'name'))); ?></dd>
                    </dl>
                    <?php if ($session['is_host']): ?>
                        <?php // 主催者だけ: 参加者（名前・参加日時・ひとこと）と、参加者への連絡 ?>
                        <p class="club-session-host-title">参加者（主催者だけに表示）</p>
                        <?php if (empty($session['participants'])): ?>
                            <p class="club-empty">まだ参加者はいません。</p>
                        <?php else: ?>
                            <ul class="club-session-people">
                                <?php foreach ($session['participants'] as $participant): ?>
                                    <li>
                                        <span class="club-session-person"><?php echo $e((string)$participant['name']); ?></span>
                                        <span class="club-session-joined"><?php echo date('n/j H:i', strtotime((string)$participant['joined_at'])); ?></span>
                                        <?php if (!empty($participant['comment'])): ?><span class="club-session-comment"><?php echo $e((string)$participant['comment']); ?></span><?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if (!$session['is_canceled']): ?>
                                <form method="POST" action="<?php echo $session_action_url; ?>" class="club-session-message">
                                    <?php echo $session_hidden($sid, 'message'); ?>
                                    <label class="form-label small fw-bold" for="sessionMessage<?php echo $sid; ?>">参加者に連絡する</label>
                                    <textarea class="form-control" id="sessionMessage<?php echo $sid; ?>" name="body" rows="2" maxlength="500" required placeholder="例: 雨のため集合場所を変更します"></textarea>
                                    <button type="submit" class="club-outline-btn">送る</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php // 削除（主催者と管理者だけ。中止と違ってカードごと消え、元に戻せない） ?>
                    <?php if ($session['is_host'] || $viewer['role'] === MEMBER_ROLE_ADMIN): ?>
                        <?php $session_delete_message = '「' . $session['title'] . '」を削除しますか？' . "\n" . 'カードごと消え、元に戻せません。' . ($session['is_canceled'] || empty($session['participants']) ? '' : "\n" . '参加予定の人には取り消しを知らせます。'); ?>
                        <form method="POST" action="<?php echo $session_action_url; ?>" class="club-session-delete" onsubmit="return confirm(<?php echo $e(json_encode($session_delete_message, JSON_UNESCAPED_UNICODE)); ?>);">
                            <?php echo $session_hidden($sid, 'delete'); ?>
                            <button type="submit" class="club-session-delete-btn">この合同練習を削除する</button>
                        </form>
                    <?php endif; ?>
                </details>

                <?php // カード下の横幅いっぱいのボタン ?>
                <?php if ($session['is_canceled']): ?>
                    <button type="button" class="club-session-btn is-disabled" disabled>中止になりました</button>
                <?php elseif ($session['is_host']): ?>
                    <form method="POST" action="<?php echo $session_action_url; ?>" onsubmit="return confirm(<?php echo $e(json_encode('「' . $session['title'] . '」の募集を中止しますか？参加者に知らせます。', JSON_UNESCAPED_UNICODE)); ?>);">
                        <?php echo $session_hidden($sid, 'cancel'); ?>
                        <button type="submit" class="club-session-btn is-danger">この募集を中止する</button>
                    </form>
                <?php elseif ($session['is_joined']): ?>
                    <form method="POST" action="<?php echo $session_action_url; ?>" onsubmit="return confirm(<?php echo $e(json_encode('「' . $session['title'] . '」への参加を取り消しますか？', JSON_UNESCAPED_UNICODE)); ?>);">
                        <?php echo $session_hidden($sid, 'leave'); ?>
                        <button type="submit" class="club-session-btn is-joined">参加予定 ✓（タップで取り消し）</button>
                    </form>
                <?php elseif ($session['is_full']): ?>
                    <button type="button" class="club-session-btn is-disabled" disabled>満員です</button>
                <?php elseif (!$is_logged_in): ?>
                    <a class="club-session-btn" href="<?php echo $e($session_base . 'mypage/login.php'); ?>">ログインして参加する</a>
                <?php else: ?>
                    <form method="POST" action="<?php echo $session_action_url; ?>">
                        <?php echo $session_hidden($sid, 'join'); ?>
                        <button type="submit" class="club-session-btn">参加する</button>
                    </form>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</section>

<?php if ($session_can_post && $viewer['can_host']): ?>
    <?php // 募集フォーム（タイトル・日付・集合時間・集合場所は必須） ?>
    <div class="modal fade" id="sessionFormModal" tabindex="-1" aria-labelledby="sessionFormTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <form method="POST" action="<?php echo $session_action_url; ?>" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="return_to" value="<?php echo $e($page_return_to); ?>">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5 fw-bold" id="sessionFormTitle">合同練習を募集する</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold" for="sessionTitle">タイトル</label>
                            <input class="form-control" id="sessionTitle" name="title" maxlength="<?php echo CLUB_SESSION_TITLE_MAX; ?>" required placeholder="例: 週末のペース走 10km">
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-7">
                                <label class="form-label fw-bold" for="sessionDate">日付</label>
                                <input class="form-control" type="date" id="sessionDate" name="session_date" min="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-5">
                                <label class="form-label fw-bold" for="sessionTime">集合時間</label>
                                <input class="form-control" type="time" id="sessionTime" name="meet_time" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold" for="sessionPlace">集合場所</label>
                            <input class="form-control" id="sessionPlace" name="meet_place" maxlength="<?php echo CLUB_SESSION_PLACE_MAX; ?>" required placeholder="例: 駒沢公園・中央広場">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold" for="sessionMenu">練習内容<span class="text-secondary fw-normal small ms-1">（任意）</span></label>
                            <textarea class="form-control" id="sessionMenu" name="menu" rows="2" maxlength="<?php echo CLUB_SESSION_MENU_MAX; ?>" placeholder="例: 10km ペース走（5:00/km）"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold" for="sessionNote">ひとこと<span class="text-secondary fw-normal small ms-1">（任意）</span></label>
                            <input class="form-control" id="sessionNote" name="note" maxlength="<?php echo CLUB_SESSION_NOTE_MAX; ?>" placeholder="例: 初めての方も歓迎です！">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold" for="sessionImage">写真<span class="text-secondary fw-normal small ms-1">（任意・10MBまで）</span></label>
                            <input class="form-control" type="file" id="sessionImage" name="image" accept="image/jpeg,image/png,image/webp">
                        </div>
                        <div class="row g-2 mb-1">
                            <div class="col-5">
                                <label class="form-label fw-bold" for="sessionCapacity">定員</label>
                                <select class="form-select" id="sessionCapacity" name="capacity">
                                    <option value="none">なし</option>
                                    <?php for ($capacity = CLUB_SESSION_CAPACITY_MIN; $capacity <= CLUB_SESSION_CAPACITY_MAX; $capacity++): ?>
                                        <option value="<?php echo $capacity; ?>"><?php echo $capacity; ?>人</option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-7">
                                <label class="form-label fw-bold" for="sessionVisibility">公開範囲</label>
                                <select class="form-select" id="sessionVisibility" name="visibility" data-session-visibility>
                                    <?php foreach (CLUB_SESSION_VISIBILITY_LABELS as $visibility_key => $visibility_label): ?>
                                        <?php if ($visibility_key === CLUB_SESSION_VISIBILITY_GROUP && empty($session_groups)) { continue; } ?>
                                        <option value="<?php echo $e($visibility_key); ?>"><?php echo $e($visibility_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <?php if (!empty($session_groups)): ?>
                            <div class="mb-1 mt-2" data-session-group-field hidden>
                                <label class="form-label fw-bold" for="sessionGroup">公開するグループ</label>
                                <select class="form-select" id="sessionGroup" name="group_id">
                                    <?php foreach ($session_groups as $session_group): ?>
                                        <option value="<?php echo (int)$session_group['id']; ?>"><?php echo $e((string)$session_group['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">やめる</button>
                        <button type="submit" class="btn btn-primary">募集する</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script>
        // 公開範囲で「自分のグループ」を選んだときだけ、グループを選ぶ欄を出す
        (function () {
            var select = document.querySelector('[data-session-visibility]');
            var groupField = document.querySelector('[data-session-group-field]');
            if (!select || !groupField) {
                return;
            }
            function sync() {
                groupField.hidden = select.value !== 'group';
            }
            select.addEventListener('change', sync);
            sync();
        })();
    </script>
<?php endif; ?>
