# Ultra-Intersections — PricingService SSOT × 11 callers

HEAD 48050af80 · DB foodking_e2e · read-only · slug `ui-pricing-ssot`

## Cible
`PricingService::calculateOrder(PricingRequest, CouponService): PricingResult`
— seule fonction de calcul de prix (SSOT). 8 sites d'appel réels (le
"11 callers" comptant les factory `PricingRequest::for*`).

## Les N chemins/consommateurs
| # | Caller | Factory | Rôle | round* flags |
|---|--------|---------|------|--------------|
| 1 | OrderService:392 | `forWeb` | commit WEB/takeaway/delivery | **tous FALSE** |
| 2 | OrderService:819 | `forPos` | commit CAISSE | tous TRUE |
| 3 | OrderService:1382 | `forTable` | commit DINE-IN | **tous FALSE** |
| 4 | FrontendOrderService:302 | `forKiosk` | commit BORNE | tous TRUE |
| 5 | OrderQuoteService:289 | `forKiosk` | quote borne | tous TRUE |
| 6 | OrderQuoteService:304 | `forPos` | quote caisse | tous TRUE |
| 7 | PricingPreviewService:71 | `forKiosk` | preview draft borne | tous TRUE |
| 8 | PricingPreviewService:107 | `forKiosk` | preview borne | tous TRUE |

## Ce qui est COHÉRENT (prouvé, refute-by-default confirmé)
- **Total client (`orders.total`) identique sur tous les chemins.** Tinker
  live : même panier (Cheddar 0,90 ×7) → `total=6.30` sur forPos/forKiosk/
  forWeb/forTable. DB : **0 commande sur ~3100** avec `total <> ROUND(total,2)`.
- **preview == commit par surface.** Borne : preview/quote/commit tous
  `forKiosk`. Caisse : quote/commit tous `forPos`. `sealForCommit`
  (OrderQuoteService:122) re-quote et **rejette en 409** si
  `|quote.total_ttc − expectedTotal| > 1e-6` → parité forcée.
- **Totaux client toujours ignorés/recalculés.** Chaque commit écrase
  subtotal/total_tax/discount/total avec le résultat SSOT
  (OrderService:561-562/1042-1046/1578-1582). Le total client n'entre jamais
  dans le prix.
- **Discount/coupon cohérents.** Coupon: tous contextes. Manual discount:
  gaté `in_array(context,['pos','table'])` (PricingService:339) — kiosk/web
  ne peuvent pas injecter de remise manuelle.
- **Z-report NF525 recohère la TVA.** `taxBreakdownForOrders` fait
  `SUM(tax_amount)` brut puis `round(...,2)` par tranche (ZReportService:
  711/439) → identité `total_tva == Σ buckets` exacte malgré le sous-cent
  stocké.

## L'INCOHÉRENCE réelle (P3) — asymétrie des flags de rounding
`PricingRequest::forPos`/`forKiosk` posent `roundLineTax / roundOrderTotalTax
/ roundSubtotal / … = true` ; `forWeb`/`forTable` posent **tout à false**,
sans commentaire justificatif. Pour un panier IDENTIQUE :

```
forPos    tax=0.570000   forWeb   tax=0.572727
forKiosk  tax=0.570000   forTable tax=0.572727   (total=6.30 partout)
```

`orders.total_tax` / `order_items.tax_amount` = `decimal(19,6)` → le sous-cent
est **persisté** sur les chemins web/delivery/table.

**Repro DB live :**
- `#4983` DINING_TABLE(20) `total_tax=0.636364`
- `#5093` DELIVERY(5) `total_tax=0.272727`
- `#5102` TAKEAWAY(10) `total_tax=0.272727`
- POS(15) : 0/164 sous-cent · KIOSK(25)/type30 : 0 sous-cent (toujours arrondis)

**Impact (borné, honnête) :**
- Total payé par le client : **aucun** (toujours 2 décimales propres).
- Surfaces fiscalisées V1 live (CAISSE + BORNE) : **cohérentes** (arrondies).
- Z-report : recohéré à l'agrégation → identité NF525 tenue.
- Reste : hygiène de données + latence fiscale si delivery/dine-in
  (récemment câblés) sont un jour fiscalisés → `orders.total_tax` à 6
  décimales sur ces lignes vs 2 sur caisse/borne. Chemins d'affichage ticket
  utilisent `number_format(...,2)` donc pas de « 0,272727 € » à l'écran.

**Fix proposé :** aligner `forWeb`/`forTable` sur les mêmes flags round* que
`forPos`/`forKiosk` (tous `true`) — ou documenter l'intention. Changement
d'1 flag par factory, sans toucher la logique de `calculateOrder`. Zone non
frozen (`PricingRequest.php` n'est pas dans §7 ; `PricingService.php` l'est
mais N'EST PAS modifié).

## Verdict
SSOT prix **COHÉRENT sur ce qui compte** (total client + fiscal). Une seule
asymétrie inter-chemins réelle et reproductible : la précision de la TVA
stockée diverge par surface (P3). Réfutation partielle de « cohérent sur TOUS
les chemins » : le montant l'est, la ventilation TVA persistée ne l'est pas.
