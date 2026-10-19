<?php
// クラブ画面の「大会」。表示締切日を過ぎた大会は出さない（back/tournament_list_lib.php）。
// 申し込みは陸連登録者だけ。申し込んでいる人は誰でも見られる。
// 使う変数: $races, $entries_by_title, $is_jaaf, $club, $user_id, $user_name, $e, $weekdays, $page_return_to, $same_person
//   $race_owner_email（任意）: 大会追加の窓の「通知を受け取るメールアドレス」に最初から入れる値
//   $races_guest（任意）: true なら未ログインの人向け。申し込んでいる人（会員の名前と記録）は出さない
$races_guest = $races_guest ?? false;
// 自分が追加し、自分で申し込み管理を行う大会（「⋯」の編集・申し込みを承認・削除を申請の窓をカードの下にまとめて出す）
$owned_races = [];
?>
<section class="club-panel" aria-label="大会">
    <?php if ($races_guest): ?>
        <p class="club-info">大会の申し込みは、<a href="../mypage/login.php">ログイン</a>した陸連登録者のみです。</p>
    <?php elseif ($is_jaaf && $club === MEMBER_CLUB_TOBG): ?>
        <div class="club-actions">
            <?php // 押すと下の窓で入力する（送り先は back/save_tournament.php。処理はマイページの大会追加と共通） ?>
            <button type="button" class="club-outline-btn" data-bs-toggle="modal" data-bs-target="#raceAddModal">＋ 大会情報を追加（陸連登録者）</button>
        </div>
    <?php elseif (!$is_jaaf): ?>
        <p class="club-info">大会の申し込みは陸連登録者のみです（申し込み状況や出場するメンバーは見られます）。</p>
    <?php endif; ?>

    <?php if (empty($races)): ?>
        <p class="club-empty">いま受け付けている大会はありません。</p>
    <?php endif; ?>

    <?php foreach ($races as $race): ?>
        <?php
        $race_time = strtotime($race['event_date']);
        $race_entries = $entries_by_title[$race['event_name']] ?? [];
        // 自分の申し込み（申し込みは名前で保存されている）
        $my_entry = null;
        foreach ($race_entries as $race_entry) {
            if ($same_person($race_entry['user_name'], $user_name)) {
                $my_entry = $race_entry;
            }
        }
        // 申し込んでいる人を種目ごとにまとめる（1人が複数種目なら、それぞれの種目に出す）
        $by_event = [];
        foreach ($race_entries as $race_entry) {
            foreach ($race_entry['events'] as $race_event) {
                $by_event[$race_event['name']][] = ['name' => $race_entry['user_name'], 'record' => $race_event['record']];
            }
        }
        ?>
        <?php
        $race_owned = !$races_guest && $race['self_managed'] && $race['owner_user_id'] === (int)($user_id ?? 0) && $race['owner_user_id'] > 0;
        if ($race_owned) {
            $owned_races[] = ['race' => $race, 'entries' => $race_entries];
        }
        ?>
        <?php // id は通知・ホームのリンクの飛び先（back/tournament_list_lib.php の tournament_page_path） ?>
        <article class="club-race" id="race-<?php echo (int)$race['id']; ?>">
            <div class="club-race-top">
                <span class="club-race-date">
                    <span class="club-race-day"><?php echo date('n/j', $race_time); ?></span>
                    <span class="club-race-weekday">（<?php echo $weekdays[(int)date('w', $race_time)]; ?>）</span>
                </span>
                <?php // 申込締切（会員の締切日。前日にリマインドの通知が届く日と同じ）を大きく。3日以内は赤 ?>
                <?php $race_deadline_time = strtotime($race['member_deadline']); ?>
                <span class="club-race-deadline<?php echo $race['deadline_soon'] ? ' is-soon' : ''; ?>">
                    <span class="club-race-deadline-label">申込締切</span>
                    <span class="club-race-deadline-date"><?php echo date('n/j', $race_deadline_time); ?><small>（<?php echo $weekdays[(int)date('w', $race_deadline_time)]; ?>）</small></span>
                    <span class="club-race-deadline-left"><?php echo $e($race['deadline_label']); ?></span>
                </span>
            </div>
            <div class="club-race-name-row">
                <h2 class="club-race-name"><?php echo $e($race['event_name']); ?></h2>
                <?php if ($race_owned): ?>
                    <?php // 追加した本人だけ: 編集・申し込みを承認・削除を申請（削除は管理者が確認してから行う） ?>
                    <div class="dropdown club-race-menu">
                        <button type="button" class="club-race-menu-btn" data-bs-toggle="dropdown" aria-expanded="false" aria-label="この大会の操作">⋯</button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#raceEditModal<?php echo (int)$race['id']; ?>">大会情報を編集</button></li>
                            <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#raceEntriesModal<?php echo (int)$race['id']; ?>">申し込みを承認（<?php echo count($race_entries); ?>件）</button></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="POST" action="../back/save_tournament.php" class="m-0" onsubmit="return confirm(<?php echo $e(json_encode('「' . $race['event_name'] . '」の削除を管理者に申請しますか？', JSON_UNESCAPED_UNICODE)); ?>);">
                                    <input type="hidden" name="action" value="request_delete">
                                    <input type="hidden" name="tournament_id" value="<?php echo (int)$race['id']; ?>">
                                    <input type="hidden" name="return_to" value="<?php echo $e($page_return_to); ?>">
                                    <button type="submit" class="dropdown-item text-danger">削除を申請</button>
                                </form>
                            </li>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($race['location'] !== ''): ?>
                <p class="club-race-place"><?php echo $e($race['location']); ?></p>
            <?php endif; ?>
            <?php if ($race['details'] !== ''): ?>
                <p class="club-race-details"><?php echo nl2br($e($race['details'])); ?></p>
            <?php endif; ?>
            <?php // 「申し込む」と「大会概要を見る」を横に並べる ?>
            <?php $race_can_apply = !$races_guest && $is_jaaf && $my_entry === null; ?>
            <?php $race_has_link = preg_match('#^https?://#', $race['official_link']) === 1; ?>
            <?php if ($race_can_apply || $race_has_link): ?>
                <div class="club-race-actions">
                    <?php if ($race_can_apply): ?>
                        <a class="club-race-apply" href="entry_form.php?tournament_id=<?php echo (int)$race['id']; ?>">申し込む</a>
                    <?php endif; ?>
                    <?php if ($race_has_link): ?>
                        <a class="club-race-link" href="<?php echo $e($race['official_link']); ?>" target="_blank" rel="noopener noreferrer">大会概要を見る<span aria-hidden="true">↗</span></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($my_entry !== null): ?>
                <?php // 申し込み済み: 薄いオレンジの帯。表示締切日の前（＝この一覧に出ている間）は取り消せる ?>
                <div class="club-race-mine">
                    <span>申し込み済み：<?php echo $e(implode('・', array_column($my_entry['events'], 'name')) ?: '種目なし'); ?></span>
                    <form method="POST" action="../back/delete_entry.php" class="m-0" onsubmit="return confirm(<?php echo $e(json_encode('「' . $race['event_name'] . '」の申し込みを取り消しますか？', JSON_UNESCAPED_UNICODE)); ?>);">
                        <input type="hidden" name="match_id" value="<?php echo (int)$my_entry['id']; ?>">
                        <input type="hidden" name="return_to" value="<?php echo $e($page_return_to); ?>">
                        <button type="submit" class="club-race-cancel">取り消す</button>
                    </form>
                </div>
            <?php endif; ?>

            <div class="club-race-buttons">
                <?php if (!$races_guest): ?>
                <details class="club-race-entries">
                    <summary>申し込んでいる人（<?php echo count($race_entries); ?>件）<span aria-hidden="true">▼</span></summary>
                    <?php if (empty($by_event)): ?>
                        <p class="club-race-entries-empty">まだ申し込みはありません。</p>
                    <?php else: ?>
                        <?php foreach ($by_event as $event_name => $event_people): ?>
                            <p class="club-race-event"><?php echo $e($event_name); ?></p>
                            <ul class="club-race-people">
                                <?php foreach ($event_people as $person): ?>
                                    <li<?php echo $same_person($person['name'], $user_name) ? ' class="is-me"' : ''; ?>>
                                        <span><?php echo $e($person['name']); ?></span>
                                        <span class="club-race-record"><?php echo $e($person['record'] !== '' ? $person['record'] : '-'); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </details>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</section>

