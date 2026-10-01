# X-Sense MQTT (IP-Symcon)

Module zur Integration von X-Sense Geräten über MQTT in IP-Symcon.

## Bisher mit folgenden Geräten getestet:
- SBS50 Bridge
- XS0D-MR Rauchmelder
- XS0B-MR121 Rauchmelder
- XS01-M Rauchmelder

## Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Enthaltene Module](#3-enthaltene-module)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Installation (Repository)](#5-installation-repository)
6. [Architektur](#6-architektur)
7. [Versionshistorie](#7-versionshistorie)

## 1. Funktionsumfang

- Verarbeitung von Home Assistant MQTT Discovery (`.../config`) und Statusnachrichten (`.../state`)
- Manuelles Anlegen von Device-Instanzen über eine Konfigurator-Liste
- Automatische Variablenerstellung im Device-Modul anhand der Discovery-Metadaten

## 2. Voraussetzungen

- IP-Symcon ab Version 8.1
- Konfigurierter MQTT Server in IP-Symcon
- X-Sense Bridge, die Home Assistant MQTT Discovery publiziert

## 3. Enthaltene Module

- `X-Sense MQTT Bridge` (Splitter)
  - [README](XSenseMQTTBridge/README.md)
- `X-Sense MQTT Konfigurator` (Konfigurator)
  - [README](XSenseMQTTkonfigurator/README.md)
- `X-Sense MQTT Device` (Device)
  - [README](XSenseMQTTdevice/README.md)

## 4. Einrichten der Instanzen in IP-Symcon

1. MQTT-Server-Instanz in IP-Symcon erstellen
2. `X-Sense MQTT Bridge` anlegen und und als Schnittstelle den MQTT-Server verwenden
3. `X-Sense MQTT Konfigurator` anlegen und als Gateway die XSenseMQTTBridge verwenden
4. In der X-Sense App in der Bridge Konfiguration den Punkt "Mit Home Assistant verbinden" entsprechend konfigurieren
5. Im Konfigurator werden gefundene Geräte gelistet; darüber werden `X-Sense MQTT Device`-Instanzen angelegt. Falls keine Geräte angezeigt werden bitte die Home Assistant unterstützung in der X-Sense App kurz ausschalten und wieder einschalten. Danach sollten aktuelle Informationen von den X-Sense Geräten gesendet werden.

## 5. Installation (Repository)

Die Installation kann über den Module Store erfolgen (X-Sense MQTT) oder über **Module Control** durch Hinzufügen der Repository-URL.
https://github.com/da8ter/x-sense-mqtt.git

## 6. Architektur

```
X-Sense Gerät/Bridge
    ↓
MQTT Server
    ↓
X-Sense MQTT Bridge (Splitter)
    ↓
X-Sense MQTT Konfigurator (Konfigurator)
    ↓
X-Sense MQTT Device (Device)
```

## 7. Versionshistorie

- **0.4**: Symcon 9.1: Beschriftungen der Bool-Variablen als Modul-Darstellung über `MaintainVariable` (die frühere Benutzer-Darstellung mit `ColorDisplay` lehnt 9.1 ab, jede MQTT-Nachricht endete im Fehler 201); eine alte Benutzer-Darstellung des Moduls wird einmalig entfernt, eigene bleiben. Status 201 (Bridge inaktiv) setzt sich bei Statuswechsel der Bridge selbst zurück. Discovery in einem Durchgang, Entity-Bestand und Topic-Index je Objekt gepuffert (gültig nur für den gelesenen Attributstand), Debug ohne Formatierung im Normalbetrieb. Code des Gerätemoduls in `libs/XSenseMQTTDeviceDiscovery.php` und `libs/XSenseMQTTDeviceVariables.php`; Rauchtest [tests/smoke_test.php](tests/smoke_test.php) (nutzt den Prüfstand-Kernel aus `modules/LGThinQ`).
- **0.3**: Umstellung auf Module Strict.
- **0.2**: Aufräumen und Code optimiert.
- **0.1**: Initiale Version