<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/XSenseMQTTHelper.php';

class XSenseMQTTKonfigurator extends IPSModuleStrict
{
    use XSenseMQTTHelper;
    private const BRIDGE_MODULE_GUID = '{3B3A2F6D-7E9B-4F2A-9C6A-1F2E3D4C5B6A}';
    private const BRIDGE_RX_GUID = '{D5C8F9A1-2D3E-4F50-8A6B-1C2D3E4F5A6B}'; // Bridge→Konfigurator (not used anymore)
    private const DEVICE_MODULE_GUID = '{C523B0B6-870E-9726-778A-0FF5C6E9656E}';

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyBoolean('Debug', false);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $bridgeId = $this->getBridgeId();
        if ($bridgeId <= 0) {
            $this->SetStatus(104);
            return;
        }

        $this->SetStatus(102);
    }

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || ($data['DataID'] ?? '') !== self::BRIDGE_RX_GUID) {
            return '';
        }

        $topic = (string)($data['Topic'] ?? '');
        if ($topic === '' || !$this->isConfigTopic($topic)) {
            return '';
        }

        $this->debug('ReceiveData', sprintf($this->t('Topic=%s'), $topic));
        return '';
    }

    public function GetConfigurationForm(): string
    {
        $bridgeId = $this->getBridgeId();
        $cacheCount = count($this->readCache());
        $bridgeInfo = $bridgeId > 0
            ? sprintf($this->t('Bridge #%d, Cache: %d entries'), $bridgeId, $cacheCount)
            : $this->t('No Bridge found');
        
        $values = $this->buildDeviceValues();
        $form = [
            'elements' => [
                [
                    'type'    => 'Label',
                    'caption' => $bridgeInfo
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'Debug',
                    'caption' => 'Debug'
                ],
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
            'actions' => [
                [
                    'type'    => 'Button',
                    'caption' => $this->t('Refresh cache'),
                    'onClick' => 'IPS_RequestAction($id, "RefreshCache", true);'
                ]
            ],
            'status'  => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Active'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'No Bridge connected']
            ]
        ];
        return json_encode($form);
    }
    
    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'RefreshCache') {
            $bridgeId = $this->getBridgeId();
            if ($bridgeId > 0) {
                @XSNB_ReplayDiscovery($bridgeId, '');
            }
            $this->ReloadForm();
        }
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

    private function getBridgeId(): int
    {
        $bridges = @IPS_GetInstanceListByModuleID(self::BRIDGE_MODULE_GUID);
        if (!is_array($bridges) || empty($bridges)) {
            return 0;
        }
        foreach ($bridges as $bridgeId) {
            if (@IPS_InstanceExists($bridgeId)) {
                $status = (int)(@IPS_GetInstance($bridgeId)['InstanceStatus'] ?? 0);
                if ($status === 102) {
                    return $bridgeId;
                }
            }
        }
        // Return first bridge even if not active
        return (int)$bridges[0];
    }

    private function readCache(): array
    {
        $bridgeId = $this->getBridgeId();
        if ($bridgeId <= 0) {
            return [];
        }
        $raw = @XSNB_GetDiscoveryCache($bridgeId);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $bridgeCache = json_decode($raw, true);
        if (!is_array($bridgeCache)) {
            return [];
        }
        $result = [];
        foreach ($bridgeCache as $topic => $payload) {
            if (!is_string($payload) || $payload === '') {
                continue;
            }
            $decoded = $this->decodePayload($payload);
            $entry = json_decode($decoded, true);
            if (!is_array($entry)) {
                continue;
            }
            $uniqueId = (string)($entry['unique_id'] ?? '');
            if ($uniqueId === '') {
                $uniqueId = $this->getTopicToken($topic, 2);
            }
            if ($uniqueId !== '') {
                $result[$uniqueId] = $entry;
            }
        }
        $this->debug('readCache', sprintf('Bridge #%d: %d entries', $bridgeId, count($result)));
        return $result;
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
            // Extract entity type from unique_id (e.g., SBS50148995FA_00000001_online -> online)
            $uniqueId = (string)($entry['unique_id'] ?? '');
            $entityType = '';
            if ($uniqueId !== '') {
                $parts = explode('_', $uniqueId);
                if (count($parts) >= 3) {
                    $entityType = end($parts);
                }
            }
            if ($entityType !== '' && !in_array($entityType, $devices[$deviceId]['entities'], true)) {
                $devices[$deviceId]['entities'][] = $entityType;
            }
        }

        ksort($devices, SORT_NATURAL | SORT_FLAG_CASE);

        $values = [];
        foreach ($devices as $device) {
            $instanceId = $this->findDeviceInstance($device['id']);
            $name = $this->formatInstanceName((string)$device['name']);
            $createConfig = [
                'moduleID'      => self::DEVICE_MODULE_GUID,
                'configuration' => ['DeviceId' => $device['id']],
                'name'          => $name
            ];
            $values[] = [
                'name'       => $name,
                'deviceId'   => $device['id'],
                'model'      => $device['model'],
                'entities'   => implode(', ', $device['entities']),
                'instanceID' => $instanceId,
                'create'     => $createConfig
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

}