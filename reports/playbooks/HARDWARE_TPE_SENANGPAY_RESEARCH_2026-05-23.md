# Hardware TPE — Senangpay Research Decision 2026-05-23

> Brain.1 deep-research for Le Cayenne V1 single-restaurant France
> Author: Claude (Research Agent BRAIN.1)
> Status: ACTIONABLE OWNER DECISION DOC

---

## 1. TL;DR (3 lignes pour owner)

1. **Senangpay = NO-GO France.** C'est un payment gateway **malaisien** (RM tarifs, FPX, eWallet local MY). Aucune présence Europe. Le code Laravel existant (`app/Http/PaymentGateways/Gateways/Senangpay.php`) est juste un **webhook online** copié d'un theme/template Smartisan — pas une intégration TPE physique pour restaurant France.
2. **Recommandation : Stripe Terminal (BBPOS WisePOS E 259€ HT, 1.4% + 0.10€ EEE)** — meilleur SDK PHP du marché, webhooks robustes (déjà implémentés FoodKing post-Sprint 3A pour Stripe), titres-restaurant Swile/Edenred/Pluxee/Bimpli intégrés depuis 2024, NF525-compatible côté caisse (FoodKing gère la chaîne fiscale).
3. **Plan B France volume bas (<5K€/mois) : SumUp Solo 79€ HT + 1.75%.** Pas d'abonnement, SDK Cloud API + webhook OK, lock device au pays au 1er paiement (FR = OK). Moins polished mais valide V1 pop-up resto.

**Verdict** : ne pas finir l'intégration Senangpay. Pivoter vers Stripe Terminal (préféré) OU SumUp (low-cost). Coût total V1 hardware = **259-280€ HT** (Stripe) ou **79-100€ HT** (SumUp). Délai première transaction réelle = **5-10 jours ouvrés** depuis "GO" owner.

---

## 2. Senangpay 2026 — état réel

| Critère | Donnée vérifiée |
|---------|-----------------|
| Pays opérés | **Malaisie uniquement** (extension Indonésie via DOKU) |
| Devise | MYR (Ringgit Malaisie), pas EUR |
| Abonnement | RM149/an (Starter) à RM299/an (Advance) — promo CNY 2026 |
| Frais FPX | RM1 ou 1.5% (le plus élevé) — réseau bancaire MY uniquement |
| Frais carte locale MY | RM0.65 ou 2.5% |
| Frais carte étrangère | Variable selon package |
| BNPL | SPayLater 2.0%, GrabPayLater 6%, Atome 5.5% |
| Hardware terminal | "senang Terminal" + "Card Terminal" (offered MY market) |
| SDK PHP | **Aucun SDK officiel** — sample code PHP cURL seulement |
| Doc API | https://developer.senangpay.my/ + https://api-guide.senangpay.my/ |
| Sandbox | Oui, gratuit |
| France support | **AUCUN** — site web et docs MY-only |

### Raisons techniques qui rendent Senangpay inutilisable France

- Méthodes de paiement = FPX (réseau bancaire MY), eWallets locaux (Boost, TouchNGo, GrabPay MY)
- Aucune Visa/Mastercard EEA flow documenté
- Aucune intégration titres-restaurant France (Swile, Edenred, Pluxee, Bimpli)
- Aucune compatibilité réglementaire France (DSP2 SCA, taxe TVA, NF525)
- Settlement bancaire MY uniquement (compte bancaire MY requis)

### Honnêteté sur le code existant FoodKing

Le code `app/Http/PaymentGateways/Gateways/Senangpay.php` (252 lignes) implémente un **webhook online HMAC-SHA256** copié du theme Smartisan (Restaurant POS template). Pas une intégration TPE physique. Il était déjà commenté comme "iter15 501 stub replacement" — c'est de la dette technique du theme initial, pas une décision business owner. Le code est solide techniquement (idempotency, signature verify, DLQ replay) mais **inutilisable** pour un restaurant français.

**Decision : décommissionner Senangpay** (admin page payment-gateways disable + delete config row) ou laisser dormant. ZÉRO valeur business pour Le Cayenne France.

---

## 3. Concurrents directs France 2026 — matrice comparative

