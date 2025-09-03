# ⚡ GERMANLY – Học tiếng Đức vui mà chất

![Flashcard Banner](http://www.deutsch.ct.ws/assets/meme.jpg)

> Một app nho nhỏ để học to to. Đảo thẻ, làm quiz, chia sẻ sổ tay qua QR – và không cần đăng nhập vẫn học được nếu chủ sổ tay cho phép. Vui là chính, nhớ được từ là lợi.

---

## 🚀 Giới thiệu
GERMANLY là web app giúp bạn học từ vựng tiếng Đức bằng flashcard và quiz với giao diện hiện đại, mượt, gọn. Mục tiêu: Học nhanh – Nhớ lâu – Dùng sướng - Hiệu quả.

---

## 🎯 Mục tiêu dự án
- Tối giản thao tác học và ôn tập hằng ngày
- Học ở mọi nơi: Máy tính, laptop, điện thoại, thậm chí đồng hồ thông minh nếu truy cập internet được :v
- Tập trung vào vốn từ thực dụng, có theo dõi tiến độ
- Vui vẻ lành mạnh: có âm thanh khen thưởng, màu mè vừa đủ

---

## 🌟 Tính năng mới nhất
- **Chia sẻ công khai sổ tay bằng token + QR**  
  Bật/tắt public sổ tay bằng nút chia sẻ ở sổ tay. Người có link/QR có thể học flashcard/quiz mà không cần đăng nhập (không ghi lại trạng thái cá nhân đâu nhé 😜) và đừng quên có thể tạo link cho bạn bè nhập để copy toàn bộ sổ tay thành của riêng cá nhân.

- **Trang công khai lựa chọn chế độ**  
  `public_notebook.php?token=...` với 3 nút: Flashcard, Quiz nghĩa, Quiz giống.

- **Quiz nghĩa (study_quiz.php)**  
  Xáo trộn câu hỏi 1 lần, chỉ lặp lại các câu trả lời sai. Có thống kê đúng/sai, streak, auto-progress.

- **Quiz giống danh từ (study_gender.php)**  
  Câu hỏi 3 lựa chọn der/die/das. Chỉ hiển thị nếu sổ tay có danh từ có giống.

- **Flashcard siu đẹp 😜 (study_flashcard.php)**  
  Lật thẻ, swipe trái/phải, đánh dấu Known/Unknown, nút phát âm, trộn ngẫu nhiên.  
  Mặt sau thẻ tự đổi màu theo giống: die=đỏ, der=xanh dương, das=xanh lá, không xác định=xám.

- **Tra cứu chia động từ từ Database**  
  Nút “⚡ Tra cứu” mở modal lấy dữ liệu từ bảng `verbs` (Infinitive, Präsens, Präteritum, Partizip II, Imperativ, Hilfsverb...). Không còn dùng Netzverb – tất cả nội tại.

- **Tích hợp AI 🤖 🔥**  
  Tích hợp AI để học: `Tra từ vựng`, `Chia động từ`, `Dịch đa ngôn ngữ` và `HỎI ĐÁP 💬` (Đúng rồi đó, bạn có thể chat với AI để giải đáp thắc mắc về tiếng Đức 🔥).

---

## 🛠️ Cài đặt nhanh (local)
1. Clone repo:
```bash
git clone https://github.com/ntdcong/deutsch-flashcard.git
cd deutsch-flashcard
```
2. Cài Composer:
```bash
composer install
```
3. Tạo database và import: `assets/sample.sql` (hoặc `assets/flashcard-backup.sql`) rồi chạy thêm các lệnh ở mục “Cấu hình cơ sở dữ liệu”.
4. Sửa thông tin kết nối trong `db.php`.
```bash
<?php
$host = 'localhost';
$db   = 'flashcard';
$user = 'root'; // Đổi lại nếu cần
$pass = '';
$charset = 'utf8mb4';

// Groq API Key
$GROQ_API_KEY = 'TỰ_LẤY_API_GROQ_NHÉ';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    throw new PDOException($e->getMessage(), (int)$e->getCode());
} 
```
5. Mở trình duyệt: `http://localhost/flashcard` (hoặc domain của bạn).

---

## 🚦 Hướng dẫn dùng nhanh
- Truy cập: https://deutsch.ct.ws (Bỏ qua nếu dùng local)
- Đăng ký → đăng nhập
- Tạo nhóm cho sổ tay (Vd: A1, A2, Hobbys, Musik,...)
- Tạo sổ tay, thêm từ vựng hoặc nhập bằng file Excel
- Học Flashcard (lật/lướt/đánh dấu) hoặc làm Quiz nghĩa/giống
- Chia sẻ công khai: vào trang “Chia sẻ” của sổ tay, bật Public và gửi QR/Link cho bạn bè
- Tra cứu động từ: bấm “⚡ Tra cứu” trong flashcard của động từ
- AI: Truy cập trang AI Tools

---

## 📬 Liên hệ
- **Email:** duycong2580@gmail.com

> App làm cho cá nhân và bạn bè – học cho vui, nhớ cho đã. Nếu thấy cool, share link cho đồng bọn và cùng nhau học nhé! 😎
