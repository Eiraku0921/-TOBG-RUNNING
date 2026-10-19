<?php
require_once __DIR__ . '/../back/session_bootstrap.php';
if (session_status() === PHP_SESSION_NONE) {
    tobg_start_session();
}
require_once __DIR__ . '/../back/db_connect.php';
require_once __DIR__ . '/../back/records_lib.php';

// 歴代記録（管理者が登録した記録＋練習日記の大会記録の上位5人）。集計は back/records_lib.php（クラブ画面と共通）
$record_tables = fetch_record_tables($pdo);
$male_track = $record_tables['male_track'];
$male_field = $record_tables['male_field'];
$female_track = $record_tables['female_track'];
$female_field = $record_tables['female_field'];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>チーム歴代記録 | TOBG</title>
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
        body { background: var(--color-page-bg); color: var(--color-text-primary); }
        .page-wrap { max-width: var(--content-max-width); margin: 0 auto; padding: 10px var(--page-gutter) 32px; }

        /* 情報のまとまりごとの白いカード（間にページ背景の余白を挟む） */
        .rec-card {
            background: var(--color-card-bg);
            border-radius: var(--card-radius);
            padding: 20px 24px;
            margin-bottom: 10px;
        }

        /* 見出し: 帯は使わず、左にアクセントの丸だけ */
        .section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
            font-weight: 800;
            color: var(--color-text-primary);
        }
        .section-title::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--color-accent);
            flex-shrink: 0;
        }
        .page-title {
            font-size: 1.35rem;
        }
        .page-lead {
            margin: 4px 0 0 17px;
            color: var(--color-text-secondary);
            font-size: 0.85rem;
        }
        .category-title {
            font-size: 1.05rem;
            margin-bottom: 14px;
        }

        /* 種目: 枠で囲んだ折りたたみを2列に並べる。開いた種目は表が読めるよう1行全体に広げる */
        .event-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            align-items: start;
        }
        .event-accordion {
            border: 1px solid var(--color-border);
            border-radius: 10px;
            background: var(--color-card-bg);
        }
        .event-accordion[open] {
            grid-column: 1 / -1;
        }
        .event-accordion summary {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 18px;
            cursor: pointer;
            list-style: none;
        }
        .event-accordion summary::-webkit-details-marker {
            display: none;
        }
        .event-accordion summary:hover {
            background: var(--color-page-bg);
            border-radius: 10px;
        }
        .event-name {
            flex-shrink: 0;
            font-size: 1.05rem;
            font-weight: 800;
        }
        .top-note {
            flex: 1;
            min-width: 0;
            color: var(--color-text-secondary);
            font-size: 0.85rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .event-chevron {
            flex-shrink: 0;
            margin-left: auto;
            color: var(--color-text-muted);
            transition: transform 0.2s ease;
        }
        .event-accordion[open] .event-chevron {
            transform: rotate(180deg);
        }

        /* 開いたときの TOP5 */
        .accordion-content {
            padding: 0 18px 16px;
        }
        .table-wrapper {
            overflow-x: auto;
        }
        .records-table {
            width: 100%;
            min-width: 560px;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        .records-table th {
            padding: 8px 10px;
            border-bottom: 1px solid var(--color-border);
            color: var(--color-text-secondary);
            font-size: 0.78rem;
            font-weight: 700;
            text-align: left;
            white-space: nowrap;
        }
        .records-table td {
            padding: 10px;
            border-bottom: 1px solid var(--color-border);
            vertical-align: middle;
            word-break: break-word;
        }
        .records-table tbody tr:last-child td {
            border-bottom: 0;
        }
        .records-table .rank-cell {
            width: 64px;
            font-weight: 800;
            white-space: nowrap;
        }
        .records-table .record-cell {
            font-weight: 800;
            white-space: nowrap;
        }
        .records-table .date-cell {
            white-space: nowrap;
            color: var(--color-text-secondary);
        }
        /* 1位の行だけ薄いアクセント背景 */
        .records-table tr.is-first td {
            background: var(--color-accent-bg);
        }
        .records-table tr.is-first .rank-cell {
            color: var(--color-accent);
        }
        .empty-cell {
            color: var(--color-text-muted);
        }

        @media (max-width: 768px) {
            .event-grid {
                grid-template-columns: minmax(0, 1fr);
            }
        }
        @media (max-width: 576px) {
            .rec-card {
                padding: 16px 14px;
            }
            .page-title {
                font-size: 1.2rem;
            }
            .event-accordion summary {
                padding: 12px 14px;
            }
            .accordion-content {
                padding: 0 12px 12px;
            }
        }
    </style>
</head>
<body class="theme-v2">
<?php
$shared_header = __DIR__ . '/../includes/site_header.php';
if (file_exists($shared_header)) {
    require_once $shared_header;
} else {
    echo '<div style="background:var(--color-header);color:#fff;padding:12px 16px;font-weight:700;">TEAM OFFICIAL</div>';
}
?>

<div class="page-wrap">
    <section class="rec-card">
        <h1 class="section-title page-title">チーム歴代記録ランキング</h1>
        <p class="page-lead">種目名を押すと、同じ画面内で各種目TOP5が開きます。</p>
    </section>

    <?php
    $sections = [
        ['title' => '男子トラック種目', 'data' => $male_track],
        ['title' => '男子フィールド種目', 'data' => $male_field],
        ['title' => '女子トラック種目', 'data' => $female_track],
        ['title' => '女子フィールド種目', 'data' => $female_field],
    ];
    ?>

    <?php foreach ($sections as $section_index => $section): ?>
        <section class="rec-card" aria-labelledby="recordSection<?php echo $section_index; ?>">
            <h2 class="section-title category-title" id="recordSection<?php echo $section_index; ?>"><?php echo htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8'); ?></h2>

            <div class="event-grid">
                <?php foreach ($section['data'] as $event_name => $rows): ?>
                    <details class="event-accordion">
                        <summary>
                            <span class="event-name"><?php echo htmlspecialchars($event_name, ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="top-note">歴代1位:<?php echo htmlspecialchars((string)($rows[0]['record_time'] ?? 'なし'), ENT_QUOTES, 'UTF-8'); ?> - <?php echo htmlspecialchars((string)($rows[0]['name'] ?? 'なし'), ENT_QUOTES, 'UTF-8'); ?></span>
                            <svg class="event-chevron" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                        </summary>
                        <div class="accordion-content">
                            <div class="table-wrapper">
                                <table class="records-table">
                                    <thead>
                                        <tr>
                                            <th>順位</th>
                                            <th>氏名</th>
                                            <th>記録</th>
                                            <th>大会名</th>
                                            <th>日付</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($rows as $row): ?>
                                            <?php $is_empty = !empty($row['__empty']); ?>
                                            <?php $empty_class = $is_empty ? ' empty-cell' : ''; ?>
                                            <tr class="<?php echo !$is_empty && (int)$row['rank_num'] === 1 ? 'is-first' : ''; ?>">
                                                <td class="rank-cell<?php echo $empty_class; ?>"><?php echo htmlspecialchars((string)$row['rank_num'], ENT_QUOTES, 'UTF-8'); ?>位</td>
                                                <td class="<?php echo trim($empty_class); ?>"><?php echo htmlspecialchars((string)($row['name'] ?? 'なし'), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="record-cell<?php echo $empty_class; ?>"><?php echo htmlspecialchars((string)($row['record_time'] ?? 'なし'), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="<?php echo trim($empty_class); ?>"><?php echo htmlspecialchars((string)($row['match_name'] ?? 'なし'), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="date-cell<?php echo $empty_class; ?>"><?php echo !empty($row['match_date']) ? htmlspecialchars(date('Y/m/d', strtotime((string)$row['match_date'])), ENT_QUOTES, 'UTF-8') : 'なし'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
