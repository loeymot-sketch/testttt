# MISSION COWORK — Impression automatique des tickets CUISINE (PC cuisine + Epson)

**Date** : 2026-08-09
**Machine cible** : **PC CUISINE** (celui de l'écran KDS — accès TeamViewer, poste `SP-635BZ`).
⛔ **PAS le PC caisse.** Ce sont deux machines différentes, avec deux ponts et deux ports différents.

**Objectif** : chaque nouvelle commande (caisse, borne, site, plateformes) sort **automatiquement**
en ticket papier sur l'imprimante Epson de la cuisine, sans clic et sans fenêtre.

---

## 0. Ce qui est DÉJÀ FAIT — ne pas refaire

Le logiciel est **entièrement écrit, testé et durci**. Il n'y a **aucun développement** à faire.

- L'écran KDS déclenche déjà l'impression **automatiquement à chaque nouvelle commande**,
  toutes sources confondues — caisse, borne, site, plateformes (`autoPrintNewKitchenTickets`).

  **Quand exactement ?** Au moment où la commande **entre sur le tableau cuisine**, c'est-à-dire
  quand son statut devient `ACCEPT`, `PREPARING` ou `PREPARED` (`KitchenReleaseRule::visibleStatuses`).
  Une commande encore `PENDING` — typiquement une commande en ligne non encore acceptée —
  **n'imprime pas** tant qu'elle n'est pas acceptée. C'est le comportement voulu : la cuisine ne
  doit pas produire un plat non confirmé. Le tableau porte aussi une fenêtre glissante de 8 h et
  un plafond de 50 commandes actives (au-delà, le KDS affiche un indicateur de débordement).
- L'interrupteur « 🖨️ Impression auto » est **ON par défaut** (en haut de l'écran KDS).
- Anti-doublon persistant : un ticket déjà sorti ne ressort pas au rafraîchissement,
  ni à la reconnexion, ni si deux onglets KDS sont ouverts.
- Renvoi automatique **toutes les 20 s** tant qu'un ticket n'est pas sorti (pont éteint,
  imprimante hors ligne → dès que ça revient, les tickets en attente sortent).
