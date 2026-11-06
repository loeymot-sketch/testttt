<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [UBER-DIRECT 2026-09-06 · owner] « Je veux ajouter la livraison au site : le client saisit
 * son adresse, Uber dit si c'est livrable et à quel prix, le client paie, un coursier Uber
 * vient chercher la commande. »
 *
 * POURQUOI UNE TABLE DÉDIÉE, ET NON DES COLONNES SUR `orders`
 * -----------------------------------------------------------
 * Consigne explicite du propriétaire : « Le webhook Uber concerne la LIVRAISON. Le KDS
 * continue de gérer la PRÉPARATION. Ne mélange pas les deux machines d'état. »
 *
 * Ce sont deux cycles de vie indépendants. Une commande peut être PRÊTE en cuisine pendant
 * que le coursier n'est pas encore arrivé ; elle peut être livrée alors que la cuisine a
 * bumpé depuis dix minutes. Les mêler dans `orders.status` obligerait à inventer des états
 * croisés et à toucher `OrderStateMachine` — **zone gelée**. Ici, ZÉRO arête nouvelle dans
 * la machine à états, donc aucun LOCK, et le KDS ne voit aucune différence.
 *
 * Précédent suivi à la lettre : `2026_08_10_090000_create_uber_ticket_captures_table.php`
 * (domaine neuf, additif, hors NF525, `branch_id` + BranchScope, lien nullable vers `orders`).
 *
 * L'IDEMPOTENCE EST DANS LA BASE, PAS DANS LE CODE
 * ------------------------------------------------
 * Un rejeu du webhook de paiement ne doit JAMAIS dépêcher deux coursiers pour la même
 * commande — ce serait une double facturation, et deux personnes à la porte. `order_id` est
 * donc UNIQUE : la seconde tentative se heurte à la base, pas à une condition en PHP qui
 * peut perdre une course. Même motif que `webhook_events (provider, webhook_id)`.
 *
 * L'ARGENT EST EN CENTIMES ENTIERS
 * ---------------------------------
 * Exigence propriétaire : « jamais de calcul financier principal avec des floats ». Uber
 * renvoie nativement des centimes ; on les conserve tels quels. La conversion vers le
 * `decimal(19,6)` historique de `orders.delivery_charge` se fait UNE fois, à la frontière.
 * On stocke à la fois ce que le CLIENT paie et ce qu'Uber FACTURE : sans quoi, le jour où
 * une livraison est offerte, la dépense disparaîtrait des comptes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('uber_direct_deliveries')) {
            return;
        }

        Schema::create('uber_direct_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->index();

            // Nullable : un DEVIS existe avant toute commande (le client compare, puis
            // abandonne peut-être). La course, elle, est toujours rattachée.
            $table->unsignedBigInteger('order_id')->nullable()->unique();

            // ── Le devis ─────────────────────────────────────────────────────────────
            $table->string('quote_id', 191)->nullable()->index();
            $table->unsignedInteger('quote_fee_cents')->nullable();
            $table->unsignedInteger('customer_fee_cents')->nullable();
            $table->string('currency', 3)->default('EUR');
            // Un devis Uber est temporaire : sans cette date, on facturerait un montant que
            // le client n'a jamais accepté.
            $table->timestamp('quote_expires_at')->nullable()->index();
            $table->unsignedSmallInteger('eta_minutes')->nullable();
            // La règle qui a produit le montant facturé (cf. DeliveryFeePolicy::explain).
            $table->string('pricing_rule', 40)->nullable();

            // ── La course ────────────────────────────────────────────────────────────
            $table->string('provider', 32)->default('uber_direct');
            $table->string('delivery_id', 191)->nullable()->unique();
            $table->string('tracking_url', 2048)->nullable();
            // Statuts Uber officiels : pending, pickup, pickup_complete, dropoff,
            // delivered, canceled, returned, shopping_completed. Chaîne libre à dessein :
            // un statut inconnu doit être ENREGISTRÉ, jamais rejeté ni écrasé.
            $table->string('status', 40)->nullable()->index();
            $table->timestamp('status_updated_at')->nullable();

            // ── L'adresse de livraison ───────────────────────────────────────────────
            // `order_addresses` porte déjà adresse + complément + coordonnées ; ce qui manque
            // pour Uber vit ici, sans dupliquer l'existant.
            $table->string('dropoff_postal_code', 16)->nullable();
            $table->string('dropoff_city', 120)->nullable();
            $table->string('dropoff_phone', 32)->nullable();
            $table->text('dropoff_instructions')->nullable();

            // ── L'échec après paiement ───────────────────────────────────────────────
            // Consigne : « paiement réussi mais création de livraison impossible — ne masque
            // jamais cet état comme si la commande était normale. » Ces trois colonnes le
            // rendent visible en admin et permettent une reprise SÛRE.
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_message')->nullable();
            $table->unsignedTinyInteger('create_attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();

            // Réponses brutes d'Uber : la seule preuve de ce qu'il a réellement répondu le
            // jour où un montant ou un refus est contesté.
            $table->json('quote_payload')->nullable();
            $table->json('delivery_payload')->nullable();

            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uber_direct_deliveries');
    }
};
