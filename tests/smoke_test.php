<?php
declare(strict_types=1);
/**
 * Rauchtest des Gerätemoduls ohne Symcon: läuft im Prüfstand-Kernel der LG-ThinQ-Bibliothek
 * (Nachbarordner modules/LGThinQ, Symcon 9.1 im Speicher inkl. der Darstellungsregeln).
 *   php tests/smoke_test.php
 */
$sdk = __DIR__ . '/../../LGThinQ/tests/bootstrap.php';
if (!is_file($sdk)) {
    fwrite(STDERR, "Prüfstand-Kernel fehlt: $sdk\n");
    exit(1);
}
require $sdk;
$lib = dirname(__DIR__);
Kernel::reset();
Kernel::loadLibrary($lib);
const BRIDGE = '{3B3A2F6D-7E9B-4F2A-9C6A-1F2E3D4C5B6A}';
const DEVICE = '{C523B0B6-870E-9726-778A-0FF5C6E9656E}';
const RX = '{D5C8F9A1-2D3E-4F50-8A6B-1C2D3E4F5A6B}';
Kernel::registerModule(['ModuleID' => BRIDGE, 'ModuleName' => 'Bridge-Attrappe', 'ModuleType' => 2, 'Prefix' => 'XSNB', 'Implemented' => [], 'ParentRequirements' => [], 'ChildRequirements' => [RX]]);
$DID = 'SBS50000000000_00000001';
$GLOBALS['cache'] = [
    "homeassistant/binary_sensor/$DID/smoke/config" => json_encode(['unique_id' => "{$DID}_smoke", 'name' => 'Smoke Status', 'device_class' => 'smoke', 'state_topic' => "xsense/$DID/smoke/state", 'payload_on' => 'ON', 'payload_off' => 'OFF', 'device' => ['manufacturer' => 'X-SENSE', 'model' => 'XS0B-MR', 'sw_version' => 'v1.3.0']]),
    "homeassistant/binary_sensor/$DID/battery/config" => json_encode(['unique_id' => "{$DID}_battery", 'name' => 'Battery Status', 'device_class' => 'battery', 'state_topic' => "xsense/$DID/battery/state", 'payload_on' => 'LOW', 'payload_off' => 'NORMAL', 'device' => ['manufacturer' => '', 'model' => '']]), // ohne Geräteangaben, absichtlich zuletzt
    "homeassistant/binary_sensor/OTHER_DEVICE/smoke/config" => json_encode(['unique_id' => 'OTHER_smoke', 'device_class' => 'smoke', 'state_topic' => 'xsense/OTHER_DEVICE/smoke/state', 'payload_on' => 'ON', 'payload_off' => 'OFF']),
];
function XSNB_GetDiscoveryCache(int $id): string { return json_encode($GLOBALS['cache']); }

