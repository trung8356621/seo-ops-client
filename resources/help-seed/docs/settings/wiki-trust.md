---
key: settings.editor.wiki_trust
title: Trusted External Domains
summary: Danh sách domain/pattern dùng để nhận diện liên kết ngoài tin cậy khi chấm SEO.
group: settings
sort_order: 120
keywords: []
updated_at: '2026-09-28'
---
# Trusted External Domains (Domain ngoài tin cậy)

Danh sách domain và URL pattern tin cậy dùng để chấm điểm liên kết ngoài uy tín trong tab SEO và phân loại liên kết trong `seo_link_maps`.

## Cách nhập và cơ chế chuẩn hóa

Người dùng có thể dán domain hoặc URL đầy đủ vào ô tag rồi nhấn **Enter**:

- **Chuẩn hóa tự động:** Hệ thống tự động trích xuất hostname chuẩn và lưu dưới dạng canonical (chữ thường).
- **Loại bỏ `www.`:** Tiền tố `www.` sẽ tự động được lược bỏ (ví dụ: `https://www.example.com/page` → `example.com`).
- **Bỏ qua path & query:** Đường dẫn (path), tham số query và fragment đều được tự động loại bỏ.
- **Hỗ trợ wildcard pattern:** Hỗ trợ các pattern tiền tố như `*.gov`, `*.edu`, `*.example.com` để tin cậy toàn bộ domain con thuộc hậu tố đó.
- **Quy tắc subdomain:** Các subdomain độc lập (ví dụ: `news.example.com`) được giữ cụ thể theo từng domain con, trừ khi người dùng chỉ định domain gốc hoặc wildcard pattern.

## Khi nào cập nhật?

- Khi bổ sung nguồn tham khảo tin cậy theo ngành.
- Khi muốn mở rộng hoặc thu hẹp pattern nhận diện liên kết tin cậy (trust links).
