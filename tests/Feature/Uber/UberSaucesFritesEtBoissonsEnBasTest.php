<?php

namespace Tests\Feature\Uber;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use App\Services\Uber\UberPhotoOrderMapper;
use App\Services\Uber\UberTicketPreviewBuilder;
use App\Services\Uber\Vision\OpenAiUberTicketVisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [SAUCES FRITES UBER 2026-11-20 · owner] Deux défauts du ticket cuisine d'une commande Uber scannée.
 *
 * 1. « Tu mets toutes les sauces dans le sandwich. » Le ticket Uber range les choix sous des titres
 *    en capitales (SAUCE, puis SAUCE FRITES). La lecture jetait ces titres : la sauce des frites
 *    rejoignait les deux sauces du sandwich. Elle doit aller devant le MENU / les FRITES.
 * 2. « Les boissons tout en bas, avec les desserts, pour qu'on n'oublie pas. » Elles étaient
 *    imprimées sous chaque produit.
 *
 * Chaque test passe par la VRAIE chaîne : normalisation de la lecture → mapper → ticket imprimé.
 */
class UberSaucesFritesEtBoissonsEnBasTest extends TestCase
{
    use RefreshDatabase;

    /** Ticket tel que la lecture du papier le rend : titres en capitales, choix en casse normale. */
    private function lire(array $items): array
    {
        return OpenAiUberTicketVisionService::normalize([
            'customer_name' => 'Sami', 'display_id' => '#A1B2C', 'order_type' => 'delivery', 'items' => $items,
        ]);
    }

    private function menuCayenneAvecSauceFrites(): array
    {
        return [
            'title' => 'Menu sandwich Cayenne', 'quantity' => 1, 'note' => '',
            'options' => [
                'PAIN', '1x Pain',
                'SAUCE', '1x Algérienne', '1x Harissa',
                'BOISSON', '1x Coca-Cola 33cl',
                'SAUCE FRITES', '1x Ketchup',
            ],
        ];
    }

    private function sauces(array $line): array
    {
        return array_values(array_map(
            static fn (array $l): string => (string) $l['variation_name'],
            array_filter($line['composition_snapshot']['lines'], static fn (array $l): bool => str_starts_with((string) $l['attribute_name'], 'Sauce'))
        ));
    }

    private function ticketCuisine(array $mapped, string $surface = 'uber_eats'): string
    {
        $items = [];
        foreach ($mapped['items'] as $l) {
            $oi = (new OrderItem)->forceFill([
                'quantity' => $l['quantity'], 'total_price' => 0, 'instruction' => $l['instruction'],
                'composition_snapshot' => $l['composition_snapshot'],
            ]);
            $oi->name = $l['name'];
            $items[] = $oi;
        }
        $order = (new Order)->forceFill([
            'order_serial_no' => 'UBER-SF-1', 'queue_number' => 'UB2C', 'source_surface' => $surface,
            'order_type' => \App\Enums\OrderType::DELIVERY, 'subtotal' => 0, 'total' => 0,
            'order_datetime' => '2026-11-20 20:00:00',
        ]);
        $order->setRelation('orderItems', collect($items));
        $order->setRelation('branch', (new Branch)->forceFill(['name' => 'Le Cayenne']));

        $bytes = app(OrderReceiptEscPosRenderer::class)->renderKitchenTicket($order, ['width_chars' => 48]);
        $s = preg_replace('/\x1B[aEtd!@].|\x1D[!B].|\x1B-.|\x1DV.|\x1B\x40/s', '', $bytes);

        return (string) preg_replace('/[\x00-\x09\x0B-\x1F]/', '', (string) iconv('CP858', 'UTF-8//IGNORE', (string) $s));
    }

    public function test_la_sauce_sous_le_titre_SAUCE_FRITES_va_aux_frites_et_pas_dans_le_sandwich(): void
    {
        $line = app(UberPhotoOrderMapper::class)->map($this->lire([$this->menuCayenneAvecSauceFrites()]))['items'][0];

        $this->assertSame(['Algérienne', 'Harissa'], $this->sauces($line),
            'Les deux sauces du sandwich, et elles seules, vont dans le sandwich.');
        $this->assertStringContainsString('Sauce frites : Ketchup', $line['instruction']);

        $apercu = app(UberTicketPreviewBuilder::class)->build(['items' => [$line]])['lignes'][0];
        $this->assertStringNotContainsString('KTP', $apercu['symbolique'], 'Le ketchup des frites est parti dans le sandwich.');
        $this->assertStringContainsString('KTP', $apercu['menu'], 'La sauce des frites doit être devant le MENU.');
    }

    /** Un titre de rubrique lu comme un ARTICLE à part (« SAUCE FRITES ») reste une rubrique du produit. */
    public function test_le_titre_SAUCE_FRITES_lu_comme_un_article_rejoint_les_frites_du_produit(): void
    {
        $t = $this->lire([
            ['title' => 'Menu sandwich Cayenne', 'quantity' => 1, 'note' => '', 'options' => ['SAUCE', '1x Algérienne', '1x Harissa']],
            ['title' => 'SAUCE FRITES', 'quantity' => 1, 'note' => '', 'options' => ['1x Mayonnaise']],
        ]);
        $this->assertCount(1, $t['items'], 'Une rubrique est devenue un plat fantôme.');

        $line = app(UberPhotoOrderMapper::class)->map($t)['items'][0];
        $this->assertSame(['Algérienne', 'Harissa'], $this->sauces($line));
        $this->assertStringContainsString('Sauce frites : Mayonnaise', $line['instruction']);
    }

