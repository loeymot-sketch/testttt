# 🍳 DEMANDE ULTRA-COMPLÈTE — COWORK : Écran cuisine (KDS) Le Cayenne
**Date** : 2026-07-03 · À coller dans une session Claude Cowork (contrôle du PC cuisine via TeamViewer).

---

## CONTEXTE (lis d'abord)

L'écran cuisine = la page **`/kds`** de l'app (`https://vps-418872ac.vps.ovh.net/kds`). Elle affiche les
commandes en temps réel + bouton « prêt ». **Le PC cuisine est déjà installé** (kiosque Chrome, auto-login,
watchdog, refresh nocturne, reboot testé) — ton travail matériel est OK, il n'est PAS en cause.

Historique du blocage « page blanche sous l'en-tête » :
1. Une 1ʳᵉ hypothèse (bug WebSocket `127.0.0.1:6001`) a été **RÉFUTÉE** : le KDS rend en **polling HTTP**, le
   WebSocket n'est pas requis pour l'affichage.
2. La permission serveur `kitchen-display-system` a été **vérifiée = déjà OK** (ce n'était pas ça non plus).
3. **Vraie cause = le VPS tournait l'ANCIEN code** (les correctifs n'étaient pas déployés). Le propriétaire
   vient de **déployer le code à jour** → le KDS réparé + une **bannière d'erreur claire** (si jamais un souci
   subsiste, elle affiche la cause au lieu d'un blanc muet).

---

## IDENTIFIANTS

| Champ | Valeur |
|---|---|
| URL | `https://vps-418872ac.vps.ovh.net/kds` |
| Login | `chef@lecayenne.fr` |
| Mot de passe | `123456` (rôle **Chef** → atterrit direct sur le KDS) |

---

## CE QUE TU DOIS FAIRE (dans l'ordre)

### 1. Vider le cache navigateur du poste (obligatoire après un déploiement)
Le kiosque garde l'ancien JS en cache → il FAUT le purger sinon tu re-testes l'ancien code.
- Ferme Chrome, puis (PowerShell) purge le cache + service-workers du profil kiosque :
```powershell
Get-Process chrome -EA SilentlyContinue | Stop-Process -Force
$d="$env:LOCALAPPDATA\Google\Chrome\User Data\Default"
'Service Worker','Cache','Code Cache','GPUCache' | % { Remove-Item -Recurse -Force "$d\$_" -EA SilentlyContinue }
```
- Relance Chrome kiosque sur `/kds`.

### 2. Se connecter + observer
- Login `chef@lecayenne.fr` / `123456` → tu dois atterrir sur le tableau KDS.
- **Attends 5-10 secondes** (le tableau se charge par polling toutes les ~5 s).

### 3. Constater le résultat — 3 cas possibles
- **✅ Le tableau apparaît** (colonnes + « Aucune commande en cours » s'il n'y a pas de commande) → **CUISINE OK**, passe au test #4.
- **🟠 Encore blanc MAIS une bannière rouge d'erreur s'affiche** → **lis le message de la bannière et rapporte-le mot pour mot** (c'est le nouveau code qui te dit la cause exacte : permission, session, ou serveur).
- **⚪ Encore totalement blanc SANS bannière** → le cache n'a pas été purgé (refais l'étape 1) OU le déploiement n'est pas passé → signale-le au propriétaire.

### 4. Test réel (quand le tableau s'affiche)
- Demande qu'une **commande soit passée** (borne ou caisse).
- Elle doit **apparaître sur `/kds` en < 5 secondes** (via polling).
- Clique le bouton **« prêt » / bump** sur un article → son état change **sans recharger la page**.

### 5. Durabilité (revérifier)
- **Redémarre le PC cuisine** → sans rien toucher, il doit revenir **seul** sur `/kds`, connecté (auto-login +
  kiosque + watchdog). Si un maillon manque, recrée la tâche `LeCayenne-Watchdog` + l'auto-login (`netplwiz`).

---

## CRITÈRES DE VALIDATION (tout doit être vert)
- [ ] `/kds` affiche le tableau (pas de page blanche, pas de bannière d'erreur).
- [ ] Une commande passée apparaît en < 5 s.
- [ ] Le bump « prêt » change l'état sans recharger.
- [ ] Après reboot, l'écran revient seul sur `/kds` connecté.

---

## SI ÇA RESTE BLOQUÉ
- **Ne devine pas la cause.** Rapporte : (a) y a-t-il une **bannière d'erreur** ? son texte exact ; (b) le
  cache a-t-il bien été purgé (étape 1) ; (c) une capture de l'écran.
- Le temps réel dépend d'un worker serveur (`queue:work --queue=high,default`). S'il manque, le KDS marche
  quand même en **polling ~5 s** (dégradé mais OK) — donc l'absence de worker n'explique PAS un blanc.
- **Ne touche pas au serveur toi-même** (tu n'as pas l'accès) — signale au propriétaire, il a l'accès SSH.

Quand les 4 critères sont verts : dis **« CUISINE OK »** + une capture du tableau.