section('Einrichtung und Discovery aus dem Bridge-Cache');
$bridge = Kernel::createInstance(BRIDGE);
$dev = Kernel::createInstance(DEVICE);
Kernel::$instances[$dev]['connection'] = $bridge;
IPS_SetProperty($dev, 'DeviceId', $DID);
IPS_ApplyChanges($dev);
check(IPS_GetInstance($dev)['InstanceStatus'] === 102, 'Instanz aktiv (' . IPS_GetInstance($dev)['InstanceStatus'] . ')');
$ids = World::idents($dev);
check(in_array('Smoke', $ids, true) && in_array('Battery', $ids, true) && !in_array('OTHER_smoke', $ids, true), 'Variablen Smoke und Battery, fremdes Gerät ausgelassen: ' . implode(',', $ids));
$v = World::variable($dev, 'Smoke');
$opts = json_decode((string)($v['presentation']['OPTIONS'] ?? ''), true);
check(($v['presentation']['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_VALUE_PRESENTATION && is_array($opts) && count($opts) === 2
    && array_keys($opts[0]) === ['Value', 'Caption', 'IconActive', 'IconValue', 'ColorActive', 'ColorValue', 'ContentColorActive', 'ContentColorValue'],
    'Modul-Darstellung mit genau den acht erlaubten Unterparametern');
check($opts[0]['Caption'] === 'Aus' && $opts[1]['Caption'] === 'Ein', 'Beschriftungen übersetzt (OFF→Aus, ON→Ein): ' . $opts[0]['Caption'] . '/' . $opts[1]['Caption']);
check(World::value($dev, 'Manufacturer') === 'X-SENSE' && World::value($dev, 'Model') === 'XS0B-MR' && World::value($dev, 'Firmware') === 'v1.3.0',
    'Geräteangaben zusammengeführt, leerer Eintrag überschreibt nicht: ' . World::value($dev, 'Manufacturer') . '/' . World::value($dev, 'Model'));
check(World::attr($dev, 'PresentationMigrated') === '1', 'Bereinigung alter Darstellungen als erledigt vermerkt');

section('Zustandsmeldungen');
Kernel::sendToChildren($bridge, json_encode(['DataID' => RX, 'Topic' => "xsense/$DID/smoke/state", 'Payload' => json_encode(['status' => 'ON'])]));
check(World::value($dev, 'Smoke') === true && World::value($dev, 'LastSeen') > 0, 'Smoke ON → true, LastSeen gesetzt');
Kernel::sendToChildren($bridge, json_encode(['DataID' => RX, 'Topic' => "xsense/$DID/battery/state", 'Payload' => bin2hex(json_encode(['status' => 'LOW']))]));
check(World::value($dev, 'Battery') === true, 'Hex-Payload dekodiert, Battery LOW → true');
Kernel::sendToChildren($bridge, json_encode(['DataID' => RX, 'Topic' => "xsense/$DID/smoke/state", 'Payload' => json_encode(['status' => 'OFF'])]));
check(World::value($dev, 'Smoke') === false, 'Smoke OFF → false');

section('Config über MQTT (einzelner Eintrag) und Bestand von außen geändert');
Kernel::sendToChildren($bridge, json_encode(['DataID' => RX, 'Topic' => "homeassistant/sensor/$DID/temp/config", 'Payload' => json_encode(['unique_id' => "{$DID}_temp", 'name' => 'Temperature', 'state_topic' => "xsense/$DID/temp/state", 'unit_of_measurement' => '°C'])]));
check(in_array('Temperature', World::idents($dev), true), 'neue Float-Variable aus einer Config-Nachricht');
Kernel::sendToChildren($bridge, json_encode(['DataID' => RX, 'Topic' => "xsense/$DID/temp/state", 'Payload' => json_encode(['status' => '21.5'])]));
check(World::value($dev, 'Temperature') === 21.5, 'Temperatur 21.5');
// ein anderes Objekt derselben Instanz hat den Bestand erweitert (direkt ins Attribut geschrieben)
$ent = json_decode((string)World::attr($dev, 'Entities'), true);
$ent["{$DID}_co"] = ['unique_id' => "{$DID}_co", 'name' => 'CO Status', 'device_class' => 'carbon_monoxide', 'component' => 'binary_sensor', 'state_topic' => "xsense/$DID/co/state", 'payload_on' => 'ON', 'payload_off' => 'OFF', 'suffix' => 'co', 'ident' => 'Carbon_monoxide', 'device' => ['id' => $DID]];
Kernel::$instances[$dev]['attributes']['Entities'] = json_encode($ent);
IPS_ApplyChanges($dev); // legt die Variable an
Kernel::sendToChildren($bridge, json_encode(['DataID' => RX, 'Topic' => "xsense/$DID/co/state", 'Payload' => json_encode(['status' => 'ON'])]));
check(World::value($dev, 'Carbon_monoxide') === true, 'Topic-Index folgt dem geänderten Attribut (kein veralteter Cache)');

section('Alte Benutzer-Darstellung: nur die eigene wird entfernt');
$smokeId = World::varId($dev, 'Smoke');
$battId = World::varId($dev, 'Battery');
Kernel::$variables[$smokeId]['customPresentation'] = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'OPTIONS' => json_encode([['Value' => false, 'Caption' => 'x', 'ColorDisplay' => -1]])]; // Altlast aus 8.x direkt im Kern, 9.1 nimmt sie nicht mehr an
IPS_SetVariableCustomPresentation($battId, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'OPTIONS' => json_encode([['Value' => false, 'Caption' => 'Benutzer']])]);
Kernel::$instances[$dev]['attributes']['PresentationMigrated'] = '0';
IPS_ApplyChanges($dev);
check(IPS_GetVariableCustomPresentation($smokeId) === [] && (IPS_GetVariableCustomPresentation($battId)['OPTIONS'] ?? '') !== '', 'Modul-Altlast weg, Konsolen-Darstellung bleibt (' . json_encode(IPS_GetVariableCustomPresentation($battId)) . ')');
Kernel::$instances[$dev]['attributes']['PresentationMigrated'] = '1';
Kernel::$variables[$smokeId]['customPresentation'] = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'OPTIONS' => json_encode([['Value' => false, 'Caption' => 'x', 'ColorDisplay' => -1]])]; // Altlast aus 8.x direkt im Kern, 9.1 nimmt sie nicht mehr an
IPS_ApplyChanges($dev);
check(IPS_GetVariableCustomPresentation($smokeId) !== [], 'nach der Migration fasst ApplyChanges Benutzer-Darstellungen nicht mehr an');

section('Zwei Entitäten mit device_class problem (Lebensende, Störung)');
// Aufbau wie die echte SBS50: unique_id <Gerät>_<suffix>, state_topic unter homeassistant/
$DID2 = 'SBS50000000000_00000002';
$real = static function (string $did, string $suffix, string $name, string $class, string $on, string $off): array {
    $base = "homeassistant/binary_sensor/$did/{$did}_$suffix";
    return ["$base/config" => json_encode(['name' => $name, 'unique_id' => "{$did}_$suffix", 'state_topic' => "$base/state",
        'device_class' => $class, 'payload_on' => $on, 'payload_off' => $off, 'value_template' => '{{ value_json.status }}',
        'device' => ['identifiers' => [$did], 'manufacturer' => 'X-SENSE', 'model' => 'XS0B-MR', 'sw_version' => 'v1.3.0']])];
};
$GLOBALS['cache'] = array_merge($GLOBALS['cache'],
    $real($DID2, 'online', 'Online Status', 'connectivity', 'Online', 'Offline'),
    $real($DID2, 'battery', 'Battery Status', 'battery', 'Low', 'Normal'),
    $real($DID2, 'lifeend', 'End of Life', 'problem', 'EOL', 'Normal'),
    $real($DID2, 'smokealarm', 'Smoke Status', 'smoke', 'Detected', 'Cleared'),
    $real($DID2, 'smokefault', 'Smoke Fault Status', 'problem', 'Fault', 'Normal'));
