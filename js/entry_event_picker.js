// 試合申し込みの種目と応募記録（includes/entry_event_fields.php）。
// 区分 → 種目 の2段階で種目を選び、応募記録は種目の形式に合わせたプルダウンで選ぶ。
// 選んだ記録は「11.23」「4:28.19」「2:31:05」「6m50」の形にして hidden に入れる（back/entry_event_lib.php の ENTRY_RECORD_PATTERNS と同じ形）
(function () {
    var configEl = document.getElementById('entryEventConfig');
    if (!configEl) {
        return;
    }
    var config = JSON.parse(configEl.textContent);
    var groups = config.groups || {};
    var pbValues = config.pb || {};

    function pad2(n) {
        return String(n).padStart(2, '0');
    }
    // 分・秒・1/100秒（lap と middle で共通）
    function minSecFromValue(v) {
        var c = Math.round(v * 100);
        return [String(Math.floor(c / 6000)), pad2(Math.floor((c % 6000) / 100)), pad2(c % 100)];
    }

    // 形式ごとの、プルダウンの並び（after は後ろに付く文字）・保存する文字の作り方・読み方・自己ベストの値からの変換
    var FORMATS = {
        sprint: {
            parts: [{ max: 59, after: '.', label: '秒' }, { max: 99, pad: true, after: '秒', label: '1/100秒' }],
            build: function (p) { return p[0] + '.' + p[1]; },
            parse: function (s) { var m = s.match(/^(\d{1,2})\.(\d{2})$/); return m ? [String(Number(m[1])), m[2]] : null; },
            fromValue: function (v) { var c = Math.round(v * 100); return [String(Math.floor(c / 100)), pad2(c % 100)]; }
        },
        lap: {
            parts: [{ max: 3, after: '分' }, { max: 59, pad: true, after: '.', label: '秒' }, { max: 99, pad: true, after: '秒', label: '1/100秒' }],
            // 1分未満は「52.34」のように秒だけで保存する
            build: function (p) { return p[0] === '0' ? Number(p[1]) + '.' + p[2] : p[0] + ':' + p[1] + '.' + p[2]; },
            parse: function (s) { var m = s.match(/^(?:(\d):)?(\d{1,2})\.(\d{2})$/); return m ? [m[1] || '0', pad2(Number(m[2])), m[3]] : null; },
            fromValue: minSecFromValue
        },
        middle: {
            parts: [{ max: 99, after: '分' }, { max: 59, pad: true, after: '.', label: '秒' }, { max: 99, pad: true, after: '秒', label: '1/100秒' }],
            build: function (p) { return p[0] + ':' + p[1] + '.' + p[2]; },
            parse: function (s) { var m = s.match(/^(\d{1,2}):(\d{2})\.(\d{2})$/); return m ? [String(Number(m[1])), m[2], m[3]] : null; },
            fromValue: minSecFromValue
        },
        marathon: {
            parts: [{ max: 9, after: '時間' }, { max: 59, pad: true, after: '分' }, { max: 59, pad: true, after: '秒' }],
            build: function (p) { return p[0] + ':' + p[1] + ':' + p[2]; },
            parse: function (s) { var m = s.match(/^(\d):(\d{2}):(\d{2})$/); return m ? [m[1], m[2], m[3]] : null; },
            fromValue: function (v) { var t = Math.round(v); return [String(Math.floor(t / 3600)), pad2(Math.floor((t % 3600) / 60)), pad2(t % 60)]; }
        },
        distance: {
            parts: [{ max: 99, after: 'm' }, { max: 99, pad: true, after: 'cm' }],
            build: function (p) { return p[0] + 'm' + p[1]; },
            parse: function (s) { var m = s.match(/^(\d{1,2})m(\d{2})$/); return m ? [String(Number(m[1])), m[2]] : null; },
            fromValue: function (v) { var c = Math.round(v * 100); return [String(Math.floor(c / 100)), pad2(c % 100)]; }
        }
    };

    function groupOfEvent(eventName) {
        for (var group in groups) {
            if (Object.prototype.hasOwnProperty.call(groups[group], eventName)) {
                return group;
            }
        }
        return '';
    }

    // 値がプルダウンの範囲に収まるときだけ使う（範囲外の自己ベストは初期値にしない）
    function partsFit(format, parts) {
        return parts !== null && parts.every(function (value, i) {
            return Number(value) <= format.parts[i].max;
        });
    }

    var slots = Array.prototype.slice.call(document.querySelectorAll('[data-entry-slot]'));

    // 同じ種目を2つの枠で選んでいたら、あとの枠で送れないようにする
    function checkDuplicates() {
        var seen = {};
        slots.forEach(function (slot) {
            var select = slot.querySelector('[data-entry-event]');
            var value = select.value;
            select.setCustomValidity(value !== '' && seen[value] ? '同じ種目がほかの枠で選ばれています。' : '');
            if (value !== '') {
                seen[value] = true;
            }
        });
    }

    slots.forEach(function (slot) {
        var required = slot.getAttribute('data-required') === '1';
        var groupSelect = slot.querySelector('[data-entry-group]');
        var eventSelect = slot.querySelector('[data-entry-event]');
        var recordWrap = slot.querySelector('[data-entry-record]');
        var partsWrap = slot.querySelector('[data-entry-record-parts]');
        var hint = slot.querySelector('[data-entry-record-hint]');
        var hidden = slot.querySelector('[data-entry-record-value]');
        var currentFormat = null;

        function partSelects() {
            return Array.prototype.slice.call(partsWrap.querySelectorAll('select'));
        }

        function syncHidden() {
            if (!currentFormat) {
                hidden.value = '';
                return;
            }
            var values = partSelects().map(function (s) { return s.value; });
            hidden.value = values.some(function (v) { return v === ''; }) ? '' : currentFormat.build(values);
        }

        function fillEvents(group) {
            eventSelect.innerHTML = '<option value="">種目を選ぶ</option>';
            var events = groups[group] || {};
            Object.keys(events).forEach(function (name) {
                var opt = document.createElement('option');
                opt.value = name;
                opt.textContent = name;
                eventSelect.appendChild(opt);
            });
            eventSelect.disabled = group === '';
            eventSelect.required = group !== '';
        }

        // 種目の形式に合わせて記録のプルダウンを作り、values（あれば）を選んでおく
        function buildRecord(eventName, values, fromPb) {
            var formatName = groupSelect.value !== '' ? (groups[groupSelect.value] || {})[eventName] : null;
            currentFormat = formatName ? FORMATS[formatName] : null;
            partsWrap.innerHTML = '';
            recordWrap.hidden = !currentFormat;
            hint.hidden = !(currentFormat && fromPb);
            if (!currentFormat) {
                syncHidden();
                return;
            }
            currentFormat.parts.forEach(function (part, i) {
                var select = document.createElement('select');
                select.className = 'form-select entry-record-select';
                select.required = true;
                select.setAttribute('aria-label', eventName + 'の応募記録（' + (part.label || part.after) + '）');
                var empty = document.createElement('option');
                empty.value = '';
                empty.textContent = '–';
                select.appendChild(empty);
                for (var n = 0; n <= part.max; n++) {
                    var opt = document.createElement('option');
                    opt.value = part.pad ? pad2(n) : String(n);
                    opt.textContent = opt.value;
                    select.appendChild(opt);
                }
                if (values) {
                    select.value = values[i];
                }
                select.addEventListener('change', function () {
                    hint.hidden = true;
                    syncHidden();
                });
                var unit = document.createElement('span');
                unit.className = 'entry-record-unit';
                unit.textContent = part.after;
                partsWrap.appendChild(select);
                partsWrap.appendChild(unit);
            });
            syncHidden();
        }

        function initialValuesFromPb(eventName) {
            var formatName = (groups[groupSelect.value] || {})[eventName];
            if (!formatName || pbValues[eventName] === undefined) {
                return null;
            }
            var parts = FORMATS[formatName].fromValue(Number(pbValues[eventName]));
            return partsFit(FORMATS[formatName], parts) ? parts : null;
        }

        groupSelect.addEventListener('change', function () {
            fillEvents(groupSelect.value);
            buildRecord('', null, false);
            checkDuplicates();
        });
        eventSelect.addEventListener('change', function () {
            var pb = initialValuesFromPb(eventSelect.value);
            buildRecord(eventSelect.value, pb, pb !== null);
            checkDuplicates();
        });

        // 編集のとき: 今の種目と記録を選んだ状態にする（一覧に無い種目や読めない記録は選び直してもらう）
        var initialEvent = slot.getAttribute('data-initial-event') || '';
        var initialGroup = groupOfEvent(initialEvent);
        groupSelect.required = required;
        if (initialGroup !== '') {
            groupSelect.value = initialGroup;
            fillEvents(initialGroup);
            eventSelect.value = initialEvent;
            var format = FORMATS[groups[initialGroup][initialEvent]];
            var parsed = format.parse(slot.getAttribute('data-initial-record') || '');
            buildRecord(initialEvent, partsFit(format, parsed) ? parsed : null, false);
        }
    });
    checkDuplicates();
})();
