<?php

namespace Tests\Feature\Assistant;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [ONB-04 2026-08-28] L'assistant de missions locales : proposer, puis écrire.
 *
 * Le mandat le demande en toutes lettres — « chatbot de missions locales sur le
 * profil », avec pour exemple « ajoute une sauce à tous les tacos ». Il n'existait
 * pas : `grep -rln "chatbot\|missions locales"` rendait zéro fichier.
 *
 * ═══ CE QUE CE BANC PROTÈGE ═══
 *
 * Une mission locale touche cinquante produits d'un coup. Trois propriétés font
 * toute la différence entre un gain de temps et un accélérateur d'erreurs :
 *
 *   1. **La lecture n'écrit RIEN.** Le commerçant voit le diff avant de décider.
 *   2. **Le plan dit ce qu'il ÉCARTE**, pas seulement ce qu'il change. Un plan qui
 *      cache ses exclusions ment par omission : on croirait avoir couvert toute la
 *      catégorie.
 *   3. **Ce qui n'est pas compris est REFUSÉ**, en nommant les formes connues.
 *      « J'ai compris à peu près » sur cinquante produits se découvre trois jours
 *      plus tard, quand un client commande.
 *
 * L'interpréteur est déterministe — aucun appel sortant, donc aucun gate G-IA. Le
 * jour où un modèle prendra le relais, il remplacera UNIQUEMENT l'étape « comprendre
 * la phrase » : le plan, la confirmation et l'écriture validée ne bougent pas.
 */
class UneMissionLocaleProposeAvantDEcrireTest extends TestCase
{
    use RefreshDatabase;

    private User $karim;
    private ItemCategory $tacos;
    private Tax $taxe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->taxe = Tax::factory()->create(['tax_rate' => 10, 'status' => Status::ACTIVE]);
        $this->tacos = ItemCategory::factory()->create(['name' => 'Tacos', 'status' => Status::ACTIVE]);

        foreach (['Tacos poulet', 'Tacos mixte', 'Tacos merguez'] as $nom) {
            Item::factory()->create([
                'name'             => $nom,
                'item_category_id' => $this->tacos->id,
                'tax_id'           => $this->taxe->id,
                'status'           => Status::ACTIVE,
                'price'            => 8.50,
                'order'            => 1,
            ]);
        }

