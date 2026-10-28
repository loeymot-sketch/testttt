<?php

namespace Tests\Feature\Settings;

use Tests\TestCase;

/**
 * [GOAL-OPS-SWAP W2 2026-08-12 — constat RÉGLAGES-ORPHELINS]
 *
 * UN RÉGLAGE ORPHELIN est une clé que l'écran d'administration fait SAISIR et
 * ENREGISTRER, mais que plus aucun code ne LIT. L'exploitant croit piloter
 * quelque chose ; il ne pilote rien.
 *
 * Ce n'est pas théorique dans ce dépôt : deux interrupteurs de remise, faux par
 * défaut et absents du `.env`, ont fait émettre des codes promo ensuite refusés
 * à la commande (2026-08-10). Même famille de défaut.
 *
 * CE BANC EST UN CLIQUET — il échoue dans les DEUX sens :
 *   · un réglage NEUF que rien ne lit apparaît   → « tu ajoutes une promesse vide »
 *   · un orphelin connu gagne enfin un lecteur   → « retire-le de la liste »
 *
 * Il ne se contente donc pas de documenter la dette : il empêche qu'elle croisse
 * ET il force à la réduire proprement.
 *
 * MÉTHODE : les classes `*Request` des réglages déclarent exactement les clés
 * que l'admin peut écrire. Pour chacune, on cherche un CONSOMMATEUR — un fichier
 * qui la lit ailleurs que sur le chemin d'écriture (validateur, service
 * d'écriture, ressource d'exposition, seeder, le formulaire de réglages
 * lui-même, les catalogues de libellés).
 */
class OrphanSettingsRatchetSentinelTest extends TestCase
{
    /** Les formulaires de réglages : ils déclarent les clés écrivables. */
    private const REQUETES_DE_REGLAGES = [
        'CompanyRequest.php',
        'CookiesRequest.php',
        'KioskSetupRequest.php',
        'LoyaltySetupRequest.php',
        'NotificationRequest.php',
        'OrderSetupRequest.php',
        'OtpRequest.php',
        'SiteRequest.php',
        'SocialMediaRequest.php',
    ];

    /**
     * ORPHELINS CONNUS AU 2026-08-12 — vérifiés un par un, à la main, en base
     * et par grep. Chacun est une décision owner en attente : implémenter le
     * lecteur, ou retirer le champ. Il n'y a pas de troisième voie.
     *
     * ⛔ N'ajoute JAMAIS une clé ici pour faire passer le banc. Une addition
     *    signifie « j'ai livré un réglage qui ne pilote rien » — c'est le
     *    défaut, pas la solution.
     */
    private const ORPHELINS_CONNUS = [
        // ① Un code à 4 chiffres présenté comme protégeant l'admin de la borne.
        //    `SettingResource` renvoie littéralement `null` ; aucun écran ne le
        //    demande ni ne le vérifie. Une protection qui ne protège rien.
        'kiosk_admin_pin',

        // ② Barème de livraison : l'écran écrit ces 3 clés, mais le SEUL
        //    calculateur (`app/Services/Delivery/DeliveryFeeService.php`) lit
        //    les COLONNES de la table `branches`. Régler « 1 €/km » dans
        //    l'admin ne change aucun montant facturé.
        'order_setup_free_delivery_kilometer',
        'order_setup_basic_delivery_charge',
        'order_setup_charge_per_kilo',

        // ③ Deux interrupteurs muets, alors que leur jumeau
        //    `site_phone_verification` est lu et gardé jusqu'au preflight prod.
        'site_email_verification',
        'site_auto_update',

        // ④ Quatre champs OBLIGATOIRES à la saisie (`CompanyRequest`) que
        //    personne ne lit : l'adresse d'établissement n'est jamais composée
        //    en entier, y compris en en-tête de ticket.
        'company_city',
        'company_state',
        'company_zip_code',
        // [ONB-05 2026-08-28] `company_website` RETIRE de la liste : il a desormais un
        // lecteur. `OrderReceiptEscPosRenderer` lisait le site en dur depuis
        // `config/printing.php`, dont le defaut vaut `lecayenne.fr` — le ticket remis
        // au client portait donc l'adresse web d'un AUTRE restaurant, alors que le
        // commercant avait rempli son champ. Le ticket lit maintenant son reglage,
        // avec la configuration en repli.
        //
        // C'est ce banc qui a exige ce commit : il a vu le lecteur apparaitre et a
        // demande le resserrage, pour que la dette ne puisse pas revenir en silence.
    ];

