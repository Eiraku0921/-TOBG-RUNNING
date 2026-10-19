<?php
require_once __DIR__ . '/../back/session_bootstrap.php';
if (session_status() === PHP_SESSION_NONE) {
    tobg_start_session();
}

$is_logged_in = isset($_SESSION['user_id'], $_SESSION['user_name']);
$logged_in_name = $is_logged_in ? (string)$_SESSION['user_name'] : '';

$contact_flash = (string)($_SESSION['contact_flash'] ?? '');
$contact_error = (string)($_SESSION['contact_error'] ?? '');
unset($_SESSION['contact_flash'], $_SESSION['contact_error']);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>お問い合わせ | TOBG</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;700;800;900&family=Zen+Kaku+Gothic+New:wght@400;500;700;900&display=swap" rel="stylesheet">
    <link href="../css/theme.css?v=<?php echo (int)@filemtime(__DIR__ . '/../css/theme.css'); ?>" rel="stylesheet">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#F4EEE4">
    <meta name="apple-mobile-web-app-title" content="TOBG-RUNNING">
    <link rel="apple-touch-icon" href="../icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" href="../icons/favicon.png">
    <style>
        body { background: var(--color-page-bg); }
        .page-wrap { max-width: var(--content-max-width); margin: 0 auto; padding: 24px var(--page-gutter) 40px; }
        .form-card { border: 0; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08); }
        .section-title { font-size: 1.7rem; font-weight: 800; color: #0f172a; margin: 24px 0 18px; }
        .form-label { font-weight: 700; }
        .team-application-panel {
            border: 1px solid #dbe3ef;
            border-radius: 12px;
            background: #f8fafc;
            padding: 16px 18px;
            margin-bottom: 24px;
        }
        .team-application-title {
            font-size: 1.05rem;
            font-weight: 800;
            color: #1f2937;
            margin-bottom: 14px;
        }
    </style>
</head>
<body>
<?php
$shared_header = __DIR__ . '/../includes/site_header.php';
if (file_exists($shared_header)) {
    require_once $shared_header;
} else {
    echo '<div style="background:var(--color-header);color:#fff;padding:12px 16px;font-weight:700;">TEAM OFFICIAL</div>';
}
?>

<div class="page-wrap">
    <h1 class="section-title">お問い合わせ</h1>

    <?php if ($contact_flash !== ''): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($contact_flash, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($contact_error !== ''): ?>
        <div class="alert alert-warning"><?php echo htmlspecialchars($contact_error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="card form-card">
        <div class="card-body p-4 p-md-5">
            <form method="POST" action="../back/save_inquiry.php" id="contactForm">
                <div class="mb-3">
                    <label class="form-label" for="sender_name">お名前</label>
                    <input type="text" class="form-control" id="sender_name" name="sender_name" maxlength="100" value="<?php echo htmlspecialchars($logged_in_name, ENT_QUOTES, 'UTF-8'); ?>" placeholder="匿名で送信する場合は下のチェックをご利用ください">
                </div>
                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" id="is_anonymous" name="is_anonymous" value="1">
                    <label class="form-check-label" for="is_anonymous">匿名で送信する</label>
                </div>
                <div class="mb-4">
                    <label class="form-label" for="message">お問い合わせ内容</label>
                    <textarea class="form-control" id="message" name="message" rows="7" required placeholder="ご意見・ご要望・お問い合わせなどをご自由にお書きください"></textarea>
                </div>

                <div class="form-check mb-3">
                    <?php // クラブ画面の「入会について問い合わせる」から来たときは、最初からチェックしておく ?>
                    <input class="form-check-input" type="checkbox" id="wants_team_membership" name="wants_team_membership" value="1"<?php echo ($_GET['membership'] ?? '') === '1' ? ' checked' : ''; ?>>
                    <label class="form-check-label" for="wants_team_membership">チーム所属を希望する</label>
                </div>

                <div class="team-application-panel" id="teamApplicationPanel" hidden>
                    <div class="team-application-title">チーム所属 申し込み情報</div>

                    <div class="mb-3">
                        <label class="form-label" for="applicant_name">お名前</label>
                        <input type="text" class="form-control" id="applicant_name" name="applicant_name" maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="applicant_furigana">フリガナ</label>
                        <input type="text" class="form-control" id="applicant_furigana" name="applicant_furigana" maxlength="150" placeholder="例：ヤマダ タロウ">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">生年月日</label>
                        <div class="d-flex gap-2">
                            <select class="form-select" id="birth_year" name="birth_year" style="max-width:110px;">
                                <option value="">年</option>
                                <?php for ($birth_year_option = (int)date('Y'); $birth_year_option >= (int)date('Y') - 100; $birth_year_option--): ?>
                                    <option value="<?php echo $birth_year_option; ?>"><?php echo $birth_year_option; ?></option>
                                <?php endfor; ?>
                            </select>
                            <select class="form-select" id="birth_month" name="birth_month" style="max-width:90px;">
                                <option value="">月</option>
                                <?php for ($birth_month_option = 1; $birth_month_option <= 12; $birth_month_option++): ?>
                                    <option value="<?php echo $birth_month_option; ?>"><?php echo $birth_month_option; ?></option>
                                <?php endfor; ?>
                            </select>
                            <select class="form-select" id="birth_day" name="birth_day" style="max-width:90px;">
                                <option value="">日</option>
                                <?php for ($birth_day_option = 1; $birth_day_option <= 31; $birth_day_option++): ?>
                                    <option value="<?php echo $birth_day_option; ?>"><?php echo $birth_day_option; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="applicant_postal_code">郵便番号</label>
                        <div class="d-flex gap-2">
                            <input type="text" class="form-control" id="applicant_postal_code" name="applicant_postal_code" maxlength="8" placeholder="1234567" style="max-width:180px;">
                            <button type="button" class="btn btn-outline-secondary" id="postalLookupBtn"><?php // 狭い画面では「住所を／検索」の位置で折り返す（語の途中では切らない） ?><span class="d-inline-block">住所を</span><span class="d-inline-block">検索</span></button>
                        </div>
                        <div class="form-text" id="postalLookupStatus"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="applicant_prefecture">都道府県</label>
                        <input type="text" class="form-control" id="applicant_prefecture" name="applicant_prefecture" maxlength="50">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="applicant_address">市区町村・番地</label>
                        <input type="text" class="form-control" id="applicant_address" name="applicant_address" maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="applicant_address_detail">建物名など（任意）</label>
                        <input type="text" class="form-control" id="applicant_address_detail" name="applicant_address_detail" maxlength="255">
                    </div>

                    <div class="mb-0">
                        <label class="form-label" for="applicant_phone">電話番号</label>
                        <input type="tel" class="form-control" id="applicant_phone" name="applicant_phone" maxlength="20" placeholder="09012345678">
                    </div>
                </div>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary px-4 py-2 fw-bold">送信する</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    (function () {
        var anonymousCheckbox = document.getElementById('is_anonymous');
        var senderNameInput = document.getElementById('sender_name');
        if (!anonymousCheckbox || !senderNameInput) {
            return;
        }
        function syncState() {
            senderNameInput.disabled = anonymousCheckbox.checked;
        }
        anonymousCheckbox.addEventListener('change', syncState);
        syncState();
    })();

    (function () {
        var membershipCheckbox = document.getElementById('wants_team_membership');
        var panel = document.getElementById('teamApplicationPanel');
        var form = document.getElementById('contactForm');
        if (!membershipCheckbox || !panel || !form) {
            return;
        }

        var requiredFieldIds = ['applicant_name', 'applicant_furigana', 'applicant_postal_code', 'applicant_prefecture', 'applicant_address', 'applicant_phone'];

        function syncRequiredState() {
            var isOpen = membershipCheckbox.checked;
            panel.hidden = !isOpen;
            requiredFieldIds.forEach(function (fieldId) {
                var field = document.getElementById(fieldId);
                if (field) {
                    field.required = isOpen;
                }
            });
        }

        membershipCheckbox.addEventListener('change', syncRequiredState);
        syncRequiredState();

        var postalInput = document.getElementById('applicant_postal_code');
        var postalLookupBtn = document.getElementById('postalLookupBtn');
        var postalLookupStatus = document.getElementById('postalLookupStatus');
        var prefectureInput = document.getElementById('applicant_prefecture');
        var addressInput = document.getElementById('applicant_address');

        if (postalLookupBtn && postalInput) {
            postalLookupBtn.addEventListener('click', function () {
                var zipcode = postalInput.value.replace(/[^0-9]/g, '');
                if (zipcode.length !== 7) {
                    postalLookupStatus.textContent = '郵便番号は7桁の数字で入力してください。';
                    return;
                }
                postalLookupStatus.textContent = '検索中...';
                fetch('https://zipcloud.ibsnet.co.jp/api/search?zipcode=' + zipcode)
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (!data.results || data.results.length === 0) {
                            postalLookupStatus.textContent = '該当する住所が見つかりませんでした。手入力してください。';
                            return;
                        }
                        var result = data.results[0];
                        prefectureInput.value = result.address1 || '';
                        addressInput.value = (result.address2 || '') + (result.address3 || '');
                        postalLookupStatus.textContent = '住所を自動入力しました。';
                    })
                    .catch(function () {
                        postalLookupStatus.textContent = '住所の検索に失敗しました。手入力してください。';
                    });
            });
        }

        form.addEventListener('submit', function (event) {
            if (!membershipCheckbox.checked) {
                return;
            }
            var nameValue = document.getElementById('applicant_name').value.trim();
            var confirmMessage = 'チーム所属の申し込み内容で送信します。よろしいですか？\n\nお名前: ' + (nameValue || '未入力');
            if (!window.confirm(confirmMessage)) {
                event.preventDefault();
            }
        });
    })();
</script>
</body>
</html>
