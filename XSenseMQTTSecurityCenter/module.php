<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/XSenseMQTTSecurityClassifier.php';

/**
 * Sicherheitszentrale: eine HTML-Kachel mit dem Zustand aller X-Sense-Geräte (Alarm, Störung,
 * Batterie, Lebensende, Verbindung). Rein lesend — über lokales MQTT lassen sich die Melder
 * nicht steuern. Die Bedeutung der Variablen liefert jedes Gerät über XSND_GetEntityMap.
 */
class XSenseMQTTSecurityCenter extends IPSModuleStrict
{
    private const DEVICE_MODULE_GUID = '{C523B0B6-870E-9726-778A-0FF5C6E9656E}';

    /** Einmal-Timer, der mehrere Ereignisse zu einem Push bündelt */
    private const PUSH_TIMER = 'Push';
    /**
     * Timer für das Einlesen der Geräte, kurz nach ApplyChanges: beim Neuladen der Bibliothek
     * entstehen die Geräte-Instanzen nach dieser, ein sofortiger XSND_GetEntityMap-Aufruf
     * schrieb „InstanceInterface is not available" ins Log (gemessen 09.10.2026, Symcon 9.1)
     */
    private const INIT_TIMER = 'Init';
    private const INIT_DELAY_MS = 1500;
    /** Prüfwert der zuletzt gesendeten Nachricht: Unverändertes geht nicht erneut hinaus */
    private const BUFFER_HASH = 'PushHash';
    /** Gerät → Entitäten (Rolle, Variable), beim ApplyChanges aus XSND_GetEntityMap */
    private const BUFFER_MAP = 'EntityMap';

