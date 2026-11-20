<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [CAYENNE HYGIÈNE 2026-10-17 · owner] Socle commun + tâches de nettoyage.
 *
 *   hygiene_attachments        — pièces jointes photo (BL, étiquette, bandelette…), ré-encodées côté serveur.
 *   hygiene_task_templates     — le plan de nettoyage : une fiche = une checklist, une fréquence, un horaire facultatif.
 *   hygiene_task_completions   — le JOURNAL : qui a coché quoi, quand. Append-only (annulation motivée seulement), checklist
 *                                COPIÉE (modifier la fiche ne réécrit jamais ce qui a été attesté).
 * Domaine NEUF, ADDITIF, HORS NF525.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hygiene_attachments')) {
            Schema::create('hygiene_attachments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id')->index();
                $table->string('kind', 16)->default('other'); // bl | label | strip | task | other
                $table->string('path', 255);
                $table->string('mime', 40);
                $table->unsignedInteger('bytes');
                $table->unsignedSmallInteger('width')->nullable();
                $table->unsignedSmallInteger('height')->nullable();
                $table->string('sha256', 64)->nullable();
                $table->unsignedBigInteger('uploaded_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('hygiene_task_templates')) {
            Schema::create('hygiene_task_templates', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id')->index();
                $table->string('catalog_key', 40)->nullable(); // fiche issue du catalogue de départ (installation idempotente)
                $table->string('name', 80);
                $table->string('zone', 40)->nullable();
                $table->text('how_to')->nullable(); // produit, méthode : l'aide affichée sur la fiche
                $table->json('checklist'); // [{key, label}]
                $table->string('frequency', 8)->default('daily'); // daily | weekly | monthly
                $table->unsignedTinyInteger('weekday')->nullable(); // 1 (lundi) … 7 (dimanche), si weekly
                $table->unsignedTinyInteger('month_day')->nullable(); // 1 … 28, si monthly
                $table->string('due_time', 5)->nullable(); // « 22:30 » : au-delà, la fiche est en retard
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['branch_id', 'is_active', 'sort_order'], 'hyg_task_tpl_branch_active');
            });
        }

        if (! Schema::hasTable('hygiene_task_completions')) {
            Schema::create('hygiene_task_completions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id')->index();
                $table->unsignedBigInteger('template_id')->index();
                // — contexte copié —
                $table->string('task_name', 80);
                $table->string('zone', 40)->nullable();
                $table->date('due_date');
                $table->json('items'); // [{key, label, done}]
                $table->unsignedSmallInteger('items_total');
                $table->unsignedSmallInteger('items_done');
                $table->boolean('complete')->default(true); // tout est coché ?
                $table->string('note', 200)->nullable(); // obligatoire quand un point n'est pas fait
                $table->boolean('late')->default(false); // validé après l'horaire de la fiche
                $table->timestamp('completed_at')->index();
                $table->string('timezone', 40)->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('operator_name', 80)->nullable();
                $table->unsignedBigInteger('attachment_id')->nullable();

                $table->timestamp('voided_at')->nullable();
                $table->unsignedBigInteger('voided_by')->nullable();
                $table->string('void_reason', 120)->nullable();

                $table->string('client_ref', 40)->nullable();
                $table->timestamps();

                $table->unique(['branch_id', 'client_ref'], 'hyg_task_comp_client_ref');
                $table->index(['branch_id', 'due_date'], 'hyg_task_comp_branch_due');
                $table->index(['template_id', 'due_date'], 'hyg_task_comp_tpl_due');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hygiene_task_completions');
        Schema::dropIfExists('hygiene_task_templates');
        Schema::dropIfExists('hygiene_attachments');
    }
};
