<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') · ExoticCRM</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 24px; background: #f8fafc; color: #0f172a;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        .card {
            width: 100%; max-width: 460px; text-align: center; background: #fff;
            border: 1px solid #e2e8f0; border-radius: 16px; padding: 40px 32px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
        }
        .code { font-size: 56px; font-weight: 700; letter-spacing: -.02em; color: #0d9488; margin: 0; line-height: 1; }
        .title { font-size: 18px; font-weight: 600; margin: 12px 0 8px; }
        .text { font-size: 14px; color: #475569; margin: 0 0 24px; line-height: 1.55; }
        .actions { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; }
        .btn {
            cursor: pointer; border-radius: 10px; padding: 10px 18px; font-size: 14px; font-weight: 600;
            border: 1px solid transparent; text-decoration: none; display: inline-flex; align-items: center; justify-content: center;
        }
        .btn-primary { background: #0d9488; color: #fff; }
        .btn-primary:hover { background: #0f766e; }
        .btn-secondary { background: #fff; color: #334155; border-color: #e2e8f0; }
        .btn-secondary:hover { background: #f8fafc; }
        .support { font-size: 12px; color: #94a3b8; margin: 20px 0 0; }
    </style>
</head>
<body>
    <div class="card">
        <p class="code">@yield('code')</p>
        <h1 class="title">@yield('title')</h1>
        <p class="text">@yield('message')</p>
        <div class="actions">
            <a href="/" class="btn btn-primary">Back to CRM</a>
            <a href="/network-check" class="btn btn-secondary">Network check</a>
        </div>
        <p class="support">If this keeps happening, contact support with the time and page you were on.</p>
    </div>
</body>
</html>
