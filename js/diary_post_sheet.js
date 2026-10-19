/*
 * 練習日記の登録シート（includes/diary_post_sheet.php のフォーム）の動き。見た目は css/diary_post_sheet.css。
 * ログイン中は全ページのヘッダーから読み込み、どのページからでも同じシートで投稿できる。
 *
 * 中身: 写真・動画の選択 / 練習タイプと強度の切り替え / ウエイト・ランニング・大会の種目入力 / 投稿前のチェック
 * サーバー側の値（風速を入れる種目・公認の上限）は、ページが window.TOBG_DIARY_SHEET で渡す。
 */

// 練習日記の登録シートが無いページ（未ログイン）では何もしない
if (document.getElementById('diaryPostForm')) {
var SHEET_CONFIG = window.TOBG_DIARY_SHEET || {};
// 下書きを入力欄へ戻すための入口（各部品が登録し、いちばん下の下書きの処理が呼ぶ）
var SHEET_RESTORE = {};

(function () {
    var imageInput = document.getElementById('training_image');
    var videoInput = document.getElementById('training_video');
    var imageName = document.getElementById('training_image_name');
    var videoName = document.getElementById('training_video_name');
    var clearButtons = document.querySelectorAll('[data-clear-target]');

    if (!imageInput || !videoInput) {
        return;
    }

    function updateFileName(input, displayNode) {
        if (!displayNode) {
            return;
        }
        var file = input.files && input.files[0] ? input.files[0] : null;
        displayNode.textContent = file ? file.name : '未選択';
    }

    function clearInput(input, displayNode) {
        input.value = '';
        if (displayNode) {
            displayNode.textContent = '未選択';
        }
    }

    imageInput.addEventListener('change', function () {
        updateFileName(imageInput, imageName);
    });

    videoInput.addEventListener('change', function () {
        updateFileName(videoInput, videoName);

        var file = videoInput.files && videoInput.files[0] ? videoInput.files[0] : null;
        if (!file) {
            return;
        }

        if (file.type.indexOf('video/') !== 0) {
            return;
        }

        var url = URL.createObjectURL(file);
        var video = document.createElement('video');
        video.preload = 'metadata';
        video.onloadedmetadata = function () {
            URL.revokeObjectURL(url);
            // 案内は「15秒以内」だが、少しはみ出した動画も投稿できるよう、実際は20秒まで受け付ける
            if (video.duration > 20) {
                alert('動画は15秒以内にしてください。');
                clearInput(videoInput, videoName);
            }
        };
        video.src = url;
    });

    clearButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            var targetId = button.getAttribute('data-clear-target');
            if (targetId === 'training_image') {
                clearInput(imageInput, imageName);
                return;
            }
            if (targetId === 'training_video') {
                clearInput(videoInput, videoName);
            }
        });
    });
})();

// 写真・動画の「＋ 写真を追加」ボタン: ファイルを選んでいるかで見た目と文字を切り替える（日記・ギャラリー共通）。
// 「選択を削除」や長すぎる動画のときは、別の処理がファイル名の欄（〇〇_name）を書き換えるので、それも見て合わせる
(function () {
    document.querySelectorAll('[data-file-pick]').forEach(function (wrap) {
        var input = wrap.querySelector('input[type="file"]');
        var text = wrap.querySelector('[data-file-pick-text]');
        if (!input || !text) {
            return;
        }
        function sync() {
            var hasFile = !!(input.files && input.files.length > 0);
            wrap.classList.toggle('has-file', hasFile);
            text.textContent = text.getAttribute(hasFile ? 'data-filled-text' : 'data-empty-text');
        }
        input.addEventListener('change', sync);
        var nameNode = document.getElementById(input.id + '_name');
        if (nameNode && window.MutationObserver) {
            new MutationObserver(sync).observe(nameNode, { childList: true, characterData: true, subtree: true });
        }
        sync();
    });
})();

(function () {
    var typeShortSprint = document.getElementById('diaryTypeShortSprint');
    var typeLongSprint = document.getElementById('diaryTypeLongSprint');
    var typeWeight = document.getElementById('diaryTypeWeight');
    var typeJog = document.getElementById('diaryTypeJog');
    var intensitySelect = document.getElementById('intensity');
    var diaryTypeSection = document.getElementById('diaryTypeSection');
    var weightPanel = document.getElementById('dyWeightPanel');
    var runningPanel = document.getElementById('dyRunningPanel');
    var racePanel = document.getElementById('dyRacePanel');
    var jogPanel = document.getElementById('dyJogPanel');
    var menuTextLabel = document.getElementById('menuTextLabel');
    var menuTextInput = document.getElementById('menu_text');
    var trainingTypeField = document.getElementById('trainingTypeField');

    if (!typeShortSprint || !typeLongSprint || !typeWeight || !typeJog || !jogPanel || !intensitySelect || !diaryTypeSection || !weightPanel || !runningPanel || !racePanel || !menuTextLabel || !menuTextInput || !trainingTypeField) {
        return;
    }

    function checkedTrainingType() {
        if (typeWeight.checked) {
            return 'weight';
        }
        if (typeJog.checked) {
            return 'jog';
        }
        if (typeLongSprint.checked) {
            return 'long_sprint';
        }
        return 'short_sprint';
    }

    function applyPanelVisibility() {
        if (intensitySelect.value === 'race') {
            diaryTypeSection.hidden = true;
            weightPanel.hidden = true;
            runningPanel.hidden = true;
            jogPanel.hidden = true;
            racePanel.hidden = false;
            menuTextLabel.textContent = '試合名';
            menuTextInput.placeholder = '例: 県陸上競技選手権大会';
            trainingTypeField.value = 'race';
            return;
        }

        diaryTypeSection.hidden = false;
        racePanel.hidden = true;
        menuTextLabel.textContent = '練習タイトル';
        menuTextInput.placeholder = '例: フォームを綺麗に！';

        var trainingType = checkedTrainingType();
        weightPanel.hidden = trainingType !== 'weight';
        jogPanel.hidden = trainingType !== 'jog';
        runningPanel.hidden = trainingType !== 'short_sprint' && trainingType !== 'long_sprint';
        trainingTypeField.value = trainingType;
    }

    [typeShortSprint, typeLongSprint, typeJog, typeWeight].forEach(function (radio) {
        radio.addEventListener('change', applyPanelVisibility);
    });
    // 練習強度を「ウエイト」にしたら、練習タイプもウエイトを選んだ状態にする（あとから変えてもよい）。
    // 「ウエイト」から別の強度に変えたときは、練習タイプを最初のショートスプに戻す
    var previousIntensity = intensitySelect.value;
    intensitySelect.addEventListener('change', function () {
        if (intensitySelect.value === 'weight') {
            typeWeight.checked = true;
        } else if (previousIntensity === 'weight') {
            typeShortSprint.checked = true;
        }
        previousIntensity = intensitySelect.value;
        applyPanelVisibility();
    });

    applyPanelVisibility();

    // 下書きの強度と練習タイプに切り替える（強度を変えたときの自動切り替えは通さない）
    SHEET_RESTORE.type = function (intensity, trainingType) {
        intensitySelect.value = intensity;
        previousIntensity = intensitySelect.value;
        var radio = { long_sprint: typeLongSprint, jog: typeJog, weight: typeWeight }[trainingType] || typeShortSprint;
        radio.checked = true;
        radio.dispatchEvent(new Event('change', { bubbles: true }));
    };
})();

