# FIX DÉFINITIF — « le terminal flashe sur n'importe quelle page, sans raison » (borne + caisse)

> Cible : Cowork / owner, exécution **machine-side** (borne + caisse). Dev fournit les
> lanceurs prêts (`tools/bridge-service/`) ; l'installation finale sur les machines
> reste un **geste owner/cowork** (accès physique/AnyDesk requis).

---

## 1. Diagnostic (confirmé)

Le flash **n'est PAS** l'impression, ni le pont lui-même :

- `tools/borne/bridge.js` = **aucun** `child_process` (node-usb seul) → n'ouvre rien.
- `tools/caisse-bridge/caisse-bridge.js` = **1 seul** `spawn` PowerShell, déjà
  `windowsHide:true` (FLASH-FIX 2026-07-03) → n'ouvre pas de fenêtre à l'impression.

Le flash vient du **LANCEUR** du pont / des watchdogs :

- `schtasks /TR "node bridge.js" /SC MINUTE` → **relance node chaque minute** ⇒ une
  fenêtre console apparaît à chaque relance = **flash récurrent** (borne **et** caisse).
- `schtasks /TR "powershell -WindowStyle Hidden -File watchdog.ps1" /SC MINUTE` → flashe
  **quand même** : `-WindowStyle Hidden` s'applique **APRÈS** que `conhost.exe` a déjà
  affiché la fenêtre. **C'est le « flash bleu PowerShell » constaté sur la borne le 05/07**
  (cf. `COWORK_VERIF_BORNE_KDS_2026-07-05.md` §B : « fenêtre powershell.exe, fond bleu,
  apparaît puis disparaît »).

**Conclusion** : tant qu'un lanceur ouvre un process console dans la **session interactive**
(schtasks `node` nu, `.bat`, ou `powershell` même « Hidden »), il y aura un flash. Le fix
est de **ne plus jamais** faire ça.

---

## 2. Le fix (2 façons — choisir A **ou** B, par machine)

### Pourquoi ça tue le flash définitivement

| Lanceur | Flash ? | Pourquoi |
|---------|---------|----------|
| `schtasks /TR "node …" /SC MINUTE` | **OUI, par minute** | nouvelle console visible à chaque relance |
| `powershell -WindowStyle Hidden …` | **OUI, bref** | conhost crée/affiche la fenêtre AVANT que Hidden agisse |
| **VBS** `Run "node …", 0, False` | **NON** | SW_HIDE posé au `CreateProcess` → console jamais dessinée |
| **Service NSSM** (session 0) | **NON** | session des services = pas de bureau interactif + relance NATIVE (fini le schtasks/min) |

### Option A — Service Windows NSSM  ✅ *idéal*
Session 0 (invisible) + redémarrage natif → 0 flash **et** on **supprime** la tâche « /min ».

### Option B — Lanceur VBS fenêtre 0  ✅ *repli si pas d'admin/NSSM*
Un seul process node persistant, caché dès la création. Pas de relance/min.

---

## 3. Récupérer les lanceurs sur la machine

Les 4 lanceurs sont dans le repo (`tools/bridge-service/`) et publiés dans `public/dl`
au déploiement.

**Méthode fiable (recommandée) : copier-coller.** Les fichiers sont courts et **reproduits
intégralement au §7** ci-dessous → les créer avec le Bloc-notes sur la machine (pas de
dépendance réseau).

**Méthode téléchargement (si le static public/dl est servi)** : `Invoke-WebRequest` depuis
`https://<domaine-le-cayenne>/dl/<fichier>`.
⚠️ La route Laravel `/dl/{bridge}` ne sert que les **`.js`** ; les `.vbs`/`.ps1` dépendent du
service statique `public/dl`. Si le GET renvoie du HTML → utiliser le copier-coller (§7).

---

## 4. BORNE — étapes

Chemin réel constaté : `C:\borne-print\bridge.js` (port 9100).

1. **Tuer tous les anciens lanceurs du flash** (le vrai geste) :
   ```powershell
   # Lister les taches planifiees qui lancent node ou powershell :
   Get-ScheduledTask | Where-Object { ($_.Actions | ForEach-Object Execute) -match 'node|powershell|cmd' } |
     Select-Object TaskName, TaskPath
   # Supprimer celles qui relancent le pont / watchdog (adapter les noms) :
   schtasks /Delete /TN "LeCayenne-BornePont" /F
   schtasks /Delete /TN "LeCayenne-KioskWatchdog" /F   # si present et non-VBS
   ```
   Vérifier aussi `shell:startup` et `C:\borne-print\` : supprimer tout `.bat` ou lanceur
   qui fait `node bridge.js` / `powershell …` **non** wrappé en VBS caché.

2. **Installer le lanceur 0-flash** :

   **A. Service NSSM (admin)** — depuis `C:\borne-print\` :
   ```powershell
   powershell -ExecutionPolicy Bypass -File install-borne-service.ps1 -BridgePath "C:\borne-print\bridge.js"
   ```
   **B. VBS caché (repli)** — placer `start-borne-bridge-hidden.vbs` dans `C:\borne-print\`,
   double-clic, puis raccourci dans `shell:startup`.

3. **Vérifier** : `http://127.0.0.1:9100/health` → `UP`. Rien ne doit s'afficher à l'écran.

