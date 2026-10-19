<?php
// 練習日記の登録シート。ログイン中は全ページのヘッダー（includes/site_header.php）から読み込み、
// 下タブの「記録する」やページの「練習日記を登録する」から開く（#diaryPostModal）。
// 見た目は css/diary_post_sheet.css、動きは js/diary_post_sheet.js。
//
// 使う値: $diary_sheet_base（サイトの一番上のパス。'' または '/TOBG' など）、$pdo、ログイン中の会員
require_once __DIR__ . '/../back/training_diary_lib.php';
require_once __DIR__ . '/../back/member_group_lib.php';
require_once __DIR__ . '/../back/training_diary_draft_lib.php';

// 公開範囲に出すグループ。マイページなど、すでに読み込んであるページではそれを使う
if (!isset($diary_post_groups)) {
    $diary_post_groups = [];
    try {
        ensure_member_group_tables($pdo);
        $diary_post_groups = array_values(array_filter(
            fetch_member_groups_for_user($pdo, (int)($_SESSION['user_id'] ?? 0)),
            static fn(array $group): bool => $group['status'] === MEMBER_GROUP_STATUS_ACTIVE
        ));
    } catch (PDOException $site_error) {
        error_log('diary sheet groups failed: ' . $site_error->getMessage());
    }
}

// 下書き（あれば、シートを開いたときに入力欄へ戻す。js/diary_post_sheet.js）
$diary_sheet_draft = null;
try {
    $diary_sheet_draft = fetch_training_diary_draft($pdo, (int)($_SESSION['user_id'] ?? 0));
} catch (PDOException $site_error) {
    error_log('diary sheet draft failed: ' . $site_error->getMessage());
}

// 前回の練習タイプ（大会以外で最後に投稿した日記のもの）。シートを開いたとき、はじめからこれを選んでおく（js/diary_post_sheet.js）
$diary_sheet_last_type = null;
try {
    $last_type_stmt = $pdo->prepare(
        "SELECT training_type FROM training_diaries
         WHERE user_id = :user_id AND training_type IN ('short_sprint', 'long_sprint', 'jog', 'weight')
         ORDER BY created_at DESC, id DESC LIMIT 1"
    );
    $last_type_stmt->execute([':user_id' => (int)($_SESSION['user_id'] ?? 0)]);
    $diary_sheet_last_type = $last_type_stmt->fetchColumn() ?: null;
} catch (PDOException $site_error) {
    error_log('diary sheet last type failed: ' . $site_error->getMessage());
}

// 投稿後に戻るページ（今のページ）。マイページの「?post_diary=1」はシートを開く合図なので外す
$diary_sheet_return_to = (string)($_SERVER['REQUEST_URI'] ?? '');
$diary_sheet_return_to = preg_replace('/([?&])post_diary=1(&|$)/', '$1', $diary_sheet_return_to);
$diary_sheet_return_to = rtrim((string)$diary_sheet_return_to, '?&');

