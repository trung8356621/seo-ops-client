<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('client_control.locked_title') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #111827; color: #f9fafb; }
        .card { max-width: 32rem; padding: 2rem; }
        a { color: #fbbf24; }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ __('client_control.locked_title') }}</h1>
        <p>{{ $message }}</p>
        @auth
            <p><a href="{{ url('/admin/control-server') }}">{{ __('client_control.open_control_server') }}</a></p>
        @else
            <p><a href="{{ url('/admin/login') }}">{{ __('client_control.login') }}</a></p>
        @endauth
    </div>
</body>
</html>
