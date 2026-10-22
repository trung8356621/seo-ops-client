<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Bảo trì hệ thống — SEO-OPS' }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <style>
        :root { color-scheme: light; }
        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f9fafb;
            color: #1f2937;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 1.5rem;
        }
        .card {
            max-width: 32rem;
            width: 100%;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 1rem;
            padding: 2.5rem 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
            text-align: center;
        }
        .icon-wrap {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 4rem;
            height: 4rem;
            border-radius: 9999px;
            background: #fef3c7;
            color: #d97706;
            margin-bottom: 1.5rem;
        }
        h1 {
            font-size: 1.35rem;
            font-weight: 700;
            color: #111827;
            margin: 0 0 0.75rem;
        }
        p {
            font-size: 0.95rem;
            line-height: 1.5;
            color: #4b5563;
            margin: 0 0 1.5rem;
        }
        .badge {
            display: inline-block;
            background: #fee2e2;
            color: #b91c1c;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            margin-bottom: 1rem;
        }
        .meta {
            font-size: 0.8rem;
            color: #9ca3af;
            border-top: 1px solid #f3f4f6;
            padding-top: 1rem;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon-wrap">
            <svg style="width: 2rem; height: 2rem;" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
        </div>
        <div class="badge">HTTP 503 SERVICE UNAVAILABLE</div>
        <h1>{{ $title ?? 'Database Connection Unavailable' }}</h1>
        <p>{{ $message ?? 'Hệ thống tạm thời không thể kết nối tới cơ sở dữ liệu. Vui lòng kiểm tra lại trạng thái của máy chủ cơ sở dữ liệu.' }}</p>
        <div class="meta">
            SEO-OPS Platform Runtime &bull; Automatic Maintenance Guard
        </div>
    </div>
</body>
</html>
