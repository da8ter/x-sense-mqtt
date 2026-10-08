# X-Sense MQTT — Projektdoku

Versioniertes Projektwissen: **warum** etwas so gebaut ist und wie man es prüft. Bedienung steht in den READMEs der Module.

Jede Datei endet mit „Stand: geprüft gegen den Code am …“. Ändert ein Commit eine hier beschriebene Entscheidung, wird die Datei im selben Commit nachgezogen.

## Entscheidungen (`entscheidungen/`)

- [darstellung-status-bestand](entscheidungen/darstellung-status-bestand.md) – Modul-Darstellung statt Benutzer-Darstellung, Migration aus 0.3, Status-Latch 201, Entity-Bestand, Discovery-Cache

## Testen

- **Rauchtest:** `php tests/smoke_test.php` — Gerätemodul mit Bridge-Attrappe im Prüfstand-Kernel der LG-ThinQ-Bibliothek. Setzt den Nachbarordner `../LGThinQ` voraus (`tests/bootstrap.php`, Symcon 9.1 im Speicher samt Darstellungsregeln); fehlt er, bricht der Test mit Hinweis ab. Am 08.10.2026: 19 Prüfungen grün.
- Sonst `php -l` auf geänderte Dateien.

## Symcon-Plattform

Gemessenes Symcon-Verhalten für alle Module: https://github.com/da8ter/SymDo-Family-Organizer/tree/SymDo-Beta/.claude/docs/plattform (lokal `../List/.claude/docs/plattform/`).

## Stand

[stand.md](stand.md) – Offenes.

Stand: geprüft gegen den Code am 08.10.2026
