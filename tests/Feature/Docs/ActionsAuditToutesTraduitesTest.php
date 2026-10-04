<?php

namespace Tests\Feature\Docs;

use Tests\TestCase;

/**
 * Sentinelle — toute action d'audit déclarée dans le code a son libellé français.
 *
 * LE DÉFAUT, relevé à l'écran le 2026-10-04 : le widget « Audit Trail NF525 » du tableau de
 * bord traduit chaque action via `label.audit_event_<action avec . -> _>` et, faute de clé,
 * affiche l'action TELLE QUELLE (`AuditTrailComponent::translateAction`). Résultat : un
 * identifiant technique — `order.counter_pending_purged` — montré à l'exploitant dans la
 * colonne « Action », au milieu de libellés français.
 *
 * Les 14 actions présentes en production étaient toutes traduites : le défaut était LATENT.
 * Il suffit qu'une action soit ajoutée sans libellé — c'est arrivé deux fois, la purge des
 * commandes d'encaissement périmées et l'extra offert (`order.line_offered`) — pour qu'elle
 * s'affiche en code brut dès qu'elle est journalisée. Aucun test ne peut le voir : l'absence
 * d'une clé de traduction ne lève rien, et le widget a un repli silencieux.
 *
 * Le contrôle est à la SOURCE : il lit les constantes d'action déclarées dans `app/`
 * (`const AUDIT_ACTION = '…'` ou `const ACTION = '…'`) et exige une clé pour chacune. Il
 * n'énumère aucune action — il ne casse donc pas quand une action est ajoutée AVEC son libellé,
 * et il échoue le jour où on l'oublie.
 */
class ActionsAuditToutesTraduitesTest extends TestCase
{
    /** Les actions déclarées en constante, avec le fichier qui les porte. */
    private function actionsDeclarees(): array
    {
        $trouvees = [];
        $iterateur = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterateur as $fichier) {
            if ($fichier->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($fichier->getPathname());
            if (preg_match_all(
                "/const\s+(?:AUDIT_)?ACTION\s*=\s*'([a-z_]+(?:\.[a-z_]+)+)'/",
                $source,
                $m,
            )) {
                foreach ($m[1] as $action) {
                    $trouvees[$action] = str_replace(base_path() . '/', '', $fichier->getPathname());
                }
            }
        }
        ksort($trouvees);

        return $trouvees;
    }

    /** @test */
    public function le_banc_voit_bien_des_actions(): void
    {
        // Garde-fou : si le motif ne trouve plus rien, le contrôle suivant devient trivial.
        $this->assertGreaterThanOrEqual(
            3,
            count($this->actionsDeclarees()),
            "Aucune constante d'action d'audit trouvée : le motif de ce banc ne correspond plus "
            . 'à la façon dont le code déclare ses actions.',
        );
    }

    /** @test */
    public function chaque_action_d_audit_a_son_libelle_francais(): void
    {
        $fr = json_decode((string) file_get_contents(base_path('resources/js/languages/fr.json')), true);
        $labels = $fr['label'] ?? [];

        $sansLibelle = [];
        foreach ($this->actionsDeclarees() as $action => $fichier) {
            $cle = 'audit_event_' . str_replace('.', '_', $action);
            if (! isset($labels[$cle]) || trim((string) $labels[$cle]) === '') {
                $sansLibelle[] = "{$action}  (déclarée dans {$fichier}, clé attendue : label.{$cle})";
            }
        }

        $this->assertSame(
            [],
            $sansLibelle,
            "Ces actions d'audit s'afficheront en CODE BRUT dans le widget « Audit Trail NF525 » "
            . "du tableau de bord (repli silencieux de translateAction) :\n  - "
            . implode("\n  - ", $sansLibelle)
            . "\nAjouter la clé dans resources/js/languages/fr.json.",
        );
    }
}
