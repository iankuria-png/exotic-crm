<?php

return [
    'pay_link_receipt' => '{{market_name}}: {{currency}} {{amount}} received. Your {{plan}} profile is live until {{live_until}}. {{profile_url}}',
    'payment' => [
        'success' => 'Payment of KES :amount received. Txn ID: :txn. Date: :date. Thank you!',
        'cancelled' => 'Your payment of KES :amount was cancelled on :date. You can try again.',
        'failed' => 'Payment of KES :amount failed. Txn ID: :txn. Date: :date. Please try again.',
        'default' => 'Payment notification. Amount: :amount. Date: :date. Txn ID: :txn'
    ]
];
