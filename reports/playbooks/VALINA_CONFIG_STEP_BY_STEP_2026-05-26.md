# 🏧 CONFIG VALINA BORNE — Guide step-by-step · 2026-05-26
**TPE concerné** : Worldline Valina (déjà physiquement installé dans la borne)
**Objectif** : que le client mette sa carte → la borne encaisse → l'argent arrive sur ton IBAN Shine

---

## ⚠️ Avant tout : le délai réaliste

**Activer un Valina = 8 à 12 semaines** en moyenne France 2026.

Ce n'est PAS un TPE plug-and-play. C'est un terminal "unattended" (sans surveillance) qui exige :
1. Un contrat acquéreur signé avec Worldline (3-4 semaines)
2. Un provisioning hardware par Worldline (1-2 semaines)
3. Une certification logicielle de l'intégration POS (2-6 semaines)
4. Un test sandbox + go-live (1-2 semaines)

Si tu veux ouvrir Le Cayenne dans moins de 2 mois, **prends une décision honnête maintenant** :
- **Option Worldline pure** : Valina actif au Jour J+90 (mi-août si on démarre demain)
- **Option Worldline + plan B** : ouvre avec un TPE comptoir simple pour le comptoir ET la borne en mode "cash uniquement" temporairement, le Valina arrive en production V1.0.1 dans 2-3 mois

Je continue ce guide en supposant que tu veux **lancer la procédure Valina maintenant** quoi qu'il arrive — c'est la bonne décision si tu veux que Valina soit en prod un jour, plus vite c'est lancé, plus vite c'est prêt.

---

## 📐 PARTIE 1 — Comprendre l'architecture Valina

### Schéma de flux

```
┌─────────────────────────┐
│   Client devant borne   │
│   Insère carte CB       │
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────────────────────┐
│  Valina (intégré dans la borne)          │
│  - Lit la carte (EMV chip + NFC)         │
│  - Demande PIN si > 50 €                 │
│  - Communique en TCP socket avec...      │
└────────────┬────────────────────────────┘
             │ TIM protocol (socket TCP)
             ▼
┌─────────────────────────────────────────┐
│  TIM-Server (binaire Worldline)          │
│  installé sur le PC qui pilote la borne  │
│  - Reçoit "Purchase {amount}"            │
│  - Transmet au Valina                    │
│  - Retourne "Approved / Declined"        │
└────────────┬────────────────────────────┘
             │ HTTPS sortant (chiffré DUKPT)
             ▼
┌─────────────────────────────────────────┐
│  Réseau acquéreur Worldline (datacenter) │
│  - Validation carte + interchange        │
│  - Réservation montant                   │
└────────────┬────────────────────────────┘
             │ J+1 ou J+2 (virement net)
             ▼
┌─────────────────────────────────────────┐
│  Ton IBAN Shine FR76...                  │
│  - Virement net de commissions           │
│  - Visible dans dashboard Shine          │
└─────────────────────────────────────────┘
```

### Acteurs en jeu

| Acteur | Rôle | Tu paies/reçois quoi ? |
|--------|------|------------------------|
| **Toi (Le Cayenne)** | Commerçant | Tu paies setup + abonnement + commission |
| **Worldline Merchant Services FR** | Acquéreur CB | T'envoie l'argent net sur Shine |
| **Worldline Terminal Services** | Fabricant/loueur du Valina | T'envoie le firmware + clés crypto |
| **Shine** | Banque (compte de réception) | Te garde l'argent disponible |
| **Visa / Mastercard / CB** | Schemes carte | Worldline les paie, tu ne traites pas avec eux |
| **Foodking POS (moi côté code)** | Logiciel POS qui parle au Valina | Tu paies 0 € (déjà développé) |

---

## 📞 PARTIE 2 — Appel commercial Worldline (Semaine 1)

### Numéro à composer

**Worldline France — Ventes Terminaux Commerçants**
- Site officiel : `worldline.com/fr-fr/home/contact-us`
- Téléphone : `0800 100 200` (gratuit depuis France)
- Email commercial via formulaire web

### Demande d'avant-vente — script verbatim

> ✂️ **Lis ce texte au téléphone (ou envoie par email via le formulaire)** :

