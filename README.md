# 🤖 AZMinion - Hệ Thống Đệ Tử Tự Động Cho PocketMine-MP

**AZMinion** là plugin cung cấp hệ thống đệ tử (Minion) giúp **tự động hóa các công việc cày cuốc** như đào khoáng sản, chặt gỗ và câu cá trên server PocketMine-MP.  
Plugin được thiết kế **tối ưu hiệu suất (Anti-Lag)**, tích hợp **hệ thống kinh tế tùy chỉnh** và cơ chế **quản lý chuyên sâu**, phù hợp cho server sinh tồn, prison, RPG.

---

## 🚀 Tính Năng Nổi Bật

### 🤖 Các Loại Minion
- **⛏ Miner Minion**  
  Tự động tìm kiếm và khai thác các loại quặng trong khu vực hoạt động.

- **🪓 Lumber Minion**  
  Tự động quét và chặt cây gỗ, hỗ trợ **trồng lại cây** sau khi thu hoạch.

- **🎣 Fisher Minion**  
  Câu cá thông minh với:
  - Tính toán **quỹ đạo ném cần (Ballistic Trajectory)**  
  - Tự tìm **mặt nước thoáng** để câu hiệu quả nhất

---

## 🛠️ Cơ Chế Hoạt Động Thông Minh

- **Thuật toán tìm đường nâng cao**
  - Tự phá lá cây chắn đường
  - Biết nhảy qua vật cản
  - Tự quay về vị trí spawn nếu bị kẹt

- **Radar Quét Phân Đoạn (Anti-Lag)**
  - Quét từng lớp bán kính nhỏ theo tick
  - Tránh quét toàn bộ khu vực cùng lúc
  - Giảm đáng kể tải cho server đông người chơi

---

## 💰 Hệ Thống Kinh Tế & Nâng Cấp

- **Nâng cấp Minion**
  - 5 cấp độ hoạt động
  - Tăng tốc độ làm việc
  - Sử dụng **Xu hoặc Gold**

- **Mở rộng kho chứa**
  - Nâng cấp tối đa **5 kho phụ**
  - Tổng cộng **6 rương đôi**
  - Thanh toán bằng **Gold**

- **Auto Sell (Tự động bán)**
  - Mua **vĩnh viễn**
  - Minion tự động bán vật phẩm khi kho đầy

- **Bán thủ công**
  - Menu bán toàn bộ vật phẩm
  - Giá trị:
    - Quặng / gỗ: cấu hình tùy chỉnh
    - Cá: lấy trực tiếp từ plugin **AZFishingRod**

---

## 🎮 Hướng Dẫn Sử Dụng

- **Nhận Minion**
/minion <loại> <cấp>

- **Đặt Minion**
- Nhấn chuột phải vào block để triệu hồi Minion

- **Quản lý Minion**
- Nhấn vào Minion để mở:
  - Kho đồ
  - Nâng cấp
  - Cài đặt Auto Sell

---

## 📋 Yêu Cầu & Tích Hợp

- **PocketMine-MP**: `5.0+`
- **InvMenu**: Quản lý giao diện rương
- **pmforms**: Hệ thống Form & Menu
- **AZFishingRod**: Tích hợp kinh tế và dữ liệu cá

---

## 🛠️ Cài Đặt

1. Tải plugin và bỏ vào thư mục `plugins/`
2. Cấu hình file `db.php` để kết nối cơ sở dữ liệu cho hệ thống kinh tế
3. Khởi động lại server

---

## 📌 Ghi Chú
- Plugin được thiết kế hướng **hiệu suất & mở rộng**
- Phù hợp cho server đông người chơi
- Dễ dàng tích hợp với hệ sinh thái plugin AZ

---

🔥 **AZMinion – Biến việc cày cuốc thành tự động, tối ưu và chuyên nghiệp!**
