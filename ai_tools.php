<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Khởi tạo lịch sử chat trong session
if (!isset($_SESSION['ai_chat'])) {
    $_SESSION['ai_chat'] = [];
}

// Tracking usage (lưu vào JSON file, không dùng DB)
function trackAIUsage($action, $model) {
    $trackFile = 'assets/config/ai_usage.json';
    $data = [];
    
    if (file_exists($trackFile)) {
        $data = json_decode(file_get_contents($trackFile), true) ?: [];
    }
    
    $today = date('Y-m-d');
    if (!isset($data[$today])) {
        $data[$today] = ['translate' => 0, 'vocabulary' => 0, 'conjugation' => 0, 'chat' => 0, 'models' => []];
    }
    
    $data[$today][$action] = ($data[$today][$action] ?? 0) + 1;
    $data[$today]['models'][$model] = ($data[$today]['models'][$model] ?? 0) + 1;
    
    // Giữ chỉ 30 ngày gần nhất
    $keys = array_keys($data);
    if (count($keys) > 30) {
        arsort($keys);
        $data = array_intersect_key($data, array_flip(array_slice($keys, 0, 30)));
    }
    
    file_put_contents($trackFile, json_encode($data, JSON_PRETTY_PRINT));
}

// Biến trạng thái mặc định
$error = null;
$result = null;
$activeTab = $_GET['tab'] ?? 'vocabulary';
// Model mặc định và danh sách cho phép
if (!isset($_SESSION['ai_model'])) {
    $_SESSION['ai_model'] = 'llama-3.1-8b-instant';
}
$ALLOWED_MODELS = [
    'gemma2-9b-it' => 'Gemma 2 9B IT',
    'llama-3.1-8b-instant' => 'Llama 3.1 8B Instant',
    'llama-3.3-70b-versatile' => 'Llama 3.3 70B Versatile',
    'moonshotai/kimi-k2-instruct' => 'Kimi K2'
];

// Hạn chế spam - chỉ cho phép 1 request mỗi 3 giây
if (!isset($_SESSION['last_api_call'])) {
    $_SESSION['last_api_call'] = 0;
}

$current_time = time();
$time_since_last_call = $current_time - $_SESSION['last_api_call'];

if ($time_since_last_call < 2) {
    $wait_time = 5 - $time_since_last_call;
    $error = "Vui lòng chờ {$wait_time} giây trước khi gửi tiếp để bảo vệ túi tiền của Duy Công 😭.";
    $activeTab = $_GET['tab'] ?? 'vocabulary';
} else {
    $_SESSION['last_api_call'] = $current_time;
}

// Groq API Class
class GroqAPI
{
    private $apiKey;
    private $baseUrl = 'https://api.groq.com/openai/v1/chat/completions';

    public function __construct($apiKey)
    {
        $this->apiKey = $apiKey;
    }

    // Gọi API với danh sách messages (chat theo lịch sử)
    public function callChat($messages, $model = 'llama-3.3-70b-versatile', $temperature = 0.7, $maxTokens = 1024)
    {
        $data = [
            'messages' => $messages,
            'model' => $model,
            'temperature' => $temperature,
            'max_completion_tokens' => $maxTokens,
            'top_p' => 1,
            'stream' => false,
            'stop' => null
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['error' => 'CURL Error: ' . $error];
        }

        if ($httpCode !== 200) {
            return ['error' => 'HTTP Error: ' . $httpCode . ' - ' . $response];
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => 'JSON Decode Error: ' . json_last_error_msg()];
        }

