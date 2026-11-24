# Compact Updater

Uitbreiding **Updates** voor [Mijn Websites Monitor](https://github.com/JVNL/joomlanl-monitor), met de Joomla-plugin **Compact Updater**.

Met deze uitbreiding zie je in de monitor alle updates van al je Joomla-sites in één overzicht: van extensies én van Joomla zelf. Je werkt ze vanuit de monitor bij: per stuk, of alles wat je daarvoor hebt aangezet in één keer, eventueel automatisch via een cronjob.

## Wat zit hierin

| Onderdeel | Wat het is |
|---|---|
| `uitbreidingen/updates/` | De uitbreiding voor de monitor. Een zelfstandig PHP-programma, net als de monitor zelf; geen Joomla-extensie. |
| `uitbreidingen/updates/updates_plugin/compactupdater/` | De Joomla-plugin **Compact Updater** (`plg_system_compactupdater`). De uitbreiding zet die zelf op elke site; je hoeft hem niet handmatig te installeren. |
| `updates.xml` | De updatesite van de plugin, waarmee Joomla en de monitor zien of er een nieuwere versie van de plugin is. |
| `uitbreiding.json` | De nieuwste versie van de uitbreiding zelf, waarmee de monitor ziet of hij zichzelf kan bijwerken. |

## Wat het kan

- Alle updates van alle sites in één overzicht, zoals Joomla ze op elke site zelf heeft gevonden.
- Per extensie en per site instellen wat er mag gebeuren: handmatig, automatisch (alleen kleine reparaties, binnen dezelfde hoofdversie, of alles), of overslaan.
- Joomla zelf bijwerken, per site of op alle sites in één keer, binnen de huidige hoofdversie of (als je dat aanzet, alleen met een eigen knop) naar een nieuwe hoofdversie, na Joomla's eigen controle vooraf. Per site gaat Joomla altijd vóór de extensies.
- Gedeelde updates: vindt een site een update niet zelf (bijvoorbeeld omdat de server van de maker vanaf die hosting niet bereikbaar is), terwijl een andere site met dezelfde extensie hem wel vond, dan komt het pakket via die andere site. Gebruik dit alleen als je licentie voor de extensie voor al die sites geldt; het is uit te zetten.
- Een snapshot vóór elke extensie-update, en terugzetten als het misgaat.
- Updatesites inschakelen of herbouwen, en een samenvattende e-mail na de cronjob.

## Wat je nodig hebt

- Een werkende installatie van [Mijn Websites Monitor](https://github.com/JVNL/joomlanl-monitor), met FTP- of SFTP-gegevens van de sites. De uitbreiding werkt niet zonder de monitor.
- Joomla 4, 5 of 6 op de sites.

## Installeren

1. Download `uitbreiding-updates.zip` bij de nieuwste [release](https://github.com/WoodyF4u/Compact-Updater/releases).
2. Pak de zip uit in de hoofdmap van de monitor (waar ook `index.php` staat), zodat de map `uitbreidingen/updates` ontstaat.
3. Open de monitor: rechts in de samenvattingsbalk staat nu de knop **📦 Updates**.
4. Op de pagina Updates zet je met één knop de plugin op alle sites. Daarna haal je de updates op.

Verdere uitleg staat in de uitbreiding zelf: elk vraagtekentje op de pagina verwijst naar de helppagina.

## Belangrijk

- **Er wordt geen back-up gemaakt** vóór een update van Joomla zelf. De monitor vraagt daarom vóór elke Joomla-update om akkoord. Maak zelf een back-up (bijvoorbeeld met Akeeba Backup) of zorg dat je hostingpartij er een heeft.
- Er is geen Joomla-account nodig. Elke monitor heeft per site een eigen geheime sleutel; elk verzoek aan de plugin is ondertekend, kort geldig en maar één keer bruikbaar.
- De plugin installeert alleen updates die Joomla zelf heeft gevonden, met dezelfde code als Joomla's eigen knop "Bijwerken".

## Bijwerken

- **De uitbreiding:** naast de titel op de pagina Updates staat de versie. Is er een nieuwere, dan wordt dat een knop **⬆️ Nieuwe versie … beschikbaar**. Na een klik en akkoord werkt de monitor de uitbreiding meteen zelf bij: hij downloadt de nieuwe versie van GitHub, controleert de controlesom, bewaart de huidige versie als `uitbreidingen/updates-vorige-versie.zip` en zet de nieuwe bestanden neer. Instellingen en geschiedenis blijven bewaard.
  - Daarvoor moet de monitor zijn eigen bestanden mogen wijzigen en moet PHP de uitbreiding `zip` hebben; meestal is dat zo. Lukt het niet, dan zegt de melding waarom. Download dan `uitbreiding-updates.zip` bij de nieuwste [release](https://github.com/WoodyF4u/Compact-Updater/releases) en pak hem uit in de hoofdmap van de monitor.
  - Bijwerken met de knop kan vanaf versie 1.0.0 van de uitbreiding. Had je een eerdere download (zonder versienummer rechtsboven), werk dan eenmalig bij met de zip.
- **De plugin op de sites:** gebruik op de pagina Updates, tabblad *Updater-plugins*, de knop **Verouderde plugin(s) bijwerken**.

## Licentie

GNU General Public License versie 2 of later; zie [LICENSE](LICENSE).

(C) 2026 CompactWeb
