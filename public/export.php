<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

use Mailbox\Auth;
use Mailbox\EmailRepository;
use Mailbox\SearchFilter;

Auth::require();

$repo    = new EmailRepository(getDB());
$filters = [
    'from'            => trim($_GET['from']    ?? ''),
    'to'              => trim($_GET['to']      ?? ''),
    'subject'         => trim($_GET['subject'] ?? ''),
    'date_from'       => $_GET['date_from']    ?? '',
    'date_to'         => $_GET['date_to']      ?? '',
    'q'               => trim($_GET['q']       ?? ''),
    'folder'          => $_GET['folder']       ?? '',
    'import_id'       => $_GET['import_id']    ?? '',
    'has_attachments' => $_GET['has_attachments'] ?? '',
];

// Recupera tutte le email (senza paginazione) per l'export
$filter = new SearchFilter();
['sql' => $sql, 'params' => $params] = $filter->buildQuery($filters, 1, 999999);

$pdo  = getDB();
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$emails = $stmt->fetchAll();

// Export CSV
$filename = 'mailbox_export_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache');

// BOM UTF-8 per Excel
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// Intestazioni colonne
fputcsv($out, ['ID', 'Da', 'Nome mittente', 'A', 'CC', 'Oggetto', 'Data', 'Cartella', 'Allegati', 'PST File'], ';');

foreach ($emails as $email) {
    fputcsv($out, [
        $email['id'],
        $email['from_address'],
        $email['from_name'] ?? '',
        $email['to_address'],
        $email['cc_address'] ?? '',
        $email['subject'],
        $email['email_date'] ? date('d/m/Y H:i', strtotime($email['email_date'])) : '',
        $email['folder_name'] ?? '',
        $email['attachment_count'] ?? 0,
        $email['pst_filename'] ?? '',
    ], ';');
}

fclose($out);
exit;