```
Bonjour,

Je suis [TON NOM], dirigeant du restaurant Le Cayenne 
(SIRET XXXXXXXXX, restauration rapide).

J'ai déjà physiquement installé un terminal Valina dans 
une borne self-service self-commande dans mon établissement. 
Je souhaite l'activer pour acceptation Cartes Bancaires.

Pourriez-vous m'orienter vers le bon service commercial pour :

1. Ouverture d'un contrat acquéreur Worldline Merchant Services 
   (acquisition CB Visa/Mastercard + titres-restaurant CONECS 
   si possible).

2. Provisioning de mon Valina existant 
   - Numéro de série : [À récupérer au dos du Valina, sticker]
   - Modèle Valina : [Valina / Valina+ / Valina V2 selon ce 
     que tu lis au dos]
   - Compatibilité avec compte de versement IBAN Shine FR76...

3. Mise à disposition du TIM-Server (le binaire qui pilote le 
   Valina depuis le PC de la borne) + documentation 
   d'intégration.

4. Devis incluant :
   - Frais d'ouverture de contrat
   - Frais de provisioning terminal
   - Frais de certification de l'intégration POS custom 
     (j'utilise un logiciel maison FoodKing en Laravel + Vue)
   - Abonnement mensuel terminal
   - Commission par transaction (CB, hors-EEE, AMEX)
   - Frais de virement vers IBAN Shine

5. Délais réels - signature contrat à première transaction 
   réussie en production.

6. Référence : pourriez-vous m'indiquer un commercial dédié et 
   me donner son nom + email + téléphone direct ?

Merci pour votre retour.
Cordialement,
[TON NOM]
[Téléphone]
[Email]
```

### Ce qui se passe ensuite

- Worldline va te rappeler dans **24-72h** avec un commercial dédié
- Premier RDV téléphonique de qualification : **1 semaine après l'appel**
- Devis envoyé par email : **1-2 semaines après le RDV**

---

## 📁 PARTIE 3 — Pack KYC à fournir à Worldline (Semaine 2-3)

### Documents commerçant (tous en PDF couleur, < 3 mois)

- [ ] **K-bis Le Cayenne** moins de 3 mois (impression de `infogreffe.fr`, gratuit ou 2,82 €)
- [ ] **Pièce d'identité dirigeant** (CNI ou passeport recto-verso couleur)
- [ ] **Justificatif domicile dirigeant** (EDF / gaz / eau / internet, moins de 3 mois)
- [ ] **Bail commercial** du local Le Cayenne OU titre de propriété
- [ ] **Statuts de la société** signés
- [ ] **RIB / IBAN Shine FR** complet
- [ ] **Attestation INSEE** (`avis-situation-sirene.insee.fr` gratuit)
- [ ] **Estimation chiffre d'affaires mensuel** (honnête : 5K / 10K / 20K €)
- [ ] **Photos de la borne installée** avec le Valina dedans (Worldline demande souvent pour valider que le terminal est bien intégré dans un hôte conforme)

### Documents Valina

- [ ] **Numéro de série du Valina** (S/N) — sticker au dos
- [ ] **Modèle exact** : `Valina` ou `Valina+` ou `Valina V2`
- [ ] **Date d'achat du Valina** + facture si tu l'as
- [ ] **Photos du Valina** :
  - Face avant écran allumé
  - Dos avec sticker S/N visible
  - Connexions (Port 5 TTL / Port 6 MDB visibles)

⚠️ **Si tu as acheté le Valina d'occasion** : il faudra peut-être un transfert de propriété auprès de Worldline (le terminal était peut-être déjà provisionné pour un autre commerçant et doit être "dé-provisionné" puis re-provisionné pour toi). C'est faisable mais ajoute 1-2 semaines.

---

## 🔌 PARTIE 4 — Connexion physique du Valina dans la borne (Semaine 3-4)

### Ce que tu dois vérifier MAINTENANT sur ta borne

Le Valina **ne se branche pas en WiFi ou Ethernet directement**. Il a 2 ports :
- **Port 5 TTL** : alimentation + données série (TX/RX)
- **Port 6 MDB** : alimentation + données vending machine (peu utile restaurant)

