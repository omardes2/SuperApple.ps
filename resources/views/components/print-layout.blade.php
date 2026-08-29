<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Tajawal', 'Segoe UI', Tahoma, Arial, sans-serif;
            margin: 0; color: #1e293b; background: #f1f5f9;
        }
        .sheet {
            width: 210mm; min-height: 297mm; margin: 12px auto; padding: 18mm;
            background: #fff; box-shadow: 0 1px 6px rgba(0,0,0,.08);
        }
        h1 { margin: 0; font-size: 22px; }
        table { width: 100%; border-collapse: collapse; }
        .items th, .items td { padding: 8px 10px; text-align: right; border-bottom: 1px solid #e2e8f0; font-size: 13px; }
        .items th { background: #f8fafc; color: #64748b; font-size: 11px; text-transform: uppercase; }
        .muted { color: #64748b; }
        .num { direction: ltr; text-align: left; font-variant-numeric: tabular-nums; }
        .totals td { padding: 6px 10px; font-size: 14px; }
        .brand { display:flex; align-items:center; gap:10px; }
        .logo { width:42px; height:42px; border-radius:10px; background:#1f47f5; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:20px; }
        .badge { display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; }
        .print-bar { max-width:210mm; margin: 10px auto 0; text-align: left; direction: ltr; }
        .print-bar button { background:#1f47f5; color:#fff; border:0; padding:8px 16px; border-radius:8px; font-size:13px; cursor:pointer; }
        @media print {
            body { background: #fff; }
            .sheet { box-shadow: none; margin: 0; width: auto; min-height: auto; padding: 12mm; }
            .print-bar { display: none; }
        }
    </style>
</head>
<body>
    <div class="print-bar"><button onclick="window.print()">طباعة</button></div>
    <div class="sheet">
        {{ $slot }}
    </div>
</body>
</html>
