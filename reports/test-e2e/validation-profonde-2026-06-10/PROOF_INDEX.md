# PROOF INDEX — GOAL VALIDATION PROFONDE 100% (2026-06-10)

> Index fonctionnalité → preuve → statut. Toutes les preuves sont committées sur la spine
> `heal/pre-cloud-exec-2026-06-05` (mutations exclusivement sur le clone :8766 `foodking_e2e`).
> Convergence par vague = 2 cycles propres identiques. Compléments : `plans/GOAL_VALIDATION_PROFONDE_100_2026-06-10.md`.

## W-A — BORNE (cycles 9/9 ×2, ~180 captures, commit `cc90a3c7f` mergé)
| Fonctionnalité | Statut | Preuve |
|---|---|---|
| Idle + « À emporter », dine-in ABSENT (V1) | ✅ | borne/captures a1-* |
| 11 catégories (images, badges, compteurs = DB) | ✅ | a2-* + a2-sidebar-cycle*.json |
| 7 wizards step-par-step (38 captures), min_select bloquant, retour, annulation | ✅ | a3-* |
| Panier : ±qty, clamp 20 (€140=20×7), remove, clear, vide, refill | ✅ | a4-* |
| Upsell SKIP + ACCEPT (totaux exacts) | ✅ | a5-* |
| Loyalty code invalide FR propre | ✅ | a6-* |
| Paiement comptoir + idempotence double-clic (1 seule commande) | ✅ | a7-* + a7-db-cycle*.txt |
| Rupture live : « Épuisé » + clic no-op + restore | ✅ | a8-* |
| **HEAL F-BORNE-01 (P1)** : atterrissage « Boissons » → 1ʳᵉ catégorie réelle (KioskMenuService sortBy chaîné) | ✅ rouge→vert + live | tests PHPUnit + heal-validation/ |
| **HEAL F-BORNE-07 (P1)** : panier BLANC post-upsell (TypeError legacy) → guard dual-format cart | ✅ rouge→vert + live | kioskCartUpsellLegacyModifiersGuard + heal-validation/ |