    /** Sans titre lisible : une sauce qui revient APRÈS la formule, sur un menu, est celle des frites. */
    public function test_sans_titre_une_sauce_revenue_apres_la_formule_va_aux_frites(): void
    {
        $line = app(UberPhotoOrderMapper::class)->map($this->lire([[
            'title' => 'Menu sandwich Cayenne', 'quantity' => 1, 'note' => '',
            'options' => ['1x Algérienne', '1x Harissa', '1x Coca-Cola 33cl', '1x Ketchup'],
        ]]))['items'][0];

        $this->assertSame(['Algérienne', 'Harissa'], $this->sauces($line));
        $this->assertStringContainsString('Sauce frites : Ketchup', $line['instruction']);
    }

    /** ⚠️ Sans frites, il n'y a pas de sauce des frites : la sauce reste au produit, jamais jetée. */
    public function test_sans_frites_une_sauce_tardive_reste_au_produit(): void
    {
        $line = app(UberPhotoOrderMapper::class)->map($this->lire([[
            'title' => 'Cayenne', 'quantity' => 1, 'note' => '',
            'options' => ['1x Algérienne', '1x Coca-Cola 33cl', '1x Harissa'],
        ]]))['items'][0];

        $this->assertSame(['Algérienne', 'Harissa'], $this->sauces($line));
        $this->assertStringNotContainsString('Sauce frites', $line['instruction']);
    }

    /** ⚠️ Une sauce PAYANTE sous « SUPPLÉMENTS », même après la boisson, est une sauce en plus du sandwich. */
    public function test_une_sauce_en_supplement_apres_la_boisson_reste_au_sandwich(): void
    {
        $line = app(UberPhotoOrderMapper::class)->map($this->lire([[
            'title' => 'Menu sandwich Cayenne', 'quantity' => 1, 'note' => '',
            'options' => ['SAUCE', '1x Algérienne', 'BOISSON', '1x Coca-Cola 33cl', 'SUPPLÉMENTS', '1x Harissa (+0,50 €)'],
        ]]))['items'][0];

        $this->assertSame(['Algérienne', 'Harissa'], $this->sauces($line));
        $this->assertStringNotContainsString('Sauce frites', $line['instruction']);
    }

    /** ⚠️ Un titre ne fait pas oublier qu'une crudité PAYANTE est un supplément servi (2026-11-11). */
    public function test_une_crudite_payante_sous_CRUDITES_reste_un_supplement(): void
    {
        $line = app(UberPhotoOrderMapper::class)->map($this->lire([[
            'title' => 'Cayenne', 'quantity' => 1, 'note' => '',
            'options' => ['CRUDITÉS', '1x Salade', '1x Oignons frits (+0,90 €)'],
        ]]))['items'][0];

        $extras = $line['composition_snapshot']['extras'];
        $payant = array_values(array_filter($extras, static fn (array $e): bool => $e['extra_name'] === 'Oignons frits'));
        $this->assertCount(1, $payant);
        $this->assertTrue($payant[0]['uber_supplement'] ?? false, 'La crudité payante a été repliée en garniture gratuite.');
    }

    // ── Relecture adverse du 2026-11-20 : un titre mal lu ou écrit autrement ne doit rien déplacer ──

    /** Titre « BOISSON » absent de la photo : le Coca ne part pas dans le sandwich. */
    public function test_un_titre_BOISSON_manquant_ne_met_pas_le_coca_dans_le_sandwich(): void
    {
        $line = app(UberPhotoOrderMapper::class)->map($this->lire([[
            'title' => 'Menu sandwich Cayenne', 'quantity' => 1, 'note' => '',
            'options' => ['SAUCE', '1x Algérienne', '1x Harissa', '1x Coca-Cola 33cl', 'SAUCE FRITES', '1x Ketchup'],
        ]]))['items'][0];

        $this->assertSame(['Algérienne', 'Harissa'], $this->sauces($line));
        $this->assertContains('Coca-Cola 33cl', array_column($line['composition_snapshot']['addons'], 'addon_name'));
        $this->assertStringContainsString('Sauce frites : Ketchup', $line['instruction']);
    }

    /**
     * @dataProvider titresDeLaSauceDesFrites
     */
    public function test_le_titre_des_frites_ecrit_autrement_garde_la_sauce_aux_frites(string $titre): void
    {
        $line = app(UberPhotoOrderMapper::class)->map($this->lire([[
            'title' => 'Menu sandwich Cayenne', 'quantity' => 1, 'note' => '',
            'options' => ['SAUCE', '1x Algérienne', '1x Harissa', 'BOISSON', '1x Coca-Cola 33cl', $titre, '1x Ketchup'],
        ]]))['items'][0];

        $this->assertSame(['Algérienne', 'Harissa'], $this->sauces($line), $titre);
        $this->assertStringContainsString('Sauce frites : Ketchup', $line['instruction'], $titre);
        $this->assertSame(['Coca-Cola 33cl'], array_values(array_filter(
            array_column($line['composition_snapshot']['addons'], 'addon_name'),
            static fn (string $n): bool => ! str_starts_with($n, 'Menu')
        )), "Le Ketchup est devenu une boisson ({$titre}).");
    }

