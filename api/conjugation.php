<?php
// api/conjugation.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// ===== CONFIG =====
const CACHE_DIR   = __DIR__ . '/../cache';
const CACHE_TTL_S = 3 * 24 * 60 * 60; // 3 ngày
const TIMEOUT_S   = 8;

// ===== Helpers =====
function respond(int $code, array $payload) {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function sanitize_word(string $w): string {
    $w = trim($w);
    $w = preg_replace('/\s+/u', '-', $w);
    $w = preg_replace('/[^a-zA-ZäöüÄÖÜß\-]/u', '', $w);
    return mb_strtolower($w, 'UTF-8');
}
function ensure_cache_dir(): void {
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0775, true);
}
function cache_path(string $key): string {
    ensure_cache_dir();
    return CACHE_DIR . '/' . $key . '.json';
}
function get_cached(string $key): ?array {
    $path = cache_path($key);
    if (!is_file($path)) return null;
    if (time() - filemtime($path) > CACHE_TTL_S) return null;
    $raw = @file_get_contents($path);
    return $raw ? json_decode($raw, true) : null;
}
function set_cached(string $key, array $data): void {
    @file_put_contents(cache_path($key), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
function http_get(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => TIMEOUT_S,
        CURLOPT_TIMEOUT        => TIMEOUT_S,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Flashcard-Conjugation/1.0',
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, $body, $err];
}
function fetch_html(string $word): array {
    $urls = [
        "https://en.verbformen.com/conjugation/{$word}.htm",
        "https://www.verbformen.com/?w={$word}",
    ];
    foreach ($urls as $url) {
        [$code, $body, $err] = http_get($url);
        if ($code >= 200 && $code < 300 && $body && strlen($body) > 1000) {
            return ['url'=>$url,'html'=>$body];
        }
    }
    respond(502,['ok'=>false,'error'=>'fetch_failed']);
    return [];
}
function load_dom(string $html): DOMXPath {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html, LIBXML_NOERROR|LIBXML_NOWARNING|LIBXML_NONET);
    libxml_clear_errors();
    return new DOMXPath($dom);
}
function extract_simple_section(DOMXPath $xp): ?DOMNode {
    $nodes=$xp->query("//h2[contains(., 'simple conjugated verbs')]|//h3[contains(., 'simple conjugated verbs')]");
    if($nodes && $nodes->length>0){
        $h=$nodes->item(0);
        for($n=$h->nextSibling;$n;$n=$n->nextSibling){
            if($n instanceof DOMElement){
                if(in_array(strtolower($n->nodeName),['h2','h3'])) break;
                if(trim($n->textContent)!=='') return $n;
            }
        }
    }
    return null;
}

// ===== Parser bằng DOM <table>, lọc đúng 6 ngôi =====
function parse_simple_tables(string $html): array {
    $xp = load_dom($html);
    $section = extract_simple_section($xp);
    if (!$section) return [null, []];

    $tables = [
        'present'=>[], 'imperfect'=>[], 'imperative'=>[],
        'subjunctive_present'=>[], 'subjunctive_imperfect'=>[]
    ];
    $validPronouns = ['ich','du','er','wir','ihr','sie'];

    // lấy text thô của section
    $raw = inner_html($section);

    // thay <br> thành xuống dòng, bỏ tag
    $plain = preg_replace('#<\s*br\s*/?>#i', "\n", $raw);
    $plain = strip_tags($plain);
    $lines = preg_split('/\r?\n/u', $plain);
    $lines = array_values(array_filter(array_map('trim',$lines)));

    $current = null;
    foreach($lines as $line){
        $low = mb_strtolower($line,'UTF-8');

        // xác định tiêu đề block
        if (strpos($low,'präsens')!==false || strpos($low,'present')!==false) {$current='present';continue;}
        if (strpos($low,'präteritum')!==false || strpos($low,'imperfect')!==false) {$current='imperfect';continue;}
        if (strpos($low,'imperativ')!==false || strpos($low,'imperative')!==false) {$current='imperative';continue;}
        if (strpos($low,'konjunktiv i')!==false) {$current='subjunctive_present';continue;}
        if (strpos($low,'konjunktiv ii')!==false) {$current='subjunctive_imperfect';continue;}

        // nếu trong block và dòng bắt đầu bằng ngôi thì giữ lại
        if ($current) {
            if (preg_match('/^(ich|du|er|wir|ihr|sie)\s+(.+)$/ui', $line, $m)) {
                $pron = mb_strtolower($m[1],'UTF-8');
                $form = trim($m[2]);
                if (in_array($pron,$validPronouns)) {
                    $tables[$current][$pron] = $form;
                }
            }
        }
    }

    return [null,$tables];
}


// ===== MAIN =====
$word = isset($_GET['word']) ? sanitize_word($_GET['word']) : '';
if ($word==='') respond(400,['ok'=>false,'error'=>'missing_word']);

$key='cj_'.md5($word);
if($cached=get_cached($key)){ respond(200,$cached); }

$fetched=fetch_html($word);
[$simpleHtml,$tables]=parse_simple_tables($fetched['html']);

$data=[
    'ok'=>true,
    'word'=>$word,
    'source'=>$fetched['url'],
    'simple'=>$tables
];
set_cached($key,$data);
respond(200,$data);
