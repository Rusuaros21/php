<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Flags commonly-risky open ports/services with a short explanation and a
 * severity level. This is a heuristic based on well-known port/service
 * conventions — NOT a CVE or exploit scanner. It does not probe service
 * versions or run exploit checks; it only tells you "this kind of exposed
 * service is usually a bad idea on an untrusted network".
 */
final class PortRiskAdvisor
{
    public const SEVERITY_ORDER = ['high' => 3, 'medium' => 2, 'low' => 1];

    /**
     * @param array<int, array{port:int, service?:string}> $openPorts
     * @return array<int, array{port:int, service:string, severity:string, title:string, description:string}>
     */
    public static function assess(array $openPorts): array
    {
        $rules = self::rules();
        $findings = [];

        foreach ($openPorts as $entry) {
            $port = (int) ($entry['port'] ?? 0);
            $rule = $rules[$port] ?? null;
            if ($rule === null) {
                continue;
            }

            $findings[] = [
                'port' => $port,
                'service' => (string) ($entry['service'] ?? ''),
                'severity' => $rule['severity'],
                'title' => $rule['title'],
                'description' => $rule['description'],
            ];
        }

        usort($findings, static fn (array $a, array $b) => self::SEVERITY_ORDER[$b['severity']] <=> self::SEVERITY_ORDER[$a['severity']]);

        return $findings;
    }

    /**
     * Highest severity across a set of findings, or null if none.
     */
    public static function highestSeverity(array $findings): ?string
    {
        $best = null;
        foreach ($findings as $finding) {
            if ($best === null || self::SEVERITY_ORDER[$finding['severity']] > self::SEVERITY_ORDER[$best]) {
                $best = $finding['severity'];
            }
        }

        return $best;
    }

    private static function rules(): array
    {
        return [
            21 => [
                'severity' => 'high',
                'title' => 'FTP em texto puro',
                'description' => 'Credenciais e dados trafegam sem criptografia. Prefira SFTP/FTPS ou desative se não for usado.',
            ],
            23 => [
                'severity' => 'high',
                'title' => 'Telnet em texto puro',
                'description' => 'Protocolo obsoleto, sem criptografia. Substitua por SSH.',
            ],
            25 => [
                'severity' => 'low',
                'title' => 'SMTP exposto',
                'description' => 'Confirme que o serviço não permite relay aberto (spam via terceiros).',
            ],
            53 => [
                'severity' => 'low',
                'title' => 'DNS exposto',
                'description' => 'Se for um resolver recursivo aberto para a internet, pode ser abusado em ataques de amplificação DDoS.',
            ],
            110 => [
                'severity' => 'medium',
                'title' => 'POP3 sem criptografia',
                'description' => 'Credenciais de e-mail trafegam em texto puro. Prefira POP3S (porta 995).',
            ],
            111 => [
                'severity' => 'medium',
                'title' => 'RPCbind exposto',
                'description' => 'Historicamente alvo de diversas vulnerabilidades de rede; deveria ficar restrito à rede interna.',
            ],
            135 => [
                'severity' => 'medium',
                'title' => 'MSRPC (Windows) exposto',
                'description' => 'Serviço interno do Windows; não deveria estar acessível fora da máquina/rede local.',
            ],
            139 => [
                'severity' => 'high',
                'title' => 'NetBIOS/SMB legado exposto',
                'description' => 'Historicamente alvo de worms de rede. Restrinja o acesso a essa porta.',
            ],
            143 => [
                'severity' => 'medium',
                'title' => 'IMAP sem criptografia',
                'description' => 'Credenciais de e-mail trafegam em texto puro. Prefira IMAPS (porta 993).',
            ],
            445 => [
                'severity' => 'high',
                'title' => 'SMB exposto',
                'description' => 'Alvo comum de ransomware (ex.: WannaCry/EternalBlue). Mantenha atualizado e evite expor fora da rede local.',
            ],
            1723 => [
                'severity' => 'medium',
                'title' => 'PPTP exposto',
                'description' => 'Protocolo de VPN considerado inseguro por design. Prefira WireGuard ou OpenVPN.',
            ],
            3306 => [
                'severity' => 'high',
                'title' => 'MySQL/MariaDB exposto',
                'description' => 'Banco de dados acessível pela rede. Normalmente deveria ficar restrito ao host da aplicação.',
            ],
            3389 => [
                'severity' => 'high',
                'title' => 'RDP exposto',
                'description' => 'Alvo frequente de força bruta e ransomware. Prefira acesso via VPN com autenticação multifator.',
            ],
            5900 => [
                'severity' => 'high',
                'title' => 'VNC exposto',
                'description' => 'Frequentemente configurado sem senha ou com senha fraca. Evite expor fora da rede local.',
            ],
            8080 => [
                'severity' => 'low',
                'title' => 'Serviço HTTP alternativo exposto',
                'description' => 'Confirme que a aplicação nessa porta está atualizada e não usa credenciais padrão.',
            ],
            8443 => [
                'severity' => 'low',
                'title' => 'Serviço HTTPS alternativo exposto',
                'description' => 'Confirme que o certificado é válido e a aplicação está atualizada.',
            ],
        ];
    }
}
