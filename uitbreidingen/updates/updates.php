<?php
require_once __DIR__ . '/basis.php';
require_once MONITOR_MAP . '/sessie_start.php';
if (!isset($_SESSION['ingelogd'])) {
    header("Location: " . MONITOR_URL . "login.php");
    exit;
}
// Toont actuele gegevens - nooit uit een cache laten komen (zie "Geleerde lessen").
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once MONITOR_MAP . '/config.php';
require_once MONITOR_MAP . '/versie.php';
require_once MONITOR_MAP . '/csrf_functies.php';
require_once MONITOR_MAP . '/instellingen_functies.php';
require_once __DIR__ . '/updates_functies.php';
require_once MONITOR_MAP . '/versie_vergelijk_functies.php';

// updates.php
//
// Centraal overzicht van alle extensie-updates op alle sites, met per
// extensie en per site de keuze of die automatisch mee mag of handmatig
// blijft, en de knop "Alle geactiveerde updates uitvoeren". Bereikbaar via
// de knop "Updates" in de samenvattingsbalk van index.php van de monitor.
// Deze pagina hoort bij de uitbreiding in uitbreidingen/updates.
//
// De updates komen van Joomla zelf op elke site (Extensiebeheer > Updates),
// via de plugin "Compact Updater" (zie updates_functies.php).

zorgUpdatesTabellen($pdo);

$programmaNaam = trim(haalInstelling($pdo, 'email_afzendernaam', '')) ?: 'Mijn Websites Monitor';
$categorie     = ($_GET['categorie'] ?? 'eigen') === 'anderen' ? 'anderen' : 'eigen';
$weergave      = ($_GET['weergave'] ?? 'extensie') === 'site' ? 'site' : 'extensie';

// Tabbladen: beschikbare updates, extensies (per extensie of per site),
// de updater-plugins op de sites en de geschiedenis. Zonder keuze opent
// "Beschikbare updates" (of de plugins, zolang nog geen enkele site de
// plugin heeft; zie verderop).
$tabbladen       = ['updates', 'extensies', 'joomla', 'plugins', 'geschiedenis'];
$tab             = in_array($_GET['tab'] ?? '', $tabbladen, true) ? (string) $_GET['tab'] : '';
$extWeergave     = ($_GET['ext_weergave'] ?? 'extensie') === 'site' ? 'site' : 'extensie';

$anderenToegestaan = haalInstelling($pdo, 'updates_anderen_toestaan', '0') === '1';
$mailAan           = haalInstelling($pdo, 'updates_mail', '1') === '1';
$snapshotAan       = haalInstelling($pdo, 'updates_snapshot', '1') === '1';
$adminToolsAan     = haalInstelling($pdo, 'updates_admintools', '1') === '1';
$kernHoofdversieAan = haalInstelling($pdo, 'updates_kern_hoofdversie', '0') === '1';
$vensterVan        = haalInstelling($pdo, 'updates_venster_van', '');
$vensterTot        = haalInstelling($pdo, 'updates_venster_tot', '');
$pluginVersie      = updatesMeegeleverdePluginVersie();

$stmt = $pdo->prepare("SELECT * FROM sites WHERE categorie = ? ORDER BY domein ASC");
$stmt->execute([$categorie]);
$sites = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
    $sites[(int) $s['id']] = $s;
}

$aantalPerCategorie = $pdo->query("SELECT categorie, COUNT(*) AS aantal FROM sites GROUP BY categorie")->fetchAll(PDO::FETCH_KEY_PAIR);
$aantalEigen   = (int) ($aantalPerCategorie['eigen'] ?? 0);
$aantalAnderen = (int) ($aantalPerCategorie['anderen'] ?? 0);

$koppelingen = [];
foreach ($pdo->query("SELECT * FROM updates_koppeling") as $k) {
    $koppelingen[(int) $k['site_id']] = $k;
}

$beleid = updatesHaalBeleid($pdo);

// De standaard voor alle extensies zonder eigen keuze bij "Op alle sites".
$standaardStand  = $beleid[0][''];
$standaardLabel  = 'Standaard (' . [
    'handmatig' => 'handmatig',
    'patch'     => 'auto, x.y.Z',
    'minor'     => 'auto, zelfde hoofdversie',
    'alles'     => 'auto, alle versies',
    'overslaan' => 'overslaan',
][$standaardStand] . ')';

// Beschikbare updates van de sites in deze categorie, met de beslissing voor "alles uitvoeren".
$updates = [];
// Joomla zelf (de kern) bovenaan, daarna op naam.
$rijen   = $pdo->query("SELECT * FROM updates_beschikbaar ORDER BY (ext_sleutel = '" . UPDATES_KERN_SLEUTEL . "') DESC, naam")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rijen as $rij) {
    $sid = (int) $rij['site_id'];
    if (!isset($sites[$sid])) {
        continue;
    }
    [$mag, $reden] = updatesBeslissing($pdo, $sites[$sid], $rij, $beleid, $koppelingen[$sid] ?? null);
    $rij['mag_automatisch'] = $mag;
    $rij['reden']           = $reden;
    $rij['stand']           = updatesEffectieveStand($beleid, $sid, $rij['ext_sleutel']);
    $updates[]              = $rij;
}

// Joomla zelf per site: de update binnen de hoofdversie (uit de lijst
// hierboven) en een nieuwe hoofdversie (uit de koppeling).
$kernUpdates = [];
foreach ($updates as $u) {
    if (updatesIsKern($u)) {
        $kernUpdates[(int) $u['site_id']] = $u;
    }
}
$kernAantalUpdates = count($kernUpdates);

// Totaal voor de knop gaat over ALLE sites (beide categorieën).
$aantalTaken = count(updatesTakenVoorAlles($pdo));

