<?php
// クラブ画面の「メニュー」（OGMRC）。中身は back/ogmrc_menu_lib.php の fetch_ogmrc_menu()。
// コーチ・管理者には、入力のフォーム（ひとことの配信、練習予定の追加・編集・削除）も出す。送り先は back/ogmrc_menu_action.php
// 使う変数: $ogmrc_menu, $ogmrc_can_edit, $e, $weekdays
$ogmrc_action_url = '../back/ogmrc_menu_action.php';
$ogmrc_date = static function (string $date) use ($weekdays): string {
    $time = strtotime($date);
    return date('n/j', $time) . '（' . $weekdays[(int)date('w', $time)] . '）';
};
// 練習予定の入力フォーム（追加と編集で共通）
$ogmrc_practice_form = static function (?array $practice) use ($e, $ogmrc_action_url): string {
    $v = static fn(string $key): string => $e((string)($practice[$key] ?? ''));
    $html = '<form method="POST" action="' . $ogmrc_action_url . '" class="ogmrc-form">'
        . '<input type="hidden" name="action" value="save_practice">'
        . '<input type="hidden" name="practice_id" value="' . (int)($practice['id'] ?? 0) . '">'
        . '<div class="row g-2">'
        . '<div class="col-6"><label class="form-label small fw-bold">日付</label><input class="form-control form-control-sm" type="date" name="practice_date" required value="' . $v('practice_date') . '"></div>'
        . '<div class="col-6"><label class="form-label small fw-bold">時間（任意）</label><input class="form-control form-control-sm" type="time" name="start_time" value="' . $e(substr((string)($practice['start_time'] ?? ''), 0, 5)) . '"></div>'
        . '<div class="col-12"><label class="form-label small fw-bold">内容（予定の一覧に出す一言）</label><input class="form-control form-control-sm" name="title" maxlength="60" required placeholder="例: スピード練習" value="' . $v('title') . '"></div>'
        . '<div class="col-12"><label class="form-label small fw-bold">場所（任意）</label><input class="form-control form-control-sm" name="place" maxlength="100" placeholder="例: 博多の森陸上競技場" value="' . $v('place') . '"></div>'
        . '<div class="col-12"><label class="form-check"><input class="form-check-input" type="checkbox" name="is_race" value="1"' . (!empty($practice['is_race']) ? ' checked' : '') . '> <span class="form-check-label small">この日は大会</span></label></div>';
    foreach (['warmup' => 'ウォームアップ', 'drill' => 'ドリル', 'main_set' => 'メイン', 'cool_down' => 'さいご'] as $field => $label) {
        $html .= '<div class="col-6"><label class="form-label small fw-bold">' . $label . '</label><textarea class="form-control form-control-sm" name="' . $field . '" rows="2" maxlength="' . OGMRC_PRACTICE_TEXT_MAX . '">' . $v($field) . '</textarea></div>';
    }
    return $html . '</div><button type="submit" class="club-outline-btn mt-2">' . ($practice ? '更新する' : '追加する') . '</button></form>';
};
$ogmrc_delete_form = static function (array $practice) use ($e, $ogmrc_action_url, $ogmrc_date): string {
    return '<form method="POST" action="' . $ogmrc_action_url . '" class="d-inline" onsubmit="return confirm(' . $e(json_encode($ogmrc_date($practice['practice_date']) . '「' . $practice['title'] . '」を削除しますか？', JSON_UNESCAPED_UNICODE)) . ');">'
        . '<input type="hidden" name="action" value="delete_practice"><input type="hidden" name="practice_id" value="' . (int)$practice['id'] . '">'
        . '<button type="submit" class="ogmrc-delete">削除</button></form>';
};
$note = $ogmrc_menu['note'];
$next = $ogmrc_menu['next'];
?>
<section class="club-panel ogmrc" aria-label="メニュー">
    <?php // コーチのひとこと（コーチ名、配信日、NEW、本文） ?>
    <p class="club-section-title">コーチのひとこと</p>
    <?php if ($note === null): ?>
        <p class="club-empty">まだひとことはありません。</p>
    <?php else: ?>
        <article class="ogmrc-note">
            <div class="ogmrc-note-head">
                <span class="ogmrc-note-author"><?php echo $e((string)$note['author_name']); ?>コーチ</span>
                <span class="ogmrc-note-date"><?php echo date('n/j', strtotime((string)$note['created_at'])); ?></span>
                <?php if ($note['is_new']): ?><span class="ogmrc-new">NEW</span><?php endif; ?>
            </div>
            <p class="ogmrc-note-body"><?php echo nl2br($e((string)$note['body'])); ?></p>
        </article>
    <?php endif; ?>
    <?php if ($ogmrc_can_edit): ?>
        <details class="ogmrc-edit">
            <summary>＋ ひとことを配信する（コーチ）</summary>
            <form method="POST" action="<?php echo $ogmrc_action_url; ?>" class="ogmrc-form">
                <input type="hidden" name="action" value="note">
                <textarea class="form-control" name="body" rows="3" maxlength="<?php echo OGMRC_NOTE_MAX; ?>" required placeholder="例: 今週は記録会に向けてスピード練習を中心にします。"></textarea>
                <button type="submit" class="club-outline-btn mt-2">配信する</button>
            </form>
        </details>
    <?php endif; ?>

    <?php // 次の練習日（濃い紺のカード）: 日時・場所、ウォームアップ／ドリル／メイン／さいご ?>
    <p class="club-section-title">次の練習日</p>
    <?php if ($next === null): ?>
        <p class="club-empty">次の練習日はまだ決まっていません。</p>
    <?php else: ?>
        <article class="ogmrc-next">
            <p class="ogmrc-next-date"><?php echo $e($ogmrc_date((string)$next['practice_date'])); ?><?php if (!empty($next['start_time'])): ?> <?php echo $e(substr((string)$next['start_time'], 0, 5)); ?>〜<?php endif; ?></p>
            <p class="ogmrc-next-title"><?php echo $e((string)$next['title']); ?></p>
            <?php if (!empty($next['place'])): ?><p class="ogmrc-next-place"><?php echo $e((string)$next['place']); ?></p><?php endif; ?>
            <div class="ogmrc-boxes">
                <?php foreach (['warmup' => 'ウォームアップ', 'drill' => 'ドリル', 'main_set' => 'メイン', 'cool_down' => 'さいご'] as $field => $label): ?>
                    <div class="ogmrc-box">
                        <p class="ogmrc-box-label"><?php echo $label; ?></p>
                        <p class="ogmrc-box-text"><?php echo !empty($next[$field]) ? nl2br($e((string)$next[$field])) : '—'; ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
        <?php if ($ogmrc_can_edit): ?>
            <details class="ogmrc-edit">
                <summary>この練習を編集する（コーチ）</summary>
                <?php echo $ogmrc_practice_form($next); ?>
                <?php echo $ogmrc_delete_form($next); ?>
            </details>
        <?php endif; ?>
    <?php endif; ?>

    <?php // この先の予定: 日付・内容・時間（または「大会」） ?>
    <p class="club-section-title">この先の予定</p>
    <?php if (empty($ogmrc_menu['upcoming'])): ?>
        <p class="club-empty">この先の予定はまだありません。</p>
    <?php else: ?>
        <ul class="ogmrc-schedule">
            <?php foreach ($ogmrc_menu['upcoming'] as $practice): ?>
                <li>
                    <div class="ogmrc-schedule-row">
                        <span class="ogmrc-schedule-date"><?php echo $e($ogmrc_date((string)$practice['practice_date'])); ?></span>
                        <span class="ogmrc-schedule-title"><?php echo $e((string)$practice['title']); ?></span>
                        <?php if ((int)$practice['is_race'] === 1): ?>
                            <span class="ogmrc-race">大会</span>
                        <?php else: ?>
                            <span class="ogmrc-schedule-time"><?php echo !empty($practice['start_time']) ? $e(substr((string)$practice['start_time'], 0, 5)) : ''; ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($ogmrc_can_edit): ?>
                        <details class="ogmrc-edit is-small">
                            <summary>編集</summary>
                            <?php echo $ogmrc_practice_form($practice); ?>
                            <?php echo $ogmrc_delete_form($practice); ?>
                        </details>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($ogmrc_can_edit): ?>
        <details class="ogmrc-edit">
            <summary>＋ 練習予定を追加する（コーチ）</summary>
            <?php echo $ogmrc_practice_form(null); ?>
        </details>
    <?php endif; ?>
</section>
