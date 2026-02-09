<?php

declare(strict_types=1);

class XSenseMQTTkonfigurator extends IPSModule
{
    private const MQTT_SERVER_GUID = '{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}';
    private const MQTT_DATA_GUID = '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}';
    private const DEVICE_MODULE_GUID = '{C523B0B6-870E-9726-778A-0FF5C6E9656E}';

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('TopicRoot', 'homeassistant/binary_sensor');
        $this->RegisterPropertyBoolean('AutoCreateInstances', true);
        $this->RegisterPropertyBoolean('Debug', true);
        $this->RegisterAttributeString('DiscoveryCache', '{}');
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
        $root = $this->normalizeTopicRoot($this->ReadPropertyString('TopicRoot'));
        $filter = '.*' . preg_quote($root, '/') . '\/[^\/]+\/[^\/]+\/config.*';
        $this->SetReceiveDataFilter($filter);
        $this->SetSummary($root);
        $this->subscribeTopic($root . '/+/+/config');
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

        if (!$this->isConfigTopic($topic)) {
            return '';
        }

        if ($payload === '') {
            $this->removeEntityByTopic($topic);
            return '';
        }

        $cfg = json_decode($payload, true);
        if (!is_array($cfg)) {
            $this->debug('Config', $this->t('Invalid JSON'));
            return '';
        }

        $uniqueId = trim((string)($cfg['unique_id'] ?? $this->getTopicToken($topic, 2)));
        $device = isset($cfg['device']) && is_array($cfg['device']) ? $cfg['device'] : [];
        $deviceId = trim((string)($device['identifiers'][0] ?? $this->getTopicToken($topic, 3)));
        if ($uniqueId === '' || $deviceId === '') {
            $this->debug('Config', $this->t('unique_id/deviceId missing'));
            return '';
        }

        $stateTopic = trim((string)($cfg['state_topic'] ?? ''));
        if ($stateTopic === '') {
            $this->debug('Config', $this->t('state_topic missing'));
            return '';
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

        $this->updateCache($uniqueId, $entry);

        if ($this->ReadPropertyBoolean('AutoCreateInstances')) {
            $instanceId = $this->ensureDeviceInstance($deviceId, $entry['device']['name']);
            if ($instanceId > 0) {
                @XSND_UpdateDiscovery($instanceId, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }

        return '';
    }

    public function GetCompatibleParents(): string
    {
        return json_encode(['type' => 'connect', 'moduleIDs' => [self::MQTT_SERVER_GUID]]);
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    private function normalizeTopicRoot(string $root): string
    {
        return trim($root, '/');
    }

    private function isConfigTopic(string $topic): bool
    {
        $root = $this->normalizeTopicRoot($this->ReadPropertyString('TopicRoot'));
        return str_starts_with($topic, $root . '/') && str_ends_with($topic, '/config');
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

    private function getTopicToken(string $topic, int $fromEnd): string
    {
        $parts = explode('/', trim($topic, '/'));
        $index = count($parts) - $fromEnd;
        if ($index >= 0 && $index < count($parts)) {
            return (string)$parts[$index];
        }
        return '';
    }

    private function updateCache(string $uniqueId, array $entry): void
    {
        $cache = $this->readCache();
        $cache[$uniqueId] = $entry;
        $this->WriteAttributeString('DiscoveryCache', json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function removeEntityByTopic(string $topic): void
    {
        $uniqueId = $this->getTopicToken($topic, 2);
        if ($uniqueId === '') {
            return;
        }
        $cache = $this->readCache();
        if (isset($cache[$uniqueId])) {
            unset($cache[$uniqueId]);
            $this->WriteAttributeString('DiscoveryCache', json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    private function readCache(): array
    {
        $raw = $this->ReadAttributeString('DiscoveryCache');
        $cache = json_decode($raw, true);
        return is_array($cache) ? $cache : [];
    }

    private function ensureDeviceInstance(string $deviceId, string $name): int
    {
        $existing = $this->findDeviceInstance($deviceId);
        if ($existing > 0) {
            if ($name !== '') {
                @IPS_SetName($existing, $name);
            }
            return $existing;
        }

        $id = IPS_CreateInstance(self::DEVICE_MODULE_GUID);
        if ($name !== '') {
            IPS_SetName($id, $name);
        }
        IPS_SetProperty($id, 'DeviceId', $deviceId);
        IPS_ApplyChanges($id);
        return $id;
    }

    private function findDeviceInstance(string $deviceId): int
    {
        $instances = IPS_GetInstanceListByModuleID(self::DEVICE_MODULE_GUID);
        foreach ($instances as $instanceId) {
            $value = (string)@IPS_GetProperty($instanceId, 'DeviceId');
            if ($value !== '' && strcasecmp($value, $deviceId) === 0) {
                return $instanceId;
            }
        }
        return 0;
    }

    private function subscribeTopic(string $topic): void
    {
        $parentId = $this->getParentId();
        if ($parentId <= 0 || $topic === '') {
            return;
        }
        if (function_exists('MQTT_Subscribe')) {
            @MQTT_Subscribe($parentId, $topic, 0);
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