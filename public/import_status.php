<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

use Mailbox\Auth;

Auth::require();

$id  = (int)($_GET['id'] ?? 0);
$pdo = getDB();

$stmt = $pdo->prepare('SELECT * FROM pst_imports WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$import = $stmt->fetch();

if (!$import) {
    http_response_code(404);
    die('Importazione non trovata.');
}

// Se richiesta AJAX, restituisce JSON
if (isset($_GET['json'])) {
    header('Content-Type: application/json');

    $elapsed  = $import['started_at'] ? time() - strtotime($import['started_at']) : 0;
    $total    = (int)$import['total_emails'];
    $done     = (int)$import['imported_emails'] + (int)$import['skipped_emails'] + (int)$import['error_emails'];
    $pct      = $total > 0 ? min(100, round($done / $total * 100)) : 0;
    $remaining = 0;
    if ($done > 0 && $elapsed > 0 && $total > $done) {
        $rate      = $done / $elapsed;
        $remaining = (int)(($total - $done) / $rate);
    }

    echo json_encode([
        'status'            => $import['status'],
        'total_emails'      => $total,
        'imported_emails'   => (int)$import['imported_emails'],
        'skipped_emails'    => (int)$import['skipped_emails'],
        'error_emails'      => (int)$import['error_emails'],
        'percentage'        => $pct,
        'elapsed_seconds'   => $elapsed,
        'estimated_remaining' => $remaining,
        'error_message'     => $import['error_message'],
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Importazione in corso – <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/custom.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-primary px-3 py-2 shadow-sm">
    <a class="navbar-brand fw-bold" href="index.php">
        <i class="fa-solid fa-envelope-open-text me-2"></i><?= htmlspecialchars(APP_NAME) ?>
    </a>
</nav>

<div class="container py-5" style="max-width:640px">
    <div class="card border-0 shadow-sm p-4">
        <h5 class="fw-bold mb-1">
            <i class="fa-solid fa-gear fa-spin me-2 text-primary" id="spinIcon"></i>
            Importazione in corso
        </h5>
        <p class="text-muted small mb-4" id="filename">
            <?= htmlspecialchars($import['original_filename']) ?>
        </p>

        <div class="progress mb-3" style="height:28px">
            <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary"
                 id="progressBar" role="progressbar" style="width:0%">0%</div>
        </div>

        <div class="row g-3 text-center mb-3">
            <div class="col-3">
                <div class="fw-bold text-success fs-4" id="cntImported">0</div>
                <div class="small text-muted">Importate</div>
            </div>
            <div class="col-3">
                <div class="fw-bold text-warning fs-4" id="cntSkipped">0</div>
                <div class="small text-muted">Duplicate</div>
            </div>
            <div class="col-3">
                <div class="fw-bold text-danger fs-4" id="cntErrors">0</div>
                <div class="small text-muted">Errori</div>
            </div>
            <div class="col-3">
                <div class="fw-bold text-muted fs-4" id="cntTotal">–</div>
                <div class="small text-muted">Totali</div>
            </div>
        </div>

        <div class="text-center small text-muted" id="statusText">
            Avvio in corso...
        </div>

        <div id="doneActions" class="d-none text-center mt-4">
            <a href="index.php" class="btn btn-primary px-4">
                <i class="fa-solid fa-inbox me-2"></i>Vai alle email
            </a>
            <a href="import.php" class="btn btn-outline-secondary px-4 ms-2">
                <i class="fa-solid fa-file-import me-2"></i>Nuova importazione
            </a>
        </div>

        <div id="errorBox" class="alert alert-danger d-none mt-3"></div>
    </div>
</div>

<script>
const importId  = <?= $id ?>;
let pollTimer   = null;

function formatSeconds(s) {
    if (s < 60) return s + 's';
    return Math.floor(s/60) + 'm ' + (s%60) + 's';
}

function poll() {
    fetch('import_status.php?id=' + importId + '&json=1')
        .then(r => r.json())
        .then(data => {
            document.getElementById('progressBar').style.width    = data.percentage + '%';
            document.getElementById('progressBar').textContent    = data.percentage + '%';
            document.getElementById('cntImported').textContent    = data.imported_emails.toLocaleString('it');
            document.getElementById('cntSkipped').textContent     = data.skipped_emails.toLocaleString('it');
            document.getElementById('cntErrors').textContent      = data.error_emails.toLocaleString('it');
            document.getElementById('cntTotal').textContent       = data.total_emails > 0 ? data.total_emails.toLocaleString('it') : '–';

            let statusMsg = '';
            if (data.status === 'extracting') statusMsg = 'Estrazione email dal file PST...';
            else if (data.status === 'importing') {
                statusMsg = 'Importazione nel database...';
                if (data.estimated_remaining > 0) statusMsg += ' (circa ' + formatSeconds(data.estimated_remaining) + ' rimanenti)';
            }
            else if (data.status === 'pending') statusMsg = 'In attesa di avvio...';
            document.getElementById('statusText').textContent = statusMsg;

            if (data.status === 'completed') {
                clearInterval(pollTimer);
                document.getElementById('spinIcon').classList.remove('fa-spin');
                document.getElementById('progressBar').classList.remove('progress-bar-animated', 'progress-bar-striped');
                document.getElementById('progressBar').classList.add('bg-success');
                document.getElementById('statusText').textContent = 'Importazione completata!';
                document.getElementById('doneActions').classList.remove('d-none');
            } else if (data.status === 'error') {
                clearInterval(pollTimer);
                document.getElementById('spinIcon').classList.remove('fa-spin');
                document.getElementById('progressBar').classList.add('bg-danger');
                const box = document.getElementById('errorBox');
                box.textContent = 'Errore: ' + (data.error_message || 'Errore sconosciuto');
                box.classList.remove('d-none');
                document.getElementById('doneActions').classList.remove('d-none');
            }
        })
        .catch(() => {});
}

poll();
pollTimer = setInterval(poll, 2500);
</script>
</body>
</html>
