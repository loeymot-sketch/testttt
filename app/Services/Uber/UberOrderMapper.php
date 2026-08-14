<?php

namespace App\Services\Uber;

use App\Models\Item;
use Illuminate\Support\Facades\DB;

/**
 * [UBER-EATS 2026-07-01] Mappe une commande Uber Eats → structure interne réutilisable
 * (item_id catalogue + composition_snapshot lines/extras/addons). Réutilise TOUT le reste
 * (ticket ESC/POS, KDS symbolique, caisse) car ils lisent le composition_snapshot.
 *
 * NB : les PRIX Uber sont scellés (déjà encaissés côté Uber) — on N'appelle PAS PricingService
 * pour recalculer ; on garde les montants Uber tels quels (canal séparé).
 */
class UberOrderMapper
{
    /**
     * @param  array  $uberOrder  Payload complet de GET /v1/eats/orders/{id}
     * @return array{queue_number:?string, display_id:?string, items:array<int,array>, total:float, customer_name:?string, order_type:string}
     */
    public function map(array $uberOrder): array
    {
        $cart = $uberOrder['cart'] ?? $uberOrder['eater_cart'] ?? [];
        $lines = $cart['items'] ?? $uberOrder['items'] ?? [];

        $items = [];
        foreach ($lines as $line) {
            $items[] = $this->mapLine($line);
        }

        return [
            'display_id'   => (string) ($uberOrder['display_id'] ?? $uberOrder['id'] ?? ''),
            'queue_number' => $this->shortDisplay($uberOrder),
            'items'        => $items,
            'total'        => (float) (($uberOrder['payment']['charges']['total']['amount'] ?? 0) / 100) ?: (float) ($uberOrder['total'] ?? 0),
            // [AUDIT-5SYS 2026-08-12 P1] Clé alignée sur celle lue par UberOrderIngestor::orderAttributes()
            // (`customer_name`) — avant, cette clé s'appelait `raw_customer` et n'était consommée nulle part :
            // le webhook créait sa commande sans jamais poser pos_customer_name, seule la capture photo le faisait.
            'customer_name' => (string) ($uberOrder['eater']['first_name'] ?? $uberOrder['customer']['name'] ?? ''),
            // [AUDIT-5SYS 2026-08-12 P1] Pas de champ Uber Orders API vérifié en LIVE pour distinguer
            // retrait/livraison (aucun accès production à ce jour) → on garde le comportement historique
            // (toujours DELIVERY, cf. UberOrderIngestor::orderAttributes) plutôt que d'inventer un nom de
            // champ non confirmé. À COMPLÉTER dès qu'un vrai payload Uber sera inspectable.
            'order_type' => 'delivery',
        ];
    }

    private function mapLine(array $line): array
    {
        $title = (string) ($line['title'] ?? $line['name'] ?? '');
        $uberId = (string) ($line['id'] ?? $line['external_id'] ?? '');
        $qty = (int) ($line['quantity'] ?? 1);

        $itemId = $this->resolveItemId($title, $uberId);

        // [GO-LIVE UBER 2026-07-04] Dégradation gracieuse : un titre non mappé renvoyait item_id
        // NULL → violation NOT NULL à l'insert → rollback TOTAL → 5×503 → 200 give-up → la
        // commande PAYÉE était PERDUE (invisible cuisine, Uber ne rejoue plus). On ancre la ligne
        // sur un item placeholder TECHNIQUE (inactif, hors canaux — pas un produit menu, §3bis
        // respecté) ; le titre Uber réel reste visible cuisine via instruction + snapshot.uber_title,
        // et les montants Uber scellés sont conservés. Une commande payée ne se perd JAMAIS.
        $unmapped = ($itemId === null);
        if ($unmapped) {
            $itemId = $this->fallbackItemId();
        }

        // Modificateurs Uber (sauces / suppléments / options) → extras du snapshot.
        // [FIX CUISSON-UBER 2026-08-14 owner] Un groupe dont le TITRE identifie un choix de
        // viande alimente AUSSI `lines` (attribute_name/variation_name — le format lu par
        // meatPortionsForItem()/KitchenTicketSymbolicFormatter::meatSymbol, jumeau JS/PHP).
        // Avant ce fix, `lines` restait TOUJOURS vide pour Uber (toute la viande finissait en
        // extra, un format que le bandeau de cuisson ne lit jamais) : une commande Uber avec
        // viande au choix (tacos, sandwich...) ne comptait JAMAIS dans le total cuisson, alors
        // que la même commande prise borne/caisse/web comptait normalement. Additif strict :
        // `extras` reste peuplé à l'identique (ticket/affichage existants inchangés).
        $extras = [];
        $lines = [];
        foreach (($line['selected_modifier_groups'] ?? $line['modifier_groups'] ?? []) as $group) {
            $groupTitle = (string) ($group['title'] ?? $group['name'] ?? '');
            $isMeatGroup = (bool) preg_match('/viande|meat/i', $this->norm($groupTitle));
            foreach (($group['selected_items'] ?? $group['items'] ?? []) as $mod) {
                $modTitle = (string) ($mod['title'] ?? $mod['name'] ?? '');
                $extras[] = [
                    'extra_name' => $modTitle,
                    'quantity'   => (int) ($mod['quantity'] ?? 1),
                    'line_total' => (float) (($mod['price']['amount'] ?? 0) / 100),
                ];
                if ($isMeatGroup && $modTitle !== '') {
                    $lines[] = ['attribute_name' => 'Viande', 'variation_name' => $modTitle];
                }
            }
        }

        return [
            'item_id'    => $itemId,
            'name'       => $title,
            'quantity'   => max(1, $qty),
            'unit_price' => (float) (($line['price']['unit_price']['amount'] ?? 0) / 100),
            'total'      => (float) (($line['price']['total_price']['amount'] ?? 0) / 100),
            'instruction'=> trim(($unmapped ? '[UBER NON MAPPÉ: ' . $title . '] ' : '') . $this->safeNote((string) ($line['special_instructions'] ?? ''))),
            'composition_snapshot' => [
                'schema_version' => 1,
                'source'         => 'uber_eats',
                'lines'          => $lines,  // viande détectée par titre de groupe (cf. ci-dessus)
                'extras'         => $extras, // sauces/suppléments/options — inchangé
                'addons'         => [],
                'uber_title'     => $title,
            ],
        ];
    }