// ジョグの距離: 1行ずつ「整数 km（0〜42）」と「小数第1位（0〜9）」を選ぶ。選ぶたびに送信用の JSON と合計を更新する
// （ほかの種目のような「この内容で確定する」は無い。0.0km の行は送らない）
(function () {
    var rowsContainer = document.getElementById('jogRowsContainer');
    var addRowBtn = document.getElementById('jogAddRowBtn');
    var jsonField = document.getElementById('jogEntriesJsonField');
    var totalEl = document.getElementById('jogTotal');
    var form = document.getElementById('diaryPostForm');
    if (!rowsContainer || !addRowBtn || !jsonField || !totalEl || !form) {
        return;
    }
    var MAX_WHOLE_KM = 42;

    function buildSelect(className, label, max) {
        var select = document.createElement('select');
        select.className = 'form-select form-select-sm ' + className;
        select.setAttribute('aria-label', label);
        for (var i = 0; i <= max; i++) {
            var option = document.createElement('option');
            option.value = String(i);
            option.textContent = String(i);
            select.appendChild(option);
        }
        return select;
    }

    function rowKm(row) {
        var whole = parseInt(row.querySelector('.jog-row-whole').value, 10) || 0;
        var tenth = parseInt(row.querySelector('.jog-row-tenth').value, 10) || 0;
        return Math.round((whole + tenth / 10) * 10) / 10;
    }

    function sync() {
        var entries = [];
        var total = 0;
        Array.prototype.forEach.call(rowsContainer.children, function (row) {
            var km = rowKm(row);
            if (km > 0) {
                entries.push({ distance_km: km });
                total += km;
            }
        });
        jsonField.value = JSON.stringify(entries);
        totalEl.textContent = '合計 ' + (Math.round(total * 10) / 10).toFixed(1) + 'km';
        // 投稿ボタンの押せる・押せないを、投稿前のチェック側で更新してもらう
        form.dispatchEvent(new CustomEvent('jogchange', { detail: { count: entries.length } }));
    }

    function addRow() {
        var row = document.createElement('div');
        row.className = 'jog-row';
        var whole = buildSelect('jog-row-whole', 'km（整数）', MAX_WHOLE_KM);
        var tenth = buildSelect('jog-row-tenth', 'km（小数第1位）', 9);
        var dot = document.createElement('span');
        dot.className = 'jog-row-sep';
        dot.textContent = '.';
        var unit = document.createElement('span');
        unit.className = 'jog-row-unit';
        unit.textContent = 'km';
        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'jog-row-remove';
        remove.setAttribute('aria-label', 'この距離を削除');
        remove.textContent = '×';
        remove.addEventListener('click', function () {
            row.remove();
            if (rowsContainer.children.length === 0) {
                addRow(); // 最低1行は残す
            }
            sync();
        });
        row.appendChild(whole);
        row.appendChild(dot);
        row.appendChild(tenth);
        row.appendChild(unit);
        row.appendChild(remove);
        rowsContainer.appendChild(row);
        row.addEventListener('change', sync);
        return row;
    }

    addRowBtn.addEventListener('click', function () {
        addRow().querySelector('.jog-row-whole').focus();
        sync();
    });

    addRow();
    sync();

    // 下書きのジョグの距離を行に戻す（無ければ空の1行）
    SHEET_RESTORE.jog = function (entries) {
        rowsContainer.innerHTML = '';
        (entries || []).forEach(function (entry) {
            var tenths = Math.round((Number(entry.distance_km) || 0) * 10);
            if (tenths <= 0) {
                return;
            }
            var row = addRow();
            row.querySelector('.jog-row-whole').value = String(Math.min(MAX_WHOLE_KM, Math.floor(tenths / 10)));
            row.querySelector('.jog-row-tenth').value = String(tenths % 10);
        });
        if (rowsContainer.children.length === 0) {
            addRow();
        }
        sync();
    };
})();

function attachComboDropdown(inputEl, datalistEl) {
    if (!inputEl || !datalistEl) {
        return;
    }

    var wrapper = inputEl.parentNode;
    if (wrapper) {
        wrapper.style.position = 'relative';
    }

    var menu = document.createElement('div');
    menu.className = 'combo-dropdown-menu';
    menu.hidden = true;
    if (wrapper) {
        wrapper.appendChild(menu);
    }

    function currentOptions() {
        return Array.prototype.map.call(datalistEl.querySelectorAll('option'), function (opt) {
            return opt.value;
        });
    }

    function renderMenu() {
        var query = inputEl.value.trim().toLowerCase();
        var options = currentOptions().filter(function (value) {
            return query === '' || value.toLowerCase().indexOf(query) !== -1;
        });

        menu.innerHTML = '';
        if (options.length === 0) {
            menu.hidden = true;
            return;
        }

        options.forEach(function (value) {
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'combo-dropdown-item';
            item.textContent = value;
            item.addEventListener('mousedown', function (event) {
                event.preventDefault();
                inputEl.value = value;
                menu.hidden = true;
                inputEl.dispatchEvent(new Event('input', { bubbles: true }));
                inputEl.dispatchEvent(new Event('change', { bubbles: true }));
            });
            menu.appendChild(item);
        });

        menu.hidden = false;
    }

    inputEl.addEventListener('focus', renderMenu);
    inputEl.addEventListener('input', renderMenu);
    inputEl.addEventListener('blur', function () {
        setTimeout(function () {
            menu.hidden = true;
        }, 150);
    });
}

