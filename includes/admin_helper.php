<?php
// Admin Helper Functions - không cần DB hay composer

// Lấy thông tin hệ thống
function getSystemInfo() {
    return [
        'php_version' => phpversion(),
        'os' => php_uname('s') . ' ' . php_uname('r'),
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'max_upload_size' => ini_get('upload_max_filesize'),
        'max_post_size' => ini_get('post_max_size'),
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'timezone' => date_default_timezone_get()
    ];
}

// Export dữ liệu ra CSV
function exportToCSV($data, $filename, $headers) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, $headers);
    
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
}

// Lấy danh sách backups
function getBackups() {
    $backupDir = 'assets/backups/';
    if (!is_dir($backupDir)) {
        return [];
    }
    
    $files = glob($backupDir . '*.sql');
    $backups = [];
    
    foreach ($files as $file) {
        $backups[] = [
            'name' => basename($file),
            'size' => filesize($file),
            'date' => filemtime($file),
            'path' => $file
        ];
    }
    
    // Sắp xếp theo ngày mới nhất
    usort($backups, function($a, $b) {
        return $b['date'] - $a['date'];
    });
    
    return $backups;
}

// Lấy dung lượng thư mục
function getDirectorySize($path) {
    $size = 0;
    
    if (!is_dir($path)) {
        return 0;
    }
    
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }
    
    return $size;
}

// Format dung lượng
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    for ($i = 0; $bytes > 1024; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

// Lấy database size
function getDatabaseSize($pdo) {
    try {
        $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $result = $pdo->query("
            SELECT 
                SUM(data_length + index_length) as size
            FROM information_schema.TABLES 
            WHERE table_schema = '$dbName'
        ")->fetch(PDO::FETCH_ASSOC);
        
        return $result['size'] ?? 0;
    } catch (Exception $e) {
        return 0;
    }
}

// Tạo activity chart data
function getActivityChartData($pdo, $days = 7) {
    $data = [];
    
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $label = date('d/m', strtotime("-$i days"));
        
        $users = $pdo->query("SELECT COUNT(*) FROM users WHERE DATE(created_at) = '$date'")->fetchColumn();
        $notebooks = $pdo->query("SELECT COUNT(*) FROM notebooks WHERE DATE(created_at) = '$date'")->fetchColumn();
        $vocab = $pdo->query("SELECT COUNT(*) FROM vocabularies WHERE DATE(created_at) = '$date'")->fetchColumn();
        
        $data[] = [
            'date' => $label,
            'users' => (int)$users,
            'notebooks' => (int)$notebooks,
            'vocab' => (int)$vocab
        ];
    }
    
    return $data;
}

// Optimize database tables
function optimizeDatabase($pdo) {
    try {
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $optimized = [];
        
        foreach ($tables as $table) {
            $pdo->query("OPTIMIZE TABLE `$table`");
            $optimized[] = $table;
        }
        
        writeSystemLog('Database optimized: ' . count($optimized) . ' tables', 'info');
        return $optimized;
    } catch (Exception $e) {
        writeSystemLog('Database optimization failed: ' . $e->getMessage(), 'error');
        return false;
    }
}

// Kiểm tra bảo mật
function securityCheck() {
    $issues = [];
    
    // Kiểm tra PHP version
    if (version_compare(PHP_VERSION, '7.4.0', '<')) {
        $issues[] = 'PHP version quá cũ (' . PHP_VERSION . '). Nên nâng cấp lên 7.4+';
    }
    
    // Kiểm tra register_globals
    if (ini_get('register_globals')) {
        $issues[] = 'register_globals đang bật (bảo mật kém)';
    }
    
    // Kiểm tra display_errors trên production
    if (ini_get('display_errors')) {
        $issues[] = 'display_errors đang bật (không nên trên production)';
    }
    
    // Kiểm tra file permissions
    if (is_writable('db.php')) {
        $issues[] = 'File db.php có quyền ghi (nguy hiểm)';
    }
    
    return $issues;
}

// Lấy user activity summary
function getUserActivitySummary($pdo) {
    $summary = [];
    
    // Active users (có hoạt động trong 7 ngày)
    $summary['active_users'] = $pdo->query("
        SELECT COUNT(DISTINCT user_id) 
        FROM notebooks 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ")->fetchColumn();
    
    // Inactive users (không hoạt động > 30 ngày)
    $summary['inactive_users'] = $pdo->query("
        SELECT COUNT(*) FROM users u
        WHERE NOT EXISTS (
            SELECT 1 FROM notebooks n 
            WHERE n.user_id = u.id 
            AND n.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        )
        AND u.created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ")->fetchColumn();
    
    return $summary;
}
?>
