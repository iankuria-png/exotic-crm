<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login</title>

    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>

    <style>
        body {
            font-family: Arial;
            padding: 50px;
            display: flex;
            justify-content: center;
        }
        .card {
            width: 400px;
            padding: 30px;
            border: 1px solid #ddd;
            border-radius: 10px;
            text-align: center;
        }
        .google-btn {
            background: #fff;
            border: 1px solid #ddd;
            padding: 12px;
            width: 100%;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .google-btn:hover {
            background: #f7f7f7;
        }
        img {
            width: 22px;
        }
    </style>
</head>

<body>

    <div class="card">
        <h2>Login</h2>

        <button class="google-btn" onclick="loginWithGoogle()">
            <img src="https://www.gstatic.com/firebasejs/ui/2.0.0/images/auth/google.svg">
            Login With Google
        </button>
    </div>

    <script>
       function loginWithGoogle() {
            axios.get("https://testing.exotic-ads.com/api/auth/google/redirect")
                .then(res => {
                    window.location.href = res.data.url;
                })
                .catch(err => {
                    alert("Could not connect to Google Login");
                    console.error(err);
                });
        }


    </script>

</body>
</html>