(function () {
    var weightPanel = document.getElementById('dyWeightPanel');
    var weightSetsJsonField = document.getElementById('weightSetsJsonField');

    if (!weightPanel || !weightSetsJsonField) {
        return;
    }

    var exerciseOptionsByPart = {
        '胸': ['ベンチプレス', 'インクラインベンチプレス', 'ダンベルプレス', 'ダンベルフライ', 'チェストプレス(マシン)', 'プッシュアップ', 'ディップス'],
        '背中': ['ラットプルダウン', 'ベントオーバーロウ', 'ワンハンドロウ', 'チンニング(懸垂)', 'シーテッドロウ', 'Tバーロウ'],
        '腕': ['バーベルカール', 'ダンベルカール', 'ハンマーカール', 'トライセプスエクステンション', 'ケーブルプレスダウン', 'ナローベンチプレス', 'プリーチャーカール'],
        '下半身': ['スクワット', 'デッドリフト', 'レッグプレス', 'レッグエクステンション', 'レッグカール', 'ランジ', 'ヒップスラスト', 'カーフレイズ', 'ブルガリアンスクワット']
    };

    var repsDatalist = document.getElementById('weightRepsOptions');
    var setsDatalist = document.getElementById('weightSetsOptions');
    var step5Btn = document.getElementById('weightStep5');
    var step25Btn = document.getElementById('weightStep25');
    var addBtn = document.getElementById('weightAddBtn');
    var addRowBtn = document.getElementById('weightAddRowBtn');
    var entryList = document.getElementById('weightEntryList');
    var rowsContainer = document.getElementById('weightRowsContainer');

    var rowCounter = 0;

    function populateSimpleDatalist(datalistEl, values) {
        if (!datalistEl) {
            return;
        }
        datalistEl.innerHTML = '';
        values.forEach(function (value) {
            var opt = document.createElement('option');
            opt.value = String(value);
            datalistEl.appendChild(opt);
        });
    }

    var repsRange = [];
    for (var r = 5; r <= 20; r++) {
        repsRange.push(r);
    }
    populateSimpleDatalist(repsDatalist, repsRange);
    populateSimpleDatalist(setsDatalist, [1, 2, 3, 4, 5, 6, 7]);

    function populateExerciseDatalist(datalistEl, part) {
        if (!datalistEl) {
            return;
        }
        var options = exerciseOptionsByPart[part] || [];
        datalistEl.innerHTML = '';
        options.forEach(function (name) {
            var opt = document.createElement('option');
            opt.value = name;
            datalistEl.appendChild(opt);
        });
    }

    function buildLoadOptionsFor(selectEl) {
        if (!selectEl) {
            return;
        }
        var step = step25Btn && step25Btn.checked ? 2.5 : 5;
        var current = selectEl.value;
        selectEl.innerHTML = '';

        var bodyweightOpt = document.createElement('option');
        bodyweightOpt.value = '自重';
        bodyweightOpt.textContent = '自重';
        selectEl.appendChild(bodyweightOpt);

        for (var w = 5; w <= 120 + 0.001; w += step) {
            var value = Math.round(w * 10) / 10;
            var opt = document.createElement('option');
            opt.value = String(value);
            opt.textContent = value + 'kg';
            selectEl.appendChild(opt);
        }

        var customOpt = document.createElement('option');
        customOpt.value = 'custom';
        customOpt.textContent = 'その他(自由入力)';
        selectEl.appendChild(customOpt);

        if (current && Array.prototype.some.call(selectEl.options, function (o) { return o.value === current; })) {
            selectEl.value = current;
        }
    }

    function refreshAllLoadOptions() {
        if (!rowsContainer) {
            return;
        }
        rowsContainer.querySelectorAll('.weight-row-load').forEach(function (sel) {
            buildLoadOptionsFor(sel);
        });
    }

    [step5Btn, step25Btn].forEach(function (btn) {
        if (btn) {
            btn.addEventListener('change', refreshAllLoadOptions);
        }
    });

    function createWeightRow() {
        rowCounter += 1;
        var datalistId = 'weightExerciseOptions_' + rowCounter;

        var row = document.createElement('div');
        row.className = 'row g-2 align-items-center weight-entry-row mb-2';
        row.setAttribute('data-weight-row', '');
        row.innerHTML =
            '<div class="col-6 col-md-2">' +
                '<select class="form-select form-select-sm weight-row-part" aria-label="部位">' +
                    '<option value="胸">胸</option>' +
                    '<option value="背中">背中</option>' +
                    '<option value="腕">腕</option>' +
                    '<option value="下半身">下半身</option>' +
                '</select>' +
            '</div>' +
            '<div class="col-6 col-md-3">' +
                '<input class="form-control form-control-sm weight-row-exercise" type="text" placeholder="種目名（選択 or 入力）" autocomplete="off" aria-label="種目名">' +
                '<datalist id="' + datalistId + '"></datalist>' +
            '</div>' +
            '<div class="col-4 col-md-2">' +
                '<input class="form-control form-control-sm weight-row-reps" type="number" min="1" max="100" placeholder="回数" aria-label="回数">' +
            '</div>' +
            '<div class="col-4 col-md-2">' +
                '<input class="form-control form-control-sm weight-row-sets" type="number" min="1" max="20" placeholder="セット数" aria-label="セット数">' +
            '</div>' +
            '<div class="col-4 col-md-2">' +
                '<select class="form-select form-select-sm weight-row-load" aria-label="重量"></select>' +
            '</div>' +
            '<div class="col-auto ms-auto d-grid">' +
                '<button type="button" class="btn btn-outline-danger btn-sm weight-row-remove" aria-label="この行を削除">×</button>' +
            '</div>';

        rowsContainer.appendChild(row);

        var partSelect = row.querySelector('.weight-row-part');
        var exerciseDatalist = row.querySelector('datalist');
        var loadSelect = row.querySelector('.weight-row-load');
        var removeBtn = row.querySelector('.weight-row-remove');

        populateExerciseDatalist(exerciseDatalist, partSelect.value);
        partSelect.addEventListener('change', function () {
            populateExerciseDatalist(exerciseDatalist, partSelect.value);
        });
        attachComboDropdown(row.querySelector('.weight-row-exercise'), exerciseDatalist);
        attachComboDropdown(row.querySelector('.weight-row-reps'), repsDatalist);
        attachComboDropdown(row.querySelector('.weight-row-sets'), setsDatalist);

        buildLoadOptionsFor(loadSelect);

        removeBtn.addEventListener('click', function () {
            if (rowsContainer.querySelectorAll('[data-weight-row]').length <= 1) {
                row.querySelector('.weight-row-exercise').value = '';
                row.querySelector('.weight-row-reps').value = '';
                row.querySelector('.weight-row-sets').value = '';
                partSelect.value = '胸';
                populateExerciseDatalist(exerciseDatalist, '胸');
                buildLoadOptionsFor(loadSelect);
                return;
            }
            row.remove();
        });

        return row;
    }

    function resetWeightRows() {
        rowsContainer.innerHTML = '';
        createWeightRow();
    }

    if (rowsContainer) {
        createWeightRow();
    }

    if (addRowBtn) {
        addRowBtn.addEventListener('click', function () {
            createWeightRow();
        });
    }

    var confirmedWeightSets = [];
    var weightEntryUid = 0;

    function syncWeightSetsField() {
        var payload = confirmedWeightSets.map(function (entry) {
            return {
                body_part: entry.body_part,
                exercise_name: entry.exercise_name,
                reps: entry.reps,
                sets: entry.sets,
                weight_kg: entry.weight_kg,
                is_bodyweight: entry.is_bodyweight,
                weight_note: entry.weight_note
            };
        });
        weightSetsJsonField.value = JSON.stringify(payload);
    }

    function buildDisplayLabel(entry) {
        var loadLabel;
        if (entry.is_bodyweight) {
            loadLabel = '自重';
        } else if (entry.weight_kg !== null) {
            loadLabel = entry.weight_kg + 'kg';
        } else {
            loadLabel = entry.weight_note || '重量未設定';
        }

        var detailPieces = [loadLabel];
        if (entry.reps !== null) {
            detailPieces.push(entry.reps + '回');
        }
        if (entry.sets !== null) {
            detailPieces.push(entry.sets + 'セット');
        }

        return entry.body_part + ': ' + entry.exercise_name + ' ' + detailPieces.join(' x ');
    }

    function appendEntryChip(entry) {
        var chip = document.createElement('div');
        chip.className = 'weight-entry-chip';
        var label = document.createElement('span');
        label.textContent = buildDisplayLabel(entry);
        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.textContent = '削除';
        removeBtn.addEventListener('click', function () {
            confirmedWeightSets = confirmedWeightSets.filter(function (item) { return item.uid !== entry.uid; });
            syncWeightSetsField();
            chip.remove();
        });
        chip.appendChild(label);
        chip.appendChild(removeBtn);
        entryList.appendChild(chip);
    }

    if (addBtn) {
        addBtn.addEventListener('click', function () {
            var rows = rowsContainer.querySelectorAll('[data-weight-row]');
            var addedEntries = [];

            for (var i = 0; i < rows.length; i++) {
                var row = rows[i];
                var part = row.querySelector('.weight-row-part').value;
                var exercise = row.querySelector('.weight-row-exercise').value.trim();

                if (exercise === '') {
                    continue;
                }

                var repsRaw = row.querySelector('.weight-row-reps').value.trim();
                var setsRaw = row.querySelector('.weight-row-sets').value.trim();
                var loadSelect = row.querySelector('.weight-row-load');
                var loadValue = loadSelect.value;

                var isBodyweight = false;
                var weightKg = null;
                var weightNote = null;

                if (loadValue === 'custom') {
                    var customInput = (prompt('重量を入力してください（例: 62.5）') || '').trim();
                    if (customInput === '') {
                        continue;
                    }
                    var numericCustom = parseFloat(customInput.replace(/kg$/i, ''));
                    if (!isNaN(numericCustom)) {
                        weightKg = Math.round(numericCustom * 100) / 100;
                    } else {
                        weightNote = customInput;
                    }
                } else if (loadValue === '自重') {
                    isBodyweight = true;
                } else {
                    weightKg = parseFloat(loadValue);
                }

                weightEntryUid += 1;
                addedEntries.push({
                    uid: weightEntryUid,
                    body_part: part,
                    exercise_name: exercise,
                    reps: repsRaw !== '' ? parseInt(repsRaw, 10) : null,
                    sets: setsRaw !== '' ? parseInt(setsRaw, 10) : null,
                    weight_kg: weightKg,
                    is_bodyweight: isBodyweight,
                    weight_note: weightNote
                });
            }

            if (addedEntries.length === 0) {
                alert('種目名を入力してください。');
                return;
            }

            confirmedWeightSets = confirmedWeightSets.concat(addedEntries);
            syncWeightSetsField();
            addedEntries.forEach(appendEntryChip);
            resetWeightRows();
        });
    }

    // 下書きの確定済みの種目を戻す
    SHEET_RESTORE.weight = function (entries) {
        entryList.innerHTML = '';
        confirmedWeightSets = (entries || []).map(function (entry) {
            weightEntryUid += 1;
            return {
                uid: weightEntryUid,
                body_part: String(entry.body_part || ''),
                exercise_name: String(entry.exercise_name || ''),
                reps: entry.reps !== null && entry.reps !== undefined ? entry.reps : null,
                sets: entry.sets !== null && entry.sets !== undefined ? entry.sets : null,
                weight_kg: entry.weight_kg !== null && entry.weight_kg !== undefined ? entry.weight_kg : null,
                is_bodyweight: !!entry.is_bodyweight,
                weight_note: entry.weight_note || null
            };
        });
        confirmedWeightSets.forEach(appendEntryChip);
        syncWeightSetsField();
    };
})();

