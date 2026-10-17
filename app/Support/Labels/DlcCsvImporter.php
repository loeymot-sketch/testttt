<?php

namespace App\Support\Labels;

use App\Models\DlcLabelProduct;
use Illuminate\Support\Str;

/**
 * [CAYENNE HYGIÈNE 2026-10-17 · méga-fichier P1.4] Import des produits à étiqueter depuis un fichier (CSV, comme celui d'Excel).
 *
 * Un produit par ligne : nom, famille, conservation, une colonne de durée PAR événement (« 24 h », « 3 j »), allergènes, mention,
 * source des durées. Le fichier-modèle (`template()`) est le contrat : mêmes colonnes, mêmes mots.
 *
 * RÈGLES — les mêmes que la fiche produit, pas d'autres :
 *   · conservation obligatoire (elle est imprimée sur l'étiquette) ; au moins une durée ; durées bornées (720 h / 365 j) ;
 *   · une décongélation exige « Après décongélation » (froid positif reconnu ou « Ambiante ») ;
 *   · allergènes : noms ou codes ; « aucun » = déclaré sans allergène ; cellule vide = à renseigner (jamais deviné) ;
 *   · un nom déjà présent n'est JAMAIS écrasé (ligne « déjà présent »), un nom répété dans le fichier n'est pris qu'une fois ;
 *   · aucune durée n'est validée par l'import : le responsable les valide ensuite (« Contrôle du catalogue »).
 *
 * Pur : lit du texte, rend des lignes analysées. Rien n'est écrit ici (c'est le contrôleur qui crée, après aperçu).
 */
final class DlcCsvImporter
{
    public const MAX_ROWS = 500;

    /** Colonnes du modèle, dans l'ordre : clé interne => libellé. L'ordre des durées fixe l'événement « par défaut » (le premier rempli). */
    public const COLUMNS = [
        'name' => 'Produit',
        'category' => 'Famille',
        'storage' => 'Conservation',
        'thawed_storage' => 'Après décongélation',
        'opened' => 'Ouverture',
        'thawed' => 'Décongélation',
        'prepared' => 'Préparation',
        'cooked' => 'Cuisson',
        'vacuum' => 'Sous vide',
        'frozen' => 'Congélation',
        'witness' => 'Repas témoin',
        'received' => 'Réception',
        'allergens' => 'Allergènes',
        'note' => 'Mention',
        'source' => 'Source des durées',
    ];

    /** Mots acceptés en en-tête (pliés : sans accent, minuscules) → clé interne. */
    private const ALIASES = [
        'name' => ['produit', 'nom', 'name', 'designation'],
        'category' => ['famille', 'categorie', 'category', 'rayon'],
        'storage' => ['conservation', 'stockage', 'temperature', 'temperature de conservation', 'storage'],
        'thawed_storage' => ['apres decongelation', 'conservation apres decongelation', 'decongele a'],
        'opened' => ['ouverture', 'ouvert', 'entame', 'apres ouverture'],
        'thawed' => ['decongelation', 'decongele'],
        'prepared' => ['preparation', 'prepare', 'fabrique', 'fabrication'],
        'cooked' => ['cuisson', 'cuit'],
        'vacuum' => ['sous vide', 'sousvide', 'mis sous vide', 'mise sous vide'],
        'frozen' => ['congelation', 'congele'],
        'witness' => ['repas temoin', 'temoin'],
        'received' => ['reception', 'recu'],
        'allergens' => ['allergenes', 'allergene', 'allergens'],
        'note' => ['mention', 'note', 'remarque'],
        'source' => ['source des durees', 'source', 'origine des durees'],
    ];

