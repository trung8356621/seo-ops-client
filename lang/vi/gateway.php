<?php

declare(strict_types=1);

return [
    'nav' => 'Gateway',
    'title' => 'Gateway',
    'tab_api_access' => 'API Access Gate',
    'tab_control' => 'Giấy phép & Dịch vụ',

    'control_title' => 'Control Gate — Giấy phép & Dịch vụ',
    'control_description' => 'Hiển thị entitlement từ OPS-SERVER và mức sẵn sàng của Service. Không hiển thị machine credential hay secret nội bộ của Service Gate.',
    'control_product' => 'Sản phẩm',
    'control_plan' => 'Gói',
    'control_license_status' => 'Trạng thái license',
    'control_starts_at' => 'Ngày bắt đầu',
    'control_ends_at' => 'Ngày hết hạn',
    'control_h1_label' => 'H1 receiver',
    'control_h1_pending_integration' => 'Chờ tích hợp (chưa triển khai trong task này)',
    'control_h1_not_implemented' => 'Chưa triển khai',
    'control_no_info' => 'Chưa có thông tin',
    'control_services_title' => 'Dịch vụ được cấp',
    'control_service' => 'Dịch vụ',
    'control_entitled' => 'Được cấp',
    'control_activated' => 'Kích hoạt',
    'control_readiness' => 'Sẵn sàng',
    'control_no_services' => 'Không có service trong catalog.',
    'control_no_secrets' => 'Không hiển thị machine secret, internal service key, rotation version hay command ID kỹ thuật.',

    'license_status_disconnected' => 'Chưa kết nối',
    'license_status_active' => 'Đang hiệu lực',
    'license_status_expired' => 'Hết hạn',
    'license_status_revoked' => 'Đã thu hồi',
    'license_status_unknown' => 'Không xác định',

    'readiness_disconnected' => 'Chưa kết nối',
    'readiness_not_entitled' => 'Chưa được cấp',
    'readiness_pending' => 'Đang chờ',
    'readiness_ready' => 'Sẵn sàng',
    'readiness_not_required' => 'Sẵn sàng (không cần credential nội bộ)',
    'readiness_revoked' => 'Đã thu hồi',
    'readiness_error' => 'Lỗi — có thể thử lại',
    'readiness_unknown' => 'Không xác định',

    'api_access_title' => 'API Access Gate',
    'api_access_description' => 'API key cục bộ do Root quản lý cho tích hợp ngoài. Tách biệt Control Gate và credential nội bộ Service Gate. Raw key chỉ hiện một lần.',
    'api_access_create' => 'Tạo API key',
    'api_access_service' => 'Service',
    'api_access_scopes_helper' => 'Chỉ scope trong allowlist (không wildcard). service:read mở /api/v1/me/capabilities và status service.',
    'api_access_empty' => 'Chưa có API credential người dùng.',
    'api_access_root_only' => 'Chỉ account owner (Root) được tạo, thu hồi hoặc xoay API key.',

    'summary_active' => 'Key đang active',
    'summary_total' => 'Tổng số key',
    'yes' => 'Có',
    'no' => 'Không',
];
