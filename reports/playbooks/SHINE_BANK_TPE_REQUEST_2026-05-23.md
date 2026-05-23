# Shine Banque Pro — Demande TPE Caisse Le Cayenne · 2026-05-23

> Recherche : RESEARCH AGENT BRAIN.6 — Shine bank TPE offering 2026.
> Owner : Le Cayenne (compte pro Shine principal).
> Méthode : WebSearch + WebFetch sur sources officielles Shine.fr (2026) +
> comparateurs néo-banques + fiches partenaires + presse SumUp.

---

## TL;DR (3 lignes)

**Shine n'a pas de TPE physique en propre — c'est une néo-banque, pas un acquéreur.** Elle propose un partenariat exclusif **SumUp Solo Lite à 24 EUR (au lieu de 34) + commission 1,49 % (au lieu de 1,75 %)** directement débité sur l'IBAN Shine FR, livré en 3-5 jours. **Pour le kiosk Worldline Valina déjà acheté : Shine ne fournit PAS de contrat acquéreur Worldline — il faut un acquéreur séparé (Worldline Merchant Services direct, Adyen ou Stripe)**, le compte Shine ne fait que recevoir les virements. **Action Day-1 : Owner appelle Shine au numéro in-app (9h-12h / 13h30-17h) pour valider activation SumUp via dashboard + ouvrir en parallèle un dossier Worldline France pour le Valina.**

---

## §1 Shine + TPE en 2026

### Shine propose-t-elle un TPE physique en propre ? **NON**

Shine est une **néo-banque (établissement de paiement)**, filiale Société Générale. Son métier : compte pro en ligne + IBAN français FR + carte Mastercard + outils comptables/facturation. **Shine n'est PAS un acquéreur bancaire** — elle ne signe pas de contrats commerçants pour l'acquisition CB, et ne fabrique/loue pas de TPE elle-même.

