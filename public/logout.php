<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Zum Abmelden bitte das Formular im geschützten Bereich verwenden.');
}
requireCsrf();
destroyLocalSession();
redirectTo('login.php?logout=1');