---

## 5. CAISSE — étapes

Adapter le chemin de `caisse-bridge.js` + le **nom exact de l'imprimante** (ex `SAGA`).

1. **Tuer les anciens lanceurs** (idem borne) :
   ```powershell
   schtasks /Delete /TN "LeCayenne-CaissePont" /F
   # + tout .bat / watchdog powershell non-VBS dans Startup / dossier du pont
   ```

2. **Installer le lanceur 0-flash** :

   **A. Service NSSM (admin)** :
   ```powershell
   powershell -ExecutionPolicy Bypass -File install-caisse-service.ps1 `
     -BridgePath "C:\caisse-bridge\caisse-bridge.js" -Printer "SAGA"
   ```
   > Si l'imprimante SAGA est installée « par utilisateur », le service (LocalSystem) peut
   > ne pas la voir → soit l'installer pour **tous les utilisateurs**, soit
   > `nssm set FoodKingCaisseBridge ObjectName ".\UTILISATEUR" "MDP"`, soit utiliser le **VBS**.

   **B. VBS caché (repli)** — `start-caisse-bridge-hidden.vbs` à côté de `caisse-bridge.js`,
   régler `printerName = "SAGA"`, double-clic, raccourci dans `shell:startup`.

3. **Vérifier** : `http://127.0.0.1:9100/health` → `UP`. Encaisser une commande → ticket
   sort tout seul, **aucune fenêtre**.

---

## 6. Preuve à renvoyer à l'owner

- Petite **vidéo** de l'accueil borne pendant 2-3 min → **plus aucun flash**.
- `Get-ScheduledTask | ? {($_.Actions|% Execute) -match 'node|powershell'}` → il ne reste
  que des tâches VBS/inoffensives (ou rien).
- `sc query FoodKingBorneBridge` / `FoodKingCaisseBridge` → `RUNNING` (si option A).
- `/health` = `UP` sur les deux machines.

---

## 7. Contenu intégral des lanceurs (copier-coller sur la machine)

> Créer avec le Bloc-notes. **Encodage ANSI** (pas d'accents dans ces fichiers, exprès).

### `start-borne-bridge-hidden.vbs`
```vbs
Option Explicit
Dim shell, fso, scriptDir, nodeExe, bridgeJs, cmd
Set shell = CreateObject("WScript.Shell")
Set fso   = CreateObject("Scripting.FileSystemObject")
scriptDir = fso.GetParentFolderName(WScript.ScriptFullName)
bridgeJs  = scriptDir & "\bridge.js"       ' sinon chemin absolu C:\borne-print\bridge.js
nodeExe   = "node"                          ' sinon "C:\Program Files\nodejs\node.exe"
cmd = """" & nodeExe & """ """ & bridgeJs & """"
shell.Run cmd, 0, False                     ' 0 = cache ; False = ne pas attendre
```

### `start-caisse-bridge-hidden.vbs`
```vbs
Option Explicit
Dim shell, fso, scriptDir, nodeExe, bridgeJs, printerName, cmd
Set shell = CreateObject("WScript.Shell")
Set fso   = CreateObject("Scripting.FileSystemObject")
scriptDir = fso.GetParentFolderName(WScript.ScriptFullName)
bridgeJs  = scriptDir & "\caisse-bridge.js"
nodeExe   = "node"
printerName = "SAGA"                         ' NOM EXACT de l'imprimante caisse
cmd = """" & nodeExe & """ """ & bridgeJs & """ """ & printerName & """"
shell.Run cmd, 0, False
```

> Les installeurs NSSM complets (`install-borne-service.ps1`, `install-caisse-service.ps1`)
> sont dans `tools/bridge-service/` — trop longs pour être recopiés ici ; les télécharger
> (`/dl/…`) ou les copier depuis le repo. En dernier recours, la ligne NSSM minimale :
> ```
> nssm install FoodKingBorneBridge  "C:\Program Files\nodejs\node.exe" "C:\borne-print\bridge.js"
> nssm set     FoodKingBorneBridge  AppExit Default Restart
> nssm set     FoodKingBorneBridge  Start SERVICE_AUTO_START
> nssm start   FoodKingBorneBridge
> ```

---

## 8. Ce qui reste au owner/cowork

Dev a livré les lanceurs prêts + ce runbook. **L'installation sur les vraies machines
(supprimer les anciennes tâches, poser le VBS/service, redémarrer le pont) reste un geste
owner/cowork** — accès physique/AnyDesk requis, non automatisable depuis le repo.
