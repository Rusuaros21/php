<?php

declare(strict_types=1);

namespace App\Alerting;

/**
 * Notifies about newly-seen devices via a generic webhook (works with
 * Slack/Discord/Telegram-via-bridge, n8n, Make, etc.) and/or plain email.
 * Both channels are optional and configured via environment variables.
 */
final class AlertDispatcher
{
    public function __construct(private readonly array $config)
    {
    }

    public function notifyNewDevices(array $devices): void
    {
        if (!$devices) {
            return;
        }

        $webhookUrl = $this->config['alerts']['webhook_url'] ?? null;
        if ($webhookUrl) {
            $this->sendWebhook($webhookUrl, $devices);
        }

        $emailTo = $this->config['alerts']['email_to'] ?? null;
        if ($emailTo) {
            $this->sendEmail($emailTo, $devices);
        }
    }

    private function sendWebhook(string $url, array $devices): void
    {
        $payload = json_encode([
            'event' => 'new_device_detected',
            'devices' => array_values($devices),
            'detected_at' => date(DATE_ATOM),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 3,
                'ignore_errors' => true,
            ],
        ]);

        @file_get_contents($url, false, $context);
    }

    private function sendEmail(string $to, array $devices): void
    {
        $count = count($devices);
        $subject = $count === 1
            ? 'Novo dispositivo detectado na rede'
            : sprintf('%d novos dispositivos detectados na rede', $count);

        $lines = array_map(
            static fn (array $d) => sprintf(
                '- %s (IP: %s, MAC: %s)',
                $d['hostname'] ?: '(desconhecido)',
                $d['ip'],
                $d['mac'] ?: '—'
            ),
            array_values($devices)
        );

        $body = "Os seguintes dispositivos foram vistos pela primeira vez na rede:\n\n"
            . implode("\n", $lines) . "\n";

        @mail($to, $subject, $body);
    }
}
