<?php

declare(strict_types=1);

namespace App\Network;

/**
 * Nécessite le paquet Composer : phpseclib/phpseclib (v3)
 *   composer require phpseclib/phpseclib:^3.0
 *
 * Ne pas oublier d'inclure l'autoload de Composer dans votre bootstrap :
 *   require_once __DIR__ . '/vendor/autoload.php';
 *
 * ------------------------------------------------------------------
 * IMPORTANT - à propos du CLI Allied Telesis
 * ------------------------------------------------------------------
 * Cette classe cible les switchs tournant sous AlliedWare Plus (AW+),
 * le système d'exploitation moderne des séries x230/x510/x900/IE2xx/
 * SwitchBlade x8, etc. AW+ a été volontairement conçu pour ressembler
 * à Cisco IOS (mêmes modes "User Exec" / "Privileged Exec" / "Global
 * Configuration", prompts du type "awplus>" / "awplus#" /
 * "awplus(config)#", commande "enable", "configure terminal", etc.).
 *
 * En revanche, si vos switchs tournent sous l'ancien AlliedWare
 * "classique" (AT-S63, AT-S39, séries AT-8xxx/AT-9xxx historiques),
 * le CLI est complètement différent (interface façon menu, commandes
 * du type "show switch port=1-24"...) et cette classe NE conviendra
 * PAS : il faudrait l'adapter entièrement à cette syntaxe.
 *
 * Les commandes marquées [confirmé] ci-dessous ont été vérifiées dans
 * la documentation Allied Telesis. Celles marquées [à vérifier] sont
 * des adaptations raisonnables du modèle Cisco mais doivent être
 * validées sur votre matériel / version de firmware exacte avant mise
 * en production, car le format de sortie peut varier d'une gamme à
 * l'autre (x230 vs x510 vs x900...).
 */

use phpseclib3\Net\SSH2;
use phpseclib3\Exception\ConnectionClosedException;

class AlliedTelesisSshException extends \RuntimeException {}

