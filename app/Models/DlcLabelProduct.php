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
     * Un produit décongelé se garde au FROID POSITIF (GBPH restauration § 2.6 : « ne doit en aucun cas être recongelé ») :
     * si sa fiche dit « ≤ −18 °C » (ou ne dit rien, ou « ambiante »), l'étiquette de décongélation imprime ceci.
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
        'note',
        'ask_original_dlc',
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

    /** La conservation à IMPRIMER pour cet événement : un produit décongelé ne retourne jamais au congélateur. */
    public function storageFor(string $actionKey): ?string
    {
        $storage = $this->storage !== null && trim((string) $this->storage) !== '' ? trim((string) $this->storage) : null;
        if ($actionKey === 'thawed' && ($storage === null || self::isFrozenOrAmbient($storage))) {
            return self::THAWED_STORAGE;
        }

        return $storage;
    }

    /** « ≤ −18 °C », « -18 », « congélateur », « Ambiante » : tout ce qui n'est pas du froid positif. */
    public static function isFrozenOrAmbient(string $storage): bool
    {
        return (bool) preg_match('/[−-]\s*\d|cong[eé]l|ambiant/iu', $storage);
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
