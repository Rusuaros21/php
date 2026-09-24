<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal HTTP Basic Auth gate. Disabled by default; enable by setting the
 * SCANNER_AUTH_ENABLED / SCANNER_AUTH_USER / SCANNER_AUTH_PASS env vars.
 */
final class Auth
{
    public static function requireBasicAuth(array $config): void
    {
        $auth = $config['auth'] ?? [];
        if (empty($auth['enabled'])) {
            return;
        }

        $expectedUser = (string) ($auth['username'] ?? '');
        $expectedPass = (string) ($auth['password'] ?? '');

        if ($expectedPass === '') {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Autenticação habilitada, mas SCANNER_AUTH_PASS não foi definida no ambiente.';
            exit;
        }

        $user = $_SERVER['PHP_AUTH_USER'] ?? '';
        $pass = $_SERVER['PHP_AUTH_PW'] ?? '';

        $validUser = hash_equals($expectedUser, $user);
        $validPass = hash_equals($expectedPass, $pass);

        if (!$validUser || !$validPass) {
            header('WWW-Authenticate: Basic realm="Monitor de Rede"');
            http_response_code(401);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Autenticação necessária.';
            exit;
        }
    }
}
