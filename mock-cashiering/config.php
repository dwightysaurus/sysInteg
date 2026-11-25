<?php
// MOCK Cashiering System settings (stand-in until the real Cashiering System is ready).
return [
    'api_key'             => 'itec116-shared-secret',  // same as the portal's cashiering.api_key
    'portal_callback_url' => 'http://localhost/registration-portal/api/cashiering-callback.php',
    'timeout'             => 5,
    // Sample fee rules (the real Cashiering System owns these)
    'tuition_per_unit'    => 150.00,
    'laboratory_fee'      => 1200.00,   // charged when at least one subject has a lab
    'miscellaneous_fee'   => 1850.00,
];
