<?php
// 「メンバー参加状況」は、クラブ画面の「大会」タブ（大会ごとの「申し込んでいる人」）にまとめた。
// 古いリンク・ブックマークから来た人は、そこへ送る
header('Location: club.php?tab=races', true, 301);
exit;
