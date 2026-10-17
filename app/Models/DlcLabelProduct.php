<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * [ETIQUETTES 2026-10-16] Un produit à étiqueter pour l'hygiène (oignons, tomates, olives…), configuré comme dans les
 * logiciels HACCP du marché : plusieurs ÉVÉNEMENTS possibles (ouverture, décongélation, préparation, cuisson, mise sous
 * vide), chacun avec SA durée ; une famille, des allergènes, une température de conservation, une mention libre.
 *
 * Les durées sont celles du restaurant (son plan de maîtrise sanitaire), jamais les nôtres.
 */
class DlcLabelProduct extends Model
{
    use SoftDeletes;

    public const UNIT_HOURS = 'hours';
    public const UNIT_DAYS = 'days';
    public const UNITS = [self::UNIT_HOURS, self::UNIT_DAYS];

    /**
     * Événements qui ouvrent une DLC secondaire (libellés français côté écran). Les huit états de la restauration :
     * ouverture, décongélation, préparation (« fabriqué »), cuisson, mise sous vide, congélation, repas témoin, réception.
     */
    public const ACTIONS = ['opened', 'thawed', 'prepared', 'cooked', 'vacuum', 'frozen', 'witness', 'received'];

    /** Libellés des événements, pour les messages du serveur (les mêmes qu'à l'écran). */
    public const ACTION_LABELS = [
        'opened' => 'Ouverture', 'thawed' => 'Décongélation', 'prepared' => 'Préparation', 'cooked' => 'Cuisson', 'vacuum' => 'Mise sous vide',
        'frozen' => 'Congélation', 'witness' => 'Repas témoin', 'received' => 'Réception',
    ];

    /**
     * Conservation imposée par l'ÉVÉNEMENT, pas par la fiche : un produit congelé est au congélateur (−18 °C ou moins,
     * même seuil que les relevés de température), un repas témoin se garde entre 0 et +3 °C (arrêté du 21/12/2009, art. 31 :
     * conservé au moins cinq jours après la dernière présentation au consommateur). Valeurs de départ ; la durée, elle,
     * reste celle du restaurant.
     */
    /** Source des durées d'un produit installé depuis le catalogue recommandé : une valeur de DÉPART, à valider par le responsable. */
    public const SOURCE_STARTING = 'Valeur de départ Cayenne';
    /** Source d'une fiche dont le restaurant a changé les durées lui-même. */
    public const SOURCE_OWN = 'Saisie du restaurant';
    /** Durées importées d'un fichier SANS source indiquée : à valider comme les valeurs de départ. */
    public const SOURCE_IMPORTED = 'Import du restaurant';

    public const FROZEN_STORAGE = '≤ −18 °C';
    public const WITNESS_STORAGE = '0 à +3 °C';
    /** Repas témoin : au moins cinq jours (120 h) — arrêté du 21/12/2009, art. 31. */
    public const WITNESS_MIN_HOURS = 120;

    /**
     * Un produit décongelé ne retourne jamais au congélateur (GBPH restauration § 2.6 : « ne doit en aucun cas être
     * recongelé »). Sa conservation APRÈS décongélation se choisit sur la fiche (`thawed_storage` : 0 à +2 °C pour une
     * viande hachée ou un poisson — arrêté du 21/12/2009, annexe I — ambiante pour un pain) ; elle est OBLIGATOIRE dès que
     * la fiche prévoit la décongélation. Aucune valeur de repli : une valeur unique imposée à tous serait fausse pour
     * certains produits (revue HACCP, 3e tour).
     */
    /** Froid positif : au-delà de +8 °C ce n'est plus de la réfrigération (produits frais : 0 à +8 °C au plus). */
    public const CHILLED_MAX_C = 8;

    /** Les 14 allergènes à déclaration obligatoire (règlement UE 1169/2011), codes de l'application. */
    public const ALLERGENS = [
        'gluten', 'crustaces', 'oeufs', 'poisson', 'arachides', 'soja', 'lait',
        'fruits_a_coque', 'celeri', 'moutarde', 'sesame', 'sulfites', 'lupin', 'mollusques',
    ];

    protected $table = 'dlc_label_products';