$geschiedenis = $pdo->query("
    SELECT * FROM updates_geschiedenis ORDER BY tijdstip DESC, id DESC LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

$magBijwerken = $categorie === 'eigen' || $anderenToegestaan;

// Alle extensies van derden op de sites in deze categorie (uit de laatste
// scans), zodat je de instelling per extensie al vóór een update kunt kiezen.
// Onderdelen van een pakket worden niet los getoond: het pakket wordt
// als geheel bijgewerkt.
$updateNamen = [];
foreach ($rijen as $rij) {
    $updateNamen[$rij['ext_sleutel']] = $rij['naam'];
}
$catalogusLabels = [];
foreach ($pdo->query("SELECT sleutel, label FROM extensie_catalogus") as $c) {
    $catalogusLabels[$c['sleutel']] = trim((string) $c['label']);
}

/**
 * Leesbare naam voor een extensie: eerst het label uit de extensiecatalogus
 * van de monitor, dan de naam uit de updatefeed, dan de naam uit de scan.
 * Is dat een onvertaalde taalsleutel (bijv. PKG_IETS_ANDERS), dan wordt
 * daar een nette naam van gemaakt.
 */
function leesbareExtensieNaam(array $e, ?string $catalogusLabel, ?string $updateNaam): string
{
    foreach ([$catalogusLabel, $updateNaam, $e['naam'] ?? ''] as $kandidaat) {
        $kandidaat = trim((string) $kandidaat);
        if ($kandidaat !== '' && !preg_match('/^[A-Z0-9_]+$/', $kandidaat) && !preg_match('/^(com|mod|plg|pkg|lib|tpl|file)_[a-z0-9_]+$/', $kandidaat)) {
            return $kandidaat;
        }
    }

    $ruw = trim((string) (($e['naam'] ?? '') !== '' ? $e['naam'] : ($e['element'] ?? '')));
    $ruw = preg_replace('/^(com|mod|pkg|lib|tpl|file)_/i', '', $ruw);
    $ruw = preg_replace('/^plg_[a-z0-9]+_/i', '', $ruw);
    $ruw = str_replace('_', ' ', strtolower($ruw));

    return $ruw !== '' ? ucwords($ruw) : (string) ($e['element'] ?? '');
}
$extensieGroepen = [];
$stmt = $pdo->prepare("
    SELECT e.site_id, e.naam, e.type, e.element, e.folder, e.client, e.versie, e.auteur, e.package_id, e.update_feed_url, s.domein
    FROM site_alle_extensies e
    JOIN sites s ON s.id = e.site_id
    WHERE s.categorie = ?
    ORDER BY e.naam
");
$stmt->execute([$categorie]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
    if (isJoomlaKernExtensie($e) || isUitgeslotenVanExtensieoverzicht($e) || isOnderdeelVanPakket($e) || updatesIsEigenPlugin($e)) {
        continue;
    }
    $sleutel = updatesExtSleutel([
        'type'      => $e['type'],
        'element'   => $e['element'],
        'folder'    => $e['folder'],
        'client_id' => strtolower((string) $e['client']) === 'administrator' ? 1 : 0,
    ]);
    if (!isset($extensieGroepen[$sleutel])) {
        $extensieGroepen[$sleutel] = [
            'naam'  => leesbareExtensieNaam($e, $catalogusLabels[maakExtensieSleutel((string) $e['type'], (string) $e['element'])] ?? null, $updateNamen[$sleutel] ?? null),
            'type'  => $e['type'],
            'sites' => [],
            // Heeft Joomla op minstens één site een eigen updatesite voor
            // deze extensie? Zie hieronder.
            'updatesite' => isset($updateNamen[$sleutel]),
        ];
    }
    $extensieGroepen[$sleutel]['sites'][(int) $e['site_id']] = ['domein' => $e['domein'], 'versie' => $e['versie']];
    if (trim((string) $e['update_feed_url']) !== '') {
        $extensieGroepen[$sleutel]['updatesite'] = true;
    }
}

// Joomla zoekt alleen updates voor extensies met een eigen updatesite
// (#__update_sites_extensions; de scan geeft die door als update_feed_url).
// Extensies zonder updatesite zijn vrijwel altijd plugins en modules die
// een component of installatiepakket tijdens zijn eigen installatie
// neerzet, zonder dat Joomla ze aan een pakket koppelt (package_id 0).
// Die gaan mee als het hoofdonderdeel wordt bijgewerkt; Joomla biedt er
// nooit los een update voor aan, dus een instelling ervoor heeft geen zin.
// Een extensie waarvoor nu een update klaarstaat, telt altijd mee.
$aantalMetUpdatesite = count(array_filter($extensieGroepen, function ($g) { return $g['updatesite']; }));
$aantalZonderUpdatesite = count($extensieGroepen) - $aantalMetUpdatesite;
uasort($extensieGroepen, function ($a, $b) { return strcasecmp((string) $a['naam'], (string) $b['naam']); });
foreach ($extensieGroepen as &$groep) {
    uasort($groep['sites'], function ($a, $b) { return strcasecmp((string) $a['domein'], (string) $b['domein']); });
}
unset($groep);

// Dezelfde leesbare namen ook in de lijst met beschikbare updates.
foreach ($updates as &$u) {
    if (isset($extensieGroepen[$u['ext_sleutel']])) {
        $u['naam'] = $extensieGroepen[$u['ext_sleutel']]['naam'];
    } else {
        [$type, $element] = array_pad(explode(':', (string) $u['ext_sleutel']), 2, '');
        $u['naam'] = leesbareExtensieNaam(['naam' => $u['naam'], 'element' => $element], $catalogusLabels[maakExtensieSleutel($type, $element)] ?? null, null);
    }
}
unset($u);

// Dezelfde extensies, maar dan per site (voor de onderverdeling
// "Per site" op het tabblad Extensies). $extensieGroepen is al op naam
// gesorteerd, dus per site staan ze vanzelf op naam.
$extensiesPerSite = [];
foreach ($extensieGroepen as $sleutel => $groep) {
    foreach ($groep['sites'] as $sid => $info) {
        $extensiesPerSite[$sid][$sleutel] = ['naam' => $groep['naam'], 'type' => $groep['type'], 'versie' => $info['versie'], 'updatesite' => $groep['updatesite']];
    }
}

// Sites die bewust buiten het centraal bijwerken blijven: die tellen
// nergens mee als "zonder plugin", "verouderd" of "met melding".
$uitgesloten      = updatesUitgeslotenSites($pdo);
$sitesUitgesloten = count(array_intersect_key($uitgesloten, $sites));

$sitesZonderPlugin = 0;
$sitesZonderFtp    = 0;   // zonder plugin én zonder FTP-gegevens: daar kan de monitor niets plaatsen
$sitesVerouderd    = 0;
$sitesMetMelding   = 0;
foreach ($sites as $sid => $s) {
    if (isset($uitgesloten[$sid])) {
        continue;
    }
    $k = $koppelingen[$sid] ?? null;
    if ($k === null || empty($k['plugin_versie'])) {
        $sitesZonderPlugin++;
        if (empty($s['ftp_host'])) {
            $sitesZonderFtp++;
        }
    } elseif (version_compare((string) $k['plugin_versie'], $pluginVersie, '<')) {
        $sitesVerouderd++;
    }
    if ($k !== null && (!empty($k['laatste_fout']) || (string) $k['toestaan'] === '0' || (json_decode((string) $k['uitgeschakelde_locaties'], true) ?: []))) {
        $sitesMetMelding++;
    }
}
unset($k);
$sitesTePlaatsen = $sitesZonderPlugin - $sitesZonderFtp;

// Welke sites hebben volgens de laatste scan van de monitor verouderde
// extensies? Dezelfde telling als op de voorpagina (rood bij Extensies).
// Alleen sites waar de plugin staat en die niet zijn uitgesloten; zo kan
// "Updates ophalen" zich tot de sites beperken waar het om gaat.
$sitesMetPlugin   = [];
$sitesScanUpdates = [];
$laatsteScan      = null;
$scanSamenvatting = haalAlleDerdePartijSamenvatting($pdo);
foreach ($sites as $sid => $s) {
    if (isset($uitgesloten[$sid]) || empty($koppelingen[$sid]['plugin_versie'])) {
        continue;
    }
    $sitesMetPlugin[] = (int) $sid;
    if (($scanSamenvatting[$sid]['niet_up_to_date'] ?? 0) > 0) {
        $sitesScanUpdates[] = (int) $sid;
    }
    if (!empty($s['extensies_laatste_scan']) && ($laatsteScan === null || $s['extensies_laatste_scan'] > $laatsteScan)) {
        $laatsteScan = $s['extensies_laatste_scan'];
    }
}
unset($scanSamenvatting);

if ($tab === '') {
    $tab = $sites && $sitesZonderPlugin > 0 && $sitesZonderPlugin === count($sites) - $sitesUitgesloten ? 'plugins' : 'updates';
}

/** URL naar deze pagina met de huidige keuzes, eventueel met een paar andere waarden. */
function updatesPaginaUrl(array $anders): string
{
    global $categorie, $weergave, $tab, $extWeergave;
    $param = array_merge(['categorie' => $categorie, 'tab' => $tab, 'weergave' => $weergave, 'ext_weergave' => $extWeergave], $anders);

    return '?' . http_build_query($param, '', '&amp;');
}

function h($tekst): string
{
    return htmlspecialchars((string) $tekst, ENT_QUOTES, 'UTF-8');
}

function standKeuzelijst(string $huidig, bool $metVolgen, string $volgenLabel, array $data, ?array $standen = null): string
{
    $attrs = '';
    foreach ($data as $naam => $waarde) {
        $attrs .= ' data-' . $naam . '="' . h($waarde) . '"';
    }
    $html = '<select class="stand-keuze" onchange="slaBeleidOp(this)"' . $attrs . ' data-vorige="' . h($huidig !== '' ? $huidig : 'volgen') . '">';
    if ($metVolgen) {
        $html .= '<option value="volgen"' . ($huidig === '' ? ' selected' : '') . '>' . h($volgenLabel) . '</option>';
    }
    foreach ($standen ?? updatesStanden() as $waarde => $label) {
        $html .= '<option value="' . h($waarde) . '"' . ($huidig === $waarde ? ' selected' : '') . '>' . h($label) . '</option>';
    }

    return $html . '</select>';
}

function updateRij(array $u, array $sites, array $beleid, bool $magBijwerken, string $weergave): string
{
    $sid        = (int) $u['site_id'];
    $site       = $sites[$sid];
    $eigen      = $beleid[$sid][$u['ext_sleutel']] ?? '';
    $overgeslagen = !empty($u['overgeslagen_versie']) && $u['overgeslagen_versie'] === $u['nieuwe_versie'];

    $kern       = updatesIsKern($u);

    $eersteKolom = $weergave === 'extensie'
        ? '<td data-label="Website"><a class="domein-link" href="https://' . h($site['domein']) . '" target="_blank" rel="noopener">' . h($site['domein']) . '</a></td>'
        : '<td data-label="Extensie">' . ($kern ? '<strong>Joomla</strong> <span class="klein">Joomla zelf</span>' : h($u['naam']) . ' <span class="klein">' . h($u['type']) . '</span>')
            . ($u['downloadsleutel'] ? ' <span class="badge" title="Er is een downloadsleutel ingesteld op de site">🔑</span>' : '') . '</td>';

    $beslissing = $u['mag_automatisch']
        ? '<span class="groen">✅ ' . h($u['reden']) . '</span>'
        : '<span class="klein">' . ($overgeslagen || $u['reden'] === 'Overslaan' ? '⏸️ ' : '✋ ') . h($u['reden']) . '</span>';

    $knoppen = '';
    if ($magBijwerken) {
        $knoppen .= $kern
            ? '<button type="button" class="knop knop-klein" onclick="installeerEen(this, ' . $sid . ', ' . UPDATES_KERN_ID . ', ' . h(json_encode(['van' => $u['huidige_versie'], 'naar' => $u['nieuwe_versie']])) . ')">Bijwerken</button> '
            : '<button type="button" class="knop knop-klein" onclick="installeerEen(this, ' . $sid . ', ' . (int) $u['extension_id'] . ')">Bijwerken</button> ';
    }
    $knoppen .= $overgeslagen
        ? '<button type="button" class="knop knop-klein" onclick="slaOver(' . $sid . ', ' . (int) $u['extension_id'] . ', \'\')" title="Deze versie weer meenemen">Weer meenemen</button>'
        : '<button type="button" class="knop knop-klein" onclick="slaOver(' . $sid . ', ' . (int) $u['extension_id'] . ', \'' . h($u['nieuwe_versie']) . '\')" title="Alleen deze versie overslaan; een volgende versie verschijnt weer">Versie overslaan</button>';

    $info = $u['infourl'] ? ' <a href="' . h($u['infourl']) . '" target="_blank" rel="noopener" title="Informatie over deze versie">ℹ️</a>' : '';

    return '<tr id="rij-' . $sid . '-' . (int) $u['extension_id'] . '"' . ($overgeslagen ? ' class="gedimd"' : '') . '>'
        . $eersteKolom
        . '<td data-label="Versie">' . h($u['huidige_versie']) . ' → <strong>' . h($u['nieuwe_versie']) . '</strong>' . $info . '</td>'
        . '<td data-label="Bij alles uitvoeren">' . $beslissing . '</td>'
        . '<td data-label="Instelling hier">' . standKeuzelijst($eigen, true, 'Volgt ' . ($kern ? 'alle sites' : ($weergave === 'extensie' ? 'extensie' : 'algemene instelling')), ['site' => $sid, 'ext' => $u['ext_sleutel']], $kern ? updatesKernStanden() : null) . '</td>'
        . '<td data-label="Actie" class="acties">' . $knoppen . '</td>'
        . '<td data-label="Resultaat" class="resultaat"></td>'
        . '</tr>';
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<script>
// Voorkeur voor licht/donker zo vroeg mogelijk toepassen (vóór de rest van
// de pagina rendert), zodat er geen flits van het verkeerde thema is.
(function () {
    var voorkeur = localStorage.getItem('thema_voorkeur');
    if (voorkeur === 'licht' || voorkeur === 'donker') {
        document.documentElement.setAttribute('data-thema', voorkeur);
    }
})();
</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php echo updatesFaviconTags($pdo); ?>
<title>Updates - <?php echo h($programmaNaam); ?></title>
<style>

body {
    margin: 20px;
    font-size: 13px;
    font-family: Arial, sans-serif;
}

header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    gap: 10px;
    flex-wrap: wrap;
}

h1 {
    margin: 0 0 5px 0;
}

h2 {
    font-size: 16px;
    margin: 25px 0 10px 0;
}

.knop {
    display: inline-block;
    padding: 6px 12px;
    background: #333;
    color: white;
    text-decoration: none;
    border: none;
    border-radius: 4px;
    font-size: 13px;
    white-space: nowrap;
    cursor: pointer;
}

.knop:hover:not(:disabled) {
    background: #555;
}

.knop:disabled {
    /* Uitgeschakeld: gedempt, maar goed leesbaar in licht én donker. */
    background: transparent;
    color: var(--thema-uitleg-tekst);
    outline: 1px dashed var(--thema-invoer-rand);
    outline-offset: -1px;
    cursor: not-allowed;
}

.knop:disabled .icoon-glyph {
    filter: none;
    opacity: 0.6;
}

.knop-klein {
    padding: 4px 9px;
}

/* Opslaan: blauw, zoals op de configuratiepagina. */
.knop.opslaan {
    background: #1f6fa8;
}

.knop.opslaan:hover {
    background: #175a87;
}

/* Een knop waar je iets mee moet: blauw met witte tekst, zoals op de rest
   van de monitor. */
.knop.knop-blauw {
    background: #1f6fa8;
    color: white;
}

.knop.knop-blauw:hover:not(:disabled) {
    background: #175a87;
}

/* "Updates ophalen": geel met zwarte tekst, zodat die linksboven opvalt. */
.knop.knop-geel:not(:disabled) {
    background: #f5c518;
    color: #000;
    font-weight: bold;
}

.knop.knop-geel:hover:not(:disabled) {
    background: #ddb012;
}

/* "Alle geactiveerde updates uitvoeren" zodra er iets klaarstaat: blauw,
   vet en met een rand eromheen, zodat hij naast de gele knop opvalt. */
.knop.knop-opvallend:not(:disabled) {
    font-weight: bold;
    box-shadow: 0 0 0 2px var(--thema-kader-bg), 0 0 0 4px #1f6fa8;
}

/* Een actieknop onder een melding, op een eigen regel. */
.actie-onder {
    margin: 6px 0 2px 0;
}

/* Tabbladen voor de onderdelen van de pagina: een duidelijke balk met
   grote knoppen; het gekozen tabblad blauw met witte tekst, net als de
   actieknoppen van de monitor, met een blauwe lijn eronder. */
.tabbladen {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin: 25px 0 18px 0;
    padding: 8px 8px 0 8px;
    background: var(--thema-zebra);
    border: 1px solid var(--thema-rand);
    border-bottom: 4px solid #1f6fa8;
    border-radius: 8px 8px 0 0;
}

.tabblad {
    padding: 11px 20px;
    border: 1px solid var(--thema-rand);
    border-bottom: none;
    border-radius: 6px 6px 0 0;
    background: var(--thema-kader-bg);
    color: var(--thema-tekst);
    font-size: 15px;
    font-weight: bold;
    cursor: pointer;
}

.tabblad:hover:not(.actief) {
    border-color: #1f6fa8;
    color: var(--thema-link);
}

.tabblad.actief {
    background: #1f6fa8;
    border-color: #1f6fa8;
    color: white;
}

.tabblad.actief .categorie-aantal {
    background: white;
    color: #1f6fa8;
}

@media (max-width: 700px) {
    .tabblad {
        flex: 1 1 45%;
        border-radius: 6px;
        border-bottom: 1px solid var(--thema-rand);
        margin-bottom: 6px;
    }
}

/* Eigen venster voor vragen en meldingen (zie toonDialoog()). */
.dialoog {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(0, 0, 0, 0.55);
}

.dialoog[hidden] {
    display: none;
}

.dialoog-venster {
    max-width: 560px;
    width: 100%;
    max-height: 85vh;
    overflow-y: auto;
    padding: 20px 22px;
    border: 1px solid var(--thema-rand);
    border-radius: 8px;
    background: var(--thema-kader-bg);
    color: var(--thema-tekst);
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.4);
}

.dialoog-tekst {
    font-size: 14px;
    line-height: 1.5;
    white-space: pre-line;
}

.dialoog-invoer {
    width: 100%;
    box-sizing: border-box;
    margin-top: 12px;
    padding: 7px 8px;
    background: var(--thema-invoer-bg);
    color: var(--thema-invoer-tekst);
    border: 1px solid var(--thema-invoer-rand);
    border-radius: 4px;
    font-size: 14px;
}

.dialoog-knoppen {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 18px;
}

.dialoog-knoppen .knop {
    padding: 8px 18px;
    font-size: 14px;
}

/* Een site die buiten het centraal bijwerken blijft. */
.uitgesloten-label {
    font-weight: bold;
    color: var(--thema-rood);
}

tr.sitekop .uitgesloten-label {
    color: #8b1e1e;
}

tr.rij-uitgesloten td:not(.acties) {
    color: var(--thema-uitleg-tekst);
}

.tab-paneel[hidden] {
    display: none;
}

/* Onderverdeling binnen een tabblad (per extensie / per site). */
.subtabs {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}

.subtab {
    padding: 5px 12px;
    border: 1px solid var(--thema-rand);
    border-radius: 14px;
    background: var(--thema-badge-bg);
    color: var(--thema-tekst);
    font-size: 13px;
    cursor: pointer;
}

.subtab.actief {
    border-color: var(--thema-groen);
    font-weight: bold;
}

/* Filters op het tabblad Extensies: een rij keuzelijsten met een label erboven. */
.filterbalk {
    display: flex;
    gap: 10px 14px;
    flex-wrap: wrap;
    align-items: flex-end;
    margin-bottom: 14px;
    padding: 10px 12px;
    border: 1px solid var(--thema-rand);
    border-radius: 6px;
    background: var(--thema-zebra);
}

.filter-veld {
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.filter-veld label {
    font-weight: bold;
    font-size: 12px;
    color: var(--thema-uitleg-tekst);
}

.filterbalk select {
    max-width: 240px;
    padding: 5px 6px;
}

.filterbalk #extensie-zoeken {
    width: 200px;
    margin-bottom: 0;
}

.filter-uitkomst {
    align-self: center;
}

/* Kopregel van een website (met daaronder, iets ingesprongen, haar
   extensies): lichtgroen met zwarte tekst, in licht én donker thema. */
/* !important: het thema (responsive_stijlen.php) zet zelf een achtergrond
   met !important op elke tabelcel. */
tr.groepkop.sitekop td {
    background: #cfeccf !important;
    color: #000;
    font-weight: bold;
    padding-top: 9px;
    padding-bottom: 9px;
    border-top: 1px solid #9fcf9f;
    border-bottom: 1px solid #9fcf9f;
}

/* Lege regel als lucht tussen twee websites. */
tr.tussenruimte td {
    height: 14px;
    padding: 0;
    border: none;
    background: transparent !important;
}

tr.sitekop a {
    color: #000;
    font-size: 14px;
}

tr.sitekop .kop-aantal,
tr.sitekop .klein {
    color: #2f5f2f;
    font-weight: normal;
}

/* De extensies onder een website springen iets in. */
tr.onder-kop td:first-child {
    padding-left: 26px;
}

@media (max-width: 700px) {
    table.responsive-tabel tr.tussenruimte {
        display: none;
    }

    table.responsive-tabel tr.sitekop td::before {
        color: #2f5f2f;
    }

    tr.onder-kop td:first-child {
        padding-left: 0;
    }

    /* Ook als kaartje groen (gaat boven de kaartjeskleuren uit responsive_stijlen.php). */
    table.responsive-tabel tr.groepkop.sitekop {
        background: #cfeccf !important;
        color: #000;
    }
}

#extensie-zoeken {
    width: 320px;
    max-width: 100%;
    padding: 6px 8px;
    margin-bottom: 10px;
    background: var(--thema-invoer-bg);
    color: var(--thema-invoer-tekst);
    border: 1px solid var(--thema-invoer-rand);
    border-radius: 4px;
}

.per-site-lijst {
    display: grid;
    grid-template-columns: max-content 260px;
    gap: 4px 10px;
    align-items: center;
    margin-top: 6px;
}

.categorie-schakelaar {
    display: flex;
    gap: 8px;
    margin-bottom: 15px;
    flex-wrap: wrap;
}
.categorie-tab {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: 6px;
    text-decoration: none;
    color: var(--thema-tekst);
    background: var(--thema-badge-bg);
    border: 2px solid transparent;
    font-size: 14px;
}
.categorie-tab.actief {
    border-color: var(--thema-groen);
    font-weight: bold;
}
.categorie-aantal {
    display: inline-block;
    min-width: 20px;
    padding: 1px 6px;
    border-radius: 10px;
    background: var(--thema-rand);
    font-size: 12px;
    text-align: center;
}

.uitleg {
    margin-bottom: 15px;
    padding: 12px 15px;
    border: 1px solid var(--thema-rand);
    background: var(--thema-zebra);
    border-radius: 6px;
    line-height: 1.5;
}

.uitleg p {
    margin: 0 0 8px 0;
}

.werkbalk {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
    margin-bottom: 10px;
}

.paneel {
    margin-bottom: 15px;
    padding: 12px 15px;
    border: 1px solid var(--thema-rand);
    background: var(--thema-kader-bg);
    border-radius: 6px;
}

.paneel .regel {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
    flex-wrap: wrap;
}

.paneel label {
    display: inline;
    font-weight: normal;
}

details > summary {
    cursor: pointer;
    font-weight: bold;
    font-size: 16px;
    margin: 20px 0 10px 0;
}

table {
    border-collapse: collapse;
    width: 100%;
    margin-bottom: 10px;
}

th {
    background: var(--thema-kop-bg);
    color: var(--thema-kop-tekst);
    padding: 6px 4px;
    text-align: left;
    /* Lopen koppen bij een smal scherm over meer regels, dan blijven ze
       linksboven staan in plaats van verticaal in het midden. */
    vertical-align: top;
    font-weight: bold;
}

td {
    padding: 5px 4px;
    border-bottom: 1px solid var(--thema-rand);
    vertical-align: top;
}

tr.groepkop td {
    background: var(--thema-badge-bg);
    font-weight: bold;
    padding-top: 9px;
}

tr.gedimd td {
    opacity: 0.55;
}

select {
    background: var(--thema-invoer-bg);
    color: var(--thema-invoer-tekst);
    border: 1px solid var(--thema-invoer-rand);
    border-radius: 4px;
    padding: 3px 4px;
    max-width: 260px;
}

input[type="number"] {
    width: 55px;
    background: var(--thema-invoer-bg);
    color: var(--thema-invoer-tekst);
    border: 1px solid var(--thema-invoer-rand);
    border-radius: 4px;
    padding: 3px 4px;
}

.klein {
    color: var(--thema-uitleg-tekst);
}

.groen {
    color: var(--thema-groen);
}

.rood {
    color: var(--thema-rood);
}

.geel {
    color: var(--thema-geel);
}

.badge {
    display: inline-block;
    padding: 1px 6px;
    border-radius: 8px;
    background: var(--thema-badge-bg);
    color: var(--thema-badge-tekst);
    border: 1px solid var(--thema-rand);
    font-size: 12px;
}

.acties {
    white-space: nowrap;
}

.resultaat {
    max-width: 320px;
    white-space: pre-line;
    overflow-wrap: anywhere;
}

#voortgang {
    display: none;
    margin-bottom: 15px;
    padding: 12px 15px;
    border: 2px solid var(--thema-groen);
    border-radius: 6px;
    background: var(--thema-kader-bg);
}

/* Geen eigen scrollbalk: het blok groeit mee, zodat je alles en het
   einde ("Klaar") gewoon op de pagina ziet. */
#voortgang ol {
    margin: 8px 0 0 0;
    padding-left: 20px;
}

#voortgang li {
    margin-bottom: 3px;
}

/* Wat het blok aan het doen is, in één oogopslag. */
.voortgang-kop {
    font-size: 16px;
    font-weight: bold;
    margin-bottom: 4px;
}

