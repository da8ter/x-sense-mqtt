# Darstellung, Status und Entity-Bestand

Die Module lesen Home-Assistant-MQTT-Discovery (`{TopicRoot}/<komponente>/<gerät>/<entity>/config`) und die zugehörigen `state`-Nachrichten. Hier die Entscheidungen aus der Umstellung auf Symcon 9.1 (Version 0.4, Oktober 2026). Plattformregeln (Darstellungs-Parameter, HEX-Datenfluss unter Module Strict) stehen im Plattformwissen: `../List/.claude/docs/plattform/`.

## Entscheidungen

- **Bool-Darstellung als Modul-Darstellung.** Bool-Entities mit `payload_on`/`payload_off` bekommen über `MaintainVariable` eine Wertanzeige mit beschrifteten Optionen (Beschriftung übersetzt). Bis 0.3 stand die Beschriftung als Benutzer-Darstellung (`IPS_SetVariableCustomPresentation`) mit `ColorDisplay`/`ContentColorDisplay` in den Optionen. Symcon 9.1 lehnt diese Schlüssel ab — der Aufruf scheiterte bei jeder Config-Nachricht im Empfang. Optionen tragen deshalb nur die acht erlaubten Schlüssel; lässt sich ein Payload nicht als JSON kodieren, gibt es lieber keine Darstellung als eine kaputte.
- **Altlast einmalig entfernen.** `migrateLegacyPresentations` entfernt je Instanz einmal eine Benutzer-Darstellung, die von 0.3 stammt (Wertanzeige mit `ColorDisplay`), und merkt das im Attribut `PresentationMigrated`. Eine vom Nutzer in der Konsole gesetzte Darstellung bleibt.
- **Status folgt der Bridge.** 201 ist in diesen Modulen „Elter inaktiv“. Bridge, Konfigurator und Gerät melden sich auf `IM_CHANGESTATUS` ihres Elters an und wenden sich beim Wechsel neu an; ebenso bei `FM_CONNECT`/`FM_DISCONNECT`. Vorher klebte 201 nach einem kurzen Ausfall der Bridge, bis jemand „Übernehmen“ drückte.
- **Entity-Bestand ohne Datenverlust.** Das Attribut `Entities` wird bei jedem Zugriff gelesen (ein Kernelaufruf), aber nur dekodiert, wenn sich der Text geändert hat; der Topic-Index verfällt mit ihm. `writeEntities` bricht bei einem `json_encode`-Fehler ab und loggt `KL_ERROR`, der alte Bestand bleibt.
- **Discovery-Cache in der Bridge.** Die Bridge hält die Config-Nachrichten (`DiscoveryCache`), ein neues Gerät holt sie per `XSNB_GetDiscoveryCache`, der Konfigurator stößt `XSNB_ReplayDiscovery` an. Modul-zu-Modul läuft über öffentliche Präfix-Funktionen.
- **Debug ohne Kosten im Normalbetrieb.** `debug()` prüft die Property `Debug` einmal je Lauf und formatiert nur bei eingeschaltetem Debug.
- **Payload-Dekodierung:** HEX wird nur dekodiert, wenn der Text eine gerade Anzahl Hex-Zeichen hat (`decodePayload`). Native Eltern liefern einem `IPSModuleStrict`-Kind die Nutzlast HEX-kodiert (Plattformwissen `module-strict-und-php.md`); für den MQTT Server selbst ist das dort nicht eigens gemessen.

## Offen

- Ein Status-Text wie `abcd`, der zufällig aus Hex-Zeichen gerader Länge besteht, würde fälschlich dekodiert. Bei X-Sense bisher nicht beobachtet (nicht am Code prüfbar).

Stand: geprüft gegen den Code am 08.10.2026
