<?php
// api/proxy.php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

const CACHE_DIR   = __DIR__ . '/../cache';
const CACHE_TTL_S = 3 * 24 * 60 * 60;
const TIMEOUT_S   = 8;

function sanitize_word(string $w): string {
  $w = trim($w);
  $w = preg_replace('/\s+/u', '-', $w);
  $w = preg_replace('/[^a-zA-ZäöüÄÖÜß\-]/u', '', $w);
  return mb_strtolower($w, 'UTF-8');
}
function ensure_cache_dir() { if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0775, true); }
function cache_path(string $key): string { ensure_cache_dir(); return CACHE_DIR.'/'.$key.'.html'; }
function http_get(string $url): array {
  $ch = curl_init($url);
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_FOLLOWLOCATION=>true,
    CURLOPT_CONNECTTIMEOUT=>TIMEOUT_S,
    CURLOPT_TIMEOUT=>TIMEOUT_S,
    CURLOPT_SSL_VERIFYPEER=>true,
    CURLOPT_ENCODING=>'',
    CURLOPT_USERAGENT=>'Flashcard-Conjugation/1.1',
    CURLOPT_HTTPHEADER=>['Accept-Language: en;q=0.9,de;q=0.8,*;q=0.7'],
  ]);
  $body = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  return [$code,$body];
}

$word = isset($_GET['word']) ? sanitize_word($_GET['word']) : '';
if ($word==='') { http_response_code(400); echo 'missing_word'; exit; }

$key = 'px_'.md5($word);
$cache = cache_path($key);
if (is_file($cache) && time() - filemtime($cache) < CACHE_TTL_S) {
  readfile($cache); exit;
}

$urls = [
  "https://en.verbformen.com/conjugation/{$word}.htm",
  "https://www.verbformen.com/?w={$word}",
];
foreach($urls as $u){
  [$code,$body]=http_get($u);
  if ($code>=200 && $code<300 && $body && strlen($body)>1000){
    @file_put_contents($cache, $body);
    echo $body; exit;
  }
}
http_response_code(502); echo 'fetch_failed';