/* Afronding: wat ruimte boven "Klaar" en daaronder de knop. */
#voortgang-slot {
    margin-top: 14px;
    padding-top: 12px;
    border-top: 1px solid var(--thema-rand);
}

#voortgang-slot:empty {
    display: none;
}

.voortgang-melding {
    font-size: 14px;
    font-weight: bold;
}

.voortgang-knop {
    margin-top: 12px;
}

.leeg {
    padding: 15px;
    color: var(--thema-uitleg-tekst);
    border: 1px dashed var(--thema-rand);
    border-radius: 6px;
}

</style>
<?php include MONITOR_MAP . '/responsive_stijlen.php'; ?>
</head>
<body>
<header>
    <div>
        <h1>🔄 Updates</h1>
        <div class="klein">Updates van extensies en van Joomla zelf op alle sites, zoals Joomla ze op elke site zelf heeft gevonden.<?php echo hulpIcoon('updates', 'Hoe het centraal bijwerken werkt: de plugin op de sites, de instellingen per extensie en per site, snapshots en terugzetten.'); ?></div>
    </div>
    <div style="display: flex; gap: 8px;">
        <button type="button" class="knop" onclick="history.back()" style="padding: 8px 12px;" title="Eén stap terug">←</button>
        <a class="knop" href="<?php echo MONITOR_URL; ?>index.php?categorie=<?php echo urlencode($categorie); ?>">Terug naar monitor</a>
    </div>
</header>

<div class="categorie-schakelaar">
    <a href="<?php echo updatesPaginaUrl(['categorie' => 'eigen']); ?>" data-behoud-tab class="categorie-tab<?php echo $categorie === 'eigen' ? ' actief' : ''; ?>">
        🏠 Eigen websites <span class="categorie-aantal"><?php echo $aantalEigen; ?></span>
    </a>
    <a href="<?php echo updatesPaginaUrl(['categorie' => 'anderen']); ?>" data-behoud-tab class="categorie-tab<?php echo $categorie === 'anderen' ? ' actief' : ''; ?>">
        👥 Websites van anderen <span class="categorie-aantal"><?php echo $aantalAnderen; ?></span>
    </a>
</div>

<?php if (!$magBijwerken): ?>
<div class="uitleg">👥 Het bijwerken van <strong>websites van anderen</strong> staat uit. Je ziet wel welke updates er zijn. Zet het aan bij <em>Instellingen</em> hieronder als je deze sites ook wilt bijwerken.</div>
<?php endif; ?>

<div class="werkbalk">
    <?php
    // Twee manieren van ophalen: alleen de sites waar de laatste scan van de
    // monitor verouderde extensies vond (sneller), of alle sites.
    $scanTijd = $laatsteScan ? ' (laatste scan: ' . date('d-m-Y H:i', strtotime($laatsteScan)) . ')' : '';
    if ($sitesScanUpdates):
    ?>
    <button type="button" class="knop knop-geel knop-ophalen" onclick="haalAllesOp('scan')" title="Alleen de sites waar de scan van de monitor verouderde extensies vond<?php echo h($scanTijd); ?>"><span class="icoon-glyph">🔍</span> Updates ophalen bij <?php echo count($sitesScanUpdates); ?> gescande site(s)</button>
    <button type="button" class="knop knop-ophalen" onclick="haalAllesOp('alle')" title="Laat Joomla op alle sites met de plugin opnieuw naar updates zoeken"><span class="icoon-glyph">🔍</span> Alle sites ophalen (<?php echo count($sitesMetPlugin); ?>)</button>
    <?php else: ?>
    <button type="button" class="knop knop-geel knop-ophalen" onclick="haalAllesOp('alle')" title="Laat Joomla op alle sites met de plugin opnieuw naar updates zoeken. Volgens de laatste scan van de monitor heeft geen site verouderde extensies<?php echo h($scanTijd); ?>."><span class="icoon-glyph">🔍</span> Updates ophalen bij alle sites (<?php echo count($sitesMetPlugin); ?>)</button>
    <?php endif; ?>
    <?php // Staan er updates klaar die mee mogen, dan in de actiekleur: hier moet je iets mee. ?>
    <button type="button" class="knop<?php echo $aantalTaken > 0 ? ' knop-blauw knop-opvallend' : ''; ?>" id="knop-alles" onclick="voerAllesUit()" <?php echo $aantalTaken === 0 ? 'disabled' : ''; ?> title="Installeert alle updates met een ✅, over alle sites heen"><span class="icoon-glyph">▶</span> Alle geactiveerde updates uitvoeren (<?php echo $aantalTaken; ?>)</button>
    <button type="button" class="knop" onclick="document.getElementById('instellingen').style.display = document.getElementById('instellingen').style.display === 'block' ? 'none' : 'block'"><span class="icoon-glyph">⚙️</span> Instellingen</button>
<?php echo hulpIcoon('updates-alles', 'Updates ophalen laat Joomla opnieuw zoeken: alleen op de sites waar de laatste scan van de monitor verouderde extensies vond, of op alle sites. Alle geactiveerde updates uitvoeren installeert alles met een ✅ in de kolom Bij alles uitvoeren, over alle sites heen.'); ?>
    <span id="voortgang-tekst" class="klein"></span>
</div>

