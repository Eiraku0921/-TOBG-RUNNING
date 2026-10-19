<?php
// NEXT PR の「種目を追加」モーダル（NEXT PR のページで使う）。見た目は css/next_pr_cards.css。
// 使う変数:
//   $np_modal_events          種目マスタ（fetch_pr_events の戻り値）
//   $np_modal_tab_event_ids   すでにタブにある種目ID
//   $np_modal_form_action     追加フォームの送り先（mypage/next_pr.php への相対パス）
//   $np_modal_added_url       追加済みの種目を押したときの行き先を返す関数 fn(int $event_id): string
//   $np_modal_initial_category 最初に開くカテゴリ（NEXT_PR_CATEGORY_TRACK / NEXT_PR_CATEGORY_STRENGTH）

$np_modal_e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$np_modal_catalog = next_pr_event_catalog($np_modal_events);
?>
<div class="modal fade" id="addEventModal" tabindex="-1" aria-labelledby="addEventModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5 fw-bold" id="addEventModalTitle">種目を追加</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
            </div>
            <div class="modal-body">
                <div class="segment" role="tablist" aria-label="カテゴリ">
                    <?php foreach (NEXT_PR_CATEGORY_LABELS as $np_category => $np_category_label): ?>
                        <button type="button" class="segment-btn" role="tab" data-catalog-tab="<?php echo $np_modal_e($np_category); ?>" aria-controls="catalog-<?php echo $np_modal_e($np_category); ?>" aria-selected="<?php echo $np_category === $np_modal_initial_category ? 'true' : 'false'; ?>"><?php echo $np_modal_e($np_category_label); ?></button>
                    <?php endforeach; ?>
                </div>
                <?php foreach ($np_modal_catalog as $np_category => $np_groups): ?>
                    <form method="POST" action="<?php echo $np_modal_e($np_modal_form_action); ?>" id="catalog-<?php echo $np_modal_e($np_category); ?>" role="tabpanel" <?php echo $np_category === $np_modal_initial_category ? '' : 'hidden'; ?>>
                        <input type="hidden" name="action" value="add_event">
                        <?php foreach ($np_groups as $np_group_label => $np_group_events): ?>
                            <div class="catalog-group">
                                <h3 class="catalog-group-title"><?php echo $np_modal_e($np_group_label); ?></h3>
                                <div class="catalog-items">
                                    <?php foreach ($np_group_events as $np_catalog_event): ?>
                                        <?php if (in_array($np_catalog_event['id'], $np_modal_tab_event_ids, true)): ?>
                                            <a class="catalog-item is-added" href="<?php echo $np_modal_e($np_modal_added_url($np_catalog_event['id'])); ?>"><span class="catalog-check" aria-hidden="true">✓</span><?php echo $np_modal_e($np_catalog_event['label']); ?><span class="visually-hidden">（追加済み）</span></a>
                                        <?php else: ?>
                                            <button type="submit" class="catalog-item" name="event_id" value="<?php echo (int)$np_catalog_event['id']; ?>"><?php echo $np_modal_e($np_catalog_event['label']); ?></button>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </form>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<script>
(() => {
    // 種目追加モーダル: 陸上競技 / 筋トレ の切り替え
    document.querySelectorAll('#addEventModal [data-catalog-tab]').forEach((tab) => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('#addEventModal [data-catalog-tab]').forEach((other) => {
                const selected = other === tab;
                other.setAttribute('aria-selected', selected ? 'true' : 'false');
                document.getElementById(other.getAttribute('aria-controls')).hidden = !selected;
            });
        });
    });
})();
</script>
