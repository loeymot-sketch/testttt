<?php

namespace Tests\Feature\Menu;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Console\Commands\MenuResetLeCayenneCommand;
use Tests\TestCase;

/**
 * [SUPERVISION 2026-08-22] `menu:reset-le-cayenne` NE DOIT PAS POUVOIR CRÉER DE DOUBLONS.
 *
 * D'OÙ VIENT CE CAS
 * Le 2026-08-19, un correctif a rattrapé UNE constante : `galette-cayenne` figée à 7,00 €
 * alors que la base vend 7,40 €. `createOrRestoreItem()` faisant un `update()` sur un slug
 * existant, la prochaine réinitialisation aurait silencieusement annulé le changement de
 * tarif. Le correctif était juste — mais il a été posé SANS test, et sa portée s'arrêtait à
 * cette ligne. Le même mécanisme frappe les douze autres articles du spec, et pas seulement
 * sur le prix : il RESSUSCITE aussi ce qui a été retiré de la vente.
 *
 * MESURÉ AVANT D'ÉCRIRE CE TEST (catalogue `foodking_e2e`, 2026-08-22) : une réinitialisation
 * remettrait en vente 7 produits retirés (5 bols supprimés le 2026-05-28, `big-tacos-2-viandes`
 * et `sandwich-classique-faluche` désactivés), créerait 2 articles absents — dont un
 * « Sandwich Cayenne » à 7,00 € face au vrai `cayenne` #22 à 7,40 € — et laisserait DEUX
 * articles ACTIFS nommés « Sandwich Classique » à deux prix différents.
 *
 * CE QUE CE FICHIER VERROUILLE
 * 1. la garde bloque et n'écrit RIEN ;
 * 2. `--allow-drift` reste la seule porte de sortie, distincte de `--force` (qui ne saute que
 *    la question de confirmation — les confondre rendrait la garde inopérante en script) ;
 * 3. un catalogue conforme passe sans bruit (sinon la garde serait un mur permanent) ;
 * 4. l'écrasement de prix — le défaut d'origine — est détecté ;
 * 5. `SPEC_ITEMS` ne peut pas se désynchroniser de ce que step 9 écrit vraiment.
 */
class MenuResetDriftGuardTest extends TestCase
{
    use RefreshDatabase;

    private const COMMANDE = 'menu:reset-le-cayenne';

    /** Échec ORDINAIRE (1) : la commande est allée au-delà de la garde, puis a buté ailleurs. */
    private const FAILURE_AUTRE_CAUSE = 1;

    /** Le pré-vol exige au moins une catégorie active ; sinon il s'arrête avant la garde. */
    private function catalogueMinimal(): ItemCategory
    {
        return ItemCategory::create([
            'name' => 'Sandwichs',
            'slug' => 'sandwichs',
            'status' => Status::ACTIVE,
        ]);
    }

    private function article(array $attrs): Item
    {
        return Item::factory()->create($attrs + [
            'status' => Status::ACTIVE,
            'price' => 7.40,
        ]);
    }

    /**
     * Le rapport de dérive, tel que la commande le calcule avant d'écrire. On affirme sur CE
     * tableau plutôt que sur la sortie console : dans ce dépôt, `Artisan::output()` revient
     * vide même pour `artisan env`, donc une assertion sur du texte prouverait surtout que le
     * texte n'a pas été capturé.
     */
    private function derive(): array
    {
        return MenuResetLeCayenneCommand::catalogueDriftReport();
    }

    /** @return list<array{slug:string,name:string,price:float}> */
    private function specItems(): array
    {
        return (new \ReflectionClass(MenuResetLeCayenneCommand::class))->getConstant('SPEC_ITEMS');
    }

    private function toutesLesLignes(array $drift): string
    {
        return implode("\n", array_merge(...array_values($drift)));
    }

