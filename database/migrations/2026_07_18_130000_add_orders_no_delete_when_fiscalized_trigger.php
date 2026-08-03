<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [P1-1 NF525 2026-07-18] orders BEFORE DELETE immutability trigger when fiscalized.
 *
 * ⚠️⚠️  GATE OWNER — À VALIDER AVANT DEPLOY PROD  ⚠️⚠️
 * -----------------------------------------------------------------------------
 * Cette migration CHANGE le comportement de suppression en PRODUCTION :
 * un `orders` porteur d'un `fiscal_sequence_no` NON NULL ne pourra PLUS être
 * hard-deleté (raw DELETE / forceDelete() / FK CASCADE). C'est le BUT NF525.
 * Impact opérationnel connu :
 *   - Le workflow « purge 186 cmd test » : les commandes de test fabriquées
 *     AVEC un fiscal_sequence_no seront désormais BLOQUÉES au DELETE — c'est
 *     CORRECT (elles font partie de la chaîne fiscale signée). Les commandes
 *     de test NON encaissées (fiscal_sequence_no NULL) restent purgeables.
 *   - Une éventuelle « purge RGPD après la fenêtre de 6 ans NF525 » d'un order
 *     fiscalisé devra explicitement DROP ce trigger sous gate owner (même
 *     posture que z_reports_no_delete / order_payments_no_delete, déjà
 *     immuables — order_payments a de surcroît une FK restrictOnDelete vers
 *     orders qui bloque déjà cette suppression côté MySQL).
 *   NE PAS jouer cette migration sur la DB opérationnelle sans validation owner.
 *
 * PROBLÈME (finding P1-1, registre goal-intelligence-2026-07-18) :
 *   FiscalSequenceService::next() (FROZEN, CLAUDE.md §7) alloue le prochain
 *   numéro fiscal via `MAX(fiscal_sequence_no)+1` sur `orders` avec
 *   `->withTrashed()`. Les SOFT-deletes (Order = SoftDeletes, restore() bloqué)
 *   sont donc bien pris en compte. MAIS un HARD-delete d'un order fiscalisé
 *   fait REDESCENDRE le MAX → le prochain encaissement RÉÉMET un numéro déjà
 *   gravé dans la chaîne d'audit signée. Preuve (chaîne signée) : seq 2579
 *   revendiqué par 6 orders distincts, 2068 par 5, 2624 par 2 — montants
 *   divergents, immuables. Il existe déjà un trigger `order_payments_no_delete`
 *   (2026_05_10_010000) mais AUCUN équivalent sur `orders` lui-même.
 *
 * FIX (mirror EXACT du pattern order_items.composition_snapshot,
 *      migration 2026_05_24_040211 — même structure, pas de nouvelle archi) :
 *   BEFORE DELETE trigger sur `orders` qui lève SQLSTATE 45000 (MySQL) /
 *   RAISE(ABORT) (SQLite) UNIQUEMENT quand OLD.fiscal_sequence_no IS NOT NULL.
 *   Le compteur monotone dédié (table de séquence) évoqué dans le registre
 *   toucherait FiscalSequenceService (FROZEN) → HORS SCOPE : ce trigger est la
 *   part SAFE, mirror-pattern, qui ferme le trou de réutilisation à la source
 *   (la table redevient non-suppressible pour les lignes fiscalisées).
 *
 * DESIGN NOTES
 * ------------
 *   - Condition sur fiscal_sequence_no NON NULL : un order NON encaissé
 *     (fiscal_sequence_no NULL) reste hard-deletable → cleanup légitime OK.
 *   - Soft-delete (deleted_at) = UPDATE, PAS un DELETE → le trigger ne fire
 *     JAMAIS dessus : le flux d'annulation POS/borne (soft-delete + restore
 *     bloqué) est intact.
 *   - Per-row trigger BEFORE DELETE. INSERT/UPDATE inchangés.
 *   - SQLite parity requise car PHPUnit tourne sur :memory: SQLite
 *     (phpunit.xml force DB_CONNECTION=sqlite / DB_DATABASE=:memory:).
 *   - Idempotent : DROP TRIGGER IF EXISTS avant CREATE (migration re-jouable
 *     en dev). Ré-installable aussi par `fiscal:install-immutability-triggers`
 *     (compteur 9/9 → 10/10) et vérifié par `fiscal:verify-immutability-triggers`.
 *
 * EDGE CASES
 * ----------
 *   - TRUNCATE TABLE orders bypasse les triggers MySQL — mitigé par REVOKE
 *     TRUNCATE sur l'utilisateur DB prod (même caveat que audit_logs /
 *     order_payments, périmètre deploy-doc, pas migration).
 *   - Table `orders` absente sur schéma partiel : Schema::hasTable() guard.
 *
 * ROLLBACK
 * --------
 * down() refuse en production (immuabilité NF525 non réversible silencieusement
 * une fois posée). Hors-prod : drop autorisé pour itération locale.
 */
return new class extends Migration {
    public function up(): void
    {
        $driver = DB::getDriverName();

        if (! Schema::hasTable('orders')) {
            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::unprepared('DROP TRIGGER IF EXISTS orders_no_delete_when_fiscalized');

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER orders_no_delete_when_fiscalized
                BEFORE DELETE ON orders
                FOR EACH ROW
                BEGIN
                    IF OLD.fiscal_sequence_no IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'NF525: order with fiscal_sequence_no cannot be deleted — fiscal number reuse forbidden (P1-1)';
                    END IF;
                END
            SQL);

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS orders_no_delete_when_fiscalized');

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER orders_no_delete_when_fiscalized
                BEFORE DELETE ON orders
                FOR EACH ROW
                WHEN OLD.fiscal_sequence_no IS NOT NULL
                BEGIN
                    SELECT RAISE(ABORT, 'NF525: order with fiscal_sequence_no cannot be deleted — fiscal number reuse forbidden (P1-1)');
                END;
            SQL);
        }
    }

    public function down(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException(
                'NF525: cannot drop orders_no_delete_when_fiscalized trigger in production (P1-1)'
            );
        }

        $driver = DB::getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb' || $driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS orders_no_delete_when_fiscalized');
        }
    }
};