Toute la communication marketing Shine sur le sujet TPE renvoie vers **un partenaire unique exclusif : SumUp** (https://www.shine.fr/sumup/).

### Partenaire recommandé officiel : SumUp (exclusif)

**Offre Shine x SumUp (2026)** :
- TPE : **SumUp Solo Lite à 24 EUR HT** (prix public 34 EUR, soit -30 %).
- Commission transaction : **1,49 %** (au lieu de 1,75 % en standard SumUp).
- Aucun abonnement mensuel.
- Sans engagement.
- Livraison : **3 à 5 jours ouvrés**.
- Activation : compte Shine validé sous 48h ouvrées, puis lien SumUp accessible depuis dashboard Shine → création compte SumUp → commande TPE.

**Spécs techniques SumUp Solo Lite** :
- Sans contact jusqu'à 50 EUR.
- Mastercard, Visa, American Express, Apple Pay, Google Pay.
- **Titres-restaurant dématérialisés acceptés** : Edenred, Pluxee (ex-Sodexo), Up Déjeuner, Swile (via partenariat Pulp/Conecs depuis 2024).

### Subtilité **IBAN cruciale**

- **Shine = IBAN français FR** (filiale Société Générale, conforme NF525).
- **SumUp standalone (compte SumUp pro) = IBAN irlandais IE** — incompatible avec certains acquéreurs/TPE tiers exigeant IBAN FR.
- **L'offre Shine x SumUp évite ce piège** : les fonds encaissés via SumUp Solo Lite sont **virés directement sur l'IBAN Shine FR** (T+1/T+2), pas sur un compte SumUp IE. **C'est exactement ce qu'il faut pour Le Cayenne.**

### Autres TPE compatibles compte Shine (si owner veut alternative à SumUp)

Liste vérifiée 2026 — TPE qui acceptent un IBAN Shine FR comme compte de réception :

| Partenaire | Confirmé compatible Shine ? |
|---|---|
| **SumUp** (via partenariat) | OUI — exclusif Shine |
| **Yavin** | OUI — listé explicitement Qonto / Shine / Anytime / manager.one / Sogexia / BoursoBank |
| **Smile & Pay** | OUI (vire vers tout IBAN SEPA FR) |
| **myPOS** | OUI (vire vers IBAN SEPA FR) |
| **Stripe Terminal** | OUI (vire vers IBAN SEPA FR) |
| **Square Reader** | OUI (vire vers IBAN SEPA FR) |
| **Zettle (PayPal)** | OUI (vire vers IBAN SEPA FR) |
| **Up2pay Restaurant (Crédit Agricole)** | Probable — à confirmer car acquéreur bancaire CA |
| **Worldline Valina (kiosk)** | **NON direct** — voir §4 |

---

## §2 Démarches owner — script pour appeler/chatter Shine

### Canal de contact Shine

- **Téléphone** : visible **uniquement depuis l'app Shine connectée** (menu "Centre d'aide"). Horaires : lundi-vendredi **9h-12h** et **13h30-17h**.
- **Chat in-app** : 7j/7 depuis dashboard client → "Centre d'aide".
- **Email client** : `contact@shine.fr` (réponse sous 24h).
- **Email non-client** : `support@shine.fr`.

### Script verbatim à dire au support Shine

> Bonjour, je suis [Nom Owner], titulaire du compte pro Shine [IBAN FR76 XXXX XXXX XXXX XXXX], je dirige le restaurant **Le Cayenne** (SIRET [XXXX]). J'ai 5 questions précises :
>
> 1. **Activation TPE SumUp** : je veux activer l'offre exclusive Shine x SumUp Solo Lite à 24 EUR + 1,49 %. Comment j'accède au lien SumUp depuis mon dashboard Shine ? Y a-t-il un délai ou une étape KYC supplémentaire ?
>
> 2. **Versement sur Shine FR** : confirmez-moi que **les fonds encaissés via SumUp Solo Lite seront crédités directement sur mon IBAN Shine FR** (pas sur un compte SumUp irlandais). Quel est le délai de virement T+? (T+1 ouvré ?).
>
> 3. **Titres-restaurant 2026** : le SumUp Solo Lite accepte-t-il bien les **cartes restaurant dématérialisées** Edenred, Pluxee/Sodexo, Up Déjeuner, Swile (CONECS) en France pour un restaurant fast-food ? Y a-t-il une commission différente pour ces transactions ?
>
> 4. **Kiosk Worldline Valina** : j'ai aussi **un TPE Worldline Valina déjà acheté** pour mon kiosk self-service (paiement client autonome). **Shine peut-elle fournir un contrat acquéreur Worldline ?** Si non, vers quel acquéreur dois-je me tourner — Worldline Merchant Services directement, ou un autre partenaire ?
>
> 5. **Commission + frais** : confirmez-moi qu'il n'y a **aucun abonnement mensuel TPE** dans l'offre Shine x SumUp, et que les seuls frais sont la commission 1,49 % par transaction CB. Y a-t-il des frais cachés (frais réception virement SumUp → Shine, frais conversion devise, etc.) ?

### Si support Shine répond évasivement sur Worldline (§4)

> Très bien, je comprends que Shine ne couvre pas l'acquisition Worldline directement. Pouvez-vous me confirmer que je peux **utiliser mon compte Shine FR comme compte de réception** pour un contrat acquéreur signé avec un autre prestataire (Worldline France, Adyen, Stripe) ? Y a-t-il des limites de plafond ou des restrictions d'usage que je dois connaître ?

---

## §3 Partenaires TPE compatibles Shine — matrice complète

Sources : shine.fr/blog (2026), moneyvox.fr/tarif-bancaire, pennylane.com, fr.mobiletransaction.org, independant.io/avis/yavin, fiches officielles partenaires.

| Partenaire | TPE physique | Délai activation | Frais TPE (one-shot) | Abonnement mensuel | Commission transaction | Cartes restaurant | Compatible Shine ? |
|---|---|---|---|---|---|---|---|
| **SumUp Solo Lite (offre Shine)** | Mobile sans imprimante | 3-5 jours après KYC Shine | **24 EUR HT** | **0 EUR** | **1,49 %** | OUI Edenred/Swile/Pluxee/Up via Conecs | **OUI exclusif** |
| **SumUp Solo + imprimante** | Mobile avec ticket | 3-5 jours | 169 EUR HT | 0 EUR | 1,75 % standard | OUI | OUI (sans offre Shine) |
| **Yavin Mini X / X / X fixe** | Mobile + fixe | ~1 sem KYC | Variable selon modèle | **29 EUR HT/mois** | 0,4 % à 0,6 % (volumique) | OUI toutes cartes restaurant | OUI confirmé |
| **Smile & Pay Maxi Smile** | Mobile avec ticket | 5-7 jours | 299 EUR HT | 0 EUR | 1,55 % HT | OUI dématérialisées | OUI |
| **Smile & Pay Super Smile** | Mobile + clavier PIN | 5-7 jours | 299 EUR HT | 0 EUR | 0,49 % + interchange | OUI dématérialisées | OUI |
| **myPOS Go Combo** | Mobile avec ticket | 3-7 jours | 179 EUR HT | 0 EUR | 1,69 % (<10k EUR/mois) | OUI partiel | OUI |
| **Stripe Terminal** | Mobile + comptoir | 1-2 sem KYC | ~59-249 EUR | 0 EUR | 1,4 % EU + 0,25 EUR | OUI via Stripe + Conecs | OUI |
| **Square Reader v2** | Mobile sans imprimante | 3-5 jours | 19 EUR HT | 0 EUR | 1,65 % | Limité | OUI |
| **Zettle Reader 2 (PayPal)** | Mobile sans imprimante | 3-5 jours | 59 EUR HT | 0 EUR | 1,75 % | Limité | OUI |
| **Up2pay Restaurant (Crédit Agricole)** | Mobile + comptoir | 1-3 sem (banque) | Sur devis | Sur devis | Sur devis | OUI CONECS native | À confirmer |
| **Viva.com Terminal app** | Smartphone (Tap-on-Phone) | 24-48h | Logiciel only | 0 EUR | Variable | OUI via app (depuis jan 2024) | OUI |
| **Worldline Valina (Le Cayenne kiosk)** | **Borne self-service** | **§4 ci-dessous** | Déjà acheté | Sur contrat | Sur contrat acquéreur | À configurer | **NON direct** |

### Recommandation pondérée (matrice Le Cayenne fast-food)

1. **SumUp Solo Lite (Shine exclusif)** — **WINNER Day-1** pour POS comptoir. 24 EUR + 1,49 % + 0 abonnement + IBAN Shine direct + 3-5 jours. Acceptation titres-restaurant via Conecs. Simple, propre, NF525-compatible.
2. **Yavin** — runner-up si Le Cayenne dépasse ~3000 EUR CB/mois (commission 0,4-0,6 % > seuil rentabilité abonnement 29 EUR/mois). Compatible Shine confirmé. À considérer en V2 quand volume confirmé.
3. **Smile & Pay Super Smile** — alternative si owner veut commission ultra-basse (0,49 %) + clavier PIN dédié (sécurité plus haute fast-food). Investissement 299 EUR amorti à ~2 mois si volume >5k EUR/mois.

---

## §4 Cas spécial Worldline Valina (kiosk)

### Le Valina n'est PAS un TPE comptoir — c'est un terminal **unattended payment** pour borne self-service

- Construction : **TPE encastrable dans la borne kiosk**, conformité European Vending Association (EVA).
- Cible : distributeurs automatiques, parkings, bornes self-service (cas Le Cayenne).
- Plateforme Android, écran tactile capacitif couleur.
- Owner Le Cayenne **a déjà acheté le matériel Valina** (voir BRAIN.7 et BRAIN.1 Senangpay research).

### Worldline n'est PAS un PSP type Stripe — c'est un **acquéreur bancaire traditionnel**

**Conséquence directe** : pour faire fonctionner le Valina, il faut :
1. Le **terminal Valina lui-même** (déjà acheté).
2. Un **contrat acquéreur** signé avec un acquéreur compatible Worldline (généralement **Worldline Merchant Services France**, ou éventuellement **Adyen** ou **Société Générale acquéreur** via partenaire).
3. L'**affiliation TPE** par l'acquéreur (création du MID / contrat d'affiliation), délai typique **~1 semaine** si déjà affilié, **~3 semaines** si nouvelle affiliation.
4. Une **passerelle de paiement** intégrée à l'application kiosk Le Cayenne (FoodKing kiosk → Valina via SDK / API Worldline).

### Shine fait-elle l'acquisition ? **NON**

Shine est un **établissement de paiement** (filiale Société Générale) **mais pas un acquéreur**. Elle ne signe pas de contrats commerçants pour l'acquisition CB. **Aucune offre de Shine ne couvre l'activation d'un Worldline Valina.**

Le compte Shine **peut servir de compte de réception** pour les virements de l'acquéreur Worldline (Worldline Merchant Services vire vers tout IBAN SEPA FR). **C'est tout.** Le contrat acquéreur doit être ouvert **directement avec Worldline France ou un de ses revendeurs** (Planet Monetic, Symotronic, RPSolutions, etc.).

### Démarches concrètes : ordre des appels

**Phase 1 — TPE comptoir POS (URGENT Day-1, encaissement standard)**
1. **Owner appelle Shine in-app** : active offre SumUp Solo Lite (script §2 questions 1-3 + 5).
2. **Commande SumUp Solo Lite** depuis dashboard Shine → livraison 3-5 jours.
3. **Encaisse premières CB** sur compte Shine FR — Le Cayenne ouvert avec POS opérationnel.

**Phase 2 — Kiosk Valina (sous 4-6 semaines, paiement self-service)**
1. **Owner appelle Worldline France direct** : 0 800 911 911 (service commerçants) ou via revendeur Planet Monetic (https://www.planet-monetic.fr).
2. **Demande contrat acquéreur Worldline + affiliation Valina** + IBAN Shine FR comme compte de réception.
3. **Délai KYC + affiliation** : ~1-3 semaines selon dossier.
4. **Intégration technique FoodKing kiosk** : SDK Worldline ou API VALINA selon l'intégrateur choisi (référer à `reports/playbooks/HARDWARE_TPE_SENANGPAY_RESEARCH_2026-05-23.md` pour le contexte Senangpay déjà étudié — décider si on garde Senangpay PSP ou si on bascule sur Worldline acquéreur pur).

### Question stratégique à poser à Worldline lors de l'appel

> J'ai déjà acquis un terminal **Worldline Valina** pour mon kiosk self-service au restaurant Le Cayenne. Je veux :
> 1. Souscrire un **contrat acquéreur Worldline Merchant Services France** avec ce Valina comme TPE associé.
> 2. Recevoir les virements sur mon IBAN pro **Shine FR**.
> 3. Connaître les **commissions Worldline** (CB Visa/Mastercard + Amex + titres-restaurant) et l'**abonnement mensuel**.
> 4. Connaître le **délai d'activation total** (KYC + affiliation + paramétrage Valina).
> 5. Vérifier la **compatibilité du Valina avec l'API/SDK Worldline** pour intégration à mon application kiosk Vue.js + Laravel (besoin info technique pour mon développeur).

---

## §5 Recommandation finale brain-orator

### Action immédiate Day-1 — **SumUp Solo Lite via Shine**

- **Owner appelle Shine demain matin 9h** (chat in-app ou téléphone visible app) avec le script §2.
- **Active l'offre Shine x SumUp** : 24 EUR + 1,49 % + 0 abonnement + IBAN Shine FR.
- **Commande SumUp Solo Lite** depuis dashboard Shine (3-5 jours livraison).
- **Le Cayenne peut ouvrir le restaurant avec POS opérationnel sous 1 semaine**, sans toucher au kiosk Worldline pour l'instant.

### Phase 2 — Kiosk Worldline Valina (sous 4-6 semaines)

- **Pas via Shine** — Shine ne fait pas l'acquisition Worldline.
- **Contact direct Worldline France** ou revendeur (Planet Monetic, Symotronic) pour contrat acquéreur Valina.
- **Délai : ~1-3 sem KYC + affiliation** + intégration FoodKing kiosk.
- **Cross-reference** : voir `reports/playbooks/HARDWARE_TPE_SENANGPAY_RESEARCH_2026-05-23.md` (BRAIN.1) pour décider si on garde Senangpay (PSP simple) OU si on bascule sur Worldline acquéreur direct (intégration plus lourde mais commissions probablement meilleures pour gros volumes).

### Pourquoi cet ordre

1. **SumUp Solo Lite résout 80 % du besoin restaurant** en 1 semaine (POS comptoir standard).
2. **Le Valina kiosk est un projet plus lourd** (contrat acquéreur séparé + intégration tech) — peut attendre Phase 2 sans bloquer ouverture restaurant.
3. **Owner ne doit PAS attendre Worldline pour ouvrir** — le risque serait de perdre 3-6 semaines avec un POS papier/cash only.
4. **L'IBAN Shine FR est l'invariant central** : il reçoit aussi bien SumUp (T+1) que plus tard Worldline acquéreur (T+1/T+2) sans changer de banque.

### Verdict honnête sur Shine

- **Force Shine** : IBAN FR + Mastercard pro + comptabilité + dépôt chèques/espèces (1600 points) + service client FR (élu 2026) + partenariat SumUp préférentiel. Bon choix pour Le Cayenne.
- **Limite Shine** : **pas d'acquisition CB en propre, pas de contrat Worldline, pas de POS intégré**. Si owner voulait un "tout-en-un banque + acquisition + POS", Shine ne le fait pas — il faudrait basculer vers BNP Paribas pro (acquéreur direct) ou Crédit Agricole Up2pay (acquéreur restaurant). Pour Le Cayenne, **rester sur Shine + SumUp pour POS + Worldline direct pour kiosk = stack hybride mais propre**, validée par NF525 (chaque acquéreur a son rapport Z séparé, agrégation côté FoodKing backend).

### Risques détectés

1. **Risque IBAN IE confusion** : si owner clique sur "compte pro SumUp" au lieu de "Shine x SumUp", il aura un IBAN irlandais — **garde un seul IBAN FR Shine**, ne jamais ouvrir de compte SumUp séparé.
2. **Risque commission cumulée** : 1,49 % SumUp = bien pour <10 000 EUR/mois. Au-delà, **Yavin (29 EUR/mois + 0,5 %) devient plus rentable**. À monitorer après 6 mois d'activité.
3. **Risque titres-restaurant** : tester en réel que SumUp accepte bien les 4 marques (Edenred, Swile, Pluxee, Up) sur le terrain. Conecs est censé couvrir, mais Le Cayenne doit faire une transaction test avec chaque carte la semaine d'ouverture.

---

## §6 Sources consultées

### Sources officielles Shine
- [Shine x SumUp — TPE à 24 EUR + 1,49 %](https://www.shine.fr/sumup/)
- [Shine — Contacter le support](https://www.shine.fr/contacter-shine/)
- [Shine — Top 6 meilleurs TPE 2026](https://www.shine.fr/blog/meilleurs-terminaux-paiement/)
- [Shine — Combien coûte un TPE en 2026](https://www.shine.fr/blog/outils-tpe-prix/)
- [Shine — Quel TPE pour cartes restaurant ?](https://www.shine.fr/blog/terminaux-paiement-encaisser-titres-restaurant/)
- [Shine — Quelle banque pro pour restaurant ?](https://www.shine.fr/blog/banque-choix-restaurant/)
- [Shine — Compte pro tarifs](https://www.shine.fr/tarifs/)
- [Shine — Cartes bancaires](https://www.shine.fr/nos-cartes/)

### Sources Worldline (Valina kiosk)
- [Worldline France — Self-service](https://worldline.com/fr-fr/home/main-navigation/qui-sont-nos-clients/self-service)
- [Worldline — VALINA terminal sans surveillance](https://worldline.com/fr-ch/home/main-navigation/solutions/merchants/solutions-and-services/terminals/unattended-payment-terminals/valina)
- [Worldline — Délai activation moyens paiement](https://support.legacy.worldline-solutions.com/fr/direct/faq/quel-est-le-d-lai-d-activation-des-m-thodes-de-paiement)
- [Planet Monetic — Valina revendeur FR](https://www.planet-monetic.fr/en/produit/terminal-for-wordline-valina/)
- [Symotronic — Valina revendeur FR](https://www.symotronic.com/produit/valina-worldline/)

### Comparateurs néo-banques + TPE 2026
- [MoneyVox — Tarifs Shine 2026](https://www.moneyvox.fr/tarif-bancaire/compte-pro/shine/)
- [Qonto blog — Compte pro Shine fonctionnalités](https://qonto.com/fr/blog/qonto/compte-pro-vs-banques/shine)
- [Indy — Compte pro avec TPE 2026](https://www.indy.fr/guide/compte-bancaire/pro/tpe/)
- [Portail Auto-Entrepreneur — Avis Shine](https://www.portail-autoentrepreneur.fr/academie/comparatifs/banques/avis-shine)
- [Detective Banque — Compte pro avec TPE](https://www.detective-banque.fr/banque/banque-pro/compte-pro-avec-tpe/)
- [Pennylane — Top 6 TPE 2026](https://www.pennylane.com/fr/fiches-pratiques/compte-pro/les-5-meilleurs-terminaux-de-paiement)
- [Independant.io — Avis Yavin 2026](https://independant.io/avis/yavin/)
- [MoneyVox — Yavin offre TPE 2026](https://www.moneyvox.fr/epargne/yavin)
- [Connect Banque — Avis Yavin](https://www.connectbanque.com/fr/avis-yavin-terminal-de-paiement)

### Sources SumUp + Conecs (titres-restaurant)
- [SumUp — Compte pro IBAN](https://www.sumup.com/fr-fr/compte-pro/retail-new/)
- [SumUp x Conecs partenariat](https://www.sumup.com/fr-fr/press/partenariat-sumup-conecs/)
- [SumUp — Accepter titres-restaurant](https://help.sumup.com/fr-FR/articles/1R5CzImZJZVrsDAHsVe7Wk-accepter-titres-restaurant)
- [SumUp — Modes de paiement restaurant](https://www.sumup.com/fr-fr/business-guide/modes-de-paiement-restaurant/)
- [Mobile Transaction — Avis compte pro SumUp](https://fr.mobiletransaction.org/sumup-compte-pro-avis/)

### Cross-references internes FoodKing
- `reports/playbooks/HARDWARE_TPE_SENANGPAY_RESEARCH_2026-05-23.md` (BRAIN.1 — Senangpay PSP vs Worldline acquéreur)
- `reports/playbooks/HARDWARE_PRINTER_DRAWER_RESEARCH_2026-05-23.md` (BRAIN.3 — imprimante + tiroir)
- `reports/playbooks/OWNER_OPERATIONS_PLAYBOOK_2026-05-23.md` (BRAIN.4 — playbook Day-1)
- `reports/playbooks/STAFF_ONBOARDING_K_2026-05-23.md` (BRAIN.5 — onboarding caisse)
- `reports/playbooks/CLOUD_DOMAIN_DECISION_2026-05-23.md` (BRAIN.2 — domaine + cloud)
- BRAIN.7 — Worldline Valina activation path (sister-research, pending)
- BRAIN.8 — NF525 "Vente diverse" design propre (pending)