    public static function titresDeLaSauceDesFrites(): array
    {
        return [
            ['SAUCE FRITES (1 INCLUSE)'], ['Sauce frites (1 incluse)'], ['SAUCE FRITES : 1 INCLUSE'],
            ['SAUCE FRITES - 1 INCLUSE'], ['SAUCES POUR VOS FRITES'], ['Sauce frites'],
        ];
    }

    /** Un choix écrit en capitales n'est pas un titre : rien ne disparaît, surtout pas un refus. */
    public function test_un_choix_en_capitales_n_est_pas_pris_pour_un_titre(): void
    {
        $t = $this->lire([[
            'title' => 'Cayenne', 'quantity' => 1, 'note' => '',
            'options' => ['CRUDITÉS', 'SANS CRUDITÉS', 'SAUCE', 'SAUCE BLANCHE', 'HARISSA', 'SUPPLÉMENTS', 'DOUBLE VIANDE'],
        ]]);
        $tout = implode(' | ', $t['items'][0]['options']);
        foreach (['SANS CRUDITÉS', 'SAUCE BLANCHE', 'HARISSA', 'DOUBLE VIANDE'] as $choix) {
            $this->assertStringContainsString($choix, $tout, "Le choix « {$choix} » a été effacé comme un titre.");
        }

        $line = app(UberPhotoOrderMapper::class)->map($t)['items'][0];
        $this->assertStringContainsString('SANS CRUDITÉS', $line['instruction'], 'Le refus du client est perdu.');
    }

    /** « SAUCE FRITES (1 INCLUSE) » lu comme un ARTICLE à part n'est pas un plat fantôme. */
    public function test_un_titre_avec_quota_lu_comme_article_rejoint_le_produit(): void
    {
        $t = $this->lire([
            ['title' => 'Menu sandwich Cayenne', 'quantity' => 1, 'note' => '', 'options' => ['SAUCES (2 INCLUSES)', '1x Algérienne', '1x Harissa']],
            ['title' => 'SAUCE FRITES (1 INCLUSE)', 'quantity' => 1, 'note' => '', 'options' => ['1x Ketchup']],
        ]);
        $this->assertCount(1, $t['items']);

        $line = app(UberPhotoOrderMapper::class)->map($t)['items'][0];
        $this->assertSame(['Algérienne', 'Harissa'], $this->sauces($line));
        $this->assertStringContainsString('Sauce frites : Ketchup', $line['instruction']);
    }

    /** La lecture range les titres, le mapper la rejoue : une seconde passe ne change plus rien. */
    public function test_ranger_les_titres_deux_fois_ne_change_rien(): void
    {
        $options = ['SAUCE', '1x Algérienne', 'VOS DESSERTS', 'BOISSON', '1x Coca-Cola 33cl', 'SAUCE FRITES', '1x Ketchup'];
        $une = \App\Services\Uber\UberTicketRubriques::attacher($options);

        $this->assertSame($une, \App\Services\Uber\UberTicketRubriques::attacher($une));
    }

    /** Un dessert pris avec le produit reste visible à l'écran cuisine (jumeau PHP de l'écran). */
    public function test_un_dessert_pris_avec_le_produit_reste_visible_a_l_ecran(): void
    {
        $line = app(UberPhotoOrderMapper::class)->map($this->lire([[
            'title' => 'Cayenne', 'quantity' => 1, 'note' => '', 'options' => ['SAUCE', '1x Algérienne', 'DESSERT', '1x Tiramisu'],
        ]]))['items'][0];

        $this->assertContains('1 Tiramisu', app(\App\Services\Hardware\KitchenTicketSymbolicFormatter::class)->drinkLines($line['composition_snapshot']));
    }

    // ── Relecture adverse n° 2 : un titre INCONNU n'étiquette pas les choix qui le suivent ──

    private function ligne(string $titre, array $options): array
    {
        return app(UberPhotoOrderMapper::class)->map($this->lire([[
            'title' => $titre, 'quantity' => 1, 'note' => '', 'options' => $options,
        ]]))['items'][0];
    }

    private function roles(array $line): array
    {
        return array_column($line['composition_snapshot']['addons'], 'role');
    }

    public function test_SEUL_OU_EN_MENU_garde_le_menu_et_ses_frites(): void
    {
        $line = $this->ligne('Sandwich Cayenne', ['PAIN', '1x Pain', 'SAUCE', '1x Algérienne', 'SEUL OU EN MENU', '1x Menu (Frites + Boisson)', 'BOISSON', '1x Coca-Cola 33cl']);

        $this->assertSame(['Algérienne'], $this->sauces($line));
        $this->assertContains('menu_full', $this->roles($line), 'Le menu est perdu : aucune frite à plonger.');
    }

    public function test_TYPE_DE_PAIN_garde_la_galette(): void
    {
        $line = $this->ligne('Cayenne', ['SAUCE', '1x Algérienne', 'TYPE DE PAIN', '1x Galette', 'CRUDITÉS', '1x Salade']);

        $this->assertSame(['Algérienne'], $this->sauces($line));
        $pain = array_values(array_filter($line['composition_snapshot']['lines'], static fn (array $l): bool => $l['attribute_name'] === 'Pain'));
        $this->assertSame('Galette', $pain[0]['variation_name'] ?? null, 'La galette est devenue une sauce.');
    }

    public function test_un_titre_inconnu_arrete_l_etiquette_du_titre_precedent(): void
    {
        $line = $this->ligne('Cayenne', ['SAUCE', '1x Algérienne', 'BASE BOL', '1x Frites', 'SAUCES AU CHOIX', '1x Harissa']);

        $this->assertSame(['Algérienne', 'Harissa'], $this->sauces($line));
        $this->assertContains('menu_frites', $this->roles($line), 'Les frites sont devenues une sauce.');
    }

