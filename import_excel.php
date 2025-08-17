<?php
session_start();
require 'db.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$user_id = $_SESSION['user_id'];
$notebook_id = (int)($_GET['notebook_id'] ?? 0);

// Kiểm tra quyền sở hữu sổ tay
$stmt = $pdo->prepare('SELECT * FROM notebooks WHERE id=? AND user_id=?');
$stmt->execute([$notebook_id, $user_id]);
$notebook = $stmt->fetch();
if (!$notebook) {
    die('Không tìm thấy sổ tay hoặc bạn không có quyền truy cập!');
}

$message = '';
$dataPreview = [];
$previewMode = false;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 15;
$totalPages = 1;

// Xử lý AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['ajax_action'] === 'delete' && isset($_POST['index'])) {
        $index = (int)$_POST['index'];
        if (isset($_SESSION['data_preview'][$index])) {
            unset($_SESSION['data_preview'][$index]);
            $_SESSION['data_preview'] = array_values($_SESSION['data_preview']); // reset keys
        }
        echo json_encode(['status' => 'ok']);
        exit;
    }
    
    if ($_POST['ajax_action'] === 'update' && isset($_POST['index'])) {
        $index = (int)$_POST['index'];
        if (isset($_SESSION['data_preview'][$index])) {
            $_SESSION['data_preview'][$index] = [
                'word' => trim($_POST['word']),
                'phonetic' => trim($_POST['phonetic']),
                'meaning' => trim($_POST['meaning']),
                'note' => trim($_POST['note']),
                'plural' => trim($_POST['plural']),
                'genus' => trim($_POST['genus'])
            ];
        }
        echo json_encode(['status' => 'ok']);
        exit;
    }
    
    if ($_POST['ajax_action'] === 'add') {
        $_SESSION['data_preview'][] = [
            'word' => trim($_POST['word']),
            'phonetic' => trim($_POST['phonetic']),
            'meaning' => trim($_POST['meaning']),
            'note' => trim($_POST['note']),
            'plural' => trim($_POST['plural']),
            'genus' => trim($_POST['genus'])
        ];
        echo json_encode(['status' => 'ok']);
        exit;
    }
    
    if ($_POST['ajax_action'] === 'get' && isset($_POST['index'])) {
        $index = (int)$_POST['index'];
        if (isset($_SESSION['data_preview'][$index])) {
            echo json_encode($_SESSION['data_preview'][$index]);
        } else {
            echo json_encode([]);
        }
        exit;
    }
    
    // Xử lý phân trang AJAX
    if ($_POST['ajax_action'] === 'paginate') {
        $newPage = (int)$_POST['page'];
        $_SESSION['current_page'] = $newPage;
        echo json_encode(['status' => 'ok']);
        exit;
    }
}

