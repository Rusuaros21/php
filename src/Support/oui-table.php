<?php

declare(strict_types=1);

/**
 * Small, best-effort table of well-known IEEE OUI prefixes (first 3 octets
 * of a MAC address, uppercase, no separators) mapped to vendor names.
 *
 * This is NOT the full IEEE OUI registry (which has 40k+ entries) — it only
 * covers a handful of vendors that are common on home/office networks
 * (virtualization, Raspberry Pi, Espressif-based IoT devices, Apple).
 * When `nmap` is available, NetworkScanner prefers its much more complete
 * vendor database instead; this table only powers the pure-PHP fallback
 * path used when `nmap` is not installed.
 */
return [
    // Raspberry Pi
    'B827EB' => 'Raspberry Pi Foundation',
    'DCA632' => 'Raspberry Pi Trading Ltd',
    'E45F01' => 'Raspberry Pi Trading Ltd',

    // Virtualization
    '000C29' => 'VMware, Inc.',
    '005056' => 'VMware, Inc.',
    '000569' => 'VMware, Inc.',
    '080027' => 'Oracle VirtualBox',
    '001C42' => 'Parallels, Inc.',
    '00163E' => 'Xen / Citrix',

    // Espressif (chips used by most low-cost Wi-Fi IoT devices: plugs,
    // sensors, bulbs, etc. — ESP32 / ESP8266)
    '246F28' => 'Espressif Inc.',
    '30AEA4' => 'Espressif Inc.',
    '3C71BF' => 'Espressif Inc.',
    'A4CF12' => 'Espressif Inc.',
    'ECFABC' => 'Espressif Inc.',
    'CC50E3' => 'Espressif Inc.',
    '84CCA8' => 'Espressif Inc.',

    // Apple
    'ACDE48' => 'Apple, Inc.',
    'F01898' => 'Apple, Inc.',
    '3C15C2' => 'Apple, Inc.',
    'A45E60' => 'Apple, Inc.',
];
