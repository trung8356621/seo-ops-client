<?php

declare(strict_types=1);

return [
    'trigger' => 'Ticket',
    'trigger_aria' => 'Mở form gửi Support Ticket',
    'dialog_aria' => 'Gửi Support Ticket',
    'title' => 'Tiêu đề',
    'title_placeholder' => 'Tóm tắt ngắn',
    'content' => 'Nội dung',
    'content_placeholder' => 'Mô tả lỗi… có thể dán ảnh (Ctrl+V)',
    'attach' => 'Đính kèm',
    'submit' => 'Gửi',
    'submitting' => 'Đang gửi…',
    'remove_attachment' => 'Xóa',
    'messages' => [
        'submitted' => 'Đã gửi ticket thành công.',
        'file_too_large' => 'Tệp vượt quá giới hạn kích thước.',
        'submit_failed' => 'Không gửi được — thử lại.',
    ],
    'errors' => [
        'unauthenticated' => 'Chưa đăng nhập.',
        'invalid' => 'Dữ liệu ticket không hợp lệ.',
        'owner_missing' => 'Không xác định được workspace.',
        'store_failed' => 'Không lưu được tệp đính kèm.',
        'file_empty' => 'Tệp rỗng hoặc không hợp lệ.',
        'file_too_large' => 'Tệp vượt quá giới hạn :mb MB.',
        'file_type' => 'Loại tệp không được phép. Cho phép: :types.',
    ],
    'admin' => [
        'nav' => 'Support Tickets',
        'model' => 'Support Ticket',
        'plural' => 'Support Tickets',
        'columns' => [
            'id' => 'ID',
            'created_at' => 'Thời gian',
            'user' => 'Người gửi',
            'title' => 'Tiêu đề',
            'status' => 'Trạng thái',
            'service' => 'Dịch vụ',
            'attachments' => 'Đính kèm',
            'page_url' => 'URL trang',
            'connection_hash' => 'Connection',
            'body' => 'Nội dung',
        ],
        'view' => 'Xem',
        'empty_attachments' => 'Không có tệp đính kèm',
    ],
];
