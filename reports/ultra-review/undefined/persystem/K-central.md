# Ultra-review K. CENTRAL (gestion) — 2026-07-02

HEAD `61e9ea7b7` (working-tree). Audit AS-IS, read-only. Verify-before-report strict.

## Verdict : GREEN_WITH_NOTES

Système validé production-perfect V1 LOCAL. Aucun P0/P1/P2 nouveau.
1 note P3 robustesse (borderline, trusted-user-only).

## Invariants confirmés (file:line + preuve)

1. **OrderHistoryController HEAL** — `app/Http/Controllers/Admin/OrderHistoryController.php:42`
   middleware constructeur `permission:pos-orders|pos` (fail-closed toute méthode présente+future) ;
   gardes inline abort_unless conservées (belt-and-suspenders) ; show() unifie ModelNotFound+cross-branch en 403.
   Preuve : `php artisan test --filter=OrderHistory` → **19/19 verts** (dont "user without order permissions is forbidden").

2. **Dashboard Audit Trail NF525** — `app/Services/DashboardService.php:794-857`
   lit `App\Models\AuditLog` (hash-chainé, INSERT-only, expose `current_hash` prefix), PAS ActionLog.
   Branch-scope manuel : admin(0)=tout+NULL, staff(>0)=branch only. Gated `permission:dashboard`
   (`DashboardController.php:53` dans la liste ->only).

3. **eodPdf** — `DashboardController.php:60` gated `permission:pos-manage-fiscal` (ligne middleware
   séparée, PAS fusionnée dans :dashboard) ; validation date `^\d{4}-\d{2}-\d{2}$` (ligne 218) ;
   pure read-only, n'alloue pas de séquence fiscale ni n'insère dans audit_logs.

4. **Settings RBAC** — `permission:settings` sur update vérifié :
   Site/Company/OrderSetup `->only('update')` ; Kiosk/Loyalty setup `->only('index','update')`
   (GAP-19-2). Index company/site/order-setup non-gatés = défense-profondeur déféré (garde-fou).

5. **Catalogue SSOT** — `ItemController.php:31-35` permissions granulaires
   (items/items_create/items_edit/items_delete/items_show). DB : 62 items status=5 + 25 status=10,
   28 soft-deleted (doc-drift "59/48" bénin, garde-fou).

6. **Rapports Z/X lecture** — `ZReportController.php:97-102` + `XReportController.php:25`
   gated `pos-manage-fiscal` ; read-only ; branch-scopé (show/pdf abort_if cross-branch 403 ;
   index admin=cross-branch read, staff=scopé). Mutating open/close throttle 10,1.

## Nouveau finding (P3 — note)

**X-report : `Carbon::parse` sur `from`/`to` non validés → 500 au lieu de 422**
`app/Http/Controllers/Admin/Fiscal/XReportController.php:32-33`
```php
$from = $request->filled('from') ? Carbon::parse((string) $request->query('from')) : null;
$to   = $request->filled('to')   ? Carbon::parse((string) $request->query('to'))   : null;
```
Aucun try/catch dans `show()`. Un opérateur authentifié `pos-manage-fiscal` qui envoie
`?from=garbage` déclenche `Carbon\Exceptions\InvalidFormatException` → HTTP 500 (fuite
potentielle de trace selon APP_DEBUG) au lieu d'un 422 propre.
- Repro : GET /api/admin/fiscal/x-report?from=garbagenotadate (avec token pos-manage-fiscal).
- Sévérité P3 : self-inflicted, trusted-user, read-only, mono-poste. Contraste avec eodPdf
  (`DashboardController.php:218`) qui valide le format en amont — incohérence de discipline
  entre les deux endpoints fiscaux read-only.
- Contexte : famille "broad-catch/robustesse backlog" mais ici c'est l'ABSENCE de catch, pas un catch large.

## Non reportés (garde-fous respectés)
settings index non-gatés (déféré) ; Z-report by_terminal (faux-positif confirmé) ;
Z/X show/pdf 422-pour-admin-non-pinné (comportement documenté intentionnel) ;
catalogue 59/48 doc-drift (bénin).
