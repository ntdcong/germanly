<?php
/**
 * Navbar Component - Tái sử dụng cho toàn bộ dự án
 * 
 * Cách sử dụng:
 * 
 * // Navbar đơn giản với nút quay lại
 * $navbar_config = [
 *     'type' => 'simple',
 *     'back_link' => 'dashboard.php',
 *     'page_title' => 'Tên trang'
 * ];
 * include 'includes/navbar.php';
 * 
 * // Navbar đầy đủ với logo và logout
 * $navbar_config = [
 *     'type' => 'main',
 *     'show_logout' => true
 * ];
 * include 'includes/navbar.php';
 */

// Default config
$config = array_merge([
    'type' => 'main', // 'main', 'simple', 'minimal'
    'back_link' => null,
    'page_title' => '',
    'show_logout' => true,
    'brand_link' => 'home.php',
    'extra_class' => '',
    'show_brand' => true
], $navbar_config ?? []);

// Kiểm tra xem có đang ở chế độ public không
$is_public = isset($_GET['token']) && !empty($_GET['token']);
?>

<?php if ($config['type'] === 'main'): ?>
    <!-- Navbar chính cho Dashboard và các trang chính -->
    <nav class="modern-navbar <?= htmlspecialchars($config['extra_class']) ?>">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <?php if ($config['show_brand']): ?>
                    <a class="navbar-brand" href="<?= htmlspecialchars($config['brand_link']) ?>">GERMANLY</a>
                <?php endif; ?>
                
                <?php if ($config['page_title']): ?>
                    <span class="navbar-text"><?= htmlspecialchars($config['page_title']) ?></span>
                <?php endif; ?>
                
                <?php if ($config['show_logout'] && !$is_public): ?>
                    <a href="logout.php" class="logout-btn">
                        <i class="bi bi-box-arrow-right me-2"></i>Đăng xuất
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

<?php elseif ($config['type'] === 'simple'): ?>
    <!-- Navbar đơn giản với nút quay lại -->
    <nav class="navbar navbar-light shadow-sm <?= htmlspecialchars($config['extra_class']) ?>">
        <div class="container">
            <?php if ($config['back_link']): ?>
                <a class="navbar-brand" href="<?= htmlspecialchars($config['back_link']) ?>">
                    <i class="bi bi-arrow-left"></i> Quay lại
                </a>
            <?php endif; ?>
            
            <?php if ($config['page_title']): ?>
                <span class="navbar-text text-truncate" style="max-width: 200px;">
                    <?= htmlspecialchars($config['page_title']) ?>
                </span>
            <?php endif; ?>
            
            <?php if ($config['show_logout'] && !$is_public): ?>
                <a href="logout.php" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-box-arrow-right"></i>
                </a>
            <?php endif; ?>
        </div>
    </nav>

<?php elseif ($config['type'] === 'minimal'): ?>
    <!-- Navbar tối giản -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm <?= htmlspecialchars($config['extra_class']) ?>">
        <div class="container-fluid">
            <?php if ($config['back_link']): ?>
                <a href="<?= htmlspecialchars($config['back_link']) ?>" class="btn btn-sm btn-outline-primary me-3">
                    <i class="bi bi-arrow-left"></i> Quay lại
                </a>
            <?php endif; ?>
            
            <?php if ($config['show_brand']): ?>
                <a class="navbar-brand fw-bold text-primary" href="<?= htmlspecialchars($config['brand_link']) ?>">
                    GERMANLY
                </a>
            <?php endif; ?>
            
            <?php if ($config['page_title']): ?>
                <span class="navbar-text me-auto">
                    <?= htmlspecialchars($config['page_title']) ?>
                </span>
            <?php endif; ?>
            
            <?php if ($config['show_logout'] && !$is_public): ?>
                <a href="logout.php" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-box-arrow-right me-1"></i>Đăng xuất
                </a>
            <?php endif; ?>
        </div>
    </nav>

<?php endif; ?>
