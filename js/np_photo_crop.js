/*
 * NEXT PR の背景写真の「位置を調整」（mypage/next_pr.php の #prPhotoModal と #prCropModal）。
 *
 * 写真を選ぶ → 長辺 1600px に縮める → 位置を調整の窓（ドラッグで位置、バーで 1〜3 倍の大きさ）→「この位置で決定」で
 * 枠に見えている範囲だけを JPEG（品質 0.85、幅 1400px まで）に切り抜き、元写真（縮めたもの）と一緒に NEXT PR のページへ送る。
 * 「位置を調整」は保存してある元写真で窓を開き、切り抜いた写真だけを送る（元写真はサーバーで今のものを使い続ける）。
 * 枠の縦横比は、このページの自己ベストのカード（.np-photo-card）の今の縦横比に合わせる。
 * 大きさは「写真全体が枠に収まる大きさ」〜「枠をちょうど覆う大きさの 3 倍」。写真が枠より小さい向きでは写真が枠からはみ出さない範囲で、
 * 大きい向きでは枠が写真からはみ出さない範囲で動かせる。写真が枠より小さいときの隙間は、同じ写真をぼかして薄暗くしたもので埋める
 * （窓の中の見え方と、保存する写真は同じ）。
 */
(function () {
    var menuEl = document.getElementById('prPhotoModal');
    var cropEl = document.getElementById('prCropModal');
    if (!menuEl || !cropEl || !window.bootstrap) {
        return;
    }

    var MAX_SOURCE = 1600;  // 元写真（調整に使う写真）の長辺
    var MAX_OUTPUT = 1400;  // 切り抜いた写真の幅
    var QUALITY = 0.85;
    // 大きさは「枠をちょうど覆う大きさ」を 1 倍として数える。いちばん小さいのは、写真全体が枠に収まる大きさの MIN_FIT_RATIO 倍
    var MIN_FIT_RATIO = 0.5;
    // 開いたときの大きさ: 上下にも動かせるよう、写真の縦の長さが枠の 1.3 倍になる大きさから始める（小さくする余地も残る）
    var START_VERTICAL_ROOM = 1.3;
    var MAX_ZOOM = 3;

    var input = menuEl.querySelector('[data-pr-photo-input]');
    var chooseBtn = menuEl.querySelector('[data-pr-photo-choose]');
    var adjustBtn = menuEl.querySelector('[data-pr-photo-adjust]');
    var menuError = menuEl.querySelector('[data-pr-photo-error]');
    var frame = cropEl.querySelector('[data-crop-frame]');
    var img = cropEl.querySelector('[data-crop-img]');
    var backdrop = cropEl.querySelector('[data-crop-backdrop]');
    var BACKDROP_BLUR = 16;        // 隙間を埋めるぼかし（px。窓の CSS と同じ）
    var BACKDROP_BRIGHTNESS = 0.75;
    var BACKDROP_FALLBACK = '#1F2A44'; // ぼかしが使えないブラウザでは紺で埋める
    var zoomInput = cropEl.querySelector('[data-crop-zoom]');
    var saveBtn = cropEl.querySelector('[data-crop-save]');
    var cancelBtn = cropEl.querySelector('[data-crop-cancel]');
    var cropError = cropEl.querySelector('[data-crop-error]');
    var menuModal = bootstrap.Modal.getOrCreateInstance(menuEl);
    var cropModal = bootstrap.Modal.getOrCreateInstance(cropEl);

    // いま調整している写真
    var state = {
        source: null,        // 切り抜きに使う絵（canvas か img）
        width: 0,            // その大きさ
        height: 0,
        originalBlob: null,  // 新しく選んだときだけ: 一緒に送る元写真（縮めたもの）
        displayUrl: '',      // 枠に出す写真の URL（作ったものは閉じるときに片付ける）
        frameW: 0,           // 枠の大きさ
        frameH: 0,
        baseScale: 1,        // 大きさ 1 倍のとき（枠をちょうど覆う）の倍率
        minZoom: 1,          // いちばん小さい大きさ（写真全体が枠に収まる）
        zoom: 1,
        x: 0,                // 枠の左上から見た、写真の左上の位置
        y: 0
    };

    function showError(el, text) {
        el.textContent = text;
        el.hidden = text === '';
    }

    function loadImage(url) {
        return new Promise(function (resolve, reject) {
            var image = new Image();
            image.onload = function () {
                resolve(image);
            };
            image.onerror = reject;
            image.src = url;
        });
    }

    function canvasToBlob(canvas, quality) {
        return new Promise(function (resolve, reject) {
            canvas.toBlob(function (blob) {
                if (blob) {
                    resolve(blob);
                } else {
                    reject(new Error('toBlob failed'));
                }
            }, 'image/jpeg', quality);
        });
    }

    // 大きな写真でも重くならないよう、長辺 MAX_SOURCE に縮めた canvas にする
    function shrink(image) {
        var scale = Math.min(1, MAX_SOURCE / Math.max(image.naturalWidth, image.naturalHeight));
        var canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
        canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
        canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);
        return canvas;
    }

    // 枠の縦横比 = 自己ベストのカードの今の縦横比（測れなければ 16:9）
    function frameRatio() {
        var card = document.querySelector('.np-photo-card');
        var rect = card ? card.getBoundingClientRect() : null;
        return rect && rect.width > 0 && rect.height > 0 ? rect.width / rect.height : 16 / 9;
    }

    function scale() {
        return state.baseScale * state.zoom;
    }

    // 動かせる範囲におさえる: 写真が枠より大きい向きは、枠が写真からはみ出さない範囲。小さい向きは、写真が枠からはみ出さない範囲
    function clampAxis(position, frameSize, imageSize) {
        var low = Math.min(0, frameSize - imageSize);
        var high = Math.max(0, frameSize - imageSize);
        return Math.min(high, Math.max(low, position));
    }
    function clamp() {
        var s = scale();
        state.x = clampAxis(state.x, state.frameW, state.width * s);
        state.y = clampAxis(state.y, state.frameH, state.height * s);
    }

    function render() {
        var s = scale();
        img.style.width = (state.width * s) + 'px';
        img.style.height = (state.height * s) + 'px';
        img.style.transform = 'translate(' + state.x + 'px, ' + state.y + 'px)';
    }

    // 枠の大きさを測り、写真を中央に置く。大きさは、縦の長さが枠の 1.3 倍になるところから（枠をちょうど覆う大きさまで小さくできる）
    function layout() {
        state.frameW = frame.clientWidth;
        state.frameH = frame.clientHeight;
        state.baseScale = Math.max(state.frameW / state.width, state.frameH / state.height);
        // いちばん小さいのは、写真全体が枠に収まる大きさの半分（小さくしたときも上下左右に動かせるように）
        state.minZoom = Math.min(state.frameW / state.width, state.frameH / state.height) * MIN_FIT_RATIO / state.baseScale;
        zoomInput.min = String(state.minZoom);
        var startScale = Math.max(state.baseScale, state.frameH * START_VERTICAL_ROOM / state.height);
        state.zoom = Math.min(MAX_ZOOM, startScale / state.baseScale);
        zoomInput.value = String(state.zoom);
        state.x = (state.frameW - state.width * scale()) / 2;
        state.y = (state.frameH - state.height * scale()) / 2;
        render();
    }

    // 調整の窓を開く（写真のメニューの窓を閉じてから）
    function openCrop(source, displayUrl, originalBlob) {
        state.source = source;
        state.width = source.naturalWidth || source.width;
        state.height = source.naturalHeight || source.height;
        state.displayUrl = displayUrl;
        state.originalBlob = originalBlob;
        frame.style.aspectRatio = String(frameRatio());
        img.src = displayUrl;
        backdrop.src = displayUrl;
        showError(cropError, '');
        saveBtn.disabled = false;
        saveBtn.textContent = 'この位置で決定';
        menuEl.addEventListener('hidden.bs.modal', function () {
            cropModal.show();
        }, { once: true });
        menuModal.hide();
    }

    cropEl.addEventListener('shown.bs.modal', layout);
    // 窓の大きさが変わったら（スマホの向きを変えたなど）、置き直す
    window.addEventListener('resize', function () {
        if (cropEl.classList.contains('show') && state.source) {
            layout();
        }
    });
    // 閉じたら片付ける（「やめる」なら何も変えない）
    cropEl.addEventListener('hidden.bs.modal', function () {
        if (state.displayUrl.indexOf('blob:') === 0) {
            URL.revokeObjectURL(state.displayUrl);
        }
        state.source = null;
        state.originalBlob = null;
        state.displayUrl = '';
        img.removeAttribute('src');
        backdrop.removeAttribute('src');
    });

    // ---- 写真を選ぶ ----
    chooseBtn.addEventListener('click', function () {
        showError(menuError, '');
        input.click();
    });
    input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        input.value = '';
        if (!file) {
            return;
        }
        var fileUrl = URL.createObjectURL(file);
        loadImage(fileUrl).then(function (image) {
            var canvas = shrink(image);
            return canvasToBlob(canvas, 0.9).then(function (blob) {
                openCrop(canvas, URL.createObjectURL(blob), blob);
            });
        }).catch(function () {
            showError(menuError, 'この写真は読み込めませんでした');
        }).then(function () {
            URL.revokeObjectURL(fileUrl);
        });
    });

    // ---- 位置を調整（保存してある元写真で開く） ----
    if (adjustBtn) {
        adjustBtn.addEventListener('click', function () {
            showError(menuError, '');
            var url = cropEl.getAttribute('data-original-url');
            loadImage(url + (url.indexOf('?') === -1 ? '?' : '&') + 't=' + Date.now()).then(function (image) {
                openCrop(image, image.src, null);
            }).catch(function () {
                showError(menuError, 'この写真は読み込めませんでした');
            });
        });
    }

    // ---- ドラッグで位置（マウスとタッチの両方。Pointer Events） ----
    var drag = null;
    frame.addEventListener('pointerdown', function (event) {
        if (!state.source) {
            return;
        }
        drag = { id: event.pointerId, startX: event.clientX, startY: event.clientY, x: state.x, y: state.y };
        frame.setPointerCapture(event.pointerId);
        frame.classList.add('is-dragging');
    });
    frame.addEventListener('pointermove', function (event) {
        if (!drag || event.pointerId !== drag.id) {
            return;
        }
        state.x = drag.x + (event.clientX - drag.startX);
        state.y = drag.y + (event.clientY - drag.startY);
        clamp();
        render();
    });
    function endDrag(event) {
        if (drag && event.pointerId === drag.id) {
            drag = null;
            frame.classList.remove('is-dragging');
        }
    }
    frame.addEventListener('pointerup', endDrag);
    frame.addEventListener('pointercancel', endDrag);

    // ---- バーで大きさ（枠の中心を基準に拡大する） ----
    zoomInput.addEventListener('input', function () {
        if (!state.source) {
            return;
        }
        var oldScale = scale();
        var centerX = (state.frameW / 2 - state.x) / oldScale; // 枠の中心にある、写真の上の点
        var centerY = (state.frameH / 2 - state.y) / oldScale;
        state.zoom = Math.min(MAX_ZOOM, Math.max(state.minZoom, parseFloat(zoomInput.value) || 1));
        state.x = state.frameW / 2 - centerX * scale();
        state.y = state.frameH / 2 - centerY * scale();
        clamp();
        render();
    });

    // ---- この位置で決定: 枠の中だけを切り抜いて送る ----
    saveBtn.addEventListener('click', function () {
        if (!state.source) {
            return;
        }
        var s = scale();
        // 出す絵の幅は MAX_OUTPUT（元の写真がそれより小さく、拡大もしていなければ、その幅）
        var outW = Math.max(1, Math.round(Math.min(MAX_OUTPUT, Math.max(state.frameW / s, state.width))));
        var outH = Math.max(1, Math.round(outW * state.frameH / state.frameW));
        var k = outW / state.frameW; // 枠の 1px が、出す絵の何 px か
        var canvas = document.createElement('canvas');
        canvas.width = outW;
        canvas.height = outH;
        var ctx = canvas.getContext('2d');
        // 隙間を埋める背景（窓と同じく、少し大きめに置いた写真をぼかして薄暗くする）
        if (typeof ctx.filter === 'string') {
            var coverScale = Math.max(outW / state.width, outH / state.height) * 1.2;
            var bw = state.width * coverScale;
            var bh = state.height * coverScale;
            ctx.filter = 'blur(' + Math.round(BACKDROP_BLUR * k) + 'px) brightness(' + BACKDROP_BRIGHTNESS + ')';
            ctx.drawImage(state.source, (outW - bw) / 2, (outH - bh) / 2, bw, bh);
            ctx.filter = 'none';
        } else {
            ctx.fillStyle = BACKDROP_FALLBACK;
            ctx.fillRect(0, 0, outW, outH);
        }
        // 写真（枠の外にはみ出した部分は絵の外なので切れる）
        ctx.drawImage(state.source, state.x * k, state.y * k, state.width * s * k, state.height * s * k);

        saveBtn.disabled = true;
        cancelBtn.disabled = true;
        saveBtn.textContent = '保存しています…';
        canvasToBlob(canvas, QUALITY).then(function (blob) {
            var data = new FormData();
            data.append('action', 'save_photo');
            data.append('event_id', cropEl.getAttribute('data-event-id'));
            data.append('photo', blob, 'photo.jpg');
            if (state.originalBlob) {
                data.append('original', state.originalBlob, 'original.jpg');
            }
            // 送ったあとのページの移動は自分で行う（裏で移動先を開くと、上に出す「〇〇の背景を設定しました」がそこで使われて消えるため）
            return fetch(cropEl.getAttribute('data-post-url'), { method: 'POST', body: data, credentials: 'same-origin', redirect: 'manual' });
        }).then(function () {
            // 保存の結果（「〇〇の背景を設定しました」など）は、ページを開き直したときに上に出る
            window.location.href = cropEl.getAttribute('data-return-url');
        }).catch(function () {
            saveBtn.disabled = false;
            cancelBtn.disabled = false;
            saveBtn.textContent = 'この位置で決定';
            showError(cropError, '保存できませんでした。電波の良いところでもう一度お試しください。');
        });
    });
})();
