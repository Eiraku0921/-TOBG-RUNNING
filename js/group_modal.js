/*
 * マイグループの画面（includes/group_modal.php）の動き: 一覧・作成・参加・招待・名前とアイコンの変更・脱退・削除の申請。
 * 操作は back/member_group_action.php（アイコンは back/member_group_icon.php）に送り、最新のグループ一覧を受け取る。
 * ページから window.TOBG_GROUP（base: パスの頭、groups: 自分のグループ、joinCode: 招待コード）を受け取る。
 */
(function () {
    var modalEl = document.getElementById('groupModal');
    if (!modalEl) {
        return;
    }
    var CONFIG = window.TOBG_GROUP || {};
    var BASE = CONFIG.base || '';
    var groups = CONFIG.groups || [];
    var inviteJoinCode = CONFIG.joinCode || '';
    var titleEl = document.getElementById('groupModalTitle');
    var listEl = document.getElementById('groupList');
    var panes = modalEl.querySelectorAll('[data-group-pane]');
    var changed = false; // 作成・参加などをしたら、閉じたときにページを読み直して見出しのシートや投稿画面にも反映する
    var currentGroupId = 0;
    var paneTitles = { list: 'マイグループ', join: 'グループに参加', create: 'グループを作成', invite: '招待する', rename: 'グループ名を変更', icon: 'アイコンを変更' };
    var ICON_GROUP = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    function findGroup(id) {
        for (var i = 0; i < groups.length; i++) {
            if (groups[i].id === id) {
                return groups[i];
            }
        }
        return null;
    }

    function showPane(name) {
        panes.forEach(function (pane) {
            pane.hidden = pane.getAttribute('data-group-pane') !== name;
            var error = pane.querySelector('[data-group-error]');
            if (error) {
                error.textContent = '';
            }
        });
        titleEl.textContent = paneTitles[name] || 'マイグループ';
        if (name === 'list') {
            renderList();
        }
    }

    function post(payload) {
        return fetch(BASE + 'back/member_group_action.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json();
        }).then(function (data) {
            if (data.groups) {
                groups = data.groups;
            }
            return data;
        }).catch(function () {
            return { success: false, message: '通信に失敗しました。電波の良い所で、もう一度お試しください。' };
        });
    }

    // 作成した・参加しているグループを1つのリストにまとめて出す
    function renderList() {
        listEl.innerHTML = '';
        if (groups.length === 0) {
            listEl.innerHTML = '<li class="group-empty">まだグループがありません。<br>「＋ 作成」で作るか、招待コードで参加できます。</li>';
            return;
        }
        groups.forEach(function (group) {
            var item = document.createElement('li');
            item.className = 'group-item';
            var pending = group.status !== 'active';
            var sub = group.is_owner
                ? 'メンバー ' + group.member_count + '人'
                : '代表者：' + esc(group.owner_name) + '・' + group.member_count + '人';
            var html = '<div class="group-item-row">' +
                '<span class="group-item-icon">' + ICON_GROUP + '</span>' +
                '<span class="group-item-text"><span class="group-item-name">' + esc(group.name) + '</span>' +
                '<span class="group-item-sub">' + sub + '</span>' +
                (pending ? '<span class="group-item-pending">削除申請中（最高管理者の承認待ち）</span>' : '') +
                '</span>';
            // 削除申請中の自分のグループは、招待もメニューも出さない
            if (group.is_owner && !pending) {
                html += '<button type="button" class="btn btn-outline-secondary btn-sm" data-group-invite="' + group.id + '">招待</button>';
            }
            if (!(group.is_owner && pending)) {
                html += '<button type="button" class="group-more-btn" data-group-more="' + group.id + '" aria-expanded="false" aria-label="メニュー">…</button>';
            }
            html += '</div>';
            if (!(group.is_owner && pending)) {
                html += '<div class="group-menu" hidden data-group-menu="' + group.id + '">' +
                    (group.is_owner
                        ? '<button type="button" class="btn btn-outline-secondary btn-sm" data-group-rename="' + group.id + '">グループ名を変更</button>' +
                          '<button type="button" class="btn btn-outline-secondary btn-sm" data-group-icon="' + group.id + '">アイコンを変更</button>' +
                          '<button type="button" class="btn btn-sm btn-group-danger" data-group-delete="' + group.id + '">削除を申請する</button>'
                        : '<button type="button" class="btn btn-sm btn-group-danger" data-group-leave="' + group.id + '">脱退する</button>') +
                    '</div>';
            }
            item.innerHTML = html;
            listEl.appendChild(item);
        });
    }

    function openInvite(groupId) {
        currentGroupId = groupId;
        post({ action: 'invite', group_id: groupId }).then(function (data) {
            if (!data.success) {
                alert(data.message);
                return;
            }
            var group = findGroup(groupId);
            if (!group) {
                return;
            }
            var link = new URL(BASE + 'front/training_diary.php?join_group=' + group.invite_code, window.location.href).href;
            var expires = new Date(String(group.invite_expires_at).replace(' ', 'T'));
            var expiresText = isNaN(expires.getTime()) ? '' : '有効期限：' + (expires.getMonth() + 1) + '月' + expires.getDate() + '日 ' + expires.getHours() + ':' + String(expires.getMinutes()).padStart(2, '0') + 'まで（7日間）';
            document.getElementById('groupInviteName').textContent = group.name;
            document.getElementById('groupInviteCode').textContent = group.invite_code;
            document.getElementById('groupInviteExpires').textContent = expiresText;
            modalEl.querySelector('[data-invite-expires-copy]').textContent = expiresText;
            document.getElementById('groupInviteLink').value = link;
            document.getElementById('groupInviteLine').href = 'https://line.me/R/msg/text/?' + encodeURIComponent('TOBG-RUNNING のグループ「' + group.name + '」に招待します。\n' + link + '\n招待コード：' + group.invite_code);
            selectInviteTab('code');
            showPane('invite');
        });
    }

    function selectInviteTab(name) {
        modalEl.querySelectorAll('[data-invite-tab]').forEach(function (tab) {
            var selected = tab.getAttribute('data-invite-tab') === name;
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            document.getElementById(tab.getAttribute('aria-controls')).hidden = !selected;
        });
    }

    modalEl.addEventListener('click', function (event) {
        var target = event.target.closest('button, a');
        if (!target || !modalEl.contains(target)) {
            return;
        }
        var open = target.getAttribute('data-group-open');
        if (open) {
            showPane(open);
            if (open === 'join' || open === 'create') {
                var input = modalEl.querySelector('[data-group-pane="' + open + '"] input');
                input.value = '';
                input.focus();
            }
            return;
        }
        if (target.hasAttribute('data-invite-tab')) {
            selectInviteTab(target.getAttribute('data-invite-tab'));
            return;
        }
        if (target.hasAttribute('data-group-more')) {
            var menu = listEl.querySelector('[data-group-menu="' + target.getAttribute('data-group-more') + '"]');
            menu.hidden = !menu.hidden;
            target.setAttribute('aria-expanded', menu.hidden ? 'false' : 'true');
            return;
        }
        if (target.hasAttribute('data-group-invite')) {
            openInvite(Number(target.getAttribute('data-group-invite')));
            return;
        }
        if (target.hasAttribute('data-group-icon')) {
            currentGroupId = Number(target.getAttribute('data-group-icon'));
            var iconGroup = findGroup(currentGroupId);
            showPane('icon');
            document.getElementById('groupIconName').textContent = iconGroup ? iconGroup.name : '';
            document.getElementById('groupIconFile').value = '';
            renderIconPreview(iconGroup && iconGroup.icon_path ? iconGroup.icon_path : '');
            document.getElementById('groupIconRemove').hidden = !(iconGroup && iconGroup.icon_path);
            return;
        }
        if (target.id === 'groupIconRemove') {
            if (!confirm('アイコンを外して、標準の人のアイコンに戻しますか？')) {
                return;
            }
            var removeBody = new FormData();
            removeBody.append('action', 'remove');
            removeBody.append('group_id', String(currentGroupId));
            postIcon(removeBody, modalEl.querySelector('[data-group-pane="icon"] [data-group-error]'));
            return;
        }
        if (target.hasAttribute('data-group-rename')) {
            currentGroupId = Number(target.getAttribute('data-group-rename'));
            var group = findGroup(currentGroupId);
            showPane('rename');
            var renameInput = document.getElementById('groupRenameName');
            renameInput.value = group ? group.name : '';
            renameInput.focus();
            return;
        }
        var leaveId = target.getAttribute('data-group-leave');
        var deleteId = target.getAttribute('data-group-delete');
        if (leaveId || deleteId) {
            var groupId = Number(leaveId || deleteId);
            var targetGroup = findGroup(groupId);
            var name = targetGroup ? targetGroup.name : '';
            // 脱退すると、そのグループに公開していた自分の日記も外れる（サーバーの leave_member_group）
            var question = leaveId
                ? '「' + name + '」から脱退しますか？\n\n' +
                  '・このグループに公開していた自分の練習日記は、メンバーから見えなくなります\n' +
                  '・もう一度参加するには、招待コードが必要です\n\n' +
                  '本当によろしいですか？'
                : '「' + name + '」の削除を申請しますか？本当によろしいですか？';
            if (!confirm(question)) {
                return;
            }
            post({ action: leaveId ? 'leave' : 'request_delete', group_id: groupId }).then(function (data) {
                if (!data.success) {
                    alert(data.message);
                    return;
                }
                changed = true;
                renderList();
            });
        }
    });

    document.getElementById('groupInviteCopy').addEventListener('click', function () {
        var input = document.getElementById('groupInviteLink');
        var button = this;
        var done = function () {
            button.textContent = 'コピーしました';
            setTimeout(function () { button.textContent = 'コピー'; }, 1500);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(input.value).then(done);
        } else {
            input.select();
            document.execCommand('copy');
            done();
        }
    });

    // アイコン: 選んだ写真（または今のアイコン）を丸く表示して確認できるようにする
    function renderIconPreview(src) {
        var preview = document.getElementById('groupIconPreview');
        preview.innerHTML = src ? '<img alt="" src="' + esc(src.indexOf('blob:') === 0 ? src : BASE + src) + '">' : '<span>' + ICON_GROUP + '</span>';
    }
    document.getElementById('groupIconFile').addEventListener('change', function () {
        var file = this.files && this.files[0];
        if (file) {
            renderIconPreview(URL.createObjectURL(file));
        }
    });
    function postIcon(body, error) {
        fetch(BASE + 'back/member_group_icon.php', { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (response) { return response.json(); })
            .catch(function () { return { success: false, message: '通信に失敗しました。電波の良い所で、もう一度お試しください。' }; })
            .then(function (data) {
                if (!data.success) {
                    error.textContent = data.message;
                    return;
                }
                groups = data.groups || groups;
                changed = true;
                showPane('list');
            });
    }

    // 参加・作成・名前の変更（空欄や間違いは、その画面の中に赤字で出す）
    modalEl.querySelectorAll('form[data-group-pane]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var pane = form.getAttribute('data-group-pane');
            if (pane === 'icon') {
                var file = document.getElementById('groupIconFile').files[0];
                var iconError = form.querySelector('[data-group-error]');
                if (!file) {
                    iconError.textContent = '写真を選んでください。';
                    return;
                }
                if (file.size > 5 * 1024 * 1024) {
                    iconError.textContent = '写真は5MB以内にしてください。';
                    return;
                }
                var iconBody = new FormData();
                iconBody.append('group_id', String(currentGroupId));
                iconBody.append('icon', file);
                postIcon(iconBody, iconError);
                return;
            }
            var input = form.querySelector('input');
            var error = form.querySelector('[data-group-error]');
            var value = input.value.trim();
            if (value === '') {
                error.textContent = pane === 'join' ? '招待コードを入力してください。' : 'グループ名を入力してください。';
                input.focus();
                return;
            }
            var payload = pane === 'join'
                ? { action: 'join', code: value }
                : (pane === 'create' ? { action: 'create', name: value } : { action: 'rename', group_id: currentGroupId, name: value });
            var submit = form.querySelector('[type="submit"]');
            submit.disabled = true;
            post(payload).then(function (data) {
                submit.disabled = false;
                if (!data.success) {
                    error.textContent = data.message;
                    return;
                }
                changed = true;
                if (pane === 'create') {
                    // 作ったら、そのまま招待の画面へ
                    openInvite(data.group_id);
                } else {
                    showPane('list');
                }
            });
        });
    });

    modalEl.addEventListener('show.bs.modal', function () {
        showPane('list');
    });
    modalEl.addEventListener('hidden.bs.modal', function () {
        if (changed) {
            window.location.reload();
        }
    });

    // ページの「＋ 招待」（data-open-group-invite="グループID"）から、招待の画面を直接開く
    document.addEventListener('click', function (event) {
        var opener = event.target.closest('[data-open-group-invite]');
        if (!opener || typeof bootstrap === 'undefined') {
            return;
        }
        event.preventDefault();
        bootstrap.Modal.getOrCreateInstance(modalEl).show(); // 開いたときは一覧になるので、そのあと招待へ切り替える
        openInvite(Number(opener.getAttribute('data-open-group-invite')));
    });

    // 招待リンクから来たとき（ログイン中）は、コードを入れた状態で「参加する」画面を開く
    if (inviteJoinCode !== '' && typeof bootstrap !== 'undefined') {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
        showPane('join');
        document.getElementById('groupJoinCode').value = inviteJoinCode;
        if (window.history && window.history.replaceState) {
            window.history.replaceState(null, '', window.location.pathname);
        }
    }

    // 未ログインのときにマイグループの「参加する」「作成」を押してログインした人は、その画面を開いた状態で戻す
    var openGroupPane = new URLSearchParams(window.location.search).get('open_group');
    if (inviteJoinCode === '' && ['list', 'join', 'create'].indexOf(openGroupPane) !== -1 && typeof bootstrap !== 'undefined') {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
        showPane(openGroupPane);
        if (window.history && window.history.replaceState) {
            window.history.replaceState(null, '', window.location.pathname + window.location.hash);
        }
    }
})();
