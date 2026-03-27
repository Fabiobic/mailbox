<?php

declare(strict_types=1);

namespace Mailbox;

class SearchFilter
{
    /**
     * Costruisce la query di ricerca con tutti i filtri
     */
    public function buildQuery(array $filters, int $page = 1, int $perPage = 25): array
    {
        ['where' => $where, 'params' => $params] = $this->buildConditions($filters);

        $sql = 'SELECT id, from_address, from_name, to_address, subject,
                       email_date, has_attachments, attachment_count, folder_name,
                       pst_import_id, size_bytes, is_flagged, tags';

        // Se c'è una ricerca full-text, includi il punteggio di rilevanza
        if (!empty($filters['q'])) {
            $sql .= ', MATCH(from_address, to_address, subject, body_text)
                       AGAINST (:q_score IN NATURAL LANGUAGE MODE) AS relevance';
            $params[':q_score'] = $filters['q'];
        }

        $sql .= ' FROM emails';

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        // Ordinamento
        $order = $this->buildOrderBy($filters);
        $sql  .= ' ORDER BY ' . $order;

        // Paginazione
        $offset = ($page - 1) * $perPage;
        $sql   .= ' LIMIT :limit OFFSET :offset';
        $params[':limit']  = $perPage;
        $params[':offset'] = $offset;

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * Costruisce la query per il conteggio totale (senza LIMIT)
     */
    public function buildCountQuery(array $filters): array
    {
        ['where' => $where, 'params' => $params] = $this->buildConditions($filters);

        $sql = 'SELECT COUNT(*) FROM emails';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * Costruisce le condizioni WHERE dai filtri
     */
    private function buildConditions(array $filters): array
    {
        $where  = [];
        $params = [];

        // Mittente (Da)
        if (!empty($filters['from'])) {
            $where[]        = '(from_address LIKE :from OR from_name LIKE :from_name)';
            $params[':from']      = '%' . $filters['from'] . '%';
            $params[':from_name'] = '%' . $filters['from'] . '%';
        }

        // Destinatario (A)
        if (!empty($filters['to'])) {
            $where[]      = 'to_address LIKE :to';
            $params[':to'] = '%' . $filters['to'] . '%';
        }

        // Oggetto
        if (!empty($filters['subject'])) {
            $where[]           = 'subject LIKE :subject';
            $params[':subject'] = '%' . $filters['subject'] . '%';
        }

        // Data da
        if (!empty($filters['date_from'])) {
            $where[]              = 'email_date >= :date_from';
            $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
        }

        // Data a
        if (!empty($filters['date_to'])) {
            $where[]            = 'email_date <= :date_to';
            $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        // Ricerca full-text (corpo + oggetto + mittente)
        if (!empty($filters['q'])) {
            $where[]     = 'MATCH(from_address, to_address, subject, body_text) AGAINST (:q IN BOOLEAN MODE)';
            $params[':q'] = $filters['q'];
        }

        // Solo email con allegati
        if (!empty($filters['has_attachments'])) {
            $where[] = 'has_attachments = 1';
        }

        // Cartella PST
        if (!empty($filters['folder'])) {
            $where[]          = 'folder_name = :folder';
            $params[':folder'] = $filters['folder'];
        }

        // Archivio PST specifico
        if (!empty($filters['import_id'])) {
            $where[]             = 'pst_import_id = :import_id';
            $params[':import_id'] = (int)$filters['import_id'];
        }

        // Email flaggate
        if (!empty($filters['flagged'])) {
            $where[] = 'is_flagged = 1';
        }

        return ['where' => $where, 'params' => $params];
    }

    private function buildOrderBy(array $filters): string
    {
        $allowed = [
            'date_desc'    => 'email_date DESC',
            'date_asc'     => 'email_date ASC',
            'subject_asc'  => 'subject ASC',
            'from_asc'     => 'from_address ASC',
            'relevance'    => 'relevance DESC, email_date DESC',
        ];

        $sort = $filters['sort'] ?? 'date_desc';

        // Se c'è full-text e non è specificato un sort, ordina per rilevanza
        if (!empty($filters['q']) && $sort === 'date_desc') {
            $sort = 'relevance';
        }

        return $allowed[$sort] ?? 'email_date DESC';
    }
}