    // ── 1. La garde bloque, et n'écrit rien ────────────────────────────────────
    public function test_un_doublon_de_nom_actif_bloque_la_reinitialisation(): void
    {
        $this->catalogueMinimal();
        // Le vrai Sandwich Classique, actif, à 7,40 €.
        $this->article(['name' => 'Sandwich Classique', 'slug' => 'sandwich-classique', 'price' => 7.40]);
        // L'ancien, désactivé, que le spec ressusciterait à 6,50 € sous le MÊME nom.
        $this->article(['name' => 'Sandwich Classique', 'slug' => 'sandwich-classique-faluche', 'price' => 6.50, 'status' => Status::INACTIVE]);

        $drift = $this->derive();
        $this->assertNotEmpty($drift['dupes'], 'Le doublon de nom doit être vu AVANT toute écriture.');
        $this->assertStringContainsString('Sandwich Classique', $this->toutesLesLignes($drift));

        $avant = Item::withTrashed()->count();
        $this->artisan(self::COMMANDE, ['--force' => true])
            ->assertExitCode(MenuResetLeCayenneCommand::EXIT_CATALOGUE_DRIFT);

        $this->assertSame($avant, Item::withTrashed()->count(), 'ABORT doit signifier ZÉRO écriture.');
        $this->assertSame(
            (int) Status::INACTIVE,
            (int) Item::where('slug', 'sandwich-classique-faluche')->value('status'),
            "L'article retiré de la vente doit le rester."
        );
    }

    public function test_un_produit_retire_de_la_vente_ne_ressuscite_pas_en_silence(): void
    {
        $this->catalogueMinimal();
        $bol = $this->article(['name' => 'Bol Curry', 'slug' => 'bol-curry', 'price' => 10.50]);
        $bol->delete(); // retiré de la carte, comme les 5 bols du 2026-05-28

        $drift = $this->derive();
        $this->assertNotEmpty($drift['resurrect']);
        $this->assertStringContainsString('bol-curry', $this->toutesLesLignes($drift));

        $this->artisan(self::COMMANDE, ['--force' => true])
            ->assertExitCode(MenuResetLeCayenneCommand::EXIT_CATALOGUE_DRIFT);

        $this->assertNotNull(
            Item::withTrashed()->where('slug', 'bol-curry')->value('deleted_at'),
            'Le bol supprimé doit rester supprimé.'
        );
    }

    // ── 2. L'écrasement de prix — le défaut d'origine du 2026-08-19 ────────────
    public function test_un_prix_de_base_different_du_spec_est_signale(): void
    {
        $this->catalogueMinimal();
        // La Galette Cayenne vendue 9,90 € alors que le spec en fige 7,40 €.
        $this->article(['name' => 'Galette Cayenne', 'slug' => 'galette-cayenne', 'price' => 9.90]);

        $drift = $this->derive();
        $this->assertNotEmpty($drift['price'], "L'écrasement silencieux d'un tarif doit être vu.");
        $this->assertStringContainsString('9,90 € en base serait ÉCRASÉ par 7,40 €', $this->toutesLesLignes($drift));

        $this->artisan(self::COMMANDE, ['--force' => true])
            ->assertExitCode(MenuResetLeCayenneCommand::EXIT_CATALOGUE_DRIFT);
        $this->assertEquals(9.90, (float) Item::where('slug', 'galette-cayenne')->value('price'));
    }

    // ── 3. Un catalogue conforme passe : la garde n'est pas un mur permanent ───
    /**
     * Une garde qui ne peut JAMAIS être satisfaite n'est pas une garde, c'est un mur : on
     * finirait par toujours passer `--allow-drift`, et elle ne dirait plus rien. Ce cas prouve
     * qu'un catalogue aligné sur le spec traverse le pré-vol en silence.
     */
    public function test_un_catalogue_conforme_ne_declenche_aucune_derive(): void
    {
        $this->catalogueMinimal();
        foreach ($this->specItems() as $spec) {
            $this->article(['name' => $spec['name'], 'slug' => $spec['slug'], 'price' => $spec['price']]);
        }

        $drift = $this->derive();

        $this->assertSame('', $this->toutesLesLignes($drift), 'Un catalogue conforme ne doit produire AUCUNE ligne de dérive.');
    }

    /**
     * Une base VIDE de tout article actif = première installation : les règles « créerait » ne
     * s'appliquent pas, sinon la commande ne pourrait plus jamais servir à ce pour quoi elle
     * a été écrite.
     */
    public function test_une_base_sans_article_actif_n_est_pas_traitee_comme_une_derive(): void
    {
        $this->catalogueMinimal();

        $drift = $this->derive();

        $this->assertEmpty($drift['create'], 'Une première installation ne doit pas être vue comme une dérive.');
        $this->assertSame('', $this->toutesLesLignes($drift));
    }