// Xử lý khi có POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_action'])) {
    if (isset($_POST['reset'])) {
        // Reset session khi chọn file mới
        unset($_SESSION['data_preview']);
        unset($_SESSION['uploaded_file_name']);
        unset($_SESSION['uploaded_file_data']);
        unset($_SESSION['current_page']);
        $previewMode = false;
        header("Location: ?notebook_id=$notebook_id");
        exit;
    } elseif (isset($_FILES['excel']) && $_FILES['excel']['error'] === 0) {
        require_once __DIR__ . '/vendor/autoload.php';

        $file = $_FILES['excel']['tmp_name'];
        $fileName = $_FILES['excel']['name'];
        
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();
            $dataPreview = [];

            foreach ($rows as $i => $row) {
                if ($i === 0) continue; // Bỏ dòng tiêu đề
                
                // Chỉ thêm hàng có dữ liệu
                $word = trim($row[0] ?? '');
                $meaning = trim($row[2] ?? '');
                
                if ($word || $meaning) { // Nếu có ít nhất từ vựng hoặc nghĩa
                    $dataPreview[] = [
                        'word' => $word,
                        'phonetic' => trim($row[1] ?? ''),
                        'meaning' => $meaning,
                        'note' => trim($row[3] ?? ''),
                        'plural' => trim($row[4] ?? ''),
                        'genus' => trim($row[5] ?? ''),
                    ];
                }
            }

            // Lưu dữ liệu và tên file vào session
            $_SESSION['data_preview'] = $dataPreview;
            $_SESSION['uploaded_file_name'] = $fileName;
            $_SESSION['uploaded_file_data'] = base64_encode(file_get_contents($file)); // Lưu file để có thể dùng lại
            
            if (isset($_POST['preview'])) {
                $previewMode = true;
            } elseif (isset($_POST['import'])) {
                $dataToImport = $_SESSION['data_preview'] ?? [];
                $count = 0;
                foreach ($dataToImport as $item) {
                    if ($item['word'] && $item['meaning']) {
                        $stmt = $pdo->prepare('INSERT INTO vocabularies (notebook_id, word, phonetic, meaning, note, plural, genus) VALUES (?, ?, ?, ?, ?, ?, ?)');
                        $stmt->execute([$notebook_id, $item['word'], $item['phonetic'], $item['meaning'], $item['note'], $item['plural'], $item['genus']]);
                        $count++;
                    }
                }
                // Xóa session sau khi import
                unset($_SESSION['data_preview']);
                unset($_SESSION['uploaded_file_name']);
                unset($_SESSION['uploaded_file_data']);
                unset($_SESSION['current_page']);
                $message = "✅ Đã import <b>$count</b> từ vựng thành công!";
                $previewMode = false;
            }
            header("Location: ?notebook_id=$notebook_id");
            exit;
        } catch (Exception $e) {
            $message = '❌ Lỗi đọc file: ' . htmlspecialchars($e->getMessage());
        }
    } elseif (isset($_POST['import'])) {
        // Import từ session
        $dataToImport = $_SESSION['data_preview'] ?? [];
        $count = 0;
        foreach ($dataToImport as $item) {
            if ($item['word'] && $item['meaning']) {
                $stmt = $pdo->prepare('INSERT INTO vocabularies (notebook_id, word, phonetic, meaning, note, plural, genus) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$notebook_id, $item['word'], $item['phonetic'], $item['meaning'], $item['note'], $item['plural'], $item['genus']]);
                $count++;
            }
        }
        // Xóa session sau khi import
        unset($_SESSION['data_preview']);
        unset($_SESSION['uploaded_file_name']);
        unset($_SESSION['uploaded_file_data']);
        unset($_SESSION['current_page']);
        $message = "✅ Đã import <b>$count</b> từ vựng thành công!";
        $previewMode = false;
        header("Location: ?notebook_id=$notebook_id&message=" . urlencode($message));
        exit;
    }
}

// Lấy message từ URL nếu có
if (isset($_GET['message'])) {
    $message = $_GET['message'];
}

// Lấy trang hiện tại từ session hoặc GET
if (isset($_SESSION['current_page'])) {
    $page = (int)$_SESSION['current_page'];
} else {
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $_SESSION['current_page'] = $page;
}