(function () {
    var rowsContainer = document.getElementById('runningRowsContainer');
    var addRowBtn = document.getElementById('runningAddRowBtn');
    var addBtn = document.getElementById('runningAddBtn');
    var entryList = document.getElementById('runningEntryList');
    var typeDatalist = document.getElementById('runningWorkoutTypeOptions');
    var distanceDatalist = document.getElementById('runningDistanceOptions');
    var runningSetsJsonField = document.getElementById('runningSetsJsonField');

    if (!rowsContainer || !addRowBtn || !addBtn || !entryList || !typeDatalist || !distanceDatalist || !runningSetsJsonField) {
        return;
    }

    var shortWorkoutTypeOptions = ['流し', '快調走', 'セット走', 'SD', 'TT', 'マーク走'];
    var longWorkoutTypeOptions = ['インターバル', 'ペース走', 'ジョグ・LSD', '坂道', 'レース'];
    var distanceOptions = ['30m', '60m', '100m', '120m', '150m', '200m', '200+200', '300m', '400m', '600m', '800m', '1000m', '1200m', '1500m', '2000m', '3000m', '5000m', '10000m', 'ハーフ', 'フル'];

    function populateDatalist(datalistEl, values) {
        datalistEl.innerHTML = '';
        values.forEach(function (value) {
            var opt = document.createElement('option');
            opt.value = value;
            datalistEl.appendChild(opt);
        });
    }

    function isShortSprintSelected() {
        var el = document.getElementById('diaryTypeShortSprint');
        return !el || el.checked;
    }

    function lapsCaptionText() {
        return isShortSprintSelected() ? 'タイム' : 'ラップタイム';
    }

    function refreshTypeDatalist() {
        populateDatalist(typeDatalist, isShortSprintSelected() ? shortWorkoutTypeOptions : longWorkoutTypeOptions);
    }

    function distanceValueMeters(label) {
        if (label === 'ハーフ') {
            return 21097;
        }
        if (label === 'フル') {
            return 42195;
        }
        var match = label.match(/\d+/);
        return match ? parseInt(match[0], 10) : null;
    }

    function refreshDistanceDatalist() {
        var isShort = isShortSprintSelected();
        var filtered = distanceOptions.filter(function (label) {
            var meters = distanceValueMeters(label);
            if (meters === null) {
                return true;
            }
            return isShort ? meters <= 400 : meters >= 300;
        });
        populateDatalist(distanceDatalist, filtered);
    }

    // 設定タイムの候補（距離に合わせて作る。速い順）。一覧から選ぶほか、今まで通り手入力もできる
    //   〜600m  : 合計タイム。150m以下は0.5秒刻み、それより長いと1秒刻み（例 13秒 / 12.5秒 / 1:05）
    //   〜3000m : 合計タイム。2000m以下は5秒刻み、それより長いと10秒刻み（例 3:10）
    //   それより長い距離・ハーフ・フル : 1kmあたりのペース、5秒刻み（例 3:30/km）
    function formatTargetSeconds(seconds) {
        if (seconds < 60) {
            return (Math.round(seconds * 10) / 10) + '秒';
        }
        var whole = Math.round(seconds);
        return Math.floor(whole / 60) + ':' + String(whole % 60).padStart(2, '0');
    }

    function targetTimeOptions(distanceLabel) {
        var meters = distanceValueMeters(distanceLabel);
        if (!meters) {
            return [];
        }
        var options = [];
        var t;
        if (meters <= 600) {
            var step = meters <= 150 ? 0.5 : 1;
            var fastest = Math.max(3, Math.floor(meters * 0.105 / step) * step);
            var slowest = Math.ceil(meters * 0.25 / step) * step;
            for (t = fastest; t <= slowest; t += step) {
                options.push(formatTargetSeconds(t));
            }
        } else if (meters <= 3000) {
            var stepLong = meters <= 2000 ? 5 : 10;
            var fastestLong = Math.floor(meters / 1000 * 170 / stepLong) * stepLong;
            var slowestLong = Math.ceil(meters / 1000 * 360 / stepLong) * stepLong;
            for (t = fastestLong; t <= slowestLong; t += stepLong) {
                options.push(formatTargetSeconds(t));
            }
        } else {
            for (t = 180; t <= 420; t += 5) {
                options.push(formatTargetSeconds(t) + '/km');
            }
        }
        return options;
    }

    function refreshAllLapsCaptions() {
        var text = lapsCaptionText();
        Array.prototype.forEach.call(rowsContainer.querySelectorAll('.running-row-laps-caption-text'), function (el) {
            el.textContent = text;
        });
    }

    refreshTypeDatalist();
    refreshDistanceDatalist();

    Array.prototype.forEach.call(document.querySelectorAll('input[name="diary_type_switch"]'), function (radio) {
        radio.addEventListener('change', function () {
            refreshTypeDatalist();
            refreshDistanceDatalist();
            refreshAllLapsCaptions();
        });
    });

    var rowCounter = 0;

    function populateCountSelect(selectEl, max, suffix) {
        selectEl.innerHTML = '';
        for (var i = 1; i <= max; i++) {
            var opt = document.createElement('option');
            opt.value = String(i);
            opt.textContent = i + suffix;
            selectEl.appendChild(opt);
        }
    }

    function createRunningRow() {
        rowCounter += 1;

        var row = document.createElement('div');
        row.className = 'running-entry-row mb-2 p-2 border rounded';
        row.setAttribute('data-running-row', '');
        row.innerHTML =
            '<div class="row g-2 align-items-center">' +
                '<div class="col-6 col-md-3">' +
                    '<input class="form-control form-control-sm running-row-type" type="text" placeholder="種別（選択 or 入力）" aria-label="種別" autocomplete="off">' +
                '</div>' +
                '<div class="col-6 col-md-3">' +
                    '<input class="form-control form-control-sm running-row-distance" type="text" placeholder="距離（例 400m）" aria-label="距離" autocomplete="off">' +
                '</div>' +
                '<div class="col-3 col-md-2">' +
                    '<select class="form-select form-select-sm running-row-reps" aria-label="本数"></select>' +
                '</div>' +
                '<div class="col-3 col-md-2">' +
                    '<select class="form-select form-select-sm running-row-sets" aria-label="セット数"></select>' +
                '</div>' +
                '<div class="col-6 col-md-2">' +
                    '<input class="form-control form-control-sm running-row-target-time" type="text" placeholder="設定タイム" aria-label="設定タイム" autocomplete="off">' +
                '</div>' +
            '</div>' +
            '<div class="running-row-laps-caption mt-1 d-flex align-items-center gap-2">' +
                '<span class="running-row-laps-caption-text">' + lapsCaptionText() + '</span>' +
                '<button type="button" class="btn btn-outline-secondary btn-sm running-row-add-lap" aria-label="タイム欄を追加">＋タイム欄を追加</button>' +
                '<button type="button" class="btn btn-outline-danger btn-sm running-row-remove ms-auto" aria-label="この種目を削除">×</button>' +
            '</div>' +
            '<div class="running-row-laps mt-2 d-flex flex-wrap gap-2"></div>';

        rowsContainer.appendChild(row);

        var typeInput = row.querySelector('.running-row-type');
        var distanceInput = row.querySelector('.running-row-distance');
        var repsSelect = row.querySelector('.running-row-reps');
        var setsSelect = row.querySelector('.running-row-sets');
        var lapsWrap = row.querySelector('.running-row-laps');
        var addLapBtn = row.querySelector('.running-row-add-lap');
        var removeBtn = row.querySelector('.running-row-remove');
        var lapCounter = 0;

        populateCountSelect(repsSelect, 20, '本');
        populateCountSelect(setsSelect, 5, 'セット');
        attachComboDropdown(typeInput, typeDatalist);
        attachComboDropdown(distanceInput, distanceDatalist);

        // 設定タイム: この行の距離に合わせた候補を出す（距離を変えると候補も変わる）
        var targetTimeInput = row.querySelector('.running-row-target-time');
        var targetTimeDatalist = document.createElement('datalist');
        row.appendChild(targetTimeDatalist);
        function refreshTargetTimeOptions() {
            populateDatalist(targetTimeDatalist, targetTimeOptions(distanceInput.value.trim()));
        }
        distanceInput.addEventListener('input', refreshTargetTimeOptions);
        distanceInput.addEventListener('change', refreshTargetTimeOptions);
        refreshTargetTimeOptions();
        attachComboDropdown(targetTimeInput, targetTimeDatalist);

        // 1本ごとのタイム: 選ぶだけで入れられるプルダウン。この行の距離で形を変える
        //   400m まで（距離が分からないときも）: 秒 ＋ 小数点以下（例 58 . 4）
        //   400m より長い                      : 分 ＋ 秒 ＋ 小数点以下（例 3 分 05 . 3）
        // 選んだ値は、隠した .running-lap-input に「秒の数」で入れる（確定・送信はこれまでどおりこの値を読む）
        function lapMode() {
            var meters = distanceValueMeters(distanceInput.value.trim());
            return meters === null || meters <= 400 ? 'short' : 'long';
        }

        function buildSelect(className, label, placeholder, from, to, format) {
            var select = document.createElement('select');
            select.className = 'form-select form-select-sm ' + className;
            select.setAttribute('aria-label', label);
            if (placeholder !== null) {
                var empty = document.createElement('option');
                empty.value = '';
                empty.textContent = placeholder;
                select.appendChild(empty);
            }
            for (var i = from; i <= to; i++) {
                var opt = document.createElement('option');
                opt.value = String(i);
                opt.textContent = format(i);
                select.appendChild(opt);
            }
            return select;
        }

        // その欄のプルダウンを、今の距離の形で作り直す（入っていたタイムは引き継ぐ）
        function fillLapBox(box, number, seconds) {
            var mode = lapMode();
            box.setAttribute('data-lap-mode', mode);
            box.innerHTML = '';
            var label = document.createElement('span');
            label.className = 'running-lap-label';
            label.textContent = number + '本目';
            box.appendChild(label);
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.className = 'running-lap-input';
            box.appendChild(hidden);

            var minuteSelect = null;
            if (mode === 'long') {
                minuteSelect = buildSelect('running-lap-min', number + '本目のタイム（分）', '分', 0, 30, function (i) { return i + '分'; });
                box.appendChild(minuteSelect);
            }
            var secondSelect = buildSelect('running-lap-sec', number + '本目のタイム（秒）', mode === 'long' ? null : '秒', 0, mode === 'long' ? 59 : 99, function (i) {
                return mode === 'long' ? String(i).padStart(2, '0') + '秒' : i + '秒';
            });
            var tenthSelect = buildSelect('running-lap-tenth', number + '本目のタイム（小数点以下）', null, 0, 9, function (i) { return '.' + i; });
            box.appendChild(secondSelect);
            box.appendChild(tenthSelect);

            function sync() {
                var main = mode === 'long' ? minuteSelect.value : secondSelect.value;
                if (main === '') {
                    hidden.value = '';
                    return;
                }
                var total = (mode === 'long' ? parseInt(minuteSelect.value, 10) * 60 : 0)
                    + parseInt(secondSelect.value, 10) + parseInt(tenthSelect.value, 10) / 10;
                hidden.value = String(Math.round(total * 10) / 10);
            }
            [minuteSelect, secondSelect, tenthSelect].forEach(function (select) {
                if (select) {
                    select.addEventListener('change', sync);
                }
            });

            // 引き継ぐタイムを選んだ状態にする（その形で表せないときは空のまま）
            if (seconds !== null && !isNaN(seconds)) {
                var tenths = Math.round(seconds * 10);
                var whole = Math.floor(tenths / 10);
                if (mode === 'long' && whole <= 30 * 60 + 59) {
                    minuteSelect.value = String(Math.floor(whole / 60));
                    secondSelect.value = String(whole % 60);
                    tenthSelect.value = String(tenths % 10);
                } else if (mode === 'short' && whole <= 99) {
                    secondSelect.value = String(whole);
                    tenthSelect.value = String(tenths % 10);
                }
            }
            sync();
        }

        function appendLapInput() {
            lapCounter += 1;
            var box = document.createElement('div');
            box.className = 'running-lap-box';
            box.setAttribute('data-lap-number', String(lapCounter));
            fillLapBox(box, lapCounter, null);
            lapsWrap.appendChild(box);
        }

        // 距離を変えて形（400m まで / より長い）が変わったら、タイムの欄を作り直す
        function refreshLapBoxes() {
            var mode = lapMode();
            Array.prototype.forEach.call(lapsWrap.querySelectorAll('.running-lap-box'), function (box) {
                if (box.getAttribute('data-lap-mode') === mode) {
                    return;
                }
                var value = box.querySelector('.running-lap-input').value;
                fillLapBox(box, parseInt(box.getAttribute('data-lap-number'), 10), value === '' ? null : parseFloat(value));
            });
        }
        distanceInput.addEventListener('input', refreshLapBoxes);
        distanceInput.addEventListener('change', refreshLapBoxes);

        addLapBtn.addEventListener('click', function () {
            appendLapInput();
        });

        removeBtn.addEventListener('click', function () {
            if (rowsContainer.querySelectorAll('[data-running-row]').length <= 1) {
                distanceInput.value = '';
                row.querySelector('.running-row-target-time').value = '';
                typeInput.value = '';
                repsSelect.value = '1';
                setsSelect.value = '1';
                lapsWrap.innerHTML = '';
                lapCounter = 0;
                return;
            }
            row.remove();
        });

        return row;
    }

    function resetRunningRows() {
        rowsContainer.innerHTML = '';
        createRunningRow();
    }

    createRunningRow();

    addRowBtn.addEventListener('click', function () {
        createRunningRow();
    });

    var confirmedRunningSets = [];

    function syncRunningSetsField() {
        var payload = confirmedRunningSets.map(function (entry) {
            return {
                workout_type: entry.workout_type,
                distance_label: entry.distance_label,
                target_time_label: entry.target_time_label,
                reps: entry.reps,
                sets: entry.sets,
                laps: entry.laps
            };
        });
        runningSetsJsonField.value = JSON.stringify(payload);
    }

    function buildDisplayLabel(entry) {
        var label = entry.workout_type + ': ' +
            entry.distance_label + ' × ' + entry.reps + '本' +
            (entry.sets > 1 ? ' × ' + entry.sets + 'set' : '') +
            (entry.target_time_label ? ' (設定' + entry.target_time_label + ')' : '');

        // 1分を超えるタイムは「1:32.0」の形で出す（60秒未満はそのまま。例 17.8）
        var lapValues = entry.laps.filter(function (v) { return v !== null && v !== ''; }).map(function (v) {
            var seconds = Number(v);
            if (seconds < 60) {
                return String(v);
            }
            var tenths = Math.round(seconds * 10);
            var whole = Math.floor(tenths / 10);
            return Math.floor(whole / 60) + ':' + String(whole % 60).padStart(2, '0') + '.' + (tenths % 10);
        });
        if (lapValues.length > 0) {
            label += ' - ' + lapValues.join(',');
        }
        return label;
    }

    function appendEntryChip(entry) {
        var chip = document.createElement('div');
        chip.className = 'weight-entry-chip';
        var label = document.createElement('span');
        label.textContent = buildDisplayLabel(entry);
        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.textContent = '削除';
        removeBtn.addEventListener('click', function () {
            confirmedRunningSets = confirmedRunningSets.filter(function (item) { return item.uid !== entry.uid; });
            syncRunningSetsField();
            chip.remove();
        });
        chip.appendChild(label);
        chip.appendChild(removeBtn);
        entryList.appendChild(chip);
    }

    var runningEntryUid = 0;

    addBtn.addEventListener('click', function () {
        var rows = rowsContainer.querySelectorAll('[data-running-row]');
        var addedEntries = [];

        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            var distance = row.querySelector('.running-row-distance').value.trim();

            if (distance === '') {
                continue;
            }

            var workoutType = row.querySelector('.running-row-type').value.trim();
            if (workoutType === '') {
                workoutType = 'ランニング';
            }
            var reps = parseInt(row.querySelector('.running-row-reps').value, 10);
            var sets = parseInt(row.querySelector('.running-row-sets').value, 10);
            var targetTime = row.querySelector('.running-row-target-time').value.trim();

            var laps = Array.prototype.map.call(row.querySelectorAll('.running-lap-input'), function (input) {
                return input.value.trim() !== '' ? parseFloat(input.value) : null;
            });

            runningEntryUid += 1;
            addedEntries.push({
                uid: runningEntryUid,
                workout_type: workoutType,
                distance_label: distance,
                target_time_label: targetTime !== '' ? targetTime : null,
                reps: reps,
                sets: sets,
                laps: laps
            });
        }

        if (addedEntries.length === 0) {
            alert('距離を入力してください。');
            return;
        }

        confirmedRunningSets = confirmedRunningSets.concat(addedEntries);
        syncRunningSetsField();
        addedEntries.forEach(appendEntryChip);
        resetRunningRows();
    });

    // 下書きの確定済みの種目を戻す
    SHEET_RESTORE.running = function (entries) {
        entryList.innerHTML = '';
        confirmedRunningSets = (entries || []).map(function (entry) {
            runningEntryUid += 1;
            return {
                uid: runningEntryUid,
                workout_type: String(entry.workout_type || 'ランニング'),
                distance_label: String(entry.distance_label || ''),
                target_time_label: entry.target_time_label || null,
                reps: parseInt(entry.reps, 10) || 1,
                sets: parseInt(entry.sets, 10) || 1,
                laps: Array.isArray(entry.laps) ? entry.laps : []
            };
        });
        confirmedRunningSets.forEach(appendEntryChip);
        syncRunningSetsField();
    };
})();