$diary_sheet_asset = static function (string $path) use ($diary_sheet_base): string {
    return htmlspecialchars($diary_sheet_base . '/' . $path . '?v=' . (int)@filemtime(__DIR__ . '/../' . $path), ENT_QUOTES, 'UTF-8');
};
?>
<link href="<?php echo $diary_sheet_asset('css/diary_post_sheet.css'); ?>" rel="stylesheet">
<div class="modal fade diary-sheet" id="diaryPostModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="POST" action="<?php echo htmlspecialchars($diary_sheet_base . '/back/save_training_diary.php', ENT_QUOTES, 'UTF-8'); ?>" enctype="multipart/form-data" id="diaryPostForm">
                <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($diary_sheet_return_to, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="training_type" id="trainingTypeField" value="short_sprint">
                <input type="hidden" name="weight_sets_json" id="weightSetsJsonField" value="[]">
                <input type="hidden" name="running_sets_json" id="runningSetsJsonField" value="[]">
                <input type="hidden" name="race_entries_json" id="raceEntriesJsonField" value="[]">
        <input type="hidden" name="jog_entries_json" id="jogEntriesJsonField" value="[]">
                <div class="modal-header diary-post-modal-header">
                    <div class="diary-post-header-row">
                        <h5 class="modal-title mb-0">練習日記を登録する</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                </div>
                <div class="modal-body">
                    <?php // 下書きを戻したときの案内（「下書きを削除」で入力欄も最初の状態に戻す） ?>
                    <div class="diary-draft-note" id="diaryDraftNote" hidden>
                        <span id="diaryDraftNoteText">下書きを読み込みました。</span>
                        <button type="button" class="diary-draft-discard" id="diaryDraftDiscardBtn">下書きを削除</button>
                    </div>
                    <?php
                    $scope_icon_globe = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>';
                    $scope_icon_lock = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
                    $scope_icon_group = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
                    ?>
                    <?php // 公開範囲（タップで選択・解除）。自分の日記は必ず自分のカレンダーに載るので「自分のカレンダーのみ」は外せない ?>
                    <fieldset class="diary-scope-field mb-3">
                        <legend class="form-label fw-bold">公開範囲</legend>
                        <div class="diary-scope-chips">
                            <input type="hidden" name="is_public" value="0">
                            <input type="checkbox" class="btn-check" id="diaryScopePublic" name="is_public" value="1" checked autocomplete="off">
                            <label class="diary-scope-chip" for="diaryScopePublic"><?php echo $scope_icon_globe; ?>みんなの練習日記</label>
                            <span class="diary-scope-chip is-fixed" aria-label="自分のカレンダーのみ（常に選択）"><?php echo $scope_icon_lock; ?>自分のカレンダーのみ</span>
                            <?php foreach ($diary_post_groups as $post_group): ?>
                                <input type="checkbox" class="btn-check" id="diaryScopeGroup<?php echo (int)$post_group['id']; ?>" name="group_ids[]" value="<?php echo (int)$post_group['id']; ?>" autocomplete="off">
                                <label class="diary-scope-chip" for="diaryScopeGroup<?php echo (int)$post_group['id']; ?>"><?php echo $scope_icon_group; ?><?php echo htmlspecialchars($post_group['name'], ENT_QUOTES, 'UTF-8'); ?></label>
                            <?php endforeach; ?>
                        </div>
                        <p class="diary-scope-note">選んだ範囲すべてに表示されます。自分のカレンダーには必ず残ります。</p>
                    </fieldset>
                    <div class="row g-3 mb-2">
                        <?php // スマホでも日付と練習強度は横に並べる（半分ずつ） ?>
                        <div class="col-6 col-md-3">
                            <label class="form-label fw-bold" for="practice_date">日付</label>
                            <input class="form-control" type="date" id="practice_date" name="practice_date" required value="<?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label fw-bold" for="intensity">練習強度</label>
                            <select class="form-select" id="intensity" name="intensity">
                                <?php foreach (training_diary_intensity_options() as $intensity_value => $intensity_label): ?>
                                    <option
                                        value="<?php echo htmlspecialchars($intensity_value, ENT_QUOTES, 'UTF-8'); ?>"
                                        <?php echo $intensity_value === 'normal' ? 'selected' : ''; ?>
                                    ><?php echo htmlspecialchars($intensity_label, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold" for="menu_text" id="menuTextLabel">練習タイトル</label>
                            <input class="form-control" type="text" id="menu_text" name="menu_text" maxlength="255" required placeholder="例: フォームを綺麗に！">
                        </div>
                    </div>

                    <div class="mb-3" id="diaryTypeSection">
                        <label class="form-label fw-bold d-block mb-2">練習タイプ</label>
                        <div class="btn-group diary-type-toggle" role="group" aria-label="練習タイプの切り替え">
                            <input type="radio" class="btn-check" name="diary_type_switch" id="diaryTypeShortSprint" autocomplete="off" checked>
                            <label class="btn btn-outline-primary" for="diaryTypeShortSprint">🏃 ショート<wbr>スプ</label>
                            <input type="radio" class="btn-check" name="diary_type_switch" id="diaryTypeLongSprint" autocomplete="off">
                            <label class="btn btn-outline-primary" for="diaryTypeLongSprint">🏃‍♂️ ロング<wbr>スプ</label>
                            <input type="radio" class="btn-check" name="diary_type_switch" id="diaryTypeJog" autocomplete="off">
                <label class="btn btn-outline-primary" for="diaryTypeJog">👟 ジョグ</label>
                <input type="radio" class="btn-check" name="diary_type_switch" id="diaryTypeWeight" autocomplete="off">
                            <label class="btn btn-outline-primary" for="diaryTypeWeight">🏋 ウエイト</label>
                        </div>
                    </div>

                    <div id="dyRacePanel" class="diary-weight-panel mb-3" hidden>
                        <div class="fw-bold mb-2">出場種目を追加</div>
                        <div id="raceRowsContainer"></div>
                        <div class="d-flex justify-content-between align-items-center mt-4">
                            <button type="button" class="btn btn-outline-primary btn-sm" id="raceAddRowBtn">＋ 種目を追加</button>
                            <button type="button" class="btn btn-weight-confirm btn-sm" id="raceAddBtn">この内容で確定する</button>
                        </div>
                        <div class="mt-2 d-flex flex-column gap-1" id="raceEntryList"></div>
                    </div>

                    <div id="dyRunningPanel" class="diary-weight-panel mb-3">
                        <div class="fw-bold mb-2">ランニング種目を追加</div>
                        <datalist id="runningWorkoutTypeOptions"></datalist>
                        <datalist id="runningDistanceOptions"></datalist>
                        <div id="runningRowsContainer"></div>
                        <div class="d-flex justify-content-between align-items-center mt-4">
                            <button type="button" class="btn btn-outline-primary btn-sm" id="runningAddRowBtn">＋ 種目を追加</button>
                            <button type="button" class="btn btn-weight-confirm btn-sm" id="runningAddBtn">この内容で確定する</button>
                        </div>
                        <div class="mt-2 d-flex flex-column gap-1" id="runningEntryList"></div>
                    </div>

                    <?php // ジョグ: 距離を「整数 km」と「小数第1位」の2つのプルダウンで選ぶ（手入力なし）。朝と夜など複数行も入れられる ?>
            <div id="dyJogPanel" class="diary-weight-panel mb-3" hidden>
                <div class="fw-bold mb-2">ジョグの距離</div>
                <div id="jogRowsContainer" class="d-flex flex-column gap-2"></div>
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="jogAddRowBtn">＋ 距離を追加</button>
                    <span class="jog-total" id="jogTotal" aria-live="polite">合計 0.0km</span>
                </div>
            </div>

            <div id="dyWeightPanel" class="diary-weight-panel mb-3" hidden>
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                            <div class="fw-bold">ウエイト種目を追加</div>
                            <div class="btn-group btn-group-sm" role="group" aria-label="重量の刻み幅">
                                <input type="radio" class="btn-check" name="weight_step" id="weightStep5" autocomplete="off" checked>
                                <label class="btn btn-outline-secondary" for="weightStep5">5kg刻み</label>
                                <input type="radio" class="btn-check" name="weight_step" id="weightStep25" autocomplete="off">
                                <label class="btn btn-outline-secondary" for="weightStep25">2.5kg刻み</label>
                            </div>
                        </div>
                        <datalist id="weightRepsOptions"></datalist>
                        <datalist id="weightSetsOptions"></datalist>
                        <div id="weightRowsContainer"></div>
                        <div class="d-flex justify-content-between align-items-center mt-4">
                            <button type="button" class="btn btn-outline-primary btn-sm" id="weightAddRowBtn">＋ 種目を追加</button>
                            <button type="button" class="btn btn-weight-confirm btn-sm" id="weightAddBtn">この内容で確定する</button>
                        </div>
                        <div class="mt-2 d-flex flex-column gap-1" id="weightEntryList"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold" for="comment_text">ひとこと<span class="text-secondary fw-normal small ms-1">（任意）</span></label>
                        <textarea class="form-control" id="comment_text" name="comment_text" rows="2" placeholder="例: 後半もペースが落ちずに走れて手応えあり！"></textarea>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold" for="training_image">写真1つ<span class="text-secondary fw-normal small ms-1">（任意）</span></label>
                            <div class="file-pick" data-file-pick>
                                <input class="file-pick-input" type="file" id="training_image" name="training_image" accept="image/jpeg,image/png,image/webp">
                                <label class="file-pick-box" for="training_image"><span class="file-pick-plus" aria-hidden="true">＋</span><span data-file-pick-text data-empty-text="写真を追加" data-filled-text="写真を変更">写真を追加</span></label>
                            </div>
                            <div class="form-text">JPG/PNG/WEBP・40MB以内</div>
                            <div class="d-flex align-items-center justify-content-between mt-2 gap-2 file-pick-meta">
                                <small class="text-secondary" id="training_image_name">未選択</small>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-clear-target="training_image">選択を削除</button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold" for="training_video">動画1つ（15秒以内）<span class="text-secondary fw-normal small ms-1">（任意）</span></label>
                            <div class="file-pick" data-file-pick>
                                <input class="file-pick-input" type="file" id="training_video" name="training_video" accept="video/mp4,video/webm,video/quicktime">
                                <label class="file-pick-box" for="training_video"><span class="file-pick-plus" aria-hidden="true">＋</span><span data-file-pick-text data-empty-text="動画を追加" data-filled-text="動画を変更">動画を追加</span></label>
                            </div>
                            <div class="form-text">MP4/WEBM/MOV・60MB以内・自動再生対応</div>
                            <div class="d-flex align-items-center justify-content-between mt-2 gap-2 file-pick-meta">
                                <small class="text-secondary" id="training_video_name">未選択</small>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-clear-target="training_video">選択を削除</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <?php // 閉じるのは右上の × だけ。下書きは写真・動画以外の入力を保存する ?>
                    <span class="diary-draft-status" id="diaryDraftStatus" role="status" aria-live="polite"></span>
                    <button type="button" class="btn btn-outline-secondary" id="diaryDraftSaveBtn">下書きを保存</button>
                    <button type="submit" class="btn btn-primary" id="diaryPostSubmitBtn" disabled>投稿する</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // サーバー側の値（js/diary_post_sheet.js の大会の記録の入力で使う）
    window.TOBG_DIARY_SHEET = <?php echo json_encode([
        'windEvents' => TRAINING_DIARY_WIND_EVENTS,
        'windLimit' => TRAINING_DIARY_WIND_LIMIT,
        'draft' => $diary_sheet_draft,
        'lastType' => $diary_sheet_last_type,
        'draftUrl' => $diary_sheet_base . '/back/training_diary_draft.php',
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
</script>
<script src="<?php echo $diary_sheet_asset('js/diary_post_sheet.js'); ?>" defer></script>
