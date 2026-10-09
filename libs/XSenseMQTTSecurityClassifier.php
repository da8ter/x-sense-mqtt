<?php

declare(strict_types=1);

/**
 * Einordnung der X-Sense-Entitäten für die Sicherheitszentrale — rein, ohne Kernel, damit der
 * Rauchtest sie mit den echten Discovery-Daten prüfen kann.
 *
 * Grundlage sind device_class und Suffix der unique_id, nicht der Ident der Variable: der hängt
 * vom Discovery-Bestand ab. Gemessen an einer SBS50 mit XS0B-MR (09.10.2026): smokealarm (smoke),
 * smokefault und lifeend (beide problem), battery (battery), online (connectivity).
 */
final class XSenseMQTTSecurityClassifier
{
    public const LEVEL_OK = 0;
    public const LEVEL_WARNING = 1;
    public const LEVEL_ALARM = 2;

    private const ALARM_CLASSES = ['smoke', 'carbon_monoxide', 'gas', 'heat', 'moisture', 'safety'];

    /** Reihenfolge der Hinweise: das Dringendste zuerst */
    private const ISSUE_ORDER = ['alarm', 'connection', 'offline', 'fault', 'lifeEnd', 'battery', 'problem', 'stale'];

    /**
     * Rolle einer Entität: alarm, fault, lifeEnd, battery, online, problem, value oder other.
     *
     * @param array{component?: string, deviceClass?: string, suffix?: string, unit?: string} $entity
     */
    public static function role(array $entity): string
    {
        $class = strtolower((string)($entity['deviceClass'] ?? ''));
        $suffix = strtolower((string)($entity['suffix'] ?? ''));
        $component = strtolower((string)($entity['component'] ?? ''));

        if ($component === 'sensor' || ($component !== 'binary_sensor' && (string)($entity['unit'] ?? '') !== '')) {
            return 'value';
        }
        if (in_array($class, self::ALARM_CLASSES, true) || str_ends_with($suffix, 'alarm')) {
            return 'alarm';
        }
        if ($class === 'battery' || str_contains($suffix, 'battery')) {
            return 'battery';
        }
        if ($class === 'connectivity' || $suffix === 'online') {
            return 'online';
        }
        if (str_contains($suffix, 'lifeend') || $suffix === 'eol') {
            return 'lifeEnd';
        }
        if (str_contains($suffix, 'fault')) {
            return 'fault';
        }
        if ($class === 'problem' || $class === 'tamper') {
            return 'problem';
        }
        return 'other';
    }

    /** Art eines Alarms für das Symbol: smoke, co, heat, water oder other. */
    public static function alarmKind(array $entity): string
    {
        $class = strtolower((string)($entity['deviceClass'] ?? ''));
        $suffix = strtolower((string)($entity['suffix'] ?? ''));
        return match (true) {
            $class === 'smoke' || str_starts_with($suffix, 'smoke')                     => 'smoke',
            $class === 'carbon_monoxide' || $class === 'gas' || str_starts_with($suffix, 'co') => 'co',
            $class === 'heat' || str_starts_with($suffix, 'heat')                       => 'heat',
            $class === 'moisture' || str_starts_with($suffix, 'water') || str_contains($suffix, 'leak') => 'water',
            default                                                                     => 'other'
        };
    }

    /**
     * Zustand eines Geräts.
     *
     * @param array{
     *   entities: list<array{component?: string, deviceClass?: string, suffix?: string, unit?: string, name?: string, value: mixed}>,
     *   instanceStatus: int, bridgeStatus: int, lastSeen: int, now: int, staleHours: int
     * } $device
     * @return array{level: int, issues: list<string>, indicators: list<array{key: string, kind: string, state: string}>, values: list<array{name: string, value: float|int|string, unit: string}>}
     */
    public static function evaluateDevice(array $device): array
    {
        $issues = [];
        $indicators = [];
        $values = [];
        $connectionLost = (int)$device['instanceStatus'] !== 102 || (int)$device['bridgeStatus'] !== 102;
        if ($connectionLost) {
            $issues['connection'] = true;
        }

        foreach ($device['entities'] as $entity) {
            $role = self::role($entity);
            $value = $entity['value'] ?? null;
            $active = $value === true;
            switch ($role) {
                case 'alarm':
                    if ($active) {
                        $issues['alarm'] = true;
                    }
                    $indicators[] = ['key' => 'alarm', 'kind' => self::alarmKind($entity), 'state' => $active ? 'alarm' : 'ok'];
                    break;
                case 'online':
                    $offline = $value === false;
                    if ($offline) {
                        $issues['offline'] = true;
                    }
                    $state = $connectionLost || $offline ? 'warn' : ($value === true ? 'ok' : 'unknown');
                    $indicators[] = ['key' => 'online', 'kind' => '', 'state' => $state];
                    break;
                case 'fault':
                case 'lifeEnd':
                case 'battery':
                case 'problem':
                    if ($active) {
                        $issues[$role] = true;
                    }
                    $indicators[] = ['key' => $role, 'kind' => '', 'state' => $active ? 'warn' : 'ok'];
                    break;
                case 'value':
                    if (is_int($value) || is_float($value) || (is_string($value) && $value !== '')) {
                        $values[] = ['name' => (string)($entity['name'] ?? ''), 'value' => $value, 'unit' => (string)($entity['unit'] ?? '')];
                    }
                    break;
            }
        }

        $staleHours = (int)$device['staleHours'];
        $lastSeen = (int)$device['lastSeen'];
        if ($staleHours > 0 && $lastSeen > 0 && (int)$device['now'] - $lastSeen > $staleHours * 3600) {
            $issues['stale'] = true;
        }

        usort($indicators, static fn(array $a, array $b): int => self::indicatorRank($a['key']) <=> self::indicatorRank($b['key']));
        $ordered = array_values(array_filter(self::ISSUE_ORDER, static fn(string $code): bool => isset($issues[$code])));
        $level = isset($issues['alarm']) ? self::LEVEL_ALARM : ($ordered !== [] ? self::LEVEL_WARNING : self::LEVEL_OK);

        return ['level' => $level, 'issues' => $ordered, 'indicators' => $indicators, 'values' => $values];
    }

    /**
     * Gesamtstand über alle Geräte.
     *
     * @param list<array{level: int, issues: list<string>}> $devices
     * @return array{level: int, total: int, alarms: int, warnings: int, online: int}
     */
    public static function overall(array $devices): array
    {
        $result = ['level' => self::LEVEL_OK, 'total' => count($devices), 'alarms' => 0, 'warnings' => 0, 'online' => 0];
        foreach ($devices as $device) {
            $level = (int)$device['level'];
            $result['level'] = max($result['level'], $level);
            if ($level === self::LEVEL_ALARM) {
                $result['alarms']++;
            } elseif ($level === self::LEVEL_WARNING) {
                $result['warnings']++;
            }
            if (!in_array('offline', $device['issues'], true) && !in_array('connection', $device['issues'], true)) {
                $result['online']++;
            }
        }
        return $result;
    }

    private static function indicatorRank(string $key): int
    {
        $rank = array_search($key, ['alarm', 'fault', 'battery', 'lifeEnd', 'problem', 'online'], true);
        return $rank === false ? 99 : $rank;
    }
}
