# 🚀 GO-LIVE CHECKLIST OWNER — Le Cayenne V1
**Date** : 2026-05-26 · **Owner** : toi · **Stack tech** : ✅ prête (V1.0.2 hardening complet)

---

## 🎯 Ce qu'il reste pour vraiment "ouvrir la caisse" en réel

| Lot | Status | Bloqueur |
|-----|--------|----------|
| **Stack logicielle** | ✅ Prête | Aucun (10 commits cycle V1.0.2 livrés) |
| **TPE Caisse (comptoir)** | ⏳ Activer | Commander SumUp Solo Lite via Shine |
| **TPE Borne (kiosk)** | ⏳ 2 options | Activer Valina OU 2e SumUp Solo |
| **Imprimante ticket + tiroir** | ⏳ Acheter | Bons connus (Epson TM-T20III + Safescan 4141) |
| **Domaine + Cloud** | ⏳ Acheter | `lecayenne.fr` + Hetzner CX32 |
| **SSL HTTPS** | ⏳ Auto | certbot lance auto au server-setup |
| **Données fiscales** | ⏳ Toi | SIRET + numéro TVA + adresse + RIB |
| **Comptes Stripe (failsafe)** | ⏳ Optionnel | Backup si SumUp tombe |

---

# 📋 PARTIE A — Ce que tu demandes à TON ASSOCIÉ

> Note : ton associé est la personne qui interface avec la banque et les fournisseurs pour le restaurant. Cette section est ce que **tu lui transfères en bloc** pour qu'il fasse les démarches.

## A.1 — Demande à l'associé : ouvrir les contrats commerçants

**Mission associé** : « Appelle ou rends-toi en agence Shine pour activer les choses suivantes en suivant ce script. Demande UN seul rendez-vous où tu valides tout d'un coup. »

### À cocher avec l'associé

- [ ] **Confirmer l'IBAN Shine du compte pro Le Cayenne** est bien `FR76 XXXX XXXX XXXX XXXX` (à reporter ici ↑)
- [ ] **Récupérer le numéro SIRET** Le Cayenne → format `XXX XXX XXX XXXXX` (14 chiffres)
- [ ] **Récupérer le numéro de TVA intracommunautaire** → format `FR XX XXXXXXXXX` (13 caractères)
- [ ] **Récupérer le code APE/NAF** (activité de restauration rapide = `56.10C`)
- [ ] **Récupérer le K-bis < 3 mois** (extrait registre du commerce — service Infogreffe gratuit ou 2,82€)
- [ ] **Récupérer la pièce d'identité dirigeant** (CNI/passeport recto-verso couleur scannée)
- [ ] **Récupérer le justificatif de domicile pro** (bail commercial Le Cayenne, EDF, internet pro)

> Sans ces 7 éléments, RIEN ne peut être activé. C'est le pack KYC standard France 2026 pour activer un acquéreur carte.

## A.2 — Demande à l'associé : commander le hardware

**Mission associé** : « Commande aujourd'hui ce hardware via le dashboard Shine ou directement aux fournisseurs. »

### TPE Caisse (comptoir) — recommandé

- [ ] **SumUp Solo Lite** via le partenariat Shine
  - Prix : **24 € HT** (au lieu de 34 € public) + commission **1,49 %** (au lieu de 1,75 %)
  - Délai : **3-5 jours ouvrés**
  - Activation : passer par le dashboard Shine → bandeau "TPE SumUp" → bouton "Commander"
  - **Important** : ne PAS commander un SumUp Solo en dehors de Shine — ça donne un IBAN irlandais incompatible avec ta fiscalité française. **Le passage via Shine garantit l'IBAN FR**.

### TPE Borne (kiosk) — 2 OPTIONS, owner décide

#### Option Worldline Valina (si déjà acheté/installé)
- [ ] Ouvrir un **dossier acquéreur Worldline Merchant Services France**
  - Téléphone : `0800 100 200` ou via le site `worldline.com/fr-fr` → Solutions commerçants → Terminaux
  - Demander : "activation Valina déjà installé sur kiosk self-service, restaurant Cayenne, intégration SDK TIM Android"
  - Délai : **3-8 semaines** (KYC heavy + provisioning terminal ID)
  - Coût : **frais d'activation 200-500 € + abonnement mensuel ~30-50 € + commission négociée**
  - **AVERTISSEMENT** : selon la recherche Brain.7, le Valina nécessite 3-8 semaines de dev intégration. Pour V1 c'est lourd. Voir option B.

