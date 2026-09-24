<?php

declare(strict_types=1);

namespace App\Discovery;

use App\Support\Environment;

/**
 * Reads a device's standard MIB-II "system" group via SNMP — sysDescr
 * (vendor/model/firmware, free text), sysName, sysUpTime, sysContact and
 * sysLocation. No credentials or agent needed: any device with an SNMP
 * agent enabled (routers, managed switches, printers, APs, UPS units —
 * regular Windows/macOS/Linux desktops usually have SNMP off by default)
 * answers this to anyone who knows its "community string", a weak
 * shared-secret read key that's very often left at the default "public".
 *
 * Prefers the `snmp` PHP extension (fast, structured); falls back to
 * nmap's SNMP script when only nmap is available.
 */
final class SnmpDiscovery
{
    public function __construct(private readonly string $community = 'public')
    {
    }

    public function isAvailable(): bool
    {
        return function_exists('snmpget') || Environment::commandExists('nmap');
    }

    /**
     * @return array<string, string>|null Null when the device didn't answer at all.
     */
    public function query(string $ip): ?array
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }

        if (function_exists('snmpget')) {
            return $this->queryViaExtension($ip);
        }

        if (Environment::commandExists('nmap')) {
            return $this->queryViaNmap($ip);
        }

        return null;
    }

    private function queryViaExtension(string $ip): ?array
    {
        if (function_exists('snmp_set_valueretrieval')) {
            snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
        }

        $oids = [
            'sys_descr' => '.1.3.6.1.2.1.1.1.0',
            'sys_uptime' => '.1.3.6.1.2.1.1.3.0',
            'sys_contact' => '.1.3.6.1.2.1.1.4.0',
            'sys_name' => '.1.3.6.1.2.1.1.5.0',
            'sys_location' => '.1.3.6.1.2.1.1.6.0',
        ];

        $result = [];
        foreach ($oids as $label => $oid) {
            $value = @snmpget($ip, $this->community, $oid, 800000, 1);
            if ($value !== false) {
                $clean = trim((string) $value, "\" \t\n\r");
                if ($clean !== '') {
                    $result[$label] = $clean;
                }
            }
        }

        return $result === [] ? null : $result;
    }

    private function queryViaNmap(string $ip): ?array
    {
        $cmd = sprintf(
            'nmap -sU -p 161 --script snmp-sysdescr --script-args snmpcommunity=%s -oX - %s 2>/dev/null',
            escapeshellarg($this->community),
            escapeshellarg($ip)
        );
        $xml = @shell_exec($cmd);
        if (!$xml) {
            return null;
        }

        $sx = @simplexml_load_string($xml);
        if ($sx === false) {
            return null;
        }

        foreach ($sx->xpath('//script[@id="snmp-sysdescr"]') as $script) {
            $output = trim((string) $script['output']);
            if ($output !== '') {
                return ['sys_descr' => $output];
            }
        }

        return null;
    }
}
