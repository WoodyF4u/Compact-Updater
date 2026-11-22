<?php
// updates_functies.php (uitbreiding "Updates", map uitbreidingen/updates)
//
// Gedeelde functies voor het centraal bijwerken van extensies op de sites
// (pagina updates.php, de acties in updates_actie.php en de cronjob
// cron_updates.php).
//
// Werking in het kort:
//   - Op elke site staat een kleine Joomla-plugin ("Compact Updater",
//     map updates_plugin/compactupdater). De monitor zet die zelf neer via FTP/SFTP
//     en meldt hem eenmalig aan in de database van de site, met een los
//     koppelscript dat zichzelf daarna verwijdert. Er is geen Joomla-account
//     voor nodig.
//   - Elke monitor heeft per site een eigen geheime sleutel (een eigen bestand
//     in de map "sleutels" van de plugin). Twee monitors die dezelfde site
//     volgen, zitten elkaar daardoor niet in de weg.
//   - Elk verzoek aan de plugin is ondertekend (HMAC-SHA256), 5 minuten geldig
//     en maar één keer bruikbaar. Het antwoord is ook ondertekend.
//   - De plugin installeert alleen updates die Joomla zelf heeft gevonden,
//     met dezelfde code als de knop "Bijwerken" in Extensiebeheer.
//
// Dit bestand maakt zijn eigen tabellen aan (zorgUpdatesTabellen()) en raakt
// de bestaande migraties in auto_migratie.php niet. Zo kunnen deze bestanden
// los worden toegevoegd aan elke versie van de monitor.

require_once __DIR__ . '/basis.php';
require_once MONITOR_MAP . '/instellingen_functies.php';
require_once MONITOR_MAP . '/versleuteling_functies.php';

/** Versie van de plugin in updates_plugin/compactupdater (gelijk aan het manifest). */
const UPDATES_PLUGIN_MAP = __DIR__ . '/updates_plugin/compactupdater';

/**
 * Is dit de plugin van deze uitbreiding zelf? Die wordt niet via de lijst
 * met beschikbare updates bijgewerkt (en heeft geen eigen instelling), maar
 * met de knop "Verouderde plugins bijwerken", die de bijgeleverde versie
 * uit updates_plugin/ op de site neerzet.
 */
function updatesIsEigenPlugin(array $extensie): bool
{
    return strtolower((string) ($extensie['type'] ?? '')) === 'plugin'
        && strtolower((string) ($extensie['element'] ?? '')) === 'compactupdater';
}

/** Schemaversie van de tabellen van deze functie. */
const UPDATES_SCHEMA_VERSIE = 4;

/**
 * Joomla zelf (de kern) staat in de lijsten als één bijzondere "extensie":
 * met deze sleutel in updates_beleid en updates_beschikbaar, en dit
 * extension_id (Joomla's eigen ID verschilt per site).
 */
const UPDATES_KERN_SLEUTEL = 'joomla:kern';
const UPDATES_KERN_ID      = -1;

/** De standen die voor de Joomla-kern kunnen (een nieuwe hoofdversie gaat nooit automatisch). */
function updatesKernStanden(): array
{
    return [
        'handmatig' => 'Handmatig',
        'patch'     => 'Automatisch: alleen kleine reparaties (x.y.Z)',
        'minor'     => 'Automatisch: binnen dezelfde hoofdversie',
        'overslaan' => 'Overslaan (niet bijwerken)',
    ];
}

/** Is dit de Joomla-kern (een regel uit updates_beschikbaar of een update)? */
function updatesIsKern(array $update): bool
{
    return (string) ($update['ext_sleutel'] ?? '') === UPDATES_KERN_SLEUTEL || (int) ($update['extension_id'] ?? 0) === UPDATES_KERN_ID;
}

/** Mogelijke standen per extensie (en per site × extensie). */
function updatesStanden(): array
{
    return [
        'handmatig' => 'Handmatig',
        'patch'     => 'Automatisch: alleen kleine reparaties (x.y.Z)',
        'minor'     => 'Automatisch: binnen dezelfde hoofdversie',
        'alles'     => 'Automatisch: alle versies',
        'overslaan' => 'Overslaan (niet bijwerken)',
    ];
}

/** Korte labels voor in tabellen. */
function updatesStandKort(string $stand): string
{
    $labels = [
        'handmatig' => '✋ Handmatig',
        'patch'     => '🔁 Auto (x.y.Z)',
        'minor'     => '🔁 Auto (zelfde hoofdversie)',
        'alles'     => '🔁 Auto (alles)',
        'overslaan' => '⏸️ Overslaan',
    ];

    return $labels[$stand] ?? $stand;
}

// ----------------------------------------------------------------------
// Database
// ----------------------------------------------------------------------

/**
 * Maakt de tabellen voor deze functie aan als ze er nog niet zijn. Snel bij
 * elke aanroep: na de eerste keer is het één SELECT op de instellingen.
 */
