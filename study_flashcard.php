<?php
if (session_status() === PHP_SESSION_NONE) {
session_start();
}
require 'db.php';

// Hỗ trợ chế độ công khai bằng token
$token = $_GET['token'] ?? '';
if ($token !== '') {
    $stmt = $pdo->prepare('SELECT * FROM notebooks WHERE public_token = ? AND is_public = 1');
    $stmt->execute([$token]);
    $notebook = $stmt->fetch();
    if (!$notebook) {
        die('Link không hợp lệ hoặc sổ tay không công khai!');
    }
    $notebook_id = (int) $notebook['id'];
    $user_id = $_SESSION['user_id'] ?? null;  // Không bắt buộc đăng nhập
} else {
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
    $user_id = (int) $_SESSION['user_id'];
    $notebook_id = (int) ($_GET['notebook_id'] ?? 0);
// Kiểm tra quyền sở hữu sổ tay
$stmt = $pdo->prepare('SELECT * FROM notebooks WHERE id=? AND user_id=?');
$stmt->execute([$notebook_id, $user_id]);
$notebook = $stmt->fetch();
if (!$notebook) {
    die('Không tìm thấy sổ tay hoặc bạn không có quyền truy cập!');
    }
}
// Lấy tất cả từ vựng một lần để tải trước
if ($user_id) {
$stmt = $pdo->prepare('SELECT v.*, ls.status FROM vocabularies v
    LEFT JOIN learning_status ls ON v.id = ls.vocab_id AND ls.user_id = ?
    WHERE v.notebook_id = ? ORDER BY v.created_at DESC');
$stmt->execute([$user_id, $notebook_id]);
} else {
    // Truy cập công khai: không có trạng thái cá nhân
    $stmt = $pdo->prepare('SELECT v.*, NULL as status FROM vocabularies v WHERE v.notebook_id = ? ORDER BY v.created_at DESC');
    $stmt->execute([$notebook_id]);
}
$all_vocabs = $stmt->fetchAll();
// API để cập nhật trạng thái vẫn giữ nguyên
if (isset($_GET['action']) && $_GET['action'] === 'update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    // Chỉ cập nhật khi có đăng nhập (không cho public ghi trạng thái)
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Chỉ người đăng nhập mới cập nhật trạng thái được.']);
        exit;
    }
    $vocab_id = (int) ($_POST['vocab_id'] ?? 0);
    $status = $_POST['status'] === 'known' ? 'known' : 'unknown';
    if ($vocab_id > 0) {
        $stmt = $pdo->prepare('SELECT id FROM learning_status WHERE user_id=? AND vocab_id=?');
        $stmt->execute([$user_id, $vocab_id]);
        if ($stmt->fetch()) {
            $pdo
                ->prepare('UPDATE learning_status SET status=?, last_reviewed=NOW() WHERE user_id=? AND vocab_id=?')
                ->execute([$status, $user_id, $vocab_id]);
        } else {
            $pdo
                ->prepare('INSERT INTO learning_status (user_id, vocab_id, status, last_reviewed) VALUES (?, ?, ?, NOW())')
                ->execute([$user_id, $vocab_id, $status]);
        }
        echo json_encode(['success' => true, 'message' => 'Cập nhật thành công.']);
        exit;
    }
    echo json_encode(['success' => false, 'message' => 'Thiếu ID từ vựng.']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Học Flashcard - Trải nghiệm mới</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/flashcard.css" rel="stylesheet">
</head>

<body>
    <?php
    $navbar_config = [
        'type' => 'simple',
        'back_link' => isset($token) && $token !== '' ? 'public_notebook.php?token=' . urlencode($token) : 'dashboard.php',
        'page_title' => $notebook['title'],
        'show_logout' => false
    ];
    include 'includes/navbar.php';
    ?>

    <script type="application/json" id="vocab-data">
        <?= json_encode($all_vocabs, JSON_UNESCAPED_UNICODE) ?>
    </script>

    <div class="main-content">
        <div class="header-section">
            <h1>📖 Học Flashcard</h1>
            <div class="progress-container">
                <div class="progress-bar" id="progress-bar"></div>
            </div>
            <div class="stats-container">
                <div class="stat-badge">
                    <i class="bi bi-collection"></i>
                    <span id="total-count">0</span> từ
                </div>
                <div class="stat-badge">
                    <i class="bi bi-check-circle"></i>
                    <span id="known-count">0</span> đã biết
                </div>
                <div class="shuffle-container" style="margin-bottom: 20px;">
                    <button id="btn-shuffle" class="btn btn-light btn-action">
                        <i class="bi bi-shuffle"></i> Trộn từ vựng
                    </button>
                </div>
            </div>
        </div>

        <div class="card-stack-container" id="cardStackContainer">
            <div class="card-stack" id="cardStack"></div>
        </div>

        <div class="action-buttons">
            <button id="btn-prev" class="btn btn-light btn-action">Trước</button>
            <button id="btn-flip" class="btn btn-primary btn-action">Lật thẻ</button>
            <button id="btn-next" class="btn btn-light btn-action">Tiếp</button>
        </div>

        <div class="status-buttons">
            <button id="btn-unknown" class="btn btn-unknown btn-status" title="Chưa biết">
                <i class="bi bi-x-lg"></i>
            </button>
            <button id="btn-known" class="btn btn-known btn-status" title="Đã biết">
                <i class="bi bi-check-lg"></i>
            </button>
        </div>

        <div class="keyboard-shortcuts" id="keyboard-shortcuts">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h4 class="mb-0" style="padding-right:8px;">Phím tắt </h4>
                <button class="btn btn-sm btn-outline-light" id="toggle-shortcuts" title="Ẩn/Hiện phím tắt">
                    <i class="bi bi-eye" id="shortcuts-icon"></i>
                </button>
            </div>
            <div id="shortcuts-content">
            <ul>
                <li><kbd>←</kbd> Thẻ trước</li>
                <li><kbd>→</kbd> Thẻ tiếp</li>
                <li><kbd>Space</kbd> <kbd> Enter</kbd> Lật thẻ</li>
                <li><kbd>K</kbd> Đánh dấu đã biết</li>
                <li><kbd>U</kbd> Đánh dấu chưa biết</li>
                <li><kbd>S</kbd> Trộn từ vựng</li>
                <li><i>Lưu ý: Chức năng phát âm có thể không hoạt động trên iPhone hoặc MacOS</i></li>
                <i class="text-center"><a href="tts_fix.php" target="_blank" class="text-white">Tham khảo cách sửa tại đây</a></i>
            </ul>
            </div>
        </div>

        <div class="caution text-center mt-2">
            Lưu ý: Chức năng phát âm có thể không hoạt động nếu tiếng Đức chưa được cài đặt trên thiết bị
            <a href="tts_fix.php" target="_blank" class="text-white">Tham khảo cách sửa tại đây</a>
        </div>

    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const notebookId = <?= $notebook_id ?>;
            let allVocabs = [];
            let currentIndex = 0;
            let isAnimating = false;

            try {
                const vocabDataElement = document.getElementById('vocab-data');
                allVocabs = JSON.parse(vocabDataElement.textContent);
            } catch (e) {
                console.error("Lỗi khi đọc dữ liệu từ vựng:", e);
            }

            // Thêm hàm trộn mảng (Fisher-Yates shuffle algorithm)
            function shuffleArray(array) {
                const newArray = [...array];
                for (let i = newArray.length - 1; i > 0; i--) {
                    const j = Math.floor(Math.random() * (i + 1));
                    [newArray[i], newArray[j]] = [newArray[j], newArray[i]];
                }
                return newArray;
            }

            // Thêm sự kiện click cho nút trộn
            document.getElementById('btn-shuffle').addEventListener('click', () => {
                if (!isAnimating && allVocabs.length > 1) {
                    // Phát âm thanh khi trộn
                    try {
                        const shuffleSound = new Audio('assets/shuffle.mp3');
                        shuffleSound.volume = 0.5;
                        shuffleSound.play();
                    } catch (e) {
                        console.log('Không thể phát âm thanh:', e);
                    }

                    // Trộn mảng từ vựng
                    allVocabs = shuffleArray(allVocabs);
                    // Đặt lại vị trí hiện tại về đầu
                    currentIndex = 0;
                    // Cập nhật lại stack thẻ
                    updateCardStack();

                    // Hiển thị thông báo
                    const toast = document.createElement('div');
                    toast.className = 'toast-notification';
                    toast.textContent = 'Đã trộn từ vựng!';
                    document.body.appendChild(toast);

                    // Hiệu ứng hiển thị và ẩn thông báo
                    setTimeout(() => toast.classList.add('show'), 10);
                    setTimeout(() => {
                        toast.classList.remove('show');
                        setTimeout(() => toast.remove(), 300);
                    }, 2000);
                }
            });

            const urlParams = new URLSearchParams(window.location.search);
            const initialIndexParam = parseInt(urlParams.get('i'), 10);
            if (!isNaN(initialIndexParam) && initialIndexParam >= 0 && initialIndexParam < allVocabs.length) {
                currentIndex = initialIndexParam;
            }

            const cardStack = document.getElementById('cardStack');
            const progressBar = document.getElementById('progress-bar');
            const totalCount = document.getElementById('total-count');
            const knownCount = document.getElementById('known-count');

            // Initialize counts
            totalCount.textContent = allVocabs.length;
            updateKnownCount();

            function getCardBackBgByGenus(genus) {
                if (!genus) return '#e5e7eb'; // gray
                const g = String(genus).trim().toLowerCase();
                if (g === 'die') return '#EF9A9A'; // red 200
                if (g === 'der') return '#90CAF9'; // blue 200
                if (g === 'das') return '#A5D6A7'; // green 200
                return '#e5e7eb';
            }

            function createCardElement(vocab, index) {
                const card = document.createElement('div');
                card.className = 'flashcard';
                card.dataset.index = index;
                card.dataset.id = vocab.id;
                card.innerHTML = `
            <div class="swipe-indicator left">Chưa biết</div>
            <div class="swipe-indicator right">Đã biết</div>
            <div class="card-inner">
                <div class="card-front">
                    <div id="word-display">${escapeHtml(vocab.word)}</div>
                    ${vocab.phonetic ? `<div class="phonetic">[${escapeHtml(vocab.phonetic)}]</div>` : ''}
                    <button class="btn btn-sm btn-outline-primary mt-2 btn-audio">
                        <i class="bi bi-volume-up"></i> Nghe
                    </button>
                </div>
                <div class="card-back">
                    <div class="card-back-content">
                        <div class="vocab-info" style="font-size:24px; font-weight:bold;">
                        ${nl2br(escapeHtml(vocab.meaning))}
                        </div>
                        ${vocab.note ? `<div class="vocab-info"><strong>Ghi chú:</strong> ${nl2br(escapeHtml(vocab.note))}</div>` : ''}
                        ${vocab.plural ? `<div class="vocab-info"><strong>Số nhiều:</strong> ${escapeHtml(vocab.plural)}</div>` : ''}
                        ${vocab.genus ? `<div class=\"vocab-info\"><strong>Giống:</strong> ${escapeHtml(vocab.genus)}</div>` : ''}
                        <button class="btn btn-sm btn-primary mt-2"
                            data-conjugation-word="${escapeHtml(vocab.word)}">
                            ⚡ Tra cứu
                        </button>
                    </div>
                </div>
            </div>
        `;

                card.querySelector('.btn-audio').addEventListener('click', (e) => {
                    e.stopPropagation();
                    speakWord(vocab.word);
                });

                // Set background color based on genus
                const backEl = card.querySelector('.card-back');
                if (backEl) {
                    backEl.style.background = getCardBackBgByGenus(vocab.genus);
                }

                // Add conjugation button event listener
                const conjugationBtn = card.querySelector('[data-conjugation-word]');
                if (conjugationBtn) {
                    conjugationBtn.addEventListener('click', (e) => {
                        e.stopPropagation();
                        const word = conjugationBtn.getAttribute('data-conjugation-word');
                        openConjugationModal(word);
                    });
                }

                return card;
            }

            function updateCardStack() {
                cardStack.innerHTML = '';
                const cardsToCreate = [];

                if (allVocabs[currentIndex]) {
                    cardsToCreate.push({
                        vocab: allVocabs[currentIndex],
                        index: currentIndex
                    });
                }

                const nextIndex = (currentIndex + 1) % allVocabs.length;
                if (allVocabs.length > 1 && nextIndex !== currentIndex) {
                    cardsToCreate.push({
                        vocab: allVocabs[nextIndex],
                        index: nextIndex
                    });
                }

                const afterNextIndex = (currentIndex + 2) % allVocabs.length;
                if (allVocabs.length > 2 && afterNextIndex !== currentIndex && afterNextIndex !== nextIndex) {
                    cardsToCreate.push({
                        vocab: allVocabs[afterNextIndex],
                        index: afterNextIndex
                    });
                }

                cardsToCreate.reverse().forEach((data, i) => {
                    const cardEl = createCardElement(data.vocab, data.index);
                    if (i === 1) cardEl.classList.add('card--next');
                    if (i === 0) cardEl.classList.add('card--after-next');
                    cardStack.appendChild(cardEl);
                });

                updateUI();
            }

            function updateUI() {
                if (!allVocabs[currentIndex]) return;

                // Update progress bar
                const progressPercent = ((currentIndex + 1) / allVocabs.length) * 100;
                progressBar.style.width = `${progressPercent}%`;

                // Update known count
                updateKnownCount();
            }

            function updateKnownCount() {
                const known = allVocabs.filter(v => v.status === 'known').length;
                knownCount.textContent = known;
            }

            function speakWord(text) {
                if ('speechSynthesis' in window) {
                    speechSynthesis.cancel();
                    const utterance = new SpeechSynthesisUtterance(text);
                    utterance.lang = 'de-DE';
                    utterance.rate = 0.8;
                    speechSynthesis.speak(utterance);
                }
            }

            async function updateStatusOnServer(vocabId, status) {
                try {
                    await fetch(`?action=update_status¬ebook_id=${notebookId}`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: `vocab_id=${vocabId}&status=${status}`
                    });
                } catch (error) {
                    console.error("Lỗi khi cập nhật trạng thái:", error);
                }
            }

            document.getElementById('btn-flip').addEventListener('click', () => {
                const topCard = cardStack.lastChild;
                if (topCard) {
                    topCard.classList.toggle('flipped');
                }
            });

            document.getElementById('btn-known').addEventListener('click', () => {
                if (!isAnimating) {
                    processSwipe('known');
                }
            });

            document.getElementById('btn-unknown').addEventListener('click', () => {
                if (!isAnimating) {
                    processSwipe('unknown');
                }
            });

            document.getElementById('btn-next').addEventListener('click', () => {
                if (!isAnimating) processSwipe(allVocabs[currentIndex]?.status || 'unknown');
            });

            document.getElementById('btn-prev').addEventListener('click', () => {
                if (isAnimating || allVocabs.length < 2) return;
                isAnimating = true;

                // Cập nhật index trước
                currentIndex = (currentIndex - 1 + allVocabs.length) % allVocabs.length;

                // Cập nhật stack ngay lập tức để lấy card mới
                updateCardStack();

                // Lấy card mới và thêm hiệu ứng bay vào từ bên trái
                const newCardEl = cardStack.lastChild;
                if (newCardEl) {
                    // Đặt vị trí ban đầu bên ngoài bên trái
                    newCardEl.style.transform = 'translateX(-100%)';
                    newCardEl.style.opacity = '0';

                    // Trigger reflow để animation hoạt động
                    void newCardEl.offsetWidth;

                    // Animation bay vào từ trái
                    newCardEl.style.transition = 'all 0.4s ease-out';
                    newCardEl.style.transform = 'translateX(0)';
                    newCardEl.style.opacity = '1';

                    // Xóa transition sau khi hoàn thành
                    setTimeout(() => {
                        newCardEl.style.transition = '';
                        isAnimating = false;
                    }, 400);
                } else {
                    isAnimating = false;
                }
            });

            function escapeHtml(text) {
                if (!text) return '';
                const div = document.createElement('div');
                div.innerText = text;
                return div.innerHTML;
            }

            function nl2br(str) {
                if (!str) return '';
                return str.replace(/(\r\n|\r|\n)/g, '<br>');
            }

            function openConjugationModal(word) {
                document.getElementById('modal-word').textContent = word;
                
                // Reset modal content
                document.getElementById('db-result').innerHTML = `
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Đang tải...</span>
                        </div>
                        <p class="mt-2">Đang tra cứu từ database...</p>
                    </div>
                `;
                
                // Show modal
                const modal = new bootstrap.Modal(document.getElementById('conjugationModal'));
                modal.show();
                
                // Load database result immediately
                loadDatabaseConjugation(word);
            }

            async function loadDatabaseConjugation(word) {
                try {
                    const response = await fetch('conjugation_db.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `word=${encodeURIComponent(word)}`
                    });
                    
                    const result = await response.text();
                    document.getElementById('db-result').innerHTML = result;
                } catch (error) {
                    document.getElementById('db-result').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle"></i> Lỗi khi tra cứu từ database: ${error.message}
                        </div>
                    `;
                }
            }



            function processSwipe(status) {
                const currentCardEl = cardStack.lastChild;
                if (currentCardEl) {
                    // Add swipe animation
                    currentCardEl.classList.add('card--out');
                    if (status === 'known') {
                        currentCardEl.style.transform = 'translateX(200%) rotate(30deg)';
                    } else {
                        currentCardEl.style.transform = 'translateX(-200%) rotate(-30deg)';
                    }

                    // Show indicator
                    const indicator = currentCardEl.querySelector(`.swipe-indicator.${status === 'known' ? 'right' : 'left'}`);
                    if (indicator) {
                        indicator.style.opacity = '1';
                    }
                }

                updateStatusOnServer(allVocabs[currentIndex].id, status);
                allVocabs[currentIndex].status = status;
                currentIndex = (currentIndex + 1) % allVocabs.length;

                setTimeout(() => {
                    updateCardStack();
                    isAnimating = false;
                }, 400);
            }

            if (allVocabs && allVocabs.length > 0) {
                updateCardStack();
            } else {
                document.getElementById('cardStackContainer').innerHTML = `
            <div class="empty-state">
                <i class="bi bi-book"></i>
                <h3>Sổ tay trống</h3>
                <p>Chưa có từ vựng nào trong sổ tay này để học.</p>
            </div>
        `;
                document.querySelector('.action-buttons').style.display = 'none';
                document.querySelector('.status-buttons').style.display = 'none';
                document.querySelector('.progress-container').style.display = 'none';
                document.querySelector('.stats-container').style.display = 'none';
            }

            // Toggle keyboard shortcuts visibility
            document.getElementById('toggle-shortcuts').addEventListener('click', () => {
                const content = document.getElementById('shortcuts-content');
                const icon = document.getElementById('shortcuts-icon');
                
                if (content.style.display === 'none') {
                    content.style.display = 'block';
                    icon.className = 'bi bi-eye';
                } else {
                    content.style.display = 'none';
                    icon.className = 'bi bi-eye-slash';
                }
            });

            // Keyboard shortcuts
            document.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowLeft') {
                    document.getElementById('btn-prev').click();
                } else if (e.key === 'ArrowRight') {
                    document.getElementById('btn-next').click();
                } else if (e.key === ' ' || e.key === 'Enter') {
                    e.preventDefault();
                    document.getElementById('btn-flip').click();
                } else if (e.key === 'k' || e.key === 'K') {
                    document.getElementById('btn-known').click();
                } else if (e.key === 'u' || e.key === 'U') {
                    document.getElementById('btn-unknown').click();
                } else if (e.key === 'f' || e.key === 's' || e.key === 'S') {
                    document.getElementById('btn-shuffle').click();
                }
            });
        });
    </script>
    <!-- Modal tra cứu động từ -->
    <div class="modal fade" id="conjugationModal" tabindex="-1" aria-labelledby="conjugationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="conjugationModalLabel">
                        <i class="bi bi-translate"></i> Tra cứu động từ: <span id="modal-word"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="db-result">
                        <div class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Đang tải...</span>
                            </div>
                            <p class="mt-2">Đang tra cứu từ database...</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>