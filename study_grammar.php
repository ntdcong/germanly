<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Học Ngữ Pháp - GERMANLY</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="assets/css/dashboard.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .main-wrapper {
            background: #f8f9fa;
            min-height: calc(100vh - 80px);
            margin-top: 0;
            border-radius: 0;
        }
        
        .section-card {
            border: none;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 1.5rem;
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .section-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
        }
        
        .section-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 1rem 1.25rem;
            border-radius: 12px 12px 0 0 !important;
            cursor: pointer;
            user-select: none;
            transition: all 0.2s;
        }
        
        .section-header:hover {
            background: linear-gradient(135deg, #764ba2, #667eea);
        }
        
        .section-content {
            padding: 1.25rem;
            display: none;
            background: white;
        }
        
        .sticky-sidebar {
            position: sticky;
            top: 20px;
        }
        
        .sticky-sidebar .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        
        .sticky-sidebar .card-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 1rem;
            border-radius: 12px 12px 0 0 !important;
            font-weight: 700;
        }
        
        .list-group-item {
            padding: 0.75rem 1rem;
            border: none;
            border-left: 3px solid transparent;
            transition: all 0.2s;
        }
        
        .list-group-item:hover {
            background: #f8f9fa;
            border-left-color: #667eea;
            padding-left: 1.25rem;
        }
        
        .list-group-item.active {
            background: #667eea;
            border-left-color: #667eea;
            color: white;
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table th {
            background: #f8f9fa;
            font-weight: 600;
            padding: 0.75rem;
        }
        
        .table td {
            padding: 0.75rem;
        }
        
        .example-box {
            background: #f8f9fa;
            border-left: 3px solid #667eea;
            padding: 0.875rem;
            margin: 0.625rem 0;
            border-radius: 0 0.4rem 0.4rem 0;
        }
        
        .highlight-text {
            color: #667eea;
            font-weight: 600;
        }
        
        @media (max-width: 1024px) {
            .sticky-sidebar {
                position: static;
                margin-bottom: 2rem;
            }
        }
        
        @media (max-width: 768px) {
            .section-header {
                font-size: 1rem;
                padding: 0.875rem 1rem;
            }
            
            .section-header h4 {
                font-size: 1.1rem;
            }
            
            .table-responsive {
                font-size: 0.85rem;
            }
            
            .example-box {
                padding: 0.75rem;
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <?php
    $navbar_config = [
        'type' => 'main',
        'show_logout' => true,
        'brand_link' => 'home.php'
    ];
    include 'includes/navbar.php';
    ?>

    <div class="main-wrapper">
    <div class="container py-4">
        <div class="row">
            <!-- Sidebar Navigation -->
            <div class="col-lg-3 mb-4">
                <div class="sticky-sidebar">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <i class="bi bi-list"></i> Mục lục
                        </div>
                        <div class="list-group list-group-flush">
                            <a href="#tenses" class="list-group-item list-group-item-action">
                                <i class="bi bi-clock"></i> Thì trong tiếng Đức
                            </a>
                            <a href="#modal-verbs" class="list-group-item list-group-item-action">
                                <i class="bi bi-gear"></i> Động từ khiếm khuyết
                            </a>
                            <a href="#conditionals" class="list-group-item list-group-item-action">
                                <i class="bi bi-question-diamond"></i> Câu điều kiện
                            </a>
                            <a href="#passive" class="list-group-item list-group-item-action">
                                <i class="bi bi-arrow-left-right"></i> Câu bị động
                            </a>
                            <a href="#word-order" class="list-group-item list-group-item-action">
                                <i class="bi bi-sort-alpha-down"></i> Trật tự từ
                            </a>
                            <a href="#conjunctions" class="list-group-item list-group-item-action">
                                <i class="bi bi-link-45deg"></i> Liên từ
                            </a>
                            <a href="#adjectives" class="list-group-item list-group-item-action">
                                <i class="bi bi-stars"></i> Tính từ & So sánh
                            </a>
                            <a href="#reflexive" class="list-group-item list-group-item-action">
                                <i class="bi bi-arrow-repeat"></i> Đại từ phản thân
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-lg-9">
                <div class="row">
                    <!-- THÌ TRONG TIẾNG ĐỨC -->
                    <div class="col-12 mb-4">
                        <div class="card section-card">
                            <div class="card-header section-header" onclick="toggleSection('tenses')">
                                <h4 class="mb-0">
                                    <i class="bi bi-clock"></i> Thì trong tiếng Đức
                                </h4>
                            </div>
                            <div id="tenses" class="section-content">
                                <h5>1. Präsens (Hiện tại)</h5>
                                <div class="example-box">
                                    <strong>Cách dùng:</strong> Diễn tả hành động hiện tại, sự thật, thói quen
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead class="table-primary">
                                            <tr>
                                                <th>Chủ ngữ</th>
                                                <th>gehen (đi)</th>
                                                <th>arbeiten (làm việc)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr><td>ich</td><td>gehe</td><td>arbeite</td></tr>
                                            <tr><td>du</td><td>gehst</td><td>arbeitest</td></tr>
                                            <tr><td>er/sie/es</td><td>geht</td><td>arbeitet</td></tr>
                                            <tr><td>wir</td><td>gehen</td><td>arbeiten</td></tr>
                                            <tr><td>ihr</td><td>geht</td><td>arbeitet</td></tr>
                                            <tr><td>sie/Sie</td><td>gehen</td><td>arbeiten</td></tr>
                                        </tbody>
                                    </table>
                                </div>

                                <h5 class="mt-4">2. Perfekt (Quá khứ hoàn thành)</h5>
                                <div class="example-box">
                                    <strong>Cấu trúc:</strong> haben/sein + Partizip II<br>
                                    <strong>Cách dùng:</strong> Diễn tả hành động đã hoàn thành trong quá khứ (phổ biến nhất trong giao tiếp)
                                </div>
                                <div class="example-box">
                                    <p><strong>Ví dụ với haben:</strong></p>
                                    <p>Ich <span class="highlight-text">habe</span> ein Buch <span class="highlight-text">gelesen</span>. (Tôi đã đọc một quyển sách)</p>
                                    <p><strong>Ví dụ với sein:</strong></p>
                                    <p>Ich <span class="highlight-text">bin</span> nach Berlin <span class="highlight-text">gefahren</span>. (Tôi đã đi đến Berlin)</p>
                                </div>

                                <h5 class="mt-4">3. Präteritum (Quá khứ đơn)</h5>
                                <div class="example-box">
                                    <strong>Cách dùng:</strong> Dùng trong văn viết, truyện kể, tin tức
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead class="table-success">
                                            <tr>
                                                <th>Chủ ngữ</th>
                                                <th>sein (là/ở)</th>
                                                <th>haben (có)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr><td>ich</td><td>war</td><td>hatte</td></tr>
                                            <tr><td>du</td><td>warst</td><td>hattest</td></tr>
                                            <tr><td>er/sie/es</td><td>war</td><td>hatte</td></tr>
                                            <tr><td>wir</td><td>waren</td><td>hatten</td></tr>
                                            <tr><td>ihr</td><td>wart</td><td>hattet</td></tr>
                                            <tr><td>sie/Sie</td><td>waren</td><td>hatten</td></tr>
                                        </tbody>
                                    </table>
                                </div>

                                <h5 class="mt-4">4. Futur I (Tương lai)</h5>
                                <div class="example-box">
                                    <strong>Cấu trúc:</strong> werden + động từ nguyên mẫu<br>
                                    <strong>Ví dụ:</strong> Ich <span class="highlight-text">werde</span> morgen nach Hause <span class="highlight-text">gehen</span>. (Tôi sẽ về nhà vào ngày mai)
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ĐỘNG TỪ KHIẾM KHUYẾT -->
                    <div class="col-12 mb-4">
                        <div class="card section-card">
                            <div class="card-header section-header" onclick="toggleSection('modal-verbs')">
                                <h4 class="mb-0">
                                    <i class="bi bi-gear"></i> Động từ khiếm khuyết (Modalverben)
                                </h4>
                            </div>
                            <div id="modal-verbs" class="section-content">
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead class="table-primary">
                                            <tr>
                                                <th>Động từ</th>
                                                <th>Nghĩa</th>
                                                <th>Ví dụ</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td><strong>können</strong></td>
                                                <td>có thể, biết</td>
                                                <td>Ich <span class="highlight-text">kann</span> Deutsch sprechen. (Tôi có thể nói tiếng Đức)</td>
                                            </tr>
                                            <tr>
                                                <td><strong>müssen</strong></td>
                                                <td>phải</td>
                                                <td>Ich <span class="highlight-text">muss</span> arbeiten. (Tôi phải làm việc)</td>
                                            </tr>
                                            <tr>
                                                <td><strong>wollen</strong></td>
                                                <td>muốn</td>
                                                <td>Ich <span class="highlight-text">will</span> nach Hause gehen. (Tôi muốn về nhà)</td>
                                            </tr>
                                            <tr>
                                                <td><strong>sollen</strong></td>
                                                <td>nên (theo lời khuyên)</td>
                                                <td>Du <span class="highlight-text">sollst</span> mehr lernen. (Bạn nên học nhiều hơn)</td>
                                            </tr>
                                            <tr>
                                                <td><strong>dürfen</strong></td>
                                                <td>được phép</td>
                                                <td>Ich <span class="highlight-text">darf</span> hier rauchen. (Tôi được phép hút thuốc ở đây)</td>
                                            </tr>
                                            <tr>
                                                <td><strong>mögen</strong></td>
                                                <td>thích</td>
                                                <td>Ich <span class="highlight-text">mag</span> Pizza. (Tôi thích pizza)</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <h5 class="mt-4">Chia động từ können (Präsens)</h5>
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead class="table-success">
                                            <tr><th>Chủ ngữ</th><th>können</th></tr>
                                        </thead>
                                        <tbody>
                                            <tr><td>ich</td><td>kann</td></tr>
                                            <tr><td>du</td><td>kannst</td></tr>
                                            <tr><td>er/sie/es</td><td>kann</td></tr>
                                            <tr><td>wir</td><td>können</td></tr>
                                            <tr><td>ihr</td><td>könnt</td></tr>
                                            <tr><td>sie/Sie</td><td>können</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- CÂU ĐIỀU KIỆN -->
                    <div class="col-12 mb-4">
                        <div class="card section-card">
                            <div class="card-header section-header" onclick="toggleSection('conditionals')">
                                <h4 class="mb-0">
                                    <i class="bi bi-question-diamond"></i> Câu điều kiện (Konditionalsätze)
                                </h4>
                            </div>
                            <div id="conditionals" class="section-content">
                                <h5>1. Loại 1 - Có thể xảy ra (Real)</h5>
                                <div class="example-box">
                                    <strong>Cấu trúc:</strong> Wenn + Präsens, ... Präsens/Futur<br>
                                    <strong>Ví dụ:</strong> <span class="highlight-text">Wenn</span> es regnet, bleibe ich zu Hause.<br>
                                    (Nếu trời mưa, tôi sẽ ở nhà)
                                </div>

                                <h5 class="mt-4">2. Loại 2 - Không có thật ở hiện tại (Irreal)</h5>
                                <div class="example-box">
                                    <strong>Cấu trúc:</strong> Wenn + Konjunktiv II, ... würde + động từ<br>
                                    <strong>Ví dụ:</strong> <span class="highlight-text">Wenn</span> ich reich <span class="highlight-text">wäre</span>, <span class="highlight-text">würde</span> ich ein Haus <span class="highlight-text">kaufen</span>.<br>
                                    (Nếu tôi giàu, tôi sẽ mua một căn nhà)
                                </div>

                                <h5 class="mt-4">3. Loại 3 - Không có thật trong quá khứ</h5>
                                <div class="example-box">
                                    <strong>Cấu trúc:</strong> Wenn + Plusquamperfekt, ... hätte/wäre + Partizip II<br>
                                    <strong>Ví dụ:</strong> <span class="highlight-text">Wenn</span> ich Zeit <span class="highlight-text">gehabt hätte</span>, <span class="highlight-text">wäre</span> ich gekommen.<br>
                                    (Nếu tôi có thời gian, tôi đã đến rồi)
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- CÂU BỊ ĐỘNG -->
                    <div class="col-12 mb-4">
                        <div class="card section-card">
                            <div class="card-header section-header" onclick="toggleSection('passive')">
                                <h4 class="mb-0">
                                    <i class="bi bi-arrow-left-right"></i> Câu bị động (Passiv)
                                </h4>
                            </div>
                            <div id="passive" class="section-content">
                                <h5>Vorgangspassiv (Bị động chỉ hành động)</h5>
                                <div class="example-box">
                                    <strong>Cấu trúc:</strong> werden + Partizip II<br>
                                    <p class="mt-2"><strong>Chủ động:</strong> Der Mann baut das Haus. (Người đàn ông xây ngôi nhà)</p>
                                    <p><strong>Bị động:</strong> Das Haus <span class="highlight-text">wird</span> vom Mann <span class="highlight-text">gebaut</span>. (Ngôi nhà được xây bởi người đàn ông)</p>
                                </div>

                                <h5 class="mt-4">Zustandspassiv (Bị động chỉ trạng thái)</h5>
                                <div class="example-box">
                                    <strong>Cấu trúc:</strong> sein + Partizip II<br>
                                    <strong>Ví dụ:</strong> Das Haus <span class="highlight-text">ist</span> schon <span class="highlight-text">gebaut</span>. (Ngôi nhà đã được xây rồi)
                                </div>

                                <div class="table-responsive mt-4">
                                    <table class="table table-bordered">
                                        <thead class="table-primary">
                                            <tr><th>Thì</th><th>Vorgangspassiv</th></tr>
                                        </thead>
                                        <tbody>
                                            <tr><td>Präsens</td><td>wird gebaut</td></tr>
                                            <tr><td>Präteritum</td><td>wurde gebaut</td></tr>
                                            <tr><td>Perfekt</td><td>ist gebaut worden</td></tr>
                                            <tr><td>Futur I</td><td>wird gebaut werden</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TRẬT TỰ TỪ -->
                    <div class="col-12 mb-4">
                        <div class="card section-card">
                            <div class="card-header section-header" onclick="toggleSection('word-order')">
                                <h4 class="mb-0">
                                    <i class="bi bi-sort-alpha-down"></i> Trật tự từ (Wortstellung)
                                </h4>
                            </div>
                            <div id="word-order" class="section-content">
                                <h5>1. Câu chính (Hauptsatz)</h5>
                                <div class="example-box">
                                    <strong>Vị trí động từ:</strong> Vị trí 2<br>
                                    <p class="mt-2"><strong>Ví dụ:</strong></p>
                                    <p>[1] Ich [2] <span class="highlight-text">gehe</span> [3] heute [4] ins Kino.</p>
                                    <p>[1] Heute [2] <span class="highlight-text">gehe</span> [3] ich [4] ins Kino.</p>
                                </div>

                                <h5 class="mt-4">2. Câu phụ (Nebensatz)</h5>
                                <div class="example-box">
                                    <strong>Vị trí động từ:</strong> Cuối câu<br>
                                    <strong>Ví dụ:</strong> Ich weiß, <span class="highlight-text">dass</span> er heute ins Kino <span class="highlight-text">geht</span>.<br>
                                    (Tôi biết rằng hôm nay anh ấy đi xem phim)
                                </div>

                                <h5 class="mt-4">3. Quy tắc TeKaMoLo</h5>
                                <div class="example-box">
                                    <strong>Trật tự trạng từ:</strong><br>
                                    <strong>Te</strong>mporal (thời gian) - <strong>Ka</strong>usal (nguyên nhân) - <strong>Mo</strong>dal (cách thức) - <strong>Lo</strong>kal (nơi chốn)<br>
                                    <p class="mt-2"><strong>Ví dụ:</strong></p>
                                    <p>Ich gehe <span class="highlight-text">heute</span> (Te) <span class="highlight-text">wegen des Wetters</span> (Ka) <span class="highlight-text">schnell</span> (Mo) <span class="highlight-text">nach Hause</span> (Lo).</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- LIÊN TỪ -->
                    <div class="col-12 mb-4">
                        <div class="card section-card">
                            <div class="card-header section-header" onclick="toggleSection('conjunctions')">
                                <h4 class="mb-0">
                                    <i class="bi bi-link-45deg"></i> Liên từ (Konjunktionen)
                                </h4>
                            </div>
                            <div id="conjunctions" class="section-content">
                                <h5>1. Liên từ đẳng lập (Không đổi vị trí động từ)</h5>
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead class="table-primary">
                                            <tr><th>Liên từ</th><th>Nghĩa</th><th>Ví dụ</th></tr>
                                        </thead>
                                        <tbody>
                                            <tr><td><strong>und</strong></td><td>và</td><td>Ich lerne Deutsch <span class="highlight-text">und</span> er lernt Englisch.</td></tr>
                                            <tr><td><strong>aber</strong></td><td>nhưng</td><td>Ich bin müde, <span class="highlight-text">aber</span> ich muss arbeiten.</td></tr>
                                            <tr><td><strong>oder</strong></td><td>hoặc</td><td>Gehst du <span class="highlight-text">oder</span> bleibst du?</td></tr>
                                            <tr><td><strong>denn</strong></td><td>vì</td><td>Ich bleibe zu Hause, <span class="highlight-text">denn</span> es regnet.</td></tr>
                                            <tr><td><strong>sondern</strong></td><td>mà (phủ định)</td><td>Nicht du, <span class="highlight-text">sondern</span> ich gehe.</td></tr>
                                        </tbody>
                                    </table>
                                </div>

                                <h5 class="mt-4">2. Liên từ phụ thuộc (Đưa động từ xuống cuối)</h5>
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead class="table-success">
                                            <tr><th>Liên từ</th><th>Nghĩa</th><th>Ví dụ</th></tr>
                                        </thead>
                                        <tbody>
                                            <tr><td><strong>weil</strong></td><td>vì, bởi vì</td><td>Ich bleibe zu Hause, <span class="highlight-text">weil</span> es regnet.</td></tr>
                                            <tr><td><strong>dass</strong></td><td>rằng</td><td>Ich weiß, <span class="highlight-text">dass</span> du recht hast.</td></tr>
                                            <tr><td><strong>wenn</strong></td><td>khi, nếu</td><td>Ich komme, <span class="highlight-text">wenn</span> ich Zeit habe.</td></tr>
                                            <tr><td><strong>ob</strong></td><td>liệu có... không</td><td>Ich frage, <span class="highlight-text">ob</span> er kommt.</td></tr>
                                            <tr><td><strong>obwohl</strong></td><td>mặc dù</td><td>Ich gehe, <span class="highlight-text">obwohl</span> es regnet.</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TÍNH TỪ & SO SÁNH -->
                    <div class="col-12 mb-4">
                        <div class="card section-card">
                            <div class="card-header section-header" onclick="toggleSection('adjectives')">
                                <h4 class="mb-0">
                                    <i class="bi bi-stars"></i> Tính từ & So sánh
                                </h4>
                            </div>
                            <div id="adjectives" class="section-content">
                                <h5>1. So sánh (Komparation)</h5>
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead class="table-primary">
                                            <tr><th>Cấp độ</th><th>Quy tắc</th><th>Ví dụ</th></tr>
                                        </thead>
                                        <tbody>
                                            <tr><td><strong>Positiv</strong> (Nguyên mẫu)</td><td>-</td><td>schön (đẹp)</td></tr>
                                            <tr><td><strong>Komparativ</strong> (So sánh hơn)</td><td>+ er</td><td>schöner (đẹp hơn)</td></tr>
                                            <tr><td><strong>Superlativ</strong> (So sánh nhất)</td><td>am + (e)sten</td><td>am schönsten (đẹp nhất)</td></tr>
                                        </tbody>
                                    </table>
                                </div>

                                <h5 class="mt-4">2. So sánh bất quy tắc</h5>
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead class="table-warning">
                                            <tr><th>Positiv</th><th>Komparativ</th><th>Superlativ</th></tr>
                                        </thead>
                                        <tbody>
                                            <tr><td>gut</td><td>besser</td><td>am besten</td></tr>
                                            <tr><td>viel</td><td>mehr</td><td>am meisten</td></tr>
                                            <tr><td>gern</td><td>lieber</td><td>am liebsten</td></tr>
                                            <tr><td>groß</td><td>größer</td><td>am größten</td></tr>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="example-box mt-4">
                                    <strong>Ví dụ:</strong><br>
                                    <p>Peter ist <span class="highlight-text">groß</span>. (Peter cao)</p>
                                    <p>Maria ist <span class="highlight-text">größer</span> als Peter. (Maria cao hơn Peter)</p>
                                    <p>Lisa ist <span class="highlight-text">am größten</span>. (Lisa cao nhất)</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ĐẠI TỪ PHẢN THÂN -->
                    <div class="col-12 mb-4">
                        <div class="card section-card">
                            <div class="card-header section-header" onclick="toggleSection('reflexive')">
                                <h4 class="mb-0">
                                    <i class="bi bi-arrow-repeat"></i> Đại từ phản thân (Reflexivpronomen)
                                </h4>
                            </div>
                            <div id="reflexive" class="section-content">
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead class="table-primary">
                                            <tr><th>Chủ ngữ</th><th>Akkusativ</th><th>Dativ</th></tr>
                                        </thead>
                                        <tbody>
                                            <tr><td>ich</td><td>mich</td><td>mir</td></tr>
                                            <tr><td>du</td><td>dich</td><td>dir</td></tr>
                                            <tr><td>er/sie/es</td><td>sich</td><td>sich</td></tr>
                                            <tr><td>wir</td><td>uns</td><td>uns</td></tr>
                                            <tr><td>ihr</td><td>euch</td><td>euch</td></tr>
                                            <tr><td>sie/Sie</td><td>sich</td><td>sich</td></tr>
                                        </tbody>
                                    </table>
                                </div>

                                <h5 class="mt-4">Động từ phản thân phổ biến</h5>
                                <div class="example-box">
                                    <p><strong>sich freuen (vui mừng):</strong> Ich freue <span class="highlight-text">mich</span>.</p>
                                    <p><strong>sich waschen (rửa, tắm):</strong> Ich wasche <span class="highlight-text">mich</span>.</p>
                                    <p><strong>sich interessieren (quan tâm):</strong> Ich interessiere <span class="highlight-text">mich</span> für Musik.</p>
                                    <p><strong>sich vorstellen (tự giới thiệu):</strong> Ich stelle <span class="highlight-text">mich</span> vor.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Toggle sections
            window.toggleSection = function(sectionId) {
                const section = document.getElementById(sectionId);
                if (section.style.display === 'none' || section.style.display === '') {
                    section.style.display = 'block';
                } else {
                    section.style.display = 'none';
                }
            };

            // Smooth scroll for sidebar links
            document.querySelectorAll('.list-group-item').forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const targetId = this.getAttribute('href').substring(1);
                    const targetSection = document.getElementById(targetId);
                    
                    // Open the section if closed
                    if (targetSection.style.display === 'none' || targetSection.style.display === '') {
                        targetSection.style.display = 'block';
                    }
                    
                    // Scroll to section
                    targetSection.parentElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    
                    // Update active state
                    document.querySelectorAll('.list-group-item').forEach(item => item.classList.remove('active'));
                    this.classList.add('active');
                });
            });
        });
    </script>
</body>
</html>
