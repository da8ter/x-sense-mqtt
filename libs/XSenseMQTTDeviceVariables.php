<?php

declare(strict_types=1);

/**
 * Variablen des Geräts: Anlegen mit Modul-Darstellung, Werte aus den Zustandsmeldungen,
 * Namen, Idents und Typen aus den Discovery-Einträgen.
 */
trait XSenseMQTTDeviceVariables
{
    private function processStatus(array $entry, mixed $status): void
    {
        $ident = (string)($entry['ident'] ?? $this->getIdentForEntry($entry));
        if ($ident === '') {
            return;
        }

        $varType = $this->resolveVariableType($entry);
        $statusStr = (string)$status;

        if ($varType === 0) {
            $payloadOn = (string)($entry['payload_on'] ?? '');
            $payloadOff = (string)($entry['payload_off'] ?? '');
            if ($payloadOn !== '' && $statusStr === $payloadOn) {
                $this->debug('State', '%s=%s → true', $ident, $statusStr);
                $this->SetValue($ident, true);
                return;
            }
            if ($payloadOff !== '' && $statusStr === $payloadOff) {
                $this->debug('State', '%s=%s → false', $ident, $statusStr);
                $this->SetValue($ident, false);
                return;
            }
            $this->debug('State', 'Unknown status for %s: %s', $ident, $statusStr);
            return;
        }

        if ($varType === 2) {
            $this->debug('State', '%s=%s → float', $ident, $statusStr);
            $this->SetValue($ident, (float)$status);
            return;
        }
        if ($varType === 1) {
            $this->debug('State', '%s=%s → int', $ident, $statusStr);
            $this->SetValue($ident, (int)$status);
            return;
        }
        $this->debug('State', '%s=%s', $ident, $statusStr);
        $this->SetValue($ident, $statusStr);
    }

    private function extractValue(array $data, array $entry): mixed
    {
        $key = $this->parseTemplateKey((string)($entry['value_template'] ?? ''));
        if ($key !== '' && array_key_exists($key, $data)) {
            return $data[$key];
        }
        if (array_key_exists('status', $data)) {
            return $data['status'];
        }
        return null;
    }

    private function parseTemplateKey(string $template): string
    {
        if (preg_match('/value_json\.([\w]+)/', $template, $m)) {
            return $m[1];
        }
        if (preg_match("/value_json\['([\w]+)'\]/", $template, $m)) {
            return $m[1];
        }
        return '';
    }

    /** ApplyChanges: alle Variablen des Bestands; die einmalige Bereinigung alter Darstellungen hängt hier. */
    private function maintainAllVariables(): void
    {
        $entities = $this->readEntities();
        $this->migrateLegacyPresentations($entities);
        if (empty($entities)) {
            return;
        }
        $this->maintainDeviceVariables();
        foreach ($entities as $entry) {
            if (is_array($entry)) {
                $this->maintainEntityVariable($entry);
            }
        }
    }