| Solution | Hardware | Prix HT | Frais EEA | Frais Hors-EEA | Abonnement | Titres-resto | SDK PHP | Délai activation |
|---------|----------|--------|-----------|----------------|------------|--------------|---------|------------------|
| **Stripe Terminal** | BBPOS WisePOS E (Wi-Fi/4G) | 259€ | 1.4% + 0.10€ | 2.9% + 0.10€ | 0€ (option 9€/mo 4G illimité) | Oui (Swile/Edenred/Pluxee/Bimpli depuis 2024) | Oui officiel | 3-7j (KYC + livraison) |
| **Stripe Terminal** | Reader S700 | 259€ | 1.4% + 0.10€ | 2.9% + 0.10€ | 0€ | Idem | Idem | Idem |
| **Stripe Terminal** | WisePad 3 (BT) | 59€ | 1.4% + 0.10€ | 2.9% + 0.10€ | 0€ (besoin smartphone) | Idem | Idem | 3-7j |
| **SumUp** | Solo (4G/Wi-Fi) | 79€ | 1.75% | 1.75% | 0€ (option SumUp One 19€/mo → 0.79%) | Partiel (Swile/Conecs FR) | Oui officiel (multi-langage) | 2-5j |
| **SumUp** | Solo + Printer | 139€ | 1.75% | 1.75% | 0€ | Partiel | Oui | 2-5j |
| **Smile&Pay** | Smile Plus (4G) | ~29€/mois locatif | 1.65% (sans abo) ou 0.65% (35€ abo) | Variable | 0-35€/mo | **Oui complet** | API REST | 5-10j |
| **Yavin** | Yavin Pro Android | 29€/mois locatif | 0.4-0.9% (CB EU) | 2.5% (hors SEPA) | 29€/mo inclus | **Oui complet** + intégration native | Oui (compatible 50+ POS) | 5-10j |
| **Zettle (PayPal)** | Reader 2 | 29€ | 1.75% | 1.75% | 0€ | Limité | Oui | 3-5j |
| **myPOS** | Go 2 | ~29€ | 1.20-1.75% | Variable | 0€ | Oui (Swile/Edenred) | Oui | 5-7j |
| **Adyen / Worldline** | Multiple Verifone/Ingenico | Sur devis | Sur devis (négocié) | Sur devis | Variable | Oui | Oui enterprise | 2-4 semaines (KYC heavy) |
| ~~Senangpay~~ | ~~MY only~~ | ~~N/A~~ | ~~N/A FR~~ | ~~N/A FR~~ | ~~RM199/an~~ | ~~Non~~ | ~~Pas de SDK~~ | ~~N/A FR~~ |

### Lecture de la matrice pour Le Cayenne V1

- **Volume bas-moyen V1 (<5K€/mois)** : SumUp Solo gagne sur simplicité + zéro abonnement. Frais 1.75% acceptable. Limite : SDK Cloud API est correct mais moins riche que Stripe.
- **Volume moyen-haut (5-20K€/mois)** : Stripe Terminal gagne nettement sur SDK + écosystème + titres-resto. Frais 1.4% + 0.10€ = ~1.5-1.7% effectif sur ticket moyen 12-15€.
- **Volume haut (>20K€/mois) ou multi-resto** : Yavin (locatif 29€/mo, 0.4-0.9% CB EU) ou Adyen (sur devis) deviennent rentables.

---

## 4. Code existant FoodKing — niveau d'intégration & delta dev requis

### État actuel (commit `heal/cms-pr1-quickwins-2026-05-18`)

```
app/Http/PaymentGateways/
├── Gateways/Senangpay.php           (252 lignes — webhook HMAC, idempotency, DLQ)
├── PaymentRequests/Senangpay.php    (37 lignes — FormRequest validation admin config)
├── Requests/Senangpay.php           (29 lignes — empty FormRequest)
└── Routes/senangpay.php             (22 lignes — POST /payment/senangpay-webhook)

tests/Feature/Webhooks/SenangpayWebhookIdempotencyTest.php  (test idempotency)
public/images/payment-gateway/senangpay.png                  (logo admin)
```

**Surface = ~340 lignes Senangpay + 1 test feature.** C'est un stub admin online-checkout, pas une intégration TPE physique. ZÉRO connexion à un terminal hardware.

