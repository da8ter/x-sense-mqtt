# Stand

Offenes. Erledigtes wird gestrichen, nicht abgehakt.

## Offen

- **Store-Version 0.4** ist nicht veröffentlicht; wer aus dem Store installiert, bekommt 0.3 mit der Darstellung, die Symcon 9.1 ablehnt (nicht am Code prüfbar).
- Getestet sind laut README nur SBS50-Bridge und drei Rauchmelder-Modelle; andere X-Sense-Geräte laufen über die generische Discovery, ungeprüft.
- **SymBox** läuft auf der Store-Kopie: dort teilen sich „End of Life“ und „Smoke Fault Status“ weiter eine Variable, und die Sicherheitszentrale fehlt — beides erst mit einem Release (0.5).
- Die Sicherheitszentrale kennt CO-, Hitze- und Wassermelder nur aus `device_class`/Suffix; an echten Geräten ungeprüft (die lokale MQTT-Anbindung meldet laut Community nur Rauch-, CO-, Kombi- und Hitzemelder).

## Zu klären

- `vendor` in den `module.json` ist „da8ter“; die Hausregel sieht für fremde Hardware den Gerätehersteller vor. `aliases` sind nicht leer.
- Der Rauchtest und ein Kommentar im Konfigurator nutzen eine Bridge-Kennung im Format einer echten Seriennummer als Beispiel; durch einen Platzhalter ersetzen, falls es eine echte ist.

Stand: geprüft gegen den Code am 09.10.2026