    private const ALLERGEN_WORDS = [
        'gluten' => 'gluten', 'ble' => 'gluten', 'crustaces' => 'crustaces', 'crustace' => 'crustaces', 'oeufs' => 'oeufs', 'oeuf' => 'oeufs',
        'poisson' => 'poisson', 'poissons' => 'poisson', 'arachides' => 'arachides', 'arachide' => 'arachides', 'soja' => 'soja', 'lait' => 'lait',
        'fruits a coque' => 'fruits_a_coque', 'fruit a coque' => 'fruits_a_coque', 'fruits_a_coque' => 'fruits_a_coque',
        'celeri' => 'celeri', 'moutarde' => 'moutarde', 'sesame' => 'sesame', 'sulfites' => 'sulfites', 'sulfite' => 'sulfites',
        'lupin' => 'lupin', 'mollusques' => 'mollusques', 'mollusque' => 'mollusques',
    ];

    private const ACTION_KEYS = ['opened', 'thawed', 'prepared', 'cooked', 'vacuum', 'frozen', 'witness', 'received'];

    /** Le fichier-modèle : en-têtes, puis trois exemples tirés du catalogue recommandé. Séparateur « ; », UTF-8 avec BOM (Excel FR). */
    public static function template(): string
    {
        $lines = [implode(';', array_values(self::COLUMNS))];
        foreach (['Oignons rouges émincés', 'Steak haché décongelé', 'Cheddar tranché (ouvert)'] as $name) {
            $row = collect(RecommendedDlcCatalog::all())->firstWhere('name', $name);
            if (! $row) continue;
            $cells = array_fill_keys(array_keys(self::COLUMNS), '');
            $cells['name'] = $row['name'];
            $cells['category'] = $row['category'];
            $cells['storage'] = $row['storage'];
            $cells['thawed_storage'] = (string) ($row['thawed_storage'] ?? '');
            foreach ($row['actions'] as $a) {
                $cells[$a['key']] = $a['value'].' '.($a['unit'] === DlcLabelProduct::UNIT_HOURS ? 'h' : 'j');
            }
            $cells['allergens'] = $row['allergens'] ? implode('|', array_map(fn ($c) => self::allergenLabel($c), $row['allergens'])) : ($row['no_allergens'] ? 'aucun' : '');
            $cells['source'] = 'Plan de maîtrise sanitaire';
            $lines[] = implode(';', array_map([self::class, 'csvCell'], $cells));
        }

        return "\xEF\xBB\xBF".implode("\r\n", $lines)."\r\n";
    }

    /**
     * @param  array<string,bool>  $existingNames  noms déjà présents (minuscules)
     * @return array{rows:list<array<string,mixed>>, summary:array{new:int, exists:int, invalid:int, total:int}, fatal:?string}
     */
    public static function parse(string $content, array $existingNames): array
    {
        $table = self::readTable($content);
        if ($table === null) {
            return self::fatal('Le fichier est vide.');
        }
        [$header, $lines] = $table;
        $map = self::mapHeader($header);
        if (! isset($map['name'])) {
            return self::fatal('Colonne « Produit » introuvable : utilisez le fichier-modèle (première ligne = en-têtes).');
        }
        if (count($lines) > self::MAX_ROWS) {
            return self::fatal('Trop de lignes ('.count($lines).') : '.self::MAX_ROWS.' au plus par fichier.');
        }

        $rows = [];
        $seen = [];
        foreach ($lines as $i => $cells) {
            $cell = fn (string $key) => isset($map[$key]) ? trim((string) ($cells[$map[$key]] ?? '')) : '';
            $name = $cell('name');
            if ($name === '' && implode('', array_map('trim', $cells)) === '') continue; // ligne vide
            $row = self::analyseRow($i + 2, $cell, $existingNames, $seen);
            $rows[] = $row;
            if ($row['status'] !== 'invalid') $seen[mb_strtolower($row['name'])] = true;
        }

        $summary = ['new' => 0, 'exists' => 0, 'invalid' => 0, 'total' => count($rows)];
        foreach ($rows as $r) $summary[$r['status']]++;

        return ['rows' => $rows, 'summary' => $summary, 'fatal' => null];
    }

    // ───────────────────────────── analyse d'une ligne ─────────────────────────────

