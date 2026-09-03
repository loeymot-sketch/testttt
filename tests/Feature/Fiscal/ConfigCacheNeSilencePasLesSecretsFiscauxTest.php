<?php

namespace Tests\Feature\Fiscal;

use App\Providers\AppServiceProvider;
use Tests\TestCase;

/**
 * [2026-09-03 · superviseur] `config:cache` signait la chaîne fiscale avec le MAUVAIS secret,
 * en silence, et définitivement.
 *
 * Le mécanisme, mesuré en production le 2026-09-03 et inversé deux fois pour le prouver :
 * `AuditLogService::secretFor()` lit la surcharge par branche via `env('FISCAL_AUDIT_SECRET_BRANCH_{id}')`.
 * Après `config:cache`, Laravel ne charge plus `.env` — `env()` rend `null`, et la signature
 * retombe sans bruit sur le secret par défaut. Une commande encaissée pendant cette fenêtre est
 * signée avec un autre secret que ses voisines, et `audit_logs` est append-only : la scission
 * est définitive.
 *
 * Douze procédures du dépôt prescrivent `config:cache`. Aucune ne prévenait.
 *
 * POURQUOI UN GARDE ET PAS LE CORRECTIF DE FOND — le correctif propre serait que la
 * configuration publie les secrets par branche, puisque le service sait déjà lire un tableau
 * (`AuditLogService.php:332`). Mais son repli sur le secret par défaut ne s'applique QUE si la
 * valeur est une chaîne (`:335`) : publier un tableau ferait échouer toutes les branches SANS
 * surcharge — en production, les branches 2, 7, 8, 9 et 10. Le service est en zone gelée
 * (CLAUDE.md §7) : compléter ce repli demande un LOCK contresigné. D'ici là, le garde rend
 * l'état dangereux IMPOSSIBLE au lieu de le laisser silencieux.
 *
 * Ce banc ne teste pas `config:cache` lui-même — il teste que la configuration SAIT quelles
 * branches portent une surcharge, ce qui est la seule information qui survit à la mise en cache
 * et permet au garde de boot de détecter la perte.
 */
class ConfigCacheNeSilencePasLesSecretsFiscauxTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (array_keys($_ENV) as $cle) {
            if (str_starts_with((string) $cle, 'FISCAL_AUDIT_SECRET_BRANCH_')) {
                unset($_ENV[$cle], $_SERVER[$cle]);
                putenv((string) $cle);
            }
        }

        parent::tearDown();
    }

    /**
     * La configuration doit recenser les branches qui portent une surcharge. C'est cette liste,
     * et elle seule, qui survit à `config:cache` : sans elle, une fois le cache chaud, rien ne
     * permet de distinguer « aucune surcharge n'a jamais existé » de « la surcharge est perdue ».
     */
    public function test_la_configuration_recense_les_branches_qui_portent_une_surcharge(): void
    {
        $_ENV['FISCAL_AUDIT_SECRET_BRANCH_1'] = str_repeat('a', 48);
        $_ENV['FISCAL_AUDIT_SECRET_BRANCH_7'] = str_repeat('b', 48);

        $recensees = require base_path('config/fiscal.php');

        $this->assertSame(
            [1, 7],
            $recensees['audit_secret_branch_ids'],
            'La configuration doit recenser les identifiants de branche porteurs d’une surcharge, '
            .'triés, afin que le garde de boot puisse détecter leur disparition après mise en cache.'
        );
    }

    /**
     * Un dépôt sans surcharge — le cas de tout poste de développement — ne doit rien recenser,
     * sinon le garde se déclencherait à tort partout.
     */
    public function test_sans_surcharge_la_liste_est_vide(): void
    {
        $recensees = require base_path('config/fiscal.php');

        $this->assertSame(
            [],
            $recensees['audit_secret_branch_ids'],
            'Sans surcharge déclarée, la liste doit être vide : un garde qui se déclenche sur '
            .'une installation saine finit désarmé.'
        );
    }

    /**
     * Le type de `audit_secret` ne change PAS. Le passer en tableau ferait échouer les branches
     * sans surcharge, puisque le repli du service gelé exige une chaîne.
     */
    public function test_le_type_du_secret_par_defaut_reste_une_chaine(): void
    {
        $_ENV['FISCAL_AUDIT_SECRET_BRANCH_1'] = str_repeat('a', 48);

        $recensees = require base_path('config/fiscal.php');

        $this->assertIsString(
            $recensees['audit_secret'],
            'AuditLogService.php:335 ne retombe sur le secret par défaut que si la valeur est '
            .'une CHAÎNE. En faire un tableau ferait échouer toute branche sans surcharge.'
        );
    }

    /**
     * Le garde de boot doit exister dans le fournisseur, avec un message qui nomme la cause.
     * Un garde dont le message ne dit pas quoi faire se fait contourner à 3 h du matin.
     */
    public function test_le_garde_de_boot_existe_et_nomme_la_cause(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(AppServiceProvider::class))->getFileName()
        );

        $this->assertStringContainsString(
            'audit_secret_branch_ids',
            $source,
            'Le fournisseur doit consulter la liste des branches recensées.'
        );
        $this->assertStringContainsString(
            'configurationIsCached',
            $source,
            'Le garde doit détecter la mise en cache de la configuration.'
        );
        $this->assertMatchesRegularExpression(
            '/config:clear/',
            $source,
            'Le message doit dire quoi faire — `config:clear` — et pas seulement constater.'
        );
    }
}
