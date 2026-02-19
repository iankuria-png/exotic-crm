<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Successful</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .success-container {
            background: white;
            border-radius: 15px;
            padding: 40px;
            text-align: center;
            max-width: 500px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
            animation: slideUp 0.6s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .success-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            background: #4CAF50;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: pulse 1.5s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        .success-icon::after {
            content: '✓';
            color: white;
            font-size: 40px;
            font-weight: bold;
        }
        
        h1 {
            color: #333;
            margin-bottom: 15px;
            font-size: 28px;
        }
        
        .success-message {
            color: #666;
            margin-bottom: 30px;
            font-size: 16px;
            line-height: 1.5;
        }
        
        .payment-details {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            text-align: left;
        }
        
        .payment-details h3 {
            color: #333;
            margin-bottom: 15px;
            font-size: 18px;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        
        .detail-row:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            color: #666;
            font-weight: 500;
        }
        
        .detail-value {
            color: #333;
            font-weight: 600;
        }
        
        .amount {
            color: #4CAF50;
            font-size: 20px;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: #f8f9fa;
            color: #333;
            border: 2px solid #e9ecef;
        }
        
        .btn-secondary:hover {
            background: #e9ecef;
            transform: translateY(-1px);
        }
        
        @media (max-width: 480px) {
            .success-container {
                padding: 30px 20px;
            }
            
            h1 {
                font-size: 24px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="success-container">
        <div class="success-icon"></div>
        
        <h1>Payment Successful!</h1>
        <p class="success-message">
            Your payment has been processed successfully. You will receive a confirmation email shortly.
        </p>
        
        <div class="payment-details">
            <h3>Payment Details</h3>
            
            <div class="detail-row">
                <span class="detail-label">Transaction ID:</span>
                <span class="detail-value">{{ $payment->transaction_id ?? $payment->transaction_uuid }}</span>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">Reference Number:</span>
                <span class="detail-value">{{ $payment->reference_number }}</span>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">Product:</span>
                <span class="detail-value">{{ $payment->product->name ?? 'Product #' . $payment->product_id }}</span>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">Amount:</span>
                <span class="detail-value amount">{{ strtoupper($payment->currency) }} {{ number_format($payment->amount, 2) }}</span>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">Date:</span>
                <span class="detail-value">{{ $payment->updated_at->format('M d, Y \a\t H:i') }}</span>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">Status:</span>
                <span class="detail-value" style="color: #4CAF50;">{{ ucfirst($payment->status) }}</span>
            </div>
        </div>
        
        <div class="action-buttons">
            <a href="{{ url('/') }}" class="btn btn-primary">
                🏠 Return Home
            </a>
            <button onclick="window.print()" class="btn btn-secondary">
                🖨️ Print Receipt
            </button>
        </div>
    </div>

    <script>
        // Optional: Auto-redirect after 30 seconds
        setTimeout(function() {
            const autoRedirect = confirm('Would you like to return to the homepage?');
            if (autoRedirect) {
                window.location.href = '{{ url('/') }}';
            }
        }, 30000);
    </script>
</body>
</html>