    protected $fillable = [
        'branch_id',
        'name',
        'category',
        'shelf_life_value',
        'shelf_life_unit',
        'actions',
        'allergens',
        'storage',
        'thawed_storage',
        'no_allergens',
        'note',
        'ask_original_dlc',
        'needs_parent',
        'durations_checked_at',
        'durations_checked_by',
        'duration_source',
        'print_operator',
        'default_copies',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'id' => 'integer',
        'branch_id' => 'integer',
        'shelf_life_value' => 'integer',
        'actions' => 'array',
        'allergens' => 'array',
        'ask_original_dlc' => 'boolean',
        'needs_parent' => 'boolean',
        'durations_checked_at' => 'datetime',
        'durations_checked_by' => 'integer',
        'no_allergens' => 'boolean',
        'print_operator' => 'boolean',
        'default_copies' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    /**
     * Clé d'unicité d'un NOM de produit : sans casse, sans accent, sans espace insécable ni espaces multiples, en Unicode normalisé (un « é » saisi
     * sur Mac et le même « é » d'Excel sont deux suites d'octets différentes). Même idée que l'égalité insensible aux accents de MySQL, appliquée
     * partout où l'on décide « ce produit existe déjà » (import, installation du catalogue).
     */
    public static function nameKey(?string $name): string
    {
        $s = (string) $name;
        if (class_exists(\Normalizer::class)) $s = \Normalizer::normalize($s, \Normalizer::FORM_C) ?: $s;
        $s = mb_strtolower(str_replace(['œ', 'æ'], ['oe', 'ae'], mb_strtolower($s)));
        $s = \Illuminate\Support\Str::ascii($s);

        return trim((string) preg_replace('/[\s\x{00A0}\x{202F}\x{2007}]+/u', ' ', $s));
    }

    /**
     * Où en est la validation des durées : `validated` (le responsable les a confirmées : qui et quand sont gardés),
     * `starting` (valeur de départ du catalogue, jamais confirmée — À VALIDER), `own` (saisie par le restaurant).
     */
    public function durationReview(): string
    {
        if ($this->durations_checked_at !== null) return 'validated';

        return in_array($this->duration_source, [self::SOURCE_STARTING, self::SOURCE_IMPORTED], true) ? 'starting' : 'own';
    }

    /**
     * Les événements configurés, toujours au moins un. Un produit créé avant la configuration détaillée n'a que sa durée
     * historique : elle devient l'événement « ouverture ».
     *
     * @return array<int, array{key:string, value:int, unit:string}>
     */
    public function actionList(): array
    {
        $list = [];
        foreach ((array) ($this->actions ?? []) as $a) {
            if (! is_array($a) || ! in_array($a['key'] ?? null, self::ACTIONS, true)) continue;
            $value = (int) ($a['value'] ?? 0);
            $unit = in_array($a['unit'] ?? null, self::UNITS, true) ? $a['unit'] : self::UNIT_DAYS;
            if ($value < 1) continue;
            $list[] = ['key' => $a['key'], 'value' => $value, 'unit' => $unit];
        }
        if ($list === []) {
            $list[] = ['key' => 'opened', 'value' => (int) $this->shelf_life_value, 'unit' => (string) $this->shelf_life_unit];
        }

        return $list;
    }

    /** L'événement demandé, ou celui par défaut (le premier) quand aucun n'est demandé. */
    public function action(?string $key): array
    {
        $list = $this->actionList();
        foreach ($list as $a) {
            if ($a['key'] === $key) return $a;
        }

        return $list[0];
    }

    /** L'événement est-il prévu par la fiche ? (un événement non prévu n'est jamais remplacé en silence par un autre) */
    public function hasAction(string $key): bool
    {
        foreach ($this->actionList() as $a) {
            if ($a['key'] === $key) return true;
        }

        return false;
    }

    /**
     * La conservation à IMPRIMER pour cet événement. Décongélation : celle choisie pour l'après‑décongélation, et rien
     * d'autre — sans elle la fiche est à compléter (aucune valeur de repli).
     */
    public function storageFor(string $actionKey): ?string
    {
        $clean = fn ($v) => $v !== null && trim((string) $v) !== '' ? trim((string) $v) : null;
        // décongélation : la conservation APRÈS décongélation de la fiche, et rien d'autre (aucun repli)
        if ($actionKey === 'thawed') return $clean($this->thawed_storage);
        if ($actionKey === 'frozen') return self::FROZEN_STORAGE;
        if ($actionKey === 'witness') return self::WITNESS_STORAGE;

        return $clean($this->storage);
    }

    /** La fiche prévoit la décongélation : sa conservation après décongélation doit être dite. */
    public static function needsThawedStorage(?string $storage, array $actions): bool
    {
        return collect($actions)->contains(fn ($a) => is_array($a) && ($a['key'] ?? null) === 'thawed');
    }

    /** Valeur acceptée pour l'après‑décongélation : du froid positif reconnu, ou « Ambiante » (un pain). */
    public static function isValidThawedStorage(string $storage): bool
    {
        return self::isChilled($storage) || (bool) preg_match('/^\s*ambiante\s*$/iu', $storage);
    }

    /**
     * Froid POSITIF reconnu (liste blanche stricte, miroir de dlcLabelBuilder.js) : au moins une température écrite
     * (« °C » ou « +N »), AUCUN nombre précédé d'un signe moins — quel que soit ce qui précède (« T°-18 », « max-18 »,
     * « 0/-18 », tiret demi-cadratin, cadratin, insécable) ; seul un tiret ENTRE deux chiffres est une plage (« 2-4 °C ») —,
     * aucune valeur au-dessus de +8 °C, et ni congélateur, ni surgelé, ni froid négatif, ni ambiante.
     */
    public static function isChilled(string $storage): bool
    {
        if (! preg_match('/°\s*C|\+\s*\d/iu', $storage)) return false;
        if (! preg_match_all('/(?<!\d)([−–—‑-])?\s*(\d+)/u', $storage, $m, PREG_SET_ORDER)) return false;
        foreach ($m as $x) {
            if ($x[1] !== '' || (int) $x[2] > self::CHILLED_MAX_C) return false;
        }

        return ! preg_match('/cong[eé]l|surgel|n[ée]gati|ambiant/iu', $storage);
    }

    /** L'inverse : tout ce qui n'est pas du froid positif reconnu. */
    public static function isFrozenOrAmbient(string $storage): bool
    {
        return ! self::isChilled($storage);
    }

    /**
     * Date limite pour un événement à `$at`. Heures : N heures RÉELLES écoulées ; jours : même heure murale, N jours plus tard.
     *
     * « 24 heures » = 24 heures exactes, y compris la nuit du changement d'heure : `addHours` (Carbon 2) compte en heures MURALES, ce qui
     * donnait 25 h de conservation la nuit où l'on recule l'horloge (et 23 h celle où on l'avance) — une DLC d'une heure trop longue sur
     * le papier. `addRealHours` compte le temps qui passe vraiment ; l'aperçu de la tablette (millisecondes) disait déjà la même chose.
     */
    public function expiresFrom(CarbonInterface $at, ?array $action = null): CarbonInterface
    {
        $action = $action ?? $this->action(null);
        $expires = $at->copy();

        return $action['unit'] === self::UNIT_HOURS
            ? $expires->addRealHours($action['value'])
            : $expires->addDays($action['value']);
    }
}
