<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$message = '';
$notebook = null;
$share_owner = null;
$share_code = null;

// Trường hợp: người dùng nhập link qua form
if (isset($_POST['share_link']) && !isset($_GET['code'])) {
    $input_link = trim($_POST['share_link']);

    // Trích xuất code từ URL
    $parsed = parse_url($input_link);
    if ($parsed && isset($parsed['query'])) {
        parse_str($parsed['query'], $params);
        if (isset($params['code'])) {
            $share_code = $params['code'];
        } else {
            $message = 'Không tìm thấy mã chia sẻ trong link.';
        }
    } else {
        $message = 'Link không hợp lệ. Vui lòng nhập đúng định dạng URL.';
    }
}
// Trường hợp: truy cập trực tiếp bằng ?code=...
elseif (isset($_GET['code'])) {
    $share_code = $_GET['code'];
}

// Nếu có share_code, xử lý lấy thông tin sổ tay
if ($share_code) {
    $stmt = $pdo->prepare('
        SELECT s.*, n.title, n.description, n.group_id, u.id as share_user_id 
        FROM notebook_shares s 
        JOIN notebooks n ON s.notebook_id = n.id 
        JOIN users u ON s.user_id = u.id 
        WHERE s.share_code = ?
    ');
    $stmt->execute([$share_code]);
    $share = $stmt->fetch();

    if (!$share) {
        $message = '❌ Link chia sẻ không hợp lệ hoặc đã hết hạn!';
    } elseif ($share['user_id'] == $user_id) {
        $message = '⚠️ Bạn không thể nhập sổ tay của chính mình!';
    } else {
        $notebook = [
            'id' => $share['notebook_id'],
            'title' => $share['title'],
            'description' => $share['description'],
            'group_id' => $share['group_id']
        ];
        $share_owner = $share['share_user_id'];
        $message = '';  // Xóa thông báo lỗi nếu hợp lệ
    }
}

// Xử lý nhập sổ tay
if (isset($_POST['import_notebook']) && isset($_POST['notebook_id']) && isset($_POST['share_code'])) {
    $notebook_id = (int) $_POST['notebook_id'];
    $share_code = $_POST['share_code'];
    $new_title = trim($_POST['title'] ?? '');
    $new_desc = trim($_POST['description'] ?? '');
    $group_id = $_POST['group_id'] !== '' ? (int) $_POST['group_id'] : null;

    $stmt = $pdo->prepare('SELECT s.*, n.id as notebook_id FROM notebook_shares s JOIN notebooks n ON s.notebook_id = n.id WHERE s.share_code = ? AND n.id = ?');
    $stmt->execute([$share_code, $notebook_id]);
    $share = $stmt->fetch();

    if (!$share) {
        $message = '❌ Link chia sẻ không hợp lệ!';
    } elseif ($share['user_id'] == $user_id) {
        $message = '⚠️ Bạn không thể nhập sổ tay của chính mình!';
    } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO notebooks (user_id, title, description, group_id) VALUES (?, ?, ?, ?)');
            $stmt->execute([$user_id, $new_title, $new_desc, $group_id]);
            $new_notebook_id = $pdo->lastInsertId();

            $stmt = $pdo->prepare('INSERT INTO vocabularies (notebook_id, word, meaning, note, created_at) 
                                  SELECT ?, word, meaning, note, NOW() 
                                  FROM vocabularies 
                                  WHERE notebook_id = ?');
            $stmt->execute([$new_notebook_id, $notebook_id]);

            $pdo->commit();
            $_SESSION['success_message'] = '🎉 Đã nhập sổ tay thành công!';
            header('Location: dashboard.php');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = '❌ Có lỗi xảy ra: ' . $e->getMessage();
        }
    }
}

