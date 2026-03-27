<?php

declare(strict_types=1);

namespace Mailbox;

class PstImporter
{
    private string $readpstPath;
    private string $storagePath;

    public function __construct()
    {
        $this->readpstPath = $_ENV['READPST_PATH'] ?? '/usr/bin/readpst';
        $this->storagePath = STORAGE_PATH;
    }

    /**
     * Estrae le email dal file PST in singoli file .eml
     * Restituisce il path della directory con i file .eml
     */
    public function extract(int $importId, string $pstFilePath): string
    {
        $outputDir = $this->storagePath . '/eml/' . $importId;

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $safePst    = escapeshellarg($pstFilePath);
        $safeOutput = escapeshellarg($outputDir);

        // -e = un file .eml per email | -r = ricorsione cartelle | -j 2 = 2 thread
        $cmd = sprintf('%s -e -r -o %s %s 2>&1', $this->readpstPath, $safeOutput, $safePst);

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException(
                'Errore readpst (codice ' . $returnCode . '): ' . implode("\n", $output)
            );
        }

        return $outputDir;
    }

    /**
     * Importa tutti i file .eml estratti nel database
     */
    public function importAll(int $importId, string $emlDir): array
    {
        $parser = new EmailParser();
        $repo   = new EmailRepository(getDB());
        $stats  = ['imported' => 0, 'skipped' => 0, 'errors' => 0];

        $pdo = getDB();
        $updateStmt = $pdo->prepare(
            'UPDATE pst_imports SET imported_emails=?, skipped_emails=?, error_emails=? WHERE id=?'
        );

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($emlDir, \FilesystemIterator::SKIP_DOTS)
        );

        $count = 0;
        foreach ($iterator as $file) {
            if (strtolower($file->getExtension()) !== 'eml') {
                continue;
            }

            try {
                $emailData = $parser->parse($file->getPathname());
                $emailData['pst_import_id'] = $importId;
                $emailData['folder_name']   = basename(dirname($file->getPathname()));
                $emailData['pst_filename']  = $file->getFilename();

                if ($repo->insert($emailData)) {
                    $stats['imported']++;
                } else {
                    $stats['skipped']++; // message_id duplicato
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                error_log('[Mailbox] Errore parsing ' . $file->getFilename() . ': ' . $e->getMessage());
            }

            // Aggiorna contatori ogni 50 email
            if (++$count % 50 === 0) {
                $updateStmt->execute([
                    $stats['imported'],
                    $stats['skipped'],
                    $stats['errors'],
                    $importId,
                ]);
            }
        }

        // Aggiornamento finale
        $updateStmt->execute([
            $stats['imported'],
            $stats['skipped'],
            $stats['errors'],
            $importId,
        ]);

        return $stats;
    }

    /**
     * Salva il file PST caricato in storage/pst/
     */
    public function storePstFile(array $uploadedFile, int $importId): string
    {
        $ext      = pathinfo($uploadedFile['name'], PATHINFO_EXTENSION);
        $filename = $importId . '_' . time() . '.' . strtolower($ext);
        $destPath = $this->storagePath . '/pst/' . $filename;

        if (!move_uploaded_file($uploadedFile['tmp_name'], $destPath)) {
            throw new \RuntimeException('Impossibile salvare il file PST.');
        }

        return $destPath;
    }
}
