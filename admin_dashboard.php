<?php
session_start();
require 'db.php';

// Kiểm tra quyền admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Hàm ghi log hệ thống
function writeSystemLog($message, $type = 'info') {
    $logDir = 'assets/logs/';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $logFile = $logDir . 'system_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$type] $message" . PHP_EOL;

    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

// Xử lý các hành động quản lý người dùng
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Xử lý sao lưu dữ liệu
    if ($action === 'create_backup') {
        try {
            $backupDir = 'assets/backups/';
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }

            $timestamp = date('Y-m-d_H-i-s');
            $backupFile = $backupDir . 'backup_' . $timestamp . '.sql';

            // Lấy danh sách tables
            $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

            $sql = "-- Germanly Database Backup\n";
            $sql .= "-- Created: " . date('Y-m-d H:i:s') . "\n\n";

            foreach ($tables as $table) {
                // Lấy cấu trúc bảng
                $createTable = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
                $sql .= $createTable['Create Table'] . ";\n\n";

                // Lấy dữ liệu bảng
                $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    foreach ($rows as $row) {
                        $values = array_map(function($value) use ($pdo) {
                            return $value === null ? 'NULL' : $pdo->quote($value);
                        }, $row);
                        $sql .= "INSERT INTO `$table` VALUES (" . implode(', ', $values) . ");\n";
                    }
                    $sql .= "\n";
                }
            }

            file_put_contents($backupFile, $sql);
            $message = 'Sao lưu dữ liệu thành công! File: ' . basename($backupFile);

            // Ghi log
            writeSystemLog('Backup created: ' . basename($backupFile), 'info');

        } catch (Exception $e) {
            $error = 'Lỗi khi tạo sao lưu: ' . $e->getMessage();
            writeSystemLog('Backup failed: ' . $e->getMessage(), 'error');
        }
    }

    // Xử lý thông báo hệ thống
    if ($action === 'send_announcement') {
        $title = trim($_POST['announcement_title'] ?? '');
        $content = trim($_POST['announcement_content'] ?? '');

        if ($title && $content) {
            try {
                // Lưu thông báo vào database (giả sử có bảng announcements)
                $stmt = $pdo->prepare('INSERT INTO announcements (title, content, created_by, created_at) VALUES (?, ?, ?, NOW())');
                $stmt->execute([$title, $content, $user_id]);

                $message = 'Thông báo đã được gửi thành công!';
                writeSystemLog('Announcement sent: ' . $title, 'info');

            } catch (Exception $e) {
                $error = 'Lỗi khi gửi thông báo: ' . $e->getMessage();
                writeSystemLog('Announcement failed: ' . $e->getMessage(), 'error');
            }
        } else {
            $error = 'Vui lòng nhập đầy đủ tiêu đề và nội dung!';
        }
    }

    // Thay đổi quyền người dùng
    if ($action === 'change_role') {
        $target_user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        $new_role = $_POST['new_role'] ?? '';

        if ($target_user_id && ($new_role === 'admin' || $new_role === 'user')) {
            $stmt = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?');
            if ($stmt->execute([$new_role, $target_user_id])) {
                $message = 'Đã cập nhật quyền người dùng!';
            } else {
                $error = 'Cập nhật quyền thất bại.';
            }
        } else {
            $error = 'Dữ liệu không hợp lệ!';
        }
    }

    // Xóa người dùng
    if ($action === 'delete_user') {
        $target_user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

        if ($target_user_id === false || $target_user_id === null) {
            $error = 'ID người dùng không hợp lệ!';
        } elseif ($target_user_id === $user_id) {
            $error = 'Không thể xóa tài khoản của chính bạn!';
        } else {
            try {
                $pdo->beginTransaction();

                // Xóa dữ liệu liên quan theo thứ tự phụ thuộc khóa ngoại
                $pdo->prepare('DELETE FROM learning_status WHERE user_id = ?')->execute([$target_user_id]);
                // Lấy danh sách notebook_id để xóa vocabularies liên quan (có thể tối ưu hơn bằng JOIN trong DELETE)
                $notebookStmt = $pdo->prepare('SELECT id FROM notebooks WHERE user_id = ?');
                $notebookStmt->execute([$target_user_id]);
                $notebookIds = $notebookStmt->fetchAll(PDO::FETCH_COLUMN, 0); // Lấy cột đầu tiên (id)

                if (!empty($notebookIds)) {
                    // Sử dụng placeholder động cho IN clause
                    $placeholders = str_repeat('?,', count($notebookIds) - 1) . '?';
                    $pdo->prepare("DELETE FROM vocabularies WHERE notebook_id IN ($placeholders)")->execute($notebookIds);
                }

                $pdo->prepare('DELETE FROM notebooks WHERE user_id = ?')->execute([$target_user_id]);
                $pdo->prepare('DELETE FROM notebook_groups WHERE user_id = ?')->execute([$target_user_id]);
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$target_user_id]);

                $pdo->commit();
                $message = 'Đã xóa người dùng và dữ liệu liên quan!';
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Lỗi khi xóa người dùng ID $target_user_id: " . $e->getMessage());
                $error = 'Đã xảy ra lỗi khi xóa người dùng.';
            }
        }
    }

    // Xóa sổ tay
    if ($action === 'delete_notebook') {
        $notebook_id = filter_input(INPUT_POST, 'notebook_id', FILTER_VALIDATE_INT);

        if ($notebook_id === false || $notebook_id === null) {
            $error = 'ID sổ tay không hợp lệ!';
        } else {
            try {
                $pdo->beginTransaction();

                // Xóa dữ liệu liên quan theo thứ tự phụ thuộc khóa ngoại
                $pdo->prepare('DELETE FROM learning_status WHERE notebook_id = ?')->execute([$notebook_id]);
                $pdo->prepare('DELETE FROM vocabularies WHERE notebook_id = ?')->execute([$notebook_id]);
                $pdo->prepare('DELETE FROM notebooks WHERE id = ?')->execute([$notebook_id]);

                $pdo->commit();
                $message = 'Đã xóa sổ tay và dữ liệu liên quan!';
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Lỗi khi xóa sổ tay ID $notebook_id: " . $e->getMessage());
                $error = 'Đã xảy ra lỗi khi xóa sổ tay.';
            }
        }
    }

    // Chỉnh sửa sổ tay
    if ($action === 'edit_notebook') {
        $notebook_id = filter_input(INPUT_POST, 'notebook_id', FILTER_VALIDATE_INT);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($notebook_id && $title) {
            $stmt = $pdo->prepare('UPDATE notebooks SET title = ?, description = ? WHERE id = ?');
            if ($stmt->execute([$title, $description, $notebook_id])) {
                $message = 'Đã cập nhật sổ tay!';
            } else {
                $error = 'Cập nhật sổ tay thất bại.';
            }
        } else {
            $error = 'Dữ liệu không hợp lệ!';
        }
    }
}