// Lấy dữ liệu từ session nếu đang ở chế độ xem trước
if (isset($_SESSION['data_preview'])) {
    $dataPreview = $_SESSION['data_preview'];
    $totalItems = count($dataPreview);
    $totalPages = ceil($totalItems / $perPage);
    $page = max(1, min($page, $totalPages));
    $_SESSION['current_page'] = $page;
    $offset = ($page - 1) * $perPage;
    $pagedData = array_slice($dataPreview, $offset, $perPage);
    $previewMode = true;
} else {
    $pagedData = [];
    $previewMode = false;
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Import từ Excel - Flashcard Đức</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary-gradient: linear-gradient(to right, #e0eafc, #cfdef3);
            --card-shadow: 0 10px 20px rgba(0,0,0,0.08);
            --border-radius: 1rem;
        }
        
        body {
            background: var(--primary-gradient);
            font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            padding-bottom: 2rem;
        }
        
        .card {
            border-radius: var(--border-radius);
            padding: 2rem;
            box-shadow: var(--card-shadow);
            background: #fff;
            max-width: 1200px;
            margin: 2rem auto;
        }
        
        .example-box {
            background: #f8f9fa;
            border: 1px dashed #ccc;
            padding: 1rem;
            border-radius: 8px;
            margin-top: 1.5rem;
        }
        
        .drop-area {
            border: 2px dashed #0d6efd;
            border-radius: 10px;
            padding: 2rem;
            text-align: center;
            margin-bottom: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            background: #fafbff;
        }
        
        .drop-area.highlight {
            background: #e0f0ff;
            border-color: #0b5ed7;
            transform: scale(1.02);
        }
        
        .file-info {
            margin-top: 1rem;
            padding: 0.75rem;
            background: #e7f1ff;
            border-radius: 5px;
            display: none;
        }
        
        .back-link {
            text-decoration: none;
            color: #0d6efd;
            font-weight: 500;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
        
        .pagination-nav {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin: 1.5rem 0;
            flex-wrap: wrap;
        }
        
        .pagination-btn {
            padding: 8px 16px;
            border: 1px solid #ddd;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            color: #0d6efd;
            transition: all 0.2s ease;
            min-width: 40px;
            text-align: center;
        }
        
        .pagination-btn:hover:not(.disabled) {
            background: #e9ecef;
            transform: translateY(-1px);
        }
        
        .pagination-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        
        .current-page {
            font-weight: 600;
            color: #0d6efd;
            padding: 8px 16px;
            background: #e7f1ff;
            border-radius: 6px;
            min-width: 40px;
            text-align: center;
        }
        
        .reset-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            cursor: pointer;
            display: none;
            transition: all 0.2s ease;
            font-size: 18px;
            line-height: 1;
        }
        
        .reset-btn:hover {
            background: #c82333;
            transform: scale(1.1);
        }
        
        .action-btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
            border-radius: 4px;
            margin: 0 2px;
        }
        
        .table-responsive {
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .table th {
            font-weight: 600;
            background-color: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(13, 110, 253, 0.05);
        }
        
        /* Mobile responsive */
        @media (max-width: 768px) {
            .card {
                padding: 1.5rem;
                margin: 1rem;
            }
            
            .drop-area {
                padding: 1.5rem 1rem;
            }
            
            .table th, .table td {
                padding: 0.5rem;
                font-size: 0.875rem;
            }
            
            .action-btn {
                padding: 0.15rem 0.3rem;
                font-size: 0.75rem;
            }
            
            .pagination-btn, .current-page {
                padding: 6px 12px;
                font-size: 0.875rem;
            }
            
            .navbar-text {
                font-size: 0.875rem;
            }
        }
        
        @media (max-width: 576px) {
            .card {
                padding: 1rem;
            }
            
            .drop-area {
                padding: 1rem 0.5rem;
            }
            
            .table th, .table td {
                padding: 0.4rem;
                font-size: 0.75rem;
            }
            
            .action-btn {
                padding: 0.1rem 0.2rem;
                font-size: 0.7rem;
            }
            
            .pagination-nav {
                gap: 4px;
            }
            
            .pagination-btn, .current-page {
                padding: 4px 8px;
                font-size: 0.75rem;
                min-width: 32px;
            }
        }
        
        /* Loading spinner */
        .loading {
            display: none;
            text-align: center;
            padding: 1rem;
        }
        
        .spinner-border {
            width: 1.5rem;
            height: 1.5rem;
        }
        
        /* Modal improvements */
        .modal-content {
            border: none;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }
        
        .modal-header {
            border-bottom: 1px solid #e9ecef;
            padding: 1rem 1.5rem;
        }
        
        .modal-footer {
            border-top: 1px solid #e9ecef;
            padding: 1rem 1.5rem;
        }
        
        .form-control:focus {
            border-color: #86b7fe;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
    </style>
</head>
<body>
<nav class="navbar navbar-light bg-light">
    <div class="container">
        <a class="back-link" href="dashboard.php"><i class="bi bi-arrow-left"></i> Quay lại Sổ tay</a>
        <span class="navbar-text text-truncate">
            Đang import cho sổ tay: <strong><?= htmlspecialchars($notebook['title']) ?></strong>
        </span>
    </div>
</nav>

<div class="container">
    <div class="card">
        <h4 class="mb-4"><i class="bi bi-file-earmark-excel-fill text-success"></i> Import từ vựng từ file Excel</h4>

        <?php if ($message): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" id="uploadForm">
            <div class="drop-area" id="dropArea">
                <button type="button" class="reset-btn" id="resetBtn" title="Chọn file khác">×</button>
                <i class="bi bi-cloud-arrow-up" style="font-size: 2rem; color: #0d6efd;"></i>
                <p class="mb-2">Kéo thả file Excel (.xlsx) vào đây hoặc <strong>chọn file</strong></p>
                <button type="button" class="btn btn-outline-primary btn-sm" onclick="document.getElementById('fileInput').click();">
                    <i class="bi bi-folder2-open"></i> Chọn file
                </button>
                <input type="file" name="excel" id="fileInput" accept=".xlsx" style="display: none;">
                
                <div class="file-info" id="fileInfo">
                    <i class="bi bi-file-earmark-excel"></i> 
                    <span id="fileName"></span>
                </div>
            </div>

            <?php if ($previewMode && !empty($dataPreview)): ?>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
                    <h5 class="mb-0">📋 Xem trước dữ liệu (<?= count($dataPreview) ?> từ):</h5>
                    <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addModal">
                        <i class="bi bi-plus-circle"></i> Thêm từ
                    </button>
                </div>
                
                <div class="loading" id="loading">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Đang tải...</span>
                    </div>
                </div>
                
                <div class="table-responsive mt-3" id="tableContainer">
                    <table class="table table-bordered table-sm table-hover">
                        <thead class="table-light">
                            <tr>
                                <th width="15%">Từ vựng</th>
                                <th width="15%">Phiên âm</th>
                                <th width="25%">Nghĩa</th>
                                <th width="20%">Ghi chú</th>
                                <th width="15%">Số nhiều</th>
                                <th width="10%">Giống</th>
                                <th width="10%">Hành động</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <?php if (!empty($pagedData)): ?>
                                <?php foreach ($pagedData as $index => $row): ?>
                                    <tr data-index="<?= $offset + $index ?>">
                                        <td><?= htmlspecialchars($row['word']) ?></td>
                                        <td><?= htmlspecialchars($row['phonetic']) ?></td>
                                        <td><?= htmlspecialchars($row['meaning']) ?></td>
                                        <td><?= htmlspecialchars($row['note']) ?></td>
                                        <td><?= htmlspecialchars($row['plural']) ?></td>
                                        <td><?= htmlspecialchars($row['genus']) ?></td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-outline-primary action-btn edit-btn"
                                                    data-index="<?= $offset + $index ?>"
                                                    data-bs-toggle="modal" data-bs-target="#editModal">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger action-btn delete-btn"
                                                    data-index="<?= $offset + $index ?>">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center">Không có dữ liệu</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Phân trang -->
                <div class="pagination-nav" id="paginationNav">
                    <?php if ($page > 1): ?>
                        <button type="button" class="pagination-btn" data-page="<?= $page - 1 ?>">
                            <i class="bi bi-chevron-left"></i> Trước
                        </button>
                    <?php else: ?>
                        <span class="pagination-btn disabled">
                            <i class="bi bi-chevron-left"></i> Trước
                        </span>
                    <?php endif; ?>

                    <span class="current-page">Trang <?= $page ?></span>

                    <?php if ($page < $totalPages): ?>
                        <button type="button" class="pagination-btn" data-page="<?= $page + 1 ?>">
                            Sau <i class="bi bi-chevron-right"></i>
                        </button>
                    <?php else: ?>
                        <span class="pagination-btn disabled">
                            Sau <i class="bi bi-chevron-right"></i>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-md-center">
                    <button type="submit" name="import" class="btn btn-success flex-fill flex-md-grow-0">
                        <i class="bi bi-check-circle"></i> Xác nhận Import (<?= count($dataPreview) ?> từ)
                    </button>
                    <button type="submit" name="reset" class="btn btn-outline-secondary flex-fill flex-md-grow-0">
                        <i class="bi bi-arrow-repeat"></i> Chọn file khác
                    </button>
                </div>
            <?php else: ?>
                <button type="submit" name="preview" class="btn btn-primary w-100" id="previewBtn" disabled>
                    <i class="bi bi-eye"></i> Xem trước dữ liệu
                </button>
            <?php endif; ?>
        </form>

        <div class="example-box">
            <strong>📌 Mẫu Excel cần có:</strong><br>
            Dòng đầu tiên là tiêu đề cột:
            <code>Từ vựng | Phiên âm | Nghĩa | Ghi chú | Số nhiều | Giống</code><br>
            <a href="assets/sample.xlsx" download class="btn btn-sm btn-outline-primary mt-2">
                <i class="bi bi-download"></i> Tải file mẫu
            </a>
        </div>
    </div>
</div>

<!-- Modal chỉnh sửa -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="editForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="editModalLabel">Chỉnh sửa từ vựng</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="editIndex">
                    <div class="mb-3">
                        <label class="form-label">Từ vựng</label>
                        <input type="text" class="form-control" id="editWord" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phiên âm</label>
                        <input type="text" class="form-control" id="editPhonetic">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nghĩa</label>
                        <input type="text" class="form-control" id="editMeaning" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ghi chú</label>
                        <input type="text" class="form-control" id="editNote">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Số nhiều</label>
                        <input type="text" class="form-control" id="editPlural">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Giống</label>
                        <input type="text" class="form-control" id="editGenus">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="editCancelBtn">Hủy</button>
                    <button type="submit" class="btn btn-primary">Lưu thay đổi</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal thêm từ -->
<div class="modal fade" id="addModal" tabindex="-1" aria-labelledby="addModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="addForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="addModalLabel">Thêm từ vựng mới</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Từ vựng</label>
                        <input type="text" class="form-control" id="addWord" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phiên âm</label>
                        <input type="text" class="form-control" id="addPhonetic">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nghĩa</label>
                        <input type="text" class="form-control" id="addMeaning" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ghi chú</label>
                        <input type="text" class="form-control" id="addNote">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Số nhiều</label>
                        <input type="text" class="form-control" id="addPlural">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Giống</label>
                        <input type="text" class="form-control" id="addGenus">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="addCancelBtn">Hủy</button>
                    <button type="submit" class="btn btn-success">Thêm từ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Helper functions
    function showLoading() {
        document.getElementById('loading').style.display = 'block';
        document.getElementById('tableContainer').style.opacity = '0.5';
    }
    
    function hideLoading() {
        document.getElementById('loading').style.display = 'none';
        document.getElementById('tableContainer').style.opacity = '1';
    }
    
    function updatePagination(newPage, totalPages) {
        const paginationNav = document.getElementById('paginationNav');
        if (!paginationNav) return;
        
        let html = '';
        if (newPage > 1) {
            html += `<button type="button" class="pagination-btn" data-page="${newPage - 1}">
                        <i class="bi bi-chevron-left"></i> Trước
                     </button>`;
        } else {
            html += `<span class="pagination-btn disabled">
                        <i class="bi bi-chevron-left"></i> Trước
                     </span>`;
        }
        
        html += `<span class="current-page">Trang ${newPage}</span>`;
        
        if (newPage < totalPages) {
            html += `<button type="button" class="pagination-btn" data-page="${newPage + 1}">
                        Sau <i class="bi bi-chevron-right"></i>
                     </button>`;
        } else {
            html += `<span class="pagination-btn disabled">
                        Sau <i class="bi bi-chevron-right"></i>
                     </span>`;
        }
        
        paginationNav.innerHTML = html;
        
        // Reattach event listeners
        document.querySelectorAll('.pagination-btn[data-page]').forEach(btn => {
            btn.addEventListener('click', function() {
                const page = this.getAttribute('data-page');
                changePage(page);
            });
        });
    }
    
    function changePage(newPage) {
        showLoading();
        
        const formData = new FormData();
        formData.append('ajax_action', 'paginate');
        formData.append('page', newPage);
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'ok') {
                // Reload page content via AJAX
                fetch(window.location.pathname + window.location.search, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.text())
                .then(html => {
                    // Parse the returned HTML
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    
                    // Update table body
                    const newTableBody = doc.getElementById('tableBody');
                    if (newTableBody) {
                        document.getElementById('tableBody').innerHTML = newTableBody.innerHTML;
                        reattachTableEvents();
                    }
                    
                    // Update pagination
                    const newPagination = doc.getElementById('paginationNav');
                    if (newPagination) {
                        document.getElementById('paginationNav').innerHTML = newPagination.innerHTML;
                        reattachPaginationEvents();
                    }
                    
                    hideLoading();
                });
            }
        })
        .catch(error => {
            console.error('Error:', error);
            hideLoading();
        });
    }
    
    function reattachTableEvents() {
        // Reattach edit and delete events
        document.querySelectorAll('.edit-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const index = this.getAttribute('data-index');
                openEditModal(index);
            });
        });
        
        document.querySelectorAll('.delete-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const index = this.getAttribute('data-index');
                deleteVocabulary(index);
            });
        });
    }
    
    function reattachPaginationEvents() {
        document.querySelectorAll('.pagination-btn[data-page]').forEach(btn => {
            btn.addEventListener('click', function() {
                const page = this.getAttribute('data-page');
                changePage(page);
            });
        });
    }
    
    function openEditModal(index) {
        const formData = new FormData();
        formData.append('ajax_action', 'get');
        formData.append('index', index);
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            const editIndex = document.getElementById('editIndex');
            const editWord = document.getElementById('editWord');
            const editPhonetic = document.getElementById('editPhonetic');
            const editMeaning = document.getElementById('editMeaning');
            const editNote = document.getElementById('editNote');
            const editPlural = document.getElementById('editPlural');
            const editGenus = document.getElementById('editGenus');
            
            if (editIndex) editIndex.value = index;
            if (editWord) editWord.value = data.word || '';
            if (editPhonetic) editPhonetic.value = data.phonetic || '';
            if (editMeaning) editMeaning.value = data.meaning || '';
            if (editNote) editNote.value = data.note || '';
            if (editPlural) editPlural.value = data.plural || '';
            if (editGenus) editGenus.value = data.genus || '';
        })
        .catch(error => console.error('Error:', error));
    }
    
    function deleteVocabulary(index) {
        if (confirm("Bạn có chắc muốn xóa từ này?")) {
            const formData = new FormData();
            formData.append('ajax_action', 'delete');
            formData.append('index', index);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'ok') {
                    // Remove row from table
                    const row = document.querySelector(`tr[data-index="${index}"]`);
                    if (row) {
                        row.style.transition = 'opacity 0.3s';
                        row.style.opacity = '0';
                        setTimeout(() => {
                            row.remove();
                            // Reload to update pagination if needed
                            location.reload();
                        }, 300);
                    }
                }
            })
            .catch(error => console.error('Error:', error));
        }
    }
    
    // Main DOM ready
    document.addEventListener('DOMContentLoaded', function () {
        const dropArea = document.getElementById('dropArea');
        const fileInput = document.getElementById('fileInput');
        const fileInfo = document.getElementById('fileInfo');
        const fileName = document.getElementById('fileName');
        const previewBtn = document.getElementById('previewBtn');
        const resetBtn = document.getElementById('resetBtn');

        if (dropArea) {
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                dropArea.addEventListener(eventName, preventDefaults, false);
            });

            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }

            ['dragenter', 'dragover'].forEach(eventName => {
                dropArea.addEventListener(eventName, highlight, false);
            });

            ['dragleave', 'drop'].forEach(eventName => {
                dropArea.addEventListener(eventName, unhighlight, false);
            });

            function highlight() {
                dropArea.classList.add('highlight');
            }

            function unhighlight() {
                dropArea.classList.remove('highlight');
            }

            dropArea.addEventListener('drop', handleDrop, false);

            function handleDrop(e) {
                const dt = e.dataTransfer;
                const files = dt.files;
                if (files.length) {
                    fileInput.files = files;
                    showFileInfo(files[0].name);
                    if (previewBtn) previewBtn.disabled = false;
                    if (resetBtn) resetBtn.style.display = 'block';
                }
            }

            if (fileInput) {
                fileInput.addEventListener('change', function() {
                    if (this.files.length) {
                        showFileInfo(this.files[0].name);
                        if (previewBtn) previewBtn.disabled = false;
                        if (resetBtn) resetBtn.style.display = 'block';
                    } else {
                        hideFileInfo();
                        if (previewBtn) previewBtn.disabled = true;
                        if (resetBtn) resetBtn.style.display = 'none';
                    }
                });
            }

            function showFileInfo(name) {
                if (fileName) fileName.textContent = name;
                if (fileInfo) fileInfo.style.display = 'block';
            }

            function hideFileInfo() {
                if (fileInfo) fileInfo.style.display = 'none';
            }

            if (resetBtn) {
                resetBtn.addEventListener('click', function() {
                    fileInput.value = '';
                    hideFileInfo();
                    if (previewBtn) previewBtn.disabled = true;
                    this.style.display = 'none';
                    
                    // Submit form để reset session
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.style.display = 'none';
                    
                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'reset';
                    hiddenInput.value = '1';
                    
                    form.appendChild(hiddenInput);
                    document.body.appendChild(form);
                    form.submit();
                });
            }

            // Hiển thị tên file nếu đã có trong session
            <?php if (isset($_SESSION['uploaded_file_name'])): ?>
                showFileInfo('<?= addslashes($_SESSION['uploaded_file_name']) ?>');
                if (previewBtn) previewBtn.disabled = false;
                if (resetBtn) resetBtn.style.display = 'block';
            <?php endif; ?>
        }

        // Xử lý xóa
        document.querySelectorAll('.delete-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const index = this.getAttribute('data-index');
                deleteVocabulary(index);
            });
        });

        // Xử lý mở modal sửa
        document.querySelectorAll('.edit-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const index = this.getAttribute('data-index');
                openEditModal(index);
            });
        });

        // Lưu chỉnh sửa
        const editForm = document.getElementById('editForm');
        if (editForm) {
            editForm.addEventListener('submit', function (e) {
                e.preventDefault();
                const formData = new FormData();
                const editIndex = document.getElementById('editIndex');
                const editWord = document.getElementById('editWord');
                const editPhonetic = document.getElementById('editPhonetic');
                const editMeaning = document.getElementById('editMeaning');
                const editNote = document.getElementById('editNote');
                const editPlural = document.getElementById('editPlural');
                const editGenus = document.getElementById('editGenus');
                
                if (editIndex && editWord && editMeaning) {
                    formData.append('ajax_action', 'update');
                    formData.append('index', editIndex.value);
                    formData.append('word', editWord.value);
                    formData.append('phonetic', editPhonetic ? editPhonetic.value : '');
                    formData.append('meaning', editMeaning.value);
                    formData.append('note', editNote ? editNote.value : '');
                    formData.append('plural', editPlural ? editPlural.value : '');
                    formData.append('genus', editGenus ? editGenus.value : '');
                    
                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'ok') {
                            // Đóng modal
                            const editModal = bootstrap.Modal.getInstance(document.getElementById('editModal'));
                            if (editModal) editModal.hide();
                            
                            // Reload trang để cập nhật dữ liệu
                            location.reload();
                        }
                    })
                    .catch(error => console.error('Error:', error));
                }
            });
        }

        // Thêm từ mới
        const addForm = document.getElementById('addForm');
        if (addForm) {
            addForm.addEventListener('submit', function (e) {
                e.preventDefault();
                const formData = new FormData();
                const addWord = document.getElementById('addWord');
                const addPhonetic = document.getElementById('addPhonetic');
                const addMeaning = document.getElementById('addMeaning');
                const addNote = document.getElementById('addNote');
                const addPlural = document.getElementById('addPlural');
                const addGenus = document.getElementById('addGenus');
                
                if (addWord && addMeaning) {
                    formData.append('ajax_action', 'add');
                    formData.append('word', addWord.value);
                    formData.append('phonetic', addPhonetic ? addPhonetic.value : '');
                    formData.append('meaning', addMeaning.value);
                    formData.append('note', addNote ? addNote.value : '');
                    formData.append('plural', addPlural ? addPlural.value : '');
                    formData.append('genus', addGenus ? addGenus.value : '');
                    
                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'ok') {
                            // Đóng modal
                            const addModal = bootstrap.Modal.getInstance(document.getElementById('addModal'));
                            if (addModal) addModal.hide();
                            
                            // Reload trang để cập nhật dữ liệu
                            location.reload();
                        }
                    })
                    .catch(error => console.error('Error:', error));
                }
            });
        }

        // Xử lý phân trang
        reattachPaginationEvents();
    });
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>