    /** @param array<string,bool> $existing @param array<string,bool> $seen */
    private static function analyseRow(int $line, callable $cell, array $existing, array $seen): array
    {
        $errors = [];
        $name = $cell('name');
        $key = mb_strtolower($name);
        if ($name === '') $errors[] = 'Nom du produit manquant.';
        elseif (mb_strlen($name) > 120) $errors[] = 'Nom trop long (120 caractères au plus).';

        $storage = $cell('storage');
        if ($storage === '') $errors[] = 'Conservation obligatoire (elle est imprimée sur l’étiquette).';
        elseif (mb_strlen($storage) > 60) $errors[] = 'Conservation trop longue (60 caractères au plus).';

        $actions = [];
        foreach (self::ACTION_KEYS as $k) {
            $raw = $cell($k);
            if ($raw === '') continue;
            $d = self::parseDuration($raw);
            if ($d === null) {
                $errors[] = DlcLabelProduct::ACTION_LABELS[$k].' : « '.$raw.' » illisible — écrivez « 24 h » ou « 3 j ».';
                continue;
            }
            $cap = $d['unit'] === DlcLabelProduct::UNIT_HOURS ? 720 : 365;
            if ($d['value'] < 1 || $d['value'] > $cap) {
                $errors[] = DlcLabelProduct::ACTION_LABELS[$k].' : '.$raw.' hors limites (1 à '.$cap.($d['unit'] === DlcLabelProduct::UNIT_HOURS ? ' h' : ' j').').';
                continue;
            }
            $actions[] = ['key' => $k] + $d;
        }
        if ($actions === [] && ! array_filter($errors, fn ($e) => str_contains($e, 'illisible') || str_contains($e, 'hors limites'))) {
            $errors[] = 'Aucune durée : renseignez au moins un événement (Ouverture, Préparation…).';
        }

        $thawedStorage = $cell('thawed_storage');
        $hasThaw = collect($actions)->contains(fn ($a) => $a['key'] === 'thawed');
        if ($hasThaw && $thawedStorage === '') {
            $errors[] = 'Décongélation : indiquez « Après décongélation » (ex. 0 à +2 °C, ambiante pour un pain).';
        } elseif ($thawedStorage !== '' && ! DlcLabelProduct::isValidThawedStorage($thawedStorage)) {
            $errors[] = 'Après décongélation : du froid positif (ex. 0 à +2 °C) ou « Ambiante » — jamais le congélateur.';
        }

        [$allergens, $none, $allergenErrors] = self::parseAllergens($cell('allergens'));
        $errors = array_merge($errors, $allergenErrors);

        if ($errors === [] && isset($seen[$key])) $errors[] = 'Produit déjà présent plus haut dans le fichier : ignoré.';

        $status = $errors !== [] ? 'invalid' : (isset($existing[$key]) ? 'exists' : 'new');

        return [
            'line' => $line,
            'name' => $name,
            'status' => $status,
            'errors' => $errors,
            'data' => $status === 'new' ? [
                'name' => $name,
                'category' => $cell('category') !== '' ? mb_substr($cell('category'), 0, 60) : null,
                'storage' => $storage,
                'thawed_storage' => $hasThaw ? $thawedStorage : null,
                'actions' => $actions,
                'allergens' => $allergens,
                'no_allergens' => $none,
                'note' => $cell('note') !== '' ? mb_substr($cell('note'), 0, 80) : null,
                'duration_source' => $cell('source') !== '' ? mb_substr($cell('source'), 0, 80) : 'Import du restaurant',
            ] : null,
        ];
    }

    /** « 24 h », « 3 j », « 48 heures », « 2 jours » → {value, unit}. Un nombre seul est ambigu : refusé. @return array{value:int,unit:string}|null */
    public static function parseDuration(string $raw): ?array
    {
        $s = self::fold($raw);
        if (! preg_match('/^(\d{1,4})\s*(h|heure|heures|j|jour|jours)$/', $s, $m)) return null;

        return ['value' => (int) $m[1], 'unit' => $m[2][0] === 'h' ? DlcLabelProduct::UNIT_HOURS : DlcLabelProduct::UNIT_DAYS];
    }

