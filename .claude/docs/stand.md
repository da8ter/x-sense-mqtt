# Stand

Offenes. Erledigtes wird gestrichen, nicht abgehakt.

## Offen

- **Version 0.5 (Build 6)** ist gebucht; im Store steht sie erst, wenn der Nutzer sie einreicht (nicht am Code prüfbar). Bis dahin bekommt, wer aus dem Store installiert, den älteren Stand.
- Getestet sind laut README nur SBS50-Bridge und drei Rauchmelder-Modelle; andere X-Sense-Geräte laufen über die generische Discovery, ungeprüft.
- **SymBox** läuft auf der Store-Kopie: dort teilen sich „End of Life“ und „Smoke Fault Status“ weiter eine Variable, und die Sicherheitszentrale fehlt — beides erst mit dem Store-Stand 0.5.
- Die Sicherheitszentrale kennt CO-, Hitze- und Wassermelder nur aus `device_class`/Suffix; an echten Geräten ungeprüft (die lokale MQTT-Anbindung meldet laut Community nur Rauch-, CO-, Kombi- und Hitzemelder).

## Zu klären

- `aliases` in den `module.json` sind nicht leer.
- Die Firmware-Korrektur (leeres Feld leert den Wert nicht mehr) ist am XSOB-MR nur durch den Melder aus dem Forum belegt, der Rauchtest deckt den Fall nicht ab.

Stand: geprüft gegen den Code am 18.11.2026