#### Option B (recommandée V1) — DOUBLE SumUp Solo Lite
- [ ] Commander **2 SumUp Solo Lite** via Shine (un pour caisse, un pour borne)
  - Prix total : **48 € HT** (24 × 2)
  - Délai : **3-5 jours**
  - Activation immédiate, plug-and-play
  - **Suffit pour V1** — bornes self-service avec TPE attaché par câble court ou Bluetooth = 100% fonctionnel dès Day-1
  - Désactiver le Valina temporairement, repassage à V1.0.2 quand volume justifie

### Périphériques caisse — recommandés (recherche Brain.3)

- [ ] **Imprimante ticket Epson TM-T20III**
  - Prix : **~ 150 € HT**
  - Format : 80mm thermique USB + LAN
  - Compatible NF525 (ticket thermique conforme 6 ans archive)
  - Achat : amazon.fr / cdiscount-pro / fournisseur restaurant local
- [ ] **Tiroir-caisse Safescan 4141**
  - Prix : **~ 75 € HT**
  - Connexion : RJ11 vers imprimante (pas USB direct)
  - Ouverture sur impression ticket = standard NF525
- [ ] **Câble RJ11 imprimante↔tiroir** (souvent fourni avec tiroir, vérifier)
- [ ] **Rouleaux papier thermique 80mm × 80mm** : pack de 50 ~30 € HT

---

# 🏦 PARTIE B — Ce que tu demandes à SHINE (la banque)

> Tu peux faire ça toi-même par chat in-app Shine OU par téléphone (numéro visible app connectée, lundi-vendredi 9h-12h / 13h30-17h).

## B.1 — Script verbatim FR à envoyer/dire à Shine

> ✂️ **Copie-colle ce texte tel quel dans le chat Shine** :

```
Bonjour,

Je suis [TON NOM], titulaire du compte pro Shine Le Cayenne 
(IBAN FR76 _____________________).
SIRET : _____________________

J'ai 6 demandes à activer ensemble :

1) ACTIVATION TPE SUMUP SOLO LITE x2
   Je veux activer l'offre exclusive Shine x SumUp Solo Lite 
   à 24€ HT + 1,49 % en DOUBLE (1 pour ma caisse comptoir + 
   1 pour ma borne self-service). Pouvez-vous m'envoyer le lien 
   d'activation SumUp depuis mon dashboard Shine ?

2) VERSEMENT IBAN FR
   Confirmez-moi que les fonds encaissés via SumUp Solo Lite 
   seront crédités directement sur mon IBAN Shine FR (T+1 ouvré ?),
   et NON sur un compte SumUp irlandais.

3) TITRES-RESTAURANT 2026
   Le SumUp Solo Lite accepte-t-il bien les cartes restaurant 
   dématérialisées Edenred, Pluxee/Sodexo, Up Déjeuner, Swile 
   (via CONECS) pour mon restaurant fast-food ?
   Y a-t-il une commission différente sur ces transactions ?

4) RECEVOIR LES IDS TPE
   Une fois le SumUp activé et livré, je veux que vous me 
   confirmiez par écrit (email) les éléments techniques suivants :
   - Numéro de série de chaque TPE (Serial Number / S/N)
   - Numéro IMEI 4G (si applicable)
   - Merchant ID (compte commerçant SumUp)
   - Clé API SumUp Cloud (Access Token / Secret)
   - URL webhook de notification (pour intégration POS)

5) ATTESTATION CONFORMITÉ NF525
   Pouvez-vous m'envoyer une attestation que les transactions 
   carte via SumUp sont conformes à la Loi de Finance NF525 ?
   (mon logiciel de caisse archive les tickets 6 ans, j'ai besoin 
   de la pièce côté acquéreur pour mon dossier d'inspection)

6) ASSURANCE FRAUDE / CHARGEBACK
   Quelle est la politique de chargeback SumUp via Shine ?
   Y a-t-il une assurance fraude incluse, ou dois-je souscrire ?

Merci de me répondre point par point par email.
Cordialement,
[TON NOM]
```

## B.2 — Ce que Shine doit te renvoyer par email (à vérifier)

Après leur réponse, tu dois avoir **PAR ÉCRIT (email)** :

