<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Cài đặt SEO-OPS V1 (Phase 1)</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <style>
        :root {
            --primary: #d97706;
            --primary-hover: #b45309;
            --primary-light: #fef3c7;
            --bg: #f8fafc;
            --card-bg: #ffffff;
            --border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --success: #10b981;
            --error: #ef4444;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .header {
            background: #ffffff;
            border-bottom: 1px solid var(--border);
            padding: 1rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .header-brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .header-brand img {
            height: 2.25rem;
        }
        .header-brand .title {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--text-main);
        }
        .header-brand .sub {
            font-size: 0.8rem;
            color: var(--primary);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .main {
            flex: 1;
            max-width: 52rem;
            width: 100%;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }
        .card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.04), 0 2px 4px -2px rgba(0, 0, 0, 0.04);
            overflow: hidden;
        }
        .steps-nav {
            display: flex;
            border-bottom: 1px solid var(--border);
            background: #fcfdfe;
        }
        .step-item {
            flex: 1;
            padding: 1rem;
            text-align: center;
            border-bottom: 3px solid transparent;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s;
        }
        .step-item.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background: #ffffff;
        }
        .step-item.done {
            color: var(--success);
        }
        .step-badge {
            width: 1.5rem;
            height: 1.5rem;
            border-radius: 9999px;
            background: #e2e8f0;
            color: #475569;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
        }
        .step-item.active .step-badge {
            background: var(--primary);
            color: #ffffff;
        }
        .step-item.done .step-badge {
            background: var(--success);
            color: #ffffff;
        }
        .content {
            padding: 2rem;
        }
        h2 {
            margin: 0 0 0.5rem;
            font-size: 1.25rem;
            font-weight: 700;
        }
        p.desc {
            margin: 0 0 1.75rem;
            color: var(--text-muted);
            font-size: 0.9rem;
            line-height: 1.5;
        }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.25rem;
        }
        .col-span-2 {
            grid-column: span 2;
        }
        .field {
            margin-bottom: 1rem;
        }
        label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 0.35rem;
            color: #334155;
        }
        input[type="text"], input[type="email"], input[type="password"], input[type="number"] {
            width: 100%;
            padding: 0.65rem 0.85rem;
            border: 1px solid #cbd5e1;
            border-radius: 0.5rem;
            font-size: 0.9rem;
            outline: none;
            transition: border-color 0.15s;
        }
        input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(217, 119, 6, 0.15);
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            font-size: 0.9rem;
            font-weight: 600;
            border-radius: 0.5rem;
            border: none;
            cursor: pointer;
            transition: background 0.15s;
        }
        .btn-primary {
            background: var(--primary);
            color: #ffffff;
        }
        .btn-primary:hover {
            background: var(--primary-hover);
        }
        .btn-primary:disabled {
            background: #cbd5e1;
            cursor: not-allowed;
        }
        .btn-secondary {
            background: #e2e8f0;
            color: #334155;
        }
        .btn-secondary:hover {
            background: #cbd5e1;
        }
        .alert {
            padding: 0.85rem 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1.25rem;
            font-size: 0.875rem;
            line-height: 1.45;
        }
        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }
        .alert-success {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #166534;
        }
        .alert-info {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #334155;
        }
        .terminal {
            background: #0f172a;
            color: #e2e8f0;
            border-radius: 0.5rem;
            padding: 1rem;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.825rem;
            max-height: 18rem;
            overflow-y: auto;
            margin-bottom: 1.5rem;
            line-height: 1.5;
        }
        .terminal .log-line {
            margin: 0.2rem 0;
        }
        .terminal .log-line.highlight {
            color: #38bdf8;
        }
        .terminal .log-line.success {
            color: #4ade80;
        }
        .terminal .log-line.error {
            color: #f87171;
        }
        .accordion {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .accordion-item {
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            background: #ffffff;
            overflow: hidden;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .accordion-item.open {
            border-color: #cbd5e1;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.03);
        }
        .accordion-header {
            padding: 1rem 1.25rem;
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            user-select: none;
            transition: background 0.15s;
        }
        .accordion-header:hover {
            background: #f1f5f9;
        }
        .accordion-title {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--text-main);
        }
        .accordion-meta {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .conn-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.25rem 0.65rem;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 9999px;
            background: #f1f5f9;
            color: #64748b;
        }
        .conn-badge.success {
            background: #ecfdf5;
            color: #065f46;
        }
        .conn-badge.error {
            background: #fef2f2;
            color: #991b1b;
        }
        .conn-badge.testing {
            background: #eff6ff;
            color: #1d4ed8;
        }
        .accordion-chevron {
            width: 1.25rem;
            height: 1.25rem;
            transition: transform 0.2s;
            color: var(--text-muted);
        }
        .accordion-item.open .accordion-chevron {
            transform: rotate(180deg);
        }
        .accordion-body {
            padding: 1.25rem;
            border-top: 1px solid var(--border);
            display: none;
        }
        .accordion-item.open .accordion-body {
            display: block;
        }
        .checkbox-container {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            margin: 0.75rem 0 1rem;
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 500;
            color: #334155;
            user-select: none;
        }
        .checkbox-container input[type="checkbox"] {
            width: 1.15rem;
            height: 1.15rem;
            accent-color: var(--primary);
            cursor: pointer;
            margin: 0;
        }
        .panel-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 1rem;
            padding-top: 0.75rem;
            border-top: 1px dashed #e2e8f0;
        }
        .footer-note {
            text-align: center;
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 1.5rem;
        }
        .hidden { display: none !important; }
        .spinner {
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top: 2px solid white;
            width: 1rem;
            height: 1rem;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-brand">
            <img src="{{ asset('images/logo.png') }}" alt="SEO-OPS Logo" onerror="this.style.display='none'">
            <div>
                <div class="title">SEO-OPS Client Setup</div>
                <div class="sub">Phase 1 &bull; Software Installation Only</div>
            </div>
        </div>
        <div style="font-size: 0.85rem; color: var(--text-muted);">
            V1.0.0
        </div>
    </header>

    <main class="main">
        <div class="card">
            <!-- Step Navigation Bar -->
            <div class="steps-nav">
                <div class="step-item active" id="nav-step-1">
                    <span class="step-badge">1</span>
                    <span>Cơ sở dữ liệu</span>
                </div>
                <div class="step-item" id="nav-step-2">
                    <span class="step-badge">2</span>
                    <span>Khởi tạo hệ thống</span>
                </div>
                <div class="step-item" id="nav-step-3">
                    <span class="step-badge">3</span>
                    <span>Administrator</span>
                </div>
                <div class="step-item" id="nav-step-4">
                    <span class="step-badge">4</span>
                    <span>Website chính</span>
                </div>
                <div class="step-item" id="nav-step-5">
                    <span class="step-badge">5</span>
                    <span>Hoàn tất</span>
                </div>
            </div>

            <!-- Content Area -->
            <div class="content">
                <div id="alert-box" class="alert hidden"></div>

                <!-- STEP 1: DATABASE -->
                <div id="step-1-panel">
                    <h2>Bước 1 — Cấu hình kết nối MySQL</h2>
                    <p class="desc">
                        SEO-OPS sử dụng kiến trúc multi-database độc lập cho từng dịch vụ. Bạn có thể sử dụng cùng một MySQL server hoặc cấu hình máy chủ/thông tin đăng nhập riêng biệt cho từng dịch vụ bên dưới.
                    </p>

                    <form id="db-form" onsubmit="handleDbSubmit(event)">
                        <div class="accordion" id="db-accordion">

                            <!-- PANEL 1: CLIENT CORE (PRIMARY) -->
                            <div class="accordion-item open" id="acc-item-core">
                                <div class="accordion-header" onclick="toggleAccordion('core')">
                                    <div class="accordion-title">
                                        <span class="step-badge" style="width:1.4rem;height:1.4rem;font-size:0.75rem;">1</span>
                                        <span>Client Core DB (Bảng hệ thống, Users, Sites)</span>
                                        <span style="font-size:0.75rem; color:var(--primary); font-weight:600; background:var(--primary-light); padding:0.15rem 0.5rem; border-radius:4px;">Primary</span>
                                    </div>
                                    <div class="accordion-meta">
                                        <span id="badge-core" class="conn-badge">Chưa kiểm tra</span>
                                        <svg class="accordion-chevron" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                        </svg>
                                    </div>
                                </div>
                                <div class="accordion-body" id="acc-body-core">
                                    <div class="field">
                                        <label for="core_database">Tên Database (Database Name) *</label>
                                        <input type="text" id="core_database" name="core_database" value="omi_clean_client" required>
                                    </div>
                                    <div class="form-grid">
                                        <div class="field">
                                            <label for="core_host">MySQL Host *</label>
                                            <input type="text" id="core_host" name="core_host" value="127.0.0.1" required>
                                        </div>
                                        <div class="field">
                                            <label for="core_port">Port *</label>
                                            <input type="number" id="core_port" name="core_port" value="3306" required>
                                        </div>
                                        <div class="field">
                                            <label for="core_username">Username *</label>
                                            <input type="text" id="core_username" name="core_username" value="root" required>
                                        </div>
                                        <div class="field">
                                            <label for="core_password">Password</label>
                                            <input type="password" id="core_password" name="core_password" placeholder="(để trống nếu không có mật khẩu)">
                                        </div>
                                    </div>
                                    <div class="panel-actions">
                                        <span id="msg-core" style="font-size:0.8rem; color:var(--text-muted);">Kết nối chính của hệ thống Client.</span>
                                        <button type="button" class="btn btn-secondary" style="padding:0.45rem 1rem; font-size:0.825rem;" onclick="testSingleDb('core')">
                                            <span>Kiểm tra kết nối Core</span>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- PANEL 2: SEO CONTENT AI -->
                            <div class="accordion-item" id="acc-item-seo">
                                <div class="accordion-header" onclick="toggleAccordion('seo')">
                                    <div class="accordion-title">
                                        <span class="step-badge" style="width:1.4rem;height:1.4rem;font-size:0.75rem;">2</span>
                                        <span>SEO Content AI (Bài viết, Từ khóa, Topical Map)</span>
                                    </div>
                                    <div class="accordion-meta">
                                        <span id="badge-seo" class="conn-badge">Chưa kiểm tra</span>
                                        <svg class="accordion-chevron" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                        </svg>
                                    </div>
                                </div>
                                <div class="accordion-body" id="acc-body-seo">
                                    <div class="field">
                                        <label for="seo_database">Tên Database (Database Name) *</label>
                                        <input type="text" id="seo_database" name="seo_database" value="omi_clean_seo_ai" required>
                                    </div>

                                    <label class="checkbox-container">
                                        <input type="checkbox" id="seo_inherit_core" name="seo_inherit_core" value="1" checked onchange="handleInheritChange('seo')">
                                        <span>Dùng chung Host, Port và thông tin đăng nhập từ Core</span>
                                    </label>

                                    <div id="seo-custom-fields" class="form-grid hidden">
                                        <div class="field">
                                            <label for="seo_host">MySQL Host *</label>
                                            <input type="text" id="seo_host" name="seo_host" value="127.0.0.1">
                                        </div>
                                        <div class="field">
                                            <label for="seo_port">Port *</label>
                                            <input type="number" id="seo_port" name="seo_port" value="3306">
                                        </div>
                                        <div class="field">
                                            <label for="seo_username">Username *</label>
                                            <input type="text" id="seo_username" name="seo_username" value="root">
                                        </div>
                                        <div class="field">
                                            <label for="seo_password">Password</label>
                                            <input type="password" id="seo_password" name="seo_password" placeholder="(mật khẩu MySQL cho SEO)">
                                        </div>
                                    </div>

                                    <div class="panel-actions">
                                        <span id="msg-seo" style="font-size:0.8rem; color:var(--text-muted);">Database độc lập cho SEO AI.</span>
                                        <button type="button" class="btn btn-secondary" style="padding:0.45rem 1rem; font-size:0.825rem;" onclick="testSingleDb('seo')">
                                            <span>Kiểm tra kết nối SEO</span>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- PANEL 3: SEEDING -->
                            <div class="accordion-item" id="acc-item-seeding">
                                <div class="accordion-header" onclick="toggleAccordion('seeding')">
                                    <div class="accordion-title">
                                        <span class="step-badge" style="width:1.4rem;height:1.4rem;font-size:0.75rem;">3</span>
                                        <span>Seeding Service (Chủ đề Seeding, Báo cáo)</span>
                                    </div>
                                    <div class="accordion-meta">
                                        <span id="badge-seeding" class="conn-badge">Chưa kiểm tra</span>
                                        <svg class="accordion-chevron" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                        </svg>
                                    </div>
                                </div>
                                <div class="accordion-body" id="acc-body-seeding">
                                    <div class="field">
                                        <label for="seeding_database">Tên Database (Database Name) *</label>
                                        <input type="text" id="seeding_database" name="seeding_database" value="omi_clean_seeding" required>
                                    </div>

                                    <label class="checkbox-container">
                                        <input type="checkbox" id="seeding_inherit_core" name="seeding_inherit_core" value="1" checked onchange="handleInheritChange('seeding')">
                                        <span>Dùng chung Host, Port và thông tin đăng nhập từ Core</span>
                                    </label>

                                    <div id="seeding-custom-fields" class="form-grid hidden">
                                        <div class="field">
                                            <label for="seeding_host">MySQL Host *</label>
                                            <input type="text" id="seeding_host" name="seeding_host" value="127.0.0.1">
                                        </div>
                                        <div class="field">
                                            <label for="seeding_port">Port *</label>
                                            <input type="number" id="seeding_port" name="seeding_port" value="3306">
                                        </div>
                                        <div class="field">
                                            <label for="seeding_username">Username *</label>
                                            <input type="text" id="seeding_username" name="seeding_username" value="root">
                                        </div>
                                        <div class="field">
                                            <label for="seeding_password">Password</label>
                                            <input type="password" id="seeding_password" name="seeding_password" placeholder="(mật khẩu MySQL cho Seeding)">
                                        </div>
                                    </div>

                                    <div class="panel-actions">
                                        <span id="msg-seeding" style="font-size:0.8rem; color:var(--text-muted);">Database độc lập cho Seeding.</span>
                                        <button type="button" class="btn btn-secondary" style="padding:0.45rem 1rem; font-size:0.825rem;" onclick="testSingleDb('seeding')">
                                            <span>Kiểm tra kết nối Seeding</span>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- PANEL 4: SOCIAL AUTOMATION -->
                            <div class="accordion-item" id="acc-item-sa">
                                <div class="accordion-header" onclick="toggleAccordion('sa')">
                                    <div class="accordion-title">
                                        <span class="step-badge" style="width:1.4rem;height:1.4rem;font-size:0.75rem;">4</span>
                                        <span>Social Automation (Gói tác vụ, RPA)</span>
                                    </div>
                                    <div class="accordion-meta">
                                        <span id="badge-sa" class="conn-badge">Chưa kiểm tra</span>
                                        <svg class="accordion-chevron" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                        </svg>
                                    </div>
                                </div>
                                <div class="accordion-body" id="acc-body-sa">
                                    <div class="field">
                                        <label for="social_automation_database">Tên Database (Database Name) *</label>
                                        <input type="text" id="social_automation_database" name="social_automation_database" value="omi_clean_social_automation" required>
                                    </div>

                                    <label class="checkbox-container">
                                        <input type="checkbox" id="social_automation_inherit_core" name="social_automation_inherit_core" value="1" checked onchange="handleInheritChange('sa')">
                                        <span>Dùng chung Host, Port và thông tin đăng nhập từ Core</span>
                                    </label>

                                    <div id="sa-custom-fields" class="form-grid hidden">
                                        <div class="field">
                                            <label for="social_automation_host">MySQL Host *</label>
                                            <input type="text" id="social_automation_host" name="social_automation_host" value="127.0.0.1">
                                        </div>
                                        <div class="field">
                                            <label for="social_automation_port">Port *</label>
                                            <input type="number" id="social_automation_port" name="social_automation_port" value="3306">
                                        </div>
                                        <div class="field">
                                            <label for="social_automation_username">Username *</label>
                                            <input type="text" id="social_automation_username" name="social_automation_username" value="root">
                                        </div>
                                        <div class="field">
                                            <label for="social_automation_password">Password</label>
                                            <input type="password" id="social_automation_password" name="social_automation_password" placeholder="(mật khẩu MySQL cho Social Automation)">
                                        </div>
                                    </div>

                                    <div class="panel-actions">
                                        <span id="msg-sa" style="font-size:0.8rem; color:var(--text-muted);">Database độc lập cho Social Automation.</span>
                                        <button type="button" class="btn btn-secondary" style="padding:0.45rem 1rem; font-size:0.825rem;" onclick="testSingleDb('sa')">
                                            <span>Kiểm tra kết nối Social Automation</span>
                                        </button>
                                    </div>
                                </div>
                            </div>

                        </div>

                        <div class="alert alert-info">
                            <strong>Lưu ý an toàn:</strong> Trình cài đặt sẽ kiểm tra kết nối độc lập cho từng dịch vụ và xác thực an toàn dữ liệu trước khi tiếp tục. Không bao giờ ghi đè database đã có dữ liệu quản trị viên.
                        </div>

                        <div style="display: flex; justify-content: flex-end; margin-top: 1.5rem;">
                            <button type="submit" id="btn-save-db" class="btn btn-primary">
                                <span>Kiểm tra tất cả & Lưu cấu hình Database</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- STEP 2: INITIALIZE / MIGRATIONS -->
                <div id="step-2-panel" class="hidden">
                    <h2>Bước 2 — Khởi tạo ứng dụng & Chạy Migrations</h2>
                    <p class="desc">
                        Chạy migration cho Core và các Addon được phê duyệt theo đúng thứ tự liên kết, đăng ký Service Catalog và nạp dữ liệu nền tảng ban đầu.
                    </p>

                    <div class="terminal" id="init-terminal">
                        <div class="log-line highlight">> Sẵn sàng khởi tạo SEO-OPS Client...</div>
                        <div class="log-line">> Sẽ chạy Core migrations (mysql) -> SEO migrations (omi_seo_ai) -> Seeding (omi_seeding) -> Social Automation (omi_social_automation).</div>
                        <div class="log-line">> Addons sẽ được quét và đăng ký vào Service Catalog ở chế độ Passive (Inactive).</div>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1.5rem;">
                        <button type="button" class="btn btn-secondary" onclick="goToStep(1)">
                            &larr; Quay lại Bước 1
                        </button>
                        <div style="display: flex; gap: 0.75rem;">
                            <button type="button" id="btn-retry-init" class="btn btn-secondary hidden" onclick="startInitialization()">
                                Thử lại (Retry)
                            </button>
                            <button type="button" id="btn-run-init" class="btn btn-primary" onclick="startInitialization()">
                                <span>Bắt đầu Khởi tạo</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- STEP 3: ADMINISTRATOR -->
                <div id="step-3-panel" class="hidden">
                    <h2>Bước 3 — Tạo tài khoản Administrator cục bộ</h2>
                    <p class="desc">
                        Tạo tài khoản quản trị viên cục bộ để đăng nhập vào trang Quản trị (/admin).
                    </p>

                    <div class="alert alert-info">
                        <strong>Quyền hạn:</strong> Tài khoản này có quyền Owner cục bộ cho ứng dụng. Tài khoản chưa được gắn bất kỳ bản quyền thương mại nào (Commercial Services răm unlicensed).
                    </div>

                    <form id="admin-form" onsubmit="handleAdminSubmit(event)">
                        <div class="field">
                            <label for="admin_name">Họ và tên</label>
                            <input type="text" id="admin_name" name="name" value="Admin SEO-OPS" required>
                        </div>
                        <div class="field">
                            <label for="admin_email">Email đăng nhập</label>
                            <input type="email" id="admin_email" name="email" value="admin@seo-ops.test" required>
                        </div>
                        <div class="form-grid">
                            <div class="field">
                                <label for="admin_password">Mật khẩu (tối thiểu 8 ký tự)</label>
                                <input type="password" id="admin_password" name="password" required minlength="8">
                            </div>
                            <div class="field">
                                <label for="admin_password_confirmation">Xác nhận mật khẩu</label>
                                <input type="password" id="admin_password_confirmation" name="password_confirmation" required minlength="8">
                            </div>
                        </div>

                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1.5rem;">
                            <button type="button" class="btn btn-secondary" onclick="goToStep(2)">
                                &larr; Quay lại Bước 2
                            </button>
                            <button type="submit" id="btn-create-admin" class="btn btn-primary">
                                <span>Tạo tài khoản Administrator</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- STEP 4: PRIMARY SITE (DOMAIN ANCHOR) -->
                <div id="step-4-panel" class="hidden">
                    <h2>Bước 4 — Đăng ký Website chính (Domain Anchor)</h2>
                    <p class="desc">
                        Website chính là một Website bình thường được gắn cố định với Installation này. Domain này sẽ đồng bộ sang OPS-SERVER khi kích hoạt bản quyền.
                    </p>

                    <div class="alert alert-info">
                        <strong>Lưu ý:</strong> Website phải truy cập được công khai (HTTP/HTTPS). Localhost, IP nội bộ và HTTP 404 sẽ bị từ chối. Sau khi đăng ký, không thể xóa hoặc đổi domain của Website chính.
                    </div>

                    <form id="primary-site-form" onsubmit="handlePrimarySiteSubmit(event)">
                        <div class="field">
                            <label for="primary_domain">Tên miền Website chính</label>
                            <input
                                type="text"
                                id="primary_domain"
                                name="domain"
                                value="{{ $registeredPrimaryDomain ?? $suggestedPrimaryDomain ?? '' }}"
                                placeholder="example.com"
                                required
                                @if(!empty($registeredPrimaryDomain)) readonly @endif
                            >
                            @if(!empty($suggestedPrimaryDomain) && empty($registeredPrimaryDomain))
                                <div style="margin-top:0.4rem;font-size:0.8rem;color:var(--text-muted);">
                                    Gợi ý từ cấu hình ứng dụng: <strong>{{ $suggestedPrimaryDomain }}</strong>
                                </div>
                            @endif
                        </div>

                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1.5rem;">
                            <button type="button" class="btn btn-secondary" onclick="goToStep(3)">
                                &larr; Quay lại Bước 3
                            </button>
                            <button type="submit" id="btn-register-primary" class="btn btn-primary">
                                <span>{{ !empty($registeredPrimaryDomain) ? 'Tiếp tục' : 'Kiểm tra & đăng ký Website chính' }}</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- STEP 5: FINISH -->
                <div id="step-5-panel" class="hidden">
                    <div style="text-align: center; padding: 1.5rem 0;">
                        <div style="width: 4rem; height: 4rem; background: #ecfdf5; color: var(--success); border-radius: 9999px; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 1.25rem;">
                            <svg style="width: 2.25rem; height: 2.25rem;" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                            </svg>
                        </div>

                        <h2>Cài đặt phần mềm thành công!</h2>
                        <p class="desc" style="max-width: 32rem; margin: 0 auto 1.5rem;">
                            Hệ thống SEO-OPS Client đã được cài đặt và cấu hình hoàn tất ở mức Local Software.
                        </p>

                        <div class="alert alert-info" style="text-align: left; max-width: 32rem; margin: 0 auto 2rem;">
                            <div style="font-weight: 700; margin-bottom: 0.5rem; color: #1e293b;">Trạng thái hệ thống:</div>
                            <ul style="margin: 0; padding-left: 1.25rem; font-size: 0.85rem; line-height: 1.6;">
                                <li><strong>MySQL Databases:</strong> Đã kết nối và chạy migrations thành công.</li>
                                <li><strong>Website chính (Domain Anchor):</strong> <em id="finish-primary-domain">{{ $registeredPrimaryDomain ?? '—' }}</em></li>
                                <li><strong>Service Catalog:</strong> Đã đăng ký thụ động (Tất cả Dịch vụ: <em>Inactive / Chưa cấp quyền</em>).</li>
                                <li><strong>Bản quyền Thương mại:</strong> <em>Zero Commercial Activation</em> (Chưa liên kết OPS-SERVER / H1).</li>
                                <li><strong>Tài khoản Quản trị:</strong> Đã sẵn sàng truy cập vào /admin.</li>
                            </ul>
                        </div>

                        <div>
                            <button type="button" id="btn-finish-go" class="btn btn-primary" style="padding: 0.85rem 2.25rem; font-size: 1rem;" onclick="finishAndGoAdmin()">
                                <span>Truy cập Quản trị (/admin) &rarr;</span>
                            </button>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <div class="footer-note">
            SEO-OPS Client Installer &bull; Phase 1 First-Run Only &bull; Token Session Protected
        </div>
    </main>

    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        function showAlert(msg, type = 'error') {
            const box = document.getElementById('alert-box');
            box.className = 'alert alert-' + type;
            box.innerHTML = msg;
            box.classList.remove('hidden');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function clearAlert() {
            const box = document.getElementById('alert-box');
            box.classList.add('hidden');
            box.innerHTML = '';
        }

        function goToStep(stepNum) {
            clearAlert();
            for (let i = 1; i <= 5; i++) {
                const nav = document.getElementById('nav-step-' + i);
                const panel = document.getElementById('step-' + i + '-panel');
                if (!nav || !panel) continue;
                if (i === stepNum) {
                    nav.classList.add('active');
                    panel.classList.remove('hidden');
                } else {
                    nav.classList.remove('active');
                    panel.classList.add('hidden');
                    if (i < stepNum) {
                        nav.classList.add('done');
                    } else {
                        nav.classList.remove('done');
                    }
                }
            }
        }

        // STEP 1: Accordion & Independent DB Logic
        function toggleAccordion(service) {
            const item = document.getElementById('acc-item-' + service);
            if (item) {
                item.classList.toggle('open');
            }
        }

        function handleInheritChange(service) {
            const inheritId = service === 'sa' ? 'social_automation_inherit_core' : service + '_inherit_core';
            const customFieldsId = service + '-custom-fields';
            const inheritCheckbox = document.getElementById(inheritId);
            const customFields = document.getElementById(customFieldsId);
            if (inheritCheckbox && customFields) {
                if (inheritCheckbox.checked) {
                    customFields.classList.add('hidden');
                } else {
                    customFields.classList.remove('hidden');
                }
            }
        }

        function getServiceConnectionData(service) {
            const coreHost = document.getElementById('core_host').value.trim();
            const corePort = parseInt(document.getElementById('core_port').value.trim(), 10) || 3306;
            const coreUser = document.getElementById('core_username').value.trim();
            const corePass = document.getElementById('core_password').value;

            if (service === 'core') {
                return {
                    label: 'Client Core',
                    database: document.getElementById('core_database').value.trim(),
                    host: coreHost,
                    port: corePort,
                    username: coreUser,
                    password: corePass,
                };
            }

            const prefix = service === 'sa' ? 'social_automation' : service;
            const inherit = document.getElementById(prefix + '_inherit_core').checked;
            const dbName = document.getElementById(prefix + '_database').value.trim();
            const label = service === 'seo' ? 'SEO Content AI' : (service === 'seeding' ? 'Seeding' : 'Social Automation');

            if (inherit) {
                return {
                    label: label,
                    database: dbName,
                    host: coreHost,
                    port: corePort,
                    username: coreUser,
                    password: corePass,
                };
            }

            return {
                label: label,
                database: dbName,
                host: document.getElementById(prefix + '_host').value.trim(),
                port: parseInt(document.getElementById(prefix + '_port').value.trim(), 10) || 3306,
                username: document.getElementById(prefix + '_username').value.trim(),
                password: document.getElementById(prefix + '_password').value,
            };
        }

        async function testSingleDb(service) {
            const badge = document.getElementById('badge-' + service);
            const msgSpan = document.getElementById('msg-' + service);
            badge.className = 'conn-badge testing';
            badge.textContent = 'Đang kiểm tra...';
            msgSpan.textContent = 'Đang kết nối đến MySQL...';
            msgSpan.style.color = 'var(--text-muted)';

            const payload = getServiceConnectionData(service);

            try {
                const res = await fetch('{{ url("/setup/api/database/test-single") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(payload),
                });
                const json = await res.json();
                if (res.ok && json.ok) {
                    badge.className = 'conn-badge success';
                    badge.textContent = 'Đã kết nối';
                    msgSpan.textContent = 'Kết nối thành công (' + payload.host + ':' + payload.port + ' / ' + payload.database + ')';
                    msgSpan.style.color = 'var(--success)';
                } else {
                    badge.className = 'conn-badge error';
                    badge.textContent = 'Thất bại';
                    msgSpan.textContent = json.message || 'Lỗi kết nối MySQL.';
                    msgSpan.style.color = 'var(--error)';
                }
            } catch (err) {
                badge.className = 'conn-badge error';
                badge.textContent = 'Lỗi';
                msgSpan.textContent = 'Lỗi máy chủ: ' + err.message;
                msgSpan.style.color = 'var(--error)';
            }
        }

        // STEP 1: DB Save All
        async function handleDbSubmit(e) {
            e.preventDefault();
            clearAlert();
            const btn = document.getElementById('btn-save-db');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Đang kiểm tra 4 kết nối...';

            const payload = {
                // Core
                core_database: document.getElementById('core_database').value.trim(),
                core_host: document.getElementById('core_host').value.trim(),
                core_port: parseInt(document.getElementById('core_port').value.trim(), 10) || 3306,
                core_username: document.getElementById('core_username').value.trim(),
                core_password: document.getElementById('core_password').value,
                // Backward-compat keys for core
                host: document.getElementById('core_host').value.trim(),
                port: parseInt(document.getElementById('core_port').value.trim(), 10) || 3306,
                username: document.getElementById('core_username').value.trim(),
                password: document.getElementById('core_password').value,
                db_client: document.getElementById('core_database').value.trim(),

                // SEO
                seo_inherit_core: document.getElementById('seo_inherit_core').checked,
                seo_database: document.getElementById('seo_database').value.trim(),
                db_seo: document.getElementById('seo_database').value.trim(),
                seo_host: document.getElementById('seo_host').value.trim(),
                seo_port: parseInt(document.getElementById('seo_port').value.trim(), 10) || 3306,
                seo_username: document.getElementById('seo_username').value.trim(),
                seo_password: document.getElementById('seo_password').value,

                // Seeding
                seeding_inherit_core: document.getElementById('seeding_inherit_core').checked,
                seeding_database: document.getElementById('seeding_database').value.trim(),
                db_seeding: document.getElementById('seeding_database').value.trim(),
                seeding_host: document.getElementById('seeding_host').value.trim(),
                seeding_port: parseInt(document.getElementById('seeding_port').value.trim(), 10) || 3306,
                seeding_username: document.getElementById('seeding_username').value.trim(),
                seeding_password: document.getElementById('seeding_password').value,

                // Social Automation
                social_automation_inherit_core: document.getElementById('social_automation_inherit_core').checked,
                social_automation_database: document.getElementById('social_automation_database').value.trim(),
                db_social_automation: document.getElementById('social_automation_database').value.trim(),
                social_automation_host: document.getElementById('social_automation_host').value.trim(),
                social_automation_port: parseInt(document.getElementById('social_automation_port').value.trim(), 10) || 3306,
                social_automation_username: document.getElementById('social_automation_username').value.trim(),
                social_automation_password: document.getElementById('social_automation_password').value,
            };

            try {
                const res = await fetch('{{ url("/setup/api/database") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(payload),
                });
                const json = await res.json();
                if (res.ok && json.ok) {
                    ['core', 'seo', 'seeding', 'sa'].forEach(svc => {
                        const b = document.getElementById('badge-' + svc);
                        if (b) {
                            b.className = 'conn-badge success';
                            b.textContent = 'Đã kết nối';
                        }
                    });
                    showAlert('Kết nối và cấu hình 4 cơ sở dữ liệu thành công!', 'success');
                    setTimeout(() => goToStep(2), 700);
                } else {
                    showAlert(json.message || 'Lỗi kiểm tra cơ sở dữ liệu.');
                }
            } catch (err) {
                showAlert('Lỗi kết nối tới máy chủ: ' + err.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<span>Kiểm tra tất cả & Lưu cấu hình Database</span>';
            }
        }

        // STEP 2: Initialize
        async function startInitialization() {
            clearAlert();
            const btn = document.getElementById('btn-run-init');
            const retryBtn = document.getElementById('btn-retry-init');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Đang khởi tạo...';
            retryBtn.classList.add('hidden');

            const terminal = document.getElementById('init-terminal');
            terminal.innerHTML = '<div class="log-line highlight">> Bắt đầu tiến trình khởi tạo ứng dụng...</div>';

            try {
                const res = await fetch('{{ url("/setup/api/initialize") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                });
                const json = await res.json();
                if (json.logs && Array.isArray(json.logs)) {
                    json.logs.forEach(l => {
                        const div = document.createElement('div');
                        div.className = 'log-line';
                        div.textContent = '> ' + l;
                        terminal.appendChild(div);
                    });
                }

                if (res.ok && json.ok) {
                    const okDiv = document.createElement('div');
                    okDiv.className = 'log-line success';
                    okDiv.textContent = '> [THÀNH CÔNG] Khởi tạo hoàn tất!';
                    terminal.appendChild(okDiv);
                    terminal.scrollTop = terminal.scrollHeight;
                    showAlert('Khởi tạo ứng dụng và migrations thành công!', 'success');
                    setTimeout(() => goToStep(3), 1000);
                } else {
                    const errDiv = document.createElement('div');
                    errDiv.className = 'log-line error';
                    errDiv.textContent = '> [LỖI] ' + (json.message || 'Khởi tạo thất bại.');
                    terminal.appendChild(errDiv);
                    terminal.scrollTop = terminal.scrollHeight;
                    showAlert(json.message || 'Khởi tạo thất bại. Vui lòng kiểm tra lại.', 'error');
                    retryBtn.classList.remove('hidden');
                }
            } catch (err) {
                showAlert('Lỗi kết nối khi khởi tạo: ' + err.message);
                retryBtn.classList.remove('hidden');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<span>Bắt đầu Khởi tạo</span>';
            }
        }

        // STEP 3: Admin Create
        async function handleAdminSubmit(e) {
            e.preventDefault();
            clearAlert();
            const btn = document.getElementById('btn-create-admin');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Đang tạo tài khoản...';

            const form = document.getElementById('admin-form');
            const data = Object.fromEntries(new FormData(form).entries());

            try {
                const res = await fetch('{{ url("/setup/api/admin") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(data),
                });
                const json = await res.json();
                if (res.ok && json.ok) {
                    showAlert('Tạo tài khoản quản trị thành công!', 'success');
                    setTimeout(() => goToStep(4), 700);
                } else {
                    showAlert(json.message || 'Lỗi tạo tài khoản Administrator.');
                }
            } catch (err) {
                showAlert('Lỗi: ' + err.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<span>Tạo tài khoản Administrator</span>';
            }
        }

        // STEP 4: Primary Site (Domain Anchor)
        async function handlePrimarySiteSubmit(e) {
            e.preventDefault();
            clearAlert();
            const btn = document.getElementById('btn-register-primary');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Đang kiểm tra website...';

            const form = document.getElementById('primary-site-form');
            const data = Object.fromEntries(new FormData(form).entries());

            try {
                const res = await fetch('{{ url("/setup/api/primary-site") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(data),
                });
                const json = await res.json();
                if (res.ok && json.ok) {
                    const finishLabel = document.getElementById('finish-primary-domain');
                    if (finishLabel && json.domain) {
                        finishLabel.textContent = json.domain;
                    }
                    showAlert(json.message || 'Đã đăng ký Website chính.', 'success');
                    setTimeout(() => goToStep(5), 700);
                } else {
                    showAlert(json.message || 'Không thể đăng ký Website chính.');
                }
            } catch (err) {
                showAlert('Lỗi: ' + err.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<span>Kiểm tra & đăng ký Website chính</span>';
            }
        }

        // STEP 5: Finish
        async function finishAndGoAdmin() {
            const btn = document.getElementById('btn-finish-go');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Đang hoàn tất & khóa installer...';

            try {
                const res = await fetch('{{ url("/setup/api/finish") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                });
                const json = await res.json();
                if (!res.ok || !json.ok) {
                    showAlert(json.message || 'Không thể hoàn tất cài đặt. Cần đăng ký Website chính trước.');
                    btn.disabled = false;
                    btn.innerHTML = '<span>Truy cập Quản trị (/admin) &rarr;</span>';
                    goToStep(4);
                    return;
                }
                window.location.href = json.redirect || '{{ url("/admin") }}';
            } catch (err) {
                showAlert('Lỗi hoàn tất cài đặt: ' + err.message);
                btn.disabled = false;
                btn.innerHTML = '<span>Truy cập Quản trị (/admin) &rarr;</span>';
            }
        }

        // Init step from server status if available
        @if (($currentStep ?? '') === 'admin')
            goToStep(3);
        @elseif (($currentStep ?? '') === 'primary_site')
            goToStep(4);
        @elseif (($currentStep ?? '') === 'finish')
            goToStep(5);
        @elseif (($currentStep ?? '') === 'migrations')
            goToStep(2);
        @endif
    </script>
</body>
</html>
