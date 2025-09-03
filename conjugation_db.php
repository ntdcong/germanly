<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $word = trim($_POST['word'] ?? '');
    
    if (empty($word)) {
        echo '<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> Vui lòng nhập từ cần tra cứu.</div>';
        exit;
    }
    
    try {
        // Tìm động từ trong bảng verbs
        $stmt = $pdo->prepare('SELECT * FROM verbs WHERE Infinitive = ? LIMIT 1');
        $stmt->execute([$word]);
        $verb = $stmt->fetch();
        
        if (!$verb) {
            echo '<div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> Không tìm thấy động từ "<strong>' . htmlspecialchars($word) . '</strong>" trong database.
                  </div>';
            exit;
        }
        
        // Hiển thị kết quả
        echo '<div class="conjugation-result">';
        echo '<h6 class="mb-3"><i class="bi bi-check-circle text-success"></i> Tìm thấy trong database</h6>';
        
        echo '<div class="row g-3">';
        
        // Infinitive
        echo '<div class="col-md-6">';
        echo '<div class="card h-100">';
        echo '<div class="card-header bg-primary text-white"><strong>Nguyên mẫu</strong></div>';
        echo '<div class="card-body">';
        echo '<h5 class="mb-0">' . htmlspecialchars($verb['Infinitive']) . '</h5>';
        echo '</div></div></div>';
        
        // Present tense
        echo '<div class="col-md-6">';
        echo '<div class="card h-100">';
        echo '<div class="card-header bg-success text-white"><strong>Hiện tại (Präsens)</strong></div>';
        echo '<div class="card-body">';
        echo '<div class="row g-2">';
        echo '<div class="col-4"><small class="text-muted">ich</small><br><strong>' . htmlspecialchars($verb['Praesens_ich']) . '</strong></div>';
        echo '<div class="col-4"><small class="text-muted">du</small><br><strong>' . htmlspecialchars($verb['Praesens_du']) . '</strong></div>';
        echo '<div class="col-4"><small class="text-muted">er/sie/es</small><br><strong>' . htmlspecialchars($verb['Praesens_er_sie_es']) . '</strong></div>';
        echo '</div></div></div></div>';
        
        // Past tense
        echo '<div class="col-md-6">';
        echo '<div class="card h-100">';
        echo '<div class="card-header bg-warning text-dark"><strong>Quá khứ (Präteritum)</strong></div>';
        echo '<div class="card-body">';
        echo '<div class="row g-2">';
        echo '<div class="col-12"><small class="text-muted">ich</small><br><strong>' . htmlspecialchars($verb['Praeteritum_ich']) . '</strong></div>';
        echo '</div></div></div></div>';
        
        // Past participle
        echo '<div class="col-md-6">';
        echo '<div class="card h-100">';
        echo '<div class="card-header bg-info text-white"><strong>Phân từ II (Partizip II)</strong></div>';
        echo '<div class="card-body">';
        echo '<h5 class="mb-0">' . htmlspecialchars($verb['Partizip_II']) . '</h5>';
        echo '</div></div></div>';
        
        // Imperative
        echo '<div class="col-md-6">';
        echo '<div class="card h-100">';
        echo '<div class="card-header bg-secondary text-white"><strong>Mệnh lệnh (Imperativ)</strong></div>';
        echo '<div class="card-body">';
        echo '<div class="row g-2">';
        echo '<div class="col-6"><small class="text-muted">Số ít</small><br><strong>' . htmlspecialchars($verb['Imperativ_Singular']) . '</strong></div>';
        echo '<div class="col-6"><small class="text-muted">Số nhiều</small><br><strong>' . htmlspecialchars($verb['Imperativ_Plural']) . '</strong></div>';
        echo '</div></div></div></div>';
        
        // Auxiliary verb
        echo '<div class="col-md-6">';
        echo '<div class="card h-100">';
        echo '<div class="card-header bg-dark text-white"><strong>Trợ động từ</strong></div>';
        echo '<div class="card-body">';
        echo '<h5 class="mb-0">' . htmlspecialchars($verb['Hilfsverb']) . '</h5>';
        echo '</div></div></div>';
        
        echo '</div>'; // End row
        
        echo '</div>'; // End conjugation-result
        
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle"></i> Lỗi database: ' . htmlspecialchars($e->getMessage()) . '
              </div>';
    }
} else {
    echo '<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> Phương thức không hợp lệ.</div>';
}
?>
