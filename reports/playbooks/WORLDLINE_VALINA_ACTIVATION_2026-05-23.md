# Worldline Valina — Activation pour Le Cayenne borne kiosk · 2026-05-23

> **Agent** : Research Agent BRAIN.7 — Worldline Valina activation path
> **Cible** : owner Le Cayenne (Shine pro acct), Valina déjà installé physiquement
>             sur la borne + imprimante Epson intégrée.
> **Question owner** : "Comment j'active mon Valina pour qu'il encaisse vraiment ?"

---

## TL;DR (3 lignes)

1. **Le Valina n'est PAS un TPE plug-and-play type SumUp.** C'est un terminal "unattended"
   (sans surveillance) destiné aux intégrateurs/fabricants de bornes — il **nécessite
   un contrat acquéreur Worldline merchant services** (≠ Shine), un terminal ID
   provisionné, et une **intégration logicielle TIM / TIM-Server / Android SDK**
   non triviale (estimation owner-side : 3 à 8 semaines + €1500–€8000 dev).
2. **Shine n'est PAS acquéreur Worldline.** Shine recommande SumUp pour ses clients.
   Pour activer le Valina, owner doit signer un contrat acquéreur **Worldline
   Merchant Services France** (ou un acquéreur tiers compatible Valina) — Shine
   garde le compte courant pro mais les flux carte transitent par Worldline.
3. **Recommandation V1** : **NE PAS activer le Valina pour V1 Le Cayenne**.
   Bypass court terme = SumUp Solo (~€39 TTC + 1.75% transaction, plug-and-play
   en 24h, déjà recommandé Shine). Le Valina devient une option V1.0.2/V2 quand
   le volume justifiera le coût d'intégration. Si activation indispensable :
   contacter **Worldline France ventes terminaux** (worldline.com/fr-fr →
   "Solutions commerçants → Terminaux") **avant** d'écrire une ligne de code.

---

## §1 Worldline Valina — qu'est-ce que c'est exactement

### Type
**Terminal de paiement "unattended" (sans surveillance)** destiné à être
**intégré** dans un boîtier hôte : distributeur automatique, borne self-service,
parcmètre, kiosque. Ce n'est pas un TPE comptoir autonome type Ingenico Move.

### Specs techniques (confirmées sources Worldline)

| Item | Détail |
|---|---|
| **Plateforme logicielle** | Android (supporte apps tierces + legacy MAPS) |
| **Écran** | 3.5" couleur capacitif tactile |
| **Lecteurs carte** | Contactless NFC + chip EMV + magstripe (3-en-1) |
| **PIN entry** | PIN pad intégré, EMV L1+L2 |
| **Sécurité** | **PCI PTS 4.x certifié, SRED inclus** (chiffrement bout-en-bout) |
| **Conformité physique** | Vandal-proof + waterproof IP rated, conforme **EVA (European Vending Association)** |
| **Interfaces hardware** | Port 5 TTL (alim + data) + Port 6 MDB (alim + data) — **PAS d'Ethernet/WiFi/4G natif sur le terminal seul** |
| **Connectivité réseau** | Via la **machine hôte** (la borne fournit Ethernet/WiFi/4G au Valina via TIM-Server) |
| **Mode offline / dégradé** | Echo / mode dégradé supporté selon configuration acquéreur — **non garanti**, dépend du contrat |
| **Firmware** | Android Worldline custom, updates OTA via Worldline Terminal Management System (TMS) |

### Implication clé