        $this->karim = User::factory()->create(['branch_id' => 0]);
        $this->karim->assignRole('Admin');
        foreach (['items', 'items_create', 'items_edit'] as $droit) {
            Permission::findOrCreate($droit, 'sanctum');
        }
        $this->karim->givePermissionTo(['items', 'items_create', 'items_edit']);
        $this->actingAs($this->karim, 'sanctum');
    }

    private function lire(string $phrase): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/admin/assistant/mission/lecture', ['phrase' => $phrase]);
    }

    private function appliquer(string $phrase, bool $confirme = true): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/admin/assistant/mission/application', [
            'phrase'       => $phrase,
            'confirmation' => $confirme ? 'true' : 'false',
        ]);
    }

    // ══════════════════════════════════════════════════ la phrase du mandat

    public function test_la_phrase_exacte_du_mandat_est_comprise_et_ne_change_rien(): void
    {
        $avant = ItemExtra::query()->count();

        $reponse = $this->lire('ajoute la sauce Algérienne à tous les tacos');

        $reponse->assertOk();
        $this->assertTrue($reponse->json('compris'), (string) $reponse->getContent());

        $plan = $reponse->json('plan');

        $this->assertSame('Tacos', $plan['categorie']);
        $this->assertCount(3, $plan['changements'], 'Les trois tacos doivent être listés.');
        $this->assertTrue($plan['applicable']);

        // LA PROPRIÉTÉ CENTRALE : lire ne doit RIEN écrire.
        $this->assertSame(
            $avant,
            ItemExtra::query()->count(),
            "La lecture a écrit en base. Le commerçant doit voir le diff AVANT de\n"
            . "décider : une mission touche cinquante produits, et « j'ai ajouté la\n"
            . "sauce à vos 47 tacos » n'est pas rattrapable en un clic."
        );
    }

    public function test_l_accent_et_la_casse_saisis_sont_conserves(): void
    {
        $plan = $this->lire('ajoute la sauce Algérienne à tous les tacos')->json('plan');

        // La normalisation sert à RECONNAÎTRE, jamais à écrire. « Algérienne » ne doit
        // pas devenir « algerienne » à l'écran ni en base.
        $this->assertStringContainsString('Algérienne', $plan['changements'][0]['apres']);
    }

    public function test_le_plan_dit_ce_qu_il_ecarte_et_pas_seulement_ce_qu_il_change(): void
    {
        $premier = Item::query()->where('name', 'Tacos poulet')->firstOrFail();

        ItemExtra::create([
            'item_id' => $premier->id,
            'name'    => 'Algérienne',
            'price'   => 0,
            'status'  => Status::ACTIVE,
        ]);

        $plan = $this->lire('ajoute la sauce Algérienne à tous les tacos')->json('plan');

        $this->assertCount(2, $plan['changements'], 'Deux produits restent à traiter.');

        $this->assertCount(
            1,
            $plan['ecartes'],
            "Un plan qui cache ses exclusions ment par omission : le commerçant\n"
            . 'croirait avoir couvert toute sa catégorie.'
        );

        $this->assertSame('Tacos poulet', $plan['ecartes'][0]['produit']);
        $this->assertStringContainsString('déjà', $plan['ecartes'][0]['raison']);
    }

    /**
     * [ONB-04 2026-08-28] LES DEUX CONJUGAISONS.
     *
     * `RegistreDeLangueCoherentTest` a mordu sur mon repère de saisie : il disait
     * « ajoute », tutoiement, alors que l'interface vouvoie partout ailleurs. Elle
     * avait raison — et la bonne réponse n'était pas de contourner le banc.
     *
     * Un commerçant que le produit vouvoie écrira spontanément « ajoutez ». Si la
     * grammaire ne comprend que « ajoute », il reçoit un refus sur la forme la plus
     * naturelle POUR LUI — et un refus, si soigné soit-il, reste un échec.
     *
     * @dataProvider lesDeuxConjugaisons
     */
    public function test_les_deux_conjugaisons_sont_comprises(string $phrase): void
    {
        $reponse = $this->lire($phrase);

        $reponse->assertOk();
        $this->assertTrue(
            $reponse->json('compris'),
            "« {$phrase} » doit être compris : " . (string) $reponse->getContent()
        );
    }

    /** @return array<string, array{0:string}> */
    public function lesDeuxConjugaisons(): array
    {
        return [
            'ajouter · vouvoiement'   => ['ajoutez la sauce Algérienne à tous les tacos'],
            'ajouter · tutoiement'    => ['ajoute la sauce Algérienne à tous les tacos'],
            'prix · vouvoiement'      => ['passez tous les tacos à 9,50 €'],
            'prix · tutoiement'       => ['passe tous les tacos à 9,50 €'],
            'désactiver · vouvoiement'=> ['désactivez tous les tacos'],
            'désactiver · tutoiement' => ['désactive tous les tacos'],
            'activer · vouvoiement'   => ['activez tous les tacos'],
        ];
    }

    public function test_activer_et_desactiver_ne_se_confondent_pas(): void
    {
        // `desactivez` commence par « desactive », `activez` par « active ». Comparer
        // sur le radical sans y penser confondrait les deux — et désactiver toute une
        // catégorie en croyant l'activer est la pire issue possible ici.
        $this->appliquer('désactivez tous les tacos')->assertOk();

        $this->assertSame(
            0,
            Item::query()->where('item_category_id', $this->tacos->id)
                ->where('status', Status::ACTIVE)->count()
        );

        $this->appliquer('activez tous les tacos')->assertOk();

        $this->assertSame(
            3,
            Item::query()->where('item_category_id', $this->tacos->id)
                ->where('status', Status::ACTIVE)->count()
        );
    }

    // ══════════════════════════════════════════════════ le refus

    public function test_une_phrase_incomprise_est_refusee_en_nommant_ce_qui_est_connu(): void
    {
        $reponse = $this->lire('fais-moi un café et range la réserve');

        $reponse->assertOk();
        $this->assertFalse($reponse->json('compris'));

        $texte = (string) $reponse->json('reponse');

        $this->assertStringContainsString('ajoutez la sauce', $texte);
        $this->assertStringContainsString('passez tous les', $texte);

        // « J'ai compris à peu près » sur cinquante produits se découvre trois jours
        // plus tard. On refuse, et on dit quoi écrire à la place.
        $this->assertNotEmpty($reponse->json('formes'));
    }

    public function test_une_categorie_inconnue_est_refusee_en_listant_les_vraies(): void
    {
        $plan = $this->lire('ajoute la sauce Blanche à tous les sushis')->json('plan');

        $this->assertFalse($plan['applicable']);
        $this->assertNull($plan['categorie']);
        $this->assertStringContainsString('Tacos', (string) $plan['avertissement']);
    }

    public function test_une_categorie_ambigue_est_refusee_plutot_que_devinee(): void
    {
        ItemCategory::factory()->create(['name' => 'Tacos du chef', 'status' => Status::ACTIVE]);

        // « tacos » correspond désormais à deux catégories. En choisir une au hasard
        // écrirait dans la mauvaise ; on préfère ne rien faire et le dire.
        $plan = $this->lire('ajoute la sauce Blanche à tous les tacos gourmands')->json('plan');

        $this->assertFalse($plan['applicable']);
    }

    // ══════════════════════════════════════════════════ l'écriture

    public function test_l_application_exige_une_confirmation_explicite(): void
    {
        $this->appliquer('ajoute la sauce Algérienne à tous les tacos', false)
            ->assertStatus(422);

        $this->assertSame(
            0,
            ItemExtra::query()->count(),
            "Une écriture de masse ne doit jamais partir d'un seul appel."
        );
    }

    public function test_l_application_ajoute_l_option_a_chaque_produit(): void
    {
        $reponse = $this->appliquer('ajoute la sauce Algérienne à tous les tacos');

        $reponse->assertOk();
        $this->assertSame(3, $reponse->json('rapport.applique'), (string) $reponse->getContent());
        $this->assertSame([], $reponse->json('rapport.echecs'));

        $this->assertSame(3, ItemExtra::query()->where('name', 'Algérienne')->count());

        // Le groupe vient du mot-clé de la phrase : c'est ce qui range l'option dans
        // la bonne étape du parcours client.
        $this->assertSame('Sauce', ItemExtra::query()->where('name', 'Algérienne')->value('group_label'));
        $this->assertEqualsWithDelta(0.0, (float) ItemExtra::query()->where('name', 'Algérienne')->value('price'), 0.001);
    }

    public function test_un_supplement_payant_porte_son_prix(): void
    {
        $this->appliquer("ajoute l'option Cheddar à tous les tacos pour 1,50 €")->assertOk();

        $this->assertEqualsWithDelta(
            1.50,
            (float) ItemExtra::query()->where('name', 'Cheddar')->value('price'),
            0.001,
            'La virgule décimale française doit être comprise.'
        );
    }

    public function test_le_changement_de_prix_passe_par_les_regles_du_catalogue(): void
    {
        $this->appliquer('passe tous les tacos à 9,50 €')->assertOk();

        foreach (Item::query()->where('item_category_id', $this->tacos->id)->get() as $produit) {
            $this->assertEqualsWithDelta(9.50, (float) $produit->price, 0.001);

            // L'écriture passe par `ItemRequest` : la taxe obligatoire posée par
            // ONB-02 tient toujours. Une porte d'écriture qui contournerait la
            // FormRequest rouvrirait le trou du 0 % silencieux.
            $this->assertNotNull($produit->tax_id);
        }
    }

    public function test_la_desactivation_en_masse_fonctionne_et_se_dit(): void
    {
        $reponse = $this->appliquer('désactive tous les tacos');

        $reponse->assertOk();
        $this->assertSame(3, $reponse->json('rapport.applique'));

        $this->assertSame(
            0,
            Item::query()
                ->where('item_category_id', $this->tacos->id)
                ->where('status', Status::ACTIVE)
                ->count()
        );
    }

    public function test_reappliquer_la_meme_mission_ne_double_rien(): void
    {
        $this->appliquer('ajoute la sauce Algérienne à tous les tacos');
        $reponse = $this->appliquer('ajoute la sauce Algérienne à tous les tacos');

        $this->assertSame(
            3,
            ItemExtra::query()->where('name', 'Algérienne')->count(),
            "Recliquer ne doit rien dupliquer : c'est ce qui rend l'assistant utilisable."
        );

        $this->assertSame(0, $reponse->json('rapport.applique'));
        $this->assertStringContainsString('Rien à', (string) $reponse->json('rapport.resume'));
    }
}
