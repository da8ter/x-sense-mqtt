<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/XSenseMQTTHelper.php';
require_once __DIR__ . '/../libs/XSenseMQTTDeviceDiscovery.php';
require_once __DIR__ . '/../libs/XSenseMQTTDeviceVariables.php';

class XSenseMQTTDevice extends IPSModuleStrict
{
    use XSenseMQTTHelper;
    use XSenseMQTTDeviceDiscovery;
    use XSenseMQTTDeviceVariables;

    private const BRIDGE_MODULE_GUID = '{3B3A2F6D-7E9B-4F2A-9C6A-1F2E3D4C5B6A}';
    private const BRIDGE_RX_GUID = '{D5C8F9A1-2D3E-4F50-8A6B-1C2D3E4F5A6B}'; // Bridge→Device

    private const STATUS_ACTIVE = 102;
    private const STATUS_NO_PARENT = 104;
    private const STATUS_PARENT_INACTIVE = 201;
    private const STATUS_DEVICE_ID_EMPTY = 202;

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('DeviceId', '');
        $this->RegisterPropertyBoolean('Debug', false);
        $this->RegisterAttributeString('Entities', '{}');
        $this->RegisterAttributeString('PresentationMigrated', '0'); // einmalige Bereinigung, siehe migrateLegacyPresentations
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        // Neu anwenden, wenn der Kernel bereit ist, sich die Verbindung ändert oder
        // die Bridge ihren Status wechselt — sonst bliebe der Status-Latch (104/201)
        // bis zum manuellen Übernehmen bestehen.
        switch ($Message) {
            case IPS_KERNELSTARTED:
            case FM_CONNECT:
            case FM_DISCONNECT:
                $this->ApplyChanges();
                break;
            case IM_CHANGESTATUS:
                if ($SenderID === $this->getParentId()) {
                    $this->ApplyChanges();
                }
                break;
        }
    }

    public function Destroy(): void
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->resetDebugFlagCache();

        // Kein Heavy Work vor KR_READY
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        $this->SetReceiveDataFilter('.*');

        $parentId = $this->getParentId();
        $this->registerWatchedMessages($parentId);

        if ($parentId <= 0) {
            $this->SetStatus(self::STATUS_NO_PARENT);
            return;
        }

        $parentStatus = 0;
        try {
            $parentStatus = (int)(@IPS_GetInstance($parentId)['InstanceStatus'] ?? 0);
        } catch (Throwable $e) {
            $parentStatus = 0;
        }
        if ($parentStatus !== self::STATUS_ACTIVE) {
            $this->SetStatus(self::STATUS_PARENT_INACTIVE);
            return;
        }

        if (trim($this->ReadPropertyString('DeviceId')) === '') {
            $this->SetStatus(self::STATUS_DEVICE_ID_EMPTY);
            return;
        }

        $this->SetStatus(self::STATUS_ACTIVE);
        $this->updateReceiveDataFilter();
        $this->SetSummary($this->ReadPropertyString('DeviceId'));
        $this->maintainAllVariables();
        $this->debug('ApplyChanges', 'Status=102, DeviceId=%s, ParentId=%d', $this->ReadPropertyString('DeviceId'), $parentId);
        $this->requestDiscovery();
    }

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || ($data['DataID'] ?? '') !== self::BRIDGE_RX_GUID) {
            return '';
        }

        $topic = (string)($data['Topic'] ?? '');
        if ($topic === '') {
            return '';
        }

        $payload = $this->decodePayload($data['Payload'] ?? '');
        $this->debug('ReceiveData', 'Topic=%s Payload=%s', $topic, $payload);

        if ($this->isConfigTopic($topic)) {
            $entry = $this->buildConfigEntry($topic, $payload);
            if ($entry !== null) {
                $this->commitDiscoveryEntries($this->prepareDiscoveryEntries([$entry]));
            }
            return '';
        }

        $state = json_decode($payload, true);
        if (!is_array($state)) {
            $this->debug('State', $this->t('Invalid JSON payload'));
            return '';
        }

        $matches = $this->findEntitiesByTopic($topic);
        if (empty($matches)) {
            $this->debug('State', 'No entity for topic=%s (entities=%d)', $topic, count($this->readEntities()));
            return '';
        }

        $updated = false;
        foreach ($matches as $entry) {
            $value = $this->extractValue($state, $entry);
            if ($value === null) {
                $this->debug('State', 'No value for %s', $entry['ident'] ?? '?');
                continue;
            }
            $this->processStatus($entry, $value);
            $updated = true;
        }

        if ($updated) {
            $this->SetValue('LastSeen', time());
        }

        return '';
    }

    public function GetCompatibleParents(): string
    {
        return json_encode([
            'type'    => 'connect',
            'moduleIDs' => [self::BRIDGE_MODULE_GUID]
        ]);
    }

    /** Vom Konfigurator: ein bereits normalisierter Discovery-Eintrag als JSON. */
    public function UpdateDiscovery(string $json): void
    {
        $this->debug('UpdateDiscovery', 'Received: %s', substr($json, 0, 200));

        $entry = json_decode($json, true);
        if (!is_array($entry)) {
            $this->debug('UpdateDiscovery', $this->t('Invalid JSON'));
            return;
        }
        $this->commitDiscoveryEntries($this->prepareDiscoveryEntries([$entry]));
    }

    private function getParentId(): int
    {
        $inst = @IPS_GetInstance($this->InstanceID);
        return is_array($inst) ? (int)($inst['ConnectionID'] ?? 0) : 0;
    }

    private function registerWatchedMessages(int $parentId): void
    {
        foreach ($this->GetMessageList() as $senderID => $messageIDs) {
            foreach ($messageIDs as $messageID) {
                $this->UnregisterMessage($senderID, $messageID);
            }
        }
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);
        if ($parentId > 0) {
            $this->RegisterMessage($parentId, IM_CHANGESTATUS);
        }
    }
}
