# X Sense MQTT Security Center
Sicherheitszentrale als Kachel: zeigt den Zustand aller X-Sense-Geräte (Rauchalarm, Sensorstörung, Batterie, Lebensende, Verbindung) in einem Dashboard der Tile-Visualisierung.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in Symcon](#4-einrichten-der-instanzen-in-symcon)
5. [Statusvariablen und Darstellungen](#5-statusvariablen-und-darstellungen)
6. [Visualisierung](#6-visualisierung)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)

### 1. Funktionsumfang

- Findet alle `X Sense MQTT Device`-Instanzen selbst; einzelne lassen sich ausblenden
- Gesamtstatus: „Alles sicher", „Hinweise beachten" oder die Art des Alarms (z. B. „Rauch erkannt"), dazu Zähler für Melder, online, Alarme und Hinweise
- Je Gerät: Alarm, Sensorstörung, Batterie, Lebensende und Verbindung als Symbol; Auffälliges farbig, das Dringendste zuerst
- Optional ein Hinweis, wenn ein Gerät zu lange nichts gemeldet hat
- Rein lesend: über die lokale MQTT-Anbindung lassen sich die Melder nicht steuern (kein Test, kein Stummschalten)

### 2. Voraussetzungen

- Symcon ab Version 8.1; die vergrößerte Kachel zeigt das Dashboard ab Symcon 9.1
- Eingerichtete `X Sense MQTT Device`-Instanzen

### 3. Software-Installation

- Über den Module Store das Modul `X-Sense MQTT` installieren.
- Alternativ über das Module Control die Repository-URL hinzufügen.
https://github.com/da8ter/x-sense-mqtt.git

### 4. Einrichten der Instanzen in Symcon

Unter „Instanz hinzufügen" das Modul `X-Sense Sicherheitszentrale` anlegen. Die Instanz braucht keine Verbindung; sie liest die Geräte-Instanzen direkt.

__Konfigurationsseite__:

Name | Beschreibung
-----|-------------
Geräte | Alle X-Sense-Geräte; „Anzeigen" abwählen blendet ein Gerät aus. Neue Geräte erscheinen nach „Übernehmen"
Hinweis nach Stunden ohne Meldung | 0 = aus; sonst gilt ein Gerät als Hinweis, wenn es so lange keine Statusnachricht geschickt hat

### 5. Statusvariablen und Darstellungen

Das Modul legt keine Variablen an.

### 6. Visualisierung

Die Kachel richtet sich nach ihrer Größe:

- **sehr klein:** nur der Gesamtstatus
- **klein:** Gesamtstatus und Geräteliste mit Statussymbolen
- **groß / vergrößert:** Gesamtstatus und ein Raster mit einer Karte je Gerät (Modell, Hinweise, letzte Meldung)

Die Karten zeigen eine Zeichnung des Geräts (Rauch-, Kombi-, CO-, Hitze- und Wassermelder, Basisstation, Thermo-Hygrometer, Bewegungsmelder, Tür-/Fensterkontakt, Tastenfeld); unbekannte Modelle bekommen die Zeichnung ihrer Alarmart. Ein eigenes Foto unter `assets/<Modell>.png` (oder `.webp`/`.jpg`, z. B. `assets/XS0B-MR.png`) ersetzt die Zeichnung. Die Fotos werden nur für vorhandene Modelle und nur einmal beim Laden der Kachel übertragen.

Ein Klick auf ein Gerät öffnet dessen Instanz. Bei einem Alarm pulsiert der Gesamtstatus zweimal und bleibt dann rot.

### 7. PHP-Befehlsreferenz

Das Modul stellt keine eigenen Befehle bereit. Die Zuordnung der Variablen liest es über `XSND_GetEntityMap` der Geräte.
