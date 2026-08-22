<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Smtp.php';
require_once __DIR__ . '/RequestContext.php';
require_once __DIR__ . '/AppLogger.php';

final class TechnicalAlertMailer
{
    private const SEVERITIES = ['TEST', 'WARNING', 'CRITICAL', 'RECOVERED'];

    public static function configured(): bool
    {
        $recipient = strtolower(ALERT_TO);
        return ALERT_SMTP_HOST !== ''
            && filter_var(ALERT_FROM, FILTER_VALIDATE_EMAIL)
            && filter_var($recipient, FILTER_VALIDATE_EMAIL)
            && in_array($recipient, ALERT_ALLOWED_RECIPIENTS, true)
            && in_array(ALERT_SMTP_SECURITY, ['tls', 'ssl'], true);
    }

    public static function send(string $severity, string $component, string $message): bool
    {
        $severity = strtoupper($severity);
        if (!in_array($severity, self::SEVERITIES, true) || !self::configured()) return false;

        $component = self::plain($component, 80);
        $message = self::plain($message, 1000);
        $correlation = RequestContext::correlationId();
        $subject = $severity === 'TEST'
            ? '[GIMNERA STAGING TEST] Canal técnico de alertas'
            : '[GIMNERA STAGING] ' . $severity . ' — ' . $component;
        $rows = [
            'Entorno' => APP_ENV,
            'Severidad' => $severity,
            'UTC' => gmdate('Y-m-d\TH:i:s\Z'),
            'Host' => gethostname() ?: 'unknown',
            'Componente' => $component,
            'Mensaje' => $message,
            'Correlation ID' => $correlation,
        ];
        $html = '<h2>Gimnera — alerta técnica</h2><table>';
        foreach ($rows as $name => $value) {
            $html .= '<tr><th align="left">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</th><td>'
                . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }
        $html .= '</table><p>Evento sintético/técnico; no contiene datos de socios.</p>';

        $config = [
            'host' => ALERT_SMTP_HOST,
            'port' => ALERT_SMTP_PORT,
            'security' => ALERT_SMTP_SECURITY,
            'user' => ALERT_SMTP_USER,
            'password' => ALERT_SMTP_PASSWORD,
            'timeout' => ALERT_SMTP_TIMEOUT,
        ];
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if (Smtp::enviarConConfiguracion(ALERT_TO, $subject, $html, ALERT_FROM, ALERT_FROM_NAME, $config)) {
                AppLogger::info('technical_alert_delivered', [
                    'severity' => $severity,
                    'component' => $component,
                    'attempt' => $attempt,
                ]);
                return true;
            }
            if ($attempt < 3) usleep($attempt * 250000);
        }
        AppLogger::error('technical_alert_delivery_failed', [
            'severity' => $severity,
            'component' => $component,
            'attempts' => 3,
        ]);
        return false;
    }

    private static function plain(string $value, int $max): string
    {
        $value = preg_replace('/[\r\n\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        return mb_substr(trim($value), 0, $max);
    }
}
