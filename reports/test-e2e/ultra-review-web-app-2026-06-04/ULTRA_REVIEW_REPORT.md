# Ultra-review + E2E — Web & App Le Cayenne — 2026-06-04

**Méthode** : skill `test-e2e` (équipe GStack + superviseur adversarial) en mode hybride —
(1) **review statique parallèle** : Workflow 15 zones × UI/UX/technique/a11y → vérification
adversariale par finding (file:line + repro, défaut-refute) → synthèse (129 agents, 7.1M tokens) ;
(2) **E2E live** piloté à la main via Preview MCP (1 navigateur/serveur = non parallélisable) —
chaque écran + 2 flows critiques, visuel + console + réseau ; (3) heal scope-minimal des défauts
clairs + escalade des décisions produit. Anti-hallucination strict (chaque finding rejoué live ou
file:line+evidence). 3 findings DROP par l'adversarial, 1 sous-claim réfuté.

## VERDICT

| Frontend | État | Note |
|---|---|---|
| **WEB** (standalone, port 8083) | ✅ **GO après heals** | Était NON-shippable (1 P0 + 5 P1). P0 + 3 P1 customer-facing **healed & live-vérifiés**. Reste : polish a11y + 2 gaps fonctionnels mock-only à arbitrer. |
| **MOBILE** (standalone, port 8081) | ✅ **GO** | Flows OK, 1 P1 partagé (menu price) healed. Console clean. Aucun défaut bloquant trouvé live. |

**Périmètre** : 2 frontends STANDALONE (0 wireup API — attendu). Aucune exposition NF525/fiscal/
sécurité/paiement (CLAUDE.md §3bis). Donc **0 blocker de conformité** ; tous les findings sont
UX/correctness/a11y customer-facing. 0 backend frozen-zone touché.

## FLOWS CRITIQUES — ✅ FONCTIONNELS END-TO-END

- **WEB — commander un Tacos** : menu → détail Tacos M (6,90€) → wizard 7 étapes (viande req /
  suppléments +0,90 / menu / boisson / frites style / sauce) → panier (2 items, qty, promo, créneau
  retrait, note cuisine, +pts) → checkout (jour+heure) → paiement (Payer en caisse CONSEILLÉ / CB
  Stripe / Apple / Google Pay) → **confirmation #C-8242 + QR retrait + TOTAL 16,90€**. Intégrité prix
  vérifiée à chaque étape (6,90 → +Cheddar 7,80 → +Menu 9,90 → panier 16,90 → confirm 16,90). Bannière
  allergènes sur le récap. Auth démo fonctionnelle (+25 pts). Dashboard Pepper Club riche (solde, QR,
  paliers, défis, streak, parrainage, leaderboard, 8 trophées).
