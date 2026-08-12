<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [TICKET-CUISINE-DEUX-POSTES 2026-08-12 · owner « les deux »] Une réclamation PAR DESTINATION.
 *
 * POURQUOI UNE TABLE PLUTÔT QU'UNE COLONNE DE PLUS
 * ------------------------------------------------
 * Jusqu'ici, « ce ticket est déjà sorti » tenait dans une seule colonne :
 * `orders.kitchen_ticket_printed_at`. Cette colonne répond à UNE question binaire, donc elle ne
 * peut servir qu'UN poste : le premier qui réclame gagne, et l'autre n'imprime jamais. C'est
 * exactement ce qui se serait produit en ajoutant naïvement un second écouteur — le PC caisse et
 * le PC cuisine se seraient volé les tickets à tour de rôle, chacun n'en sortant qu'un sur deux.
 *
 * L'owner veut un papier à la caisse ET un en cuisine. « Déjà imprimé » devient donc une question
 * par destination, et une question à plusieurs réponses ne tient pas dans un booléen.
 *
 * LA BASE ARBITRE, TOUJOURS
 * -------------------------
 * La garde reste la même doctrine que la colonne qu'elle prolonge : c'est un INSERT protégé par
 * une contrainte d'unicité (order_id, destination), pas un « si absent alors insérer » écrit en
 * PHP. Deux onglets ouverts sur le même poste, ou deux cycles qui se chevauchent, ne peuvent pas
 * gagner tous les deux : le second INSERT est simplement ignoré par la base.
 *
 * REPRISE DE L'EXISTANT — SINON ON RÉIMPRIME LE PASSÉ
 * ---------------------------------------------------
 * Au moment de cette migration, des commandes ont DÉJÀ été imprimées par le pont caisse (10 entre
 * le 10 et le 11 août, dont deux commandes du site). Sans reprise, aucune ne posséderait de ligne
 * dans la nouvelle table : la fenêtre de 30 minutes en rattraperait les plus récentes et le
 * rouleau ressortirait des tickets déjà servis. On sème donc la destination 'counter' à partir de
 * `orders.kitchen_ticket_printed_at`, qui est précisément la trace de ce que la caisse a déjà
 * sorti. La cuisine, elle, n'a encore rien imprimé : elle n'a légitimement aucune ligne.
 *
 * `orders.kitchen_ticket_printed_at` N'EST PAS ABANDONNÉE : elle reste la garde du chemin
 * serveur→imprimante (KitchenTicketAutoPrinter::printOnce), qui vit toujours pour le jour où une
 * imprimante serait joignable depuis le serveur. Les deux gardes sont indépendantes parce que les
 * deux chemins le sont.
 *
 * HORS NF525 : cette table ne porte aucune donnée fiscale, seulement l'état d'une sortie papier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_ticket_claims', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            // 'counter' = pont caisse (9100) · 'kitchen' = pont cuisine (9101).
            $table->string('destination', 16);
            // Posé à l'accusé de réception. NULL = réclamé mais pas encore confirmé ; une
            // réclamation en échec est SUPPRIMÉE (la commande retourne en file), jamais laissée
            // à NULL indéfiniment — un ticket réclamé sans papier est le pire des deux mondes.
            $table->timestamp('printed_at')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamps();

            // LA garde : la base refuse la seconde réclamation pour la même destination.
            $table->unique(['order_id', 'destination'], 'kitchen_ticket_claims_order_destination_unique');
            $table->index(['destination', 'printed_at'], 'kitchen_ticket_claims_destination_idx');
        });

        // Reprise : tout ce que la caisse a déjà sorti ne doit pas ressortir.
        $dejaImprimees = DB::table('orders')
            ->whereNotNull('kitchen_ticket_printed_at')
            ->select(['id', 'kitchen_ticket_printed_at'])
            ->get();

        foreach ($dejaImprimees->chunk(500) as $lot) {
            DB::table('kitchen_ticket_claims')->insertOrIgnore(
                $lot->map(fn ($o) => [
                    'order_id'    => $o->id,
                    'destination' => 'counter',
                    'printed_at'  => $o->kitchen_ticket_printed_at,
                    'created_at'  => $o->kitchen_ticket_printed_at,
                    'updated_at'  => $o->kitchen_ticket_printed_at,
                ])->all()
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_ticket_claims');
    }
};