    public function test_SAUCE_SUPPLEMENTAIRE_apres_la_boisson_reste_au_sandwich_sans_boisson_fantome(): void
    {
        $line = $this->ligne('Menu sandwich Cayenne', ['PAIN', '1x Pain', 'SAUCE', '1x Algérienne', 'BOISSON', '1x Coca-Cola 33cl', 'SAUCE SUPPLÉMENTAIRE', '1x Harissa (+0,50 €)']);

        $this->assertSame(['Algérienne', 'Harissa'], $this->sauces($line));
        $this->assertStringNotContainsString('Sauce frites', $line['instruction']);
        $boissons = app(\App\Services\Hardware\KitchenTicketSymbolicFormatter::class)->boissonsEtDessertsUber($line['composition_snapshot']);
        $this->assertSame(['1 Coca-Cola 33cl'], $boissons);
    }

    public function test_un_supplement_payant_sous_SAUCE_reste_un_supplement_ecrit(): void
    {
        $line = $this->ligne('Cayenne', ['SAUCE', '1x Algérienne', '1x Cheddar (+1,00 €)']);

        $this->assertSame(['Algérienne'], $this->sauces($line));
        $this->assertContains('Cheddar', array_column($line['composition_snapshot']['extras'], 'extra_name'));
    }

    public function test_deux_sauces_frites_identiques_comptent_deux(): void
    {
        $line = $this->ligne('Menu sandwich Cayenne', ['SAUCE', '1x Algérienne', 'SAUCE FRITES', '2x Ketchup']);

        $this->assertStringContainsString('Sauce frites : Ketchup, Ketchup', $line['instruction']);
    }

    public function test_un_titre_des_frites_sans_choix_n_invente_pas_de_sauce(): void
    {
        $line = $this->ligne('Menu sandwich Cayenne', ['SAUCE', '1x Algérienne', 'SAUCE FRITES (1 INCLUSE)', 'BOISSON', '1x Coca-Cola 33cl']);

        $this->assertStringNotContainsString('Sauce frites', $line['instruction']);
    }

    // ── Relecture adverse n° 3 ──

    /** Le cas du propriétaire, frites venues du choix « Frites », titre des frites absent. */
    public function test_menu_aux_frites_choisies_sans_titre_des_frites_la_sauce_va_aux_frites(): void
    {
        $line = $this->ligne('Menu sandwich Cayenne', ['SAUCE', '1x Algérienne', '1x Harissa', 'ACCOMPAGNEMENT', '1x Frites', 'BOISSON', '1x Coca-Cola 33cl', '1x Ketchup']);

        $this->assertSame(['Algérienne', 'Harissa'], $this->sauces($line));
        $this->assertStringContainsString('Sauce frites : Ketchup', $line['instruction']);
    }

    public function test_une_sauce_en_capitales_sous_SAUCE_FRITES_reste_aux_frites(): void
    {
        $line = $this->ligne('Menu sandwich Cayenne', ['SAUCE', '1x Algérienne', '1x Harissa', 'SAUCE FRITES', 'KETCHUP', '1x Mayonnaise']);

        $this->assertSame(['Algérienne', 'Harissa'], $this->sauces($line));
        $this->assertStringContainsString('Sauce frites : KETCHUP, Mayonnaise', $line['instruction']);
    }

    public function test_une_sauce_frites_payante_inconnue_reste_aux_frites(): void
    {
        $line = $this->ligne('Menu sandwich Cayenne', ['SAUCE', '1x Algérienne', 'SAUCE FRITES', '1x Ketchup', '1x Américaine (+0,50 €)']);

        $this->assertStringContainsString('Sauce frites : Ketchup, Américaine', $line['instruction']);
        $this->assertNotContains('Américaine', array_column($line['composition_snapshot']['extras'], 'extra_name'));
    }

    public function test_des_titres_ecrits_avec_deux_points_finaux_sont_des_titres(): void
    {
        $line = $this->ligne('Menu sandwich Cayenne', ['PAIN :', '1x Galette', 'SAUCE :', '1x Algérienne', 'SAUCE FRITES :', '1x Ketchup']);

        $this->assertSame(['Algérienne'], $this->sauces($line));
        $this->assertStringContainsString('Sauce frites : Ketchup', $line['instruction']);
        $pains = array_values(array_filter($line['composition_snapshot']['lines'], static fn (array $l): bool => $l['attribute_name'] === 'Pain'));
        $this->assertSame(['Galette'], array_column($pains, 'variation_name'));
    }

    public function test_le_choix_MENU_donne_le_menu_et_ses_frites(): void
    {
        foreach (['MENU', '1x Menu'] as $choix) {
            $line = $this->ligne('Sandwich Cayenne', ['SEUL OU EN MENU', $choix, 'BOISSON', '1x Coca-Cola 33cl']);
            $this->assertContains('menu_full', $this->roles($line), "« {$choix} » n'a pas donné de menu.");
        }
    }

    public function test_VIANDE_1_et_VIANDE_2_donnent_des_viandes_propres(): void
    {
        $line = $this->ligne('Tacos L', ['VIANDE 1', '1x Poulet mariné', 'VIANDE 2', '1x Viande Hachée']);
        $viandes = array_values(array_filter($line['composition_snapshot']['lines'], static fn (array $l): bool => str_starts_with($l['attribute_name'], 'Viande')));

        $this->assertSame(['Poulet mariné', 'Viande Hachée'], array_column($viandes, 'variation_name'));
    }

