/*
 * 練習のまとめカード（YOUR WEEK）の中身を描く。HTML の枠は includes/week_summary_card.php、見た目は css/week_summary.css。
 *
 * 表示（仕様書）
 *   短距離: 今週の練習回数 / 今月の練習回数 / 今週の本数、日別グラフは本数、週の目標は回数（1〜21回）
 *   長距離: 今週の距離 / 今月の距離 / 今週の練習回数、日別グラフは km、週の目標は距離（5〜300km）
 *   両方  : 長距離と短距離を線で区切って両方（長距離側の3つ目は「目標まで km」）
 * 選んだ表示はこのブラウザに覚えておく（tobg.sumMode）。覚えていなければ、選んでいる NEXT PR の種目から決めた初期値。
 */
(function () {
    var STORAGE_KEY = 'tobg.sumMode';
    var WEEKDAYS = ['月', '火', '水', '木', '金', '土', '日'];
    var KINDS = {
        short: { goalKind: 'count', goalUnit: '回', chartKey: 'reps', chartUnit: '本' },
        long: { goalKind: 'distance', goalUnit: 'km', chartKey: 'km', chartUnit: 'km' }
    };

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    function formatKm(value) {
        return (Math.round(value * 10) / 10).toFixed(1);
    }

    function readMode(fallback) {
        try {
            var saved = localStorage.getItem(STORAGE_KEY);
            if (saved === 'short' || saved === 'long' || saved === 'both') {
                return saved;
            }
        } catch (e) {
            // 保存できないブラウザでは、毎回初期値で表示する
        }
        return fallback;
    }

    function saveMode(mode) {
        try {
            localStorage.setItem(STORAGE_KEY, mode);
        } catch (e) {
            // 覚えられなくても表示は切り替わる
        }
    }

    function mount(card) {
        var data = JSON.parse(card.querySelector('[data-week-data]').textContent);
        var body = card.querySelector('[data-week-body]');
        var saveUrl = card.getAttribute('data-save-url');
        var mode = readMode(card.getAttribute('data-default-mode') || 'short');
        var editingKind = null; // 目標を編集中の種類（'count' / 'distance'）
        var draftValue = 0;

        function stats(kind, isBoth) {
            if (kind === 'short') {
                return [
                    { value: String(data.weekCount), unit: '回', label: '今週の練習回数' },
                    { value: String(data.monthCount), unit: '回', label: '今月の練習回数' },
                    { value: String(data.weekReps), unit: '本', label: '今週の本数' }
                ];
            }
            var third = { value: String(data.weekCount), unit: '回', label: '今週の練習回数' };
            if (isBoth) {
                var goal = data.goals.distance;
                third = goal === null
                    ? { value: '-', unit: '', label: '目標まで' }
                    : { value: formatKm(Math.max(0, goal - data.weekKm)), unit: 'km', label: '目標まで' };
            }
            return [
                { value: formatKm(data.weekKm), unit: 'km', label: '今週の距離' },
                { value: formatKm(data.monthKm), unit: 'km', label: '今月の距離' },
                third
            ];
        }

        function buildStats(kind, isBoth) {
            var wrap = el('div', 'week-stats');
            stats(kind, isBoth).forEach(function (item) {
                var stat = el('div', 'week-stat');
                var value = el('p', 'week-stat-value', item.value);
                if (item.unit) {
                    value.appendChild(el('span', 'week-stat-unit', item.unit));
                }
                stat.appendChild(value);
                stat.appendChild(el('p', 'week-stat-label', item.label));
                wrap.appendChild(stat);
            });
            return wrap;
        }

        // 日別グラフ: 月〜日の7本。棒の上に値、下に曜日。今日は強調、未来の日は薄く
        function buildChart(kind) {
            var key = KINDS[kind].chartKey;
            var values = data.days.map(function (day) {
                return Number(day[key]) || 0;
            });
            var max = Math.max.apply(null, values.concat([0]));
            var chart = el('div', 'week-chart');
            chart.setAttribute('role', 'img');
            chart.setAttribute('aria-label', '日別の' + (kind === 'short' ? '本数' : '距離') + ': ' + data.days.map(function (day, i) {
                return WEEKDAYS[i] + ' ' + (kind === 'short' ? values[i] + '本' : formatKm(values[i]) + 'km');
            }).join('、'));
            data.days.forEach(function (day, i) {
                var isFuture = day.date > data.today;
                var col = el('div', 'week-chart-col' + (day.date === data.today ? ' is-today' : '') + (isFuture ? ' is-future' : ''));
                var label = values[i] > 0 ? (kind === 'short' ? String(values[i]) : formatKm(values[i])) : '';
                col.appendChild(el('span', 'week-chart-value', label));
                var bar = el('span', 'week-chart-bar');
                // 値が無い日も、細い線として残す（7日が並んでいることが分かるように）
                bar.style.height = values[i] > 0 && max > 0 ? Math.max(4, Math.round(values[i] / max * 54)) + 'px' : '3px';
                col.appendChild(bar);
                col.appendChild(el('span', 'week-chart-day', WEEKDAYS[i]));
                chart.appendChild(col);
            });
            return chart;
        }

        // 週の目標: バーと「週の目標 ◯ ✎ … ◯%」。✎ で「− 値 ＋ 保存」に変わる
        function buildGoal(kind) {
            var goalKind = KINDS[kind].goalKind;
            var unit = KINDS[kind].goalUnit;
            var goal = data.goals[goalKind];
            var current = kind === 'short' ? data.weekCount : data.weekKm;
            var percent = goal ? Math.min(100, Math.round(current / goal * 100)) : 0;
            var wrap = el('div', 'week-goal');

            var track = el('div', 'week-progress');
            track.setAttribute('role', 'progressbar');
            track.setAttribute('aria-valuemin', '0');
            track.setAttribute('aria-valuemax', '100');
            track.setAttribute('aria-valuenow', String(percent));
            track.setAttribute('aria-label', '週の目標の達成度');
            var fill = el('span', 'week-progress-fill');
            fill.style.width = percent + '%';
            track.appendChild(fill);
            wrap.appendChild(track);

            var row = el('div', 'week-goal-row');
            if (editingKind === goalKind) {
                var range = data.goalRanges[goalKind];
                var minus = el('button', 'week-goal-step', '−');
                minus.type = 'button';
                minus.setAttribute('aria-label', '目標を減らす');
                minus.disabled = draftValue <= range.min;
                var value = el('span', 'week-goal-draft', String(draftValue) + unit);
                value.setAttribute('aria-live', 'polite');
                var plus = el('button', 'week-goal-step', '＋');
                plus.type = 'button';
                plus.setAttribute('aria-label', '目標を増やす');
                plus.disabled = draftValue >= range.max;
                var save = el('button', 'week-goal-save', '保存');
                save.type = 'button';
                minus.addEventListener('click', function () {
                    draftValue = Math.max(range.min, draftValue - range.step);
                    render();
                });
                plus.addEventListener('click', function () {
                    draftValue = Math.min(range.max, draftValue + range.step);
                    render();
                });
                save.addEventListener('click', function () {
                    saveGoal(goalKind, draftValue, save);
                });
                row.appendChild(el('span', 'week-goal-label', '週の目標'));
                row.appendChild(minus);
                row.appendChild(value);
                row.appendChild(plus);
                row.appendChild(save);
            } else {
                var label = el('span', 'week-goal-label', goal ? '週の目標 ' + (goalKind === 'distance' ? String(goal) : String(Math.round(goal))) + ' ' + unit : '週の目標を決める');
                var edit = el('button', 'week-goal-edit', '✎');
                edit.type = 'button';
                edit.setAttribute('aria-label', '週の目標を編集');
                edit.addEventListener('click', function () {
                    var range = data.goalRanges[goalKind];
                    editingKind = goalKind;
                    draftValue = goal || (goalKind === 'distance' ? 30 : 4);
                    draftValue = Math.min(range.max, Math.max(range.min, draftValue));
                    render();
                });
                row.appendChild(label);
                row.appendChild(edit);
                row.appendChild(el('span', 'week-goal-percent', goal ? percent + '%' : ''));
            }
            wrap.appendChild(row);
            return wrap;
        }

        function saveGoal(goalKind, value, button) {
            button.disabled = true;
            fetch(saveUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ kind: goalKind, value: value })
            }).then(function (response) {
                return response.json();
            }).then(function (result) {
                if (!result.success) {
                    throw new Error(result.message || '目標の保存に失敗しました。');
                }
                data.goals[goalKind] = Number(result.value);
                editingKind = null;
                render();
            }).catch(function (error) {
                button.disabled = false;
                alert(error.message || '目標の保存に失敗しました。');
            });
        }

        function buildSection(kind, isBoth) {
            var section = el('div', 'week-section');
            if (isBoth) {
                section.appendChild(el('p', 'week-section-title', kind === 'long' ? '長距離' : '短距離'));
            }
            section.appendChild(buildStats(kind, isBoth));
            section.appendChild(buildChart(kind));
            section.appendChild(buildGoal(kind));
            return section;
        }

        function render() {
            card.querySelectorAll('[data-week-mode]').forEach(function (btn) {
                btn.setAttribute('aria-pressed', btn.getAttribute('data-week-mode') === mode ? 'true' : 'false');
            });
            body.innerHTML = '';
            if (mode === 'both') {
                body.appendChild(buildSection('long', true));
                body.appendChild(el('hr', 'week-divider'));
                body.appendChild(buildSection('short', true));
            } else {
                body.appendChild(buildSection(mode, false));
            }
        }

        card.querySelectorAll('[data-week-mode]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                mode = btn.getAttribute('data-week-mode');
                editingKind = null;
                saveMode(mode);
                render();
            });
        });
        render();
    }

    document.querySelectorAll('[data-week-summary]').forEach(mount);
})();
