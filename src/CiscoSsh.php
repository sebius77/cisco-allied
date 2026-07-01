<?php

declare(strict_types=1);

namespace App\Network;

/**
 * Nécessite le paquet Composer : phpseclib/phpseclib (v3)
 *   composer require phpseclib/phpseclib:^3.0
 *
 * Ne pas oublier d'inclure l'autoload de Composer dans votre bootstrap :
 *   require_once __DIR__ . '/vendor/autoload.php';
 */

use phpseclib3\Net\SSH2;
use phpseclib3\Exception\ConnectionClosedException;

/**
 * Exception levée pour les erreurs métier propres à cette classe
 * (remplace les die() de l'implémentation d'origine).
 */
class CiscoSshException extends \RuntimeException {}

class CiscoSsh
{
    private SSH2 $ssh;
    private string $prompt = '';

    /** @var mixed */
    private $data;

    public function __construct(
        private readonly string $hostname,
        private readonly string $username,
        private readonly string $password,
        private readonly int $timeout = 3,
    ) {
    }

    public function connect(): bool
    {
        $this->ssh = new SSH2($this->hostname);
        $this->ssh->setTimeout($this->timeout);

        $connected = $this->ssh->login($this->username, $this->password);

        if ($connected) {
            $this->prompt = $this->readPrompt();
            $this->ssh->write("terminal length 0\n");
            $this->ssh->read($this->prompt);
        }

        return $connected;
    }

    public function exec(string $cmd): string|false
    {
        $this->ssh->write($cmd . "\n");
        $data = $this->ssh->read($this->prompt);

        if (str_contains($data, '% Invalid input detected')) {
            $this->data = false;
            return false;
        }

        $this->data = $data;
        return $data;
    }

    public function enable(string $password): bool
    {
        $this->ssh->write("enable\n");
        $this->ssh->read('Password:');
        $this->ssh->write($password . "\n");

        $this->prompt = $this->readPrompt();

        return str_contains($this->prompt, '#');
    }

    public function close(): void
    {
        try {
            $this->ssh->write("quit\n");
        } catch (ConnectionClosedException) {
            // La connexion peut déjà être fermée côté distant, on l'ignore.
        }

        $this->ssh->disconnect();
    }

    /**
     * Lit le prompt courant ("hostname>" ou "hostname#") via une regex,
     * en normalisant les retours à la ligne (comportement IOS parfois incohérent).
     */
    private function readPrompt(): string
    {
        $prompt = $this->ssh->read('/.*[>|#]/', SSH2::READ_REGEX);
        return str_replace("\r\n", '', trim($prompt));
    }

    private function requireEnabled(string $method): void
    {
        if (!str_contains($this->prompt, '#')) {
            throw new CiscoSshException("Error: User must be enabled to use {$method}()");
        }
    }

    /**
     * @return array<int, array{interface:string, description:string, status:string, vlan:string, duplex:string, speed:string, type:string}>
     */
    public function showIntStatus(): array
    {
        $result = [];

        $this->exec('show int status');

        $lines = explode("\r\n", (string) $this->data);
        for ($i = 0; $i < 2; $i++) {
            array_shift($lines);
        }
        array_pop($lines);

        $pos = strpos($lines[0], 'Status');

        foreach ($lines as $line) {
            $temp = trim($line);

            if (strlen($temp) > 1 && $temp[2] !== 'r' && $temp[0] !== '-') {
                $entry = [];
                $entry['interface'] = substr($temp, 0, strpos($temp, ' '));
                $entry['description'] = trim(substr(
                    $temp,
                    strpos($temp, ' ') + 1,
                    $pos - strlen($entry['interface']) - 1
                ));

                $rest = substr($temp, $pos);
                $fields = sscanf($rest, '%s %s %s %s %s %s');

                $entry['status'] = $fields[0];
                $entry['vlan'] = $fields[1];
                $entry['duplex'] = $fields[2];
                $entry['speed'] = $fields[3];
                $entry['type'] = trim($fields[4] . ' ' . $fields[5]);

                $result[] = $entry;
            }
        }

        $this->data = $result;
        return $result;
    }

