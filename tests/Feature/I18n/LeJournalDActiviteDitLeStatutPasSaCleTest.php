<?php

namespace Tests\Feature\I18n;

use App\Enums\OrderStatus;
use Tests\TestCase;

/**
 * [ONB-11 2026-08-28] Le journal d'activité dit le statut, pas sa clé.
 *
 * ═══ LE DÉFAUT ═══
 *
 * `OrderService.php:2650` écrit dans `action_logs` :
 *
 *     'Nouveau statut: %s' avec trans('all.order.status.' . $status)
 *
 * Or `all.order.status` était un tableau **vide** — même en français. `trans()`
 * rend la clé quand elle manque. Mesure sur la base en service le 2026-08-28 :
 * **469 entrées** de la forme « Nouveau statut: all.order.status.16 ».
 *
 * Le journal d'activité est ce que le commerçant lit pour savoir **qui a changé
 * quoi**. Le rendre illisible revient à ne pas en avoir — et personne ne s'en
 * apercevait, parce qu'un journal qu'on ne lit pas ne se plaint jamais.
 *
 * ═══ CE QUI REND CE DÉFAUT INSTRUCTIF ═══
 *
 * Le côté caisse l'avait déjà rencontré et **contourné** :
 * `PosOrdersTrackerComponent.vue:1620` documente noir sur blanc que
 * « `all.order.status.X` n'existe QUE côté PHP » et se rabat sur des libellés en
 * dur. Le contournement a résolu l'écran qui gênait et laissé la cause intacte —
 * si bien que le journal a continué d'écrire des clés pendant des mois.
 *
 * ═══ POURQUOI CE BANC REGARDE LES TROIS LANGUES ═══
 *
 * Un audit adverse a relevé que mon premier garde-fou ne comparait que `auth.php`
 * et seulement les clés **racine** : les sous-clés lui échappaient, et c'est
 * exactement une sous-clé qui manquait ici.
 */
class LeJournalDActiviteDitLeStatutPasSaCleTest extends TestCase
{
    private const LANGUES_SERVIES = ['fr', 'en', 'ar'];

    /**
     * Dette de traduction MESURÉE, à ne jamais relever.
     *
     * L'arabe accuse 88 clés de retard dans `all.php`. Les traduire en masse à
     * l'aveugle serait pire que le trou : on obtiendrait 88 phrases dont personne
     * n'a vérifié le sens, avec l'air d'être fini.
     *
     * Un cliquet est la réponse honnête : il nomme la dette, empêche qu'elle
     * grandisse, et laisse la traduction se faire par lots relus. Chaque lot
     * abaisse ce nombre. **On ne le relève jamais.**
     *
     * L'anglais est à zéro depuis le 2026-08-28 : toute clé qui y manquerait
     * s'afficherait brute et fait donc échouer ce banc immédiatement.
     */
    private const DETTE_CONNUE = [
        'ar/all.php' => 88,
    ];

    private const STATUTS = [
        OrderStatus::PENDING,
        OrderStatus::ACCEPT,
        OrderStatus::PREPARING,
        OrderStatus::PREPARED,
        OrderStatus::OUT_FOR_DELIVERY,
        OrderStatus::DELIVERED,
        OrderStatus::CANCELED,
        OrderStatus::REJECTED,
        OrderStatus::RETURNED,
    ];

    public function test_chaque_statut_a_un_libelle_dans_chaque_langue_servie(): void
    {
        foreach (self::LANGUES_SERVIES as $langue) {
            foreach (self::STATUTS as $statut) {
                $libelle = trans('all.order.status.' . $statut, [], $langue);

                $this->assertNotSame(
                    'all.order.status.' . $statut,
                    $libelle,
                    "En `{$langue}`, le statut {$statut} n'a pas de libellé.\n"
                    . "Le journal d'activité écrirait « Nouveau statut: all.order.status."
                    . "{$statut} », et le commerçant ne saurait pas ce qui s'est passé."
                );

                $this->assertNotSame('', trim((string) $libelle));
            }
        }
    }

