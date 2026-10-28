<?php

namespace App\Support\Hygiene;

/** [CAYENNE HYGIÈNE 2026-10-17] Les types d'équipement d'UN écran (config/hygiene.php, clé `domain`) : temperature | oil. */
final class Kinds
{
    /** @return list<string> clés des types du domaine */
    public static function keys(string $domain): array
    {
        return array_keys(self::all($domain));
    }

    /** @return array<string, array> type => définition */
    public static function all(string $domain): array
    {
        return array_filter((array) config('hygiene.kinds'), fn ($k) => ($k['domain'] ?? 'temperature') === $domain);
    }
}
