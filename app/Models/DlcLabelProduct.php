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

    /** Événements qui ouvrent une DLC secondaire (libellés français côté écran). */
    public const ACTIONS = ['opened', 'thawed', 'prepared', 'cooked', 'vacuum'];

    /** Libellés des événements, pour les messages du serveur (les mêmes qu'à l'écran). */
    public const ACTION_LABELS = [
        'opened' => 'Ouverture', 'thawed' => 'Décongélation', 'prepared' => 'Préparation', 'cooked' => 'Cuisson', 'vacuum' => 'Mise sous vide',
    ];

    /**
     * Un produit décongelé ne retourne jamais au congélateur (GBPH restauration § 2.6 : « ne doit en aucun cas être
     * recongelé »). Sa conservation se choisit sur la fiche (`thawed_storage` : +2 °C pour une viande hachée, 0 à +2 °C pour
     * un poisson — arrêté du 21/12/2009, annexe I — ambiante pour un pain). Ceci n'est que le REPLI d'une fiche ancienne
     * qui ne l'a pas encore renseignée : jamais « ≤ −18 °C » sur une étiquette de décongélation.
     */
    public const THAWED_STORAGE = '0 à +4 °C';

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
     * La conservation à IMPRIMER pour cet événement. Décongélation : celle choisie pour l'après‑décongélation, sinon la
     * conservation habituelle si c'est du froid positif, sinon le repli (jamais le congélateur).
     */
    public function storageFor(string $actionKey): ?string
    {
        $clean = fn ($v) => $v !== null && trim((string) $v) !== '' ? trim((string) $v) : null;
        $storage = $clean($this->storage);
        if ($actionKey === 'thawed') {
            if ($thawed = $clean($this->thawed_storage)) return $thawed;
            if ($storage === null || ! self::isChilled($storage)) return self::THAWED_STORAGE;
        }

        return $storage;
    }

    /** La fiche prévoit la décongélation mais sa conservation habituelle n'est pas du froid positif : il faut la préciser. */
    public static function needsThawedStorage(?string $storage, array $actions): bool
    {
        $thawing = collect($actions)->contains(fn ($a) => is_array($a) && ($a['key'] ?? null) === 'thawed');

        return $thawing && ($storage === null || trim($storage) === '' || ! self::isChilled($storage));
    }

    /**
     * Froid POSITIF reconnu (liste blanche, miroir de dlcLabelBuilder.js) : une température, aucune valeur négative
     * (« −18 », « –18 », « -18 » en tête ou après une espace, « ≤ », « < », « ( » — « 2-4 °C » reste une plage positive),
     * ni congélateur, surgelé, froid négatif ou ambiante.
     */
    public static function isChilled(string $storage): bool
    {
        if (! preg_match('/\d/', $storage)) return false;
        if (preg_match('/(^|[\s≤<(])[−–-]\s*\d/u', $storage)) return false;

        return ! preg_match('/cong[eé]l|surgel|n[ée]gati|ambiant/iu', $storage);
    }

    /** L'inverse : tout ce qui n'est pas du froid positif reconnu. */
    public static function isFrozenOrAmbient(string $storage): bool
    {
        return ! self::isChilled($storage);
    }

    /**
     * Date limite pour un événement à `$at`. Heures : ajout exact ; jours : même heure, N jours plus tard.
     */
    public function expiresFrom(CarbonInterface $at, ?array $action = null): CarbonInterface
    {
        $action = $action ?? $this->action(null);
        $expires = $at->copy();

        return $action['unit'] === self::UNIT_HOURS
            ? $expires->addHours($action['value'])
            : $expires->addDays($action['value']);
    }
}
