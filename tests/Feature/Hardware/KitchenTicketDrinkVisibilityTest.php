<?php

namespace Tests\Feature\Hardware;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Hardware\KitchenTicketSymbolicFormatter;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Database\Seeders\DrinksUpdate20260705Seeder;
use Database\Seeders\RestoreLeCayenneDessertsAndDrinksSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [W3-FIX-C 2026-07-06] Boissons VISIBLES sur le ticket cuisine (owner : le cuisinier
 * prépare aussi les boissons). 3 chemins réels prouvés en DB :
 *  1. item boisson standalone (#5456 « Coca-Cola 33cl ») → nom COMPLET (plus « 1 x COC »)
 *  2. addon role=drink (#5171 « Boisson Seule » sur Bol Riz) → ligne « 1 Boisson Seule »
 *  3. addon role=menu_boisson (formule borne) → boisson listée sous MENU
 * + width-safe 32 col (58mm). Détection isDrinkItem = jumeau EXACT du JS
 * categorize()==='drink' (kdsCustomization.js, garde dessert-avant-drink).
 *
 * [W6-ADV B-1 2026-07-06] Data-driven sur LA LISTE RÉELLE DB : les seeders canoniques
 * (RestoreLeCayenneDessertsAndDrinksSeeder + DrinksUpdate20260705Seeder) recréent les
 * 15 boissons actives → chacune DOIT sortir en nom complet (l'ancien filet regex en
 * ratait 8/15 : Hawaï — régression du renommage —, Oasis, Orangina, Capri-Sun, Tropico,
 * Ice Tea, Fuze Tea, Perrier → « 1 x HAW »). Le set DB est verrouillé contre
 * tests/fixtures/drinks_active_db.json (jumeau JS kdsSymbolicDrinks.spec.js) : une 16e
 * boisson seedée casse ce test tant que la fixture + le filet ne sont pas re-vérifiés.
 *
 * [W6-ADV C-P1-1 2026-07-06] Boisson de FORMULE BORNE (« Formule : … (Hawaï 33cl). … »
 * sur une seule ligne, shape réel #5533) : extraite en « BOISSON: X » au lieu de mourir
 * avec la ligne droppée par le sanitizer.
 */
class KitchenTicketDrinkVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function renderer(): OrderReceiptEscPosRenderer
    {
        return app(OrderReceiptEscPosRenderer::class);
    }

    /** Seed le catalogue RÉEL boissons+desserts via les seeders canoniques (SSOT DB). */
    private function seedRealDrinkCatalog(): void
    {
        foreach ([['Desserts', 'desserts'], ['Boissons', 'boissons']] as [$name, $slug]) {
            ItemCategory::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'status' => Status::ACTIVE, 'channels' => []]
            );
        }
        $this->seed([
            RestoreLeCayenneDessertsAndDrinksSeeder::class,
            DrinksUpdate20260705Seeder::class,
        ]);
    }

    /** @return array{drinks: list<string>, desserts: list<string>} */
    private function fixtureNames(): array
    {
        $fx = json_decode((string) file_get_contents(base_path('tests/fixtures/drinks_active_db.json')), true);

        return ['drinks' => $fx['drinks'] ?? [], 'desserts' => $fx['desserts'] ?? []];
    }

    /** @return array<int,array{text:string,width:int}> décode ESC/POS avec la double-largeur (GS ! n) */
    private function decodeLines(string $bytes): array
    {
        $lines = [];
        $cur = '';
        $wmul = 1;
        $i = 0;
        $len = strlen($bytes);
        while ($i < $len) {
            $c = $bytes[$i];
            if ($c === "\x1D" && $i + 2 < $len && $bytes[$i + 1] === '!') {
                $n = ord($bytes[$i + 2]);
                $wmul = (($n >> 4) & 0x07) + 1;
                $i += 3;

                continue;
            }
            if ($c === "\x1D" && $i + 1 < $len && $bytes[$i + 1] === 'V') {
                if ($cur !== '') {
                    $lines[] = [$cur, $wmul];
                    $cur = '';
                }
                $i += 2;
                if ($i < $len) {
                    $i++;
                }

                continue;
            }
            if ($c === "\x1B" && $i + 1 < $len && in_array($bytes[$i + 1], ['a', 'E', 't', 'd', '!'], true)) {
                $i += 3;

                continue;
            }
            if ($c === "\x1B" && $i + 1 < $len && $bytes[$i + 1] === '@') {
                $i += 2;

                continue;
            }
            if ($c === "\x0A") {
                $lines[] = [$cur, $wmul];
                $cur = '';
                $i++;

                continue;
            }
            if (ord($c) < 0x20) {
                $i++;

                continue;
            }
            $cur .= $c;
            $i++;
        }
        if ($cur !== '') {
            $lines[] = [$cur, $wmul];
        }

        $out = [];
        foreach ($lines as [$ln, $wm]) {
            $txt = (string) iconv('CP858', 'UTF-8//IGNORE', $ln);
            $out[] = ['text' => $txt, 'width' => mb_strlen($txt) * $wm];
        }

        return $out;
    }

    private function decodedText(string $bytes): string
    {
        return implode("\n", array_map(fn ($l) => $l['text'], $this->decodeLines($bytes)));
    }

    /** @param array<int,array{name:string,snapshot:array,instruction?:string}> $items */
    private function makeOrder(array $items): Order
    {
        $branch = (new Branch)->forceFill([
            'name' => 'Le Cayenne (principal)',
            'address' => '437 Rue Élie Gruyelle, 62110 Hénin-Beaumont',
            'phone' => '+33365678291',
        ]);
        $orderItems = collect();
        foreach ($items as $it) {
            $oi = (new OrderItem)->forceFill([
                'quantity' => $it['quantity'] ?? 1,
                'total_price' => 8.90,
                'composition_snapshot' => $it['snapshot'],
                'instruction' => $it['instruction'] ?? '',
            ]);
            $oi->name = $it['name'];
            $orderItems->push($oi);
        }
        $order = (new Order)->forceFill([
            'order_serial_no' => 'E2E-173832',
            'queue_number' => 'A0055',
            'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'subtotal' => 8.90,
            'total' => 8.90,
            'order_datetime' => '2026-07-05 23:29:00',
        ]);
        $order->setRelation('orderItems', $orderItems);
        $order->setRelation('branch', $branch);

        return $order;
    }

    private const EMPTY_SNAP = ['lines' => [], 'extras' => [], 'addons' => []];

    public function test_standalone_drink_item_prints_full_name_not_code(): void
    {
        // shape réel #5456 : « Coca-Cola 33cl » standalone (snapshot vide)
        $order = $this->makeOrder([['name' => 'Coca-Cola 33cl', 'snapshot' => self::EMPTY_SNAP]]);
        $txt = $this->decodedText($this->renderer()->renderKitchenTicket($order));

        $this->assertStringContainsString('Coca-Cola 33cl', $txt, 'la boisson doit sortir en NOM COMPLET');
        $this->assertDoesNotMatchRegularExpression('/1 x COC\b/', $txt, 'plus de code 3 lettres cryptique');
    }

    /**
     * [W6-ADV B-1] LA LISTE RÉELLE DB (seeders canoniques) : les 15 boissons actives
     * rendent leur NOM COMPLET sur le ticket cuisine. Data-driven : une 16e boisson
     * qui sortirait du filet regex fait échouer ce test.
     */
    public function test_all_active_db_drinks_print_full_name_on_kitchen_ticket(): void
    {
        $this->seedRealDrinkCatalog();
        $f = app(KitchenTicketSymbolicFormatter::class);

        $cat = ItemCategory::query()->where('slug', 'boissons')->firstOrFail();
        $dbNames = Item::query()
            ->where('item_category_id', $cat->id)
            ->where('status', Status::ACTIVE)
            ->pluck('name')
            ->all();

        // Verrou de fraîcheur : le set DB seedé == la fixture jumelle JS (15 noms réels
        // dumpés le 2026-07-06). Si un seeder ajoute/renomme une boisson → régénérer
        // tests/fixtures/drinks_active_db.json (le spec JS boucle dessus).
        $fixture = $this->fixtureNames();
        sort($dbNames);
        $fxDrinks = $fixture['drinks'];
        sort($fxDrinks);
        $this->assertSame($fxDrinks, $dbNames, 'DB boissons ≠ fixture drinks_active_db.json — régénérer la fixture + re-vérifier le filet isDrinkItem/isDrinkName (jumeau JS).');
        $this->assertGreaterThanOrEqual(15, count($dbNames), 'le catalogue canonique porte 15 boissons actives');

        foreach ($dbNames as $name) {
            $this->assertTrue($f->isDrinkItem($name), "isDrinkItem('$name') doit être TRUE (boisson active DB)");

            $order = $this->makeOrder([['name' => $name, 'snapshot' => self::EMPTY_SNAP]]);
            $txt = $this->decodedText($this->renderer()->renderKitchenTicket($order));
            // La tête est en double-largeur wrappée à 16 col → replier les sauts de
            // ligne/indentations avant l'assertion de présence du nom complet.
            $flat = preg_replace('/\s+/u', ' ', $txt);
            $this->assertStringContainsString($name, $flat, "« $name » doit sortir en NOM COMPLET sur le ticket cuisine");
        }

        // Régression B-1 explicite : plus jamais « 1 x HAW » / « 1 x OAS ».
        foreach (['Hawaï 33cl' => 'HAW', 'Oasis Tropical 33cl' => 'OAS'] as $name => $code) {
            $order = $this->makeOrder([['name' => $name, 'snapshot' => self::EMPTY_SNAP]]);
            $txt = $this->decodedText($this->renderer()->renderKitchenTicket($order));
            $this->assertStringNotContainsString('1 x '.$code, $txt, "« 1 x $code » cryptique interdit");
        }
    }

    /**
     * [W6-ADV B-1] Faux positifs : les desserts RÉELS DB (et le token volumétrique)
     * ne classent JAMAIS un dessert en boisson (garde dessert-avant-drink).
     */
    public function test_real_db_desserts_are_never_drinks(): void
    {
        $this->seedRealDrinkCatalog();
        $f = app(KitchenTicketSymbolicFormatter::class);

        $cat = ItemCategory::query()->where('slug', 'desserts')->firstOrFail();
        $dbDesserts = Item::query()
            ->where('item_category_id', $cat->id)
            ->where('status', Status::ACTIVE)
            ->pluck('name')
            ->all();
        $this->assertNotEmpty($dbDesserts);

        $fixture = $this->fixtureNames();
        foreach ($fixture['desserts'] as $fx) {
            $this->assertContains($fx, $dbDesserts, 'fixture desserts désalignée de la DB seedée');
        }

        foreach ($dbDesserts as $name) {
            $this->assertFalse($f->isDrinkItem($name), "isDrinkItem('$name') doit être FALSE (dessert réel DB)");
        }
        // « Glace » ≠ « glaçons » : la garde /glace/ tient, et un dessert nommé au
        // gramme ne matche pas le token volumétrique cl/l.
        $this->assertFalse($f->isDrinkItem('Glace 2 boules'));
        $this->assertFalse($f->isDrinkItem('Tarte Daim 150g'));
    }

    public function test_drink_addon_role_drink_is_printed(): void
    {
        // shape réel #5171 : Bol Riz + addon « Boisson Seule » role=drink
        $order = $this->makeOrder([[
            'name' => 'Bol Riz',
            'snapshot' => [
                'lines' => [],
                'extras' => [],
                'addons' => [['role' => 'drink', 'addon_id' => 100, 'quantity' => 1, 'addon_name' => 'Boisson Seule', 'line_total' => 2, 'unit_price' => 2]],
            ],
        ]]);
        $txt = $this->decodedText($this->renderer()->renderKitchenTicket($order));

        $this->assertStringContainsString('1 Boisson Seule', $txt, 'addon role=drink DOIT apparaître en cuisine');
        $this->assertStringContainsString('BOL', $txt, 'le produit principal garde sa ligne symbolique');
    }

    public function test_menu_boisson_addon_is_printed_with_menu_line(): void
    {
        $order = $this->makeOrder([[
            'name' => 'Tacos M',
            'snapshot' => [
                'lines' => [['attribute_name' => 'Viande 1', 'variation_name' => 'Poulet mariné']],
                'extras' => [],
                'addons' => [
                    ['role' => 'menu_frites', 'quantity' => 1, 'addon_name' => 'Frites'],
                    ['role' => 'menu_boisson', 'quantity' => 1, 'addon_name' => 'Coca-Cola 33cl'],
                ],
            ],
        ]]);
        $txt = $this->decodedText($this->renderer()->renderKitchenTicket($order));

        $this->assertStringContainsString('MENU', $txt);
        $this->assertStringContainsString('1 Coca-Cola 33cl', $txt, 'la boisson du MENU doit être listée (owner : préparée en cuisine)');
    }

    public function test_menu_item_branch_prints_its_drink_addon(): void
    {
        // item addon « Menu (Frites + Boisson) » séparé (branche isMenuItem)
        $order = $this->makeOrder([[
            'name' => 'Menu (Frites + Boisson)',
            'snapshot' => [
                'lines' => [],
                'extras' => [],
                'addons' => [['role' => 'menu_boisson', 'quantity' => 1, 'addon_name' => 'Fanta 33cl']],
            ],
        ]]);
        $txt = $this->decodedText($this->renderer()->renderKitchenTicket($order));

        $this->assertStringContainsString('MENU', $txt);
        $this->assertStringContainsString('1 Fanta 33cl', $txt);
    }

    /**
     * [W6-ADV C-P1-1] Boisson de formule BORNE : la borne n'a PAS d'addon boisson
     * (menu_full seul) — la boisson voyage en texte dans la ligne « Formule : … »
     * que le sanitizer droppe. Réplique EXACTE de #5533 (A0012) : la boisson doit
     * ressortir en « BOISSON: Hawaï 33cl » (canal caisse) sur le ticket.
     */
    public function test_kiosk_formule_drink_extracted_to_boisson_line(): void
    {
        $order = $this->makeOrder([[
            'name' => 'Cayenne',
            'instruction' => 'Pain : Pain. Formule : Menu complet (frites + boisson) (Hawaï 33cl). Sauce frites : Algérienne',
            'snapshot' => [
                'lines' => [
                    ['attribute_name' => 'Sauce (1ère Gratuite)', 'variation_name' => 'Algérienne'],
                    ['attribute_name' => 'Type de Pain', 'variation_name' => 'Pain'],
                ],
                'extras' => [
                    ['extra_name' => 'Salade', 'quantity' => 1, 'unit_price' => 0, 'line_total' => 0],
                    ['extra_name' => 'Tomate', 'quantity' => 1, 'unit_price' => 0, 'line_total' => 0],
                    ['extra_name' => 'Oignons cuits', 'quantity' => 1, 'unit_price' => 0, 'line_total' => 0],
                ],
                'addons' => [
                    ['role' => 'menu_full', 'addon_id' => 25, 'quantity' => 1, 'addon_name' => 'Menu (Frites + Boisson)', 'line_total' => 2.5, 'unit_price' => 2.5],
                ],
            ],
        ]]);
        $txt = $this->decodedText($this->renderer()->renderKitchenTicket($order));

        $this->assertStringContainsString('** BOISSON: Hawaï 33cl', $txt, 'la boisson de formule borne doit sortir en cuisine');
        $this->assertStringContainsString('MENU : ALG', $txt, 'sauce frites toujours convertie en symbole (fritesSauceSymbol intact)');
        $this->assertStringNotContainsString('Formule : Menu complet', $txt, 'la ligne borne verbeuse reste droppée');
    }

    /** [W6-ADV C-P1-1] Formule SANS boisson → rien d'inventé (garde /frite/ sur « frites + boisson »). */
    public function test_kiosk_formule_without_drink_invents_nothing(): void
    {
        foreach ([
            'Pain : Pain. Formule : Menu complet (frites + boisson). Sauce frites : Andalouse',
            'Formule : Frites seules. Sauce frites : Andalouse',
        ] as $instruction) {
            $order = $this->makeOrder([[
                'name' => 'Cayenne',
                'instruction' => $instruction,
                'snapshot' => self::EMPTY_SNAP,
            ]]);
            $txt = $this->decodedText($this->renderer()->renderKitchenTicket($order));
            $this->assertStringNotContainsString('BOISSON:', $txt, "aucune boisson inventée pour « $instruction »");
        }
    }

    public function test_order_5501_shape_note_and_drink_both_visible(): void
    {
        // réplique EXACTE de la commande 5501 E2E-173832 (Tacos M note « oignons cuits » + Coca item)
        $order = $this->makeOrder([
            [
                'name' => 'Tacos M',
                'instruction' => "Viandes : Poulet mariné ×1\noignons cuits",
                'snapshot' => [
                    'lines' => [
                        ['attribute_name' => 'Viande 1', 'variation_name' => 'Poulet mariné'],
                        ['attribute_name' => 'Sauce (1ère Gratuite)', 'variation_name' => 'Algérienne'],
                    ],
                    'extras' => [],
                    'addons' => [],
                ],
            ],
            ['name' => 'Coca-Cola 33cl', 'snapshot' => self::EMPTY_SNAP],
        ]);
        $txt = $this->decodedText($this->renderer()->renderKitchenTicket($order));

        $this->assertStringContainsString('** oignons cuits', $txt, 'note client sur le ticket cuisine');
        $this->assertStringContainsString('Coca-Cola 33cl', $txt, 'boisson en nom complet');
        $this->assertStringNotContainsString('Viandes : Poulet', $txt, 'écho compo du wizard strippé');
    }

    public function test_width_safe_32_and_48_columns(): void
    {
        $order = $this->makeOrder([
            ['name' => 'Coca-Cola Cherry Zero 33cl', 'snapshot' => self::EMPTY_SNAP],
            ['name' => 'Oasis Tropical 33cl', 'snapshot' => self::EMPTY_SNAP],
            [
                'name' => 'Cayenne',
                'instruction' => 'Pain : Pain. Formule : Menu complet (frites + boisson) (Hawaï 33cl). Sauce frites : Algérienne',
                'snapshot' => self::EMPTY_SNAP,
            ],
            [
                'name' => 'Bol Riz',
                'snapshot' => [
                    'lines' => [],
                    'extras' => [],
                    'addons' => [['role' => 'drink', 'quantity' => 2, 'addon_name' => 'Boisson Seule Grand Format 50cl']],
                ],
            ],
        ]);
        foreach ([32, 48] as $w) {
            $bytes = $this->renderer()->renderKitchenTicket($order, ['width_chars' => $w]);
            $bad = [];
            foreach ($this->decodeLines($bytes) as $ln) {
                if ($ln['width'] > $w) {
                    $bad[] = "[{$ln['width']}] « {$ln['text']} »";
                }
            }
            $this->assertSame([], $bad, "lignes > $w col (seraient coupées) :\n  ".implode("\n  ", $bad));
        }
    }

    public function test_is_drink_item_twin_of_js_categorize(): void
    {
        $f = app(KitchenTicketSymbolicFormatter::class);
        // attendus = categorize()/isDrinkName() JS (kdsCustomization.js) — parité verrouillée à la main.
        // [W6-ADV B-1] « Fanta Hawai 33cl » n'existe plus (renommé « Hawaï 33cl ») —
        // le test épinglait l'ancien nom et masquait la régression.
        $cases = [
            'Coca-Cola 33cl' => true,
            'Hawaï 33cl' => true,            // régression B-1 (ex Fanta Hawai)
            'Oasis Tropical 33cl' => true,
            'Orangina 33cl' => true,
            'Capri-Sun' => true,
            'Tropico 33cl' => true,
            'Ice Tea Pêche 33cl' => true,
            'Fuze Tea 33cl' => true,
            'Perrier 33cl' => true,
            'Boisson Seule' => true,         // item technique upsell (nom complet > « BOI »)
            'Eau 50cl' => true,
            'Jus de pomme' => true,
            'Café' => true,
            'Limonade artisanale 1L' => true, // token volumétrique (future-proof)
            'Gâteau' => false,          // garde dessert-avant-drink (« eau » non ancré)
            'Tiramisu' => false,
            'Glace' => false,
            'Tarte Daim' => false,
            'Tacos M' => false,
            'Tacos L' => false,         // « L » seul ≠ volume (pas de chiffre)
            'Menu Enfant Burger' => false, // menu_formule avant drink
            'Menu (Frites + Boisson)' => false, // « boisson » gardé par /menu/
            'frites + boisson' => false, // libellé formule (garde /frite/) — anti C-P1-1
            'Bol Riz' => false,
            'Frites' => false,
        ];
        foreach ($cases as $name => $expected) {
            $this->assertSame($expected, $f->isDrinkItem($name), "isDrinkItem('$name')");
        }
    }
}