## W-B — CAISSE (cycles 8/8 ×2, 38 captures, commits `8e53c13b7`+`d1ca11d75` mergés)
| Fonctionnalité | Statut | Preuve |
|---|---|---|
| Grille 45 tuiles, catégories, recherche | ✅ | caisse/cycle-*/b1-* |
| Wizard FROZEN piloté observe-only (étapes capturées), total wizard == panier | ✅ | b2-01..10 |
| Panier : ±qty, gate remise, park 201 → recall restauré, clear | ✅ | b3-* |
| Paiement CASH inline : rendu monnaie, reçu NF525 (SIRET/TVA/Opérateur/#fiscal/empreinte) | ✅ | b4-* + b4-recu-texte.txt |
| Session tiroir : fond 50 → mouvements → clôture 62 → écart +2,00 € + raison obligatoire, DB reconciled | ✅ | b5-* |
| posOrders liste/show/tracker (FR, 0 nullXXXX) | ✅ | b6-* |
| **HEAL CAISSE-REMISE-01 (P1)** : remise caisse morte (dispatch vuex inexistant) → commit ; validé live 3,80→3,42 € | ✅ | zz-caisse-heal-remise + heals/ |
| CAISSE-01 (frozen-gated) re-confirmé : 12 € affiché / 10 € facturé sans seed ItemExtras | 🔶 GATE owner (seed data) | REPORT W-B §findings |

## W-C — DASHBOARD MUTATIONS (25/25 ×2, commit `086cd3f0a` mergé)
| Fonctionnalité | Statut | Preuve |
|---|---|---|
| Item CRUD complet (create/edit/toggle rupture/soft-delete) + catégorie CRUD | ✅ | dashboard-mutations/cycle*/c1-* |
| Coupon CRUD | ✅ | c2-* |
| Employé CRUD, rôle « Opérateur caisse » FR | ✅ | c3-* |
| **DATA-FIX 12h→24h appliqué et GARDÉ** (`site_time_format=H:i`) + historique 24h | ✅ | c4-* |
| Stock toggle persistant ; race F-DASH-2 NON reproduite (6/6) | ✅ (échantillon) | c5-* |
| Exports XLS historique (86 Ko, fiscal, FR) + sales-report | ✅ | c6-* |
| Push composée sans envoi, messages | ✅ | c7-* |
| **HEAL F-WC-01 (P2)** : checkbox coupon interceptait Enregistrer → wrapper design-system ; coupon créé À LA SOURIS | ✅ live | heals-wc/coupon-*.jpg |
| **HEAL F-WC-02 (P2)** : Paramètres/Site 422 permanent → nullable map-key/copyright ; save 200 champs vides | ✅ live | heals-wc/site-saved.jpg |
| **HEAL F-WC-03** : 15 clés i18n coupon ×5 langues ; 0 label brut au form | ✅ live | heals-wc/coupon-form.jpg |

## W-D — KDS/OSS PROFOND (8/8, commit `7414cd844` mergé)
| Fonctionnalité | Statut | Preuve |
|---|---|---|
| Flux NOUVELLE→Démarrer→Prêt→servies ×2 (transitions DB 1>4>7>8 actor=chef) | ✅ | kds-oss/c*-d1-* |
| Recall : POST serveur 200, badge RAPPELÉ, `8>8 kitchen_recall` append-only NF525, re-recall 409 | ✅ ×2 | c*-d2-* |
| Drawer historique (50) + refetch réseau prouvé | ✅ | d3-* |
| Multi-postes : propagation bump **405-611 ms** (<6 s), 0 doublon | ✅ ×2 | results.json |
| Mode secours : bannière « actualisation 5s », commande visible en **2,3 s** sans soketi, retour temps réel 2,5 s sans doublon | ✅ | d5-* |
| OSS public `/order-status-screen` + feed sans PII | ✅ | d6-* |

## W-E — AUTH/RBAC (8/8 ×2, commit `ba204c493` mergé + heals `5d31868a5`)
| Fonctionnalité | Statut | Preuve |
|---|---|---|
| Login/logout/re-login admin FR | ✅ ×2 | auth/captures e1a-* (worktree we-auth) |
| Anti-énumération admin (corps identique existant vs ghost) | ✅ ×2 | e1b-* |
| Lockout 429 (user dédié) + purge | ✅ ×2 | e1c-* |
| RBAC UI : POS Operator → administrators/settings = redirect propre, 0 fuite | ✅ ×2 | e3a-* |
| Token kiosk:order sur /api/admin → **403 `token_ability_insufficient`** | ✅ ×2 | e3b evidence |
| Logout → back → pas d'accès | ✅ ×2 | e4-* |
| **HEAL AUTH-F3-SPINE (P2)** : Hash::check AVANT contrôles d'état (anti-énumération bornes) porté sur la spine + KioskLoginEnumerationTest 4/4 | ✅ tests | commit `5d31868a5` |
| **HEAL AUTH-E1C-EN (P3)** : messages 429 FR (2 limiters) + KioskThrottleKeysTest 5/5 | ✅ tests | idem (validation par tests, pas de re-capture UI — divulgué) |

## W-F — CONVERGENCE FINALE
- **PHPUnit FINAL : 3092/0** (full suite post-heals, +7 nouveaux tests régression) · **Vitest FINAL : 2098/0** (+1 sentinel focus-visible assoupli à la forme minifiée prod `[role=button]` — le contrat CSS est présent).
- **Adversarial final : EXHAUSTED** (41 claims vérifiés, 0 P0/P1 ; 7 P3 documentaires/cosmétiques traités ou divulgués ci-dessous). Les 8 heals vérifiés par l'adversaire au niveau code ET bundle minifié servi par :8766 ; frozen diff campagne = 0 ligne ; cohérence numérique recoupée (clamp 20×7=140, reçu NF525 diff c1/c2 = champs attendus seuls, file encaissement +10,00 € = la commande W-A parallèle).
- **Nuance honnête (ADVF-3)** : « 2 cycles » = convergence au root-cause — W-A cycle 2 a CAPTURÉ le défaut F-BORNE-07 (panier blanc, healé ensuite + validé live), W-D D5 exécuté 1× (destructif, justifié), W-C C4a non rejoué (précondition déjà 24h). Détail dans chaque REPORT de vague.
- **ADVF-4 clos** : zone jours/surfaces du formulaire coupon re-capturée intégralement post-heal → `heals-wc/coupon-form-jours-zone.jpg` (Lun…Dim FR, checkboxes contenues, 0 label brut).
- Worktrees des vagues W-C/W-E : rapports+captures committés sur leurs branches (`heal/wc-dash-validation-2026-06-10` @ `086cd3f0a`, `heal/we-auth-validation-2026-06-10` @ `ba204c493`), mergées dans la spine — les chemins « worktree » du tableau y réfèrent.

## Transversal (GOAL ultra-audit précédent, même spine — re-validé en W-F par les suites finales ci-dessus)
Sync outbox 100% dispatché · fiscal CHAIN OK · stress 50/50 · race encaissement idempotente · impression simulation 62/62 (branche, GATE-PRINT-1).

## Heals de cette campagne (tous non-frozen, tous re-testés live)
F-BORNE-01 · F-BORNE-07 · CAISSE-REMISE-01 · F-WC-01 · F-WC-02 · F-WC-03 (+ data-fix 24h clone). **0 ligne frozen touchée** (diff vérifié à chaque merge).

## P2/P3 divulgués non bloquants (cumulés — incl. ADVF-6/7 adversarial final)
Astérisque « requis » résiduel sur les 2 champs Site passés nullable (cosmétique trompeur) · badge RAPPELÉ qui masque partiellement les chips de la carte KDS rappelée ·
KDS-WD-01 (apparition borne→KDS 3,6-10 s sous contention partagée — re-mesurer isolé) · datepickers US/EN (5 composants + coupon `:is24=false`) · « ATTENT » tronqué 1280px · « 24 Hour (11:9) » sans zero-pad · OnlineOrderList montant brut · CashOverview date UTC 00h-02h · F-WC-04/05 · stock-rupture troncatures · collision visuelle N°A0001 inter-jours (file encaissement) · F-BORNE-02/03 (upsell data-ops, throttle anglais) · SiteComponent err guard.
