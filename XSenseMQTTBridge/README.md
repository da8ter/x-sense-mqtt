# XSenseMQTTBridge

Dieses Modul ist ein IP-Symcon **Splitter** zwischen dem MQTT Server und den X-Sense Modulen.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [Visualisierung](#6-visualisierung)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)
8. [Versionshistorie](#8-versionshistorie)

### 1. Funktionsumfang

- Abonniert automatisch MQTT Topics für Home Assistant MQTT Discovery:
  - `{TopicRoot}/+/+/config`
  - `{TopicRoot}/+/+/state`
- Leitet alle empfangenen Nachrichten an die Child-Instanzen weiter (Konfigurator und Devices)
- Hält Discovery-Configs im Cache, damit neu erstellte Device-Instanzen per Replay initialisiert werden können

### 2. Voraussetzungen

- IP-Symcon ab Version 7.1
- MQTT Server Instanz (als Parent)

### 3. Software-Installation

- Über den Module Store das Modul installieren.
- Alternativ über das Module Control die Repository-URL hinzufügen.

### 4. Einrichten der Instanzen in IP-Symcon

1. MQTT-Server-Instanz anlegen und verbinden
2. `XSenseMQTTBridge`-Instanz anlegen
3. Bridge mit dem MQTT-Server verbinden
4. `TopicRoot` setzen (Standard: `homeassistant/binary_sensor`)

__Konfigurationsseite__:

Name | Beschreibung
-----|-------------
Topic root | MQTT Topic-Root für Discovery und State Topics

### 5. Statusvariablen und Profile

Dieses Modul legt keine Statusvariablen an.

### 6. Visualisierung

Dieses Modul stellt keine eigene Visualisierung bereit.

### 7. PHP-Befehlsreferenz

`void XSNB_ReplayDiscovery(int $InstanzID, string $DeviceId);`

Sendet alle im Bridge-Cache gespeicherten Discovery-Configs erneut an die Child-Instanzen. Wenn `DeviceId` leer ist, werden alle Einträge gesendet.

### 8. Versionshistorie

- 1.0: Initiale Version