### Delta dev requis selon choix

| Choix owner | Delta dev | Effort |
|-------------|-----------|--------|
| **Stripe Terminal** | Existe déjà partiel (Stripe gateway dans `app/Http/PaymentGateways/Gateways/Stripe.php`). Reste : intégrer Stripe Terminal SDK PHP côté backend (connection token endpoint), brancher POS PaymentComponent.vue sur Terminal JS SDK frontend, mapper succès/échec terminal vers `OrderPayment` + `composition_snapshot`. | **3-5 jours dev** |
| **SumUp Solo** | NEW gateway class `app/Http/PaymentGateways/Gateways/SumUp.php` + webhook Cloud API + branchement PaymentComponent.vue (boutons "Connecter Solo" + état "En attente paiement"). Plus simple que Stripe Terminal (pas d'abstraction Reader-API complexe). | **2-3 jours dev** |
| Yavin / Smile&Pay | API REST custom — nécessite intégration spec partner. | **5-8 jours dev** |
| Décommissionner Senangpay | Supprimer route, gateway, FormRequest, image, test. Mettre flag `senangpay.enabled=false` dans `payment_gateway_options`. | **2-4 heures** |

### Recommandation technique

1. **Décommissionner Senangpay** (quick win, supprime confusion + dette)
2. **Ajouter Stripe Terminal** (réutilise écosystème Stripe existant + meilleur SDK marché)
3. Garder SumUp en plan B documenté si owner veut renégocier

---

## 5. Recommandation finale — Stripe Terminal

### Pourquoi Stripe Terminal pour Le Cayenne V1

**Forces décisives**
- Écosystème Stripe déjà partiellement intégré FoodKing (gateway Stripe online + webhook idempotency Sprint 3A)
- SDK officiel PHP serveur + JS frontend (server-driven, parfait pour POS Vanilla JS protégé wizard)
- Titres-restaurant France natifs depuis 2024 (Swile, Edenred, Pluxee, Bimpli) — accepted comme CB normale via Stripe Checkout/Payment Element
- Tarif transparent EEA 1.4% + 0.10€ — pas de surprise
- Pas d'abonnement obligatoire (option 9€/mo pour data 4G illimitée, optionnel si Wi-Fi resto stable)
- KYC fluide (24-72h verification automatique) si Stripe account existe déjà
- Webhook signature HMAC + idempotency déjà battle-tested chez FoodKing

**Faiblesses honnêtes**
- BBPOS WisePOS E à 259€ HT = ~3x prix SumUp Solo (79€)
- Pas d'imprimante intégrée (le WisePOS E a écran tactile uniquement) — il faut imprimante Epson séparée (cf. Brain.3 research)
- Frais hors-EEA 2.9% + 0.10€ peuvent surprendre si touristes US/UK
- Stripe NF525 = Stripe ne certifie pas la caisse, c'est **FoodKing** qui doit gérer la chaîne fiscale (ce qui est déjà fait via `FiscalSequenceService` + `ZReportService` + `AuditLogService`)

### Plan B si owner refuse Stripe Terminal

**SumUp Solo 79€ HT** — meilleur "low-cost SDK-friendly"
- Frais 1.75% flat (lisible)
- Cloud API + Reader SDK + webhook real-time
- Lock device au pays au 1er paiement (FR OK)
- Limite : moins polished que Stripe, moins de moyens de paiement local

---

## 6. Action items owner — étapes concrètes Stripe Terminal

### Pré-requis (à valider avec owner avant souscription)

- [ ] **Compte Stripe France existant ?** (sinon créer https://dashboard.stripe.com/register)
- [ ] **SIRET Le Cayenne disponible** (KYC obligatoire)
- [ ] **RIB compte pro Le Cayenne** (pour settlement)
- [ ] **Pièce identité gérant** (KYC)
- [ ] **Connexion Wi-Fi stable au resto OU 4G externe** (TPE Wi-Fi a besoin)

### Souscription en 5 clics (15 min)

1. https://dashboard.stripe.com/register → créer compte (ou login si existant)
2. Activer "Stripe Terminal" depuis dashboard → "Products" → "Terminal"
3. Compléter KYC (SIRET + RIB + ID) — typiquement 24-72h verification
4. Commander 1× BBPOS WisePOS E à 259€ HT depuis le dashboard Stripe Hardware Shop
5. Activer le terminal en suivant guide officiel https://docs.stripe.com/terminal/payments/setup-reader/bbpos-wisepos-e

### Post-livraison TPE (chez Claude)

6. Générer Stripe Terminal API keys (test + production)
7. Implémenter endpoint `/api/stripe/terminal/connection_token` côté Laravel (cf. doc Stripe)
8. Brancher PaymentComponent.vue (POS) sur Stripe Terminal JS SDK
9. Test sandbox €0 (CB test 4242 4242 4242 4242)
10. Premier paiement réel (smoke test 1€)

### Configuration admin FoodKing

11. Ajouter ligne `payment_gateways` slug=`stripe_terminal` dans seed
12. Désactiver `senangpay` (admin → Payment gateways → toggle off) ou supprimer ligne entièrement
13. Test E2E Playwright sur PaymentComponent en mode Stripe Terminal

---

## 7. Timeline estimée — owner "GO" → première transaction réelle

| Phase | Durée | Détails |
|-------|-------|---------|
| **J0** : Owner décide Stripe Terminal | 0j | Décision owner ce matin |
| **J0-J1** : Créer compte Stripe + KYC submit | 4h owner | Si compte Stripe existant déjà : 30 min seulement |
| **J1-J3** : Stripe KYC verification | 1-3j ouvrés | Automatique si docs OK, sinon manual review |
| **J3-J5** : Stripe livre BBPOS WisePOS E | 2-3j ouvrés France métropole | Hardware shop dispatch depuis Paris |
| **J5-J7** : Claude dev intégration Laravel + PaymentComponent | 3-5j Claude | Stripe Terminal SDK PHP + Vue + tests |
| **J7-J8** : Test sandbox €0 + smoke prod 1€ | 1j | Validation end-to-end |
| **J8** : **Première transaction réelle Le Cayenne** | ✓ | Service midi normal |

**Total réaliste : 5-10 jours ouvrés** depuis "GO" owner.

### Path optimiste (compte Stripe existant + Claude prio)

- J0 commande TPE + dev en parallèle
- J3 TPE livré + dev fini
- J4 test + première transaction

**Path optimiste : 4 jours.**

### Path pessimiste (KYC complications)

- KYC review manuelle (docs flou) : +5j
- Livraison TPE province isolée : +3j
- Bugs intégration : +3j

**Path pessimiste : 15 jours.**

---

## 8. Coût total V1 hardware (TPE + lancement) — récapitulatif owner

| Poste | Stripe Terminal | SumUp Solo |
|-------|-----------------|------------|
| TPE achat | 259€ HT | 79€ HT |
| Imprimante (cf. Brain.3) | ~150-300€ HT | ~150-300€ HT |
| Tiroir-caisse (cf. Brain.3) | ~80-150€ HT | ~80-150€ HT |
| Câbles + alim | ~30€ | ~30€ |
| Abonnement mensuel | 0€ (option 9€/mo data) | 0€ |
| Setup KYC + activation | 0€ | 0€ |
| **TOTAL one-shot HT** | **~520-740€ HT** | **~340-560€ HT** |
| Frais sur transaction (ticket moyen 15€) | 1.4% + 0.10€ = **~0.31€** | 1.75% = **~0.26€** |

**Estimation owner V1.0.1 hardware Stripe Terminal : 600-750€ HT one-shot** (TPE + imprimante + tiroir + câbles).

**Si volume V1 = 200 tickets/jour × 15€ × 26j = 78 000€/mois CA** :
- Stripe : 78 000 × 1.4% + 5200 × 0.10€ = **1 612€/mois frais** (~2.07% effectif)
- SumUp : 78 000 × 1.75% = **1 365€/mois frais** (~1.75%)

→ **SumUp économise ~250€/mois sur volume haut**, mais Stripe gagne sur SDK + écosystème + titres-resto natifs.

→ **Recommandation finale** : Stripe Terminal pour V1 (qualité intégration > économie 250€/mois). Si owner volume bas (<3000€/mois), SumUp acceptable.

---

## 9. Anti-pitfalls / pièges à éviter

1. **Ne pas commander Senangpay terminal** — c'est un produit Malaisien. Risque : matériel inutilisable France, contrat MY-only.
2. **Ne pas finir l'intégration Senangpay code-side** — c'est du temps perdu (340 lignes de stub admin sans valeur).
3. **Vérifier acceptance titres-restaurant** avant de commander : si owner veut Swile + Edenred + Pluxee + Bimpli, valider que le TPE choisi les accepte tous (Stripe oui depuis 2024, SumUp partiel, Yavin oui complet).
4. **NF525 ≠ TPE certification** — NF525 certifie la **caisse logiciel** (FoodKing). Le TPE ne porte pas la conformité NF525, c'est la chaîne fiscale FoodKing qui le fait. Stripe/SumUp/Yavin/etc. sont tous compatibles si FoodKing gère bien la chaîne.
5. **Wi-Fi resto stable** — vérifier avant TPE Wi-Fi-only (sinon prendre option 4G ou Yavin/SumUp Solo qui ont 4G intégré).
6. **Pas de "free TPE"** — tous les TPE annoncés "sans frais" cachent une commission par transaction (1.2-2.89%). Toujours regarder le TER (Total Effective Rate) sur le volume estimé.
7. **Stripe Terminal réservé pro** — KYC requiert SIRET + RIB pro. Pas possible avec compte perso.
8. **CB hors-EEA frais ×2** — touristes US/UK = 2.9% au lieu de 1.4%. Si beaucoup de touristes, faire estimation mixée.

---

## 10. Décision attendue owner — 3 options

### Option A (recommandée) : **Stripe Terminal**
- Commander BBPOS WisePOS E 259€ HT
- Claude implémente intégration (3-5j)
- Première transaction réelle J7-J10
- Cible : qualité intégration + écosystème + titres-resto natifs

### Option B (low-cost) : **SumUp Solo**
- Commander Solo 79€ HT
- Claude implémente intégration (2-3j)
- Première transaction réelle J5-J8
- Cible : démarrage rapide + faible engagement + ticket moyen modeste

### Option C (locatif premium) : **Yavin Pro Android**
- Souscrire 29€/mo locatif
- Claude implémente intégration custom (5-8j)
- Première transaction réelle J10-J15
- Cible : restaurants moyens-hauts qui veulent un seul TPE-tout-en-un

**Ma recommandation forte : Option A (Stripe Terminal)** — meilleur rapport qualité-intégration pour FoodKing.

---

## Sources consultées

- https://senangpay.com/ (vérif présence Malaisie uniquement)
- https://senangpay.com/pricing/ (tarifs RM)
- https://developer.senangpay.my/ (docs API)
- https://stripe.com/fr/terminal (tarifs France 2026)
- https://docs.stripe.com/terminal/readers/bbpos-wisepos-e (hardware specs)
- https://developer.sumup.com/ (SDK + Cloud API)
- https://developer.sumup.com/terminal-payments/sdks (Reader SDKs)
- https://www.smileandpay.com/restaurateurs (Smile&Pay restaurant tarifs)
- https://yavin.com/industries/restauration/ (Yavin restaurant features)
- https://www.meilleur-tpe.fr/tpe-restaurant (comparatif marché)
- https://qonto.com/fr/blog/methodes-de-paiement/terminal-tpe/tpe-comparatif (comparatif 9 TPE)
- https://lesmakers.fr/certification-nf525/ (NF525 obligations 2026)
- https://umihformation.fr/blog/article/52 (NF525 fin self-attestation 2026)

## Code FoodKing inspecté

- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/PaymentGateways/Gateways/Senangpay.php` (252 lignes — webhook HMAC stub)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/PaymentGateways/PaymentRequests/Senangpay.php` (37 lignes — admin FormRequest)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/PaymentGateways/Routes/senangpay.php` (22 lignes — POST webhook route)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/tests/Feature/Webhooks/SenangpayWebhookIdempotencyTest.php` (test idempotency)

---

> Doc rédigé 2026-05-23 par Claude (Research Agent BRAIN.1).
> Décision finale owner attendue avant souscription Stripe Terminal account.
> Ce doc est read-only — aucune modification code dans le scope de cette research.
