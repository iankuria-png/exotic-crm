<?php

return [
    'host' => 'exoticonline.mintlify.app',
    'manifest' => 'https://exoticonline.mintlify.app/llms.txt',
    // This is an ACL and classification manifest, never remote-controlled.
    'documents' => [
        'payments/overview.md' => ['uri' => 'exotic://docs/payments/overview', 'roles' => ['admin', 'sub_admin', 'sales', 'field_sales'], 'audiences' => ['finance', 'sales'], 'stages' => ['payment']],
        'payments/matching.md' => ['uri' => 'exotic://docs/payments/matching', 'roles' => ['admin', 'sub_admin', 'sales', 'field_sales'], 'audiences' => ['finance', 'sales'], 'stages' => ['payment', 'activation']],
        'product/stage-5e-failure-modes.md' => ['uri' => 'exotic://docs/product/failure-modes', 'roles' => ['admin', 'sub_admin', 'sales', 'field_sales'], 'audiences' => ['finance', 'sales'], 'stages' => ['payment', 'activation']],
        'product/overview.md' => ['uri' => 'exotic://docs/product/overview', 'roles' => ['admin', 'sub_admin', 'sales', 'field_sales', 'marketing'], 'audiences' => ['sales', 'product'], 'stages' => ['discover']],
    ],
    'max_documents' => 60,
    'max_bytes' => 8 * 1024 * 1024,
    'max_chunk_chars' => 4500,
];