<div class="paneel" id="instellingen" style="display: none;">
    <div class="regel">
        <input type="checkbox" id="inst-anderen" <?php echo $anderenToegestaan ? 'checked' : ''; ?>>
        <label for="inst-anderen">Ook <strong>websites van anderen</strong> mogen worden bijgewerkt</label>
    </div>
    <div class="regel">
        <input type="checkbox" id="inst-mail" <?php echo $mailAan ? 'checked' : ''; ?>>
        <label for="inst-mail">Na <em>Alle geactiveerde updates uitvoeren</em> en na de cronjob één samenvattende e-mail sturen (naar het adres bij Configuratie &gt; E-mail)</label>
    </div>
    <div class="regel">
        <input type="checkbox" id="inst-snapshot" <?php echo $snapshotAan ? 'checked' : ''; ?>>
        <label for="inst-snapshot">Vóór elke update een <strong>snapshot</strong> maken, zodat de update terug te zetten is (aanbevolen)</label>
    </div>
    <div class="regel">
        <input type="checkbox" id="inst-admintools" <?php echo $adminToolsAan ? 'checked' : ''; ?>>
        <label for="inst-admintools">Bij het plaatsen van de plugin het IP-adres van de monitor in Admin Tools op <em>Nooit deze IP-adressen blokkeren</em> zetten (als Admin Tools op de site staat)</label>
    </div>
    <div class="regel">
        <input type="checkbox" id="inst-kern-hoofdversie" <?php echo $kernHoofdversieAan ? 'checked' : ''; ?>>
        <label for="inst-kern-hoofdversie">Ook bijwerken naar een <strong>nieuwe hoofdversie van Joomla</strong> toestaan (alleen met een eigen knop per site op het tabblad Joomla, na Joomla's eigen controle vooraf; nooit automatisch)</label>
    </div>
    <div class="regel">
        <label>Cronjob alleen laten bijwerken tussen</label>
        <input type="number" id="inst-van" min="0" max="23" value="<?php echo h($vensterVan); ?>">
        <label>en</label>
        <input type="number" id="inst-tot" min="0" max="23" value="<?php echo h($vensterTot); ?>">
        <label>uur (leeg = altijd)</label>
    </div>
    <div class="regel">
        <button type="button" class="knop opslaan" onclick="slaInstellingenOp()">Opslaan</button>
    </div>
    <div class="klein"><?php echo hulpIcoon('updates-instellingen', 'Uitleg bij elke instelling: sites van anderen, de samenvattende e-mail, snapshots, Admin Tools en de cronjob.'); ?> Automatisch bijwerken op een vast tijdstip kan met een cronjob op <code>uitbreidingen/updates/cron_updates.php</code>, met dezelfde geheime cron-code als de gewone cronjob (Configuratie). Bij voorkeur via PHP op de commandoregel: <code>php uitbreidingen/updates/cron_updates.php --cron_code=...</code>, omdat het bijwerken van veel sites lang kan duren.</div>
</div>

<div id="voortgang">
    <div class="voortgang-kop" id="voortgang-kop">Bezig…</div>
    <div class="klein" id="voortgang-titel"></div>
    <ol id="voortgang-lijst"></ol>
    <div id="voortgang-slot"></div>
</div>
<?php
// ------------------------------------------------------------------
// Tabbladen
// ------------------------------------------------------------------
?>
<div class="tabbladen" role="tablist">
    <button type="button" class="tabblad<?php echo $tab === 'updates' ? ' actief' : ''; ?>" data-tab="updates" onclick="toonTab('updates')">📦 Beschikbare updates <span class="categorie-aantal" id="tab-aantal-updates"><?php echo count($updates); ?></span></button>
    <button type="button" class="tabblad<?php echo $tab === 'extensies' ? ' actief' : ''; ?>" data-tab="extensies" onclick="toonTab('extensies')" title="Extensies waarvoor Joomla zelf naar updates zoekt">🧩 Extensies <span class="categorie-aantal"><?php echo $aantalMetUpdatesite; ?></span></button>
    <button type="button" class="tabblad<?php echo $tab === 'joomla' ? ' actief' : ''; ?>" data-tab="joomla" onclick="toonTab('joomla')" title="Joomla zelf bijwerken">🏛️ Joomla<?php echo $kernAantalUpdates ? ' <span class="categorie-aantal" title="Sites met een Joomla-update">' . $kernAantalUpdates . '</span>' : ''; ?></button>
    <button type="button" class="tabblad<?php echo $tab === 'plugins' ? ' actief' : ''; ?>" data-tab="plugins" onclick="toonTab('plugins')">🔌 Updater-plugins
        <span id="plugin-tellers">
        <?php if ($sitesZonderPlugin): ?><span class="badge"><?php echo $sitesZonderPlugin; ?> zonder plugin</span><?php endif; ?>
        <?php if ($sitesVerouderd): ?><span class="badge"><?php echo $sitesVerouderd; ?> verouderd</span><?php endif; ?>
        <?php if ($sitesMetMelding): ?><span class="badge" title="Sites met een melding: een fout, uitgeschakelde updatesites of updates die uit staan"><span class="geel">⚠️ <?php echo $sitesMetMelding; ?></span></span><?php endif; ?>
        </span>
    </button>
    <button type="button" class="tabblad<?php echo $tab === 'geschiedenis' ? ' actief' : ''; ?>" data-tab="geschiedenis" onclick="toonTab('geschiedenis')">🕓 Geschiedenis</button>
</div>

<?php
// ------------------------------------------------------------------
// Tabblad: beschikbare updates
// ------------------------------------------------------------------
?>
<div class="tab-paneel" data-tab="updates" id="paneel-updates"<?php echo $tab === 'updates' ? '' : ' hidden'; ?>>
<div id="updates-lijst">
<div class="subtabs">
    <a href="<?php echo updatesPaginaUrl(['tab' => 'updates', 'weergave' => 'extensie']); ?>" data-behoud-tab class="subtab<?php echo $weergave === 'extensie' ? ' actief' : ''; ?>" style="text-decoration: none;">Per extensie</a>
    <a href="<?php echo updatesPaginaUrl(['tab' => 'updates', 'weergave' => 'site']); ?>" data-behoud-tab class="subtab<?php echo $weergave === 'site' ? ' actief' : ''; ?>" style="text-decoration: none;">Per website</a>
</div>

<?php if (!$updates): ?>
    <div class="leeg">Er staan geen updates klaar<?php echo $sitesZonderPlugin > 0 && $sitesZonderPlugin === count($sites) - $sitesUitgesloten ? ': installeer eerst de plugin op de sites (tabblad Updater-plugins)' : ''; ?>. Gebruik <em>Updates ophalen</em> om Joomla op de sites opnieuw te laten zoeken.</div>
<?php elseif ($weergave === 'extensie'):
    $groepen = [];
    foreach ($updates as $u) {
        $groepen[$u['ext_sleutel']][] = $u;
    }
    uasort($groepen, function ($a, $b) {
        // Joomla zelf bovenaan, daarna op naam.
        return [!updatesIsKern($a[0]), strtolower($a[0]['naam'])] <=> [!updatesIsKern($b[0]), strtolower($b[0]['naam'])];
    });
?>
    <table class="responsive-tabel">
        <tr>
            <th>Website<?php echo hulpIcoon('updates-lijst', 'De sites waarop deze extensie een update heeft.'); ?></th>
            <th>Versie<?php echo hulpIcoon('updates-versie', 'De versie op de site en de versie die Joomla aanbiedt. ℹ️ opent de informatie van de maker; 🔑 = er is een downloadsleutel ingevuld.'); ?></th>
            <th>Bij alles uitvoeren<?php echo hulpIcoon('updates-bij-alles', 'Wat Alle geactiveerde updates uitvoeren (en de cronjob) met deze update zou doen, met de reden. ✅ gaat mee, ✋ blijft staan, ⏸️ wordt overgeslagen.'); ?></th>
            <th>Instelling voor deze site<?php echo hulpIcoon('updates-standen-uitleg', 'Afwijkende keuze voor deze extensie op alleen deze site. Volgt = de instelling voor de extensie op alle sites geldt.'); ?></th>
            <th>Actie<?php echo hulpIcoon('updates-acties', 'Bijwerken installeert deze update nu. Versie overslaan slaat alleen deze versie over; een volgende versie verschijnt weer.'); ?></th>
            <th>Resultaat<?php echo hulpIcoon('updates-lijst', 'De uitkomst van de installatie. Een ⚠️ eronder: de voorkant van de site gaf daarna een fout; kijk op de site en zet zo nodig de snapshot terug.'); ?></th>
        </tr>
        <?php foreach ($groepen as $extSleutel => $groep):
            $extStand = $beleid[0][$extSleutel] ?? '';
            $heeftSleutel = (bool) array_filter($groep, function ($u) { return (int) $u['downloadsleutel'] === 1; });
        ?>
        <tr class="groepkop">
            <td colspan="3" data-label="Extensie">
                <?php echo updatesIsKern($groep[0]) ? '<strong>Joomla</strong>' : h($groep[0]['naam']); ?>
                <span class="klein"><?php echo updatesIsKern($groep[0]) ? 'Joomla zelf' : h($groep[0]['type']); ?> · <?php echo count($groep); ?> site(s)</span>
                <?php echo $heeftSleutel ? '<span class="badge" title="Op een of meer sites is een downloadsleutel ingesteld">🔑</span>' : ''; ?>
            </td>
            <td colspan="3" data-label="Op alle sites">
                <span class="klein">Op alle sites:</span><?php echo hulpIcoon('updates-standen-uitleg', 'Wat er met de updates van deze extensie mag gebeuren op alle sites: handmatig, automatisch (alleen kleine reparaties, binnen dezelfde hoofdversie, of alles), of overslaan.'); ?>
                <?php echo updatesIsKern($groep[0])
                    ? standKeuzelijst($extStand !== '' ? $extStand : 'handmatig', false, '', ['site' => 0, 'ext' => $extSleutel], updatesKernStanden())
                    : standKeuzelijst($extStand, true, $standaardLabel, ['site' => 0, 'ext' => $extSleutel]); ?>
            </td>
        </tr>
        <?php foreach ($groep as $u) {
            echo updateRij($u, $sites, $beleid, $magBijwerken, 'extensie');
        } ?>
        <?php endforeach; ?>
    </table>
<?php else:
    $perSite = [];
    foreach ($updates as $u) {
        $perSite[(int) $u['site_id']][] = $u;
    }
?>
    <table class="responsive-tabel">
        <tr>
            <th>Extensie<?php echo hulpIcoon('updates-lijst', 'De extensies met een update op deze site, met hun soort (component, plugin, pakket, ...).'); ?></th>
            <th>Versie<?php echo hulpIcoon('updates-versie', 'De versie op de site en de versie die Joomla aanbiedt. ℹ️ opent de informatie van de maker; 🔑 = er is een downloadsleutel ingevuld.'); ?></th>
            <th>Bij alles uitvoeren<?php echo hulpIcoon('updates-bij-alles', 'Wat Alle geactiveerde updates uitvoeren (en de cronjob) met deze update zou doen, met de reden. ✅ gaat mee, ✋ blijft staan, ⏸️ wordt overgeslagen.'); ?></th>
            <th>Instelling voor deze site<?php echo hulpIcoon('updates-standen-uitleg', 'Afwijkende keuze voor deze extensie op alleen deze site. Volgt = de instelling voor de extensie op alle sites geldt.'); ?></th>
            <th>Actie<?php echo hulpIcoon('updates-acties', 'Bijwerken installeert deze update nu. Versie overslaan slaat alleen deze versie over; een volgende versie verschijnt weer.'); ?></th>
            <th>Resultaat<?php echo hulpIcoon('updates-lijst', 'De uitkomst van de installatie. Een ⚠️ eronder: de voorkant van de site gaf daarna een fout; kijk op de site en zet zo nodig de snapshot terug.'); ?></th>
        </tr>
        <?php foreach ($sites as $sid => $site):
            if (empty($perSite[$sid])) {
                continue;
            }
        ?>
        <tr class="groepkop">
            <td colspan="6" data-label="Website"><a class="domein-link" href="https://<?php echo h($site['domein']); ?>" target="_blank" rel="noopener"><?php echo h($site['domein']); ?></a> <span class="klein"><?php echo count($perSite[$sid]); ?> update(s)</span></td>
        </tr>
        <?php foreach ($perSite[$sid] as $u) {
            echo updateRij($u, $sites, $beleid, $magBijwerken, 'site');
        } ?>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

</div>
</div>

<?php
// ------------------------------------------------------------------
// Tabblad: extensies (per extensie of per site)
// ------------------------------------------------------------------
?>
<?php
$geenUpdatesiteTekst = '<span class="klein">Geen eigen updatesite; gaat mee met het onderdeel waarmee hij is geïnstalleerd</span>'
    . hulpIcoon('updates-geen-updatesite', 'Joomla zoekt alleen updates voor extensies met een eigen updatesite. Een extensie zonder updatesite is meestal meegeïnstalleerd met een component of pakket en wordt daarmee ook bijgewerkt.');
?>
<div class="tab-paneel" data-tab="extensies" id="extensie-instellingen"<?php echo $tab === 'extensies' ? '' : ' hidden'; ?>>
    <p class="klein">De extensies van derden die de scans op de sites in deze categorie hebben gevonden, ook als er nu geen update voor is. Kies wat er met hun updates mag gebeuren; dat geldt meteen ook voor toekomstige updates. Standaard zie je alleen extensies waarvoor Joomla zelf naar updates zoekt: <?php echo $aantalMetUpdatesite; ?> van de <?php echo count($extensieGroepen); ?>. De andere <?php echo $aantalZonderUpdatesite; ?> hebben geen eigen updatesite; meestal zijn dat plugins en modules die met een component of pakket zijn meegeïnstalleerd en daarmee ook worden bijgewerkt.<?php echo hulpIcoon('updates-extensie-instellingen', 'Stel per extensie vooraf in wat er met haar updates mag gebeuren, ook als er nu geen update klaarstaat. De lijst komt uit de scans van de sites.'); ?></p>
    <div class="paneel standaard-paneel">
        <div class="regel">
            <label for="standaard-stand"><strong>Standaard voor alle extensies:</strong></label>
            <select id="standaard-stand" onchange="slaStandaardOp(this)" data-vorige="<?php echo h($standaardStand); ?>">
                <?php foreach (updatesStanden() as $waarde => $label): ?>
                <option value="<?php echo h($waarde); ?>"<?php echo $standaardStand === $waarde ? ' selected' : ''; ?>><?php echo h($label); ?></option>
                <?php endforeach; ?>
            </select>
            <?php echo hulpIcoon('updates-standaard', 'Geldt voor elke extensie waarvoor je bij Op alle sites Standaard laat staan, ook voor extensies die er later bij komen. Kies alleen bij de uitzonderingen iets anders.'); ?>
        </div>
        <div class="klein">Geldt voor elke extensie waarvoor bij <em>Op alle sites</em> <em>Standaard</em> staat, ook voor extensies die er later bij komen. Zo hoef je alleen bij de uitzonderingen iets anders te kiezen; filter daarvoor bij <em>Instelling</em> op <em>Met een eigen keuze</em>.</div>
    </div>

    <div class="subtabs">
        <button type="button" class="subtab<?php echo $extWeergave === 'extensie' ? ' actief' : ''; ?>" data-ext-weergave="extensie" onclick="toonExtWeergave('extensie')">Per extensie</button>
        <button type="button" class="subtab<?php echo $extWeergave === 'site' ? ' actief' : ''; ?>" data-ext-weergave="site" onclick="toonExtWeergave('site')">Per website</button>
    </div>

    <?php if ($extensieGroepen): ?>
    <div class="filterbalk" id="extensie-filters">
        <div class="filter-veld">
            <label for="filter-soort">Toon</label>
            <select id="filter-soort" onchange="filterExtensies()">
                <option value="updatesite" selected>Met eigen updatesite (<?php echo $aantalMetUpdatesite; ?>)</option>
                <option value="zonder">Zonder eigen updatesite (<?php echo $aantalZonderUpdatesite; ?>)</option>
                <option value="alle">Alle extensies (<?php echo count($extensieGroepen); ?>)</option>
            </select>
        </div>
        <div class="filter-veld">
            <label for="filter-extensie">Extensie</label>
            <select id="filter-extensie" onchange="filterExtensies()">
                <option value="">Alle extensies (<?php echo count($extensieGroepen); ?>)</option>
                <?php foreach ($extensieGroepen as $sleutel => $groep): ?>
                <option value="<?php echo h($sleutel); ?>"><?php echo h($groep['naam']); ?><?php echo $groep['updatesite'] ? '' : ' (geen eigen updatesite)'; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-veld">
            <label for="filter-site">Website</label>
            <select id="filter-site" onchange="filterExtensies()">
                <option value="">Alle websites (<?php echo count($extensiesPerSite); ?>)</option>
                <?php foreach ($sites as $sid => $site): if (empty($extensiesPerSite[$sid])) { continue; } ?>
                <option value="<?php echo $sid; ?>"><?php echo h($site['domein']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-veld">
            <label for="filter-stand">Instelling</label>
            <select id="filter-stand" onchange="filterExtensies()">
                <option value="">Alle instellingen</option>
                <option value="standaard">Volgt de standaard</option>
                <option value="eigen">Met een eigen keuze (wijkt af van de standaard)</option>
                <option value="handmatig">Handmatig</option>
                <option value="automatisch">Automatisch (alle varianten)</option>
                <option value="overslaan">Overslaan</option>
                <option value="afwijkend">Met een afwijkende keuze per site</option>
            </select>
        </div>
        <div class="filter-veld">
            <label for="extensie-zoeken">Zoeken</label>
            <input type="search" id="extensie-zoeken" placeholder="Deel van een naam" oninput="filterExtensies()">
        </div>
        <div class="filter-veld">
            <button type="button" class="knop" onclick="wisExtensieFilters()">Filters wissen</button>
        </div>
        <div class="filter-veld filter-uitkomst klein" id="filter-uitkomst"></div>
    </div>
    <?php endif; ?>

    <?php if (!$extensieGroepen): ?>
        <div class="leeg">Nog geen extensies bekend. Laat eerst de sites scannen.</div>
    <?php else: ?>
    <div id="ext-weergave-extensie"<?php echo $extWeergave === 'extensie' ? '' : ' hidden'; ?>>
    <table class="responsive-tabel" id="extensie-tabel">
        <tr>
            <th>Extensie<?php echo hulpIcoon('updates-extensie-instellingen', 'De extensie en haar soort (component, plugin, pakket, ...). Onderdelen van een pakket staan er niet los in: het pakket wordt als geheel bijgewerkt.'); ?></th>
            <th>Sites<?php echo hulpIcoon('updates-extensie-instellingen', 'Op hoeveel sites (in deze categorie) de extensie staat.'); ?></th>
            <th>Op alle sites<?php echo hulpIcoon('updates-standen-uitleg', 'Wat er op alle sites met de updates van deze extensie mag gebeuren: handmatig, automatisch (alleen kleine reparaties, binnen dezelfde hoofdversie, of alles), of overslaan.'); ?></th>
            <th>Per site<?php echo hulpIcoon('updates-standen-uitleg', 'Kies voor één site iets anders dan voor alle sites. Volgt extensie = de instelling voor alle sites geldt.'); ?></th>
        </tr>
        <?php foreach ($extensieGroepen as $sleutel => $groep):
            $afwijkend = 0;
            foreach ($groep['sites'] as $sid => $info) {
                if (isset($beleid[$sid][$sleutel])) {
                    $afwijkend++;
                }
            }
            $zoektekst = mb_strtolower($groep['naam'] . ' ' . $sleutel . ' ' . implode(' ', array_column($groep['sites'], 'domein')));
        ?>
        <tr data-zoek="<?php echo h($zoektekst); ?>" data-ext="<?php echo h($sleutel); ?>" data-sites="<?php echo h(' ' . implode(' ', array_keys($groep['sites'])) . ' '); ?>" data-updatesite="<?php echo $groep['updatesite'] ? '1' : '0'; ?>"<?php echo $groep['updatesite'] ? '' : ' class="gedimd"'; ?>>
            <td data-label="Extensie"><?php echo h($groep['naam']); ?> <span class="klein"><?php echo h($groep['type']); ?></span></td>
            <td data-label="Sites"><?php echo count($groep['sites']); ?></td>
            <?php if (!$groep['updatesite']): ?>
            <td data-label="Op alle sites" colspan="2"><?php echo $geenUpdatesiteTekst; ?></td>
            <?php else: ?>
            <td data-label="Op alle sites"><?php echo standKeuzelijst($beleid[0][$sleutel] ?? '', true, $standaardLabel, ['site' => 0, 'ext' => $sleutel]); ?></td>
            <td data-label="Per site">
                <details>
                    <summary style="margin: 0; font-size: 14px; font-weight: normal;"><?php echo $afwijkend ? $afwijkend . ' afwijkend' : 'Per site instellen'; ?></summary>
                    <div class="per-site-lijst">
                        <?php foreach ($groep['sites'] as $sid => $info): ?>
                            <span><?php echo h($info['domein']); ?> <span class="klein"><?php echo h($info['versie']); ?><?php echo isset($beleid[$sid]['']) ? ' · site altijd handmatig' : ''; ?></span></span>
                            <?php echo standKeuzelijst($beleid[$sid][$sleutel] ?? '', true, 'Volgt extensie', ['site' => $sid, 'ext' => $sleutel]); ?>
                        <?php endforeach; ?>
                    </div>
                </details>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>

    <div id="ext-weergave-site"<?php echo $extWeergave === 'site' ? '' : ' hidden'; ?>>
    <table class="responsive-tabel" id="extensie-site-tabel">
        <tr>
            <th>Extensie<?php echo hulpIcoon('updates-extensie-instellingen', 'De extensies die op deze site staan, met hun soort (component, plugin, pakket, ...). Onderdelen van een pakket staan er niet los in.'); ?></th>
            <th>Versie<?php echo hulpIcoon('updates-extensie-instellingen', 'De versie die bij de laatste scan op de site stond.'); ?></th>
            <th>Op alle sites<?php echo hulpIcoon('updates-standen-uitleg', 'De instelling voor deze extensie op alle sites. Wijzig je die hier, dan geldt dat ook voor de andere sites.'); ?></th>
            <th>Alleen op deze site<?php echo hulpIcoon('updates-standen-uitleg', 'Kies voor alleen deze site iets anders. Volgt extensie = de instelling voor alle sites geldt.'); ?></th>
        </tr>
        <?php foreach ($sites as $sid => $site):
            if (empty($extensiesPerSite[$sid])) {
                continue;
            }
            $siteStand = $beleid[$sid][''] ?? '';
            if (!empty($eerdereSite)) {
                echo '<tr class="tussenruimte" data-tussen="' . $sid . '" aria-hidden="true"><td colspan="4"></td></tr>';
            }
            $eerdereSite = true;
        ?>
        <tr class="groepkop sitekop" data-site-kop="<?php echo $sid; ?>" data-domein="<?php echo h(mb_strtolower($site['domein'])); ?>">
            <td colspan="2" data-label="Website">🌐 <a href="https://<?php echo h($site['domein']); ?>" target="_blank" rel="noopener"><?php echo h($site['domein']); ?></a> <span class="kop-aantal"><?php echo count(array_filter($extensiesPerSite[$sid], function ($x) { return $x['updatesite']; })); ?> extensie(s)</span><?php echo isset($uitgesloten[$sid]) ? ' <span class="uitgesloten-label">🚫 uitgesloten van updates</span>' : ''; ?></td>
            <td colspan="2" data-label="Hele site">
                <span class="klein">Hele site:</span><?php echo hulpIcoon('updates-site-instelling', 'Altijd handmatig: van deze site gaat nooit iets automatisch mee, tenzij je voor één extensie op deze site zelf iets anders kiest.'); ?>
                <select class="stand-keuze" data-site="<?php echo $sid; ?>" data-ext="" onchange="slaBeleidOp(this)">
                    <option value="volgen" <?php echo $siteStand === '' ? 'selected' : ''; ?>>Volgt de instellingen per extensie</option>
                    <option value="handmatig" <?php echo $siteStand === 'handmatig' ? 'selected' : ''; ?>>Altijd handmatig</option>
                </select>
            </td>
        </tr>
        <?php foreach ($extensiesPerSite[$sid] as $sleutel => $ext): ?>
        <tr class="onder-kop<?php echo $ext['updatesite'] ? '' : ' gedimd'; ?>" data-site-rij="<?php echo $sid; ?>" data-ext="<?php echo h($sleutel); ?>" data-updatesite="<?php echo $ext['updatesite'] ? '1' : '0'; ?>" data-zoek="<?php echo h(mb_strtolower($ext['naam'] . ' ' . $sleutel)); ?>">
            <td data-label="Extensie"><?php echo h($ext['naam']); ?> <span class="klein"><?php echo h($ext['type']); ?></span></td>
            <td data-label="Versie"><?php echo h($ext['versie']); ?></td>
            <?php if (!$ext['updatesite']): ?>
            <td data-label="Updates" colspan="2"><?php echo $geenUpdatesiteTekst; ?></td>
            <?php else: ?>
            <td data-label="Op alle sites"><?php echo standKeuzelijst($beleid[0][$sleutel] ?? '', true, $standaardLabel, ['site' => 0, 'ext' => $sleutel]); ?></td>
            <td data-label="Alleen op deze site"><?php echo standKeuzelijst($beleid[$sid][$sleutel] ?? '', true, 'Volgt extensie', ['site' => $sid, 'ext' => $sleutel]); ?></td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php
// ------------------------------------------------------------------
// Tabblad: Joomla zelf bijwerken
// ------------------------------------------------------------------
$kernStandaard = $beleid[0][UPDATES_KERN_SLEUTEL] ?? 'handmatig';
?>
<div class="tab-paneel" data-tab="joomla" id="paneel-joomla"<?php echo $tab === 'joomla' ? '' : ' hidden'; ?>>
    <p class="klein">Joomla zelf bijwerken, met dezelfde onderdelen als de Joomla-update in het beheer van Joomla. Er wordt vooraf <strong>geen back-up</strong> van de website gemaakt: de enige back-up is die van de hostingpartij, als die er is. Daarom vraagt de monitor vóór elke Joomla-update om akkoord.<?php echo hulpIcoon('updates-joomla', 'Joomla zelf bijwerken: binnen de huidige hoofdversie (handmatig of automatisch) of, als dat in de Instellingen aanstaat, naar een nieuwe hoofdversie (alleen met een eigen knop, na Joomla\'s eigen controle vooraf).'); ?></p>

    <div class="paneel standaard-paneel">
        <div class="regel">
            <label><strong>Joomla-updates op alle sites:</strong></label>
            <?php echo standKeuzelijst($kernStandaard, false, '', ['site' => 0, 'ext' => UPDATES_KERN_SLEUTEL], updatesKernStanden()); ?>
            <?php echo hulpIcoon('updates-joomla-standen', 'Handmatig: alleen met de knop. Automatisch: gaat mee met Alle geactiveerde updates uitvoeren en de cronjob, alleen binnen de huidige hoofdversie. Per site kun je hieronder iets anders kiezen.'); ?>
        </div>
        <div class="klein">Een nieuwe hoofdversie gaat nooit automatisch. <?php echo $kernHoofdversieAan
            ? 'Bijwerken naar een nieuwe hoofdversie staat aan (Instellingen): daarvoor staat hieronder een eigen knop zodra die beschikbaar is.'
            : 'Bijwerken naar een nieuwe hoofdversie staat uit; zet het aan bij <em>Instellingen</em> als je dat wilt.'; ?></div>
    </div>

    <table class="responsive-tabel" id="kern-tabel">
        <tr>
            <th>Website</th>
            <th>Joomla nu<?php echo hulpIcoon('updates-joomla', 'De versie van Joomla bij het laatste contact met de plugin.'); ?></th>
            <th>Update<?php echo hulpIcoon('updates-joomla', 'Een nieuwere versie binnen de huidige hoofdversie, gevonden bij het laatste ophalen.'); ?></th>
            <?php if ($kernHoofdversieAan): ?><th>Nieuwe hoofdversie<?php echo hulpIcoon('updates-joomla-hoofdversie', 'Een nieuwe hoofdversie, gevonden bij het laatste ophalen. Bijwerken alleen met de knop, na Joomla\'s eigen controle.'); ?></th><?php endif; ?>
            <th>Instelling voor deze site<?php echo hulpIcoon('updates-joomla-standen', 'Afwijkende keuze voor Joomla op alleen deze site. Volgt = de instelling voor alle sites geldt. Kies Handmatig om deze site uit te sluiten van automatisch bijwerken van Joomla.'); ?></th>
            <th>Actie</th>
            <th>Resultaat</th>
        </tr>
        <?php foreach ($sites as $sid => $site):
            $k       = $koppelingen[$sid] ?? null;
            $isUit   = isset($uitgesloten[$sid]);
            $kernU   = $kernUpdates[$sid] ?? null;
            $volgend = $kernHoofdversieAan ? (string) ($k['kern_volgende'] ?? '') : '';
            $pluginOk = $k !== null && !empty($k['plugin_versie']) && version_compare((string) $k['plugin_versie'], '2.1.0', '>=');
        ?>
        <tr id="kern-<?php echo $sid; ?>"<?php echo $isUit ? ' class="rij-uitgesloten"' : ''; ?>>
            <td data-label="Website"><a class="domein-link" href="https://<?php echo h($site['domein']); ?>" target="_blank" rel="noopener"><?php echo h($site['domein']); ?></a></td>
            <td data-label="Joomla nu"><?php echo h($k['joomla_versie'] ?? '-'); ?></td>
            <td data-label="Update"><?php echo $kernU ? '<strong>' . h($kernU['nieuwe_versie']) . '</strong>' : '<span class="klein">-</span>'; ?></td>
            <?php if ($kernHoofdversieAan): ?><td data-label="Nieuwe hoofdversie"><?php echo $volgend !== '' ? '<strong>' . h($volgend) . '</strong>' : '<span class="klein">-</span>'; ?></td><?php endif; ?>
            <td data-label="Instelling voor deze site"><?php echo $isUit ? '<span class="klein">-</span>' : standKeuzelijst($beleid[$sid][UPDATES_KERN_SLEUTEL] ?? '', true, 'Volgt alle sites', ['site' => $sid, 'ext' => UPDATES_KERN_SLEUTEL], updatesKernStanden()); ?></td>
            <td data-label="Actie" class="acties">
                <?php if ($isUit): ?>
                    <span class="klein">🚫 Uitgesloten van updates</span>
                <?php elseif ($k === null || empty($k['plugin_versie'])): ?>
                    <span class="klein">Geen plugin</span>
                <?php elseif (!$pluginOk): ?>
                    <span class="klein geel">Werk eerst de plugin bij (tabblad Updater-plugins)</span>
                <?php elseif (!$magBijwerken): ?>
                    <span class="klein">Bijwerken staat uit voor sites van anderen</span>
                <?php else: ?>
                    <?php if ($kernU): ?>
                    <button type="button" class="knop knop-klein" onclick="kernBijwerken(this, <?php echo $sid; ?>, 'binnen', <?php echo h(json_encode(['van' => $k['joomla_versie'], 'naar' => $kernU['nieuwe_versie']])); ?>)">Bijwerken naar <?php echo h($kernU['nieuwe_versie']); ?></button>
                    <?php endif; ?>
                    <?php if ($volgend !== ''): ?>
                    <button type="button" class="knop knop-klein" onclick="kernBijwerken(this, <?php echo $sid; ?>, 'volgende', <?php echo h(json_encode(['van' => $k['joomla_versie'], 'naar' => $volgend])); ?>)" title="Eerst Joomla's eigen controle vooraf, daarna bijwerken">Naar Joomla <?php echo h($volgend); ?> (nieuwe hoofdversie)</button>
                    <?php endif; ?>
                    <?php if (!$kernU && $volgend === ''): ?><span class="klein">Bijgewerkt</span><?php endif; ?>
                <?php endif; ?>
            </td>
            <td data-label="Resultaat" class="resultaat"><?php echo !empty($k['kern_fout']) ? '<span class="klein rood" title="' . h($k['kern_fout']) . '">Kon bij het ophalen niet zoeken naar Joomla-updates</span>' : ''; ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php
// ------------------------------------------------------------------
// Tabblad: updater-plugins op de sites
// ------------------------------------------------------------------
?>
<div class="tab-paneel" data-tab="plugins" id="paneel-plugins"<?php echo $tab === 'plugins' ? '' : ' hidden'; ?>>
    <p class="klein">De plugin <strong>Compact Updater</strong> op elke site, waarmee de monitor updates ophaalt en installeert. De monitor zet hem zelf neer via FTP/SFTP; er is geen Joomla-account voor nodig. Is dit eenmaal ingericht, dan hoef je hier alleen nog te kijken als er een melding op dit tabblad staat.<?php echo hulpIcoon('updates-plugin', 'De plugin op elke site waarmee de monitor updates ophaalt en installeert. De monitor zet hem zelf neer via FTP/SFTP; er is geen Joomla-account voor nodig.'); ?></p>


    <div class="werkbalk" id="plugin-knoppen">
        <?php // Alleen een knop als er echt iets te doen is; anders een regel waarom niet. ?>
        <?php if ($sitesTePlaatsen > 0): ?>
        <button type="button" class="knop knop-blauw" onclick="rolPluginUitOpAlle(false)">🔌 Plugin installeren op <?php echo $sitesTePlaatsen; ?> site(s) zonder plugin</button>
        <?php endif; ?>
        <?php if ($sitesVerouderd > 0): ?>
        <button type="button" class="knop knop-blauw" onclick="rolPluginUitOpAlle(true)">⬆️ <?php echo $sitesVerouderd; ?> verouderde plugin(s) bijwerken</button>
        <?php endif; ?>
        <?php if ($sitesTePlaatsen === 0 && $sitesVerouderd === 0): ?>
        <span class="groen"><strong>✅ <?php echo $sitesZonderFtp ? 'Alle sites waarop dat kan, hebben' : 'Alle sites hebben'; ?> de actuele plugin.</strong></span>
        <?php elseif ($sitesTePlaatsen === 0): ?>
        <span class="groen">✅ Geen sites meer zonder plugin.</span>
        <?php endif; ?>
        <?php if ($sitesZonderFtp > 0): ?>
        <span class="geel">⚠️ <?php echo $sitesZonderFtp; ?> site(s) zonder plugin hebben geen FTP-gegevens; vul die in bij de site-instellingen van de monitor, of sluit de site uit.</span>
        <?php endif; ?>
        <span class="klein">Actuele versie: <?php echo h($pluginVersie); ?><?php echo $sitesUitgesloten ? ' · 🚫 ' . $sitesUitgesloten . ' site(s) uitgesloten van updates' : ''; ?></span>
    </div>

    <table class="responsive-tabel">
        <tr>
            <th>Website<?php echo hulpIcoon('updates-plugin', 'De site zoals die in de monitor staat; de naam opent de website. "geen FTP" betekent dat de monitor de plugin hier niet kan neerzetten.'); ?></th>
            <th>Plugin<?php echo hulpIcoon('updates-plugin-status', 'De versie van de plugin op de site, of Niet geïnstalleerd. Daaronder eventueel de laatste fout, of dat updates op de site uit staan of updatesites uitgeschakeld zijn.'); ?></th>
            <th>Joomla<?php echo hulpIcoon('updates-joomla', 'De versie van Joomla op de site, zoals de plugin die doorgeeft bij elk contact.'); ?></th>
            <th>Laatst opgehaald<?php echo hulpIcoon('updates-opgehaald', 'Wanneer Joomla op deze site voor het laatst via de monitor naar updates heeft gezocht.'); ?></th>
            <th>Instelling voor de hele site<?php echo hulpIcoon('updates-site-instelling', 'Altijd handmatig: van deze site gaat nooit iets automatisch mee, tenzij je voor één extensie op deze site zelf iets anders kiest.'); ?></th>
            <th>Actie<?php echo hulpIcoon('updates-plugin-acties', 'Plugin (opnieuw) plaatsen, updates ophalen voor deze ene site, de updatesites in Joomla herbouwen, de plugin verwijderen, of de site uitsluiten van updates.'); ?></th>
            <th>Resultaat<?php echo hulpIcoon('updates-resultaat', 'De uitkomst van de laatste actie op deze regel.'); ?></th>
        </tr>
        <?php foreach ($sites as $sid => $site):
            $k = $koppelingen[$sid] ?? null;
            if ($k === null || empty($k['plugin_versie'])) {
                $status = '<span class="klein">Niet geïnstalleerd</span>';
                $knopTekst = 'Installeren';
            } else {
                $verouderd = version_compare((string) $k['plugin_versie'], $pluginVersie, '<');
                $status    = ($verouderd ? '<span class="geel">⚠️ ' : '<span class="groen">✅ ') . h($k['plugin_versie']) . ($verouderd ? ' (verouderd)' : '') . '</span>';
                if ((string) $k['toestaan'] === '0') {
                    $status .= '<br><span class="geel">Updates staan uit op de site</span>';
                }
                $knopTekst = $verouderd ? 'Bijwerken' : 'Opnieuw plaatsen';
            }
            if ($k !== null && !empty($k['laatste_fout'])) {
                $status .= '<br><span class="rood">' . h($k['laatste_fout']) . '</span>';
            }
            $uitgeschakeld = $k !== null ? (json_decode((string) $k['uitgeschakelde_locaties'], true) ?: []) : [];
            if ($uitgeschakeld) {
                $status .= '<br><span class="geel" title="' . h(implode(', ', $uitgeschakeld)) . '">⚠️ ' . count($uitgeschakeld)
                    . ' uitgeschakelde updatesite(s): daarvoor vindt Joomla geen updates</span>'
                    . '<div class="actie-onder"><button type="button" class="knop knop-blauw" onclick="locaties(this, ' . $sid . ', \'inschakelen\')" title="' . h(implode(', ', $uitgeschakeld)) . '">Updatesites inschakelen</button></div>';
            }
            $siteStand = $beleid[$sid][''] ?? '';
            $isUit     = isset($uitgesloten[$sid]);
            // Staat de plugin echt op de site? (Na een mislukte plaatsing is er
            // wel een koppeling, maar geen plugin.) De bestanden kunnen er ook
            // staan als de plugin daarna nog niet antwoordde.
            $pluginActief  = $k !== null && !empty($k['plugin_versie']);
            $pluginGeplaatst = $pluginActief || ($k !== null && !empty($k['geinstalleerd_op']));
            if ($isUit) {
                // Uitgesloten: geen meldingen over de plugin, alleen dat.
                $status = '<span class="uitgesloten-label">🚫 Uitgesloten van updates</span>'
                    . '<br><span class="klein">Sinds ' . h(date('d-m-Y', strtotime($uitgesloten[$sid]['sinds'])))
                    . ($uitgesloten[$sid]['reden'] !== '' ? ': ' . h($uitgesloten[$sid]['reden']) : '') . '</span>'
                    . ($k !== null && !empty($k['plugin_versie'])
                        ? '<br><span class="klein">De plugin (' . h($k['plugin_versie']) . ') staat nog op de site; de monitor gebruikt hem niet meer. Haal hem zo nodig weg met <em>Plugin verwijderen</em>.</span>'
                        : '');
            }
        ?>
        <tr id="plugin-<?php echo $sid; ?>" data-site="<?php echo $sid; ?>" data-uitgesloten="<?php echo $isUit ? '1' : '0'; ?>" data-ftp="<?php echo empty($site['ftp_host']) ? '0' : '1'; ?>"<?php echo $isUit ? ' class="rij-uitgesloten"' : ''; ?> data-heeft-plugin="<?php echo $k !== null && !empty($k['plugin_versie']) ? '1' : '0'; ?>" data-verouderd="<?php echo $k !== null && !empty($k['plugin_versie']) && version_compare((string) $k['plugin_versie'], $pluginVersie, '<') ? '1' : '0'; ?>">
            <td data-label="Website"><a class="domein-link" href="https://<?php echo h($site['domein']); ?>" target="_blank" rel="noopener"><?php echo h($site['domein']); ?></a><?php echo empty($site['ftp_host']) ? ' <span class="badge">geen FTP</span>' : ''; ?></td>
            <td data-label="Plugin"><?php echo $status; ?></td>
            <td data-label="Joomla"><?php echo h($k['joomla_versie'] ?? ''); ?></td>
            <td data-label="Laatst opgehaald"><?php echo $k !== null && $k['opgehaald'] ? h(date('d-m-Y H:i', strtotime($k['opgehaald']))) : '<span class="klein">-</span>'; ?></td>
            <?php if ($isUit): ?>
            <td data-label="Hele site"><span class="klein">-</span></td>
            <td data-label="Actie" class="acties">
                <button type="button" class="knop knop-klein knop-blauw" onclick="sluitUit(this, <?php echo $sid; ?>, false)" title="Deze site weer laten meedoen met het centraal bijwerken">Weer toelaten</button>
                <?php if ($pluginGeplaatst): ?>
                <button type="button" class="knop knop-klein" onclick="verwijderPlugin(this, <?php echo $sid; ?>)" <?php echo empty($site['ftp_host']) ? 'disabled' : ''; ?> title="De plugin van deze site halen (of alleen de sleutel van deze monitor, als een andere monitor hem ook gebruikt)">Plugin verwijderen</button>
                <?php endif; ?>
            </td>
            <?php else: ?>
            <td data-label="Hele site">
                <select class="stand-keuze" data-site="<?php echo $sid; ?>" data-ext="" onchange="slaBeleidOp(this)">
                    <option value="volgen" <?php echo $siteStand === '' ? 'selected' : ''; ?>>Volgt de instellingen per extensie</option>
                    <option value="handmatig" <?php echo $siteStand === 'handmatig' ? 'selected' : ''; ?>>Altijd handmatig</option>
                </select>
            </td>
            <td data-label="Actie" class="acties">
                <button type="button" class="knop knop-klein" onclick="rolPluginUit(this, <?php echo $sid; ?>)" <?php echo empty($site['ftp_host']) ? 'disabled' : ''; ?>><?php echo h($knopTekst); ?></button>
                <?php if ($pluginActief): ?>
                <button type="button" class="knop knop-klein" onclick="haalEenOp(this, <?php echo $sid; ?>)">Ophalen</button>
                <button type="button" class="knop knop-klein" onclick="locaties(this, <?php echo $sid; ?>, 'herbouwen')" title="Hetzelfde als herbouwen in het scherm Updatesites van Joomla: alle updatesites opnieuw opbouwen uit de extensies (downloadsleutels blijven bewaard)">Updatesites herbouwen</button>
                <?php endif; ?>
                <?php if ($pluginGeplaatst): ?>
                <button type="button" class="knop knop-klein" onclick="verwijderPlugin(this, <?php echo $sid; ?>)" <?php echo empty($site['ftp_host']) ? 'disabled' : ''; ?> title="De plugin van deze site halen (of alleen de sleutel van deze monitor, als een andere monitor hem ook gebruikt)">Plugin verwijderen</button>
                <?php endif; ?>
                <button type="button" class="knop knop-klein" onclick="sluitUit(this, <?php echo $sid; ?>, true)" title="Deze site nooit centraal bijwerken: geen plugin plaatsen, geen updates ophalen of installeren. De monitor blijft de site wel scannen.">🚫 Uitsluiten</button>
            </td>
            <?php endif; ?>
            <td data-label="Resultaat" class="resultaat"></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<div class="tab-paneel" data-tab="geschiedenis" id="paneel-geschiedenis"<?php echo $tab === 'geschiedenis' ? '' : ' hidden'; ?>>
<div id="geschiedenis-blok">
    <p class="klein">De laatste 100 updates en terugzettingen via de monitor.<?php echo hulpIcoon('updates-geschiedenis', 'De laatste 100 updates en terugzettingen via de monitor, met het resultaat en de melding van Joomla.'); ?></p>
    <?php if (!$geschiedenis): ?>
        <div class="leeg">Nog niets bijgewerkt via de monitor.</div>
    <?php else: ?>
    <table class="responsive-tabel">
        <tr>
            <th>Tijdstip<?php echo hulpIcoon('updates-geschiedenis', 'Wanneer, en hoe: losse knop, alles uitvoeren, cronjob of terugzetten.'); ?></th>
            <th>Website<?php echo hulpIcoon('updates-geschiedenis', 'De site waarop de update of terugzetting plaatsvond.'); ?></th>
            <th>Extensie<?php echo hulpIcoon('updates-geschiedenis', 'De bijgewerkte of teruggezette extensie.'); ?></th>
            <th>Versie<?php echo hulpIcoon('updates-geschiedenis', 'Van welke naar welke versie.'); ?></th>
            <th>Resultaat<?php echo hulpIcoon('updates-geschiedenis', 'Bijgewerkt, Mislukt, Onbekend (de site antwoordde niet op tijd; haal later opnieuw op), Overgeslagen of Teruggezet.'); ?></th>
            <th>Melding<?php echo hulpIcoon('updates-geschiedenis', 'De melding van Joomla of van de monitor.'); ?></th>
            <th>Snapshot<?php echo hulpIcoon('updates-snapshot', 'Terugzetten zet bestanden, eigen tabellen en registratie van de extensie terug naar vóór de update, ook als Joomla niet meer opstart.'); ?></th>
        </tr>
        <?php foreach ($geschiedenis as $g):
            $resultaatLabel = [
                'succes'       => '<span class="groen">✅ Bijgewerkt</span>',
                'mislukt'      => '<span class="rood">❌ Mislukt</span>',
                'onbekend'     => '<span class="geel">❓ Onbekend</span>',
                'overgeslagen' => '<span class="klein">⏭️ Overgeslagen</span>',
                'teruggezet'   => '<span class="groen">↩️ Teruggezet</span>',
            ][$g['resultaat']] ?? h($g['resultaat']);
            $bronLabel = ['handmatig' => 'losse knop', 'alles' => 'alles uitvoeren', 'cron' => 'cronjob', 'terugzetten' => 'terugzetten'][$g['bron']] ?? $g['bron'];
            if (!empty($g['teruggezet'])) {
                $snapshotCel = '<span class="klein">↩️ Teruggezet op ' . h(date('d-m-Y H:i', strtotime($g['teruggezet']))) . '</span>';
            } elseif (!empty($g['snapshot'])) {
                $snapshotCel = '<button type="button" class="knop knop-klein" onclick="zetTerug(this, ' . (int) $g['id'] . ', '
                    . h(json_encode($g['domein'] . ': ' . $g['naam'] . ' terug naar ' . $g['van_versie'])) . ')">Terugzetten</button>'
                    . ' <span class="klein">' . h((int) $g['snapshot_grootte'] >= 1048576
                        ? number_format(((int) $g['snapshot_grootte']) / 1048576, 1, ',', '.') . ' MB'
                        : max(1, (int) round(((int) $g['snapshot_grootte']) / 1024)) . ' kB') . '</span>';
            } else {
                $snapshotCel = '<span class="klein">-</span>';
            }
        ?>
        <tr>
            <td data-label="Tijdstip"><?php echo h(date('d-m-Y H:i', strtotime($g['tijdstip']))); ?> <span class="klein"><?php echo h($bronLabel); ?></span></td>
            <td data-label="Website"><?php echo h($g['domein']); ?></td>
            <td data-label="Extensie"><?php echo h($g['naam']); ?></td>
            <td data-label="Versie"><?php echo h($g['van_versie']); ?> → <?php echo h($g['naar_versie']); ?></td>
            <td data-label="Resultaat"><?php echo $resultaatLabel; ?><?php echo $g['controle'] ? '<br><span class="rood">' . h($g['controle']) . '</span>' : ''; ?></td>
            <td data-label="Melding" class="resultaat"><?php echo h(mb_substr((string) $g['bericht'], 0, 600)); ?></td>
            <td data-label="Snapshot" class="acties"><?php echo $snapshotCel; ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>
</div>

<div id="dialoog" class="dialoog" hidden>
    <div class="dialoog-venster" role="dialog" aria-modal="true" aria-labelledby="dialoog-tekst">
        <div id="dialoog-tekst" class="dialoog-tekst"></div>
        <input type="text" id="dialoog-invoer" class="dialoog-invoer" hidden>
        <div class="dialoog-knoppen">
            <button type="button" class="knop" id="dialoog-annuleer">Annuleren</button>
            <button type="button" class="knop knop-blauw" id="dialoog-ok">OK</button>
        </div>
    </div>
</div>

<script>
var CSRF = <?php echo json_encode(haalCsrfToken()); ?>;
var STANDAARD_STAND = <?php echo json_encode($standaardStand); ?>;
var SITES_SCAN_UPDATES = <?php echo json_encode($sitesScanUpdates); ?>;
var SITES_MET_PLUGIN = <?php echo json_encode(array_values(array_map('intval', array_keys(array_filter($sites, function ($s) use ($koppelingen, $uitgesloten) { return isset($koppelingen[(int) $s['id']]) && !isset($uitgesloten[(int) $s['id']]); }))))); ?>;
var SITE_NAMEN = <?php echo json_encode(array_map(function ($s) { return $s['domein']; }, $sites)); ?>;

// Pakt de velden in als één base64-veld "pb" (wordt in sessie_start.php
// weer uitgepakt), net als op de andere pagina's: zo weigert de firewall
// van de hostingpartij het verzoek niet om de inhoud.
function ingepakteBody(velden) {
    var bytes = new TextEncoder().encode(velden.toString());
    var binair = '';
    for (var i = 0; i < bytes.length; i++) {
        binair += String.fromCharCode(bytes[i]);
    }
    return 'pb=' + encodeURIComponent(btoa(binair));
}

function stuur(actie, gegevens) {
    var velden = new URLSearchParams();
    velden.append('actie', actie);
    velden.append('csrf_token', CSRF);
    Object.keys(gegevens || {}).forEach(function (k) { velden.append(k, gegevens[k]); });
    return fetch('updates_actie.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: ingepakteBody(velden),
        cache: 'no-store',
        credentials: 'same-origin'
    })
        .then(function (r) { return r.text(); })
        .then(function (tekst) {
            try {
                return JSON.parse(tekst);
            } catch (e) {
                return { succes: false, foutmelding: 'Onverwacht antwoord van de monitor: ' + tekst.substring(0, 200) };
            }
        })
        .catch(function (e) { return { succes: false, foutmelding: 'Verbinding met de monitor mislukt: ' + e }; });
}

function zetResultaat(rij, html) {
    var cel = rij ? rij.querySelector('.resultaat') : null;
    if (cel) {
        cel.innerHTML = html;
    }
}

function escape(tekst) {
    var d = document.createElement('div');
    d.textContent = tekst == null ? '' : String(tekst);
    return d.innerHTML;
}

// Voor tekst in een attribuut (zoals title="..."): ook aanhalingstekens.
function escapeAttr(tekst) {
    return escape(tekst).replace(/"/g, '&quot;');
}

function resultaatHtml(r) {
    if (r.resultaat === 'succes') {
        return '<span class="groen">✅ ' + escape(r.bericht || 'Bijgewerkt') + '</span>'
            + (r.controle ? '<br><span class="rood">' + escape(r.controle) + '</span>' : '');
    }
    if (r.resultaat === 'onbekend') {
        return '<span class="geel">❓ ' + escape(r.bericht) + '</span>';
    }
    if (r.resultaat === 'overgeslagen') {
        return '<span class="klein">⏭️ ' + escape(r.bericht) + '</span>';
    }
    return '<span class="rood">❌ ' + escape(r.bericht || r.foutmelding || 'Mislukt') + '</span>';
}

async function slaBeleidOp(select) {
    var waarde = select.value;
    // Joomla zelf automatisch laten bijwerken: dan gebeurt het zonder knop
    // (ook via de cronjob), dus nu akkoord vragen op de waarschuwing.
    if (select.dataset.ext === 'joomla:kern' && (waarde === 'patch' || waarde === 'minor')) {
        var wie = select.dataset.site === '0' ? 'op alle sites (tenzij per site anders gekozen)' : 'op ' + (SITE_NAMEN[select.dataset.site] || 'deze site');
        if (!await bevestig('Joomla ' + wie + ' automatisch laten bijwerken, ook via "Alle geactiveerde updates uitvoeren" en de cronjob?\n\n'
            + KERN_WAARSCHUWING, 'Akkoord, automatisch bijwerken')) {
            select.value = select.dataset.vorige || (select.dataset.site === '0' ? 'handmatig' : 'volgen');
            return;
        }
    }
    select.disabled = true;
    stuur('beleid', { site_id: select.dataset.site, ext_sleutel: select.dataset.ext, stand: waarde }).then(async function (r) {
        select.disabled = false;
        if (!r.succes) {
            await meld(r.foutmelding || 'Opslaan mislukt.');
            location.reload();
            return;
        }
        // Dezelfde instelling kan op meer plekken op de pagina staan.
        select.dataset.vorige = waarde;
        document.querySelectorAll('select.stand-keuze').forEach(function (ander) {
            if (ander !== select && ander.dataset.site === select.dataset.site && ander.dataset.ext === select.dataset.ext) {
                ander.value = waarde;
                ander.dataset.vorige = waarde;
            }
        });
        // "Volgt …" bij de sites laten zien wat er nu geldt.
        werkVolgLabelsBij();
        // De kolom "Bij alles uitvoeren" en de knop opnieuw laten berekenen.
        verversDelen(['updates-lijst', 'knop-alles']);
    });
}

// Een keuze "Volgt …" per site laat zien wát er dan geldt, bijvoorbeeld
// "Volgt alle sites: Overslaan (niet bijwerken)". Zo zie je meteen dat
// een wijziging bij "op alle sites" ook voor die sites geldt.
function werkVolgLabelsBij() {
    function gekozen(sel) {
        return sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].textContent : '';
    }
    document.querySelectorAll('select.stand-keuze').forEach(function (sel) {
        if (sel.dataset.site === '0' || !sel.dataset.ext) {
            return;
        }
        var optie = sel.querySelector('option[value="volgen"]');
        if (!optie) {
            return;
        }
        if (!optie.dataset.basis) {
            optie.dataset.basis = optie.textContent.replace(/:.*$/, '');
        }
        var tekst = '';
        // Een instelling voor de hele site gaat voor de instelling op alle sites.
        var site = document.querySelector('select.stand-keuze[data-site="' + sel.dataset.site + '"][data-ext=""]');
        var alle = document.querySelector('select.stand-keuze[data-site="0"][data-ext="' + CSS.escape(sel.dataset.ext) + '"]');
        if (site && site.value !== 'volgen') {
            tekst = 'instelling voor de hele site (' + gekozen(site) + ')';
        } else if (alle) {
            tekst = gekozen(alle);
        }
        optie.textContent = optie.dataset.basis + (tekst !== '' ? ': ' + tekst : '');
    });
}
werkVolgLabelsBij();

// ------------------------------------------------------------------
// Tabbladen
// ------------------------------------------------------------------

// Zet een waarde in de adresbalk (zonder de pagina te herladen) en in de
// links die de keuzes moeten onthouden, zodat verversen of van categorie
// wisselen op hetzelfde tabblad uitkomt.
function zetPaginaKeuze(naam, waarde) {
    try {
        var url = new URL(location.href);
        url.searchParams.set(naam, waarde);
        history.replaceState(null, '', url.toString());
        document.querySelectorAll('a[data-behoud-tab]').forEach(function (a) {
            var link = new URL(a.getAttribute('href'), location.href);
            // De links naar "per extensie/per site" bij de updates horen altijd bij dat tabblad.
            if (!(naam === 'tab' && a.closest('#updates-lijst'))) {
                link.searchParams.set(naam, waarde);
            }
            a.setAttribute('href', link.search);
        });
    } catch (e) { /* niet erg: dan onthoudt verversen het tabblad niet */ }
}

function toonTab(naam) {
    document.querySelectorAll('.tabblad').forEach(function (knop) {
        knop.classList.toggle('actief', knop.dataset.tab === naam);
    });
    document.querySelectorAll('.tab-paneel').forEach(function (paneel) {
        paneel.hidden = paneel.dataset.tab !== naam;
    });
    zetPaginaKeuze('tab', naam);
}

// Onderverdeling op het tabblad Extensies: per extensie of per site.
function toonExtWeergave(naam) {
    document.querySelectorAll('.subtab[data-ext-weergave]').forEach(function (knop) {
        knop.classList.toggle('actief', knop.dataset.extWeergave === naam);
    });
    ['extensie', 'site'].forEach(function (w) {
        var blok = document.getElementById('ext-weergave-' + w);
        if (blok) {
            blok.hidden = w !== naam;
        }
    });
    zetPaginaKeuze('ext_weergave', naam);
    filterExtensies();
}

function wisExtensieFilters() {
    ['filter-extensie', 'filter-site', 'filter-stand', 'extensie-zoeken'].forEach(function (id) {
        var veld = document.getElementById(id);
        if (veld) {
            veld.value = '';
        }
    });
    var soort = document.getElementById('filter-soort');
    if (soort) {
        soort.value = 'updatesite';
    }
    filterExtensies();
}

// Past een extensie bij de gekozen instelling? Gelezen uit de keuzelijsten
// in de regel zelf, zodat een net gewijzigde instelling meteen meetelt.
function pastBijStand(rij, stand, eigenSite) {
    if (stand === '') {
        return true;
    }
    if (rij.dataset.updatesite === '0') {
        // Zonder eigen updatesite is er geen instelling.
        return false;
    }
    if (stand === 'afwijkend') {
        var selector = eigenSite ? 'select[data-site="' + eigenSite + '"]' : '.per-site-lijst select';
        return Array.prototype.some.call(rij.querySelectorAll(selector), function (s) { return s.value !== 'volgen'; });
    }
    var alle = rij.querySelector('select[data-site="0"]');
    var waarde = alle ? alle.value : 'volgen';
    if (stand === 'standaard' || stand === 'eigen') {
        return (waarde === 'volgen') === (stand === 'standaard');
    }
    // De stand die nu geldt: "Standaard" betekent de standaard voor alle extensies.
    if (waarde === 'volgen') {
        waarde = STANDAARD_STAND;
    }
    if (stand === 'automatisch') {
        return waarde === 'patch' || waarde === 'minor' || waarde === 'alles';
    }
    return waarde === stand;
}

// Filtert beide weergaven op extensie, website, instelling en zoektekst.
function filterExtensies() {
    var veld = function (id) { var e = document.getElementById(id); return e ? e.value : ''; };
    var ext   = veld('filter-extensie');
    var site  = veld('filter-site');
    var stand = veld('filter-stand');
    var zoek  = veld('extensie-zoeken').trim().toLowerCase();
    var soort = veld('filter-soort') || 'updatesite';
    // Kies je één bepaalde extensie, dan altijd tonen, ook zonder updatesite.
    var pastSoort = function (rij) {
        return ext !== '' || soort === 'alle' || (soort === 'zonder') === (rij.dataset.updatesite === '0');
    };
    var aantalExt = 0;
    var aantalSites = 0;

    // Per extensie.
    document.querySelectorAll('#extensie-tabel tr[data-ext]').forEach(function (rij) {
        var toon = (ext === '' || rij.dataset.ext === ext)
            && pastSoort(rij)
            && (site === '' || rij.dataset.sites.indexOf(' ' + site + ' ') !== -1)
            && (zoek === '' || rij.dataset.zoek.indexOf(zoek) !== -1)
            && pastBijStand(rij, stand, '');
        rij.style.display = toon ? '' : 'none';
        if (toon) {
            aantalExt++;
        }
    });

    // Per website: past de zoekterm op de website zelf, dan telt die hele
    // site mee; een website zonder passende extensies verdwijnt.
    document.querySelectorAll('#extensie-site-tabel tr[data-site-kop]').forEach(function (kop) {
        var sid = kop.dataset.siteKop;
        var siteTreffer = zoek !== '' && kop.dataset.domein.indexOf(zoek) !== -1;
        var zichtbaar = 0;
        document.querySelectorAll('#extensie-site-tabel tr[data-site-rij="' + sid + '"]').forEach(function (rij) {
            var toon = (site === '' || site === sid)
                && (ext === '' || rij.dataset.ext === ext)
                && pastSoort(rij)
                && (zoek === '' || siteTreffer || rij.dataset.zoek.indexOf(zoek) !== -1)
                && pastBijStand(rij, stand, sid);
            rij.style.display = toon ? '' : 'none';
            if (toon) {
                zichtbaar++;
            }
        });
        kop.style.display = zichtbaar ? '' : 'none';
        if (zichtbaar) {
            aantalSites++;
        }
        var aantal = kop.querySelector('.kop-aantal');
        if (aantal) {
            aantal.textContent = zichtbaar + ' extensie(s)';
        }
        var tussen = document.querySelector('#extensie-site-tabel tr[data-tussen="' + sid + '"]');
        if (tussen) {
            // Geen lucht boven de eerste zichtbare website.
            tussen.style.display = zichtbaar && aantalSites > 1 ? '' : 'none';
        }
    });

    var uitkomst = document.getElementById('filter-uitkomst');
    if (uitkomst) {
        var actief = ext || site || stand || zoek || soort !== 'updatesite';
        var perSite = !document.getElementById('ext-weergave-site').hidden;
        uitkomst.textContent = !actief ? '' : (perSite ? aantalSites + ' website(s) gevonden' : aantalExt + ' extensie(s) gevonden');
    }
}

// De standaard voor alle extensies opslaan; daarna de pagina opnieuw laden,
// zodat elke keuzelijst en de kolom "Bij alles uitvoeren" die meteen tonen.
async function slaStandaardOp(select) {
    var labels = {};
    Array.prototype.forEach.call(select.options, function (o) { labels[o.value] = o.textContent; });
    var automatisch = select.value === 'patch' || select.value === 'minor' || select.value === 'alles';
    var vraag = 'Standaard voor alle extensies wijzigen naar "' + labels[select.value] + '"?\n\n'
        + 'Dit geldt voor elke extensie waarvoor bij Op alle sites "Standaard" staat, ook voor extensies die er later bij komen.'
        + (automatisch ? '\n\nUpdates van die extensies gaan dan automatisch mee met "Alle geactiveerde updates uitvoeren" en de cronjob.' : '');
    if (!await bevestig(vraag)) {
        select.value = select.dataset.vorige;
        return;
    }
    select.disabled = true;
    stuur('standaard', { stand: select.value }).then(async function (r) {
        if (!r.succes) {
            await meld(r.foutmelding || 'Opslaan mislukt.');
        }
        location.reload();
    });
}

// ------------------------------------------------------------------
// Eigen vensters voor vragen en meldingen
// ------------------------------------------------------------------
// In plaats van confirm(), alert() en prompt() van de browser: die kan een
// browser stilletjes blokkeren (bijvoorbeeld na "Deze pagina geen extra
// dialoogvensters laten maken"), waarna een knop niets lijkt te doen.
// Dit venster hoort bij de pagina zelf en is niet te blokkeren.
//
// toonDialoog() geeft een Promise die bij OK true geeft (of de ingevulde
// tekst, bij soort 'tekst') en bij Annuleren/Escape null.
function toonDialoog(tekst, soort, standaard, okTekst) {
    return new Promise(function (klaar) {
        var blok    = document.getElementById('dialoog');
        var invoer  = document.getElementById('dialoog-invoer');
        var ok      = document.getElementById('dialoog-ok');
        var annuleer = document.getElementById('dialoog-annuleer');
        document.getElementById('dialoog-tekst').textContent = tekst;
        ok.textContent = okTekst || 'OK';
        invoer.hidden = soort !== 'tekst';
        invoer.value = standaard || '';
        annuleer.hidden = soort === 'meld';
        blok.hidden = false;
        (soort === 'tekst' ? invoer : ok).focus();

        function sluit(waarde) {
            blok.hidden = true;
            ok.onclick = annuleer.onclick = null;
            document.removeEventListener('keydown', toets, true);
            klaar(waarde);
        }
        function toets(e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                sluit(soort === 'meld' ? true : null);
            } else if (e.key === 'Enter' && document.activeElement !== annuleer) {
                e.preventDefault();
                sluit(soort === 'tekst' ? invoer.value : true);
            }
        }
        ok.onclick = function () { sluit(soort === 'tekst' ? invoer.value : true); };
        annuleer.onclick = function () { sluit(null); };
        document.addEventListener('keydown', toets, true);
    });
}