    public function test_SAUCE_GRATINEE_apres_la_boisson_reste_au_tacos(): void
    {
        $line = $this->ligne('Menu Tacos L', ['SAUCE', '1x Algérienne', 'BOISSON', '1x Coca-Cola 33cl', 'SAUCE GRATINÉE', '1x Fromagère']);

        $this->assertStringNotContainsString('Sauce frites', $line['instruction']);
    }

    // ── Relecture adverse n° 4 ──

    private function extras(array $line): array
    {
        return array_column($line['composition_snapshot']['extras'], 'extra_name');
    }

    /**
     * Un titre inconnu (« FROMAGE ») reste une ligne visible — comme avant cette correction : il est
     * indiscernable d'un choix en capitales. Mais il n'étiquette plus le Cheddar qui le suit.
     */
    public function test_un_titre_FROMAGE_inconnu_ne_transforme_pas_le_cheddar_en_sauce(): void
    {
        $line = $this->ligne('Tacos L', ['VIANDE', '1x Poulet', 'SAUCE', '1x Algérienne', 'FROMAGE', '1x Cheddar']);
        $this->assertNotContains('Cheddar', $this->sauces($line));
        $this->assertContains('Cheddar', $this->extras($line));
    }

    /** [relecture adverse 5] Une valeur en capitales avant un choix chiffré n'est JAMAIS effacée. */
    public function test_une_valeur_en_capitales_avant_un_choix_chiffre_survit_aux_deux_passes(): void
    {
        $cas = [
            ['Cayenne', ['PAIN', 'GALETTE', 'SAUCE', '1x Algérienne']],
            ['Tacos L', ['VIANDE', 'POULET', '1x Viande Hachée']],
            ['Cayenne', ['SAUCE', 'SAUCE BLANCHE', '1x Harissa']],
            ['Cayenne', ['BOISSON', 'COCA-COLA 33CL', 'DESSERT', '1x Tiramisu']],
        ];
        foreach ($cas as [$titre, $options]) {
            $une = \App\Services\Uber\UberTicketRubriques::attacher($options);
            $this->assertSame($une, \App\Services\Uber\UberTicketRubriques::attacher($une), implode(' | ', $options));
            $valeurCaps = $options[1];
            $this->assertStringContainsString($valeurCaps, implode(' | ', $une), "« {$valeurCaps} » a été effacé.");
        }
        $line = $this->ligne('Tacos L', ['VIANDE', 'POULET', 'SAUCE', '1x Algérienne']);
        $viandes = array_filter($line['composition_snapshot']['lines'], static fn (array $l): bool => str_starts_with($l['attribute_name'], 'Viande'));
        $this->assertCount(1, $viandes, 'Le poulet en capitales a été perdu : rien à cuire.');
    }

    public function test_un_titre_GRATINE_AU_FROMAGE_ne_fabrique_pas_une_deuxieme_viande(): void
    {
        $line = $this->ligne('Tacos L', ['VIANDE', '1x Poulet', 'GRATINÉ AU FROMAGE', '1x Cheddar']);
        $viandes = array_filter($line['composition_snapshot']['lines'], static fn (array $l): bool => str_starts_with($l['attribute_name'], 'Viande'));
        $this->assertCount(1, $viandes, 'Le Cheddar est devenu une viande : la portion de poulet serait divisée par deux.');
    }

    public function test_un_supplement_payant_apres_la_sauce_des_frites_reste_un_supplement(): void
    {
        $line = $this->ligne('Menu sandwich Cayenne', ['SAUCE', '1x Algérienne', 'SAUCE FRITES', '1x Ketchup', 'SUPPLÉMENT FROMAGE', '1x Cheddar (+1,00 €)']);
        $this->assertStringContainsString('Sauce frites : Ketchup', $line['instruction']);
        $this->assertStringNotContainsString('Cheddar', (string) strstr($line['instruction'], 'Sauce frites'));
        $this->assertContains('Cheddar', $this->extras($line));
    }

    public function test_des_crudites_en_capitales_sans_leur_titre_restent_des_crudites(): void
    {
        $line = $this->ligne('Cayenne', ['SAUCE', '1x Algérienne', 'SALADE', 'TOMATE', 'OIGNONS']);
        $this->assertSame(['Algérienne'], $this->sauces($line));
    }

    public function test_le_choix_FORMULE_en_capitales_donne_le_menu(): void
    {
        $line = $this->ligne('Sandwich Cayenne', ['SEUL OU EN MENU', 'FORMULE', 'BOISSON', '1x Coca-Cola 33cl']);
        $this->assertContains('menu_full', $this->roles($line));
    }

    public function test_un_titre_BOISSON_33CL_n_imprime_pas_de_boisson_fantome(): void
    {
        $line = $this->ligne('Menu sandwich Cayenne', ['SAUCE', '1x Algérienne', 'BOISSON 33CL', '1x Coca-Cola 33cl']);
        $bas = app(\App\Services\Hardware\KitchenTicketSymbolicFormatter::class)->boissonsEtDessertsUber($line['composition_snapshot']);
        $this->assertSame(['1 Coca-Cola 33cl'], $bas);
    }

