<?php

/**
 * Compact Updater: koppeling met de uitbreiding "Updates" van de Websitesmonitor.
 *
 * Met deze plugin kan de monitor op deze site:
 *  - opvragen welke extensie-updates Joomla zelf heeft gevonden ("ophalen"),
 *  - één zo'n update laten installeren ("installeer"), via precies dezelfde
 *    code als de knop "Bijwerken" in Extensiebeheer > Updates,
 *  - Joomla zelf bijwerken ("kern_controle", "kern_bijwerken",
 *    "kern_afronden"), via dezelfde onderdelen als Joomla's eigen
 *    Joomla-update en het opdrachtregelcommando core:update.
 *
 * Er is geen gebruikersaccount voor nodig. Elk verzoek is ondertekend met een
 * geheime sleutel (HMAC-SHA256), die per monitor in de map "sleutels" van
 * deze plugin staat. Elke monitor heeft een eigen sleutelbestand, zodat
 * meerdere monitors dezelfde site kunnen bijwerken zonder elkaars sleutel te
 * kennen. Verzoeken zijn maar 5 minuten geldig en elke "nonce" mag maar één
 * keer gebruikt worden.
 *
 * Bewust NIET mogelijk: een pakket van een opgegeven URL installeren. Alleen
 * updates die Joomla zelf heeft gevonden: voor extensies die al op de site
 * staan, en voor Joomla zelf uit de updatebron van Joomla (met controle van
 * de controlesom van het pakket).
 *
 * Bereikbaar via: index.php?option=com_ajax&plugin=compactupdater&group=system&format=raw
 */

namespace CompactWeb\Plugin\System\Compactupdater\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Extension\ExtensionHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerHelper;
use Joomla\CMS\Version;
use Joomla\Filesystem\Folder;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Updater\Updater;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

final class Compactupdater extends CMSPlugin implements SubscriberInterface
{
    const PLUGIN_VERSIE = '2.1.0';
    const PROTOCOL      = 1;

    /** Hoe lang (seconden) een ondertekend verzoek geldig is. */
    const GELDIGHEID = 300;

    /** @var resource|null Open lock-bestand tijdens een installatie. */
    private $lockHandle = null;

    /** @var string Sleutel van het lopende verzoek (voor het ondertekenen van het antwoord). */
    private $actieveSleutel = '';

    /** @var string Nonce van het lopende verzoek. */
    private $actieveNonce = '';

    public static function getSubscribedEvents(): array
    {
        return ['onAjaxCompactupdater' => 'onAjaxCompactupdater'];
    }

    /**
     * Ingang voor alle verzoeken van de monitor. Stuurt zelf het antwoord en
     * sluit de applicatie af, zodat er geen template of andere uitvoer bij
     * komt.
     */
    public function onAjaxCompactupdater(Event $event): void
    {
        $app = $this->haalApplicatie();

        try {
            $verzoek = $this->controleerVerzoek();
        } catch (\RuntimeException $e) {
            $this->stuurOnondertekend(403, $e->getMessage());
            return;
        }

        $gegevens = $verzoek['gegevens'];
        $actie    = (string) ($gegevens['actie'] ?? '');

        try {
            switch ($actie) {
                case 'ping':
                    $antwoord = $this->actiePing();
                    break;
                case 'ophalen':
                    $antwoord = $this->actieOphalen(!empty($gegevens['vers']), !empty($gegevens['kern_volgende']));
                    break;
                case 'kern_controle':
                    $antwoord = $this->actieKernControle((string) ($gegevens['doel'] ?? 'binnen'));
                    break;
                case 'kern_bijwerken':
                    $antwoord = $this->actieKernBijwerken(
                        (string) ($gegevens['doel'] ?? 'binnen'),
                        (string) ($gegevens['verwachte_versie'] ?? '')
                    );
                    break;
                case 'kern_afronden':
                    $antwoord = $this->actieKernAfronden();
                    break;
                case 'installeer':
                    $antwoord = $this->actieInstalleer(
                        (int) ($gegevens['extension_id'] ?? 0),
                        (string) ($gegevens['verwachte_versie'] ?? ''),
                        !empty($gegevens['snapshot']),
                        max(10, (int) ($gegevens['snapshot_max_mb'] ?? 1000))
                    );
                    break;
                case 'locaties':
                    $antwoord = $this->actieLocaties((string) ($gegevens['modus'] ?? ''));
                    break;
                default:
                    $antwoord = ['succes' => false, 'status' => 'onbekende_actie', 'bericht' => 'Onbekende actie.'];
            }
        } catch (\Throwable $e) {
            $antwoord = [
                'succes'  => false,
                'status'  => 'fout',
                'bericht' => 'Fout op de site: ' . $e->getMessage(),
            ];
        }

        $this->laatLockLos();
        $this->stuurOndertekend($antwoord);
    }

    // ------------------------------------------------------------------
    // Acties
    // ------------------------------------------------------------------

    private function actiePing(): array
    {
        return [
            'succes'        => true,
            'status'        => 'ok',
            'plugin_versie' => self::PLUGIN_VERSIE,
            'protocol'      => self::PROTOCOL,
            'joomla_versie' => \defined('JVERSION') ? JVERSION : '',
            'php_versie'    => PHP_VERSION,
            'toestaan'      => $this->updatesToegestaan(),
            'bezig'         => $this->isBezig(),
            'ip_monitor'    => $this->ipVanMonitor(),
            'snapshots'     => class_exists('ZipArchive'),
            // Een kernupdate die gekopieerd is maar nog niet afgerond: de
            // monitor kan hem dan alsnog afronden (zie actieKernAfronden()).
            'kern_status'   => array_intersect_key($this->leesKernStatus(), ['fase' => 1, 'van' => 1, 'naar' => 1]),
        ];
    }

    /**
     * Geeft de extensie-updates die Joomla kent. Met $vers = true eerst
     * opnieuw zoeken, precies zoals de knop "Zoek naar updates" in
     * Extensiebeheer > Updates (tabel legen + alle updatesites nalopen).
     */
    private function actieOphalen(bool $vers, bool $kernVolgende = false): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $berichten = [];

        if ($vers) {
            @set_time_limit(300);
            $this->laadTalen();
            $model = $this->installerUpdateModel();
            $model->purge();
            $model->findUpdates(0, 0, $this->minimaleStabiliteit());
            $berichten = $this->haalBerichten();
        }

        $query = $db->getQuery(true)
            ->select([
                'u.update_id', 'u.extension_id', 'u.name AS update_naam', 'u.version',
                'u.infourl', 'u.update_site_id',
                'e.name AS extensie_naam', 'e.type', 'e.element', 'e.folder', 'e.client_id',
                'e.enabled', 'e.manifest_cache', 'e.package_id',
                'us.type AS site_type', 'us.extra_query', 'us.name AS updatesite_naam',
            ])
            ->from($db->quoteName('#__updates', 'u'))
            ->join('INNER', $db->quoteName('#__extensions', 'e') . ' ON e.extension_id = u.extension_id')
            ->join('LEFT', $db->quoteName('#__update_sites', 'us') . ' ON us.update_site_id = u.update_site_id')
            ->where('u.extension_id <> 0');

        $rijen = $db->setQuery($query)->loadObjectList();

        $updates = [];
        foreach ($rijen as $rij) {
            if ($this->isJoomlaKern($rij)) {
                continue;
            }

            $manifest = json_decode((string) $rij->manifest_cache, true) ?: [];
            $huidig   = (string) ($manifest['version'] ?? '');
            $eid      = (int) $rij->extension_id;

            // Meerdere regels voor dezelfde extensie: de hoogste versie telt.
            if (isset($updates[$eid]) && version_compare($updates[$eid]['nieuwe_versie'], (string) $rij->version, '>=')) {
                continue;
            }

            $updates[$eid] = [
                'extension_id'   => $eid,
                'update_id'      => (int) $rij->update_id,
                'naam'           => $this->leesbareNaam($rij),
                'type'           => (string) $rij->type,
                'element'        => (string) $rij->element,
                'folder'         => (string) $rij->folder,
                'client_id'      => (int) $rij->client_id,
                'enabled'        => (int) $rij->enabled,
                'package_id'     => (int) $rij->package_id,
                'huidige_versie' => $huidig,
                'nieuwe_versie'  => (string) $rij->version,
                'infourl'        => (string) $rij->infourl,
                'downloadsleutel' => trim((string) $rij->extra_query) !== '',
                'updatesite'     => (string) $rij->updatesite_naam,
            ];
        }

