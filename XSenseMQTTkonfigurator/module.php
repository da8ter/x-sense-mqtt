<?php

declare(strict_types=1);

class XSenseMQTTKonfigurator extends IPSModuleStrict
{
    private const BRIDGE_MODULE_GUID = '{3B3A2F6D-7E9B-4F2A-9C6A-1F2E3D4C5B6A}';
    private const BRIDGE_DATA_GUID = '{E8C5B3A2-1D4F-5A60-9B7C-2D3E4F5A6B7C}';
    private const DEVICE_MODULE_GUID = '{C523B0B6-870E-9726-778A-0FF5C6E9656E}';

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyBoolean('Debug', false);
        $this->RegisterAttributeString('DiscoveryCache', '{}');
        $this->RegisterAttributeInteger('RetryTries', 0);
        $this->RegisterTimer('RetryConnect', 0, 'XSNK_RetryConnect($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetReceiveDataFilter('.*');

        $parentId = $this->getParentId();
        if ($parentId <= 0) {
            $this->scheduleRetry();
            $this->SetStatus(104);
            return;
        }

        $parentStatus = 0;
        try {
            $parentStatus = (int)(@IPS_GetInstance($parentId)['InstanceStatus'] ?? 0);
        } catch (Throwable $e) {
            $parentStatus = 0;
        }
        if ($parentStatus !== 102) {
            $this->scheduleRetry();
            $this->SetStatus(104);
            return;
        }

        $this->SetTimerInterval('RetryConnect', 0);
        $this->WriteAttributeInteger('RetryTries', 0);
        $this->SetStatus(102);
        $this->SetReceiveDataFilter('.*\/config.*');
    }

    public function RetryConnect(): void
    {
        $parentId = $this->getParentId();
        if ($parentId <= 0) {
            $parentId = $this->autoConnectToBridge();
        }
        if ($parentId > 0) {
            $status = 0;
            try {
                $status = (int)(@IPS_GetInstance($parentId)['InstanceStatus'] ?? 0);
            } catch (Throwable $e) {
                $status = 0;
            }
            if ($status === 102) {
                $this->SetTimerInterval('RetryConnect', 0);
                $this->WriteAttributeInteger('RetryTries', 0);
                $this->ApplyChanges();
                return;
            }
        }

        $tries = $this->ReadAttributeInteger('RetryTries');
        if ($tries >= 12) {
            $this->SetTimerInterval('RetryConnect', 0);
            return;
        }
        $this->WriteAttributeInteger('RetryTries', $tries + 1);
        $this->SetTimerInterval('RetryConnect', 1000);
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

    private function scheduleRetry(): void
    {
        $tries = $this->ReadAttributeInteger('RetryTries');
        if ($tries >= 12) {
            $this->SetTimerInterval('RetryConnect', 0);
            return;
        }
        $this->SetTimerInterval('RetryConnect', 1000);
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
        $deviceId = $this->getTopicToken($topic, 3);
        if ($deviceId === '') {
            $deviceId = $this->getDeviceIdentifier($device);
        }
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

        return '';
    }

    public function GetConfigurationForm(): string
    {
        $values = $this->buildDeviceValues();
        $form = [
            'elements' => [
                [
                    'type'    => 'Configurator',
                    'caption' => 'Devices',
                    'columns' => [
                        ['caption' => 'Name', 'name' => 'name', 'width' => '250px'],
                        ['caption' => 'Device ID', 'name' => 'deviceId', 'width' => '200px'],
                        ['caption' => 'Model', 'name' => 'model', 'width' => '120px'],
                        ['caption' => 'Entities', 'name' => 'entities', 'width' => 'auto']
                    ],
                    'values'  => $values
                ]
            ],
            'status'  => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Active'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'No Bridge connected']
            ]
        ];
        return json_encode($form);
    }

    public function GetCompatibleParents(): string
    {
        return json_encode(['type' => 'require', 'moduleIDs' => [self::BRIDGE_MODULE_GUID]]);
    }

