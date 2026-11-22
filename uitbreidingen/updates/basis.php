<?php
// basis.php
//
// Gedeelde basis voor de uitbreiding "Updates" (map uitbreidingen/updates
// van de monitor). Wordt als eerste geladen door elk bestand van deze
// uitbreiding.
//
// De uitbreiding staat twee mappen dieper dan de monitor zelf, maar
// gebruikt wel diens inlogsessie, database, instellingen en opmaak. Alles
// van de monitor wordt daarom geladen via MONITOR_MAP, en links naar
// pagina's van de monitor lopen via MONITOR_URL ('../../').

// Dit bestand en updates_functies.php zijn alleen bedoeld om door de
// pagina's van de uitbreiding geladen te worden; die doen zelf de
// inlogcontrole van de monitor (of, bij de cronjob, die van
// endpoint_beveiliging.php). Rechtstreeks openen wordt geweigerd.
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME'])
    && in_array(realpath((string) $_SERVER['SCRIPT_FILENAME']), [realpath(__FILE__), realpath(__DIR__ . '/updates_functies.php')], true)) {
    http_response_code(403);
    exit('Niet toegestaan.');
}

if (!defined('MONITOR_MAP')) {
    define('MONITOR_MAP', dirname(__DIR__, 2));
}
if (!defined('MONITOR_URL')) {
    define('MONITOR_URL', '../../');
}

/**
 * De favicon-tags van de monitor, met de paden aangepast aan deze submap.
 * favicon_tags.php schrijft paden die vanaf de hoofdmap van de monitor
 * gelden (bijv. "favicon-32x32.png"); vanuit deze map moeten die met
 * MONITOR_URL beginnen.
 */
function updatesFaviconTags(PDO $pdo): string
{
    ob_start();
    include MONITOR_MAP . '/favicon_tags.php';
    $html = (string) ob_get_clean();

    return preg_replace_callback('/\b(href|src)="([^"]*)"/', function ($m) {
        $pad = $m[2];
        if ($pad === '' || $pad[0] === '/' || $pad[0] === '#' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $pad)) {
            return $m[0];
        }

        return $m[1] . '="' . MONITOR_URL . $pad . '"';
    }, $html);
}
