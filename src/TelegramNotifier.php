<?php

declare(strict_types=1);

namespace JettyCli;

/**
 * Optional operator alerts via Telegram Bot API (HTTPS + JSON).
 *
 * Env:
 * - JETTY_TELEGRAM_BOT_TOKEN — bot token from @BotFather
 * - JETTY_TELEGRAM_CHAT_ID — chat or channel id (string ok for supergroups)
 * - JETTY_TELEGRAM_ENABLED — set to "0" to disable even when token/chat are set
 */
final class TelegramNotifier
{
    public static function isEnabled(): bool
    {
        $enabled = getenv('JETTY_TELEGRAM_ENABLED');
        if (is_string($enabled) && strtolower(trim($enabled)) === '0') {
            return false;
        }

        return self::token() !== '' && self::chatId() !== '';
    }

    public static function shareStarted(array $context): void
    {
        $url = (string) ($context['public_url'] ?? '');
        $id = (string) ($context['tunnel_id'] ?? '');
        $local = (string) ($context['local_target'] ?? '');
        $printOnly = ! empty($context['print_url_only']);

        $title = $printOnly ? '<b>jetty</b> tunnel created (print-url-only)' : '<b>jetty share</b> started';
        $lines = [
            $title,
            'Tunnel id: <code>'.self::e($id).'</code>',
            'URL: '.self::e($url),
        ];
        if ($local !== '') {
            $lines[] = 'Local: <code>'.self::e($local).'</code>';
        }
        if (! empty($context['server'])) {
            $lines[] = 'Server label: <code>'.self::e((string) $context['server']).'</code>';
        }

        self::sendHtml(implode("\n", $lines));
    }

    public static function shareFailed(string $phase, \Throwable|string $error, array $context = []): void
    {
        $msg = $error instanceof \Throwable ? $error->getMessage() : $error;
        $id = (string) ($context['tunnel_id'] ?? '');
        $lines = [
            '<b>jetty share</b> failed',
            'Phase: <code>'.self::e($phase).'</code>',
            'Error: '.self::e($msg),
        ];
        if ($id !== '') {
            $lines[] = 'Tunnel id: <code>'.self::e($id).'</code>';
        }
        if (($context['public_url'] ?? '') !== '') {
            $lines[] = 'URL: '.self::e((string) $context['public_url']);
        }

        self::sendHtml(implode("\n", $lines));
    }

    public static function edgeAgentFailed(string $tunnelId, string $publicUrl, string $detail): void
    {
        $lines = [
            '<b>jetty share</b> edge agent failed early',
            'Tunnel id: <code>'.self::e((string) $tunnelId).'</code>',
            'URL: '.self::e($publicUrl),
            'Detail: '.self::e($detail),
        ];

        self::sendHtml(implode("\n", $lines));
    }

    public static function shareEnded(string $tunnelId, string $publicUrl, string $reason): void
    {
        $lines = [
            '<b>jetty share</b> ended',
            'Tunnel id: <code>'.self::e((string) $tunnelId).'</code>',
            'URL: '.self::e($publicUrl),
            'Reason: '.self::e($reason),
        ];

        self::sendHtml(implode("\n", $lines));
    }

    public static function tunnelDeleteFailed(string $tunnelId, string $detail): void
    {
        $lines = [
            '<b>jetty share</b> tunnel delete failed',
            'Tunnel id: <code>'.self::e((string) $tunnelId).'</code>',
            'Detail: '.self::e($detail),
        ];

        self::sendHtml(implode("\n", $lines));
    }

    private static function token(): string
    {
        $t = getenv('JETTY_TELEGRAM_BOT_TOKEN');

        return is_string($t) ? trim($t) : '';
    }

    private static function chatId(): string
    {
        $c = getenv('JETTY_TELEGRAM_CHAT_ID');
        if (! is_string($c) || trim($c) === '') {
            return '';
        }

        return trim($c);
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function sendHtml(string $text): void
    {
        if (! self::isEnabled()) {
            return;
        }

        if (strlen($text) > 4000) {
            $text = substr($text, 0, 3997).'…';
        }

        $payload = [
            'chat_id' => self::chatId(),
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        $url = 'https://api.telegram.org/bot'.rawurlencode(self::token()).'/sendMessage';

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return;
        }

        try {
            curl_setopt_array($ch, [
                \CURLOPT_POST => true,
                \CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
                \CURLOPT_POSTFIELDS => $json,
                \CURLOPT_RETURNTRANSFER => true,
                \CURLOPT_TIMEOUT => 8,
                \CURLOPT_CONNECTTIMEOUT => 4,
            ]);
            curl_exec($ch);
        } catch (\Throwable) {
            // Never break the CLI on notify failures
        }
    }
}
