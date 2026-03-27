<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

use Mailbox\Auth;
use Mailbox\EmailRepository;

Auth::require();

$repo    = new EmailRepository(getDB());
$stats   = $repo->getStats();
$folders = $repo->getFolders();
$imports = $repo->getImports();

// Filtri dalla query string
$filters = [
    'from'            => trim($_GET['from']   ?? ''),
    'to'              => trim($_GET['to']     ?? ''),
    'subject'         => trim($_GET['subject'] ?? ''),
    'date_from'       => $_GET['date_from']   ?? '',
    'date_to'         => $_GET['date_to']     ?? '',
    'q'               => trim($_GET['q']      ?? ''),
    'folder'          => $_GET['folder']      ?? '',
    'import_id'       => $_GET['import_id']   ?? '',
    'has_attachments' => $_GET['has_attachments'] ?? '',
    'flagged'         => $_GET['flagged']     ?? '',
    'sort'            => $_GET['sort']        ?? 'date_desc',
];

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$total   = $repo->countAll($filters);
$emails  = $repo->search($filters, $page, $perPage);
$pages   = (int)ceil($total / $perPage);

// Costruisce URL filtri per paginazione
$queryParams = http_build_query(array_filter(array_merge($filters, ['page' => '%d'])));
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
    <link href="assets/css/custom.css" rel="stylesheet">
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-dark bg-primary navbar-expand-lg px-3 py-2 shadow-sm">
    <a class="navbar-brand fw-bold" href="index.php">
        <i class="fa-solid fa-envelope-open-text me-2"></i><?= htmlspecialchars(APP_NAME) ?>
    </a>
    <div class="ms-auto d-flex gap-2 align-items-center">
        <a href="import.php" class="btn btn-outline-light btn-sm">
            <i class="fa-solid fa-file-import me-1"></i>Importa PST
        </a>
        <span class="text-white-50 small"><?= htmlspecialchars($_SESSION['username']) ?></span>
        <a href="logout.php" class="btn btn-outline-light btn-sm">
            <i class="fa-solid fa-right-from-bracket"></i>
        </a>
    </div>
</nav>

