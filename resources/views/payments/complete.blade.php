<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Processing</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background:
                radial-gradient(circle at top, rgba(37, 99, 235, 0.18), transparent 40%),
                linear-gradient(160deg, #0f172a 0%, #111827 55%, #1f2937 100%);
            color: #e5eef8;
        }
        .panel {
            width: min(520px, 100%);
            border-radius: 20px;
            padding: 32px 28px;
            background: rgba(15, 23, 42, 0.86);
            border: 1px solid rgba(148, 163, 184, 0.18);
            box-shadow: 0 28px 80px rgba(2, 6, 23, 0.45);
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            background: rgba(59, 130, 246, 0.14);
            border: 1px solid rgba(96, 165, 250, 0.25);
            color: #bfdbfe;
        }
        .badge.sandbox {
            background: rgba(245, 158, 11, 0.14);
            border-color: rgba(251, 191, 36, 0.24);
            color: #fde68a;
        }
        h1 {
            margin: 16px 0 10px;
            font-size: 28px;
            line-height: 1.15;
        }
        p {
            margin: 0;
            color: #cbd5e1;
            line-height: 1.6;
        }
        .details {
            margin-top: 24px;
            padding: 18px 0 0;
            border-top: 1px solid rgba(148, 163, 184, 0.18);
        }
        .row {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding: 10px 0;
            border-bottom: 1px solid rgba(148, 163, 184, 0.08);
        }
        .row:last-child { border-bottom: 0; }
        .label {
            color: #94a3b8;
            font-size: 14px;
        }
        .value {
            color: #f8fafc;
            font-weight: 600;
            text-align: right;
        }
        .helper {
            margin-top: 18px;
            font-size: 14px;
            color: #93c5fd;
        }
        .helper.error {
            color: #fca5a5;
        }
        .link {
            margin-top: 20px;
            display: inline-block;
            color: #93c5fd;
        }
        .spinner {
            width: 42px;
            height: 42px;
            border-radius: 999px;
            border: 3px solid rgba(148, 163, 184, 0.18);
            border-top-color: #60a5fa;
            animation: spin 0.85s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <main class="panel">
        <div class="spinner" aria-hidden="true"></div>
        <div class="badge {{ $mode === 'sandbox' ? 'sandbox' : '' }}">
            {{ $mode === 'sandbox' ? 'Sandbox Billing' : 'Billing' }}
        </div>

        <h1>Payment processing</h1>
        <p>
            @if($payment)
                We are finishing your wallet payment and will return you to the profile page shortly.
            @else
                We could not match this return to a wallet payment. You can go back to your profile and refresh the wallet card.
            @endif
        </p>

        <section class="details">
            <div class="row">
                <span class="label">Status</span>
                <span class="value">{{ strtoupper(str_replace('_', ' ', $status_label)) }}</span>
            </div>
            @if($payment)
                <div class="row">
                    <span class="label">Reference</span>
                    <span class="value">{{ $payment->reference_number }}</span>
                </div>
                <div class="row">
                    <span class="label">Amount</span>
                    <span class="value">{{ $payment->currency }} {{ number_format((float) $payment->amount, 2) }}</span>
                </div>
            @endif
        </section>

        @if($redirect_url)
            <p class="helper">
                Redirecting in {{ $redirect_delay_seconds }} seconds. If nothing happens, use the return link below.
            </p>
            <a class="link" href="{{ $redirect_url }}">Return to profile</a>
        @else
            <p class="helper error">
                Automatic return is unavailable for this payment.
            </p>
        @endif
    </main>

    @if($redirect_url)
        <script>
            window.setTimeout(function () {
                window.location.replace(@json($redirect_url));
            }, {{ max(1, (int) $redirect_delay_seconds) * 1000 }});
        </script>
    @endif
</body>
</html>