    /**
     * @return array<int, array{timestamp:string, type:string, message:string}>
     */
    public function showLog(): array
    {
        $this->requireEnabled('showLog');

        $result = [];

        $this->exec('sh log | inc %');

        $lines = explode("\r\n", (string) $this->data);
        array_shift($lines);
        array_pop($lines);

        foreach ($lines as $line) {
            $temp = trim($line);
            $entry = [];

            $entry['timestamp'] = substr($temp, 0, strpos($temp, '%') - 2);
            if ($entry['timestamp'] !== '' && ($entry['timestamp'][0] === '.' || $entry['timestamp'][0] === '*')) {
                $entry['timestamp'] = substr($entry['timestamp'], 1);
            }

            $temp = substr($temp, strpos($temp, '%') + 1);
            $entry['type'] = substr($temp, 0, strpos($temp, ':'));

            $temp = substr($temp, strpos($temp, ':') + 2);
            $entry['message'] = $temp;

            $result[] = $entry;
        }

        $this->data = $result;
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function showInt(string $int): array
    {
        $result = [];

        $this->exec('show int ' . $int);

        $lines = explode("\r\n", (string) $this->data);

        foreach ($lines as $line) {
            $entry = trim($line);

            if (str_contains($entry, 'line protocol')) {
                $result['interface'] = substr($entry, 0, strpos($entry, ' '));

                if (str_contains($entry, 'administratively')) {
                    $result['status'] = 'disabled';
                } elseif (substr($entry, strpos($entry, 'line protocol') + 17, 2) === 'up') {
                    $result['status'] = 'connected';
                } else {
                    $result['status'] = 'notconnect';
                }
            } elseif (str_contains($entry, 'Description: ')) {
                $parts = explode(':', $entry);
                $result['description'] = trim($parts[1]);
            } elseif (str_contains($entry, 'MTU')) {
                $parts = explode(',', $entry);

                $mtu = explode(' ', trim($parts[0]));
                $result['mtu'] = $mtu[1];

                $bw = explode(' ', trim($parts[1]));
                $result['bandwidth'] = $bw[1];

                $dly = explode(' ', trim($parts[2]));
                $result['dly'] = $dly[1];
            } elseif (str_contains($entry, 'duplex')) {
                $parts = explode(',', $entry);

                $first = explode(' ', trim($parts[0]));
                $first0 = explode('-', $first[0]);
                $result['duplex'] = strtolower($first0[0]);

                $speedPart = trim($parts[1]);
                $result['speed'] = str_contains($speedPart, 'Auto') ? 'auto' : (int) $speedPart;

                $typePart = rtrim($parts[2]);
                $result['type'] = substr($typePart, strrpos($typePart, ' ') + 1);
            } elseif (str_contains($entry, 'input rate')) {
                $parts = explode(',', $entry);

                $result['in_rate'] = substr(
                    $parts[0],
                    strpos($parts[0], 'rate') + 5,
                    strrpos($parts[0], ' ') - (strpos($parts[0], 'rate') + 5)
                );

                $packetPart = explode(' ', trim($parts[1]));
                $result['in_packet_rate'] = $packetPart[0];
            } elseif (str_contains($entry, 'output rate')) {
                $parts = explode(',', $entry);

                $result['out_rate'] = substr(
                    $parts[0],
                    strpos($parts[0], 'rate') + 5,
                    strrpos($parts[0], ' ') - (strpos($parts[0], 'rate') + 5)
                );

                $packetPart = explode(' ', trim($parts[1]));
                $result['out_packet_rate'] = $packetPart[0];
            } elseif (str_contains($entry, 'packets input')) {
                $parts = explode(',', $entry);

                $p0 = explode(' ', trim($parts[0]));
                $result['in_packet'] = $p0[0];

                $p1 = explode(' ', trim($parts[1]));
                $result['in'] = $p1[0];

                if (count($parts) > 2) {
                    $p2 = explode(' ', trim($parts[2]));
                    $result['no_buffer'] = $p2[0];
                }
            } elseif (str_contains($entry, 'Received')) {
                $parts = explode(',', $entry);

                $p0 = explode(' ', trim($parts[0]));
                $result['broadcast'] = $p0[1];

                if (count($parts) > 1) {
                    $p1 = explode(' ', trim($parts[1]));
                    $result['runt'] = $p1[0];

                    $p2 = explode(' ', trim($parts[2]));
                    $result['giant'] = $p2[0];

                    $p3 = explode(' ', trim($parts[3]));
                    $result['throttle'] = $p3[0];
                }
            } elseif (str_contains($entry, 'CRC')) {
                $parts = explode(',', $entry);

                $p0 = explode(' ', trim($parts[0]));
                $result['in_error'] = $p0[0];

                $p1 = explode(' ', trim($parts[1]));
                $result['crc'] = $p1[0];

                $p2 = explode(' ', trim($parts[2]));
                $result['frame'] = $p2[0];

                $p3 = explode(' ', trim($parts[3]));
                $result['overrun'] = $p3[0];

                $p4 = explode(' ', trim($parts[4]));
                $result['ignored'] = $p4[0];
            } elseif (str_contains($entry, 'watchdog')) {
                $parts = explode(',', $entry);

                $p0 = explode(' ', trim($parts[0]));
                $result['watchdog'] = $p0[0];

                $p1 = explode(' ', trim($parts[1]));
                $result['multicast'] = $p1[0];

                if (count($parts) > 2) {
                    $p2 = explode(' ', trim($parts[2]));
                    $result['pause_in'] = $p2[0];
                }
            } elseif (str_contains($entry, 'dribble')) {
                $parts = explode(' ', trim($entry));
                $result['in_dribble'] = $parts[0];
            } elseif (str_contains($entry, 'packets output')) {
                $parts = explode(',', $entry);

                $p0 = explode(' ', trim($parts[0]));
                $result['out_packet'] = $p0[0];

                $p1 = explode(' ', trim($parts[1]));
                $result['out'] = $p1[0];

                $p2 = explode(' ', trim($parts[2]));
                $result['underrun'] = $p2[0];
            } elseif (str_contains($entry, 'output errors')) {
                $parts = explode(',', $entry);

                $p0 = explode(' ', trim($parts[0]));
                $result['out_error'] = $p0[0];

                if (count($parts) > 2) {
                    $p1 = explode(' ', trim($parts[1]));
                    $result['collision'] = $p1[0];

                    $p2 = explode(' ', trim($parts[2]));
                    $result['reset'] = $p2[0];
                } else {
                    $p1 = explode(' ', trim($parts[1]));
                    $result['reset'] = $p1[0];
                }
            } elseif (str_contains($entry, 'babbles')) {
                $parts = explode(',', $entry);

                $p0 = explode(' ', trim($parts[0]));
                $result['babble'] = $p0[0];

                $p1 = explode(' ', trim($parts[1]));
                $result['late_collision'] = $p1[0];

                $p2 = explode(' ', trim($parts[2]));
                $result['deferred'] = $p2[0];
            } elseif (str_contains($entry, 'lost carrier')) {
                $parts = explode(',', $entry);

                $p0 = explode(' ', trim($parts[0]));
                $result['lost_carrier'] = $p0[0];

                $p1 = explode(' ', trim($parts[1]));
                $result['no_carrier'] = $p1[0];

                if (count($parts) > 2) {
                    $p2 = explode(' ', trim($parts[2]));
                    $result['pause_out'] = $p2[0];
                }
            } elseif (str_contains($entry, 'output buffer failures')) {
                $parts = explode(',', $entry);

                $p0 = explode(' ', trim($parts[0]));
                $result['out_buffer_fail'] = $p0[0];

                $p1 = explode(' ', trim($parts[1]));
                $result['out_buffer_swap'] = $p1[0];
            }
        }

        $this->data = $result;
        return $result;
    }

    public function showIntConfig(string $int): string
    {
        $this->requireEnabled('showIntConfig');

        $this->exec('show run int ' . $int);

        $lines = explode("\r\n", (string) $this->data);

        for ($i = 0; $i < 5; $i++) {
            array_shift($lines);
        }
        for ($i = 0; $i < 2; $i++) {
            array_pop($lines);
        }

        $result = implode("\n", $lines);
        $this->data = $result;
        return $result;
    }

    /**
     * @return array<int, string>
     */
    public function trunkPorts(): array
    {
        $result = [];

        $this->exec('show interface status | include trunk');

        $lines = explode("\r\n", (string) $this->data);
        array_shift($lines);
        array_pop($lines);

        foreach ($lines as $interface) {
            $parts = explode(' ', $interface);
            $result[] = $parts[0];
        }

        $this->data = $result;
        return $result;
    }

    /**
     * @return array<int, int>
     */
    public function vlans(): array
    {
        $result = [];

        $this->exec('show spanning-tree summary | include ^VLAN');

        $lines = explode("\r\n", (string) $this->data);
        array_shift($lines);
        array_pop($lines);

        foreach ($lines as $vlanLine) {
            $parts = explode(' ', $vlanLine);
            $vlan = substr($parts[0], 4);
            $result[] = (int) $vlan;
        }

        $this->data = $result;
        return $result;
    }

    /**
     * @return array<int, array{interface:string, description:string, status:string, reason:string}>
     */
    public function errdisabled(): array
    {
        $result = [];

        $this->exec('show int status err');

        $lines = explode("\r\n", (string) $this->data);
        for ($i = 0; $i < 2; $i++) {
            array_shift($lines);
        }
        array_pop($lines);

        $pos = strpos($lines[0], 'Status');

        foreach ($lines as $line) {
            $temp = trim($line);

            if (strlen($temp) > 1 && $temp[2] !== 'r') {
                $entry = [];
                $entry['interface'] = substr($temp, 0, strpos($temp, ' '));
                $entry['description'] = trim(substr(
                    $temp,
                    strpos($temp, ' ') + 1,
                    $pos - strlen($entry['interface']) - 1
                ));

                $rest = substr($temp, $pos);
                $fields = sscanf($rest, '%s %s');

                $entry['status'] = $fields[0];
                $entry['reason'] = $fields[1];

                $result[] = $entry;
            }
        }

        $this->data = $result;
        return $result;
    }

    /**
     * @return array<int, array{mac_address:string, ip_address:string, lease:string, vlan:string, interface:string}>
     */
    public function dhcpSnoopBindings(): array
    {
        $result = [];

        $this->exec('sh ip dhcp snoop binding | inc dhcp-snooping');

        $lines = explode("\r\n", (string) $this->data);
        array_shift($lines);
        array_pop($lines);

        foreach ($lines as $line) {
            $fields = sscanf($line, '%s %s %s %s %s %s');

            if ($fields[3] === 'dhcp-snooping') {
                $entry = [];
                $entry['mac_address'] = strtolower(str_replace(':', '', $fields[0]));
                $entry['ip_address'] = $fields[1];
                $entry['lease'] = $fields[2];
                $entry['vlan'] = $fields[4];
                $entry['interface'] = $fields[5];
                $result[] = $entry;
            }
        }

        $this->data = $result;
        return $result;
    }

    /**
     * @return array<int, array{mac_address:string, interface:string}>
     */
    public function macAddressTable(): array
    {
        $result = [];

        $omit = $this->trunkPorts();

        $this->exec('show mac address-table | exclude CPU');
        $raw = str_replace('          ', '', (string) $this->data);

        $lines = explode("\r\n", $raw);
        for ($i = 0; $i < 6; $i++) {
            array_shift($lines);
        }
        for ($i = 0; $i < 2; $i++) {
            array_pop($lines);
        }

        foreach ($lines as $line) {
            $fields = sscanf($line, '%s %s %s %s');
            $entry = [
                'mac_address' => $fields[1],
                'interface' => $fields[3],
            ];

            if (!in_array($entry['interface'], $omit, true)) {
                $result[] = $entry;
            }
        }

        $this->data = $result;
        return $result;
    }

    /**
     * @return array<int, array{ip:string, mac_address:string, age:string, interface:string}>
     */
    public function arpTable(): array
    {
        $result = [];

        $this->exec('show arp | exc Incomplete');

        $lines = explode("\r\n", (string) $this->data);
        for ($i = 0; $i < 2; $i++) {
            array_shift($lines);
        }
        array_pop($lines);

        foreach ($lines as $line) {
            $fields = sscanf($line, '%s %s %s %s %s %s');

            $entry = [];
            $entry['ip'] = $fields[1];
            $entry['mac_address'] = $fields[3];
            $entry['age'] = $fields[2] === '-' ? '0' : $fields[2];
            $entry['interface'] = $fields[5];

            if ($entry['ip'] !== 'Address' && $entry['mac_address'] !== 'Incomplete') {
                $result[] = $entry;
            }
        }

        $this->data = $result;
        return $result;
    }

    /**
     * @return array<int, array{ipv6:string, mac_address:string, age:string, interface:string}>
     */
    public function ipv6NeighborTable(): array
    {
        $result = [];

        $this->exec('show ipv6 neighbors | exc INCMP');

        $lines = explode("\r\n", (string) $this->data);
        for ($i = 0; $i < 2; $i++) {
            array_shift($lines);
        }
        for ($i = 0; $i < 2; $i++) {
            array_pop($lines);
        }

        foreach ($lines as $line) {
            $fields = sscanf($line, '%s %s %s %s %s');

            $result[] = [
                'ipv6' => $fields[0],
                'age' => $fields[1],
                'mac_address' => $fields[2],
                'interface' => $fields[4],
            ];
        }

        $this->data = $result;
        return $result;
    }

    /**
     * @return array<int, array{router:string, interface:string, prefix:string}>
     */
    public function ipv6Routers(): array
    {
        $result = [];

        $this->exec('show ipv6 routers');

        $lines = explode("\r\n", (string) $this->data);
        array_shift($lines);
        array_pop($lines);

        for ($i = 0; $i < count($lines); $i++) {
            $line = trim($lines[$i]);

            if (str_starts_with($line, 'Router ')) {
                $fields = sscanf($line, '%s %s %s %s');

                $entry = [];
                $entry['router'] = $fields[1];
                $entry['interface'] = str_replace(',', '', $fields[3]);

                $prefixFields = sscanf(trim($lines[$i + 4]), '%s %s %s');
                $entry['prefix'] = $prefixFields[1];

                $i += 5;
                $result[] = $entry;
            }
        }

        $this->data = $result;
        return $result;
    }

    /**
     * ATTENTION : cette méthode applique de la configuration sur l'équipement, à vos risques.
     */
    public function configure(string $config): bool
    {
        $this->requireEnabled('configure');

        $lines = explode("\n", $config);

        $this->ssh->write("config t\n");
        $configPrompt = $this->ssh->read('/.*[>|#]/', SSH2::READ_REGEX);
        $configPrompt = str_replace("\r\n", '', trim($configPrompt));

        if (str_contains($configPrompt, 'config)#')) {
            foreach ($lines as $line) {
                $this->ssh->write($line . "\n");
            }
            $this->ssh->write("end\n");
        }

        $result = $this->ssh->read($this->prompt);
        $resultLines = explode("\r\n", $result);

        if (count($lines) === (count($resultLines) - 2)) {
            return true;
        }

        throw new CiscoSshException("Error: Switch rejected configuration:\n{$config}");
    }

    public function writeConfig(): bool
    {
        $this->exec('write');
        return str_contains((string) $this->data, '[OK]');
    }
}