<div class="container-fluid px-4 py-3">

    <!-- Statistiche -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="display-6 fw-bold text-primary"><?= number_format($stats['total']) ?></div>
                <div class="text-muted small">Email totali</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="display-6 fw-bold text-success"><?= number_format($stats['imports']) ?></div>
                <div class="text-muted small">PST importati</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="display-6 fw-bold text-warning"><?= number_format($stats['folders']) ?></div>
                <div class="text-muted small">Cartelle</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="display-6 fw-bold text-info"><?= number_format($stats['with_att']) ?></div>
                <div class="text-muted small">Con allegati</div>
            </div>
        </div>
    </div>

    <!-- Pannello filtri -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold border-bottom">
            <i class="fa-solid fa-filter me-2 text-primary"></i>Filtri di ricerca
            <?php if (array_filter($filters)): ?>
                <a href="index.php" class="btn btn-outline-secondary btn-sm ms-2">
                    <i class="fa-solid fa-xmark me-1"></i>Azzera filtri
                </a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="get" id="filterForm">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Da (mittente)</label>
                        <input type="text" name="from" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($filters['from']) ?>" placeholder="es. mario@esempio.com">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">A (destinatario)</label>
                        <input type="text" name="to" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($filters['to']) ?>" placeholder="es. ufficio@azienda.it">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Oggetto</label>
                        <input type="text" name="subject" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($filters['subject']) ?>" placeholder="Parole nell'oggetto">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">Cartella PST</label>
                        <select name="folder" class="form-select form-select-sm">
                            <option value="">Tutte</option>
                            <?php foreach ($folders as $f): ?>
                                <option value="<?= htmlspecialchars($f['folder_name']) ?>"
                                    <?= $filters['folder'] === $f['folder_name'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($f['folder_name']) ?> (<?= $f['cnt'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">Data da</label>
                        <input type="text" name="date_from" id="date_from" class="form-control form-control-sm flatpickr"
                               value="<?= htmlspecialchars($filters['date_from']) ?>" placeholder="gg/mm/aaaa">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">Data a</label>
                        <input type="text" name="date_to" id="date_to" class="form-control form-control-sm flatpickr"
                               value="<?= htmlspecialchars($filters['date_to']) ?>" placeholder="gg/mm/aaaa">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Testo corpo email</label>
                        <input type="text" name="q" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($filters['q']) ?>" placeholder="Ricerca full-text nel corpo...">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">Archivio PST</label>
                        <select name="import_id" class="form-select form-select-sm">
                            <option value="">Tutti</option>
                            <?php foreach ($imports as $imp): ?>
                                <option value="<?= $imp['id'] ?>"
                                    <?= (string)$filters['import_id'] === (string)$imp['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($imp['original_filename']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="has_attachments" value="1" id="chkAtt"
                                <?= $filters['has_attachments'] ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="chkAtt">Con allegati</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="flagged" value="1" id="chkFlag"
                                <?= $filters['flagged'] ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="chkFlag">Flaggate</label>
                        </div>
                    </div>
                    <div class="col-12 d-flex gap-2 justify-content-end">
                        <select name="sort" class="form-select form-select-sm w-auto">
                            <option value="date_desc" <?= $filters['sort']==='date_desc'?'selected':''?>>Data ↓ (più recenti)</option>
                            <option value="date_asc"  <?= $filters['sort']==='date_asc' ?'selected':''?>>Data ↑ (più vecchie)</option>
                            <option value="subject_asc" <?= $filters['sort']==='subject_asc'?'selected':''?>>Oggetto A→Z</option>
                            <option value="from_asc"  <?= $filters['sort']==='from_asc' ?'selected':''?>>Mittente A→Z</option>
                        </select>
                        <button type="submit" class="btn btn-primary btn-sm px-4">
                            <i class="fa-solid fa-magnifying-glass me-1"></i>Cerca
                        </button>
                        <a href="export.php?<?= http_build_query(array_filter($filters)) ?>" class="btn btn-outline-success btn-sm">
                            <i class="fa-solid fa-file-csv me-1"></i>Export CSV
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Risultati -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center border-bottom py-2">
            <span class="fw-semibold">
                <i class="fa-solid fa-list me-2 text-primary"></i>
                <?= number_format($total) ?> email trovate
            </span>
            <small class="text-muted">Pagina <?= $page ?> di <?= max(1, $pages) ?></small>
        </div>

        <?php if (empty($emails)): ?>
            <div class="card-body text-center py-5 text-muted">
                <i class="fa-solid fa-inbox fa-3x mb-3 d-block opacity-25"></i>
                Nessuna email trovata con i filtri selezionati.
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:30px"></th>
                        <th>Mittente</th>
                        <th>Oggetto</th>
                        <th>Cartella</th>
                        <th>Data</th>
                        <th style="width:60px" class="text-center">All.</th>
                        <th style="width:80px"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($emails as $email): ?>
                    <tr class="<?= $email['is_flagged'] ? 'table-warning' : '' ?>">
                        <td class="text-center">
                            <?php if ($email['is_flagged']): ?>
                                <i class="fa-solid fa-flag text-warning"></i>
                            <?php endif; ?>
                        </td>
                        <td class="text-truncate" style="max-width:180px" title="<?= htmlspecialchars($email['from_address']) ?>">
                            <?= htmlspecialchars($email['from_name'] ?: $email['from_address']) ?>
                        </td>
                        <td class="text-truncate" style="max-width:350px">
                            <a href="email.php?id=<?= $email['id'] ?>" class="text-decoration-none fw-semibold text-dark">
                                <?= htmlspecialchars($email['subject'] ?: '(senza oggetto)') ?>
                            </a>
                        </td>
                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($email['folder_name'] ?? '-') ?></span></td>
                        <td class="text-nowrap small text-muted">
                            <?= $email['email_date'] ? date('d/m/Y H:i', strtotime($email['email_date'])) : '-' ?>
                        </td>
                        <td class="text-center">
                            <?php if ($email['has_attachments']): ?>
                                <span class="badge bg-secondary"><?= $email['attachment_count'] ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="email.php?id=<?= $email['id'] ?>" class="btn btn-outline-primary btn-xs py-0 px-2 small">
                                <i class="fa-solid fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Paginazione -->
        <?php if ($pages > 1): ?>
        <div class="card-footer bg-white border-top">
            <nav>
                <ul class="pagination pagination-sm justify-content-center mb-0">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?= sprintf($queryParams, $page - 1) ?>">‹</a>
                        </li>
                    <?php endif; ?>
                    <?php for ($i = max(1, $page-3); $i <= min($pages, $page+3); $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?<?= sprintf($queryParams, $i) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <?php if ($page < $pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?= sprintf($queryParams, $page + 1) ?>">›</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://npmcdn.com/flatpickr/dist/l10n/it.js"></script>
<script src="assets/js/app.js"></script>
<script>
flatpickr('.flatpickr', {
    dateFormat: 'Y-m-d',
    locale: 'it',
    allowInput: true
});
</script>
</body>
</html>