class AlliedTelesisSsh
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

            // [à vérifier] AW+ mime le CLI Cisco ; "terminal length 0"
            // est généralement accepté pour désactiver la pagination.
            // Si votre firmware ne le supporte pas, cette ligne est
            // sans risque : AW+ renverra juste une erreur "Invalid
            // input" ignorée par exec().
            $this->exec('terminal length 0');
        }

        return $connected;
    }

    public function exec(string $cmd): string|false
    {
        $this->ssh->write($cmd . "\n");
        $data = $this->ssh->read($this->prompt);

        if (str_contains($data, '% Invalid input detected')
            || str_contains($data, '% Unknown command')
            || str_contains($data, 'Command not found')
        ) {
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
            $this->ssh->write("exit\n");
        } catch (ConnectionClosedException) {
            // Connexion déjà fermée côté distant, on l'ignore.
        }

        $this->ssh->disconnect();
    }

    private function readPrompt(): string
    {
        // Prompts AW+ typiques : "awplus>", "awplus#", "awplus(config)#"
        $prompt = $this->ssh->read('/.*[>|#]/', SSH2::READ_REGEX);
        return str_replace("\r\n", '', trim($prompt));
    }

    private function requireEnabled(string $method): void
    {
        if (!str_contains($this->prompt, '#')) {
            throw new AlliedTelesisSshException("Error: User must be enabled to use {$method}()");
        }
    }

    /**
     * [confirmé] "show interface status" existe sous AW+ (ex : séries
     * XS900MX, x230, x510...). Le nombre et l'ordre exact des colonnes
     * peut varier légèrement selon le modèle/firmware ; l'analyse ici
     * se base sur un découpage par espaces plutôt que sur des offsets
     * de colonnes fixes (plus robuste que l'implémentation Cisco
     * d'origine, qui supposait des largeurs de colonnes figées).
     *
     * @return array<int, array{interface:string, status:string, vlan:string, duplex:string, speed:string, type:string}>
     */
    public function showIntStatus(): array
    {
        $result = [];

        $this->exec('show interface status');

        $lines = explode("\r\n", (string) $this->data);

        // Retire l'écho de la commande + la ligne d'en-tête ("Port  Name  Status ...")
        for ($i = 0; $i < 2; $i++) {
            array_shift($lines);
        }
        array_pop($lines); // retire la ligne du prompt

        foreach ($lines as $line) {
            $temp = trim($line);

            if ($temp === '' || str_starts_with($temp, '-')) {
                continue;
            }

            $fields = preg_split('/\s+/', $temp);

            if (count($fields) < 5) {
                continue;
            }

            $entry = [
                'interface' => $fields[0],
                'status' => $fields[1],
                'vlan' => $fields[2],
                'duplex' => $fields[3],
                'speed' => $fields[4],
                'type' => implode(' ', array_slice($fields, 5)),
            ];

            $result[] = $entry;
        }

        $this->data = $result;
        return $result;
    }

    /**
     * [à vérifier] Ports en err-disable : dérivé de show_int_status()
     * en filtrant sur le statut, car AW+ n'a pas de commande dédiée
     * strictement équivalente à "show int status err" de Cisco.
     * Adaptez la valeur de statut recherchée si votre firmware
     * utilise un libellé différent (ex: "err-disable" vs "errDisable").
     *
     * @return array<int, array{interface:string, status:string, vlan:string, duplex:string, speed:string, type:string}>
     */
    public function errdisabled(): array
    {
        $result = array_values(array_filter(
            $this->showIntStatus(),
            static fn (array $entry): bool => str_contains(strtolower($entry['status']), 'err')
        ));

        $this->data = $result;
        return $result;
    }

    /**
     * [confirmé] "show log" existe sous AW+. Le format des lignes
     * ("<timestamp> <facility> <severity>: <message>") est similaire
     * à celui de Cisco IOS mais peut varier ; adaptez le filtre au
     * besoin (AW+ ne connaît pas nécessairement le marqueur "%").
     *
     * @return array<int, array{timestamp:string, message:string}>
     */
    public function showLog(): array
    {
        $this->requireEnabled('showLog');

        $result = [];

        $this->exec('show log');

        $lines = explode("\r\n", (string) $this->data);
        array_shift($lines);
        array_pop($lines);

        foreach ($lines as $line) {
            $temp = trim($line);

            if ($temp === '') {
                continue;
            }

            // Format typique : "Jan  1 00:00:00 awplus <facility>[...]: message"
            // On sépare le timestamp (les 3 premiers "mots") du reste.
            $fields = preg_split('/\s+/', $temp, 4);

            $entry = [
                'timestamp' => implode(' ', array_slice($fields, 0, 3)),
                'message' => $fields[3] ?? '',
            ];

            $result[] = $entry;
        }

        $this->data = $result;
        return $result;
    }

    /**
     * [confirmé] "show interface <name>" existe sous AW+. Le format de
     * sortie (compteurs, duplex, etc.) diffère sensiblement de Cisco
     * IOS ; cette méthode retourne les champs les plus communément
     * disponibles. Vérifiez le format exact sur votre firmware et
     * adaptez le parsing si besoin (ex: via "show interface <name>
     * counters" pour des statistiques détaillées).
     *
     * @return array<string, mixed>
     */
    public function showInt(string $int): array
    {
        $result = [];

        $this->exec('show interface ' . $int);

        $lines = explode("\r\n", (string) $this->data);

        foreach ($lines as $line) {
            $entry = trim($line);

            if ($entry === '') {
                continue;
            }

            if (preg_match('/^(\S+)\s+is\s+(up|down|administratively down)/i', $entry, $m)) {
                $result['interface'] = $m[1];
                $result['status'] = match (strtolower($m[2])) {
                    'up' => 'connected',
                    'administratively down' => 'disabled',
                    default => 'notconnect',
                };
            } elseif (str_contains($entry, 'Description:')) {
                $parts = explode(':', $entry, 2);
                $result['description'] = trim($parts[1] ?? '');
            } elseif (preg_match('/Hardware\s+is\s+([^,]+),\s+address\s+is\s+(\S+)/i', $entry, $m)) {
                $result['hardware'] = trim($m[1]);
                $result['mac_address'] = $m[2];
            } elseif (preg_match('/(\d+)\s*Mb\/s/i', $entry, $m)) {
                $result['speed'] = (int) $m[1];
            } elseif (stripos($entry, 'duplex') !== false) {
                $result['duplex'] = stripos($entry, 'full') !== false ? 'full' : 'half';
            } elseif (preg_match('/(\d+)\s+packets\s+input/i', $entry, $m)) {
                $result['in_packet'] = $m[1];
            } elseif (preg_match('/(\d+)\s+packets\s+output/i', $entry, $m)) {
                $result['out_packet'] = $m[1];
            } elseif (preg_match('/(\d+)\s+input\s+errors?/i', $entry, $m)) {
                $result['in_error'] = $m[1];
            } elseif (preg_match('/(\d+)\s+output\s+errors?/i', $entry, $m)) {
                $result['out_error'] = $m[1];
            }
        }

        $this->data = $result;
        return $result;
    }

    /**
     * [confirmé] "show running-config interface <name>" existe sous
     * AW+ (équivalent de "show run int" chez Cisco). Le nombre exact
     * de lignes d'en-tête/pied de page à retirer peut différer ; à
     * ajuster si besoin selon votre firmware.
     */
    public function showIntConfig(string $int): string
    {
        $this->requireEnabled('showIntConfig');

        $this->exec('show running-config interface ' . $int);

        $lines = explode("\r\n", (string) $this->data);

        for ($i = 0; $i < 3; $i++) {
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
     * [à vérifier] Liste des ports trunk. AW+ utilise "switchport mode
     * trunk" en configuration, mais l'affichage dans "show interface
     * status" ne mentionne pas toujours "trunk" explicitement selon
     * le firmware. Cette méthode reste basée sur ce principe ; si elle
     * ne remonte rien sur votre matériel, remplacez-la par un parsing
     * de "show running-config" filtré sur "switchport mode trunk".
     *
     * @return array<int, string>
     */
    public function trunkPorts(): array
    {
        $result = [];

        $this->exec('show interface status | include trunk');

        $lines = explode("\r\n", (string) $this->data);
        array_shift($lines);
        array_pop($lines);

        foreach ($lines as $line) {
            $temp = trim($line);
            if ($temp === '') {
                continue;
            }
            $fields = preg_split('/\s+/', $temp);
            $result[] = $fields[0];
        }

        $this->data = $result;
        return $result;
    }

    /**
     * [confirmé] "show vlan brief" est une commande standard AW+ pour
     * lister les VLAN configurés (bien plus fiable ici que de dériver
     * la liste depuis spanning-tree, comme le faisait l'implémentation
     * Cisco d'origine).
     *
     * @return array<int, int>
     */
    public function vlans(): array
    {
        $result = [];

        $this->exec('show vlan brief');

        $lines = explode("\r\n", (string) $this->data);
        array_shift($lines);
        array_pop($lines);

        foreach ($lines as $line) {
            $temp = trim($line);

            if ($temp === '' || !preg_match('/^\d+/', $temp)) {
                continue;
            }

            $fields = preg_split('/\s+/', $temp);
            $result[] = (int) $fields[0];
        }

        $this->data = $result;
        return $result;
    }

    /**
     * [confirmé] "show mac address-table" existe sous AW+. ATTENTION :
     * l'ordre des colonnes est différent de Cisco. Format AW+ observé :
     *   VLAN   Port        MAC              State
     *   1      port1.0.4   0030.846e.bac7   dynamic
     *   1      unknown     0000.cd28.0752   static
     * (contre "Vlan  Mac Address  Type  Ports" chez Cisco). On exclut
     * les entrées "unknown" (adresses statiques non liées à un port
     * physique, équivalent des lignes "ARP"/CPU chez Cisco) ainsi que
     * les ports trunk, comme dans l'implémentation Cisco d'origine.
     *
     * @return array<int, array{mac_address:string, interface:string}>
     */
    public function macAddressTable(): array
    {
        $result = [];

        $omit = $this->trunkPorts();

        $this->exec('show mac address-table');

        $lines = explode("\r\n", (string) $this->data);
        array_shift($lines); // écho de la commande
        array_shift($lines); // ligne d'en-tête "VLAN Port MAC State"
        array_pop($lines);   // ligne du prompt

        foreach ($lines as $line) {
            $temp = trim($line);

            if ($temp === '') {
                continue;
            }

            $fields = preg_split('/\s+/', $temp);

            if (count($fields) < 4) {
                continue;
            }

            $entry = [
                'mac_address' => strtolower(str_replace('.', '', $fields[2])),
                'interface' => $fields[1],
            ];

            if ($entry['interface'] !== 'unknown' && !in_array($entry['interface'], $omit, true)) {
                $result[] = $entry;
            }
        }

        $this->data = $result;
        return $result;
    }

    /**
     * [à vérifier] "show arp" existe sous AW+ mais le format exact des
     * colonnes doit être confirmé sur votre firmware. Adaptez les
     * indices de $fields si votre sortie diffère.
     *
     * @return array<int, array{ip:string, mac_address:string, interface:string}>
     */
    public function arpTable(): array
    {
        $result = [];

        $this->exec('show arp');

        $lines = explode("\r\n", (string) $this->data);
        array_shift($lines);
        array_shift($lines);
        array_pop($lines);

        foreach ($lines as $line) {
            $temp = trim($line);

            if ($temp === '') {
                continue;
            }

            $fields = preg_split('/\s+/', $temp);

            if (count($fields) < 4) {
                continue;
            }

            $entry = [
                'ip' => $fields[0],
                'mac_address' => $fields[1],
                'interface' => $fields[count($fields) - 1],
            ];

            if (!str_contains(strtolower($entry['mac_address']), 'incomplete')) {
                $result[] = $entry;
            }
        }

        $this->data = $result;
        return $result;
    }

    /**
     * [à vérifier] Cette commande DHCP Snooping doit être confirmée
     * sur votre firmware exact (le préfixe "ip" n'existe pas forcément
     * sous AW+, contrairement à Cisco).
     *
     * @return array<int, array{mac_address:string, ip_address:string, vlan:string, interface:string}>
     */
    public function dhcpSnoopBindings(): array
    {
        $result = [];

        $this->exec('show dhcp snooping binding');

        $lines = explode("\r\n", (string) $this->data);
        array_shift($lines);
        array_shift($lines);
        array_pop($lines);

        foreach ($lines as $line) {
            $temp = trim($line);

            if ($temp === '') {
                continue;
            }

            $fields = preg_split('/\s+/', $temp);

            if (count($fields) < 5) {
                continue;
            }

            $result[] = [
                'mac_address' => strtolower(str_replace([':', '.'], '', $fields[0])),
                'ip_address' => $fields[1],
                'vlan' => $fields[count($fields) - 2],
                'interface' => $fields[count($fields) - 1],
            ];
        }

        $this->data = $result;
        return $result;
    }

    /**
     * [à vérifier] "show ipv6 neighbors" — à confirmer sur votre
     * firmware AW+ (support IPv6 variable selon les modèles/licences).
     *
     * @return array<int, array{ipv6:string, mac_address:string, interface:string}>
     */
    public function ipv6NeighborTable(): array
    {
        $result = [];

        $this->exec('show ipv6 neighbors');

        $lines = explode("\r\n", (string) $this->data);
        array_shift($lines);
        array_shift($lines);
        array_pop($lines);

        foreach ($lines as $line) {
            $temp = trim($line);

            if ($temp === '') {
                continue;
            }

            $fields = preg_split('/\s+/', $temp);

            if (count($fields) < 4) {
                continue;
            }

            $result[] = [
                'ipv6' => $fields[0],
                'mac_address' => $fields[1],
                'interface' => $fields[count($fields) - 1],
            ];
        }

        $this->data = $result;
        return $result;
    }

    /**
     * ATTENTION : cette méthode applique de la configuration sur
     * l'équipement, à vos risques. [confirmé] "configure terminal"
     * existe sous AW+ et le sous-mode d'interface se termine par
     * "(config)#" (ou "(config-if)#" en mode interface), comme chez
     * Cisco.
     */
    public function configure(string $config): bool
    {
        $this->requireEnabled('configure');

        $lines = explode("\n", $config);

        $this->ssh->write("configure terminal\n");
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

        throw new AlliedTelesisSshException("Error: Switch rejected configuration:\n{$config}");
    }

    /**
     * [confirmé] AW+ utilise "copy running-config startup-config"
     * (équivalent au "write" ou "write memory" de Cisco). AW+ ne
     * confirme pas systématiquement le succès par un message "[OK]" :
     * en l'absence de message d'erreur (contenant "%"), on considère
     * l'opération réussie.
     */
    public function writeConfig(): bool
    {
        $this->exec('copy running-config startup-config');
        return !str_contains((string) $this->data, '%');
    }
}
