<?php

declare(strict_types=1);

namespace Mailbox;

use PDO;

class EmailRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Inserisce una email nel database.
     * Restituisce false se duplicato (message_id già presente).
     */
    public function insert(array $data): bool
    {
        // Salta duplicati basandosi sul message_id
        if (!empty($data['message_id'])) {
            $check = $this->pdo->prepare('SELECT id FROM emails WHERE message_id = ? LIMIT 1');
            $check->execute([$data['message_id']]);
            if ($check->fetch()) {
                return false;
            }
        }

        $sql = 'INSERT INTO emails
                (message_id, from_address, from_name, to_address, cc_address, bcc_address,
                 reply_to, subject, body_text, body_html, email_date, has_attachments,
                 attachment_count, folder_name, pst_import_id, pst_filename, size_bytes)
                VALUES
                (:message_id, :from_address, :from_name, :to_address, :cc_address, :bcc_address,
                 :reply_to, :subject, :body_text, :body_html, :email_date, :has_attachments,
                 :attachment_count, :folder_name, :pst_import_id, :pst_filename, :size_bytes)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':message_id'       => $data['message_id']       ?? null,
            ':from_address'     => $data['from_address']     ?? '',
            ':from_name'        => $data['from_name']        ?? null,
            ':to_address'       => $data['to_address']       ?? '',
            ':cc_address'       => $data['cc_address']       ?? null,
            ':bcc_address'      => $data['bcc_address']      ?? null,
            ':reply_to'         => $data['reply_to']         ?? null,
            ':subject'          => $data['subject']          ?? '(senza oggetto)',
            ':body_text'        => $data['body_text']        ?? null,
            ':body_html'        => $data['body_html']        ?? null,
            ':email_date'       => $data['email_date']       ?? date('Y-m-d H:i:s'),
            ':has_attachments'  => $data['has_attachments']  ?? 0,
            ':attachment_count' => $data['attachment_count'] ?? 0,
            ':folder_name'      => $data['folder_name']      ?? null,
            ':pst_import_id'    => $data['pst_import_id']    ?? null,
            ':pst_filename'     => $data['pst_filename']     ?? null,
            ':size_bytes'       => $data['size_bytes']       ?? 0,
        ]);

        $emailId = (int)$this->pdo->lastInsertId();

        // Inserisce allegati
        if (!empty($data['attachments'])) {
            $this->insertAttachments($emailId, $data['attachments']);
        }

        return true;
    }

    private function insertAttachments(int $emailId, array $attachments): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO attachments (email_id, filename, content_type, file_path, file_size, checksum_md5)
             VALUES (:email_id, :filename, :content_type, :file_path, :file_size, :checksum_md5)'
        );

        foreach ($attachments as $att) {
            $stmt->execute([
                ':email_id'     => $emailId,
                ':filename'     => $att['filename']     ?? 'allegato',
                ':content_type' => $att['content_type'] ?? 'application/octet-stream',
                ':file_path'    => $att['file_path']    ?? '',
                ':file_size'    => $att['file_size']    ?? 0,
                ':checksum_md5' => $att['checksum_md5'] ?? null,
            ]);
        }
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM emails WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function getAttachments(int $emailId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM attachments WHERE email_id = ?');
        $stmt->execute([$emailId]);
        return $stmt->fetchAll();
    }

    public function countAll(array $filters = []): int
    {
        $filter = new SearchFilter();
        ['sql' => $sql, 'params' => $params] = $filter->buildCountQuery($filters);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function search(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $filter = new SearchFilter();
        ['sql' => $sql, 'params' => $params] = $filter->buildQuery($filters, $page, $perPage);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getStats(): array
    {
        $pdo = $this->pdo;
        return [
            'total'     => (int)$pdo->query('SELECT COUNT(*) FROM emails')->fetchColumn(),
            'imports'   => (int)$pdo->query('SELECT COUNT(*) FROM pst_imports WHERE status="completed"')->fetchColumn(),
            'folders'   => (int)$pdo->query('SELECT COUNT(DISTINCT folder_name) FROM emails')->fetchColumn(),
            'with_att'  => (int)$pdo->query('SELECT COUNT(*) FROM emails WHERE has_attachments=1')->fetchColumn(),
        ];
    }

    public function getFolders(): array
    {
        return $this->pdo->query(
            'SELECT folder_name, COUNT(*) as cnt FROM emails
             WHERE folder_name IS NOT NULL
             GROUP BY folder_name ORDER BY cnt DESC'
        )->fetchAll();
    }

    public function getImports(): array
    {
        return $this->pdo->query(
            'SELECT * FROM pst_imports ORDER BY created_at DESC'
        )->fetchAll();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM emails WHERE id = ?');
        return $stmt->execute([$id]) && $stmt->rowCount() > 0;
    }
}