    public function SyncDiscoveryToDevices(): void
    {
        $cache = $this->readCache();
        $this->debug('SyncDiscovery', sprintf('Cache has %d entries', count($cache)));
        
        if (empty($cache)) {
            $this->debug('SyncDiscovery', 'Cache is empty - nothing to sync');
            return;
        }

        $instances = IPS_GetInstanceListByModuleID(self::DEVICE_MODULE_GUID);
        $this->debug('SyncDiscovery', sprintf('Found %d device instances', count($instances)));
        
        foreach ($instances as $instanceId) {
            $deviceId = trim((string)@IPS_GetProperty($instanceId, 'DeviceId'));
            $this->debug('SyncDiscovery', sprintf('Instance %d has DeviceId=%s', $instanceId, $deviceId));
            
            if ($deviceId === '') {
                continue;
            }

            $matchCount = 0;
            foreach ($cache as $uniqueId => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $entryDeviceId = $this->getTopicToken((string)($entry['state_topic'] ?? ''), 3);
                if ($entryDeviceId === '') {
                    $entryDeviceId = (string)($entry['device']['id'] ?? '');
                }
                
                $this->debug('SyncDiscovery', sprintf('Entry %s has DeviceId=%s (comparing to %s)', $uniqueId, $entryDeviceId, $deviceId));
                
                if ($entryDeviceId === '' || strcasecmp($entryDeviceId, $deviceId) !== 0) {
                    continue;
                }
                
                $matchCount++;
                $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $this->debug('SyncDiscovery', sprintf('Sending to instance %d: %s', $instanceId, substr($json, 0, 150)));
                @XSND_UpdateDiscovery($instanceId, $json);
            }
            
            $this->debug('SyncDiscovery', sprintf('Sent %d entries to instance %d', $matchCount, $instanceId));
        }
    }

    public function Destroy(): void
    {
        //Never delete this line!
        parent::Destroy();
    }

    private function isConfigTopic(string $topic): bool
    {
        return str_ends_with($topic, '/config');
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

    private function getParentId(): int
    {
        $inst = @IPS_GetInstance($this->InstanceID);
        return is_array($inst) ? (int)($inst['ConnectionID'] ?? 0) : 0;
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

    private function buildDeviceValues(): array
    {
        $cache = $this->readCache();
        $devices = [];
        foreach ($cache as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $device = isset($entry['device']) && is_array($entry['device']) ? $entry['device'] : [];
            $deviceId = $this->getTopicToken((string)($entry['state_topic'] ?? ''), 3);
            if ($deviceId === '') {
                $deviceId = (string)($device['id'] ?? '');
            }
            if ($deviceId === '') {
                continue;
            }
            if (!isset($devices[$deviceId])) {
                $devices[$deviceId] = [
                    'id'       => $deviceId,
                    'name'     => (string)($device['name'] ?? $deviceId),
                    'model'    => (string)($device['model'] ?? ''),
                    'entities' => []
                ];
            }
            $suffix = (string)($entry['suffix'] ?? '');
            if ($suffix !== '' && !in_array($suffix, $devices[$deviceId]['entities'], true)) {
                $devices[$deviceId]['entities'][] = $suffix;
            }
        }

        ksort($devices, SORT_NATURAL | SORT_FLAG_CASE);

        $values = [];
        foreach ($devices as $device) {
            $instanceId = $this->findDeviceInstance($device['id']);
            $name = $this->formatInstanceName((string)$device['name']);
            $values[] = [
                'name'      => $name,
                'deviceId'  => $device['id'],
                'model'     => $device['model'],
                'entities'  => implode(', ', $device['entities']),
                'instanceID' => $instanceId,
                'create'    => [
                    'moduleID'      => self::DEVICE_MODULE_GUID,
                    'configuration' => ['DeviceId' => $device['id']]
                ]
            ];
        }
        return $values;
    }

    private function formatInstanceName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return $name;
        }
        $pos = strpos($name, '(');
        if ($pos === false || $pos === 0) {
            return $name;
        }
        if ($name[$pos - 1] === ' ') {
            return $name;
        }
        return substr($name, 0, $pos) . ' ' . substr($name, $pos);
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