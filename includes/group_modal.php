<?php
// マイグループの画面（作成・招待・参加・名前とアイコンの変更・脱退・削除の申請）。みんなのページで使う。
// 未ログインの人には、機能の紹介とログインへの入口を出す。動きは js/group_modal.js、見た目は css/group_modal.css。
//
// 使う変数:
//   $group_modal_base   サイトの一番上へのパスの頭（front/ のページは '../'）
//   $is_logged_in       ログイン中か
//   $my_groups          fetch_member_groups_for_user() の戻り値（ログイン中）
//   $group_modal_join_code  招待リンクから来たときの招待コード（無ければ ''）
require_once __DIR__ . '/../back/member_group_lib.php'; // グループ名の最大文字数などの定数

$group_modal_e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$group_modal_asset = static function (string $path) use ($group_modal_base): string {
    return htmlspecialchars($group_modal_base . $path . '?v=' . (int)@filemtime(__DIR__ . '/../' . $path), ENT_QUOTES, 'UTF-8');
};
?>
<link href="<?php echo $group_modal_asset('css/group_modal.css'); ?>" rel="stylesheet">
<?php if ($is_logged_in): ?>
    <!-- マイグループ（作成・招待・参加・名前の変更・脱退・削除の申請） -->
    <div class="modal fade group-modal" id="groupModal" tabindex="-1" aria-labelledby="groupModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title" id="groupModalTitle">マイグループ</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                </div>
                <div class="modal-body">
                    <div data-group-pane="list">
                        <div class="group-top-actions">
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-group-open="join">参加する</button>
                            <button type="button" class="btn btn-primary btn-sm" data-group-open="create">＋ 作成</button>
                        </div>
                        <ul class="group-list" id="groupList"></ul>
                        <a class="group-guide-link" href="<?php echo $group_modal_e($group_modal_base . 'front/group_guide.php'); ?>">グループ機能の使い方 ›</a>
                    </div>

                    <form data-group-pane="join" hidden novalidate>
                        <label class="form-label fw-bold" for="groupJoinCode">招待コード（6桁の数字）</label>
                        <input type="text" class="form-control" id="groupJoinCode" inputmode="numeric" autocomplete="off" maxlength="6" placeholder="例 123456">
                        <p class="group-pane-error" data-group-error></p>
                        <div class="group-pane-actions">
                            <button type="button" class="btn btn-outline-secondary" data-group-open="list">戻る</button>
                            <button type="submit" class="btn btn-primary">参加する</button>
                        </div>
                    </form>

                    <form data-group-pane="create" hidden novalidate>
                        <label class="form-label fw-bold" for="groupCreateName">グループ名</label>
                        <input type="text" class="form-control" id="groupCreateName" maxlength="<?php echo MEMBER_GROUP_NAME_MAX; ?>" autocomplete="off" placeholder="例 朝ラン仲間">
                        <p class="group-pane-error" data-group-error></p>
                        <p class="group-note">作成したら、招待コードか招待リンクを送ってメンバーを招待します。</p>
                        <div class="group-pane-actions">
                            <button type="button" class="btn btn-outline-secondary" data-group-open="list">戻る</button>
                            <button type="submit" class="btn btn-primary">作成する</button>
                        </div>
                    </form>

                    <div data-group-pane="invite" hidden>
                        <p class="fw-bold mb-1" id="groupInviteName"></p>
                        <div class="group-invite-tabs" role="tablist" aria-label="招待の方法">
                            <button type="button" class="group-invite-tab" role="tab" aria-selected="true" aria-controls="groupInviteCodePanel" data-invite-tab="code">コード</button>
                            <button type="button" class="group-invite-tab" role="tab" aria-selected="false" aria-controls="groupInviteLinkPanel" data-invite-tab="link">リンク</button>
                        </div>
                        <div id="groupInviteCodePanel" role="tabpanel">
                            <div class="group-code" id="groupInviteCode"></div>
                            <p class="group-note text-center mb-0">このコードを伝えて、「参加する」から入力してもらいます。<br><span id="groupInviteExpires"></span></p>
                        </div>
                        <div id="groupInviteLinkPanel" role="tabpanel" hidden>
                            <input type="text" class="form-control mt-2" id="groupInviteLink" readonly aria-label="招待リンク">
                            <div class="group-link-buttons">
                                <button type="button" class="btn btn-outline-secondary" id="groupInviteCopy">コピー</button>
                                <a class="btn btn-line" id="groupInviteLine" href="#" target="_blank" rel="noopener noreferrer">LINEで送る</a>
                            </div>
                            <p class="group-note mt-2 mb-0">リンクを開いた人は、ログインするとこのグループに参加できます。<br><span data-invite-expires-copy></span></p>
                        </div>
                        <div class="group-pane-actions">
                            <span></span>
                            <button type="button" class="btn btn-primary" data-group-open="list">完了</button>
                        </div>
                    </div>

                    <form data-group-pane="icon" hidden novalidate>
                        <p class="fw-bold mb-1" id="groupIconName"></p>
                        <div class="group-icon-preview" id="groupIconPreview"></div>
                        <label class="form-label fw-bold" for="groupIconFile">写真を選ぶ</label>
                        <input type="file" class="form-control" id="groupIconFile" accept="image/jpeg,image/png,image/webp,image/gif">
                        <p class="group-note mt-1 mb-0">JPG・PNG・WEBP・GIF（5MBまで）。真ん中を丸く切り取って使います。<br>見出しの切り替え（みんなの練習日記 ▾）で表示されます。</p>
                        <p class="group-pane-error" data-group-error></p>
                        <div class="group-pane-actions">
                            <button type="button" class="btn btn-outline-secondary" data-group-open="list">戻る</button>
                            <button type="button" class="group-icon-remove" id="groupIconRemove" hidden>アイコンを外す</button>
                            <button type="submit" class="btn btn-primary">保存する</button>
                        </div>
                    </form>

                    <form data-group-pane="rename" hidden novalidate>
                        <label class="form-label fw-bold" for="groupRenameName">新しいグループ名</label>
                        <input type="text" class="form-control" id="groupRenameName" maxlength="<?php echo MEMBER_GROUP_NAME_MAX; ?>" autocomplete="off">
                        <p class="group-pane-error" data-group-error></p>
                        <div class="group-pane-actions">
                            <button type="button" class="btn btn-outline-secondary" data-group-open="list">戻る</button>
                            <button type="submit" class="btn btn-primary">保存する</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <?php // 未ログインの人向けのマイグループ（機能の紹介と、ログインして参加・作成する入り口） ?>
    <div class="modal fade group-modal" id="groupGuestModal" tabindex="-1" aria-labelledby="groupGuestModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title" id="groupGuestModalTitle">マイグループ</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                </div>
                <div class="modal-body">
                    <div class="group-guest-hero">
                        <span class="group-guest-hero-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
                        <p class="group-guest-lead">仲間だけに練習日記を見せられます</p>
                    </div>
                    <ul class="group-guest-points">
                        <li>チームメイトや練習仲間とグループを作れます</li>
                        <li>招待コードを入れるだけで参加できます</li>
                        <li>日記ごとに、見せるグループを選べます</li>
                    </ul>
                    <div class="group-top-actions">
                        <a class="btn btn-outline-secondary btn-sm" href="<?php echo $group_modal_e($group_modal_base . 'mypage/login.php?open_group=join'); ?>">参加する</a>
                        <a class="btn btn-primary btn-sm" href="<?php echo $group_modal_e($group_modal_base . 'mypage/login.php?open_group=create'); ?>">＋ 作成</a>
                    </div>
                    <p class="group-guest-login-note">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        ログインすると使えます
                    </p>
                    <a class="group-guide-link" href="<?php echo $group_modal_e($group_modal_base . 'front/group_guide.php'); ?>">グループ機能の使い方 ›</a>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php if ($is_logged_in): ?>
<script>
    // js/group_modal.js に渡す値
    window.TOBG_GROUP = <?php echo json_encode([
        'base' => $group_modal_base,
        'groups' => $my_groups,
        'joinCode' => $group_modal_join_code,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
</script>
<script src="<?php echo $group_modal_asset('js/group_modal.js'); ?>" defer></script>
<?php endif; ?>
