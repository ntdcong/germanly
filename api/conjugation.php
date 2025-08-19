<?php
// api/conjugation.php — parse theo layout .vTbl giống popup.js
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
mb_internal_encoding('UTF-8');

$word = trim($_GET['word'] ?? '');
if ($word === '') {
    echo json_encode(['ok'=>false,'error'=>'Missing word'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit;
}

/* ---------------- HTTP ---------------- */
function http_get(string $url, int $timeout=15): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_MAXREDIRS=>5,
        CURLOPT_TIMEOUT=>$timeout, CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_ENCODING=>'',
        CURLOPT_HTTPHEADER=>[
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: de-DE,de;q=0.8,en-US;q=0.7,en;q=0.6',
            'Referer: https://www.verbformen.com/'
        ],
    ]);
    $body=curl_exec($ch); $err=curl_error($ch); $code=(int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, $body?:'', $err?:''];
}

/* ---------------- DOM helpers ---------------- */
function norm(?string $s): string {
    if ($s===null) return '';
    return trim(preg_replace('/\s+/u',' ',$s));
}
function clean_form(string $s): string {
    // Bỏ số mũ ⁵, v.v.
    $s = preg_replace('/[\p{No}]+/u','',$s);
    // Gộp nội dung ngoặc: ess(e) -> esse; aß(es)t -> aßest
    $s = preg_replace('/\(([^)]+)\)/u','$1',$s);
    // Bỏ ngoặc còn sót, dấu gạch đơn placeholder
    $s = trim(str_replace(['(',')'],'',$s));
    $s = trim($s,"- \t\n\r\0\x0B");
    return $s;
}
function dom_xpath(string $html): array {
    $dom=new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOWARNING|LIBXML_NOERROR);
    return [$dom, new DOMXPath($dom)];
}
function inner_html(DOMNode $n): string {
    $h=''; foreach($n->childNodes as $c){ $h.=$n->ownerDocument->saveHTML($c); } return $h;
}

/* ---------------- Parsers ---------------- */
function parse_person_table(DOMElement $table): array {
    $out=['ich'=>'','du'=>'','er'=>'','wir'=>'','ihr'=>'','sie'=>''];
    foreach ($table->getElementsByTagName('tr') as $tr) {
        $td=$tr->getElementsByTagName('td'); if($td->length<2) continue;
        $label=mb_strtolower(norm($td->item(0)->textContent ?? ''));
        $form = clean_form(norm($td->item(1)->textContent ?? ''));
        foreach (['ich','du','er','wir','ihr','sie'] as $p) {
            if (preg_match('/\b'.$p.'\b/u',$label)) { $out[$p]=$form; break; }
        }
    }
    return $out;
}

// Imperative: thường là "form" cột trái, "pronoun" cột phải (du/ihr/wir/Sie); có hàng chỉ "-" thì bỏ
function parse_imperative_table(DOMElement $table): array {
    $out=['ich'=>'','du'=>'','er'=>'','wir'=>'','ihr'=>'','sie'=>''];
    foreach ($table->getElementsByTagName('tr') as $tr) {
        $td=$tr->getElementsByTagName('td'); if($td->length===0) continue;
        $left = norm($td->item(0)->textContent ?? '');
        $right= $td->length>=2 ? norm($td->item(1)->textContent ?? '') : '';
        // pronoun ở bên phải nếu có; ngược lại cố lấy ở bên trái
        $pron = $right !== '' ? $right : $left;
        $form = $right !== '' ? clean_form($left) : '';
        if ($form === '' || $form === '-') continue;

        if (preg_match('/\bdu\b/i',$pron))  $out['du']  = $form;
        if (preg_match('/\bihr\b/i',$pron)) $out['ihr'] = $form;
        if (preg_match('/\bwir\b/i',$pron)) $out['wir'] = (stripos($form,' wir')!==false? $form : $form.' wir');
        if (preg_match('/\bSie\b/u',$pron) || preg_match('/\bsie\b/u',$pron)) {
            $out['sie'] = (stripos($form,' Sie')!==false? $form : $form.' Sie');
        }
    }
    return $out;
}

// Lấy đúng section giống popup.js: section có nhiều .vTbl nhất (tránh nhầm section deklination)
function find_verb_section(DOMXPath $xp): ?DOMElement {
    $nodes = $xp->query('//section[contains(@class,"rBox") and contains(@class,"rBoxWht")]');
    $best=null; $max=0;
    foreach ($nodes as $sec) {
        /** @var DOMElement $sec */
        $cnt = $sec->getElementsByTagName('div')->length; // thô
        $tbls= $sec->getElementsByTagName('table')->length;
        $vTbls=0; foreach ($sec->getElementsByTagName('div') as $d) {
            if (strpos(' '.($d->getAttribute('class')??'').' ',' vTbl ')!==false) $vTbls++;
        }
        // ưu tiên nhiều .vTbl
        $score = $vTbls*10 + $tbls - $cnt*0.01;
        if ($vTbls > 1 && $score > $max) { $max=$score; $best=$sec; }
    }
    return $best;
}