    public function test_CHOIX_DE_LA_SAUCE_apres_la_boisson_se_range_par_sa_place(): void
    {
        $line = $this->ligne('Menu sandwich Cayenne', ['SAUCE', '1x Algérienne', 'BOISSON', '1x Coca-Cola 33cl', 'CHOIX DE LA SAUCE', '1x Ketchup']);
        $this->assertStringContainsString('Sauce frites : Ketchup', $line['instruction']);
    }

    public function test_FORMULE_puis_la_vraie_formule_ne_fait_qu_un_menu(): void
    {
        $line = $this->ligne('Cayenne', ['SAUCE', '1x Algérienne', 'MENU', '1x Menu (Grande Frites + Boisson)']);
        $menus = array_values(array_filter($line['composition_snapshot']['addons'], static fn (array $a): bool => $a['role'] === 'menu_full'));

        $this->assertCount(1, $menus, 'Deux formules : la friteuse compterait des frites en trop.');
        $this->assertStringContainsString('Grande', $menus[0]['addon_name']);
    }

    public function test_RETIRER_OIGNONS_en_capitales_est_un_refus(): void
    {
        $line = $this->ligne('Cayenne', ['RETIRER OIGNONS', '1x Salade']);
        $this->assertStringContainsString('RETIRER OIGNONS', $line['instruction']);
        $this->assertNotContains('RETIRER OIGNONS', array_column($line['composition_snapshot']['extras'], 'extra_name'));
    }

    // ── Formes de ticket Uber de la carte Le Cayenne (formule « seul / en menu / frites seules »,
    //    titre suivi d'un slogan) ──

    /** Suprême pris « Frites seules » : la sauce qui suit la formule est celle des frites. */
    public function test_ticket_supreme_frites_seules_la_sauce_suivante_va_aux_frites(): void
    {
        $line = $this->ligne('Suprême', ['1x Galette', '1x Mayonnaise', '1x Salade', '1x Oignon', '1x Frites seules', '1x Mayonnaise']);

        $this->assertSame(['Mayonnaise'], $this->sauces($line), 'La sauce des frites est encore dans le sandwich.');
        $this->assertStringContainsString('Sauce frites : Mayonnaise', $line['instruction']);
        $this->assertContains('menu_frites', $this->roles($line), 'Les frites seules ne sont pas comptées au bain.');
    }

    /** Un titre suivi d'un slogan après la virgule désigne toujours le produit. */
    public function test_ticket_titre_avec_slogan_trouve_le_produit(): void
    {
        $catId = \Illuminate\Support\Facades\DB::table('item_categories')->insertGetId(['name' => 'Sandwich', 'slug' => 'sandwich-t', 'status' => 5, 'created_at' => now(), 'updated_at' => now()]);
        \Illuminate\Support\Facades\DB::table('items')->insert(['name' => 'Cayenne', 'slug' => 'cayenne-t', 'item_category_id' => $catId, 'price' => 8, 'status' => 5, 'created_at' => now(), 'updated_at' => now()]);

        $line = $this->ligne('Sandwich Cayenne, Ultra GOURMAND et Original', ['Pain', 'Barbecue', 'Tomate', 'Oignon', 'Cheddar (recommandé)', 'En menu (frites + boisson incluses)', 'Fuze Tea']);

        $this->assertSame('Cayenne', $line['name']);
        $this->assertSame(['Barbecue'], $this->sauces($line));
        $this->assertContains('menu_full', $this->roles($line));
    }

    /** Méga en menu, sauces AVANT la formule = sauces du produit, aucune sauce frites. */
    public function test_ticket_mega_en_menu_les_sauces_restent_au_produit(): void
    {
        $line = $this->ligne('Méga', ['Poulet mariné', 'Viande hachée', 'Salade', 'Tomate', 'Oignon', 'Pain', 'Mayonnaise', 'Harissa', 'En menu (frites + boisson incluses)', 'Coca-Cola zéro']);

        $this->assertSame(['Mayonnaise', 'Harissa'], $this->sauces($line));
        $this->assertStringNotContainsString('Sauce frites', $line['instruction']);
    }

    /** ⚠️ Le rôle « dessert » ne change rien hors commande Uber photographiée. */
    public function test_un_dessert_hors_uber_photo_ne_change_pas_la_mise_en_page(): void
    {
        $lignes = app(\App\Services\Hardware\KitchenTicketSymbolicFormatter::class)->drinkLines([
            'source' => 'pos', 'addons' => [['role' => 'dessert', 'addon_name' => 'Tiramisu', 'quantity' => 1]],
        ]);
        $this->assertSame([], $lignes);
    }

