<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

use Mailbox\Auth;

Auth::require();

$id  = (int)($_GET['id'] ?? 0);
$pdo = getDB();

$stmt = $pdo->prepare('SELECT * FROM attachments WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$att = $stmt->fetch();

if (!$att) {
    http_response_code(404);
    die('Allegato non trovato.');
}

$filePath = STORAGE_PATH . '/' . $att['file_path'];

// Sicurezza: verifica che il path sia dentro la directory storage
$realPath    = realpath($filePath);
$storagePath = realpath(STORAGE_PATH);

if (!$realPath || strpos($realPath, $storagePath) !== 0) {
    http_response_code(403);
    die('Accesso negato.');
}

if (!file_exists($realPath)) {
    http_response_code(404);
    die('File non trovato su disco.');
}

// Invia il file
$filename    = basename($att['filename']);
$contentType = $att['content_type'] ?: 'application/octet-stream';

header('Content-Type: ' . $contentType);
header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
header('Content-Length: ' . filesize($realPath));
header('Cache-Control: private');
header('Pragma: private');

readfile($realPath);
exit;
