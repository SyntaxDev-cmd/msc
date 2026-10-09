<?php
declare(strict_types=1);

/**
 * Processador da fila via linha de comando / cron (opcional — o app já processa sozinho).
 * Cron na Hostinger (a cada 5 min):  /usr/bin/php /home/SEU_USUARIO/domains/SEU_DOMINIO/public_html/worker.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require __DIR__ . '/src/bootstrap.php';

$n = Worker::run(280);
echo date('c') . " processados: {$n}\n";
