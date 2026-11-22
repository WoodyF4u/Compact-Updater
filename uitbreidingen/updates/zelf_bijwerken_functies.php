<?php
// zelf_bijwerken_functies.php
//
// De uitbreiding Updates werkt zichzelf bij vanaf GitHub.
//
// In de repository staat uitbreiding.json met de nieuwste versie, de
// downloadlink van uitbreiding-updates.zip bij de release en de sha256 van
// die zip. De pagina vraagt dat bestand op (hooguit eens per 6 uur, de
// uitkomst wordt bewaard) en toont rechtsboven de versie, of een knop als
// er een nieuwere is. Bij het bijwerken:
//   1. uitbreiding.json opnieuw ophalen en de nieuwe versie controleren;
//   2. nagaan of de monitor zijn eigen bestanden mag wijzigen;
//   3. de zip downloaden en de sha256 controleren;
//   4. de inhoud controleren: alleen bestanden onder uitbreidingen/updates/,
//      met hetzelfde versienummer als in uitbreiding.json;
//   5. de huidige map bewaren als uitbreidingen/updates-vorige-versie.zip;
//   6. de nieuwe bestanden over de oude heen zetten (elk bestand eerst als
//      tijdelijk bestand, daarna in één keer hernoemd).
// Er wordt niets verwijderd: bestanden die in de nieuwe versie niet meer
// bestaan, blijven gewoon staan. De databasetabellen worden bij het
// openen van de pagina vanzelf bijgewerkt (zie zorgUpdatesTabellen()).

require_once __DIR__ . '/uitbreiding_versie.php';

const UPDATES_ZELF_MANIFEST = 'https://raw.githubusercontent.com/WoodyF4u/Compact-Updater/main/uitbreiding.json';
const UPDATES_ZELF_DOWNLOAD = 'https://github.com/WoodyF4u/Compact-Updater/releases/download/';
const UPDATES_ZELF_INTERVAL = 6 * 3600;

/** Haalt een adres op (met omleidingen). Met $naarBestand wordt het antwoord daarin geschreven. */
function updatesZelfHttp(string $url, ?string $naarBestand = null, int $timeout = 20): array
{
    $ch = curl_init($url);
    $fp = null;
    $opties = [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'CompactUpdater-uitbreiding/' . UPDATES_UITBREIDING_VERSIE,
        CURLOPT_HTTPHEADER     => ['Cache-Control: no-cache'],
    ];
    if (defined('CURLOPT_PROTOCOLS') && strpos($url, 'https://') === 0) {
        $opties[CURLOPT_PROTOCOLS]       = CURLPROTO_HTTPS;
        $opties[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
    }
    if ($naarBestand !== null) {
        $fp = fopen($naarBestand, 'wb');
        if ($fp === false) {
            return ['ok' => false, 'fout' => 'Kon geen tijdelijk bestand maken.', 'body' => ''];
        }
        $opties[CURLOPT_FILE] = $fp;
    } else {
        $opties[CURLOPT_RETURNTRANSFER] = true;
    }
    curl_setopt_array($ch, $opties);
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $fout = curl_error($ch);
    curl_close($ch);
    if ($fp !== null) {
        fclose($fp);
    }
    if ($body === false) {
        return ['ok' => false, 'fout' => 'GitHub is niet bereikbaar vanaf deze server' . ($fout !== '' ? ' (' . $fout . ')' : '') . '.', 'body' => ''];
    }
    if ($http !== 200) {
        return ['ok' => false, 'fout' => "GitHub gaf een onverwacht antwoord (HTTP $http).", 'body' => ''];
    }

    return ['ok' => true, 'fout' => '', 'body' => $naarBestand === null ? (string) $body : ''];
}

/** Haalt uitbreiding.json op en controleert de velden. */
function updatesZelfManifest(): array
{
    $r = updatesZelfHttp(UPDATES_ZELF_MANIFEST);
    if (!$r['ok']) {
        return ['ok' => false, 'fout' => $r['fout']];
    }
    $m = json_decode($r['body'], true);
    if (!is_array($m)
        || !preg_match('/^\d+\.\d+\.\d+$/', (string) ($m['versie'] ?? ''))
        || !preg_match('/^[a-f0-9]{64}$/', (string) ($m['sha256'] ?? ''))
        || strpos((string) ($m['download'] ?? ''), UPDATES_ZELF_DOWNLOAD) !== 0) {
        return ['ok' => false, 'fout' => 'Het versiebestand op GitHub is niet in orde.'];
    }
    $wijzigingen = [];
    foreach ((array) ($m['wijzigingen'] ?? []) as $regel) {
        if (is_string($regel) && trim($regel) !== '') {
            $wijzigingen[] = mb_substr(trim($regel), 0, 300);
        }
    }

    return [
        'ok'          => true,
        'versie'      => $m['versie'],
        'datum'       => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($m['datum'] ?? '')) ? $m['datum'] : '',
        'download'    => $m['download'],
        'sha256'      => $m['sha256'],
        'wijzigingen' => array_slice($wijzigingen, 0, 20),
    ];
}