    /**
     * [W6-ADV B-4 2026-07-06] Note client Uber → canal SÛR cuisine. Les sanitizers
     * jumeaux (cleanInstruction PHP / sanitizeKdsInstruction JS) droppent toute ligne
     * 100% MAJUSCULES (écho du nom produit écrit par le pos-wizard) : une vraie note
     * Uber en capitales — « SANS OIGNONS SVP », fréquent — serait SILENCIEUSEMENT
     * perdue en cuisine (latent food-safety). Le wizard caisse bracket ses notes
     * libres ([...]) et le sanitize les PRÉSERVE → même pattern pour la note Uber
     * brute (sans double-bracket si Uber/merchant l'a déjà encadrée).
     */
    private function safeNote(string $note): string
    {
        $note = trim($note);
        if ($note === '' || str_starts_with($note, '[')) {
            return $note;
        }

        return '['.$note.']';
    }

    /** Résout l'item_id catalogue : map par titre, par id Uber, puis fallback match nom DB. */
    public function resolveItemId(string $title, string $uberId = ''): ?int
    {
        $mapTitle = (array) config('uber_menu_map.by_title', []);
        $mapUberId = (array) config('uber_menu_map.by_uber_id', []);

        if ($uberId !== '' && isset($mapUberId[$uberId])) {
            return (int) $mapUberId[$uberId];
        }
        $n = $this->norm($title);
        if (isset($mapTitle[$n])) {
            return (int) $mapTitle[$n];
        }
        // Fallback : match par nom sur le catalogue actif (best-effort).
        $item = Item::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($title))])
            ->first(['id']);
        if ($item) {
            return (int) $item->id;
        }
        $item = Item::query()->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower(trim($title)) . '%'])->first(['id']);
        if ($item) {
            return (int) $item->id;
        }

        // [GO-LIVE UBER 2026-07-04] Passage INSENSIBLE AUX ACCENTS/CASSE sur le catalogue actif :
        // les titres Uber perdent souvent les accents (« Supreme » vs « Suprême », « Mega » vs
        // « Méga » — la moitié de la carte Le Cayenne est accentuée) → le match SQL exact ratait
        // et la ligne tombait inutilement sur le placeholder. norm() (translit ASCII + lower)
        // des DEUX côtés rend la carte vide (uber_menu_map) fonctionnelle pour toute la carte
        // canonique aux noms identiques. Catalogue ≤ ~60 items → scan en mémoire trivial.
        if ($n !== '') {
            foreach (Item::query()->where('status', \App\Enums\Status::ACTIVE)->get(['id', 'name']) as $candidate) {
                if ($this->norm((string) $candidate->name) === $n) {
                    return (int) $candidate->id;
                }
            }
        }

        return null;
    }

    /**
     * [GO-LIVE UBER 2026-07-04] Item placeholder TECHNIQUE pour les lignes non mappées :
     * inactif + hors canaux + catégorie technique inactive → n'apparaît JAMAIS sur borne/POS
     * (pas un produit menu — §3bis anti-invention respecté). Sert uniquement d'ancre FK pour
     * que la commande PAYÉE soit toujours créée ; le vrai titre vit dans instruction/snapshot.
     * Configurable via uber.fallback_item_id si l'owner préfère un item dédié existant.
     */
    public function fallbackItemId(): int
    {
        $configured = (int) config('uber.fallback_item_id', 0);
        if ($configured > 0 && Item::query()->whereKey($configured)->exists()) {
            return $configured;
        }

        $category = \App\Models\ItemCategory::query()->firstOrCreate(
            ['slug' => 'uber-technique'],
            ['name' => 'Uber (technique)', 'status' => \App\Enums\Status::INACTIVE, 'channels' => []]
        );

        $item = Item::query()->firstOrCreate(
            ['slug' => 'uber-article-non-mappe'],
            [
                'name'             => 'Article Uber (non mappé)',
                'item_category_id' => $category->id,
                'price'            => 0,
                'status'           => \App\Enums\Status::INACTIVE,
                'channels'         => [],
                'is_available'     => false,
            ]
        );

        return (int) $item->id;
    }

    private function shortDisplay(array $uberOrder): ?string
    {
        $d = (string) ($uberOrder['display_id'] ?? '');
        return $d !== '' ? ('U' . mb_substr($d, -4)) : null;
    }

    private function norm(string $s): string
    {
        // [GO-LIVE UBER 2026-07-04] iconv//TRANSLIT est FRAGILE PAR PLATEFORME : la libiconv
        // macOS rend « Suprême »→« Supr^eme » et « Méga »→« M'ega » (accents → ^ ') → les clés
        // normalisées ne matchaient jamais selon l'OS. Str::ascii() (foldering Laravel) est
        // déterministe cross-plateforme ; on strip aussi la ponctuation résiduelle.
        $ascii = \Illuminate\Support\Str::ascii($s);
        return trim(preg_replace('/[^a-z0-9 ]+/', '', mb_strtolower($ascii)) ?? '');
    }
}
