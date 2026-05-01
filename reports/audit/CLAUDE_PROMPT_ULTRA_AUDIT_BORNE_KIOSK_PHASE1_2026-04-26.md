# Mission Claude — Ultra audit + ultra plan : Borne / Kiosk (Phase 1)

**À coller en tête de session** (orchestrateur, pas d’implémentation de code sauf mention explicite d’un audit « read-only » de fichiers).

---

## 0) Positionnement (ne pas mélanger les cycles)

- **Cette demande = Phase 1 (borne / kiosk).** Objectif : **auditer l’existant** et produire un **plan de correction** pour une **V1 fonctionnelle et solide côté borne** (front + back), avec preuves et **critères binaires** de clôture — *puis* enchaînement exécuteur (ex. Codex) sur ce plan, dans le respect de `AGENTS.md`, invariants, gates.
- **Hors scope immédiat = Phase 2 (centralisation produit / catégorie / stock / file « depuis le Dashboard caisse / back-office »).** En **une page max en annexe F**, décris seulement *comment* la Phase 1 conditionne la Phase 2 (intersections), **sans** détailler l’implémentation Dashboard ici. La Phase 2 fera l’objet d’un **cycle orphelin** dédié après livraison des livrables Phase 1 côté exécuteur.
- Cohérent avec l’**ultra review caisse** déjà menée : les mêmes **fondations** s’appliquent (prix backend SSOT, `branch_id`, `OrderStatus` enum, dispatch après commit, `EventContract` / outbox, file d’attente / `queue_number` où c’est lié) — ne pas contredire ces invariants.

---

## 1) Périmètre technique à cartographier (obligatoire, avec chemins disque quand c’est prouvable)

1. **Surface utilisateur borne** : parcours complet (idle → menu → personnalisation → panier → paiement → attente), erreurs (réseau, produit retiré, refus TPE, etc.) — enchaînement `resources/js/components/frontend/kiosk/`** (+ tout routeur / store associé), **et** toute brique parallèle `kiosk_implementation/`, `borne (Remix)/` si toujours référencée.
2. **Back-end** : API kiosk documentées / utilisées (ex. `POST` commande, prévisualisation tarif, auth machine, `PricingPreview*`, `KioskMachine*`, routes `api/frontend` ou équivalent) ; services `app/Services/Kiosk/`** ; requêtes de validation.
3. **Commande** : de la **création** côté borne → **persistence** → visibilité **caisse (POS)** (Echo / Pusher) et **KDS** — câbler explicitement *où* le message transite (listeners, events, ressources JSON) en cohérence avec `docs/DEVICE_FLOW.md`.
4. **Produits / catégories côté borne** : *lecture* uniquement (SSOT menu) — pas d’invention de règles de prix côté client. Signaler tout écart d’invariant.
5. **Hors-ligne & idempotence** (si présent) : `kioskOfflineQueue*` — lien avec l’état d’ingénierie connu (R4 du plan caisse) sans dupliquer un audit entier, mais en signalant **intersection** borne / tests.
6. **Tests** : `tests/Feature/Kiosk*`, Playwright / Vitest ciblés kiosk (chemins) — quelles zones sont **couverte** / **trou** pour une V1 borne.
7. **Dépendances** croisées avec l’**audit caisse** : `queue_number`, outbox, middleware machine→branche, `KioskSecurityTest`, etc. — *matrice* courte « déjà traité ailleurs / reste côté borne ».

---

## 2) Livrables de sortie (format strict)

### 2.1 — État des lieux (audit produit) **par point numéroté**

Pour chaque grande capacité (ex. catalogue par catégorie, filtre, panier, devis, paiement, impression / numéro de file, affichage erreur, i18n, accessibilité, loyauté, promo, etc.) : **Fonctionnel actuel** | **Défauts constatés** (avec fichier ou test) | **Risque** (P0 / P1 / P2) | **Prérequis** (autre branche, gate, M-13, etc. si applicable).