        // Uitgeschakelde updatesites: daarvoor vindt Joomla nooit een update.
        $query = $db->getQuery(true)
            ->select(['name', 'location'])
            ->from($db->quoteName('#__update_sites'))
            ->where($db->quoteName('enabled') . ' = 0');
        $uitgeschakeld = [];
        foreach ($db->setQuery($query)->loadObjectList() as $site) {
            $uitgeschakeld[] = (string) $site->name;
        }

        return [
            'succes'                  => true,
            'status'                  => 'ok',
            'updates'                 => array_values($updates),
            'uitgeschakelde_locaties' => $uitgeschakeld,
            'berichten'               => $berichten,
            'joomla_versie'           => \defined('JVERSION') ? JVERSION : '',
            'plugin_versie'           => self::PLUGIN_VERSIE,
            'toestaan'                => $this->updatesToegestaan(),
            'kern'                    => $this->kernInfo($vers, $kernVolgende),
        ];
    }

    /**
     * Installeert de update die Joomla voor deze extensie heeft gevonden.
     */
    private function actieInstalleer(int $extensionId, string $verwachteVersie, bool $snapshot, int $snapshotMaxMb): array
    {
        if (!$this->updatesToegestaan()) {
            return [
                'succes'  => false,
                'status'  => 'uitgeschakeld',
                'bericht' => 'Updates via de monitor staan op deze site uit (instelling van de plugin).',
            ];
        }

        if ($extensionId <= 0) {
            return ['succes' => false, 'status' => 'fout', 'bericht' => 'Geen extensie opgegeven.'];
        }

        if (!$this->neemLock()) {
            return [
                'succes'  => false,
                'status'  => 'bezig',
                'bericht' => 'Op deze site loopt al een update (mogelijk vanuit een andere monitor). Probeer het zo opnieuw.',
            ];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select(['u.update_id', 'u.version', 'u.name', 'us.type AS site_type',
                'e.type', 'e.element', 'e.folder', 'e.client_id', 'e.manifest_cache'])
            ->from($db->quoteName('#__updates', 'u'))
            ->join('INNER', $db->quoteName('#__extensions', 'e') . ' ON e.extension_id = u.extension_id')
            ->join('LEFT', $db->quoteName('#__update_sites', 'us') . ' ON us.update_site_id = u.update_site_id')
            ->where('u.extension_id = ' . (int) $extensionId);
        $rijen = $db->setQuery($query)->loadObjectList();

        $rij = null;
        foreach ($rijen as $kandidaat) {
            if ($rij === null || version_compare((string) $kandidaat->version, (string) $rij->version, '>')) {
                $rij = $kandidaat;
            }
        }

        if ($rij === null) {
            return [
                'succes'  => false,
                'status'  => 'geen_update',
                'bericht' => 'Joomla heeft voor deze extensie (nu) geen update klaarstaan.',
            ];
        }

        if ($this->isJoomlaKern($rij)) {
            return ['succes' => false, 'status' => 'fout', 'bericht' => 'Joomla zelf wordt niet via de monitor bijgewerkt.'];
        }

        if ($verwachteVersie !== '' && (string) $rij->version !== $verwachteVersie) {
            return [
                'succes'        => false,
                'status'        => 'andere_versie',
                'bericht'       => 'Joomla biedt nu versie ' . $rij->version . ' aan in plaats van ' . $verwachteVersie . '. Haal de updates opnieuw op.',
                'nieuwe_versie' => (string) $rij->version,
            ];
        }

        $vanManifest = json_decode((string) $rij->manifest_cache, true) ?: [];
        $vanVersie   = (string) ($vanManifest['version'] ?? '');
        $doelVersie  = (string) $rij->version;

        // De installatie moet afmaken, ook als de monitor de verbinding
        // verbreekt (time-out): half geïnstalleerde extensies zijn erger dan
        // een gemiste melding.
        @ignore_user_abort(true);
        @set_time_limit(0);

        // Eerst een snapshot. Lukt dat niet, dan wordt er niet bijgewerkt:
        // liever een update later dan een update die niet terug kan.
        $snapshotInfo = null;
        if ($snapshot) {
            try {
                $snapshotInfo = $this->maakSnapshot($extensionId, $vanVersie, $doelVersie, $snapshotMaxMb);
            } catch (\Throwable $e) {
                return [
                    'succes'  => false,
                    'status'  => 'snapshot_mislukt',
                    'bericht' => 'Niet bijgewerkt: de snapshot vooraf is mislukt (' . $e->getMessage() . ').',
                ];
            }
        }

        $this->laadTalen();
        $this->haalBerichten(); // eerdere meldingen weggooien

        // Uitpakken in de eigen werkmap in plaats van Joomla's tmp-map (zie werkMap()).
        $app         = $this->haalApplicatie();
        $oudeTmp     = $app->get('tmp_path');
        $installTmp  = $this->werkMap() . '/tmp';
        if ($installTmp !== '/tmp' && (is_dir($installTmp) || @mkdir($installTmp, 0755, true)) && is_writable($installTmp)) {
            $app->set('tmp_path', $installTmp);
        } else {
            $installTmp = '';
        }

        try {
            $model = $this->installerUpdateModel();
            $model->update([(int) $rij->update_id], $this->minimaleStabiliteit());
            $resultaat = (bool) $model->getState('result');
        } finally {
            $app->set('tmp_path', $oudeTmp);
            if ($installTmp !== '') {
                $this->leegMap($installTmp);
            }
        }
        $berichten = $this->haalBerichten();

        $naVersie = $this->huidigeVersie($extensionId, $rij);

        $geinstalleerd = $naVersie !== '' && version_compare($naVersie, $vanVersie, '>')
            || ($resultaat && $naVersie === $doelVersie);

        return [
            'succes'        => $geinstalleerd,
            'status'        => $geinstalleerd ? 'geinstalleerd' : 'mislukt',
            'van_versie'    => $vanVersie,
            'doel_versie'   => $doelVersie,
            'na_versie'     => $naVersie,
            'joomla_result' => $resultaat,
            'berichten'     => $berichten,
            'snapshot'      => $snapshotInfo,
            'bericht'       => $geinstalleerd
                ? 'Bijgewerkt naar ' . ($naVersie ?: $doelVersie) . '.'
                : 'De installatie is niet gelukt.' . ($berichten ? ' ' . $this->eersteFout($berichten) : ''),
        ];
    }

    /**
     * Updatesites repareren. Zonder werkende updatesite vindt Joomla
     * nooit een update voor een extensie.
     *  - "inschakelen": uitgeschakelde updatesites weer aanzetten.
     *  - "herbouwen": herbouwen in het scherm Updatesites van Joomla (alle
     *    locaties opnieuw opbouwen uit de manifesten; downloadsleutels blijven
     *    bewaard). Joomla staat dat alleen een Super User toe. De plugin
     *    gebruikt daarom alleen voor deze ene handeling de rechten van een
     *    bestaande Super User van de site; er wordt niets opgeslagen of
     *    ingelogd.
     */
    private function actieLocaties(string $modus): array
    {
        if (!$this->updatesToegestaan()) {
            return ['succes' => false, 'status' => 'uitgeschakeld', 'bericht' => 'Wijzigingen via de monitor staan op deze site uit (instelling van de plugin).'];
        }
        if (!$this->neemLock()) {
            return ['succes' => false, 'status' => 'bezig', 'bericht' => 'Op deze site loopt al een update. Probeer het zo opnieuw.'];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        if ($modus === 'inschakelen') {
            $query = $db->getQuery(true)
                ->select('name')
                ->from($db->quoteName('#__update_sites'))
                ->where($db->quoteName('enabled') . ' = 0');
            $namen = $db->setQuery($query)->loadColumn();

            $query = $db->getQuery(true)
                ->update($db->quoteName('#__update_sites'))
                ->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('enabled') . ' = 0');
            $db->setQuery($query)->execute();

            return [
                'succes'  => true,
                'status'  => 'ok',
                'bericht' => \count($namen) . ' updatesite(s) ingeschakeld' . ($namen ? ': ' . implode(', ', $namen) : '') . '.',
            ];
        }

        if ($modus !== 'herbouwen') {
            return ['succes' => false, 'status' => 'fout', 'bericht' => 'Onbekende keuze.'];
        }

        $superUser = $this->zoekSuperUser();
        if ($superUser === null) {
            return ['succes' => false, 'status' => 'fout', 'bericht' => 'Geen actieve Super User gevonden op de site; herbouwen kan alleen met die rechten.'];
        }

        @set_time_limit(300);
        $this->laadTalen();
        $this->haalBerichten();

        $app   = $this->haalApplicatie();
        $oud   = $app->getIdentity();
        $model = $this->haalApplicatie()->bootComponent('com_installer')->getMVCFactory()
            ->createModel('Updatesites', 'Administrator', ['ignore_request' => true]);

        $app->loadIdentity($superUser);
        if (method_exists($model, 'setCurrentUser')) {
            $model->setCurrentUser($superUser);
        }
        try {
            $model->rebuild();
        } finally {
            $app->loadIdentity($oud);
        }
        $berichten = $this->haalBerichten();

        $query = $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__update_sites'));
        $aantal = (int) $db->setQuery($query)->loadResult();

        $fout = '';
        foreach ($berichten as $bericht) {
            if (\in_array($bericht['type'], ['error', 'danger'], true)) {
                $fout = $bericht['tekst'];
                break;
            }
        }

        return [
            'succes'    => $fout === '',
            'status'    => $fout === '' ? 'ok' : 'mislukt',
            'bericht'   => $fout === '' ? "Updatesites herbouwd ($aantal updatesites)." : $fout,
            'berichten' => $berichten,
        ];
    }

    private function zoekSuperUser()
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $groepen = [];
        foreach ($db->setQuery($db->getQuery(true)->select('id')->from($db->quoteName('#__usergroups')))->loadColumn() as $groep) {
            if (Access::checkGroup((int) $groep, 'core.admin')) {
                $groepen[] = (int) $groep;
            }
        }
        if (!$groepen) {
            return null;
        }

        $query = $db->getQuery(true)
            ->select('u.id')
            ->from($db->quoteName('#__users', 'u'))
            ->join('INNER', $db->quoteName('#__user_usergroup_map', 'm') . ' ON m.user_id = u.id')
            ->where('m.group_id IN (' . implode(',', $groepen) . ')')
            ->where($db->quoteName('u.block') . ' = 0')
            ->order('u.id ASC');
        $query->setLimit(1);
        $id = (int) $db->setQuery($query)->loadResult();

        return $id > 0 ? Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($id) : null;
    }

    // ------------------------------------------------------------------
    // Snapshot vóór een update
    // ------------------------------------------------------------------
    //
    // Eén zip per update, met:
    //   snapshot.json   - wat erin zit: extensies (registratie in
    //                     #__extensions), schemaversies, paden, tabellen
    //   bestanden/...   - de mappen en bestanden van de extensie
    //   database/...    - de tabellen die de extensie zelf aanmaakt
    //
    // Terugzetten gebeurt door een los herstelscript dat de monitor neerzet,
    // zodat dat ook werkt als Joomla na een update niet meer opstart. Per
    // extensie blijven de laatste 3 snapshots bewaard.

    const SNAPSHOT_SCHEIDING = "\n-- compactupdater-einde-opdracht\n";
    const SNAPSHOTS_BEWAREN  = 3;

    private function maakSnapshot(int $extensionId, string $vanVersie, string $naarVersie, int $maxMb): array
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('PHP-extensie zip ontbreekt op de server');
        }

        @set_time_limit(0);

        $db  = Factory::getContainer()->get('DatabaseDriver');
        $ext = $db->setQuery(
            $db->getQuery(true)->select('*')->from($db->quoteName('#__extensions'))->where('extension_id = ' . (int) $extensionId)
        )->loadAssoc();
        if (!$ext) {
            throw new \RuntimeException('extensie niet gevonden');
        }

        // Bij een pakket ook alle onderdelen.
        $extensies = [$ext];
        if ($ext['type'] === 'package') {
            $kinderen = $db->setQuery(
                $db->getQuery(true)->select('*')->from($db->quoteName('#__extensions'))->where('package_id = ' . (int) $extensionId)
            )->loadAssocList();
            $extensies = array_merge($extensies, $kinderen);
        }

        $paden = [];
        foreach ($extensies as $e) {
            foreach ($this->padenVoorExtensie($e) as $pad) {
                $paden[$pad] = true;
            }
        }
        $paden = array_keys($paden);
        sort($paden);

        // Overlappende paden samenvoegen (een map binnen een andere map).
        $uniek = [];
        foreach ($paden as $pad) {
            $binnen = false;
            foreach ($uniek as $ander) {
                if (strpos($pad . '/', $ander . '/') === 0) {
                    $binnen = true;
                    break;
                }
            }
            if (!$binnen) {
                $uniek[] = $pad;
            }
        }
        $paden = $uniek;

        if (!$paden) {
            throw new \RuntimeException('geen bestanden van de extensie gevonden');
        }

        $tabellen = $this->tabellenVanExtensie($paden);

        $map  = $this->snapshotMap();
        $naam = $this->snapshotVoorvoegsel($ext) . '__' . date('Ymd-His') . '_' . bin2hex(random_bytes(6)) . '.zip';
        $zipPad = $map . '/' . $naam;

        $zip = new \ZipArchive();
        if ($zip->open($zipPad, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('kon geen zip aanmaken in de snapshotmap');
        }

        $maxBytes = $maxMb * 1024 * 1024;
        $bytes    = 0;
        $tijdelijk = [];
        $padTypen = [];

        try {
            foreach ($paden as $pad) {
                $volledig = JPATH_ROOT . '/' . $pad;
                if (is_dir($volledig) && !is_link($volledig)) {
                    $padTypen[] = ['pad' => $pad, 'type' => 'map'];
                    $zip->addEmptyDir('bestanden/' . $pad);
                    $items = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($volledig, \FilesystemIterator::SKIP_DOTS),
                        \RecursiveIteratorIterator::SELF_FIRST
                    );
                    foreach ($items as $item) {
                        $relatief = $pad . '/' . str_replace('\\', '/', substr($item->getPathname(), \strlen($volledig) + 1));
                        if ($item->isLink()) {
                            continue;
                        }
                        if ($item->isDir()) {
                            $zip->addEmptyDir('bestanden/' . $relatief);
                            continue;
                        }
                        $bytes += (int) $item->getSize();
                        if ($bytes > $maxBytes) {
                            throw new \RuntimeException('de extensie is groter dan ' . $maxMb . ' MB');
                        }
                        $zip->addFile($item->getPathname(), 'bestanden/' . $relatief);
                    }
                } elseif (is_file($volledig)) {
                    $padTypen[] = ['pad' => $pad, 'type' => 'bestand'];
                    $bytes += (int) filesize($volledig);
                    $zip->addFile($volledig, 'bestanden/' . $pad);
                }
            }

            foreach ($tabellen as $tabel) {
                $dump = $this->dumpTabel($tabel, $maxBytes - $bytes);
                $tijdelijk[] = $dump;
                $bytes += (int) filesize($dump);
                $zip->addFile($dump, 'database/' . $tabel . '.sql');
            }

            $ids = array_map(function ($e) { return (int) $e['extension_id']; }, $extensies);
            $schemas = $db->setQuery(
                $db->getQuery(true)->select('*')->from($db->quoteName('#__schemas'))->where('extension_id IN (' . implode(',', $ids) . ')')
            )->loadAssocList();

            $info = [
                'formaat'     => 1,
                'aangemaakt'  => date('c'),
                'joomla_root' => realpath(JPATH_ROOT),
                'prefix'      => $db->getPrefix(),
                'van_versie'  => $vanVersie,
                'naar_versie' => $naarVersie,
                'extensies'   => array_map(function ($e) {
                    return [
                        'extension_id'   => (int) $e['extension_id'],
                        'type'           => $e['type'],
                        'element'        => $e['element'],
                        'folder'         => $e['folder'],
                        'client_id'      => (int) $e['client_id'],
                        'enabled'        => (int) $e['enabled'],
                        'manifest_cache' => $e['manifest_cache'],
                        'params'         => $e['params'],
                    ];
                }, $extensies),
                'schemas'     => $schemas,
                'paden'       => $padTypen,
                'tabellen'    => $tabellen,
                'scheiding'   => self::SNAPSHOT_SCHEIDING,
            ];
            $zip->addFromString('snapshot.json', (string) json_encode($info, JSON_PRETTY_PRINT));

            if (!$zip->close()) {
                throw new \RuntimeException('de zip kon niet worden weggeschreven (schijfruimte?)');
            }
        } catch (\Throwable $e) {
            @$zip->close();
            @unlink($zipPad);
            foreach ($tijdelijk as $bestand) {
                @unlink($bestand);
            }
            throw $e;
        }

        foreach ($tijdelijk as $bestand) {
            @unlink($bestand);
        }

        $this->ruimOudeSnapshotsOp($this->snapshotVoorvoegsel($ext));

        return [
            'pad'      => $zipPad,
            'grootte'  => (int) filesize($zipPad),
            'paden'    => \count($padTypen),
            'tabellen' => \count($tabellen),
        ];
    }

    /** Mappen en bestanden (relatief aan de Joomla-map) die bij een extensie horen. */
    private function padenVoorExtensie(array $e): array
    {
        $type    = (string) $e['type'];
        $element = (string) $e['element'];
        $folder  = (string) $e['folder'];
        $beheer  = (int) $e['client_id'] === 1;

        if ($element === '' || preg_match('#[/\\\\]|\.\.#', $element . $folder)) {
            return [];
        }

        $paden    = [];
        $manifest = '';

        switch ($type) {
            case 'component':
                $paden[]  = 'components/' . $element;
                $paden[]  = 'administrator/components/' . $element;
                $paden[]  = 'api/components/' . $element;
                $kort     = preg_replace('/^com_/', '', $element);
                $manifest = is_file(JPATH_ROOT . '/administrator/components/' . $element . '/' . $kort . '.xml')
                    ? 'administrator/components/' . $element . '/' . $kort . '.xml'
                    : 'administrator/components/' . $element . '/' . $element . '.xml';
                break;
            case 'module':
                $paden[]  = ($beheer ? 'administrator/' : '') . 'modules/' . $element;
                $manifest = end($paden) . '/' . $element . '.xml';
                break;
            case 'plugin':
                $paden[]  = 'plugins/' . $folder . '/' . $element;
                $manifest = end($paden) . '/' . $element . '.xml';
                break;
            case 'template':
                $paden[]  = ($beheer ? 'administrator/' : '') . 'templates/' . $element;
                $paden[]  = 'media/templates/' . ($beheer ? 'administrator' : 'site') . '/' . $element;
                $manifest = $paden[0] . '/templateDetails.xml';
                break;
            case 'library':
                $paden[]  = 'libraries/' . $element;
                $paden[]  = 'administrator/manifests/libraries/' . $element . '.xml';
                $manifest = end($paden);
                break;
            case 'package':
                $paden[]  = 'administrator/manifests/packages/' . $element . '.xml';
                $manifest = end($paden);
                break;
            case 'file':
                $paden[]  = 'administrator/manifests/files/' . $element . '.xml';
                $manifest = end($paden);
                break;
            default:
                return [];
        }

        // Media en (bij type "file") losse bestanden uit het manifest.
        $xml = $manifest !== '' && is_file(JPATH_ROOT . '/' . $manifest) ? @simplexml_load_file(JPATH_ROOT . '/' . $manifest) : false;
        if ($xml) {
            foreach ($xml->xpath('//media') ?: [] as $media) {
                $doel = trim((string) $media['destination'], '/');
                if ($doel !== '') {
                    $paden[] = 'media/' . $doel;
                }
            }
            if ($type === 'file') {
                foreach ($xml->xpath('//fileset/files') ?: [] as $files) {
                    $doel = trim((string) $files['target'], '/');
                    foreach ($files->children() as $kind) {
                        $naam = trim((string) $kind, '/');
                        if ($naam !== '') {
                            $paden[] = ($doel !== '' ? $doel . '/' : '') . $naam;
                        }
                    }
                }
            }
        }

        // Taalbestanden in de algemene taalmappen.
        $voorvoegsel = $type === 'plugin' ? 'plg_' . $folder . '_' . $element : $element;
        foreach (['language', 'administrator/language', 'api/language'] as $taalMap) {
            foreach (glob(JPATH_ROOT . '/' . $taalMap . '/*', GLOB_ONLYDIR) ?: [] as $tag) {
                foreach ([$voorvoegsel . '.ini', $voorvoegsel . '.sys.ini', basename($tag) . '.' . $voorvoegsel . '.ini', basename($tag) . '.' . $voorvoegsel . '.sys.ini'] as $bestand) {
                    if (is_file($tag . '/' . $bestand)) {
                        $paden[] = $taalMap . '/' . basename($tag) . '/' . $bestand;
                    }
                }
            }
        }

        // Alleen wat bestaat, en nooit iets wat te algemeen is.
        $uit = [];
        foreach ($paden as $pad) {
            $pad = trim(str_replace('\\', '/', $pad), '/');
            if ($pad === '' || strpos($pad, '..') !== false || substr_count($pad, '/') < 1) {
                continue;
            }
            if (\in_array($pad, ['media/system', 'media/vendor', 'media/templates', 'libraries/src', 'libraries/vendor', 'administrator/components', 'components', 'plugins', 'modules', 'templates'], true)) {
                continue;
            }
            if (file_exists(JPATH_ROOT . '/' . $pad)) {
                $uit[] = $pad;
            }
        }

        return $uit;
    }

    /** Tabellen die de extensie zelf aanmaakt (CREATE TABLE in de eigen .sql-bestanden). */
    private function tabellenVanExtensie(array $paden): array
    {
        $db     = Factory::getContainer()->get('DatabaseDriver');
        $prefix = $db->getPrefix();
        $namen  = [];

        foreach ($paden as $pad) {
            $volledig = JPATH_ROOT . '/' . $pad;
            if (!is_dir($volledig)) {
                continue;
            }
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($volledig, \FilesystemIterator::SKIP_DOTS));
            foreach ($items as $item) {
                if (!$item->isFile() || strtolower($item->getExtension()) !== 'sql' || $item->getSize() > 5 * 1024 * 1024) {
                    continue;
                }
                $inhoud = (string) file_get_contents($item->getPathname());
                if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?#__([A-Za-z0-9_]+)/i', $inhoud, $m)) {
                    foreach ($m[1] as $naam) {
                        $namen[$prefix . $naam] = true;
                    }
                }
            }
        }

        if (!$namen) {
            return [];
        }

        $bestaand = array_map('strtolower', $db->getTableList());
        $uit = [];
        foreach (array_keys($namen) as $naam) {
            if (\in_array(strtolower($naam), $bestaand, true)) {
                $uit[] = $naam;
            }
        }
        sort($uit);

        return $uit;
    }

    /** Schrijft één tabel (structuur + gegevens) naar een tijdelijk bestand. */
    private function dumpTabel(string $tabel, int $maxBytes): string
    {
        $db      = Factory::getContainer()->get('DatabaseDriver');
        $bestand = $this->werkMap() . '/dump_' . bin2hex(random_bytes(6)) . '.sql';
        $uit     = fopen($bestand, 'wb');
        if ($uit === false) {
            throw new \RuntimeException('kon geen tijdelijk bestand schrijven');
        }

        try {
            $create = $db->setQuery('SHOW CREATE TABLE ' . $db->quoteName($tabel))->loadRow();
            fwrite($uit, 'DROP TABLE IF EXISTS ' . $db->quoteName($tabel) . self::SNAPSHOT_SCHEIDING);
            fwrite($uit, $create[1] . self::SNAPSHOT_SCHEIDING);

            // Vaste volgorde voor het ophalen in delen: de primaire sleutel als die er is.
            $sleutels = [];
            foreach ($db->setQuery('SHOW KEYS FROM ' . $db->quoteName($tabel) . " WHERE Key_name = 'PRIMARY'")->loadAssocList() as $sleutel) {
                $sleutels[] = $db->quoteName($sleutel['Column_name']);
            }
            $volgorde = $sleutels ? implode(', ', $sleutels) : '1';

            $deel = 0;
            do {
                $query = $db->getQuery(true)->select('*')->from($db->quoteName($tabel))->order($volgorde);
                $query->setLimit(500, $deel * 500);
                $rijen = $db->setQuery($query)->loadRowList();
                if ($rijen) {
                    $waarden = [];
                    foreach ($rijen as $rij) {
                        $velden = [];
                        foreach ($rij as $veld) {
                            $velden[] = $veld === null ? 'NULL' : $db->quote((string) $veld);
                        }
                        $waarden[] = '(' . implode(',', $velden) . ')';
                    }
                    fwrite($uit, 'INSERT INTO ' . $db->quoteName($tabel) . ' VALUES ' . implode(",\n", $waarden) . self::SNAPSHOT_SCHEIDING);
                    if (ftell($uit) > $maxBytes) {
                        throw new \RuntimeException('de tabellen van de extensie zijn te groot voor een snapshot');
                    }
                }
                $deel++;
            } while (\count($rijen) === 500);
        } catch (\Throwable $e) {
            fclose($uit);
            @unlink($bestand);
            throw $e;
        }

        fclose($uit);

        return $bestand;
    }

    /**
     * Waar snapshots staan. Bij voorkeur buiten de webroot (als de site in
     * een gebruikelijke webmap als public_html staat en de map erboven
     * schrijfbaar is); anders in een map met een onvoorspelbare naam binnen
     * de werkmap van de plugin.
     */
    private function snapshotMap(): string
    {
        $root = realpath(JPATH_ROOT) ?: JPATH_ROOT;
        $webmappen = ['public_html', 'httpdocs', 'htdocs', 'www', 'html', 'public', 'web', 'private_html'];

        if (\in_array(strtolower(basename($root)), $webmappen, true)) {
            $boven = \dirname($root) . '/compactupdater_snapshots/' . substr(hash('sha256', $root), 0, 12);
            if (@is_dir($boven) || @mkdir($boven, 0750, true)) {
                if (@is_writable($boven)) {
                    return $boven;
                }
            }
        }

        $werk      = $this->werkMap();
        $naamBestand = $werk . '/snapshotmap.txt';
        $naam      = is_file($naamBestand) ? trim((string) file_get_contents($naamBestand)) : '';
        if (!preg_match('/^snapshots_[a-f0-9]{32}$/', $naam)) {
            $naam = 'snapshots_' . bin2hex(random_bytes(16));
            file_put_contents($naamBestand, $naam);
        }
        $map = $werk . '/' . $naam;
        if (!is_dir($map) && !@mkdir($map, 0750, true)) {
            throw new \RuntimeException('kon de snapshotmap niet aanmaken');
        }
        if (!is_file($map . '/index.html')) {
            @file_put_contents($map . '/index.html', '<!DOCTYPE html><title></title>');
        }

        return $map;
    }

    private function snapshotVoorvoegsel(array $ext): string
    {
        return preg_replace('/[^a-z0-9_]+/i', '-', $ext['type'] . '_' . $ext['folder'] . '_' . $ext['element'] . '_' . (int) $ext['client_id']);
    }

    private function ruimOudeSnapshotsOp(string $voorvoegsel): void
    {
        $bestanden = glob($this->snapshotMap() . '/' . $voorvoegsel . '__*.zip') ?: [];
        rsort($bestanden); // nieuwste eerst (de naam begint na het voorvoegsel met de datum)
        foreach (\array_slice($bestanden, self::SNAPSHOTS_BEWAREN) as $oud) {
            @unlink($oud);
        }
    }

    /**
     * Het IP-adres waarmee de monitor binnenkomt (voor een witte lijst in
     * een firewall). Achter een proxy het doorgegeven adres.
     */
    private function ipVanMonitor(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $kop) {
            if (!empty($_SERVER[$kop])) {
                $ip = trim(explode(',', (string) $_SERVER[$kop])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    // ------------------------------------------------------------------
    // Beveiliging van het verzoek
    // ------------------------------------------------------------------

    /**
     * Controleert sleutel, tijd, nonce en handtekening. Geeft de
     * gedecodeerde gegevens terug, of gooit een RuntimeException.
     */
    private function controleerVerzoek(): array
    {
        $input = $this->haalApplicatie()->getInput();

        if (strtoupper($input->getMethod()) !== 'POST') {
            throw new \RuntimeException('Alleen POST.');
        }

        $sleutelId    = (string) $input->post->getString('sleutel_id', '');
        $tijd         = (int) $input->post->getInt('tijd', 0);
        $nonce        = (string) $input->post->getString('nonce', '');
        $gegevensB64  = (string) $input->post->getString('gegevens', '');
        $handtekening = (string) $input->post->getString('handtekening', '');

        if (!preg_match('/^[a-f0-9]{16,64}$/', $sleutelId) || !preg_match('/^[a-f0-9]{32,64}$/', $nonce)) {
            throw new \RuntimeException('Ongeldig verzoek.');
        }

        $sleutel = $this->zoekSleutel($sleutelId);
        if ($sleutel === '') {
            throw new \RuntimeException('Onbekende monitor.');
        }

        $verwacht = hash_hmac('sha256', self::PROTOCOL . '|' . $sleutelId . '|' . $tijd . '|' . $nonce . '|' . $gegevensB64, $sleutel);
        if (!hash_equals($verwacht, $handtekening)) {
            throw new \RuntimeException('Ongeldige handtekening.');
        }

        if (abs(time() - $tijd) > self::GELDIGHEID) {
            throw new \RuntimeException('Verzoek verlopen (klok van de server en de monitor wijken te veel af).');
        }

        if (!$this->registreerNonce($nonce)) {
            throw new \RuntimeException('Dit verzoek is al eens gebruikt.');
        }

        $gegevens = json_decode((string) base64_decode($gegevensB64, true), true);
        if (!\is_array($gegevens)) {
            throw new \RuntimeException('Ongeldige gegevens.');
        }

        $this->actieveSleutel = $sleutel;
        $this->actieveNonce   = $nonce;

        return ['gegevens' => $gegevens];
    }

    /**
     * Elke monitor heeft een eigen bestand sleutels/<id>.php, dat een array
     * teruggeeft met 'id' en 'sleutel'.
     */
    private function zoekSleutel(string $sleutelId): string
    {
        $bestand = \dirname(__DIR__, 2) . '/sleutels/' . $sleutelId . '.php';
        if (!is_file($bestand)) {
            return '';
        }

        $gegevens = include $bestand;
        if (!\is_array($gegevens) || ($gegevens['id'] ?? '') !== $sleutelId) {
            return '';
        }

        $sleutel = (string) ($gegevens['sleutel'] ?? '');

        return \strlen($sleutel) >= 32 ? $sleutel : '';
    }

    /**
     * Onthoudt gebruikte nonces (tot ze verlopen zijn), zodat een
     * onderschept verzoek niet nog eens verstuurd kan worden.
     */
    private function registreerNonce(string $nonce): bool
    {
        $bestand = $this->werkMap() . '/nonces.json';
        $handle  = @fopen($bestand, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('De map administrator/cache van Joomla is niet schrijfbaar.');
        }

        flock($handle, LOCK_EX);
        $inhoud = stream_get_contents($handle);
        $lijst  = json_decode((string) $inhoud, true);
        $lijst  = \is_array($lijst) ? $lijst : [];

        $nu = time();
        foreach ($lijst as $n => $tijd) {
            if ($tijd < $nu - 2 * self::GELDIGHEID) {
                unset($lijst[$n]);
            }
        }

        if (isset($lijst[$nonce])) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return false;
        }

        $lijst[$nonce] = $nu;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($lijst));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return true;
    }

    // ------------------------------------------------------------------
    // Joomla zelf bijwerken
    // ------------------------------------------------------------------
    //
    // Dezelfde onderdelen als Joomla's eigen Joomla-update (component
    // com_joomlaupdate) en het opdrachtregelcommando core:update: het
    // updatemodel van com_joomlaupdate zoekt, downloadt (met controle van de
    // controlesom) en rondt af. Net als het webscherm van Joomla gebeurt het
    // in twee verzoeken: eerst downloaden, uitpakken en kopiëren
    // (kern_bijwerken), dan in een nieuw verzoek, met de nieuwe bestanden
    // geladen, afronden (kern_afronden: database bijwerken, oude bestanden
    // weg, opruimen).
    //
    // "Binnen" = binnen de huidige hoofdversie (Joomla's kanaal "default"),
    // "volgende" = de volgende hoofdversie (Joomla's kanaal "next"). Het
    // kanaal wordt alleen voor de duur van het verzoek omgezet; de instelling
    // van de site blijft ongewijzigd. Staat de site op een eigen updatebron
    // ("custom"), dan wordt die gebruikt zoals hij is.

    /** De hoofdversie van een versienummer ("6.2.1" → 6). */
    private function hoofdversie(string $versie): int
    {
        return (int) explode('.', $versie)[0];
    }

    /** Het updatekanaal dat de site zelf heeft ingesteld. */
    private function kernKanaal(): string
    {
        return (string) ComponentHelper::getParams('com_joomlaupdate')->get('updatesource', 'default');
    }

    /** Een vers updatemodel van com_joomlaupdate (zonder gegevens uit het verzoek). */
    private function kernUpdateModel()
    {
        $app = $this->haalApplicatie();
        $app->getLanguage()->load('com_joomlaupdate', JPATH_ADMINISTRATOR);
        $app->getLanguage()->load('com_installer', JPATH_ADMINISTRATOR);

        return $app->bootComponent('com_joomlaupdate')
            ->getMVCFactory()
            ->createModel('Update', 'Administrator', ['ignore_request' => true]);
    }

    /**
     * Laat Joomla opnieuw naar een kernupdate zoeken in het gevraagde
     * kanaal ('binnen' of 'volgende') en geeft het model terug (met de
     * gevonden update-informatie), of null bij een eigen updatebron die
     * het gevraagde kanaal niet kent.
     */
    private function zoekKernUpdate(string $doel)
    {
        $params   = ComponentHelper::getParams('com_joomlaupdate');
        $origineel = (string) $params->get('updatesource', 'default');

        if ($origineel !== 'custom') {
            $params->set('updatesource', $doel === 'volgende' ? 'next' : 'default');
        }

        try {
            $model = $this->kernUpdateModel();
            $model->purge();
            $model->refreshUpdates(true);
            $model->getUpdateInformation();
        } finally {
            $params->set('updatesource', $origineel);
        }

        return $model;
    }

    /**
     * Kerninformatie voor het ophalen: de huidige versie en de beschikbare
     * versie binnen de hoofdversie en (als gevraagd) de volgende hoofdversie.
     * Zonder $vers alleen wat Joomla al weet (geen verzoek naar buiten).
     */
    private function kernInfo(bool $vers, bool $volgende): array
    {
        $huidig = \defined('JVERSION') ? JVERSION : '';
        $info   = ['huidig' => $huidig, 'binnen' => '', 'volgende' => '', 'kanaal' => $this->kernKanaal(), 'fout' => ''];
        $cache  = $this->werkMap() . '/kern_volgende.json';

        try {
            if ($vers) {
                @set_time_limit(300);
                if ($volgende && $info['kanaal'] !== 'custom') {
                    $gevonden = $this->gevondenKernVersie($this->zoekKernUpdate('volgende'));
                    $info['volgende'] = $gevonden !== '' && $this->hoofdversie($gevonden) > $this->hoofdversie($huidig) ? $gevonden : '';
                    @file_put_contents($cache, json_encode(['versie' => $info['volgende'], 'voor' => $huidig, 'tijd' => time()]));
                }
                // Als laatste het eigen kanaal, zodat Joomla zelf daarna
                // weer de gewone update-informatie heeft.
                $gevonden = $this->gevondenKernVersie($this->zoekKernUpdate('binnen'));
            } else {
                $gevonden = $this->kernVersieInDatabase();
                if ($volgende && is_file($cache)) {
                    $c = json_decode((string) @file_get_contents($cache), true) ?: [];
                    if (($c['voor'] ?? '') === $huidig) {
                        $info['volgende'] = (string) ($c['versie'] ?? '');
                    }
                }
            }

            if ($gevonden !== '' && version_compare($gevonden, $huidig, '>')) {
                if ($this->hoofdversie($gevonden) === $this->hoofdversie($huidig)) {
                    $info['binnen'] = $gevonden;
                } elseif ($volgende && $info['volgende'] === '') {
                    // Eigen updatebron die al een nieuwe hoofdversie aanbiedt.
                    $info['volgende'] = $gevonden;
                }
            }
        } catch (\Throwable $e) {
            $info['fout'] = $e->getMessage();
        }

        return $info;
    }

    /** De versie die het model heeft gevonden, of '' als er geen update is. */
    private function gevondenKernVersie($model): string
    {
        $info = $model->getUpdateInformation();

        return !empty($info['hasUpdate']) ? (string) $info['latest'] : '';
    }

    /** De kernupdate die Joomla al in #__updates heeft staan (zonder te zoeken). */
    private function kernVersieInDatabase(): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $id = (int) ExtensionHelper::getExtensionRecord('joomla', 'file')->extension_id;

        $query = $db->getQuery(true)
            ->select('version')
            ->from($db->quoteName('#__updates'))
            ->where('extension_id = ' . $id);

        $hoogste = '';
        foreach ((array) $db->setQuery($query)->loadColumn() as $versie) {
            if ($hoogste === '' || version_compare((string) $versie, $hoogste, '>')) {
                $hoogste = (string) $versie;
            }
        }

        return $hoogste;
    }

    /**
     * Joomla's eigen controles vóór een kernupdate. Geeft
     * [problemen (blokkeren), waarschuwingen (niet blokkeren), extensies].
     * Bij een nieuwe hoofdversie ook de geschiktheid van de extensies, net
     * als de controle vooraf in Joomla's eigen Joomla-update.
     */
    private function kernControles($model, string $naar, bool $volgende): array
    {
        $problemen      = [];
        $waarschuwingen = [];
        $extensies      = ['ongeschikt' => [], 'onbekend' => []];

        $schoon = function ($tekst): string {
            return trim(html_entity_decode(strip_tags((string) $tekst), ENT_QUOTES, 'UTF-8'));
        };

        foreach ((array) $model->getPhpOptions() as $optie) {
            if ($optie->state) {
                continue;
            }
            $regel = $schoon($optie->label) . ($optie->notice ? ' (' . $schoon($optie->notice) . ')' : '');
            // De standaardtaal van mbstring is alleen een aanbeveling.
            if (stripos((string) $optie->label, 'mbstring') !== false || strpos((string) $optie->label, 'INSTL_MB_LANGUAGE') !== false) {
                $waarschuwingen[] = $regel;
            } else {
                $problemen[] = $regel;
            }
        }

        if ($volgende) {
            @set_time_limit(300);
            foreach ((array) $model->getNonCoreExtensions() as $extensie) {
                try {
                    $resultaat = $model->fetchCompatibility((int) $extensie->extension_id, $naar);
                } catch (\Throwable $e) {
                    $resultaat = (object) ['state' => 3];
                }
                $naam = $schoon($extensie->name) . ' ' . $schoon($extensie->version ?? '');
                $staat = (int) ($resultaat->state ?? 3);
                if ($staat === 0 || ($staat === 1 && empty($resultaat->compatibleVersions))) {
                    $extensies['ongeschikt'][] = trim($naam);
                } elseif ($staat !== 1) {
                    $extensies['onbekend'][] = trim($naam);
                }
            }
            if ($extensies['ongeschikt']) {
                $problemen[] = \count($extensies['ongeschikt']) . ' extensie(s) zijn (nog) niet geschikt voor Joomla ' . $naar . ': '
                    . implode(', ', \array_slice($extensies['ongeschikt'], 0, 10));
            }
        }

        return [$problemen, $waarschuwingen, $extensies];
    }

    /**
     * Zoekt de kernupdate en doet de controles vooraf, zonder iets te
     * veranderen. Voor de monitor om vóór het bijwerken te tonen.
     */
    private function actieKernControle(string $doel): array
    {
        $doel  = $doel === 'volgende' ? 'volgende' : 'binnen';
        $model = $this->zoekKernUpdate($doel);
        $info  = $model->getUpdateInformation();
        $van   = \defined('JVERSION') ? JVERSION : '';

        if (empty($info['hasUpdate'])) {
            return ['succes' => true, 'status' => 'geen_update', 'van' => $van, 'bericht' => 'Joomla heeft (nu) geen update klaarstaan.'];
        }

        $naar = (string) $info['latest'];
        if (($this->hoofdversie($naar) > $this->hoofdversie($van)) !== ($doel === 'volgende')) {
            return ['succes' => true, 'status' => 'geen_update', 'van' => $van, 'naar' => $naar,
                'bericht' => $doel === 'volgende' ? 'Er is (nog) geen nieuwe hoofdversie beschikbaar.' : 'Er is geen update binnen de huidige hoofdversie.'];
        }

        [$problemen, $waarschuwingen, $extensies] = $this->kernControles($model, $naar, $doel === 'volgende');

        return [
            'succes'         => true,
            'status'         => $problemen ? 'geblokkeerd' : 'ok',
            'van'            => $van,
            'naar'           => $naar,
            'problemen'      => $problemen,
            'waarschuwingen' => $waarschuwingen,
            'extensies'      => $extensies,
        ];
    }

    /**
     * Stap 1 van de kernupdate: controleren, downloaden (met controlesom),
     * uitpakken en de bestanden over de site kopiëren. Afronden gebeurt in
     * een nieuw verzoek (actieKernAfronden()), zoals in Joomla zelf.
     */
    private function actieKernBijwerken(string $doel, string $verwachteVersie): array
    {
        if (!$this->updatesToegestaan()) {
            return ['succes' => false, 'status' => 'uitgeschakeld', 'bericht' => 'Updates via de monitor staan op deze site uit (instelling van de plugin).'];
        }
        // Gekopieerd maar nog niet afgerond: eerst afronden. (Een poging die
        // halverwege het kopiëren stopte, mag gewoon opnieuw.)
        if (($this->leesKernStatus()['fase'] ?? '') === 'gekopieerd') {
            return ['succes' => false, 'status' => 'nog_afronden', 'bericht' => 'Een eerdere Joomla-update is nog niet afgerond.'];
        }
        if (!$this->neemLock()) {
            return ['succes' => false, 'status' => 'bezig', 'bericht' => 'Op deze site loopt al een update (mogelijk vanuit een andere monitor). Probeer het zo opnieuw.'];
        }

        // Moet afmaken, ook als de monitor de verbinding verbreekt.
        @ignore_user_abort(true);
        @set_time_limit(0);

        $doel  = $doel === 'volgende' ? 'volgende' : 'binnen';
        $van   = \defined('JVERSION') ? JVERSION : '';
        $params    = ComponentHelper::getParams('com_joomlaupdate');
        $origineel = (string) $params->get('updatesource', 'default');

        $model = $this->zoekKernUpdate($doel);
        $info  = $model->getUpdateInformation();

        if (empty($info['hasUpdate'])) {
            return ['succes' => false, 'status' => 'geen_update', 'bericht' => 'Joomla heeft (nu) geen update klaarstaan.'];
        }
        $naar = (string) $info['latest'];
        if ($verwachteVersie !== '' && $naar !== $verwachteVersie) {
            return ['succes' => false, 'status' => 'andere_versie', 'nieuwe_versie' => $naar,
                'bericht' => 'Joomla biedt nu versie ' . $naar . ' aan in plaats van ' . $verwachteVersie . '. Haal de updates opnieuw op.'];
        }
        if (($this->hoofdversie($naar) > $this->hoofdversie($van)) !== ($doel === 'volgende')) {
            return ['succes' => false, 'status' => 'geen_update', 'bericht' => 'Deze update hoort niet bij het gevraagde soort bijwerken.'];
        }

        [$problemen] = $this->kernControles($model, $naar, $doel === 'volgende');
        if ($problemen) {
            return ['succes' => false, 'status' => 'geblokkeerd', 'problemen' => $problemen,
                'bericht' => 'Niet bijgewerkt: de controle vooraf vond een probleem. ' . $problemen[0]];
        }

        // Downloaden in de eigen werkmap (zie werkMap()), met controle van
        // de controlesom zoals Joomla zelf dat doet.
        $app        = $this->haalApplicatie();
        $oudeTmp    = $app->get('tmp_path');
        $werkTmp    = $this->werkMap() . '/kern';
        if (!is_dir($werkTmp) && !@mkdir($werkTmp, 0755, true)) {
            return ['succes' => false, 'status' => 'fout', 'bericht' => 'De werkmap van de plugin is niet schrijfbaar.'];
        }
        $this->leegMap($werkTmp);
        $app->set('tmp_path', $werkTmp);

        try {
            // Voor het downloaden het juiste kanaal (de update-informatie
            // wordt dan opnieuw bij dezelfde bron opgevraagd).
            if ($origineel !== 'custom') {
                $params->set('updatesource', $doel === 'volgende' ? 'next' : 'default');
            }
            $download = $model->download();
        } finally {
            $params->set('updatesource', $origineel);
            $app->set('tmp_path', $oudeTmp);
        }

        if (empty($download['basename'])) {
            return ['succes' => false, 'status' => 'mislukt', 'bericht' => 'Het downloaden van Joomla ' . $naar . ' is mislukt.'];
        }
        if ($download['check'] === false) {
            $this->leegMap($werkTmp);
            return ['succes' => false, 'status' => 'mislukt', 'bericht' => 'Het gedownloade pakket klopt niet met de controlesom van Joomla; er is niets veranderd.'];
        }

        $pakket   = $werkTmp . '/' . $download['basename'];
        $uitgepakt = InstallerHelper::unpack($pakket, true);
        if (empty($uitgepakt['extractdir']) || !is_dir($uitgepakt['extractdir'])) {
            $this->leegMap($werkTmp);
            return ['succes' => false, 'status' => 'mislukt', 'bericht' => 'Het pakket van Joomla ' . $naar . ' kon niet worden uitgepakt; er is niets veranderd.'];
        }

        // Vanaf hier verandert de site. Eerst vastleggen, zodat afronden
        // altijd nog kan, ook als dit verzoek halverwege stopt.
        $this->schrijfKernStatus(['fase' => 'kopieren', 'van' => $van, 'naar' => $naar, 'pakket' => $pakket,
            'map' => $uitgepakt['extractdir'], 'tijd' => time()]);

        try {
            Folder::copy($uitgepakt['extractdir'], JPATH_ROOT, '', true);
        } catch (\Throwable $e) {
            return ['succes' => false, 'status' => 'mislukt', 'bericht' => 'Het kopiëren van de nieuwe Joomla-bestanden is mislukt: ' . $e->getMessage()
                . ' Een deel van de bestanden kan al vervangen zijn; zet zo nodig de back-up van de hosting terug.'];
        }

        $this->schrijfKernStatus(['fase' => 'gekopieerd', 'van' => $van, 'naar' => $naar, 'pakket' => $pakket,
            'map' => $uitgepakt['extractdir'], 'tijd' => time()]);

        // Oude gecompileerde PHP-code weggooien, zodat het afronden de
        // nieuwe bestanden gebruikt.
        if (\function_exists('opcache_reset')) {
            @opcache_reset();
        }

        return ['succes' => true, 'status' => 'gekopieerd', 'van' => $van, 'naar' => $naar,
            'bericht' => 'Bestanden van Joomla ' . $naar . ' geplaatst; nu afronden.'];
    }

    /**
     * Stap 2 van de kernupdate, in een nieuw verzoek: de database en de
     * registratie bijwerken, oude bestanden weghalen (Joomla's eigen
     * finaliseUpgrade()) en opruimen.
     */
    private function actieKernAfronden(): array
    {
        $status = $this->leesKernStatus();
        if (!$status || ($status['fase'] ?? '') !== 'gekopieerd') {
            return ['succes' => false, 'status' => 'niets_af_te_ronden', 'bericht' => 'Er is geen Joomla-update om af te ronden.'];
        }
        if (!$this->neemLock()) {
            return ['succes' => false, 'status' => 'bezig', 'bericht' => 'Op deze site loopt al een update. Probeer het zo opnieuw.'];
        }

        @ignore_user_abort(true);
        @set_time_limit(0);

        $model = $this->kernUpdateModel();
        $this->haalBerichten();

        $gelukt = false;
        try {
            $gelukt = (bool) $model->finaliseUpgrade();
        } catch (\Throwable $e) {
            $model->collectError('finaliseUpgrade', $e);
        }

        $fouten = [];
        foreach ((array) $model->getErrors() as $fout) {
            $fouten[] = $fout instanceof \Throwable ? $fout->getMessage() : (string) $fout;
        }
        $berichten = $this->haalBerichten();

        if (!$gelukt) {
            return ['succes' => false, 'status' => 'mislukt', 'berichten' => $berichten,
                'bericht' => 'Het afronden van de Joomla-update is mislukt' . ($fouten ? ': ' . $fouten[0] : '.')
                    . ' Probeer het afronden opnieuw; lukt dat niet, zet dan de back-up van de hosting terug.'];
        }

        // Opruimen, zoals core:update en het webscherm van Joomla.
        try {
            $model->resetUpdateSource();
        } catch (\Throwable $e) {
        }
        foreach ([JPATH_ADMINISTRATOR . '/cache/autoload_psr4.php', JPATH_ROOT . '/cache/autoload_psr4.php', JPATH_ROOT . '/joomla.xml'] as $bestand) {
            if (is_file($bestand)) {
                @unlink($bestand);
            }
        }
        try {
            InstallerHelper::cleanupInstall((string) $status['pakket'], (string) $status['map']);
        } catch (\Throwable $e) {
        }
        $this->leegMap($this->werkMap() . '/kern');
        try {
            $model->purge();
            $this->haalApplicatie()->setUserState('com_joomlaupdate.oldversion', (string) $status['van']);
            $model->cleanUp();
        } catch (\Throwable $e) {
        }
        $this->wisKernStatus();
        $this->haalBerichten();

        $naVersie = $this->kernVersieUitManifest();

        return [
            'succes'    => true,
            'status'    => 'geinstalleerd',
            'van'       => (string) $status['van'],
            'naar'      => (string) $status['naar'],
            'na_versie' => $naVersie,
            'fouten'    => $fouten,
            'bericht'   => 'Joomla bijgewerkt naar ' . ($naVersie ?: $status['naar']) . '.',
        ];
    }

    /** De versie van Joomla volgens de registratie in #__extensions. */
    private function kernVersieUitManifest(): string
    {
        $db    = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('manifest_cache')
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('file'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('joomla'));
        $manifest = json_decode((string) $db->setQuery($query)->loadResult(), true) ?: [];

        return (string) ($manifest['version'] ?? '');
    }

    private function leesKernStatus(): array
    {
        $bestand = $this->werkMap() . '/kern_status.json';

        return is_file($bestand) ? (json_decode((string) @file_get_contents($bestand), true) ?: []) : [];
    }

    private function schrijfKernStatus(array $status): void
    {
        @file_put_contents($this->werkMap() . '/kern_status.json', json_encode($status));
    }

    private function wisKernStatus(): void
    {
        @unlink($this->werkMap() . '/kern_status.json');
    }

    // ------------------------------------------------------------------
    // Lock: maar één installatie tegelijk per site, ook bij meerdere monitors
    // ------------------------------------------------------------------

    private function neemLock(): bool
    {
        $handle = @fopen($this->werkMap() . '/update.lock', 'c');
        if ($handle === false) {
            return false;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        $this->lockHandle = $handle;

        return true;
    }

    private function laatLockLos(): void
    {
        if ($this->lockHandle !== null) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    private function isBezig(): bool
    {
        $handle = @fopen($this->werkMap() . '/update.lock', 'c');
        if ($handle === false) {
            return false;
        }
        $vrij = flock($handle, LOCK_EX | LOCK_NB);
        if ($vrij) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return !$vrij;
    }

    // ------------------------------------------------------------------
    // Antwoorden
    // ------------------------------------------------------------------

    private function stuurOndertekend(array $antwoord): void
    {
        $antwoordB64  = base64_encode((string) json_encode($antwoord));
        $handtekening = hash_hmac('sha256', 'antwoord|' . $this->actieveNonce . '|' . $antwoordB64, $this->actieveSleutel);

        $this->stuur(200, [
            'protocol'     => self::PROTOCOL,
            'antwoord'     => $antwoordB64,
            'handtekening' => $handtekening,
        ]);
    }

    private function stuurOnondertekend(int $code, string $bericht): void
    {
        $this->stuur($code, ['protocol' => self::PROTOCOL, 'fout' => $bericht]);
    }

    private function stuur(int $code, array $inhoud): void
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Robots-Tag: noindex');
        echo json_encode($inhoud);

        $this->haalApplicatie()->close();
    }

    // ------------------------------------------------------------------
    // Hulpfuncties
    // ------------------------------------------------------------------

    private function haalApplicatie()
    {
        return method_exists($this, 'getApplication') && $this->getApplication() ? $this->getApplication() : Factory::getApplication();
    }

    private function updatesToegestaan(): bool
    {
        return (int) $this->params->get('toestaan', 1) === 1;
    }

    /**
     * Eigen werkmap van de plugin (lock, gebruikte nonces, uitpakken van
     * pakketten). Bewust NIET Joomla's tmp-map: die wordt door
     * beveiligingsscans en opruimfuncties geleegd, ook midden in een update.
     */
    private function werkMap(): string
    {
        $map = JPATH_ADMINISTRATOR . '/cache/compactupdater';
        if (!is_dir($map)) {
            @mkdir($map, 0755, true);
            @file_put_contents($map . '/index.html', '<!DOCTYPE html><title></title>');
        }

        if (is_dir($map) && is_writable($map)) {
            return $map;
        }

        $tmp = rtrim((string) $this->haalApplicatie()->get('tmp_path', JPATH_ROOT . '/tmp'), '/\\');

        return is_dir($tmp) && is_writable($tmp) ? $tmp : sys_get_temp_dir();
    }

    /** Ruimt alles op in een map (niet de map zelf). */
    private function leegMap(string $map): void
    {
        if (!is_dir($map)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($map, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
    }

    private function minimaleStabiliteit(): int
    {
        return (int) ComponentHelper::getParams('com_installer')->get('minimum_stability', Updater::STABILITY_STABLE);
    }

    /**
     * Het Update-model van Extensiebeheer: dezelfde code als de knoppen
     * "Zoek naar updates" en "Bijwerken".
     */
    private function installerUpdateModel()
    {
        return $this->haalApplicatie()
            ->bootComponent('com_installer')
            ->getMVCFactory()
            ->createModel('Update', 'Administrator', ['ignore_request' => true]);
    }

    private function laadTalen(): void
    {
        $taal = $this->haalApplicatie()->getLanguage();
        $taal->load('lib_joomla', JPATH_ADMINISTRATOR);
        $taal->load('com_installer', JPATH_ADMINISTRATOR);
    }

    /**
     * Haalt de meldingen van Joomla op (en leegt de wachtrij), als platte tekst.
     */
    private function haalBerichten(): array
    {
        $app = $this->haalApplicatie();
        if (!method_exists($app, 'getMessageQueue')) {
            return [];
        }

        $uit = [];
        foreach ((array) $app->getMessageQueue(true) as $bericht) {
            $tekst = trim(html_entity_decode(strip_tags((string) ($bericht['message'] ?? '')), ENT_QUOTES, 'UTF-8'));
            if ($tekst !== '') {
                $uit[] = ['type' => (string) ($bericht['type'] ?? 'message'), 'tekst' => mb_substr($tekst, 0, 1000)];
            }
        }

        return $uit;
    }

    private function eersteFout(array $berichten): string
    {
        foreach ($berichten as $bericht) {
            if (\in_array($bericht['type'], ['error', 'danger', 'warning'], true)) {
                return $bericht['tekst'];
            }
        }

        return $berichten[0]['tekst'] ?? '';
    }

    private function isJoomlaKern($rij): bool
    {
        return ((string) $rij->type === 'file' && (string) $rij->element === 'joomla')
            || (string) ($rij->site_type ?? '') === 'tuf';
    }

    /**
     * De versie die nu in #__extensions staat. Een pakket kan een extensie
     * bij een update een nieuw ID geven; dan zoeken we op type/element.
     */
    private function huidigeVersie(int $extensionId, $rij): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select('manifest_cache')
            ->from($db->quoteName('#__extensions'))
            ->where('extension_id = ' . (int) $extensionId);
        $cache = $db->setQuery($query)->loadResult();

        if ($cache === null) {
            $query = $db->getQuery(true)
                ->select('manifest_cache')
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote((string) $rij->type))
                ->where($db->quoteName('element') . ' = ' . $db->quote((string) $rij->element))
                ->where($db->quoteName('folder') . ' = ' . $db->quote((string) $rij->folder))
                ->where($db->quoteName('client_id') . ' = ' . (int) $rij->client_id);
            $cache = $db->setQuery($query)->loadResult();
        }

        $manifest = json_decode((string) $cache, true) ?: [];

        return (string) ($manifest['version'] ?? '');
    }

    /**
     * Naam van de extensie zoals een mens hem kent. De naam in #__extensions
     * is vaak een taalsleutel (bijv. PLG_SYSTEM_...); de naam uit de
     * updatefeed is meestal leesbaar.
     */
    private function leesbareNaam($rij): string
    {
        $updateNaam   = trim((string) $rij->update_naam);
        $extensieNaam = trim((string) $rij->extensie_naam);

        if ($updateNaam !== '' && !preg_match('/^[A-Z0-9_]+$/', $updateNaam)) {
            return $updateNaam;
        }

        return $extensieNaam !== '' ? $extensieNaam : $updateNaam;
    }
}