$dev2 = Kernel::createInstance(DEVICE);
Kernel::$instances[$dev2]['connection'] = $bridge;
IPS_SetProperty($dev2, 'DeviceId', $DID2);
IPS_ApplyChanges($dev2);
$ids2 = World::idents($dev2);
check(in_array('Problem', $ids2, true) && in_array('Smokefault', $ids2, true), 'Lebensende und Störung je eigene Variable: ' . implode(',', $ids2));
$state = static fn(string $suffix, string $status) => Kernel::sendToChildren($bridge, json_encode(['DataID' => RX,
    'Topic' => "homeassistant/binary_sensor/$DID2/{$DID2}_$suffix/state", 'Payload' => json_encode(['status' => $status])]));
$state('lifeend', 'Normal');
$state('smokefault', 'Fault');
check(World::value($dev2, 'Smokefault') === true && World::value($dev2, 'Problem') === false, 'Störung setzt nicht mehr das Lebensende');
$problemId = World::varId($dev2, 'Problem');
// Bestand bis 0.4: beide Einträge mit Ident Problem
$ent2 = json_decode((string)World::attr($dev2, 'Entities'), true);
$ent2["{$DID2}_smokefault"]['ident'] = 'Problem';
Kernel::$instances[$dev2]['attributes']['Entities'] = json_encode($ent2);
IPS_ApplyChanges($dev2);
$ent2 = json_decode((string)World::attr($dev2, 'Entities'), true);
check($ent2["{$DID2}_lifeend"]['ident'] === 'Problem' && $ent2["{$DID2}_smokefault"]['ident'] === 'Smokefault' && World::varId($dev2, 'Problem') === $problemId,
    'Bereinigung: Lebensende behält Problem (gleiche Variable), Störung bekommt Smokefault');
// derselbe Bestand, aber die Config kommt vor dem nächsten ApplyChanges
$ent2["{$DID2}_smokefault"]['ident'] = 'Problem';
Kernel::$instances[$dev2]['attributes']['Entities'] = json_encode($ent2);
Kernel::sendToChildren($bridge, json_encode(['DataID' => RX, 'Topic' => "homeassistant/binary_sensor/$DID2/{$DID2}_smokefault/config",
    'Payload' => $GLOBALS['cache']["homeassistant/binary_sensor/$DID2/{$DID2}_smokefault/config"]]));
check(json_decode((string)World::attr($dev2, 'Entities'), true)["{$DID2}_smokefault"]['ident'] === 'Smokefault', 'Config-Nachricht übernimmt einen fremd belegten Ident nicht');

section('GetEntityMap für die Sicherheitszentrale');
$map = json_decode((string)Kernel::callPublic($dev2, 'GetEntityMap', []), true);
$bySuffix = array_column(is_array($map) ? $map['entities'] : [], null, 'suffix');
check(is_array($map) && $map['deviceId'] === $DID2 && $map['model'] === 'XS0B-MR' && $map['lastSeenVarId'] === World::varId($dev2, 'LastSeen'), 'Gerätedaten und LastSeen-Variable');
check(count($bySuffix) === 5 && $bySuffix['smokefault']['varId'] === World::varId($dev2, 'Smokefault') && $bySuffix['lifeend']['varId'] === $problemId
    && $bySuffix['smokealarm']['deviceClass'] === 'smoke' && $bySuffix['online']['component'] === 'binary_sensor', 'fünf Entitäten mit Variable, Klasse und Komponente');

section('Debug robust');
IPS_SetProperty($dev, 'Debug', true);
IPS_ApplyChanges($dev);
$obj = Kernel::$instances[$dev]['object'];
$m = new ReflectionMethod($obj, 'debug');
$m->invoke($obj, 'Test', 'drei %s %s %s', 'nur-eins');
check(str_contains(World::debugText($dev), 'drei %s %s %s'), 'Platzhalterzahl passt nicht: Rohformat statt Abbruch');
$m->invoke($obj, 'Test', 'ok %d', 7);
check(str_contains(World::debugText($dev), 'ok 7'), 'normale Formatierung');

check(Kernel::$warnings === [], 'keine PHP-Warnungen' . (Kernel::$warnings === [] ? '' : ': ' . implode(' | ', Kernel::$warnings)));
check(World::logLines('/ERROR/') === [], 'keine Fehler im Log' . (World::logLines('/ERROR/') === [] ? '' : ': ' . implode(' | ', World::logLines('/ERROR/'))));
done();
