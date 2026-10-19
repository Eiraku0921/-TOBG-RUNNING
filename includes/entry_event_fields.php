<?php
// 試合申し込みの「種目1〜3」と「応募記録」（申し込みフォームと申し込みの編集で共通）。
// 種目は 区分 → 種目 の2段階で選び、応募記録は種目に合わせたプルダウン（秒・分・m など）で選ぶ。動きは js/entry_event_picker.js
// 使う変数: $entry_values（任意。編集のときの今の値 ['event_name1' => …, 'pb_time1' => …]）
//           $entry_pb_values（任意。NEXT PR の自己ベスト。種目を選んだときに応募記録の初期値にする）
require_once __DIR__ . '/../back/entry_event_lib.php';
$entry_values = $entry_values ?? [];
$entry_pb_values = $entry_pb_values ?? [];
$entry_event_config = [
    'groups' => ENTRY_EVENT_GROUPS,
    'pb' => (object)$entry_pb_values,
];
?>
<div class="entry-events" data-entry-events>
    <?php for ($entry_slot = 1; $entry_slot <= ENTRY_EVENT_SLOTS; $entry_slot++): ?>
        <?php $entry_slot_required = $entry_slot === 1; ?>
        <fieldset class="entry-event-slot" data-entry-slot data-required="<?php echo $entry_slot_required ? '1' : '0'; ?>"
            data-initial-event="<?php echo htmlspecialchars((string)($entry_values['event_name' . $entry_slot] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
            data-initial-record="<?php echo htmlspecialchars((string)($entry_values['pb_time' . $entry_slot] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            <legend class="entry-event-title">
                種目<?php echo $entry_slot; ?>
                <span class="entry-event-badge<?php echo $entry_slot_required ? ' is-required' : ''; ?>"><?php echo $entry_slot_required ? '必須' : '任意'; ?></span>
            </legend>
            <div class="entry-event-pick">
                <select class="form-select" data-entry-group aria-label="種目<?php echo $entry_slot; ?>の区分"<?php echo $entry_slot_required ? ' required' : ''; ?>>
                    <option value=""><?php echo $entry_slot_required ? '区分を選ぶ' : '選ばない'; ?></option>
                    <?php foreach (array_keys(ENTRY_EVENT_GROUPS) as $entry_group): ?>
                        <option value="<?php echo htmlspecialchars($entry_group, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($entry_group, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="form-select" name="event_name<?php echo $entry_slot; ?>" data-entry-event aria-label="種目<?php echo $entry_slot; ?>" disabled>
                    <option value="">種目を選ぶ</option>
                </select>
            </div>
            <div class="entry-record" data-entry-record hidden>
                <span class="entry-record-label">応募記録<small>（走りたい組の目安）</small></span>
                <div class="entry-record-parts" data-entry-record-parts></div>
                <p class="entry-record-hint" data-entry-record-hint hidden>NEXT PR の自己ベストを入れました。変えることもできます。</p>
            </div>
            <input type="hidden" name="pb_time<?php echo $entry_slot; ?>" value="" data-entry-record-value>
        </fieldset>
    <?php endfor; ?>
</div>
<script type="application/json" id="entryEventConfig"><?php echo json_encode($entry_event_config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?></script>
<script src="../js/entry_event_picker.js?v=<?php echo (int)@filemtime(__DIR__ . '/../js/entry_event_picker.js'); ?>"></script>
