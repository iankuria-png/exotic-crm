<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Opening CRM Session</title>
</head>
<body style="font-family: Arial, sans-serif; padding: 24px; color: #0f172a;">
<p>Opening CRM session...</p>
<script>
    const payload = @json($payload);

    try {
        window.sessionStorage.setItem('crm_impersonation', JSON.stringify(payload));
    } catch (error) {
        console.error('Unable to store CRM impersonation context.', error);
    }

    window.location.replace(payload.redirect_to || '/');
</script>
</body>
</html>