function bevestig(tekst, okTekst) {
    return toonDialoog(tekst, 'bevestig', '', okTekst).then(function (w) { return w !== null; });
}

// ------------------------------------------------------------------
// Joomla zelf bijwerken
// ------------------------------------------------------------------

// De waarschuwing die vóór elke Joomla-update akkoord moet krijgen.
var KERN_WAARSCHUWING = '⚠️ Let op: er wordt vooraf GEEN back-up van de website gemaakt (bijvoorbeeld met Akeeba Backup).\n'
    + 'De enige back-up is die van de hostingpartij, als die er is. Gaat er iets mis, dan moet die back-up van de hosting worden teruggezet.';

function kernVraag(domein, van, naar) {
    return KERN_WAARSCHUWING + '\n\nJoomla op ' + domein + ' bijwerken van ' + (van || '?') + ' naar ' + naar + '?';
}

// Een knop op het tabblad Joomla: bijwerken binnen de hoofdversie, of naar
// een nieuwe hoofdversie (eerst Joomla's eigen controle vooraf).
async function kernBijwerken(knop, siteId, doel, info) {
    var rij = document.getElementById('kern-' + siteId);
    var domein = SITE_NAMEN[siteId] || 'deze site';
    var extra = '';

    if (doel === 'volgende') {
        knop.disabled = true;
        zetResultaat(rij, '<span class="klein">⏳ Joomla controleert of de site klaar is voor Joomla ' + escape(info.naar) + '…</span>');
        var c = await stuur('kern_controle', { site_id: siteId, doel: 'volgende' });
        knop.disabled = false;
        if (!c.succes) {
            zetResultaat(rij, '<span class="rood">❌ ' + escape(c.foutmelding) + '</span>');
            return;
        }
        if (c.status === 'geen_update') {
            zetResultaat(rij, '<span class="klein">' + escape(c.bericht || 'Geen nieuwe hoofdversie beschikbaar.') + '</span>');
            return;
        }
        if (c.status === 'geblokkeerd') {
            zetResultaat(rij, '<span class="rood">❌ De controle vooraf vond een probleem; niet bijgewerkt.</span>');
            await meld('De site is nog niet klaar voor Joomla ' + c.naar + '. Joomla\'s eigen controle vond:\n\n• '
                + (c.problemen || []).join('\n• ') + '\n\nLos dit eerst op in het beheer van de site en probeer het daarna opnieuw.');
            return;
        }
        zetResultaat(rij, '<span class="groen">✅ Controle vooraf in orde</span>');
        info.naar = c.naar || info.naar;
        var onbekend = (c.extensies && c.extensies.onbekend) || [];
        if (onbekend.length) {
            extra += '\n\nVan ' + onbekend.length + ' extensie(s) kon Joomla niet nagaan of ze geschikt zijn voor Joomla ' + info.naar + ':\n• '
                + onbekend.slice(0, 15).join('\n• ') + (onbekend.length > 15 ? '\n• … en nog ' + (onbekend.length - 15) : '')
                + '\nControleer dat zo nodig eerst bij de makers.';
        }
        if (c.waarschuwingen && c.waarschuwingen.length) {
            extra += '\n\nOpmerkingen van Joomla:\n• ' + c.waarschuwingen.join('\n• ');
        }
        extra = '\n\nDit is een NIEUWE HOOFDVERSIE van Joomla.' + extra;
    }

    if (!await bevestig(kernVraag(domein, info.van, info.naar) + extra, 'Akkoord, Joomla bijwerken')) {
        return;
    }

    knop.disabled = true;
    zetResultaat(rij, '<span class="klein">⏳ Joomla wordt bijgewerkt; dat kan enkele minuten duren…</span>');
    var r = await stuur('kern_bijwerken', { site_id: siteId, doel: doel, versie: info.naar });
    zetResultaat(rij, resultaatHtml(r.resultaat ? r : { resultaat: 'mislukt', bericht: r.foutmelding }));
    if (r.resultaat !== 'succes') {
        knop.disabled = false;
        return;
    }
    verversDelen(['updates-lijst', 'knop-alles']);
}