- [ ] Lien d'activation SumUp depuis dashboard Shine ✉️
- [ ] Confirmation IBAN FR settlement T+1 ✉️
- [ ] Liste titres-restaurant acceptés + commissions ✉️
- [ ] Procédure pour récupérer les **identifiants techniques TPE** une fois reçu ✉️
- [ ] Attestation NF525 (ou nom de la pièce équivalente) ✉️
- [ ] Politique chargeback + délai contestation ✉️

⚠️ **Si Shine n'arrive pas à te répondre sur le point 5 (NF525)** : c'est NORMAL — c'est ton logiciel de caisse (FoodKing) qui gère la chaîne fiscale, pas l'acquéreur. Tu peux passer outre.

---

# 💳 PARTIE C — Ce que tu demandes à SUMUP directement

> Une fois Shine t'a donné l'accès SumUp et que tu as créé ton compte SumUp via le lien Shine, **tu devras compléter ton profil SumUp**.

## C.1 — Profil SumUp à compléter (dashboard SumUp)

- [ ] **Identité dirigeant** : CNI scan recto-verso (le K-bis prouve juste la société, pas le dirigeant)
- [ ] **Coordonnées société** : raison sociale exacte = "Le Cayenne" + adresse établissement + SIRET
- [ ] **Type d'activité** : "Restauration rapide" (code MCC 5814 - Eating Places, Restaurants)
- [ ] **IBAN de versement** : IBAN Shine FR confirmé ↑
- [ ] **Justificatif activité** : K-bis < 3 mois + bail commercial OU EDF Pro
- [ ] **Volume estimé mensuel** : honnête (e.g. 5 000 € / 10 000 € / 20 000 €) — détermine niveau KYC
- [ ] **Activer Apple Pay / Google Pay** dans paramètres SumUp (gratuit, en 2 clics)
- [ ] **Activer titres-restaurant** : section "Moyens de paiement" → demander activation Conecs (Swile/Edenred/Pluxee/Up) → 3-5 jours validation
- [ ] **Récupérer les clés API SumUp Cloud** (à donner au dev = moi) :
  - `client_id` (sous "Développeur" > "Application")
  - `client_secret`
  - `merchant_code` (visible dans Profil > Informations société)
  - Webhook signing secret

## C.2 — Vérification reçu après livraison TPE

Quand le SumUp Solo Lite arrive (3-5j), à la première utilisation :

- [ ] **Allumer le TPE** (bouton à droite)
- [ ] **Connecter Wi-Fi du restaurant** (paramètres → réseau → ta box)
- [ ] **Login** avec le compte SumUp créé via Shine (email + mot de passe)
- [ ] **Mettre à jour le firmware** si proposé
- [ ] **Faire une transaction test 0,01 €** sur ta propre carte (tu peux te rembourser après)
- [ ] **Vérifier dans Shine** que le 0,01 € arrive bien en T+1 sur IBAN FR
- [ ] **Noter le S/N (numéro de série)** au dos du TPE → à donner au dev

---

# 🌐 PARTIE D — Domaine + Cloud (toi seul peux le faire)

## D.1 — Acheter le domaine `lecayenne.fr`

**Vérifié 2026-05-23** : `lecayenne.fr` est disponible.

- [ ] Aller sur **ovh.com/fr** → barre de recherche domaine → taper `lecayenne.fr`
- [ ] Ajouter au panier : `lecayenne.fr` (≈ 5,99 € TTC année 1, ≈ 9,35 € renouvellement)
- [ ] **OPTIONNEL mais recommandé** : ajouter aussi `lecayenne.com` (~ 10 € TTC) et `lecayenne.eu` (~ 5 € TTC) pour protéger la marque → total ~ 21 € TTC année 1
- [ ] **Décocher les options** : pas besoin DNS Premium, pas besoin hébergement OVH (on prend Hetzner ailleurs)
- [ ] **Activer protection WHOIS gratuite** (cache tes infos perso)
- [ ] **Payer carte CB** → réception immédiate

Une fois acheté :
- [ ] **Récupérer les identifiants OVH** (login + mot de passe) → à donner au dev pour configurer les DNS qui pointent vers Hetzner

## D.2 — Acheter le serveur cloud Hetzner CX32

**Choix confirmé** : Hetzner CX32 Falkenstein DE (4 vCPU / 8 GB RAM / 80 GB NVMe / 20 TB trafic). Coût **~ 6,80 €/mo HT ≈ 8,16 € TTC** (RGPD ✓).