function zorgUpdatesTabellen(PDO $pdo): void
{
    $huidig = (int) haalInstelling($pdo, 'updates_schema_versie', '0');
    if ($huidig >= UPDATES_SCHEMA_VERSIE) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `updates_koppeling` (
            `site_id` int(11) NOT NULL,
            `sleutel_id` varchar(64) NOT NULL,
            `sleutel` varchar(500) NOT NULL,
            `plugin_versie` varchar(20) DEFAULT NULL,
            `joomla_versie` varchar(50) DEFAULT NULL,
            `toestaan` tinyint(1) DEFAULT NULL,
            `geinstalleerd_op` datetime DEFAULT NULL,
            `laatste_contact` datetime DEFAULT NULL,
            `laatste_fout` varchar(1000) DEFAULT NULL,
            `opgehaald` datetime DEFAULT NULL,
            `uitgeschakelde_locaties` text DEFAULT NULL,
            PRIMARY KEY (`site_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `updates_beschikbaar` (
            `site_id` int(11) NOT NULL,
            `extension_id` int(11) NOT NULL,
            `ext_sleutel` varchar(190) NOT NULL,
            `naam` varchar(255) DEFAULT NULL,
            `type` varchar(50) DEFAULT NULL,
            `huidige_versie` varchar(50) DEFAULT NULL,
            `nieuwe_versie` varchar(50) DEFAULT NULL,
            `downloadsleutel` tinyint(1) NOT NULL DEFAULT 0,
            `infourl` varchar(500) DEFAULT NULL,
            `eerst_gezien` datetime DEFAULT NULL,
            `gemeld_versie` varchar(50) DEFAULT NULL,
            `overgeslagen_versie` varchar(50) DEFAULT NULL,
            PRIMARY KEY (`site_id`, `extension_id`),
            KEY `ext_sleutel` (`ext_sleutel`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    // site_id 0 = geldt voor alle sites; ext_sleutel '' = geldt voor de hele site.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `updates_beleid` (
            `site_id` int(11) NOT NULL DEFAULT 0,
            `ext_sleutel` varchar(190) NOT NULL DEFAULT '',
            `stand` varchar(20) NOT NULL,
            `gewijzigd` datetime DEFAULT NULL,
            PRIMARY KEY (`site_id`, `ext_sleutel`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `updates_geschiedenis` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `run_id` varchar(32) DEFAULT NULL,
            `site_id` int(11) NOT NULL,
            `domein` varchar(255) DEFAULT NULL,
            `extension_id` int(11) DEFAULT NULL,
            `naam` varchar(255) DEFAULT NULL,
            `van_versie` varchar(50) DEFAULT NULL,
            `naar_versie` varchar(50) DEFAULT NULL,
            `resultaat` varchar(20) NOT NULL,
            `bericht` text DEFAULT NULL,
            `controle` varchar(500) DEFAULT NULL,
            `bron` varchar(20) DEFAULT NULL,
            `tijdstip` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `run_id` (`run_id`),
            KEY `tijdstip` (`tijdstip`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    // Versie 2: snapshot vóór de update en terugzetten.
    $kolommen = $pdo->query("SHOW COLUMNS FROM updates_geschiedenis")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('snapshot', $kolommen, true)) {
        $pdo->exec("ALTER TABLE updates_geschiedenis
            ADD COLUMN `snapshot` varchar(1000) DEFAULT NULL,
            ADD COLUMN `snapshot_grootte` bigint DEFAULT NULL,
            ADD COLUMN `teruggezet` datetime DEFAULT NULL");
    }

    // Versie 3: sites die bewust buiten het centraal bijwerken blijven.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `updates_uitgesloten` (
            `site_id` int(11) NOT NULL,
            `sinds` datetime NOT NULL,
            `reden` varchar(255) DEFAULT NULL,
            PRIMARY KEY (`site_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    // Versie 4: Joomla zelf bijwerken. De update binnen de hoofdversie staat
    // als regel in updates_beschikbaar; een nieuwe hoofdversie hier.
    $kolommen = $pdo->query("SHOW COLUMNS FROM updates_koppeling")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('kern_volgende', $kolommen, true)) {
        $pdo->exec("ALTER TABLE updates_koppeling
            ADD COLUMN `kern_volgende` varchar(50) DEFAULT NULL,
            ADD COLUMN `kern_fout` varchar(500) DEFAULT NULL");
    }

    slaInstellingOp($pdo, 'updates_schema_versie', (string) UPDATES_SCHEMA_VERSIE);
}

// ----------------------------------------------------------------------
// Uitgesloten sites
// ----------------------------------------------------------------------
//
// Een uitgesloten site blijft gewoon in de monitor (scans, beveiliging),
// maar doet niet mee aan het centraal bijwerken: geen plugin plaatsen,
// geen updates ophalen of installeren, ook niet via "alles" of de
// cronjob. Dit geldt alleen voor deze monitor; een andere monitor die
// dezelfde site volgt, heeft zijn eigen lijst.

/** De uitgesloten sites, als [site_id => ['sinds' => ..., 'reden' => ...]]. */
function updatesUitgeslotenSites(PDO $pdo, bool $opnieuw = false): array
{
    static $lijst = null;
    if ($lijst === null || $opnieuw) {
        $lijst = [];
        foreach ($pdo->query("SELECT site_id, sinds, reden FROM updates_uitgesloten") as $rij) {
            $lijst[(int) $rij['site_id']] = ['sinds' => $rij['sinds'], 'reden' => (string) $rij['reden']];
        }
    }

    return $lijst;
}

function updatesIsUitgesloten(PDO $pdo, int $siteId): bool
{
    return isset(updatesUitgeslotenSites($pdo)[$siteId]);
}

/**
 * Sluit een site uit (of laat haar weer toe). Bij uitsluiten verdwijnen
 * de opgehaalde updates van die site uit de lijst; de koppeling (en een
 * eventueel al geplaatste plugin) blijft staan, zodat weer toelaten
 * zonder opnieuw plaatsen kan.
 */
function updatesZetUitgesloten(PDO $pdo, int $siteId, bool $uitsluiten, string $reden = ''): void
{
    if ($uitsluiten) {
        $pdo->prepare("
            INSERT INTO updates_uitgesloten (site_id, sinds, reden) VALUES (?, NOW(), ?)
            ON DUPLICATE KEY UPDATE reden = VALUES(reden)
        ")->execute([$siteId, $reden !== '' ? mb_substr($reden, 0, 255) : null]);
        $pdo->prepare("DELETE FROM updates_beschikbaar WHERE site_id = ?")->execute([$siteId]);
    } else {
        $pdo->prepare("DELETE FROM updates_uitgesloten WHERE site_id = ?")->execute([$siteId]);
    }
    updatesUitgeslotenSites($pdo, true);
}

/** Versie van de plugin die deze monitor meelevert (uit het manifest). */
function updatesMeegeleverdePluginVersie(): string
{
    static $versie = null;
    if ($versie === null) {
        $xml    = @simplexml_load_file(UPDATES_PLUGIN_MAP . '/compactupdater.xml');
        $versie = $xml ? trim((string) $xml->version) : '';
    }

    return $versie;
}

function updatesHaalKoppeling(PDO $pdo, int $siteId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM updates_koppeling WHERE site_id = ?");
    $stmt->execute([$siteId]);
    $rij = $stmt->fetch(PDO::FETCH_ASSOC);

    return $rij ?: null;
}

function updatesHaalSite(PDO $pdo, int $siteId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = ?");
    $stmt->execute([$siteId]);
    $rij = $stmt->fetch(PDO::FETCH_ASSOC);

    return $rij ?: null;
}

/** Mag de monitor op deze site updates installeren (los van de stand per extensie)? */
function updatesSiteMagBijwerken(PDO $pdo, array $site): bool
{
    if (($site['categorie'] ?? 'eigen') === 'anderen') {
        return haalInstelling($pdo, 'updates_anderen_toestaan', '0') === '1';
    }

    return true;
}

/** Sleutel van een extensie die op elke site hetzelfde is (het extension_id verschilt per site). */
function updatesExtSleutel(array $update): string
{
    return implode(':', [
        (string) ($update['type'] ?? ''),
        (string) ($update['element'] ?? ''),
        (string) ($update['folder'] ?? ''),
        (string) (int) ($update['client_id'] ?? 0),
    ]);
}

// ----------------------------------------------------------------------
// Beleid: welke updates gaan mee met "Alle geactiveerde updates uitvoeren"
// ----------------------------------------------------------------------

/**
 * De standaard voor elke extensie waarvoor bij "Op alle sites" niets
 * anders is gekozen (ook voor extensies die er later bij komen). Staat in
 * de instellingen van de monitor; zonder keuze: Handmatig.
 */
function updatesStandaardStand(PDO $pdo): string
{
    $stand = (string) haalInstelling($pdo, 'updates_standaard_stand', 'handmatig');

    return array_key_exists($stand, updatesStanden()) ? $stand : 'handmatig';
}

/**
 * Alle beleidsregels, als [site_id][ext_sleutel] => stand.
 *
 * Daarnaast staat in [0][''] (site 0 = alle sites, lege sleutel = alle
 * extensies) de standaard uit updatesStandaardStand(). Die komt niet uit
 * de tabel updates_beleid, maar zo kent elke functie die het beleid
 * gebruikt hem vanzelf.
 */
function updatesHaalBeleid(PDO $pdo): array
{
    $beleid = [];
    foreach ($pdo->query("SELECT site_id, ext_sleutel, stand FROM updates_beleid WHERE NOT (site_id = 0 AND ext_sleutel = '')") as $rij) {
        $beleid[(int) $rij['site_id']][(string) $rij['ext_sleutel']] = (string) $rij['stand'];
    }
    $beleid[0][''] = updatesStandaardStand($pdo);

    return $beleid;
}

function updatesSlaBeleidOp(PDO $pdo, int $siteId, string $extSleutel, string $stand): void
{
    if ($stand === '' || $stand === 'volgen') {
        $stmt = $pdo->prepare("DELETE FROM updates_beleid WHERE site_id = ? AND ext_sleutel = ?");
        $stmt->execute([$siteId, $extSleutel]);
        return;
    }

    if ($siteId === 0 && $extSleutel === '') {
        // De standaard voor alle extensies hoort in de instellingen (updatesStandaardStand()).
        throw new InvalidArgumentException('Onbekende combinatie.');
    }
    $toegestaan = $extSleutel === '' ? ['handmatig'] : array_keys($extSleutel === UPDATES_KERN_SLEUTEL ? updatesKernStanden() : updatesStanden());
    if (!in_array($stand, $toegestaan, true)) {
        throw new InvalidArgumentException('Onbekende stand.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO updates_beleid (site_id, ext_sleutel, stand, gewijzigd) VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE stand = VALUES(stand), gewijzigd = NOW()
    ");
    $stmt->execute([$siteId, $extSleutel, $stand]);
}

/**
 * De stand die voor deze site × extensie geldt, met waar die vandaan komt.
 * Volgorde: instelling voor precies deze combinatie > "deze site altijd
 * handmatig" > instelling voor de extensie op alle sites > de standaard
 * voor alle extensies (zie updatesHaalBeleid()) > Handmatig.
 */
function updatesEffectieveStand(array $beleid, int $siteId, string $extSleutel): array
{
    if (isset($beleid[$siteId][$extSleutel])) {
        return ['stand' => $beleid[$siteId][$extSleutel], 'bron' => 'combinatie'];
    }
    if (isset($beleid[$siteId][''])) {
        return ['stand' => $beleid[$siteId][''], 'bron' => 'site'];
    }
    if ($extSleutel !== '' && isset($beleid[0][$extSleutel])) {
        return ['stand' => $beleid[0][$extSleutel], 'bron' => 'extensie'];
    }
    // De standaard voor alle extensies geldt niet voor Joomla zelf: die
    // heeft een eigen instelling (tabblad Joomla), zonder keuze Handmatig.
    if (isset($beleid[0]['']) && $extSleutel !== UPDATES_KERN_SLEUTEL) {
        return ['stand' => $beleid[0][''], 'bron' => 'standaard'];
    }

    return ['stand' => 'handmatig', 'bron' => 'standaard'];
}

/** De cijfers van een versie (1.2.3-rc1 → [1, 2, 3]). */
function updatesVersieDelen(string $versie): array
{
    preg_match('/^\D*(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $versie, $m);

    return [(int) ($m[1] ?? 0), (int) ($m[2] ?? 0), (int) ($m[3] ?? 0)];
}

/**
 * Beslist of een update automatisch mee mag ("Alle geactiveerde updates
 * uitvoeren" en de cronjob). Geeft [bool, reden] terug - de reden is wat
 * de proefdraai laat zien.
 */
function updatesBeslissing(PDO $pdo, array $site, array $update, array $beleid, ?array $koppeling): array
{
    if (updatesIsUitgesloten($pdo, (int) $site['id'])) {
        return [false, 'Site uitgesloten van updates'];
    }
    if (!updatesSiteMagBijwerken($pdo, $site)) {
        return [false, 'Site van anderen: bijwerken staat uit in de instellingen'];
    }
    if ($koppeling === null) {
        return [false, 'Plugin nog niet geïnstalleerd'];
    }
    if ((string) ($koppeling['toestaan'] ?? '1') === '0') {
        return [false, 'Updates staan uit in de plugin op de site'];
    }
    if (!empty($update['overgeslagen_versie']) && $update['overgeslagen_versie'] === $update['nieuwe_versie']) {
        return [false, 'Deze versie is overgeslagen'];
    }

    $stand = updatesEffectieveStand($beleid, (int) $site['id'], (string) $update['ext_sleutel']);
    // Komt de stand van de standaard voor alle extensies, dan staat dat erbij.
    $std = $stand['bron'] === 'standaard' ? ', standaard' : '';

    switch ($stand['stand']) {
        case 'alles':
            return [true, 'Automatisch (alle versies' . $std . ')'];
        case 'minor':
        case 'patch':
            [$vanMaj, $vanMin] = updatesVersieDelen((string) $update['huidige_versie']);
            [$naarMaj, $naarMin] = updatesVersieDelen((string) $update['nieuwe_versie']);
            if ($vanMaj !== $naarMaj) {
                return [false, 'Nieuwe hoofdversie: handmatig bijwerken'];
            }
            if ($stand['stand'] === 'patch' && $vanMin !== $naarMin) {
                return [false, 'Geen kleine reparatie: handmatig bijwerken'];
            }
            return [true, $stand['stand'] === 'patch' ? 'Automatisch (kleine reparatie' . $std . ')' : 'Automatisch (zelfde hoofdversie' . $std . ')'];
        case 'overslaan':
            return [false, 'Overslaan' . ($std ? ' (standaard)' : '')];
        default:
            return [false, $stand['bron'] === 'site' ? 'Handmatig (instelling van de site)' : 'Handmatig' . ($std ? ' (standaard)' : '')];
    }
}

// ----------------------------------------------------------------------
// Ondertekende verzoeken aan de plugin
// ----------------------------------------------------------------------

function updatesPluginUrl(array $site): string
{
    return bepaalSiteUrl($site, 'index.php?option=com_ajax&plugin=compactupdater&group=system&format=raw');
}

/**
 * Na een installatie waarvan het antwoord niet (goed) terugkwam: is de
 * update toch geïnstalleerd? Wacht eerst tot de plugin klaar is met een
 * eventueel nog lopende installatie, en leest dan de updatelijst van
 * Joomla opnieuw (zonder nieuw te zoeken). Joomla haalt een update uit die
 * lijst zodra hij is geïnstalleerd. Geeft 'geinstalleerd', 'niet' of
 * 'onbekend'.
 */
function updatesControleerAfloop(PDO $pdo, array $site, int $extensionId, string $nieuweVersie): string
{
    $siteId = (int) $site['id'];

    // Hooguit een minuut wachten op een installatie die nog loopt.
    for ($i = 0; $i < 12; $i++) {
        $ping = updatesPing($pdo, $site);
        if (!$ping['ok']) {
            return 'onbekend';
        }
        if (empty($ping['antwoord']['bezig'])) {
            break;
        }
        sleep(5);
    }
    if (!empty($ping['antwoord']['bezig'])) {
        return 'onbekend';
    }

    $opgehaald = updatesHaalOp($pdo, $site, false);
    if (!$opgehaald['ok']) {
        return 'onbekend';
    }

    $stmt = $pdo->prepare("SELECT huidige_versie, nieuwe_versie FROM updates_beschikbaar WHERE site_id = ? AND extension_id = ?");
    $stmt->execute([$siteId, $extensionId]);
    $rij = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($rij === false) {
        return 'geinstalleerd';
    }
    // Staat hij er nog, maar is de geïnstalleerde versie al de nieuwe?
    if ($nieuweVersie !== '' && version_compare((string) $rij['huidige_versie'], $nieuweVersie, '>=')) {
        return 'geinstalleerd';
    }

    return 'niet';
}

/**
 * Stuurt een ondertekend verzoek naar de plugin en controleert de
 * handtekening van het antwoord.
 *
 * @return array ['ok' => bool, 'antwoord' => array|null, 'fout' => string, 'http' => int, 'timeout' => bool]
 */
function updatesPluginVerzoek(array $site, array $koppeling, string $actie, array $parameters = [], int $timeout = 60): array
{
    $sleutelId = (string) $koppeling['sleutel_id'];
    $sleutel   = ontsleutelWaarde((string) $koppeling['sleutel']);
    if ($sleutel === '') {
        return ['ok' => false, 'antwoord' => null, 'fout' => 'Geen sleutel bekend voor deze site; installeer de plugin opnieuw.', 'http' => 0, 'timeout' => false];
    }

    $gegevens     = base64_encode((string) json_encode(['actie' => $actie] + $parameters));
    $tijd         = time();
    $nonce        = bin2hex(random_bytes(16));
    $handtekening = hash_hmac('sha256', '1|' . $sleutelId . '|' . $tijd . '|' . $nonce . '|' . $gegevens, $sleutel);

    $ch = curl_init(updatesPluginUrl($site));
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'sleutel_id'   => $sleutelId,
            'tijd'         => $tijd,
            'nonce'        => $nonce,
            'gegevens'     => $gegevens,
            'handtekening' => $handtekening,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_POSTREDIR      => 7, // ook na een doorverwijzing (bijv. naar www.) een POST blijven sturen
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'CompactUpdater',
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Cache-Control: no-cache'],
    ]);
    $body     = curl_exec($ch);
    $http     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlFout = curl_errno($ch);
    $curlTekst = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        $isTimeout = $curlFout === CURLE_OPERATION_TIMEDOUT;

        return [
            'ok'       => false,
            'antwoord' => null,
            'fout'     => $isTimeout
                ? 'Geen antwoord binnen ' . $timeout . ' seconden.'
                : 'Verbinding met de site mislukt: ' . $curlTekst,
            'http'     => 0,
            'timeout'  => $isTimeout,
        ];
    }

    // De handtekening hieronder blijft de echte controle; tekst vóór het
    // antwoord (een PHP-melding) maakt het daarom niet onveilig.
    $json = updatesLeesJson((string) $body);

    if (!is_array($json)) {
        return ['ok' => false, 'antwoord' => null, 'fout' => updatesLeesbareHttpFout($http, (string) $body), 'http' => $http, 'timeout' => false];
    }

    if (isset($json['fout'])) {
        // "Al eens gebruikt": hetzelfde verzoek kwam twee keer aan (een
        // doorverwijzing of een herhaling door de server). Het eerste is
        // dan meestal wél uitgevoerd; de aanroeper kan dat nagaan.
        $herhaald = strpos((string) $json['fout'], 'al eens gebruikt') !== false;
        return ['ok' => false, 'antwoord' => null, 'fout' => 'De plugin weigerde het verzoek: ' . $json['fout'], 'http' => $http, 'timeout' => false, 'herhaald' => $herhaald];
    }

    $antwoordB64 = (string) ($json['antwoord'] ?? '');
    $verwacht    = hash_hmac('sha256', 'antwoord|' . $nonce . '|' . $antwoordB64, $sleutel);
    if (!hash_equals($verwacht, (string) ($json['handtekening'] ?? ''))) {
        return ['ok' => false, 'antwoord' => null, 'fout' => 'Het antwoord van de site is niet correct ondertekend.', 'http' => $http, 'timeout' => false];
    }

    $antwoord = json_decode((string) base64_decode($antwoordB64, true), true);
    if (!is_array($antwoord)) {
        return ['ok' => false, 'antwoord' => null, 'fout' => 'Onleesbaar antwoord van de plugin.', 'http' => $http, 'timeout' => false];
    }

    return ['ok' => true, 'antwoord' => $antwoord, 'fout' => '', 'http' => $http, 'timeout' => false];
}

/**
 * Leest het JSON-antwoord van een site. Staat er tekst vóór (bijvoorbeeld
 * een PHP-melding van een hosting met display_errors aan), dan wordt het
 * JSON-deel aan het eind toch gelezen. Geeft null als er geen JSON is.
 */
function updatesLeesJson(string $body): ?array
{
    $json = json_decode(trim($body), true);
    if (is_array($json)) {
        return $json;
    }
    // Het laatste {...} aan het eind van het antwoord proberen.
    $pos = strrpos($body, '{"');
    while ($pos !== false) {
        $json = json_decode(trim(substr($body, $pos)), true);
        if (is_array($json)) {
            return $json;
        }
        $pos = $pos > 0 ? strrpos(substr($body, 0, $pos), '{"') : false;
    }

    return null;
}

/** Een PHP-melding (Warning, Fatal error, ...) in een antwoord, kort gemaakt; anders ''. */
function updatesPhpMeldingInAntwoord(string $body): string
{
    $tekst = trim(preg_replace('/\s+/', ' ', strip_tags($body)));
    if (preg_match('/\b(Fatal error|Parse error|Warning|Notice|Deprecated)\s*:\s*(.+?)\s+in\s+\S+?([^\/\s]+\.php)\s+on line\s+(\d+)/i', $tekst, $m)) {
        return $m[1] . ': ' . $m[2] . ' (' . $m[3] . ', regel ' . $m[4] . ')';
    }

    return '';
}

/** Maakt van een onverwacht antwoord een uitleg die iemand verder helpt. */
function updatesLeesbareHttpFout(int $http, string $body): string
{
    $kort = trim(mb_substr(strip_tags($body), 0, 200));

    $php = updatesPhpMeldingInAntwoord($body);
    if ($php !== '') {
        $fataal = stripos($php, 'Fatal') === 0 || stripos($php, 'Parse') === 0;
        return ($fataal
                ? 'Het script op de site liep vast op een PHP-fout.'
                : 'De site toonde een PHP-melding, waardoor de monitor het antwoord niet kon lezen.')
            . ' (Technisch: ' . $php . '.)';
    }

    if ($http === 403 || $http === 406) {
        return "De site weigerde het verzoek (HTTP $http). Vaak is dat de firewall van de site (bijv. Admin Tools of de hostingpartij). "
            . 'Zet het IP-adres van de monitor op de witte lijst.';
    }
    if ($http === 404) {
        return 'De plugin is niet gevonden (HTTP 404). Controleer de URL-submap van de site, of installeer de plugin opnieuw.';
    }
    if ($http === 503) {
        return 'De site is (tijdelijk) niet beschikbaar (HTTP 503). Mogelijk staat de site offline of blokkeert een botcontrole het verzoek.';
    }
    if ($http >= 500) {
        return "De site gaf een serverfout (HTTP $http)." . ($kort !== '' ? " Melding: $kort" : '');
    }
    if ($http === 200) {
        return 'De site gaf geen bruikbaar antwoord. Staat de plugin aan in Joomla (Systeem > Plugins)?'
            . ($kort !== '' ? ' (Technisch, begin van het antwoord: ' . mb_substr($kort, 0, 120) . ')' : '');
    }

    return "Onverwacht antwoord van de site (HTTP $http)." . ($kort !== '' ? " $kort" : '');
}

/** Legt contact, versies en eventuele fout vast bij de koppeling. */
function updatesWerkKoppelingBij(PDO $pdo, int $siteId, array $velden): void
{
    if (!$velden) {
        return;
    }
    $sets = [];
    $waarden = [];
    foreach ($velden as $kolom => $waarde) {
        $sets[]    = "`$kolom` = ?";
        $waarden[] = $waarde;
    }
    $waarden[] = $siteId;
    $pdo->prepare("UPDATE updates_koppeling SET " . implode(', ', $sets) . " WHERE site_id = ?")->execute($waarden);
}

// ----------------------------------------------------------------------
// Updates ophalen
// ----------------------------------------------------------------------

/**
 * Vraagt de plugin welke updates er zijn (met $vers: eerst opnieuw zoeken)
 * en slaat de lijst op. Geeft ['ok', 'aantal', 'fout'] terug.
 */
function updatesHaalOp(PDO $pdo, array $site, bool $vers = true): array
{
    $siteId    = (int) $site['id'];
    if (updatesIsUitgesloten($pdo, $siteId)) {
        return ['ok' => false, 'aantal' => 0, 'fout' => 'Deze site is uitgesloten van updates via de monitor.'];
    }
    $koppeling = updatesHaalKoppeling($pdo, $siteId);
    if ($koppeling === null) {
        return ['ok' => false, 'aantal' => 0, 'fout' => 'Plugin nog niet geïnstalleerd.'];
    }

    $resultaat = updatesPluginVerzoek($site, $koppeling, 'ophalen', [
        'vers'          => $vers,
        'kern_volgende' => haalInstelling($pdo, 'updates_kern_hoofdversie', '0') === '1',
    ], $vers ? 300 : 60);

    if (!$resultaat['ok']) {
        updatesWerkKoppelingBij($pdo, $siteId, ['laatste_fout' => mb_substr($resultaat['fout'], 0, 1000)]);
        return ['ok' => false, 'aantal' => 0, 'fout' => $resultaat['fout']];
    }

    $antwoord = $resultaat['antwoord'];
    $updates  = is_array($antwoord['updates'] ?? null) ? $antwoord['updates'] : [];

    $bestaand = [];
    $stmt = $pdo->prepare("SELECT * FROM updates_beschikbaar WHERE site_id = ?");
    $stmt->execute([$siteId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rij) {
        $bestaand[(int) $rij['extension_id']] = $rij;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM updates_beschikbaar WHERE site_id = ?")->execute([$siteId]);

        $invoegen = $pdo->prepare("
            INSERT INTO updates_beschikbaar
                (site_id, extension_id, ext_sleutel, naam, type, huidige_versie, nieuwe_versie,
                 downloadsleutel, infourl, eerst_gezien, gemeld_versie, overgeslagen_versie)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($updates as $update) {
            if (updatesIsEigenPlugin($update)) {
                continue;
            }
            $eid    = (int) ($update['extension_id'] ?? 0);
            $nieuw  = mb_substr((string) ($update['nieuwe_versie'] ?? ''), 0, 50);
            $oud    = $bestaand[$eid] ?? null;
            $zelfde = $oud !== null && (string) $oud['nieuwe_versie'] === $nieuw;

            $invoegen->execute([
                $siteId,
                $eid,
                mb_substr(updatesExtSleutel($update), 0, 190),
                mb_substr((string) ($update['naam'] ?? ''), 0, 255),
                mb_substr((string) ($update['type'] ?? ''), 0, 50),
                mb_substr((string) ($update['huidige_versie'] ?? ''), 0, 50),
                $nieuw,
                !empty($update['downloadsleutel']) ? 1 : 0,
                mb_substr((string) ($update['infourl'] ?? ''), 0, 500),
                $zelfde ? $oud['eerst_gezien'] : date('Y-m-d H:i:s'),
                $oud['gemeld_versie'] ?? null,
                $oud['overgeslagen_versie'] ?? null,
            ]);
        }

        // Joomla zelf: een update binnen de huidige hoofdversie als gewone
        // regel (zodat hij in "Beschikbare updates" en bij "alles" meedoet).
        $kern = is_array($antwoord['kern'] ?? null) ? $antwoord['kern'] : null;
        if ($kern !== null && (string) ($kern['binnen'] ?? '') !== '') {
            $nieuw  = mb_substr((string) $kern['binnen'], 0, 50);
            $oud    = $bestaand[UPDATES_KERN_ID] ?? null;
            $zelfde = $oud !== null && (string) $oud['nieuwe_versie'] === $nieuw;
            $invoegen->execute([
                $siteId,
                UPDATES_KERN_ID,
                UPDATES_KERN_SLEUTEL,
                'Joomla',
                'kern',
                mb_substr((string) ($kern['huidig'] ?? ''), 0, 50),
                $nieuw,
                0,
                'https://www.joomla.org/announcements.html',
                $zelfde ? $oud['eerst_gezien'] : date('Y-m-d H:i:s'),
                $oud['gemeld_versie'] ?? null,
                $oud['overgeslagen_versie'] ?? null,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $velden = [
        'laatste_contact'         => date('Y-m-d H:i:s'),
        'laatste_fout'            => null,
        'joomla_versie'           => mb_substr((string) ($antwoord['joomla_versie'] ?? ''), 0, 50),
        'plugin_versie'           => mb_substr((string) ($antwoord['plugin_versie'] ?? ''), 0, 20),
        'uitgeschakelde_locaties' => json_encode(array_values((array) ($antwoord['uitgeschakelde_locaties'] ?? []))),
    ];
    // Een oudere plugin geeft geen kerninformatie: dan niets veranderen.
    if ($kern !== null) {
        $velden['kern_volgende'] = (string) ($kern['volgende'] ?? '') !== '' ? mb_substr((string) $kern['volgende'], 0, 50) : null;
        $velden['kern_fout']     = (string) ($kern['fout'] ?? '') !== '' ? mb_substr((string) $kern['fout'], 0, 500) : null;
    }
    if (array_key_exists('toestaan', $antwoord)) {
        $velden['toestaan'] = !empty($antwoord['toestaan']) ? 1 : 0;
    }
    if ($vers) {
        $velden['opgehaald'] = date('Y-m-d H:i:s');
    }
    updatesWerkKoppelingBij($pdo, $siteId, $velden);

    // Een Joomla-update telt mee in het aantal.
    $aantal = count($updates) + ($kern !== null && (string) ($kern['binnen'] ?? '') !== '' ? 1 : 0);

    return ['ok' => true, 'aantal' => $aantal, 'fout' => ''];
}

/** Vraagt de status van de plugin op (versie, aan/uit, bezig). */
function updatesPing(PDO $pdo, array $site): array
{
    $siteId    = (int) $site['id'];
    $koppeling = updatesHaalKoppeling($pdo, $siteId);
    if ($koppeling === null) {
        return ['ok' => false, 'fout' => 'Plugin nog niet geïnstalleerd.'];
    }

    $resultaat = updatesPluginVerzoek($site, $koppeling, 'ping', [], 30);
    if (!$resultaat['ok']) {
        updatesWerkKoppelingBij($pdo, $siteId, ['laatste_fout' => mb_substr($resultaat['fout'], 0, 1000)]);
        return ['ok' => false, 'fout' => $resultaat['fout']];
    }

    $a = $resultaat['antwoord'];
    updatesWerkKoppelingBij($pdo, $siteId, [
        'laatste_contact' => date('Y-m-d H:i:s'),
        'laatste_fout'    => null,
        'plugin_versie'   => mb_substr((string) ($a['plugin_versie'] ?? ''), 0, 20),
        'joomla_versie'   => mb_substr((string) ($a['joomla_versie'] ?? ''), 0, 50),
        'toestaan'        => !empty($a['toestaan']) ? 1 : 0,
    ]);

    return ['ok' => true, 'antwoord' => $a];
}

// ----------------------------------------------------------------------
// Een update installeren
// ----------------------------------------------------------------------

/**
 * Installeert één update op één site en legt het resultaat vast.
 *
 * @param string $bron 'handmatig' (één knop), 'alles' (knop "Alle geactiveerde ...") of 'cron'
 */
function updatesInstalleer(PDO $pdo, array $site, int $extensionId, string $runId, string $bron): array
{
    $siteId = (int) $site['id'];

    $stmt = $pdo->prepare("SELECT * FROM updates_beschikbaar WHERE site_id = ? AND extension_id = ?");
    $stmt->execute([$siteId, $extensionId]);
    $update = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$update) {
        return ['resultaat' => 'overgeslagen', 'bericht' => 'Deze update staat niet (meer) in de lijst. Haal de updates opnieuw op.'];
    }

    if (!updatesSiteMagBijwerken($pdo, $site)) {
        return ['resultaat' => 'overgeslagen', 'bericht' => 'Bijwerken van sites van anderen staat uit in de instellingen.'];
    }
    if (updatesIsUitgesloten($pdo, $siteId)) {
        return ['resultaat' => 'overgeslagen', 'bericht' => 'Deze site is uitgesloten van updates via de monitor.'];
    }

    $koppeling = updatesHaalKoppeling($pdo, $siteId);
    if ($koppeling === null) {
        return ['resultaat' => 'overgeslagen', 'bericht' => 'De plugin is op deze site nog niet geïnstalleerd.'];
    }

    // Joomla zelf: een eigen route (zie updatesInstalleerKern()).
    if (updatesIsKern($update)) {
        return updatesInstalleerKern($pdo, $site, 'binnen', (string) $update['nieuwe_versie'], $runId, $bron);
    }

    // Ruim, want grote pakketten kunnen even duren. De plugin maakt de
    // installatie ook af als de monitor eerder ophoudt met wachten.
    $antwoord = updatesPluginVerzoek($site, $koppeling, 'installeer', [
        'extension_id'     => $extensionId,
        'verwachte_versie' => (string) $update['nieuwe_versie'],
        'snapshot'         => haalInstelling($pdo, 'updates_snapshot', '1') === '1',
        'snapshot_max_mb'  => 1000,
    ], 600);

    $controle = '';

    // Kwam het antwoord niet (goed) terug, dan zelf nagaan hoe het afliep.
    $afloop = '';
    if (!$antwoord['ok'] && ($antwoord['timeout'] || !empty($antwoord['herhaald']))) {
        $afloop = updatesControleerAfloop($pdo, $site, $extensionId, (string) $update['nieuwe_versie']);
    }

    if ($afloop === 'geinstalleerd') {
        $resultaat = 'succes';
        $bericht   = 'Bijgewerkt naar ' . $update['nieuwe_versie'] . ' (vastgesteld na controle).';
        $controle  = updatesControleerSite($site);
    } elseif ($afloop === 'niet') {
        $resultaat = 'mislukt';
        $bericht   = 'De update is niet geïnstalleerd. Probeer het opnieuw. (Technisch: ' . $antwoord['fout'] . ')';
        updatesWerkKoppelingBij($pdo, $siteId, ['laatste_fout' => mb_substr($antwoord['fout'], 0, 1000)]);
    } elseif (!$antwoord['ok']) {
        $onzeker   = $antwoord['timeout'] || !empty($antwoord['herhaald']);
        $resultaat = $onzeker ? 'onbekend' : 'mislukt';
        $bericht   = $onzeker
            ? 'De site gaf geen bruikbaar antwoord. Haal de updates straks opnieuw op om te zien of het gelukt is. (Technisch: ' . $antwoord['fout'] . ')'
            : $antwoord['fout'];
        updatesWerkKoppelingBij($pdo, $siteId, ['laatste_fout' => mb_substr($antwoord['fout'], 0, 1000)]);
    } else {
        $a       = $antwoord['antwoord'];
        $status  = (string) ($a['status'] ?? '');
        $bericht = (string) ($a['bericht'] ?? '');

        $overigeMeldingen = [];
        foreach ((array) ($a['berichten'] ?? []) as $m) {
            $tekst = trim((string) ($m['tekst'] ?? ''));
            if ($tekst !== '' && strpos($bericht, $tekst) === false) {
                $overigeMeldingen[] = $tekst;
            }
        }
        if ($overigeMeldingen) {
            $bericht .= "\n" . implode("\n", array_slice($overigeMeldingen, 0, 5));
        }

        if ($status === 'geinstalleerd') {
            $resultaat = 'succes';
            $controle  = updatesControleerSite($site);
        } elseif (in_array($status, ['bezig', 'uitgeschakeld', 'geen_update', 'andere_versie'], true)) {
            $resultaat = 'overgeslagen';
        } else {
            $resultaat = 'mislukt';
        }

        if ($status === 'uitgeschakeld') {
            updatesWerkKoppelingBij($pdo, $siteId, ['toestaan' => 0]);
        }
        updatesWerkKoppelingBij($pdo, $siteId, ['laatste_contact' => date('Y-m-d H:i:s'), 'laatste_fout' => null]);
    }

    $snapshot = $antwoord['ok'] && is_array($antwoord['antwoord']['snapshot'] ?? null) ? $antwoord['antwoord']['snapshot'] : null;

    $naarVersie = $resultaat === 'succes'
        ? (string) ($antwoord['antwoord']['na_versie'] ?? $update['nieuwe_versie'])
        : (string) $update['nieuwe_versie'];

    // "Overgeslagen" omdat er al een update loopt, hoort niet in de
    // geschiedenis: er is niets gebeurd.
    if ($resultaat !== 'overgeslagen' || ($antwoord['antwoord']['status'] ?? '') !== 'bezig') {
        $pdo->prepare("
            INSERT INTO updates_geschiedenis
                (run_id, site_id, domein, extension_id, naam, van_versie, naar_versie, resultaat, bericht, controle, bron,
                 snapshot, snapshot_grootte, tijdstip)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([
            $runId !== '' ? $runId : null,
            $siteId,
            $site['domein'],
            $extensionId,
            $update['naam'],
            $update['huidige_versie'],
            $naarVersie,
            $resultaat,
            mb_substr(trim($bericht), 0, 5000),
            $controle !== '' ? mb_substr($controle, 0, 500) : null,
            $bron,
            $snapshot['pad'] ?? null,
            $snapshot['grootte'] ?? null,
        ]);
    }

    // Lijst van deze site bijwerken (zonder opnieuw te zoeken: Joomla heeft
    // de geïnstalleerde update al uit zijn eigen lijst gehaald).
    if (($resultaat === 'succes' || $resultaat === 'onbekend') && $afloop === '') {
        try {
            updatesHaalOp($pdo, $site, false);
        } catch (Throwable $e) {
            // Niet erg: de volgende keer ophalen werkt het bij.
        }
    }

    return [
        'resultaat' => $resultaat,
        'bericht'   => trim($bericht),
        'controle'  => $controle,
        'van'       => (string) $update['huidige_versie'],
        'naar'      => $naarVersie,
    ];
}

// ----------------------------------------------------------------------
// Joomla zelf bijwerken
// ----------------------------------------------------------------------
//
// De plugin doet het werk met Joomla's eigen onderdelen, in twee stappen
// (zie de plugin): kern_bijwerken (controleren, downloaden met controle van
// de controlesom, uitpakken, kopiëren) en kern_afronden (database bijwerken,
// oude bestanden weg, opruimen), elk in een eigen verzoek. Er wordt vooraf
// GEEN back-up gemaakt: de gebruiker bevestigt dat vóór het starten (zie
// updates.php), of heeft dat gedaan door automatisch bijwerken van Joomla
// aan te zetten.

/**
 * Joomla's eigen controle vooraf, zonder iets te veranderen.
 * 'doel': 'binnen' (huidige hoofdversie) of 'volgende' (nieuwe hoofdversie).
 */
function updatesKernControle(PDO $pdo, array $site, string $doel): array
{
    $koppeling = updatesHaalKoppeling($pdo, (int) $site['id']);
    if ($koppeling === null || empty($koppeling['plugin_versie'])) {
        return ['ok' => false, 'bericht' => 'De plugin staat (nog) niet op deze site.'];
    }
    if (version_compare((string) $koppeling['plugin_versie'], '2.1.0', '<')) {
        return ['ok' => false, 'bericht' => 'Werk eerst de plugin op deze site bij; deze versie kan Joomla zelf nog niet bijwerken.'];
    }

    $r = updatesPluginVerzoek($site, $koppeling, 'kern_controle', ['doel' => $doel === 'volgende' ? 'volgende' : 'binnen'], 300);
    if (!$r['ok']) {
        return ['ok' => false, 'bericht' => $r['fout']];
    }

    return ['ok' => true] + $r['antwoord'];
}

/**
 * Werkt Joomla zelf bij naar $verwachteVersie en legt het vast in de
 * geschiedenis. Geeft hetzelfde terug als updatesInstalleer().
 */
function updatesInstalleerKern(PDO $pdo, array $site, string $doel, string $verwachteVersie, string $runId, string $bron): array
{
    $siteId    = (int) $site['id'];
    $doel      = $doel === 'volgende' ? 'volgende' : 'binnen';
    $koppeling = updatesHaalKoppeling($pdo, $siteId);
    $van       = (string) ($koppeling['joomla_versie'] ?? '');

    $uit = function (string $resultaat, string $bericht, string $naar = '', string $controle = '') use ($pdo, $site, $siteId, $van, $verwachteVersie, $runId, $bron) {
        if ($resultaat !== 'overgeslagen') {
            $pdo->prepare("
                INSERT INTO updates_geschiedenis
                    (run_id, site_id, domein, extension_id, naam, van_versie, naar_versie, resultaat, bericht, controle, bron, tijdstip)
                VALUES (?, ?, ?, ?, 'Joomla', ?, ?, ?, ?, ?, ?, NOW())
            ")->execute([
                $runId !== '' ? $runId : null, $siteId, $site['domein'], UPDATES_KERN_ID,
                $van, $naar !== '' ? $naar : $verwachteVersie, $resultaat, mb_substr($bericht, 0, 5000),
                $controle !== '' ? mb_substr($controle, 0, 500) : null, $bron,
            ]);
        }

        return ['resultaat' => $resultaat, 'bericht' => $bericht, 'controle' => $controle, 'van' => $van, 'naar' => $naar !== '' ? $naar : $verwachteVersie];
    };

    if ($koppeling === null || empty($koppeling['plugin_versie'])) {
        return $uit('overgeslagen', 'De plugin is op deze site nog niet geïnstalleerd.');
    }
    if (version_compare((string) $koppeling['plugin_versie'], '2.1.0', '<')) {
        return $uit('overgeslagen', 'Werk eerst de plugin op deze site bij; deze versie kan Joomla zelf nog niet bijwerken.');
    }
    if (!updatesSiteMagBijwerken($pdo, $site)) {
        return $uit('overgeslagen', 'Bijwerken van sites van anderen staat uit in de instellingen.');
    }
    if (updatesIsUitgesloten($pdo, $siteId)) {
        return $uit('overgeslagen', 'Deze site is uitgesloten van updates via de monitor.');
    }
    if ($doel === 'volgende' && haalInstelling($pdo, 'updates_kern_hoofdversie', '0') !== '1') {
        return $uit('overgeslagen', 'Bijwerken naar een nieuwe hoofdversie staat uit in de instellingen.');
    }

    // Stap 1: controleren, downloaden, uitpakken, kopiëren.
    $stap1 = updatesPluginVerzoek($site, $koppeling, 'kern_bijwerken', ['doel' => $doel, 'verwachte_versie' => $verwachteVersie], 900);

    if ($stap1['ok'] && ($stap1['antwoord']['status'] ?? '') !== 'gekopieerd') {
        $a      = $stap1['antwoord'];
        $status = (string) ($a['status'] ?? '');
        $bericht = (string) ($a['bericht'] ?? 'Niet bijgewerkt.');
        // Nog een eerdere update om af te ronden? Dan dat eerst.
        if ($status !== 'nog_afronden') {
            return $uit(in_array($status, ['bezig', 'geen_update', 'andere_versie', 'uitgeschakeld'], true) ? 'overgeslagen' : 'mislukt', $bericht);
        }
    } elseif (!$stap1['ok']) {
        // Geen bruikbaar antwoord (time-out, verzoek herhaald): de plugin
        // kan nog bezig zijn. Wachten en kijken hoe ver hij is gekomen.
        $status = updatesWachtOpKern($pdo, $site);
        if (($status['fase'] ?? '') !== 'gekopieerd') {
            $nu = (string) ($status['joomla_versie'] ?? '');
            if ($nu !== '' && $verwachteVersie !== '' && version_compare($nu, $verwachteVersie, '>=')) {
                return $uit('succes', 'Joomla bijgewerkt naar ' . $nu . ' (vastgesteld na controle).', $nu, updatesControleerSite($site));
            }
            return $uit($status === null ? 'onbekend' : 'mislukt',
                'Joomla is niet bijgewerkt. (Technisch: ' . $stap1['fout'] . ')');
        }
    }

    // Stap 2: afronden, in een nieuw verzoek (met de nieuwe bestanden).
    $stap2 = updatesPluginVerzoek($site, $koppeling, 'kern_afronden', [], 600);
    if (!$stap2['ok']) {
        $status = updatesWachtOpKern($pdo, $site);
        if ($status !== null && ($status['fase'] ?? '') === '' && version_compare((string) ($status['joomla_versie'] ?? ''), $verwachteVersie, '>=')) {
            $nu = (string) $status['joomla_versie'];
            return $uit('succes', 'Joomla bijgewerkt naar ' . $nu . ' (vastgesteld na controle).', $nu, updatesControleerSite($site));
        }
        updatesWerkKoppelingBij($pdo, $siteId, ['laatste_fout' => mb_substr('Joomla afronden: ' . $stap2['fout'], 0, 1000)]);
        return $uit('onbekend', 'De nieuwe Joomla-bestanden staan op de site, maar het afronden gaf geen bruikbaar antwoord. Probeer het opnieuw (de monitor rondt dan eerst af). (Technisch: ' . $stap2['fout'] . ')');
    }
    $a = $stap2['antwoord'];
    if (empty($a['succes'])) {
        return $uit('mislukt', (string) ($a['bericht'] ?? 'Het afronden is mislukt.'));
    }

    $nu = (string) ($a['na_versie'] ?? '') ?: $verwachteVersie;
    updatesWerkKoppelingBij($pdo, $siteId, ['joomla_versie' => mb_substr($nu, 0, 50), 'laatste_contact' => date('Y-m-d H:i:s'), 'laatste_fout' => null]);
    try {
        updatesHaalOp($pdo, $site, false);
    } catch (Throwable $e) {
        // Niet erg: de volgende keer ophalen werkt het bij.
    }

    return $uit('succes', 'Joomla bijgewerkt naar ' . $nu . '.', $nu, updatesControleerSite($site));
}

/**
 * Wacht (hooguit een paar minuten) tot de plugin klaar is en geeft dan de
 * stand: ['fase' => ''|'kopieren'|'gekopieerd', 'joomla_versie' => ...],
 * of null als de site niet antwoordt.
 */
function updatesWachtOpKern(PDO $pdo, array $site): ?array
{
    for ($i = 0; $i < 36; $i++) {
        $ping = updatesPing($pdo, $site);
        if (!$ping['ok']) {
            return null;
        }
        if (empty($ping['antwoord']['bezig'])) {
            return [
                'fase'          => (string) ($ping['antwoord']['kern_status']['fase'] ?? ''),
                'joomla_versie' => (string) ($ping['antwoord']['joomla_versie'] ?? ''),
            ];
        }
        sleep(5);
    }

    return null;
}

/**
 * Controle na een update: werkt de voorkant van de site nog? Geeft een lege
 * string terug als alles in orde lijkt, anders een korte omschrijving.
 */
function updatesControleerSite(array $site): string
{
    $url = bepaalSiteUrl($site) . '/?nc=' . bin2hex(random_bytes(4));

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; CompactUpdater)',
        CURLOPT_HTTPHEADER     => ['Cache-Control: no-cache'],
    ]);
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        return '⚠️ De voorkant van de site reageerde niet na de update.';
    }
    if ($http >= 500) {
        return "⚠️ De voorkant van de site gaf na de update een serverfout (HTTP $http).";
    }
    if (preg_match('/(Fatal error|Parse error|Uncaught (Error|Exception|TypeError))\b/i', (string) $body)) {
        return '⚠️ Op de voorkant van de site staat na de update een PHP-foutmelding.';
    }

    return '';
}

/** Zet een versie op "overslaan" (of haalt dat weer weg met een lege versie). */
function updatesSlaVersieOver(PDO $pdo, int $siteId, int $extensionId, string $versie): void
{
    $pdo->prepare("UPDATE updates_beschikbaar SET overgeslagen_versie = ? WHERE site_id = ? AND extension_id = ?")
        ->execute([$versie !== '' ? $versie : null, $siteId, $extensionId]);
}

/**
 * Alle updates die met "Alle geactiveerde updates uitvoeren" mee zouden gaan,
 * als lijst van ['site_id', 'extension_id', ...].
 */
function updatesTakenVoorAlles(PDO $pdo): array
{
    $beleid      = updatesHaalBeleid($pdo);
    $koppelingen = [];
    foreach ($pdo->query("SELECT * FROM updates_koppeling") as $k) {
        $koppelingen[(int) $k['site_id']] = $k;
    }

    $taken = [];
    $rijen = $pdo->query("
        SELECT u.*, s.domein, s.categorie, s.id AS sid
        FROM updates_beschikbaar u
        JOIN sites s ON s.id = u.site_id
        ORDER BY s.domein, u.naam
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rijen as $rij) {
        $site = ['id' => (int) $rij['sid'], 'domein' => $rij['domein'], 'categorie' => $rij['categorie']];
        [$mag] = updatesBeslissing($pdo, $site, $rij, $beleid, $koppelingen[(int) $rij['site_id']] ?? null);
        if ($mag) {
            $taken[] = [
                'site_id'      => (int) $rij['site_id'],
                'extension_id' => (int) $rij['extension_id'],
                'domein'       => $rij['domein'],
                'naam'         => $rij['naam'],
                'van'          => $rij['huidige_versie'],
                'naar'         => $rij['nieuwe_versie'],
                'kern'         => updatesIsKern($rij),
            ];
        }
    }

    return $taken;
}

// ----------------------------------------------------------------------
// De plugin op een site zetten (of bijwerken) via FTP/SFTP
// ----------------------------------------------------------------------

/**
 * Opent een FTP- of SFTP-verbinding met de gegevens van de site.
 * @return array ['type' => 'ftp'|'sftp', 'conn' => ..., 'basis' => '/pad'] of ['fout' => '...']
 */
function updatesOpenBestandsverbinding(array $site): array
{
    if (empty($site['ftp_host'])) {
        return ['fout' => 'Geen FTP-gegevens ingesteld voor deze site.'];
    }

    $gebruiker  = (string) ($site['ftp_gebruikersnaam'] ?? '');
    $wachtwoord = ontsleutelWaarde($site['ftp_wachtwoord'] ?? '');
    $pad        = trim((string) ($site['ftp_pad'] ?? '/'), '/');
    $basis      = $pad !== '' ? '/' . $pad : '';

    if (($site['ftp_protocol'] ?? 'ftp') === 'sftp') {
        require_once MONITOR_MAP . '/sftp_functies.php';
        $poort = !empty($site['ftp_poort']) ? (int) $site['ftp_poort'] : 22;
        [$sftp, $fout] = sftpVerbind($site['ftp_host'], $poort, $gebruiker, $wachtwoord);
        if ($sftp === null) {
            return ['fout' => $fout];
        }
        return ['type' => 'sftp', 'conn' => $sftp, 'basis' => $basis, 'site' => $site];
    }

    $poort = !empty($site['ftp_poort']) ? (int) $site['ftp_poort'] : 21;
    $conn  = !empty($site['ftp_ssl']) ? @ftp_ssl_connect($site['ftp_host'], $poort, 15) : @ftp_connect($site['ftp_host'], $poort, 15);
    if ($conn === false) {
        return ['fout' => 'Kon geen FTP-verbinding maken met ' . $site['ftp_host'] . ':' . $poort . '.'];
    }
    if (!@ftp_login($conn, $gebruiker, $wachtwoord)) {
        ftp_close($conn);
        return ['fout' => 'FTP-inloggen mislukt (controleer gebruikersnaam/wachtwoord).'];
    }
    ftp_pasv($conn, true);

    return ['type' => 'ftp', 'conn' => $conn, 'basis' => $basis, 'site' => $site];
}

function updatesSluitBestandsverbinding(array $verbinding): void
{
    if (($verbinding['type'] ?? '') === 'ftp') {
        @ftp_close($verbinding['conn']);
    } elseif (($verbinding['type'] ?? '') === 'sftp') {
        $verbinding['conn']->disconnect();
    }
}

/** Zet een bestand neer (pad relatief aan de Joomla-map van de site). Maakt mappen aan waar nodig. */
function updatesSchrijfBestand(array $verbinding, string $relatiefPad, string $inhoud): bool
{
    $volledig = $verbinding['basis'] . '/' . ltrim($relatiefPad, '/');

    if ($verbinding['type'] === 'sftp') {
        $map = dirname($volledig);
        if (!$verbinding['conn']->file_exists($map)) {
            $verbinding['conn']->mkdir($map, -1, true);
        }
        return (bool) $verbinding['conn']->put($volledig, $inhoud);
    }

    $conn = $verbinding['conn'];
    $map  = '';
    foreach (array_filter(explode('/', dirname($volledig)), 'strlen') as $deel) {
        $map .= '/' . $deel;
        if (!@ftp_chdir($conn, $map)) {
            @ftp_mkdir($conn, $map);
        }
    }
    @ftp_chdir($conn, '/');

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $inhoud);
    rewind($stream);
    $gelukt = @ftp_fput($conn, $volledig, $stream, FTP_BINARY);
    fclose($stream);

    if ($gelukt) {
        return true;
    }

    // Terugval via curl: lost het bekende probleem op van hosts die bij PASV
    // een verkeerd IP-adres teruggeven.
    $site = $verbinding['site'];
    $ch   = curl_init();
    $tmp  = fopen('php://temp', 'r+');
    fwrite($tmp, $inhoud);
    rewind($tmp);
    curl_setopt_array($ch, [
        CURLOPT_URL                => 'ftp://' . $site['ftp_host'] . ':' . (!empty($site['ftp_poort']) ? (int) $site['ftp_poort'] : 21) . str_replace('%2F', '/', rawurlencode($volledig)),
        CURLOPT_USERPWD            => ($site['ftp_gebruikersnaam'] ?? '') . ':' . ontsleutelWaarde($site['ftp_wachtwoord'] ?? ''),
        CURLOPT_UPLOAD             => true,
        CURLOPT_INFILE             => $tmp,
        CURLOPT_INFILESIZE         => strlen($inhoud),
        CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR,
        CURLOPT_FTP_SKIP_PASV_IP   => true,
        CURLOPT_TIMEOUT            => 60,
    ]);
    if (!empty($site['ftp_ssl'])) {
        curl_setopt($ch, CURLOPT_USE_SSL, CURLUSESSL_ALL);
    }
    $ok = curl_exec($ch) !== false;
    curl_close($ch);
    fclose($tmp);

    return $ok;
}

function updatesVerwijderBestand(array $verbinding, string $relatiefPad): void
{
    $volledig = $verbinding['basis'] . '/' . ltrim($relatiefPad, '/');
    if ($verbinding['type'] === 'sftp') {
        if ($verbinding['conn']->file_exists($volledig)) {
            @$verbinding['conn']->delete($volledig);
        }
    } else {
        @ftp_delete($verbinding['conn'], $volledig);
    }
}

/** Alle bestanden van de meegeleverde plugin: [relatief pad binnen de plugin => inhoud]. */
function updatesPluginBestanden(): array
{
    $bestanden = [];
    $basis     = realpath(UPDATES_PLUGIN_MAP);
    $iterator  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($basis, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $bestand) {
        if (!$bestand->isFile()) {
            continue;
        }
        $relatief = str_replace('\\', '/', substr($bestand->getPathname(), strlen($basis) + 1));
        // Sleutelbestanden horen bij een site, niet bij het pakket.
        if (strpos($relatief, 'sleutels/') === 0 && $relatief !== 'sleutels/index.html') {
            continue;
        }
        $bestanden[$relatief] = (string) file_get_contents($bestand->getPathname());
    }
    ksort($bestanden);

    return $bestanden;
}

/** Inhoud van het sleutelbestand van deze monitor voor één site. */
function updatesSleutelbestandInhoud(string $sleutelId, string $sleutel, string $label): string
{
    return "<?php\n"
        . "// Sleutel van een Websitesmonitor voor de plugin \"Compact Updater\".\n"
        . "// Verwijder dit bestand om deze monitor de toegang te ontnemen.\n"
        . "\\defined('_JEXEC') or die;\n\n"
        . 'return ' . var_export([
            'id'       => $sleutelId,
            'sleutel'  => $sleutel,
            'label'    => $label,
            'aangemaakt' => date('Y-m-d H:i:s'),
        ], true) . ";\n";
}

/**
 * Inhoud van het eenmalige koppelscript. Het script meldt de plugin aan in
 * #__extensions van de site (of werkt het manifest bij) en verwijdert
 * zichzelf. Het bevat geen geheimen: alleen de hash van een eenmalige code,
 * en het werkt maar 15 minuten.
 */
function updatesScriptBasis(string $omschrijving, string $tokenHash, int $verlooptOp): string
{
    $basis = <<<'PHP'
<?php
// %OMSCHRIJVING%
// Werkt alleen met de juiste eenmalige code, en alleen tot het tijdstip
// hieronder. Verwijdert zichzelf na gebruik.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex');
@set_time_limit(0);
@ignore_user_abort(true);

// Sommige hostingpakketten tonen PHP-meldingen in de uitvoer
// (display_errors aan). Dan staat er tekst vóór het antwoord en kan de
// monitor het niet lezen. Daarom: niets tonen, meldingen alleen
// verzamelen en netjes in het antwoord meegeven.
@ini_set('display_errors', '0');
$PHP_MELDINGEN = array();
set_error_handler(function ($nummer, $tekst, $bestand, $regel) {
    global $PHP_MELDINGEN;
    if (count($PHP_MELDINGEN) < 5) {
        $PHP_MELDINGEN[] = $tekst . ' (regel ' . $regel . ')';
    }
    return true;
});
ob_start();

$TOKEN_HASH = '%TOKEN_HASH%';
$VERLOOPT   = %VERLOOPT%;

function scriptAntwoord(array $antwoord, $opruimen = true)
{
    global $PHP_MELDINGEN;
    // Alles wat er onbedoeld al werd uitgevoerd, weggooien.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if ($PHP_MELDINGEN) {
        $antwoord['php_meldingen'] = $PHP_MELDINGEN;
    }
    echo json_encode($antwoord);
    if ($opruimen) {
        @unlink(__FILE__);
    }
    exit;
}

if (time() > $VERLOOPT) {
    scriptAntwoord(array('succes' => false, 'fout' => 'Dit script is verlopen.'));
}

$token = isset($_POST['token']) ? (string) $_POST['token'] : '';
if ($token === '' || !hash_equals($TOKEN_HASH, hash('sha256', $token))) {
    http_response_code(403);
    scriptAntwoord(array('succes' => false, 'fout' => 'Ongeldige code.'), false);
}

$root = __DIR__;
if (!is_file($root . '/configuration.php')) {
    scriptAntwoord(array('succes' => false, 'fout' => 'configuration.php niet gevonden naast dit script.'));
}

if (!defined('_JEXEC')) {
    define('_JEXEC', 1);
}
require_once $root . '/configuration.php';
if (!class_exists('JConfig')) {
    scriptAntwoord(array('succes' => false, 'fout' => 'configuration.php kon niet gelezen worden.'));
}
$c = new JConfig();

$dbtype = strtolower((string) $c->dbtype);
if (!in_array($dbtype, array('mysqli', 'mysql', 'pdomysql'), true)) {
    scriptAntwoord(array('succes' => false, 'fout' => 'Databasetype ' . $dbtype . ' wordt (nog) niet ondersteund.'));
}

$host = (string) $c->host;
$dsn  = 'mysql:dbname=' . $c->db . ';charset=utf8mb4';
if (preg_match('/^(.+):(\/.+)$/', $host, $m)) {
    $dsn .= ';host=' . $m[1] . ';unix_socket=' . $m[2];
} elseif (preg_match('/^(.+):(\d+)$/', $host, $m)) {
    $dsn .= ';host=' . $m[1] . ';port=' . $m[2];
} else {
    $dsn .= ';host=' . $host;
}

try {
    $pdo = new PDO($dsn, (string) $c->user, (string) $c->password, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
} catch (Exception $e) {
    scriptAntwoord(array('succes' => false, 'fout' => 'Kon geen verbinding maken met de database: ' . $e->getMessage()));
}
$prefix = (string) $c->dbprefix;

function tabelBestaat(PDO $pdo, $naam)
{
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute(array(str_replace(array('_', '%'), array('\_', '\%'), $naam)));
    return (bool) $stmt->fetchColumn();
}

// Caches legen, zodat Joomla de wijzigingen direct ziet.
function leegJoomlaCaches($root, $c)
{
    $cachePad = isset($c->cache_path) && $c->cache_path !== '' ? rtrim((string) $c->cache_path, '/\\') : $root . '/cache';
    foreach (array($cachePad, $root . '/administrator/cache') as $map) {
        foreach (array('com_plugins', '_system', 'com_modules', 'com_templates') as $groep) {
            if (is_dir($map . '/' . $groep)) {
                foreach (glob($map . '/' . $groep . '/*') ?: array() as $bestand) {
                    if (is_file($bestand)) {
                        @unlink($bestand);
                    }
                }
            }
        }
        // Joomla bouwt zijn namespace-overzicht vanzelf opnieuw op.
        if (is_file($map . '/autoload_psr4.php')) {
            @unlink($map . '/autoload_psr4.php');
        }
    }
}

PHP;

    return str_replace(
        ['%OMSCHRIJVING%', '%TOKEN_HASH%', '%VERLOOPT%'],
        [$omschrijving, $tokenHash, (string) $verlooptOp],
        $basis
    );
}

/**
 * Inhoud van het eenmalige koppelscript. Het script meldt de plugin aan in
 * #__extensions van de site (of werkt het manifest bij) en verwijdert
 * zichzelf. Het bevat geen geheimen: alleen de hash van een eenmalige code,
 * en het werkt maar 15 minuten.
 *
 * Met $adminTools zet het script het IP-adres van de monitor (zoals de site
 * het ziet) bij Admin Tools op de lijst "nooit blokkeren" en haalt het een
 * eventuele automatische blokkade van dat adres weg. Dit script draait
 * buiten Joomla, dus ook als de firewall de monitor al blokkeert.
 */
function updatesKoppelscriptInhoud(string $tokenHash, int $verlooptOp, bool $adminTools = false): string
{
    $sjabloon = <<<'PHP'
$ADMINTOOLS = %ADMINTOOLS%;

$manifestBestand = $root . '/plugins/system/compactupdater/compactupdater.xml';
if (!is_file($manifestBestand)) {
    scriptAntwoord(array('succes' => false, 'fout' => 'De pluginbestanden staan niet op de verwachte plek.'));
}

$tabel = $prefix . 'extensions';

$xml = @simplexml_load_file($manifestBestand);
if (!$xml) {
    scriptAntwoord(array('succes' => false, 'fout' => 'Het manifest van de plugin is onleesbaar.'));
}
$manifest = array(
    'name'         => (string) $xml->name,
    'type'         => 'plugin',
    'creationDate' => (string) $xml->creationDate,
    'author'       => (string) $xml->author,
    'copyright'    => (string) $xml->copyright,
    'authorEmail'  => (string) $xml->authorEmail,
    'authorUrl'    => (string) $xml->authorUrl,
    'version'      => (string) $xml->version,
    'description'  => (string) $xml->description,
    'group'        => '',
    'changelogurl' => '',
    'namespace'    => (string) $xml->namespace,
    'filename'     => 'compactupdater',
);
$manifestJson = json_encode($manifest);

try {
    $stmt = $pdo->prepare("SELECT extension_id, enabled FROM `$tabel` WHERE type = 'plugin' AND element = 'compactupdater' AND folder = 'system' AND client_id = 0");
    $stmt->execute();
    $bestaand = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($bestaand) {
        $pdo->prepare("UPDATE `$tabel` SET manifest_cache = ?, name = ? WHERE extension_id = ?")
            ->execute(array($manifestJson, $manifest['name'], (int) $bestaand['extension_id']));
        $extensionId = (int) $bestaand['extension_id'];
        $nieuw       = false;
        $enabled     = (int) $bestaand['enabled'];
    } else {
        $waarden = array(
            'package_id'     => 0,
            'name'           => $manifest['name'],
            'type'           => 'plugin',
            'element'        => 'compactupdater',
            'folder'         => 'system',
            'client_id'      => 0,
            'enabled'        => 1,
            'access'         => 1,
            'protected'      => 0,
            'locked'         => 0,
            'manifest_cache' => $manifestJson,
            'params'         => '{"toestaan":"1"}',
            'custom_data'    => '',
            'ordering'       => 0,
            'state'          => 0,
        );

        // Alleen kolommen die in deze Joomla-versie bestaan; verplichte
        // kolommen die we niet kennen krijgen een lege waarde.
        $kolommen = array();
        foreach ($pdo->query("SHOW COLUMNS FROM `$tabel`") as $kolom) {
            $kolommen[$kolom['Field']] = $kolom;
        }
        $invoer = array();
        foreach ($kolommen as $naam => $kolom) {
            if ($naam === 'extension_id') {
                continue;
            }
            if (array_key_exists($naam, $waarden)) {
                $invoer[$naam] = $waarden[$naam];
            } elseif ($kolom['Null'] === 'NO' && $kolom['Default'] === null) {
                $type = strtolower((string) $kolom['Type']);
                if (preg_match('/int|decimal|float|double/', $type)) {
                    $invoer[$naam] = 0;
                } elseif (preg_match('/date|time/', $type)) {
                    $invoer[$naam] = date('Y-m-d H:i:s');
                } else {
                    $invoer[$naam] = '';
                }
            }
        }
        $sql = "INSERT INTO `$tabel` (`" . implode('`, `', array_keys($invoer)) . "`) VALUES ("
            . implode(', ', array_fill(0, count($invoer), '?')) . ")";
        $pdo->prepare($sql)->execute(array_values($invoer));
        $extensionId = (int) $pdo->lastInsertId();
        $nieuw       = true;
        $enabled     = 1;
    }
} catch (Exception $e) {
    scriptAntwoord(array('succes' => false, 'fout' => 'Aanmelden in de database mislukt: ' . $e->getMessage()));
}

// De updatesites uit het manifest (<updateservers>) aanmelden, zoals de
// installer van Joomla dat ook doet. Daardoor zoekt Joomla zelf naar nieuwe
// versies van de plugin, en ziet de monitor bij elke scan welke versie de
// nieuwste is. Een bestaande updatesite met dezelfde URL wordt hergebruikt;
// een koppeling van de plugin met een andere (oude) URL wordt opgeruimd.
$updatesiteInfo = array('aangemeld' => 0, 'fout' => '');
try {
    $locaties = array();
    if (isset($xml->updateservers->server)) {
        foreach ($xml->updateservers->server as $server) {
            $url = trim((string) $server);
            if ($url !== '') {
                $locaties[] = array(
                    'name'     => trim((string) $server['name']) !== '' ? trim((string) $server['name']) : $manifest['name'],
                    'type'     => trim((string) $server['type']) !== '' ? trim((string) $server['type']) : 'extension',
                    'location' => $url,
                );
            }
        }
    }

    $usTabel  = $prefix . 'update_sites';
    $useTabel = $prefix . 'update_sites_extensions';
    if ($locaties && tabelBestaat($pdo, $usTabel) && tabelBestaat($pdo, $useTabel)) {
        $usKolommen = array();
        foreach ($pdo->query("SHOW COLUMNS FROM `$usTabel`") as $kolom) {
            $usKolommen[$kolom['Field']] = $kolom;
        }

        $gewenst = array();
        foreach ($locaties as $locatie) {
            $stmt = $pdo->prepare("SELECT update_site_id FROM `$usTabel` WHERE location = ? ORDER BY update_site_id LIMIT 1");
            $stmt->execute(array($locatie['location']));
            $siteId = $stmt->fetchColumn();

            if ($siteId === false) {
                $waarden = array(
                    'name'                 => $locatie['name'],
                    'type'                 => $locatie['type'],
                    'location'             => $locatie['location'],
                    'enabled'              => 1,
                    'last_check_timestamp' => 0,
                    'extra_query'          => '',
                );
                $invoer = array();
                foreach ($usKolommen as $naam => $kolom) {
                    if ($naam === 'update_site_id') {
                        continue;
                    }
                    if (array_key_exists($naam, $waarden)) {
                        $invoer[$naam] = $waarden[$naam];
                    } elseif ($kolom['Null'] === 'NO' && $kolom['Default'] === null) {
                        $type = strtolower((string) $kolom['Type']);
                        if (preg_match('/int|decimal|float|double/', $type)) {
                            $invoer[$naam] = 0;
                        } elseif (preg_match('/date|time/', $type)) {
                            $invoer[$naam] = date('Y-m-d H:i:s');
                        } else {
                            $invoer[$naam] = '';
                        }
                    }
                }
                $pdo->prepare("INSERT INTO `$usTabel` (`" . implode('`, `', array_keys($invoer)) . "`) VALUES ("
                    . implode(', ', array_fill(0, count($invoer), '?')) . ")")->execute(array_values($invoer));
                $siteId = (int) $pdo->lastInsertId();
            } else {
                $siteId = (int) $siteId;
                $pdo->prepare("UPDATE `$usTabel` SET name = ?, type = ? WHERE update_site_id = ?")
                    ->execute(array($locatie['name'], $locatie['type'], $siteId));
            }
            $gewenst[] = $siteId;

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$useTabel` WHERE update_site_id = ? AND extension_id = ?");
            $stmt->execute(array($siteId, $extensionId));
            if ((int) $stmt->fetchColumn() === 0) {
                $pdo->prepare("INSERT INTO `$useTabel` (update_site_id, extension_id) VALUES (?, ?)")
                    ->execute(array($siteId, $extensionId));
            }
            $updatesiteInfo['aangemeld']++;
        }

        // Koppelingen van de plugin met andere updatesites opruimen, en die
        // updatesites zelf als niets anders ze nog gebruikt.
        $stmt = $pdo->prepare("SELECT update_site_id FROM `$useTabel` WHERE extension_id = ?");
        $stmt->execute(array($extensionId));
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $oudId) {
            $oudId = (int) $oudId;
            if (in_array($oudId, $gewenst, true)) {
                continue;
            }
            $pdo->prepare("DELETE FROM `$useTabel` WHERE update_site_id = ? AND extension_id = ?")
                ->execute(array($oudId, $extensionId));
            $teller = $pdo->prepare("SELECT COUNT(*) FROM `$useTabel` WHERE update_site_id = ?");
            $teller->execute(array($oudId));
            if ((int) $teller->fetchColumn() === 0) {
                $pdo->prepare("DELETE FROM `$usTabel` WHERE update_site_id = ?")->execute(array($oudId));
                if (tabelBestaat($pdo, $prefix . 'updates')) {
                    $pdo->prepare("DELETE FROM `{$prefix}updates` WHERE update_site_id = ?")->execute(array($oudId));
                }
            }
        }
    }
} catch (Exception $e) {
    // Geen reden om de koppeling te laten mislukken: de plugin werkt ook
    // zonder updatesite, alleen ziet de scan dan geen nieuwste versie.
    $updatesiteInfo['fout'] = $e->getMessage();
}

leegJoomlaCaches($root, $c);

// Admin Tools: het IP-adres van de monitor op "nooit blokkeren" zetten.
$adminToolsInfo = null;
if ($ADMINTOOLS && tabelBestaat($pdo, $prefix . 'admintools_storage')) {
    $ip = '';
    foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR') as $kop) {
        if (!empty($_SERVER[$kop])) {
            $kandidaat = trim(current(explode(',', (string) $_SERVER[$kop])));
            if (filter_var($kandidaat, FILTER_VALIDATE_IP)) {
                $ip = $kandidaat;
                break;
            }
        }
    }
    $adminToolsInfo = array('ip' => $ip, 'toegevoegd' => false, 'al_aanwezig' => false, 'hersteld' => false, 'gedeblokkeerd' => 0, 'fout' => '');
    try {
        $stmt = $pdo->prepare("SELECT `value` FROM `{$prefix}admintools_storage` WHERE `key` = 'cparams'");
        $stmt->execute();
        $waarde = $stmt->fetchColumn();
        $config = $waarde !== false ? json_decode((string) $waarde, true) : null;
        if ($ip === '') {
            $adminToolsInfo['fout'] = 'IP-adres van de monitor onbekend.';
        } elseif (!is_array($config)) {
            $adminToolsInfo['fout'] = 'Geen firewall-instellingen van Admin Tools gevonden.';
        } else {
            // Admin Tools bewaart deze lijst als tekst (komma's of regels)
            // of, bij sommige versies, als lijst (array). Beide kunnen.
            $huidig  = isset($config['neverblockips']) ? $config['neverblockips'] : '';
            $alsLijst = is_array($huidig);
            if ($alsLijst) {
                $items = array();
                foreach ($huidig as $item) {
                    if (is_scalar($item) && trim((string) $item) !== '') {
                        $items[] = trim((string) $item);
                    }
                }
                $scheiding = "\n";
            } else {
                $tekst     = (string) $huidig;
                $items     = preg_split('/[\s,]+/', $tekst, -1, PREG_SPLIT_NO_EMPTY);
                $scheiding = strpos($tekst, "\n") !== false ? "\n" : ', ';
            }
            // Een eerdere versie van dit script kon hier per ongeluk het
            // woord "Array" neerzetten; dat is geen IP-adres en gaat eruit.
            $schoon = array_values(array_filter($items, function ($item) { return $item !== 'Array'; }));
            $adminToolsInfo['hersteld'] = count($schoon) !== count($items);
            $items = $schoon;
            if (in_array($ip, $items, true)) {
                $adminToolsInfo['al_aanwezig'] = true;
            } else {
                $items[] = $ip;
                $adminToolsInfo['toegevoegd'] = true;
            }
            if ($adminToolsInfo['toegevoegd'] || $adminToolsInfo['hersteld']) {
                // Stond er "Array", dan bewaarde Admin Tools de lijst
                // oorspronkelijk als array: zo zetten we hem ook terug.
                $config['neverblockips'] = $alsLijst || $adminToolsInfo['hersteld'] ? $items : implode($scheiding, $items);
                $pdo->prepare("UPDATE `{$prefix}admintools_storage` SET `value` = ? WHERE `key` = 'cparams'")
                    ->execute(array(json_encode($config)));
            }
        }
        if ($ip !== '' && tabelBestaat($pdo, $prefix . 'admintools_ipautoban')) {
            $stmt = $pdo->prepare("DELETE FROM `{$prefix}admintools_ipautoban` WHERE `ip` = ?");
            $stmt->execute(array($ip));
            $adminToolsInfo['gedeblokkeerd'] = $stmt->rowCount();
        }
    } catch (Exception $e) {
        $adminToolsInfo['fout'] = $e->getMessage();
    }
}

scriptAntwoord(array(
    'succes'       => true,
    'extension_id' => $extensionId,
    'nieuw'        => $nieuw,
    'enabled'      => $enabled,
    'versie'       => $manifest['version'],
    'admintools'   => $adminToolsInfo,
    'updatesite'   => $updatesiteInfo,
));
PHP;

    return updatesScriptBasis('Eenmalig koppelscript van de Websitesmonitor: meldt de plugin "Compact Updater" aan in Joomla.', $tokenHash, $verlooptOp)
        . str_replace('%ADMINTOOLS%', $adminTools ? 'true' : 'false', $sjabloon);
}

/** Bestaat een bestand (pad relatief aan de Joomla-map)? */
function updatesBestandBestaat(array $verbinding, string $relatiefPad): bool
{
    $volledig = $verbinding['basis'] . '/' . ltrim($relatiefPad, '/');
    if ($verbinding['type'] === 'sftp') {
        return (bool) $verbinding['conn']->file_exists($volledig);
    }

    return @ftp_size($verbinding['conn'], $volledig) >= 0;
}

function updatesHernoemBestand(array $verbinding, string $van, string $naar): bool
{
    $van  = $verbinding['basis'] . '/' . ltrim($van, '/');
    $naar = $verbinding['basis'] . '/' . ltrim($naar, '/');
    if ($verbinding['type'] === 'sftp') {
        return (bool) $verbinding['conn']->rename($van, $naar);
    }

    return @ftp_rename($verbinding['conn'], $van, $naar);
}

/** Roept een eenmalig script op de site aan met de code. */
function updatesRoepScriptAan(array $site, string $bestandsnaam, string $token, int $timeout): array
{
    $ch = curl_init(bepaalSiteUrl($site, $bestandsnaam));
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['token' => $token]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_POSTREDIR      => 7,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'CompactUpdater',
    ]);
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['body' => (string) $body, 'http' => $http, 'json' => updatesLeesJson((string) $body)];
}

/**
 * Zet een eenmalig script (koppel- of herstelscript) op de site, roept het
 * aan en ruimt het weer op.
 *
 * Eerst onder een eigen, willekeurige naam. Weigert de site dat (HTTP 403,
 * typisch: de .htaccess van Admin Tools staat alleen bekende PHP-bestanden
 * toe), dan nog één keer onder de bestandsnaam van het scanscript, die op
 * zo'n site al als uitzondering is toegestaan. Het scanscript wordt daarvoor
 * even opzij gezet en daarna teruggezet (of, als dat niet lukt, opnieuw
 * aangemaakt), zodat het scannen gewoon blijft werken.
 *
 * @return array ['body' => string, 'http' => int, 'fout' => string, 'via_scanscript' => bool]
 */
function updatesVoerEenmaligScriptUit(PDO $pdo, array $site, string $inhoud, string $voorvoegsel, string $token, int $timeout): array
{
    $verbinding = updatesOpenBestandsverbinding($site);
    if (isset($verbinding['fout'])) {
        return ['body' => '', 'http' => 0, 'fout' => $verbinding['fout'], 'via_scanscript' => false];
    }

    $naam = $voorvoegsel . bin2hex(random_bytes(8)) . '.php';
    if (!updatesSchrijfBestand($verbinding, $naam, $inhoud)) {
        updatesSluitBestandsverbinding($verbinding);
        return ['body' => '', 'http' => 0, 'fout' => 'Het script kon niet op de site worden geplaatst (FTP-pad en schrijfrechten).', 'via_scanscript' => false];
    }
    updatesSluitBestandsverbinding($verbinding);

    $r = updatesRoepScriptAan($site, $naam, $token, $timeout);

    // Opnieuw verbinden om op te ruimen: een lange taak kan de FTP-verbinding laten verlopen.
    $verbinding = updatesOpenBestandsverbinding($site);
    if (!isset($verbinding['fout'])) {
        updatesVerwijderBestand($verbinding, $naam);
    }

    $geweigerd = !is_array($r['json']) && in_array($r['http'], [403, 406], true);
    if (!$geweigerd || isset($verbinding['fout'])) {
        if (!isset($verbinding['fout'])) {
            updatesSluitBestandsverbinding($verbinding);
        }
        return [
            'body'           => $r['body'],
            'http'           => $r['http'],
            'fout'           => is_array($r['json']) ? '' : updatesLeesbareHttpFout($r['http'], $r['body']),
            'via_scanscript' => false,
        ];
    }

    // Tweede poging: onder de (toegestane) naam van het scanscript.
    $scanNaam = bepaalScanBestandsnaam($site);
    $bewaard  = 'compactupdater_bewaard_' . bin2hex(random_bytes(8)) . '.php';
    $scanWasEr = updatesBestandBestaat($verbinding, $scanNaam);

    if ($scanWasEr && !updatesHernoemBestand($verbinding, $scanNaam, $bewaard)) {
        updatesSluitBestandsverbinding($verbinding);
        return ['body' => $r['body'], 'http' => $r['http'], 'fout' => updatesLeesbareHttpFout($r['http'], $r['body']), 'via_scanscript' => false];
    }

    $tweede = null;
    if (updatesSchrijfBestand($verbinding, $scanNaam, $inhoud)) {
        updatesSluitBestandsverbinding($verbinding);
        $tweede     = updatesRoepScriptAan($site, $scanNaam, $token, $timeout);
        $verbinding = updatesOpenBestandsverbinding($site);
    }

    // Het scanscript hoe dan ook terugzetten.
    $scanTerug = false;
    if (!isset($verbinding['fout'])) {
        updatesVerwijderBestand($verbinding, $scanNaam);
        // Stond er geen scanscript, dan valt er niets terug te zetten.
        $scanTerug = !$scanWasEr || updatesHernoemBestand($verbinding, $bewaard, $scanNaam);
        if (!$scanTerug) {
            require_once MONITOR_MAP . '/genereer_scanscript_functies.php';
            $scanTerug = updatesSchrijfBestand($verbinding, $scanNaam, genereerScanScriptInhoud($pdo, $site));
            updatesVerwijderBestand($verbinding, $bewaard);
        }
        updatesSluitBestandsverbinding($verbinding);
    }

    $waarschuwing = $scanTerug ? '' : ' Let op: het scanscript kon niet worden teruggezet; verstuur het opnieuw via Site-instellingen.';

    if ($tweede === null) {
        return ['body' => '', 'http' => 0, 'fout' => 'Het script kon niet onder de naam van het scanscript worden geplaatst.' . $waarschuwing, 'via_scanscript' => true];
    }
    if (!is_array($tweede['json'])) {
        return [
            'body'           => $tweede['body'],
            'http'           => $tweede['http'],
            // Eerst geweigerd onder de eigen naam, daarna onder die van het
            // scanscript: alleen de laatste reden telt nog.
            'fout'           => updatesLeesbareHttpFout($tweede['http'], $tweede['body']) . $waarschuwing,
            'via_scanscript' => true,
        ];
    }

    return ['body' => $tweede['body'], 'http' => $tweede['http'], 'fout' => '', 'waarschuwing' => trim($waarschuwing), 'via_scanscript' => true];
}

/**
 * Zet de plugin op de site (of werkt hem bij), meldt hem aan en controleert
 * of hij antwoordt. Geeft ['ok' => bool, 'bericht' => string] terug.
 */
function updatesRolPluginUit(PDO $pdo, array $site): array
{
    $siteId = (int) $site['id'];
    $domein = (string) $site['domein'];

    if (updatesIsUitgesloten($pdo, $siteId)) {
        return ['ok' => false, 'bericht' => 'Deze site is uitgesloten van updates via de monitor. Laat haar eerst weer toe.'];
    }

    $koppeling = updatesHaalKoppeling($pdo, $siteId);
    if ($koppeling === null) {
        $sleutelId = bin2hex(random_bytes(16));
        $sleutel   = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO updates_koppeling (site_id, sleutel_id, sleutel) VALUES (?, ?, ?)")
            ->execute([$siteId, $sleutelId, versleutelWaarde($sleutel)]);
        $koppeling = updatesHaalKoppeling($pdo, $siteId);
    }
    $sleutelId = (string) $koppeling['sleutel_id'];
    $sleutel   = ontsleutelWaarde((string) $koppeling['sleutel']);

    $verbinding = updatesOpenBestandsverbinding($site);
    if (isset($verbinding['fout'])) {
        return ['ok' => false, 'bericht' => $verbinding['fout']];
    }

    $label = trim((string) haalInstelling($pdo, 'email_afzendernaam', '')) ?: 'Websitesmonitor';
    $label .= ' (' . (parse_url((string) haalInstelling($pdo, 'monitor_basis_url', ''), PHP_URL_HOST) ?: 'monitor') . ')';

    $pluginPad = 'plugins/system/compactupdater/';
    $mislukt   = [];

    foreach (updatesPluginBestanden() as $relatief => $inhoud) {
        if (!updatesSchrijfBestand($verbinding, $pluginPad . $relatief, $inhoud)) {
            $mislukt[] = $relatief;
        }
    }
    if (!updatesSchrijfBestand($verbinding, $pluginPad . 'sleutels/' . $sleutelId . '.php', updatesSleutelbestandInhoud($sleutelId, $sleutel, $label))) {
        $mislukt[] = 'sleutels/' . $sleutelId . '.php';
    }

    if ($mislukt) {
        updatesSluitBestandsverbinding($verbinding);
        return ['ok' => false, 'bericht' => 'Uploaden mislukt voor: ' . implode(', ', array_slice($mislukt, 0, 5))
            . '. Controleer het FTP-pad (moet naar de map met configuration.php wijzen) en de schrijfrechten.'];
    }

    updatesSluitBestandsverbinding($verbinding);

    // Eenmalig koppelscript neerzetten en aanroepen.
    $token     = bin2hex(random_bytes(24));
    $uitvoer   = updatesVoerEenmaligScriptUit(
        $pdo,
        $site,
        updatesKoppelscriptInhoud(hash('sha256', $token), time() + 900, haalInstelling($pdo, 'updates_admintools', '1') === '1'),
        'compactupdater_koppel_',
        $token,
        60
    );
    if ($uitvoer['fout'] !== '') {
        updatesWerkKoppelingBij($pdo, $siteId, ['laatste_fout' => mb_substr('Aanmelden mislukt: ' . $uitvoer['fout'], 0, 1000)]);
        return ['ok' => false, 'bericht' => 'Aanmelden van de plugin mislukt: ' . $uitvoer['fout']];
    }
    $body = $uitvoer['body'];
    $http = $uitvoer['http'];

    $json = updatesLeesJson((string) $body);
    if (!is_array($json) || empty($json['succes'])) {
        $fout = is_array($json) && !empty($json['fout']) ? $json['fout'] : updatesLeesbareHttpFout($http, (string) $body);
        updatesWerkKoppelingBij($pdo, $siteId, ['laatste_fout' => mb_substr('Aanmelden mislukt: ' . $fout, 0, 1000)]);
        return ['ok' => false, 'bericht' => 'Aanmelden van de plugin mislukt: ' . $fout];
    }

    updatesWerkKoppelingBij($pdo, $siteId, ['geinstalleerd_op' => date('Y-m-d H:i:s')]);

    if (empty($json['enabled'])) {
        updatesWerkKoppelingBij($pdo, $siteId, ['laatste_fout' => 'De plugin staat uit in Joomla (Extensies > Plugins).']);
        return ['ok' => false, 'bericht' => 'De bestanden zijn bijgewerkt, maar de plugin staat uit in Joomla (Extensies > Plugins). Zet hem daar aan.'];
    }

    $ping = updatesPing($pdo, $site);
    if (!$ping['ok']) {
        return ['ok' => false, 'bericht' => 'De plugin is geplaatst, maar antwoordt nog niet: ' . $ping['fout']];
    }

    // Kort: gelukt is gelukt. Alleen wat je zelf moet nakijken, komt erbij,
    // als korte regel ('let_op') met de uitleg apart ('let_op_uitleg').
    $bericht = ($json['nieuw'] ? 'Plugin geïnstalleerd' : 'Plugin bijgewerkt') . ' (' . ($ping['antwoord']['plugin_versie'] ?? '?') . ').';
    $letOp   = [];
    $uitleg  = [];
    if (!empty($uitvoer['waarschuwing'])) {
        $letOp[]  = 'Scanscript opnieuw versturen';
        $uitleg[] = $uitvoer['waarschuwing'];
    }
    $at = $json['admintools'] ?? null;
    if (is_array($at)) {
        if ($at['fout'] !== '') {
            $letOp[]  = 'Admin Tools niet bijgewerkt';
            $uitleg[] = 'Het IP-adres van de monitor kon niet op "Nooit deze IP-adressen blokkeren" worden gezet: ' . $at['fout'];
        }
        if (!empty($at['hersteld'])) {
            $letOp[]  = 'Lijst in Admin Tools nakijken';
            $uitleg[] = 'Kijk in Admin Tools de lijst "Nooit deze IP-adressen blokkeren" na. Daar stond een ongeldige regel ("Array"), achtergelaten door een eerdere versie van de monitor; die is verwijderd. IP-adressen die er eerder stonden, kunnen daarbij verloren zijn gegaan.';
        }
    }

    return ['ok' => true, 'bericht' => $bericht, 'let_op' => implode(' · ', $letOp), 'let_op_uitleg' => implode(' ', $uitleg)];
}

// ----------------------------------------------------------------------
// De plugin van een site verwijderen
// ----------------------------------------------------------------------

/**
 * Inhoud van het eenmalige verwijderscript.
 *
 * De plugin kan door meer monitors tegelijk gebruikt worden: elke monitor
 * heeft zijn eigen sleutelbestand in plugins/system/compactupdater/sleutels.
 * Het script haalt daarom eerst alleen de sleutel van DEZE monitor weg.
 * Staat er daarna nog een sleutel van een andere monitor, dan blijft de
 * plugin staan (die andere monitor gebruikt hem nog). Anders wordt de
 * plugin helemaal verwijderd: de registratie in Joomla (#__extensions),
 * zijn updatesite en de pluginmap. Snapshots van eerdere updates
 * (administrator/cache/compactupdater) blijven staan, zodat terugzetten
 * nog kan; Joomla ruimt ze op bij het legen van de cache.
 */
function updatesVerwijderscriptInhoud(string $tokenHash, int $verlooptOp, string $sleutelId): string
{
    $sjabloon = <<<'PHP'
$SLEUTEL_ID = %SLEUTEL_ID%;

$pluginMap = $root . '/plugins/system/compactupdater';
$sleutelMap = $pluginMap . '/sleutels';

// Niet verwijderen terwijl de plugin een update installeert.
$werkMap = $root . '/administrator/cache/compactupdater';
if (is_file($werkMap . '/update.lock')) {
    $lock = @fopen($werkMap . '/update.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        scriptAntwoord(array('succes' => false, 'fout' => 'Op de site loopt op dit moment een update. Probeer het zo opnieuw.'));
    }
}

// 1. De sleutel van deze monitor.
$eigenSleutel = $sleutelMap . '/' . $SLEUTEL_ID . '.php';
$sleutelWeg   = !is_file($eigenSleutel) || @unlink($eigenSleutel);
if (!$sleutelWeg) {
    scriptAntwoord(array('succes' => false, 'fout' => 'Het sleutelbestand van deze monitor kon niet worden verwijderd (schrijfrechten).'));
}

// 2. Gebruikt een andere monitor de plugin nog?
$andere = 0;
foreach (glob($sleutelMap . '/*.php') ?: array() as $bestand) {
    if (basename($bestand) !== 'index.php') {
        $andere++;
    }
}
if ($andere > 0) {
    leegJoomlaCaches($root, $c);
    scriptAntwoord(array('succes' => true, 'geheel' => false, 'andere' => $andere));
}

// 3. Helemaal verwijderen: registratie, updatesite, bestanden.
$fouten = array();
try {
    $tabel = $prefix . 'extensions';
    $stmt  = $pdo->prepare("SELECT extension_id FROM `$tabel` WHERE type = 'plugin' AND element = 'compactupdater' AND folder = 'system' AND client_id = 0");
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $extensionId) {
        $extensionId = (int) $extensionId;
        $usTabel  = $prefix . 'update_sites';
        $useTabel = $prefix . 'update_sites_extensions';
        if (tabelBestaat($pdo, $usTabel) && tabelBestaat($pdo, $useTabel)) {
            $sites = $pdo->prepare("SELECT update_site_id FROM `$useTabel` WHERE extension_id = ?");
            $sites->execute(array($extensionId));
            $siteIds = array_map('intval', $sites->fetchAll(PDO::FETCH_COLUMN));
            $pdo->prepare("DELETE FROM `$useTabel` WHERE extension_id = ?")->execute(array($extensionId));
            foreach ($siteIds as $siteId) {
                // Alleen een updatesite die nu door niets anders meer wordt gebruikt.
                $teller = $pdo->prepare("SELECT COUNT(*) FROM `$useTabel` WHERE update_site_id = ?");
                $teller->execute(array($siteId));
                if ((int) $teller->fetchColumn() === 0) {
                    $pdo->prepare("DELETE FROM `$usTabel` WHERE update_site_id = ?")->execute(array($siteId));
                    if (tabelBestaat($pdo, $prefix . 'updates')) {
                        $pdo->prepare("DELETE FROM `{$prefix}updates` WHERE update_site_id = ?")->execute(array($siteId));
                    }
                }
            }
        }
        if (tabelBestaat($pdo, $prefix . 'updates')) {
            $pdo->prepare("DELETE FROM `{$prefix}updates` WHERE extension_id = ?")->execute(array($extensionId));
        }
        $pdo->prepare("DELETE FROM `$tabel` WHERE extension_id = ?")->execute(array($extensionId));
    }
} catch (Exception $e) {
    scriptAntwoord(array('succes' => false, 'fout' => 'Afmelden in de database mislukt: ' . $e->getMessage()));
}

function verwijderMapGeheel($map, &$fouten)
{
    if (!is_dir($map) || is_link($map)) {
        return;
    }
    foreach (scandir($map) ?: array() as $naam) {
        if ($naam === '.' || $naam === '..') {
            continue;
        }
        $pad = $map . '/' . $naam;
        if (is_dir($pad) && !is_link($pad)) {
            verwijderMapGeheel($pad, $fouten);
        } elseif (!@unlink($pad)) {
            $fouten[] = $pad;
        }
    }
    if (!@rmdir($map)) {
        $fouten[] = $map;
    }
}
verwijderMapGeheel($pluginMap, $fouten);

leegJoomlaCaches($root, $c);

scriptAntwoord(array(
    'succes' => true,
    'geheel' => true,
    'achtergebleven' => array_slice(array_map(function ($pad) use ($root) { return substr($pad, strlen($root) + 1); }, $fouten), 0, 10),
));
PHP;

    return updatesScriptBasis('Eenmalig verwijderscript van de Websitesmonitor: haalt de plugin "Compact Updater" (of alleen de sleutel van deze monitor) weg.', $tokenHash, $verlooptOp)
        . str_replace('%SLEUTEL_ID%', var_export($sleutelId, true), $sjabloon);
}

/**
 * Haalt de plugin van de site (zie updatesVerwijderscriptInhoud()) en
 * vergeet daarna de koppeling en de opgehaalde updates van die site. De
 * geschiedenis blijft bewaard. Geeft ['ok' => bool, 'bericht' => string].
 */
function updatesVerwijderPlugin(PDO $pdo, array $site): array
{
    $siteId    = (int) $site['id'];
    $koppeling = updatesHaalKoppeling($pdo, $siteId);
    if ($koppeling === null) {
        return ['ok' => false, 'bericht' => 'Deze monitor heeft op deze site geen plugin geplaatst.'];
    }
    $sleutelId = (string) $koppeling['sleutel_id'];
    if (!preg_match('/^[a-f0-9]{8,64}$/', $sleutelId)) {
        return ['ok' => false, 'bericht' => 'Onverwachte sleutel bij deze site; verwijder de plugin in Joomla.'];
    }

    $token   = bin2hex(random_bytes(24));
    $uitvoer = updatesVoerEenmaligScriptUit(
        $pdo,
        $site,
        updatesVerwijderscriptInhoud(hash('sha256', $token), time() + 900, $sleutelId),
        'compactupdater_verwijder_',
        $token,
        60
    );
    if ($uitvoer['fout'] !== '') {
        return ['ok' => false, 'bericht' => 'Verwijderen mislukt: ' . $uitvoer['fout']];
    }
    $json = updatesLeesJson((string) $uitvoer['body']);
    if (!is_array($json) || empty($json['succes'])) {
        $fout = is_array($json) && !empty($json['fout']) ? $json['fout'] : updatesLeesbareHttpFout((int) $uitvoer['http'], (string) $uitvoer['body']);
        return ['ok' => false, 'bericht' => 'Verwijderen mislukt: ' . $fout];
    }

    // De site is los van deze monitor: koppeling en opgehaalde updates weg.
    $pdo->prepare("DELETE FROM updates_beschikbaar WHERE site_id = ?")->execute([$siteId]);
    $pdo->prepare("DELETE FROM updates_koppeling WHERE site_id = ?")->execute([$siteId]);

    if (empty($json['geheel'])) {
        $bericht = 'De sleutel van deze monitor is verwijderd. De plugin zelf blijft staan, omdat '
            . ((int) $json['andere'] === 1 ? 'nog een andere monitor' : 'nog ' . (int) $json['andere'] . ' andere monitors')
            . ' hem op deze site gebruikt.';
    } else {
        $bericht = 'Plugin verwijderd.';
        if (!empty($json['achtergebleven'])) {
            $bericht .= ' Een paar bestanden konden niet worden verwijderd: ' . implode(', ', $json['achtergebleven'])
                . '. Verwijder die zo nodig via FTP.';
        }
    }
    if (!empty($uitvoer['waarschuwing'])) {
        $bericht .= ' ' . $uitvoer['waarschuwing'];
    }

    return ['ok' => true, 'bericht' => $bericht];
}

// ----------------------------------------------------------------------
// Terugzetten van een snapshot
// ----------------------------------------------------------------------

/**
 * Inhoud van het eenmalige herstelscript. Zet een snapshot terug die de
 * plugin vóór een update maakte: bestanden, tabellen en de registratie in
 * Joomla. Draait buiten Joomla, zodat het ook werkt als de site na een
 * update niet meer opstart. Het pad van de snapshot staat vast in het
 * script; het script accepteert geen paden van buitenaf.
 */
function updatesHerstelscriptInhoud(string $tokenHash, int $verlooptOp, string $snapshotPad): string
{
    $sjabloon = <<<'PHP'
$SNAPSHOT = %SNAPSHOT%;

if (!class_exists('ZipArchive')) {
    scriptAntwoord(array('succes' => false, 'fout' => 'PHP-extensie zip ontbreekt op de server.'));
}
if (!preg_match('/\.zip$/', $SNAPSHOT) || !is_file($SNAPSHOT)) {
    scriptAntwoord(array('succes' => false, 'fout' => 'De snapshot bestaat niet meer op de server (er worden er per extensie 3 bewaard).'));
}

// Dezelfde lock als de plugin: niet terugzetten tijdens een update.
$werkMap = $root . '/administrator/cache/compactupdater';
if (!is_dir($werkMap)) {
    @mkdir($werkMap, 0755, true);
}
$lock = @fopen($werkMap . '/update.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    scriptAntwoord(array('succes' => false, 'fout' => 'Op de site loopt op dit moment een update. Probeer het zo opnieuw.'));
}

$zip = new ZipArchive();
if ($zip->open($SNAPSHOT) !== true) {
    scriptAntwoord(array('succes' => false, 'fout' => 'De snapshot is niet te openen.'));
}
$info = json_decode((string) $zip->getFromName('snapshot.json'), true);
if (!is_array($info) || (int) $info['formaat'] !== 1) {
    scriptAntwoord(array('succes' => false, 'fout' => 'De snapshot is onleesbaar.'));
}
if (realpath($info['joomla_root']) !== realpath($root)) {
    scriptAntwoord(array('succes' => false, 'fout' => 'Deze snapshot hoort bij een andere Joomla-installatie.'));
}
if ((string) $info['prefix'] !== $prefix) {
    scriptAntwoord(array('succes' => false, 'fout' => 'Het tabelvoorvoegsel van de site is veranderd sinds de snapshot.'));
}

function veiligPad($pad)
{
    $pad = str_replace('\\', '/', (string) $pad);
    return $pad !== '' && strpos($pad, '..') === false && $pad[0] !== '/' && substr_count($pad, '/') >= 1;
}

function verwijderMap($map)
{
    if (!is_dir($map) || is_link($map)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($map, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($map);
}

// 1. Bestanden: de mappen van de extensie leegmaken en de inhoud van de
//    snapshot terugzetten.
$paden = array();
foreach ((array) $info['paden'] as $item) {
    if (!veiligPad($item['pad'])) {
        scriptAntwoord(array('succes' => false, 'fout' => 'Ongeldig pad in de snapshot.'));
    }
    $paden[] = $item['pad'];
    $doel = $root . '/' . $item['pad'];
    if ($item['type'] === 'map') {
        verwijderMap($doel);
        @mkdir($doel, 0755, true);
    } elseif (is_file($doel)) {
        @unlink($doel);
    }
}

$aantalBestanden = 0;
$fouten = array();
for ($i = 0; $i < $zip->numFiles; $i++) {
    $naam = $zip->getNameIndex($i);
    if (strpos($naam, 'bestanden/') !== 0) {
        continue;
    }
    $relatief = substr($naam, strlen('bestanden/'));
    if ($relatief === '' || !veiligPad(rtrim($relatief, '/'))) {
        continue;
    }
    $hoortErbij = false;
    foreach ($paden as $pad) {
        if (rtrim($relatief, '/') === $pad || strpos($relatief, $pad . '/') === 0) {
            $hoortErbij = true;
            break;
        }
    }
    if (!$hoortErbij) {
        continue;
    }
    $doel = $root . '/' . $relatief;
    if (substr($naam, -1) === '/') {
        if (!is_dir($doel)) {
            @mkdir($doel, 0755, true);
        }
        continue;
    }
    if (!is_dir(dirname($doel))) {
        @mkdir(dirname($doel), 0755, true);
    }
    $invoer  = $zip->getStream($naam);
    $uitvoer = @fopen($doel, 'wb');
    if ($invoer === false || $uitvoer === false) {
        $fouten[] = $relatief;
        continue;
    }
    stream_copy_to_stream($invoer, $uitvoer);
    fclose($invoer);
    fclose($uitvoer);
    $aantalBestanden++;
}

// 2. Tabellen van de extensie.
$aantalTabellen = 0;
try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ((array) $info['tabellen'] as $tabelNaam) {
        $sql = $zip->getFromName('database/' . $tabelNaam . '.sql');
        if ($sql === false) {
            continue;
        }
        foreach (explode($info['scheiding'], $sql) as $opdracht) {
            if (trim($opdracht) !== '') {
                $pdo->exec($opdracht);
            }
        }
        $aantalTabellen++;
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    // 3. Registratie in Joomla: versie (manifest), instellingen en schemaversie.
    $ids = array();
    foreach ((array) $info['extensies'] as $e) {
        $stmt = $pdo->prepare("UPDATE `{$prefix}extensions` SET manifest_cache = ?, params = ?, enabled = ? WHERE extension_id = ?");
        $stmt->execute(array($e['manifest_cache'], $e['params'], (int) $e['enabled'], (int) $e['extension_id']));
        if ($stmt->rowCount() === 0) {
            $stmt = $pdo->prepare("UPDATE `{$prefix}extensions` SET manifest_cache = ?, params = ?, enabled = ?
                WHERE type = ? AND element = ? AND folder = ? AND client_id = ?");
            $stmt->execute(array($e['manifest_cache'], $e['params'], (int) $e['enabled'], $e['type'], $e['element'], $e['folder'], (int) $e['client_id']));
        }
        $ids[] = (int) $e['extension_id'];
    }
    foreach ((array) $info['schemas'] as $schema) {
        $pdo->prepare("DELETE FROM `{$prefix}schemas` WHERE extension_id = ?")->execute(array((int) $schema['extension_id']));
        $pdo->prepare("INSERT INTO `{$prefix}schemas` (extension_id, version_id) VALUES (?, ?)")
            ->execute(array((int) $schema['extension_id'], (string) $schema['version_id']));
    }
    // Joomla zoekt de updates daarna opnieuw.
    if ($ids) {
        $pdo->exec("DELETE FROM `{$prefix}updates` WHERE extension_id IN (" . implode(',', $ids) . ")");
    }
} catch (Exception $e) {
    scriptAntwoord(array('succes' => false, 'fout' => 'Bestanden zijn teruggezet, maar de database niet volledig: ' . $e->getMessage(), 'bestanden' => $aantalBestanden));
}

$zip->close();
leegJoomlaCaches($root, $c);
if (function_exists('opcache_reset')) {
    @opcache_reset();
}
flock($lock, LOCK_UN);

scriptAntwoord(array(
    'succes'    => count($fouten) === 0,
    'bestanden' => $aantalBestanden,
    'tabellen'  => $aantalTabellen,
    'versie'    => (string) $info['van_versie'],
    'fout'      => $fouten ? 'Niet terug te zetten: ' . implode(', ', array_slice($fouten, 0, 5)) : '',
));
PHP;

    return updatesScriptBasis('Eenmalig herstelscript van de Websitesmonitor: zet de snapshot van een extensie terug.', $tokenHash, $verlooptOp)
        . str_replace('%SNAPSHOT%', var_export($snapshotPad, true), $sjabloon);
}

/**
 * Zet een snapshot uit de geschiedenis terug op de site, via een los
 * herstelscript (FTP/SFTP). Geeft ['ok' => bool, 'bericht' => string] terug.
 */
function updatesZetTerug(PDO $pdo, int $geschiedenisId): array
{
    $stmt = $pdo->prepare("SELECT * FROM updates_geschiedenis WHERE id = ?");
    $stmt->execute([$geschiedenisId]);
    $regel = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$regel || empty($regel['snapshot'])) {
        return ['ok' => false, 'bericht' => 'Voor deze update is geen snapshot bekend.'];
    }
    if (!empty($regel['teruggezet'])) {
        return ['ok' => false, 'bericht' => 'Deze snapshot is al teruggezet.'];
    }

    $site = updatesHaalSite($pdo, (int) $regel['site_id']);
    if ($site === null) {
        return ['ok' => false, 'bericht' => 'De site bestaat niet meer in de monitor.'];
    }

    $token   = bin2hex(random_bytes(24));
    $uitvoer = updatesVoerEenmaligScriptUit(
        $pdo,
        $site,
        updatesHerstelscriptInhoud(hash('sha256', $token), time() + 900, (string) $regel['snapshot']),
        'compactupdater_herstel_',
        $token,
        900
    );
    if ($uitvoer['fout'] !== '') {
        return ['ok' => false, 'bericht' => 'Terugzetten mislukt: ' . $uitvoer['fout']];
    }
    $body = $uitvoer['body'];
    $http = $uitvoer['http'];

    $json = updatesLeesJson((string) $body);
    if (!is_array($json)) {
        return ['ok' => false, 'bericht' => 'Terugzetten mislukt: ' . updatesLeesbareHttpFout($http, (string) $body)];
    }

    $gelukt  = !empty($json['succes']);
    $waarschuwing = (string) ($uitvoer['waarschuwing'] ?? '');
    $bericht = $gelukt
        ? 'Teruggezet naar ' . $regel['van_versie'] . ' (' . (int) $json['bestanden'] . ' bestanden, ' . (int) $json['tabellen'] . ' tabellen).'
        : 'Terugzetten (deels) mislukt: ' . ($json['fout'] ?? 'onbekende fout');

    if ($gelukt || !empty($json['bestanden'])) {
        $pdo->prepare("UPDATE updates_geschiedenis SET teruggezet = NOW() WHERE id = ?")->execute([$geschiedenisId]);
    }

    $controle = $gelukt ? updatesControleerSite($site) : '';

    $pdo->prepare("
        INSERT INTO updates_geschiedenis
            (run_id, site_id, domein, extension_id, naam, van_versie, naar_versie, resultaat, bericht, controle, bron, tijdstip)
        VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'terugzetten', NOW())
    ")->execute([
        (int) $site['id'],
        $site['domein'],
        $regel['extension_id'],
        $regel['naam'],
        $regel['naar_versie'],
        $regel['van_versie'],
        $gelukt ? 'teruggezet' : 'mislukt',
        $bericht,
        $controle !== '' ? $controle : null,
    ]);

    try {
        updatesHaalOp($pdo, $site, true);
    } catch (Throwable $e) {
        // De volgende keer ophalen werkt het bij.
    }

    return ['ok' => $gelukt, 'bericht' => $bericht . ($controle !== '' ? ' ' . $controle : '') . ($waarschuwing !== '' ? ' ' . $waarschuwing : '')];
}

/**
 * Updatesites op een site repareren ("inschakelen" of "herbouwen") en
 * daarna de updates opnieuw ophalen.
 */
function updatesLocaties(PDO $pdo, array $site, string $modus): array
{
    if (updatesIsUitgesloten($pdo, (int) $site['id'])) {
        return ['ok' => false, 'bericht' => 'Deze site is uitgesloten van updates via de monitor.'];
    }
    $koppeling = updatesHaalKoppeling($pdo, (int) $site['id']);
    if ($koppeling === null) {
        return ['ok' => false, 'bericht' => 'Plugin nog niet geïnstalleerd.'];
    }

    $r = updatesPluginVerzoek($site, $koppeling, 'locaties', ['modus' => $modus], 300);
    if (!$r['ok']) {
        return ['ok' => false, 'bericht' => $r['fout']];
    }
    $a = $r['antwoord'];
    if (empty($a['succes'])) {
        return ['ok' => false, 'bericht' => (string) ($a['bericht'] ?? 'Mislukt.')];
    }

    $op = updatesHaalOp($pdo, $site, true);

    return [
        'ok'      => true,
        'bericht' => (string) $a['bericht'] . ($op['ok'] ? ' Daarna ' . $op['aantal'] . ' update(s) gevonden.' : ''),
    ];
}

// ----------------------------------------------------------------------
// Eén samenvattende e-mail voor alle sites
// ----------------------------------------------------------------------

/**
 * Stuurt één e-mail met wat er in deze run is bijgewerkt (alle sites samen),
 * plus nieuwe updates die op een handmatige beslissing wachten en nog niet
 * eerder gemeld zijn. Verstuurt niets als er niets te melden is.
 */
function updatesStuurSamenvatting(PDO $pdo, string $runId): string
{
    if (haalInstelling($pdo, 'updates_mail', '1') !== '1') {
        return 'E-mail staat uit.';
    }

    $naar = trim((string) haalInstelling($pdo, 'notificatie_email', ''));
    if ($naar === '') {
        return 'Geen e-mailadres ingesteld (Configuratie > E-mail).';
    }

    $stmt = $pdo->prepare("SELECT * FROM updates_geschiedenis WHERE run_id = ? ORDER BY domein, naam");
    $stmt->execute([$runId]);
    $regels = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Wachtende updates die nog niet gemeld zijn (elke nieuwe versie één keer).
    $beleid      = updatesHaalBeleid($pdo);
    $koppelingen = [];
    foreach ($pdo->query("SELECT * FROM updates_koppeling") as $k) {
        $koppelingen[(int) $k['site_id']] = $k;
    }
    $wachtend = [];
    foreach ($pdo->query("
        SELECT u.*, s.domein, s.categorie, s.id AS sid FROM updates_beschikbaar u JOIN sites s ON s.id = u.site_id
        WHERE (u.gemeld_versie IS NULL OR u.gemeld_versie <> u.nieuwe_versie)
        ORDER BY s.domein, u.naam
    ")->fetchAll(PDO::FETCH_ASSOC) as $rij) {
        $site = ['id' => (int) $rij['sid'], 'domein' => $rij['domein'], 'categorie' => $rij['categorie']];
        if (!updatesSiteMagBijwerken($pdo, $site)) {
            continue;
        }
        [$mag, $reden] = updatesBeslissing($pdo, $site, $rij, $beleid, $koppelingen[(int) $rij['site_id']] ?? null);
        if (!$mag && $reden !== 'Overslaan' && $reden !== 'Deze versie is overgeslagen') {
            $wachtend[] = $rij + ['reden' => $reden];
        }
    }

    if (!$regels && !$wachtend) {
        return 'Niets te melden; geen e-mail verstuurd.';
    }

    $tel = ['succes' => 0, 'mislukt' => 0, 'onbekend' => 0];
    $sitesBijgewerkt = [];
    foreach ($regels as $r) {
        if (isset($tel[$r['resultaat']])) {
            $tel[$r['resultaat']]++;
        }
        if ($r['resultaat'] === 'succes') {
            $sitesBijgewerkt[$r['site_id']] = true;
        }
    }
    $metControle = array_filter($regels, function ($r) { return !empty($r['controle']); });

    $h = function ($tekst) { return htmlspecialchars((string) $tekst, ENT_QUOTES, 'UTF-8'); };

    $samenvatting = [];
    if ($tel['succes']) {
        $samenvatting[] = $tel['succes'] . ' update(s) geïnstalleerd op ' . count($sitesBijgewerkt) . ' site(s)';
    }
    if ($tel['mislukt']) {
        $samenvatting[] = $tel['mislukt'] . ' mislukt';
    }
    if ($tel['onbekend']) {
        $samenvatting[] = $tel['onbekend'] . ' met onbekende afloop';
    }
    if ($wachtend) {
        $samenvatting[] = count($wachtend) . ' nieuwe update(s) wachten op jou';
    }

    $blok = function (string $titel, string $kleur, array $rijen) use ($h): string {
        if (!$rijen) {
            return '';
        }
        $html = '<tr><td style="padding: 16px 24px 4px 24px; font-size: 14px; font-weight: bold; color: ' . $kleur . ';">' . $h($titel) . '</td></tr>'
            . '<tr><td style="padding: 0 24px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">';
        $huidigeSite = null;
        foreach ($rijen as $rij) {
            if ($rij['domein'] !== $huidigeSite) {
                $huidigeSite = $rij['domein'];
                $html .= '<tr><td colspan="2" style="padding: 8px 0 2px 0; font-weight: bold; border-top: 1px solid #eeeeee;">' . $h($huidigeSite) . '</td></tr>';
            }
            $html .= '<tr><td style="padding: 2px 0 2px 12px;">' . $h($rij['naam']) . '</td>'
                . '<td style="padding: 2px 0; text-align: right; white-space: nowrap;">' . $h($rij['links']) . '</td></tr>';
            if (!empty($rij['extra'])) {
                $html .= '<tr><td colspan="2" style="padding: 0 0 4px 12px; font-size: 12px; color: #888888;">' . nl2br($h($rij['extra'])) . '</td></tr>';
            }
        }

        return $html . '</table></td></tr>';
    };

    $mislukt = [];
    $gelukt  = [];
    foreach ($regels as $r) {
        $rij = [
            'domein' => $r['domein'],
            'naam'   => $r['naam'],
            'links'  => $r['van_versie'] . ' → ' . $r['naar_versie'],
            'extra'  => '',
        ];
        if ($r['resultaat'] === 'succes') {
            $rij['extra'] = (string) $r['controle'];
            if (!empty($r['controle'])) {
                $mislukt[] = $rij;
            } else {
                $gelukt[] = $rij;
            }
        } elseif (!in_array($r['resultaat'], ['overgeslagen', 'teruggezet'], true)) {
            $rij['extra'] = ($r['resultaat'] === 'onbekend' ? 'Afloop onbekend: ' : '') . mb_substr((string) $r['bericht'], 0, 400);
            $mislukt[] = $rij;
        }
    }
    $wachtRijen = array_map(function ($w) {
        return ['domein' => $w['domein'], 'naam' => $w['naam'], 'links' => $w['huidige_versie'] . ' → ' . $w['nieuwe_versie'], 'extra' => $w['reden']];
    }, $wachtend);

    $monitorUrl = rtrim((string) haalInstelling($pdo, 'monitor_basis_url', ''), '/');

    $inhoud = '<!DOCTYPE html><html><body style="margin: 0; padding: 0; background: #f4f4f4; font-family: Arial, sans-serif; font-size: 14px; color: #222222;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="padding: 20px 10px;">'
        . '<table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="background: #ffffff; max-width: 640px;">'
        . '<tr><td style="background: #333333; color: #ffffff; padding: 16px 24px; font-size: 16px; font-weight: bold;">🔄 Updateoverzicht</td></tr>'
        . '<tr><td style="padding: 16px 24px 0 24px;">' . $h(implode(', ', $samenvatting)) . '.</td></tr>'
        . $blok('Mislukt of controleren', '#c0392b', $mislukt)
        . $blok('Bijgewerkt', '#1e7e34', $gelukt)
        . $blok('Wachten op jou', '#665200', $wachtRijen)
        . '<tr><td style="padding: 20px 24px; font-size: 12px; color: #888888;">'
        . ($monitorUrl !== '' ? '<a href="' . $h($monitorUrl) . '/uitbreidingen/updates/updates.php" style="color: #1f6fa8;">Open het updateoverzicht in de monitor</a>' : '')
        . '</td></tr></table></td></tr></table></body></html>';

    $afzenderNaam = trim((string) haalInstelling($pdo, 'email_afzendernaam', '')) ?: 'Mijn Websites Monitor';
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=utf-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";
    $headers .= 'From: ' . mb_encode_mimeheader($afzenderNaam, 'UTF-8', 'B') . " <$naar>\r\n";

    $onderwerp = '🔄 Updates: ' . implode(', ', $samenvatting);
    $verstuurd = @mail($naar, mb_encode_mimeheader($onderwerp, 'UTF-8', 'B'), $inhoud, $headers);

    if ($verstuurd && $wachtend) {
        $markeer = $pdo->prepare("UPDATE updates_beschikbaar SET gemeld_versie = nieuwe_versie WHERE site_id = ? AND extension_id = ?");
        foreach ($wachtend as $w) {
            $markeer->execute([(int) $w['site_id'], (int) $w['extension_id']]);
        }
    }

    return $verstuurd ? "E-mail verstuurd naar $naar." : 'Versturen van de e-mail is mislukt.';
}
