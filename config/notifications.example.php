<?php
// Copy to config/notifications.php on the server; that local file is Git-ignored.
// Prefer a service-account file outside public_html, accessible only to this hosting user.
return [
    'credentials' => __DIR__ . '/firebase-service-account.json',
];