- [ ] Aller sur **hetzner.com** → "Cloud" → "Sign up"
- [ ] Créer un compte avec ton email pro Le Cayenne
- [ ] **Validation KYC légère** : carte d'identité scan (24h max)
- [ ] **Ajouter moyen de paiement** : carte CB ou PayPal
- [ ] Une fois validé, créer un **Project** : "FoodKing-LeCayenne-V1"
- [ ] Créer un **Server** :
  - Image : **Ubuntu 22.04 LTS**
  - Type : **CX32** (Standard, AMD)
  - Location : **Falkenstein (fsn1) DE** (latence FR ≈ 20ms)
  - SSH Key : ajouter ta clé publique (ou en générer une avec instructions Hetzner)
  - Volume : laisser les 80 GB par défaut, pas besoin d'extra disk
- [ ] **Au démarrage, noter** :
  - IP publique du serveur (IPv4 + IPv6)
  - User par défaut : `root`
  - Mot de passe initial OU clé SSH selon ton choix

→ **À me donner** : IP publique + accès SSH (clé OU mot de passe) — je lance `server-setup-hetzner.sh` et `deploy-hetzner.sh` (déjà préparés Wave C3/C4).

## D.3 — Configurer DNS OVH → Hetzner

Une fois `lecayenne.fr` acheté ET serveur Hetzner créé :

- [ ] Aller sur **manager.ovh.com** → "Domaines" → `lecayenne.fr` → onglet **"Zone DNS"**
- [ ] Supprimer les enregistrements par défaut "parkpage" / "redirect"
- [ ] Ajouter **2 enregistrements A** :
  - Sous-domaine `@` (= racine) → IPv4 Hetzner
  - Sous-domaine `www` → IPv4 Hetzner
- [ ] Ajouter **2 enregistrements AAAA** (optionnel IPv6) :
  - `@` → IPv6 Hetzner
  - `www` → IPv6 Hetzner
- [ ] **TTL** : laisser à 300 ou 3600 secondes
- [ ] **Valider** : la propagation prend 15 min à 4 h
- [ ] **Vérifier** : depuis ton ordi, `ping lecayenne.fr` doit répondre depuis l'IP Hetzner

---

# 🛠️ PARTIE E — Ce que je (dev/Claude) ai BESOIN de toi pour finaliser

> Sans ces 8 éléments, je ne peux pas terminer la config technique. Donne-les-moi dès que tu les as récupérés des parties A/B/C/D.

## E.1 — Données fiscales société (PARTIE A)
- [ ] **SIRET** Le Cayenne : `___ ___ ___ _____`
- [ ] **Numéro TVA intracommunautaire** : `FR __ _________`
- [ ] **Adresse exacte du restaurant** : `____________________________`
- [ ] **Code APE/NAF** : `56.10C` (à confirmer)
- [ ] **Nom commercial exact** affiché sur tickets : `Le Cayenne` (oui/non/autre ?)
- [ ] **IBAN Shine FR** : `FR76 ____ ____ ____ ____ ____ ___`

## E.2 — TPE Caisse (PARTIE B + C)
- [ ] **S/N (serial number) TPE Caisse** : `__________`
- [ ] **SumUp Merchant Code** : `__________`
- [ ] **SumUp `client_id`** : `__________`
- [ ] **SumUp `client_secret`** : `__________`
- [ ] **SumUp Webhook signing secret** : `__________`
- [ ] **Email du compte SumUp** : `__________@__________`

## E.3 — TPE Borne (PARTIE B option Valina OU 2e SumUp)
**Si SumUp x2** (recommandé V1) :
- [ ] S/N TPE Borne (2e SumUp) : `__________`
- [ ] Reste = mêmes credentials SumUp que Caisse

**Si Worldline Valina activé** :
- [ ] Terminal ID Worldline : `__________`
- [ ] Merchant ID Worldline : `__________`
- [ ] URL TIM-Server Worldline : `__________`
- [ ] Certificat client mTLS Worldline : (fichier .pem)

## E.4 — Domaine + Cloud (PARTIE D)
- [ ] **IP publique IPv4 Hetzner** : `___.___.___.___`
- [ ] **IP publique IPv6 Hetzner** (optionnel) : `____:____:____:____:____:____:____:____`
- [ ] **Clé SSH privée OU mot de passe root** Hetzner (à me transmettre via canal sécurisé)
- [ ] **Domaine confirmé acheté** : `lecayenne.fr` ✅ + `.com` `.eu` (oui/non)
- [ ] **DNS A/AAAA configurés** OVH → Hetzner (oui/non)

