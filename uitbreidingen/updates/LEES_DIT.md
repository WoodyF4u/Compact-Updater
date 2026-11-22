# Uitbreiding "Updates"

Extensie-updates van alle sites in één overzicht, en ze vanuit de monitor bijwerken: per stuk, of alles wat daarvoor is aangezet in één keer. Met een snapshot vóór elke update en terugzetten, het herstellen van updatesites en een samenvattende e-mail.

Dit is een **uitbreiding**: hij staat los van de monitor in de map `uitbreidingen/updates` en gebruikt alleen de inlogsessie, database, instellingen en opmaak van de monitor. Staat de map er niet, dan merkt de monitor daar niets van. De uitbreiding wordt apart van de monitor bijgehouden, in een eigen repository.

## Bestanden (alles in `uitbreidingen/updates/`)

- `basis.php`: laadt de monitor (vaste paden naar de hoofdmap) en past de favicon-paden aan
- `updates.php`: de pagina
- `updates_actie.php`: de acties van de pagina (JSON)
- `updates_functies.php`: de gedeelde functies, inclusief de eigen databasetabellen (`updates_koppeling`, `updates_beschikbaar`, `updates_beleid`, `updates_geschiedenis`) en instellingen die met `updates_` beginnen
- `cron_updates.php`: optionele cronjob
- `help.php`: de uitleg; de vraagtekentjes op de pagina verwijzen hierheen
- `uitbreiding_versie.php`: het versienummer van de uitbreiding zelf
- `zelf_bijwerken_functies.php`: de uitbreiding zichzelf laten bijwerken vanaf GitHub (de knop rechtsboven)
- `updates_plugin/compactupdater/...`: de Joomla-plugin **Compact Updater** (`plg_system_compactupdater`) die de uitbreiding op de sites zet

## Raakvlakken met de monitor zelf

- `index.php`: de knop, alleen zichtbaar als de uitbreiding er staat:

  ```php
  <?php if (is_file(__DIR__ . '/uitbreidingen/updates/updates.php')): ?>
      <a class="knop" href="uitbreidingen/updates/updates.php"><span class="icoon-glyph">📦</span> Updates</a>
  <?php endif; ?>
  ```

Verder niets: de monitor zelf heeft geen uitzonderingen voor de uitbreiding. De plugin Compact Updater heeft een gewone updatesite (zie hieronder), dus het extensieoverzicht van de monitor toont hem zoals elke andere extensie: up-to-date, of met een nieuwere versie als er op een site nog een oude staat.

## Nieuwe versie van de uitbreiding uitbrengen

De uitbreiding heeft een eigen versienummer (los van de plugin en van de monitor). De pagina Updates kijkt in

```
https://raw.githubusercontent.com/WoodyF4u/Compact-Updater/main/uitbreiding.json
```

of er een nieuwere versie is, en kan zich dan met één klik bijwerken. Bij een nieuwe versie:

1. Versie ophogen in `uitbreiding_versie.php`.
2. Vanuit de hoofdmap van de repository inpakken: `zip -r uitbreiding-updates.zip uitbreidingen` (de zip mag alleen de map `uitbreidingen/updates` bevatten).
3. Een release `uitbreiding-vX.Y.Z` maken met `uitbreiding-updates.zip` erbij.
4. In `uitbreiding.json` de versie, de datum, de downloadlink, de `sha256` van de zip en de wijzigingen (een paar korte regels) bijwerken. Pas daarna zien de monitors de nieuwe versie.

Zit er ook een nieuwe versie van de plugin in, breng die dan eerst uit (zie hieronder).

## Updatesite van de plugin

Het manifest van de plugin (`updates_plugin/compactupdater/compactupdater.xml`) verwijst naar

```
https://raw.githubusercontent.com/WoodyF4u/Compact-Updater/main/updates.xml
```

De uitbreiding meldt die updatesite aan bij het neerzetten van de plugin, net zoals de installer van Joomla dat doet. Bij een nieuwe versie van de plugin:

1. Versie ophogen in `compactupdater.xml` én in `PLUGIN_VERSIE` in `src/Extension/Compactupdater.php`.
2. De map `compactupdater` inpakken als `plg_system_compactupdater_vX.Y.Z.zip` (met `compactupdater.xml` in de hoofdmap van de zip, zonder sleutelbestanden) en die als release `vX.Y.Z` in de repository zetten.
3. In `updates.xml` het versienummer, de downloadlink en de `sha256` van de zip bijwerken.
4. Op de updatespagina **Verouderde plugins bijwerken**.

De plugin zelf staat bewust niet in de lijst met beschikbare updates en heeft geen eigen instelling per extensie: hij wordt altijd bijgewerkt met de versie die bij de uitbreiding zit. Werkt iemand hem in Joomla zelf bij via de updatesite, dan blijven de sleutels gewoon staan.

## Installeren

1. Zet de map `uitbreidingen/updates` in de hoofdmap van de monitor.
2. De knop in `index.php` (zie hierboven) zit al in de monitor; hij verschijnt vanzelf zodra de map er staat.
3. Open **Updates** en volg op de helppagina het stuk "Aan de slag".

