---
key: settings.keywords.cta_blacklist
title: CTA Keyword Blacklist
summary: Danh sách cụm CTA bị bỏ qua khi tạo keyword mới từ bài viết; hỗ trợ pattern chứa/bắt đầu/kết thúc.
group: settings
sort_order: 95
keywords: []
updated_at: '2026-08-30'
---

# CTA Keyword Blacklist

CTA Keyword Blacklist là danh sách các cụm từ Call-to-Action mà hệ thống sẽ bỏ qua khi tạo keyword mới từ bài viết.

## Dùng để làm gì?

- Ngăn keyword kiểu “liên hệ”, “tại đây”, “xem catalogue” vào kho keyword
- Giữ danh sách keyword tập trung vào chủ đề nội dung thật
- Áp dụng cùng quy tắc cho mọi bài khi quét/tạo keyword

## Cách nhập

- Text thường (ví dụ: `tại đây`) được hiểu là chứa cụm đó
- Có thể dùng pattern dạng SQL: `Liên hệ%` (bắt đầu), `%tại đây` (kết thúc), `%catalogue%` (chứa)
- Không phân biệt hoa thường
- Nhấn Enter để thêm từng cụm

## Khi nào thay đổi?

- Khi keyword mới bị lẫn nhiều cụm CTA
- Khi thêm CTA mới cho site/ngành
- Khi debug thấy blacklist chưa đủ hoặc quá rộng

## Lưu ý

Blacklist chỉ ảnh hưởng bước tạo/bỏ qua keyword. Nó không xóa keyword đã có sẵn trong hệ thống.
