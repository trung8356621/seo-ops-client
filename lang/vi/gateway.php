<?php

declare(strict_types=1);

return [
    'nav' => 'Gateway',
    'title' => 'Gateway',
    'tab_api_access' => 'API Access Gate',
    'tab_control' => 'Control Gate',

    'control_title' => 'Control Gate',
    'control_description' => 'Kênh license / installation với OPS-SERVER. Chỉ hiển thị trạng thái an toàn — không quản lý credential hay lệnh H1 tại đây.',
    'control_h1_label' => 'H1 local receiver',
    'control_h1_not_implemented' => 'Chưa triển khai trên client build này',
    'control_entitlement_label' => 'Enforcement entitlement cục bộ',
    'control_entitlement_on' => 'Bật',
    'control_entitlement_off' => 'Tắt (pass-through)',
    'control_service' => 'Service',
    'control_active' => 'Active',
    'control_key_provisioned' => 'Đã có provisioned key',
    'control_no_services' => 'Không có service trong catalog.',
    'control_no_secrets' => 'Không bao giờ hiển thị machine secret hay service_key.',

    'api_access_title' => 'API Access Gate',
    'api_access_description' => 'API key cục bộ do Root quản lý cho tích hợp ngoài. Tách biệt credential máy OPS-SERVER. Raw key chỉ hiện một lần.',
    'api_access_create' => 'Tạo API key',
    'api_access_service' => 'Service',
    'api_access_scopes_helper' => 'Chỉ scope trong allowlist (không wildcard). service:read mở /api/v1/me/capabilities và status service.',
    'api_access_empty' => 'Chưa có API credential.',
    'api_access_root_only' => 'Chỉ account owner (Root) được tạo, thu hồi hoặc xoay API key.',

    'summary_active' => 'Key đang active',
    'summary_total' => 'Tổng số key',
    'yes' => 'Có',
    'no' => 'Không',
];
