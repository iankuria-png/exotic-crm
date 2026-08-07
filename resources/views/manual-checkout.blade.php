<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title>Complete your payment · {{ $platform->name }}</title>
<style>
    :root { --brand:#0f766e; --brand-d:#0b5e57; --ink:#0f172a; --muted:#64748b; --line:#e2e8f0; --bg:#f1f5f9; --ok:#059669; }
    * { box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
    body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; background:var(--bg); color:var(--ink); line-height:1.5; }
    .wrap { max-width:520px; margin:0 auto; padding:16px 16px 48px; }
    .top { display:flex; align-items:center; gap:10px; padding:14px 2px 18px; }
    .top .flag { font-size:24px; line-height:1; }
    .top b { font-size:15px; }
    .top .sec { margin-left:auto; display:inline-flex; align-items:center; gap:5px; font-size:11px; color:var(--muted); background:#fff; border:1px solid var(--line); padding:5px 9px; border-radius:999px; }
    .top .sec svg { width:12px; height:12px; }
    .card { background:#fff; border:1px solid var(--line); border-radius:16px; padding:18px; margin-bottom:14px; box-shadow:0 1px 2px rgba(15,23,42,.04); }
    .amt { text-align:center; padding:22px 18px; }
    .amt .lbl { font-size:12px; letter-spacing:.08em; text-transform:uppercase; color:var(--muted); }
    .amt .val { font-size:34px; font-weight:800; margin:6px 0 2px; letter-spacing:-.02em; }
    .amt .sub { font-size:13px; color:var(--muted); }
    .step { display:flex; align-items:center; gap:8px; font-weight:700; font-size:15px; margin:2px 0 12px; }
    .step .n { width:22px; height:22px; border-radius:50%; background:var(--brand); color:#fff; display:grid; place-items:center; font-size:12px; }
    .intro { font-size:13.5px; color:var(--muted); margin:-4px 0 12px; }
    .method + .method { margin-top:12px; border-top:1px dashed var(--line); padding-top:14px; }
    .method h3 { margin:0 0 8px; font-size:14px; }
    .row { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:9px 12px; background:var(--bg); border-radius:10px; margin-top:7px; }
    .row .k { font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); }
    .row .v { font-weight:700; font-size:15px; word-break:break-all; }
    .copy { border:1px solid var(--line); background:#fff; color:var(--brand); font-weight:700; font-size:12px; padding:7px 12px; border-radius:9px; cursor:pointer; white-space:nowrap; }
    .copy:active { transform:scale(.96); }
    .copy.done { color:var(--ok); border-color:#a7f3d0; background:#ecfdf5; }
    .foot { font-size:12.5px; color:var(--muted); margin-top:10px; }
    label { display:block; font-size:12px; font-weight:600; color:var(--ink); margin:12px 0 6px; }
    input[type=text], select, textarea { width:100%; border:1px solid var(--line); border-radius:11px; padding:12px 13px; font-size:15px; font-family:inherit; background:#fff; }
    input:focus, select:focus, textarea:focus { outline:none; border-color:var(--brand); box-shadow:0 0 0 3px rgba(15,118,110,.12); }
    .file { border:1.5px dashed var(--line); border-radius:12px; padding:16px; text-align:center; color:var(--muted); font-size:13.5px; cursor:pointer; display:block; }
    .file.has { border-color:var(--brand); color:var(--brand); background:#f0fdfa; font-weight:600; }
    .file input { display:none; }
    .btn { width:100%; border:none; background:var(--brand); color:#fff; font-weight:700; font-size:16px; padding:15px; border-radius:13px; cursor:pointer; margin-top:16px; }
    .btn:active { background:var(--brand-d); }
    .btn:disabled { opacity:.55; cursor:progress; }
    .note { font-size:12px; color:var(--muted); text-align:center; margin-top:14px; }
    .alert { border-radius:11px; padding:12px 14px; font-size:14px; margin-top:12px; display:none; }
    .alert.err { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; }
    .done-screen { text-align:center; padding:36px 18px; display:none; }
    .done-screen .tick { width:64px; height:64px; border-radius:50%; background:#ecfdf5; color:var(--ok); display:grid; place-items:center; margin:0 auto 16px; font-size:30px; }
    .done-screen h2 { margin:0 0 8px; }
    .done-screen p { color:var(--muted); font-size:14px; }
</style>
</head>
<body>
<div class="wrap">
    <div class="top">
        @if($flag)<span class="flag">{!! $flag !!}</span>@endif
        <b>{{ $platform->name }}</b>
        <span class="sec">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
            Secure
        </span>
    </div>

    <div id="checkout">
        <div class="card amt">
            <div class="lbl">Amount to pay</div>
            <div class="val">{{ $amountDisplay }}</div>
            <div class="sub">{{ $productName }}@if($client) · {{ $client->name }}@endif</div>
        </div>

        <div class="card">
            <div class="step"><span class="n">1</span> Pay using any option below</div>
            @foreach($methods as $m)
                <div class="method">
                    <h3>{{ $m['label'] }}</h3>
                    @if($m['intro'])<div class="intro">{{ $m['intro'] }}</div>@endif
                    @foreach($m['details'] as $k => $v)
                        @if(is_scalar($v) && trim((string)$v) !== '')
                            <div class="row">
                                <div>
                                    <div class="k">{{ ucwords(str_replace('_',' ', $k)) }}</div>
                                    <div class="v">{{ $v }}</div>
                                </div>
                                <button type="button" class="copy" data-copy="{{ $v }}">Copy</button>
                            </div>
                        @endif
                    @endforeach
                    <div class="row">
                        <div><div class="k">Amount</div><div class="v">{{ $amountDisplay }}</div></div>
                        <button type="button" class="copy" data-copy="{{ (int) $payment->amount }}">Copy</button>
                    </div>
                    @if($m['footer'])<div class="foot">{{ $m['footer'] }}</div>@endif
                </div>
            @endforeach
        </div>

        <form id="proofForm" class="card" enctype="multipart/form-data">
            <div class="step"><span class="n">2</span> Upload your payment proof</div>

            <label for="method">Which option did you pay to?</label>
            <select id="method" name="manual_method_key" required>
                @foreach($methods as $m)
                    <option value="{{ $m['key'] }}">{{ $m['label'] }}</option>
                @endforeach
            </select>

            <label for="sender">Name on the account you paid from</label>
            <input id="sender" type="text" name="sender_name" placeholder="e.g. {{ $client?->name ?: 'Your name' }}" required>

            <label for="txn">Transaction / reference code</label>
            <input id="txn" type="text" name="transaction_reference" placeholder="e.g. QGH7XY8Z1P" required>

            <label>Screenshot of the payment</label>
            <label class="file" id="fileLabel">
                <span id="fileText">Tap to attach the payment screenshot</span>
                <input type="file" id="proof" name="proof_image" accept="image/jpeg,image/png,image/webp" required>
            </label>

            <div class="alert err" id="err"></div>
            <button type="submit" class="btn" id="submitBtn">Submit for activation</button>
            <p class="note">Your profile is activated once we confirm the payment. Ref {{ $reference }}</p>
        </form>
    </div>

    <div class="done-screen" id="done">
        <div class="tick">✓</div>
        <h2>Proof received</h2>
        <p>Thanks! We’re confirming your payment now — your profile will be activated shortly. You can close this page.</p>
    </div>
</div>

<script>
(function () {
    var ctx = @json($submitContext);

    document.querySelectorAll('.copy').forEach(function (b) {
        b.addEventListener('click', function () {
            var t = b.getAttribute('data-copy');
            (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () {
                b.textContent = 'Copied'; b.classList.add('done');
                setTimeout(function () { b.textContent = 'Copy'; b.classList.remove('done'); }, 1500);
            }).catch(function () {});
        });
    });

    var proof = document.getElementById('proof'), fileLabel = document.getElementById('fileLabel'), fileText = document.getElementById('fileText');
    proof.addEventListener('change', function () {
        if (proof.files && proof.files[0]) { fileText.textContent = proof.files[0].name; fileLabel.classList.add('has'); }
    });

    var form = document.getElementById('proofForm'), btn = document.getElementById('submitBtn'), err = document.getElementById('err');
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        err.style.display = 'none';
        btn.disabled = true; btn.textContent = 'Submitting…';

        var fd = new FormData(form);
        Object.keys(ctx).forEach(function (k) { if (ctx[k] !== null && ctx[k] !== '') fd.append(k, ctx[k]); });

        fetch('/api/manual-payment-submissions', { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                if (!res.ok) throw new Error((res.j && (res.j.message || (res.j.errors && Object.values(res.j.errors)[0][0]))) || 'Submission failed. Please check your details.');
                document.getElementById('checkout').style.display = 'none';
                document.getElementById('done').style.display = 'block';
                window.scrollTo(0, 0);
            })
            .catch(function (e2) {
                err.textContent = e2.message || 'Something went wrong. Please try again.';
                err.style.display = 'block';
                btn.disabled = false; btn.textContent = 'Submit for activation';
            });
    });
})();
</script>
</body>
</html>
