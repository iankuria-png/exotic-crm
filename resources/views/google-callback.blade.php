<!DOCTYPE html>
<html>
<head>
    <title>Google Login Success</title>
</head>
<body>
<script>
    const user = @json($user);
    const token = "{{ $token }}";

    // Send data back to the main window (React app)
    window.opener.postMessage(
        { success: true, user, token },
        "https://exotic-tau.vercel.app" // <-- frontend URL
    );

    window.location.href = "https://exotic-tau.vercel.app/platform-selector"; 
    // Fallback if postMessage doesn't work
</script>
<p>Login successful. You can close this window.</p>
</body>
</html>
