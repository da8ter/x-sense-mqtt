<?php

declare(strict_types=1);

/**
 * Discovery und Entity-Bestand des Geräts: Config-Payloads werden zu Einträgen, Einträge in
 * einem Durchgang übernommen; der Bestand liegt im Attribut "Entities" und wird je Objekt nur
 * dann neu dekodiert, wenn sich das Attribut geändert hat (ein anderes Objekt derselben Instanz
 * kann zwischendurch geschrieben haben).
 */
trait XSenseMQTTDeviceDiscovery
{
    /** Dekodierter Bestand samt dem Rohtext, aus dem er stammt */
    private ?array $entitiesCache = null;
    private ?string $entitiesCacheRaw = null;
    /** state_topic → Einträge, gültig für $entitiesCacheRaw */
    private ?array $topicIndexCache = null;

    /**
     * Macht aus rohen Discovery-Einträgen (Config-Payload-Struktur oder bereits normalisiert)
     * die Einträge dieser Instanz: DeviceId-Abgleich, suffix und ident ergänzt. Fremde oder
     * unvollständige Einträge fallen weg.
     *
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private function prepareDiscoveryEntries(array $entries): array
    {
        $prepared = [];
        foreach ($entries as $entry) {
            $entry = $this->prepareDiscoveryEntry($entry);
            if ($entry !== null) {
                $prepared[] = $entry;
            }
        }
        return $prepared;
    }

    private function prepareDiscoveryEntry(array $entry): ?array
    {
        $deviceId = $this->getTopicToken((string)($entry['state_topic'] ?? ''), 3);
        if ($deviceId === '') {
            $deviceId = $this->extractDeviceIdFromEntry($entry);
        }
        if ($deviceId !== '') {
            $entry['device']['id'] = $deviceId;
        }
        $this->debug('UpdateDiscovery', 'Extracted DeviceId=%s from entry', $deviceId);

        if ($deviceId === '') {
            $this->debug('UpdateDiscovery', $this->t('DeviceId missing'));
            return null;
        }

        $propertyDeviceId = trim($this->ReadPropertyString('DeviceId'));
        $this->debug('UpdateDiscovery', 'PropertyDeviceId=%s, EntryDeviceId=%s', $propertyDeviceId, $deviceId);

        if ($propertyDeviceId === '' || strcasecmp($propertyDeviceId, $deviceId) !== 0) {
            $this->debug('UpdateDiscovery', '%s (property=%s, entry=%s)', $this->t('DeviceId mismatch'), $propertyDeviceId, $deviceId);
            return null;
        }

        $uniqueId = (string)($entry['unique_id'] ?? '');
        $stateTopic = (string)($entry['state_topic'] ?? '');
        if ($uniqueId === '' || $stateTopic === '') {
            $this->debug('UpdateDiscovery', $this->t('unique_id/state_topic missing'));
            return null;
        }

        $entry['suffix'] = $entry['suffix'] ?? $this->extractSuffix($uniqueId);
        $entry['ident'] = $entry['ident'] ?? $this->getIdentForEntry($entry);

        return $entry;
    }

    /**
     * Übernimmt vorbereitete Einträge in EINEM Durchgang: ein Attribut-Write, ein Filter-Update.
     * Die Geräteangaben (Hersteller, Modell, Firmware) werden über alle Einträge zusammengeführt,
     * ein leeres Feld überschreibt kein gefülltes; so hängt das Ergebnis nicht von der
     * Reihenfolge des Bridge-Caches ab.
     *
     * @param array<int, array<string, mixed>> $entries
     */
    private function commitDiscoveryEntries(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $entities = $this->readEntities();
        foreach ($entries as $entry) {
            $entities[(string)$entry['unique_id']] = $entry;
        }
        if (!$this->writeEntities($entities)) {
            return; // nicht gespeichert: keine Variablen zu einem Bestand anlegen, den es nicht gibt
        }

        $this->maintainDeviceVariables();
        $device = [];
        foreach ($entries as $entry) {
            $this->maintainEntityVariable($entry);
            foreach ((array)($entry['device'] ?? []) as $key => $value) {
                if (is_string($value) && $value !== '') {
                    $device[$key] = $value;
                }
            }
        }
        $this->updateDeviceInfo($device);
        $this->updateReceiveDataFilter();
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

    /** Holt den Discovery-Cache der Bridge und übernimmt die Einträge dieses Geräts in einem Durchgang. */
    private function requestDiscovery(): void
    {
        $parentId = $this->getParentId();
        if ($parentId <= 0) {
            $this->debug('requestDiscovery', 'No parent');
            return;
        }
        $deviceId = trim($this->ReadPropertyString('DeviceId'));
        if ($deviceId === '') {
            $this->debug('requestDiscovery', 'DeviceId not set, skipping');
            return;
        }
        $this->debug('requestDiscovery', 'Reading cache from Bridge %d for DeviceId=%s', $parentId, $deviceId);

        try {
            $raw = @XSNB_GetDiscoveryCache($parentId);
        } catch (Throwable $e) {
            $this->debug('requestDiscovery', 'GetDiscoveryCache failed: %s', $e->getMessage());
            return;
        }
        if (!is_string($raw) || $raw === '') {
            $this->debug('requestDiscovery', 'Cache empty');
            return;
        }

        $cache = json_decode($raw, true);
        if (!is_array($cache)) {
            return;
        }

        $entries = [];
        foreach ($cache as $topic => $payload) {
            if (!is_string($topic) || !str_ends_with($topic, '/config')) {
                continue;
            }
            if (!is_string($payload) || $payload === '') {
                continue;
            }
            if (strcasecmp($this->getTopicToken($topic, 3), $deviceId) !== 0) {
                continue;
            }
            $entry = $this->buildConfigEntry($topic, $this->decodePayload($payload));
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }
        $prepared = $this->prepareDiscoveryEntries($entries);
        $this->commitDiscoveryEntries($prepared);
        $this->debug('requestDiscovery', 'Processed %d config entries', count($prepared));
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

    /** Baut aus einem Config-Payload den normalisierten Discovery-Eintrag (rein, ohne Seiteneffekte). */
    private function buildConfigEntry(string $topic, string $payload): ?array
    {
        if ($payload === '') {
            return null;
        }

        $cfg = json_decode($payload, true);
        if (!is_array($cfg)) {
            return null;
        }

        $uniqueId = trim((string)($cfg['unique_id'] ?? $this->getTopicToken($topic, 2)));
        $device = isset($cfg['device']) && is_array($cfg['device']) ? $cfg['device'] : [];
        $deviceId = $this->getTopicToken($topic, 3);
        if ($deviceId === '') {
            $deviceId = $this->getDeviceIdentifier($device);
        }
        if ($uniqueId === '' || $deviceId === '') {
            return null;
        }

        $stateTopic = trim((string)($cfg['state_topic'] ?? ''));
        if ($stateTopic === '') {
            return null;
        }

        return [
            'unique_id'      => $uniqueId,
            'name'           => (string)($cfg['name'] ?? ''),
            'component'      => $this->getTopicToken($topic, 4),
            'state_topic'    => $stateTopic,
            'device_class'   => (string)($cfg['device_class'] ?? ''),
            'payload_on'     => (string)($cfg['payload_on'] ?? ''),
            'payload_off'    => (string)($cfg['payload_off'] ?? ''),
            'unit_of_measurement' => (string)($cfg['unit_of_measurement'] ?? ''),
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
    }

    /**
     * Der Entity-Bestand. Das Attribut wird jedes Mal gelesen (ein Kernelaufruf), dekodiert
     * aber nur, wenn sich der Text geändert hat; der Topic-Index verfällt mit ihm.
     *
     * @return array<string, array<string, mixed>>
     */
    private function readEntities(): array
    {
        $raw = $this->ReadAttributeString('Entities');
        if ($this->entitiesCache !== null && $this->entitiesCacheRaw === $raw) {
            return $this->entitiesCache;
        }
        $entities = json_decode($raw, true);
        $this->entitiesCache = is_array($entities) ? $entities : [];
        $this->entitiesCacheRaw = $raw;
        $this->topicIndexCache = null;
        return $this->entitiesCache;
    }

    /** Speichert den Bestand; false und ein Logeintrag, wenn er sich nicht kodieren lässt (der alte bleibt). */
    private function writeEntities(array $entities): bool
    {
        $json = json_encode($entities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) {
            $this->LogMessage(sprintf('%s: %s', $this->t('Entities could not be saved'), json_last_error_msg()), KL_ERROR);
            return false;
        }
        $this->WriteAttributeString('Entities', $json);
        $this->entitiesCache = $entities;
        $this->entitiesCacheRaw = $json;
        $this->topicIndexCache = null;
        return true;
    }

    /** @return array<int, array<string, mixed>> die Einträge zum state_topic */
    private function findEntitiesByTopic(string $topic): array
    {
        $entities = $this->readEntities(); // erneuert den Index, wenn sich der Bestand geändert hat
        if ($this->topicIndexCache === null) {
            $index = [];
            foreach ($entities as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $stateTopic = (string)($entry['state_topic'] ?? '');
                if ($stateTopic !== '') {
                    $index[$stateTopic][] = $entry;
                }
            }
            $this->topicIndexCache = $index;
        }
        return $this->topicIndexCache[$topic] ?? [];
    }
}