    /** @return array{0:list<string>,1:bool,2:list<string>} [codes, aucun déclaré, erreurs] */
    private static function parseAllergens(string $raw): array
    {
        if (trim($raw) === '') return [[], false, []];
        $codes = [];
        $none = false;
        $errors = [];
        foreach (preg_split('/[|,\/+]/u', $raw) ?: [] as $token) {
            $t = self::fold($token);
            if ($t === '') continue;
            if (in_array($t, ['aucun', 'aucune', 'sans', 'non', 'aucun allergene', 'aucun des 14'], true)) { $none = true; continue; }
            if (! isset(self::ALLERGEN_WORDS[$t])) { $errors[] = 'Allergène inconnu : « '.trim($token).' ».'; continue; }
            $codes[self::ALLERGEN_WORDS[$t]] = true;
        }
        if ($codes !== [] && $none) {
            $errors[] = 'Allergènes ET « aucun » : choisissez l’un ou l’autre.';
        }

        return [array_keys($codes), $codes === [] && $none, $errors];
    }

    // ───────────────────────────── lecture du fichier ─────────────────────────────

    /** @return array{0:list<string>,1:list<list<string>>}|null en-tête + lignes */
    private static function readTable(string $content): ?array
    {
        $content = ltrim($content, "\xEF\xBB\xBF");
        // Excel français enregistre en Windows-1252 : on convertit plutôt que d'afficher des « � » dans les noms.
        if ($content !== '' && ! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        if (trim($content) === '') return null;

        $firstLine = strtok($content, "\n") ?: '';
        $delimiter = ';';
        $best = -1;
        foreach ([';' => ';', ',' => ',', "\t" => "\t"] as $d) {
            $n = substr_count($firstLine, $d);
            if ($n > $best) { $best = $n; $delimiter = $d; }
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        $rows = [];
        while (($cells = fgetcsv($stream, 0, $delimiter, '"', '\\')) !== false) {
            if ($cells === [null]) continue;
            $rows[] = array_map(fn ($c) => (string) $c, $cells);
        }
        fclose($stream);
        if ($rows === []) return null;

        return [array_shift($rows), $rows];
    }

    /** @param list<string> $header @return array<string,int> clé interne → index de colonne */
    private static function mapHeader(array $header): array
    {
        $map = [];
        foreach ($header as $i => $label) {
            $f = self::fold($label);
            foreach (self::ALIASES as $key => $words) {
                if (! isset($map[$key]) && in_array($f, $words, true)) { $map[$key] = $i; break; }
            }
        }

        return $map;
    }

    private static function fold(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = str_replace(['œ', 'æ'], ['oe', 'ae'], $s);

        return trim((string) preg_replace('/[^a-z0-9_]+/', ' ', Str::ascii($s)));
    }

    private static function allergenLabel(string $code): string
    {
        return ['gluten' => 'Gluten', 'crustaces' => 'Crustacés', 'oeufs' => 'Œufs', 'poisson' => 'Poisson', 'arachides' => 'Arachides', 'soja' => 'Soja',
            'lait' => 'Lait', 'fruits_a_coque' => 'Fruits à coque', 'celeri' => 'Céleri', 'moutarde' => 'Moutarde', 'sesame' => 'Sésame',
            'sulfites' => 'Sulfites', 'lupin' => 'Lupin', 'mollusques' => 'Mollusques'][$code] ?? $code;
    }

    private static function csvCell(string $v): string
    {
        return preg_match('/[;"\n\r]/', $v) ? '"'.str_replace('"', '""', $v).'"' : $v;
    }

    /** @return array{rows:list<mixed>, summary:array{new:int,exists:int,invalid:int,total:int}, fatal:string} */
    private static function fatal(string $message): array
    {
        return ['rows' => [], 'summary' => ['new' => 0, 'exists' => 0, 'invalid' => 0, 'total' => 0], 'fatal' => $message];
    }
}
