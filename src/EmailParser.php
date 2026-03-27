<?php

declare(strict_types=1);

namespace Mailbox;

use PhpMimeMailParser\Parser;

class EmailParser
{
    /**
     * Analizza un file .eml e restituisce i dati strutturati dell'email
     */
    public function parse(string $emlFilePath): array
    {
        $parser = new Parser();
        $parser->setPath($emlFilePath);

        // Data email
        $rawDate   = $parser->getHeader('date');
        $emailDate = null;
        if ($rawDate) {
            $ts = strtotime($rawDate);
            $emailDate = $ts ? date('Y-m-d H:i:s', $ts) : null;
        }

        // Pulizia indirizzi email
        $from    = $this->cleanHeader($parser->getHeader('from'));
        $to      = $this->cleanHeader($parser->getHeader('to'));
        $cc      = $this->cleanHeader($parser->getHeader('cc'));
        $bcc     = $this->cleanHeader($parser->getHeader('bcc'));
        $replyTo = $this->cleanHeader($parser->getHeader('reply-to'));
        $subject = $this->cleanHeader($parser->getHeader('subject'));
        $msgId   = $this->cleanHeader($parser->getHeader('message-id'));

        // Corpo email
        $bodyText = $parser->getMessageBody('text') ?: null;
        $bodyHtml = $parser->getMessageBody('html') ?: null;

        // Allegati
        $attachments    = $this->extractAttachments($parser);
        $hasAttachments = count($attachments) > 0;

        // Dimensione file .eml
        $sizeBytes = filesize($emlFilePath) ?: 0;

        return [
            'message_id'       => $msgId ?: null,
            'from_address'     => $from   ?: 'sconosciuto@unknown.com',
            'from_name'        => $this->extractName($from),
            'to_address'       => $to     ?: '',
            'cc_address'       => $cc     ?: null,
            'bcc_address'      => $bcc    ?: null,
            'reply_to'         => $replyTo ?: null,
            'subject'          => $subject ?: '(senza oggetto)',
            'body_text'        => $bodyText,
            'body_html'        => $bodyHtml,
            'email_date'       => $emailDate ?? date('Y-m-d H:i:s'),
            'has_attachments'  => (int)$hasAttachments,
            'attachment_count' => count($attachments),
            'size_bytes'       => $sizeBytes,
            'attachments'      => $attachments,
        ];
    }

    /**
     * Estrae e salva gli allegati in storage/attachments/
     */
    private function extractAttachments(Parser $parser): array
    {
        $attachments  = $parser->getAttachments();
        $attachDir    = STORAGE_PATH . '/attachments/';
        $result       = [];

        if (!is_dir($attachDir)) {
            mkdir($attachDir, 0755, true);
        }

        foreach ($attachments as $att) {
            $originalName = $att->getFilename() ?: 'allegato_' . uniqid();
            $safeName     = preg_replace('/[^a-zA-Z0-9._\-]/', '_', basename($originalName));
            $uniqueName   = uniqid('att_') . '_' . $safeName;
            $destPath     = $attachDir . $uniqueName;

            $content = $att->getContent();
            file_put_contents($destPath, $content);

            $result[] = [
                'filename'     => $originalName,
                'content_type' => $att->getContentType() ?: 'application/octet-stream',
                'file_path'    => 'attachments/' . $uniqueName,
                'file_size'    => strlen($content),
                'checksum_md5' => md5($content),
            ];
        }

        return $result;
    }

    /**
     * Pulisce un header email da encoding MIME
     */
    private function cleanHeader(mixed $value): string
    {
        if (is_array($value)) {
            $value = implode(', ', $value);
        }
        return mb_convert_encoding((string)$value, 'UTF-8', 'auto');
    }

    /**
     * Estrae il nome dal campo "Nome Cognome <email@example.com>"
     */
    private function extractName(string $from): ?string
    {
        if (preg_match('/^(.+?)\s*<.+>$/', $from, $m)) {
            return trim($m[1], '"\'');
        }
        return null;
    }
}
