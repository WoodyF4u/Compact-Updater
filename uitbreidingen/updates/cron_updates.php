<?php
// cron_updates.php
//
// Voor wie de geactiveerde updates ook automatisch wil laten uitvoeren, via
// een cronjob. Doet hetzelfde als op de updatespagina eerst "Updates
// ophalen" en daarna "Alle geactiveerde updates uitvoeren", en stuurt
// daarna één samenvattende e-mail voor alle sites.
//
// Alleen updates met een automatische stand gaan mee; alles op "Handmatig"
// blijft staan en komt (één keer per nieuwe versie) in de e-mail onder
// "Wachten op jou".
//
// Aanroepen met de geheime cron-code (zie Configuratie), bij voorkeur via
// PHP op de commandoregel, omdat het bijwerken van veel sites lang kan duren:
//
//   php /pad/naar/monitor/uitbreidingen/updates/cron_updates.php --cron_code=JOUWCODE
//
// of via een URL:  https://monitor.example/uitbreidingen/updates/cron_updates.php?cron_code=JOUWCODE
//
// Is er op de updatespagina een tijdvenster ingesteld (bijv. van 2 tot 6
// uur), dan doet dit script buiten dat venster niets.

@set_time_limit(0);
@ini_set('memory_limit', '256M');
@ignore_user_abort(true);

require_once __DIR__ . '/basis.php';
require_once MONITOR_MAP . '/config.php';
require_once MONITOR_MAP . '/versleuteling_functies.php';
require_once MONITOR_MAP . '/endpoint_beveiliging.php';
require_once MONITOR_MAP . '/instellingen_functies.php';
require_once __DIR__ . '/updates_functies.php';

header('Content-Type: text/plain; charset=utf-8');

zorgUpdatesTabellen($pdo);

$van = haalInstelling($pdo, 'updates_venster_van', '');
$tot = haalInstelling($pdo, 'updates_venster_tot', '');
if ($van !== '' && $tot !== '') {
    $uur      = (int) date('G');
    $van      = (int) $van;
    $tot      = (int) $tot;
    $binnen   = $van <= $tot ? ($uur >= $van && $uur < $tot) : ($uur >= $van || $uur < $tot);
    if (!$binnen) {
        echo "Buiten het ingestelde tijdvenster ($van:00 - $tot:00): niets gedaan.\n";
        exit;
    }
}

echo "=== Updates ophalen: " . date('Y-m-d H:i:s') . " ===\n";

$sites = $pdo->query("
    SELECT s.* FROM sites s JOIN updates_koppeling k ON k.site_id = s.id
    WHERE s.id NOT IN (SELECT site_id FROM updates_uitgesloten)
    ORDER BY s.domein
")->fetchAll(PDO::FETCH_ASSOC);

$siteOpId = [];
foreach ($sites as $site) {
    $siteOpId[(int) $site['id']] = $site;
    try {
        $r = updatesHaalOp($pdo, $site, true);
        echo ($r['ok'] ? '✅ ' : '❌ ') . $site['domein'] . ': ' . ($r['ok'] ? $r['aantal'] . ' update(s)' : $r['fout']) . "\n";
    } catch (Throwable $e) {
        echo '❌ ' . $site['domein'] . ': ' . $e->getMessage() . "\n";
    }
}

$runId = bin2hex(random_bytes(8));
$taken = updatesTakenVoorAlles($pdo);

echo "\n=== Bijwerken: " . count($taken) . " update(s) ===\n";

foreach ($taken as $taak) {
    $site = $siteOpId[$taak['site_id']] ?? updatesHaalSite($pdo, $taak['site_id']);
    if ($site === null) {
        continue;
    }
    try {
        $r = updatesInstalleer($pdo, $site, $taak['extension_id'], $runId, 'cron');
        $teken = ['succes' => '✅', 'mislukt' => '❌', 'onbekend' => '❓', 'overgeslagen' => '⏭️'][$r['resultaat']] ?? '•';
        echo "$teken {$taak['domein']} - {$taak['naam']} {$taak['van']} → {$taak['naar']}: "
            . str_replace("\n", ' ', $r['bericht'])
            . (!empty($r["controle"]) ? " " . $r["controle"] : "") . "\n";
    } catch (Throwable $e) {
        echo "❌ {$taak['domein']} - {$taak['naam']}: " . $e->getMessage() . "\n";
    }
}

echo "\n" . updatesStuurSamenvatting($pdo, $runId) . "\n";
echo "=== Klaar: " . date('Y-m-d H:i:s') . " ===\n";