Le Valina **ne se branche pas en USB sur un PC pour encaisser**. Il s'intègre
via :
- **TIM (Till Integration Module)** = protocole Worldline propriétaire socket TCP
  sur réseau local entre le PC/borne (qui pilote l'UI) et le terminal.
- **MDB** = protocole vending machine standard (peu pertinent restaurant).
- **Android natif** = on peut développer une app Android qui tourne **directement
  sur le Valina** (mais alors c'est le Valina qui devient le kiosk, pas FoodKing).

Pour FoodKing borne kiosk : pattern correct = **TIM-Server socket TCP** entre
le PC borne (où tourne Laravel + Vue kiosk) et le Valina (qui ne fait que
le payment EMV).

---

## §2 Activation Worldline — démarches owner

### Étape 1 — Compte merchant Worldline France (3-7 jours ouvrés)
- URL : https://worldline.com/fr-fr → "Solutions commerçants" → "Acquiring"
- Owner doit prendre RDV commercial (pas de signup self-service web pour terminaux
  unattended) — **typiquement contact téléphonique → commercial dédié → devis**.
- Doc requis : Kbis < 3 mois, IBAN compte pro Shine (le compte de réception
  des virements peut être Shine), pièce d'identité dirigeant, attestation TVA.

### Étape 2 — Signature contrat acquéreur (1-3 semaines)
- Worldline propose **acquisition directe** (Worldline Financial Services Europe S.A.,
  société luxembourgeoise mais opère partout en UE) OU intermédiation via banque
  acquéreuse partenaire (BNP, SocGen, Crédit du Nord, etc.).
- Pour Le Cayenne fast-food single-resto : Worldline direct = plus simple.
- Contrat = "Acceptance Agreement" + grille tarifaire (cf §5).
- KYC + screening risk underwriting + business activity check.

### Étape 3 — Provisioning terminal (3-10 jours après signature)
- Worldline crée un **Terminal ID (TID)** + **Merchant ID (MID)**.
- Push des **clés cryptographiques DUKPT** dans le terminal via le TMS Worldline
  (Terminal Management System) — over-the-air, le Valina se met à jour
  automatiquement quand il est connecté au réseau et joint le TMS.
- Owner reçoit credentials de configuration (TIM-Server URL/port, certificats).

### Étape 4 — Certification application (le **gros** délai — 2 à 6 semaines)
- Si l'app FoodKing communique avec le Valina via TIM, **l'intégration doit être
  certifiée Worldline** (équivalent NF525 mais côté Worldline) avant production.
- Worldline fournit un **environnement sandbox** (test transactions).
- Suite de tests obligatoires : transactions cartes test, edge cases (decline,
  timeout, partial approval, reversal), pre-prod check.
- **Bottleneck typique** : équipe certification Worldline a un backlog, créneau
  alloué 2-6 semaines suivant la complexité de l'intégration.

### Étape 5 — Go-live production (1-2 jours)
- Switch clés sandbox → production via TMS.
- Première transaction réelle €1 carte du dirigeant pour valider end-to-end.

### Délais cumulés réalistes (single-resto France 2026)
- **Minimum théorique** : 4 semaines (rare, si all-star path + intégration triviale)
- **Médian réaliste** : **8-12 semaines** (compte tenu certification + provisioning)
- **Pire cas** : 4-6 mois (si app custom requise et backlog Worldline saturé)

---

## §3 Compatibilité acquéreur Shine

### Verdict net
**Shine n'est PAS acquéreur cartes bancaires.** Shine est une néo-banque
proposant compte pro + cartes émises (Mastercard / Visa émetteur), mais
**ne fait pas l'acquisition** de paiements carte chez les commerçants.

Le partenaire TPE officiel Shine = **SumUp**, plug-and-play, commission 1.75%,
zéro frais fixes. C'est ce que Shine recommande sur son blog.

### Architecture concrète recommandée

```
┌──────────────────────┐                          ┌──────────────────────┐
│  Le Cayenne client   │  →  paiement carte  →   │   Worldline acquéreur│
│  (insère carte sur   │                          │   (Worldline Financial│
│   Valina borne)      │                          │   Services Europe SA) │
└──────────────────────┘                          └──────────┬───────────┘
                                                              │
                                                              ▼ (J+1 ou J+2)
                                          ┌──────────────────────────────┐
                                          │ Virement bancaire net commissions │
                                          │ vers IBAN Shine pro owner        │
                                          └──────────────────────────────┘
```

- Contrat **bipartite** : owner ↔ Worldline (Shine n'est pas dans le contrat).
- Worldline débite les commissions et virevolent net sur IBAN Shine.
- Shine reste pour : virements, virement salaires, dépôts espèces (via
  partenaire), gestion de trésorerie.

### Concrètement pour Le Cayenne
- ✅ **Owner peut activer Valina en gardant Shine** comme banque pro.
- ❌ **Shine ne va pas l'aider à activer Worldline** (Shine pousse SumUp).
- ✅ **L'IBAN Shine sert d'IBAN de versement** auprès de Worldline merchant.

---

## §4 Intégration logicielle FoodKing

### SDK disponibles Worldline (sources : docs.direct.worldline-solutions.com)

| Plateforme | SDK | Pertinence pour FoodKing |
|---|---|---|
| **PHP** | OUI (open source, GitHub) | **Très utile** — Laravel backend FoodKing |
| Java | OUI | non utilisé |
| Node.js | OUI | non utilisé |
| .NET / Python / Ruby / Go | OUI | non utilisé |
| **Android** (Valina app native) | OUI | utile si on remplace l'UI kiosk Vue par Android sur Valina (non recommandé V1) |
| iOS / Flutter / JS | OUI | non pertinent borne |

### ⚠️ Distinction CRITIQUE — 2 produits Worldline différents

| Produit | Pour quoi | SDK | Pertinence Valina |
|---|---|---|---|
| **Worldline Direct / Connect / Sips** | Paiement **en ligne** e-commerce (carte saisie sur site web), hosted checkout, mobile redirect | PHP SDK + REST API + webhooks | ❌ **Pas Valina**. C'est pour panier web. |
| **Worldline Valina TIM-Server** | Paiement **en physique** carte présente sur Valina, terminal piloté depuis app POS/kiosk | Pas de SDK PHP officiel — protocole TIM socket TCP propriétaire | ✅ **C'est ça qu'il faut.** |

**Implication majeure** : le PHP SDK Worldline e-commerce **ne pilote PAS le Valina**.
Il sert pour Worldline Sips (gateway web). Pour Valina, le pattern est :

```
[Borne PC Laravel/Vue] ──TCP socket──> [TIM-Server Worldline localhost
                                         ou réseau LAN]
                                            │
                                            └──> [Valina via port 5 TTL]
```

Le TIM-Server est un binaire fourni par Worldline qui s'installe sur le PC
borne (ou un mini-PC dédié) et expose une API socket TCP.

### Effort dev FoodKing — estimation

| Module | Estimé | Détail |
|---|---|---|
| Lecture doc TIM Worldline + sandbox sign-up | 1 semaine | docs.sips.worldline-solutions.com + manuel Valina |
| Implémenter `App\Http\PaymentGateways\Gateways\WorldlineValina.php` | 1 semaine | Classe service qui ouvre socket TCP TIM, envoie `Purchase {amount}` + parse réponse |
| Webhook / callback handler | 3-4 jours | Pattern Senangpay existant (HMAC-SHA-256 + WebhookEvent UNIQUE) — adaptable |
| Branchement Vue kiosk (frontend) | 3-4 jours | Vue component "WaitingPaymentValina" + polling /api/payment-status |
| Tests E2E + certification Worldline | 2 semaines | suite de tests sandbox, edge cases, sign-off Worldline |
| **TOTAL dev** | **5-6 semaines** | + temps Worldline cert §2 étape 4 |

### Pattern Senangpay → Valina (réutilisable)

Le code Senangpay existant est très réutilisable pour le webhook callback Valina :
- `App\Http\PaymentGateways\Gateways\Senangpay.php` (192 LOC) gère :
  - HMAC-SHA-256 signature verification
  - `WebhookEvent::firstOrCreate(provider, webhook_id)` idempotency
  - Log channel `fiscal` audit
  - 200 OK même sur duplicate (stop retries)

Pour Valina : nouveau fichier `App\Http\PaymentGateways\Gateways\WorldlineValina.php`
qui ajoute :
- Socket TCP client vers TIM-Server (synchrone, mais avec timeout court)
- Polling `/api/payment-status` côté kiosk Vue
- Reconciliation backend après réception confirmation TIM

**Aucun frozen-zone touch nécessaire** — extension du PaymentGateway pattern
existant.

---

## §5 Coût estimé

### Frais Worldline (estimations marché France 2026, à confirmer devis)

| Poste | Prix typique | Note |
|---|---|---|
| **Frais setup terminal** (one-shot) | €100 - €300 | configuration TID/MID, push clés crypto |
| **Frais certification intégration** | **€500 - €2000** | one-shot, payé à Worldline pour valider l'app FoodKing |
| **Abonnement mensuel terminal** | **€15 - €35 / mois** | maintenance, TMS, support, mises à jour OTA |
| **Commission transaction CB** (Visa/MC) | **0.6% - 1.2%** | + parfois €0.05 fixe — varie selon volume promesse |
| **Commission AMEX / Diners** | 2.5% - 3.0% | optionnel |
| **Commission interchange** | inclus dans 0.6-1.2% | scheme fees Visa/MC répercutés |
| **Frais virement** (J+1 ou J+2) | gratuit ou €1-2 | selon contrat |

### Comparatif rapide V1

| Solution | Setup | Mensuel | Commission | Délai activation | Effort dev FoodKing |
|---|---|---|---|---|---|
| **Worldline Valina** | €100-€300 + €500-€2000 cert | €15-€35 | 0.6%-1.2% | **8-12 semaines** | **5-6 semaines** |
| **SumUp Solo + dock** | €0 | €0 | 1.75% | **2-3 jours** | **0** (autonome, ticket séparé du POS) |
| **SumUp Air + API** | €39 hardware | €0 | 1.75% | 1 semaine | 1-2 semaines (REST API SumUp) |
| **Stripe Terminal** | €299 hardware | €0 | 1.4% + €0.25 | 1-2 semaines | 1 semaine (SDK Stripe existant FoodKing) |
| **Yavin (FR)** | €0-€199 | €15-€25 | 1.0%-1.5% | 1-2 semaines | 2-3 semaines (API REST) |

### Break-even Worldline vs SumUp (€20K CA/mois mid-case Le Cayenne)

- Worldline 0.9% : €180/mois commission + €25/mois abonnement = **€205/mois**
- SumUp 1.75% : €350/mois commission + €0 = **€350/mois**
- **Économie Worldline : ~€145/mois** = **~€1740/an**
- Setup + dev one-shot Worldline : ~€2500-€10000 cumulé
- **Break-even : 18-70 mois** (1.5 à 6 ans)

⚠️ **Conclusion économique** : Worldline Valina ne devient rentable qu'à
**volume mature** (€30K+ CA/mois soutenu) — pas une priorité V1 dans le cycle
de vie d'un fast-food single-resto qui démarre.

---

## §6 Recommandation finale

### Verdict orchestrateur

**NE PAS activer le Worldline Valina pour V1 Le Cayenne.**

Raisons :
1. **Délai 8-12 semaines** incompatible avec un démarrage rapide post-NF525 V1.
2. **Coût d'intégration** (€2500-€10000) sans break-even avant 2-6 ans.
3. **Risque certification** Worldline ajoute incertitude calendrier majeure.
4. **Shine n'aide pas** activement à activer Worldline (pousse SumUp).
5. **Cash-only + SumUp Solo** = livrable V1 robuste, immédiat, NF525-compliant.

### Plan d'action recommandé owner — 3 horizons

#### Horizon V1 (immédiat — démarrage Le Cayenne)
- **Cash uniquement** sur borne kiosk (NF525 ok, simulation_hardware production guard)
- **SumUp Solo + dock €39** au comptoir POS pour les clients qui veulent CB
- Worldline Valina = **désactivé physiquement** (écran "hors service", panneau)
- Coût : €39 one-shot + 0% mensuel + 1.75% par transaction CB
- Délai activation : **24h**

#### Horizon V1.0.2 (3-6 mois post-V1)
- Si volume CA > €15K/mois confirmé et clientèle demande CB sur borne :
- **Contact commercial Worldline France** pour devis Valina activation
- Démarrer certification en parallèle de l'exploitation V1
- Garder SumUp en back-up cash-out

#### Horizon V2 (12+ mois — multi-resto SaaS)
- Worldline Valina pleinement intégré dans toutes les bornes
- TIM-Server centralisé ou par site selon archi cloud
- Économies d'échelle de la commission Worldline 0.9% justifient le dev

### Si owner insiste pour activer le Valina maintenant

**Premier contact** : Worldline France ventes terminaux unattended
- Web : https://worldline.com/fr-fr → "Contact commercial"
- Téléphone : disponible sur page contact (variable selon segment)
- Demander : "devis acquisition + activation Valina restaurant single-site
  + accompagnement certification intégration custom"

**À ne PAS faire** :
- ❌ Ne pas commencer le dev FoodKing avant d'avoir signé contrat + reçu
  credentials sandbox.
- ❌ Ne pas s'inscrire à Worldline Sips (e-commerce) en pensant que c'est
  le même produit — différent SDK, différent contrat.
- ❌ Ne pas demander à Shine d'intermédier — Shine ne le fait pas.

### Si owner peut **revendre / rendre** le Valina au vendeur de la borne

C'est une option à explorer : si le vendeur de la borne a livré le Valina
"par défaut" mais que le matériel est encore neuf et que le contrat d'achat
le permet, **récupérer l'équivalent en cash + acheter SumUp Solo (€39)** =
économie nette + simplicité opérationnelle V1.

À demander au vendeur de la borne :
- Est-ce que le Valina peut être restitué ou échangé ?
- Est-ce qu'un autre TPE intégrable est disponible (SumUp Air, Yavin, etc.) ?
- Y a-t-il une garantie partner Worldline qui force l'usage Valina ?

---

## §7 Sources

- [Worldline Valina Switzerland — fiche produit](https://worldline.com/fr-ch/home/main-navigation/solutions/merchants/solutions-and-services/terminals/unattended-payment-terminals/valina) — specs officielles
- [Worldline Valina UK Kestronics — kiosk components](https://www.kestronics.co.uk/kiosk-components/Unattended-Payment-Devices/Valina/)
- [Worldline Valina Symotronic — distributeur FR](https://www.symotronic.com/produit/valina-worldline/)
- [Worldline Valina Planet Monetic — terminal pour automate](https://www.planet-monetic.fr/en/produit/terminal-for-wordline-valina/)
- [Worldline Valina Integration Manual — ManualsLib](https://www.manualslib.com/manual/1335712/Worldline-Valina.html)
- [Worldline Six Valina Integration Manual — ManualsLib](https://www.manualslib.com/manual/1910843/Worldline-Six-Valina.html)
- [Worldline Valina factsheet PDF EN (officiel)](https://worldline.com/content/dam/worldline/documents/publications/factsheets/valina-en.pdf)
- [Worldline Valina Integration Guide FR (officiel)](https://support.worldline.com/content/dam/support-worldline/local/fr-ch/documents/manuals/110058803-A4-ma-valina-int-fr-opt.pdf)
- [Worldline Valina Hungary factsheet](https://worldline.com/content/dam/worldline/local/hu-hu/documents/110067102-fs-valina-int-en_opt.pdf)
- [Worldline TIM Integration Manual](https://worldline.com/content/dam/worldline/local/en-ch/documents/110042102-FL-TIM-INT-EN-1705081.pdf) — protocole socket TCP
- [Worldline Vending Suite France](https://worldline.com/fr-fr/home/main-navigation/solutions/commercants/vending-suite)
- [Worldline Direct PHP SDK — docs développeur](https://docs.direct.worldline-solutions.com/en/integration/how-to-integrate/server-sdks/php)
- [Worldline Direct Android SDK](https://docs.direct.worldline-solutions.com/en/integration/how-to-integrate/client-sdks/android)
- [Worldline Direct Webhooks documentation](https://docs.direct.worldline-solutions.com/en/integration/api-developer-guide/webhooks)
- [Worldline Sips documentation — paiement en ligne](https://documentation.sips.worldline.com/fr/WLSIPS.119-PM-Integration-CB.html)
- [Worldline acquisition definition FR — support legacy](https://support.legacy.worldline-solutions.com/fr/direct/faq/qu-est-ce-qu-un-acqu-reur)
- [Worldline délai activation méthodes paiement FR](https://support.legacy.worldline-solutions.com/fr/direct/faq/quel-est-le-d-lai-d-activation-des-m-thodes-de-paiement)
- [Worldline merchant acquiring solutions global](https://worldline.com/en/home/main-navigation/solutions/merchants/acquiring)
- [Worldline GitHub — SDKs open source](https://github.com/worldline)
- [Worldline Merchant Services GTC FR — contrat type](https://support.worldline.com/content/dam/support-worldline/global/documents/legal-documents/211102-merchant-services-gtc-fr.pdf)
- [Shine TPE titres restaurant — pousse SumUp partner](https://www.shine.fr/blog/terminaux-paiement-encaisser-titres-restaurant/)
- [Shine TPE prix 2026](https://www.shine.fr/blog/outils-tpe-prix/)
- [Pennylane TPE frais 2026](https://www.pennylane.com/fr/fiches-pratiques/compte-pro/quels-sont-les-frais-pour-un-tpe)
- [Qonto prix TPE 2026](https://qonto.com/fr/blog/methodes-de-paiement/terminal-tpe/prix-tpe)
- [Meilleur-tpe TPE restaurant 2026](https://www.meilleur-tpe.fr/tpe-restaurant)

---

## Cross-références internes FoodKing

- `app/Http/PaymentGateways/Gateways/Senangpay.php` — pattern HMAC-SHA-256 +
  WebhookEvent idempotency, **réutilisable** pour le callback Valina.
- `app/Http/PaymentGateways/Gateways/Stripe.php` — pattern PaymentGateway service
  full, **modèle d'extension** pour `WorldlineValina.php` futur.
- `app/Models/WebhookEvent.php` — table UNIQUE (provider, webhook_id), prêt
  pour `provider = 'worldline_valina'`.
- `app/Models/PaymentTerminal.php` — déjà existant, modèle terminal multi-tenant
  prêt pour stocker TID/MID Worldline.
- `reports/playbooks/HARDWARE_TPE_SENANGPAY_RESEARCH_2026-05-23.md` (Brain.1) —
  recherche frère sur TPE Senangpay, à recouper.

---

> **Verdict orchestrateur final** : **V1 = SumUp + cash, Valina = hors service.**
> Re-évaluer V1.0.2 si volume CA confirme rentabilité Worldline.