    /** Chemins qui ÉCRIVENT ou exposent la clé — ce ne sont pas des lecteurs. */
    private function estCheminDEcriture(string $fichier): bool
    {
        return (bool) preg_match(
            '#(app/Http/Requests/.*Request\.php'
            .'|app/Services/.*/[A-Za-z]+Service\.php'
            .'|app/Http/Resources/.*Resource\.php'
            .'|database/seeders/'
            .'|resources/js/components/admin/settings/'
            .'|resources/js/languages/)#',
            $fichier
        );
    }

    /** @return string[] clés déclarées par les formulaires de réglages */
    private function clesEcrivables(): array
    {
        $cles = [];
        foreach (self::REQUETES_DE_REGLAGES as $fichier) {
            $chemin = base_path('app/Http/Requests/'.$fichier);
            if (!is_file($chemin)) {
                continue;
            }
            preg_match_all("/'([a-z][a-z0-9_]{3,})'\s*=>/", (string) file_get_contents($chemin), $m);
            foreach ($m[1] as $cle) {
                $cles[$cle] = true;
            }
        }

        return array_keys($cles);
    }

    /** @return string[] fichiers qui LISENT la clé hors chemin d'écriture */
    private function consommateurs(string $cle): array
    {
        $cmd = sprintf(
            'grep -rl --include=*.php --include=*.vue --include=*.js -F %s %s %s %s %s 2>/dev/null',
            escapeshellarg($cle),
            escapeshellarg(base_path('app')),
            escapeshellarg(base_path('config')),
            escapeshellarg(base_path('routes')),
            escapeshellarg(base_path('resources/js'))
        );
        $sortie = (string) shell_exec($cmd);

        $lecteurs = [];
        foreach (array_filter(explode("\n", $sortie)) as $fichier) {
            $relatif = str_replace(base_path().'/', '', $fichier);
            if (!$this->estCheminDEcriture($relatif)) {
                $lecteurs[] = $relatif;
            }
        }

        return $lecteurs;
    }

    public function test_le_cliquet_des_reglages_orphelins_ne_bouge_pas(): void
    {
        $orphelinsMesures = [];
        foreach ($this->clesEcrivables() as $cle) {
            if ($this->consommateurs($cle) === []) {
                $orphelinsMesures[] = $cle;
            }
        }

        sort($orphelinsMesures);
        $connus = self::ORPHELINS_CONNUS;
        sort($connus);

        $nouveaux = array_values(array_diff($orphelinsMesures, $connus));
        $repares = array_values(array_diff($connus, $orphelinsMesures));

        $this->assertSame(
            [],
            $nouveaux,
            "RÉGLAGE ORPHELIN NEUF — l'admin fait saisir une valeur que RIEN ne lit :\n  - "
            .implode("\n  - ", $nouveaux)
            ."\nDeux issues honnêtes : écrire le lecteur, ou retirer le champ de l'écran."
        );

        $this->assertSame(
            [],
            $repares,
            "CLIQUET À RESSERRER — ces réglages ont enfin un lecteur, retire-les de "
            ."ORPHELINS_CONNUS pour que la dette ne puisse plus revenir :\n  - "
            .implode("\n  - ", $repares)
        );
    }

    public function test_le_temoin_sain_prouve_que_la_detection_fonctionne(): void
    {
        // `site_phone_verification` est le JUMEAU lu de `site_email_verification`.
        // S'il ressortait orphelin, la détection serait cassée, pas le produit.
        $this->assertNotEmpty(
            $this->consommateurs('site_phone_verification'),
            'Témoin sain introuvable : la détection de consommateur est cassée, '
            .'pas le réglage. Corrige le banc avant de croire ses verdicts.'
        );
    }
}
