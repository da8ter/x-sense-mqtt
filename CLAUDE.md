# X-Sense MQTT (Bibliothek „X Sense MQTT“)

Symcon-Bibliothek für X-Sense-Sicherheitsgeräte (Rauchmelder u. a.) über MQTT: Die X-Sense-Bridge veröffentlicht Home-Assistant-MQTT-Discovery, Symcons MQTT Server empfängt, diese Module legen daraus Variablen an. Öffentliches Repo `da8ter/x-sense-mqtt`, Arbeitsbranch `main`, veröffentlicht als `symcon-beta`.

Projektwissen: **`docs/README.md`**, Offenes in `docs/stand.md`. Betriebsdaten dieses Rechners: `CLAUDE.local.md` (nicht eingecheckt).

## Aufbau

- **`XSenseMQTTBridge/`** (Splitter, `XSNB`): Kind des MQTT Servers, abonniert `{TopicRoot}/+/+/+/config` und `…/state`, hält den Discovery-Cache, verteilt an die Kinder.
- **`XSenseMQTTkonfigurator/`** (Konfigurator, `XSNK`): listet Geräte aus der Discovery, legt Geräte-Instanzen an.
- **`XSenseMQTTdevice/`** (Gerät, `XSND`): `module.php` plus die Traits `libs/XSenseMQTTDeviceDiscovery.php` und `libs/XSenseMQTTDeviceVariables.php`.
- **`libs/XSenseMQTTHelper.php`**: gemeinsamer Trait (Payload-Dekodierung, Topic-Teile, Debug).
- Alle Module `IPSModuleStrict`, Darstellungen als Modul-Darstellung über `MaintainVariable`.

## Prüfen

```bash
php tests/smoke_test.php   # braucht ../LGThinQ (Prüfstand-Kernel)
php -l <Datei>
```

## Regeln

- **Commits:** ein Thema je Commit, deutsche Botschaft, **ohne** Co-Authored-By-Zeile; Prüfungen vorher. Ändert ein Commit eine Entscheidung aus `docs/`, die Datei im selben Commit nachziehen.
- **Nie** `git checkout`/`git restore` auf Dateien: Arbeitskopien können nicht committete Arbeit enthalten.
- **Push und Release nur auf Zuruf.** Release: `version`, `build` und `date` (Unix-Zeitstempel) in `library.json` hochsetzen.
- **Öffentliches Repo:** keine IP-Adressen, Ports, Instanz-IDs, Seriennummern/Geräte-IDs, Token, Konten, Pfade unter `/Users/` — auch nicht in Tests und Kommentaren; Beispiel-Topics mit Platzhaltern.
- **Bibliotheksname** ohne Bindestrich (Store-Schema), Nutzertexte über `locale.json`.
- **Symcon-Plattformwissen** (gemessen, für alle Module): https://github.com/da8ter/SymDo-Family-Organizer/tree/SymDo-Beta/docs/plattform, lokal `../List/docs/plattform/`. Symcon-Fragen am offiziellen Handbuch prüfen.
