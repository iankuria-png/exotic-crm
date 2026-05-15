<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $certificate->certificate_code }}</title>
    <style>
        @page { margin: 18px; }
        body {
            margin: 0;
            color: #111827;
            font-family: Helvetica, Arial, sans-serif;
            background: #f8fafc;
        }
        .certificate {
            border: 8px solid #b88a2b;
            padding: 22px 36px;
            background: #ffffff;
            position: relative;
        }
        .inner {
            border: 1px solid #d8b76a;
            padding: 20px 30px 24px;
            text-align: center;
        }
        .eyebrow {
            color: #0f766e;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 3px;
            text-transform: uppercase;
        }
        h1 {
            margin: 10px 0 4px;
            font-family: Georgia, "Times New Roman", serif;
            font-size: 36px;
            font-weight: 400;
            color: #0f172a;
        }
        .holder {
            margin: 14px auto 6px;
            padding-bottom: 8px;
            max-width: 600px;
            border-bottom: 2px solid #d8b76a;
            font-family: Georgia, "Times New Roman", serif;
            font-size: 34px;
            color: #111827;
        }
        .copy {
            margin: 8px auto;
            max-width: 680px;
            font-size: 14px;
            line-height: 1.55;
            color: #334155;
        }
        .copy.intro { margin-top: 4px; }
        .score {
            display: inline-block;
            margin: 8px 0 14px;
            padding: 6px 16px;
            border-radius: 999px;
            background: #ecfeff;
            color: #0f766e;
            font-weight: 800;
            font-size: 14px;
        }
        .meta {
            width: 100%;
            margin-top: 12px;
            font-size: 11px;
            color: #475569;
            border-collapse: collapse;
        }
        .meta td {
            width: 33%;
            padding: 6px 4px;
            vertical-align: top;
        }
        .label {
            display: block;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 2px;
        }
        .footer-row {
            width: 100%;
            margin-top: 18px;
            border-collapse: collapse;
        }
        .footer-row td {
            vertical-align: bottom;
            font-size: 11px;
            color: #475569;
        }
        .footer-row .verify {
            font-size: 10px;
            color: #64748b;
            line-height: 1.4;
        }
        .footer-row .signature {
            text-align: right;
        }
        .footer-row .signature span {
            display: inline-block;
            min-width: 180px;
            padding-top: 8px;
            border-top: 1px solid #475569;
            text-align: center;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="certificate">
        <div class="inner">
            <div class="eyebrow">Exotic Online University</div>
            <h1>Certificate of Completion</h1>
            <p class="copy intro">This certifies that</p>
            <div class="holder">{{ $certificate->user?->name ?: 'Learner' }}</div>
            <p class="copy">
                has successfully completed {{ $certificate->certification?->title }}{{ $certificate->certification?->course ? ' for ' . $certificate->certification->course->title : '' }}.
            </p>
            <div class="score">Score: {{ number_format((float) $certificate->attempt?->score_pct, 0) }}%</div>

            <table class="meta">
                <tr>
                    <td><span class="label">Issued</span>{{ optional($certificate->issued_at)->format('M j, Y') }}</td>
                    <td><span class="label">Expires</span>{{ optional($certificate->expires_at)->format('M j, Y') }}</td>
                    <td><span class="label">Certificate Code</span>{{ $certificate->certificate_code }}</td>
                </tr>
            </table>

            <table class="footer-row">
                <tr>
                    <td class="verify">Verify authenticity at<br>{{ $verifyUrl }}</td>
                    <td class="signature"><span>Training Lead</span></td>
                </tr>
            </table>
        </div>
    </div>
</body>
</html>
