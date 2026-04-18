# QUESTIONS HUMAN — Phase POS-A (audit global, 2026-04-18)

**Objet.** Ambiguïtés produit / juridiques / arbitrages prioritaires détectées pendant l'audit lecture seule POS-A. À trancher **avant** lancement de Phase POS-B (plan d'exécution détaillé POS-9.1 → POS-9.10). Aucune de ces questions n'appelle de fix code dans POS-A.

**Format réponse attendu.** Décision binaire ou chiffrée + contrainte éventuelle (ex. "oui si permission X", "non mais prévoir dans POS-9.X").

---

## Q1 — Conformité fiscale France : périmètre d'ouverture commerciale

Le POS n'a aujourd'hui **aucun** des éléments NF525 / loi Finance 2018 (Z report, X report, hash chain, séquentialité par branche, audit logs immuables). Cf. POS-GA-F-01, F-02, F-04, F-38.

**Question.**
- (a) Le POS est-il destiné à une exploitation commerciale **France** dès la v1 ? Ou cible **hors France** / restrictive d'abord (US, UK, UAE…) ?
- (b) Si FR v1 : valide-t-on **POS-9.4 (conformité fiscale, 3-4 j)** en blocker de mise en prod, ou accepte-t-on une exploitation "soft launch" restaurant-pilote sous régime dérogatoire ?
- (c) Choix du schéma de signature : HMAC-SHA256 avec clé par branche stockée en vault (simple, non certifié) OU certification LNE / Infocert (long, ~20k€) ?

---

## Q2 — Modèle de paiement : multi-tender et split bill

L'enum `PaymentStatus` est binaire (PAID/UNPAID). Aucune table `order_payments`. Cf. POS-GA-F-08, F-23.

**Question.**
- (a) Cas d'usage **multi-tender** (cash + carte + ticket restaurant sur même commande) : **obligatoire v1** ou V2+ ?
- (b) Cas d'usage **split bill** : par item / par personne / par montant libre ? Les 3 ? Un seul ?
- (c) **Ticket restaurant** (CONECS, Swile, Up, Edenred) est-il en scope v1 ? Si oui, intégration via TPE (Q5) ou saisie manuelle ?

---

## Q3 — Permissions : seuils discount et refund

Aucune granularité Spatie POS (2 permissions seulement). Le discount staff accepte 100 % sans motif (POS-GA-F-06).

**Question.**
- (a) Seuil discount **caissier** sans validation manager : 10 % ? 15 % ? Autre ? Motif obligatoire à partir de combien ?
- (b) **Manager escalation** : code PIN sur même terminal (UX rapide) ou re-login (plus sûr) ?
- (c) **Refund monétaire** : autorisé caissier ou manager-only ? Motif obligatoire (liste fermée ou libre) ? Limite par jour ?
- (d) **Amend order après envoi cuisine** : autorisé (risque gaspillage) ou manager-only ?

---

## Q4 — Modèle dine-in vs takeaway

Dine-in et table selector sont hardcodés `v-if="false"` (POS-GA-F-17). La TVA n'a pas de cascade `order_type` (POS-GA-F-37).

**Question.**
- (a) Dine-in (sur place) fait-il partie du scope v1 POS ? Si non, doit-on **supprimer** le code mort ou le garder derrière feature flag branche ?
- (b) Si dine-in v1 : **obligation de sélectionner une table** ou optionnel (table libre / zone) ?
- (c) TVA : cascade dine-in 10 % / takeaway 5.5 % / alcool 20 % — c'est bien le modèle FR attendu ? Les boissons alcoolisées sont-elles taggées par `Item.tax_id` ou via `ItemAttribute` (ex. "formule avec bière") ?

---

## Q5 — Intégration TPE

Aucune intégration TPE réelle ; `pos_payment_note` = 4 chiffres tapés à la main (POS-GA-F-18).

**Question.**
- (a) TPE cible **v1** : Ingenico Move/Desk, NEPTING Concert, Verifone, SumUp, Stripe Terminal, Adyen, autre ? Préférence "indépendant" (bridge USB/réseau local) ou "intégré" (SDK editor) ?
- (b) Bridge **borne/kiosk** et **POS** : même solution ou distincte ?
- (c) En cas de timeout TPE (30 s+) : auto-annulation commande, retry manuel, ou mode dégradé "saisie manuelle PIN/RRN" ?

---

## Q6 — Tiroir-caisse : process comptage

Aucun module tiroir (POS-GA-F-03, F-19).

**Question.**
- (a) Ouverture de tiroir **hors encaissement** (rendu monnaie, échange billet) : autorisée pour qui ? Motif obligatoire ?
- (b) Comptage : **début de shift** (fond de caisse) + **fin de shift** (clôture) obligatoires ? Ou **fin de journée seule** (Z) ?
- (c) **Écart accepté** : limite en € ou % au-delà de laquelle une escalade manager est déclenchée ?

---

## Q7 — Multi-sources POS drawer

Le drawer ne centralise que kiosk cash (POS-GA-F-15). Sources attendues ?

**Question.**
- (a) Priorités **v1** : kiosk (cash + carte deferred), web/app, table orders, delivery partners (Uber Eats, Deliveroo, Just Eat) ?
- (b) Delivery partners : **API directe** chaque partenaire ou **agrégateur** (Deliverect, Otter) ? Choix impacte POS-GA-F-27 (extension enum Source).
- (c) POS peut-il **agir** sur commande web/app en cours (cancel, amend) ou lecture seule ?

