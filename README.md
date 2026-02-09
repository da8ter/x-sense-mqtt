# XSenseMQTT

Dieses Repository stellt Module zur Integration von X-Sense Rauchmeldern über MQTT (Home Assistant Discovery) bereit.

Folgende Module beinhaltet das XSenseMQTT Repository:

- __XSenseMQTTkonfigurator__ ([Dokumentation](XSenseMQTTkonfigurator))  
  Liest Discovery-Topics, erstellt Geräteinstanzen automatisch und verteilt Entity-Metadaten.

- __XSenseMQTTdevice__ ([Dokumentation](XSenseMQTTdevice))  
  Verarbeitet State-Topics pro Gerät, legt Variablen an und setzt LastSeen/Batterie.