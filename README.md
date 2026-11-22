# Compact Updater

Uitbreiding **Updates** voor [Mijn Websites Monitor](https://github.com/JVNL/joomlanl-monitor), met de Joomla-plugin **Compact Updater**.

Met deze uitbreiding zie je in de monitor alle updates van al je Joomla-sites in één overzicht: van extensies én van Joomla zelf. Je werkt ze vanuit de monitor bij: per stuk, of alles wat je daarvoor hebt aangezet in één keer, eventueel automatisch via een cronjob.

## Wat zit hierin

| Onderdeel | Wat het is |
|---|---|
| `uitbreidingen/updates/` | De uitbreiding voor de monitor. Een zelfstandig PHP-programma, net als de monitor zelf; geen Joomla-extensie. |
| `uitbreidingen/updates/updates_plugin/compactupdater/` | De Joomla-plugin **Compact Updater** (`plg_system_compactupdater`). De uitbreiding zet die zelf op elke site; je hoeft hem niet handmatig te installeren. |
| `updates.xml` | De updatesite van de plugin, waarmee Joomla en de monitor zien of er een nieuwere versie van de plugin is. |

## Wat het kan

- Alle updates van alle sites in één overzicht, zoals Joomla ze op elke site zelf heeft gevonden.
- Per extensie en per site instellen wat er mag gebeuren: handmatig, automatisch (alleen kleine reparaties, binnen dezelfde hoofdversie, of alles), of overslaan.
- Joomla zelf bijwerken, binnen de huidige hoofdversie of (als je dat aanzet, alleen met een eigen knop) naar een nieuwe hoofdversie, na Joomla's eigen controle vooraf.
- Een snapshot vóór elke extensie-update, en terugzetten als het misgaat.
- Updatesites inschakelen of herbouwen, en een samenvattende e-mail na het bijwerken.

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

- **De uitbreiding:** download de nieuwe `uitbreiding-updates.zip` en pak hem uit over de bestaande map. Instellingen en geschiedenis blijven bewaard.
- **De plugin op de sites:** gebruik op de pagina Updates, tabblad *Updater-plugins*, de knop **Verouderde plugin(s) bijwerken**.

## Licentie

GNU General Public License versie 2 of later; zie [LICENSE](LICENSE).

(C) 2026 CompactWeb