// --- Truy vấn dữ liệu cho Dashboard ---
// Tổng quan thống kê (có thể cache nếu dữ liệu lớn)
$statsStmt = $pdo->query('
    SELECT 
        (SELECT COUNT(*) FROM users) as total_users,
        (SELECT COUNT(*) FROM notebooks) as total_notebooks,
        (SELECT COUNT(*) FROM vocabularies) as total_vocabularies,
        (SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as recent_users,
        (SELECT COUNT(*) FROM vocabularies WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as recent_vocabularies
');
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Top 5 người dùng tích cực (có thể giới hạn thời gian nếu cần)
$topUsersStmt = $pdo->query('
    SELECT u.email, COUNT(v.id) as vocab_count 
    FROM users u 
    JOIN notebooks n ON u.id = n.user_id 
    JOIN vocabularies v ON n.id = v.notebook_id 
    GROUP BY u.id, u.email
    ORDER BY vocab_count DESC 
    LIMIT 5
');
$top_users = $topUsersStmt->fetchAll(PDO::FETCH_ASSOC);

// --- Truy vấn dữ liệu cho Quản lý người dùng ---
$usersStmt = $pdo->query('SELECT id, email, role, created_at FROM users ORDER BY created_at DESC');
$users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

// --- Truy vấn dữ liệu cho Quản lý nội dung ---
$allNotebooksStmt = $pdo->query('
    SELECT n.id, n.title, n.description, n.created_at, u.email as user_email, 
           COALESCE(vocab_counts.vocab_count, 0) as vocab_count 
    FROM notebooks n 
    JOIN users u ON n.user_id = u.id 
    LEFT JOIN (
        SELECT notebook_id, COUNT(*) as vocab_count 
        FROM vocabularies 
        GROUP BY notebook_id
    ) vocab_counts ON n.id = vocab_counts.notebook_id
    ORDER BY n.created_at DESC
');
$all_notebooks = $allNotebooksStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - Germanly</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="assets/css/admin_dashboard.css" rel="stylesheet">
</head>
<body>
    <!-- Mobile Toggle Button -->
    <button class="mobile-toggle" id="mobileToggle">
        <i class="bi bi-list"></i>
    </button>

    <!-- Sidebar -->
    <div class="admin-sidebar" id="sidebar">
        <div class="sidebar-brand">
            <i class="bi bi-lightning-charge sidebar-brand-icon"></i>
            <span class="sidebar-brand-text">Germanly</span>
        </div>
        
        <nav class="sidebar-nav">
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link active" href="#dashboard" data-tab="dashboard">
                        <i class="bi bi-grid"></i>
                        <span>Tổng quan</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#users" data-tab="users">
                        <i class="bi bi-people"></i>
                        <span>Quản lý người dùng</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#content" data-tab="content">
                        <i class="bi bi-journal-text"></i>
                        <span>Quản lý nội dung</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#system" data-tab="system">
                        <i class="bi bi-gear"></i>
                        <span>Hệ thống</span>
                    </a>
                </li>

            </ul>
            
            <div class="sidebar-divider"></div>
            
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link" href="home.php">
                        <i class="bi bi-house"></i>
                        <span>Về trang chủ</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="logout.php">
                        <i class="bi bi-box-arrow-right"></i>
                        <span>Đăng xuất</span>
                    </a>
                </li>
            </ul>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="admin-content" id="mainContent">
        <!-- Header -->
        <header class="admin-header">
            <div class="d-flex justify-content-between align-items-center">
                <h1 class="header-title">Admin Dashboard</h1>
                <div class="header-meta">
                    <span class="header-date"><?= date('d/m/Y') ?></span>
                    <span class="admin-badge">Admin</span>
                </div>
            </div>
        </header>

        <!-- Alerts -->
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible" role="alert">
                <i class="bi bi-check-circle"></i>
                <span><?= htmlspecialchars($message) ?></span>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible" role="alert">
                <i class="bi bi-exclamation-circle"></i>
                <span><?= htmlspecialchars($error) ?></span>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Tab Content -->
        <div class="tab-content active" id="dashboard">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-content">
                        <div class="stat-info">
                            <h3><?= (int)($stats['total_users'] ?? 0) ?></h3>
                            <p>Tổng số người dùng</p>
                        </div>
                        <div class="stat-icon">
                            <i class="bi bi-people"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-content">
                        <div class="stat-info">
                            <h3><?= (int)($stats['total_notebooks'] ?? 0) ?></h3>
                            <p>Tổng số sổ tay</p>
                        </div>
                        <div class="stat-icon">
                            <i class="bi bi-journal-bookmark"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-content">
                        <div class="stat-info">
                            <h3><?= (int)($stats['total_vocabularies'] ?? 0) ?></h3>
                            <p>Tổng số từ vựng</p>
                        </div>
                        <div class="stat-icon">
                            <i class="bi bi-card-text"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-lg-6 mb-4">
                    <div class="content-card">
                        <div class="card-header">
                            <h6 class="card-title">Người dùng mới (7 ngày qua)</h6>
                        </div>
                        <div class="card-body">
                            <div class="stat-content">
                                <div class="stat-info">
                                    <h3><?= (int)($stats['recent_users'] ?? 0) ?></h3>
                                </div>
                                <div class="stat-icon">
                                    <i class="bi bi-person-plus"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-6 mb-4">
                    <div class="content-card">
                        <div class="card-header">
                            <h6 class="card-title">Từ vựng mới (7 ngày qua)</h6>
                        </div>
                        <div class="card-body">
                            <div class="stat-content">
                                <div class="stat-info">
                                    <h3><?= (int)($stats['recent_vocabularies'] ?? 0) ?></h3>
                                </div>
                                <div class="stat-icon">
                                    <i class="bi bi-plus-circle"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="content-card">
                <div class="card-header">
                    <h6 class="card-title">Top 5 người dùng tích cực</h6>
                </div>
                <div class="card-body">
                    <?php if (empty($top_users)): ?>
                        <div class="empty-state">
                            <i class="bi bi-inbox"></i>
                            <p>Chưa có dữ liệu.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-wrapper">
                            <table class="custom-table">
                                <thead>
                                    <tr>
                                        <th>Email</th>
                                        <th>Số từ vựng</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($top_users as $user): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($user['email']) ?></td>
                                        <td><span class="vocab-count-badge"><?= (int)$user['vocab_count'] ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Users Tab -->
        <div class="tab-content" id="users">
            <div class="content-card">
                <div class="card-header">
                    <h6 class="card-title">Quản lý người dùng</h6>
                </div>
                <div class="card-body">
                    <?php if (empty($users)): ?>
                        <div class="empty-state">
                            <i class="bi bi-people"></i>
                            <p>Chưa có người dùng nào.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-wrapper">
                            <table class="custom-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Email</th>
                                        <th>Quyền</th>
                                        <th>Ngày tạo</th>
                                        <th>Hành động</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><?= (int)$user['id'] ?></td>
                                        <td><?= htmlspecialchars($user['email']) ?></td>
                                        <td>
                                            <span class="role-badge <?= $user['role'] === 'admin' ? 'role-admin' : 'role-user' ?>">
                                                <?= $user['role'] === 'admin' ? 'Admin' : 'User' ?>
                                            </span>
                                        </td>
                                        <td><?= date('d/m/Y', strtotime($user['created_at'])) ?></td>
                                        <td>
                                            <div class="btn-group">
                                                <button type="button" class="action-btn btn-primary-outline"
                                                        data-bs-toggle="modal" data-bs-target="#changeRoleModal"
                                                        data-user-id="<?= (int)$user['id'] ?>"
                                                        data-user-email="<?= htmlspecialchars($user['email']) ?>"
                                                        data-user-role="<?= htmlspecialchars($user['role']) ?>">
                                                    <i class="bi bi-shield"></i> Đổi quyền
                                                </button>
                                                <?php if ((int)$user['id'] !== $user_id): ?>
                                                <button type="button" class="action-btn btn-danger-outline"
                                                        data-bs-toggle="modal" data-bs-target="#deleteUserModal"
                                                        data-user-id="<?= (int)$user['id'] ?>"
                                                        data-user-email="<?= htmlspecialchars($user['email']) ?>">
                                                    <i class="bi bi-trash"></i> Xóa
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Content Tab -->
        <div class="tab-content" id="content">
            <div class="content-card">
                <div class="card-header">
                    <h6 class="card-title">Danh sách tất cả các sổ tay</h6>
                </div>
                <div class="card-body">
                    <?php if (empty($all_notebooks)): ?>
                        <div class="empty-state">
                            <i class="bi bi-journal-text"></i>
                            <p>Chưa có sổ tay nào.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-wrapper">
                            <table class="custom-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Tiêu đề</th>
                                        <th>Mô tả</th>
                                        <th>Người tạo</th>
                                        <th>Số từ vựng</th>
                                        <th>Ngày tạo</th>
                                        <th>Hành động</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_notebooks as $notebook): ?>
                                    <tr>
                                        <td><?= (int)$notebook['id'] ?></td>
                                        <td>
                                            <div class="notebook-title" title="<?= htmlspecialchars($notebook['title']) ?>">
                                                <?= htmlspecialchars($notebook['title']) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="notebook-description" title="<?= htmlspecialchars($notebook['description'] ?? '') ?>">
                                                <?= htmlspecialchars(substr($notebook['description'] ?? '', 0, 50) . (strlen($notebook['description'] ?? '') > 50 ? '...' : '')) ?>
                                            </div>
                                        </td>
                                        <td><?= htmlspecialchars($notebook['user_email']) ?></td>
                                        <td><span class="vocab-count-badge"><?= (int)$notebook['vocab_count'] ?></span></td>
                                        <td><?= date('d/m/Y', strtotime($notebook['created_at'])) ?></td>
                                        <td>
                                            <div class="btn-group">
                                                <button type="button" class="action-btn btn-primary-outline"
                                                        data-bs-toggle="modal" data-bs-target="#editNotebookModal"
                                                        data-notebook-id="<?= (int)$notebook['id'] ?>"
                                                        data-notebook-title="<?= htmlspecialchars($notebook['title']) ?>"
                                                        data-notebook-description="<?= htmlspecialchars($notebook['description'] ?? '') ?>">
                                                    <i class="bi bi-pencil"></i> Sửa
                                                </button>
                                                <button type="button" class="action-btn btn-danger-outline"
                                                        data-bs-toggle="modal" data-bs-target="#deleteNotebookModal"
                                                        data-notebook-id="<?= (int)$notebook['id'] ?>"
                                                        data-notebook-title="<?= htmlspecialchars($notebook['title']) ?>">
                                                    <i class="bi bi-trash"></i> Xóa
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- System Tab -->
        <div class="tab-content" id="system">
            <div class="row">
                <div class="col-lg-6 mb-4">
                    <div class="content-card">
                        <div class="card-header">
                            <h6 class="card-title">Sao lưu dữ liệu</h6>
                        </div>
                        <div class="card-body">
                            <p class="text-muted mb-3">Tạo bản sao lưu cơ sở dữ liệu và file hệ thống</p>
                            <form method="post" action="" style="display: inline;">
                                <input type="hidden" name="action" value="create_backup">
                                <button type="submit" class="action-btn btn-primary-solid">
                                    <i class="bi bi-download"></i> Tạo sao lưu
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6 mb-4">
                    <div class="content-card">
                        <div class="card-header">
                            <h6 class="card-title">Thông tin hệ thống</h6>
                        </div>
                        <div class="card-body">
                            <div class="system-info">
                                <div class="info-item">
                                    <span class="info-label">Phiên bản PHP:</span>
                                    <span class="info-value"><?= phpversion() ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Hệ điều hành:</span>
                                    <span class="info-value"><?= php_uname('s') . ' ' . php_uname('r') ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Thời gian hoạt động:</span>
                                    <span class="info-value" id="uptime">Đang tải...</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-6 mb-4">
                    <div class="content-card">
                        <div class="card-header">
                            <h6 class="card-title">Thông báo hệ thống</h6>
                        </div>
                        <div class="card-body">
                            <form method="post" action="">
                                <input type="hidden" name="action" value="send_announcement">
                                <div class="mb-3">
                                    <label class="form-label">Tiêu đề thông báo</label>
                                    <input type="text" class="form-control" name="announcement_title" placeholder="Nhập tiêu đề...">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Nội dung thông báo</label>
                                    <textarea class="form-control" name="announcement_content" rows="3" placeholder="Nhập nội dung thông báo..."></textarea>
                                </div>
                                <button type="submit" class="action-btn btn-success-solid">
                                    <i class="bi bi-send"></i> Gửi thông báo
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6 mb-4">
                    <div class="content-card">
                        <div class="card-header">
                            <h6 class="card-title">Nhật ký hệ thống (gần đây)</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-wrapper" style="max-height: 300px; overflow-y: auto;">
                                <table class="custom-table">
                                    <thead>
                                        <tr>
                                            <th>Thời gian</th>
                                            <th>Loại</th>
                                            <th>Nội dung</th>
                                        </tr>
                                    </thead>
                                    <tbody id="systemLogs">
                                        <tr>
                                            <td colspan="3" class="text-center text-muted">Đang tải nhật ký...</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- Change Role Modal -->
    <div class="modal fade" id="changeRoleModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Thay đổi quyền người dùng</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="change_role">
                        <input type="hidden" name="user_id" id="changeRoleUserId">
                        <p>Bạn đang thay đổi quyền cho người dùng: <strong id="changeRoleUserEmail"></strong></p>
                        <div class="mb-3">
                            <label class="form-label">Quyền mới</label>
                            <select name="new_role" class="form-select" id="newRoleSelect">
                                <option value="user">User</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="action-btn btn-secondary-solid" data-bs-dismiss="modal">Hủy</button>
                        <button type="submit" class="action-btn btn-primary-solid">Lưu thay đổi</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete User Modal -->
    <div class="modal fade" id="deleteUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Xóa người dùng</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="user_id" id="deleteUserId">
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle"></i>
                            <span><strong>Cảnh báo:</strong> Hành động này không thể hoàn tác!</span>
                        </div>
                        <p>Bạn có chắc chắn muốn xóa người dùng: <strong id="deleteUserEmail"></strong>?</p>
                        <p>Tất cả dữ liệu liên quan đến người dùng này sẽ bị xóa vĩnh viễn, bao gồm:</p>
                        <ul>
                            <li>Tài khoản người dùng</li>
                            <li>Tất cả sổ tay và nhóm sổ tay</li>
                            <li>Tất cả từ vựng và tiến trình học tập</li>
                        </ul>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="action-btn btn-secondary-solid" data-bs-dismiss="modal">Hủy</button>
                        <button type="submit" class="action-btn btn-danger-solid">Xác nhận xóa</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Notebook Modal -->
    <div class="modal fade" id="editNotebookModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Chỉnh sửa sổ tay</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_notebook">
                        <input type="hidden" name="notebook_id" id="editNotebookId">
                        <div class="mb-3">
                            <label class="form-label">Tiêu đề</label>
                            <input type="text" class="form-control" name="title" id="editNotebookTitle" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Mô tả</label>
                            <textarea class="form-control" name="description" id="editNotebookDescription" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="action-btn btn-secondary-solid" data-bs-dismiss="modal">Hủy</button>
                        <button type="submit" class="action-btn btn-primary-solid">Lưu thay đổi</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Notebook Modal -->
    <div class="modal fade" id="deleteNotebookModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Xóa sổ tay</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete_notebook">
                        <input type="hidden" name="notebook_id" id="deleteNotebookId">
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle"></i>
                            <span><strong>Cảnh báo:</strong> Hành động này không thể hoàn tác!</span>
                        </div>
                        <p>Bạn có chắc chắn muốn xóa sổ tay: <strong id="deleteNotebookTitle"></strong>?</p>
                        <p>Tất cả dữ liệu liên quan sẽ bị xóa vĩnh viễn, bao gồm:</p>
                        <ul>
                            <li>Tất cả từ vựng trong sổ tay</li>
                            <li>Tiến trình học tập của người dùng</li>
                        </ul>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="action-btn btn-secondary-solid" data-bs-dismiss="modal">Hủy</button>
                        <button type="submit" class="action-btn btn-danger-solid">Xác nhận xóa</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Tab Navigation
        document.addEventListener('DOMContentLoaded', function() {
            const navLinks = document.querySelectorAll('[data-tab]');
            const tabContents = document.querySelectorAll('.tab-content');
            
            navLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    // Remove active classes
                    navLinks.forEach(nl => nl.classList.remove('active'));
                    tabContents.forEach(tc => tc.classList.remove('active'));
                    
                    // Add active class to clicked link
                    this.classList.add('active');
                    
                    // Show corresponding tab content
                    const targetTab = document.getElementById(this.getAttribute('data-tab'));
                    if (targetTab) {
                        targetTab.classList.add('active');
                    }
                });
            });
        });

        // Mobile Sidebar Toggle
        const mobileToggle = document.getElementById('mobileToggle');
        const sidebar = document.getElementById('sidebar');
        
        mobileToggle.addEventListener('click', function() {
            sidebar.classList.toggle('show');
        });
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 1024) {
                if (!sidebar.contains(e.target) && !mobileToggle.contains(e.target)) {
                    sidebar.classList.remove('show');
                }
            }
        });

        // Change Role Modal
        document.querySelectorAll('[data-bs-target="#changeRoleModal"]').forEach(button => {
            button.addEventListener('click', function() {
                const userId = this.getAttribute('data-user-id');
                const userEmail = this.getAttribute('data-user-email');
                const userRole = this.getAttribute('data-user-role');
                document.getElementById('changeRoleUserId').value = userId;
                document.getElementById('changeRoleUserEmail').textContent = userEmail;
                document.getElementById('newRoleSelect').value = userRole;
            });
        });

        // Delete User Modal
        document.querySelectorAll('[data-bs-target="#deleteUserModal"]').forEach(button => {
            button.addEventListener('click', function() {
                const userId = this.getAttribute('data-user-id');
                const userEmail = this.getAttribute('data-user-email');
                document.getElementById('deleteUserId').value = userId;
                document.getElementById('deleteUserEmail').textContent = userEmail;
            });
        });

        // Edit Notebook Modal
        document.querySelectorAll('[data-bs-target="#editNotebookModal"]').forEach(button => {
            button.addEventListener('click', function() {
                const notebookId = this.getAttribute('data-notebook-id');
                const notebookTitle = this.getAttribute('data-notebook-title');
                const notebookDescription = this.getAttribute('data-notebook-description');
                document.getElementById('editNotebookId').value = notebookId;
                document.getElementById('editNotebookTitle').value = notebookTitle;
                document.getElementById('editNotebookDescription').value = notebookDescription;
            });
        });

        // Delete Notebook Modal
        document.querySelectorAll('[data-bs-target="#deleteNotebookModal"]').forEach(button => {
            button.addEventListener('click', function() {
                const notebookId = this.getAttribute('data-notebook-id');
                const notebookTitle = this.getAttribute('data-notebook-title');
                document.getElementById('deleteNotebookId').value = notebookId;
                document.getElementById('deleteNotebookTitle').textContent = notebookTitle;
            });
        });

        // Load system uptime
        function loadSystemInfo() {
            const uptimeElement = document.getElementById('uptime');
            uptimeElement.textContent = 'Đang chạy';
        }

        // Load system logs from file
        function loadSystemLogs() {
            const logsContainer = document.getElementById('systemLogs');

            fetch('assets/logs/system_<?= date("Y-m-d") ?>.log')
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Log file not found');
                    }
                    return response.text();
                })
                .then(data => {
                    const logs = data.trim().split('\n').slice(-10).reverse(); // Get last 10 logs
                    const logRows = logs.map(line => {
                        if (!line.trim()) return '';
                        const parts = line.split('] ');
                        if (parts.length < 3) return '';

                        const timestamp = parts[0].replace('[', '');
                        const type = parts[1].replace('[', '').replace(']', '');
                        const content = parts.slice(2).join('] ');

                        return `
                            <tr>
                                <td>${timestamp}</td>
                                <td><span class="badge bg-${type === 'error' ? 'danger' : type === 'warning' ? 'warning' : 'info'}">${type}</span></td>
                                <td>${content}</td>
                            </tr>
                        `;
                    }).join('');

                    logsContainer.innerHTML = logRows || '<tr><td colspan="3" class="text-center text-muted">Chưa có nhật ký nào.</td></tr>';
                })
                .catch(error => {
                    logsContainer.innerHTML = '<tr><td colspan="3" class="text-center text-muted">Không thể tải nhật ký hệ thống.</td></tr>';
                });
        }

        // Initialize system functions
        document.addEventListener('DOMContentLoaded', function() {
            loadSystemInfo();
            loadSystemLogs();
        });

        // Auto-hide alerts
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    </script>
</body>
</html>