## E.5 — Email pro (besoin pour SSL + notifications)
- [ ] **Email admin Le Cayenne** : `admin@lecayenne.fr` (à créer post-domaine via OVH Email Pro 0€ inclus 6 mois, puis ~2€/mo) ou Gmail temporaire
- [ ] **Email de réception alertes système** : si différent, dis-le-moi

## E.6 — Données back-up (NF525 6 ans)
- [ ] **Compte Backblaze B2** créé (ou OVH Object Storage / S3 OVH) pour push backup quotidien chiffré : `__________@__________`
- [ ] **Clés API Backblaze (Key ID + Key Application)** : `__________`

---

# 🚀 PARTIE F — Ordre d'exécution conseillé (timeline réaliste)

## Semaine 1 (toi)
- **Jour 1** : envoie email Shine (script B.1) + commande SumUp via Shine + achat `lecayenne.fr` OVH
- **Jour 2-3** : associé rassemble les 7 documents KYC (A.1)
- **Jour 4-5** : créé compte Hetzner + serveur CX32
- **Jour 6-7** : DNS OVH → Hetzner

## Semaine 2 (toi + moi)
- **Jour 8-10** : SumUp arrive → activation + transaction test 0,01 €
- **Jour 9-10** : tu me transmets E.1 + E.2 + E.4 + E.5
- **Jour 10** : je lance `server-setup-hetzner.sh --really-setup` sur Hetzner (45 min auto)
- **Jour 11** : je lance `deploy-hetzner.sh --really-deploy --host=<IP>` (15 min auto)
- **Jour 11** : test https://lecayenne.fr → page login
- **Jour 12-14** : on intègre les credentials SumUp dans admin → POS branch payment_gateways → tests live de bout en bout

## Semaine 3 (soak test)
- **Jour 15-17** : soak test 5 jours avec données réelles non-critiques (cf. tâche G3)
- **Jour 18-21** : formation staff K (cf. `STAFF_ONBOARDING_K_2026-05-23.md`)

## Semaine 4 (ouverture réelle)
- **Jour 22** : ouverture réelle aux clients
- **Jour 22-35** : 2 semaines shadow operation (cf. tâche G5)

---

# 📊 RÉCAP COÛTS TOTAUX V1 GO-LIVE

| Poste | Coût HT one-shot | Coût TTC mensuel |
|-------|------------------|------------------|
| TPE Caisse SumUp Solo Lite | 24 € | 1,49% / transaction |
| TPE Borne SumUp Solo Lite (option B) | 24 € | 1,49% / transaction |
| Imprimante Epson TM-T20III | ~150 € | 0 |
| Tiroir-caisse Safescan 4141 | ~75 € | 0 |
| Domaine `lecayenne.fr` | 6 € | 0,80 € (amorti) |
| Domaine `.com` + `.eu` (optionnel) | 15 € | 1,30 € (amorti) |
| Serveur Hetzner CX32 | 0 € (mensuel) | 8,16 € |
| Email Pro `admin@lecayenne.fr` | 0 € | 0 € (6 mois) puis ~2 € |
| Backups Backblaze B2 | 0 € | ~0,05 € (< 1 GB) |
| **TOTAL one-shot HT** | **~294 €** | — |
| **TOTAL mensuel TTC** | — | **~10 €** + commissions transactions |

> Pour 200 transactions/jour à ticket moyen 12 € = 72 000 € CA mensuel, commissions SumUp ≈ **1 073 € / mois** (1,49 %). C'est le coût de "garder l'argent encaissé en CB sur ton compte FR".

---

# ⚠️ POINTS D'ATTENTION CRITIQUES

## 1. NF525 fiscal — non négociable
- ✅ Stack FoodKing gère la chaîne fiscale (audit_logs HMAC + z_reports 6 ans)
- ✅ `php artisan fiscal:assert-chain-clean` est le gate avant tout deploy (Wave C1)
- ⚠️ **Ne JAMAIS** supprimer une ligne `audit_logs` ou `z_reports` manuellement → prison time France

## 2. Pas de "test" en prod sur des vrais clients
- Avant ouverture, **soak test 5 jours** avec ta carte (G3)
- 14 jours **shadow operation** post-ouverture (G5) — staff K supervise avec œil sur tous les retours clients

