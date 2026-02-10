<?php

declare(strict_types=1);

class XSenseMQTTDevice extends IPSModuleStrict
{
    private const BRIDGE_MODULE_GUID = '{3B3A2F6D-7E9B-4F2A-9C6A-1F2E3D4C5B6A}';
    private const BRIDGE_DATA_GUID = '{E8C5B3A2-1D4F-5A60-9B7C-2D3E4F5A6B7C}';

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('DeviceId', '');
        $this->RegisterPropertyBoolean('CreateUnknownEntities', true);
        $this->RegisterPropertyBoolean('Debug', false);
        $this->RegisterAttributeString('Entities', '{}');
        $this->RegisterAttributeInteger('AutoConnectTries', 0);
        $this->RegisterTimer('AutoConnect', 0, 'XSND_AutoConnect($_IPS[\'TARGET\']);');
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELMESSAGE && $Data[0] === KR_READY) {
            $this->ApplyChanges();
        }
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetReceiveDataFilter('.*');

        $parentId = $this->getParentId();
        if ($parentId <= 0) {
            $this->scheduleAutoConnect();
            $this->SetStatus(104);
            return;
        }

        $this->SetTimerInterval('AutoConnect', 0);
        $this->WriteAttributeInteger('AutoConnectTries', 0);

        $parentStatus = 0;
        try {
            $parentStatus = (int)(@IPS_GetInstance($parentId)['InstanceStatus'] ?? 0);
        } catch (Throwable $e) {
            $parentStatus = 0;
        }
        if ($parentStatus !== 102) {
            $this->SetStatus(104);
            return;
        }

        $this->SetStatus(102);
        $this->updateReceiveDataFilter();
        $this->SetSummary($this->ReadPropertyString('DeviceId'));
        $this->debug('ApplyChanges', sprintf('Status=102, DeviceId=%s, ParentId=%d', $this->ReadPropertyString('DeviceId'), $parentId));
        $this->requestDiscovery();
    }

    public function AutoConnect(): void
    {
        $parentId = $this->getParentId();
        if ($parentId > 0) {
            $this->SetTimerInterval('AutoConnect', 0);
            $this->WriteAttributeInteger('AutoConnectTries', 0);
            $this->ApplyChanges();
            return;
        }

        $tries = $this->ReadAttributeInteger('AutoConnectTries');
        if ($tries >= 12) {
            $this->SetTimerInterval('AutoConnect', 0);
            return;
        }
        $this->WriteAttributeInteger('AutoConnectTries', $tries + 1);

        if ($this->autoConnectToBridge() > 0) {
            $this->SetTimerInterval('AutoConnect', 0);
            $this->WriteAttributeInteger('AutoConnectTries', 0);
            $this->ApplyChanges();
        }
    }

    private function scheduleAutoConnect(): void
    {
        $tries = $this->ReadAttributeInteger('AutoConnectTries');
        if ($tries >= 12) {
            $this->SetTimerInterval('AutoConnect', 0);
            return;
        }
        $this->SetTimerInterval('AutoConnect', 1000);
    }

    private function autoConnectToBridge(): int
    {
        $active = [];
        foreach (IPS_GetInstanceListByModuleID(self::BRIDGE_MODULE_GUID) as $id) {
            $status = 0;
            try {
                $status = (int)(@IPS_GetInstance($id)['InstanceStatus'] ?? 0);
            } catch (Throwable $e) {
                $status = 0;
            }
            if ($status === 102) {
                $active[] = $id;
            }
        }

        if (count($active) !== 1) {
            return 0;
        }

        $target = (int)$active[0];
        @IPS_ConnectInstance($this->InstanceID, $target);

        $parentId = $this->getParentId();
        if ($parentId === $target) {
            return $parentId;
        }
        return 0;
    }

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || ($data['DataID'] ?? '') !== self::BRIDGE_DATA_GUID) {
            return '';
        }

        $topic = (string)($data['Topic'] ?? '');
        if ($topic === '') {
            return '';
        }

        $payload = $this->decodePayload($data['Payload'] ?? '');
        $this->debug('ReceiveData', sprintf($this->t('Topic=%s Payload=%s'), $topic, $payload));

        if ($this->isConfigTopic($topic)) {
            $this->processConfig($topic, $payload);
            return '';
        }

        $entities = $this->readEntities();
        $entry = $this->findEntityByTopic($topic, $entities);
        if ($entry === null) {
            return '';
        }

        $status = $this->extractStatus($payload, $entry);
        if ($status === null) {
            return '';
        }

        $this->processStatus($entry, $status);
        $this->setValueIfChanged('LastSeen', time());

        return '';
    }

    public function GetCompatibleParents(): string
    {
        return json_encode(['type' => 'connect', 'moduleIDs' => [self::BRIDGE_MODULE_GUID]]);
    }

    public function UpdateDiscovery(string $json): void
    {
        $this->debug('UpdateDiscovery', sprintf('Received: %s', substr($json, 0, 200)));
        
        $entry = json_decode($json, true);
        if (!is_array($entry)) {
            $this->debug('UpdateDiscovery', $this->t('Invalid JSON'));
            return;
        }

        $deviceId = $this->getTopicToken((string)($entry['state_topic'] ?? ''), 3);
        if ($deviceId === '') {
            $deviceId = $this->extractDeviceIdFromEntry($entry);
        }
        if ($deviceId !== '') {
            $entry['device']['id'] = $deviceId;
        }
        $this->debug('UpdateDiscovery', sprintf('Extracted DeviceId=%s from entry', $deviceId));
        
        if ($deviceId === '') {
            $this->debug('UpdateDiscovery', $this->t('DeviceId missing'));
            return;
        }

        $propertyDeviceId = trim($this->ReadPropertyString('DeviceId'));
        $this->debug('UpdateDiscovery', sprintf('PropertyDeviceId=%s, EntryDeviceId=%s', $propertyDeviceId, $deviceId));
        
        if ($propertyDeviceId === '') {
            IPS_SetProperty($this->InstanceID, 'DeviceId', $deviceId);
            IPS_ApplyChanges($this->InstanceID);
        } elseif (strcasecmp($propertyDeviceId, $deviceId) !== 0) {
            $this->debug('UpdateDiscovery', sprintf('%s (property=%s, entry=%s)', $this->t('DeviceId mismatch'), $propertyDeviceId, $deviceId));
            return;
        }

        $uniqueId = (string)($entry['unique_id'] ?? '');
        $stateTopic = (string)($entry['state_topic'] ?? '');
        if ($uniqueId === '' || $stateTopic === '') {
            $this->debug('UpdateDiscovery', $this->t('unique_id/state_topic missing'));
            return;
        }

        $entry['suffix'] = $entry['suffix'] ?? $this->extractSuffix($uniqueId);
        $entry['ident'] = $entry['ident'] ?? $this->getIdentForEntry($entry);

        $entities = $this->readEntities();
        $previousTopic = isset($entities[$uniqueId]) ? (string)($entities[$uniqueId]['state_topic'] ?? '') : '';
        $entities[$uniqueId] = $entry;
        $this->writeEntities($entities);

        $this->debug('UpdateDiscovery', sprintf('Creating variables for suffix=%s, ident=%s', $entry['suffix'] ?? '', $entry['ident'] ?? ''));
        $this->ensureDeviceInfoVariables($entry['device'] ?? []);
        $this->ensureEntityVariable($entry);
        $this->updateReceiveDataFilter();
        $this->debug('UpdateDiscovery', 'Variables created successfully');
    }

    private function updateReceiveDataFilter(): void
    {
        $deviceId = trim($this->ReadPropertyString('DeviceId'));

        $sep = '(?:\\\\/|\\/)';

        if ($deviceId === '') {
            $this->SetReceiveDataFilter('.*"Topic"\s*:\s*".*' . $sep . 'config".*');
            return;
        }

        $escaped = preg_quote($deviceId, '/');
        $filter = '.*"Topic"\s*:\s*".*' . $sep . $escaped . $sep . '[^"]+' . $sep . '(config|state)".*';
        $this->SetReceiveDataFilter($filter);
    }

    private function requestDiscovery(): void
    {
        $parentId = $this->getParentId();
        if ($parentId <= 0) {
            $this->debug('requestDiscovery', 'No parent');
            return;
        }
        $deviceId = trim($this->ReadPropertyString('DeviceId'));
        $this->debug('requestDiscovery', sprintf('Requesting replay from Bridge %d for DeviceId=%s', $parentId, $deviceId));
        if (function_exists('XSNB_ReplayDiscovery')) {
            @XSNB_ReplayDiscovery($parentId, $deviceId);
        } else {
            $this->debug('requestDiscovery', 'XSNB_ReplayDiscovery not available');
        }
    }

    private function getParentId(): int
    {
        $inst = @IPS_GetInstance($this->InstanceID);
        return is_array($inst) ? (int)($inst['ConnectionID'] ?? 0) : 0;
    }

    private function getTopicToken(string $topic, int $fromEnd): string
    {
        $parts = explode('/', trim($topic, '/'));
        $index = count($parts) - $fromEnd;
        if ($index >= 0 && $index < count($parts)) {
            return (string)$parts[$index];
        }
        return '';
    }

    private function extractDeviceIdFromEntry(array $entry): string
    {
        if (isset($entry['device']['id']) && is_string($entry['device']['id'])) {
            return trim($entry['device']['id']);
        }
        if (isset($entry['device'])) {
            return $this->getDeviceIdentifier($entry['device']);
        }
        return '';
    }

    private function getDeviceIdentifier(array $device): string
    {
        if (!isset($device['identifiers'])) {
            return '';
        }
        $identifiers = $device['identifiers'];
        $values = [];
        $add = function ($value) use (&$values): void {
            if (is_string($value)) {
                $value = trim($value);
                if ($value !== '') {
                    $values[] = $value;
                }
            }
        };

        if (is_string($identifiers)) {
            $add($identifiers);
        } elseif (is_array($identifiers)) {
            foreach ($identifiers as $entry) {
                if (is_array($entry)) {
                    foreach ($entry as $nested) {
                        $add($nested);
                    }
                } else {
                    $add($entry);
                }
            }
        }

        if (empty($values)) {
            return '';
        }
        return $values[count($values) - 1];
    }

    private function isConfigTopic(string $topic): bool
    {
        return str_ends_with($topic, '/config');
    }

    private function processConfig(string $topic, string $payload): void
    {
        if ($payload === '') {
            return;
        }

        $cfg = json_decode($payload, true);
        if (!is_array($cfg)) {
            return;
        }

        $uniqueId = trim((string)($cfg['unique_id'] ?? $this->getTopicToken($topic, 2)));
        $device = isset($cfg['device']) && is_array($cfg['device']) ? $cfg['device'] : [];
        $deviceId = $this->getTopicToken($topic, 3);
        if ($deviceId === '') {
            $deviceId = $this->getDeviceIdentifier($device);
        }
        if ($uniqueId === '' || $deviceId === '') {
            return;
        }

        $stateTopic = trim((string)($cfg['state_topic'] ?? ''));
        if ($stateTopic === '') {
            return;
        }

        $entry = [
            'unique_id'      => $uniqueId,
            'name'           => (string)($cfg['name'] ?? ''),
            'state_topic'    => $stateTopic,
            'payload_on'     => (string)($cfg['payload_on'] ?? ''),
            'payload_off'    => (string)($cfg['payload_off'] ?? ''),
            'value_template' => (string)($cfg['value_template'] ?? ''),
            'suffix'         => $this->extractSuffix($uniqueId),
            'device'         => [
                'id'           => $deviceId,
                'name'         => (string)($device['name'] ?? $deviceId),
                'manufacturer' => (string)($device['manufacturer'] ?? ''),
                'model'        => (string)($device['model'] ?? ''),
                'sw_version'   => (string)($device['sw_version'] ?? '')
            ]
        ];

        $this->UpdateDiscovery(json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function processStatus(array $entry, $status): void
    {
        $suffix = (string)($entry['suffix'] ?? '');
        $ident = (string)($entry['ident'] ?? $this->getIdentForEntry($entry));
        if ($ident === '') {
            return;
        }

        if ($suffix === 'battery' && is_numeric($status)) {
            $this->ensureBatteryPercentVariable();
            $this->setValueIfChanged('Battery', (int)round((float)$status));
        }

        $payloadOn = (string)($entry['payload_on'] ?? '');
        $payloadOff = (string)($entry['payload_off'] ?? '');

        if ($payloadOn !== '' && (string)$status === $payloadOn) {
            $this->setValueIfChanged($ident, true);
            return;
        }

        if ($payloadOff !== '' && (string)$status === $payloadOff) {
            $this->setValueIfChanged($ident, false);
            return;
        }

        $this->debug('State', sprintf($this->t('Unknown status for %s: %s'), $ident, (string)$status));
    }

    private function extractStatus(string $payload, array $entry)
    {
        if ($payload === '') {
            return null;
        }

        $data = json_decode($payload, true);
        if (!is_array($data)) {
            $this->debug('State', $this->t('Invalid JSON payload'));
            return null;
        }

        $template = (string)($entry['value_template'] ?? '');
        if ($template !== '' && !$this->isSupportedTemplate($template)) {
            $this->debug('State', $this->t('Unsupported value_template'));
        }

        if (!array_key_exists('status', $data)) {
            $this->debug('State', $this->t('status missing'));
            return null;
        }

        return $data['status'];
    }

    private function isSupportedTemplate(string $template): bool
    {
        $tpl = trim($template);
        return $tpl === '' || str_contains($tpl, 'value_json') && str_contains($tpl, 'status');
    }

    private function ensureDeviceInfoVariables(array $device): void
    {
        $this->registerString('Manufacturer', $this->t('Manufacturer'), 1);
        $this->registerString('Model', $this->t('Model'), 2);
        $this->registerString('Firmware', $this->t('Firmware'), 3);
        $this->registerInteger('LastSeen', $this->t('Last Seen'), '~UnixTimestamp', 4);

        $this->setValueIfChanged('Manufacturer', (string)($device['manufacturer'] ?? ''));
        $this->setValueIfChanged('Model', (string)($device['model'] ?? ''));
        $this->setValueIfChanged('Firmware', (string)($device['sw_version'] ?? ''));
    }

    private function ensureEntityVariable(array $entry): void
    {
        $ident = (string)($entry['ident'] ?? $this->getIdentForEntry($entry));
        if ($ident === '') {
            return;
        }
        $suffix = (string)($entry['suffix'] ?? '');
        $name = $this->getNameForSuffix($suffix);
        $position = $this->getPositionForSuffix($suffix);

        $this->registerBoolean($ident, $name, $position);
    }

    private function ensureBatteryPercentVariable(): void
    {
        $this->registerInteger('Battery', $this->t('Battery'), '~Battery.100', 25);
    }

    private function getNameForSuffix(string $suffix): string
    {
        $map = $this->getSuffixMap();
        if (isset($map[$suffix])) {
            return $this->t($map[$suffix]['name']);
        }
        $suffix = trim($suffix);
        if ($suffix === '') {
            return $this->t('Entity');
        }
        return sprintf($this->t('Entity %s'), $suffix);
    }

    private function getIdentForEntry(array $entry): string
    {
        $suffix = (string)($entry['suffix'] ?? '');
        $map = $this->getSuffixMap();
        if (isset($map[$suffix])) {
            return $map[$suffix]['ident'];
        }
        if (!$this->ReadPropertyBoolean('CreateUnknownEntities')) {
            return '';
        }
        $uniqueId = (string)($entry['unique_id'] ?? $suffix);
        return $this->sanitizeIdent('Entity_' . $uniqueId);
    }

    private function getSuffixMap(): array
    {
        return [
            'online'     => ['ident' => 'Online', 'name' => 'Online', 'position' => 10],
            'battery'    => ['ident' => 'BatteryLow', 'name' => 'Battery Low', 'position' => 11],
            'lifeend'    => ['ident' => 'EndOfLife', 'name' => 'End Of Life', 'position' => 12],
            'smokealarm' => ['ident' => 'SmokeDetected', 'name' => 'Smoke Detected', 'position' => 13],
            'smokefault' => ['ident' => 'SmokeFault', 'name' => 'Smoke Fault', 'position' => 14]
        ];
    }

    private function getPositionForSuffix(string $suffix): int
    {
        $map = $this->getSuffixMap();
        if (isset($map[$suffix]['position'])) {
            return (int)$map[$suffix]['position'];
        }
        return 20;
    }

    private function readEntities(): array
    {
        $raw = $this->ReadAttributeString('Entities');
        $entities = json_decode($raw, true);
        return is_array($entities) ? $entities : [];
    }

    private function writeEntities(array $entities): void
    {
        $this->WriteAttributeString('Entities', json_encode($entities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function findEntityByTopic(string $topic, array $entities): ?array
    {
        foreach ($entities as $entry) {
            if (($entry['state_topic'] ?? '') === $topic) {
                return $entry;
            }
        }
        return null;
    }

    private function extractSuffix(string $uniqueId): string
    {
        $uniqueId = trim($uniqueId);
        if ($uniqueId === '') {
            return '';
        }
        foreach (['_', '-'] as $sep) {
            $pos = strrpos($uniqueId, $sep);
            if ($pos !== false) {
                return strtolower(substr($uniqueId, $pos + 1));
            }
        }
        return strtolower($uniqueId);
    }

    private function sanitizeIdent(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_]/', '_', $value);
        $clean = trim((string)$clean, '_');
        if ($clean === '') {
            $clean = 'Entity';
        }
        return $clean;
    }

    private function decodePayload($payload): string
    {
        if (!is_string($payload)) {
            return '';
        }
        $payload = trim($payload);
        if ($payload === '') {
            return '';
        }
        if (ctype_xdigit($payload) && (strlen($payload) % 2 === 0)) {
            $bin = hex2bin($payload);
            if ($bin !== false) {
                return $bin;
            }
        }
        return $payload;
    }

    private function registerBoolean(string $ident, string $name, int $position): void
    {
        if (@$this->GetIDForIdent($ident) === false) {
            $this->RegisterVariableBoolean($ident, $name, '', $position);
        }
    }

    private function registerString(string $ident, string $name, int $position): void
    {
        if (@$this->GetIDForIdent($ident) === false) {
            $this->RegisterVariableString($ident, $name, '', $position);
        }
    }

    private function registerInteger(string $ident, string $name, string $profile, int $position): void
    {
        if (@$this->GetIDForIdent($ident) === false) {
            $this->RegisterVariableInteger($ident, $name, $profile, $position);
        }
    }

    private function setValueIfChanged(string $ident, $value): void
    {
        $varId = @$this->GetIDForIdent($ident);
        if ($varId === false) {
            return;
        }
        SetValue($varId, $value);
    }

    private function debug(string $message, string $data): void
    {
        try {
            if (!$this->ReadPropertyBoolean('Debug')) {
                return;
            }
        } catch (Throwable $e) {
            return;
        }
        parent::SendDebug($this->t($message), $data, 0);
    }

    private function t(string $text): string
    {
        return method_exists($this, 'Translate') ? $this->Translate($text) : $text;
    }
}