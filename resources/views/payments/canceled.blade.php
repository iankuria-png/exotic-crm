<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Failed</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .failed-container {
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
        
        .failed-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            background: #ff4757;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: shake 0.5s ease-in-out;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
        
        .failed-icon::after {
            content: '✕';
            color: white;
            font-size: 40px;
            font-weight: bold;
        }
        
        h1 {
            color: #333;
            margin-bottom: 15px;
            font-size: 28px;
        }
        
        .failed-message {
            color: #666;
            margin-bottom: 30px;
            font-size: 16px;
            line-height: 1.5;
        }
        
        .error-details {
            background: #fff5f5;
            border: 1px solid #fed7d7;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            text-align: left;
        }
        
        .error-details h3 {
            color: #c53030;
            margin-bottom: 15px;
            font-size: 18px;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #fed7d7;
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
        
        .error-reason {
            background: #ffebee;
            border-left: 4px solid #ff4757;
            padding: 15px;
            margin: 20px 0;
            border-radius: 0 8px 8px 0;
        }
        
        .error-reason h4 {
            color: #c53030;
            margin-bottom: 8px;
        }
        
        .error-reason p {
            color: #666;
            font-size: 14px;
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
        
        .btn-danger {
            background: #ff4757;
            color: white;
        }
        
        .btn-danger:hover {
            background: #ff3742;
            transform: translateY(-2px);
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
            .failed-container {
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
    <div class="failed-container">
        <div class="failed-icon"></div>
        
        <h1>Payment Failed</h1>
        <p class="failed-message">
            Unfortunately, your payment could not be processed. Please check the details below and try again.
        </p>
        
        @if(session('reason'))
        <div class="error-reason">
            <h4>Reason for Failure:</h4>
            <p>{{ session('reason') }}</p>
        </div>
        @endif
        
        <div class="error-details">
            <h3>Transaction Details</h3>
            
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
                <span class="detail-value">{{ strtoupper($payment->currency) }} {{ number_format($payment->amount, 2) }}</span>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">Date:</span>
                <span class="detail-value">{{ $payment->created_at->format('M d, Y \a\t H:i') }}</span>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">Status:</span>
                <span class="detail-value" style="color: #ff4757;">{{ ucfirst($payment->status) }}</span>
            </div>
        </div>
        
        <div class="action-buttons">
            <button onclick="retryPayment()" class="btn btn-danger">
                🔄 Try Again
            </button>
            <a href="{{ url('/') }}" class="btn btn-secondary">
                🏠 Return Home
            </a>
        </div>
    </div>

    <script>
        function retryPayment() {
            // You can implement retry logic here
            // For now, just redirect to home or payment page
            if (confirm('Would you like to try the payment again?')) {
                window.location.href = '{{ url('/') }}';
            }
        }
    </script>
</body>
</html>