    public function test_l_emetteur_utilise_toujours_cette_cle(): void
    {
        // Si quelqu'un remplace un jour `trans()` par une chaîne en dur — le
        // contournement déjà appliqué côté caisse — ce banc n'aurait plus rien à
        // protéger, et son vert deviendrait trompeur.
        $service = file_get_contents(app_path('Services/OrderService.php'));

        $this->assertStringContainsString(
            "trans('all.order.status.'",
            $service,
            "Plus personne n'émet `all.order.status` : soit le journal a changé de\n"
            . "forme, soit un libellé a été écrit en dur. Relire ce banc."
        );
    }

    public function test_aucune_langue_servie_ne_perd_de_cle_par_rapport_au_francais(): void
    {
        // ═══ LE GARDE-FOU ÉLARGI ═══
        //
        // Mon premier banc ne comparait que `auth.php`, et seulement les clés
        // RACINE. Un audit adverse l'a relevé : les sous-clés lui échappaient, et
        // c'est précisément une sous-clé (`order.status`) qui manquait ici.
        //
        // On compare désormais TOUS les fichiers, à TOUTE profondeur.
        // PÉRIMÈTRE ASSUMÉ, et c'est le point délicat de ce banc.
        //
        // Ma première version comparait TOUS les fichiers : elle exigeait alors
        // `validation.attributes.*` en anglais. Or Laravel s'y rabat sur le NOM DU
        // CHAMP, qui se lit correctement en anglais (« The email field is
        // required »). Le banc réclamait donc un travail sans effet, et un banc qui
        // crie pour rien finit ignoré — c'est la même faute que trop peu couvrir,
        // en sens inverse.
        //
        // On couvre les fichiers dont une clé absente atteint VRAIMENT un humain
        // sous forme brute : messages applicatifs et libellés d'énumération.
        $enPerimetre = ['all.php', 'auth.php', 'payment_status.php',
                        'pos_payment_method.php', 'statuse.php', 'order_status.php'];

        $fichiers = array_values(array_filter(
            glob(lang_path('fr/*.php')),
            fn (string $c): bool => in_array(basename($c), $enPerimetre, true)
        ));

        $this->assertNotEmpty($fichiers, 'Aucun fichier de langue française en périmètre.');

        foreach ($fichiers as $chemin) {
            $nom = basename($chemin);
            $reference = $this->aplatir(require $chemin);

            foreach (self::LANGUES_SERVIES as $langue) {
                if ($langue === 'fr') {
                    continue;
                }

                $equivalent = lang_path($langue . '/' . $nom);

                if (! is_file($equivalent)) {
                    continue; // fichier absent = sujet distinct, pas une perte de clé
                }

                $traduit = $this->aplatir(require $equivalent);

                // Les clés vides sont un artefact des fichiers d'énumération (une
                // entrée `'' => ''` traîne dans plusieurs) : les compter ferait
                // rougir le banc sur du bruit.
                $attendues = array_filter(array_keys($reference), fn ($c) => trim((string) $c) !== '');
                $absentes = array_diff($attendues, array_keys($traduit));

                $plafond = self::DETTE_CONNUE[$langue . '/' . $nom] ?? 0;

                $this->assertLessThanOrEqual(
                    $plafond,
                    count($absentes),
                    "Dans `{$langue}/{$nom}`, ces clés s'afficheraient BRUTES :\n  - "
                    . implode("\n  - ", array_slice($absentes, 0, 12))
                    . (count($absentes) > 12 ? "\n  … et " . (count($absentes) - 12) . ' autres' : '')
                    . "\n\nLaravel ne se rabat pas sur le français : il rend la clé."
                    . "\nPlafond admis pour ce fichier : {$plafond}. Ne jamais le RELEVER —"
                    . "\nseulement l'abaisser en traduisant."
                );
            }
        }
    }

    /**
     * Aplatit un tableau de traductions en clés pointées, à toute profondeur.
     *
     * @param  array<string|int, mixed>  $tableau
     * @return array<string, string>
     */
    private function aplatir(array $tableau, string $prefixe = ''): array
    {
        $plat = [];

        foreach ($tableau as $cle => $valeur) {
            $chemin = $prefixe === '' ? (string) $cle : $prefixe . '.' . $cle;

            if (is_array($valeur)) {
                $plat += $this->aplatir($valeur, $chemin);
                continue;
            }

            $plat[$chemin] = (string) $valeur;
        }

        return $plat;
    }
}
