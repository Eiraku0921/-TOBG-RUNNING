<?php
// 試合告知ページは廃止した。大会の一覧と申し込みは、クラブ画面の「大会」タブ（front/club.php?tab=races）にある。
// 古いリンク・ブックマーク・通知から来た人は、そこへ送る
header('Location: club.php?tab=races', true, 301);
exit;
