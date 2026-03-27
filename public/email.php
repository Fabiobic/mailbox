<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

use Mailbox\Auth;
use Mailbox\EmailRepository;

Auth::require();

$id   = (int)($_GET['id'] ?? 0);
$repo = new EmailRepository(getDB());

$email = $repo->findById($id);
if (!$email) {
    http_response_code(404);
    die('Email non trovata.');
}

$attachments = $repo->getAttachments($id);
$viewMode    = $_GET['view'] ?? 'html'; // html | text
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($email['subject'] ?: '(senza oggetto)') ?> – <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/custom.css" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-dark bg-primary px-3 py-2 shadow-sm">
    <a class="navbar-brand fw-bold" href="index.php">
        <i class="fa-solid fa-envelope-open-text me-2"></i><?= htmlspecialchars(APP_NAME) ?>
    </a>
    <a href="javascript:history.back()" class="btn btn-outline-light btn-sm ms-auto">
        <i class="fa-solid fa-arrow-left me-1"></i>Torna alla lista
    </a>
</nav>

<div class="container-lg py-4">

    <!-- Header email -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <h5 class="fw-bold mb-3">
                <?php if ($email['is_flagged']): ?>
                    <i class="fa-solid fa-flag text-warning me-2"></i>
                <?php endif; ?>
                <?= htmlspecialchars($email['subject'] ?: '(senza oggetto)') ?>
            </h5>
            <div class="row g-2 small text-muted">
                <div class="col-sm-2 fw-semibold text-dark">Da:</div>
                <div class="col-sm-10">
                    <?= htmlspecialchars($email['from_name'] ? $email['from_name'] . ' <' . $email['from_address'] . '>' : $email['from_address']) ?>
                </div>
                <div class="col-sm-2 fw-semibold text-dark">A:</div>
                <div class="col-sm-10"><?= htmlspecialchars($email['to_address']) ?></div>
                <?php if ($email['cc_address']): ?>
                <div class="col-sm-2 fw-semibold text-dark">CC:</div>
                <div class="col-sm-10"><?= htmlspecialchars($email['cc_address']) ?></div>
                <?php endif; ?>
                <div class="col-sm-2 fw-semibold text-dark">Data:</div>
                <div class="col-sm-10">
                    <?= $email['email_date'] ? date('d/m/Y \a\l\l\e H:i:s', strtotime($email['email_date'])) : '-' ?>
                </div>
                <div class="col-sm-2 fw-semibold text-dark">Cartella:</div>
                <div class="col-sm-10">
                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($email['folder_name'] ?? '-') ?></span>
                </div>
                <?php if ($email['size_bytes']): ?>
                <div class="col-sm-2 fw-semibold text-dark">Dimensione:</div>
                <div class="col-sm-10"><?= number_format($email['size_bytes'] / 1024, 1) ?> KB</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Allegati -->
    <?php if (!empty($attachments)): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <span class="fw-semibold small me-3">
                <i class="fa-solid fa-paperclip me-1 text-secondary"></i>
                <?= count($attachments) ?> allegat<?= count($attachments) === 1 ? 'o' : 'i' ?>:
            </span>
            <?php foreach ($attachments as $att): ?>
                <a href="download.php?id=<?= $att['id'] ?>" class="btn btn-outline-secondary btn-sm me-1 mb-1">
                    <i class="fa-solid fa-download me-1"></i>
                    <?= htmlspecialchars($att['filename']) ?>
                    <span class="text-muted ms-1">(<?= number_format($att['file_size'] / 1024, 1) ?> KB)</span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Corpo email -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom d-flex gap-2">
            <?php if ($email['body_html']): ?>
            <a href="?id=<?= $id ?>&view=html" class="btn btn-sm <?= $viewMode==='html' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                <i class="fa-solid fa-code me-1"></i>HTML
            </a>
            <?php endif; ?>
            <?php if ($email['body_text']): ?>
            <a href="?id=<?= $id ?>&view=text" class="btn btn-sm <?= $viewMode==='text' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                <i class="fa-solid fa-align-left me-1"></i>Testo
            </a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($viewMode === 'html' && $email['body_html']): ?>
                <!-- Sandbox iframe per isolare il contenuto HTML dell'email -->
                <iframe
                    srcdoc="<?= htmlspecialchars($email['body_html']) ?>"
                    sandbox="allow-same-origin"
                    style="width:100%; min-height:500px; border:none;"
                    onload="this.style.height = this.contentDocument.body.scrollHeight + 'px'">
                </iframe>
            <?php elseif ($email['body_text']): ?>
                <pre class="mb-0" style="white-space:pre-wrap;font-family:inherit;font-size:.9rem"><?= htmlspecialchars($email['body_text']) ?></pre>
            <?php else: ?>
                <p class="text-muted text-center py-4">
                    <i class="fa-solid fa-circle-info me-2"></i>Nessun corpo disponibile per questa email.
                </p>
            <?php endif; ?>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
