# X-Sense MQTT (IP-Symcon)

Module zur Integration von X-Sense Geräten über MQTT in IP-Symcon.

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

- IP-Symcon ab Version 8.1
- Konfigurierter MQTT Server in IP-Symcon
- X-Sense Bridge, die Home Assistant MQTT Discovery publiziert

## 3. Enthaltene Module

- `X-Sense MQTT Bridge` (Splitter)
  - README: https://github.com/da8ter/x-sense-mqtt/blob/main/XSenseMQTTBridge/README.md
- `X-Sense MQTT Konfigurator` (Konfigurator)
  - README: https://github.com/da8ter/x-sense-mqtt/blob/main/XSenseMQTTkonfigurator/README.md
- `X-Sense MQTT Device` (Device)
  - README: https://github.com/da8ter/x-sense-mqtt/blob/main/XSenseMQTTdevice/README.md

## 4. Einrichten der Instanzen in IP-Symcon

1. MQTT-Server-Instanz in IP-Symcon erstellen
2. In der X-Sense App in der Bridge Konfiguration den Punkt "Mit Home Assistant verbinden" entsprechent konfigurieren. 
3. `X-Sense MQTT Bridge` anlegen und und als Schnittstelle den MQTT-Server verwenden
4. `X-Sense MQTT Konfigurator` anlegen und als Gateway die XSenseMQTTBridge verwenden
5. Im Konfigurator werden gefundene Geräte gelistet; darüber werden `X-Sense MQTT Device`-Instanzen angelegt. Falls keine Geräte angezeigt werden bitte die Home Assistant unterstützung in der X-Sense App kurz ausschalten und wieder einschalten. Danach sollten aktuelle Informationen von den X-Sense Geräten gesendet werden.

## Installation (Repository)

Die Installation kann über den Module Store erfolgen (X-Sense MQTT) oder über **Module Control** durch Hinzufügen der Repository-URL.
https://github.com/da8ter/x-sense-mqtt/tree/main

## 5. Architektur

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

## 6. Versionshistorie

- 0.1: Initiale Version