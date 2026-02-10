# X-Sense MQTT (IP-Symcon)

Dieses Repository enthält Module zur Integration von X-Sense Geräten über MQTT (Home Assistant MQTT Discovery) in IP-Symcon.

## Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Enthaltene Module](#3-enthaltene-module)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Architektur](#5-architektur)
6. [Versionshistorie](#6-versionshistorie)

## 1. Funktionsumfang

- Verarbeitung von Home Assistant MQTT Discovery (`.../config`) und Statusnachrichten (`.../state`)
- Manuelles Anlegen von Device-Instanzen über eine Konfigurator-Liste
- Automatische Variablenerstellung im Device-Modul anhand der Discovery-Metadaten

## 2. Voraussetzungen

- IP-Symcon ab Version 7.1
- Konfigurierter MQTT Server in IP-Symcon
- X-Sense Geräte/Bridge, die Home Assistant MQTT Discovery publiziert

## 3. Enthaltene Module

- `XSenseMQTTBridge` (Splitter)
  - README: `XSenseMQTTBridge/README.md`
- `XSenseMQTTkonfigurator` (Konfigurator)
  - README: `XSenseMQTTkonfigurator/README.md`
- `XSenseMQTTdevice` (Device)
  - README: `XSenseMQTTdevice/README.md`

## 4. Einrichten der Instanzen in IP-Symcon

1. MQTT-Server-Instanz in IP-Symcon erstellen und mit dem Broker verbinden
2. `XSenseMQTTBridge` anlegen und mit dem MQTT-Server verbinden
3. `XSenseMQTTkonfigurator` anlegen und mit der Bridge verbinden
4. Im Konfigurator werden gefundene Geräte gelistet; darüber werden `XSenseMQTTdevice`-Instanzen angelegt

## Installation (Repository)

Die Installation kann über den Module Store erfolgen oder über **Module Control** durch Hinzufügen der Repository-URL.

## 5. Architektur

```
MQTT Server
    ↓
XSenseMQTTBridge (Splitter)
    ↓
XSenseMQTTkonfigurator (Konfigurator)
    ↓
XSenseMQTTdevice (Device)
```

## 6. Versionshistorie

- 1.0: Initiale Version