    public function test_les_boissons_et_les_desserts_sortent_EN_BAS_du_ticket_cuisine(): void
    {
        $mapped = app(UberPhotoOrderMapper::class)->map($this->lire([
            $this->menuCayenneAvecSauceFrites(),
            ['title' => 'Menu Tacos L', 'quantity' => 2, 'note' => '', 'options' => ['SAUCE', '1x Samouraï', 'BOISSON', '1x Fanta Orange 33cl', 'DESSERT', '1x Tiramisu']],
            ['title' => 'Tarte Daim', 'quantity' => 1, 'note' => '', 'options' => []],
        ]));
        $t = $this->ticketCuisine($mapped);

        $bas = mb_strpos($t, 'BOISSONS / DESSERTS');
        $this->assertNotFalse($bas, "Aucune rubrique boissons/desserts en bas.\n{$t}");
        $avant = mb_substr($t, 0, $bas);
        $apres = mb_substr($t, $bas);

        foreach (['Coca-Cola', 'Fanta', 'Tiramisu', 'Tarte Daim'] as $mot) {
            $this->assertStringNotContainsString($mot, $avant, "« {$mot} » est encore imprimé sous un produit.\n{$t}");
            $this->assertStringContainsString($mot, $apres, "« {$mot} » manque en bas du ticket.\n{$t}");
        }
        // Deux menus Tacos = deux Fanta et deux Tiramisu : en bas, le total à servir est écrit.
        $this->assertStringContainsString('2 Fanta Orange 33cl', $apres, $t);
        $this->assertStringContainsString('2 Tiramisu', $apres, $t);
        $this->assertStringContainsString('1 Coca-Cola 33cl', $apres, $t);
        // Les frites restent avec leur produit : le badge MENU (et sa sauce) est au-dessus.
        $this->assertMatchesRegularExpression('/MENU : KTP/u', $avant, $t);
    }

    /** ⚠️ La mise en page de la caisse, de la borne et du site ne bouge pas. */
    public function test_une_commande_qui_n_est_pas_uber_garde_sa_boisson_sous_le_produit(): void
    {
        $mapped = app(UberPhotoOrderMapper::class)->map($this->lire([$this->menuCayenneAvecSauceFrites()]));
        $t = $this->ticketCuisine($mapped, 'pos');

        $this->assertStringNotContainsString('BOISSONS / DESSERTS', $t);
        $this->assertStringContainsString('Coca-Cola', $t);
    }

    public function test_l_apercu_montre_les_boissons_en_bas_comme_le_ticket(): void
    {
        $mapped = app(UberPhotoOrderMapper::class)->map($this->lire([
            $this->menuCayenneAvecSauceFrites(),
            ['title' => 'Tiramisu', 'quantity' => 1, 'note' => '', 'options' => []],
        ]));
        $apercu = app(UberTicketPreviewBuilder::class)->build($mapped);

        $this->assertSame([], $apercu['lignes'][0]['boissons'], 'La boisson est encore affichée sous le produit.');
        $this->assertSame(['1 Coca-Cola 33cl'], $apercu['en_bas']);
        $this->assertTrue($apercu['lignes'][1]['en_bas']);
        $this->assertSame('Tiramisu', $apercu['lignes'][1]['symbolique'], 'En bas, un dessert seul s’écrit en entier.');
    }
    /**
     * [DOUBLON BOISSON 2026-11-20] Ticket Méga réel : le Coca zéro du menu a AUSSI été lu comme un article
     * à part. On ne peut pas savoir sans le papier si le client en a pris un second : l'aperçu le
     * SIGNALE (doublon_de = la ligne qui contient déjà cette boisson), il ne retire jamais rien.
     */
    public function test_une_boisson_seule_deja_dans_un_menu_est_signalee_sans_etre_retiree(): void
    {
        $mapped = app(UberPhotoOrderMapper::class)->map($this->lire([
            ['title' => 'Menu sandwich Cayenne', 'quantity' => 1, 'note' => '',
                'options' => ['SAUCE', '1x Algérienne', 'BOISSON', '1x Coca-Cola Zéro 33cl']],
            ['title' => 'Coca zero', 'quantity' => 1, 'note' => '', 'options' => []],
        ]));
        $apercu = app(UberTicketPreviewBuilder::class)->build($mapped);

        $this->assertCount(2, $apercu['lignes'], 'La boisson seule ne doit JAMAIS être retirée automatiquement.');
        $this->assertSame($apercu['lignes'][0]['titre'], $apercu['lignes'][1]['doublon_de']);
        $this->assertNull($apercu['lignes'][0]['doublon_de']);
    }

    public function test_une_autre_boisson_ou_une_autre_variante_n_est_pas_signalee(): void
    {
        foreach (['Fanta Orange 33cl', 'Coca-Cola 33cl', 'Tiramisu'] as $seule) {
            $mapped = app(UberPhotoOrderMapper::class)->map($this->lire([
                ['title' => 'Menu sandwich Cayenne', 'quantity' => 1, 'note' => '',
                    'options' => ['SAUCE', '1x Algérienne', 'BOISSON', '1x Coca-Cola Zéro 33cl']],
                ['title' => $seule, 'quantity' => 1, 'note' => '', 'options' => []],
            ]));
            $apercu = app(UberTicketPreviewBuilder::class)->build($mapped);

            $this->assertNull($apercu['lignes'][1]['doublon_de'], $seule.' signalé à tort comme doublon du Coca zéro.');
        }
    }
    /**
     * [VRAIS TICKETS 2026-11-20 soir] Forme rendue par la lecture APRÈS le correctif : certains choix déjà
     * étiquetés (« 1x SAUCE : Andalouse »), et des titres que la passe ne connaissait pas — « FORMULE »,
     * « SUPPLÉMENTS BURGERS », « SAUCE GRATUITE FRITES », « SAUCE SUPPLÉMENTAIRE FRITES ». Ils
     * s'imprimaient comme des lignes, « FORMULE » devenait un MENU, et la sauce des frites restait en vrac.
     */
    private function ticketReel(array $items): array
    {
        $mapped = app(UberPhotoOrderMapper::class)->map($this->lire($items));

        return [$mapped, $this->ticketCuisine($mapped)];
    }