/* ---------------- MAIN ---------------- */
$meta = ['word'=>$word,'source'=>'','fetched_at'=>gmdate('c')];
$errors=[]; $html=''; $code=0;

// dùng .com ?w= như popup.js
$url = "https://www.verbformen.com/?w=".rawurlencode($word);
[$code,$html,$err]=http_get($url);
if ($code!==200 || $html==='') {
    echo json_encode(['ok'=>true,'meta'=>$meta,'simple'=>(object)[],'raw_section_html'=>null,'errors'=>["Fetch failed: HTTP $code @ $url".($err?" ($err)":"")]], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit;
}
$meta['source']=$url;
[$dom,$xp]=dom_xpath($html);

// tìm verb section như extension
$verbSec = find_verb_section($xp);

$simple = [
    'present'               => ['ich'=>'','du'=>'','er'=>'','wir'=>'','ihr'=>'','sie'=>''],
    'imperfect'             => ['ich'=>'','du'=>'','er'=>'','wir'=>'','ihr'=>'','sie'=>''],
    'subjunctive_present'   => ['ich'=>'','du'=>'','er'=>'','wir'=>'','ihr'=>'','sie'=>''],
    'subjunctive_imperfect' => ['ich'=>'','du'=>'','er'=>'','wir'=>'','ihr'=>'','sie'=>''],
    'imperative'            => ['ich'=>'','du'=>'','er'=>'','wir'=>'','ihr'=>'','sie'=>''],
];

$raw = null;

if ($verbSec) {
    // Build base absolute src for images (cho phía frontend nếu bạn muốn nhúng html)
    foreach ($verbSec->getElementsByTagName('img') as $img) {
        $src = $img->getAttribute('src');
        if ($src && strpos($src,'http')!==0) $img->setAttribute('src', 'https://www.verbformen.com'.$src);
        if (strpos($src,'/s.svg')!==false) { $img->parentNode->removeChild($img); } // bỏ icon loa
    }

    foreach ($verbSec->getElementsByTagName('div') as $box) {
        if (strpos(' '.($box->getAttribute('class')??'').' ',' vTbl ')===false) continue;
        $h2 = $box->getElementsByTagName('h2')->item(0);
        if (!$h2) continue;
        $title = mb_strtolower(norm($h2->textContent ?? ''));
        $table = $box->getElementsByTagName('table')->item(0);
        if (!$table) continue;

        if (preg_match('/\b(imperf\.\s*subj|imperfect\s*subj|konjunktiv\s*ii)\b/u', $title)) {
            $simple['subjunctive_imperfect'] = parse_person_table($table);
        } elseif (preg_match('/\b(present\s*subj|konjunktiv\s*i)\b/u', $title)) {
            $simple['subjunctive_present'] = parse_person_table($table);
        } elseif (preg_match('/\b(imperative|imperativ)\b/u', $title)) {
            $simple['imperative'] = parse_imperative_table($table);
        } elseif (preg_match('/^(?:imperfect)(?!\s*subj)\b|(?:präteritum|praeteritum)\b/u', $title)) {
            // Imperfect nhưng KHÔNG phải Imperf. Subj.
            $simple['imperfect'] = parse_person_table($table);
        } elseif (preg_match('/^(?:present)(?!\s*subj)\b|präsens\b/u', $title)) {
            // Present nhưng KHÔNG phải Present Subj.
            $simple['present'] = parse_person_table($table);
        }
    }
    $raw = inner_html($verbSec);
} else {
    $errors[] = 'Verb section not found';
}

// Nếu Imperativ trống mà đã có Present → synthesize tối thiểu (động từ yếu)
if (empty(array_filter($simple['imperative']))) {
    $ich = $simple['present']['ich'] ?? '';
    $wir = $simple['present']['wir'] ?? '';
    $ihr = $simple['present']['ihr'] ?? '';
    if ($ich !== '') {
        $stem = preg_replace('/e\b/u','',$ich); // mache->mach ; esse->ess
        $du   = $stem;
        if ($ihr === '') $ihr = $stem.'t';
        $sie  = ($wir !== '' ? $wir : $stem.'en') . ' Sie';
        $simple['imperative'] = ['ich'=>'','du'=>$du,'er'=>'','wir'=>($wir? $wir.' wir' : ''),'ihr'=>$ihr,'sie'=>$sie];
        $errors[] = 'Imperativ synthesized';
    }
}

echo json_encode([
    'ok'=>true,
    'meta'=>$meta,
    'simple'=>$simple,
    'raw_section_html'=>$raw,
    'errors'=>$errors,
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
