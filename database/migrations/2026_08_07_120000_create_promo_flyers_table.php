<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [FLYER PROMO UBER 2026-08-07] Ticket promotionnel imprimé à la caisse.
 *
 * Besoin owner : les commandes Uber Eats coûtent 30-35 % de commission. On
 * glisse dans le sac un petit ticket nominatif portant un code de réduction
 * à usage unique, pour ramener le client sur le site en direct.
 *
 * Le propriétaire saisit le prénom depuis son téléphone ; le ticket sort sur
 * l'imprimante de la caisse.
 *
 * Pourquoi une table de FILE et pas une impression directe : mesuré ce jour,
 * le serveur applicatif (VPS) NE PEUT PAS joindre l'imprimante, qui est
 * branchée au PC de la caisse sur son réseau local (`tools/caisse-bridge`
 * l'explique déjà : « Laravel tourne sur le cloud OVH → il NE PEUT PAS sortir
 * sur l'USB du SAGA branché au PC caisse »). L'ordre doit donc être DÉPOSÉ ici
 * et RÉCLAMÉ par la caisse. Cela rend aussi le mécanisme tolérant à une caisse
 * momentanément éteinte : le ticket sortira à la réouverture, au lieu d'être
 * perdu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_flyers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->index();

            // Prénom tel que saisi (Uber ne fournit qu'un prénom).
            $table->string('customer_name', 60);

            // Code réellement imprimé. UNIQUE en base : c'est la seule garantie
            // solide que deux clients ne repartent pas avec le même code. Une
            // vérification applicative ne protège pas de deux créations
            // simultanées depuis deux appareils — cas devenu réel puisque
            // l'exploitant travaille désormais sur plusieurs terminaux.
            $table->string('code', 32)->unique();

            // Coupon créé pour ce ticket. Nullable : si le coupon est supprimé
            // plus tard, on garde la trace du ticket imprimé.
            $table->unsignedBigInteger('coupon_id')->nullable()->index();

            // pending → printed | failed. Pas d'enum SQL : le projet utilise
            // des entiers de statut ailleurs, mais ici la lisibilité en base
            // prime (c'est un objet d'exploitation, hors chaîne fiscale).
            $table->string('status', 16)->default('pending');

            // Réclamation atomique par la caisse : empêche deux onglets ouverts
            // d'imprimer le même ticket deux fois.
            $table->dateTime('claimed_at', 3)->nullable();
            $table->string('claimed_by_device', 64)->nullable();
            $table->dateTime('printed_at', 3)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('last_error', 255)->nullable();

            // Qui a demandé le ticket (traçabilité : plusieurs terminaux
            // peuvent partager un compte admin).
            $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
            $table->string('created_by_device', 64)->nullable();

            // Instantané du texte au moment de l'impression : si l'exploitant
            // change le message demain, on doit toujours savoir ce qui est
            // parti chez le client.
            $table->json('rendered_payload')->nullable();

            $table->timestamps();

            // La requête chaude est « les tickets à imprimer de CETTE caisse ».
            $table->index(['branch_id', 'status', 'claimed_at'], 'promo_flyers_queue_idx');
        });

        // Unicité réelle du code coupon. Elle n'existait pas : seule une règle
        // de formulaire la vérifiait (Rule::unique), ce qui ne protège pas de
        // deux insertions concurrentes. Sans danger ici : la production compte
        // 0 coupon (vérifié avant écriture de cette migration).
        if (Schema::hasTable('coupons')) {
            $duplicates = DB::table('coupons')
                ->select('code')
                ->groupBy('code')
                ->havingRaw('COUNT(*) > 1')
                ->count();

            // On refuse de casser une base qui contiendrait déjà des doublons :
            // mieux vaut une migration qui s'abstient et le dit qu'une
            // migration qui échoue à moitié en production.
            if ($duplicates === 0) {
                Schema::table('coupons', function (Blueprint $table) {
                    $table->unique('code', 'coupons_code_unique');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('coupons')) {
            try {
                Schema::table('coupons', function (Blueprint $table) {
                    $table->dropUnique('coupons_code_unique');
                });
            } catch (\Throwable $e) {
                // L'index peut ne jamais avoir été créé (base avec doublons).
            }
        }

        Schema::dropIfExists('promo_flyers');
    }
};
