<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [ETIQUETTES 2026-10-16 · owner] Imprimante d'étiquettes Brother QL-820NWB : étiquettes d'hygiène
 * (DLC) et étiquettes par produit de commande.
 *
 * DEUX TABLES, DEUX RÔLES
 * -----------------------
 *   dlc_label_products — le catalogue des produits à étiqueter (oignons, tomates, olives…) avec leur
 *                        durée de conservation APRÈS OUVERTURE. C'est le restaurant qui la saisit : ce
 *                        sont ses règles d'hygiène (plan de maîtrise sanitaire), pas les nôtres.
 *   label_print_logs   — le JOURNAL de tout ce qui est imprimé. Deux usages :
 *                          · traçabilité d'hygiène : qui a ouvert quoi, quand, avec quelle DLC — c'est
 *                            ce que demande un contrôle sanitaire ;
 *                          · verrou d'impression automatique : deux tablettes ne doivent jamais imprimer
 *                            deux fois les étiquettes de la même commande (`claim_key` UNIQUE).
 *
 * LES DATES SONT CALCULÉES PAR LE SERVEUR, jamais par la tablette : une seule horloge, et le papier est
 * identique au journal.
 *
 * Domaine NEUF, ADDITIF, HORS NF525 : aucune séquence fiscale, aucune chaîne d'audit touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dlc_label_products')) {
            Schema::create('dlc_label_products', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id')->index();
                $table->string('name', 120);
                // Durée de conservation après ouverture : `shelf_life_value` + `shelf_life_unit`.
                $table->unsignedSmallInteger('shelf_life_value');
                $table->string('shelf_life_unit', 8)->default('days'); // hours | days
                // Mention libre imprimée sur l'étiquette : « +4 °C », « Au frais »…
                $table->string('storage', 60)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['branch_id', 'is_active', 'sort_order'], 'dlc_products_branch_active_sort');
            });
        }

        if (! Schema::hasTable('label_print_logs')) {
            Schema::create('label_print_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id')->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();

                // dlc | order
                $table->string('kind', 10)->index();

                // — DLC —
                $table->unsignedBigInteger('dlc_product_id')->nullable()->index();
                // Nom et durée COPIÉS au moment de l'impression : renommer ou supprimer le produit ne
                // doit jamais réécrire l'historique d'hygiène.
                $table->string('product_name', 120)->nullable();
                $table->unsignedSmallInteger('shelf_life_value')->nullable();
                $table->string('shelf_life_unit', 8)->nullable();
                $table->timestamp('opened_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->unsignedSmallInteger('copies')->default(1);

                // — Commande —
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->unsignedSmallInteger('labels_count')->nullable();
                // Étiquettes RÉELLEMENT sorties (confirmées par l'imprimante). Tant qu'il vaut 0 après un échec,
                // l'impression automatique peut retenter sans risque de doublon ; dès qu'une étiquette est sortie,
                // seul un humain relance (« Réimprimer »).
                $table->unsignedSmallInteger('printed_pages')->default(0);
                $table->unsignedTinyInteger('attempts')->default(1);

                // claimed → printed | failed | unconfirmed   (DLC : queued → idem)
                $table->string('status', 12)->default('claimed')->index();
                $table->string('message', 300)->nullable();
                $table->timestamp('claimed_at')->nullable();
                $table->timestamp('finished_at')->nullable();

                // Verrou d'impression AUTOMATIQUE : « order:{id}:auto ». NULL pour une impression manuelle
                // (réimprimer est toujours permis). UNIQUE par branche : MySQL admet plusieurs NULL.
                $table->string('claim_key', 60)->nullable();

                $table->timestamps();

                $table->unique(['branch_id', 'claim_key'], 'label_logs_branch_claim_unique');
                $table->index(['kind', 'created_at'], 'label_logs_kind_created');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('label_print_logs');
        Schema::dropIfExists('dlc_label_products');
    }
};