- Bouton de réimpression manuelle par commande (ticket perdu ou illisible).
- Le pont cuisine borne chaque impression à 15 s et se relance seul s'il se fige.
- **Pas de risque de double ticket** : côté caisse, le ticket cuisine est **manuel uniquement**
  (bouton « 🖨️ Cuisine »). Aucune impression cuisine automatique ne part de la caisse, et les
  impressions côté serveur sont inertes en production (l'application tourne sous Linux chez OVH).
  Vérifié le 09/08 : `printers` = 0 ligne, `WindowsRawPrinterTransport` refuse hors Windows.

**Ce qui manquait — et que j'ai corrigé aujourd'hui côté serveur :**
le pont cuisine n'était **publié nulle part** : le PC cuisine n'avait littéralement aucun
moyen de le télécharger, et il n'existait **aucun lanceur de démarrage automatique** pour lui
(la caisse et la borne en avaient un depuis le 07/07, pas la cuisine). Les trois fichiers
sont désormais en ligne et vérifiés (HTTP 200, MD5 identique à la source) :

| Fichier | URL |
|---|---|
| `kitchen-bridge.js` | `https://vps-418872ac.vps.ovh.net/dl/kitchen-bridge.js` |
| `start-kitchen-bridge-hidden.vbs` | `https://vps-418872ac.vps.ovh.net/dl/start-kitchen-bridge-hidden.vbs` |
| `install-kitchen-service.ps1` | `https://vps-418872ac.vps.ovh.net/dl/install-kitchen-service.ps1` |

**Le seul travail restant est sur le PC cuisine.** C'est cette mission.

---

## 1. Comment ça marche (à comprendre AVANT de toucher au poste)

```
Commande créée (caisse / borne / site / Uber)
        ↓
Écran KDS (Chrome, PC cuisine) détecte la nouvelle commande
        ↓  GET /api/.../orders/{id}/escpos?ticket=kitchen   (le SERVEUR rend les octets)
        ↓  POST http://127.0.0.1:9101/raw                   (octets bruts au pont local)
   Pont cuisine (node, PC cuisine)
        ↓  spouleur Windows en mode RAW, PAR NOM d'imprimante
   Imprimante Epson  →  papier
```

### ⚠️ Le point que tout le monde se trompe

> **L'« imprimante par défaut » de Windows n'a AUCUN effet sur ce ticket.**

Le pont envoie les octets à une imprimante **désignée par son nom exact**, pas à l'imprimante
par défaut. La mettre par défaut est inoffensif et même souhaitable pour le reste de Windows,
mais **ce n'est pas ça qui fera sortir le ticket**. Ce qui compte est le nom passé au pont.

C'est exactement ce qui vient de se produire côté caisse : le nom « SAGA » y était figé, la
SAGA a été remplacée par une Epson, et **5 tickets se sont empilés silencieusement** dans la
file de l'ancienne imprimante — sans la moindre erreur à l'écran, parce que le spouleur
Windows accepte les octets tant que la file existe encore.

**Règle d'or : le nom doit être recopié AU CARACTÈRE PRÈS depuis `Get-Printer`.**

---

## 2. Étapes sur le PC cuisine

### L0 — Relever le nom EXACT de l'imprimante (bloquant)

```powershell
Get-Printer | Select-Object Name, DriverName, PortName, Shared
```

Noter la ligne de l'Epson **telle quelle**. Le pilote Epson crée souvent la file sous
`EPSON TM-m30II Receipt` et **non** `Epson TM-m30II`. Ne pas corriger, ne pas deviner, ne pas
raccourcir : **copier-coller**.

Vérifier aussi qu'un test d'impression Windows sort bien du papier (clic droit sur
l'imprimante → Propriétés → Imprimer une page de test). Si ça ne sort pas, régler ça d'abord :
le pont ne peut rien faire de plus que ce que le spouleur sait faire.

### L0bis — Si AUCUNE imprimante n'apparaît sur le PC cuisine

`Get-Printer` ne montre pas d'Epson sur ce poste ? Alors la cuisine n'a pas encore
d'imprimante à elle, et il faut choisir **avant** d'aller plus loin. **Ne pas improviser** :
signaler au propriétaire et appliquer l'un des deux cas.

**Cas 1 — une imprimante propre à la cuisine (recommandé)**
Brancher une imprimante en USB sur le PC cuisine, l'installer avec son pilote, puis reprendre
en L0. C'est le montage le plus simple et le plus robuste : chaque poste a son imprimante,
aucune dépendance réseau, la cuisine continue d'imprimer même si la caisse est éteinte.

**Cas 2 — une seule imprimante partagée entre caisse et cuisine**
C'est réalisable **sans aucune modification du logiciel**, mais à une condition stricte :
**l'imprimante doit être en RÉSEAU** (Ethernet), avec une adresse IP valide sur le réseau du
restaurant. On l'installe alors **sur les deux PC** en « port TCP/IP standard », et chaque pont
l'adresse par son nom de file Windows local. Rien d'autre ne change.

⚠️ **Ce n'est PAS le cas aujourd'hui.** La fiche imprimée par l'imprimante (photo IMG_2070)
indique `DHCP : No Server -> Static` et l'adresse d'usine `192.168.192.168` : l'imprimante n'a
pas trouvé la box et n'est donc **pas réellement sur le réseau**. Il faudrait d'abord lui
attribuer une IP valide (idéalement une réservation DHCP sur la box, pour qu'elle ne change
jamais — un nom de file peut survivre, une IP qui bouge casse tout).

⛔ **Ce qui NE marche PAS** : faire pointer le KDS vers le pont de la caisse. Les ponts
écoutent volontairement sur `127.0.0.1` **uniquement** (`server.listen(PORT, '127.0.0.1')`),
donc une machine ne peut pas appeler le pont d'une autre. Le contourner exigerait d'ouvrir le
port d'impression sur le réseau — développement supplémentaire **et** exposition à éviter.

### L1 — Installer le pont

```powershell
New-Item -ItemType Directory -Force -Path C:\lecayenne | Out-Null
Invoke-WebRequest -Uri "https://vps-418872ac.vps.ovh.net/dl/kitchen-bridge.js" `
                  -OutFile "C:\lecayenne\kitchen-bridge.js"

# Node installé ? (le PC caisse en a déjà ; celui de la cuisine peut-être pas)
node --version    # si erreur -> installer Node.js LTS depuis https://nodejs.org
```

### L2 — Premier lancement MANUEL, à la main, pour voir ce qui se passe

Ne pas automatiser avant d'avoir vu un ticket sortir. Remplacer le nom par celui de L0 :

```powershell
node C:\lecayenne\kitchen-bridge.js "EPSON TM-m30II Receipt"
```

La console doit afficher :
```
[kitchen-bridge] écoute http://127.0.0.1:9101 → imprimante "EPSON TM-m30II Receipt"
```
**Vérifier que le nom affiché est le bon.** Laisser cette fenêtre ouverte pour l'instant.

Dans une **seconde** fenêtre PowerShell :
```powershell
(Invoke-WebRequest http://127.0.0.1:9101/health).Content    # doit répondre : UP
```

### L3 — Autoriser Chrome à parler au pont (sinon rien ne marchera jamais)

La page KDS est en **HTTPS** et doit appeler **127.0.0.1**. Chrome bloque ça par défaut.
Sans ce drapeau, le pont peut tourner parfaitement et **aucun** ticket ne sortira.

Fermer **tout** Chrome, puis le relancer ainsi (adapter le chemin si besoin) :

```powershell
Get-Process chrome -ErrorAction SilentlyContinue | Stop-Process -Force
Start-Process "C:\Program Files\Google\Chrome\Application\chrome.exe" -ArgumentList `
  '--kiosk','https://vps-418872ac.vps.ovh.net/kds',`
  '--disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks'
```

⚠️ Le raccourci / script de démarrage existant du KDS (`C:\lecayenne\start-kds.ps1`) a été
créé **sans** ce drapeau (le poste avait été installé en « écran seul, pas d'imprimante »).
**Il faut l'y ajouter**, sinon tout se remettra à ne plus marcher au prochain redémarrage.

### L4 — Test d'acceptation RÉEL

Se connecter au KDS, vérifier que l'interrupteur **« 🖨️ Impression auto »** en haut affiche
**ON**. Puis faire passer **une vraie commande** (ou une commande de test depuis la caisse).

✅ Attendu : le ticket cuisine sort **tout seul**, en quelques secondes, sans clic.

Si rien ne sort, ne pas tâtonner — appliquer le **§4 Diagnostic** ci-dessous, qui dit en trois
commandes lequel des trois maillons est en cause.

### L5 — Démarrage automatique (à faire seulement une fois L4 vert)

Le PC cuisine a déjà la machinerie de résilience installée le 08/07 : `start-kds.ps1`,
`watchdog-kds.ps1`, `run-hidden.vbs`, et trois tâches planifiées (`LeCayenne-KDS-Boot`,
`-Watchdog`, `-Nightly`). **Le plus propre est de réutiliser ce qui existe**, pas d'empiler
un nouveau système.

**Option A — intégrer à l'existant (recommandé)**

Ajouter dans `C:\lecayenne\start-kds.ps1` **et** dans `C:\lecayenne\watchdog-kds.ps1` un bloc
qui relance le pont s'il est mort (adapter le nom d'imprimante) :

```powershell
# --- pont impression CUISINE (port 9101) ---
$pont = Get-CimInstance Win32_Process -Filter "name='node.exe'" |
        Where-Object { $_.CommandLine -like '*kitchen-bridge.js*' }
if (-not $pont) {
    Start-Process -WindowStyle Hidden -FilePath "node" `
      -ArgumentList 'C:\lecayenne\kitchen-bridge.js','"EPSON TM-m30II Receipt"'
}
```

Et **ajouter le drapeau réseau local** à la ligne qui lance Chrome dans `start-kds.ps1` :
`--disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks`

**Option B — vrai service Windows (si vous préférez, nécessite NSSM + droits admin)**

```powershell
Invoke-WebRequest -Uri "https://vps-418872ac.vps.ovh.net/dl/install-kitchen-service.ps1" `
                  -OutFile "C:\lecayenne\install-kitchen-service.ps1"
powershell -ExecutionPolicy Bypass -File C:\lecayenne\install-kitchen-service.ps1 `
  -BridgePath "C:\lecayenne\kitchen-bridge.js" -Printer "EPSON TM-m30II Receipt"
```
Ce script **refuse de s'installer** si le nom d'imprimante n'existe pas et affiche la liste
des noms réels — c'est le garde-fou anti-« file morte ».

⚠️ Un service tourne sous `LocalSystem`. Si l'imprimante est installée « pour cet utilisateur
uniquement », le service **ne la verra pas**. Dans ce cas : soit réinstaller l'imprimante pour
*tous* les utilisateurs, soit rester en Option A (qui tourne dans la session utilisateur).

### L6 — Validation par redémarrage

Redémarrer le PC cuisine. Sans toucher à rien :
1. Chrome revient seul en kiosque sur le KDS,
2. `http://127.0.0.1:9101/health` répond `UP`,
3. une commande de test sort en papier,
4. **aucune fenêtre noire ne clignote** à l'écran.

---

## 3. À propos du ticket qui sort au démarrage (photo IMG_2070)

Les trois tickets identiques de la photo :

```
IP Address  : 192.168.192.168
Subnet Mask : 255.255.255.0
Gateway     : 0.0.0.0
DHCP        : No Server -> Static
```

**Ce n'est pas notre logiciel.** C'est la **fiche d'état réseau que l'Epson imprime elle-même
à chaque mise sous tension**, parce que son interface Ethernet est active.

Deux enseignements concrets :

- `192.168.192.168` est **l'adresse d'usine** des imprimantes Epson TM. `DHCP : No Server`
  signifie que l'imprimante a cherché la box du restaurant, **ne l'a pas trouvée**, et est
  retombée sur cette adresse d'usine. Elle n'est donc **pas réellement sur le réseau** du
  restaurant. (Cette même adresse est déjà connue du projet comme injoignable.)
- Trois tickets = trois mises sous tension (ou trois réinitialisations de l'interface).

**Conséquence pratique** : si l'imprimante est branchée en **USB** — ce qui est le plus simple
et ce que je recommande — cette fiche n'est que du bruit. Pour la faire taire : débrancher le
câble réseau, ou désactiver l'impression de la fiche d'état dans **Epson TM Utility** /
**EpsonNet Config**. C'est un réglage **de l'imprimante**, pas du logiciel.

Si au contraire vous voulez la brancher en **réseau**, il faut d'abord lui donner une IP
valide sur le réseau du restaurant, puis l'installer sur le PC cuisine en **port TCP/IP
standard**. La suite de la procédure est **rigoureusement identique** : le pont ne connaît que
le nom de la file Windows, pas le câble derrière.

---

## 4. Diagnostic — quel maillon est en cause, en 3 commandes

À faire **dans cet ordre**. Le premier qui échoue est le coupable ; inutile d'aller plus loin.

**1) Le pont tourne-t-il, et sur quelle imprimante ?**
```powershell
(Invoke-WebRequest http://127.0.0.1:9101/health).Content
Get-CimInstance Win32_Process -Filter "name='node.exe'" | Select-Object CommandLine
```
→ Doit répondre `UP` et afficher le **bon** nom. Sinon : pont mort, ou mauvais nom (L0/L2).

**2) L'imprimante accepte-t-elle des octets bruts ?** (test direct, sans le KDS)

Vrai ticket ESC/POS : initialisation + texte + avance papier + **coupe**. Sans la coupe ni
l'avance, le papier reste coincé sous la tête et on croit à tort que rien n'est sorti.

```powershell
$esc = [char]27; $gs = [char]29
$t  = "$esc@"                       # ESC @  : initialisation
$t += "*** TEST CUISINE FOODKING ***`n"
$t += (Get-Date -Format "dd/MM/yyyy HH:mm:ss") + "`n"
$t += "`n`n`n`n`n`n"                # avance : degage la barre de coupe
$t += "$($gs)V" + [char]0           # GS V 0 : coupe
$b = [System.Text.Encoding]::ASCII.GetBytes($t)
try {
  $r = Invoke-WebRequest -Uri http://127.0.0.1:9101/raw -Method POST -Body $b `
                         -ContentType "application/octet-stream" -UseBasicParsing
  "HTTP $($r.StatusCode) -> $($r.Content)"
} catch {
  $resp = $_.Exception.Response
  if ($resp) {
    $sr = New-Object System.IO.StreamReader($resp.GetResponseStream())
    "HTTP $([int]$resp.StatusCode) -> $($sr.ReadToEnd())"
  } else { "ECHEC RESEAU : $($_.Exception.Message)  (le pont ne tourne pas ?)" }
}
```

⚠️ **Le pont cuisine ne répond PAS comme celui de la caisse.** La caisse renvoie un `202`
immédiat sans attendre l'impression ; la cuisine renvoie **le résultat réel**, ce qui en fait
un excellent outil de diagnostic. Le `try/catch` ci-dessus est indispensable : PowerShell
transforme un HTTP 500 en erreur bloquante et **masquerait le message qui donne la cause**.

| Réponse | Signification | Action |
|---|---|---|
| **200** `{"ok":true}` | Imprimé pour de vrai. Du papier doit être sorti. | Maillon imprimante OK → si le KDS ne sort rien, c'est **Chrome** (étape 3). |
| **500** `winspool_send_failed` | `OpenPrinter` a échoué → **le nom d'imprimante est faux** | Reprendre L0, recopier le nom depuis `Get-Printer`. |
| **500** `print_timeout` | L'imprimante n'a pas répondu en 15 s | Hors ligne, plus de papier, capot ouvert, ou port USB coincé. |
| **500** `worker_unavailable` / `worker_died: …` | Le worker Windows n'a pas pu démarrer | Vérifier que PowerShell est accessible et que Node tourne bien sur Windows. |
| **500** `queue_overflow` | Plus de 50 tickets en attente | L'imprimante est bloquée depuis longtemps — traiter la cause. |
| **400** `empty` | Corps vide | Erreur de la commande de test, pas du pont. |
| **Échec réseau** | Le pont ne tourne pas / mauvais port | Reprendre L2 (et vérifier 9101, **pas** 9100). |

**Piège** : un `200` **sans papier** est possible si le nom pointe vers une file Windows
existante mais *morte* (l'ancienne imprimante). C'est exactement l'incident SAGA du 09/08 —
vérifier alors la file (ci-dessous).

**3) Chrome a-t-il le droit d'appeler le pont ?**
Sur l'écran KDS : `F12` → onglet **Console**. Une erreur mentionnant
`127.0.0.1`, `Private Network Access` ou `ERR_BLOCKED` = **le drapeau L3 est manquant**.

**File d'attente bloquée** (le symptôme de la caisse — à vérifier si rien ne sort mais que
tout semble vert) :
```powershell
Get-Printer | ForEach-Object { "$($_.Name) : $((Get-PrintJob -PrinterName $_.Name).Count) job(s)" }
```
Des travaux nommés **« FoodKing Ticket »** empilés sur une imprimante = les octets partent
au bon endroit mais l'imprimante ne les consomme pas (hors ligne, papier, ou mauvaise file).

---

## 5. Interdits

- ⛔ **Ne pas** mettre `node` ou `powershell` nu dans une tâche planifiée `/TR` : ça fait
  clignoter une console noire en pleine cuisine à chaque relance. Utiliser le VBS ou NSSM.
- ⛔ **Ne pas** utiliser le port **9100** : c'est le pont **caisse**, sur une autre machine.
  La cuisine, c'est **9101**.
- ⛔ **Ne pas** toucher au PC caisse dans cette mission (il a son propre incident en cours).
- ⛔ **Ne pas** annuler / valider de commandes réelles pour tester pendant le service.
  *(Précédent : le 04/07, un clic accidentel a ajouté un article à un panier client réel.)*
- ⛔ **Ne pas** saisir de mot de passe Windows. Si une étape en exige un, s'arrêter et le
  signaler au propriétaire.

---

## 6. Compte rendu attendu

Merci de renvoyer **exactement** ces éléments :

1. La sortie brute de `Get-Printer | Select-Object Name, DriverName, PortName` (toutes lignes).
2. Le nom d'imprimante **effectivement** configuré dans le pont (copie de la ligne
   `[kitchen-bridge] écoute ... → imprimante "..."`).
3. Réponse de `http://127.0.0.1:9101/health`.
4. Résultat du **test d'octets bruts** (§4.2) : papier sorti oui/non.
5. Résultat du **test réel** : une commande passée → ticket sorti tout seul oui/non.
6. Option retenue pour le démarrage auto (A ou B), et le contenu final des scripts modifiés.
7. Résultat du **redémarrage de validation** (§L6), dont : une console noire a-t-elle clignoté ?
8. Photo du ticket cuisine imprimé (pour vérifier la mise en page et la largeur).

---

## 7. Note technique pour le développeur (moi), à ne pas exécuter côté cuisine

Le ticket cuisine suit `RECEIPT_WIDTH_CHARS` (actuellement **42**, valeur calibrée sur
l'ancienne SAGA de la caisse). Sur une Epson TM-m30II en 80 mm, la largeur native est de
**48** colonnes. À 42, rien ne casse — il reste simplement une marge inutilisée à droite.

⚠️ Ce réglage est **partagé** entre le ticket caisse, le ticket cuisine et (en repli) la borne.
La borne SK1-31 est documentée comme repliant ses lignes à 48. Le passage à 48 ne pourra donc
se faire qu'accompagné de `RECEIPT_BORNE_WIDTH_CHARS=42`, **et** après confirmation par la
photo demandée au point 6.8 que l'imprimante cuisine est bien une 80 mm.
