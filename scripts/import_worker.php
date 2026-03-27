<?php
/**
 * Import Worker - Script CLI per importazione PST in background
 *
 * Utilizzo:
 *   php import_worker.php --import-id=42
 */

declare(strict_types=1);

// Solo da CLI
if (PHP_SAPI !== 'cli') {
    die('Questo script deve essere eseguito da CLI.');
}

// Rimuovi limiti per PST grandi
set_time_limit(0);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

use Mailbox\PstImporter;

// Parametri
$opts     = getopt('', ['import-id:']);
$importId = isset($opts['import-id']) ? (int)$opts['import-id'] : 0;

if ($importId <= 0) {
    fwrite(STDERR, "Errore: --import-id è obbligatorio\n");
    exit(1);
}

$pdo = getDB();

// Carica importazione
$stmt = $pdo->prepare('SELECT * FROM pst_imports WHERE id = ? LIMIT 1');
$stmt->execute([$importId]);
$import = $stmt->fetch();

if (!$import) {
    fwrite(STDERR, "Errore: importazione #$importId non trovata\n");
    exit(1);
}

$pstPath = STORAGE_PATH . '/pst/' . $import['stored_filename'];

if (!file_exists($pstPath)) {
    $pdo->prepare("UPDATE pst_imports SET status='error', error_message=? WHERE id=?")
        ->execute(['File PST non trovato: ' . $import['stored_filename'], $importId]);
    exit(1);
}

echo "[INFO] Avvio importazione #$importId: {$import['original_filename']}\n";

// Marca come in estrazione
$pdo->prepare("UPDATE pst_imports SET status='extracting', started_at=NOW() WHERE id=?")
    ->execute([$importId]);

try {
    $importer = new PstImporter();

    // Step 1: Estrazione PST → EML
    echo "[INFO] Estrazione file .eml da PST...\n";
    $emlDir = $importer->extract($importId, $pstPath);
    echo "[INFO] Estrazione completata: $emlDir\n";

    // Conta file .eml estratti
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($emlDir, FilesystemIterator::SKIP_DOTS)
    );
    $totalEml = 0;
    foreach ($iterator as $f) {
        if (strtolower($f->getExtension()) === 'eml') $totalEml++;
    }

    $pdo->prepare("UPDATE pst_imports SET status='importing', total_emails=? WHERE id=?")
        ->execute([$totalEml, $importId]);

    echo "[INFO] Totale email da importare: $totalEml\n";

    // Step 2: Importazione nel database
    $stats = $importer->importAll($importId, $emlDir);

    echo "[INFO] Completato: {$stats['imported']} importate, {$stats['skipped']} duplicate, {$stats['errors']} errori\n";

    // Marca come completato
    $pdo->prepare(
        "UPDATE pst_imports SET status='completed', completed_at=NOW(),
         imported_emails=?, skipped_emails=?, error_emails=? WHERE id=?"
    )->execute([$stats['imported'], $stats['skipped'], $stats['errors'], $importId]);

    // Pulizia file EML estratti (opzionale, commentare per debug)
    // array_map('unlink', glob($emlDir . '/**/*.eml', GLOB_BRACE));

} catch (\Throwable $e) {
    $errMsg = $e->getMessage();
    fwrite(STDERR, "[ERROR] $errMsg\n");

    $pdo->prepare("UPDATE pst_imports SET status='error', error_message=? WHERE id=?")
        ->execute([$errMsg, $importId]);

    exit(1);
}

echo "[INFO] Worker terminato correttamente.\n";
exit(0);
