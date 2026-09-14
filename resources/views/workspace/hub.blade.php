<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Workspace — Omnichannel</title>
    <style>
        :root {
            --bg: #0f1419;
            --card: #1a222c;
            --border: #2a3542;
            --text: #e8eef4;
            --muted: #8b9aab;
            --accent: #f59e0b;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", system-ui, sans-serif;
            background: radial-gradient(1200px 600px at 10% -10%, #1e293b 0%, var(--bg) 55%);
            color: var(--text);
        }
        .wrap { max-width: 960px; margin: 0 auto; padding: 3rem 1.25rem 4rem; }
        h1 { font-size: 1.75rem; font-weight: 650; margin: 0 0 0.35rem; letter-spacing: -0.02em; }
        .sub { color: var(--muted); margin: 0 0 2rem; font-size: 0.95rem; }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 1rem;
        }
        a.card {
            display: block;
            text-decoration: none;
            color: inherit;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1.25rem 1.35rem;
            transition: border-color .15s, transform .15s;
        }
        a.card:hover { border-color: var(--accent); transform: translateY(-2px); }
        .card h2 { margin: 0 0 0.4rem; font-size: 1.1rem; }
        .card p { margin: 0; color: var(--muted); font-size: 0.875rem; line-height: 1.45; }
        .empty {
            border: 1px dashed var(--border);
            border-radius: 14px;
            padding: 2.5rem 1.5rem;
            text-align: center;
            color: var(--muted);
        }
        .user { margin-top: 2.5rem; font-size: 0.8rem; color: var(--muted); }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>Workspace</h1>
        <p class="sub">Chọn không gian làm việc bạn được phép truy cập.</p>

        @if (count($destinations) === 0)
            <div class="empty">
                Hiện không có module nào khả dụng cho tài khoản của bạn.
                Liên hệ Owner nếu cần được cấp quyền.
            </div>
        @else
            <div class="grid">
                @foreach ($destinations as $destination)
                    <a class="card" href="{{ $destination->url }}">
                        <h2>{{ $destination->label }}</h2>
                        @if ($destination->description)
                            <p>{{ $destination->description }}</p>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif

        <p class="user">{{ $user->display_name }} · {{ $user->email }}</p>
    </div>
</body>
</html>
