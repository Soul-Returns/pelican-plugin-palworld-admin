<?php

namespace Soul\PalworldAdmin\Support;

/**
 * Parser/serializer for the OptionSettings tuple inside PalWorldSettings.ini:
 *
 *   [/Script/Pal.PalGameWorldSettings]
 *   OptionSettings=(Difficulty=None,DayTimeSpeedRate=1.000000,ServerName="My Server",...)
 *
 * Values are kept as raw strings (quotes stripped for quoted values) and the
 * original key order is preserved so a round-trip produces a minimal diff.
 */
class OptionSettings
{
    /** Keys whose values must be written with surrounding double quotes. */
    private const QUOTED_HINTS = ['ServerName', 'ServerDescription', 'AdminPassword', 'ServerPassword', 'PublicIP', 'Region', 'BanListURL', 'LogFormatType', 'CrossplayPlatforms', 'ServerAuthTicket'];

    /**
     * @param  array<string, string>  $values  raw values, unquoted
     * @param  array<string, bool>  $wasQuoted  whether each key was quoted in the source
     */
    public function __construct(
        public array $values = [],
        private array $wasQuoted = [],
    ) {}

    public static function parseIni(string $ini): self
    {
        if (!preg_match('/^\s*OptionSettings\s*=\s*\((.*)\)\s*$/m', $ini, $m)) {
            return new self();
        }

        return self::parseTuple($m[1]);
    }

    public static function parseTuple(string $tuple): self
    {
        $values = [];
        $wasQuoted = [];

        // Split on commas that are not inside double quotes.
        foreach (preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $tuple) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || !str_contains($pair, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $pair, 2);
            $key = trim($key);
            $value = trim($value);

            $quoted = strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"');
            if ($quoted) {
                $value = substr($value, 1, -1);
            }

            $values[$key] = $value;
            $wasQuoted[$key] = $quoted;
        }

        return new self($values, $wasQuoted);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, string $value): void
    {
        $this->values[$key] = $value;
    }

    /**
     * Replace the full value set (e.g. from a submitted form) while keeping
     * the quoting style learned from the parsed source for surviving keys.
     *
     * @param  array<string, string|null>  $values
     */
    public function replaceValues(array $values): void
    {
        $this->values = array_map(fn ($v) => trim((string) $v), $values);
    }

    public function toTuple(): string
    {
        $parts = [];
        foreach ($this->values as $key => $value) {
            $parts[] = $key . '=' . ($this->shouldQuote($key) ? '"' . $value . '"' : $value);
        }

        return '(' . implode(',', $parts) . ')';
    }

    /**
     * Replace the OptionSettings line inside an existing ini document, keeping
     * everything else (sections, comments) untouched. Appends the section if missing.
     */
    public function applyToIni(string $ini): string
    {
        $line = 'OptionSettings=' . $this->toTuple();

        if (preg_match('/^\s*OptionSettings\s*=.*$/m', $ini)) {
            return preg_replace('/^\s*OptionSettings\s*=.*$/m', $line, $ini, 1);
        }

        if (str_contains($ini, '[/Script/Pal.PalGameWorldSettings]')) {
            return preg_replace(
                '/(\[\/Script\/Pal\.PalGameWorldSettings\]\s*\R)/',
                '$1' . $line . "\n",
                $ini,
                1
            );
        }

        return rtrim($ini) . "\n[/Script/Pal.PalGameWorldSettings]\n" . $line . "\n";
    }

    private function shouldQuote(string $key): bool
    {
        return $this->wasQuoted[$key] ?? in_array($key, self::QUOTED_HINTS, true);
    }
}
