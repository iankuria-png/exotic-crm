<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Authenticating...</title>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const params = new URLSearchParams(window.location.search);
            const token = params.get("token");

            if (token) {
                // Save token to local storage
                localStorage.setItem("auth_token", token);

                // Redirect to dashboard or wherever you want
                window.location.href = "/dashboard";
            } else {
                alert("No token returned from server.");
                window.location.href = "/login";
            }
        });
    </script>
</head>
<body>
    Redirecting...
</body>
</html>