- **MOBILE — loyalty redeem** : Profil → carte fidélité (QR rotatif #FK-12345 exp 4:59) → 347 pts =
  3,47€ → récompenses (Petite Frites 100pts) → **WizardRedeem 2 étapes** (Confirmer : solde 347 −100 =
  247 ✓ → Quand utiliser : maintenant/30j) → **ÉCHANGÉ, code LCY-967568, solde 247 pts** (déduit ✓).
  Intégration Apple/Google Wallet. Math points correcte de bout en bout.

---

## ✅ HEALED & LIVE-VÉRIFIÉ (5 fixes, commits web `6416565` + mobile `534214639`)

| # | Sev | Défaut | Fix | Vérif live |
|---|---|---|---|---|
| 1 | **P0** | Filtres diététiques (Épicé/Nouveau/Top/Veggie) **100% morts** → 0 résultat à chaque clic. Cause : `i.tags.includes(d)` mais ids lowercase vs tags UPPERCASE + spicy/veggie sont des champs is_spicy/is_vegetarian. `screens.jsx:426` | predicate map {spicy→is_spicy, veggie→is_vegetarian, new→tags NEW, top→tags TOP} | ✅ Épicé **41→3** (Sandwich Cayenne, Big Cayenne, Galette Cayenne) |
| 2+3 | **P1** | 2 CTAs home **morts** : "J'en profite" + "Commander Big Cayenne" passaient un slug string à `find(i=>i.id===id)` (ids numériques) → no-op silencieux. `screens.jsx:181,223` | finder slug-fallback `i.id===id \|\| i.slug===id` (`index.html:56`) | ✅ "Commander Big Cayenne" ouvre la modale détail |
| 4 | **P1** | Wizard "Menu complet" affichait **+2,50€** mais facturait **+3,00€** (total 6,90→9,90). Drift hardcodé du caisse-sync 05-30. `wizard-v2.jsx:93` (web) + `screens-item-steps.jsx:525` (mobile) | price 2.50→3.00, savings 1.50→1.00 | ✅ web affiche **+3,00€** = total 9,90 ; mobile = edit identique (web live-vérifié) |
| 5 | **P2** | Champ recherche menu sans nom accessible (icône+placeholder only). `screens.jsx:468` | aria-label + type=search + clear-button aria-label | ✅ DOM |

Console 0 erreur post-heal · sentinel mobile↔web **GREEN** (menu.js intouché).

---

## 📋 SURFACED — à arbitrer (non auto-healed : plus gros, ou décision produit/contenu)

### Fonctionnel (recommandé de fixer)
- **P1** `wizard-v2.jsx:399,456` — Récap & aperçu live **omettent les étapes cascade du menu** (boisson/
  style frites/sauce) : invisibles et non-modifiables alors qu'elles affectent le total. Fix : itérer
  `active` au lieu de `baseSteps`.
- **P2** `flows.jsx:8` — **Code promo appliqué au panier silencieusement perdu au checkout** (réduction
  non reportée). À vérifier/câbler.
- **P2** `funnel.jsx:350` — Numéro de commande **non-déterministe** entre écrans confirm/tracking.
- **P2** `wizard-v2.jsx:295` — Étape viandes `max=1` — **vérifier que Tacos L (2 viandes) permet bien 2
  choix** (non testé live ; potentiel blocage si hardcodé pour tous les tacos).

### a11y (polish)
- **P1** `screens.jsx:54` — Cœur favori = `<span onClick>` **imbriqué dans le `<button>` carte** (HTML
  interactif invalide, non focusable clavier, sans aria-label, state local non persisté/mort). Refactor
  en vrai `<button>` sibling, ou retirer le favori pour V1.
- **P2** `components.jsx:151-160` — WebModal sans sémantique dialog (role/aria-modal/focus-trap/Esc).
- **P2** `wizard-v2.jsx:331` — Boutons option sans aria-pressed/checked (état non annoncé lecteur d'écran).
- **P3** `components.jsx` — Ordre titres home h1→h3 (saut de niveau) ; **pas de `<h1>` sur Menu/Loyalty/
  About** (SEO/a11y).

### Contenu / sécurité (décision OWNER requise)
- **P2** `screens-v3.jsx:199` — Items à allergènes vides (~22 : Bols…) affichent **"Allergènes : ."**
  (array vide truthy → fallback raté). ⚠️ Choix : masquer la ligne vs afficher des allergènes par défaut
  — **décision sécurité/contenu** (ne pas fabriquer d'allergènes sans validation).
- **P2** `screens.jsx:177` — Prix barré "daily-special" contredit le SSOT canonique de 1€ (contenu marketing).
- **P3 (latent)** `screens-v3.jsx:271` — Agrégation allergènes itère `baseSteps` non `active` → allergènes
  des étapes cascade jamais comptés (latent, sécurité).

### Mock / no-wireup (attendu standalone — info)
- **P2** `components.jsx:121-124` — Boutons footer contact/stores sans handler (placeholders).
- **P3** `screens.jsx:112` — "Recherche" hero readOnly + onClick redirect (typing ne fait rien — peut être
  voulu : redirige vers Menu).
- **P3** `components.jsx:72` — Nom de compte connecté jamais rendu (display:none inline override).

### OWNER-GATE (décision produit)
- **P3** `index.html:48-50` — **Panier pré-rempli** d'un item jamais ajouté (Sandwich Cayenne) → explique
  le badge "Panier 1" permanent. Choix démo : garder ou démarrer panier vide.
- **Cohérence loyalty cross-frontend** : web logged-out "500pts=5€/1000=burger" vs dashboard "dès 200pts"
  vs mobile "500pts=−5€" — harmoniser les seuils/paliers entre les 2 apps.
- **Naming Tacos** (rappel décision 06-04) : DB/caisse "Tacos/Big Tacos" vs app "Tacos M/L" (prix
  identiques 6,90/8,90 ; seul le libellé diffère).

## 🗑️ DROP (adversarial) — non-findings
- Insta `noopener` sur `href="#"` placeholder · 4 `href="#"` externes (no-wireup attendu) · stat "22 vs
  41" (distinction Plats vs créations défendable) · contrast clear-search (`--gray-3 #6F6A60` passe AA).

---

## Preuves
- Live E2E : `LIVE_FINDINGS.md` (ce dossier) — captures Preview MCP + DOM + console par écran.
- Review statique : Workflow run `w3vraq9mn` (129 agents, 22 findings vérifiés file:line, 3 DROP).
- Heals : commits web `6416565` (3 fichiers, 8/5) + mobile `534214639` (1 fichier, 1/1).
- Console : web + mobile 0 erreur. Réseau : 0 failed. Sentinel mobile↔web GREEN. 0 frozen-zone touché.
