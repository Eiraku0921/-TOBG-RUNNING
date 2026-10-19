/*
 * 練習日記のタイムライン（ホーム・みんなの練習日記で共通）。見た目は css/diary_timeline.css。
 *
 * いいね・コメント・練習内容の表は、カレンダーの日の詳細と同じ部品を使うため、各ページから渡してもらう。
 *
 *   DiaryTimeline.mount({
 *       listEl: タイムラインを入れる要素,
 *       posts: 練習日記の配列（fetch_public_training_diary_feed の結果）,
 *       basePath: 画像・ページへのパスの頭（ホームは ''、front/ のページは '../'）,
 *       isLoggedIn: ログイン中か（いいねを押したとき、未ログインならログイン画面へ）,
 *       escapeHtml, handleLikeClick(postId, button), buildCommentPanel(post),
 *       buildWeightSetsTable(sets), buildRaceEntriesTable(entries), buildRunningSetsBlock(sets),
 *       pageSize: 1回に出す件数（省略時 20）,
 *       scopeLabel: 日時の横に出す公開範囲（例「全体公開」「朝ラン仲間」。省略時は出さない）,
 *       emptyHtml: 投稿が無いときの文（省略時は「まだ投稿がありません…」）
 *   });
 */
(function () {
    var ICON_HEART = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>';
    var ICON_COMMENT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>';

    // ジョグの距離の文字。1行なら「8.5km」、複数行なら「5.0km ＋ 3.5km（合計 8.5km）」
    function formatJogDistances(entries) {
        var list = (entries || []).map(function (entry) {
            return Number(entry.distance_km) || 0;
        }).filter(function (km) {
            return km > 0;
        });
        if (list.length === 0) {
            return '';
        }
        var parts = list.map(function (km) {
            return km.toFixed(1) + 'km';
        });
        if (list.length === 1) {
            return parts[0];
        }
        var total = list.reduce(function (sum, km) {
            return sum + km;
        }, 0);
        return parts.join(' ＋ ') + '（合計 ' + (Math.round(total * 10) / 10).toFixed(1) + 'km）';
    }

    // ジョグの練習内容（タイムライン・カレンダーの詳細で共通）
    function buildJogBlock(entries) {
        var block = document.createElement('div');
        block.className = 'post-time';
        block.textContent = 'ジョグ ' + (formatJogDistances(entries) || '-');
        return block;
    }

    // ラップの秒数。60秒未満は「45.2秒」、それ以上は「2:05.3」
    function formatLapSeconds(seconds) {
        var value = Number(seconds);
        if (value < 60) {
            return String(Number(value.toFixed(2))) + '秒';
        }
        var minutes = Math.floor(value / 60);
        var rest = Number((value - minutes * 60).toFixed(2));
        return minutes + ':' + (rest < 10 ? '0' : '') + rest;
    }

    // タイムラインの一覧に出す「種目・タイム・補足」の行（全部。何行出すかは呼ぶ側で決める）
    //   大会: 種目・記録・風/公認、ランニング: 距離×本数・ラップ（なければ設定タイム）、ウエイト: 種目・重さ×回数×セット、ジョグ: 距離
    var LAP_PREVIEW_MAX = 4;
    var SUMMARY_MAX = 2;
    function summarizeTraining(post) {
        if (post.training_type === 'race') {
            return (post.race_entries || []).map(function (entry) {
                return { event: entry.event_name, time: entry.result_label, note: entry.notes_label || '' };
            });
        }
        if (post.training_type === 'weight') {
            return (post.weight_sets || []).map(function (set) {
                var load = set.is_bodyweight ? '自重' : (set.weight_kg !== null && set.weight_kg !== undefined ? set.weight_kg + 'kg' : (set.weight_note || ''));
                var parts = [load];
                if (set.reps !== null && set.reps !== undefined) {
                    parts.push(set.reps + '回');
                }
                if (set.sets !== null && set.sets !== undefined) {
                    parts.push(set.sets + 'セット');
                }
                return { event: set.exercise_name, time: parts.filter(Boolean).join(' × '), note: '' };
            });
        }
        if (post.training_type === 'jog') {
            var jog = formatJogDistances(post.jog_entries);
            return jog ? [{ event: 'ジョグ', time: jog, note: '' }] : [];
        }
        if (post.running_sets && post.running_sets.length > 0) {
            return post.running_sets.map(function (set) {
                var laps = (set.laps || []).map(function (lap) {
                    return lap.lap_time_seconds !== null && lap.lap_time_seconds !== undefined ? formatLapSeconds(lap.lap_time_seconds) : (lap.lap_time_label || '');
                }).filter(Boolean);
                var time = laps.length > 0
                    ? laps.slice(0, LAP_PREVIEW_MAX).join(' / ') + (laps.length > LAP_PREVIEW_MAX ? ' …' : '')
                    : (set.target_time_label ? '設定 ' + set.target_time_label : '');
                return {
                    event: set.distance_label + ' × ' + set.reps + '本' + (set.sets > 1 ? ' × ' + set.sets + 'set' : ''),
                    time: time,
                    note: ''
                };
            });
        }
        // 種目の記録がない古い投稿は、練習内容の文字を行ごとに出す
        return String(post.time_text || '').split('\n').map(function (line) {
            return line.trim();
        }).filter(Boolean).map(function (line) {
            return { event: line, time: '', note: '' };
        });
    }

    // 投稿した日時。「今日 06:10」「昨日 21:30」、それより前は「10/5 07:00」（去年以前は年も付ける）
    function formatPostedAt(value) {
        var date = new Date(String(value || '').replace(' ', 'T'));
        if (isNaN(date.getTime())) {
            return '';
        }
        var time = String(date.getHours()).padStart(2, '0') + ':' + String(date.getMinutes()).padStart(2, '0');
        var today = new Date();
        today.setHours(0, 0, 0, 0);
        var day = new Date(date.getFullYear(), date.getMonth(), date.getDate());
        var diffDays = Math.round((today - day) / 86400000);
        if (diffDays === 0) {
            return '今日 ' + time;
        }
        if (diffDays === 1) {
            return '昨日 ' + time;
        }
        var md = (date.getMonth() + 1) + '/' + date.getDate();
        return (date.getFullYear() === today.getFullYear() ? md : date.getFullYear() + '/' + md) + ' ' + time;
    }

    function mount(options) {
        var listEl = options.listEl;
        var basePath = options.basePath || '';
        var escapeHtml = options.escapeHtml;
        var pageSize = options.pageSize || 20;
        var shown = 0;
        // 投稿した時間が新しい順
        var posts = (options.posts || []).slice().sort(function (a, b) {
            return String(b.created_at).localeCompare(String(a.created_at)) || (b.id - a.id);
        });

        function buildAvatar(post) {
            var avatarEl;
            if (post.avatar_path) {
                avatarEl = document.createElement('img');
                avatarEl.className = 'avatar-image';
                avatarEl.src = basePath + post.avatar_path;
                avatarEl.alt = '';
            } else {
                avatarEl = document.createElement('span');
                avatarEl.className = 'avatar';
                avatarEl.textContent = (post.user_name || '').substr(0, 1);
            }
            return avatarEl;
        }

        // 開いたときの練習内容（カレンダーの日の詳細と同じ表を使う）。何も記録がなければ null
        function buildTrainingContent(post) {
            var content = null;
            if (post.training_type === 'weight') {
                content = options.buildWeightSetsTable(post.weight_sets);
            } else if (post.training_type === 'race') {
                content = options.buildRaceEntriesTable(post.race_entries);
            } else if (post.training_type === 'jog') {
                content = buildJogBlock(post.jog_entries);
            } else if (post.running_sets && post.running_sets.length > 0) {
                content = options.buildRunningSetsBlock(post.running_sets);
            } else if (post.time_text) {
                content = document.createElement('div');
                content.className = 'post-time';
                content.textContent = post.time_text;
            }
            if (!content) {
                return null;
            }
            var wrap = document.createElement('div');
            wrap.className = 'tl-training';
            wrap.appendChild(content);
            return wrap;
        }

        function buildItem(post) {
            var item = document.createElement('article');
            item.className = 'tl-item';
            var detailId = 'tdTimelineMedia' + post.id;
            var commentsId = 'tdTimelineComments' + post.id;

            // 一覧には種目とタイムを2つまで。残りの種目・ひとこと・写真/動画は、押して開いたときに出す
            var summaryRows = summarizeTraining(post);
            var summaryHtml = '';
            if (summaryRows.length > 0) {
                summaryHtml = '<div class="tl-open tl-summary" role="button" tabindex="0" aria-controls="' + detailId + '" aria-expanded="false">' +
                    summaryRows.slice(0, SUMMARY_MAX).map(function (row) {
                        return '<div class="tl-sum-row">' +
                            '<span class="tl-sum-event">' + escapeHtml(row.event) + '</span>' +
                            (row.time ? '<span class="tl-sum-time">' + escapeHtml(row.time) + '</span>' : '') +
                            (row.note ? '<span class="tl-sum-note">' + escapeHtml(row.note) + '</span>' : '') +
                        '</div>';
                    }).join('') +
                    (summaryRows.length > SUMMARY_MAX ? '<p class="tl-sum-more">ほか ' + (summaryRows.length - SUMMARY_MAX) + ' 種目</p>' : '') +
                '</div>';
            }

            // 名前・タイトル・種目の行を押すと、練習内容・ひとこと・写真・動画が開く
            var head = document.createElement('div');
            head.className = 'tl-head';
            head.appendChild(buildAvatar(post));

            var main = document.createElement('div');
            main.className = 'tl-head-main';
            main.innerHTML =
                '<div class="tl-name-row">' +
                    '<button type="button" class="tl-open tl-name" aria-controls="' + detailId + '" aria-expanded="false">' + escapeHtml(post.user_name) + '</button>' +
                    (post.is_coach ? '<span class="tl-coach">コーチの投稿</span>' : '') +
                '</div>' +
                // 投稿した日時と、どこに公開された投稿か（例: 今日 06:10 · 朝ラン仲間）
                '<p class="tl-time">' + escapeHtml(formatPostedAt(post.created_at)) + (options.scopeLabel ? ' · ' + escapeHtml(options.scopeLabel) : '') + '</p>' +
                '<button type="button" class="tl-open tl-title" aria-controls="' + detailId + '" aria-expanded="false">' + escapeHtml(post.menu_text) + '</button>' +
                summaryHtml;
            head.appendChild(main);
            item.appendChild(head);

            // 開いたときに出す部分（練習内容 → ひとこと → 写真・動画の順）。中身は初めて開いたときに作る
            var detail = document.createElement('div');
            detail.className = 'tl-detail';
            detail.id = detailId;
            detail.hidden = true;
            var media = document.createElement('div');
            media.className = 'tl-media';
            item.appendChild(detail);

            var openButtons = item.querySelectorAll('.tl-open');
            openButtons.forEach(function (button) {
                // 種目の行は button ではないので、キーボードの Enter・スペースでも開けるようにする
                if (button.tagName !== 'BUTTON') {
                    button.addEventListener('keydown', function (event) {
                        if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault();
                            button.click();
                        }
                    });
                }
                button.addEventListener('click', function () {
                    var opening = !item.classList.contains('is-open');
                    item.classList.toggle('is-open', opening);
                    openButtons.forEach(function (other) {
                        other.setAttribute('aria-expanded', opening ? 'true' : 'false');
                    });
                    if (opening && detail.childElementCount === 0) {
                        var training = buildTrainingContent(post);
                        if (training) {
                            detail.appendChild(training);
                        }
                        if (post.comment_text) {
                            var comment = document.createElement('p');
                            comment.className = 'tl-comment-text';
                            comment.textContent = post.comment_text;
                            detail.appendChild(comment);
                        }
                        if (post.image_path) {
                            var img = document.createElement('img');
                            img.src = basePath + post.image_path;
                            img.alt = '練習写真';
                            media.appendChild(img);
                        }
                        if (post.video_path) {
                            var video = document.createElement('video');
                            video.src = basePath + post.video_path;
                            video.controls = true;
                            video.playsInline = true;
                            video.preload = 'metadata';
                            media.appendChild(video);
                        }
                        if (media.childElementCount > 0) {
                            detail.appendChild(media);
                        }
                    }
                    detail.hidden = !opening || detail.childElementCount === 0;
                    if (!opening) {
                        detail.querySelectorAll('video').forEach(function (video) { video.pause(); });
                    }
                });
            });

            // いいね・コメント・写真/動画のバッジ
            var actions = document.createElement('div');
            actions.className = 'tl-actions';

            var likeBtn = document.createElement('button');
            likeBtn.type = 'button';
            likeBtn.className = 'tl-action tl-like' + (post.liked_by_me ? ' active' : '');
            likeBtn.setAttribute('aria-label', 'いいね');
            likeBtn.innerHTML = ICON_HEART + '<span class="like-count">' + post.like_count + '</span>';
            likeBtn.addEventListener('click', function () {
                if (!options.isLoggedIn) {
                    window.location.href = basePath + 'mypage/login.php';
                    return;
                }
                options.handleLikeClick(post.id, likeBtn);
            });
            actions.appendChild(likeBtn);

            var commentBtn = document.createElement('button');
            commentBtn.type = 'button';
            commentBtn.className = 'tl-action';
            commentBtn.setAttribute('aria-controls', commentsId);
            commentBtn.setAttribute('aria-expanded', 'false');
            commentBtn.setAttribute('aria-label', 'コメント');
            commentBtn.innerHTML = ICON_COMMENT + '<span class="tl-action-label">コメント</span><span data-comment-count-for="' + post.id + '">' + post.comment_count + '</span>';
            actions.appendChild(commentBtn);

            if (post.video_path || post.image_path) {
                var badge = document.createElement('span');
                badge.className = 'tl-badge' + (post.video_path ? ' is-video' : '');
                badge.textContent = post.video_path ? '動画 1' : '写真 1';
                actions.appendChild(badge);
            }
            item.appendChild(actions);

            var comments = document.createElement('div');
            comments.className = 'tl-comments';
            comments.id = commentsId;
            comments.hidden = true;
            item.appendChild(comments);

            commentBtn.addEventListener('click', function () {
                var opening = comments.hidden;
                if (opening && comments.childElementCount === 0) {
                    comments.appendChild(options.buildCommentPanel(post));
                }
                comments.hidden = !opening;
                commentBtn.setAttribute('aria-expanded', opening ? 'true' : 'false');
            });

            return item;
        }

        function renderMore() {
            var oldMore = listEl.querySelector('.tl-more');
            if (oldMore) {
                oldMore.remove();
            }
            posts.slice(shown, shown + pageSize).forEach(function (post) {
                listEl.appendChild(buildItem(post));
            });
            shown = Math.min(posts.length, shown + pageSize);
            if (shown < posts.length) {
                var more = document.createElement('button');
                more.type = 'button';
                more.className = 'btn btn-outline-secondary btn-sm tl-more';
                more.textContent = 'さらに表示';
                more.addEventListener('click', renderMore);
                listEl.appendChild(more);
            }
        }

        if (posts.length === 0) {
            listEl.innerHTML = '<p class="tl-empty">' + (options.emptyHtml || 'まだ投稿がありません。<br>「記録する」から最初の投稿をしてみましょう。') + '</p>';
        } else {
            renderMore();
        }
    }

    // 見るだけの短い一覧（PC のホームの「みんなの練習日記」）。新しい順に count 件を、押すとみんなのページへ移るカードで出す
    //   DiaryTimeline.renderPreview({ listEl, posts, basePath, linkUrl, escapeHtml, count })
    function renderPreview(options) {
        var escapeHtml = options.escapeHtml;
        var basePath = options.basePath || '';
        var posts = (options.posts || []).slice().sort(function (a, b) {
            return String(b.created_at).localeCompare(String(a.created_at)) || (b.id - a.id);
        }).slice(0, options.count || 3);
        if (posts.length === 0) {
            options.listEl.innerHTML = '<p class="tl-empty">まだ投稿がありません。</p>';
            return;
        }
        options.listEl.innerHTML = posts.map(function (post) {
            var avatar = post.avatar_path
                ? '<img class="avatar-image" src="' + escapeHtml(basePath + post.avatar_path) + '" alt="">'
                : '<span class="avatar" aria-hidden="true">' + escapeHtml((post.user_name || '').substr(0, 1)) + '</span>';
            var rows = summarizeTraining(post);
            var summary = rows.length === 0 ? '' : '<span class="tl-summary">' + rows.slice(0, SUMMARY_MAX).map(function (row) {
                return '<span class="tl-sum-row">' +
                    '<span class="tl-sum-event">' + escapeHtml(row.event) + '</span>' +
                    (row.time ? '<span class="tl-sum-time">' + escapeHtml(row.time) + '</span>' : '') +
                    (row.note ? '<span class="tl-sum-note">' + escapeHtml(row.note) + '</span>' : '') +
                '</span>';
            }).join('') + (rows.length > SUMMARY_MAX ? '<span class="tl-sum-more">ほか ' + (rows.length - SUMMARY_MAX) + ' 種目</span>' : '') + '</span>';
            return '<a class="tl-item tl-preview" href="' + escapeHtml(options.linkUrl) + '">' +
                '<span class="tl-head">' + avatar +
                    '<span class="tl-head-main">' +
                        '<span class="tl-name-row"><span class="tl-name">' + escapeHtml(post.user_name) + '</span>' +
                            (post.is_coach ? '<span class="tl-coach">コーチの投稿</span>' : '') + '</span>' +
                        '<span class="tl-time">' + escapeHtml(formatPostedAt(post.created_at)) + '</span>' +
                        '<span class="tl-title">' + escapeHtml(post.menu_text) + '</span>' +
                        summary +
                    '</span>' +
                '</span>' +
                '<span class="tl-preview-foot"><span class="tl-preview-like" aria-label="いいね">' + ICON_HEART + (Number(post.like_count) || 0) + '</span> ・ コメント ' + (Number(post.comment_count) || 0) + '</span>' +
            '</a>';
        }).join('');
    }

    // ジョグの表示は、カレンダーの日の詳細（各ページ）でも使う
    window.DiaryTimeline = { mount: mount, renderPreview: renderPreview, buildJogBlock: buildJogBlock, formatJogDistances: formatJogDistances };
})();