---

## Q8 — Stock : modèle "86 manuel" vs "stock réel"

Le modèle actuel = compteurs journaliers `max_daily_qty` (POS-GA-F-05, F-07).

**Question.**
- (a) Le modèle "stock réel" (qty, receptions, inventaires, péremption) est-il en scope v1 ou V2+ ?
- (b) Auto-86 en rupture : déclenché par **compteur journalier** seul (comme aujourd'hui) ou par **stock réel** quand implémenté ?
- (c) Quand une commande est **CANCELED/REJECTED/RETURNED**, le stock est-il **automatiquement libéré** dans tous les cas, ou seulement si l'ordre n'a pas été préparé (statut < PREPARING) ?

---

## Q9 — Reçu / impression

Pas de réimpression, pas de journal d'impression, TVA non ventilée (POS-GA-F-20, F-34, F-35).

**Question.**
- (a) Réimpression autorisée : **sans limite** ou **N fois max** par commande ? Log obligatoire ? Tamponnage "COPIE" ?
- (b) Format : **ESC/POS thermique** (80 mm) uniquement, ou HTML navigateur toléré v1 ?
- (c) Contenu obligatoire FR (art. 242 nonies A CGI) : adresse établissement, SIRET, TVA intracom, ventilation TVA par taux, numéro séquentiel fiscal. **Tout requis v1** ou seulement ventilation TVA + séquentiel v1 ?

---

## Q10 — Audit log immuable : granularité

La table `audit_logs` est à créer INSERT-only avec hash chaîné (POS-GA-F-04).

**Question.**
- (a) Quels événements **obligatoirement** audités v1 ? Minimum suggéré : `order.created`, `order.status_changed`, `order.cancelled`, `order.destroyed`, `payment.recorded`, `payment.refunded`, `discount.applied`, `drawer.opened`, `z_report.generated`, `admin.login`, `permission.granted`.
- (b) Durée de rétention : **6 ans** (comptable FR) ou différent ?
- (c) Export : requis v1 (CSV/XML chiffré pour inspection fiscale) ou différé ?

---

## Q11 — Kitchen Display / OSS : place du POS natif

`OrderType::POS(15)` est absent des 4 colonnes KDS (POS-GA-F-26).

**Question.**
- (a) Doit-on ajouter une **5e colonne KDS "Sur place POS"** ou fusionner avec `DINING_TABLE` quand une table est sélectionnée et `TAKEAWAY` sinon ?
- (b) Les items POS doivent-ils apparaître sur **l'OSS client** (écran queue number visible salle) ou rester invisibles (prévu comptoir uniquement) ?

---

## Q12 — Paniers caissier : cross-device / reprise shift

Le panier POS vit en localStorage non scopé (POS-GA-F-41).

**Question.**
- (a) En cas de **crash caisse** ou **bascule caissier** (login différent), faut-il récupérer le panier en cours (draft server-side) ou repartir vide est acceptable ?
- (b) Le panier est-il **lié à un caissier** (user_id) ou à **une caisse** (device_id) ? Cas d'usage : le caissier prend 5 min de pause, le manager encaisse à sa place.

---

## Q13 — Idempotency : TTL et collision

Idempotency POS sans lock ni scope branche (POS-GA-F-28).

**Question.**
- (a) Durée de vie d'une clé `X-Idempotency-Key` : **24 h** ou plus court/long ?
- (b) En cas de même clé réutilisée mais payload différent : **409 Conflict** ou on renvoie le résultat de la 1ʳᵉ tentative ?
- (c) La clé doit-elle être **scopée par branche** (`branch_id + key` unique) pour permettre des collisions de clés courtes entre branches, ou globale (plus strict) ?

---

## Q14 — Roadmap vagues POS-9.1 → POS-9.10 : arbitrage priorités

L'esquisse §11 du rapport global propose un ordre. Certaines équipes pourraient préférer :

**Question.**
- (a) Validez-vous l'ordre **1. stop-bleed → 2. state machine → 3. multi-tender → 4. Z fiscal → 5. tiroir → 6. drawer → 7. amend/TPE → 8. ticket/UX → 9. parité/perms → 10. dashboard/obs** ?
- (b) Ou préférence **conformité d'abord** (4 en 1ʳᵉ position, avant state machine) pour dérisquer le blocage réglementaire ?
- (c) Budget temps total acceptable avant v1 : 10-12 jours (parallélisé) ou 14-18 jours (séquentialisé) ? Si contrainte < 10 jours, quelles vagues **sacrifier / déférer V2+** ?

---

## Q15 — Parité POS / Kiosk : stratégie

La structure items POS ≠ kiosk (POS-GA-F-30) et les heuristiques d'edit cassent à l'i18n (POS-GA-F-46).

**Question.**
- (a) Priorité v1 : **unifier les payloads** (contrat `OrderItemPayloadContract v1`) ou laisser POS en legacy et refactoriser en V2 ?
- (b) Langues cibles v1 : FR uniquement, FR+EN, ou multi-marché dès v1 ?
- (c) Si unification v1 : on prend le contrat **kiosk V2 (`wizard_selections`)** comme référence (POS s'adapte) ou le contrat **POS legacy** (kiosk s'adapte) ?

---

**Note procédure.** Dès réception des réponses, ces arbitrages alimenteront le `PLAN_PHASE_POS_B_<DATE>.md` avec les décisions d'architecture figées. Les questions sans réponse bloquent leur vague POS-9.X associée.
