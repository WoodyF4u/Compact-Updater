<?php
// help.php (uitbreiding "Updates", map uitbreidingen/updates)
//
// Uitleg bij de uitbreiding "Updates". De vraagtekentjes op updates.php
// verwijzen naar de ankers in deze pagina (hulpIcoon() maakt relatieve
// links naar help.php, dus naar deze pagina in dezelfde map).
require_once __DIR__ . '/basis.php';
require_once MONITOR_MAP . '/sessie_start.php';
if (!isset($_SESSION['ingelogd'])) {
    header("Location: " . MONITOR_URL . "login.php");
    exit;
}
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require_once MONITOR_MAP . '/config.php';
require_once MONITOR_MAP . '/instellingen_functies.php';
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
<title>Help - Updates</title>
<style>

body {
    font-family: Arial, sans-serif;
    margin: 20px;
    font-size: 15px;
    line-height: 1.6;
}

.help-inhoud {
    max-width: 900px;
}

header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 5px;
}

h1 {
    margin: 0 0 5px 0;
}

.subtitel {
    color: #555;
    margin-bottom: 20px;
}

.hoofdstuk-knoppen {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    margin-bottom: 10px;
}
.knop.knop-licht {
    background: transparent;
    color: var(--thema-link);
    border: 1px solid var(--thema-rand);
    padding: 5px 10px;
    font-size: 13px;
    cursor: pointer;
}
.knop.knop-licht:hover {
    background: var(--thema-zebra);
}
details.hoofdstuk {
    border: 1px solid var(--thema-rand);
    border-radius: 6px;
    margin-bottom: 8px;
    overflow: hidden;
}
details.hoofdstuk > summary {
    list-style: none;
    cursor: pointer;
    /* Vast blauw (gelijk aan de kopkleur in donkere weergave), ook in lichte
       weergave - daar is de algemene kopkleur zwart. */
    background: #2c5f8a;
    color: #fff;
    padding: 10px 14px 10px 36px;
    position: relative;
    user-select: none;
}
details.hoofdstuk > summary::-webkit-details-marker {
    display: none;
}
details.hoofdstuk > summary::before {
    content: "\25B8";
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    transition: transform 0.15s;
}
details.hoofdstuk[open] > summary::before {
    transform: translateY(-50%) rotate(90deg);
}
details.hoofdstuk > summary:hover {
    filter: brightness(1.1);
}
details.hoofdstuk > summary h2 {
    display: inline;
    font-size: 17px;
    margin: 0;
    padding: 0;
    background: none;
    color: inherit;
}
.hoofdstuk-inhoud {
    padding: 4px 18px 16px 18px;
}
ol.stappenlijst {
    margin: 8px 0;
    padding-left: 24px;
}
ol.stappenlijst li {
    margin-bottom: 3px;
}
h3 {
    font-size: 16px;
    margin: 18px 0 6px 0;
    color: var(--thema-link);
}

code {
    background: #eef1f4;
    padding: 1px 6px;
    border-radius: 3px;
    font-size: 14px;
}

.stap {
    margin-bottom: 10px;
    padding: 10px 14px;
    background: #f9f9f9;
    border-left: 3px solid #1f6fa8;
}

.tip {
    padding: 10px 14px;
    background: #eef6fb;
    border: 1px solid #cfe4f0;
    border-radius: 6px;
    margin-top: 10px;
}

.waarschuwing {
    padding: 10px 14px;
    background: #fff3cd;
    border: 1px solid #ffe69c;
    border-radius: 6px;
    margin-top: 10px;
    color: #664d03;
}

table {
    border-collapse: collapse;
    width: 100%;
    margin: 10px 0;
}

th, td {
    border: 1px solid var(--thema-rand);
    padding: 6px 8px;
    font-size: 14px;
    text-align: left;
    vertical-align: top;
}

th {
    background: var(--thema-zebra);
}

.badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: bold;
    color: white;
}

