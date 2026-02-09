<?php

declare(strict_types=1);

class XSenseMQTTdevice extends IPSModule
{
    private const MQTT_SERVER_GUID = '{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}';
    private const MQTT_DATA_GUID = '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}';

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('DeviceId', '');
        $this->RegisterPropertyBoolean('CreateUnknownEntities', true);
        $this->RegisterPropertyBoolean('Debug', true);
        $this->RegisterAttributeString('Entities', '{}');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if (method_exists($this, 'ConnectParent')) {
            $this->ConnectParent(self::MQTT_SERVER_GUID);
        }

        if (!$this->HasActiveParent()) {
            $this->SetStatus(200);
            return;
        }

        $this->SetStatus(102);
        $this->refreshSubscriptions();
        $this->SetSummary($this->ReadPropertyString('DeviceId'));
    }

    public function ReceiveData($JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || ($data['DataID'] ?? '') !== self::MQTT_DATA_GUID) {
            return '';
        }

        $topic = (string)($data['Topic'] ?? '');
        if ($topic === '') {
            return '';
        }

        $payload = $this->decodePayload($data['Payload'] ?? '');
        $this->debug('ReceiveData', sprintf($this->t('Topic=%s Payload=%s'), $topic, $payload));

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
        return json_encode(['type' => 'connect', 'moduleIDs' => [self::MQTT_SERVER_GUID]]);
    }

    public function UpdateDiscovery(string $json): void
    {
        $entry = json_decode($json, true);
        if (!is_array($entry)) {
            $this->debug('UpdateDiscovery', $this->t('Invalid JSON'));
            return;
        }

        $deviceId = (string)($entry['device']['id'] ?? '');
        if ($deviceId === '') {
            $this->debug('UpdateDiscovery', $this->t('DeviceId missing'));
            return;
        }

        $propertyDeviceId = trim($this->ReadPropertyString('DeviceId'));
        if ($propertyDeviceId === '') {
            IPS_SetProperty($this->InstanceID, 'DeviceId', $deviceId);
            IPS_ApplyChanges($this->InstanceID);
        } elseif (strcasecmp($propertyDeviceId, $deviceId) !== 0) {
            $this->debug('UpdateDiscovery', $this->t('DeviceId mismatch'));
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

        $this->ensureDeviceInfoVariables($entry['device'] ?? []);
        $this->ensureEntityVariable($entry);
        $this->refreshSubscriptions($previousTopic);
    }

    private function refreshSubscriptions(string $previousTopic = ''): void
    {
        $entities = $this->readEntities();
        $topics = [];
        foreach ($entities as $entity) {
            $topic = (string)($entity['state_topic'] ?? '');
            if ($topic !== '') {
                $topics[] = $topic;
            }
        }

        $filter = '^$';
        if (!empty($topics)) {
            $escaped = array_map(fn(string $topic): string => preg_quote($topic, '/'), $topics);
            $filter = '.*(' . implode('|', $escaped) . ').*';
        }
        $this->SetReceiveDataFilter($filter);

        $parentId = $this->getParentId();
        if ($parentId <= 0) {
            return;
        }

        if ($previousTopic !== '' && !in_array($previousTopic, $topics, true)) {
            if (function_exists('MQTT_Unsubscribe')) {
                @MQTT_Unsubscribe($parentId, $previousTopic);
            }
        }

        if (function_exists('MQTT_Subscribe')) {
            foreach ($topics as $topic) {
                @MQTT_Subscribe($parentId, $topic, 0);
            }
        }
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
        if (@$this->GetIDForIdent($ident) === 0) {
            $this->RegisterVariableBoolean($ident, $name, '~Switch', $position);
        }
    }

    private function registerString(string $ident, string $name, int $position): void
    {
        if (@$this->GetIDForIdent($ident) === 0) {
            $this->RegisterVariableString($ident, $name, '', $position);
        }
    }

    private function registerInteger(string $ident, string $name, string $profile, int $position): void
    {
        if (@$this->GetIDForIdent($ident) === 0) {
            $this->RegisterVariableInteger($ident, $name, $profile, $position);
        }
    }

    private function setValueIfChanged(string $ident, $value): void
    {
        $varId = @$this->GetIDForIdent($ident);
        if ($varId === 0) {
            return;
        }
        $current = GetValue($varId);
        if ($current !== $value) {
            SetValue($varId, $value);
        }
    }

    private function getParentId(): int
    {
        $inst = @IPS_GetInstance($this->InstanceID);
        return is_array($inst) ? (int)($inst['ConnectionID'] ?? 0) : 0;
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