function meld(tekst) {
    return toonDialoog(tekst, 'meld');
}

function vraagTekst(tekst, standaard) {
    return toonDialoog(tekst, 'tekst', standaard);
}

// Haalt de plugin van de site (of alleen de sleutel van deze monitor).
async function verwijderPlugin(knop, siteId) {
    var naam = SITE_NAMEN[siteId] || 'deze site';
    var rij = document.getElementById('plugin-' + siteId);
    var uitgesloten = rij && rij.dataset.uitgesloten === '1';
    if (!await bevestig('De plugin van ' + naam + ' verwijderen?\n\n'
        + 'De monitor haalt eerst zijn eigen sleutel weg. Gebruikt een andere monitor de plugin op deze site nog, dan blijft de plugin staan; '
        + 'anders wordt hij helemaal verwijderd (uit Joomla en van de server). Snapshots van eerdere updates blijven bewaard.'
        + (uitgesloten ? '' : '\n\nWil je niet dat de plugin hier later opnieuw wordt geplaatst (bijvoorbeeld met de knop "Plugin installeren op … site(s) zonder plugin"), gebruik dan ook "Uitsluiten".'))) {
        return;
    }
    knop.disabled = true;
    zetResultaat(rij, '<span class="klein">⏳ Plugin verwijderen…</span>');
    stuur('plugin_verwijderen', { site_id: siteId }).then(function (r) {
        zetResultaat(rij, r.succes ? '<span class="groen">✅ ' + escape(r.bericht) + '</span>' : '<span class="rood">❌ ' + escape(r.foutmelding) + '</span>');
        knop.disabled = false;
        verversNaSiteActie(siteId);
    });
}