    public function Create(): void
    {
        parent::Create();

        // Ab Symcon 9.1 auch in der geöffneten (maximierten) Kachel: dort zeigt sie das Raster
        $this->SetVisualizationType(defined('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN') ? INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN : 1);

        $this->RegisterPropertyString('Devices', '[]');
        $this->RegisterPropertyInteger('StaleHours', 0);
        // @: beim Neuladen der Bibliothek kann der Timer die Instanz kurz ohne Schnittstelle treffen
        $this->RegisterTimer(self::INIT_TIMER, 0, '@IPS_RequestAction($_IPS[\'TARGET\'], \'' . self::INIT_TIMER . '\', 0);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                if ($message !== IPS_KERNELSTARTED) {
                    $this->UnregisterMessage($senderID, $message);
                }
            }
        }
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        $this->SetBuffer(self::BUFFER_HASH, ''); // Erstaufbau: die nächste Nachricht geht in jedem Fall hinaus
        $this->SetTimerInterval(self::INIT_TIMER, self::INIT_DELAY_MS);
    }

    /** Geräte einlesen und abonnieren — über den Init-Timer, siehe INIT_TIMER. */
    private function initialize(): void
    {
        $this->SetTimerInterval(self::INIT_TIMER, 0);
        $map = [];
        foreach ($this->visibleDevices() as $deviceID) {
            $entry = $this->readEntityMap($deviceID);
            $map[$deviceID] = $entry;
            $this->RegisterMessage($deviceID, OM_CHANGENAME);
            $this->RegisterMessage($deviceID, IM_CHANGESTATUS);
            $bridgeID = $this->connectionOf($deviceID);
            if ($bridgeID > 0) {
                $this->RegisterMessage($bridgeID, IM_CHANGESTATUS);
            }
            if ($entry === null) {
                continue; // Gerät antwortet gerade nicht: es meldet sich über IM_CHANGESTATUS zurück
            }
            // Die VARIABLEN referenzieren, nicht die Instanz: eine Instanz derselben Bibliothek
            // ist beim Neuladen selbst noch nicht wieder da (SymDo, 11.09.2026)
            foreach (array_merge(array_column($entry['entities'], 'varId'), [$entry['lastSeenVarId']]) as $varID) {
                if ($varID > 0 && IPS_VariableExists($varID)) {
                    $this->RegisterReference($varID);
                    $this->RegisterMessage($varID, VM_UPDATE);
                }
            }
        }
        $this->SetBuffer(self::BUFFER_MAP, (string)json_encode($map));
        $this->SetSummary(sprintf($this->Translate('%d devices'), count($map)));
        $this->pushState();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->ApplyChanges();
                return;
            case VM_UPDATE:
                // nur geänderte Werte; fehlt $Data[1], zählt es
                if (array_key_exists(1, $Data) && $Data[1] !== true) {
                    return;
                }
                break;
            case IM_CHANGESTATUS:
                // ein Gerät, das beim ApplyChanges nicht antwortete, ist wieder da: Zuordnung neu holen
                $map = json_decode($this->GetBuffer(self::BUFFER_MAP), true);
                if (is_array($map) && array_key_exists((string)$SenderID, $map) && $map[(string)$SenderID] === null
                    && (int)($Data[0] ?? 0) === 102) {
                    $this->SetTimerInterval(self::INIT_TIMER, self::INIT_DELAY_MS);
                    return;
                }
                break;
        }
        // mehrere Ereignisse eines Durchgangs (eine MQTT-Nachricht setzt Wert und LastSeen) zu einem Push
        @$this->RegisterOnceTimer(self::PUSH_TIMER, '@IPS_RequestAction($_IPS[\'TARGET\'], \'' . self::PUSH_TIMER . '\', 0);');
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === self::PUSH_TIMER) {
            $this->pushState();
            return;
        }
        if ($Ident === self::INIT_TIMER) {
            $this->initialize();
            return;
        }
        parent::RequestAction($Ident, $Value);
    }

    public function GetVisualizationTile(): string
    {
        $path = __DIR__ . '/module.html';
        $html = @file_get_contents($path);
        if (!is_string($html)) {
            $this->LogMessage('GetVisualizationTile: module.html not readable', KL_WARNING);
            return '';
        }
        // JSON_HEX_TAG ist Pflicht: ein „</script>" in einem Gerätenamen beendete sonst den Block
        $state = $this->buildState();
        $payload = json_encode($state,
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $images = json_encode((object)$this->deviceImages(array_column($state['devices'], 'model')),
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        return $html . '<script>setDeviceImages(' . $images . ');handleMessage(' . $payload . ');</script>';
    }

    /**
     * Gerätefotos (mit Freigabe von X-Sense) aus assets/<Modell>.png bzw. .webp/.jpg — nur für die
     * Modelle, die es hier gibt, und nur einmal im Seitenaufbau, nie in den Pushes.
     *
     * @param list<string> $models
     * @return array<string, string> Modell → data-URI
     */
    private function deviceImages(array $models): array
    {
        $types = ['png' => 'image/png', 'webp' => 'image/webp', 'jpg' => 'image/jpeg'];
        $images = [];
        foreach (array_unique($models) as $model) {
            if ($model === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $model)) {
                continue; // das Modell kommt aus der Discovery: nie als Pfad verwenden, was kein Modellname ist
            }
            foreach ($types as $extension => $mime) {
                $file = __DIR__ . '/assets/' . $model . '.' . $extension;
                if (is_file($file)) {
                    $data = @file_get_contents($file);
                    if (is_string($data) && $data !== '') {
                        $images[$model] = 'data:' . $mime . ';base64,' . base64_encode($data);
                    }
                    break;
                }
            }
        }
        return $images;
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string)@file_get_contents(__DIR__ . '/form.json'), true);
        if (!is_array($form)) {
            return '{}';
        }
        $shown = $this->shownSetting();
        $rows = [];
        foreach (IPS_GetInstanceListByModuleID(self::DEVICE_MODULE_GUID) as $deviceID) {
            $rows[] = [
                'InstanceID' => $deviceID,
                'Name'       => $this->displayName($deviceID, ''),
                'Location'   => IPS_GetLocation($deviceID),
                'Show'       => $shown[$deviceID] ?? true
            ];
        }
        // gespeicherte, aber gelöschte Geräte sichtbar halten, bis der Nutzer übernimmt
        foreach ($shown as $deviceID => $show) {
            if (!IPS_InstanceExists($deviceID)) {
                $rows[] = ['InstanceID' => $deviceID, 'Name' => '#' . $deviceID . ' (' . $this->Translate('not found') . ')', 'Location' => '', 'Show' => $show];
            }
        }
        usort($rows, static fn(array $a, array $b): int => strnatcasecmp($a['Name'], $b['Name']));
        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === 'Devices') {
                $element['values'] = $rows;
            }
        }
        unset($element);
        return (string)json_encode($form, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function pushState(): void
    {
        $state = $this->buildState();
        $json = (string)json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $hash = sha1($json);
        if (hash_equals($this->GetBuffer(self::BUFFER_HASH), $hash)) {
            return;
        }
        $this->UpdateVisualizationValue($json);
        $this->SetBuffer(self::BUFFER_HASH, $hash);
        $this->SendDebug('Push', sprintf('level=%d, %d bytes', (int)$state['overall']['level'], strlen($json)), 0);
    }

    /** Der ganze Stand der Kachel: Gesamtstatus, Geräte (dringendste zuerst) und die Texte. */
    private function buildState(): array
    {
        $map = json_decode($this->GetBuffer(self::BUFFER_MAP), true);
        $map = is_array($map) ? $map : [];
        $staleHours = max(0, $this->ReadPropertyInteger('StaleHours'));
        $now = time();
        $devices = [];
        foreach ($map as $deviceID => $entry) {
            $deviceID = (int)$deviceID;
            if (!IPS_InstanceExists($deviceID)) {
                continue;
            }
            $instanceStatus = (int)(IPS_GetInstance($deviceID)['InstanceStatus'] ?? 0);
            $bridgeID = $this->connectionOf($deviceID);
            $bridgeStatus = $bridgeID > 0 && IPS_InstanceExists($bridgeID) ? (int)(IPS_GetInstance($bridgeID)['InstanceStatus'] ?? 0) : 0;
            $entities = [];
            $lastSeen = 0;
            if (is_array($entry)) {
                foreach ($entry['entities'] as $entity) {
                    $varID = (int)$entity['varId'];
                    $entity['value'] = $varID > 0 && IPS_VariableExists($varID) ? GetValue($varID) : null;
                    $entities[] = $entity;
                }
                $lastSeenID = (int)$entry['lastSeenVarId'];
                $lastSeen = $lastSeenID > 0 && IPS_VariableExists($lastSeenID) ? (int)GetValue($lastSeenID) : 0;
            }
            $result = XSenseMQTTSecurityClassifier::evaluateDevice([
                'entities'       => $entities,
                'instanceStatus' => $instanceStatus,
                'bridgeStatus'   => $bridgeStatus,
                'lastSeen'       => $lastSeen,
                'now'            => $now,
                'staleHours'     => $staleHours
            ]);
            $devices[] = [
                'id'         => $deviceID,
                'name'       => $this->displayName($deviceID, is_array($entry) ? (string)$entry['deviceId'] : ''),
                'model'      => is_array($entry) ? (string)$entry['model'] : '',
                // minutengenau: die Kachel zeigt nur Minuten, jede Sekunde wäre sonst ein neuer Push
                'lastSeen'   => $lastSeen > 0 ? intdiv($lastSeen, 60) * 60 : 0
            ] + $result;
        }
        usort($devices, static fn(array $a, array $b): int => ($b['level'] <=> $a['level']) ?: strnatcasecmp($a['name'], $b['name']));
        return [
            'type'    => 'state',
            'overall' => XSenseMQTTSecurityClassifier::overall($devices),
            'devices' => $devices,
            'texts'   => $this->texts()
        ];
    }

    /** Nutzertexte der Kachel, übersetzt über die locale.json */
    private function texts(): array
    {
        $texts = [];
        foreach ([
            'allSafe'    => 'All safe',
            'alarm'      => 'Alarm',
            'warnings'   => 'Attention required',
            'noDevices'  => 'No X-Sense devices',
            'devices'    => 'Detectors',
            'deviceOne'  => 'Detector',
            'alarmOne'   => 'Alarm',
            'noticeOne'  => 'Notice',
            'online'     => 'online',
            'notices'    => 'Notices',
            'alarms'     => 'Alarms',
            'lastSeen'   => 'Last report',
            'never'      => 'never',
            'justNow'    => 'just now',
            'minutesAgo' => '%d min ago',
            'hoursAgo'   => '%d h ago',
            'daysAgo'    => '%d d ago',
            'ok'         => 'OK',
            'i_alarm_smoke' => 'Smoke detected',
            'i_alarm_co'    => 'Carbon monoxide detected',
            'i_alarm_heat'  => 'Heat detected',
            'i_alarm_water' => 'Water detected',
            'i_alarm_other' => 'Alarm',
            'i_connection'  => 'Connection lost',
            'i_offline'     => 'Offline',
            'i_fault'       => 'Sensor fault',
            'i_lifeEnd'     => 'End of life',
            'i_battery'     => 'Battery low',
            'i_problem'     => 'Problem',
            'i_stale'       => 'No report for a long time',
            't_alarm'    => 'Alarm',
            't_fault'    => 'Sensor',
            't_battery'  => 'Battery',
            't_lifeEnd'  => 'Lifetime',
            't_problem'  => 'Problem',
            't_online'   => 'Connection'
        ] as $key => $text) {
            $texts[$key] = $this->Translate($text);
        }
        return $texts;
    }

    /** @return list<int> alle X-Sense-Geräte außer den im Formular abgewählten */
    private function visibleDevices(): array
    {
        $shown = $this->shownSetting();
        return array_values(array_filter(IPS_GetInstanceListByModuleID(self::DEVICE_MODULE_GUID),
            static fn(int $id): bool => $shown[$id] ?? true));
    }

    /** @return array<int, bool> Instanz → anzeigen, aus der Formularliste */
    private function shownSetting(): array
    {
        $rows = json_decode($this->ReadPropertyString('Devices'), true);
        $shown = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $id = (int)($row['InstanceID'] ?? 0);
            if ($id > 0) {
                $shown[$id] = (bool)($row['Show'] ?? true);
            }
        }
        return $shown;
    }

    /**
     * Zuordnung eines Geräts, oder null, wenn es gerade nicht antwortet. Symcon warnt statt zu
     * werfen (Schnittstelle beim Neuladen noch nicht da), deshalb @ und die Typprobe.
     *
     * @return array{deviceId: string, model: string, lastSeenVarId: int, entities: list<array<string, mixed>>}|null
     */
    private function readEntityMap(int $deviceID): ?array
    {
        if (!function_exists('XSND_GetEntityMap')) {
            return null; // neue Präfixfunktion: erst nach einem Kernel-Neustart registriert
        }
        try {
            $raw = @XSND_GetEntityMap($deviceID);
        } catch (\Throwable $e) {
            $this->SendDebug('EntityMap', '#' . $deviceID . ': ' . $e->getMessage(), 0);
            return null;
        }
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || !is_array($data['entities'] ?? null)) {
            return null;
        }
        $entities = [];
        foreach ($data['entities'] as $entity) {
            if (is_array($entity)) {
                $entities[] = [
                    'component'   => (string)($entity['component'] ?? ''),
                    'deviceClass' => (string)($entity['deviceClass'] ?? ''),
                    'suffix'      => (string)($entity['suffix'] ?? ''),
                    'unit'        => (string)($entity['unit'] ?? ''),
                    'name'        => (string)($entity['name'] ?? ''),
                    'varId'       => (int)($entity['varId'] ?? 0)
                ];
            }
        }
        return [
            'deviceId'      => (string)($data['deviceId'] ?? ''),
            'model'         => (string)($data['model'] ?? ''),
            'lastSeenVarId' => (int)($data['lastSeenVarId'] ?? 0),
            'entities'      => $entities
        ];
    }

    /** Instanzname ohne die angehängte Geräte-ID „(SBS50…_…)", die der Konfigurator vergibt */
    private function displayName(int $deviceID, string $deviceId): string
    {
        $name = trim(IPS_GetName($deviceID));
        $stripped = trim((string)preg_replace('/\s*\(\s*SBS\w*_\w+\s*\)\s*$/', '', $name));
        if ($deviceId !== '') {
            $stripped = trim(str_replace('(' . $deviceId . ')', '', $stripped));
        }
        return $stripped !== '' ? $stripped : $name;
    }

    private function connectionOf(int $instanceID): int
    {
        $instance = @IPS_GetInstance($instanceID);
        return is_array($instance) ? (int)($instance['ConnectionID'] ?? 0) : 0;
    }
}
