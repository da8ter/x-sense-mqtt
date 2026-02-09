# XSenseMQTTdevice
Dieses Modul verarbeitet die State-Topics eines X-Sense Geräts (Home Assistant MQTT Discovery), legt Variablen an und aktualisiert den Status.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [Visualisierung](#6-visualisierung)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)
8. [MQTT-Topics und Payload-Beispiele](#8-mqtt-topics-und-payload-beispiele)

### 1. Funktionsumfang

- Verarbeitet State-Topics pro Entity eines Geräts
- Legt Variablen automatisch anhand der Discovery-Metadaten an
- Unterstützt bekannte Suffixe: `online`, `battery`, `lifeend`, `smokealarm`, `smokefault`
- Optional: generische Bool-Variablen für unbekannte Suffixe
- Battery-Prozent als Integer, wenn `status` numerisch ist
- Setzt `LastSeen` bei jeder gültigen Statusnachricht

### 2. Voraussetzungen

- IP-Symcon ab Version 7.1
- MQTT Server Instanz als Parent
- Discovery-Config über Home Assistant oder XSenseMQTTkonfigurator

### 3. Software-Installation

- Über den Module Store das `XSenseMQTTdevice`-Modul installieren.
- Alternativ über das Module Control die Repository-URL hinzufügen.

### 4. Einrichten der Instanzen in IP-Symcon

Die Instanzen werden üblicherweise automatisch durch den `XSenseMQTTkonfigurator` angelegt. Alternativ kann die Instanz manuell hinzugefügt werden.

__Konfigurationsseite__:

Name | Beschreibung
-----|-------------
Device ID | `device.identifiers[0]` aus der Discovery-Config
Create unknown entities | Unbekannte Suffixe als Bool-Variable anlegen
Debug | Zusätzliche Debug-Ausgaben

### 5. Statusvariablen und Profile

Die Statusvariablen werden automatisch angelegt. Das Löschen einzelner Variablen kann zu Fehlfunktionen führen.

#### Geräteinformationen

Name | Typ | Profil | Beschreibung
-----|-----|--------|-------------
Manufacturer | string | - | Hersteller
Model | string | - | Modell
Firmware | string | - | Firmware-Version
LastSeen | integer | `~UnixTimestamp` | Zeitpunkt der letzten gültigen Statusnachricht

#### Statusvariablen (Mapping)

Name | Ident | Typ | Profil | Beschreibung
-----|-------|-----|--------|-------------
Online | Online | bool | `~Switch` | Verbindungstatus
BatteryLow | BatteryLow | bool | `~Switch` | Batterie schwach
EndOfLife | EndOfLife | bool | `~Switch` | Lebensende erreicht
SmokeDetected | SmokeDetected | bool | `~Switch` | Rauch erkannt
SmokeFault | SmokeFault | bool | `~Switch` | Rauchstörung
Battery | Battery | integer | `~Battery.100` | Batterie in Prozent (nur bei numerischem `status`)

Unbekannte Suffixe (optional): `Entity <suffix>` als Bool-Variable.

### 6. Visualisierung

Das Modul stellt keine eigene Visualisierung bereit.

### 7. PHP-Befehlsreferenz

`void XSND_UpdateDiscovery(string $Json);`  
Interne Schnittstelle für den Konfigurator, um Entity-Metadaten zu aktualisieren.

### 8. MQTT-Topics und Payload-Beispiele

**State Topic** (aus Discovery):
`homeassistant/binary_sensor/{deviceId}/{uniqueId}/state`

Beispiel-Payload:
```json
{ "status": "ON" }
```

Vergleich erfolgt mit `payload_on`/`payload_off` aus der Discovery-Config. Unterstützte `value_template`-Variante: `{{ value_json.status }}`.