## 3. Sauvegardes obligatoires
- **Quotidien** automatique → Backblaze B2 (Wave 2F backup déjà setup, juste à brancher credentials)
- **Hebdomadaire** snapshot Hetzner (gratuit dans le plan)
- **Mensuel** archive NF525 complète (commande `foodking:fiscal:archive` existe)

## 4. Numéros à NE JAMAIS partager
- IBAN Shine → uniquement à la banque + acquéreur officiel
- Clés API SumUp `client_secret` → uniquement avec moi via canal chiffré (Signal / .env encrypted)
- Clé SSH privée Hetzner → uniquement avec moi
- Mot de passe admin Le Cayenne (123456 actuellement) → **À CHANGER avant prod** : me dire le nouveau mot de passe via canal sécurisé

## 5. Plan B si SumUp en panne (jour J)
- **Backup acquéreur** : avoir un compte **Stripe Standard** activé en secours (gratuit, juste validation KYC) — me dire si tu veux que je l'intègre comme failsafe
- **Backup hardware** : un 3e SumUp Solo Lite supplémentaire (24 € de plus) ou un Stripe Reader S700 (259 €)

---

# 📞 NUMÉROS UTILES

| Service | Contact | Quand l'appeler |
|---------|---------|-----------------|
| **Shine support pro** | App connecté → "Centre d'aide" → téléphone (visible en app) ; chat 7j/7 ; email `contact@shine.fr` | Activation TPE, IBAN, KYC |
| **SumUp support FR** | `08 00 94 70 30` (gratuit) ; email `support@sumup.fr` | Pannes TPE, transaction litige |
| **Worldline FR commerciaux** | `0800 100 200` (gratuit) | Activation Valina (si Option A) |
| **OVH support FR** | `1007` (gratuit depuis France) | Problème DNS, domaine |
| **Hetzner support** | Email/ticket only via console | Problème serveur |
| **AFNIC** (registre .fr) | `01 39 30 83 00` | Litige propriété domaine |
| **Infogreffe K-bis** | `0 891 01 11 11` (0,30€/min) | K-bis < 3 mois |

---

# ✅ CHECKLIST FINALE "JE PEUX OUVRIR"

Le jour où **TOUTES ces cases** sont cochées, tu peux ouvrir aux clients :

- [ ] Stack logicielle : V1.0.2 hardening complet (déjà ✅)
- [ ] TPE Caisse : SumUp Solo Lite reçu, testé, transaction 0,01 € arrive sur IBAN Shine FR
- [ ] TPE Borne : 2e SumUp Solo Lite reçu, testé (ou Valina activé si Option A)
- [ ] Imprimante ticket : Epson TM-T20III branchée, imprime un ticket test propre
- [ ] Tiroir-caisse : Safescan 4141 ouvre à l'impression du ticket test
- [ ] Domaine : `lecayenne.fr` répond HTTPS, certificat SSL valide
- [ ] Cloud : serveur Hetzner CX32 up, `php artisan fiscal:assert-chain-clean` retourne exit 0
- [ ] Données : SIRET + TVA + adresse + IBAN intégrés dans FoodKing admin → settings
- [ ] Mot de passe admin : changé depuis "123456"
- [ ] Backup quotidien : Backblaze configuré, premier backup réussi
- [ ] Staff K : formation onboarding complète (cf. `STAFF_ONBOARDING_K_2026-05-23.md`)
- [ ] Soak test : 5 jours done, 0 P0 incident
- [ ] Shadow op planifié : 14 jours owner-supervisé post-ouverture
- [ ] Plan B : compte Stripe failsafe activé (optionnel mais recommandé)

---

**Quand tu m'as donné la liste E (sections E.1 à E.6), je peux faire passer Le Cayenne de "stack prête" à "ouvre demain matin" en ~2 jours de config technique de mon côté.**

Tu n'as plus besoin d'un seul ticket dev — tout ce qui reste est procédural (administratif + matériel).

🎯 **Une question pour toi avant que tu fasses A/B/C/D** :
- **Option A (Valina kiosk)** ou **Option B (2e SumUp pour borne)** ?

Si tu hésites, recommandation forte V1 : **Option B (2e SumUp)** — 24 € vs 3-8 semaines + 200-500 € + 30-50 €/mo + dev intégration. Valina reste pour V1.0.2 quand volume justifie.
