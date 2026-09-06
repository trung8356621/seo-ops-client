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

Một số Hook là **system-managed** (ví dụ Product Gallery, Create news thumbnail): chúng vẫn chạy ở runtime nhưng **không** hiện trên trang Workflows Settings để chọn Prompt.

## Cách hoạt động

Mỗi Hook chỉ hiển thị những Prompt phù hợp với loại tác vụ đó.

Prompt chưa được gắn đúng Hook sẽ không được dùng cho tác vụ tương ứng.

`settings_visible=true` ≠ “hook đang bật”. Hook có thể `enabled=true` và `settings_visible=false` khi hệ thống tự quản lý binding.

## Khi nào cần kiểm tra?

- Khi một chức năng đang dùng sai Prompt
- Khi vừa tạo Prompt mới
- Khi muốn thay Prompt mặc định của một tác vụ (chỉ với Hook operator-configurable)
