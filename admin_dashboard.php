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

// Load site settings
$configFile = 'assets/config/site_settings.json';
if (!file_exists($configFile)) {
    mkdir(dirname($configFile), 0755, true);
    $defaultSettings = [
        'site' => [
            'name' => 'GERMANLY',
            'description' => 'Ứng dụng học tiếng Đức với Flashcard',
            'maintenance_mode' => false,
            'registration_enabled' => true,
            'max_users' => 10000,
            'max_notebooks_per_user' => 100
        ],
        'features' => [
            'ai_tools_enabled' => true,
            'public_notebooks_enabled' => true,
            'excel_import_enabled' => true,
            'gender_practice_enabled' => true,
            'quiz_mode_enabled' => true
        ],
        'notifications' => [
            'email_enabled' => false,
            'welcome_message' => 'Chào mừng bạn đến với GERMANLY!'
        ],
        'security' => [
            'session_timeout' => 3600,
            'max_login_attempts' => 5,
            'password_min_length' => 6
        ],
        'analytics' => [
            'track_user_activity' => true,
            'anonymous_statistics' => true
        ]
    ];
    file_put_contents($configFile, json_encode($defaultSettings, JSON_PRETTY_PRINT));
}
$siteSettings = json_decode(file_get_contents($configFile), true);

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

// Hàm lưu site settings
function saveSiteSettings($settings) {
    $configFile = 'assets/config/site_settings.json';
    file_put_contents($configFile, json_encode($settings, JSON_PRETTY_PRINT));
    writeSystemLog('Site settings updated', 'info');
}

