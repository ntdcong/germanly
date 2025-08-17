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

// Xử lý các hành động quản lý người dùng
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

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
    <style>
        :root {
            --primary-color: #6366f1;
            --primary-light: #f0f1ff;
            --primary-dark: #4f46e5;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --info-color: #3b82f6;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            --sidebar-width: 280px;
            --sidebar-width-collapsed: 80px;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background-color: var(--gray-50);
            color: var(--gray-900);
            line-height: 1.6;
        }

        /* Sidebar Styles */
        .admin-sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            z-index: 1000;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: var(--shadow-xl);
            overflow-y: auto;
        }

        .admin-sidebar.collapsed {
            width: var(--sidebar-width-collapsed);
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            margin-bottom: 1rem;
        }

        .sidebar-brand-icon {
            font-size: 2rem;
            margin-right: 0.75rem;
            color: #fbbf24;
        }

        .sidebar-brand-text {
            font-size: 1.375rem;
            font-weight: 700;
            letter-spacing: -0.025em;
        }

        .sidebar-nav {
            padding: 0 1rem;
        }

        .nav-item {
            margin-bottom: 0.5rem;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 0.875rem 1rem;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            border-radius: 0.75rem;
            font-weight: 500;
            transition: all 0.2s ease-in-out;
            position: relative;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            transform: translateX(4px);
        }

        .nav-link.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .nav-link i {
            font-size: 1.25rem;
            margin-right: 0.875rem;
            min-width: 1.25rem;
        }

        .sidebar-divider {
            height: 1px;
            background: rgba(255, 255, 255, 0.1);
            margin: 1.5rem 1rem;
        }

        /* Main Content */
        .admin-content {
            margin-left: var(--sidebar-width);
            padding: 2rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            min-height: 100vh;
        }

        .admin-content.expanded {
            margin-left: var(--sidebar-width-collapsed);
        }

        /* Header */
        .admin-header {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
        }

        .header-title {
            font-size: 1.875rem;
            font-weight: 700;
            color: var(--gray-900);
            margin: 0;
        }

        .header-meta {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .header-date {
            color: var(--gray-600);
            font-weight: 500;
        }

        .admin-badge {
            background: var(--primary-light);
            color: var(--primary-color);
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.875rem;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 1rem;
            padding: 2rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-color);
        }

        .stat-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .stat-info h3 {
            font-size: 2.25rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
            line-height: 1;
        }

        .stat-info p {
            color: var(--gray-600);
            font-weight: 500;
            margin: 0;
        }

        .stat-icon {
            width: 4rem;
            height: 4rem;
            background: var(--primary-light);
            border-radius: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: var(--primary-color);
        }

        /* Cards */
        .content-card {
            background: white;
            border-radius: 1rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            overflow: hidden;
        }

        .card-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--gray-200);
            background: var(--gray-50);
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-900);
            margin: 0;
        }

        .card-body {
            padding: 2rem;
        }

        /* Tables */
        .table-wrapper {
            overflow-x: auto;
            border-radius: 0.75rem;
            box-shadow: var(--shadow-sm);
        }

        .custom-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        .custom-table th {
            background: var(--gray-50);
            padding: 1rem 1.5rem;
            text-align: left;
            font-weight: 600;
            color: var(--gray-700);
            border-bottom: 1px solid var(--gray-200);
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .custom-table td {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            color: var(--gray-900);
        }

        .custom-table tbody tr:hover {
            background: var(--gray-50);
        }

        /* Badges */
        .role-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 0.5rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .role-admin {
            background: #fef2f2;
            color: #dc2626;
        }

        .role-user {
            background: #f0fdf4;
            color: #16a34a;
        }

        .vocab-count-badge {
            background: var(--info-color);
            color: white;
            padding: 0.25rem 0.625rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* Buttons */
        .btn-group {
            display: flex;
            gap: 0.5rem;
        }

        .action-btn {
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            border: 1px solid;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            transition: all 0.2s ease;
        }

        .btn-primary-outline {
            color: var(--primary-color);
            border-color: var(--primary-color);
            background: transparent;
        }

        .btn-primary-outline:hover {
            background: var(--primary-color);
            color: white;
        }

        .btn-danger-outline {
            color: var(--danger-color);
            border-color: var(--danger-color);
            background: transparent;
        }

        .btn-danger-outline:hover {
            background: var(--danger-color);
            color: white;
        }

        .btn-primary-solid {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .btn-primary-solid:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
        }

        .btn-secondary-solid {
            background: var(--gray-600);
            color: white;
            border-color: var(--gray-600);
        }

        .btn-danger-solid {
            background: var(--danger-color);
            color: white;
            border-color: var(--danger-color);
        }

        /* Alerts */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 0.75rem;
            margin-bottom: 1.5rem;
            border: 1px solid;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .alert-success {
            background: #f0fdf4;
            border-color: #bbf7d0;
            color: #166534;
        }

        .alert-danger {
            background: #fef2f2;
            border-color: #fecaca;
            color: #dc2626;
        }

        .alert-dismissible .btn-close {
            margin-left: auto;
            background: none;
            border: none;
            font-size: 1.25rem;
            opacity: 0.6;
            cursor: pointer;
        }

        /* Form Controls */
        .form-control, .form-select {
            padding: 0.75rem 1rem;
            border: 1px solid var(--gray-300);
            border-radius: 0.5rem;
            font-size: 0.875rem;
            transition: all 0.2s ease;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .form-label {
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
        }

        /* Mobile Toggle */
        .mobile-toggle {
            display: none;
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 1001;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 0.5rem;
            padding: 0.75rem;
            font-size: 1.25rem;
            cursor: pointer;
            box-shadow: var(--shadow-lg);
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .admin-sidebar {
                transform: translateX(-100%);
            }
            
            .admin-sidebar.show {
                transform: translateX(0);
            }
            
            .admin-content {
                margin-left: 0;
            }
            
            .mobile-toggle {
                display: block;
            }
            
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 1rem;
            }
        }

        @media (max-width: 768px) {
            .admin-content {
                padding: 1rem;
            }
            
            .admin-header {
                padding: 1rem 1.5rem;
                margin-bottom: 1.5rem;
            }
            
            .header-title {
                font-size: 1.5rem;
            }
            
            .header-meta {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .stat-card {
                padding: 1.5rem;
            }
            
            .stat-info h3 {
                font-size: 1.875rem;
            }
            
            .card-body {
                padding: 1.5rem;
            }
            
            .btn-group {
                flex-direction: column;
            }
        }

        @media (max-width: 640px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .custom-table {
                font-size: 0.875rem;
            }
            
            .custom-table th,
            .custom-table td {
                padding: 0.75rem 1rem;
            }
            
            .action-btn {
                font-size: 0.8rem;
                padding: 0.375rem 0.75rem;
            }
        }

        /* Tab Content */
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }

        /* Modal Improvements */
        .modal-content {
            border-radius: 1rem;
            border: none;
            box-shadow: var(--shadow-xl);
        }

        .modal-header {
            padding: 1.5rem 2rem 1rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-900);
        }

        .modal-body {
            padding: 1.5rem 2rem;
        }

        .modal-footer {
            padding: 1rem 2rem 1.5rem;
            border-top: 1px solid var(--gray-200);
            gap: 0.75rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--gray-500);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
    </style>
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
                    <a class="nav-link" href="#settings" data-tab="settings">
                        <i class="bi bi-gear"></i>
                        <span>Cài đặt</span>
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
                                        <th>Người tạo</th>
                                        <th>Số từ vựng</th>
                                        <th>Ngày tạo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_notebooks as $notebook): ?>
                                    <tr>
                                        <td><?= (int)$notebook['id'] ?></td>
                                        <td><?= htmlspecialchars($notebook['title']) ?></td>
                                        <td><?= htmlspecialchars($notebook['user_email']) ?></td>
                                        <td><span class="vocab-count-badge"><?= (int)$notebook['vocab_count'] ?></span></td>
                                        <td><?= date('d/m/Y', strtotime($notebook['created_at'])) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Settings Tab -->
        <div class="tab-content" id="settings">
            <div class="content-card">
                <div class="card-header">
                    <h6 class="card-title">Cài đặt hệ thống</h6>
                </div>
                <div class="card-body">
                    <form method="post" action="">
                        <div class="mb-4">
                            <label class="form-label">Tiêu đề trang web</label>
                            <input type="text" class="form-control" name="site_title" value="Germanly - Học tiếng Đức hiệu quả">
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Mô tả trang web</label>
                            <textarea class="form-control" name="site_description" rows="3">Ứng dụng học từ vựng tiếng Đức với flashcard và quiz</textarea>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Email liên hệ</label>
                            <input type="email" class="form-control" name="contact_email" value="contact@germanly.com">
                        </div>
                        <div class="mb-4">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" name="enable_registration" id="enableRegistration" checked>
                                <label class="form-check-label" for="enableRegistration">Cho phép đăng ký tài khoản mới</label>
                            </div>
                        </div>
                        <button type="submit" class="action-btn btn-primary-solid">
                            <i class="bi bi-save"></i> Lưu cài đặt
                        </button>
                    </form>
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