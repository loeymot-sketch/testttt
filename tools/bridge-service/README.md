# Lanceurs des ponts d'impression — fin du « flash de terminal » (borne + caisse)

> **Le problème owner** : « le terminal flashe toujours sur n'importe quelle page,
> chaque fois, sans raison. » Une fenêtre console (souvent PowerShell, fond bleu)
> apparaît puis disparaît, en boucle, sur la borne **et** la caisse.

## Ce n'est PAS le pont qui flashe — c'est son LANCEUR

Les deux ponts sont déjà propres :

| Pont | Fichier | Spawn de fenêtre ? |
|------|---------|--------------------|
| Borne | `tools/borne/bridge.js` | **Aucun** `child_process` (node-usb seul) |
| Caisse | `tools/caisse-bridge/caisse-bridge.js` | 1 seul `spawn` PowerShell, déjà `windowsHide:true` (FLASH-FIX 2026-07-03) |

Le flash récurrent vient de la **façon de lancer** le pont :

- ❌ **`schtasks /TR "node bridge.js" /SC MINUTE`** — relance node toutes les X minutes ;
  chaque relance ouvre une fenêtre console = **flash par minute**.
- ❌ **`schtasks /TR "powershell -WindowStyle Hidden -File watchdog.ps1"`** — flashe
  **quand même**, une fraction de seconde : `-WindowStyle Hidden` s'applique **après**
  que `conhost.exe` a déjà créé (et affiché) la fenêtre. C'est exactement le « flash
  bleu PowerShell » constaté sur la borne le 05/07.

## Le fix : 0 flash, deux options (choisir l'une)

### Option A — Service Windows via NSSM  ✅ *idéal*
`install-borne-service.ps1` / `install-caisse-service.ps1`

- Un **service tourne en session 0** (session des services) : **aucun bureau interactif**,
  donc **rien n'est jamais dessiné** à l'écran → 0 flash **structurel**.
- NSSM garde le pont **vivant** avec un **redémarrage natif** (`AppExit Default Restart`)
  → on **supprime** la tâche planifiée « relance toutes les 1 min » (la source du flash).
- Nécessite les **droits admin** + [NSSM](https://nssm.cc/download) (`nssm.exe`).

```powershell
# En ADMINISTRATEUR :
powershell -ExecutionPolicy Bypass -File install-borne-service.ps1  -BridgePath "C:\borne-print\bridge.js"
powershell -ExecutionPolicy Bypass -File install-caisse-service.ps1 -BridgePath "C:\caisse-bridge\caisse-bridge.js" -Printer "SAGA"
```

### Option B — Lanceur VBS fenêtre 0  ✅ *repli si pas d'admin / pas de NSSM*
`start-borne-bridge-hidden.vbs` / `start-caisse-bridge-hidden.vbs`

- `WScript.Shell.Run "node …", 0, False` : le **0** = fenêtre **cachée dès la création**
  du process (SW_HIDE au `CreateProcess`) → la console **n'apparaît jamais**. C'est ce qui
  différencie le VBS window-0 de `powershell -WindowStyle Hidden` (qui, lui, flashe).
- Un **seul** process node persistant (pas de relance par minute → rien à faire flasher).
- Installer : placer le `.vbs` **à côté** de `bridge.js` / `caisse-bridge.js`, régler le nom
  d'imprimante (VBS caisse), puis déposer un **raccourci** dans `shell:startup`
  (Win+R → `shell:startup`).

## Règle d'or (à retenir)

> **JAMAIS `node` nu (ni `powershell …`) dans une tâche planifiée `/TR`.**
> Toujours : **service NSSM** (idéal) **ou** **VBS `Run …, 0, False`** (repli).
> Et **supprimer** toute ancienne tâche planifiée « relance toutes les 1 min » +
> tout watchdog `powershell -WindowStyle Hidden` non-wrappé (les convertir en VBS caché
> ou les rendre inutiles via le service NSSM qui gère déjà le maintien-en-vie).

## Choisir : service vs VBS

| Critère | Service NSSM (A) | VBS window-0 (B) |
|---------|------------------|------------------|
| Flash | 0 (session 0) | 0 (SW_HIDE) |
| Droits admin | **requis** | non requis |
| Redémarrage si crash | **natif NSSM** | non (relancer via Startup au reboot) |
| Voit les imprimantes « par utilisateur » | non par défaut (voir note ps1) | **oui** (session user) |
| Démarre avant login | **oui** (au boot) | au login user |

- **Caisse** : si l'imprimante SAGA est installée « par utilisateur », préférer le **VBS**
  (session user) *ou* faire tourner le service sous le compte caisse (`nssm set … ObjectName`).
- **Borne** : node-usb (WinUSB) fonctionne depuis un service ; le **service NSSM** est idéal.

## Publication / téléchargement machines

Le script de déploiement publie déjà `bridge.js` et `caisse-bridge.js` dans `public/dl`.
Ces lanceurs y sont ajoutés (`.vbs` + `.ps1`).

⚠️ La route Laravel `/dl/{bridge}` ne sert que des **`.js`** (whitelist, cf. `routes/web.php`
— **code produit, non modifié ici**). Les `.vbs`/`.ps1` ne passent donc **que** par le
service de fichiers statiques de `public/dl`. Si un `GET https://…/dl/xxx.vbs` renvoie du
HTML (404 SPA), **créer les lanceurs à la main** par copier-coller (ils sont courts et
intégralement reproduits dans le runbook `reports/handoff/FIX_FLASH_TERMINAL_2026-07-07.md`).

> **L'installation finale sur les machines reste un geste owner/cowork.** Ce dossier
> fournit les lanceurs prêts à l'emploi ; il ne s'exécute pas tout seul à distance.