    private function maintainDeviceVariables(): void
    {
        $this->maintainString('Manufacturer', $this->t('Manufacturer'), 1);
        $this->maintainString('Model', $this->t('Model'), 2);
        $this->maintainString('Firmware', $this->t('Firmware'), 3);
        $this->maintainInteger('LastSeen', $this->t('Last Seen'), 4, ['PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME]);
    }

    private function updateDeviceInfo(array $device): void
    {
        $this->SetValue('Manufacturer', (string)($device['manufacturer'] ?? ''));
        $this->SetValue('Model', (string)($device['model'] ?? ''));
        $this->SetValue('Firmware', (string)($device['sw_version'] ?? ''));
    }

    private function maintainEntityVariable(array $entry): void
    {
        $ident = (string)($entry['ident'] ?? $this->getIdentForEntry($entry));
        if ($ident === '') {
            return;
        }
        $name = $this->resolveVariableName($entry);
        $varType = $this->resolveVariableType($entry);

        $this->MaintainVariable($ident, $name, $varType, $this->buildPresentation($entry, $varType), 20, true);
    }

    /**
     * Modul-Darstellung für MaintainVariable. Bool-Variablen mit payload_on/off bekommen eine
     * Wertdarstellung mit beschrifteten Optionen; Symcon 9.1 erlaubt in OPTIONS nur die acht
     * Unterparameter unten (ColorDisplay/ContentColorDisplay lassen den ganzen Aufruf scheitern).
     */
    private function buildPresentation(array $entry, int $varType): string|array
    {
        if ($varType !== 0) {
            return '';
        }
        $payloadOn = (string)($entry['payload_on'] ?? '');
        $payloadOff = (string)($entry['payload_off'] ?? '');
        if ($payloadOn === '' && $payloadOff === '') {
            return '';
        }
        $option = static function (bool $value, string $caption): array {
            return [
                'Value'              => $value,
                'Caption'            => $caption,
                'IconActive'         => false,
                'IconValue'          => '',
                'ColorActive'        => false,
                'ColorValue'         => -1,
                'ContentColorActive' => false,
                'ContentColorValue'  => -1
            ];
        };
        $options = json_encode([
            $option(false, $this->t($payloadOff ?: 'Off')),
            $option(true, $this->t($payloadOn ?: 'On'))
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($options)) {
            return ''; // ein Payload, der sich nicht kodieren lässt: lieber keine Darstellung als eine kaputte
        }
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS'      => $options
        ];
    }

    /**
     * Einmalig je Instanz: Versionen bis 0.3 setzten die Beschriftung als Benutzer-Darstellung
     * (IPS_SetVariableCustomPresentation) mit ColorDisplay/ContentColorDisplay. Die überlagert die
     * Modul-Darstellung und wird entfernt; eine in der Konsole gesetzte Darstellung kann diese
     * Schlüssel unter 9.1 nicht tragen und bleibt. Danach merkt das Attribut, dass es erledigt ist.
     *
     * @param array<string, array<string, mixed>> $entities
     */
    private function migrateLegacyPresentations(array $entities): void
    {
        if ($this->ReadAttributeString('PresentationMigrated') === '1') {
            return;
        }
        foreach ($entities as $entry) {
            $ident = is_array($entry) ? (string)($entry['ident'] ?? '') : '';
            // GetIDForIdent wirft unter Module Strict bei fehlender Variable — @ hilft nicht
            $varId = $ident !== '' ? @IPS_GetObjectIDByIdent($ident, $this->InstanceID) : 0;
            if (!is_int($varId) || $varId <= 0) {
                continue;
            }
            $current = @IPS_GetVariable($varId);
            $custom = is_array($current) ? ($current['VariableCustomPresentation'] ?? []) : [];
            if (is_array($custom) && ($custom['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_VALUE_PRESENTATION
                && str_contains((string)($custom['OPTIONS'] ?? ''), 'ColorDisplay')) {
                @IPS_SetVariableCustomPresentation($varId, []);
                $this->debug('Migration', 'legacy custom presentation removed from %s', $ident);
            }
        }
        $this->WriteAttributeString('PresentationMigrated', '1');
    }

    private function resolveVariableName(array $entry): string
    {
        $configName = trim((string)($entry['name'] ?? ''));
        if ($configName !== '') {
            return $this->t($configName);
        }
        $suffix = (string)($entry['suffix'] ?? '');
        if ($suffix !== '') {
            return $this->t(ucfirst($suffix));
        }
        return $this->t('Entity');
    }

    private function resolveVariableType(array $entry): int
    {
        $payloadOn = (string)($entry['payload_on'] ?? '');
        $payloadOff = (string)($entry['payload_off'] ?? '');
        if ($payloadOn !== '' || $payloadOff !== '') {
            return 0; // boolean
        }
        $component = (string)($entry['component'] ?? '');
        if ($component === 'binary_sensor') {
            return 0; // boolean
        }
        $unit = (string)($entry['unit_of_measurement'] ?? '');
        $floatUnits = ['°C', '°F', '%', 'ppm', 'ppb', 'V', 'mV', 'A', 'mA', 'W', 'kW', 'kWh', 'Wh', 'Hz', 'dB', 'dBm', 'hPa', 'mbar', 'bar', 'Pa', 'lx', 'lm', 'm', 'cm', 'mm', 'km', 'mph', 'km/h', 'm/s', '°', 'µg/m³', 'mg/m³'];
        if (in_array($unit, $floatUnits, true)) {
            return 2; // float
        }
        if ($unit === '' && $component === 'sensor') {
            return 2; // float (sensor without unit is typically numeric)
        }
        return 3; // string (safe default)
    }

    private function getIdentForEntry(array $entry): string
    {
        $deviceClass = (string)($entry['device_class'] ?? '');
        if ($deviceClass !== '') {
            return $this->sanitizeIdent(ucfirst($deviceClass));
        }
        $name = (string)($entry['name'] ?? '');
        if ($name !== '') {
            return $this->sanitizeIdent($name);
        }
        $suffix = (string)($entry['suffix'] ?? '');
        $uniqueId = (string)($entry['unique_id'] ?? $suffix);
        return $this->sanitizeIdent('Entity_' . $uniqueId);
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

    private function maintainString(string $ident, string $name, int $position, string|array $presentation = '', bool $keep = true): void
    {
        $this->MaintainVariable($ident, $name, 3, $presentation, $position, $keep);
    }

    private function maintainInteger(string $ident, string $name, int $position, string|array $presentation = '', bool $keep = true): void
    {
        $this->MaintainVariable($ident, $name, 1, $presentation, $position, $keep);
    }
}
