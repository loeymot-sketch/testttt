# 🏦 CONFIG TPE BANQUE — Le Cayenne · 2026-05-26
**Contexte corrigé** : owner a déjà 2 TPEs physiques, **pas de SumUp**, doit juste les marier à un contrat acquéreur CB pour que l'argent arrive sur l'IBAN Shine.

---

## ⚡ La clé à comprendre d'abord

**Shine n'est PAS un acquéreur carte bancaire.** Shine est une néo-banque (filiale Société Générale). Elle te donne juste l'IBAN où l'argent atterrit. Mais entre "le client met sa carte dans le TPE" et "l'argent arrive sur ton IBAN Shine", il faut un **acquéreur monétique** qui :

1. Signe avec toi un **contrat acceptation CB**
2. Te donne un **numéro de contrat monétique** (ce que tu appelles "le numéro divers" — c'est ça)
3. Programme ce numéro dans **chaque TPE** que tu as
4. Vire l'argent collecté vers ton IBAN Shine (T+1 ou T+2)

Sans contrat acquéreur, tes 2 TPEs sont des **briques inertes**. Avec contrat, ils acceptent les cartes et tu encaisses.

---

## 🎯 Le "numéro divers / carte divers" que tu cherches

C'est l'un des 3 numéros suivants (la banque utilise plein de noms différents pour la même chose) :

| Nom officiel | Nom commun | Format | Rôle |
|--------------|------------|--------|------|
| **Numéro de contrat monétique (NMC)** | "numéro contrat CB" / "contrat accepteur" | 7-8 chiffres | LE numéro principal — il marie ton TPE à ton compte |
| **Numéro Accepteur** ou **Merchant ID (MID)** | "numéro commerçant" | 15 chiffres souvent | Identifiant unique de TON point de vente côté réseau CB |
| **Numéro Centralisateur** | "centralisateur" | 7-8 chiffres | Numéro de regroupement des virements (un par établissement) |

**Quand tu appelles ta banque/acquéreur pour configurer un TPE, on te demandera ces 3 numéros OU on te les attribuera lors du contrat.**

Documents physiques où ils apparaissent :
- **Contrat acceptation CB** (papier signé avec l'acquéreur, format A4 5-10 pages)
- **Lettre d'accompagnement TPE** envoyée avec le terminal
- Parfois **sticker au dos du TPE** (numéro contrat imprimé)

---

## 🏦 Qui est ton acquéreur ?

**Ce n'est pas Shine.** Tu dois identifier qui a fourni / qui doit fournir le contrat CB pour tes 2 TPEs.

### Question critique pour toi (à clarifier)

| TPE | Qui te l'a donné/vendu ? | Acquéreur probable |
|-----|--------------------------|--------------------|
| **TPE Caisse comptoir** | ??? | À identifier — voir options ci-dessous |
| **TPE Borne Valina (kiosk)** | ??? | **Worldline** (Valina est un produit Worldline → l'acquéreur naturel est **Worldline Merchant Services France**) |

### Les 4 scénarios possibles pour le TPE Caisse

#### Scénario 1 — Tu as déjà signé un contrat acquéreur avec une banque (ancienne activité)
→ Sors le contrat de tes archives. Le **numéro de contrat monétique** est dessus.
→ Appelle ce même acquéreur, demande : "Je veux re-router le settlement vers mon nouveau IBAN Shine FR76..."
→ Délai : 3-5 jours (juste un changement RIB sur contrat existant).

#### Scénario 2 — Le TPE comptoir t'a été remis sans contrat (matériel d'occasion ou non-configuré)
→ Il faut **signer un nouveau contrat acquéreur** avec une banque
→ **Recommandation forte** : **Société Générale Sogecash / Sogemonétique** car Shine = filiale SG → ton dossier KYC est déjà chez SG, le contrat va plus vite (souvent 5-10 jours vs 3-4 semaines ailleurs)
→ Alternatives : Crédit Agricole (Up2pay), BNP Paribas (Mercanet), Worldline direct

#### Scénario 3 — TPE en location chez un acquéreur (Verifone, Ingenico via partenaire)
→ Le contrat acquéreur EST l'acquéreur qui te loue le TPE
→ Sors le contrat de location, le NMC y est
→ Demande changement RIB vers Shine

#### Scénario 4 — Tu ne sais pas (achat sur eBay/seconde main)
→ Le TPE est probablement bloqué/non-configuré (TMS lock)
→ Il faut le **reflasher** chez un acquéreur qui accepte ton modèle
→ Demande à Société Générale ou Worldline si ton modèle est supportable

---

## 📦 Ce que TU FOURNIS à la banque/acquéreur

Pour ouvrir/réactiver un contrat acquéreur CB pour tes 2 TPEs, prépare ce pack KYC :

### Pack documents commerçant (à scanner en PDF couleur)

- [ ] **K-bis Le Cayenne < 3 mois** (déjà fait selon toi ✅)
- [ ] **Pièce d'identité dirigeant** (CNI ou passeport recto-verso couleur)
- [ ] **Justificatif domicile dirigeant** (EDF, gaz, eau, internet — moins de 3 mois)
- [ ] **Bail commercial Le Cayenne** OU titre de propriété du local
- [ ] **Statuts de la société** signés
- [ ] **RIB / IBAN Shine FR** — c'est probablement ce que tu appelles "carte divers" : le **RIB** est imprimé comme une "carte d'identité bancaire" sur fond pré-rempli
- [ ] **SIRET + numéro TVA intracommunautaire**
- [ ] **Attestation INSEE** (avis de situation, gratuit sur avis-situation-sirene.insee.fr)
- [ ] **Estimation chiffre d'affaires mensuel** (honnête, e.g. 8 000 € / 15 000 € / 25 000 €)
- [ ] **Type d'activité** : "Restauration rapide", code APE 56.10C, code MCC 5814

### Pack hardware TPE (le plus important pour configurer)

Pour CHAQUE TPE :

- [ ] **Marque** (Ingenico / Verifone / Worldline / Castles / autre)
- [ ] **Modèle exact** (e.g. "Move 5000", "Tetra Desk 5000", "Valina", "Yomani XR")
- [ ] **Numéro de série (S/N)** — au dos du TPE, sticker
- [ ] **Numéro IMEI** si modèle 4G (au dos du TPE, sticker à côté du S/N)
- [ ] **Connexion physique du TPE** :
  - Caisse : Wi-Fi / Ethernet RJ45 / 4G / USB-vers-PC ?
  - Borne : intégré dans le kiosk (Valina = câble TTL/MDB vers la machine hôte)
- [ ] **Photos** : recto + verso + écran allumé (envoyer à l'acquéreur, ils identifient à vue)

---

## 📨 Ce que TU DEMANDES à la banque/acquéreur (script à envoyer)

**Tu peux envoyer ce texte par email à ton acquéreur (ou téléphoner et lire) :**

```
Bonjour,

Je suis [TON NOM], dirigeant de l'établissement Le Cayenne 
(SIRET XXXXXXXXX, restauration rapide).
Mon compte de versement est Shine (IBAN FR76 ____ ____ ____ ____ ____ ___).

Je dispose de 2 TPEs déjà physiquement installés que je veux 
activer pour acceptation CB :

TPE n°1 — Comptoir caisse :
  Marque : [Ingenico / Verifone / autre]
  Modèle : [_________]
  S/N    : [_________]
  IMEI 4G : [_________ si applicable]

TPE n°2 — Borne self-service :
  Marque : Worldline
  Modèle : Valina (unattended kiosk terminal)
  S/N    : [_________]

Je demande l'ouverture d'un (ou deux selon votre process) 
contrats d'acceptation Cartes Bancaires (CB, Visa, Mastercard, 
+ titres-restaurant CONECS si possible) avec settlement T+1 ou 
T+2 vers mon IBAN Shine ci-dessus.

Précisez-moi :

1. La liste des documents KYC à fournir (j'ai déjà préparé : K-bis, 
   CNI, justificatifs domicile et local, RIB Shine, statuts, 
   SIRET/TVA, attestation INSEE, estimation CA).

2. Vos frais : 
   - Frais d'ouverture de contrat
   - Abonnement mensuel par TPE
   - Commission par transaction CB EEE et hors-EEE
   - Frais éventuels d'acceptation titres-restaurant

3. La compatibilité de mes 2 TPEs avec votre infrastructure 
   acquéreur (Concert / TIM pour le Valina, paramétrage CB5 
   ou CB-Concert pour le comptoir).

4. Le délai d'activation (signature contrat → TPE opérationnel 
   première transaction réussie).

5. La procédure de configuration : 
   - Allez-vous m'envoyer un technicien sur place ?
   - Ou un téléchargement TMS à distance (Terminal Management System) ?
   - Ou dois-je apporter le TPE en agence ?

6. Une fois activé, merci de me transmettre PAR ÉCRIT :
   - Numéro de contrat monétique (NMC) pour chaque TPE
   - Numéro Accepteur / Merchant ID (MID) 
   - Numéro Centralisateur
   - Date d'effet du contrat
   - URL/clé API si paramétrage logiciel POS nécessaire

Cordialement,
[TON NOM]
[Téléphone]
```

---

## 📥 Ce que TU RÉCUPÈRES de la banque/acquéreur (à me transmettre)

Une fois le contrat signé et les TPEs activés, l'acquéreur te donne ces éléments. **C'est ça qu'il me faut pour finaliser la config POS** :

### Pour CHAQUE TPE (Caisse + Borne)

| Élément | Format type | Où le trouver |
|---------|-------------|---------------|
| **Numéro de contrat monétique (NMC)** | 7-8 chiffres | Lettre acquéreur + sticker TPE |
| **Numéro Accepteur / MID** | 15 chiffres | Contrat acceptation CB |
| **Numéro Centralisateur** | 7-8 chiffres | Lettre acquéreur (parfois = NMC) |
| **S/N TPE** | Alphanumérique 10-15 caractères | Au dos du TPE |
| **Protocole d'intégration POS↔TPE** | Concert / TIM / E-DCC / Spire / ECR-Link | À demander à l'acquéreur |
| **IP locale TPE** ou port USB / Bluetooth | 192.168.X.Y:port OU /dev/ttyUSB0 | Config réseau de ton restaurant |
| **Certificat / clé TMS** si protocole sécurisé | Fichier .pem ou .pfx | Acquéreur t'envoie post-config |

### Spécifique Worldline Valina (borne)

| Élément Valina | Pourquoi |
|----------------|----------|
| **Terminal ID Worldline** | Pour appeler l'API TIM-Server |
| **TIM-Server URL** ou IP locale | Adresse à laquelle le POS parle au Valina |
| **Certificat client mTLS** (.pem) | Authentification mutuelle Worldline ↔ POS |
| **Type d'intégration** (Cloud / On-prem / SDK Android) | Détermine le chemin code POS |

---

## 🛠️ Côté technique (moi) — ce que je dois savoir EN PLUS de la banque

Une fois ton acquéreur t'a tout donné, **dis-moi pour CHAQUE TPE** :

1. **Marque + modèle exact** (sticker au dos)
2. **Comment il est connecté** :
   - Caisse : Wi-Fi du resto ? Ethernet sur le routeur ? USB sur l'ordi caisse ?
   - Borne : intégration câble dans le kiosk ?
3. **Protocole supporté** (l'acquéreur le sait — c'est dans la doc contractuelle) :
   - **Concert** : standard Société Générale / BNP / Crédit Agricole — protocole CB français historique
   - **TIM** : Worldline (Valina, Yomani, Yoximo) — moderne
   - **E-DCC / Spire** : Verifone récents
   - **ECR-Link / Saturn** : Ingenico Move/Desk modernes
   - **API Cloud** : Stripe Terminal, SumUp, Adyen (le mode "le TPE parle à un cloud, le POS aussi, ils se synchronisent par le cloud")
4. **Si protocole local (Concert/TIM/E-DCC)** : adresse IP locale du TPE + port (ou USB device)
5. **Si protocole Cloud** : clés API + webhook secret
6. **Une fois j'ai ça** : j'écris un nouveau gateway Laravel `app/Http/PaymentGateways/Gateways/[Brand].php` qui parle au TPE quand le caissier valide une CB

---

## 🚨 ATTENTION — pièges fréquents en config TPE France

1. **Ne PAS donner le K-bis de plus de 3 mois** — refusé systématiquement par les acquéreurs
2. **Ne PAS oublier le RIB Shine** — c'est l'élément qui dit où l'argent va
3. **Vérifier que ton contrat accepte les titres-restaurant CONECS** (Swile, Edenred, Pluxee, Up Déjeuner) — pour un fast-food, c'est ~15-25% du CA en moyenne
4. **Ne pas signer un contrat avec frais d'ouverture > 200 €** — c'est anormal en 2026, les concurrents sont gratuits ou < 100 €
5. **Demander expressément un contrat SANS engagement** ou avec engagement ≤ 12 mois — certains acquéreurs imposent 36-48 mois (Crédit Agricole notamment)
6. **Vérifier les commissions hors-EEE** (cartes étrangères américaines/asiatiques) — peut grimper à 2,5-3% au lieu de 1,4% EEE
7. **Test obligatoire** : juste après config, fais une transaction 1 € avec ta propre carte et vérifie qu'elle arrive en T+1 sur Shine

---

## ✅ Récap : 3 contacts qu'il faut prendre

### Contact 1 — Société Générale Sogemonétique (recommandé pour TPE Caisse)

- **Téléphone** : `0810 105 305` (service commerçants pro)
- **Email** : passe par ton conseiller Shine, qui escalade vers SG monétique (avantage : KYC déjà fait)
- **Site** : `https://www.societegenerale.fr/particuliers/professionnels/encaissement-paiement/`

### Contact 2 — Worldline Merchant Services France (pour le Valina kiosk)

- **Téléphone** : `0800 100 200` (commerciaux terminaux)
- **Email** : via formulaire `worldline.com/fr-fr/home/contact-us`
- **Site** : `https://worldline.com/fr-fr/home/solutions/businesses/business-acceptance.html`

### Contact 3 — Shine (juste pour confirmer settlement IBAN)

- **Chat in-app** : 7j/7 depuis dashboard
- **Email client** : `contact@shine.fr`
- Demande : « Confirmez-moi que mon compte Shine peut recevoir les virements monétiques de **Société Générale Sogemonétique** et **Worldline Merchant Services** sur mon IBAN sans restriction »

---

## 📋 PROCHAINE ACTION CONCRÈTE POUR TOI

1. **Aujourd'hui** : prends une photo de chaque TPE (face + dos = S/N visible) → envoie-moi les photos
2. **Avant 48h** : appelle Société Générale au `0810 105 305` (ou via Shine conseiller) — script ci-dessus
3. **Avant 48h** : appelle Worldline au `0800 100 200` — même script adapté au Valina
4. **Quand tu as les 2 réponses** : transmets-moi par écrit les numéros (NMC, MID, Centralisateur) + marque/modèle/connexion de chaque TPE
5. **Je code l'intégration POS↔TPE** une fois j'ai les protocoles confirmés (2-5 jours dev selon protocole)
6. **Test transaction 1 €** : vérification end-to-end

---

## ❓ Une question pour toi avant que tu lances les appels

**Quelle marque + modèle est ton TPE Caisse comptoir ?** (Le Valina, je connais déjà.) Tourne-le, regarde le sticker au dos, dis-moi :
- Marque (Ingenico / Verifone / Worldline / autre)
- Modèle (Move 5000 / Tetra / Yomani / Lane 5000 / etc.)
- S/N (10-15 caractères alphanumériques)

Avec ça, je peux te dire **immédiatement** quel acquéreur naturel l'accepte sans changer de matériel, et quel protocole je dois implémenter côté POS.