.badge-rood   { background: #c0392b; }
.badge-groen  { background: #2e8b3d; }
.badge-grijs  { background: #888; }
.badge-oranje { background: #e67e22; }

.knop {
    display: inline-block;
    padding: 8px 14px;
    background: #333;
    color: white;
    text-decoration: none;
    border-radius: 4px;
    font-size: 14px;
}

.knop:hover {
    background: #555;
}

</style>
<?php include MONITOR_MAP . '/responsive_stijlen.php'; ?>
</head>
<body>

<header>
    <div>
        <h1>❓ Help: Updates</h1>
        <div class="subtitel">Extensies op alle sites centraal bijwerken (uitbreiding van de monitor).</div>
    </div>
    <div style="display: flex; gap: 8px;">
        <button type="button" class="knop" onclick="history.back()" style="padding: 8px 12px;" title="Eén stap terug">←</button>
        <a class="knop" href="updates.php">Naar Updates</a>
        <a class="knop" href="<?php echo MONITOR_URL; ?>index.php">Terug naar monitor</a>
    </div>
</header>

<div class="help-inhoud">

<details class="hoofdstuk" id="updates" open>
    <summary><h2>Extensies centraal bijwerken</h2></summary>
    <div class="hoofdstuk-inhoud">
    <p>Via de knop <strong>📦 Updates</strong> (rechts in de samenvattingsbalk op de monitorpagina; die verschijnt zodra deze uitbreiding in de map <code>uitbreidingen/updates</code> staat) zie je alle extensie-updates
    van alle sites in één overzicht, en werk je ze vanuit de monitor bij: per stuk, of alles wat je daarvoor hebt aangezet in één
    keer. De updates zijn precies de updates die Joomla zelf op elke site heeft gevonden (in Joomla:
    <em>Systeem &gt; Bijwerken &gt; Extensies</em>). Ook Joomla zelf kan zo worden bijgewerkt (zie
    <a href="#updates-joomla">Joomla zelf bijwerken</a>).</p>
    <p>Het werkt via een kleine plugin op elke site, de <strong>Compact Updater</strong>. De monitor zet die zelf neer
    met de FTP-/SFTP-gegevens van de site. Je hebt er geen Joomla-account of wachtwoord voor nodig: elke monitor krijgt per site een
    eigen geheime sleutel, en elk verzoek van de monitor is daarmee ondertekend, maar een paar minuten geldig en maar één keer
    bruikbaar. Volgen twee monitors dezelfde site, dan werkt dat naast elkaar; per site wordt er altijd maar één update tegelijk
    geïnstalleerd.</p>
    <p>Bovenaan kies je tussen <em>Eigen websites</em> en <em>Websites van anderen</em>. Sites van anderen worden alleen bijgewerkt
    als dat bij <a href="#updates-instellingen">Instellingen</a> is aangevinkt.</p>
    <p>Daaronder staan de knoppen voor de hele pagina, met de gele knop voor het ophalen van updates voorop (zie
    <a href="#updates-ophalen">Updates ophalen</a>). De rest van de
    pagina is verdeeld over tabbladen; het gekozen tabblad is blauw:</p>
    <ul>
        <li><strong>📦 Beschikbare updates</strong> - de updates die nu klaarstaan (zie <a href="#updates-lijst">Beschikbare updates</a>).</li>
        <li><strong>🧩 Extensies</strong> - alle extensies op de sites, <em>per extensie</em> of <em>per website</em>, met wat er met hun
        updates mag gebeuren (zie <a href="#updates-extensie-instellingen">Extensies</a>).</li>
        <li><strong>🏛️ Joomla</strong> - Joomla zelf bijwerken: per site de huidige versie en de beschikbare update (zie
        <a href="#updates-joomla">Joomla zelf bijwerken</a>).</li>
        <li><strong>🔌 Updater-plugins</strong> - de plugin op elke site (zie <a href="#updates-plugin">Updater-plugins</a>). Is dat
        eenmaal ingericht, dan hoef je hier alleen nog te kijken als er op het tabblad een melding staat: hoeveel sites nog geen of
        een verouderde plugin hebben, en met ⚠️ hoeveel sites een fout, uitgeschakelde updatesites of uitgeschakelde updates hebben.</li>
        <li><strong>🕓 Geschiedenis</strong> - wat er via de monitor is bijgewerkt of teruggezet (zie <a href="#updates-geschiedenis">De geschiedenis</a>).</li>
    </ul>
    <p>De pagina onthoudt het gekozen tabblad, ook na verversen of wisselen van categorie.</p>

    <h3 id="updates-voortgang">Het voortgangsblok</h3>
    <p>Na <strong>Updates ophalen</strong>, <strong>Alle geactiveerde updates uitvoeren</strong> of het plaatsen van de plugin op
    meer sites tegelijk verschijnt boven de tabbladen een groen omlijnd blok. Bovenaan staat wat het doet (bijvoorbeeld
    <em>Updates worden opgehaald</em>), daaronder per site of per update de stand. Het blok groeit mee met het aantal sites. Is
    alles verwerkt, dan verandert de kop (bijvoorbeeld in <em>Updates opgehaald</em>), staat onderaan de uitkomst en de knop
    <strong>🔄 Pagina verversen</strong>, en schuift de pagina vanzelf naar die knop. Die laadt de hele pagina met de nieuwe
    gegevens en begint weer bovenaan.</p>

    <h3 id="updates-ophalen">Updates ophalen</h3>
    <p>Bij het ophalen zoekt Joomla op de sites opnieuw naar updates. Dat kan op twee manieren:</p>
    <ul>
        <li><strong>Updates ophalen bij … gescande site(s)</strong> (geel) - alleen de sites waar de laatste scan van de monitor
        verouderde extensies vond: dezelfde sites die op de voorpagina rood staan bij <em>Extensies</em>. Dat gaat een stuk sneller.
        Wanneer die scan liep, zie je als je met de muis op de knop staat.</li>
        <li><strong>Alle sites ophalen</strong> - alle sites waar de plugin staat. Joomla kan updates vinden die de scan van de
        monitor (nog) niet kende, bijvoorbeeld voor extensies waarvan de monitor de nieuwste versie niet weet. Doe dit dus af en
        toe ook.</li>
    </ul>
    <p>Vond de laatste scan bij geen enkele site verouderde extensies, dan is er alleen de gele knop <strong>Updates ophalen bij alle
    sites</strong>. Uitgesloten sites en sites zonder plugin worden altijd overgeslagen.</p>

    <h3 id="updates-aan-de-slag">Aan de slag</h3>
    <ol>
        <li>Open het tabblad <strong>🔌 Updater-plugins</strong> en klik op de blauwe knop <strong>Plugin installeren op … site(s)
        zonder plugin</strong> (of per site <strong>Installeren</strong>).</li>
        <li>Klik op de gele knop om updates op te halen: Joomla zoekt dan op de sites opnieuw naar updates (zie
        <a href="#updates-ophalen">Updates ophalen</a>).</li>
        <li>Kies op het tabblad <strong>🧩 Extensies</strong> per extensie wat er mag (zie
        <a href="#updates-extensie-instellingen">Extensies</a>). Dat kan al voordat er een update is.</li>
        <li>Werk losse updates bij met <strong>Bijwerken</strong>, of gebruik <strong>Alle geactiveerde updates uitvoeren</strong>.
        Staan er updates klaar die automatisch mee mogen, dan is die knop blauw met een rand eromheen en staat het aantal erachter;
        anders is hij grijs.</li>
    </ol>

    <h3 id="updates-plugin">Het tabblad "Updater-plugins"</h3>
    <p>Per website de stand van de plugin. Is de pagina net geopend, dan klopt deze tabel met de laatste keer dat de monitor contact
    had met de site.</p>
    <p id="updates-plugin-knoppen">Boven de tabel staan alleen knoppen als er iets te doen is, met het aantal sites erin:
    <strong>Plugin installeren op … site(s) zonder plugin</strong> en <strong>… verouderde plugin(s) bijwerken</strong>. Is er
    niets te doen, dan staat er dat alle sites de actuele plugin hebben. Sites zonder FTP-gegevens kunnen de plugin niet krijgen;
    hoeveel dat er zijn, staat er apart bij. Vul daar de FTP-gegevens in bij de site-instellingen van de monitor, of sluit ze uit.
    Uitgesloten sites worden altijd overgeslagen.</p>
    <ul>
        <li><strong>Website</strong> - de site zoals die in de monitor staat. De naam opent de website. <em>geen FTP</em> betekent dat
        er bij de site geen FTP-/SFTP-gegevens zijn ingevuld; dan kan de monitor de plugin niet neerzetten.</li>
        <li id="updates-plugin-status"><strong>Plugin</strong> - de versie van de plugin op de site. <em>Niet geïnstalleerd</em>: de
        plugin staat er nog niet (of de monitor heeft nog nooit antwoord gekregen). <em>verouderd</em>: er is een nieuwere versie
        (de <em>actuele versie</em> boven de tabel); gebruik <strong>Bijwerken</strong>. Daaronder kunnen meldingen staan: de laatste fout bij het contact met de site,
        <em>Updates staan uit op de site</em> (de schakelaar in de plugin, zie hieronder), of <em>uitgeschakelde updatesites</em>.</li>
        <li id="updates-plugin-joomlaversie"><strong>Joomla</strong> - de versie van Joomla op de site, zoals de plugin die doorgeeft bij elk contact.</li>
        <li id="updates-opgehaald"><strong>Laatst opgehaald</strong> - wanneer Joomla op deze site voor het laatst via de monitor naar
        updates heeft gezocht (met <strong>Updates ophalen</strong>, <strong>Ophalen</strong> of de cronjob).</li>
        <li id="updates-site-instelling"><strong>Instelling voor de hele site</strong> - <em>Volgt de instellingen per extensie</em>
        (standaard), of <em>Altijd handmatig</em>: dan gaat er van deze site nooit iets mee met
        <strong>Alle geactiveerde updates uitvoeren</strong> of de cronjob, tenzij je voor één extensie op deze site zelf iets anders
        kiest.</li>
        <li id="updates-plugin-acties"><strong>Actie</strong>:
            <strong>Installeren</strong>, <strong>Bijwerken</strong> of <strong>Opnieuw plaatsen</strong> zet de plugin (opnieuw) op de
            site; <strong>Ophalen</strong> laat Joomla op deze ene site opnieuw naar updates zoeken; <strong>Updatesites herbouwen</strong>
            is hetzelfde als herbouwen in het scherm <em>Updatesites</em> van Joomla: alle updatesites worden opnieuw opgebouwd uit de
            geïnstalleerde extensies (downloadsleutels blijven bewaard). Gebruik dat als Joomla voor een extensie geen updates (meer)
            vindt. Staan er <em>uitgeschakelde updatesites</em>, dan zet de blauwe knop <strong>Updatesites inschakelen</strong>
            onder die melding ze weer aan. <strong>Plugin verwijderen</strong> haalt de plugin van de site en
            <strong>🚫 Uitsluiten</strong> houdt de site buiten het centraal bijwerken (zie hieronder).</li>
        <li id="updates-resultaat"><strong>Resultaat</strong> - de uitkomst van de laatste actie op deze regel.</li>
    </ul>
    <h4 id="updates-admintools-lijst">Admin Tools en het IP-adres van de monitor</h4>
    <p>Bij het plaatsen van de plugin zet de monitor zijn eigen IP-adres in Admin Tools op <em>Nooit deze IP-adressen
    blokkeren</em>, zodat de firewall hem niet buitensluit. De IP-adressen die daar al stonden, blijven staan. Een eerdere versie
    van de monitor kon bij sommige sites die lijst vervangen door het woord <em>Array</em> plus het IP-adres van de monitor. Plaats
    je de plugin daar opnieuw (<strong>Opnieuw plaatsen</strong>), dan haalt de monitor dat woord weg en staat er in de kolom
    <em>Resultaat</em> <em>Lijst in Admin Tools nakijken</em> (de uitleg verschijnt als je er met de muis op staat). Kijk die lijst daarna in Admin Tools na (<em>Web Application Firewall &gt; Configureren</em>) en zet
    eventueel adressen die er eerder stonden er weer in.</p>

    <h4 id="updates-uitsluiten">Een site uitsluiten van updates</h4>
    <p>Soms wil de eigenaar van een site niet dat die centraal wordt bijgewerkt. Met <strong>🚫 Uitsluiten</strong> in de kolom
    <em>Actie</em> blijft zo'n site er dan helemaal buiten: de monitor plaatst er nooit de plugin, haalt er geen updates op en
    installeert er niets, ook niet via <strong>Alle geactiveerde updates uitvoeren</strong> of de cronjob. De site telt ook niet
    mee bij <em>zonder plugin</em>, <em>verouderd</em> en de ⚠️-meldingen, en <strong>Plugin installeren op … site(s) zonder
    plugin</strong> slaat haar over. De monitor blijft de site wel gewoon scannen en bewaken.</p>
    <p>Bij het uitsluiten kun je een reden opgeven; die zie je daarna terug in de tabel, met de datum. Staat de plugin al op de
    site, dan blijft die staan, maar de monitor gebruikt hem niet meer; haal hem zo nodig weg met
    <strong>Plugin verwijderen</strong>. Met <strong>Weer toelaten</strong> doet de site weer mee. Het uitsluiten geldt alleen voor deze
    monitor: volgt een andere monitor dezelfde site, dan heeft die zijn eigen keuze.</p>

    <h4 id="updates-plugin-verwijderen">De plugin verwijderen</h4>
    <p><strong>Plugin verwijderen</strong> haalt de plugin via FTP/SFTP van de site, net zoals hij er ook op is gezet. Meer
    monitors kunnen dezelfde plugin op een site gebruiken, elk met een eigen sleutel. Daarom haalt de monitor eerst alleen zijn
    eigen sleutel weg:</p>
    <ul>
        <li>Gebruikt een andere monitor de plugin op deze site nog, dan blijft de plugin staan en werkt hij voor die andere monitor
        gewoon door. Deze monitor kan er dan niet meer bij.</li>
        <li>Anders wordt de plugin helemaal verwijderd: de registratie in Joomla, zijn updatesite en de bestanden op de server.</li>
    </ul>
    <p>In beide gevallen vergeet deze monitor de koppeling met de site en de opgehaalde updates; de geschiedenis blijft bewaard.
    Snapshots van eerdere updates blijven ook op de site staan (in de cachemap van de beheerkant), zodat terugzetten nog kan; ze
    verdwijnen als je in Joomla de cache leegt. Daarna staat de site weer op <em>Niet geïnstalleerd</em>. Wil je niet dat de
    plugin later opnieuw wordt geplaatst, bijvoorbeeld met <strong>Plugin installeren op … site(s) zonder plugin</strong>, sluit
    de site dan ook uit.</p>

    <div class="tip">
        💡 Herbouwen van updatesites mag in Joomla alleen een Super User. De plugin gebruikt daarvoor alleen tijdens die ene
        handeling de rechten van een bestaande Super User van de site; er wordt niets opgeslagen of ingelogd.
    </div>

    <h3 id="updates-extensie-instellingen">Het tabblad "Extensies"</h3>
    <p>Op het tabblad <strong>🧩 Extensies</strong> staan alle extensies van derden die de scans op de sites
    hebben gevonden, ook als er nu geen update voor is. Zo leg je vooraf vast wat er straks met updates mag gebeuren.
    Onderdelen van een pakket staan er niet los in: het pakket wordt als geheel bijgewerkt. Wil je de
    extensies van één website zien, kies dan <em>Per website</em> en die website bij het filter <strong>Website</strong>. De namen komen uit de extensiecatalogus van de
    monitor; staat een extensie daar niet in, dan wordt de naam uit Joomla gebruikt.</p>
    <h4 id="updates-geen-updatesite">Extensies met en zonder eigen updatesite</h4>
    <p>Joomla zoekt alleen naar updates voor extensies met een eigen <em>updatesite</em> (zie in Joomla
    <em>Systeem &gt; Updatesites</em>). Veel componenten en installatiepakketten zetten tijdens hun installatie zelf een aantal
    plugins en modules neer. Hoort zo'n extensie officieel bij een Joomla-pakket, dan staat ze hier helemaal niet los in. Maar vaak
    legt Joomla die koppeling niet vast, en dan heeft zo'n plugin of module ook geen eigen updatesite. Joomla biedt er dan nooit
    los een update voor aan: ze wordt bijgewerkt als het onderdeel waarmee ze is geïnstalleerd een update krijgt. Een instelling
    voor zo'n extensie heeft dus geen zin.</p>
    <p>Daarom toont dit tabblad standaard alleen de extensies <strong>met een eigen updatesite</strong>; het getal op het tabblad
    telt alleen die. Met het filter <strong>Toon</strong> zie je ook de extensies zonder eigen updatesite (grijs, zonder keuzelijst),
    of alleen die. Een extensie waarvoor op dit moment een update klaarstaat, telt altijd mee. Heeft een extensie op geen enkele
    site meer een updatesite terwijl ze die wel hoort te hebben, gebruik dan <strong>Updatesites herbouwen</strong> op het tabblad
    <em>Updater-plugins</em> en laat de site daarna opnieuw scannen.</p>

    <p id="updates-extensie-filters">Bovenaan het tabblad kies je de onderverdeling: <strong>Per extensie</strong> of
    <strong>Per website</strong>. Daaronder staan de filters, die voor beide gelden:</p>
    <ul>
        <li><strong>Toon</strong> - extensies met een eigen updatesite (standaard), zonder eigen updatesite, of alle (zie hierboven).</li>
        <li><strong>Extensie</strong> - alleen deze ene extensie.</li>
        <li><strong>Website</strong> - alleen de extensies op deze ene website.</li>
        <li><strong>Instelling</strong> - alleen extensies met deze instelling bij <em>Op alle sites</em> (handmatig, een van de
        automatische standen, of overslaan), of alleen extensies waarvoor op een of meer sites iets anders is gekozen.</li>
        <li><strong>Zoeken</strong> - op een deel van de naam van een extensie of een website.</li>
        <li><strong>Filters wissen</strong> zet alles weer op de beginstand. Naast de knop staat hoeveel extensies of websites er
        overblijven.</li>
    </ul>
    <p><strong>Per extensie</strong> toont elke extensie één keer, met deze kolommen:</p>
    <ul>
        <li><strong>Sites</strong> - op hoeveel sites (in deze categorie) de extensie staat.</li>
        <li><strong>Op alle sites</strong> - de instelling voor deze extensie op alle sites (zie
        <a href="#updates-standen-uitleg">De instellingen per extensie en per site</a>).</li>
        <li><strong>Per site</strong> - klap open om voor één site iets anders te kiezen. <em>Volgt extensie</em> betekent dat de
        instelling voor alle sites geldt. Staat een site op <em>Altijd handmatig</em>, dan staat dat erbij.</li>
    </ul>
    <p><strong>Per website</strong> toont elke website als een lichtgroene balk, met daaronder (iets ingesprongen) de extensies
    die erop staan, met de versie van de laatste scan. In de groene balk staat de instelling voor de <em>hele site</em> (dezelfde als op het tabblad <em>Updater-plugins</em>); per extensie zie je de
    instelling <strong>Op alle sites</strong> en kies je bij <strong>Alleen op deze site</strong> iets anders voor die ene site.
    Zoek je op de naam van een website, dan zie je die hele site; zoek je op een extensie, dan zie je de websites waarop die staat.</p>
    <p>Een gekozen instelling wordt direct opgeslagen, en past zich meteen aan op elke andere plek op de pagina waar dezelfde
    instelling staat. Dezelfde instellingen zie je ook terug op het tabblad <em>Beschikbare updates</em> zodra er een update is.</p>

    <h3 id="updates-lijst">Het tabblad "Beschikbare updates"</h3>
    <p>Met <em>per extensie</em> zie je per extensie op welke sites een update klaarstaat; met <em>per website</em> zie je per website welke
    extensies een update hebben.</p>
    <ul>
        <li id="updates-versie"><strong>Versie</strong> - de versie die nu op de site staat, en de versie die Joomla aanbiedt. Via ℹ️
        open je de informatiepagina van de maker, als die is opgegeven. 🔑 betekent dat er op de site een downloadsleutel voor deze
        extensie is ingevuld.</li>
        <li id="updates-bij-alles"><strong>Bij alles uitvoeren</strong> - wat <strong>Alle geactiveerde updates uitvoeren</strong> (en
        de cronjob) met deze update zou doen, met de reden. ✅ = gaat mee; ✋ = blijft staan tot je zelf op <strong>Bijwerken</strong>
        klikt; ⏸️ = wordt overgeslagen. Deze kolom laat dus altijd vooraf zien wat er zou gebeuren.</li>
        <li id="updates-standen"><strong>Instelling voor deze site</strong> en <strong>Op alle sites</strong> - zie
        <a href="#updates-standen-uitleg">De instellingen per extensie en per site</a>.</li>
        <li id="updates-acties"><strong>Actie</strong>: <strong>Bijwerken</strong> installeert deze ene update nu, ongeacht de
        instelling. <strong>Versie overslaan</strong> slaat alleen deze versie over; komt er een nieuwere versie, dan verschijnt die
        weer. <strong>Weer meenemen</strong> maakt dat ongedaan.</li>
        <li><strong>Resultaat</strong> - de uitkomst van de installatie. Een ⚠️-melding eronder betekent dat de voorkant van de site
        na de update een serverfout of PHP-foutmelding gaf: kijk dan direct op de site, en zet zo nodig de snapshot terug.</li>
    </ul>

    <h3 id="updates-standen-uitleg">De instellingen per extensie en per site</h3>
    <p id="updates-standaard">Bovenaan het tabblad <em>Extensies</em> kies je de <strong>Standaard voor alle extensies</strong>. Die geldt
    voor elke extensie waarvoor bij <strong>Op alle sites</strong> <em>Standaard</em> staat, ook voor extensies die er later bij
    komen. Wil je in principe alles automatisch laten bijwerken, zet de standaard dan op <em>Automatisch: alle versies</em> en kies
    alleen bij de uitzonderingen iets anders. Met het filter <strong>Instelling</strong> en de keuze <em>Met een eigen keuze</em>
    zie je precies welke extensies van de standaard afwijken; <em>Volgt de standaard</em> laat de rest zien. De filters
    <em>Handmatig</em>, <em>Automatisch</em> en <em>Overslaan</em> kijken naar wat er nu voor de extensie geldt, dus ook als
    dat via de standaard komt. Zolang je niets kiest, is de standaard <em>Handmatig</em>.</p>
    <p>Per extensie kies je bij <strong>Op alle sites</strong> wat er op alle sites met haar updates mag gebeuren:
    <em>Standaard</em> (de standaard voor alle extensies hierboven), of een van deze keuzes:</p>
    <ul>
        <li><strong>Handmatig</strong> - je werkt zelf bij met <strong>Bijwerken</strong>.</li>
        <li><strong>Automatisch: alleen kleine reparaties (x.y.Z)</strong> - alleen als alleen het laatste cijfer verandert
        (bijvoorbeeld van 2.4.1 naar 2.4.2).</li>
        <li><strong>Automatisch: binnen dezelfde hoofdversie</strong> - alles behalve een nieuw eerste cijfer (bijvoorbeeld wel van
        2.4 naar 2.5, niet van 2 naar 3). Nieuwe hoofdversies bevatten vaker grote veranderingen.</li>
        <li><strong>Automatisch: alle versies</strong>.</li>
        <li><strong>Overslaan</strong> - nooit bijwerken via <strong>Alle geactiveerde updates uitvoeren</strong> of de cronjob.</li>
    </ul>
    <p>Bij <strong>Instelling voor deze site</strong> kies je hetzelfde voor één site, als die moet afwijken. De volgorde waarin
    gekeken wordt: eerst de instelling voor deze extensie op deze site, dan <em>Altijd handmatig</em> voor de hele site, dan de
    instelling voor de extensie op alle sites, en ten slotte de standaard voor alle extensies. Komt een keuze van de standaard,
    dan staat er in de kolom <em>Bij alles uitvoeren</em> "standaard" bij.</p>

    <h3 id="updates-alles">Alle geactiveerde updates uitvoeren</h3>
    <p>Installeert alle updates met een ✅ in de kolom <em>Bij alles uitvoeren</em>, over alle sites heen (dus ook die van de andere
    categorie, als dat is toegestaan). Je krijgt eerst een lijst ter bevestiging. Daarna zie je de voortgang per update; er worden
    een paar sites tegelijk bijgewerkt, per site één update tegelijk. Na afloop krijg je één samenvattende e-mail voor alle sites
    (zie <a href="#updates-instellingen">Instellingen</a>).</p>

    <h3 id="updates-joomla">Joomla zelf bijwerken</h3>
    <p>Op het tabblad <strong>🏛️ Joomla</strong> zie je per site welke versie van Joomla er draait en of er een nieuwere versie
    binnen dezelfde hoofdversie is. Die update staat ook als eerste regel (<em>Joomla</em>) bij <em>Beschikbare updates</em>. De
    plugin werkt Joomla bij met dezelfde onderdelen als de Joomla-update in het beheer van Joomla: Joomla zoekt in zijn eigen
    updatebron, het pakket wordt gedownload en gecontroleerd op de controlesom van Joomla, en daarna worden de bestanden geplaatst
    en wordt de database bijgewerkt. Daarvoor is minstens versie 2.1.0 van de plugin nodig; staat er een oudere, dan staat er
    <em>Werk eerst de plugin bij</em>.</p>
    <div class="tip">
        ⚠️ Vóór een update van Joomla zelf wordt <strong>geen back-up</strong> van de website gemaakt, ook geen snapshot. De enige
        back-up is die van de hostingpartij, als die er is. Daarom vraagt de monitor vóór elke Joomla-update om akkoord: bij de knop
        <strong>Bijwerken</strong>, bij <strong>Alle geactiveerde updates uitvoeren</strong> als Joomla meegaat, en als je Joomla op
        automatisch zet. Gaat er iets mis, zet dan de back-up van de hosting terug, of maak vooraf zelf een back-up (bijvoorbeeld
        met Akeeba Backup).
    </div>
    <p id="updates-joomla-standen">Bovenaan het tabblad kies je wat er met Joomla-updates mag gebeuren op alle sites:
    <strong>Handmatig</strong> (standaard), <strong>Automatisch: alleen kleine reparaties</strong>, <strong>Automatisch: binnen
    dezelfde hoofdversie</strong> of <strong>Overslaan</strong>. Per site kun je iets anders kiezen; kies <em>Handmatig</em> om een
    site uit te sluiten van automatisch bijwerken van Joomla. De standaard voor alle extensies (tabblad <em>Extensies</em>) geldt
    niet voor Joomla. Een automatische stand betekent dat Joomla meegaat met <strong>Alle geactiveerde updates uitvoeren</strong>
    en met de cronjob, zonder dat iemand op een knop drukt.</p>
    <p>Komt het antwoord van de site niet terug terwijl de nieuwe bestanden al geplaatst zijn, dan maakt de monitor de update bij
    de volgende poging eerst af. Lukt het afronden niet, zet dan de back-up van de hosting terug.</p>
    <h4 id="updates-joomla-hoofdversie">Een nieuwe hoofdversie</h4>
    <p>Bijwerken naar een nieuwe hoofdversie (een nieuw eerste cijfer) staat standaard uit; zet het aan bij
    <a href="#updates-instellingen">Instellingen</a>. Het gaat nooit automatisch, alleen met de knop <strong>Naar Joomla …
    (nieuwe hoofdversie)</strong> op het tabblad <em>Joomla</em>. Eerst doet de monitor dezelfde controle vooraf als Joomla zelf:
    is de PHP-versie geschikt, klopt de database, staan de compatibiliteitsplugins goed, en zijn de extensies geschikt voor de
    nieuwe versie. Vindt die controle een probleem, dan wordt er niet bijgewerkt en zie je wat er eerst moet gebeuren. Kon Joomla
    van sommige extensies niet nagaan of ze geschikt zijn, dan zie je die in de vraag om akkoord, zodat je dat eerst kunt
    nakijken.</p>

    <h3 id="updates-snapshot">Snapshots en terugzetten</h3>
    <p>Vóór elke update maakt de plugin een <strong>snapshot</strong> van de extensie: de bestanden, de eigen databasetabellen en de
    registratie in Joomla (bij een pakket van alle onderdelen). Lukt die snapshot niet, bijvoorbeeld door te weinig schijfruimte, dan
    wordt er niet bijgewerkt. Per extensie blijven de laatste 3 snapshots op de site bewaard, waar mogelijk buiten de webmap.</p>
    <p>In de geschiedenis staat bij elke update met een snapshot de knop <strong>Terugzetten</strong>. Die zet bestanden, tabellen en
    registratie terug naar de toestand van vóór de update. Dat gaat via een los script dat de monitor via FTP/SFTP even neerzet, dus
    het werkt ook als Joomla na de update niet meer opstart. Let op: wat sinds de update in de mappen en tabellen van die extensie
    is veranderd, gaat daarbij verloren.</p>
    <div class="tip">
        💡 Een snapshot is geen volledige back-up van de site. Blijf daarnaast regelmatig volledige back-ups maken, bijvoorbeeld met
        Akeeba Backup.
    </div>

    <h3 id="updates-geschiedenis">De geschiedenis</h3>
    <p>De laatste 100 updates en terugzettingen via de monitor, met wie het deed (<em>losse knop</em>, <em>alles uitvoeren</em>,
    <em>cronjob</em> of <em>terugzetten</em>), het resultaat en de melding van Joomla.</p>
    <p>Komt het antwoord van een site niet goed terug (de site antwoordt niet op tijd, of het verzoek komt door een doorverwijzing of
    een herhaling door de server twee keer aan), dan gaat de monitor zelf na hoe het is afgelopen. Hij wacht tot de plugin klaar is
    en kijkt dan of de update nog in de lijst van Joomla staat. Is hij eruit, dan is de update gelukt en staat er
    <em>vastgesteld na controle</em> bij. Staat hij er nog, dan is de update niet geïnstalleerd en kun je het gewoon opnieuw
    proberen. Lukt die controle niet, dan staat er <em>onbekend</em>; haal de updates dan later opnieuw op om te zien hoe het
    staat. Bij een update die zo is vastgesteld, kan de monitor geen snapshot terugzetten.</p>

    <h3 id="updates-instellingen">Instellingen</h3>
    <ul>
        <li><strong>Ook websites van anderen mogen worden bijgewerkt</strong> - staat standaard uit.</li>
        <li><strong>Samenvattende e-mail</strong> - na <strong>Alle geactiveerde updates uitvoeren</strong> en na de cronjob één e-mail
        voor alle sites samen: wat is bijgewerkt, wat mislukte, en welke nieuwe updates op een handmatige beslissing wachten. Elke
        nieuwe versie wordt daarin maar één keer gemeld. Het adres is dat van <a href="<?php echo MONITOR_URL; ?>help.php#email">E-mailmeldingen</a>.</li>
        <li><strong>Snapshot vóór elke update</strong> - aanbevolen; zie <a href="#updates-snapshot">Snapshots en terugzetten</a>.
        Dit geldt voor extensies; vóór een update van Joomla zelf wordt geen snapshot of back-up gemaakt.</li>
        <li><strong>Ook bijwerken naar een nieuwe hoofdversie van Joomla toestaan</strong> - staat standaard uit. Staat het aan, dan
        zoekt de monitor bij het ophalen ook naar een nieuwe hoofdversie, en verschijnt daarvoor een eigen knop op het tabblad
        <em>Joomla</em> (zie <a href="#updates-joomla-hoofdversie">Een nieuwe hoofdversie</a>).</li>
        <li id="updates-admintools"><strong>Admin Tools</strong> - staat op de site Akeeba Admin Tools, dan zet de monitor bij het
        plaatsen van de plugin zijn eigen IP-adres op <em>Nooit deze IP-adressen blokkeren</em> en heft hij een eventuele automatische
        blokkade van dat adres op. Zo blokkeert de firewall de monitor niet tijdens het bijwerken. De <code>.htaccess</code> wordt
        daarbij niet aangeraakt.</li>
        <li id="updates-cron"><strong>Cronjob</strong> - met een cronjob op <code>uitbreidingen/updates/cron_updates.php</code> (met dezelfde geheime
        cron-code als de gewone cronjob, zie <a href="<?php echo MONITOR_URL; ?>help.php#cronjob">Automatiseren met een cronjob</a>) gebeurt automatisch wat je anders
        met <strong>Updates ophalen</strong> en <strong>Alle geactiveerde updates uitvoeren</strong> doet, gevolgd door de e-mail.
        Bij voorkeur via PHP op de commandoregel, omdat het bijwerken van veel sites lang kan duren. Met het tijdvenster werkt de
        cronjob alleen bij tussen de opgegeven uren.</li>
    </ul>

    <h3 id="updates-op-de-site">Op de site zelf</h3>
    <p>In Joomla staat de plugin bij <em>Systeem &gt; Plugins</em> als <strong>Systeem - Compact Updater</strong>. Met de
    schakelaar <em>Updates via de monitor toestaan</em> kan het installeren vanaf de site worden uitgezet; de monitor ziet dan nog wel
    welke updates er zijn. Zet je de hele plugin uit, dan heeft de monitor geen contact meer met de site. Wil een monitor geen toegang
    meer, verwijder dan zijn sleutelbestand uit de map <code>plugins/system/compactupdater/sleutels</code>.</p>
    <p>De plugin heeft een eigen updatesite, die de monitor meteen aanmeldt bij het plaatsen. Daardoor staat hij in het
    extensieoverzicht van de monitor zoals elke andere extensie, en zie je daar op welke sites nog een oude versie staat. Bijwerken
    gaat het makkelijkst met <strong>Bijwerken</strong> of <strong>… verouderde plugin(s) bijwerken</strong> op deze pagina; de plugin
    zelf staat daarom niet in de lijst met beschikbare updates.</p>
    <p>Voor het plaatsen van de plugin en voor <strong>Terugzetten</strong> zet de monitor een eenmalig script in de hoofdmap van de
    site. Staat op de site de <code>.htaccess</code> van Admin Tools, die alleen bekende PHP-bestanden toestaat, dan gebruikt de monitor
    daarvoor even de bestandsnaam van het scanscript (die daar al als uitzondering is toegestaan) en zet hij het scanscript daarna
    meteen terug. Je hoeft in Admin Tools dus niets extra's toe te staan. Een scan die precies op dat moment loopt, kan één keer
    mislukken.</p>
    <p>Weigert de site de verzoeken van de monitor (de melding noemt dan een HTTP-status als 403), dan houdt meestal een firewall op de
    site of bij de hostingpartij ze tegen. Zet in dat geval het IP-adres van de monitor op de witte lijst (zie ook
    <a href="<?php echo MONITOR_URL; ?>help.php#admin-tools">Akeeba Admin Tools</a>).</p>
    </div>
</details>

</div>

<script>
// Hoofdstukken als uitklapbare blokken (accordion). Een link naar een anker
// (bijv. help.php#admin-tools, of een "?"-icoontje elders in de monitor)
// klapt het hoofdstuk waar dat anker in staat automatisch open en scrolt
// ernaartoe - ook als het anker een kopje binnen een hoofdstuk is.
function zetAlleHoofdstukken(open) {
    document.querySelectorAll('details.hoofdstuk').forEach(function (d) { d.open = open; });
}
function openAnker() {
    var id = decodeURIComponent(location.hash.replace(/^#/, ''));
    if (!id) {
        return;
    }
    var doel = document.getElementById(id);
    if (!doel) {
        return;
    }
    var hoofdstuk = doel.closest('details.hoofdstuk');
    if (hoofdstuk) {
        hoofdstuk.open = true;
    }
    doel.scrollIntoView({ block: 'start' });
}
window.addEventListener('hashchange', openAnker);
document.addEventListener('DOMContentLoaded', openAnker);
</script>

<?php include MONITOR_MAP . '/terug_naar_boven.php'; ?>
</body>
</html>
