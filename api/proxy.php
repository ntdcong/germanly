<?php
// api/proxy.php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

$word = $_GET['word'] ?? '';
if (!$word) {
    http_response_code(400);
    echo "Missing word";
    exit;
}

$url = "https://www.verbformen.com/?w=" . urlencode($word);

// Cache đơn giản
$cacheDir = __DIR__ . '/../cache';
if (!is_dir($cacheDir)) mkdir($cacheDir, 0777, true);
$cacheFile = "$cacheDir/vf_" . md5($word) . ".html";
if (file_exists($cacheFile) && time() - filemtime($cacheFile) < 3*24*3600) {
    echo file_get_contents($cacheFile);
    exit;
}

$html = @file_get_contents($url);
if (!$html) {
    http_response_code(502);
    echo "Cannot fetch source";
    exit;
}

file_put_contents($cacheFile, $html);
echo $html;