        return $decoded;
    }

    public function callAPI($message, $model = 'llama-3.3-70b-versatile', $temperature = 0.6, $maxTokens = 1024)
    {
        $data = [
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $message
                ]
            ],
            'model' => $model,
            'temperature' => $temperature,
            'max_completion_tokens' => $maxTokens,
            'top_p' => 1,
            'stream' => false,
            'stop' => null
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['error' => 'CURL Error: ' . $error];
        }

        if ($httpCode !== 200) {
            return ['error' => 'HTTP Error: ' . $httpCode . ' - ' . $response];
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => 'JSON Decode Error: ' . json_last_error_msg()];
        }

        return $decoded;
    }

    public function translateText($text, $fromLang = 'auto', $toLang = 'vi', $model = 'llama-3.1-8b-instant')
    {
        $langNames = [
            'vi' => 'Tiếng Việt',
            'en' => 'Tiếng Anh',
            'de' => 'Tiếng Đức',
            'fr' => 'Tiếng Pháp',
            'es' => 'Tiếng Tây Ban Nha',
            'it' => 'Tiếng Ý',
            'pt' => 'Tiếng Bồ Đào Nha',
            'ru' => 'Tiếng Nga',
            'ja' => 'Tiếng Nhật',
            'ko' => 'Tiếng Hàn',
            'zh' => 'Tiếng Trung (Giản thể)',
            'zh-TW' => 'Tiếng Trung (Phồn thể)',
            'ar' => 'Tiếng Ả Rập',
            'th' => 'Tiếng Thái',
            'id' => 'Tiếng Indonesia'
        ];

        $fromLangName = $langNames[$fromLang] ?? $fromLang;
        $toLangName = $langNames[$toLang] ?? $toLang;

        $prompt = "Dịch văn bản sau từ {$fromLangName} sang {$toLangName}:

\"{$text}\"

Chỉ trả về bản dịch, không giải thích hay thêm văn bản nào khác.";

        return $this->callAPI($prompt, $model);
    }


    public function lookupVocabulary($word, $model = 'llama-3.1-8b-instant')
    {
        $prompt = "Tra cứu từ vựng tiếng Đức '{$word}'. 
        Trả về JSON với các trường sau:
        {
        'word': '...',
        'meaning': '...',
        'type': '...',
        'gender': '...',
        'plural': '...',
        'examples': [
            {'de': '...', 'vi': '...'},
            ...
        ],
        'note': '...'
        }
        Chỉ trả về JSON hợp lệ, không giải thích thêm.";
        return $this->callAPI($prompt, $model);
    }

    public function conjugateVerb($verb, $model = 'llama-3.1-8b-instant')
    {
        $prompt = "Chia động từ tiếng Đức '{$verb}' ở tất cả các thì và dạng.
    Trả về JSON thuần tuý, không có ``` hoặc giải thích. Cấu trúc:
    {
    \"verb\": \"{$verb}\",
    \"conjugation\": {
        \"Präsens\": {
        \"ich\": \"...\",
        \"du\": \"...\",
        \"er/sie/es\": \"...\",
        \"wir\": \"...\",
        \"ihr\": \"...\",
        \"sie/Sie\": \"...\"
        },
        \"Präteritum\": { ... },
        \"Perfekt\": { ... },
        \"Plusquamperfekt\": { ... },
        \"Futur I\": { ... },
        \"Futur II\": { ... },
        \"Imperativ\": {
        \"du\": \"...\",
        \"ihr\": \"...\",
        \"Sie\": \"...\"
        },
        \"Konjunktiv I\": { ... },
        \"Konjunktiv II\": { ... }
    }
    }";
        return $this->callAPI($prompt, $model);
    }

    public function askGermanQuestion($question, $model = 'llama-3.1-8b-instant')
    {
        $prompt = "Bạn là giáo viên tiếng Đức chuyên nghiệp. Trả lời câu hỏi về tiếng Đức sau một cách chi tiết và dễ hiểu:\n\n{$question}";
        return $this->callAPI($prompt, $model);
    }
}

// Khởi tạo API
$groq = new GroqAPI($GROQ_API_KEY);

// AJAX endpoint: xử lý mà không render trang
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';
    $resp = [ 'ok' => false ];

    if (isset($error)) {
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }

    switch ($action) {
        case 'translate':
            $text = trim($_POST['text'] ?? '');
            $fromLang = $_POST['from_lang'] ?? 'auto';
            $toLang = $_POST['to_lang'] ?? 'vi';
            $model = $_SESSION['ai_model'] ?? 'llama-3.1-8b-instant';
            if ($text === '') {
                echo json_encode(['ok' => false, 'error' => 'Vui lòng nhập text cần dịch']);
                exit;
            }
            $response = $groq->translateText($text, $fromLang, $toLang, $model);
            if (isset($response['error'])) {
                echo json_encode(['ok' => false, 'error' => $response['error'], 'retryable' => true]);
                exit;
            }
            $translated = $response['choices'][0]['message']['content'] ?? '';
            trackAIUsage('translate', $model);
            echo json_encode([
                'ok' => true,
                'input' => $text,
                'output_html' => parseMarkdown(htmlspecialchars($translated))
            ]);
            exit;

        case 'chat':
            $question = trim($_POST['question'] ?? '');
            $model = $_SESSION['ai_model'] ?? 'llama-3.1-8b-instant';
            if ($question === '') {
                echo json_encode(['ok' => false, 'error' => 'Vui lòng nhập câu hỏi']);
                exit;
            }
            $_SESSION['ai_chat'][] = [
                'role' => 'user',
                'content' => $question,
                'time' => time(),
            ];
            $messages = [
                ['role' => 'system', 'content' => 'Bạn là giáo viên tiếng Đức chuyên nghiệp, trả lời ngắn gọn, rõ ràng, có ví dụ khi cần. Ngôn ngữ trả lời: tiếng Việt (có chèn ví dụ tiếng Đức).'],
            ];

            // Limit chat history to last 4 messages to save tokens (tăng lên 4 cho ngu context tốt hơn)
            $recentMessages = array_slice($_SESSION['ai_chat'], -4);
            foreach ($recentMessages as $m) {
                $messages[] = ['role' => $m['role'], 'content' => $m['content']];
            }
            $response = $groq->callChat($messages, $model);
            if (isset($response['error'])) {
                echo json_encode(['ok' => false, 'error' => $response['error'], 'retryable' => true]);
                exit;
            }
            $assistant = $response['choices'][0]['message']['content'] ?? '';
            $_SESSION['ai_chat'][] = [
                'role' => 'assistant',
                'content' => $assistant,
                'time' => time(),
            ];
            trackAIUsage('chat', $model);
            echo json_encode([
                'ok' => true,
                'user_html' => parseMarkdown(htmlspecialchars($question)),
                'assistant_html' => parseMarkdown(htmlspecialchars($assistant))
            ]);
            exit;

        case 'clear_chat':
            $_SESSION['ai_chat'] = [];
            echo json_encode(['ok' => true]);
            exit;

        case 'set_model':
            $model = $_POST['model'] ?? '';
            if (!array_key_exists($model, $ALLOWED_MODELS)) {
                echo json_encode(['ok' => false, 'error' => 'Model không hợp lệ']);
                exit;
            }
            $_SESSION['ai_model'] = $model;
            echo json_encode(['ok' => true]);
            exit;

        default:
            echo json_encode(['ok' => false, 'error' => 'Hành động không hợp lệ']);
            exit;
    }
}

// Xử lý form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($error)) {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'vocabulary':
            $word = trim($_POST['word'] ?? '');
            if (!empty($word)) {
                $model = $_SESSION['ai_model'] ?? 'llama-3.1-8b-instant';
                $response = $groq->lookupVocabulary($word, $model);
                if (isset($response['error'])) {
                    $error = $response['error'];
                } else {
                    $content = $response['choices'][0]['message']['content'] ?? '';
                    $content = trim($content);

                    // Xử lý khi AI trả về dạng code block
                    if (str_starts_with($content, '```')) {
                        $content = preg_replace('/^```[a-zA-Z0-9]*\n?/', '', $content);
                        $content = preg_replace('/```$/', '', $content);
                        $content = trim($content);
                    }

                    $data = json_decode($content, true);

                    if ($data) {
                        // Lưu vào session để dùng cho "Lưu vào sổ tay"
                        $_SESSION['last_vocab_lookup'] = $data;
                        
                        $html  = "<h4>Từ vựng: {$data['word']}</h4>";
                        $html .= "<ul>";
                        if (!empty($data['meaning'])) $html .= "<li><strong>Nghĩa:</strong> {$data['meaning']}</li>";
                        if (!empty($data['type'])) $html .= "<li><strong>Loại từ:</strong> {$data['type']}</li>";
                        if (!empty($data['gender'])) $html .= "<li><strong>Giống:</strong> {$data['gender']}</li>";
                        if (!empty($data['plural'])) $html .= "<li><strong>Số nhiều:</strong> {$data['plural']}</li>";
                        $html .= "</ul>";

                        if (!empty($data['examples'])) {
                            $html .= "<h5>Ví dụ:</h5><ul>";
                            foreach ($data['examples'] as $ex) {
                                $html .= "<li><em>{$ex['de']}</em><br><span class='text-muted'>{$ex['vi']}</span></li>";
                            }
                            $html .= "</ul>";
                        }

                        if (!empty($data['note'])) {
                            $html .= "<p><strong>Lưu ý:</strong> {$data['note']}</p>";
                        }

                        $result = $html;
                        trackAIUsage('vocabulary', $model);
                    } else {
                        // fallback nếu vẫn không parse được
                        $result = nl2br(htmlspecialchars($content));
                    }

                }
            } else {
                $error = 'Vui lòng nhập từ cần tra cứu';
            }
            $activeTab = 'vocabulary';
            break;

            case 'conjugation':
                $verb = trim($_POST['verb'] ?? '');
                if (!empty($verb)) {
                    $model = $_SESSION['ai_model'] ?? 'llama-3.1-8b-instant';
                    $response = $groq->conjugateVerb($verb, $model);
                    if (isset($response['error'])) {
                        $error = $response['error'];
                    } else {
                        trackAIUsage('conjugation', $model);
                        $content = $response['choices'][0]['message']['content'] ?? '';
                        $content = trim($content);
            
                        // Xử lý nếu AI trả về trong code block
                        if (str_starts_with($content, '```')) {
                            $content = preg_replace('/^```[a-zA-Z0-9]*\n?/', '', $content);
                            $content = preg_replace('/```$/', '', $content);
                            $content = trim($content);
                        }
            
                        $data = json_decode($content, true);
            
                        if ($data && !empty($data['conjugation'])) {
                            $conj = $data['conjugation'];
                            $html = "<h4>Chia động từ: {$data['verb']}</h4>";
                            $html .= "<div class='table-responsive'><table class='table table-bordered table-striped'>";
                            $html .= "<thead><tr><th>Ngôi</th>";
                            foreach ($conj as $tense => $forms) {
                                $html .= "<th>{$tense}</th>";
                            }
                            $html .= "</tr></thead><tbody>";
            
                            $pronouns = ["ich","du","er/sie/es","wir","ihr","sie/Sie"];
                            foreach ($pronouns as $p) {
                                $html .= "<tr><td><strong>{$p}</strong></td>";
                                foreach ($conj as $tense => $forms) {
                                    $val = $forms[$p] ?? "-";
                                    $html .= "<td>{$val}</td>";
                                }
                                $html .= "</tr>";
                            }
            
                            $html .= "</tbody></table></div>";
                            $result = $html;
                        } else {
                            $result = "<pre>" . htmlspecialchars($content) . "</pre>";
                        }
                    }
                } else {
                    $error = 'Vui lòng nhập động từ cần chia';
                }
                $activeTab = 'conjugation';
                break;            

        case 'translate':
            $text = trim($_POST['text'] ?? '');
            $fromLang = $_POST['from_lang'] ?? 'auto';
            $toLang = $_POST['to_lang'] ?? 'vi';

            if (!empty($text)) {
                $model = $_SESSION['ai_model'] ?? 'llama-3.1-8b-instant';
                $response = $groq->translateText($text, $fromLang, $toLang, $model);

                if (isset($response['error'])) {
                    $error = $response['error'];
                } else {
                    trackAIUsage('translate', $model);
                    $translated = trim($response['choices'][0]['message']['content'] ?? '');
                    $translateInput = htmlspecialchars($text);
                    $translateOutput = htmlspecialchars($translated);

                    // hiển thị như Google Dịch
                    $result = "
                        <div class='translation-box'>
                            <div class='source-text'><strong>Văn bản gốc:</strong><br>{$translateInput}</div>
                            <div class='target-text'><strong>Bản dịch ({$toLang}):</strong><br>{$translateOutput}</div>
                        </div>
                    ";
                }
            } else {
                $error = 'Vui lòng nhập text cần dịch';
            }
            $activeTab = 'translate';
            break;

        case 'chat':
            $question = trim($_POST['question'] ?? '');
            if (!empty($question)) {
                $model = $_SESSION['ai_model'] ?? 'llama-3.1-8b-instant';
                // Thêm user message vào lịch sử
                $_SESSION['ai_chat'][] = [
                    'role' => 'user',
                    'content' => $question,
                    'time' => time(),
                ];

                // Tạo system prompt nhẹ để AI đóng vai giáo viên tiếng Đức
                $messages = [
                    ['role' => 'system', 'content' => 'Bạn là giáo viên tiếng Đức chuyên nghiệp, trả lời ngắn gọn, rõ ràng, có ví dụ khi cần. Ngôn ngữ trả lời: tiếng Việt (có chèn ví dụ tiếng Đức).'],
                ];
                foreach ($_SESSION['ai_chat'] as $m) {
                    $messages[] = ['role' => $m['role'], 'content' => $m['content']];
                }

                $response = $groq->callChat($messages, $model);
                if (isset($response['error'])) {
                    $error = $response['error'];
                } else {
                    $assistant = $response['choices'][0]['message']['content'] ?? '';
                    $_SESSION['ai_chat'][] = [
                        'role' => 'assistant',
                        'content' => $assistant,
                        'time' => time(),
                    ];
                    trackAIUsage('chat', $model);
                }
            } else {
                $error = 'Vui lòng nhập câu hỏi';
            }
            $activeTab = 'chat';
            break;

        case 'clear_chat':
            $_SESSION['ai_chat'] = [];
            $activeTab = 'chat';
            break;
    }
}