La borne où le Valina est installé doit fournir :

- [ ] **Alimentation 12-24V DC** (à travers Port 5 TTL)
- [ ] **Câble TTL** vers un convertisseur USB-Serial OU directement vers un GPIO d'un PC industriel (Raspberry Pi, mini-PC Intel)
- [ ] **Le PC de la borne** doit avoir :
  - OS Linux ou Windows (Linux recommandé pour stabilité)
  - Connexion internet (Wi-Fi du resto ou Ethernet câblé)
  - Au moins **1 GB RAM libre** pour faire tourner TIM-Server + ton app FoodKing kiosk
  - Power-on automatique au démarrage (la borne ne doit jamais "rester éteinte")

### Photos à m'envoyer

Pour que je puisse t'aider sur la config réseau du PC borne :

- [ ] Photo de l'intérieur de la borne (le PC + le câblage du Valina)
- [ ] Modèle/marque du PC industriel (sticker)
- [ ] OS installé sur le PC (si tu peux te connecter dessus : tape `lsb_release -a` sur Linux ou `winver` sur Windows)

---

## 🔐 PARTIE 5 — Provisioning Worldline (Semaine 4-5)

Après signature du contrat acquéreur, Worldline t'envoie un email avec :

### Identifiants à recevoir et me transmettre

| Élément | Format | Note |
|---------|--------|------|
| **Terminal ID (TID)** | 8 chiffres | Identifiant unique du Valina côté Worldline |
| **Merchant ID (MID)** | 15 chiffres | Identifiant unique de Le Cayenne commerçant |
| **Numéro de contrat monétique (NMC)** | 7-8 chiffres | Pour ta compta + ton dossier NF525 |
| **Numéro Centralisateur** | 7-8 chiffres | Pour le regroupement des virements |
| **URL TIM-Server prod** | Ex: `https://timserver.worldline.fr/...` | L'URL à laquelle ton PC borne appellera |
| **URL TIM-Server sandbox** | Ex: `https://sandbox-timserver.worldline.fr/...` | Pour les tests certif (différent de prod) |
| **Certificat client mTLS** | Fichier `.pem` | Auth mutuelle Worldline ↔ ton PC borne |
| **Clé privée associée** | Fichier `.key` | À garder ULTRA secrète (équivalent mot de passe) |
| **Manuel d'intégration TIM** | PDF Worldline | Doc officielle protocole TIM |
| **Access TMS (Terminal Management System)** | URL + login dashboard Worldline | Pour suivre l'état du Valina, voir les transactions |

### Push des clés crypto DUKPT (automatique, 2-5 jours)

Une fois le contrat signé, Worldline pousse automatiquement les clés cryptographiques DUKPT (Derived Unique Key Per Transaction) dans le Valina **par-dessus l'air** via TMS.

