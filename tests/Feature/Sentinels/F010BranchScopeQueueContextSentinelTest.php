<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * @FK-ID F-010 — BranchScope Queue Worker Context (Audit-only structural guard)
 * @source plans/PLAN_AUDIT_F010_BRANCHSCOPE_QUEUE_CONTEXT_2026-05-07.md
 * @sprint Backlog P2
 * @severity P2 — Cross-branch silencieux possible si futur code introduit aggregation queries en queue worker
 *
 * BACKGROUND
 * ----------
 * `App\Models\Scopes\BranchScope::apply()` court-circuite l'application du
 * filtre `branch_id` quand :
 *   - `App::runningInConsole() === true` ET
 *   - `App::runningUnitTests() === false` ET
 *   - donc en queue worker (`php artisan queue:work`), où il n'existe pas
 *     d'utilisateur authentifié et où `runningInConsole()` est vrai.
 *
 * Conséquence : tout `Order::where(...)` ou `FrontendOrder::where(...)`
 * exécuté dans un Job ou Listener sans filtre explicite `branch_id` lit
 * cross-branche silencieusement.
 *
 * AUDIT 2026-05-08
 * ----------------
 * Audit complet `app/Jobs` + `app/Listeners` :
 *   - `CleanupStalePendingKioskOrders` : explicite `withoutGlobalScope(BranchScope::class)`
 *     avec commentaire `[W9-AUDIT FIX-5]` documentant l'intention cross-branch
 *     (cleanup système).
 *   - `DispatchDomainEventsJob` : query sur `DomainEvent` (modèle non scopé)
 *     par PK uniquement.
 *   - `Observability/SloEvaluatorJob` + `SloMetricCollector` : itération
 *     explicite par branche, chaque query Order filtre `->where('branch_id', $branch->id)`.
 *   - `AwardLoyaltyPointsOnDelivery` : utilise `DB::table('orders')->where('id', ...)`
 *     (PK), `User::where('loyalty_code', ...)` (User exclu de BranchScope par design),
 *     `User::find($order->user_id)` (PK).
 *   - Notification builders (`OrderMailNotificationBuilder`,
 *     `OrderSmsNotificationBuilder`, `OrderPushNotificationBuilder`,
 *     `OrderGotMail/Sms/PushNotificationBuilder`,
 *     `OrderDeliveryBoyMail/Sms/PushNotificationBuilder`) : tous utilisent
 *     `FrontendOrder::find($orderId)` (PK) ou `Order::find($orderId)` (PK).
 *   - Persist*ToOutbox listeners : écrivent uniquement, lisent l'objet
 *     `$event->order` déjà chargé (donc déjà résolu avec son `branch_id`).
 *
 * Conclusion : aucun leak cross-branch concret détecté à la date de l'audit.
 *
 * PURPOSE OF THIS SENTINEL
 * ------------------------
 * Verrouille structurellement les invariants en empêchant un futur agent
 * d'introduire silencieusement une query cross-branch dans `app/Jobs/` ou
 * `app/Listeners/` :
 *   1. `BranchScope::apply()` conserve la sémantique documentée (bypass
 *      explicite en console worker) — toute évolution doit être délibérée.
 *   2. Le job `CleanupStalePendingKioskOrders` reste explicite sur son intention
 *      `withoutGlobalScope(BranchScope::class)` — aucun futur refactor ne doit
 *      retirer ce marqueur sans documentation.
 *   3. Aucun `Order::where(...)` ou `FrontendOrder::where(...)` non-PK dans
 *      `app/Jobs/` ou `app/Listeners/` ne doit apparaître sans soit
 *      `withoutGlobalScope(BranchScope::class)`, soit un filtre explicite
 *      `where('branch_id', ...)`.
 *
 * Si ce sentinel échoue, le futur agent doit rouvrir le finding F-010 et
 * justifier l'évolution.
 */
class F010BranchScopeQueueContextSentinelTest extends TestCase
{
    public function test_branch_scope_documents_queue_worker_bypass(): void
    {
        $source = file_get_contents(base_path('app/Models/Scopes/BranchScope.php'));

        // Verrou: la condition documentée bypass `BranchScope` en queue worker
        // (runningInConsole=true && !runningUnitTests). Toute modification de
        // ce comportement doit être délibérée et accompagnée d'une mise à jour
        // de l'audit F-010 et de ce sentinel.
        $this->assertMatchesRegularExpression(
            '/!\s*App::runningInConsole\(\)\s*\|\|\s*App::runningUnitTests\(\)/',
            $source,
            'F-010: BranchScope must keep the documented runningInConsole/runningUnitTests bypass. '
            . 'Modifying this changes the queue-worker safety surface — re-open F-010 audit first.'
        );

        $this->assertMatchesRegularExpression(
            '/&&\s*Auth::check\(\)/',
            $source,
            'F-010: BranchScope must still require Auth::check() to apply. Removing this guard '
            . 'would crash queue workers (no Auth context).'
        );
    }

