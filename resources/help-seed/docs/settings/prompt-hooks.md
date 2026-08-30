---
key: settings.workflow.prompt_hooks
title: Prompt Hooks
summary: Hook đại diện loại tác vụ Prompt; chỉ Prompt đúng hook_key mới xuất hiện và được dùng.
group: settings
sort_order: 30
keywords: []
updated_at: '2026-08-30'
---
# Prompt Hooks

Prompt Hook đại diện cho một loại tác vụ mà SEO Ops có thể gọi Prompt để xử lý.

Ví dụ có thể gồm:

- gợi ý tiêu đề
- tạo meta description
- tạo FAQ
- tạo outline
- các tác vụ nội dung khác

## Cách hoạt động

Mỗi Hook chỉ hiển thị những Prompt phù hợp với loại tác vụ đó.

Prompt chưa được gắn đúng Hook sẽ không được dùng cho tác vụ tương ứng.

## Khi nào cần kiểm tra?

- Khi một chức năng đang dùng sai Prompt
- Khi vừa tạo Prompt mới
- Khi muốn thay Prompt mặc định của một tác vụ
