# 🖼️ VIG Pix Optimizer

**Tự động tối ưu ảnh ngay khi upload — web nhẹ, tải nhanh, khỏi cần thao tác gì.**

Mỗi lần bạn tải ảnh lên Thư viện Media, plugin tự: thu ảnh về **chiều ngang tối đa** (mặc định 2000px, giữ nguyên tỉ lệ cao), **nén / chuyển sang WebP**, **xoá metadata**, và có thể **chặn ảnh quá nặng**. Ảnh cũ đã có trong thư viện **không bị đụng tới**.

## Vì sao nên dùng

- ⚡ **Web nhẹ & nhanh hơn** — ảnh gọn ngay từ khi upload.
- 🤖 **Tự động** — không phải nhớ nén ảnh thủ công.
- 🤖 **Xoá dấu vết AI** — gỡ chữ ký **C2PA / Content Credentials** + XMP để ảnh AI trông tự nhiên, tránh bị gắn cờ khi đăng web.
- 🛡️ **An toàn** — chỉ xử lý ảnh MỚI; ảnh cũ nguyên vẹn.
- 🔧 **Chạy mọi host** — dùng Imagick nếu có (PNG đẹp hơn), không thì fallback GD.

## Có sẵn những gì

- Hạ **chiều ngang** về mức tối đa (không cắt chiều cao).
- Chất lượng JPEG tuỳ chỉnh; PNG: giữ / giảm màu / chuyển JPEG.
- Xuất **WebP** (tự fallback nếu host không hỗ trợ).
- Xoá metadata (EXIF…); chặn upload ảnh vượt ngưỡng MB.
- **Xoá dấu vết AI (C2PA/Content Credentials + XMP)** — như PixClean, xử lý nhị phân server-side cho JPG/PNG/WebP.

## Cài đặt

1. Tải `vig-pix-optimizer.zip` từ [Releases](../../releases) → Plugins → Add New → Upload → Activate.
2. Vào **VIG Toolkit → Pix Optimizer**, chỉnh thông số (hoặc để mặc định) → Lưu.
3. Xong — cứ upload ảnh như bình thường, plugin lo phần còn lại.

## Cập nhật

Khi có phiên bản mới, WordPress tự báo — bấm **Cập nhật** như mọi plugin.

---

<details>
<summary><b>Dành cho developer / maintainer</b></summary>

> 📚 *Về sau tài liệu kỹ thuật đầy đủ sẽ ở **vigdigital.com**.*

- Chỉ hạ **width** về max (khác `sips -Z`/longest-side); giữ nguyên tên+đuôi (trừ PNG→JPEG đổi đuôi trên file mới). Chỉ xử lý ảnh mới upload.
- Hook: `wp_handle_upload_prefilter` (chặn nặng), `wp_handle_upload` (tối ưu gốc trước khi WP sinh bản con), tắt `big_image_size_threshold`. Option key `vig_imgopt_settings`.
- Menu dưới **VIG Toolkit**; tự cập nhật qua PUC (repo public, `vig_setup_updates(..., true)`).
- Phát hành: bump `Version:` → `git tag v1.4.2 && git push` → CI build zip.

GPL-2.0-or-later © VIG Digital

</details>