/**
 * Is er een nieuwere versie? De uitkomst wordt 6 uur bewaard, zodat de
 * pagina niet bij elk openen GitHub hoeft te vragen.
 */
function updatesZelfControle(PDO $pdo, bool $opnieuw = false): array
{
    $bewaard = json_decode((string) haalInstelling($pdo, 'updates_zelf_controle', ''), true);
    if (!$opnieuw && is_array($bewaard) && (int) ($bewaard['tijd'] ?? 0) > time() - UPDATES_ZELF_INTERVAL) {
        $m = $bewaard;
    } else {
        $m = updatesZelfManifest();
        $m['tijd'] = time();
        slaInstellingOp($pdo, 'updates_zelf_controle', json_encode($m));
    }
    $huidig = UPDATES_UITBREIDING_VERSIE;

    return [
        'ok'          => !empty($m['ok']),
        'fout'        => (string) ($m['fout'] ?? ''),
        'huidig'      => $huidig,
        'nieuwste'    => (string) ($m['versie'] ?? ''),
        'nieuwer'     => !empty($m['ok']) && version_compare((string) $m['versie'], $huidig, '>'),
        'datum'       => (string) ($m['datum'] ?? ''),
        'wijzigingen' => (array) ($m['wijzigingen'] ?? []),
    ];
}

/** Alle bestanden onder een map, als relatieve paden met '/'. */
function updatesZelfBestanden(string $map): array
{
    $lijst = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($map, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $bestand) {
        if ($bestand->isFile()) {
            $lijst[] = str_replace('\\', '/', substr($bestand->getPathname(), strlen($map) + 1));
        }
    }
    sort($lijst);

    return $lijst;
}

