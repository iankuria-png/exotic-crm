<?php

return [
    'host' => 'exoticonline.mintlify.app',
    'manifest' => 'https://exoticonline.mintlify.app/llms.txt',
    // This is an ACL and classification manifest, never remote-controlled.
    'documents' => [
        'payments/overview' => ['uri' => 'exotic://docs/payments/overview', 'roles' => ['admin', 'sub_admin', 'sales', 'field_sales'], 'audiences' => ['finance', 'sales'], 'stages' => ['payment']],
        'payments/failures/f1' => ['uri' => 'exotic://docs/payments/failures/f1', 'roles' => ['admin', 'sub_admin', 'sales', 'field_sales'], 'audiences' => ['finance', 'sales'], 'stages' => ['payment', 'activation']],
        'payments/failures/f6' => ['uri' => 'exotic://docs/payments/failures/f6', 'roles' => ['admin', 'sub_admin', 'sales', 'field_sales'], 'audiences' => ['finance', 'sales'], 'stages' => ['payment', 'activation']],
        'product/lifecycle' => ['uri' => 'exotic://docs/product/lifecycle', 'roles' => ['admin', 'sub_admin', 'sales', 'field_sales', 'marketing'], 'audiences' => ['sales', 'product'], 'stages' => ['discover', 'renewal']],
        'product/overview' => ['uri' => 'exotic://docs/product/overview', 'roles' => ['admin', 'sub_admin', 'sales', 'field_sales', 'marketing'], 'audiences' => ['sales', 'product'], 'stages' => ['discover']],
    ],
    'max_documents' => 60,
    'max_bytes' => 8 * 1024 * 1024,
    'max_chunk_chars' => 4500,
];
