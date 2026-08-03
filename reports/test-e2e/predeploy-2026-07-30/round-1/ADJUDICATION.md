# Adjudication round 1 — test-e2e pré-deploy 2026-07-30

Orchestrateur : session deploy web (vérif totale → e2e → gate → deploy → test réel).

## Agrégat brut
| Vague | P0 | P1 | P2 | P3 |
|---|---|---|---|---|
| web | 0 | 0 | 5 | 7 |
| backend | 0 | 1 | 5 | 6 |

## Adjudications (avec preuve, §3ter anti-hallucination)

### BCK-R1-01 (KDS carte W8675 sans lignes) — P1 → **P2**, hypothèse P0 RÉFUTÉE
L'adversaire exigeait : « tant qu'une query DB n'a pas prouvé que W8675 n'a pas
d'order_items, l'hypothèse P0 "le rendu avale les lignes" n'est pas levée ».
Preuve produite (tinker, DB dev locale) :
- `orders.id=5935`, `order_serial_no=E4MASS-CYCLE-1784893679457`, source=web,
  créée 2026-07-24 13:47:59, total=4.00 → **`order_items` = 0 EN BASE**.
- Périmètre : 3/100 commandes web à 0 items, TOUTES des jours de mass-test
  (07-15 ×2, 07-24 ×1), serials synthétiques. Inserts directs de cycles E4MASS,
  pas le flux réel (quote → 422 guards → order+items).
→ Le KDS affiche honnêtement une commande qui n'a pas de lignes. Pas de drop de
rendu. Résiduel retenu en **P2 hardening** : (a) empty-state défensif sur carte
0-ligne (bloquer/marquer « commande vide — voir manager » au lieu de ✓ Prêt
actionnable) ; (b) purge/étiquetage zombie >24 h (timer 8172:15 illisible).
Non-bloquant deploy : la carte n'existe que par données de test dev ; suppression
interdite (trigger fiscal orders_no_delete).

### WEB-R1-03 (CGV fidélité non re-vérifiées) — P2 process → défaut réel trouvé et **CORRIGÉ**
Vérification directe : `legal/cgv.html:226` disait « 1 € dépensé = 1 point »
(faux ×10 vs réel 1€=10pts prouvé au panier +19 pts / 1,90 €) + phrase de
conversion charabia (taux répété 3×). **Fix commit web `e745509`**, re-vérifié
servi sur :8899 (grep : « 1 € dépensé = 10 points » présent, conversion
« 100 points = 1 € » unique). Conversion 100 pts = 1 € min 100 confirmée à la
source (`data/loyalty.js`, `api.js:716`) — inchangée.

### Divergence « À encaisser 0 + coche » (tracker) vs « 16 en file » (caisse) — P2 famille S2
Tracker = scope JOUR (0 aujourd'hui, coche cohérente) ; file caisse = en-attente
all-time (16 = reliquats de tests des jours précédents, dont E2E_PLAYWRIGHT_*).
Famille documentée S2 (mémoire 2026-07-29 : « un board du jour ne doit JAMAIS
absorber une file all-time ») — la branche S2 non fusionnée porte les
étiquetages ; bruit de DB dev, pas un défaut du delta déployé.

## Échecs smoke (3/18) — dette de specs, pas régressions
- `02-pos-cash adversarial` : attend la grille d'items sans sélectionner de
  catégorie — antérieur à l'UX category-first (objet de la branche courante).
- `03-kiosk nav` : bouton résolu mais « not stable » (animation) + timeout 5 s.
- `04-kds adversarial` : transition tentée sur l'unique carte 0-item (donnée).
Chemins couverts aujourd'hui par : captures 10 PNG backend + 9 PNG web, PHPUnit
3839/0, Vitest 2653/0, specs web 7/7 (75+ assertions). Fix des 3 specs = backlog.

## Agrégat ADJUGÉ round 1
**P0 = 0 · P1 = 0** (web ET backend) · P2 divulgués = 11 · P3 = 13.
