<?php

return [
    'reasons' => [
        'DNS_ERROR' => 'DNS không phân giải được', 'CONNECTION_TIMEOUT' => 'Kết nối đã hết thời gian chờ',
        'CONNECTION_ERROR' => 'Không thể kết nối', 'TLS_ERROR' => 'Chứng chỉ hoặc bắt tay TLS thất bại',
        'HTTP_5XX' => 'Website trả về lỗi máy chủ', 'SITE_UNREACHABLE' => 'Không thể truy cập website',
        'WP_BRIDGE_UNREACHABLE' => 'WordPress Bridge không phản hồi', 'WP_BRIDGE_AUTH_ERROR' => 'Xác thực WordPress Bridge thất bại',
        'HEARTBEAT_INVALID' => 'WordPress Bridge trả về heartbeat không hợp lệ',
    ],
    'banner' => [
        'critical' => ':count website đang gặp sự cố nghiêm trọng',
        'warning' => ':count website cần kiểm tra',
        'more' => 'và :count website khác', 'detected' => 'Phát hiện :time',
    ],
    'actions' => ['view_details' => 'Xem chi tiết', 'view_all' => 'Xem tất cả', 'dismiss' => 'Đóng', 'retry' => 'Kiểm tra lại ngay', 'open_site' => 'Mở website'],
    'drawer' => ['title' => 'Chẩn đoán Site Health'],
    'fields' => ['domain' => 'Tên miền', 'status' => 'Trạng thái', 'error_code' => 'Mã lỗi', 'reason' => 'Nguyên nhân', 'detected_at' => 'Phát hiện lúc', 'duration' => 'Thời lượng', 'last_checked_at' => 'Kiểm tra gần nhất', 'last_success_at' => 'Thành công gần nhất', 'failures' => 'Số lần lỗi liên tiếp', 'diagnostics' => 'Các bước chẩn đoán', 'technical_error' => 'Lỗi kỹ thuật gần nhất'],
    'notification' => ['active_title' => 'Sự cố Site Health: :site', 'recovered_title' => 'Website đã khôi phục: :site', 'recovered_message' => 'Website đã có thể truy cập trở lại.'],
];
