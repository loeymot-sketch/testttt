<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [UBER-PHOTO 2026-08-10 · owner] « Je photographie le ticket Uber sur la tablette et il entre
 * dans le flux de la cuisine comme n'importe quelle commande. »
 *
 * POURQUOI UNE TABLE PLUTÔT QU'UN SIMPLE ENDPOINT
 * -----------------------------------------------
 * Une photo lue par un modèle est une SOURCE FAILLIBLE. Trois choses doivent donc survivre à la
 * lecture, sans quoi le jour où une commande part de travers plus personne ne peut dire pourquoi :
 *   · la ou les PHOTOS d'origine (c'est la seule preuve de ce que le restaurant a réellement reçu) ;
 *   · le TEXTE EXTRAIT tel que le modèle l'a rendu, avant toute correction humaine ;
 *   · le lien vers la COMMANDE créée, s'il y en a une.
 *
 * L'IDEMPOTENCE EST DANS LA BASE, PAS DANS LE CODE
 * ------------------------------------------------
 * En coup de feu, la même photo sera envoyée deux fois — doigt qui glisse, réseau lent, écran qui
 * ne répond pas. `photo_hash` (sha256 du contenu des images concaténées) est UNIQUE par branche :
 * la deuxième tentative retombe sur la même capture au lieu de créer une deuxième commande. C'est
 * exactement le motif déjà éprouvé sur le scan de factures (`purchase_documents.doc_hash`).
 *
 * Domaine NEUF, ADDITIF, HORS NF525 : aucune séquence fiscale, aucune chaîne d'audit touchée. Le
 * canal Uber reste non fiscalisé (config `uber.fiscalize`), comme le webhook.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('uber_ticket_captures')) {
            return;
        }

        Schema::create('uber_ticket_captures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->index();
            // Qui a photographié — pour retrouver l'humain quand une commande est contestée.
            $table->unsignedBigInteger('user_id')->nullable()->index();

            // Chemins des photos sur le disque 'local' (plusieurs : un ticket Uber long ne tient
            // pas dans un seul cadrage, l'owner le prend en deux ou trois clichés).
            $table->json('photo_paths');
            $table->string('photo_hash', 80);

            // pending → extracted | failed → confirmed | discarded
            $table->string('status', 20)->default('pending')->index();

            // Sortie BRUTE du lecteur, avant correction humaine. Sert de preuve ET de matière
            // d'amélioration du prompt le jour où une lecture se révèle fausse.
            $table->json('extracted')->nullable();
            // Charge utile RÉELLEMENT envoyée en cuisine (après correction humaine éventuelle).
            $table->json('confirmed_payload')->nullable();

            $table->string('customer_name', 120)->nullable();
            $table->string('display_id', 60)->nullable();
            $table->unsignedInteger('items_count')->default(0);
            $table->decimal('total', 19, 6)->nullable();

            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->string('vision_driver', 40)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            // La même photo ne peut pas produire deux commandes dans la même branche.
            $table->unique(['branch_id', 'photo_hash'], 'uber_captures_branch_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uber_ticket_captures');
    }
};
