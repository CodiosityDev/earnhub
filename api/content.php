<?php
declare(strict_types=1);
require dirname(__DIR__) . '/admin/cms.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('{"error":"Method not allowed"}');
}
try {
    echo json_encode(cmsContent(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('SpawnVault content: ' . $error->getMessage());
    http_response_code(503);
    echo '{"error":"Konten sementara tidak tersedia"}';
}