// Function xử lý markdown
function parseMarkdown($text)
{
    // Headers
    $text = preg_replace('/^###### (.*)$/m', '<h6>$1</h6>', $text);
    $text = preg_replace('/^##### (.*)$/m', '<h5>$1</h5>', $text);
    $text = preg_replace('/^#### (.*)$/m', '<h4>$1</h4>', $text);
    $text = preg_replace('/^### (.*)$/m', '<h3>$1</h3>', $text);
    $text = preg_replace('/^## (.*)$/m', '<h2>$1</h2>', $text);
    $text = preg_replace('/^# (.*)$/m', '<h1>$1</h1>', $text);

    // Bold + Italic
    $text = preg_replace('/\*\*\*(.*?)\*\*\*/', '<strong><em>$1</em></strong>', $text);  // bold + italic
    $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $text);
    $text = preg_replace('/__(.*?)__/', '<strong>$1</strong>', $text);
    $text = preg_replace('/_(.*?)_/', '<em>$1</em>', $text);

    // Code blocks
    $text = preg_replace('/```(.*?)```/s', '<pre><code>$1</code></pre>', $text);
    // Inline code
    $text = preg_replace('/`(.*?)`/', '<code>$1</code>', $text);

    // Links
    $text = preg_replace('/\[(.*?)\]\((.*?)\)/', '<a href="$2">$1</a>', $text);

    // Images
    $text = preg_replace('/!\[(.*?)\]\((.*?)\)/', '<img src="$2" alt="$1">', $text);

    // Blockquote
    $text = preg_replace('/^> (.*)$/m', '<blockquote>$1</blockquote>', $text);

    // Horizontal rule
    $text = preg_replace('/^(\-\-\-|\*\*\*|___)$/m', '<hr>', $text);

    // Lists (unordered)
    $text = preg_replace('/^\- (.*)$/m', '<li>$1</li>', $text);
    $text = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $text);

    // Lists (ordered)
    $text = preg_replace('/^\d+\. (.*)$/m', '<li>$1</li>', $text);
    $text = preg_replace('/(<li>.*<\/li>)/s', '<ol>$1</ol>', $text);

    // Tables (markdown style)
    $text = preg_replace_callback('/((?:\|.*\|\n?)+)/', function ($matches) {
        $lines = explode("\n", trim($matches[1]));
        $html = '<table>';
        foreach ($lines as $i => $line) {
            if (trim($line) === '')
                continue;

            // Tách cột
            $cols = array_map('trim', explode('|', trim($line, '| ')));

            // Dòng 2 (---|---|---) là định dạng, bỏ qua
            if ($i === 1 && preg_match('/^-+$/', str_replace([' ', ':'], '', implode('', $cols)))) {
                continue;
            }

            if ($i === 0) {
                $html .= '<tr>';
                foreach ($cols as $col) {
                    $html .= '<th>' . $col . '</th>';
                }
                $html .= '</tr>';
            } else {
                $html .= '<tr>';
                foreach ($cols as $col) {
                    $html .= '<td>' . $col . '</td>';
                }
                $html .= '</tr>';
            }
        }
        $html .= '</table>';
        return $html;
    }, $text);

    // Line breaks
    $paragraphs = preg_split("/\n{2,}/", trim($text));
    $text = '';
    foreach ($paragraphs as $p) {
        $text .= '<p>' . nl2br(trim($p)) . '</p>';
    }

    return $text;
}

