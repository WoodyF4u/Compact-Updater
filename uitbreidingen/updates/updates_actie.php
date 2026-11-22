<?php
// updates_actie.php
//
// Acties van de updatespagina (updates.php), elk als los verzoek met een
// JSON-antwoord. De pagina roept ze één voor één aan (per site, per
// update), zodat er nooit een tijdslimiet van PHP geraakt wordt en je de
// voortgang live ziet.
//
//   ophalen          site_id                      - updates (opnieuw) laten zoeken op de site
//   installeer       site_id, extension_id, run_id - één update installeren
//   plugin           site_id                      - plugin op de site zetten of bijwerken
//   ping             site_id                      - status van de plugin opvragen
//   beleid           site_id (0 = alle), ext_sleutel ('' = hele site), stand
//   overslaan        site_id, extension_id, versie ('' = weer meenemen)
//   taken            -                            - lijst voor "Alle geactiveerde updates uitvoeren"
//   afronden         run_id                       - één samenvattende e-mail versturen
//   instellingen     anderen_toestaan, mail
//   koppeling_wissen site_id                      - koppeling vergeten (de plugin blijft op de site)
//   terugzetten      geschiedenis_id              - snapshot van een eerdere update terugzetten
//   locaties         site_id, modus               - updatesites inschakelen of herbouwen
//   zelf_controle    opnieuw                      - is er een nieuwere versie van deze uitbreiding?
//   zelf_bijwerken   -                            - deze uitbreiding bijwerken vanaf GitHub

require_once __DIR__ . '/basis.php';
require_once MONITOR_MAP . '/sessie_start.php';
if (!isset($_SESSION['ingelogd'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['succes' => false, 'foutmelding' => 'Niet ingelogd.']);
    exit;
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['succes' => false, 'foutmelding' => 'Alleen POST.']);
    exit;
}

require_once MONITOR_MAP . '/config.php';
require_once MONITOR_MAP . '/csrf_functies.php';
require_once MONITOR_MAP . '/instellingen_functies.php';
require_once __DIR__ . '/updates_functies.php';
require_once __DIR__ . '/zelf_bijwerken_functies.php';

if (!csrfTokenGeldig()) {
    echo json_encode(['succes' => false, 'foutmelding' => 'Ongeldige aanvraag (CSRF-token verlopen). Ververs de pagina.']);
    exit;
}

// Een installatie mag 10 minuten duren, plus de controle achteraf (zie updatesControleerAfloop()).
@set_time_limit(900);
@ini_set('memory_limit', '256M');

zorgUpdatesTabellen($pdo);

$actie  = (string) ($_POST['actie'] ?? '');
$siteId = (int) ($_POST['site_id'] ?? 0);

function antwoord(array $gegevens): void
{
    echo json_encode($gegevens);
    exit;
}

function vereisSite(PDO $pdo, int $siteId): array
{
    $site = $siteId > 0 ? updatesHaalSite($pdo, $siteId) : null;
    if ($site === null) {
        antwoord(['succes' => false, 'foutmelding' => 'Onbekende site.']);
    }

    return $site;
}

