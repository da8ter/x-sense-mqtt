<?php

declare(strict_types=1);

class XSenseMQTTBridge extends IPSModuleStrict
{
    private const MQTT_SERVER_GUID = '{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}';
    private const MQTT_DATA_GUID = '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}';
    private const BRIDGE_DATA_GUID = '{E8C5B3A2-1D4F-5A60-9B7C-2D3E4F5A6B7C}';

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('TopicRoot', 'homeassistant/binary_sensor');
        $this->RegisterPropertyBoolean('Debug', false);
        $this->RegisterAttributeString('DiscoveryCache', '{}');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetReceiveDataFilter('.*');

        $connID = 0;
        try {
            $connID = (int)(@IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        } catch (Throwable $e) {
            $connID = 0;
        }

        if ($connID === 0) {
            $this->SetStatus(104);
            return;
        }

        if (!$this->HasActiveParent()) {
            $this->SetStatus(104);
            return;
        }

        $this->SetStatus(102);
        $root = $this->normalizeTopicRoot($this->ReadPropertyString('TopicRoot'));
        $this->SetSummary($root);
        $this->subscribeTopic($root . '/+/+/config');
        $this->subscribeTopic($root . '/+/+/state');
    }

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || ($data['DataID'] ?? '') !== self::MQTT_DATA_GUID) {
            return '';
        }

        $topic = (string)($data['Topic'] ?? '');
        if ($topic === '') {
            return '';
        }

        $payload = $data['Payload'] ?? '';
        $this->debug('ReceiveData', sprintf($this->t('Topic=%s'), $topic));

        $root = $this->normalizeTopicRoot($this->ReadPropertyString('TopicRoot'));
        if (!str_starts_with($topic, $root . '/')) {
            return '';
        }

        if (str_ends_with($topic, '/config')) {
            $this->updateDiscoveryCache($topic, (string)$payload);
        }

        $bridgeData = [
            'DataID' => self::BRIDGE_DATA_GUID,
            'Topic' => $topic,
            'Payload' => $payload
        ];

        $this->SendDataToChildren(json_encode($bridgeData));

        return '';
    }

    public function GetCompatibleParents(): string
    {
        return json_encode(['type' => 'require', 'moduleIDs' => [self::MQTT_SERVER_GUID]]);
    }

    public function ForwardToChildren(string $topic, string $payload): void
    {
        $bridgeData = [
            'DataID' => self::BRIDGE_DATA_GUID,
            'Topic' => $topic,
            'Payload' => $payload
        ];
        $this->SendDataToChildren(json_encode($bridgeData));
    }

    public function ReplayDiscovery(string $deviceId = ''): void
    {
        $cache = $this->readDiscoveryCache();
        $this->debug('ReplayDiscovery', sprintf('Cache has %d entries, filter DeviceId=%s', count($cache), $deviceId));
        
        if (empty($cache)) {
            $this->debug('ReplayDiscovery', 'Cache is empty');
            return;
        }

        $sentCount = 0;
        foreach ($cache as $topic => $payload) {
            if (!is_string($topic) || !is_string($payload)) {
                continue;
            }
            if ($deviceId !== '' && !$this->matchesDeviceId($deviceId, $topic, $payload)) {
                $this->debug('ReplayDiscovery', sprintf('Skipping %s (no match)', $topic));
                continue;
            }
            $this->debug('ReplayDiscovery', sprintf('Forwarding %s', $topic));
            $this->ForwardToChildren($topic, $payload);
            $sentCount++;
        }
        
        $this->debug('ReplayDiscovery', sprintf('Sent %d entries', $sentCount));
    }

    private function normalizeTopicRoot(string $root): string
    {
        return trim($root, '/');
    }

    private function updateDiscoveryCache(string $topic, string $payload): void
    {
        $cache = $this->readDiscoveryCache();
        if ($payload === '') {
            if (isset($cache[$topic])) {
                unset($cache[$topic]);
            }
        } else {
            $cache[$topic] = $payload;
        }
        $this->WriteAttributeString('DiscoveryCache', json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function readDiscoveryCache(): array
    {
        $raw = $this->ReadAttributeString('DiscoveryCache');
        $cache = json_decode($raw, true);
        return is_array($cache) ? $cache : [];
    }

    private function matchesDeviceId(string $deviceId, string $topic, string $payload): bool
    {
        $topicDevice = $this->getTopicToken($topic, 3);
        if ($topicDevice !== '' && strcasecmp($topicDevice, $deviceId) === 0) {
            return true;
        }

        $cfg = json_decode($payload, true);
        if (!is_array($cfg)) {
            return false;
        }
        $device = isset($cfg['device']) && is_array($cfg['device']) ? $cfg['device'] : [];
        $ident = $this->getDeviceIdentifier($device);
        return $ident !== '' && strcasecmp($ident, $deviceId) === 0;
    }

    private function getDeviceIdentifier(array $device): string
    {
        if (!isset($device['identifiers'])) {
            return '';
        }
        $identifiers = $device['identifiers'];
        if (is_string($identifiers)) {
            return trim($identifiers);
        }
        if (is_array($identifiers)) {
            $first = $identifiers[0] ?? '';
            if (is_string($first)) {
                return trim($first);
            }
            if (is_array($first)) {
                $flat = $first[0] ?? '';
                return is_string($flat) ? trim($flat) : '';
            }
        }
        return '';
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