    public function test_cleanup_stale_pending_kiosk_orders_keeps_explicit_branch_scope_bypass(): void
    {
        $source = file_get_contents(base_path('app/Jobs/CleanupStalePendingKioskOrders.php'));

        // Verrou: ce job traite intentionnellement TOUTES les branches (cleanup
        // système). L'intention doit rester explicite via `withoutGlobalScope(BranchScope::class)`
        // avec le marqueur de commentaire documenté.
        $this->assertMatchesRegularExpression(
            '/FrontendOrder::withoutGlobalScope\s*\(\s*BranchScope::class\s*\)/',
            $source,
            'F-010: CleanupStalePendingKioskOrders must explicitly opt out of BranchScope '
            . 'with `withoutGlobalScope(BranchScope::class)` — relying on the implicit queue '
            . 'worker bypass would break the day Auth context is added to queue middleware.'
        );

        $this->assertStringContainsString(
            'W9-AUDIT FIX-5',
            $source,
            'F-010: the audit comment documenting the cross-branch intent must remain so the '
            . 'next agent understands why withoutGlobalScope is intentional.'
        );
    }

    public function test_no_unsafe_order_queries_in_jobs_and_listeners(): void
    {
        $unsafe = [];
        $directories = [
            base_path('app/Jobs'),
            base_path('app/Listeners'),
        ];

        // Patterns QUI DÉCLENCHENT l'audit (queries non-PK sur Order/FrontendOrder).
        // On regex-grep par ligne pour rester structurel et déterministe.
        // Méthodes considérées safe par construction (PK ou bypass explicite
        // déjà identifié dans l'audit):
        //   - find / findOrFail / findMany / whereKey
        //   - withoutGlobalScope(BranchScope::class)
        //   - withoutGlobalScopes() (toutes scopes désactivées — décision agent)
        //
        // Tout autre `Order::` ou `FrontendOrder::` (notamment ::query, ::where,
        // ::firstWhere, ::all, ::with, ::whereIn) doit être accompagné soit
        // d'un `where('branch_id', ...)` dans la même chaîne, soit d'un
        // `withoutGlobalScope(BranchScope::class)` documenté.
        $unsafePattern = '/\b(Order|FrontendOrder)::(query|where|firstWhere|all|with|whereIn|whereNotIn|orderBy|select|count|first|get|exists|chunk|each|cursor|paginate|simplePaginate|latest|oldest)\b/';
        $safeContextPattern = '/(withoutGlobalScope\s*\(\s*(BranchScope::class|BranchScope\\\\class)|withoutGlobalScopes\s*\(|->where\s*\(\s*[\'"][a-z_]*\.?branch_id|->where\s*\(\s*[\'"]branch_id)/i';

        foreach ($directories as $dir) {
            if (!is_dir($dir)) continue;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') continue;

                $contents = file_get_contents($file->getPathname());

                if (!preg_match($unsafePattern, $contents)) {
                    continue;
                }

                // Pour chaque match, regarder le bloc statement (jusqu'au `;`)
                // et chercher un marqueur safe.
                // Approche : split par `;`, vérifier les statements qui matchent
                // le pattern unsafe, exiger qu'ils contiennent aussi un marker safe.
                $statements = preg_split('/;\s*\n/', $contents);
                foreach ($statements as $stmt) {
                    if (!preg_match($unsafePattern, $stmt)) continue;
                    if (preg_match($safeContextPattern, $stmt)) continue;
                    // Statement contient un appel risqué non protégé.
                    $unsafe[] = sprintf(
                        '%s: %s',
                        str_replace(base_path() . '/', '', $file->getPathname()),
                        trim(preg_replace('/\s+/', ' ', substr($stmt, 0, 200)))
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $unsafe,
            "F-010 invariant: les Jobs et Listeners ne doivent contenir aucune query Order/FrontendOrder "
            . "non-PK (where, query, etc.) sans soit `withoutGlobalScope(BranchScope::class)`, soit "
            . "un filtre explicite `->where('branch_id', ...)`. Statements détectés:\n"
            . implode("\n", $unsafe)
            . "\n\nSi cross-branch est INTENTIONNEL: ajouter `withoutGlobalScope(BranchScope::class)` "
            . "+ commentaire `[F-010] cross-branch intentional: <raison>`. "
            . "Sinon: ajouter le filtre `->where('branch_id', \$expectedBranchId)`."
        );
    }
}
