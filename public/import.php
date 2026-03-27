<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

use Mailbox\Auth;
use Mailbox\EmailRepository;

Auth::require();

$repo    = new EmailRepository(getDB());
$imports = $repo->getImports();
$error   = '';
$success = '';

// Gestione upload PST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pst_file'])) {
    $file = $_FILES['pst_file'];

    // Validazione
    $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $maxSize = (int)($_ENV['MAX_UPLOAD_MB'] ?? 2048) * 1024 * 1024;

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'Errore durante l\'upload (codice: ' . $file['error'] . ')';
    } elseif ($ext !== 'pst') {
        $error = 'Solo file .pst sono accettati.';
    } elseif ($file['size'] > $maxSize) {
        $error = 'Il file supera il limite di ' . ($_ENV['MAX_UPLOAD_MB'] ?? 2048) . ' MB.';
    } else {
        // Crea record importazione
        $pdo  = getDB();
        $stmt = $pdo->prepare(
            'INSERT INTO pst_imports (original_filename, file_size_mb, status, imported_by)
             VALUES (?, ?, "pending", ?)'
        );
        $stmt->execute([
            $file['name'],
            round($file['size'] / 1024 / 1024, 2),
            Auth::userId(),
        ]);
        $importId = (int)$pdo->lastInsertId();

        // Avvia importazione in background
        $workerPath = escapeshellarg(realpath(__DIR__ . '/../scripts/import_worker.php'));
        $phpBin     = PHP_BINARY;
        $cmd        = $phpBin . ' ' . $workerPath . ' --import-id=' . $importId;

        // Salva file PST prima di lanciare il worker
        $pstDir = STORAGE_PATH . '/pst';
        if (!is_dir($pstDir)) mkdir($pstDir, 0755, true);
        $storedName = $importId . '_' . time() . '.pst';
        $pstPath    = $pstDir . '/' . $storedName;
        move_uploaded_file($file['tmp_name'], $pstPath);

        // Aggiorna il nome del file storato
        $pdo->prepare('UPDATE pst_imports SET stored_filename=? WHERE id=?')
            ->execute([$storedName, $importId]);

        // Lancia worker in background (Linux: &, Windows: start /B)
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start /B ' . $cmd . ' > NUL 2>&1', 'r'));
        } else {
            exec($cmd . ' > /dev/null 2>&1 &');
        }

        header('Location: import_status.php?id=' . $importId);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Importa PST – <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/custom.css" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-dark bg-primary px-3 py-2 shadow-sm">
    <a class="navbar-brand fw-bold" href="index.php">
        <i class="fa-solid fa-envelope-open-text me-2"></i><?= htmlspecialchars(APP_NAME) ?>
    </a>
    <a href="index.php" class="btn btn-outline-light btn-sm ms-auto">
        <i class="fa-solid fa-inbox me-1"></i>Le mie email
    </a>
</nav>

<div class="container py-4" style="max-width:800px">

    <h4 class="fw-bold mb-4"><i class="fa-solid fa-file-import me-2 text-primary"></i>Importa file PST</h4>

    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Form upload -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4">
            <form method="post" enctype="multipart/form-data" id="uploadForm">
                <div class="mb-3">
                    <label class="form-label fw-semibold">File PST di Outlook</label>
                    <input type="file" name="pst_file" id="pstFile" class="form-control" accept=".pst" required>
                    <div class="form-text">
                        Dimensione massima: <?= $_ENV['MAX_UPLOAD_MB'] ?? 2048 ?> MB.
                        I file di grandi dimensioni possono richiedere diversi minuti.
                    </div>
                </div>

                <div id="uploadProgress" class="mb-3 d-none">
                    <label class="form-label small">Upload in corso...</label>
                    <div class="progress" style="height:20px">
                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary"
                             role="progressbar" style="width:0%" id="uploadBar">0%</div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary px-5" id="submitBtn">
                    <i class="fa-solid fa-upload me-2"></i>Carica e importa
                </button>
            </form>
        </div>
    </div>

    <!-- Storico importazioni -->
    <?php if (!empty($imports)): ?>
    <h6 class="fw-semibold mb-3"><i class="fa-solid fa-clock-rotate-left me-2 text-secondary"></i>Storico importazioni</h6>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>File PST</th>
                        <th>Dimensione</th>
                        <th>Email</th>
                        <th>Stato</th>
                        <th>Data</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($imports as $imp): ?>
                    <tr>
                        <td><?= htmlspecialchars($imp['original_filename']) ?></td>
                        <td><?= $imp['file_size_mb'] ?> MB</td>
                        <td>
                            <span class="text-success"><?= number_format($imp['imported_emails']) ?></span>
                            <?php if ($imp['skipped_emails'] > 0): ?>
                                <span class="text-muted"> +<?= $imp['skipped_emails'] ?> dup</span>
                            <?php endif; ?>
                            <?php if ($imp['error_emails'] > 0): ?>
                                <span class="text-danger"> +<?= $imp['error_emails'] ?> err</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $badges = [
                                'completed'  => 'bg-success',
                                'importing'  => 'bg-primary',
                                'extracting' => 'bg-info',
                                'error'      => 'bg-danger',
                                'pending'    => 'bg-secondary',
                            ]; ?>
                            <span class="badge <?= $badges[$imp['status']] ?? 'bg-secondary' ?>">
                                <?= htmlspecialchars($imp['status']) ?>
                            </span>
                        </td>
                        <td class="small text-muted"><?= date('d/m/Y H:i', strtotime($imp['created_at'])) ?></td>
                        <td>
                            <?php if (in_array($imp['status'], ['importing','extracting','pending'])): ?>
                                <a href="import_status.php?id=<?= $imp['id'] ?>" class="btn btn-outline-primary btn-sm">
                                    <i class="fa-solid fa-circle-notch fa-spin me-1"></i>Monitor
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('uploadForm').addEventListener('submit', function () {
    document.getElementById('uploadProgress').classList.remove('d-none');
    document.getElementById('submitBtn').disabled = true;
});
</script>
</body>
</html>
