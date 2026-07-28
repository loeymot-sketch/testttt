<?php

use App\Console\Commands\EnsureFritesSauceStepCommand;
use App\Console\Commands\EnsureKidsMenuStepsCommand;
use App\Console\Commands\EnsureViandeSupplementExtrasCommand;
use Illuminate\Database\Migrations\Migration;

/**
 * [OWNER 2026-07-28] 3 plaintes wizard borne+caisse (test live) → fix DATA/CONFIG-only (0 frozen).
 * Approche « comme un sandwich », SANS profil composer publié (l'approche à-profil introduisait une
 * régression 422 — la caisse renderSinglePage facture toujours la 2ᵉ sauce via l'extra générique
 * group_label='sauce', que le validateur belongs-to-published-profile rejette ; attrapée par 2 agents
 * adversaires) :
 *
 *  P1 — FRITES sans option sauce : {@see EnsureFritesSauceStepCommand::ensure()} ajoute l'attribut
 *       sauce (variations prix 0) sur cat 7 + bascule la catégorie en wizard_template 'snacking'
 *       (borne affiche l'étape sauce par data-gating ; has_menu=false → 0 étape parasite) + « Sauce
 *       supplémentaire » @0,50 (2ᵉ sauce). Caisse : hasSauce data-driven. Sans profil ⇒ affiché==scellé.
 *
 *  P2 — MENU ENFANT CHICKEN BURGER n'affichait que crudités : {@see EnsureKidsMenuStepsCommand} ajoute
 *       l'attribut sauce (Nuggets+Burger) + crudités/suppléments (Burger) + bascule la catégorie
 *       menu-enfant en 'sandwich' → borne = [sauce] (Nuggets) / [sauce, garnitures, supplements]
 *       (Burger) par data-gating. Corrige AUSSI la régression 422 pré-existante du Nuggets.
 *
 *  P3 — SUPPLÉMENT VIANDE indisponible sur 5 composables (Sandwich Classique/Big Tacos/Big Cayenne/
 *       Big Classique/Big Chicken) : {@see EnsureViandeSupplementExtrasCommand::ensure()} pose
 *       « Viande supplémentaire » @2,50 sur chaque item à attribut viande → dépassement NOMMÉ possible.
 *
 * Toutes idempotentes et re-jouables. Guard class_exists : replay-safe si une commande est renommée.
 * Rollback : re-publier les profils dépubliés + rebasculer les templates (les data restent inertes).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (class_exists(EnsureViandeSupplementExtrasCommand::class)) {
            EnsureViandeSupplementExtrasCommand::ensure(false);
        }

        if (class_exists(EnsureFritesSauceStepCommand::class)) {
            EnsureFritesSauceStepCommand::ensure(false);
        }

        // Re-joue la commande kids (étendue) pour appliquer le nouveau step `sauce` du
        // chicken burger — la migration jumelle 2026_07_17 a déjà tourné sans lui.
        if (class_exists(EnsureKidsMenuStepsCommand::class)) {
            EnsureKidsMenuStepsCommand::ensure(false);
        }
    }

    public function down(): void
    {
        // No-op réversible : dépublier les profils composer frites/kids revient à
        // l'heuristique borne (les data — variations sauce, extras — restent inertes,
        // routées seulement par un profil publié ou la détection data-driven caisse).
    }
};
