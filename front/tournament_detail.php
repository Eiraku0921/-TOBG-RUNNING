<?php
// 以前の「大会詳細」ページ。中身はクラブ画面の大会タブのカードと同じだったので、ページはやめて転送だけ残す。
// 送信済みのメールや、保存済みの通知に入っている古いリンク（front/tournament_detail.php?tournament_id=◯）のため。
// 大会がもう無い（終わって一覧から消えた）ときは、一覧の先頭が開くだけ
require_once __DIR__ . '/../back/tournament_list_lib.php';

$tournament_id = (int)($_GET['tournament_id'] ?? 0);
$location = $tournament_id > 0 ? tournament_page_path($tournament_id) : 'front/club.php?tab=races';

header('Location: ../' . $location, true, 301);
exit;