(function () {
    var rowsContainer = document.getElementById('raceRowsContainer');
    var addRowBtn = document.getElementById('raceAddRowBtn');
    var addBtn = document.getElementById('raceAddBtn');
    var entryList = document.getElementById('raceEntryList');
    var raceEntriesJsonField = document.getElementById('raceEntriesJsonField');

    if (!rowsContainer || !addRowBtn || !addBtn || !entryList || !raceEntriesJsonField) {
        return;
    }

    var eventOptions = ['100m', '200m', '400m', '800m', '1000m', '1500m', '5000m', '10000m', '走幅跳', '三段跳', '走高跳', '棒高跳', '砲丸投', '円盤投', 'やり投', 'ハーフ', 'フル'];
    var shortTrackEvents = ['100m', '200m', '400m'];
    var longTrackEvents = ['800m', '1000m', '1500m', '5000m', '10000m'];
    var marathonEvents = ['ハーフ', 'フル'];
    // 風速を入力する種目（back/training_diary_lib.php の TRAINING_DIARY_WIND_EVENTS と同じ）
    var windEvents = SHEET_CONFIG.windEvents || [];

    var resultConfigs = {
        short: {
            parts: [{ max: 59, pad: false }, { max: 99, pad: true }],
            units: ['秒', ''],
            build: function (vals) { return vals[0] + '.' + vals[1]; }
        },
        long: {
            parts: [{ max: 99, pad: false }, { max: 59, pad: true }, { max: 99, pad: true }],
            units: ['分', '秒', ''],
            build: function (vals) { return vals[0] + ':' + vals[1] + ':' + vals[2]; }
        },
        marathon: {
            parts: [{ max: 9, pad: false }, { max: 59, pad: true }, { max: 59, pad: true }],
            units: ['時間', '分', '秒'],
            build: function (vals) { return vals[0] + ':' + vals[1] + ':' + vals[2]; }
        },
        distance: {
            parts: [{ max: 99, pad: false }, { max: 99, pad: true }],
            units: ['m', 'cm'],
            build: function (vals) { return vals[0] + 'm' + vals[1] + 'cm'; }
        }
    };

    function resultCategoryForEvent(eventName) {
        if (shortTrackEvents.indexOf(eventName) !== -1) {
            return 'short';
        }
        if (longTrackEvents.indexOf(eventName) !== -1) {
            return 'long';
        }
        if (marathonEvents.indexOf(eventName) !== -1) {
            return 'marathon';
        }
        return 'distance';
    }

    function populateNumberSelect(selectEl, max, pad) {
        selectEl.innerHTML = '';
        for (var i = 0; i <= max; i++) {
            var text = pad ? String(i).padStart(2, '0') : String(i);
            var opt = document.createElement('option');
            opt.value = text;
            opt.textContent = text;
            selectEl.appendChild(opt);
        }
    }

    function syncResultHiddenField(row) {
        var category = row.getAttribute('data-result-category') || 'short';
        var config = resultConfigs[category];
        var selects = [row.querySelector('.race-result-part1'), row.querySelector('.race-result-part2'), row.querySelector('.race-result-part3')];
        var vals = selects.slice(0, config.parts.length).map(function (s) { return s.value; });
        row.querySelector('.race-row-result').value = config.build(vals);
    }

    function configureResultPicker(row, eventName) {
        var selects = [row.querySelector('.race-result-part1'), row.querySelector('.race-result-part2'), row.querySelector('.race-result-part3')];
        var units = [row.querySelector('.race-result-unit1'), row.querySelector('.race-result-unit2'), row.querySelector('.race-result-unit3')];

        if (eventName === '') {
            selects.forEach(function (s) { s.innerHTML = ''; s.hidden = true; });
            units.forEach(function (u) { u.hidden = true; });
            row.removeAttribute('data-result-category');
            row.querySelector('.race-row-result').value = '';
            configureWindPicker(row, eventName);
            return;
        }

        var category = resultCategoryForEvent(eventName);
        var config = resultConfigs[category];

        for (var i = 0; i < 3; i++) {
            if (i < config.parts.length) {
                populateNumberSelect(selects[i], config.parts[i].max, config.parts[i].pad);
                selects[i].hidden = false;
                units[i].textContent = config.units[i];
                units[i].hidden = false;
            } else {
                selects[i].hidden = true;
                units[i].hidden = true;
            }
        }

        row.setAttribute('data-result-category', category);
        syncResultHiddenField(row);
        configureWindPicker(row, eventName);
    }

    // 風速は「追い風/向かい風/計測なし」＋ 整数 ＋ 小数1桁 をプルダウンで選ぶ（手入力なし）
    function configureWindPicker(row, eventName) {
        var windWrap = row.querySelector('.race-wind-picker');
        var usesWind = windEvents.indexOf(eventName) !== -1;
        windWrap.hidden = !usesWind;
        if (!usesWind) {
            row.querySelector('.race-wind-direction').value = '';
        }
        syncWindDigits(row);
    }

    function syncWindDigits(row) {
        var direction = row.querySelector('.race-wind-direction').value;
        var measured = direction === '+' || direction === '-';
        row.querySelectorAll('.race-wind-digit').forEach(function (el) {
            el.hidden = !measured;
        });
        syncOfficialField(row);
    }

    // 公認/非公認は風速から自動で決める（back/training_diary_lib.php の training_diary_race_entry_is_official と同じ規則）。
    // 風を計測する種目は追い風 windLimit 以下なら公認、超える・計測なしは非公認。風を計測しない種目は公認
    var windLimit = SHEET_CONFIG.windLimit;
    function computeOfficial(row) {
        var eventName = row.querySelector('.race-row-event').value;
        if (windEvents.indexOf(eventName) === -1) {
            return true;
        }
        var direction = row.querySelector('.race-wind-direction').value;
        if (direction === '') {
            return true; // 風速を選ぶまでは仮に公認と表示（未選択のままでは確定できない）
        }
        if (direction === 'none') {
            return false;
        }
        return parseFloat(readWindValue(row)) <= windLimit;
    }

    function syncOfficialField(row) {
        row.querySelector('.race-official').value = computeOfficial(row) ? '1' : '0';
    }

    // 送信用の風速（例: "+1.2"）。計測なし・風を計測しない種目は空文字
    function readWindValue(row) {
        var direction = row.querySelector('.race-wind-direction').value;
        if (direction !== '+' && direction !== '-') {
            return '';
        }
        return direction + row.querySelector('.race-wind-int').value + '.' + row.querySelector('.race-wind-dec').value;
    }

    function formatEntryNotes(entry) {
        var notes = [];
        if (windEvents.indexOf(entry.event_name) !== -1) {
            notes.push(entry.wind_mps === '' ? '風計測なし' : entry.wind_mps + 'm/s');
        }
        notes.push(entry.is_official ? '公認' : '非公認');
        return notes.join('・');
    }

    var rowCounter = 0;

    function createRaceRow() {
        rowCounter += 1;

        var row = document.createElement('div');
        row.className = 'row g-2 align-items-center race-entry-row mb-2';
        row.setAttribute('data-race-row', '');
        row.innerHTML =
            '<div class="col-12 col-md-4">' +
                '<select class="form-select form-select-sm race-row-event" aria-label="種目"></select>' +
            '</div>' +
            '<div class="col-12 col-md-6">' +
                '<div class="race-result-picker d-flex align-items-center flex-wrap gap-1">' +
                    '<select class="form-select form-select-sm race-result-part1" aria-label="記録1"></select>' +
                    '<span class="race-result-unit"></span>' +
                    '<select class="form-select form-select-sm race-result-part2" aria-label="記録2"></select>' +
                    '<span class="race-result-unit"></span>' +
                    '<select class="form-select form-select-sm race-result-part3" aria-label="記録3"></select>' +
                    '<span class="race-result-unit"></span>' +
                '</div>' +
                '<input type="hidden" class="race-row-result">' +
            '</div>' +
            '<div class="col-auto ms-auto d-grid">' +
                '<button type="button" class="btn btn-outline-danger btn-sm race-row-remove" aria-label="この種目を削除">×</button>' +
            '</div>' +
            '<div class="col-12 d-flex align-items-center flex-wrap gap-2">' +
                '<div class="race-wind-picker" hidden>' +
                    '<span class="race-result-unit">風</span>' +
                    '<select class="form-select form-select-sm race-wind-direction" aria-label="風向き">' +
                        '<option value="">風速を選択</option>' +
                        '<option value="+">追い風 +</option>' +
                        '<option value="-">向かい風 −</option>' +
                        '<option value="none">計測なし</option>' +
                    '</select>' +
                    '<select class="form-select form-select-sm race-wind-digit race-wind-int" aria-label="風速（整数）"></select>' +
                    '<span class="race-result-unit race-wind-digit">.</span>' +
                    '<select class="form-select form-select-sm race-wind-digit race-wind-dec" aria-label="風速（小数）"></select>' +
                    '<span class="race-result-unit race-wind-digit">m/s</span>' +
                '</div>' +
                '<select class="form-select form-select-sm race-official" aria-label="公認・非公認（風速から自動で決まります）" title="風速から自動で決まります" disabled>' +
                    '<option value="1">公認</option>' +
                    '<option value="0">非公認</option>' +
                '</select>' +
            '</div>';

        rowsContainer.appendChild(row);

        var eventSelect = row.querySelector('.race-row-event');
        var removeBtn = row.querySelector('.race-row-remove');
        var partSelects = [row.querySelector('.race-result-part1'), row.querySelector('.race-result-part2'), row.querySelector('.race-result-part3')];
        var resultUnits = row.querySelectorAll('.race-result-unit');
        resultUnits[0].className = 'race-result-unit race-result-unit1';
        resultUnits[1].className = 'race-result-unit race-result-unit2';
        resultUnits[2].className = 'race-result-unit race-result-unit3';
        populateNumberSelect(row.querySelector('.race-wind-int'), 9, false);
        populateNumberSelect(row.querySelector('.race-wind-dec'), 9, false);
        var windDirection = row.querySelector('.race-wind-direction');
        windDirection.addEventListener('change', function () {
            syncWindDigits(row);
        });
        [row.querySelector('.race-wind-int'), row.querySelector('.race-wind-dec')].forEach(function (windSelect) {
            windSelect.addEventListener('change', function () {
                syncOfficialField(row);
            });
        });

        var placeholderOpt = document.createElement('option');
        placeholderOpt.value = '';
        placeholderOpt.textContent = '種目を選択';
        eventSelect.appendChild(placeholderOpt);

        eventOptions.forEach(function (value) {
            var opt = document.createElement('option');
            opt.value = value;
            opt.textContent = value;
            eventSelect.appendChild(opt);
        });

        configureResultPicker(row, eventSelect.value);

        eventSelect.addEventListener('change', function () {
            configureResultPicker(row, eventSelect.value);
        });

        partSelects.forEach(function (selectEl) {
            selectEl.addEventListener('change', function () {
                syncResultHiddenField(row);
            });
        });

        removeBtn.addEventListener('click', function () {
            if (rowsContainer.querySelectorAll('[data-race-row]').length <= 1) {
                eventSelect.value = '';
                configureResultPicker(row, eventSelect.value);
                return;
            }
            row.remove();
        });

        return row;
    }

    function resetRaceRows() {
        rowsContainer.innerHTML = '';
        createRaceRow();
    }

    createRaceRow();

    addRowBtn.addEventListener('click', function () {
        createRaceRow();
    });

    var confirmedRaceEntries = [];

    function syncRaceEntriesField() {
        var payload = confirmedRaceEntries.map(function (entry) {
            return {
                event_name: entry.event_name,
                result_label: entry.result_label,
                wind_mps: entry.wind_mps,
                is_official: entry.is_official
            };
        });
        raceEntriesJsonField.value = JSON.stringify(payload);
    }

    function appendEntryChip(entry) {
        var chip = document.createElement('div');
        chip.className = 'weight-entry-chip';
        var label = document.createElement('span');
        label.textContent = entry.event_name + ': ' + entry.result_label + '（' + formatEntryNotes(entry) + '）';
        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.textContent = '削除';
        removeBtn.addEventListener('click', function () {
            confirmedRaceEntries = confirmedRaceEntries.filter(function (item) { return item.uid !== entry.uid; });
            syncRaceEntriesField();
            chip.remove();
        });
        chip.appendChild(label);
        chip.appendChild(removeBtn);
        entryList.appendChild(chip);
    }

    var raceEntryUid = 0;

    addBtn.addEventListener('click', function () {
        var rows = rowsContainer.querySelectorAll('[data-race-row]');
        var addedEntries = [];

        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            var eventName = row.querySelector('.race-row-event').value.trim();
            var resultLabel = row.querySelector('.race-row-result').value.trim();

            if (eventName === '' || resultLabel === '') {
                continue;
            }
            if (windEvents.indexOf(eventName) !== -1 && row.querySelector('.race-wind-direction').value === '') {
                alert(eventName + 'の風速を選択してください（分からない場合は「計測なし」）。');
                return;
            }

            raceEntryUid += 1;
            addedEntries.push({
                uid: raceEntryUid,
                event_name: eventName,
                result_label: resultLabel,
                wind_mps: readWindValue(row),
                is_official: computeOfficial(row)
            });
        }

        if (addedEntries.length === 0) {
            alert('種目とタイム・記録を入力してください。');
            return;
        }

        confirmedRaceEntries = confirmedRaceEntries.concat(addedEntries);
        syncRaceEntriesField();
        addedEntries.forEach(appendEntryChip);
        resetRaceRows();
    });

    // 下書きの確定済みの種目を戻す
    SHEET_RESTORE.race = function (entries) {
        entryList.innerHTML = '';
        confirmedRaceEntries = (entries || []).map(function (entry) {
            raceEntryUid += 1;
            return {
                uid: raceEntryUid,
                event_name: String(entry.event_name || ''),
                result_label: String(entry.result_label || ''),
                wind_mps: entry.wind_mps === null || entry.wind_mps === undefined ? '' : entry.wind_mps,
                is_official: !!entry.is_official
            };
        });
        confirmedRaceEntries.forEach(appendEntryChip);
        syncRaceEntriesField();
    };
})();