// Sluit een site uit van het centraal bijwerken, of laat haar weer toe.
async function sluitUit(knop, siteId, uitsluiten) {
    var naam = SITE_NAMEN[siteId] || 'deze site';
    var rij = document.getElementById('plugin-' + siteId);
    var heeftPlugin = rij && rij.dataset.heeftPlugin === '1';
    var reden = '';
    if (uitsluiten) {
        if (!await bevestig(naam + ' uitsluiten van updates?\n\n'
            + 'De monitor plaatst hier dan nooit de plugin, haalt geen updates op en installeert niets, ook niet via "Alle geactiveerde updates uitvoeren" of de cronjob. '
            + 'De site wordt wel gewoon gescand.'
            + (heeftPlugin ? '\n\nDe plugin staat al op de site. Die blijft staan, maar de monitor gebruikt hem niet meer; haal hem zo nodig weg met "Plugin verwijderen".' : ''))) {
            return;
        }
        reden = await vraagTekst('Waarom wordt deze site uitgesloten? (mag leeg blijven; je ziet het later terug in de tabel)', '');
        if (reden === null) {
            return;
        }
    } else if (!await bevestig(naam + ' weer laten meedoen met het centraal bijwerken?')) {
        return;
    }
    knop.disabled = true;
    stuur('uitsluiten', { site_id: siteId, uit: uitsluiten ? '1' : '', reden: reden }).then(async function (r) {
        if (!r.succes) {
            await meld(r.foutmelding || 'Opslaan mislukt.');
        }
        location.reload();
    });
}

function slaOver(siteId, extensionId, versie) {
    stuur('overslaan', { site_id: siteId, extension_id: extensionId, versie: versie }).then(function () {
        location.reload();
    });
}

function slaInstellingenOp() {
    stuur('instellingen', {
        anderen_toestaan: document.getElementById('inst-anderen').checked ? '1' : '',
        mail: document.getElementById('inst-mail').checked ? '1' : '',
        snapshot: document.getElementById('inst-snapshot').checked ? '1' : '',
        admintools: document.getElementById('inst-admintools').checked ? '1' : '',
        kern_hoofdversie: document.getElementById('inst-kern-hoofdversie').checked ? '1' : '',
        venster_van: document.getElementById('inst-van').value,
        venster_tot: document.getElementById('inst-tot').value
    }).then(async function (r) {
        if (!r.succes) {
            await meld(r.foutmelding || 'Opslaan mislukt.');
        }
        location.reload();
    });
}

async function installeerEen(knop, siteId, extensionId, kernInfo) {
    var vraag = kernInfo
        ? bevestig(kernVraag(SITE_NAMEN[siteId] || 'deze site', kernInfo.van, kernInfo.naar), 'Akkoord, Joomla bijwerken')
        : bevestig('Deze update nu installeren?');
    if (!await vraag) {
        return;
    }
    var rij = document.getElementById('rij-' + siteId + '-' + extensionId);
    knop.disabled = true;
    zetResultaat(rij, '<span class="klein">⏳ Bezig met installeren…</span>');
    stuur('installeer', { site_id: siteId, extension_id: extensionId, bron: 'handmatig' }).then(function (r) {
        zetResultaat(rij, resultaatHtml(r.resultaat ? r : { resultaat: 'mislukt', bericht: r.foutmelding }));
        if (r.resultaat !== 'succes') {
            knop.disabled = false;
        }
    });
}

// Uitkomst van het plaatsen van de plugin: kort groen, en alleen als er iets
// na te kijken is een korte gele regel eronder (uitleg in de tooltip).
function pluginUitkomstHtml(r) {
    if (!r.succes) {
        return '<span class="rood">❌ ' + escape(r.foutmelding) + '</span>';
    }
    return '<span class="groen">✅ ' + escape(r.bericht) + '</span>'
        + (r.let_op ? '<br><span class="geel" title="' + escapeAttr(r.let_op_uitleg || '') + '">⚠️ ' + escape(r.let_op) + '</span>' : '');
}

function rolPluginUit(knop, siteId) {
    var rij = document.getElementById('plugin-' + siteId);
    knop.disabled = true;
    zetResultaat(rij, '<span class="klein">⏳ Plugin plaatsen…</span>');
    return stuur('plugin', { site_id: siteId }).then(function (r) {
        zetResultaat(rij, pluginUitkomstHtml(r));
        knop.disabled = false;
        // Kolommen Plugin, Joomla en Laatst opgehaald direct bijwerken.
        return verversNaSiteActie(siteId).then(function () { return r; });
    });
}

