<?php

return [
    // Local fixtures only: the production environment can never execute simulated settlement.
    'local_simulator' => env('MONETIZATION_LOCAL_SIMULATOR', false),
];
