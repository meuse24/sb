<?php
declare(strict_types=1);

function requireCsrf(): void
{
    $token = $_POST['csrf'] ?? null;
    if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(403);
        exit('Das Formular ist abgelaufen. Bitte die Seite neu laden.');
    }
}