function updatesZelfRuimOp(string $map): void
{
    if (!is_dir($map)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($map, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($map);
}

/** Werkt de uitbreiding bij naar de nieuwste versie op GitHub. */
function updatesZelfBijwerken(PDO $pdo): array
{
    $doelMap   = __DIR__;                       // .../uitbreidingen/updates
    $basisMap  = dirname($doelMap);             // .../uitbreidingen
    $werkMap   = $basisMap . '/updates-bijwerken-' . bin2hex(random_bytes(4));
    $vorigeZip = $basisMap . '/updates-vorige-versie.zip';
    $fout = function (string $tekst) use (&$werkMap): array {
        updatesZelfRuimOp($werkMap);

        return ['ok' => false, 'bericht' => $tekst];
    };

    // Resten van een eerdere, afgebroken poging opruimen.
    foreach ((array) glob($basisMap . '/updates-bijwerken-*', GLOB_ONLYDIR) as $oud) {
        if (filemtime($oud) < time() - 3600) {
            updatesZelfRuimOp($oud);
        }
    }

    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'bericht' => 'Op deze server ontbreekt de PHP-uitbreiding "zip". Werk bij door uitbreiding-updates.zip zelf uit te pakken en te uploaden.'];
    }

    // 1. Wat is de nieuwste versie?
    $m = updatesZelfManifest();
    if (!$m['ok']) {
        return ['ok' => false, 'bericht' => $m['fout']];
    }
    if (!version_compare($m['versie'], UPDATES_UITBREIDING_VERSIE, '>')) {
        updatesZelfControle($pdo, true);

        return ['ok' => false, 'bericht' => 'Er is geen nieuwere versie; deze versie (' . UPDATES_UITBREIDING_VERSIE . ') is de nieuwste.'];
    }

    // 2. Mag de monitor zijn eigen bestanden wijzigen?
    $nietSchrijfbaar = [];
    foreach (array_merge(['.'], updatesZelfBestanden($doelMap)) as $rel) {
        $pad = $rel === '.' ? $doelMap : $doelMap . '/' . $rel;
        if (!is_writable($pad) || ($rel !== '.' && !is_writable(dirname($pad)))) {
            $nietSchrijfbaar[] = $rel === '.' ? 'uitbreidingen/updates' : $rel;
        }
    }
    if (!is_writable($basisMap)) {
        $nietSchrijfbaar[] = 'uitbreidingen';
    }
    if ($nietSchrijfbaar) {
        return ['ok' => false, 'bericht' => 'De monitor mag zijn eigen bestanden op deze server niet wijzigen (bijvoorbeeld '
            . implode(', ', array_slice($nietSchrijfbaar, 0, 3)) . '). Werk bij door uitbreiding-updates.zip zelf uit te pakken en via FTP te uploaden.'];
    }

    // 3. Downloaden en controleren.
    if (!@mkdir($werkMap, 0755)) {
        return ['ok' => false, 'bericht' => 'Kon geen tijdelijke map maken in de map uitbreidingen.'];
    }
    @file_put_contents($werkMap . '/.htaccess', "Require all denied\n");
    $zipPad = $werkMap . '/pakket.zip';
    $r = updatesZelfHttp($m['download'], $zipPad, 120);
    if (!$r['ok']) {
        return $fout('Downloaden mislukt: ' . $r['fout']);
    }
    if (!hash_equals($m['sha256'], (string) hash_file('sha256', $zipPad))) {
        return $fout('Het gedownloade pakket klopt niet met de controlesom op GitHub; er is niets veranderd.');
    }

    // 4. Inhoud controleren en uitpakken.
    $zip = new ZipArchive();
    if ($zip->open($zipPad) !== true) {
        return $fout('Het gedownloade pakket is geen geldige zip; er is niets veranderd.');
    }
    $nieuweVersie = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $naam = (string) $zip->getNameIndex($i);
        if ($naam === 'uitbreidingen/') {
            continue;   // de map zelf
        }
        if (strpos($naam, 'uitbreidingen/updates/') !== 0 || strpos($naam, '..') !== false || strpos($naam, '\\') !== false) {
            $zip->close();

            return $fout('Het pakket bevat onverwachte bestanden; er is niets veranderd.');
        }
        if ($naam === 'uitbreidingen/updates/uitbreiding_versie.php'
            && preg_match("/UPDATES_UITBREIDING_VERSIE\s*=\s*'(\d+\.\d+\.\d+)'/", (string) $zip->getFromIndex($i), $v)) {
            $nieuweVersie = $v[1];
        }
    }
    if ($zip->locateName('uitbreidingen/updates/updates.php') === false || $nieuweVersie !== $m['versie']) {
        $zip->close();

        return $fout('Het pakket hoort niet bij versie ' . $m['versie'] . '; er is niets veranderd.');
    }
    $uitgepakt = $werkMap . '/uitgepakt';
    if (!$zip->extractTo($uitgepakt)) {
        $zip->close();

        return $fout('Uitpakken mislukt; er is niets veranderd.');
    }
    $zip->close();
    $bron = $uitgepakt . '/uitbreidingen/updates';
    $nieuw = updatesZelfBestanden($bron);

    // 5. De huidige versie bewaren, om zo nodig terug te kunnen.
    $bewaar = new ZipArchive();
    if ($bewaar->open($vorigeZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return $fout('Kon de huidige versie niet bewaren; er is niets veranderd.');
    }
    foreach (updatesZelfBestanden($doelMap) as $rel) {
        $bewaar->addFile($doelMap . '/' . $rel, 'uitbreidingen/updates/' . $rel);
    }
    if (!$bewaar->close()) {
        return $fout('Kon de huidige versie niet bewaren; er is niets veranderd.');
    }

    // 6. Eerst alles als tijdelijk bestand naast het doel, daarna hernoemen.
    $klaar = [];
    foreach ($nieuw as $rel) {
        $doel = $doelMap . '/' . $rel;
        if (!is_dir(dirname($doel)) && !@mkdir(dirname($doel), 0755, true)) {
            foreach ($klaar as $tijdelijk) {
                @unlink($tijdelijk);
            }

            return $fout('Kon de map ' . dirname($rel) . ' niet maken; er is niets veranderd.');
        }
        $tijdelijk = $doel . '.nieuw-' . substr(basename($werkMap), -8);
        if (!@copy($bron . '/' . $rel, $tijdelijk)) {
            foreach ($klaar as $t) {
                @unlink($t);
            }

            return $fout('Kon ' . $rel . ' niet schrijven; er is niets veranderd.');
        }
        $klaar[$doel] = $tijdelijk;
    }
    $mislukt = [];
    foreach ($klaar as $doel => $tijdelijk) {
        if (!@rename($tijdelijk, $doel)) {
            @unlink($tijdelijk);
            $mislukt[] = substr($doel, strlen($doelMap) + 1);
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($doel, true);
        }
    }
    updatesZelfRuimOp($werkMap);
    updatesZelfControle($pdo, true);

    if ($mislukt) {
        return ['ok' => false, 'bericht' => 'Bijwerken deels mislukt (' . implode(', ', array_slice($mislukt, 0, 3))
            . '). Zet de vorige versie terug met uitbreidingen/updates-vorige-versie.zip, of upload uitbreiding-updates.zip zelf.'];
    }

    return ['ok' => true, 'bericht' => 'Bijgewerkt naar versie ' . $m['versie'] . '.', 'versie' => $m['versie']];
}
