<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Notification;

use RuntimeException;

final class SmtpJobMail
{
    public function __construct(
        private readonly string $username,
        private readonly string $password,
        private readonly string $recipient,
    ) {
        foreach ([$username, $recipient] as $address) {
            if (!filter_var($address, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $address)) {
                throw new RuntimeException('Invalid SMTP address');
            }
        }
        if ($password === '') {
            throw new RuntimeException('Missing SMTP password');
        }
    }

    public function send(array $job): void
    {
        foreach (['id', 'source', 'project', 'action', 'status'] as $key) {
            if (!is_string($job[$key] ?? null) || !preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $job[$key])) {
                throw new RuntimeException('Invalid job notification metadata');
            }
        }
        $domain = substr(strrchr($this->username, '@'), 1);
        $messageId = "devmcp.{$job['source']}.{$job['id']}@$domain";
        $subject = "[devMCP] {$job['project']} / {$job['action']} : {$job['status']}";
        $body = "Projet : {$job['project']}\r\nAction : {$job['action']}\r\n"
            . "Statut : {$job['status']}\r\nTache : {$job['id']}\r\n";
        foreach ($job['links'] ?? [] as $label => $url) {
            $body .= "$label : $url\r\n";
        }
        $message = "From: <{$this->username}>\r\nTo: <{$this->recipient}>\r\n"
            . 'Date: ' . gmdate(DATE_RFC2822) . "\r\n"
            . "Message-ID: <$messageId>\r\nSubject: $subject\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$body";

        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $message);
        rewind($stream);
        $curl = curl_init('smtps://ssl0.ovh.net:465');
        try {
            curl_setopt_array($curl, [
                CURLOPT_USERNAME => $this->username,
                CURLOPT_PASSWORD => $this->password,
                CURLOPT_MAIL_FROM => '<' . $this->username . '>',
                CURLOPT_MAIL_RCPT => ['<' . $this->recipient . '>'],
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $stream,
                CURLOPT_INFILESIZE => strlen($message),
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_RETURNTRANSFER => true,
            ]);
            if (curl_exec($curl) === false) {
                throw new RuntimeException('SMTP delivery failed with cURL code ' . curl_errno($curl));
            }
        } finally {
            fclose($stream);
        }
    }
}