<?php if (!$races_guest && $is_jaaf && $club === MEMBER_CLUB_TOBG): ?>
    <!-- 大会情報を追加する窓（陸連登録者だけ） -->
    <div class="modal fade" id="raceAddModal" tabindex="-1" aria-labelledby="raceAddModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <form class="modal-content" method="POST" action="../back/save_tournament.php" data-race-add-form data-race-form>
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="return_to" value="<?php echo $e($page_return_to); ?>">
                <div class="modal-header">
                    <h2 class="modal-title fs-5 fw-bold" id="raceAddModalTitle">大会情報を追加</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-secondary mb-3">追加した大会はチームに共有され、陸連登録者に通知されます。</p>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label small fw-bold mb-1" for="raceAddName">大会名</label>
                            <input type="text" class="form-control" id="raceAddName" name="event_name" required placeholder="例: 日田市記録会">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold mb-1" for="raceAddPlace">場所</label>
                            <input type="text" class="form-control" id="raceAddPlace" name="location" required placeholder="例: 日田市陸上競技場">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold mb-1" for="raceAddDate">開催日</label>
                            <input type="date" class="form-control" id="raceAddDate" name="event_date" required data-race-event-date>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold mb-1" for="raceAddDeadline">応募締切日（任意）</label>
                            <input type="date" class="form-control" id="raceAddDeadline" name="entry_deadline" data-race-entry-deadline>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold mb-1" for="raceAddLead">表示締切</label>
                            <select class="form-select" id="raceAddLead" name="deadline_lead_days" data-race-lead-days>
                                <?php for ($race_lead = TOURNAMENT_LEAD_DAYS_MIN; $race_lead <= TOURNAMENT_LEAD_DAYS_MAX; $race_lead++): ?>
                                    <option value="<?php echo $race_lead; ?>" <?php echo $race_lead === TOURNAMENT_LEAD_DAYS_DEFAULT ? 'selected' : ''; ?>>締切の<?php echo $race_lead; ?>日前</option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <span class="form-label small fw-bold mb-1 d-block">会員の申込締切（自動）</span>
                            <div class="form-control bg-light text-secondary" data-race-display-deadline>—</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold mb-1" for="raceAddDetails">概要</label>
                            <textarea class="form-control" id="raceAddDetails" name="details" rows="2" required placeholder="例: 今年度最後の記録会です。実施種目はリンク先を確認してください。"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold mb-1" for="raceAddLink">大会概要リンク（任意）</label>
                            <input type="url" class="form-control" id="raceAddLink" name="official_link" placeholder="https://...">
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="raceAddSelfManaged" name="self_managed" value="1" data-race-self-managed>
                                <label class="form-check-label small" for="raceAddSelfManaged">この大会の申し込み対応（編集・削除・承認）を自分で行う</label>
                            </div>
                            <p class="small text-secondary mt-1 mb-0">チェックしない場合は、追加のあとの申し込み管理を管理者が行います。</p>
                        </div>
                        <div class="col-12" data-race-owner-email hidden>
                            <label class="form-label small fw-bold mb-1" for="raceAddEmail">申込の通知を受け取るメールアドレス</label>
                            <input type="email" class="form-control" id="raceAddEmail" name="owner_notify_email" placeholder="you@example.com" value="<?php echo $e((string)($race_owner_email ?? '')); ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">やめる</button>
                    <button type="submit" class="btn btn-primary">追加する</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php foreach ($owned_races as $owned): ?>
    <?php $owned_race = $owned['race']; $owned_id = (int)$owned_race['id']; ?>
    <!-- 大会情報を編集する窓（追加した本人だけ） -->
    <div class="modal fade" id="raceEditModal<?php echo $owned_id; ?>" tabindex="-1" aria-labelledby="raceEditModalTitle<?php echo $owned_id; ?>" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <form class="modal-content" method="POST" action="../back/save_tournament.php" data-race-form>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="tournament_id" value="<?php echo $owned_id; ?>">
                <input type="hidden" name="return_to" value="<?php echo $e($page_return_to); ?>">
                <div class="modal-header">
                    <h2 class="modal-title fs-5 fw-bold" id="raceEditModalTitle<?php echo $owned_id; ?>">大会情報を編集</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label small fw-bold mb-1" for="raceEditName<?php echo $owned_id; ?>">大会名</label>
                            <input type="text" class="form-control" id="raceEditName<?php echo $owned_id; ?>" name="event_name" required value="<?php echo $e($owned_race['event_name']); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold mb-1" for="raceEditPlace<?php echo $owned_id; ?>">場所</label>
                            <input type="text" class="form-control" id="raceEditPlace<?php echo $owned_id; ?>" name="location" required value="<?php echo $e($owned_race['location']); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold mb-1" for="raceEditDate<?php echo $owned_id; ?>">開催日</label>
                            <input type="date" class="form-control" id="raceEditDate<?php echo $owned_id; ?>" name="event_date" required data-race-event-date value="<?php echo $e($owned_race['event_date']); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold mb-1" for="raceEditDeadline<?php echo $owned_id; ?>">応募締切日（任意）</label>
                            <input type="date" class="form-control" id="raceEditDeadline<?php echo $owned_id; ?>" name="entry_deadline" data-race-entry-deadline value="<?php echo $e((string)($owned_race['entry_deadline'] ?? '')); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold mb-1" for="raceEditLead<?php echo $owned_id; ?>">表示締切</label>
                            <select class="form-select" id="raceEditLead<?php echo $owned_id; ?>" name="deadline_lead_days" data-race-lead-days>
                                <?php for ($race_lead = TOURNAMENT_LEAD_DAYS_MIN; $race_lead <= TOURNAMENT_LEAD_DAYS_MAX; $race_lead++): ?>
                                    <option value="<?php echo $race_lead; ?>" <?php echo $race_lead === (int)$owned_race['deadline_lead_days'] ? 'selected' : ''; ?>>締切の<?php echo $race_lead; ?>日前</option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <span class="form-label small fw-bold mb-1 d-block">会員の申込締切（自動）</span>
                            <div class="form-control bg-light text-secondary" data-race-display-deadline>—</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold mb-1" for="raceEditDetails<?php echo $owned_id; ?>">概要</label>
                            <textarea class="form-control" id="raceEditDetails<?php echo $owned_id; ?>" name="details" rows="2" required><?php echo $e($owned_race['details']); ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold mb-1" for="raceEditLink<?php echo $owned_id; ?>">大会概要リンク（任意）</label>
                            <input type="url" class="form-control" id="raceEditLink<?php echo $owned_id; ?>" name="official_link" placeholder="https://..." value="<?php echo $e($owned_race['official_link']); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold mb-1" for="raceEditEmail<?php echo $owned_id; ?>">申込の通知を受け取るメールアドレス</label>
                            <input type="email" class="form-control" id="raceEditEmail<?php echo $owned_id; ?>" name="owner_notify_email" placeholder="you@example.com" value="<?php echo $e($owned_race['owner_notify_email']); ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">やめる</button>
                    <button type="submit" class="btn btn-primary">更新する</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 申し込みを承認する窓（追加した本人だけ）。状態を変えると、申し込んだ人に通知が届く -->
    <div class="modal fade" id="raceEntriesModal<?php echo $owned_id; ?>" tabindex="-1" aria-labelledby="raceEntriesModalTitle<?php echo $owned_id; ?>" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title fs-5 fw-bold" id="raceEntriesModalTitle<?php echo $owned_id; ?>">申し込みを承認</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-secondary mb-3"><?php echo $e($owned_race['event_name']); ?>　状態を変えると、申し込んだ人に通知が届きます。</p>
                    <?php if (empty($owned['entries'])): ?>
                        <p class="club-race-entries-empty mb-0">まだ申し込みはありません。</p>
                    <?php else: ?>
                        <ul class="club-race-approve">
                            <?php foreach ($owned['entries'] as $owned_entry): ?>
                                <li>
                                    <div class="club-race-approve-who">
                                        <span class="fw-bold"><?php echo $e($owned_entry['user_name']); ?></span>
                                        <span class="club-race-approve-events"><?php echo $e(implode('・', array_map(static fn(array $event): string => $event['name'] . ($event['record'] !== '' ? ' ' . $event['record'] : ''), $owned_entry['events'])) ?: '種目なし'); ?></span>
                                    </div>
                                    <form method="POST" action="../back/save_tournament.php" class="club-race-approve-form">
                                        <input type="hidden" name="action" value="entry_status">
                                        <input type="hidden" name="match_id" value="<?php echo (int)$owned_entry['id']; ?>">
                                        <input type="hidden" name="return_to" value="<?php echo $e($page_return_to); ?>">
                                        <select name="status" class="form-select form-select-sm" aria-label="<?php echo $e($owned_entry['user_name']); ?>さんの申し込みの状態">
                                            <?php foreach (TOURNAMENT_ENTRY_STATUSES as $entry_status): ?>
                                                <option value="<?php echo $e($entry_status); ?>" <?php echo (string)$owned_entry['status'] === $entry_status ? 'selected' : ''; ?>><?php echo $e($entry_status); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-primary">保存</button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<script>
    // 大会の追加・編集の窓: 会員の申込締切（応募締切日か開催日の「表示締切」日前）を出す。自分で管理するときだけメールアドレスの欄を出す
    (function () {
        document.querySelectorAll('[data-race-form]').forEach(function (form) {
            var eventDate = form.querySelector('[data-race-event-date]');
            var entryDeadline = form.querySelector('[data-race-entry-deadline]');
            var leadDays = form.querySelector('[data-race-lead-days]');
            var display = form.querySelector('[data-race-display-deadline]');
            var selfManaged = form.querySelector('[data-race-self-managed]');
            var emailWrap = form.querySelector('[data-race-owner-email]');
            var weekdays = ['日', '月', '火', '水', '木', '金', '土'];

            function refreshDeadline() {
                var base = entryDeadline.value || eventDate.value;
                if (!base) {
                    display.textContent = '—';
                    return;
                }
                var date = new Date(base + 'T00:00:00');
                date.setDate(date.getDate() - parseInt(leadDays.value, 10));
                display.textContent = (date.getMonth() + 1) + '/' + date.getDate() + '（' + weekdays[date.getDay()] + '）';
            }
            function refreshEmail() {
                if (!selfManaged) {
                    return;
                }
                emailWrap.hidden = !selfManaged.checked;
                emailWrap.querySelector('input').required = selfManaged.checked;
            }
            [eventDate, entryDeadline, leadDays].forEach(function (input) {
                input.addEventListener('change', refreshDeadline);
                input.addEventListener('input', refreshDeadline);
            });
            if (selfManaged) {
                selfManaged.addEventListener('change', refreshEmail);
            }
            refreshDeadline();
            refreshEmail();
        });
    })();
</script>