// Lấy danh sách nhóm
$stmt = $pdo->prepare('SELECT * FROM notebook_groups WHERE user_id = ? ORDER BY created_at DESC');
$stmt->execute([$user_id]);
$groups = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Nhập sổ tay - GERMANLY</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
    body {
        background: #f4f6fb;
        font-family: 'Inter', sans-serif;
        color: #333;
    }
    .navbar, .modern-navbar {
        background: #5b67ca;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        padding: 1rem 0;
    }
    .navbar-brand {
        font-weight: 700;
        font-size: 1.5rem;
        color: white !important;
        text-decoration: none;
    }
    .logout-btn {
        color: white !important;
        text-decoration: none;
        font-weight: 500;
        padding: 0.5rem 1rem;
        border-radius: 0.4rem;
        background: rgba(255, 255, 255, 0.15);
    }
    .logout-btn:hover {
        background: rgba(255, 255, 255, 0.25);
    }
    .btn {
        border-radius: 0.4rem !important;
        font-weight: 500;
        padding: 0.5rem 1rem;
    }
    .btn-success {
        background: #28a745;
        border: none;
    }
    .btn-primary {
        background: #5b67ca;
        border: none;
    }
    .import-card {
        background: #fff;
        border-radius: 0.5rem;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        padding: 2rem;
        margin-top: 2rem;
        max-width: 820px;
        margin-left: auto;
        margin-right: auto;
    }
    h2 {
        font-weight: 600;
        color: #333;
        margin-bottom: 1.5rem;
    }
    h2 i {
        color: #5b67ca;
    }
    h5 {
        font-weight: 600;
        color: #333;
    }
    .link-input-group {
        background: #f8f9fa;
        border-radius: 0.5rem;
        padding: 1.25rem;
        border: 2px dashed #5b67ca;
    }
    .link-input-group label {
        font-weight: 600;
        color: #333;
        margin-bottom: 0.75rem;
    }
    .link-input-group input {
        border-radius: 0.4rem;
        border: 1px solid #ced4da;
        padding: 0.6rem 0.9rem;
    }
    .link-input-group input:focus {
        border-color: #5b67ca;
        box-shadow: 0 0 0 0.15rem rgba(91, 103, 202, 0.15);
    }
    .card {
        border: 1px solid #dee2e6;
        border-radius: 0.5rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        overflow: hidden;
    }
    .card-body {
        padding: 1.5rem;
    }
    .card-body p {
        margin-bottom: 0.75rem;
        line-height: 1.6;
    }
    .card-body strong {
        color: #5b67ca;
        font-weight: 600;
    }
    .card-header {
        background: #5b67ca;
        color: white;
        font-weight: 600;
        border-top-left-radius: 0.5rem;
        border-top-right-radius: 0.5rem;
        padding: 0.875rem 1.125rem;
    }
    .card-header h5 {
        color: white;
        margin: 0;
        font-size: 1rem;
    }
    .form-control, .form-select {
        border-radius: 0.4rem;
        border: 1px solid #ced4da;
        padding: 0.6rem 0.9rem;
        font-size: 0.95rem;
    }
    .form-control:focus, .form-select:focus {
        border-color: #5b67ca;
        box-shadow: 0 0 0 0.15rem rgba(91, 103, 202, 0.15);
        outline: none;
    }
    .form-label {
        font-weight: 600;
        margin-bottom: 0.5rem;
        color: #333;
    }
    .alert {
        border-radius: 0.4rem;
        font-size: 0.95rem;
        padding: 0.875rem 1rem;
    }
    textarea.form-control {
        resize: vertical;
    }
    @media (max-width: 576px) {
        .import-card {
            padding: 1.5rem;
        }
        h2 {
            font-size: 1.4rem;
        }
    }
</style>
</head>
<body>
    <?php
    $navbar_config = [
        'type' => 'main',
        'show_logout' => true,
        'brand_link' => 'home.php',
        'extra_class' => 'navbar-expand-lg navbar-dark',
        'show_brand' => true
    ];
    include 'includes/navbar.php';
    ?>

    <div class="container mt-5 mb-5">
        <div class="import-card">
            <h2 class="mb-4"><i class="bi bi-download"></i> Nhập sổ tay được chia sẻ</h2>

            <!-- Form nhập link nếu chưa có notebook -->
            <?php if (!$notebook && !isset($_POST['import_notebook'])): ?>
                <form method="post" class="mb-4">
                    <div class="link-input-group">
                        <label class="form-label">Dán link chia sẻ vào đây</label>
                        <div class="input-group">
                            <input type="text" name="share_link" class="form-control form-control-small" 
                                   placeholder="https://deutsch.ct.ws/import_shared.php?code=xxxxxxxxxxxxxxxxxxxx" 
                                   value="<?= htmlspecialchars($_POST['share_link'] ?? '') ?>" required>
                            <button type="submit" class="btn btn-primary btn-small">
                                <i class="bi bi-search"></i> Kiểm tra
                            </button>
                        </div>
                        <small class="text-muted mt-2 d-block">Sao chép link chia sẻ và dán vào ô bên trên để bắt đầu.</small>
                    </div>
                </form>

                <?php if ($message): ?>
                    <div class="alert alert-warning"><?= $message ?></div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Hiển thị thông tin sổ tay nếu hợp lệ -->
            <?php if ($notebook): ?>
                <?php if ($message): ?>
                    <div class="alert alert-info"><?= $message ?></div>
                <?php endif; ?>

                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-book"></i> Sổ tay được chia sẻ</h5>
                    </div>
                    <div class="card-body">
                        <p><strong>Tiêu đề:</strong> <?= htmlspecialchars($notebook['title']) ?></p>
                        <p><strong>Mô tả:</strong> <?= nl2br(htmlspecialchars($notebook['description'])) ?></p>
                        <p><strong>Người chia sẻ:</strong> Người dùng #<?= htmlspecialchars($share_owner) ?></p>
                    </div>
                </div>

                <form method="post">
                    <input type="hidden" name="notebook_id" value="<?= $notebook['id'] ?>">
                    <input type="hidden" name="share_code" value="<?= htmlspecialchars($share_code) ?>">

                    <div class="mb-3">
                        <label class="form-label">Tiêu đề mới</label>
                        <input type="text" name="title" class="form-control" 
                               value="<?= htmlspecialchars($notebook['title']) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Mô tả</label>
                        <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($notebook['description']) ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Nhóm</label>
                        <select name="group_id" class="form-select">
                            <option value="">-- Không thuộc nhóm --</option>
                            <?php foreach ($groups as $g): ?>
                                <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" name="import_notebook" class="btn btn-success btn-custom">
                            <i class="bi bi-download"></i> Nhập sổ tay
                        </button>
                        <a href="dashboard.php" class="btn btn-outline-secondary btn-custom">
                            <i class="bi bi-arrow-left"></i> Hủy
                        </a>
                    </div>
                </form>
            <?php elseif (!isset($_POST['import_notebook'])): ?>
                <div class="text-center py-4">
                    <p class="text-muted">Chưa có sổ tay nào được chọn.</p>
                    <a href="dashboard.php" class="btn btn-primary"><i class="bi bi-arrow-left"></i> Quay lại</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>