// Haalt de pagina opnieuw op en vervangt alleen de opgegeven onderdelen
// (een regel van de plugintabel, de lijst met updates, ...). Een
// resultaatmelding die al in een regel staat, blijft staan.
function verversDelen(ids) {
    // De tellers op de tabbladen horen bij die onderdelen.
    if (ids.indexOf('updates-lijst') !== -1 && ids.indexOf('tab-aantal-updates') === -1) {
        ids = ids.concat(['tab-aantal-updates']);
    }
    if (ids.some(function (id) { return id.indexOf('plugin-') === 0; }) && ids.indexOf('plugin-tellers') === -1) {
        ids = ids.concat(['plugin-tellers']);
    }
    return fetch(location.href, { cache: 'no-store', credentials: 'same-origin' })
        .then(function (r) { return r.text(); })
        .then(function (html) {
            var nieuw = new DOMParser().parseFromString(html, 'text/html');
            ids.forEach(function (id) {
                var oud = document.getElementById(id);
                var vers = nieuw.getElementById(id);
                if (!oud || !vers) {
                    return;
                }
                var oudResultaat = oud.querySelector('.resultaat');
                var versResultaat = vers.querySelector('.resultaat');
                if (oudResultaat && versResultaat && oud.tagName === 'TR') {
                    versResultaat.innerHTML = oudResultaat.innerHTML;
                }
                if (oud.tagName === 'DETAILS') {
                    vers.open = oud.open;
                }
                oud.replaceWith(document.importNode(vers, true));
            });
            werkVolgLabelsBij();
        })
        .catch(function () { /* niet erg: verversen kan altijd nog met de hand */ });
}

function verversNaSiteActie(siteId) {
    return verversDelen(['plugin-' + siteId, 'updates-lijst', 'knop-alles', 'plugin-knoppen', 'plugin-tellers']);
}

async function locaties(knop, siteId, modus) {
    var vraag = modus === 'herbouwen'
        ? 'Updatesites op deze site herbouwen?\n\nJoomla bouwt dan alle updatesites opnieuw op uit de geïnstalleerde extensies (downloadsleutels blijven bewaard). Dat is hetzelfde als herbouwen in het scherm Updatesites van Joomla.'
        : 'De uitgeschakelde updatesites op deze site weer inschakelen?';
    if (!await bevestig(vraag)) {
        return;
    }
    var rij = document.getElementById('plugin-' + siteId);
    knop.disabled = true;
    zetResultaat(rij, '<span class="klein">⏳ Bezig…</span>');
    stuur('locaties', { site_id: siteId, modus: modus }).then(function (r) {
        zetResultaat(rij, r.succes ? '<span class="groen">✅ ' + escape(r.bericht) + '</span>' : '<span class="rood">❌ ' + escape(r.foutmelding) + '</span>');
        knop.disabled = false;
        verversNaSiteActie(siteId);
    });
}

async function zetTerug(knop, geschiedenisId, omschrijving) {
    if (!await bevestig('Snapshot terugzetten?\n\n' + omschrijving + '\n\nDe bestanden, de eigen databasetabellen en de registratie van deze extensie gaan terug naar de toestand van vóór de update. Wat sinds die update in die mappen en tabellen is veranderd, gaat verloren.')) {
        return;
    }
    knop.disabled = true;
    var cel = knop.parentNode;
    var status = document.createElement('div');
    status.className = 'klein';
    status.textContent = '⏳ Bezig met terugzetten…';
    cel.appendChild(status);
    stuur('terugzetten', { geschiedenis_id: geschiedenisId }).then(function (r) {
        status.innerHTML = r.succes ? '<span class="groen">✅ ' + escape(r.bericht) + '</span>' : '<span class="rood">❌ ' + escape(r.foutmelding) + '</span>';
        if (!r.succes) {
            knop.disabled = false;
        }
    });
}

function haalEenOp(knop, siteId) {
    var rij = document.getElementById('plugin-' + siteId);
    knop.disabled = true;
    zetResultaat(rij, '<span class="klein">⏳ Updates zoeken…</span>');
    stuur('ophalen', { site_id: siteId }).then(function (r) {
        zetResultaat(rij, r.succes ? '<span class="groen">✅ ' + r.aantal + ' update(s) gevonden.</span>' : '<span class="rood">❌ ' + escape(r.foutmelding) + '</span>');
        knop.disabled = false;
        verversNaSiteActie(siteId);
    });
}

// Voert een lijst van taken uit met maximaal 'tegelijk' sites tegelijk; per
// site altijd één taak tegelijk (de plugin staat er ook maar één toe).
function voerUitPerSite(taken, tegelijk, uitvoerder) {
    var perSite = {};
    var volgorde = [];
    taken.forEach(function (t) {
        if (!perSite[t.site_id]) {
            perSite[t.site_id] = [];
            volgorde.push(t.site_id);
        }
        perSite[t.site_id].push(t);
    });

    return new Promise(function (klaar) {
        var bezig = 0;
        var index = 0;

        function volgendeSite() {
            if (index >= volgorde.length && bezig === 0) {
                klaar();
                return;
            }
            while (bezig < tegelijk && index < volgorde.length) {
                var lijst = perSite[volgorde[index++]];
                bezig++;
                verwerk(lijst, 0);
            }
        }

        function verwerk(lijst, i) {
            if (i >= lijst.length) {
                bezig--;
                volgendeSite();
                return;
            }
            uitvoerder(lijst[i]).then(function () { verwerk(lijst, i + 1); });
        }

        volgendeSite();
    });
}

// Toont het voortgangsblok, met bovenaan in één regel wat het aan het doen
// is (kop) en daaronder de details (titel).
function toonVoortgang(kop, titel) {
    document.getElementById('voortgang').style.display = 'block';
    document.getElementById('voortgang-kop').textContent = kop;
    document.getElementById('voortgang-titel').textContent = titel;
    document.getElementById('voortgang-lijst').innerHTML = '';
    document.getElementById('voortgang-slot').innerHTML = '';
    window.scrollTo({ top: document.getElementById('voortgang').offsetTop - 10, behavior: 'smooth' });
}

function voortgangRegel(html) {
    var li = document.createElement('li');
    li.innerHTML = html;
    document.getElementById('voortgang-lijst').appendChild(li);
    return li;
}

// Ververst de pagina en begint weer helemaal bovenaan. Een gewone reload
// onthoudt de scrollpositie; die zetten we eerst op 0 en we vragen de
// browser die niet zelf terug te zetten.
function verversBovenaan() {
    try {
        history.scrollRestoration = 'manual';
    } catch (e) { /* oudere browser: dan alleen naar boven scrollen */ }
    window.scrollTo(0, 0);
    location.reload();
}

function voortgangKop(kop) {
    document.getElementById('voortgang-kop').textContent = kop;
}

// Afronding onderaan het blok: de uitkomst, en daaronder een duidelijke knop.
function voortgangKlaar(html) {
    var slot = document.getElementById('voortgang-slot');
    // Na afloop de afronding (uitkomst en knop) in beeld brengen.
    setTimeout(function () {
        var knop = slot.querySelector('.voortgang-knop');
        var r = knop ? knop.getBoundingClientRect() : null;
        // Alleen scrollen als de knop niet helemaal in beeld is.
        if (r && (r.top < 0 || r.bottom > window.innerHeight)) {
            knop.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }, 50);
    slot.innerHTML = '<div class="voortgang-melding">' + (html || 'Klaar.') + '</div>'
        + '<div class="voortgang-knop"><button type="button" class="knop knop-blauw" onclick="verversBovenaan()">🔄 Pagina verversen</button></div>';
}

// soort 'scan': alleen de sites waar de laatste scan van de monitor
// verouderde extensies vond; 'alle': alle sites met de plugin.
function haalAllesOp(soort) {
    // Uit de tabel zelf, zodat ook net geplaatste plugins meetellen.
    SITES_MET_PLUGIN = Array.prototype.map.call(document.querySelectorAll('tr[data-heeft-plugin="1"]:not([data-uitgesloten="1"])'), function (rij) {
        return parseInt(rij.dataset.site, 10);
    });
    if (soort === 'scan') {
        SITES_MET_PLUGIN = SITES_MET_PLUGIN.filter(function (id) { return SITES_SCAN_UPDATES.indexOf(id) !== -1; });
    }
    if (!SITES_MET_PLUGIN.length) {
        meld(soort === 'scan'
            ? 'Volgens de laatste scan heeft geen site met de plugin verouderde extensies.'
            : 'Op geen enkele site in deze categorie staat de plugin. Installeer die eerst.');
        return;
    }
    document.querySelectorAll('.knop-ophalen').forEach(function (k) { k.disabled = true; });
    toonVoortgang('⏳ Updates worden opgehaald', 'Joomla zoekt op ' + SITES_MET_PLUGIN.length + ' site(s)'
        + (soort === 'scan' ? ' waar de scan verouderde extensies vond' : ' met de plugin') + ' opnieuw naar updates…');
    var taken = SITES_MET_PLUGIN.map(function (id) { return { site_id: id }; });
    var fouten = 0;
    voerUitPerSite(taken, 4, function (t) {
        var li = voortgangRegel('⏳ ' + escape(SITE_NAMEN[t.site_id]));
        return stuur('ophalen', { site_id: t.site_id }).then(function (r) {
            if (!r.succes) {
                fouten++;
            }
            li.innerHTML = r.succes
                ? '✅ ' + escape(SITE_NAMEN[t.site_id]) + ': ' + r.aantal + ' update(s)'
                : '❌ ' + escape(SITE_NAMEN[t.site_id]) + ': ' + escape(r.foutmelding);
        });
    }).then(function () {
        voortgangKop(fouten ? '⚠️ Updates opgehaald, met fouten' : '✅ Updates opgehaald');
        document.getElementById('voortgang-titel').textContent = SITES_MET_PLUGIN.length + ' site(s) bekeken.';
        verversDelen(SITES_MET_PLUGIN.map(function (id) { return 'plugin-' + id; }).concat(['updates-lijst', 'knop-alles']));
        voortgangKlaar(fouten ? '<span class="rood">' + fouten + ' site(s) gaven een fout.</span>' : '<span class="groen">Klaar.</span>');
    });
}

function voerAllesUit() {
    stuur('taken', {}).then(async function (r) {
        if (!r.succes || !r.taken.length) {
            await meld(r.foutmelding || 'Er zijn geen updates die automatisch mee mogen.');
            return;
        }
        var lijst = r.taken.slice(0, 25).map(function (t) { return '• ' + t.domein + ': ' + t.naam + ' ' + t.van + ' → ' + t.naar; }).join('\n');
        if (r.taken.length > 25) {
            lijst += '\n… en nog ' + (r.taken.length - 25);
        }
        var kernen = r.taken.filter(function (t) { return t.kern; });
        var kernTekst = kernen.length
            ? '\n\nHierbij wordt Joomla zelf bijgewerkt op ' + kernen.length + ' site(s).\n' + KERN_WAARSCHUWING
            : '';
        if (!await bevestig(r.taken.length + ' update(s) installeren?\n\n' + lijst + kernTekst, kernen.length ? 'Akkoord, installeren' : 'OK')) {
            return;
        }

        document.getElementById('knop-alles').disabled = true;
        toonVoortgang('⏳ Updates worden geïnstalleerd', r.taken.length + ' update(s), per site één tegelijk…');
        var runId = r.run_id;
        var tel = { succes: 0, mislukt: 0, onbekend: 0, overgeslagen: 0 };

        voerUitPerSite(r.taken, 3, function (t) {
            var label = escape(t.domein) + ' - ' + escape(t.naam) + ' ' + escape(t.van) + ' → ' + escape(t.naar);
            var li = voortgangRegel('⏳ ' + label);
            return stuur('installeer', { site_id: t.site_id, extension_id: t.extension_id, run_id: runId, bron: 'alles' }).then(function (a) {
                var res = a.resultaat || 'mislukt';
                tel[res] = (tel[res] || 0) + 1;
                li.innerHTML = label + ': ' + resultaatHtml(a.resultaat ? a : { resultaat: 'mislukt', bericht: a.foutmelding });
                var rij = document.getElementById('rij-' + t.site_id + '-' + t.extension_id);
                zetResultaat(rij, resultaatHtml(a.resultaat ? a : { resultaat: 'mislukt', bericht: a.foutmelding }));
                if (rij && res === 'succes') {
                    rij.querySelectorAll('.acties button').forEach(function (k) { k.disabled = true; });
                }
            });
        }).then(function () {
            voortgangKop(tel.mislukt || tel.onbekend
                ? (tel.succes ? '⚠️ Updates geïnstalleerd, met problemen' : '❌ Updates installeren mislukt')
                : '✅ Updates geïnstalleerd');
            verversDelen(['updates-lijst', 'knop-alles', 'geschiedenis-blok']);
            return stuur('afronden', { run_id: runId });
        }).then(function (m) {
            voortgangKlaar(tel.succes + ' bijgewerkt, ' + tel.mislukt + ' mislukt, ' + tel.onbekend + ' onbekend, ' + tel.overgeslagen + ' overgeslagen.'
                + (m && m.bericht ? ' <span class="klein">' + escape(m.bericht) + '</span>' : ''));
        });
    });
}

async function rolPluginUitOpAlle(alleenVerouderd) {
    var rijen = Array.prototype.slice.call(document.querySelectorAll('tr[data-heeft-plugin]')).filter(function (rij) {
        if (rij.dataset.uitgesloten === '1' || rij.dataset.ftp === '0') {
            return false;
        }
        return alleenVerouderd ? rij.dataset.verouderd === '1' : rij.dataset.heeftPlugin === '0';
    });
    if (!rijen.length) {
        await meld(alleenVerouderd
            ? 'Er zijn geen verouderde plugins (meer) om bij te werken.'
            : 'Er zijn geen sites (meer) waarop de plugin geplaatst kan worden. Sites zonder FTP-gegevens en uitgesloten sites worden overgeslagen.');
        return;
    }
    if (!await bevestig('De plugin ' + (alleenVerouderd ? 'bijwerken' : 'installeren') + ' op ' + rijen.length + ' site(s)?')) {
        return;
    }
    toonVoortgang('⏳ Plugin wordt geplaatst', 'De plugin ' + (alleenVerouderd ? 'bijwerken' : 'installeren') + ' op ' + rijen.length + ' site(s)…');
    var taken = rijen.map(function (rij) { return { site_id: parseInt(rij.dataset.site, 10) }; });
    var gelukt = 0;
    var mislukt = 0;
    voerUitPerSite(taken, 3, function (t) {
        var li = voortgangRegel('⏳ ' + escape(SITE_NAMEN[t.site_id]));
        return stuur('plugin', { site_id: t.site_id }).then(function (r) {
            if (r.succes) {
                gelukt++;
            } else {
                mislukt++;
            }
            li.innerHTML = (r.succes ? '✅ ' : '❌ ') + escape(SITE_NAMEN[t.site_id]) + ': ' + escape(r.succes ? r.bericht : r.foutmelding)
                + (r.succes && r.let_op ? ' <span class="geel" title="' + escapeAttr(r.let_op_uitleg || '') + '">⚠️ ' + escape(r.let_op) + '</span>' : '');
            zetResultaat(document.getElementById('plugin-' + t.site_id), pluginUitkomstHtml(r));
            return verversDelen(['plugin-' + t.site_id]);
        });
    }).then(function () {
        voortgangKop(mislukt === 0 ? '✅ Plugin geplaatst' : (gelukt === 0 ? '❌ Plugin plaatsen mislukt' : '⚠️ Plugin geplaatst, met fouten'));
        voortgangKlaar(gelukt + ' gelukt, ' + mislukt + ' mislukt.');
        verversDelen(['plugin-knoppen', 'plugin-tellers', 'updates-lijst', 'knop-alles']);
    });
}

// Beginstand van het tabblad Extensies: alleen extensies met een eigen updatesite.
filterExtensies();
</script>

<?php include MONITOR_MAP . '/terug_naar_boven.php'; ?>
</body>
</html>