(function () {
    var form = document.getElementById('diaryPostForm');
    var trainingTypeField = document.getElementById('trainingTypeField');
    var submitBtn = document.getElementById('diaryPostSubmitBtn');

    if (!form || !trainingTypeField || !submitBtn) {
        return;
    }

    function confirmedEntryListId(trainingType) {
        if (trainingType === 'weight') {
            return 'weightEntryList';
        }
        if (trainingType === 'race') {
            return 'raceEntryList';
        }
        if (trainingType === 'short_sprint' || trainingType === 'long_sprint') {
            return 'runningEntryList';
        }
        return null;
    }

    // ジョグは確定ボタンが無いので、0km より大きい距離が1つ以上あれば投稿できる
    var jogEntryCount = 0;
    form.addEventListener('jogchange', function (event) {
        jogEntryCount = event.detail.count;
        updateSubmitButtonState();
    });

    function updateSubmitButtonState() {
        if (trainingTypeField.value === 'jog') {
            submitBtn.disabled = jogEntryCount === 0;
            return;
        }
        var listId = confirmedEntryListId(trainingTypeField.value);
        if (!listId) {
            submitBtn.disabled = false;
            return;
        }
        var listEl = document.getElementById(listId);
        submitBtn.disabled = !listEl || listEl.children.length === 0;
    }

    ['weightEntryList', 'runningEntryList', 'raceEntryList'].forEach(function (id) {
        var listEl = document.getElementById(id);
        if (!listEl || typeof MutationObserver === 'undefined') {
            return;
        }
        new MutationObserver(updateSubmitButtonState).observe(listEl, { childList: true });
    });

    Array.prototype.forEach.call(document.querySelectorAll('input[name="diary_type_switch"]'), function (radio) {
        radio.addEventListener('change', updateSubmitButtonState);
    });
    var intensitySelect = document.getElementById('intensity');
    if (intensitySelect) {
        intensitySelect.addEventListener('change', updateSubmitButtonState);
    }

    updateSubmitButtonState();

    function hasUnconfirmedInput(container, selectors) {
        if (!container) {
            return false;
        }
        var rows = container.children;
        for (var i = 0; i < rows.length; i++) {
            for (var s = 0; s < selectors.length; s++) {
                var field = rows[i].querySelector(selectors[s]);
                if (field && field.value.trim() !== '') {
                    return true;
                }
            }
        }
        return false;
    }

    form.addEventListener('submit', function (event) {
        var trainingType = trainingTypeField.value;
        var unconfirmed = false;

        if (trainingType === 'weight') {
            unconfirmed = hasUnconfirmedInput(document.getElementById('weightRowsContainer'), ['.weight-row-exercise']);
        } else if (trainingType === 'short_sprint' || trainingType === 'long_sprint') {
            unconfirmed = hasUnconfirmedInput(document.getElementById('runningRowsContainer'), ['.running-row-distance']);
        } else if (trainingType === 'race') {
            unconfirmed = hasUnconfirmedInput(document.getElementById('raceRowsContainer'), ['.race-row-event', '.race-row-result']);
        }

        if (unconfirmed) {
            event.preventDefault();
            alert('練習内容が「この内容で確定する」ボタンで確定されていません。入力した内容をそれぞれ確定してから投稿してください。');
        }
    });
})();

