<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $paid ? 'Payment received' : 'Back to Tickora' }} · Tickora</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #F6F4FF; color: #1B1530; padding: 24px; box-sizing: border-box; }
        main { max-width: 380px; width: 100%; text-align: center; background: #fff; border-radius: 20px; padding: 32px 24px; box-shadow: 0 10px 30px rgba(45, 27, 105, .12); }
        h1 { font-size: 22px; margin: 16px 0 8px; }
        p { color: #5B5670; line-height: 1.5; margin: 0 0 24px; }
        a.button { display: block; background: #6D4AFF; color: #fff; text-decoration: none; font-weight: 700; padding: 14px; border-radius: 12px; }
    </style>
</head>
<body>
<main>
    <svg width="56" height="56" viewBox="0 0 64 64" aria-hidden="true">
        <rect width="64" height="64" rx="15" fill="#6D4AFF"/>
        <path fill="#fff" fill-rule="evenodd" d="M32 10.5a21.5 21.5 0 1 1 0 43 21.5 21.5 0 0 1 0-43Zm0 11a10.5 10.5 0 1 0 0 21 10.5 10.5 0 0 0 0-21Z"/>
        <rect x="7" y="28.5" width="12" height="7" rx="3.5" fill="#6D4AFF"/>
        <rect x="45" y="28.5" width="12" height="7" rx="3.5" fill="#6D4AFF"/>
        <circle cx="32" cy="32" r="5.5" fill="#FF6B6B"/>
    </svg>
    @if ($paid)
        <h1>Payment received</h1>
        <p>You're going! Return to the Tickora app to see your tickets.</p>
    @else
        <h1>Return to the app</h1>
        <p>Go back to the Tickora app to finish your order.</p>
    @endif
    <a class="button" href="{{ $appUrl }}">Open Tickora</a>
</main>
</body>
</html>
