# CONVERGENCE — `/goal ultra plan audit and fix with test-e2e`

**Mission** : audit + correction, boucle adversaire jusqu'à convergence.
**Clôture** : 2026-08-13 · HEAD `32d8a42e9` (+ ce rapport).
**Verdict** : **CONVERGENCE ATTEINTE** — cycles **6 et 7** à **P0+P1 = 0** avec des jeux de
constats **IDENTIQUES** (égalité d'ensemble vérifiée ligne à ligne, y compris les libellés).

---

## 1. Pourquoi la convergence n'a PAS été déclarée aux cycles 4-5

Honnêtement : parce qu'elle n'était pas atteinte. Les cycles 4 et 5 étaient tous deux à P0+P1 = 0,
mais leurs jeux de constats **différaient** (cycle 4 : 1 P3 ; cycle 5 : 1 doublon structurel trouvé
puis corrigé). La règle d'égalité d'ensemble existe pour distinguer « plus rien à trouver » de
« l'audit n'est pas encore stable ». Deux cycles supplémentaires étaient dus, et ont été exécutés.

## 2. La batterie, identique aux cycles 6 et 7

**6 parcours réels** (aucun test double, base réelle, services réels) :

| # | Parcours | Résultat, identique aux deux cycles |
|---|---|---|
| P1 | Fidélité caisse bout en bout : téléphone → rattachement → crédit → grand-livre → historique | 185 pts, **1** ligne, double-clic → toujours 1 ligne |
| P2 | QR : 12 formes lisibles + homonymie de téléphone | aucune confusion de client ; 2 candidats → `ambiguous` |
| P3 | QR signé `lqr.*` minté puis scanné | trouvé ; **rejeu refusé**, **signature falsifiée refusée**, **30 min refusé** |
| P4 | Plancher demandé par le propriétaire (1000 pts = 10 €) + table de la roue | 1000 → 10 € ; 1450 → 14 € ; **Terminator poids 0 non tirable** |
| P5 | Quota et plafond/jour de la roue | quota bloque **exactement** à N ; plafond/jour bloque |
| P6 | Points rachetés puis vente **annulée** | 1500 pts **rendus** ; rejeu idempotent ; reaper n'y touche pas |

**11 suites, 1787 tests** — comptes identiques aux deux cycles :
Pos 305 · Loyalty 56 · Wheel 247 · KDS 81 · Kitchen 108 · Payment 84 · Order 88 ·
Fiscal 296 (8 skip) · Sentinels 364 (3 skip) · Security 144 · Branch 14.
Les 11 `skip` sont **préexistants**, non introduits par cette mission.

**Portes dures** : zones gelées **0 ligne** · NF525 **CHAIN OK sur 4 branches** · aucun fichier
d'une autre session touché.

## 3. Ce qui a été corrigé pendant la boucle

| Commit | Correctif | Gravité dite honnêtement |
|---|---|---|
| `f5fc35235` | `WheelDeliveryService` créditait un solde **sans ligne au grand-livre** | Défaut réel |
| `74106df31` | `/loyalty/register` (public, non auth) remettait un solde existant **à ZÉRO** | **Piège armé, pas fuite en cours** — 0 compte concerné en dév **et en production** |
| `e6ae04311` | Plancher de rachat : **deux définitions** unifiées sur `LoyaltyRules` | Divergence **nominale** (la garde du multiple l'absorbait) |
| `550a5808a` | Banc « rouvrir » réduit de 8 à 4 tests + **correction d'une affirmation fausse de ma part** | Dette de méthode |

**12 mutations posées, 12 détectées par le test visé.**

## 4. Constats DIVULGUÉS et non corrigés (identiques aux cycles 6 et 7)

Aucun ne bloque la livraison (barème : P2/P3 = divulguer, ne pas boucler).

**P2 — le quota de la roue compte les tours GAGNANTS, pas les desserts sortis.**
Mesuré : 3 tours sur un quota de 3 épuisent le lot alors que **0** a été remis au client.
« 50 tiramisu » signifie donc **50 tours gagnants**. Le comptage est conservateur (il donne moins
que le quota, jamais plus) et c'est un choix de sens métier, pas un bogue — **décision propriétaire** :
veut-il que seuls les lots RÉELLEMENT remis consomment le quota ?

**P3 — codes d'erreur QR doublement préfixés** (`QR_QR_REPLAY`, `QR_QR_EXPIRED`) : `byQr()` ajoute
`QR_` à un code qui commence déjà par `QR_`. **Aucun consommateur** ne matche dessus (la fenêtre de
caisse branche sur `status` puis affiche `message`), donc le caissier lit la bonne phrase française.
Champ machine sans lecteur.

## 5. Ce qui reste HORS de cette mission (portes propriétaire)

Nommé pour ne pas être confondu avec un oubli :

1. **940,30 € de ventes carte absentes de la ventilation TPE du Z** — les 51 ventes carte n'ont
   aucune ligne `order_payments`. Chemin de l'ARGENT + `ZReportService` en zone gelée → gate.
2. **Réglages de fidélité VIDES en production** → plancher effectif **100**, pas les 1000 demandés.
   Le correctif de code est livré ; **poser le chiffre est un geste propriétaire** dans l'écran admin.
3. `WHEEL_ENABLED=false` — le jeu reste fermé au public.
4. `APP_ENV=staging` avec `POS_SIMULATION_HARDWARE=true` → gardes de démarrage NF525 inertes.
5. **12 fichiers Uber non committés SUR LE VPS** — la production exécute du code absent de tout commit.

## 6. Non poussé

`f5fc35235` · `19ca124a7` · `550a5808a` · `74106df31` · `e6ae04311` · `32d8a42e9` (+ ce rapport).
Le déploiement demande un geste propriétaire explicite.
