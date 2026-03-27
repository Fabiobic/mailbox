<?php

declare(strict_types=1);

namespace Mailbox;

use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message;

class EmailParser
{
    private MailMimeParser $parser;

    public function __construct()
    {
        $this->parser = new MailMimeParser();
    }

    /**
     * Analizza un file .eml e restituisce i dati strutturati dell'email
     */
    public function parse(string $emlFilePath): array
    {
        $handle  = fopen($emlFilePath, 'r');
        $message = $this->parser->parse($handle, false);
        fclose($handle);

        // Data email
        $emailDate = null;
        $rawDate   = $message->getHeaderValue('date');
        if ($rawDate) {
            $ts        = strtotime($rawDate);
            $emailDate = $ts ? date('Y-m-d H:i:s', $ts) : null;
        }

        // Header principali
        $from    = $this->cleanHeader($message->getHeaderValue('from'));
        $to      = $this->cleanHeader($message->getHeaderValue('to'));
        $cc      = $this->cleanHeader($message->getHeaderValue('cc'));
        $bcc     = $this->cleanHeader($message->getHeaderValue('bcc'));
        $replyTo = $this->cleanHeader($message->getHeaderValue('reply-to'));
        $subject = $this->cleanHeader($message->getHeaderValue('subject'));
        $msgId   = $this->cleanHeader($message->getHeaderValue('message-id'));

        // Corpo email
        $bodyText = $message->getTextContent() ?: null;
        $bodyHtml = $message->getHtmlContent() ?: null;

        // Allegati
        $attachments    = $this->extractAttachments($message);
        $hasAttachments = count($attachments) > 0;

        // Dimensione file .eml
        $sizeBytes = filesize($emlFilePath) ?: 0;

        return [
            'message_id'       => $msgId   ?: null,
            'from_address'     => $from    ?: 'sconosciuto@unknown.com',
            'from_name'        => $this->extractName($from),
            'to_address'       => $to      ?: '',
            'cc_address'       => $cc      ?: null,
            'bcc_address'      => $bcc     ?: null,
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
    private function extractAttachments(Message $message): array
    {
        $attachDir = STORAGE_PATH . '/attachments/';
        $result    = [];

        if (!is_dir($attachDir)) {
            mkdir($attachDir, 0755, true);
        }

        $attachmentCount = $message->getAttachmentCount();
        for ($i = 0; $i < $attachmentCount; $i++) {
            $att          = $message->getAttachmentPart($i);
            $originalName = $att->getFilename() ?: 'allegato_' . uniqid();
            $safeName     = preg_replace('/[^a-zA-Z0-9._\-]/', '_', basename($originalName));
            $uniqueName   = uniqid('att_') . '_' . $safeName;
            $destPath     = $attachDir . $uniqueName;

            $content = $att->getBinaryContentResourceHandle();
            $rawContent = stream_get_contents($content);
            file_put_contents($destPath, $rawContent);

            $result[] = [
                'filename'     => $originalName,
                'content_type' => $att->getContentType() ?: 'application/octet-stream',
                'file_path'    => 'attachments/' . $uniqueName,
                'file_size'    => strlen($rawContent),
                'checksum_md5' => md5($rawContent),
            ];
        }

        return $result;
    }

    /**
     * Pulisce un header email da encoding MIME
     */
    private function cleanHeader(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
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