// 下書き: 「下書きを保存」で写真・動画以外の入力をサーバーに保存する（back/training_diary_draft.php）。
// 下書きがあれば、ページを開いたときに入力欄へ戻して案内を出す。投稿すると下書きは消える
(function () {
    var form = document.getElementById('diaryPostForm');
    var saveBtn = document.getElementById('diaryDraftSaveBtn');
    var statusEl = document.getElementById('diaryDraftStatus');
    var noteEl = document.getElementById('diaryDraftNote');
    var noteText = document.getElementById('diaryDraftNoteText');
    var discardBtn = document.getElementById('diaryDraftDiscardBtn');
    var draftUrl = SHEET_CONFIG.draftUrl;
    if (!form || !saveBtn || !statusEl || !noteEl || !noteText || !discardBtn || !draftUrl) {
        return;
    }

    function formatSavedAt(value) {
        var date = new Date(String(value || '').replace(' ', 'T'));
        if (isNaN(date.getTime())) {
            return '';
        }
        return (date.getMonth() + 1) + '/' + date.getDate() + ' ' + String(date.getHours()).padStart(2, '0') + ':' + String(date.getMinutes()).padStart(2, '0');
    }

    // 前回の練習タイプ（無ければショートスプ）。ウエイトは練習強度もウエイトにそろえる（強度を選んだときの連動と同じ）
    function lastTypeState() {
        var type = SHEET_CONFIG.lastType || 'short_sprint';
        return { training_type: type, intensity: type === 'weight' ? 'weight' : 'normal' };
    }

    function restoreEntries(draft) {
        if (SHEET_RESTORE.type) {
            SHEET_RESTORE.type(draft.intensity || 'normal', draft.training_type || 'short_sprint');
        }
        ['weight', 'running', 'race', 'jog'].forEach(function (key) {
            if (SHEET_RESTORE[key]) {
                var listKey = { weight: 'weight_sets', running: 'running_sets', race: 'race_entries', jog: 'jog_entries' }[key];
                SHEET_RESTORE[key](draft[listKey] || []);
            }
        });
    }

    function applyDraft(draft) {
        if (draft.practice_date) {
            form.elements.practice_date.value = draft.practice_date;
        }
        form.elements.menu_text.value = draft.menu_text || '';
        form.elements.comment_text.value = draft.comment_text || '';
        var publicBox = document.getElementById('diaryScopePublic');
        if (publicBox) {
            publicBox.checked = !!draft.is_public;
        }
        var groupIds = (draft.group_ids || []).map(String);
        form.querySelectorAll('input[name="group_ids[]"]').forEach(function (box) {
            box.checked = groupIds.indexOf(box.value) !== -1;
        });
        restoreEntries(draft);
        var savedAt = formatSavedAt(draft.updated_at);
        noteText.textContent = '下書きを読み込みました' + (savedAt ? '（' + savedAt + ' に保存）' : '') + '。';
        noteEl.hidden = false;
    }

    function send(action) {
        var data = new FormData(form);
        data.delete('training_image');
        data.delete('training_video');
        data.set('action', action);
        return fetch(draftUrl, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (response) {
            return response.json().catch(function () {
                return { ok: false, message: '下書きの保存に失敗しました。' };
            });
        });
    }

    var statusTimer = null;
    function showStatus(text) {
        statusEl.textContent = text;
        clearTimeout(statusTimer);
        statusTimer = setTimeout(function () {
            statusEl.textContent = '';
        }, 4000);
    }

    saveBtn.addEventListener('click', function () {
        saveBtn.disabled = true;
        send('save').then(function (result) {
            if (!result.ok) {
                alert(result.message || '下書きの保存に失敗しました。');
                return;
            }
            showStatus('下書きを保存しました');
            noteEl.hidden = true;
        }).catch(function () {
            alert('通信できませんでした。電波の良いところでもう一度お試しください。');
        }).then(function () {
            saveBtn.disabled = false;
        });
    });

    discardBtn.addEventListener('click', function () {
        if (!confirm('下書きを削除して、入力欄を最初の状態に戻しますか？')) {
            return;
        }
        send('delete').then(function (result) {
            if (!result.ok) {
                alert(result.message || '下書きの削除に失敗しました。');
                return;
            }
            form.reset();
            // 写真・動画の欄の表示（ファイル名・「写真を追加」）も空に戻す
            ['training_image', 'training_video'].forEach(function (id) {
                var input = document.getElementById(id);
                if (input) {
                    input.dispatchEvent(new Event('change'));
                }
            });
            restoreEntries(lastTypeState());
            noteEl.hidden = true;
            showStatus('下書きを削除しました');
        }).catch(function () {
            alert('通信できませんでした。電波の良いところでもう一度お試しください。');
        });
    });

    // 下書きがあればそれを戻す。なければ、前回（大会以外で最後に投稿した日記）の練習タイプをはじめから選んでおく
    if (SHEET_CONFIG.draft) {
        applyDraft(SHEET_CONFIG.draft);
    } else {
        restoreEntries(lastTypeState());
    }
})();
}