Pour que ça marche :
- Le Valina doit être **allumé** et **connecté au réseau** (le PC borne doit lui transférer internet via le câble TTL → c'est le TIM-Server qui fait ce relais)
- Worldline t'enverra un email "Terminal provisioné" une fois le push réussi
- Délai typique : **2-5 jours ouvrés** après signature contrat

---

## 💻 PARTIE 6 — Installation TIM-Server sur PC borne (Semaine 5-6)

Le **TIM-Server** est un programme à installer sur le PC qui pilote la borne. C'est lui qui fait le pont entre ton app FoodKing (qui dit "le client doit payer 12,50 €") et le Valina (qui exécute le paiement).

### Étapes d'installation

1. **Worldline envoie le binaire TIM-Server** par email (lien de téléchargement sécurisé)
2. **Installer sur le PC borne** :
   - Linux : `sudo dpkg -i tim-server-prod.deb` ou décompression dans `/opt/worldline/`
   - Windows : installateur `.exe` à exécuter en mode administrateur
3. **Copier le certificat mTLS** (`worldline-client.pem` + `worldline-client.key`) dans le dossier de config TIM-Server (généralement `/etc/worldline/certs/` sur Linux)
4. **Configurer le fichier `tim-server.conf`** :
   ```ini
   [Acquirer]
   url=https://timserver.worldline.fr/...
   terminal_id=<TON_TID>
   merchant_id=<TON_MID>
   
   [Network]
   listen_port=4242  # Port TCP local sur lequel l'app FoodKing parle au TIM-Server
   
   [Certificate]
   client_cert=/etc/worldline/certs/worldline-client.pem
   client_key=/etc/worldline/certs/worldline-client.key
   
   [Valina]
   serial_device=/dev/ttyUSB0  # Le câble TTL vers le Valina
   baud_rate=115200
   ```
5. **Lancer TIM-Server** comme service systemd (Linux) ou service Windows
6. **Test de connexion** : depuis le PC borne, `telnet localhost 4242` doit ouvrir une connexion (sans erreur)

⚠️ **Worldline ne t'aide PAS à installer TIM-Server** — c'est ton intégrateur (moi) ou un technicien dépêché par Worldline (souvent facturé 500-1000 € en plus) qui fait ça.

**Option A** : je le fais à distance via SSH une fois tu m'as donné accès au PC borne.
**Option B** : Worldline envoie un technicien sur place (coût ~800 € HT).

---

## 🧪 PARTIE 7 — Intégration POS FoodKing ↔ TIM-Server (côté moi)

Une fois TIM-Server tourne sur le PC borne, **je crée le gateway Laravel** qui parle au TIM-Server.

### Ce que je code (déjà prévu dans le plan Brain.7)

```php
// app/Http/PaymentGateways/Gateways/WorldlineValina.php
class WorldlineValina extends PaymentGateway
{
    public function charge(Order $order): PaymentResult
    {
        // 1. Ouvre socket TCP vers TIM-Server (localhost:4242)
        $socket = fsockopen('localhost', 4242);
        
        // 2. Envoie demande de paiement au format TIM
        fwrite($socket, "Purchase {$order->total_eur_cents}\n");
        
        // 3. Attend la réponse Valina (timeout 60s pour saisie PIN)
        $response = fgets($socket, 4096);
        
        // 4. Parse la réponse (Approved / Declined / Cancelled)
        if (str_starts_with($response, 'Approved')) {
            return PaymentResult::success(...);
        }
        return PaymentResult::failure(...);
    }
}
```

### Branchement frontend Vue kiosk

Je modifie le composant kiosk pour :
1. Au moment où le client clique "Payer par carte sur borne"
2. Affiche un écran "Veuillez insérer votre carte sur le terminal"
3. Appelle `POST /api/kiosk/order/{id}/charge-valina`
4. Polling `/api/payment-status/{id}` toutes les 1s
5. Si "Approved" : affiche "Merci !" + envoi en cuisine
6. Si "Declined" : affiche "Paiement refusé, veuillez réessayer"

### Délai dev de mon côté

**5-6 semaines** une fois j'ai :
- ✅ Accès SSH au PC borne (pour installer + tester TIM-Server)
- ✅ Tous les credentials (TID, MID, certif mTLS, URL TIM-Server)
- ✅ Accès au sandbox Worldline (URL + credentials test)
- ✅ Documentation TIM officielle (PDF Worldline)

---

## ✅ PARTIE 8 — Certification Worldline (Semaine 8-10)

C'est l'étape la **plus longue et incertaine**. Worldline ne te laisse pas mettre en production tant que ton intégration n'a pas passé leur certification.

### Tests obligatoires (sandbox)

Worldline t'envoie une "test suite" : ~30-50 scenarios à passer. Exemples :
- [ ] Transaction nominale 10 € — Approved
- [ ] Transaction nominale 0,01 € — Approved
- [ ] Carte refusée (insuf fonds) — Declined avec message correct
- [ ] Timeout transaction (client n'insère pas la carte) — Cancelled
- [ ] Annulation par client (touche rouge) — Cancelled
- [ ] Reversal (annulation après approbation) — Approved puis Reversed
- [ ] Refund partiel
- [ ] Carte étrangère hors-EEE (Mastercard US)
- [ ] Apple Pay / Google Pay
- [ ] Carte expirée
- [ ] PIN incorrect 3 fois
- [ ] Coupure réseau pendant transaction
- [ ] Reboot du Valina en pleine transaction

### Process

1. Je code la suite de tests Playwright + PHPUnit qui couvre les 30-50 cas
2. On exécute tout en sandbox (TIM-Server pointant vers sandbox.worldline.fr)
3. On envoie le rapport à Worldline
4. Worldline reviewe (peut prendre 2-4 semaines, dépend de leur backlog)
5. Si OK : ils basculent les clés sandbox → prod
6. Si KO : on fixe + on re-soumet (peut prendre 1-2 cycles)

### Coût certification

**500 - 2000 €** facturé par Worldline (one-shot).

---

## 🚀 PARTIE 9 — Go-live + transaction test (Semaine 12)

### Bascule sandbox → production

Worldline t'envoie un email "Vous êtes prêts à passer en prod" :
1. Je modifie le fichier `tim-server.conf` :
   - `url=https://timserver.worldline.fr/prod/...` (au lieu de sandbox)
   - Nouveaux certificats prod (Worldline les enverra séparément)
2. Redémarrer TIM-Server (`systemctl restart tim-server`)
3. **Test transaction 1 €** avec ta propre carte CB
4. Vérifier dans Shine que le 1 € arrive en J+1 ou J+2

### Au passage en prod

- [ ] Vérifier le ticket imprimé sur l'imprimante Epson (mentions légales NF525 obligatoires : commerçant, SIRET, TVA, NMC, montant TTC, etc.)
- [ ] Vérifier que la transaction apparaît dans le **dashboard TMS Worldline** (interface web Worldline pour suivi)
- [ ] Vérifier qu'elle apparaît aussi dans **FoodKing admin → /admin/orders** avec le bon payment_method et payment_status=paid
- [ ] Vérifier que la chaîne `audit_logs` NF525 a bien append le nouveau row (hash chain valide)
- [ ] Vérifier que `php artisan fiscal:assert-chain-clean` retourne exit 0

---

## 📅 PARTIE 10 — Timeline réaliste

| Semaine | Action | Acteur |
|---------|--------|--------|
| **1** | Appel + email Worldline ventes terminaux | Toi |
| **1-2** | RDV qualification + devis | Toi ↔ commercial Worldline |
| **2-3** | Envoi pack KYC + signature contrat | Toi |
| **3-4** | Worldline traite KYC + provisioning hardware | Worldline |
| **4-5** | Worldline pousse clés crypto + envoie credentials | Worldline |
| **5-6** | Installation TIM-Server PC borne + config | Moi (avec accès SSH) |
| **5-8** | Dev gateway Laravel + intégration Vue kiosk | Moi |
| **8-10** | Suite tests certif sandbox | Moi + Worldline |
| **10-11** | Review Worldline | Worldline |
| **11-12** | Go-live prod + test transaction 1 € | Toi + moi |

**Total : 12 semaines** (3 mois) si tout va bien. Compte 14-16 semaines si retards normaux.

---

## 💰 PARTIE 11 — Coûts Valina réels (à confirmer devis Worldline)

| Poste | Coût estimé |
|-------|-------------|
| Frais ouverture contrat | **100 - 300 € HT** (one-shot) |
| Frais provisioning terminal | **0 - 200 € HT** (one-shot) |
| Frais certification intégration | **500 - 2000 € HT** (one-shot) |
| Abonnement mensuel terminal | **15 - 35 € HT** / mois |
| Commission CB Visa/Mastercard EEE | **0,6 % - 1,2 %** par transaction |
| Commission AMEX | 2,5 % - 3,0 % (optionnel) |
| Frais virement vers Shine | 0 - 2 € / mois ou gratuit |
| Technicien install sur place (option) | 500 - 1000 € (si tu choisis pas le SSH à distance) |
| **TOTAL one-shot HT estimé** | **600 - 3 500 € HT** |
| **TOTAL mensuel HT estimé** | **15 - 35 €** + ~1 % du CA CB |

### Comparaison économique Valina vs un TPE comptoir simple

Pour 20 000 € CA mensuel via la borne :
- Valina @ 0,9 % = 180 €/mo commission + 25 €/mo abo = **205 €/mo**
- Un TPE comptoir simple (Ingenico/Verifone, contrat SG via Shine) @ 0,8 % = 160 €/mo commission + 20 €/mo abo = **180 €/mo**
- Économie : **~25 €/mois en faveur du TPE simple**

**Mais** : le Valina te permet d'encaisser **directement sur la borne sans cassier** = gain RH ~1500 €/mois si tu peux réduire de 0,5 ETP. Donc Valina rentable même si commission un peu plus haute.

---

## 🎯 ACTION IMMÉDIATE — Cette semaine

### Lundi
- [ ] Trouver le S/N et modèle du Valina (sticker au dos)
- [ ] Photographier le Valina (face + dos + connexions)
- [ ] Photographier l'intérieur de la borne (PC + câblage Valina)

### Mardi
- [ ] Appeler Worldline au `0800 100 200` avec le script de la PARTIE 2
- [ ] Ou remplir le formulaire web `worldline.com/fr-fr/home/contact-us`

### Mercredi-Jeudi
- [ ] Rassembler les 9 documents KYC (PARTIE 3)
- [ ] M'envoyer toutes les photos prises + S/N Valina

### Vendredi
- [ ] Notifier ton associé : on attend retour Worldline (24-72h)
- [ ] En parallèle : commencer à m'identifier le TPE Caisse comptoir (sticker au dos = marque + modèle + S/N) pour qu'on enchaîne sur la 2e procédure

---

## ❓ Questions pour toi MAINTENANT

Pour avancer ensemble efficacement :

1. **Le Valina est-il actuellement allumé ?** (écran qui affiche quelque chose, même "hors service")
2. **Le PC qui pilote la borne** : tu sais y accéder (SSH ou clavier physique) ?
3. **As-tu acheté le Valina neuf** (avec facture Worldline) ou d'occasion (eBay/Leboncoin) ?
4. **Le bail commercial Le Cayenne est-il à TON nom (gérant)** ou au nom de la société ?
5. **Préférence install TIM-Server** : moi à distance SSH (gratuit, plus rapide) OU technicien Worldline sur place (~800 €, mais ils gèrent tout) ?

Réponds à ces 5 questions et je peux préparer un **scénario d'exécution précis** pour ton appel à Worldline mardi.

---

## 📞 Numéros utiles Valina

| Service | Contact | Quand |
|---------|---------|-------|
| **Worldline ventes terminaux FR** | `0800 100 200` | Ouverture contrat |
| **Worldline support technique commerçants** | À recevoir après signature | Pannes terminal, bugs |
| **Worldline TMS portal** | URL fournie post-signature | Suivi transactions, état Valina |
| **Shine support pro** | App in-app chat | Confirmer routing IBAN |
| **Le constructeur de ta borne** | (à identifier) | Modifications hardware borne |

---

## 🛡️ Pour rappel : ce qui est déjà prêt côté logiciel

Tu n'as PAS à attendre Worldline pour démarrer le reste de Le Cayenne :

- ✅ Stack FoodKing complète (POS + KDS + OSS + Cash + Admin + Stock)
- ✅ NF525 fiscal chain audit_logs + z_reports + 6 ans archive
- ✅ Sentinels frozen-zone + bundle staleness
- ✅ Sanitization PENDING_ (Ultra-Review heal + V1.0.2 Wave A)
- ✅ Deploy scripts Hetzner (Wave C3/C4 prêts en `--really-deploy`)
- ✅ Pre-flight gate (`fiscal:assert-chain-clean`)
- ✅ A11y + UX V1.0.2 polish

**Tu peux ouvrir aux clients en parallèle de l'attente Valina** :
- Borne en mode **"cash uniquement"** au démarrage (le client tape sa commande sur la borne, paie en espèces au comptoir)
- Comptoir avec TPE classique (qu'on configure dès que tu me donnes la marque/modèle)
- Valina activé à J+90 quand Worldline a fini

C'est un V1 progressif honnête.

---

Le doc complet est dans `reports/playbooks/VALINA_CONFIG_STEP_BY_STEP_2026-05-26.md`.

Tu réponds aux 5 questions de "❓ Questions pour toi MAINTENANT" et je prépare le scénario exécution Worldline pour mardi.
