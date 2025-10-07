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
$message_type = 'info';

// Thêm từ mới
if (isset($_POST['add_vocab'])) {
    $word = trim($_POST['word'] ?? '');
    $phonetic = trim($_POST['phonetic'] ?? '');
    $meaning = trim($_POST['meaning'] ?? '');
    $note = trim($_POST['note'] ?? '');
    $plural = trim($_POST['plural'] ?? '');
    $genus = trim($_POST['genus'] ?? '');

    if ($word && $meaning) {
        $stmt = $pdo->prepare('INSERT INTO vocabularies (notebook_id, word, phonetic, meaning, note, plural, genus) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$notebook_id, $word, $phonetic, $meaning, $note, $plural, $genus]);
        $message = '✅ Đã thêm từ mới!';
        $message_type = 'success';
    } else {
        $message = '❌ Vui lòng nhập từ vựng và nghĩa!';
        $message_type = 'danger';
    }
}

// Xóa từ
if (isset($_GET['delete'])) {
    $vocab_id = (int)$_GET['delete'];
    $pdo->prepare('DELETE FROM learning_status WHERE vocab_id=?')->execute([$vocab_id]);
    $pdo->prepare('DELETE FROM vocabularies WHERE id=? AND notebook_id=?')->execute([$vocab_id, $notebook_id]);
    $message = '🗑️ Đã xóa từ!';
    $message_type = 'warning';
}

// Cập nhật từ vựng
if (isset($_POST['edit_vocab'])) {
    $id = (int)$_POST['vocab_id'];
    $word = trim($_POST['word']);
    $phonetic = trim($_POST['phonetic']);
    $meaning = trim($_POST['meaning']);
    $note = trim($_POST['note']);
    $plural = trim($_POST['plural']);
    $genus = trim($_POST['genus']);

    if ($word && $meaning) {
        $stmt = $pdo->prepare('UPDATE vocabularies SET word=?, phonetic=?, meaning=?, note=?, plural=?, genus=? WHERE id=? AND notebook_id=?');
        $stmt->execute([$word, $phonetic, $meaning, $note, $plural, $genus, $id, $notebook_id]);
        $message = '✏️ Đã cập nhật từ!';
        $message_type = 'success';
    } else {
        $message = '❌ Vui lòng nhập đầy đủ thông tin!';
        $message_type = 'danger';
    }
}

// Tìm kiếm
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

$where_clause = '';
$params = [$notebook_id];

if ($search) {
    $where_clause = "AND (word LIKE ? OR meaning LIKE ? OR note LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Tổng số từ vựng
$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM vocabularies WHERE notebook_id=? $where_clause");
$totalStmt->execute($params);
$total = $totalStmt->fetchColumn();
$totalPages = ceil($total / $limit);

// Lấy danh sách từ vựng
$params[] = $limit;
$params[] = $offset;

$stmt = $pdo->prepare("SELECT * FROM vocabularies WHERE notebook_id=? $where_clause ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->execute($params);
$vocabs = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Quản lý từ vựng - <?= htmlspecialchars($notebook['title']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary: #5b67ca;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
        }

        body {
            background: #f4f6fb;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Noto Sans', sans-serif;
        }

        .add-form-wrapper {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--primary);
        }

        .quick-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-field {
            position: relative;
        }

        .form-field label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #666;
            margin-bottom: 0.35rem;
            display: block;
        }

        .form-field input {
            width: 100%;
            padding: 0.6rem 0.8rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            transition: all 0.2s;
            font-size: 0.95rem;
        }

        .form-field input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(91, 103, 202, 0.1);
        }

        .gender-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .gender-btn {
            flex: 1;
            padding: 0.5rem;
            border: 2px solid #e0e0e0;
            background: white;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .gender-btn:hover {
            border-color: var(--primary);
            background: rgba(91, 103, 202, 0.05);
        }

        .gender-btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        .form-actions {
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
        }

        .btn-submit {
            background: var(--success);
            color: white;
            border: none;
            padding: 0.7rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-submit:hover {
            background: #218838;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
        }

        .btn-clear {
            background: #6c757d;
            color: white;
            border: none;
            padding: 0.7rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-clear:hover {
            background: #5a6268;
        }

        .vocab-list {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .vocab-item {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.2s;
        }

        .vocab-item:hover {
            background: #f8f9fa;
        }

        .vocab-item:last-child {
            border-bottom: none;
        }

        .vocab-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 0.5rem;
        }

        .vocab-word {
            font-size: 1.1rem;
            font-weight: 700;
            color: #2c3e50;
        }

        .vocab-genus {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }

        .vocab-genus.der {
            background: #e3f2fd;
            color: #1976d2;
        }

        .vocab-genus.die {
            background: #fce4ec;
            color: #c2185b;
        }

        .vocab-genus.das {
            background: #fff3e0;
            color: #f57c00;
        }

        .vocab-meaning {
            color: #555;
            font-size: 0.95rem;
            margin-bottom: 0.5rem;
        }

        .vocab-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.85rem;
            color: #888;
        }

        .vocab-actions {
            display: flex;
            gap: 0.5rem;
        }

        .btn-icon {
            width: 32px;
            height: 32px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-icon.edit {
            background: #fff3cd;
            color: #856404;
        }

        .btn-icon.edit:hover {
            background: #ffc107;
            color: white;
        }

        .btn-icon.delete {
            background: #f8d7da;
            color: #721c24;
        }

        .btn-icon.delete:hover {
            background: #dc3545;
            color: white;
        }

        .search-bar {
            margin-bottom: 1.5rem;
            display: flex;
            gap: 0.5rem;
        }

        .search-bar input {
            flex: 1;
            padding: 0.7rem 1rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.95rem;
        }

        .search-bar input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .search-bar button {
            padding: 0.7rem 1.5rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }

        .list-header {
            padding: 1rem 1.25rem;
            background: #f8f9fa;
            border-bottom: 2px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .pagination-wrapper {
            display: flex;
            justify-content: center;
            margin-top: 1.5rem;
            gap: 0.5rem;
        }

        .page-btn {
            padding: 0.5rem 0.9rem;
            border: 2px solid #e0e0e0;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            color: #333;
        }

        .page-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .page-btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        .shortcut-hint {
            font-size: 0.8rem;
            color: #999;
            margin-top: 0.5rem;
            text-align: right;
        }

        @media (max-width: 768px) {
            .quick-form {
                grid-template-columns: 1fr;
            }

            .vocab-header {
                flex-direction: column;
            }

            .vocab-actions {
                margin-top: 0.75rem;
            }
        }
    </style>
</head>

<body>
    <?php
    $navbar_config = [
        'type' => 'minimal',
        'back_link' => 'dashboard.php',
        'page_title' => '📖 ' . $notebook['title'],
        'show_logout' => false,
        'show_brand' => false,
    ];
    include 'includes/navbar.php';
    ?>

    <div class="container-fluid px-3 px-md-4 mt-3" style="max-width: 1200px;">
        <?php if ($message): ?>
            <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Quick Add Form -->
        <div class="add-form-wrapper">
            <h5 class="mb-3"><i class="bi bi-plus-circle"></i> Thêm từ vựng nhanh</h5>
            <form method="post" id="quickAddForm">
                <div class="quick-form">
                    <div class="form-field">
                        <label>Từ vựng <span class="text-danger">*</span></label>
                        <input type="text" name="word" id="wordInput" placeholder="Ví dụ: Haus" required autofocus>
                    </div>

                    <div class="form-field">
                        <label>Nghĩa <span class="text-danger">*</span></label>
                        <input type="text" name="meaning" id="meaningInput" placeholder="Ví dụ: Ngôi nhà" required>
                    </div>

                    <div class="form-field">
                        <label>Giống</label>
                        <input type="text" name="genus" id="genusInput" placeholder="der/die/das" readonly>
                        <div class="gender-buttons mt-2">
                            <button type="button" class="gender-btn" onclick="setGender('der')">der</button>
                            <button type="button" class="gender-btn" onclick="setGender('die')">die</button>
                            <button type="button" class="gender-btn" onclick="setGender('das')">das</button>
                        </div>
                    </div>

                    <div class="form-field">
                        <label>Số nhiều</label>
                        <input type="text" name="plural" id="pluralInput" placeholder="Ví dụ: Häuser">
                    </div>

                    <div class="form-field">
                        <label>Phiên âm</label>
                        <input type="text" name="phonetic" id="phoneticInput" placeholder="IPA (optional)">
                    </div>

                    <div class="form-field">
                        <label>Ghi chú</label>
                        <input type="text" name="note" id="noteInput" placeholder="Thêm ghi chú...">
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-clear" onclick="clearForm()">
                        <i class="bi bi-x-circle"></i> Xóa
                    </button>
                    <button type="submit" name="add_vocab" class="btn-submit">
                        <i class="bi bi-check-circle"></i> Thêm từ
                    </button>
                </div>
            </form>
        </div>

        <!-- Search Bar -->
        <div class="search-bar">
            <form method="get" style="display: flex; gap: 0.5rem; flex: 1;">
                <input type="hidden" name="notebook_id" value="<?= $notebook_id ?>">
                <input type="text" name="search" placeholder="Tìm kiếm từ vựng..." value="<?= htmlspecialchars($search) ?>">
                <button type="submit"><i class="bi bi-search"></i> Tìm</button>
            </form>
        </div>

        <!-- Vocab List -->
        <div class="vocab-list">
            <div class="list-header">
                <h6 class="mb-0">
                    <i class="bi bi-list-ul"></i> Danh sách từ vựng
                    <?php if ($search): ?>
                        <small class="text-muted">(Kết quả cho: "<?= htmlspecialchars($search) ?>")</small>
                    <?php endif; ?>
                </h6>
                <small class="text-muted"><?= $total ?> từ • Trang <?= $page ?>/<?= $totalPages ?></small>
            </div>

            <?php foreach ($vocabs as $v): ?>
                <div class="vocab-item">
                    <div class="vocab-header">
                        <div>
                            <span class="vocab-word"><?= htmlspecialchars($v['word']) ?></span>
                            <?php if ($v['genus']): ?>
                                <span class="vocab-genus <?= strtolower($v['genus']) ?>">
                                    <?= htmlspecialchars($v['genus']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="vocab-actions">
                            <button class="btn-icon edit" data-bs-toggle="modal" data-bs-target="#editModal"
                                onclick="openEditModal(<?= $v['id'] ?>, <?= htmlspecialchars(json_encode([
                                                                            'word' => $v['word'],
                                                                            'meaning' => $v['meaning'],
                                                                            'genus' => $v['genus'],
                                                                            'plural' => $v['plural'],
                                                                            'phonetic' => $v['phonetic'],
                                                                            'note' => $v['note']
                                                                        ])) ?>)" title="Sửa">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn-icon delete" onclick="deleteVocab(<?= $v['id'] ?>)" title="Xóa">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                    <div class="vocab-meaning"><?= htmlspecialchars($v['meaning']) ?></div>
                    <div class="vocab-meta">
                        <?php if ($v['plural']): ?>
                            <span><i class="bi bi-card-list"></i> Plural: <?= htmlspecialchars($v['plural']) ?></span>
                        <?php endif; ?>
                        <?php if ($v['phonetic']): ?>
                            <span><i class="bi bi-mic"></i> <?= htmlspecialchars($v['phonetic']) ?></span>
                        <?php endif; ?>
                        <?php if ($v['note']): ?>
                            <span><i class="bi bi-info-circle"></i> <?= htmlspecialchars($v['note']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (empty($vocabs)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-inbox" style="font-size: 3rem;"></i>
                    <p class="mt-2">Chưa có từ vựng nào</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination-wrapper">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?notebook_id=<?= $notebook_id ?>&page=<?= $i ?>&search=<?= urlencode($search) ?>"
                        class="page-btn <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
        </br>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Set gender
        function setGender(gender) {
            document.getElementById('genusInput').value = gender;
            document.querySelectorAll('.gender-btn').forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
        }

        // Clear form
        function clearForm() {
            document.getElementById('quickAddForm').reset();
            document.querySelectorAll('.gender-btn').forEach(btn => btn.classList.remove('active'));
            document.getElementById('wordInput').focus();
        }

        // Delete vocab
        function deleteVocab(id) {
            if (confirm('Bạn có chắc chắn muốn xóa từ này?')) {
                window.location.href = '?notebook_id=<?= $notebook_id ?>&delete=' + id;
            }
        }

        // Edit vocab modal
        function openEditModal(id, data) {
            document.getElementById('edit_vocab_id').value = id;
            document.getElementById('edit_word').value = data.word;
            document.getElementById('edit_meaning').value = data.meaning;
            document.getElementById('edit_genus').value = data.genus;
            document.getElementById('edit_plural').value = data.plural;
            document.getElementById('edit_phonetic').value = data.phonetic;
            document.getElementById('edit_note').value = data.note;

            // Highlight gender button in modal
            document.querySelectorAll('.gender-btn-edit').forEach(btn => {
                if (btn.textContent === data.genus) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
        }

        // Set gender for edit modal
        function setGenderEdit(gender) {
            document.getElementById('edit_genus').value = gender;
            document.querySelectorAll('.gender-btn-edit').forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Ctrl+Enter to submit
            if (e.ctrlKey && e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('quickAddForm').submit();
            }

            // Esc to clear
            if (e.key === 'Escape') {
                clearForm();
            }
        });

        // Auto-focus on page load
        window.addEventListener('load', () => {
            document.getElementById('wordInput').focus();
        });
    </script>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil-square"></i> Chỉnh sửa từ vựng
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="post">
                    <input type="hidden" name="vocab_id" id="edit_vocab_id">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Từ vựng <span class="text-danger">*</span></label>
                                <input type="text" name="word" id="edit_word" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Nghĩa <span class="text-danger">*</span></label>
                                <input type="text" name="meaning" id="edit_meaning" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Giống</label>
                                <input type="text" name="genus" id="edit_genus" class="form-control" readonly>
                                <div class="gender-buttons mt-2">
                                    <button type="button" class="gender-btn gender-btn-edit" onclick="setGenderEdit('der')">der</button>
                                    <button type="button" class="gender-btn gender-btn-edit" onclick="setGenderEdit('die')">die</button>
                                    <button type="button" class="gender-btn gender-btn-edit" onclick="setGenderEdit('das')">das</button>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Số nhiều</label>
                                <input type="text" name="plural" id="edit_plural" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Phiên âm</label>
                                <input type="text" name="phonetic" id="edit_phonetic" class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold">Ghi chú</label>
                                <input type="text" name="note" id="edit_note" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> Hủy
                        </button>
                        <button type="submit" name="edit_vocab" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i> Cập nhật
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>

</html>