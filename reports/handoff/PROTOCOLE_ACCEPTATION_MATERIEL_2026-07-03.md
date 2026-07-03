# ✅ PROTOCOLE D'ACCEPTATION MATÉRIEL — Le Cayenne (borne · caisse · cuisine)
### « Assurer que c'est RÉELLEMENT fonctionnel sur les machines » — preuve couche par couche

> **Date** : 2026-07-03 · **HEAD** : `43b4ca38e`
> **Principe** : je ne peux pas appuyer sur le bouton papier d'ici — alors **chaque couche se PROUVE
> elle-même** (commande → sortie attendue). Quand toutes les couches sont vertes, c'est fonctionnel :
> **rien n'est supposé**. Et j'ai supprimé les pannes **silencieuses** → toute panne est désormais
> **bruyante et diagnostiquable** (message clair, plus de « page grise » muette).

---

## 0. CE QUI EST DÉJÀ PROUVÉ CÔTÉ CODE (ne sera pas la cause d'un échec)

| Prouvé | Preuve |
|---|---|
| Rendu ticket caisse client + cuisine (design pro : nom gras, compo indentée, tél, adresse, largeur 32, coupe partielle) | octets ESC/POS **décodés** ligne par ligne ; PHP Hardware **39/39** |
| Ticket borne (produit gras, compo compacte, **feed 8 = plus de grand blanc**, coupe partielle, tél, adresse) | test octets **13/13** |
| Les 2 ponts **démarrent et répondent `UP`** sur le vrai moteur node | boot réel vérifié (`/health` → UP) |
| Feature « imprimer ticket client/cuisine depuis la file à encaisser » | Vitest **6/6** |
| **Anti-« page grise »** : pont présent mais impression échoue → **erreur claire, PAS window.print** | Vitest **6/6** |
| Flash Terminal caisse supprimé (`windowsHide`) | code |

> Donc si un ticket sort mal sur la machine, la cause est **matérielle/config** (pont pas lancé, mauvais
> nom d'imprimante, flag Chrome, donnée prod) — **pas le rendu**. Le protocole ci-dessous isole laquelle.

---

## 1. BORNE — 6 couches (L0 → L5)

| # | Preuve à exécuter (PowerShell borne) | Sortie attendue = PASS | Si ÉCHEC |
|---|---|---|---|
| **L0** | `node -v` puis `node -e "require('usb');console.log('usb OK')"` | `v18+` et `usb OK` | réinstaller node / `npm i usb` dans `C:\borne-print` |
| **L1** | `(iwr http://127.0.0.1:9100/health -UseBasicParsing).Content` | `UP` | pont non lancé → `Start-Process node -Arg 'C:\borne-print\bridge.js' -WindowStyle Hidden` |
| **L2** 🖨️ | `(iwr http://127.0.0.1:9100/test -UseBasicParsing).Content` | `TEST OK` **+ un ticket sort** | `ERR ...` = imprimante éteinte/USB ; rien ne sort = papier/alim |
| **L2-vérif papier** | *regarder le ticket de test* | nom **gras**, `Tel : 03 65 67 82 91`, compo indentée, **coupe partielle** (reste accroché), **pas de grand blanc** | si coupe pleine → `BORNE_CUT_MODE=full` ; si texte fade → OK (thermique) |
| **L3** | Page borne → F12 → Console | **AUCUN** « Local Network Access » / « loopback » | flag Chrome manquant → runbook `BORNE_LOCAL_BRIDGE_SETUP §2` |
| **L4** | `Stop-Process -Name node -Force` → attendre 2 min → `/health` | `UP` (watchdog l'a relancé) | watchdog absent → recréer la tâche `LeCayenne-Watchdog` |
| **L4-reboot** | redémarrer le PC, ne rien toucher | idle affiché + `/health` = UP | Startup/auto-login à revérifier |
| **L5** ✅ | **3 vraies commandes d'affilée** | 3 tickets corrects, **aucun ne tombe**, 0 crash | — |

---

## 2. CAISSE — 6 couches

| # | Preuve (PowerShell caisse) | PASS | Si ÉCHEC |
|---|---|---|---|
| **L0** | `node -v` ; `Get-Printer \| ft Name` | node OK + **noter le nom EXACT** de l'imprimante | — |
| **L1** | pont lancé avec ce nom : `node C:\caisse-print\caisse-bridge.js "NOM_EXACT"` puis `(iwr http://127.0.0.1:9100/health).Content` | `UP` | mauvais nom → relancer avec le bon (L0) |
| **L2** 🖨️ | passer la commande de test L5 | le SAGA sort le ticket, **AUCUNE fenêtre Terminal qui flashe**, **AUCUNE page grise Windows** | flash = ancien bridge (redéployer) ; page grise = pont down/mauvais nom → L1 |
| **L3** | avec le pont UP, une commande payée | 2 tickets thermiques, **jamais** le dialogue d'impression du navigateur | *le blindage affiche désormais une ERREUR au lieu de la page grise* → lire le message |
| **L4** | watchdog (kill node → relance 2 min) + reboot | revient seul, `/health` UP | recréer watchdog / auto-login |
| **L5** ✅ | **commande payée sur place** | **CLIENT** : `LE CAYENNE` + adresse + `Tel : 03 65 67 82 91` + produits **gras** + compo **indentée** + TOTAL + TVA + n° fiscal, **identique à l'écran** · **CUISINE** : symbolique, **sans prix** | — |

---

## 3. ÉCRAN CUISINE — 3 couches

| # | Preuve | PASS | Si ÉCHEC |
|---|---|---|---|
| **L0** | ouvrir `/kds`, login `chef@lecayenne.fr` | atterrit **direct sur le tableau KDS** | mauvais mot de passe → le forcer via tinker VPS (voir MESSAGE 3) |
| **L1** | passer une commande (borne/caisse) | apparaît **< 5 s** ; bouton **« prêt »/bump** change l'état **sans recharger** | pas de temps réel → worker `queue:work --queue=high,default` (MESSAGE 0 §4) ; sinon polling ~5 s (dégradé mais OK) |
| **L2** | watchdog Chrome + reboot | revient seul sur `/kds` connecté | recréer watchdog / auto-login |

---

## 4. LES 3 SEULES CHOSES QUI DÉPENDENT ENCORE DE DONNÉES/CONFIG (pas du code)

1. **Prix prod** — le menu à **3,00** au lieu de 2,50 = **donnée du VPS** (le code est prouvé à 2,50). → dump SSH `Item::get` → je donne les `UPDATE` exacts. Sans ça, le menu reste à 3,00.
2. **Adresse réelle** — `RECEIPT_ADDRESS` dans le `.env` VPS (ou `branch->address`). Sans ça, l'adresse reste **vide** (le reste du ticket est bon).
3. **Téléphone en base** — le placeholder `+33600000000` → le fallback config `03 65 67 82 91` s'affiche déjà ; pour l'avoir aussi côté écran, mettre le vrai n° dans `branch->phone` (avec les prix).

---

## 5. GARANTIE

Quand **L0→L5** de chaque machine sont vertes, c'est **réellement fonctionnel** — chaque couche a prouvé sa
sortie, il n'y a **aucune supposition**. Et si quelque chose casse un jour (imprimante débranchée, pont
arrêté), ce n'est plus une **page grise muette** : c'est un **message d'erreur clair** + le **watchdog**
relance tout seul + un **bouton réimprimer**. Le seul geste humain irremplaçable = **regarder le ticket de
test L2 sortir** (une fois par machine).