    /** Le spec vise un slug absent alors que le catalogue vit déjà : c'est un AJOUT, pas un reset. */
    public function test_un_slug_absent_dans_un_catalogue_vivant_est_signale_comme_creation(): void
    {
        $this->catalogueMinimal();
        // Le vrai sandwich signature, sous SON slug — celui du spec n'existe pas.
        $this->article(['name' => 'Cayenne', 'slug' => 'cayenne', 'price' => 7.40]);

        $drift = $this->derive();

        $this->assertNotEmpty($drift['create']);
        $this->assertStringContainsString('sandwich-cayenne-classique', $this->toutesLesLignes($drift));
        $this->assertStringContainsString('« Sandwich Cayenne » à 7,00 € serait CRÉÉ', $this->toutesLesLignes($drift));
    }

    // ── 4. --allow-drift est la SEULE porte de sortie, --force n'en est pas une ─
    public function test_force_ne_franchit_pas_la_garde_mais_allow_drift_si(): void
    {
        $this->catalogueMinimal();
        $this->article(['name' => 'Galette Cayenne', 'slug' => 'galette-cayenne', 'price' => 9.90]);

        // --force ne saute QUE la question de confirmation. Les confondre rendrait la garde
        // inopérante dès qu'un script appelle la commande.
        $this->artisan(self::COMMANDE, ['--force' => true])
            ->assertExitCode(MenuResetLeCayenneCommand::EXIT_CATALOGUE_DRIFT);
        $this->assertEquals(9.90, (float) Item::where('slug', 'galette-cayenne')->value('price'));

        // Avec --allow-drift la commande dépasse la garde. Elle échoue ensuite pour une AUTRE
        // raison (ce catalogue minimal n'a pas les articles d'addon exigés par step 9), et cet
        // échec-là se produit DANS la transaction : rien n'est écrit non plus. Ce qui compte
        // ici, c'est que la garde ne soit plus ce qui l'arrête.
        $this->artisan(self::COMMANDE, ['--force' => true, '--allow-drift' => true])
            ->assertExitCode(self::FAILURE_AUTRE_CAUSE);
        $this->assertEquals(
            9.90,
            (float) Item::where('slug', 'galette-cayenne')->value('price'),
            'La transaction ayant échoué, le prix ne doit toujours pas avoir bougé.'
        );
    }

    // ── 5. SPEC_ITEMS ne peut pas se désynchroniser de step 9 ──────────────────
    /**
     * `SPEC_ITEMS` est une RECOPIE de ce que `step9CreateNewItems()` écrit. Une recopie dérive :
     * un article ajouté à step 9 sans être déclaré passerait sous le radar de la garde, en
     * silence. Ce cas relit donc le fichier source et compare les slugs réellement écrits.
     */
    public function test_spec_items_couvre_tous_les_articles_ecrits_par_step9(): void
    {
        $source = file_get_contents(app_path('Console/Commands/MenuResetLeCayenneCommand.php'));
        $this->assertNotFalse($source);

        $debut = strpos($source, 'private function step9CreateNewItems(): void');
        $fin = strpos($source, 'private function step10CreateBolsComposerProfiles(): void');
        $this->assertNotFalse($debut, 'step9CreateNewItems introuvable — ce test doit être remis à jour.');
        $this->assertNotFalse($fin, 'step10CreateBolsComposerProfiles introuvable — ce test doit être remis à jour.');

        $corpsStep9 = substr($source, $debut, $fin - $debut);
        preg_match_all("/'slug'\s*=>\s*'([a-z0-9-]+)'/", $corpsStep9, $m);
        $slugsEcrits = array_values(array_unique($m[1]));
        $this->assertNotEmpty($slugsEcrits, 'Aucun slug littéral trouvé dans step 9 — le test ne prouverait rien.');

        $reflection = new \ReflectionClass(\App\Console\Commands\MenuResetLeCayenneCommand::class);
        $specSlugs = array_column($reflection->getConstant('SPEC_ITEMS'), 'slug');

        sort($slugsEcrits);
        sort($specSlugs);
        $this->assertSame(
            $slugsEcrits,
            $specSlugs,
            'SPEC_ITEMS a dérivé de ce que step 9 écrit vraiment : la garde ne couvrirait plus tout.'
        );
    }
}
