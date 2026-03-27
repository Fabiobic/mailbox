<?php
/**
 * Import PST da riga di comando - gestisce file di qualsiasi dimensione
 *
 * Utilizzo:
 *   php scripts/import_pst.php --file="D:\percorso\archivio.pst" [--label="Nome archivio"] [--skip-extract]
 *
 * Opzioni:
 *   --file          Path assoluto al file .pst (OBBLIGATORIO)
 *   --label         Nome descrittivo (default: nome del file)
 *   --skip-extract  Salta l'estrazione readpst (usa mbox già estratti)
 *   --import-id     Riprende un'importazione esistente (usare con --skip-extract)
 *   --memory        Limite memoria es. 1024M (default: 512M)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    die("Questo script deve essere eseguito da riga di comando.\n");
}

// ── Parametri ────────────────────────────────────────────────────────────────
$opts = getopt('', [
    'file:',
    'label:',
    'skip-extract',
    'import-id:',
    'memory:',
]);

$pstFile     = $opts['file']      ?? null;
$label       = $opts['label']     ?? null;
$skipExtract = isset($opts['skip-extract']);
$importId    = isset($opts['import-id']) ? (int)$opts['import-id'] : null;
$memLimit    = $opts['memory']    ?? '512M';

ini_set('memory_limit', $memLimit);
set_time_limit(0);

if (!$pstFile && !$skipExtract) {
    fwrite(STDERR, "Errore: --file è obbligatorio.\n");
    fwrite(STDERR, "Uso: php scripts/import_pst.php --file=\"D:\\percorso\\archivio.pst\"\n");
    exit(1);
}

if ($pstFile && !file_exists($pstFile)) {
    fwrite(STDERR, "Errore: file non trovato: $pstFile\n");
    exit(1);
}

// ── Bootstrap ────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

use Mailbox\EmailParser;
use Mailbox\EmailRepository;
use Mailbox\PstImporter;

$pdo = getDB();

// ── Funzione di log con timestamp ─────────────────────────────────────────────
function log_info(string $msg): void
{
    echo '[' . date('H:i:s') . '] ' . $msg . "\n";
}

function log_err(string $msg): void
{
    fwrite(STDERR, '[' . date('H:i:s') . '] ERRORE: ' . $msg . "\n");
}

function format_bytes(int $bytes): string
{
    if ($bytes >= 1_073_741_824) return round($bytes / 1_073_741_824, 2) . ' GB';
    if ($bytes >= 1_048_576)     return round($bytes / 1_048_576, 2) . ' MB';
    return round($bytes / 1024, 2) . ' KB';
}

// ── Step 1: crea o carica record in pst_imports ───────────────────────────────
if ($importId) {
    $stmt = $pdo->prepare('SELECT * FROM pst_imports WHERE id = ? LIMIT 1');
    $stmt->execute([$importId]);
    $import = $stmt->fetch();
    if (!$import) {
        fwrite(STDERR, "Importazione #$importId non trovata.\n");
        exit(1);
    }
    log_info("Ripresa importazione #$importId: {$import['original_filename']}");
} else {
    $origName  = basename($pstFile);
    $fileSize  = filesize($pstFile);
    $labelText = $label ?: pathinfo($origName, PATHINFO_FILENAME);

    // Copia il file PST in storage/pst/ (link simbolico o copia)
    $storagePst  = STORAGE_PATH . '/pst/';
    $storedName  = time() . '_' . $origName;
    $storedPath  = $storagePst . $storedName;

    // Per file grandi: usa hard link se stesso disco, altrimenti copia
    log_info("File PST: $origName (" . format_bytes($fileSize) . ")");

    if (!is_dir($storagePst)) {
        mkdir($storagePst, 0755, true);
    }

    // Non copiamo 30 GB: usiamo il path originale direttamente
    $storedName = $origName; // mantieni nome originale
    $storedPath = $pstFile;  // path diretto, nessuna copia

    $stmt = $pdo->prepare(
        'INSERT INTO pst_imports
         (original_filename, stored_filename, file_size, status, created_at, user_id)
         VALUES (?, ?, ?, \'pending\', NOW(), 1)'
    );
    $stmt->execute([$origName, basename($pstFile), $fileSize]);
    $importId = (int)$pdo->lastInsertId();

    log_info("Record creato: importazione #$importId");
}

// ── Step 2: Estrazione PST → mbox ─────────────────────────────────────────────
$emlDir = STORAGE_PATH . '/eml/' . $importId;

if (!$skipExtract) {
    log_info("Avvio estrazione con readpst (può richiedere ore per 30 GB)...");
    log_info("Output: $emlDir");

    $pdo->prepare("UPDATE pst_imports SET status='extracting', started_at=NOW() WHERE id=?")
        ->execute([$importId]);

    if (!is_dir($emlDir)) {
        mkdir($emlDir, 0755, true);
    }

    // Converti path Windows → WSL
    $readpstPath = $_ENV['READPST_PATH'] ?? 'wsl readpst';
    $wslPst      = toWslPath($pstFile);
    $wslOutput   = toWslPath($emlDir);
    $cmd         = sprintf('%s -r -o %s %s', $readpstPath,
                          escapeshellarg($wslOutput), escapeshellarg($wslPst));

    log_info("Comando: $cmd");

    $startTime = time();
    passthru($cmd, $returnCode); // passthru mostra output in tempo reale

    $elapsed = time() - $startTime;
    log_info(sprintf("Estrazione completata in %d min %d sec (codice: %d)",
             intdiv($elapsed, 60), $elapsed % 60, $returnCode));

    if ($returnCode !== 0) {
        $pdo->prepare("UPDATE pst_imports SET status='error', error_message=? WHERE id=?")
            ->execute(["Errore readpst (codice $returnCode)", $importId]);
        exit(1);
    }
} else {
    log_info("Estrazione saltata, uso mbox in: $emlDir");
}

// ── Step 3: Conta i file mbox ─────────────────────────────────────────────────
log_info("Scansione file mbox estratti...");
$mboxFiles = [];
$iterator  = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($emlDir, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if ($file->getFilename() === 'mbox') {
        $mboxFiles[] = $file->getPathname();
    }
}

$totalMbox = count($mboxFiles);
log_info("Trovati $totalMbox file mbox da importare.");

$pdo->prepare("UPDATE pst_imports SET status='importing' WHERE id=?")->execute([$importId]);

// ── Step 4: Importazione streaming ────────────────────────────────────────────
$parser  = new EmailParser();
$repo    = new EmailRepository($pdo);
$stats   = ['imported' => 0, 'skipped' => 0, 'errors' => 0, 'total' => 0];
$startTime = time();

$updateStmt = $pdo->prepare(
    'UPDATE pst_imports SET imported_emails=?, skipped_emails=?, error_emails=?, total_emails=? WHERE id=?'
);

foreach ($mboxFiles as $mboxIdx => $mboxPath) {
    $folderName  = extractFolderName($mboxPath, $emlDir);
    $mboxSize    = filesize($mboxPath);
    $mboxNum     = $mboxIdx + 1;

    echo sprintf("[%s] Cartella %d/%d: %-50s (%s)\n",
        date('H:i:s'), $mboxNum, $totalMbox,
        substr($folderName, -50), format_bytes($mboxSize));

    // ── Parsing streaming del file mbox ───────────────────────────────────────
    // Non carichiamo tutto in RAM: leggiamo riga per riga
    $fh = fopen($mboxPath, 'r');
    if (!$fh) {
        log_err("Impossibile aprire: $mboxPath");
        continue;
    }

    $buffer      = '';
    $inMessage   = false;

    while (($line = fgets($fh)) !== false) {
        // Separatore mbox: "From " a inizio riga
        if (str_starts_with($line, 'From ')) {
            // Processa il messaggio precedente
            if ($inMessage && trim($buffer) !== '') {
                processMessage($buffer, $importId, $folderName, $parser, $repo, $stats);
                $buffer = '';
            }
            $inMessage = true;
            continue; // la riga "From " non fa parte del messaggio
        }

        if ($inMessage) {
            $buffer .= $line;
        }
    }

    // Ultimo messaggio nel file
    if ($inMessage && trim($buffer) !== '') {
        processMessage($buffer, $importId, $folderName, $parser, $repo, $stats);
    }

    fclose($fh);

    // Aggiorna DB ogni cartella
    $updateStmt->execute([
        $stats['imported'],
        $stats['skipped'],
        $stats['errors'],
        $stats['total'],
        $importId,
    ]);

    // Mostra statistiche ogni 10 cartelle
    if ($mboxNum % 10 === 0 || $mboxNum === $totalMbox) {
        $elapsed = time() - $startTime;
        $rate    = $elapsed > 0 ? round($stats['total'] / $elapsed, 1) : 0;
        $mem     = format_bytes(memory_get_usage(true));
        echo sprintf("    → Importate: %d | Duplicate: %d | Errori: %d | Velocità: %.1f email/s | RAM: %s\n",
            $stats['imported'], $stats['skipped'], $stats['errors'], $rate, $mem);
    }
}

// ── Step 5: Completamento ─────────────────────────────────────────────────────
$pdo->prepare(
    "UPDATE pst_imports SET status='completed', completed_at=NOW(),
     imported_emails=?, skipped_emails=?, error_emails=?, total_emails=? WHERE id=?"
)->execute([
    $stats['imported'],
    $stats['skipped'],
    $stats['errors'],
    $stats['total'],
    $importId,
]);

$elapsed = time() - $startTime;
echo "\n";
log_info("═══════════════════════════════════════════════════");
log_info("IMPORTAZIONE COMPLETATA - #$importId");
log_info(sprintf("  Email importate:  %d", $stats['imported']));
log_info(sprintf("  Duplicate:        %d", $stats['skipped']));
log_info(sprintf("  Errori parsing:   %d", $stats['errors']));
log_info(sprintf("  Totale elaborate: %d", $stats['total']));
log_info(sprintf("  Tempo totale:     %d min %d sec", intdiv($elapsed, 60), $elapsed % 60));
log_info("═══════════════════════════════════════════════════");

exit(0);

// ── Funzioni helper ───────────────────────────────────────────────────────────

function processMessage(
    string $raw,
    int $importId,
    string $folderName,
    EmailParser $parser,
    EmailRepository $repo,
    array &$stats
): void {
    $stats['total']++;
    try {
        $data = $parser->parseFromString($raw);
        $data['pst_import_id'] = $importId;
        $data['folder_name']   = $folderName;
        $data['pst_filename']  = 'mbox';

        if ($repo->insert($data)) {
            $stats['imported']++;
        } else {
            $stats['skipped']++;
        }
    } catch (\Throwable $e) {
        $stats['errors']++;
        // Log solo ogni 100 errori per non intasare il terminale
        if ($stats['errors'] % 100 === 1) {
            fwrite(STDERR, "  [WARN] Errore parsing: " . $e->getMessage() . "\n");
        }
    }
}

function toWslPath(string $windowsPath): string
{
    $path = str_replace('\\', '/', $windowsPath);
    if (preg_match('/^([A-Za-z]):\/(.*)$/', $path, $m)) {
        return '/mnt/' . strtolower($m[1]) . '/' . $m[2];
    }
    return $path;
}

function extractFolderName(string $mboxPath, string $baseDir): string
{
    $baseDir  = str_replace('\\', '/', rtrim($baseDir, '/\\'));
    $mboxPath = str_replace('\\', '/', $mboxPath);
    $relative = ltrim(substr($mboxPath, strlen($baseDir)), '/');
    $relative = preg_replace('/\/mbox$/', '', $relative);
    return $relative ?: 'root';
}