// Hàm xóa cache
function clearCache() {
    $cacheDir = 'assets/cache/';
    if (is_dir($cacheDir)) {
        $files = glob($cacheDir . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        return count($files);
    }
    return 0;
}

// Hàm dọn dẹp logs cũ
function cleanupOldLogs($days = 30) {
    $logDir = 'assets/logs/';
    if (!is_dir($logDir)) return 0;
    
    $count = 0;
    $files = glob($logDir . '*.log');
    $cutoff = time() - ($days * 24 * 60 * 60);
    
    foreach ($files as $file) {
        if (filemtime($file) < $cutoff) {
            unlink($file);
            $count++;
        }
    }
    return $count;
}

// Hàm lấy thống kê chi tiết
function getDetailedStats($pdo) {
    $stats = [];
    
    // Thống kê theo thời gian
    $stats['users_today'] = $pdo->query('SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()')->fetchColumn();
    $stats['users_this_week'] = $pdo->query('SELECT COUNT(*) FROM users WHERE YEARWEEK(created_at) = YEARWEEK(NOW())')->fetchColumn();
    $stats['users_this_month'] = $pdo->query('SELECT COUNT(*) FROM users WHERE YEAR(created_at) = YEAR(NOW()) AND MONTH(created_at) = MONTH(NOW())')->fetchColumn();
    
    $stats['notebooks_today'] = $pdo->query('SELECT COUNT(*) FROM notebooks WHERE DATE(created_at) = CURDATE()')->fetchColumn();
    $stats['notebooks_this_week'] = $pdo->query('SELECT COUNT(*) FROM notebooks WHERE YEARWEEK(created_at) = YEARWEEK(NOW())')->fetchColumn();
    
    $stats['vocab_today'] = $pdo->query('SELECT COUNT(*) FROM vocabularies WHERE DATE(created_at) = CURDATE()')->fetchColumn();
    $stats['vocab_this_week'] = $pdo->query('SELECT COUNT(*) FROM vocabularies WHERE YEARWEEK(created_at) = YEARWEEK(NOW())')->fetchColumn();
    
    // Thống kê usage
    $stats['avg_notebooks_per_user'] = $pdo->query('SELECT AVG(nb_count) FROM (SELECT COUNT(*) as nb_count FROM notebooks GROUP BY user_id) as counts')->fetchColumn() ?: 0;
    $stats['avg_vocab_per_notebook'] = $pdo->query('SELECT AVG(v_count) FROM (SELECT COUNT(*) as v_count FROM vocabularies GROUP BY notebook_id) as counts')->fetchColumn() ?: 0;
    
    return $stats;
}

// Include helper functions
require_once 'includes/admin_helper.php';

// Xử lý các hành động quản lý người dùng
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Cập nhật cài đặt
    if ($action === 'update_settings') {
        try {
            // Cập nhật site settings
            $siteSettings['site']['name'] = trim($_POST['site_name'] ?? $siteSettings['site']['name']);
            $siteSettings['site']['description'] = trim($_POST['site_description'] ?? $siteSettings['site']['description']);
            $siteSettings['site']['maintenance_mode'] = isset($_POST['maintenance_mode']);
            $siteSettings['site']['registration_enabled'] = isset($_POST['registration_enabled']);
            $siteSettings['site']['max_users'] = (int)($_POST['max_users'] ?? $siteSettings['site']['max_users']);
            $siteSettings['site']['max_notebooks_per_user'] = (int)($_POST['max_notebooks_per_user'] ?? $siteSettings['site']['max_notebooks_per_user']);
            
            // Features
            $siteSettings['features']['ai_tools_enabled'] = isset($_POST['ai_tools_enabled']);
            $siteSettings['features']['public_notebooks_enabled'] = isset($_POST['public_notebooks_enabled']);
            $siteSettings['features']['excel_import_enabled'] = isset($_POST['excel_import_enabled']);
            $siteSettings['features']['gender_practice_enabled'] = isset($_POST['gender_practice_enabled']);
            $siteSettings['features']['quiz_mode_enabled'] = isset($_POST['quiz_mode_enabled']);
            
            // Notifications
            $siteSettings['notifications']['email_enabled'] = isset($_POST['email_enabled']);
            $siteSettings['notifications']['welcome_message'] = trim($_POST['welcome_message'] ?? $siteSettings['notifications']['welcome_message']);
            
            // Security
            $siteSettings['security']['session_timeout'] = (int)($_POST['session_timeout'] ?? $siteSettings['security']['session_timeout']);
            $siteSettings['security']['max_login_attempts'] = (int)($_POST['max_login_attempts'] ?? $siteSettings['security']['max_login_attempts']);
            $siteSettings['security']['password_min_length'] = (int)($_POST['password_min_length'] ?? $siteSettings['security']['password_min_length']);
            
            saveSiteSettings($siteSettings);
            $message = 'Đã lưu cài đặt thành công!';
        } catch (Exception $e) {
            $error = 'Lỗi khi lưu cài đặt: ' . $e->getMessage();
        }
    }
    
    // Xóa cache
    if ($action === 'clear_cache') {
        $count = clearCache();
        $message = "Đã xóa $count file cache!";
        writeSystemLog("Cache cleared: $count files", 'info');
    }
    
    // Dọn dẹp logs cũ
    if ($action === 'cleanup_logs') {
        $days = (int)($_POST['days'] ?? 30);
        $count = cleanupOldLogs($days);
        $message = "Đã xóa $count file log cũ hơn $days ngày!";
        writeSystemLog("Old logs cleaned up: $count files", 'info');
    }
    
    // Optimize database
    if ($action === 'optimize_db') {
        $result = optimizeDatabase($pdo);
        if ($result) {
            $message = 'Đã tối ưu hóa ' . count($result) . ' bảng trong database!';
        } else {
            $error = 'Không thể tối ưu hóa database!';
        }
    }
    
    // Export users to CSV
    if ($action === 'export_users') {
        $usersData = $pdo->query('SELECT id, email, role, created_at FROM users ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
        $data = array_map(function($user) {
            return [
                $user['id'],
                $user['email'],
                $user['role'],
                $user['created_at']
            ];
        }, $usersData);
        exportToCSV($data, 'users_export_' . date('Y-m-d') . '.csv', ['ID', 'Email', 'Role', 'Created At']);
    }
    
    // Export notebooks to CSV
    if ($action === 'export_notebooks') {
        $notebooksData = $pdo->query('
            SELECT n.id, n.title, n.description, u.email, n.created_at, 
                   COUNT(v.id) as vocab_count
            FROM notebooks n
            JOIN users u ON n.user_id = u.id
            LEFT JOIN vocabularies v ON n.id = v.notebook_id
            GROUP BY n.id
            ORDER BY n.created_at DESC
        ')->fetchAll(PDO::FETCH_ASSOC);
        
        $data = array_map(function($nb) {
            return [
                $nb['id'],
                $nb['title'],
                $nb['description'],
                $nb['email'],
                $nb['vocab_count'],
                $nb['created_at']
            ];
        }, $notebooksData);
        exportToCSV($data, 'notebooks_export_' . date('Y-m-d') . '.csv', ['ID', 'Title', 'Description', 'Owner', 'Vocab Count', 'Created At']);
    }
    
    // Download backup
    if ($action === 'download_backup') {
        $filename = $_POST['backup_file'] ?? '';
        $filepath = 'assets/backups/' . basename($filename);
        
        if (file_exists($filepath)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
            header('Content-Length: ' . filesize($filepath));
            readfile($filepath);
            exit;
        } else {
            $error = 'File backup không tồn tại!';
        }
    }
    
    // Delete backup
    if ($action === 'delete_backup') {
        $filename = $_POST['backup_file'] ?? '';
        $filepath = 'assets/backups/' . basename($filename);
        
        if (file_exists($filepath)) {
            unlink($filepath);
            $message = 'Đã xóa file backup: ' . basename($filename);
            writeSystemLog('Backup deleted: ' . basename($filename), 'info');
        } else {
            $error = 'File backup không tồn tại!';
        }
    }

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

// Lấy thống kê chi tiết
$detailedStats = getDetailedStats($pdo);

// Lấy activity chart data
$activityData = getActivityChartData($pdo, 7);

// Lấy system info
$systemInfo = getSystemInfo();

// Lấy danh sách backups
$backupsList = getBackups();

// Lấy database size
$dbSize = getDatabaseSize($pdo);

// Lấy directory sizes
$backupDirSize = getDirectorySize('assets/backups/');
$logDirSize = getDirectorySize('assets/logs/');

// Security check
$securityIssues = securityCheck();

// User activity summary
$userActivity = getUserActivitySummary($pdo);

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
                <li class="nav-item">
                    <a class="nav-link" href="#analytics" data-tab="analytics">
                        <i class="bi bi-graph-up"></i>
                        <span>Thống kê</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#settings" data-tab="settings">
                        <i class="bi bi-sliders"></i>
                        <span>Cài đặt</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#backup" data-tab="backup">
                        <i class="bi bi-shield-check"></i>
                        <span>Sao lưu & Bảo mật</span>
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

        <!-- Analytics Tab -->
        <div class="tab-content" id="analytics">
            <div class="row">
                <!-- Detailed Stats Cards -->
                <div class="col-md-4 mb-4">
                    <div class="content-card">
                        <div class="card-header">
                            <h6 class="card-title"><i class="bi bi-person-plus"></i> Người dùng mới</h6>
                        </div>
                        <div class="card-body">
                            <div class="stat-group">
                                <div class="stat-item">
                                    <span class="stat-label">Hôm nay:</span>
                                    <span class="stat-value text-primary"><?= (int)$detailedStats['users_today'] ?></span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label">Tuần này:</span>
                                    <span class="stat-value text-info"><?= (int)$detailedStats['users_this_week'] ?></span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label">Tháng này:</span>
                                    <span class="stat-value text-success"><?= (int)$detailedStats['users_this_month'] ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4 mb-4">
                    <div class="content-card">
                        <div class="card-header">
                            <h6 class="card-title"><i class="bi bi-journal-bookmark"></i> Sổ tay mới</h6>
                        </div>
                        <div class="card-body">
                            <div class="stat-group">
                                <div class="stat-item">
                                    <span class="stat-label">Hôm nay:</span>
                                    <span class="stat-value text-primary"><?= (int)$detailedStats['notebooks_today'] ?></span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label">Tuần này:</span>
                                    <span class="stat-value text-info"><?= (int)$detailedStats['notebooks_this_week'] ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4 mb-4">
                    <div class="content-card">
                        <div class="card-header">
                            <h6 class="card-title"><i class="bi bi-card-text"></i> Từ vựng mới</h6>
                        </div>
                        <div class="card-body">
                            <div class="stat-group">
                                <div class="stat-item">
                                    <span class="stat-label">Hôm nay:</span>
                                    <span class="stat-value text-primary"><?= (int)$detailedStats['vocab_today'] ?></span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label">Tuần này:</span>
                                    <span class="stat-value text-info"><?= (int)$detailedStats['vocab_this_week'] ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-8 mb-4">
                    <div class="content-card">
                        <div class="card-header">
                            <h6 class="card-title"><i class="bi bi-graph-up"></i> Hoạt động 7 ngày gần đây</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-wrapper">
                                <table class="custom-table">
                                    <thead>
                                        <tr>
                                            <th>Ngày</th>
                                            <th>Người dùng</th>
                                            <th>Sổ tay</th>
                                            <th>Từ vựng</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($activityData as $day): ?>
                                        <tr>
                                            <td><strong><?= $day['date'] ?></strong></td>
                                            <td><span class="badge bg-primary"><?= $day['users'] ?></span></td>
                                            <td><span class="badge bg-info"><?= $day['notebooks'] ?></span></td>
                                            <td><span class="badge bg-success"><?= $day['vocab'] ?></span></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4 mb-4">
                    <div class="content-card">
                        <div class="card-header">
                            <h6 class="card-title"><i class="bi bi-speedometer2"></i> Usage Metrics</h6>
                        </div>
                        <div class="card-body">
                            <div class="stat-group">
                                <div class="stat-item">
                                    <span class="stat-label">TB Sổ tay/User:</span>
                                    <span class="stat-value text-primary"><?= number_format($detailedStats['avg_notebooks_per_user'], 1) ?></span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label">TB Từ/Sổ tay:</span>
                                    <span class="stat-value text-info"><?= number_format($detailedStats['avg_vocab_per_notebook'], 1) ?></span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label">Active Users:</span>
                                    <span class="stat-value text-success"><?= (int)$userActivity['active_users'] ?></span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label">Inactive Users:</span>
                                    <span class="stat-value text-warning"><?= (int)$userActivity['inactive_users'] ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Settings Tab -->
        <div class="tab-content" id="settings">
            <form method="post" action="">
                <input type="hidden" name="action" value="update_settings">
                
                <div class="row">
                    <div class="col-lg-6 mb-4">
                        <div class="content-card">
                            <div class="card-header">
                                <h6 class="card-title"><i class="bi bi-gear"></i> Cài đặt chung</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label">Tên website</label>
                                    <input type="text" class="form-control" name="site_name" value="<?= htmlspecialchars($siteSettings['site']['name']) ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Mô tả</label>
                                    <textarea class="form-control" name="site_description" rows="2"><?= htmlspecialchars($siteSettings['site']['description']) ?></textarea>
                                </div>
                                <div class="mb-3 form-check">
                                    <input type="checkbox" class="form-check-input" name="maintenance_mode" id="maintenance_mode" <?= $siteSettings['site']['maintenance_mode'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="maintenance_mode">
                                        <strong>Chế độ bảo trì</strong> - Chỉ admin có thể truy cập
                                    </label>
                                </div>
                                <div class="mb-3 form-check">
                                    <input type="checkbox" class="form-check-input" name="registration_enabled" id="registration_enabled" <?= $siteSettings['site']['registration_enabled'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="registration_enabled">
                                        <strong>Cho phép đăng ký</strong> - Người dùng mới có thể đăng ký
                                    </label>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Số user tối đa</label>
                                        <input type="number" class="form-control" name="max_users" value="<?= (int)$siteSettings['site']['max_users'] ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Sổ tay tối đa/user</label>
                                        <input type="number" class="form-control" name="max_notebooks_per_user" value="<?= (int)$siteSettings['site']['max_notebooks_per_user'] ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6 mb-4">
                        <div class="content-card">
                            <div class="card-header">
                                <h6 class="card-title"><i class="bi bi-toggles"></i> Tính năng</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3 form-check">
                                    <input type="checkbox" class="form-check-input" name="ai_tools_enabled" id="ai_tools" <?= $siteSettings['features']['ai_tools_enabled'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="ai_tools">
                                        <strong>AI Tools</strong> - Công cụ AI hỗ trợ học tập
                                    </label>
                                </div>
                                <div class="mb-3 form-check">
                                    <input type="checkbox" class="form-check-input" name="public_notebooks_enabled" id="public_notebooks" <?= $siteSettings['features']['public_notebooks_enabled'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="public_notebooks">
                                        <strong>Public Notebooks</strong> - Chia sẻ sổ tay công khai
                                    </label>
                                </div>
                                <div class="mb-3 form-check">
                                    <input type="checkbox" class="form-check-input" name="excel_import_enabled" id="excel_import" <?= $siteSettings['features']['excel_import_enabled'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="excel_import">
                                        <strong>Excel Import</strong> - Import từ file Excel
                                    </label>
                                </div>
                                <div class="mb-3 form-check">
                                    <input type="checkbox" class="form-check-input" name="gender_practice_enabled" id="gender_practice" <?= $siteSettings['features']['gender_practice_enabled'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="gender_practice">
                                        <strong>Gender Practice</strong> - Luyện giống từ
                                    </label>
                                </div>
                                <div class="mb-3 form-check">
                                    <input type="checkbox" class="form-check-input" name="quiz_mode_enabled" id="quiz_mode" <?= $siteSettings['features']['quiz_mode_enabled'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="quiz_mode">
                                        <strong>Quiz Mode</strong> - Chế độ quiz/kiểm tra
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-6 mb-4">
                        <div class="content-card">
                            <div class="card-header">
                                <h6 class="card-title"><i class="bi bi-bell"></i> Thông báo</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3 form-check">
                                    <input type="checkbox" class="form-check-input" name="email_enabled" id="email_enabled" <?= $siteSettings['notifications']['email_enabled'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="email_enabled">
                                        <strong>Email</strong> - Gửi email thông báo
                                    </label>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Welcome Message</label>
                                    <textarea class="form-control" name="welcome_message" rows="3"><?= htmlspecialchars($siteSettings['notifications']['welcome_message']) ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6 mb-4">
                        <div class="content-card">
                            <div class="card-header">
                                <h6 class="card-title"><i class="bi bi-shield-lock"></i> Bảo mật</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label">Session Timeout (giây)</label>
                                    <input type="number" class="form-control" name="session_timeout" value="<?= (int)$siteSettings['security']['session_timeout'] ?>">
                                    <small class="text-muted">Thời gian hết hạn phiên đăng nhập (3600 = 1 giờ)</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Max Login Attempts</label>
                                    <input type="number" class="form-control" name="max_login_attempts" value="<?= (int)$siteSettings['security']['max_login_attempts'] ?>">
                                    <small class="text-muted">Số lần đăng nhập sai tối đa</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Password Min Length</label>
                                    <input type="number" class="form-control" name="password_min_length" value="<?= (int)$siteSettings['security']['password_min_length'] ?>">
                                    <small class="text-muted">Độ dài mật khẩu tối thiểu</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-12">
                        <button type="submit" class="action-btn btn-primary-solid">
                            <i class="bi bi-save"></i> Lưu cài đặt
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Backup & Security Tab -->
        <div class="tab-content" id="backup">
            <div class="row">
                <div class="col-lg-8 mb-4">
                    <div class="content-card">
                        <div class="card-header">
                            <h6 class="card-title"><i class="bi bi-database"></i> Quản lý Backup</h6>
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <p class="mb-1">Tạo backup database và download về máy</p>
                                    <small class="text-muted">Database size: <?= formatBytes($dbSize) ?></small>
                                </div>
                                <form method="post" action="" style="display: inline;">
                                    <input type="hidden" name="action" value="create_backup">
                                    <button type="submit" class="action-btn btn-primary-solid">
                                        <i class="bi bi-download"></i> Tạo Backup
                                    </button>
                                </form>
                            </div>

                            <?php if (empty($backupsList)): ?>
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i> Chưa có backup nào. Hãy tạo backup đầu tiên!
                                </div>
                            <?php else: ?>
                                <div class="table-wrapper">
                                    <table class="custom-table">
                                        <thead>
                                            <tr>
                                                <th>Tên file</th>
                                                <th>Kích thước</th>
                                                <th>Ngày tạo</th>
                                                <th>Hành động</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($backupsList as $backup): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($backup['name']) ?></td>
                                                <td><?= formatBytes($backup['size']) ?></td>
                                                <td><?= date('d/m/Y H:i', $backup['date']) ?></td>
                                                <td>
                                                    <form method="post" action="" style="display: inline;" class="me-2">
                                                        <input type="hidden" name="action" value="download_backup">
                                                        <input type="hidden" name="backup_file" value="<?= htmlspecialchars($backup['name']) ?>">
                                                        <button type="submit" class="action-btn btn-primary-outline btn-sm">
                                                            <i class="bi bi-download"></i> Download
                                                        </button>
                                                    </form>
                                                    <form method="post" action="" style="display: inline;" onsubmit="return confirm('Xóa backup này?')">
                                                        <input type="hidden" name="action" value="delete_backup">
                                                        <input type="hidden" name="backup_file" value="<?= htmlspecialchars($backup['name']) ?>">
                                                        <button type="submit" class="action-btn btn-danger-outline btn-sm">
                                                            <i class="bi bi-trash"></i> Xóa
                                                        </button>
                                                    </form>
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

                <div class="col-lg-4 mb-4">
                    <div class="content-card">
                        <div class="card-header">
                            <h6 class="card-title"><i class="bi bi-hdd"></i> Storage Info</h6>
                        </div>
                        <div class="card-body">
                            <div class="stat-group">
                                <div class="stat-item">
                                    <span class="stat-label">Database:</span>
                                    <span class="stat-value"><?= formatBytes($dbSize) ?></span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label">Backups:</span>
                                    <span class="stat-value"><?= formatBytes($backupDirSize) ?></span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label">Logs:</span>
                                    <span class="stat-value"><?= formatBytes($logDirSize) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="content-card mt-3">
                        <div class="card-header">
                            <h6 class="card-title"><i class="bi bi-tools"></i> Maintenance</h6>
                        </div>
                        <div class="card-body">
                            <form method="post" action="" class="mb-2">
                                <input type="hidden" name="action" value="clear_cache">
                                <button type="submit" class="action-btn btn-secondary-solid w-100">
                                    <i class="bi bi-trash"></i> Xóa Cache
                                </button>
                            </form>
                            <form method="post" action="" class="mb-2">
                                <input type="hidden" name="action" value="cleanup_logs">
                                <input type="hidden" name="days" value="30">
                                <button type="submit" class="action-btn btn-secondary-solid w-100">
                                    <i class="bi bi-file-text"></i> Dọn Logs Cũ
                                </button>
                            </form>
                            <form method="post" action="">
                                <input type="hidden" name="action" value="optimize_db">
                                <button type="submit" class="action-btn btn-success-solid w-100">
                                    <i class="bi bi-speedometer"></i> Optimize DB
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-6 mb-4">
                    <div class="content-card">
                        <div class="card-header">
                            <h6 class="card-title"><i class="bi bi-shield-exclamation"></i> Security Check</h6>
                        </div>
                        <div class="card-body">
                            <?php if (empty($securityIssues)): ?>
                                <div class="alert alert-success">
                                    <i class="bi bi-check-circle"></i> <strong>Tốt!</strong> Không phát hiện vấn đề bảo mật.
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning">
                                    <i class="bi bi-exclamation-triangle"></i> <strong>Cảnh báo!</strong> Phát hiện <?= count($securityIssues) ?> vấn đề:
                                </div>
                                <ul class="list-group">
                                    <?php foreach ($securityIssues as $issue): ?>
                                        <li class="list-group-item list-group-item-warning">
                                            <i class="bi bi-x-circle"></i> <?= htmlspecialchars($issue) ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6 mb-4">
                    <div class="content-card">
                        <div class="card-header">
                            <h6 class="card-title"><i class="bi bi-file-earmark-arrow-down"></i> Export Data</h6>
                        </div>
                        <div class="card-body">
                            <p class="text-muted mb-3">Export dữ liệu ra file CSV</p>
                            <form method="post" action="" class="mb-2">
                                <input type="hidden" name="action" value="export_users">
                                <button type="submit" class="action-btn btn-primary-solid w-100">
                                    <i class="bi bi-people"></i> Export Users
                                </button>
                            </form>
                            <form method="post" action="">
                                <input type="hidden" name="action" value="export_notebooks">
                                <button type="submit" class="action-btn btn-info-solid w-100">
                                    <i class="bi bi-journal-bookmark"></i> Export Notebooks
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="content-card">
                        <div class="card-header">
                            <h6 class="card-title"><i class="bi bi-info-circle"></i> System Information</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="stat-group">
                                        <div class="stat-item">
                                            <span class="stat-label">PHP Version:</span>
                                            <span class="stat-value"><?= htmlspecialchars($systemInfo['php_version']) ?></span>
                                        </div>
                                        <div class="stat-item">
                                            <span class="stat-label">Operating System:</span>
                                            <span class="stat-value"><?= htmlspecialchars($systemInfo['os']) ?></span>
                                        </div>
                                        <div class="stat-item">
                                            <span class="stat-label">Server:</span>
                                            <span class="stat-value"><?= htmlspecialchars($systemInfo['server_software']) ?></span>
                                        </div>
                                        <div class="stat-item">
                                            <span class="stat-label">Timezone:</span>
                                            <span class="stat-value"><?= htmlspecialchars($systemInfo['timezone']) ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="stat-group">
                                        <div class="stat-item">
                                            <span class="stat-label">Max Upload:</span>
                                            <span class="stat-value"><?= htmlspecialchars($systemInfo['max_upload_size']) ?></span>
                                        </div>
                                        <div class="stat-item">
                                            <span class="stat-label">Max Post:</span>
                                            <span class="stat-value"><?= htmlspecialchars($systemInfo['max_post_size']) ?></span>
                                        </div>
                                        <div class="stat-item">
                                            <span class="stat-label">Memory Limit:</span>
                                            <span class="stat-value"><?= htmlspecialchars($systemInfo['memory_limit']) ?></span>
                                        </div>
                                        <div class="stat-item">
                                            <span class="stat-label">Max Execution:</span>
                                            <span class="stat-value"><?= htmlspecialchars($systemInfo['max_execution_time']) ?>s</span>
                                        </div>
                                    </div>
                                </div>
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
