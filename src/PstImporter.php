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
     * Estrae le email dal file PST in file mbox (uno per cartella).
     * readpst su filesystem Windows via WSL produce file "mbox" per cartella.
     * Restituisce il path della directory con i file mbox.
     */
    public function extract(int $importId, string $pstFilePath): string
    {
        $outputDir = $this->storagePath . '/eml/' . $importId;

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // Converte il path Windows in path WSL per readpst
        $wslPst    = $this->toWslPath($pstFilePath);
        $wslOutput = $this->toWslPath($outputDir);

        $safePst    = escapeshellarg($wslPst);
        $safeOutput = escapeshellarg($wslOutput);

        // -r = ricorsione cartelle
        // Senza -e: crea un file "mbox" per cartella (funziona su NTFS via WSL)
        $cmd = sprintf('%s -r -o %s %s 2>&1', $this->readpstPath, $safeOutput, $safePst);

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException(
                'Errore readpst (codice ' . $returnCode . '): ' . implode("\n", $output)
            );
        }

        return $outputDir;
    }

    /**
     * Importa tutti i file mbox estratti nel database.
     * Ogni file "mbox" contiene N email; ogni email viene parsata e inserita.
     */
    public function importAll(int $importId, string $emlDir): array
    {
        $parser = new EmailParser();
        $repo   = new EmailRepository(getDB());
        $stats  = ['imported' => 0, 'skipped' => 0, 'errors' => 0];

        $pdo        = getDB();
        $updateStmt = $pdo->prepare(
            'UPDATE pst_imports SET imported_emails=?, skipped_emails=?, error_emails=? WHERE id=?'
        );

        // Itera su tutti i file "mbox" ricorsivamente
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($emlDir, \FilesystemIterator::SKIP_DOTS)
        );

        $count = 0;
        foreach ($iterator as $file) {
            // readpst crea file chiamati "mbox" (senza estensione) per ogni cartella
            if ($file->getFilename() !== 'mbox') {
                continue;
            }

            // Ricava il nome della cartella PST dal path relativo
            $folderName = $this->extractFolderName($file->getPathname(), $emlDir);

            // Splitta il file mbox in singoli messaggi e li parsa
            $messages = $this->splitMbox($file->getPathname());

            foreach ($messages as $rawMessage) {
                try {
                    $emailData = $parser->parseFromString($rawMessage);
                    $emailData['pst_import_id'] = $importId;
                    $emailData['folder_name']   = $folderName;
                    $emailData['pst_filename']  = basename($file->getPathname());

                    if ($repo->insert($emailData)) {
                        $stats['imported']++;
                    } else {
                        $stats['skipped']++;
                    }
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    error_log('[Mailbox] Errore parsing email in ' . $folderName . ': ' . $e->getMessage());
                }

                // Aggiorna contatori ogni 100 email
                if (++$count % 100 === 0) {
                    $updateStmt->execute([
                        $stats['imported'],
                        $stats['skipped'],
                        $stats['errors'],
                        $importId,
                    ]);
                }
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

    /**
     * Splitta un file mbox in un array di stringhe (una per email).
     * Il formato mbox usa "From " (con spazio) come separatore di inizio messaggio.
     */
    private function splitMbox(string $mboxPath): array
    {
        $content  = file_get_contents($mboxPath);
        if ($content === false || $content === '') {
            return [];
        }

        // Separa i messaggi: ogni email inizia con "From " a inizio riga
        $messages = preg_split('/^From\s+[^\r\n]+[\r\n]+/m', $content, -1, PREG_SPLIT_NO_EMPTY);

        return array_filter(array_map('trim', $messages ?: []));
    }

    /**
     * Estrae il nome della cartella PST dal path del file mbox.
     * Es: /storage/eml/1/Archivio/Atoma/Inglese/mbox -> "Archivio/Atoma/Inglese"
     */
    private function extractFolderName(string $mboxPath, string $baseDir): string
    {
        $relative = str_replace('\\', '/', substr($mboxPath, strlen($baseDir)));
        $relative = ltrim($relative, '/');
        // Rimuove "/mbox" finale
        $relative = preg_replace('/\/mbox$/', '', $relative);
        return $relative ?: 'root';
    }

    /**
     * Converte un path Windows in path WSL (/mnt/d/...)
     */
    private function toWslPath(string $windowsPath): string
    {
        // D:\www\... -> /mnt/d/www/...
        $path = str_replace('\\', '/', $windowsPath);
        if (preg_match('/^([A-Za-z]):\/(.*)$/', $path, $m)) {
            return '/mnt/' . strtolower($m[1]) . '/' . $m[2];
        }
        // Già in formato Unix o path relativo
        return $path;
    }
}