Wie de functie al in de hoofdmap van de monitor had staan (de eerste testversie), draait daarna eenmalig `migreer_updates_naar_uitbreidingen.php` in de hoofdmap. Dat ruimt de oude bestanden op; alle gegevens blijven bewaard.

## Cronjob

Het pad is `uitbreidingen/updates/cron_updates.php`, met dezelfde geheime cron-code als de gewone cronjob:

```
php /pad/naar/monitor/uitbreidingen/updates/cron_updates.php --cron_code=JOUWCODE
```

## Verwijderen

Verwijder de map `uitbreidingen/updates`; de knop verdwijnt dan vanzelf. Wil je ook de gegevens kwijt, verwijder dan de vier tabellen hierboven en de instellingen die met `updates_` beginnen.

## Van de eerste testversie (plugin "Koppeling Websitesmonitor") naar Compact Updater

De plugin heette in de eerste testversie `plg_system_jvnlmonitor`. Op sites waar die nog staat:

1. Verwijder in Joomla bij **Systeem > Beheren > Extensies** de plugin **Systeem - Koppeling Websitesmonitor**.
2. Verwijder via FTP de map `administrator/cache/jvnlmonitor` (werkmap en eventuele snapshots van de oude plugin) en, als die bestaat, de map `jvnlmonitor_snapshots` naast de webmap.
3. Gebruik op de updatespagina **Verouderde plugins bijwerken** (of per site **Bijwerken**): Compact Updater wordt dan neergezet, met zijn updatesite. De koppelingen, instellingen en geschiedenis in de monitor blijven gewoon bestaan.

Laat je de oude plugin staan, dan hindert dat niet; hij wordt alleen niet meer gebruikt.

## Wijzigingen (voor de eigen changelog van de uitbreiding)

```
### Uitbreiding "Updates" (`uitbreidingen/updates/`, `index.php`)
Nieuwe, losse uitbreiding in `uitbreidingen/updates`: alle extensie-updates van alle sites in één overzicht, zoals Joomla ze op elke site zelf heeft gevonden, en ze vanuit de monitor bijwerken. De knop "Updates" in de samenvattingsbalk verschijnt alleen als de uitbreiding aanwezig is.

- Op elke site zet de monitor via FTP/SFTP een kleine plugin neer ("Systeem - Compact Updater") en meldt die eenmalig aan in Joomla. Er is geen Joomla-account voor nodig. Elke monitor heeft per site een eigen geheime sleutel; elk verzoek is ondertekend, 5 minuten geldig en maar één keer bruikbaar.
- De plugin installeert alleen updates die Joomla zelf heeft gevonden, met dezelfde code als de knop "Bijwerken" in Joomla.
- Instellingen per extensie (ook vooraf, voor alle extensies uit de scans) en per site: handmatig, automatisch (alleen kleine reparaties, binnen dezelfde hoofdversie, of alles), of overslaan.
- Snapshot vóór elke update en terugzetten (ook als Joomla niet meer opstart), updatesites inschakelen of herbouwen, en het IP-adres van de monitor bij Admin Tools op "Nooit blokkeren".
- Eén samenvattende e-mail voor alle sites en een optionele cronjob met tijdvenster.
- De plugin heeft een eigen updatesite, zodat de monitor bij elke scan ziet of een site nog een oude versie heeft.

### Joomla zelf bijwerken (plugin 2.1.0)
- Nieuw tabblad "Joomla": per site de huidige versie, een update binnen de huidige hoofdversie en (als dat in de Instellingen aanstaat) een nieuwe hoofdversie. Joomla staat ook als eerste regel in "Beschikbare updates".
- Instelling voor Joomla op alle sites en per site: handmatig, automatisch (alleen kleine reparaties, of binnen dezelfde hoofdversie) of overslaan. De standaard voor alle extensies geldt niet voor Joomla; zonder keuze is het handmatig. Een nieuwe hoofdversie gaat nooit automatisch, alleen met een eigen knop per site, na Joomla's eigen controle vooraf (PHP, database, compat-plugins, geschiktheid van extensies).
- Er wordt vooraf geen back-up gemaakt. Vóór elke Joomla-update (losse knop, "alle geactiveerde updates uitvoeren", of het automatisch zetten van Joomla) moet de gebruiker een waarschuwing daarover bevestigen.
- De plugin gebruikt Joomla's eigen onderdelen (het updatemodel van com_joomlaupdate, zoals ook het opdrachtregelcommando core:update): zoeken in Joomla's eigen updatebron, downloaden met controle van de controlesom, uitpakken en kopiëren, en in een tweede verzoek afronden (database bijwerken, oude bestanden weg). Valt het antwoord weg, dan rondt de monitor de update bij de volgende poging af.
- Hiervoor is plugin 2.1.0 nodig; sites met een oudere plugin tonen "Werk eerst de plugin bij".
```
