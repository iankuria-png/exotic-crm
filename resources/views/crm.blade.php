<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ExoticCRM</title>
    @php
        // A stable-per-deploy build id so diagnostics can report exactly which
        // compiled bundle a user is on. Derived from the Vite manifest hash.
        $appBuild = 'dev';
        $manifestPath = public_path('build/manifest.json');
        if (is_file($manifestPath)) {
            $appBuild = substr(md5_file($manifestPath) ?: 'dev', 0, 8);
        }
    @endphp
    <meta name="app-build" content="{{ $appBuild }}">
    <style>
        .boot-shell {
            position: fixed; inset: 0; display: flex; align-items: center; justify-content: center;
            padding: 24px; background: #f8fafc; color: #0f172a;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        .boot-card {
            width: 100%; max-width: 420px; text-align: center; background: #fff;
            border: 1px solid #e2e8f0; border-radius: 16px; padding: 32px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
        }
        .boot-spinner {
            width: 40px; height: 40px; margin: 0 auto 16px; border-radius: 9999px;
            border: 4px solid #ccfbf1; border-top-color: #0d9488; animation: boot-spin .8s linear infinite;
        }
        @keyframes boot-spin { to { transform: rotate(360deg); } }
        .boot-title { font-size: 15px; font-weight: 600; color: #334155; margin: 0; }
        .boot-fallback-title { font-size: 18px; font-weight: 600; margin: 0 0 8px; }
        .boot-text { font-size: 14px; color: #475569; margin: 0 0 20px; line-height: 1.5; }
        .boot-actions { display: flex; flex-direction: column; gap: 8px; }
        .boot-row { display: flex; gap: 8px; }
        .boot-btn {
            flex: 1; cursor: pointer; border-radius: 10px; padding: 10px 16px; font-size: 14px;
            font-weight: 600; border: 1px solid transparent; text-decoration: none; display: inline-flex;
            align-items: center; justify-content: center;
        }
        .boot-btn-primary { background: #0d9488; color: #fff; }
        .boot-btn-primary:hover { background: #0f766e; }
        .boot-btn-secondary { background: #fff; color: #334155; border-color: #e2e8f0; }
        .boot-btn-secondary:hover { background: #f8fafc; }
        .boot-note { font-size: 12px; color: #059669; margin: 10px 0 0; display: none; }
    </style>
    @viteReactRefresh
    @vite(['resources/js/app.jsx'])
</head>
<body>
    <div id="app">
        <div id="boot-loader" class="boot-shell">
            <div class="boot-card">
                <div class="boot-spinner" role="status" aria-label="Loading ExoticCRM"></div>
                <p class="boot-title">Loading ExoticCRM…</p>
            </div>
        </div>
    </div>

    <noscript>
        <div class="boot-shell">
            <div class="boot-card">
                <h1 class="boot-fallback-title">JavaScript is required</h1>
                <p class="boot-text">ExoticCRM needs JavaScript to run. Please enable it in your browser settings, then reload this page.</p>
            </div>
        </div>
    </noscript>

    {{-- Pre-React fallback: shown only if the JS bundle never boots (bad deploy,
         blocked asset, offline). Pure inline JS/CSS so it works when the app can't. --}}
    <div id="boot-fallback" class="boot-shell" style="display:none">
        <div class="boot-card">
            <h1 class="boot-fallback-title">We couldn’t load the app</h1>
            <p class="boot-text">Something stopped ExoticCRM from loading. This is usually a temporary connection problem. Try reloading, or run a network check.</p>
            <div class="boot-actions">
                <a href="#" class="boot-btn boot-btn-primary" onclick="location.reload();return false;">Reload</a>
                <div class="boot-row">
                    <a href="/network-check" class="boot-btn boot-btn-secondary">Network check</a>
                    <a href="#" id="boot-copy" class="boot-btn boot-btn-secondary">Copy diagnostics</a>
                </div>
            </div>
            <p id="boot-copied" class="boot-note">Diagnostics copied to clipboard.</p>
        </div>
    </div>

    <script>
        (function () {
            var settled = false;

            function reactMounted() {
                // createRoot(...).render replaces #app's children, removing #boot-loader.
                return !document.getElementById('boot-loader');
            }

            function showFallback() {
                if (settled) return;
                if (reactMounted()) { settled = true; return; }
                settled = true;
                var loader = document.getElementById('boot-loader');
                var fallback = document.getElementById('boot-fallback');
                if (loader) loader.style.display = 'none';
                if (fallback) fallback.style.display = 'flex';
            }

            // Hard timeout: if React hasn't mounted in 15s, assume a boot failure.
            var watchdog = setTimeout(showFallback, 15000);

            // Fail fast on a failed bundle/style asset load.
            window.addEventListener('error', function (event) {
                var target = event && event.target;
                if (!target || (target.tagName !== 'SCRIPT' && target.tagName !== 'LINK')) return;
                var src = target.src || target.href || '';
                if (src.indexOf('/build/') !== -1 || src.indexOf('app.jsx') !== -1 || src.indexOf('/@' + 'vite') !== -1 || src.indexOf('resources/js') !== -1) {
                    clearTimeout(watchdog);
                    setTimeout(showFallback, 150);
                }
            }, true);

            document.addEventListener('click', function (event) {
                if (!event.target || event.target.id !== 'boot-copy') return;
                event.preventDefault();
                var meta = document.querySelector('meta[name="app-build"]');
                var text = [
                    'ExoticCRM diagnostics (boot failure)',
                    'Time: ' + new Date().toISOString(),
                    'Page: ' + location.href,
                    'Build: ' + (meta ? meta.content : 'unknown'),
                    'Online: ' + (navigator.onLine ? 'yes' : 'no'),
                    'Browser: ' + navigator.userAgent
                ].join('\n');
                var done = function () {
                    var note = document.getElementById('boot-copied');
                    if (note) note.style.display = 'block';
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done).catch(function () {});
                } else {
                    try {
                        var ta = document.createElement('textarea');
                        ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
                        document.body.appendChild(ta); ta.select(); document.execCommand('copy');
                        document.body.removeChild(ta); done();
                    } catch (e) {}
                }
            });
        })();
    </script>
</body>
</html>
