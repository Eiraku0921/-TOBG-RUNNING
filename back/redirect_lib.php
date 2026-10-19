<?php

// フォームの送り先の処理が終わったあと、元のページに戻すときの行き先。
// 同じサイトの中のパス（/ で始まる）だけを受け付け、// で始まるもの（別のサイトを指せる）や改行・\ を含むものは $default にする
function safe_return_location(string $raw, string $default): string
{
    $raw = trim($raw);
    $is_same_site_path = $raw !== ''
        && $raw[0] === '/'
        && !str_starts_with($raw, '//')
        && strpbrk($raw, "\\\r\n") === false;
    return $is_same_site_path ? $raw : $default;
}
