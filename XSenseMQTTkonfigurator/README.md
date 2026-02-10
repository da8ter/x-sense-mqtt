# XSenseMQTTkonfigurator
Dieses Modul liest Home Assistant MQTT Discovery Topics und stellt die gefundenen X-Sense Geräte in einer Liste dar, über die Device-Instanzen manuell erzeugt werden können.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [Visualisierung](#6-visualisierung)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)
8. [MQTT-Discovery Beispiele](#8-mqtt-discovery-beispiele)

### 1. Funktionsumfang

- Abonniert `homeassistant/binary_sensor/+/+/config`
- Baut einen Discovery-Cache pro `unique_id`
- Zeigt Geräte in einer Konfigurator-Liste an und ermöglicht das manuelle Erstellen von Device-Instanzen

### 2. Voraussetzungen

- IP-Symcon ab Version 7.1
- XSenseMQTTBridge Instanz als Parent (diese ist mit dem MQTT Server verbunden)

### 3. Software-Installation

- Über den Module Store das `XSenseMQTTkonfigurator`-Modul installieren.
- Alternativ über das Module Control die Repository-URL hinzufügen.

### 4. Einrichten der Instanzen in IP-Symcon

Unter „Instanz hinzufügen“ kann das Modul per Schnellfilter gefunden werden.

1. `XSenseMQTTkonfigurator`-Instanz anlegen
2. Als Parent die `XSenseMQTTBridge` auswählen
3. Konfigurationsseite öffnen

Wenn Discovery-Configs empfangen werden, erscheinen die Geräte in der Liste. Über die Liste können `XSenseMQTTdevice`-Instanzen manuell erstellt werden.

__Konfigurationsseite__:

Name | Beschreibung
-----|-------------
Keine weiteren Einstellungen.

### 5. Statusvariablen und Profile

Dieses Modul legt keine eigenen Statusvariablen an.

### 6. Visualisierung

Das Modul stellt keine eigene Visualisierung bereit.

### 7. PHP-Befehlsreferenz

`void XSNK_SyncDiscoveryToDevices(int $InstanzID);`

Überträgt den aktuellen Discovery-Cache an bestehende Device-Instanzen.

### 8. MQTT-Discovery Beispiele

**Config Topic**:
`homeassistant/binary_sensor/{deviceId}/{uniqueId}/config`

Beispiel-Payload:
```json
{
  "unique_id": "xsense_123_smokealarm",
  "name": "Smoke Alarm",
  "state_topic": "homeassistant/binary_sensor/xsense_123/xsense_123_smokealarm/state",
  "payload_on": "ON",
  "payload_off": "OFF",
  "value_template": "{{ value_json.status }}",
  "device": {
    "identifiers": ["xsense_123"],
    "name": "XSense Wohnzimmer",
    "manufacturer": "X-Sense",
    "model": "XS01",
    "sw_version": "1.0.0"
  }
}
```