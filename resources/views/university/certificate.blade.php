<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $certificate->certificate_code }}</title>
    <style>
        @page { margin: 24px; }
        body {
            margin: 0;
            color: #111827;
            font-family: Helvetica, Arial, sans-serif;
            background: #f8fafc;
        }
        .certificate {
            height: 690px;
            border: 9px solid #b88a2b;
            padding: 42px 54px;
            background: #ffffff;
            position: relative;
        }
        .inner {
            height: 100%;
            border: 1px solid #d8b76a;
            padding: 34px 42px;
            text-align: center;
        }
        .eyebrow {
            color: #0f766e;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 3px;
            text-transform: uppercase;
        }
        h1 {
            margin: 20px 0 8px;
            font-family: Georgia, "Times New Roman", serif;
            font-size: 46px;
            font-weight: 400;
            color: #0f172a;
        }
        .holder {
            margin: 28px auto 10px;
            padding-bottom: 12px;
            max-width: 620px;
            border-bottom: 2px solid #d8b76a;
            font-family: Georgia, "Times New Roman", serif;
            font-size: 42px;
            color: #111827;
        }
        .copy {
            margin: 18px auto;
            max-width: 700px;
            font-size: 16px;
            line-height: 1.7;
            color: #334155;
        }
        .score {
            display: inline-block;
            margin: 12px 0 24px;
            padding: 10px 20px;
            border-radius: 999px;
            background: #ecfeff;
            color: #0f766e;
            font-weight: 800;
        }
        .meta {
            width: 100%;
            margin-top: 28px;
            font-size: 12px;
            color: #475569;
        }
        .meta td {
            width: 33%;
            padding: 8px;
            vertical-align: top;
        }
        .label {
            display: block;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.6px;
            text-transform: uppercase;
            color: #64748b;
        }
        .signature {
            margin-top: 34px;
            text-align: right;
            font-size: 13px;
            color: #334155;
        }
        .signature span {
            display: inline-block;
            min-width: 210px;
            padding-top: 10px;
            border-top: 1px solid #475569;
            text-align: center;
        }
        .verify {
            position: absolute;
            right: 58px;
            bottom: 48px;
            width: 180px;
            text-align: right;
            font-size: 10px;
            color: #64748b;
        }
    </style>
</head>
<body>
    <div class="certificate">
        <div class="inner">
            <div class="eyebrow">Exotic Online University</div>
            <h1>Certificate of Completion</h1>
            <p class="copy">This certifies that</p>
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

            <div class="signature">
                <span>Training Lead</span>
            </div>
        </div>
        <div class="verify">
            Verify at<br>{{ $verifyUrl }}
        </div>
    </div>
</body>
</html>
