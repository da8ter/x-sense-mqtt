<?php

declare(strict_types=1);

trait XSenseMQTTHelper
{
    /**
     * Dekodiert einen MQTT-Payload. Wenn der Payload eine gerade Anzahl
     * Hex-Zeichen enthält (z.B. MQTT Server liefert Binary als Hex-String),
     * wird hex2bin ausgeführt. Ansonsten wird der String unverändert zurückgegeben.
     */
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

    private function getTopicToken(string $topic, int $fromEnd): string
    {
        $parts = explode('/', trim($topic, '/'));
        $index = count($parts) - $fromEnd;
        if ($index >= 0 && $index < count($parts)) {
            return (string) $parts[$index];
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

    private function isConfigTopic(string $topic): bool
    {
        return str_ends_with($topic, '/config');
    }

    /** Session-Cache des Debug-Flags — erspart ReadPropertyBoolean pro Nachricht */
    private ?bool $debugFlagCache = null;

    /**
     * Debug-Ausgabe mit Fast-Path: Format-Argumente werden erst NACH dem Flag-Check
     * formatiert, damit im Normalbetrieb (Debug aus) auf dem heißen Pfad keine
     * sprintf-/String-Arbeit anfällt. Ohne $args wird $format unverändert ausgegeben.
     */
    private function debug(string $message, string $format, mixed ...$args): void
    {
        if ($this->debugFlagCache === null) {
            try {
                $this->debugFlagCache = (bool)$this->ReadPropertyBoolean('Debug');
            } catch (Throwable $e) {
                $this->debugFlagCache = false;
            }
        }
        if (!$this->debugFlagCache) {
            return;
        }
        $data = $args === [] ? $format : vsprintf($this->t($format), $args);
        $this->SendDebug($this->t($message), $data, 0);
    }

    private function resetDebugFlagCache(): void
    {
        $this->debugFlagCache = null;
    }

    private function t(string $text): string
    {
        return method_exists($this, 'Translate') ? $this->Translate($text) : $text;
    }
}