try {
    switch ($actie) {
        case 'ophalen':
            $site = vereisSite($pdo, $siteId);
            $r    = updatesHaalOp($pdo, $site, true);
            antwoord(['succes' => $r['ok'], 'aantal' => $r['aantal'], 'foutmelding' => $r['fout']]);

        case 'ping':
            $site = vereisSite($pdo, $siteId);
            $r    = updatesPing($pdo, $site);
            antwoord(['succes' => $r['ok'], 'foutmelding' => $r['fout'] ?? '', 'status' => $r['antwoord'] ?? null]);

        case 'plugin':
            $site = vereisSite($pdo, $siteId);
            $r    = updatesRolPluginUit($pdo, $site);
            antwoord([
                'succes'        => $r['ok'],
                'bericht'       => $r['bericht'],
                'let_op'        => $r['let_op'] ?? '',
                'let_op_uitleg' => $r['let_op_uitleg'] ?? '',
                'foutmelding'   => $r['ok'] ? '' : $r['bericht'],
            ]);

        case 'installeer':
            $site  = vereisSite($pdo, $siteId);
            $runId = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['run_id'] ?? ''));
            $bron  = ($_POST['bron'] ?? '') === 'alles' ? 'alles' : 'handmatig';
            $r     = updatesInstalleer($pdo, $site, (int) ($_POST['extension_id'] ?? 0), $runId, $bron);
            antwoord(['succes' => $r['resultaat'] === 'succes'] + $r);

        case 'kern_controle':
            $site = vereisSite($pdo, $siteId);
            $r    = updatesKernControle($pdo, $site, (string) ($_POST['doel'] ?? 'binnen'));
            antwoord(['succes' => $r['ok'], 'foutmelding' => $r['ok'] ? '' : $r['bericht']] + $r);

        case 'kern_bijwerken':
            // Een nieuwe hoofdversie gaat alleen met deze eigen knop (nooit via "alles" of de cronjob).
            $site  = vereisSite($pdo, $siteId);
            $doel  = ($_POST['doel'] ?? '') === 'volgende' ? 'volgende' : 'binnen';
            $r     = updatesInstalleerKern($pdo, $site, $doel, mb_substr((string) ($_POST['versie'] ?? ''), 0, 50), '', 'handmatig');
            antwoord(['succes' => $r['resultaat'] === 'succes'] + $r);

        case 'beleid':
            updatesSlaBeleidOp($pdo, max(0, $siteId), (string) ($_POST['ext_sleutel'] ?? ''), (string) ($_POST['stand'] ?? ''));
            antwoord(['succes' => true]);

        case 'overslaan':
            vereisSite($pdo, $siteId);
            updatesSlaVersieOver($pdo, $siteId, (int) ($_POST['extension_id'] ?? 0), mb_substr((string) ($_POST['versie'] ?? ''), 0, 50));
            antwoord(['succes' => true]);

        case 'taken':
            antwoord(['succes' => true, 'taken' => updatesTakenVoorAlles($pdo), 'run_id' => bin2hex(random_bytes(8))]);

        case 'afronden':
            $runId = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['run_id'] ?? ''));
            antwoord(['succes' => true, 'bericht' => $runId !== '' ? updatesStuurSamenvatting($pdo, $runId) : '']);

        case 'instellingen':
            slaInstellingOp($pdo, 'updates_anderen_toestaan', !empty($_POST['anderen_toestaan']) ? '1' : '0');
            slaInstellingOp($pdo, 'updates_mail', !empty($_POST['mail']) ? '1' : '0');
            slaInstellingOp($pdo, 'updates_snapshot', !empty($_POST['snapshot']) ? '1' : '0');
            slaInstellingOp($pdo, 'updates_admintools', !empty($_POST['admintools']) ? '1' : '0');
            slaInstellingOp($pdo, 'updates_kern_hoofdversie', !empty($_POST['kern_hoofdversie']) ? '1' : '0');
            $van = (string) ($_POST['venster_van'] ?? '');
            $tot = (string) ($_POST['venster_tot'] ?? '');
            slaInstellingOp($pdo, 'updates_venster_van', preg_match('/^([01]?\d|2[0-3])$/', $van) ? $van : '');
            slaInstellingOp($pdo, 'updates_venster_tot', preg_match('/^([01]?\d|2[0-3])$/', $tot) ? $tot : '');
            antwoord(['succes' => true]);

        case 'standaard':
            $stand = (string) ($_POST['stand'] ?? '');
            if (!array_key_exists($stand, updatesStanden())) {
                antwoord(['succes' => false, 'foutmelding' => 'Onbekende instelling.']);
            }
            slaInstellingOp($pdo, 'updates_standaard_stand', $stand);
            antwoord(['succes' => true]);

        case 'plugin_verwijderen':
            $site = vereisSite($pdo, $siteId);
            $r    = updatesVerwijderPlugin($pdo, $site);
            antwoord(['succes' => $r['ok'], 'bericht' => $r['bericht'], 'foutmelding' => $r['ok'] ? '' : $r['bericht']]);

        case 'uitsluiten':
            vereisSite($pdo, $siteId);
            $uit = ($_POST['uit'] ?? '') === '1';
            updatesZetUitgesloten($pdo, $siteId, $uit, trim((string) ($_POST['reden'] ?? '')));
            antwoord(['succes' => true, 'bericht' => $uit ? 'Uitgesloten van updates.' : 'Weer toegelaten.']);

        case 'terugzetten':
            $r = updatesZetTerug($pdo, (int) ($_POST['geschiedenis_id'] ?? 0));
            antwoord(['succes' => $r['ok'], 'bericht' => $r['bericht'], 'foutmelding' => $r['ok'] ? '' : $r['bericht']]);

        case 'locaties':
            $site  = vereisSite($pdo, $siteId);
            $modus = ($_POST['modus'] ?? '') === 'herbouwen' ? 'herbouwen' : 'inschakelen';
            $r     = updatesLocaties($pdo, $site, $modus);
            antwoord(['succes' => $r['ok'], 'bericht' => $r['bericht'], 'foutmelding' => $r['ok'] ? '' : $r['bericht']]);

        case 'koppeling_wissen':
            vereisSite($pdo, $siteId);
            $pdo->prepare("DELETE FROM updates_koppeling WHERE site_id = ?")->execute([$siteId]);
            $pdo->prepare("DELETE FROM updates_beschikbaar WHERE site_id = ?")->execute([$siteId]);
            antwoord(['succes' => true]);

        case 'zelf_controle':
            $r = updatesZelfControle($pdo, !empty($_POST['opnieuw']));
            antwoord(['succes' => true] + $r);

        case 'zelf_bijwerken':
            $r = updatesZelfBijwerken($pdo);
            antwoord(['succes' => $r['ok'], 'bericht' => $r['bericht'], 'foutmelding' => $r['ok'] ? '' : $r['bericht']]);

        default:
            antwoord(['succes' => false, 'foutmelding' => 'Onbekende actie.']);
    }
} catch (Throwable $e) {
    antwoord(['succes' => false, 'foutmelding' => 'Fout in de monitor: ' . $e->getMessage()]);
}
