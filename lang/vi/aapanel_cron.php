<?php

declare(strict_types=1);

return [
    'step_title' => 'Tác vụ nền định kỳ (aaPanel Cron)',
    'step_description' => 'Tải JSON 5 cron chuẩn và import trên máy chủ. Bước này không bắt buộc — bạn có thể tiếp tục mà không cần xác minh cron.',
    'download_json' => 'Tải cấu hình aaPanel (JSON)',
    'download_hint' => 'Chỉ export đúng 5 job SEO-OPS. Không gồm gia hạn SSL, backup hay cron hệ thống aaPanel.',
    'duplicate_warning' => 'Nếu aaPanel đã có các job SEO-OPS cùng tên, không import lại — panel không tự deduplicate theo tên.',
    'guide_title' => 'Import vào aaPanel',
    'guide_1' => 'Mở aaPanel → Cron.',
    'guide_2' => 'Import JSON nếu phiên bản panel hỗ trợ (BT/aaPanel 9.1+).',
    'guide_3' => 'Kiểm tra đúng 5 job SEO-OPS được tạo (không trùng).',
    'guide_4' => 'Chạy thử và kiểm tra log trong storage/logs/.',
    'continue' => 'Tiếp tục: Hoàn tất & Tổng kết',
    'back' => 'Quay lại Bước 3',
];
