# 🖥️ DEMANDE ULTRA-COMPLÈTE — COWORK : Installation CAISSE Le Cayenne
**Date** : 2026-07-04 · À coller dans une nouvelle session Claude Cowork (contrôle du PC caisse via AnyDesk/TeamViewer).

---

## CONTEXTE
La caisse = un PC Windows → Chrome plein écran sur **`https://vps-418872ac.vps.ovh.net/admin/pos-v4`**.
Elle imprime les tickets (client + cuisine) sur l'imprimante thermique du comptoir.

**Le code + les réglages serveur sont DÉJÀ faits** (déployé + `POS_PRINT_SILENT_ONLY=true`, `PRINT_DRIVER=windows_raw`,
largeur 32, tél `03 65 67 82 91`, pont publié en téléchargement). Le rendu du ticket est **validé octet par octet**
(nom produit gras, compo indentée, tél/adresse, total, coupe partielle, **zéro paragraphe**). Il ne reste qu'à
**installer le pont d'impression sur le PC caisse** — c'est LUI qui fait sortir le ticket propre au lieu du popup gris.

**Le seul problème possible = le pont pas lancé** → le navigateur retombe sur `window.print()` = **popup gris +
page web imprimée**. Une fois le pont installé + lancé, c'est **impossible** (le code bloque window.print en mode caisse).

---

## RÈGLES ABSOLUES (AnyDesk / Windows)
- **AZERTY** : NE JAMAIS TAPER → toujours COLLER (write_clipboard → AnyDesk « Insérer à partir du presse-papiers »,
  ou clic-DROIT dans PowerShell).
- Ouvrir **PowerShell ADMIN** : Ctrl+Alt+Suppr → Gestionnaire des tâches → Fichier → Exécuter une nouvelle tâche →
  coller `powershell` → cocher privilèges admin → OK.
- **Ne touche pas au serveur** (tu n'as pas l'accès) → signale au propriétaire.

---

## ÉTAPES (dans l'ordre, PowerShell admin)

### 1. Node installé ?
```powershell
node -v
```
Si « introuvable » → installe Node LTS (https://nodejs.org), puis rouvre PowerShell. (Le pont caisse = zéro dépendance npm.)

### 2. Télécharger le pont d'impression depuis le serveur (byte-exact)
```powershell
New-Item -ItemType Directory -Force C:\caisse-print | Out-Null
Invoke-WebRequest -UseBasicParsing "https://vps-418872ac.vps.ovh.net/dl/caisse-bridge.txt" -OutFile "C:\caisse-print\caisse-bridge.js"
```

### 3. Trouver le NOM EXACT de l'imprimante caisse (ne l'invente pas)
```powershell
Get-Printer | Select-Object Name
```
Note le nom (ex. « SAGA », « POS-80 »…).

### 4. Lancer le pont avec ce nom + vérifier la santé
```powershell
Start-Process node -ArgumentList 'C:\caisse-print\caisse-bridge.js','"NOM_EXACT_IMPRIMANTE"' -WindowStyle Hidden
Start-Sleep 2
(Invoke-WebRequest http://127.0.0.1:9100/health -UseBasicParsing).Content   # → doit afficher : UP
```

### 5. Chrome kiosque caisse (flag loopback OBLIGATOIRE) + connexion caissier
Raccourci / commande Chrome :
```
"C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk-printing --disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks "https://vps-418872ac.vps.ovh.net/admin/pos-v4"
```
Connecte-toi une fois avec le compte caissier (la session reste ouverte).

### 6. Purge de l'ancien cache (sinon vieux JS)
```powershell
Get-Process chrome,node -EA SilentlyContinue | Stop-Process -Force
$d="$env:LOCALAPPDATA\Google\Chrome\User Data\Default"
'Service Worker','Cache','Code Cache','GPUCache' | % { Remove-Item -Recurse -Force "$d\$_" -EA SilentlyContinue }
```
Relance le pont (étape 4) + Chrome (étape 5).

### 7. DURABILITÉ (le tout doit tourner seul, pour toujours)
- **Watchdog** (relance pont + Chrome s'ils meurent, toutes les 2 min). Crée `C:\lecayenne\watchdog.ps1` :
```powershell
if (-not (Get-Process node   -EA SilentlyContinue)) { Start-Process node -ArgumentList 'C:\caisse-print\caisse-bridge.js','"NOM_EXACT_IMPRIMANTE"' -WindowStyle Hidden }
if (-not (Get-Process chrome -EA SilentlyContinue)) { Start-Process 'C:\Program Files\Google\Chrome\Application\chrome.exe' -ArgumentList '--kiosk-printing','--disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks','https://vps-418872ac.vps.ovh.net/admin/pos-v4' }
```
```powershell
schtasks /Create /TN "LeCayenne-Watchdog" /TR "powershell -WindowStyle Hidden -ExecutionPolicy Bypass -File C:\lecayenne\watchdog.ps1" /SC MINUTE /MO 2 /RL HIGHEST /RU <COMPTE_CAISSE> /F
```
- **Refresh nocturne** (`nightly.ps1` : kill chrome/node + purge cache) planifié DAILY 04:00.
- **Auto-login** Windows : `netplwiz` → décocher « les utilisateurs doivent entrer un nom… ».

### 8. REBOOT DE VALIDATION
Redémarre le PC → sans rien toucher, la caisse doit revenir seule : pont UP + Chrome sur `/admin/pos-v4` connecté.

### 9. TEST FINAL (le plus important) — passe une VRAIE commande payée sur place
**DEUX tickets doivent sortir** :
- **CLIENT** : « LE CAYENNE » + « Tél : 03 65 67 82 91 » + articles/compo (nom en **gras**, détails **indentés**),
  TVA, TOTAL — **exactement comme à l'écran** (aucun retour à la ligne parasite).
- **CUISINE** : format symbolique, **sans prix**.

**VÉRIFICATIONS ANTI-BUG (owner) :**
- [ ] **AUCUN popup gris Windows** (page d'impression navigateur) — si tu le vois → le pont n'est pas lancé (étape 4).
- [ ] **AUCUNE fenêtre Terminal qui flashe** à l'impression.
- [ ] Le ticket imprimé == le ticket affiché à l'écran.

---

## CRITÈRES DE VALIDATION (tout vert)
- [ ] `/health` du pont = UP · [ ] 2 tickets propres à chaque commande · [ ] 0 popup gris · [ ] 0 flash Terminal
- [ ] ticket == écran · [ ] reboot → revient seule.

## SI PROBLÈME
- **Popup gris** = pont pas lancé OU mauvais nom d'imprimante → refais étapes 3-4.
- **Rien ne s'imprime** = imprimante éteinte/débranchée/mauvais nom → `Get-Printer`, vérifie le câble/alim.
- Ne devine pas : rapporte le message d'erreur exact + une photo du ticket.

Quand tout est vert : dis **« CAISSE OK »** + une photo des 2 tickets.