### 2.2 — Parcours technique « bout en bout » (synthèse 1 page max)

Un schéma texte ou liste ordonnée : *Borne UI* → *API* → *Domaine* → *POS* (temps réel) → *KDS* (statuts). Aucun flou : si une étape est inconnue, le marquer **à vérifier** + requête disque proposée.

### 2.3 — Gaps V1

Liste **binaire** : qu’est-ce qui manque pour appeler la borne « **V1 complète et défendable** » (hors Phase 2 Dashboard) — chaque item avec **critère de clôture** mesurable (ex. : « test X PASS », « flux Y documenté », « aucune route Z sans branche machine »).

### 2.4 — Ultra plan de correction (phases A, B, C, …)

Comme l’ultra plan caisse :

- **Phases non parallélisables** quand c’est dangereux.
- Sous chaque phase : 3–8 **actions** avec **owner** (Humain / Codex / mix), **fichiers ou allowlist** si Codex, **critère de sortie** binaire, **GATE** si humain requis.
- Candidats `**TASK_ID`** (format hors `CV1-MXX-…` si `MASTERPLAY_FROZEN=1`, conformément au repo) pour enchaîner exécuteur.
- Aucun plan de type « on verra plus tard » : chaque lot doit être **vérifiable** par test ou preuve disque.

### 2.5 — Ce qui te manque pour le prochain tour **sans trou** (liste 5–9 items, comme l’ultra review caisse)

Inclure si pertinent : emplacement exact du **helper quote-binding** pour les *tests* POS côté caisse **si** ta Phase borne en dépend pour des tests d’intégration partagés ; re-run de suites ; preuve hardware borne ; **sinon** rester sur le périmètre borne. Ne pas mélanger une demande d’**audit Dashboard** (Phase 2) ici : une ligne « Phase 2 = sujet **séparé** » suffit.

### 2.6 — Annexe F (max 1 page) — *Vision Phase 2 (hors exécution)*

- Intersections : **même** catalogue / catégorie / stock modifié au **back-office** → consistance borne + caisse (file, rupture) ; *sans* design détaillé, lister **les risques d’oublis** (double source, cache menu invalidé, etc.) pour préparer le cycle « centralisation & Dashboard » **après** V1 borne solide.

### 2.7 — **Verdict une ligne**

`BORNE_V1` = `PRÊT_POUR_EXÉCUTION_PLAN` | `BLOQUÉ_JUSQU_À_…` (explicite).

**Style** : factuel, sévère si nécessaire, pas de flatterie, chiffres sourcés ou mention « à reproduire ». Option B / frozen zones : respecter les règles du dépôt.

---

## 3) Références de lecture (priorité)

- `AGENTS.md`, `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md`, `docs/DEVICE_FLOW.md`, `docs/ORDER_FLOW.md` (si lié), `docs/BUSINESS_RULES.md` (extraits applicables), `app/Domain/Events/EventContract.php` (intersections événementielles).
- Index mémoire local : `memory/INDEX.md` si besoin, sans gonfler le contexte inutilement.
- Résultat attendu = **fichier ou réponse** réutilisable par l’orchestrateur et par **Codex** comme cahier des charges (découper en tâches bornées par `TASK_ID`).

---

## 4) Pour toi, orchestrateur (humain / autre modèle) — enchaînement

1. **Claude** exécute cette mission (lecture + plan).
2. Relecture / arbitrage (toi).
3. **Codex** : une micro-mission par phase ou par `TASK_ID`, avec le même brief en pièce jointe, traces `EXECUTE_DELEGATION` + self-audit GPT selon `AGENTS.md`.
4. **Après** stabilisation borne (preuves vertes ciblées + gouvernance), ouvrir un **nouveau document de mission** pour la **Phase 2** (Dashboard, stock, catégories, unification) — *ne pas* la diluer dans ce fichier.

---

*Fichier généré pour reformuler la demande orale : audit ultra + plan Phase 1 borne, Phase 2 référencée mais non exécutée ici.*  
*Date : 2026-04-26*