?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Tools - Germanly</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/ai_tools.css" rel="stylesheet">
</head>
<body>
    <?php
    $navbar_config = [
        'type' => 'minimal',
        'back_link' => 'dashboard.php',
        'show_brand' => true,
        'brand_link' => 'dashboard.php',
        'show_logout' => true
    ];
    include 'includes/navbar.php';
    ?>

    <div class="container">
<div class="tabs">
            <div class="model-picker">
                <select id="aiModel" class="form-select form-select-sm" style="width:auto; display:inline-block;">
                    <?php foreach ($ALLOWED_MODELS as $k => $label): ?>
                        <option value="<?= $k ?>" <?= ($_SESSION['ai_model'] ?? '') === $k ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <a href="?tab=vocabulary" class="tab <?= $activeTab === 'vocabulary' ? 'active' : '' ?>">
                <i class="bi bi-translate"></i> Tra từ vựng
            </a>
            <a href="?tab=conjugation" class="tab <?= $activeTab === 'conjugation' ? 'active' : '' ?>">
                <i class="bi bi-lightning"></i> Chia động từ
            </a>
            <a href="?tab=translate" class="tab <?= $activeTab === 'translate' ? 'active' : '' ?>">
                <i class="bi bi-translate"></i> Dịch
            </a>
            <a href="?tab=chat" class="tab <?= $activeTab === 'chat' ? 'active' : '' ?>">
                <i class="bi bi-mortarboard"></i> Hỏi đáp
            </a>
        </div>

        <!-- Tra từ vựng -->
        <div class="tab-content <?= $activeTab === 'vocabulary' ? 'active' : '' ?>">
            <div class="card">
                <h3><i class="bi bi-translate"></i> Tra từ vựng tiếng Đức</h3>
                <p class="text-muted">Tra cứu từ vựng tiếng Đức với thông tin chi tiết</p>
                
                <form method="POST">
                    <input type="hidden" name="action" value="vocabulary">
                    <div class="form-group">
                        <label class="form-label">Từ tiếng Đức</label>
                        <input type="text" name="word" class="form-control" 
                               placeholder="Nhập từ tiếng Đức cần tra cứu..." 
                               value="<?= htmlspecialchars($_POST['word'] ?? '') ?>" required>
                    </div>
                    <button type="submit" class="btn">
                        <i class="bi bi-search"></i> Tra cứu
                    </button>
                </form>

                <div class="examples">
                    <span class="example-tag" onclick="fillInput('word', 'Haus')">Haus</span>
                    <span class="example-tag" onclick="fillInput('word', 'Wasser')">Wasser</span>
                    <span class="example-tag" onclick="fillInput('word', 'Freund')">Freund</span>
                    <span class="example-tag" onclick="fillInput('word', 'Schule')">Schule</span>
                    <span class="example-tag" onclick="fillInput('word', 'Auto')">Auto</span>
                </div>

                <?php if ($error && $activeTab === 'vocabulary'): ?>
                    <div class="error">
                        <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                    </div>
                    <?php elseif ($result && $activeTab === 'vocabulary'): ?>
                        <div class="result"><?= $result ?></div>
                    <?php endif; ?>

            </div>
        </div>

        <!-- Chia động từ -->
        <div class="tab-content <?= $activeTab === 'conjugation' ? 'active' : '' ?>">
            <div class="card">
                <h3><i class="bi bi-lightning"></i> Chia động từ tiếng Đức</h3>
                <p class="text-muted">Chia động từ tiếng Đức ở tất cả các thì</p>
                
                <form method="POST">
                    <input type="hidden" name="action" value="conjugation">
                    <div class="form-group">
                        <label class="form-label">Động từ</label>
                        <input type="text" name="verb" class="form-control" 
                               placeholder="Nhập động từ tiếng Đức cần chia..." 
                               value="<?= htmlspecialchars($_POST['verb'] ?? '') ?>" required>
                    </div>
                    <button type="submit" class="btn">
                        <i class="bi bi-lightning"></i> Chia động từ
                    </button>
                </form>

                <div class="examples">
                    <span class="example-tag" onclick="fillInput('verb', 'sein')">sein</span>
                    <span class="example-tag" onclick="fillInput('verb', 'haben')">haben</span>
                    <span class="example-tag" onclick="fillInput('verb', 'werden')">werden</span>
                    <span class="example-tag" onclick="fillInput('verb', 'gehen')">gehen</span>
                    <span class="example-tag" onclick="fillInput('verb', 'kommen')">kommen</span>
                    <span class="example-tag" onclick="fillInput('verb', 'machen')">machen</span>
                </div>

                <?php if ($error && $activeTab === 'conjugation'): ?>
                    <div class="error">
                        <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                    </div>
                    <?php elseif ($result && $activeTab === 'conjugation'): ?>
                        <div class="result"><?= $result ?></div>
                    <?php endif; ?>
            </div>
        </div>

        <!-- Dịch -->
        <div class="tab-content <?= $activeTab === 'translate' ? 'active' : '' ?>">
            <div class="card">
                <h3><i class="bi bi-translate"></i> Dịch</h3>
                <p class="text-muted">Dịch văn bản giữa các ngôn ngữ với các tính năng tương tự Google Translate</p>

                <form method="POST" id="translateForm">
                    <input type="hidden" name="action" value="translate">
                    <input type="hidden" name="ajax" value="1">

                    <div class="language-selector">
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label">Ngôn ngữ nguồn</label>
                            <select name="from_lang" id="fromLang" class="form-select">
                                <option value="auto" <?= ($_POST['from_lang'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>Tự động phát hiện</option>
                                <option value="vi" <?= ($_POST['from_lang'] ?? '') === 'vi' ? 'selected' : '' ?>>Tiếng Việt</option>
                                <option value="de" <?= ($_POST['from_lang'] ?? '') === 'de' ? 'selected' : '' ?>>Tiếng Đức</option>
                                <option value="en" <?= ($_POST['from_lang'] ?? '') === 'en' ? 'selected' : '' ?>>Tiếng Anh</option>
                                <option value="fr" <?= ($_POST['from_lang'] ?? '') === 'fr' ? 'selected' : '' ?>>Tiếng Pháp</option>
                                <option value="es" <?= ($_POST['from_lang'] ?? '') === 'es' ? 'selected' : '' ?>>Tiếng Tây Ban Nha</option>
                                <option value="it" <?= ($_POST['from_lang'] ?? '') === 'it' ? 'selected' : '' ?>>Tiếng Ý</option>
                                <option value="pt" <?= ($_POST['from_lang'] ?? '') === 'pt' ? 'selected' : '' ?>>Tiếng Bồ Đào Nha</option>
                                <option value="ru" <?= ($_POST['from_lang'] ?? '') === 'ru' ? 'selected' : '' ?>>Tiếng Nga</option>
                                <option value="ja" <?= ($_POST['from_lang'] ?? '') === 'ja' ? 'selected' : '' ?>>Tiếng Nhật</option>
                                <option value="ko" <?= ($_POST['from_lang'] ?? '') === 'ko' ? 'selected' : '' ?>>Tiếng Hàn</option>
                                <option value="zh" <?= ($_POST['from_lang'] ?? '') === 'zh' ? 'selected' : '' ?>>Tiếng Trung (Giản thể)</option>
                                <option value="zh-TW" <?= ($_POST['from_lang'] ?? '') === 'zh-TW' ? 'selected' : '' ?>>Tiếng Trung (Phồn thể)</option>
                                <option value="ar" <?= ($_POST['from_lang'] ?? '') === 'ar' ? 'selected' : '' ?>>Tiếng Ả Rập</option>
                                <option value="th" <?= ($_POST['from_lang'] ?? '') === 'th' ? 'selected' : '' ?>>Tiếng Thái</option>
                                <option value="id" <?= ($_POST['from_lang'] ?? '') === 'id' ? 'selected' : '' ?>>Tiếng Indonesia</option>
                            </select>
                        </div>

                        <button type="button" class="swap-btn" onclick="swapLanguages()" title="Đổi ngôn ngữ">
                            <i class="bi bi-arrow-left-right"></i>
                        </button>

                        <div class="form-group" style="flex: 1;">
                            <label class="form-label">Ngôn ngữ đích</label>
                            <select name="to_lang" id="toLang" class="form-select">
                                <option value="vi" <?= ($_POST['to_lang'] ?? 'vi') === 'vi' ? 'selected' : '' ?>>Tiếng Việt</option>
                                <option value="de" <?= ($_POST['to_lang'] ?? '') === 'de' ? 'selected' : '' ?>>Tiếng Đức</option>
                                <option value="en" <?= ($_POST['to_lang'] ?? '') === 'en' ? 'selected' : '' ?>>Tiếng Anh</option>
                                <option value="fr" <?= ($_POST['to_lang'] ?? '') === 'fr' ? 'selected' : '' ?>>Tiếng Pháp</option>
                                <option value="es" <?= ($_POST['to_lang'] ?? '') === 'es' ? 'selected' : '' ?>>Tiếng Tây Ban Nha</option>
                                <option value="it" <?= ($_POST['to_lang'] ?? '') === 'it' ? 'selected' : '' ?>>Tiếng Ý</option>
                                <option value="pt" <?= ($_POST['to_lang'] ?? '') === 'pt' ? 'selected' : '' ?>>Tiếng Bồ Đào Nha</option>
                                <option value="ru" <?= ($_POST['to_lang'] ?? '') === 'ru' ? 'selected' : '' ?>>Tiếng Nga</option>
                                <option value="ja" <?= ($_POST['to_lang'] ?? '') === 'ja' ? 'selected' : '' ?>>Tiếng Nhật</option>
                                <option value="ko" <?= ($_POST['to_lang'] ?? '') === 'ko' ? 'selected' : '' ?>>Tiếng Hàn</option>
                                <option value="zh" <?= ($_POST['to_lang'] ?? '') === 'zh' ? 'selected' : '' ?>>Tiếng Trung (Giản thể)</option>
                                <option value="zh-TW" <?= ($_POST['to_lang'] ?? '') === 'zh-TW' ? 'selected' : '' ?>>Tiếng Trung (Phồn thể)</option>
                                <option value="ar" <?= ($_POST['to_lang'] ?? '') === 'ar' ? 'selected' : '' ?>>Tiếng Ả Rập</option>
                                <option value="th" <?= ($_POST['to_lang'] ?? '') === 'th' ? 'selected' : '' ?>>Tiếng Thái</option>
                                <option value="id" <?= ($_POST['to_lang'] ?? '') === 'id' ? 'selected' : '' ?>>Tiếng Indonesia</option>
                            </select>
                        </div>
                    </div>

                    <div class="translate-container">
                        <div class="translate-box">
                            <div class="translate-header">
                                <span class="detected-lang" id="detectedLang"></span>
                                <div class="translate-actions">
                                    <button type="button" class="action-btn" onclick="copyToClipboard('source')" title="Sao chép">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                </div>
                            </div>
                            <textarea name="text" id="sourceText" class="translate-textarea" placeholder="Nhập văn bản cần dịch..." onkeydown="handleKeyDown(event)"><?= htmlspecialchars($_POST['text'] ?? '') ?></textarea>
                            <div class="translate-footer">
                                <span class="char-count" id="sourceCharCount">0</span>
                            </div>
                        </div>

                        <div class="translate-box">
                            <div class="translate-header">
                                <span class="target-lang-label" id="targetLangLabel">Tiếng Việt</span>
                                <div class="translate-actions">
                                    <button type="button" class="action-btn" onclick="copyToClipboard('target')" title="Sao chép">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="translate-result" id="translateResult">
                                <?php if (isset($translateOutput)) { echo parseMarkdown(htmlspecialchars($translateOutput)); } ?>
                            </div>
                            <div class="translate-footer">
                                <span class="char-count" id="targetCharCount">0</span>
                            </div>
                        </div>
                    </div>

                    <div class="translate-controls">
                        <button type="button" class="btn" id="translateBtn" onclick="performTranslate()" title="Dịch văn bản">
                            <i class="bi bi-translate"></i> Dịch
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="clearTranslate()" title="Xóa tất cả">
                            <i class="bi bi-x-circle"></i> Xóa
                        </button>
                        <div class="keyboard-shortcuts">
                            <small class="text-muted">
                                <i class="bi bi-keyboard"></i> Nhấn Ctrl+Enter để dịch nhanh
                            </small>
                        </div>
                    </div>
                </form>

                <div class="examples">
                    <span class="example-tag" onclick="fillInput('text', 'Guten Tag! Wie geht es Ihnen?')">Guten Tag! Wie geht es Ihnen?</span>
                    <span class="example-tag" onclick="fillInput('text', 'Ich lerne Deutsch.')">Ich lerne Deutsch.</span>
                    <span class="example-tag" onclick="fillInput('text', 'Wo ist die Toilette?')">Wo ist die Toilette?</span>
                    <span class="example-tag" onclick="fillInput('text', 'Danke schön!')">Danke schön!</span>
                </div>

                <?php if ($error && $activeTab === 'translate'): ?>
                    <div class="error">
                        <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Chat hỏi đáp -->
        <div class="tab-content <?= $activeTab === 'chat' ? 'active' : '' ?>">
            <div class="chat-container">
                <div class="chat-header">
                    <div class="chat-title">
                        <i class="bi bi-robot"></i>
                        <span>AI Giáo viên tiếng Đức</span>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearChat()" title="Xóa cuộc trò chuyện">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>

                <div class="chat-messages" id="chatWindow">
                    <?php if (!empty($_SESSION['ai_chat'])): ?>
                        <?php foreach ($_SESSION['ai_chat'] as $m): ?>
                            <div class="message-wrapper <?= $m['role'] === 'user' ? 'user-message' : 'assistant-message' ?>">
                                <div class="message-avatar">
                                    <?php if ($m['role'] === 'user'): ?>
                                        <div class="user-avatar">
                                            <i class="bi bi-person"></i>
                                        </div>
                                    <?php else: ?>
                                        <div class="assistant-avatar">
                                            <i class="bi bi-robot"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="message-content">
                                    <div class="message-bubble">
                                        <?= parseMarkdown(htmlspecialchars($m['content'])) ?>
                                    </div>
                                    <?php if ($m['role'] === 'assistant'): ?>
                                        <div class="message-actions">
                                            <button type="button" class="action-btn-sm" onclick="copyMessage(this)" title="Sao ch\u00e9p">
                                                <i class="bi bi-clipboard"></i>
                                            </button>
                                            <button type="button" class="action-btn-sm" onclick="regenerateLastResponse()" title="T\u1ea1o l\u1ea1i">
                                                <i class="bi bi-arrow-clockwise"></i>
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="chat-empty">
                            <div class="empty-icon">
                                <i class="bi bi-chat-dots"></i>
                            </div>
                            <div class="empty-text">
                                <h4>Chào mừng bạn đến với AI Giáo viên!</h4>
                                <p>Hãy đặt câu hỏi về tiếng Đức để bắt đầu cuộc trò chuyện.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="chat-input-container">
                    <form method="POST" class="chat-form" id="chatForm">
                        <input type="hidden" name="action" value="chat">
                        <input type="hidden" name="ajax" value="1">
                        <div class="chat-input-wrapper">
                            <textarea name="question" class="chat-input" placeholder="Nhập câu hỏi của bạn..." required></textarea>
                            <button type="submit" class="chat-send-btn" title="Gửi">
                                <i class="bi bi-send"></i>
                            </button>
                        </div>
                    </form>

                    <div class="chat-examples">
                        <div class="examples-title">Câu hỏi mẫu:</div>
                        <div class="examples-grid">
                            <span class="example-tag" onclick="fillInput('question', 'Sự khác biệt giữa der, die, das là gì?')">Sự khác biệt giữa der, die, das là gì?</span>
                            <span class="example-tag" onclick="fillInput('question', 'Cách chia động từ sein trong quá khứ?')">Cách chia động từ sein trong quá khứ?</span>
                            <span class="example-tag" onclick="fillInput('question', 'Tại sao tiếng Đức có 4 cách?')">Tại sao tiếng Đức có 4 cách?</span>
                            <span class="example-tag" onclick="fillInput('question', 'Cách phát âm chữ ü trong tiếng Đức?')">Cách phát âm chữ ü trong tiếng Đức?</span>
                        </div>
                    </div>
                </div>

                <?php if ($error && $activeTab === 'chat'): ?>
                    <div class="error">
                        <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let autoTranslateTimeout;

        function fillInput(fieldName, value) {
            const input = document.querySelector(`input[name="${fieldName}"], textarea[name="${fieldName}"]`);
            if (input) {
                input.value = value;
                if (fieldName === 'text') {
                    updateCharCount('source');
                    autoTranslate();
                }
            }
        }

        function swapLanguages() {
            const fromSelect = document.getElementById('fromLang');
            const toSelect = document.getElementById('toLang');
            const tempValue = fromSelect.value;
            const tempText = fromSelect.options[fromSelect.selectedIndex].text;

            fromSelect.value = toSelect.value;
            toSelect.value = tempValue;

            updateTargetLangLabel();
            autoTranslate();
        }

        function updateCharCount(type) {
            const sourceText = document.getElementById('sourceText');
            const targetResult = document.getElementById('translateResult');
            const sourceCount = document.getElementById('sourceCharCount');
            const targetCount = document.getElementById('targetCharCount');

            if (type === 'source' || type === 'both') {
                const sourceLength = sourceText.value.length;
                sourceCount.textContent = sourceLength;
                sourceCount.className = sourceLength > 5000 ? 'char-count warning' : 'char-count';
            }

            if (type === 'target' || type === 'both') {
                const targetText = targetResult.textContent || '';
                const targetLength = targetText.length;
                targetCount.textContent = targetLength;
            }
        }

        function updateTargetLangLabel() {
            const toSelect = document.getElementById('toLang');
            const label = document.getElementById('targetLangLabel');
            const selectedOption = toSelect.options[toSelect.selectedIndex];
            label.textContent = selectedOption ? selectedOption.text : 'Tiếng Việt';
        }

        function copyToClipboard(type) {
            let text = '';
            if (type === 'source') {
                text = document.getElementById('sourceText').value;
            } else if (type === 'target') {
                text = document.getElementById('translateResult').textContent || '';
            }

            if (text.trim()) {
                navigator.clipboard.writeText(text).then(() => {
                    showNotification('Đã sao chép vào clipboard!');
                }).catch(() => {
                    // Fallback for older browsers
                    const textArea = document.createElement('textarea');
                    textArea.value = text;
                    document.body.appendChild(textArea);
                    textArea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textArea);
                    showNotification('Đã sao chép vào clipboard!');
                });
            }
        }





        function showNotification(message, type = 'success') {
            // Simple notification
            const notification = document.createElement('div');
            notification.className = 'notification';
            notification.textContent = message;
            
            const bgColor = type === 'error' ? 'var(--danger)' : 'var(--success)';
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${bgColor};
                color: white;
                padding: 10px 20px;
                border-radius: 8px;
                z-index: 1000;
                font-size: 14px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            `;
            document.body.appendChild(notification);

            setTimeout(() => {
                notification.remove();
            }, 3000);
        }

        function handleKeyDown(event) {
            if (event.ctrlKey && event.key === 'Enter') {
                event.preventDefault();
                performTranslate();
            }
        }

        function autoTranslate() {
            clearTimeout(autoTranslateTimeout);
            const sourceText = document.getElementById('sourceText');

            if (sourceText.value.trim().length > 0) {
                autoTranslateTimeout = setTimeout(() => {
                    performTranslate();
                }, 1000); // Delay 1 second after user stops typing
            } else {
                document.getElementById('translateResult').innerHTML = '';
                updateCharCount('both');
            }
        }

        function performTranslate() {
            const sourceText = document.getElementById('sourceText');
            const text = sourceText.value.trim();

            if (!text) return;

            const fromLang = document.getElementById('fromLang').value;
            const toLang = document.getElementById('toLang').value;

            // Show loading state
            const resultDiv = document.getElementById('translateResult');
            resultDiv.innerHTML = '<div class="loading">Đang dịch...</div>';

            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'translate',
                    text: text,
                    from_lang: fromLang,
                    to_lang: toLang,
                    ajax: '1'
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    resultDiv.innerHTML = data.output_html;
                    updateCharCount('target');

                    // Update detected language if auto-detect was used
                    if (fromLang === 'auto') {
                        const detectedLang = document.getElementById('detectedLang');
                        // For now, just show that language was detected
                        detectedLang.textContent = 'Ngôn ngữ được phát hiện';
                    }
                } else {
                    resultDiv.innerHTML = '<div class="error">Lỗi: ' + (data.error || 'Có lỗi xảy ra') + '</div>';
                }
            })
            .catch(error => {
                resultDiv.innerHTML = '<div class="error">Lỗi kết nối</div>';
                console.error('Translation error:', error);
            });
        }

        // Auto-focus on active tab
        document.addEventListener('DOMContentLoaded', function() {
            const activeTab = '<?= $activeTab ?>';
            const activeInput = document.querySelector(`.tab-content.${activeTab} input, .tab-content.${activeTab} textarea`);
            if (activeInput) {
                activeInput.focus();
            }

            // Initialize character count
            updateCharCount('both');
            updateTargetLangLabel();

            // Auto scroll chat xuống cuối
            const chatWindow = document.getElementById('chatWindow');
            if (chatWindow) {
                chatWindow.scrollTop = chatWindow.scrollHeight;
            }

            // Model selector AJAX
            const aiModel = document.getElementById('aiModel');
            if (aiModel) {
                aiModel.addEventListener('change', function() {
                    fetch('', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ action: 'set_model', model: aiModel.value, ajax: '1' })
                    });
                });
            }

            // Language selector change events
            const fromLang = document.getElementById('fromLang');
            const toLang = document.getElementById('toLang');

            if (fromLang) {
                fromLang.addEventListener('change', function() {
                    const detectedLang = document.getElementById('detectedLang');
                    detectedLang.textContent = this.value === 'auto' ? '' : '';
                });
            }

            if (toLang) {
                toLang.addEventListener('change', function() {
                    updateTargetLangLabel();
                });
            }
        });

        function clearTranslate() {
            const textarea = document.getElementById('sourceText');
            const result = document.getElementById('translateResult');
            const detectedLang = document.getElementById('detectedLang');

            if (textarea) textarea.value = '';
            if (result) result.innerHTML = '';
            if (detectedLang) detectedLang.textContent = '';

            updateCharCount('both');
        }

        function clearChat() {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'clear_chat', ajax: '1' })
            }).then(r => r.json()).then(data => {
                if (data.ok) {
                    const chatWindow = document.getElementById('chatWindow');
                    if (chatWindow) chatWindow.innerHTML = '<div class="chat-empty"><div class="empty-icon"><i class="bi bi-chat-dots"></i></div><div class="empty-text"><h4>Chào mừng bạn đến với AI Giáo viên!</h4><p>Hãy đặt câu hỏi về tiếng Đức để bắt đầu cuộc trò chuyện.</p></div></div>';
                }
            });
        }

        // AJAX submit Chat
        const chatForm = document.getElementById('chatForm');
        if (chatForm) {
            chatForm.addEventListener('submit', function (e) {
                e.preventDefault();
                const textarea = chatForm.querySelector('textarea[name="question"]');
                const text = (textarea?.value || '').trim();
                if (!text) return;

                // Remove empty state if it exists
                const emptyState = document.querySelector('.chat-empty');
                if (emptyState) {
                    emptyState.remove();
                }

                // append user message immediately
                const chatWindow = document.getElementById('chatWindow');
                if (chatWindow) {
                    const userMessage = document.createElement('div');
                    userMessage.className = 'message-wrapper user-message';
                    userMessage.innerHTML = `
                        <div class="message-avatar">
                            <div class="user-avatar">
                                <i class="bi bi-person"></i>
                            </div>
                        </div>
                        <div class="message-content">
                            <div class="message-bubble">${text}</div>
                        </div>
                    `;
                    chatWindow.appendChild(userMessage);
                    chatWindow.scrollTop = chatWindow.scrollHeight;
                }

                const formData = new FormData(chatForm);
                formData.set('ajax', '1');
                fetch('', { method: 'POST', body: new URLSearchParams(formData) })
                    .then(r => r.json())
                    .then(data => {
                        if (!data.ok) {
                            alert(data.error || 'Có lỗi xảy ra');
                            return;
                        }
                        const chatWindow2 = document.getElementById('chatWindow');
                        if (chatWindow2) {
                            const assistantMessage = document.createElement('div');
                            assistantMessage.className = 'message-wrapper assistant-message';
                            assistantMessage.innerHTML = `
                                <div class="message-avatar">
                                    <div class="assistant-avatar">
                                        <i class="bi bi-robot"></i>
                                    </div>
                                </div>
                                <div class="message-content">
                                    <div class="message-bubble">${data.assistant_html}</div>
                                    <div class="message-actions">
                                        <button type="button" class="action-btn-sm" onclick="copyMessage(this)" title="Sao chép">
                                            <i class="bi bi-clipboard"></i>
                                        </button>
                                        <button type="button" class="action-btn-sm" onclick="regenerateLastResponse()" title="Tạo lại">
                                            <i class="bi bi-arrow-clockwise"></i>
                                        </button>
                                    </div>
                                </div>
                            `;
                            chatWindow2.appendChild(assistantMessage);
                            chatWindow2.scrollTop = chatWindow2.scrollHeight;
                        }
                    });

                textarea.value = '';
            });
        }
        
        // Copy message in chat
        function copyMessage(button) {
            const messageContent = button.closest('.message-content').querySelector('.message-bubble').textContent;
            navigator.clipboard.writeText(messageContent).then(() => {
                showNotification('Đã sao chép tin nhắn!');
            });
        }
        
        // Regenerate last response
        function regenerateLastResponse() {
            const chatWindow = document.getElementById('chatWindow');
            const messages = chatWindow.querySelectorAll('.message-wrapper');
            
            if (messages.length < 2) return;
            
            // Xóa response cuối cùng
            const lastAssistant = chatWindow.querySelector('.message-wrapper.assistant-message:last-child');
            if (lastAssistant) {
                lastAssistant.remove();
            }
            
            // Lấy câu hỏi cuối
            const lastUserMessage = chatWindow.querySelector('.message-wrapper.user-message:last-child');
            if (!lastUserMessage) return;
            
            const questionText = lastUserMessage.querySelector('.message-bubble').textContent;
            
            // Gửi lại câu hỏi
            const formData = new URLSearchParams({
                action: 'chat',
                question: questionText,
                ajax: '1'
            });
            
            fetch('', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.ok) {
                        const assistantMessage = document.createElement('div');
                        assistantMessage.className = 'message-wrapper assistant-message';
                        assistantMessage.innerHTML = `
                            <div class="message-avatar">
                                <div class="assistant-avatar">
                                    <i class="bi bi-robot"></i>
                                </div>
                            </div>
                            <div class="message-content">
                                <div class="message-bubble">${data.assistant_html}</div>
                                <div class="message-actions">
                                    <button type="button" class="action-btn-sm" onclick="copyMessage(this)" title="Sao chép">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                    <button type="button" class="action-btn-sm" onclick="regenerateLastResponse()" title="Tạo lại">
                                        <i class="bi bi-arrow-clockwise"></i>
                                    </button>
                                </div>
                            </div>
                        `;
                        chatWindow.appendChild(assistantMessage);
                        chatWindow.scrollTop = chatWindow.scrollHeight;
                    } else {
                        showNotification('Lỗi: ' + (data.error || 'Không thể tạo lại'), 'error');
                    }
                });
        }
        
        // Enhanced error handling with retry
        function handleAPIError(error, retryCallback) {
            const errorDiv = document.createElement('div');
            errorDiv.className = 'alert alert-danger alert-dismissible fade show';
            errorDiv.innerHTML = `
                <i class="bi bi-exclamation-triangle"></i>
                <strong>Lỗi:</strong> ${error}
                <button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="(${retryCallback})()">
                    <i class="bi bi-arrow-clockwise"></i> Thử lại
                </button>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            // Insert at top of active tab content
            const activeTab = document.querySelector('.tab-content.active .card');
            if (activeTab) {
                activeTab.insertBefore(errorDiv, activeTab.firstChild);
            }
        }
        
        // Update performTranslate with retry
        const originalPerformTranslate = performTranslate;
        performTranslate = function() {
            const sourceText = document.getElementById('sourceText');
            const text = sourceText.value.trim();

            if (!text) return;

            const fromLang = document.getElementById('fromLang').value;
            const toLang = document.getElementById('toLang').value;

            const resultDiv = document.getElementById('translateResult');
            resultDiv.innerHTML = '<div class="loading"><div class="spinner-border spinner-border-sm me-2"></div>Đang dịch...</div>';

            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'translate',
                    text: text,
                    from_lang: fromLang,
                    to_lang: toLang,
                    ajax: '1'
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    resultDiv.innerHTML = data.output_html;
                    updateCharCount('target');

                    if (fromLang === 'auto') {
                        const detectedLang = document.getElementById('detectedLang');
                        detectedLang.textContent = 'Ngôn ngữ được phát hiện';
                    }
                } else {
                    const errorMsg = data.error || 'Có lỗi xảy ra';
                    if (data.retryable) {
                        resultDiv.innerHTML = `
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle"></i> ${errorMsg}
                                <button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="performTranslate()">
                                    <i class="bi bi-arrow-clockwise"></i> Thử lại
                                </button>
                            </div>
                        `;
                    } else {
                        resultDiv.innerHTML = '<div class="error">' + errorMsg + '</div>';
                    }
                }
            })
            .catch(error => {
                resultDiv.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle"></i> Lỗi kết nối
                        <button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="performTranslate()">
                            <i class="bi bi-arrow-clockwise"></i> Thử lại
                        </button>
                    </div>
                `;
                console.error('Translation error:', error);
            });
        };
    </script>
</body>
</html>

