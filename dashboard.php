<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$user_id = $_SESSION['user_id'];
$message = '';

// ====== Actions (giữ nguyên logic) ======
// Thêm nhóm sổ tay
if (isset($_POST['add_group'])) {
    $group_name = trim($_POST['group_name'] ?? '');
    if ($group_name) {
        $stmt = $pdo->prepare('INSERT INTO notebook_groups (user_id, name) VALUES (?, ?)');
        $stmt->execute([$user_id, $group_name]);
        $message = 'Đã tạo nhóm mới!';
    } else {
        $message = 'Vui lòng nhập tên nhóm!';
    }
}
// Thêm sổ tay
if (isset($_POST['add_notebook'])) {
    $title = trim($_POST['title'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $group_id = $_POST['group_id'] !== '' ? (int) $_POST['group_id'] : null;
    if ($title) {
        $stmt = $pdo->prepare('INSERT INTO notebooks (user_id, title, description, group_id) VALUES (?, ?, ?, ?)');
        $stmt->execute([$user_id, $title, $desc, $group_id]);
        $message = 'Đã thêm sổ tay!';
    } else {
        $message = 'Vui lòng nhập tiêu đề!';
    }
}
// Xóa sổ tay
if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $pdo->prepare('DELETE FROM learning_status WHERE vocab_id IN (SELECT id FROM vocabularies WHERE notebook_id=?)')->execute([$id]);
    $pdo->prepare('DELETE FROM vocabularies WHERE notebook_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM notebooks WHERE id=? AND user_id=?')->execute([$id, $user_id]);
    $message = 'Đã xóa sổ tay!';
}
// Cập nhật sổ tay
if (isset($_POST['edit_notebook'])) {
    $id = (int) $_POST['notebook_id'];
    $title = trim($_POST['title'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $group_id = $_POST['group_id'] !== '' ? (int) $_POST['group_id'] : null;
    if ($title) {
        $stmt = $pdo->prepare('UPDATE notebooks SET title=?, description=?, group_id=? WHERE id=? AND user_id=?');
        $stmt->execute([$title, $desc, $group_id, $id, $user_id]);
        $message = 'Đã cập nhật sổ tay!';
    } else {
        $message = 'Vui lòng nhập tiêu đề!';
    }
}
// Xoá nhóm
if (isset($_GET['delete_group'])) {
    $gid = (int) $_GET['delete_group'];
    $pdo->prepare('UPDATE notebooks SET group_id=NULL WHERE group_id=? AND user_id=?')->execute([$gid, $user_id]);
    $pdo->prepare('DELETE FROM notebook_groups WHERE id=? AND user_id=?')->execute([$gid, $user_id]);
    $message = 'Đã xoá nhóm. Các sổ tay trong nhóm đã chuyển về "không nhóm"!';
}

// ====== Queries (giữ nguyên chức năng) ======
$stmt = $pdo->prepare('SELECT * FROM notebook_groups WHERE user_id = ? ORDER BY created_at DESC');
$stmt->execute([$user_id]);
$groups = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT * FROM notebooks WHERE user_id = ? ORDER BY created_at DESC');
$stmt->execute([$user_id]);
$notebooks = $stmt->fetchAll();

// Tìm kiếm theo tiêu đề/mô tả
$keyword = trim($_GET['q'] ?? '');
if ($keyword !== '') {
    $kw = mb_strtolower($keyword);
    $notebooks = array_values(array_filter($notebooks, function ($nb) use ($kw) {
        $t = mb_strtolower($nb['title'] ?? '');
        $d = mb_strtolower($nb['description'] ?? '');
        return (mb_stripos($t, $kw) !== false) || (mb_stripos($d, $kw) !== false);
    }));
}

// Đếm số từ / sổ tay (cho danh sách đang hiển thị)
$counts = [];
if ($notebooks) {
    $ids = array_column($notebooks, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $q = $pdo->prepare("SELECT notebook_id, COUNT(*) cnt FROM vocabularies WHERE notebook_id IN ($in) GROUP BY notebook_id");
    $q->execute($ids);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $counts[(int) $r['notebook_id']] = (int) $r['cnt'];
    }
}
// Kiểm tra “Giống”
$genderReady = [];
if ($notebooks) {
    $ids = array_column($notebooks, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $q = $pdo->prepare("
        SELECT DISTINCT notebook_id
        FROM vocabularies
        WHERE notebook_id IN ($in)
          AND genus IS NOT NULL AND TRIM(genus) <> ''
          AND LOWER(genus) IN ('der','die','das')
    ");
    $q->execute($ids);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $genderReady[(int) $r['notebook_id']] = true;
    }
}

// Gom theo group (sau khi lọc tìm kiếm)
$notebooks_by_group = [];
foreach ($notebooks as $nb) {
    $gid = $nb['group_id'] ?? 0;
    $notebooks_by_group[$gid][] = $nb;
}
// Lọc nhóm
$selected_group = isset($_GET['group']) ? $_GET['group'] : 'all';
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Quản lý Sổ tay - Flashcard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- libs -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Styles (giống giao diện mẫu) -->
    <style>
    :root {
        /* Cập nhật màu sắc cho hiện đại hơn */
        --primary-gradient: linear-gradient(135deg, #4f46e5, #7c3aed); /* Indigo */
        --secondary-gradient: linear-gradient(135deg, #ec4899, #f43f5e); /* Rose */
        --success-gradient: linear-gradient(135deg, #10b981, #059669); /* Emerald */
        --warning-gradient: linear-gradient(135deg, #f59e0b, #d97706); /* Amber */
        --danger-gradient: linear-gradient(135deg, #ef4444, #dc2626); /* Red */
        --info-gradient: linear-gradient(135deg, #0ea5e9, #0284c7); /* Sky */


        --surface: #ffffff;
        --surface-secondary: #f9fafb; /* Xám nhạt hơn */
        --surface-tertiary: #f3f4f6;
        --border: #e5e7eb; /* Border nhạt hơn */
        --border-focus: #4f46e5; /* Màu focus */

        /* Shadow hợp lý hơn */
        --shadow-xs: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        --shadow-sm: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1);
        --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
        --shadow-md: 0 6px 10px -1px rgba(0, 0, 0, 0.1), 0 4px 6px -3px rgba(0, 0, 0, 0.1);
        --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
        --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);

        --text-primary: #1f2937; /* Xám đậm hơn */
        --text-secondary: #4b5563;
        --text-tertiary: #9ca3af;

        --border-radius-sm: 6px;
        --border-radius: 10px; /* Border-radius nhỏ gọn hơn */
        --border-radius-lg: 16px;
        --border-radius-xl: 24px;

        --transition: all 0.2s ease-in-out;
    }

    * {
        box-sizing: border-box;
    }

    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif;
        background: var(--primary-gradient); /* Giữ gradient nền */
        min-height: 100vh;
        color: var(--text-primary);
        line-height: 1.6;
        margin: 0;
        padding: 0;
    }

    .main-container {
        background: var(--surface-secondary);
        border-radius: var(--border-radius-xl) var(--border-radius-xl) 0 0;
        margin: 2rem auto 0; /* Căn giữa */
        max-width: 1400px; /* Giới hạn chiều rộng */
        min-height: calc(100vh - 2rem);
        overflow: hidden;
        box-shadow: var(--shadow-xl); /* Thêm shadow cho container chính */
    }

    /* Navbar */
    .modern-navbar {
        background: rgba(255, 255, 255, .95);
        backdrop-filter: blur(10px); /* Blur nhẹ */
        border-bottom: 1px solid var(--border);
        padding: 1rem 1rem; /* Padding đều hơn, responsive */
        position: sticky;
        top: 0;
        z-index: 1000;
        box-shadow: var(--shadow-sm); /* Shadow nhẹ cho navbar */
    }

    .navbar-brand {
        font-weight: 800; /* Font-weight đậm hơn */
        font-size: 1.5rem; /* Nhỏ hơn trên mobile */
        background: var(--primary-gradient);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        text-decoration: none;
        transition: var(--transition);
        white-space: nowrap; /* Tránh xuống dòng */
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .navbar-brand:hover {
        transform: scale(1.02); /* Hiệu ứng hover nhẹ */
    }

    .logout-btn {
        background: var(--danger-gradient);
        border: none;
        color: #fff;
        padding: 0.5rem 1rem; /* Padding nhỏ gọn hơn */
        border-radius: 50px;
        font-weight: 600;
        font-size: 0.85rem; /* Font-size nhỏ gọn */
        transition: var(--transition);
        box-shadow: var(--shadow-sm);
        display: flex; /* Flex để căn icon và text */
        align-items: center;
        gap: 0.4rem; /* Khoảng cách giữa icon và text */
        white-space: nowrap;
    }

    .logout-btn:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
        color: #fff;
    }

    /* Action section */
    .action-section {
        padding: 1.5rem 1rem 1rem; /* Padding responsive */
        text-align: center; /* Căn giữa trên mobile */
    }

    .modern-btn {
        border: none;
        padding: 0.75rem 1.25rem; /* Padding nhỏ gọn hơn */
        border-radius: 50px;
        font-weight: 600;
        font-size: 0.85rem; /* Font-size nhỏ gọn */
        color: #fff;
        text-decoration: none;
        transition: var(--transition);
        box-shadow: var(--shadow);
        position: relative;
        overflow: hidden;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem; /* Khoảng cách giữa icon và text */
        margin: 0.3rem; /* Margin giữa các nút */
        white-space: nowrap;
    }

    .modern-btn:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-lg);
        color: #fff;
    }

    .modern-btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, .2), transparent);
        transition: left 0.5s;
    }

    .modern-btn:hover::before {
        left: 100%;
    }

    .btn-create-group {
        background: var(--primary-gradient);
    }

    .btn-create-notebook {
        background: var(--success-gradient);
    }

    .btn-import {
        background: var(--secondary-gradient);
    }

    /* Filter */
    .filter-section {
        background: var(--surface);
        border-radius: var(--border-radius-lg);
        padding: 1.25rem 1rem; /* Padding responsive */
        margin: 0 1rem 1.5rem; /* Margin hai bên */
        box-shadow: var(--shadow-sm);
        border: 1px solid var(--border);
    }

    .filter-label {
        color: var(--text-secondary);
        font-weight: 600; /* Font-weight đậm hơn */
        margin-bottom: 0.6rem;
        display: flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.9rem;
    }

    .modern-select, .form-control, .form-select {
        border: 1px solid var(--border);
        border-radius: var(--border-radius-sm);
        padding: 0.65rem 0.85rem; /* Padding nhỏ gọn hơn */
        background: var(--surface);
        font-weight: 500;
        color: var(--text-primary);
        transition: var(--transition);
        box-shadow: var(--shadow-xs);
        font-size: 0.9rem; /* Font-size nhỏ gọn */
        height: auto; /* Cho phép chiều cao tự điều chỉnh */
    }

    .modern-select:focus, .form-control:focus, .form-select:focus {
        outline: none;
        border-color: var(--border-focus);
        box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1); /* Shadow focus */
    }

    .search-input {
        min-width: 0; /* Loại bỏ min-width cứng */
        flex-grow: 1; /* Chiếm không gian còn lại */
    }

    .input-group {
        flex-wrap: wrap; /* Cho phép wrap trên mobile */
    }

    .input-group > .form-control,
    .input-group > .form-select,
    .input-group > .input-group-text,
    .input-group > .btn {
        flex-shrink: 0; /* Không co lại quá mức */
    }

    /* Group card */
    .group-card {
        background: var(--surface);
        border-radius: var(--border-radius-lg);
        margin: 0 1rem 1.5rem; /* Margin hai bên */
        overflow: hidden;
        box-shadow: var(--shadow);
        border: 1px solid var(--border);
        transition: var(--transition);
    }

    .group-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-lg);
    }

    .group-header {
        background: var(--surface-tertiary); /* Màu nền nhạt hơn */
        padding: 1rem 1rem; /* Padding nhỏ gọn hơn */
        cursor: pointer;
        transition: var(--transition);
        border-bottom: 1px solid var(--border);
        position: relative;
        display: flex;
        align-items: center;
        justify-content: space-between; /* Căn đều */
        min-height: 60px; /* Chiều cao tối thiểu */
    }

    .group-header:hover {
        background: #e5e7eb; /* Hover nhẹ */
    }

    .group-info {
        display: flex;
        align-items: center;
        flex-grow: 1; /* Chiếm không gian còn lại */
        min-width: 0; /* Cho phép truncate */
    }

    .group-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2.25rem; /* Nhỏ gọn hơn */
        height: 2.25rem;
        background: var(--primary-gradient);
        border-radius: var(--border-radius-sm); /* Border-radius nhỏ */
        color: #fff;
        font-size: 1rem; /* Font-size icon nhỏ gọn */
        margin-right: 0.75rem;
        box-shadow: var(--shadow-sm);
        flex-shrink: 0; /* Không co lại */
    }

    .group-title {
        font-size: 1rem; /* Font-size nhỏ gọn hơn */
        font-weight: 700; /* Font-weight đậm hơn */
        color: var(--text-primary);
        margin: 0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis; /* ... nếu quá dài */
        flex-grow: 1;
        min-width: 0; /* Cho phép truncate */
    }

    .group-delete-btn {
        background: var(--danger-gradient);
        border: none;
        color: #fff;
        padding: 0.4rem;
        border-radius: 6px; /* Border-radius nhỏ */
        transition: var(--transition);
        margin-left: 0.5rem; /* Margin nhỏ hơn */
        flex-shrink: 0; /* Không co lại */
        width: 2rem; /* Chiều rộng cố định */
        height: 2rem; /* Chiều cao cố định */
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.8rem;
    }

    .group-delete-btn:hover {
        transform: scale(1.05); /* Scale nhẹ */
        box-shadow: var(--shadow-sm);
    }

    .toggle-icon {
        font-size: 1rem; /* Font-size nhỏ gọn */
        color: var(--text-tertiary);
        transition: var(--transition);
        flex-shrink: 0; /* Không co lại */
        margin-left: 0.5rem;
    }

    /* Notebook grid */
    .notebook-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); /* Minmax nhỏ hơn để phù hợp mobile */
        gap: 1rem; /* Gap nhỏ hơn */
        padding: 1rem; /* Padding nhỏ gọn hơn */
    }

    /* Notebook card */
    .notebook-card {
        background: var(--surface);
        border-radius: var(--border-radius);
        overflow: hidden;
        box-shadow: var(--shadow-sm);
        border: 1px solid var(--border);
        transition: var(--transition);
        height: fit-content;
        display: flex;
        flex-direction: column; /* Layout dọc */
    }

    .notebook-card:hover {
        transform: translateY(-3px);
        box-shadow: var(--shadow-md);
        border-color: rgba(79, 70, 229, 0.2); /* Border focus nhẹ */
    }

    .notebook-header {
        padding: 1rem; /* Padding nhỏ gọn hơn */
        border-bottom: 1px solid var(--border);
        flex-grow: 1; /* Chiếm không gian còn lại */
    }

    .notebook-avatar {
        width: 2.25rem; /* Nhỏ gọn hơn */
        height: 2.25rem;
        border-radius: var(--border-radius-sm); /* Border-radius nhỏ */
        background: var(--primary-gradient);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-size: 0.9rem; /* Font-size nhỏ gọn */
        margin-bottom: 0.6rem; /* Margin-bottom nhỏ gọn hơn */
        box-shadow: var(--shadow-xs);
        flex-shrink: 0; /* Không co lại */
    }

    .notebook-title {
        font-size: 1rem; /* Font-size nhỏ gọn hơn */
        font-weight: 700; /* Font-weight đậm hơn */
        color: var(--text-primary);
        margin-bottom: 0.4rem;
        line-height: 1.4;
        display: -webkit-box;
        -webkit-line-clamp: 2; /* Giới hạn 2 dòng */
        -webkit-box-orient: vertical;
        overflow: hidden;
        word-break: break-word; /* Ngắt từ dài */
    }

    .notebook-description {
        color: var(--text-secondary);
        font-size: 0.8rem; /* Font-size nhỏ gọn hơn */
        line-height: 1.5;
        display: -webkit-box;
        -webkit-line-clamp: 2; /* Giới hạn 2 dòng */
        -webkit-box-orient: vertical;
        overflow: hidden;
        word-break: break-word; /* Ngắt từ dài */
    }

    /* Actions */
    .notebook-actions {
        padding: 1rem; /* Padding nhỏ gọn hơn */
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); /* Minmax nhỏ hơn */
        gap: 0.6rem; /* Gap nhỏ hơn */
        background-color: var(--surface-secondary); /* Nền nhẹ */
        border-top: 1px solid var(--border);
    }

    /* fallback nhỏ */
    .action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.4rem;
        padding: 0.55rem 0.65rem; /* Padding nhỏ gọn hơn */
        border-radius: var(--border-radius-sm); /* Border-radius nhỏ */
        font-weight: 500;
        font-size: 0.75rem; /* Font-size nhỏ gọn */
        text-decoration: none;
        transition: var(--transition);
        border: 1px solid var(--border);
        background: var(--surface);
        color: var(--text-primary);
        position: relative;
        overflow: hidden;
        white-space: nowrap;
        min-height: 36px; /* Chiều cao tối thiểu */
        text-align: center;
    }

    .action-btn:hover {
        transform: translateY(-1px);
        box-shadow: var(--shadow-xs);
        text-decoration: none;
        z-index: 1; /* Đảm bảo hover hiển thị trên các phần tử khác */
    }

    /* Màu sắc cho từng loại action btn */
    .action-btn.flashcard {
        border-color: #f59e0b;
        color: #f59e0b;
        background-color: #fffbeb; /* Nền nhạt */
    }
    .action-btn.flashcard:hover {
        background: #f59e0b;
        color: #fff;
    }

    .action-btn.quiz {
        border-color: #0ea5e9;
        color: #0ea5e9;
        background-color: #f0f9ff; /* Nền nhạt */
    }
    .action-btn.quiz:hover {
        background: #0ea5e9;
        color: #fff;
    }

    .action-btn.vocab {
        border-color: #4f46e5;
        color: #4f46e5;
        background-color: #eef2ff; /* Nền nhạt */
    }
    .action-btn.vocab:hover {
        background: #4f46e5;
        color: #fff;
    }

    .action-btn.gender {
        border-color: #7c3aed;
        color: #7c3aed;
        background-color: #f5f3ff; /* Nền nhạt */
    }
    .action-btn.gender:hover {
        background: #7c3aed;
        color: #fff;
    }

    .action-btn.excel {
        border-color: #10b981;
        color: #10b981;
        background-color: #ecfdf5; /* Nền nhạt */
    }
    .action-btn.excel:hover {
        background: #10b981;
        color: #fff;
    }

    .action-btn.share {
        border-color: #ec4899;
        color: #ec4899;
        background-color: #fdf2f8; /* Nền nhạt */
    }
    .action-btn.share:hover {
        background: #ec4899;
        color: #fff;
    }

    .action-btn.disabled {
        opacity: 0.6;
        pointer-events: none;
        color: var(--text-tertiary);
        border-color: var(--border);
        background-color: var(--surface-tertiary); /* Nền disabled */
    }

    .action-btn.disabled:hover {
        transform: none;
        box-shadow: none;
    }


    /* Footer mini actions */
    .notebook-footer {
        padding: 0.75rem 1rem; /* Padding nhỏ gọn hơn */
        background: var(--surface);
        border-top: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 0.6rem; /* Gap nhỏ hơn */
        font-size: 0.75rem; /* Font-size nhỏ gọn */
    }

    .notebook-meta {
        color: var(--text-secondary);
        display: flex;
        align-items: center;
        gap: 0.4rem;
        flex-wrap: wrap; /* Wrap nếu quá dài */
        min-width: 0; /* Cho phép truncate */
    }

    .notebook-meta span {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .notebook-actions-mini {
        display: flex;
        gap: 0.4rem;
    }

    .mini-btn {
        padding: 0.35rem; /* Padding nhỏ gọn */
        border-radius: 5px; /* Border-radius nhỏ */
        border: 1px solid var(--border);
        background: var(--surface);
        color: var(--text-secondary);
        transition: var(--transition);
        text-decoration: none;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 1.8rem; /* Chiều rộng cố định */
        height: 1.8rem; /* Chiều cao cố định */
        flex-shrink: 0; /* Không co lại */
        font-size: 0.75rem;
    }

    .mini-btn:hover {
        background: var(--text-secondary);
        color: #fff;
        text-decoration: none;
        transform: translateY(-1px); /* Hiệu ứng nhỏ */
        box-shadow: var(--shadow-xs);
    }

    .mini-btn.danger:hover {
        background: #ef4444;
        border-color: #ef4444;
    }

    /* Empty */
    .empty-state {
        text-align: center;
        padding: 2rem 1rem; /* Padding nhỏ gọn hơn */
        color: var(--text-secondary);
        margin: 0 1rem 1.5rem; /* Margin hai bên */
        background: var(--surface);
        border-radius: var(--border-radius-lg);
        box-shadow: var(--shadow-sm);
        border: 1px solid var(--border);
    }

    .empty-state-icon {
        width: 3rem;
        height: 3rem;
        background: var(--primary-gradient);
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1rem; /* Margin-bottom nhỏ gọn hơn */
        color: #fff;
        font-size: 1.5rem;
    }

    .empty-state h3 {
        color: var(--text-primary);
        font-weight: 700; /* Font-weight đậm hơn */
        margin-bottom: 0.6rem; /* Margin-bottom nhỏ gọn hơn */
        font-size: 1.1rem;
    }

    .empty-state p {
        font-size: 0.9rem;
        margin-bottom: 1rem;
    }

    /* Modal */
    .modal-content {
        border-radius: var(--border-radius-lg);
        border: none;
        box-shadow: var(--shadow-xl);
    }

    .modal-header {
        background: var(--surface-secondary);
        border-bottom: 1px solid var(--border);
        border-radius: var(--border-radius-lg) var(--border-radius-lg) 0 0;
        padding: 1rem; /* Padding nhỏ gọn hơn */
    }

    .modal-title {
        font-weight: 700; /* Font-weight đậm hơn */
        color: var(--text-primary);
        font-size: 1.1rem; /* Font-size nhỏ gọn hơn */
    }

    .modal-body {
        padding: 1rem; /* Padding nhỏ gọn hơn */
    }

    .modal-footer {
        border-top: 1px solid var(--border);
        background: var(--surface-secondary);
        padding: 0.8rem 1rem; /* Padding nhỏ gọn hơn */
    }

    .form-label {
        font-weight: 600; /* Font-weight đậm hơn */
        color: var(--text-secondary);
        margin-bottom: 0.4rem;
        font-size: 0.9rem; /* Font-size nhỏ gọn */
    }

    .btn-primary, .btn-success, .btn-secondary {
        padding: 0.55rem 1rem; /* Padding nhỏ gọn hơn */
        border-radius: var(--border-radius-sm); /* Border-radius nhỏ */
        font-weight: 600;
        font-size: 0.9rem; /* Font-size nhỏ gọn */
        border: none;
        transition: var(--transition);
        box-shadow: var(--shadow-sm);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.4rem;
    }

    .btn-primary {
        background: var(--primary-gradient);
    }
    .btn-primary:hover {
        box-shadow: var(--shadow);
        transform: translateY(-1px);
    }

    .btn-success {
        background: var(--success-gradient);
    }
    .btn-success:hover {
        box-shadow: var(--shadow);
        transform: translateY(-1px);
    }

    .btn-secondary {
        background: var(--surface-tertiary);
        color: var(--text-secondary);
        border: 1px solid var(--border) !important; /* Đảm bảo viền */
    }
    .btn-secondary:hover {
        background: var(--border);
        transform: translateY(-1px);
    }

    .modern-alert {
        background: rgba(79, 70, 229, 0.1); /* Nền alert nhẹ */
        border: 1px solid rgba(79, 70, 229, 0.2);
        border-radius: var(--border-radius);
        padding: 0.8rem 1rem; /* Padding nhỏ gọn hơn */
        color: #4c51bf; /* Màu text alert */
        margin: 0 1rem 1rem; /* Margin hai bên và dưới */
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 0.6rem;
        animation: fadeIn 0.3s ease-out forwards; /* Animation mượt hơn */
        font-size: 0.9rem;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .fade-in {
        animation: fadeIn 0.3s ease-out forwards;
    }

    .slide-down {
        animation: slideDown 0.3s ease-out forwards;
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            max-height: 0;
        }
        to {
            opacity: 1;
            max-height: 500px; /* Đủ cao cho nội dung */
        }
    }

    .d-none {
        display: none !important;
    }

    @media (min-width: 768px) {
        .main-container {
            margin-top: 2rem;
            border-radius: var(--border-radius-xl) var(--border-radius-xl) 0 0;
        }

        .modern-navbar {
            padding: 1rem 1.5rem;
        }

        .navbar-brand {
            font-size: 1.75rem;
        }

        .logout-btn {
            padding: 0.6rem 1.25rem;
            font-size: 0.9rem;
            gap: 0.5rem;
        }

        .action-section {
            padding: 2rem 1.5rem 1.5rem;
        }

        .modern-btn {
            padding: 0.875rem 1.75rem;
            font-size: 0.95rem;
            gap: 0.6rem;
            margin: 0.5rem;
        }

        .filter-section {
            padding: 1.5rem;
            margin: 0 1.5rem 2rem;
        }

        .filter-label {
            font-size: 0.95rem;
            margin-bottom: 0.75rem;
        }

        .modern-select, .form-control, .form-select {
            padding: 0.75rem 1rem;
            font-size: 0.95rem;
        }

        .group-card {
            margin: 0 1.5rem 2rem;
        }

        .group-header {
            padding: 1.25rem 1.5rem;
        }

        .group-icon {
            width: 2.75rem;
            height: 2.75rem;
            font-size: 1.25rem;
            margin-right: 1rem;
        }

        .group-title {
            font-size: 1.25rem;
        }

        .group-delete-btn {
            width: 2.5rem;
            height: 2.5rem;
            padding: 0.5rem;
            font-size: 0.82rem;
            margin-left: 0.75rem;
        }

        .toggle-icon {
            font-size: 1.25rem;
            margin-left: 0.75rem;
        }

        .notebook-grid {
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.25rem;
            padding: 1.5rem;
        }

        .notebook-header {
            padding: 1.25rem;
        }

        .notebook-avatar {
            width: 2.75rem;
            height: 2.75rem;
            font-size: 1.1rem;
            margin-bottom: 0.8rem;
        }

        .notebook-title {
            font-size: 1.1rem;
        }

        .notebook-description {
            font-size: 0.85rem;
        }

        .notebook-actions {
            padding: 1.25rem;
            gap: 0.75rem;
        }

        .action-btn {
            padding: 0.65rem 0.8rem;
            font-size: 0.82rem;
            gap: 0.5rem;
            min-height: 40px;
        }

        .notebook-footer {
            padding: 0.8rem 1.25rem;
            gap: 0.75rem;
            font-size: 0.85rem;
        }

        .mini-btn {
            padding: 0.4rem;
            width: 2.2rem;
            height: 2.2rem;
            font-size: 0.82rem;
        }

        .empty-state {
            padding: 3rem 1.5rem;
            margin: 0 1.5rem 2rem;
        }

        .empty-state-icon {
            width: 4rem;
            height: 4rem;
            font-size: 2rem;
            margin-bottom: 1.25rem;
        }

        .empty-state h3 {
            font-size: 1.25rem;
            margin-bottom: 0.75rem;
        }

        .modal-header {
            padding: 1.25rem 1.5rem;
        }

        .modal-title {
            font-size: 1.25rem;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
        }

        .form-label {
            font-size: 0.95rem;
            margin-bottom: 0.5rem;
        }

        .btn-primary, .btn-success, .btn-secondary {
            padding: 0.65rem 1.25rem;
            font-size: 0.95rem;
            gap: 0.5rem;
        }

        .modern-alert {
            padding: 1rem 1.25rem;
            margin: 0 1.5rem 1.5rem;
            font-size: 0.95rem;
        }
    }

    @media (min-width: 576px) and (max-width: 767.98px) {
        .notebook-grid {
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 1rem;
            padding: 1rem;
        }

        .notebook-actions {
            grid-template-columns: repeat(3, minmax(0, 1fr)); /* 3 cột trên tablet nhỏ */
        }

        .action-btn {
            padding: 0.6rem 0.7rem;
            font-size: 0.8rem;
        }
    }

    .modal,
    .modal-backdrop {
        will-change: opacity, transform;
        backface-visibility: hidden;
    }

    .ripple {
        position: absolute;
        border-radius: 50%;
        background: rgba(255, 255, 255, .3);
        pointer-events: none;
        transform: scale(0);
        animation: ripple-animation .6s linear;
    }

    @keyframes ripple-animation {
        to {
            transform: scale(2);
            opacity: 0;
        }
    }
    /* Notebook grid - Thêm cuộn dọc */
    .notebook-grid {
        max-height: 70vh; 
        overflow-y: auto; 
        padding-bottom: 0.5rem;
    }

    .notebook-grid::-webkit-scrollbar {
        width: 8px; 
    }
    .notebook-grid::-webkit-scrollbar-track {
        background: var(--surface-secondary); 
        border-radius: 4px;
    }
    .notebook-grid::-webkit-scrollbar-thumb {
        background-color: var(--text-tertiary); 
        border-radius: 4px;
        border: 2px solid var(--surface-secondary); 
    }
    .notebook-grid::-webkit-scrollbar-thumb:hover {
        background-color: var(--text-secondary); 
    }

    /* Tùy chỉnh thanh cuộn cho Firefox */
    .notebook-grid {
        scrollbar-width: thin; 
        scrollbar-color: var(--text-tertiary) var(--surface-secondary); 
    }
    .action-section {
    padding: 2rem 0 1rem;
    text-align: center; /* Căn giữa nội dung */
    }

    .action-section .d-flex {
        flex-direction: row; 
        flex-wrap: wrap; 
        justify-content: center; 
        gap: 0.75rem; 
        max-width: 100%; 
        margin: 0 auto; 
    }

    .modern-btn {
        min-width: 80px; 
        padding: 0.65rem 1rem; 
        font-size: 0.9rem; 
        border-radius: 50px; 
        transition: var(--transition);
        box-shadow: var(--shadow-sm);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.4rem;
        margin: 0.3rem; 
        white-space: nowrap;
        flex-shrink: 0; 
    }
</style>
</head>

<body>
    <!-- Navbar -->
    <nav class="modern-navbar">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <a class="navbar-brand" href="home.php">GERMANLY</a>
                <a href="logout.php" class="logout-btn">
                    <i class="bi bi-box-arrow-right me-2"></i>Đăng xuất
                </a>
            </div>
        </div>
    </nav>

    <div class="main-container">
        <div class="container">
            <!-- Action Section -->
            <div class="action-section">
                <div class="d-flex flex-row flex-wrap align-items-center justify-content-center gap-3">
                    <button class="modern-btn btn-create-group" data-bs-toggle="modal" data-bs-target="#modalAddGroup">
                        <i class="bi bi-folder-plus"></i><span>Tạo nhóm</span>
                    </button>
                    <button class="modern-btn btn-create-notebook" data-bs-toggle="modal" data-bs-target="#modalAddNotebook">
                        <i class="bi bi-journal-plus"></i><span>Tạo sổ tay</span>
                    </button>
                    <a href="import_shared.php" class="modern-btn btn-import">
                        <i class="bi bi-download"></i><span>Nhập sổ tay</span>
                    </a>
                </div>
            </div>

            <!-- Filter Section (thêm ô tìm kiếm) -->
            <div class="filter-section">
                <form method="get" class="w-100">
                    <div class="row g-3 align-items-center">
                        <div class="col-12 col-md-auto">
                            <label class="filter-label d-flex align-items-center gap-2 mb-0">
                                <i class="bi bi-filter"></i> Lọc theo nhóm:
                            </label>
                        </div>

                        <div class="col-12 col-md-12">
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" name="q" value="<?= htmlspecialchars($keyword) ?>" class="form-control search-input" placeholder="Tìm theo tiêu đề hoặc mô tả...">
                                <?php if ($keyword !== ''): ?>
                                    <a class="btn btn-outline-secondary" href="?group=<?= urlencode($selected_group) ?>"><i class="bi bi-x-lg"></i></a>
                                <?php endif; ?>
                                <button class="btn btn-primary" type="submit"><i class="bi bi-arrow-return-left me-1"></i>Tìm</button>
                            </div>
                        </div>

                        <div class="col-12 col-md-3">
                            <select name="group" class="modern-select w-100" onchange="this.form.submit()">
                                <option value="all" <?= $selected_group === 'all' ? 'selected' : '' ?>>Tất cả nhóm</option>
                                <?php foreach ($groups as $g): ?>
                                    <option value="<?= $g['id'] ?>" <?= $selected_group == $g['id'] ? 'selected' : '' ?>><?= htmlspecialchars($g['name']) ?></option>
                                <?php endforeach; ?>
                                <option value="none" <?= $selected_group === 'none' ? 'selected' : '' ?>>Không nhóm</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Alert -->
            <?php if ($message): ?>
                <div class="modern-alert fade-in">
                    <i class="bi bi-info-circle me-2"></i><?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <!-- Empty State -->
            <?php
            $hasNoGroups = count($groups) === 0;
            $hasNoUngroup = empty($notebooks_by_group[null]) && empty($notebooks_by_group[0]);
            $hasNoAny = empty($notebooks);
            if ($hasNoAny):
                ?>
                <div class="empty-state fade-in">
                    <div class="empty-state-icon"><i class="bi bi-folder-plus"></i></div>
                    <h3>Chào mừng đến với GERMANLY</h3>
                    <p class="mb-4">Chưa có sổ tay nào<?= $keyword ? ' khớp từ khóa "' . htmlspecialchars($keyword) . '"' : '' ?>. Bắt đầu bằng cách tạo nhóm/sổ tay mới!</p>
                    <button class="modern-btn btn-create-group" data-bs-toggle="modal" data-bs-target="#modalAddGroup">
                        <i class="bi bi-plus-circle"></i><span>Tạo nhóm mới</span>
                    </button>
                </div>
            <?php endif; ?>

            <!-- Danh sách nhóm -->
            <?php foreach ($groups as $g): ?>
                <?php if ($selected_group === 'all' || $selected_group == $g['id']): ?>
                    <div class="group-card fade-in">
                        <div class="group-header" data-group="<?= $g['id'] ?>">
                            <div class="d-flex align-items-center">
                                <div class="group-icon"><i class="bi bi-folder-fill"></i></div>
                                <h3 class="group-title mb-0"><?= htmlspecialchars($g['name']) ?></h3>

                                <!-- Xoá nhóm -->
                                <form method="get" class="d-inline" onClick="event.stopPropagation()">
                                    <input type="hidden" name="delete_group" value="<?= $g['id'] ?>">
                                    <button class="group-delete-btn" title="Xoá nhóm"
                                        onclick="return confirm('Xoá nhóm này? Các sổ tay sẽ chuyển về không nhóm.')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>
                            <?php if (!empty($notebooks_by_group[$g['id']])): ?>
                                <i class="bi bi-chevron-down toggle-icon" data-group="<?= $g['id'] ?>"></i>
                            <?php endif; ?>
                        </div>

                        <div class="notebook-grid d-none" id="groupGrid<?= $g['id'] ?>">
                            <?php if (!empty($notebooks_by_group[$g['id']])): ?>
                                <?php foreach ($notebooks_by_group[$g['id']] as $nb): ?>
                                    <div class="notebook-card">
                                        <div class="notebook-header">
                                            <div class="notebook-title"><?= htmlspecialchars($nb['title']) ?></div>
                                            <div class="notebook-description">
                                                <?= $nb['description'] ? nl2br(htmlspecialchars($nb['description'])) : 'Chưa có mô tả…' ?>
                                            </div>
                                        </div>

                                        <div class="notebook-actions">
                                            <a href="study_flashcard.php?notebook_id=<?= $nb['id'] ?>" class="action-btn flashcard">
                                                <i class="bi bi-journal-richtext"></i><span>Thẻ</span>
                                            </a>
                                            <a href="study_quiz.php?notebook_id=<?= $nb['id'] ?>" class="action-btn quiz">
                                                <i class="bi bi-question-circle"></i><span>Quiz</span>
                                            </a>
                                            <a href="add_vocab.php?notebook_id=<?= $nb['id'] ?>" class="action-btn vocab">
                                                <i class="bi bi-pencil-square"></i><span>Từ vựng</span>
                                            </a>

                                            <?php $canGender = !empty($genderReady[(int) $nb['id']]); ?>
                                            <?php if ($canGender): ?>
                                                <a href="study_gender.php?notebook_id=<?= $nb['id'] ?>" class="action-btn gender">
                                                    <i class="bi bi-gender-ambiguous"></i><span>Giống</span>
                                                </a>
                                            <?php else: ?>
                                                <span class="action-btn gender disabled" title="Chưa có danh từ có giống">
                                                    <i class="bi bi-gender-ambiguous"></i><span>Giống</span>
                                                </span>
                                            <?php endif; ?>

                                            <a href="import_excel.php?notebook_id=<?= $nb['id'] ?>" class="action-btn excel">
                                                <i class="bi bi-file-earmark-excel"></i><span>Excel</span>
                                            </a>
                                            <a href="share_notebook.php?notebook_id=<?= $nb['id'] ?>" class="action-btn share">
                                                <i class="bi bi-share"></i><span>Chia sẻ</span>
                                            </a>
                                        </div>

                                        <div class="notebook-footer">
                                            <div class="notebook-meta">
                                                <i class="bi bi-collection"></i>
                                                <span><?= (int) ($counts[(int) $nb['id']] ?? 0) ?> từ<?php if (!empty($nb['created_at'])): ?> • tạo <?= date('d/m/Y', strtotime($nb['created_at'])) ?><?php endif; ?></span>
                                            </div>
                                             <div class="notebook-actions-mini">
                                                <button
                                                    type="button"
                                                    class="mini-btn"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#editNotebookModal"
                                                    data-id="<?= (int) $nb['id'] ?>"
                                                    data-title="<?= htmlspecialchars($nb['title'], ENT_QUOTES) ?>"
                                                    data-desc="<?= htmlspecialchars($nb['description'] ?? '', ENT_QUOTES) ?>"
                                                    data-group="<?= $nb['group_id'] !== null ? (int) $nb['group_id'] : '' ?>"
                                                    title="Sửa">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <a href="?delete=<?= $nb['id'] ?>" class="mini-btn danger" onclick="return confirm('Bạn có chắc chắn muốn xoá sổ tay này?');" title="Xoá">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <!-- Khu “Không thuộc nhóm” -->
            <?php if (($selected_group === 'all' || $selected_group === 'none') && (isset($notebooks_by_group[null]) || isset($notebooks_by_group[0]))): ?>
                <div class="group-card fade-in">
                    <div class="group-header">
                        <div class="d-flex align-items-center">
                            <div class="group-icon" style="background: linear-gradient(135deg, #f59e0b 0%, #f97316 100%);">
                                <i class="bi bi-folder2-open"></i>
                            </div>
                            <h3 class="group-title mb-0">Không thuộc nhóm</h3>
                        </div>
                    </div>

                    <div class="notebook-grid">
                        <?php foreach (($notebooks_by_group[null] ?? []) + ($notebooks_by_group[0] ?? []) as $nb): ?>
                            <div class="notebook-card">
                                <div class="notebook-header">
                                    <div class="notebook-avatar" style="background: linear-gradient(135deg, #f59e0b 0%, #f97316 100%);">
                                        <i class="bi bi-journal-text"></i>
                                    </div>
                                    <div class="notebook-title"><?= htmlspecialchars($nb['title']) ?></div>
                                    <div class="notebook-description">
                                        <?= $nb['description'] ? nl2br(htmlspecialchars($nb['description'])) : 'Chưa có mô tả…' ?>
                                    </div>
                                </div>

                                <div class="notebook-actions">
                                    <a href="study_flashcard.php?notebook_id=<?= $nb['id'] ?>" class="action-btn flashcard">
                                        <i class="bi bi-journal-richtext"></i><span>Flashcard</span>
                                    </a>
                                    <a href="study_quiz.php?notebook_id=<?= $nb['id'] ?>" class="action-btn quiz">
                                        <i class="bi bi-question-circle"></i><span>Quiz</span>
                                    </a>
                                    <a href="add_vocab.php?notebook_id=<?= $nb['id'] ?>" class="action-btn vocab">
                                        <i class="bi bi-pencil-square"></i><span>Từ vựng</span>
                                    </a>

                                    <?php $canGender = !empty($genderReady[(int) $nb['id']]); ?>
                                    <?php if ($canGender): ?>
                                        <a href="study_gender.php?notebook_id=<?= $nb['id'] ?>" class="action-btn gender">
                                            <i class="bi bi-gender-ambiguous"></i><span>Giống</span>
                                        </a>
                                    <?php else: ?>
                                        <span class="action-btn gender disabled" title="Chưa có danh từ có giống">
                                            <i class="bi bi-gender-ambiguous"></i><span>Giống</span>
                                        </span>
                                    <?php endif; ?>

                                    <a href="import_excel.php?notebook_id=<?= $nb['id'] ?>" class="action-btn excel">
                                        <i class="bi bi-file-earmark-excel"></i><span>Excel</span>
                                    </a>
                                    <a href="share_notebook.php?notebook_id=<?= $nb['id'] ?>" class="action-btn share">
                                        <i class="bi bi-share"></i><span>Chia sẻ</span>
                                    </a>

                                    <!-- Sửa/Xoá chỉ hiện trong grid trên desktop, mobile ẩn để giữ 2 dòng -->
                                    <button
                                        type="button"
                                        class="action-btn only-desktop"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editNotebookModal"
                                        data-id="<?= (int) $nb['id'] ?>"
                                        data-title="<?= htmlspecialchars($nb['title'], ENT_QUOTES) ?>"
                                        data-desc="<?= htmlspecialchars($nb['description'] ?? '', ENT_QUOTES) ?>"
                                        data-group="<?= $nb['group_id'] !== null ? (int) $nb['group_id'] : '' ?>">
                                        <i class="bi bi-pencil"></i><span>Sửa</span>
                                    </button>
                                    <a href="?delete=<?= $nb['id'] ?>" class="action-btn only-desktop" onclick="return confirm('Bạn có chắc chắn muốn xoá sổ tay này?');">
                                        <i class="bi bi-trash"></i><span>Xoá</span>
                                    </a>
                                </div>

                                <div class="notebook-footer">
                                    <div class="notebook-meta">
                                        <i class="bi bi-collection"></i>
                                        <span><?= (int) ($counts[(int) $nb['id']] ?? 0) ?> từ<?php if (!empty($nb['created_at'])): ?> • tạo <?= date('d/m/Y', strtotime($nb['created_at'])) ?><?php endif; ?></span>
                                    </div>
                                     <div class="notebook-actions-mini">
                                        <button
                                            type="button"
                                            class="mini-btn"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editNotebookModal"
                                            data-id="<?= (int) $nb['id'] ?>"
                                            data-title="<?= htmlspecialchars($nb['title'], ENT_QUOTES) ?>"
                                            data-desc="<?= htmlspecialchars($nb['description'] ?? '', ENT_QUOTES) ?>"
                                            data-group="<?= $nb['group_id'] !== null ? (int) $nb['group_id'] : '' ?>"
                                            title="Sửa">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                         <a href="share_notebook.php?notebook_id=<?= $nb['id'] ?>" class="mini-btn" title="Chia sẻ">
                                             <i class="bi bi-share"></i>
                                         </a>
                                        <a href="?delete=<?= $nb['id'] ?>" class="mini-btn danger" onclick="return confirm('Bạn có chắc chắn muốn xoá sổ tay này?');" title="Xoá">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal tạo nhóm -->
    <div class="modal fade" id="modalAddGroup" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form method="post" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-folder-plus me-2"></i>Tạo nhóm mới</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Tên nhóm</label>
                        <input type="text" name="group_name" class="form-control" placeholder="Nhập tên nhóm..." required>
                        <div class="form-text">Ví dụ: Từ vựng cơ bản, Ngữ pháp nâng cao…</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" name="add_group" class="btn btn-primary"><i class="bi bi-plus-circle me-2"></i>Tạo nhóm</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal tạo sổ tay -->
    <div class="modal fade" id="modalAddNotebook" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form method="post" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-journal-plus me-2"></i>Tạo sổ tay mới</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Tiêu đề</label>
                        <input type="text" name="title" class="form-control" placeholder="Nhập tiêu đề sổ tay..." required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Mô tả</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Mô tả ngắn…"></textarea>
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
                </div>
                <div class="modal-footer">
                    <button type="submit" name="add_notebook" class="btn btn-success"><i class="bi bi-plus-circle me-2"></i>Tạo sổ tay</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal CHỈNH SỬA dùng chung -->
    <div class="modal fade" id="editNotebookModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form method="post" class="modal-content" id="editNotebookForm">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Chỉnh sửa sổ tay</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="notebook_id" id="editNotebookId" value="">
                    <div class="mb-3">
                        <label class="form-label">Tiêu đề</label>
                        <input type="text" name="title" id="editNotebookTitle" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Mô tả</label>
                        <textarea name="description" id="editNotebookDesc" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nhóm</label>
                        <select name="group_id" id="editNotebookGroup" class="form-select">
                            <option value="">-- Không thuộc nhóm --</option>
                            <?php foreach ($groups as $g): ?>
                                <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" name="edit_notebook" class="btn btn-primary">
                        <i class="bi bi-check-circle me-2"></i>Lưu thay đổi
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                </div>
            </form>
        </div>
    </div>

    <!-- libs -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Toggle nhóm
            document.querySelectorAll('.group-header').forEach(function(header) {
                const groupId = header.getAttribute('data-group');
                const grid = document.getElementById('groupGrid' + groupId);
                const icon = header.querySelector('.toggle-icon');
                if (!grid) return;

                if (icon) icon.style.transform = grid.classList.contains('d-none') ? 'rotate(0deg)' : 'rotate(180deg)';

                const toggle = () => {
                    const isHidden = grid.classList.contains('d-none');
                    if (isHidden) {
                        grid.classList.remove('d-none');
                        grid.classList.add('slide-down');
                        if (icon) icon.style.transform = 'rotate(180deg)';
                    } else {
                        grid.classList.add('d-none');
                        grid.classList.remove('slide-down');
                        if (icon) icon.style.transform = 'rotate(0deg)';
                    }
                };

                header.addEventListener('click', function(e) {
                    if (e.target.closest('button[type="submit"], .group-delete-btn, form')) return;
                    toggle();
                });
                if (icon) {
                    icon.addEventListener('click', function(e) {
                        e.stopPropagation();
                        toggle();
                    });
                }
            });

            // Chặn nổi bọt ở form GET (xoá nhóm)
            document.querySelectorAll('form[method="get"] button[type="submit"]').forEach(btn => {
                btn.addEventListener('click', e => e.stopPropagation());
            });

            // Hover card
            document.querySelectorAll('.notebook-card').forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-4px)';
                });
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });

            // Ripple (loại trừ nút mở modal)
            document.querySelectorAll(
                '.modern-btn, .action-btn:not([data-bs-toggle="modal"]), .mini-btn:not([data-bs-toggle="modal"])'
            ).forEach(btn => {
                btn.style.position = 'relative';
                btn.style.overflow = 'hidden';
                btn.addEventListener('click', function(e) {
                    const ripple = document.createElement('span');
                    const rect = this.getBoundingClientRect();
                    const size = Math.max(rect.width, rect.height);
                    ripple.style.width = ripple.style.height = size + 'px';
                    ripple.style.left = (e.clientX - rect.left - size / 2) + 'px';
                    ripple.style.top = (e.clientY - rect.top - size / 2) + 'px';
                    ripple.classList.add('ripple');
                    this.appendChild(ripple);
                    setTimeout(() => ripple.remove(), 600);
                });
            });

            // Auto-hide alert
            setTimeout(function() {
                const alert = document.querySelector('.modern-alert');
                if (alert) {
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-20px)';
                    setTimeout(() => alert.remove(), 300);
                }
            }, 5000);

            // Modal EDIT: đổ dữ liệu động
            const editModalEl = document.getElementById('editNotebookModal');
            if (editModalEl) {
                editModalEl.addEventListener('show.bs.modal', function(event) {
                    const btn = event.relatedTarget;
                    if (!btn) return;

                    const id = btn.getAttribute('data-id') || '';
                    const title = btn.getAttribute('data-title') || '';
                    const desc = btn.getAttribute('data-desc') || '';
                    const group = btn.getAttribute('data-group') ?? '';

                    document.getElementById('editNotebookId').value = id;
                    document.getElementById('editNotebookTitle').value = title;
                    document.getElementById('editNotebookDesc').value = desc;

                    const select = document.getElementById('editNotebookGroup');
                    if (select) {
                        Array.from(select.options).forEach(opt => {
                            opt.selected = (opt.value === (group === null ? '' : String(group)));
                        });
                    }
                });
            }
        });

        // Ripple CSS runtime
        const style = document.createElement('style');
        style.textContent = `
            .ripple{position:absolute;border-radius:50%;background:rgba(255,255,255,.3);pointer-events:none;transform:scale(0);animation:ripple-animation .6s linear;}
            @keyframes ripple-animation{to{transform:scale(2);opacity:0;}}
        `;
        document.head.appendChild(style);
    </script>
</body>

</html>