    public function test_vrai_ticket_FORMULE_Seul_ne_donne_ni_menu_ni_frites(): void
    {
        [$mapped, $t] = $this->ticketReel([['title' => 'Terminator', 'quantity' => 1, 'note' => '', 'options' => [
            '1x VIANDES : Viande hachée', '1x VIANDES : Cordon Bleu', '1x SAUCE : Andalouse', '1x PAIN : Galette',
            '1x SUPPLÉMENTS : Oignons frits', 'FORMULE', '1x Seul',
        ]]]);

        $roles = array_column($mapped['items'][0]['composition_snapshot']['addons'] ?? [], 'role');
        $this->assertNotContains('menu_full', $roles, $t);
        $this->assertNotContains('menu_frites', $roles, $t);
        $this->assertDoesNotMatchRegularExpression('/\bMENU\b|\bSeul\b|FORMULE/u', $t, $t);
    }

    public function test_vrai_ticket_SAUCE_GRATUITE_FRITES_va_devant_les_frites(): void
    {
        [$mapped, $t] = $this->ticketReel([['title' => 'Cheese Burger', 'quantity' => 1, 'note' => '', 'options' => [
            '1x SAUCE : Sauce Burger', 'SUPPLÉMENTS BURGERS', '1x Boursin', 'FORMULE', '1x Frites seules',
            'SAUCE GRATUITE FRITES', '1x Sauce Burger',
        ]]]);

        $this->assertSame(['Sauce Burger'], $this->sauces($mapped['items'][0]), $t);
        $this->assertMatchesRegularExpression('/FRITES : \S/u', $t, $t);
        $this->assertStringContainsString('Boursin', $t);
        $this->assertDoesNotMatchRegularExpression('/SAUCE GRATUITE|SUPPLÉMENTS BURGERS|FORMULE|\bMENU\b/u', $t, $t);
    }

    public function test_vrai_ticket_SAUCE_SUPPLEMENTAIRE_FRITES_va_devant_les_frites(): void
    {
        [$mapped, $t] = $this->ticketReel([['title' => 'Tacos M', 'quantity' => 1, 'note' => '', 'options' => [
            '1x VIANDE : Poulet mariné', '1x SAUCE : Blanche', '1x SAUCE : Fromagère maison', 'FORMULE',
            '1x Frites seules', 'SAUCE SUPPLÉMENTAIRE FRITES', '1x Américaine',
        ]]]);

        $this->assertSame(['Blanche', 'Fromagère maison'], $this->sauces($mapped['items'][0]), $t);
        $this->assertMatchesRegularExpression('/FRITES : AME/u', $t, $t);
        $this->assertDoesNotMatchRegularExpression('/SAUCE SUPPL|FORMULE/u', $t, $t);
    }

    public function test_vrai_ticket_SUPPLEMENTS_BOWLS_et_tiramisu_au_slogan(): void
    {
        // La carte réelle : « maison » seul correspondait à « Sauce Fromagère maison » — le tiramisu
        // partait en cuisine sous le nom d'une sauce.
        $catId = \Illuminate\Support\Facades\DB::table('item_categories')->insertGetId(['name' => 'Desserts', 'slug' => 'desserts-t', 'status' => 5, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['Sauce Fromagère maison', 'Tiramisu'] as $k => $nom) {
            \Illuminate\Support\Facades\DB::table('items')->insert(['name' => $nom, 'slug' => 'art-t-'.$k, 'item_category_id' => $catId, 'price' => 3, 'status' => 5, 'created_at' => now(), 'updated_at' => now()]);
        }

        [$mapped, $t] = $this->ticketReel([
            ['title' => 'Bol frites', 'quantity' => 1, 'note' => '', 'options' => [
                '1x VIANDE : Tenders', '1x SAUCE : Fromagère maison', 'SUPPLÉMENTS BOWLS', '1x Cheddar']],
            ['title' => 'Tiramisu maison, Ultra Gourmand !', 'quantity' => 1, 'note' => '', 'options' => []],
        ]);

        $this->assertStringContainsString('Cheddar', $t);
        $this->assertStringNotContainsString('SUPPLÉMENTS BOWLS', $t, $t);
        $this->assertStringContainsString('BOISSONS / DESSERTS', $t, $t);
        $this->assertSame('Tiramisu', $mapped['items'][1]['name'], 'Le tiramisu est devenu « '.$mapped['items'][1]['name'].' ».');
        $this->assertMatchesRegularExpression('/BOISSONS \/ DESSERTS.*Tiramisu/su', $t, $t);
        $this->assertStringNotContainsString('FROMAG', $t, $t);
    }
    /** La passe des titres est rejouée par le mapper : la rejouer ne doit rien changer (FORMULE inclus). */
    public function test_vrai_ticket_la_passe_des_titres_reste_idempotente(): void
    {
        foreach ([
            ['SEUL OU EN MENU', 'MENU', 'BOISSON', '1x Coca-Cola 33cl'],
            ['1x SAUCE : Sauce Burger', 'SUPPLÉMENTS BURGERS', '1x Boursin', 'FORMULE', '1x Frites seules', 'SAUCE GRATUITE FRITES', '1x Sauce Burger'],
            ['1x VIANDES : Viande hachée', 'FORMULE', '1x Seul'],
        ] as $options) {
            $une = \App\Services\Uber\UberTicketRubriques::attacher($options);
            $this->assertSame($une, \App\Services\Uber\UberTicketRubriques::attacher($une), json_encode($options, JSON_UNESCAPED_UNICODE));
        }
    }
}
