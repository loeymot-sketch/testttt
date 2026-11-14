<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [FACTURE PRO 2026-11-14 · propriétaire] Facture à MONTANT LIBRE pour un client professionnel
 * (« 2 repas », 18,00 €, payé en titre-restaurant), créée depuis la caisse.
 *
 * Ce n'est PAS une commande. Le propriétaire a été averti qu'un montant libre sort de la chaîne
 * de vente certifiée NF525 et l'a choisi en connaissance de cause : cette table ne touche ni
 * `orders`, ni la séquence fiscale, ni le Z, ni le journal d'audit, ni le tiroir-caisse, ni le
 * calcul des prix. Chaque document imprimé porte « Facture établie hors ticket de caisse ».
 *
 * Ajout seul, comme le reste du projet :
 *   - aucune suppression (déclencheur BEFORE DELETE) ;
 *   - aucune modification du contenu après création (BEFORE UPDATE) : seule l'ANNULATION est
 *     permise, une fois, et elle conserve le numéro.
 * Le modèle porte les mêmes garde-fous ; les déclencheurs couvrent la requête brute qui les
 * contournerait.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pro_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->index();
            $table->string('number', 20);                       // FP-YYYY-NNNN

            $table->string('client_name', 190);                 // raison sociale
            $table->string('client_address', 500)->nullable();
            $table->string('client_siret', 20)->nullable();
            $table->string('client_vat_number', 20)->nullable();

            $table->date('service_date');                       // date de la prestation
            $table->string('label', 190);                       // « 2 repas »
            $table->unsignedInteger('quantity')->default(1);

            $table->decimal('amount_ttc', 10, 2);
            $table->decimal('vat_rate', 5, 2)->default(10.00);  // restauration sur place
            $table->decimal('amount_ht', 10, 2);
            $table->decimal('vat_amount', 10, 2);

            $table->string('payment_method', 20);               // especes | carte | titre_restaurant
            $table->string('notes', 500)->nullable();

            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->dateTime('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'number'], 'uniq_pro_invoices_branch_number');
            $table->index(['branch_id', 'created_at'], 'idx_pro_invoices_branch_created');
        });

        $this->createTriggers();
    }

    public function down(): void
    {
        $this->dropTriggers();
        Schema::dropIfExists('pro_invoices');
    }

    /** Colonnes figées à la création : aucune ne peut changer, annulation comprise. */
    private const FROZEN_COLUMNS = [
        'branch_id', 'number', 'client_name', 'client_address', 'client_siret', 'client_vat_number',
        'service_date', 'label', 'quantity', 'amount_ttc', 'vat_rate', 'amount_ht', 'vat_amount',
        'payment_method', 'notes', 'created_by', 'created_at',
    ];

    private function createTriggers(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $this->dropTriggers();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $changed = implode(' OR ', array_map(
                static fn (string $c) => "NOT (NEW.`{$c}` <=> OLD.`{$c}`)",
                self::FROZEN_COLUMNS
            ));

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER pro_invoices_no_delete
                BEFORE DELETE ON pro_invoices
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'pro_invoices is append-only - DELETE forbidden (annuler la facture)';
                END
            SQL);

            DB::unprepared(<<<SQL
                CREATE TRIGGER pro_invoices_no_update
                BEFORE UPDATE ON pro_invoices
                FOR EACH ROW
                BEGIN
                    IF OLD.cancelled_at IS NOT NULL OR {$changed} THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'pro_invoices is append-only - only a single cancellation is allowed';
                    END IF;
                END
            SQL);
        }

        if ($driver === 'sqlite') {
            $changed = implode(' OR ', array_map(
                static fn (string $c) => "NEW.\"{$c}\" IS NOT OLD.\"{$c}\"",
                self::FROZEN_COLUMNS
            ));

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER pro_invoices_no_delete
                BEFORE DELETE ON pro_invoices
                BEGIN
                    SELECT RAISE(ABORT, 'pro_invoices is append-only - DELETE forbidden (annuler la facture)');
                END;
            SQL);

            DB::unprepared(<<<SQL
                CREATE TRIGGER pro_invoices_no_update
                BEFORE UPDATE ON pro_invoices
                WHEN OLD.cancelled_at IS NOT NULL OR {$changed}
                BEGIN
                    SELECT RAISE(ABORT, 'pro_invoices is append-only - only a single cancellation is allowed');
                END;
            SQL);
        }
    }

    private function dropTriggers(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            return;
        }
        DB::unprepared('DROP TRIGGER IF EXISTS pro_invoices_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS pro_invoices_no_update');
    }
};
