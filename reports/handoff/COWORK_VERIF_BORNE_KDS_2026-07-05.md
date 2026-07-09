# Cowork — Vérifier borne + écran cuisine après refonte (2026-07-05)

> Contexte : le code de l'écran cuisine (3 lettres CAY/TER, fiche jaune, refonte) ET le pont
> ticket borne (bridge.js compact, sans marge blanche) sont CORRECTS dans le repo, mais les
> machines tournent sur d'ANCIENNES versions en cache / ancien pont. D'où « rien ne change ».
> Ce runbook force la mise à jour sur les 2 machines + tue le flash de terminal bleu.

Le serveur VPS a déjà été redéployé par l'owner (`deploy-kds-ticket.sh`) : nouveau bundle
`admin-kds.js` (hash changé) + `public/dl/bridge.js` republié. Il reste le travail MACHINE.

---

## A. ÉCRAN CUISINE (KDS) — vider le cache

Le bundle a un nouveau hash → un simple reload suffit normalement, mais le kiosk garde
souvent l'ancien. Faire, sur la machine cuisine, dans le navigateur du KDS :

1. **Ctrl + Maj + R** (hard reload). Si ça ne change pas :
2. **F12 → Application → Storage → « Clear site data »**, puis recharger.
3. Si c'est un navigateur en mode kiosque : **le fermer complètement et le rouvrir**.

**Résultat attendu (à confirmer par capture) :**
- Produits en **3 lettres** : « CAY », « TER », « BUR »… (plus « CAYENNE » en entier).
- **3 commandes MAX** affichées, chacune **plein écran** (plus de cartes écrasées en bas) ;
  s'il y a 4+ commandes, une pastille **« +N en attente »** apparaît.
- **Suppléments côte à côte** en **jaune gras** (⭐), pas empilés.
- Une commande avec supplément = **fiche jaune**.
- Bande « Historique » **fine** en haut à droite (plus de grosse bande qui gâche l'espace).

---

## B. BORNE — pont ticket (bridge.js) : re-télécharger + relancer CACHÉ

Le ticket blanc ~10 cm + design plat = **ancien bridge.js** sur le PC borne. Le nouveau
(compact, titre gras 2×, adresse + tél, coupe propre) est publié sur le serveur.

1. **Arrêter l'ancien pont** : fermer la fenêtre du terminal `node bridge.js` (ou tuer le
   process node), pour que le port 9100 se libère.
2. **Re-télécharger le pont** (remplace le fichier sur le PC borne) :
   - URL : `https://<domaine-le-cayenne>/dl/bridge.js`
   - l'enregistrer par-dessus `C:\borne\bridge.js` (ou le chemin actuel du pont).
3. **Relancer le pont SANS fenêtre** (⚠️ tue le « flash de terminal bleu » que voit l'owner) :
   Créer `C:\borne\start-bridge-hidden.vbs` :
   ```vbs
   CreateObject("Wscript.Shell").Run "node ""C:\borne\bridge.js""", 0, False
   ```
   (`0` = fenêtre cachée). Puis :
   - Lancer ce `.vbs` (double-clic) → le pont tourne en arrière-plan, **aucune fenêtre**.
   - Le mettre au **démarrage** : copier un raccourci du `.vbs` dans
     `shell:startup` (Win+R → `shell:startup`) → au boot, pont caché, plus de flash.
   - S'assurer qu'AUCUN ancien lanceur (`.bat`, tâche planifiée qui relance node en boucle)
     ne tourne encore — c'est lui qui fait « flash 1s puis disparaît, en boucle ».
4. **Recharger la borne FORT** (Ctrl+Maj+R ou fermer/rouvrir le kiosque) pour prendre le
   nouveau bundle (le payload envoyé au pont = feed compact).

**Résultat attendu (à confirmer par photo du ticket) :**
- **Plus de grande marge blanche** sous « Merci » (queue courte, coupe propre).
- Titre **LE CAYENNE en GROS gras**, adresse + **Tél : 03 65 67 82 91**, produit en gras.
- Plus de **flash de terminal bleu** sur la page d'accueil borne.

---

## C. TEST E2E RÉEL (à faire + renvoyer les preuves à l'owner)

1. **Borne** : passer une commande (ex. 1 Cayenne + 1 supplément) → payer → **photographier
   le ticket** (vérifier : pas de marge blanche, gras, tél).
2. **Écran cuisine** : vérifier que la commande apparaît en **CAY**, supplément **jaune côte
   à côte**, fiche jaune → **capture d'écran**.
3. Passer **4 commandes** d'affilée → vérifier « **+1 en attente** » + 3 cartes plein écran.
4. Vérifier qu'il n'y a **plus de flash de terminal bleu** sur l'accueil borne (petite vidéo).

Renvoyer les 3-4 preuves. Si un point ne change pas → me dire lequel + capture (souvent =
cache pas vidé sur la machine, ou ancien pont encore lancé).

---

## Note owner (page d'accueil borne / attract)
La **nouvelle** page d'accueil borne (carrousel produits refait) est **prête mais NON
déployée** (elle attend ta validation visuelle sur la vraie borne — décision gardée exprès).
Dis-moi si tu veux que je la commit + déploie ; sinon la borne garde l'accueil actuel.

---

## ✅ RÉSULTATS TEST RÉEL (Cowork, 05/07/2026 20:20-20:40)

### A. Écran cuisine (KDS) — SP-635BZ
- Déjà en nouveau format avant même le hard-reload (Ctrl+Maj+R fait quand même, aucun changement =
  déjà à jour) : codes 3 lettres (CAY, MEG, GRI, STO, HAN, MAY…), suppléments en gras/orange
  côte à côte avec ⭐, fiche à fond jaune quand supplément présent.
- **Confirmé en direct** : avec 3 commandes actives + 1 en attente → badge **« +1 en attente »**
  affiché en haut à droite + exactement 3 cartes plein écran (A0011, A0012, A0014). ✅ conforme.
- ⚠️ **Anomalie non liée à la refonte** : bannière jaune permanente « **Mode secours actif —
  actualisation automatique toutes les 5s** » visible en haut de l'écran KDS. Sync indiquée
  « LOCAL » au lieu de temps réel. À investiguer côté Dev (semble indiquer que le KDS tourne en
  fallback polling plutôt qu'en websocket/sync temps réel).
- Bande « Historique » : c'est un bouton compact en haut à droite (pas une grosse bande) → conforme.

### B. Pont ticket borne (bridge.js) — Borne 108683978
- Chemin réel du pont : **`C:\borne-print\bridge.js`** (pas `C:\borne\...` comme supposé dans le
  runbook). Process node PID trouvé via Gestionnaire des tâches + `Get-CimInstance Win32_Process`.
- Ancien bridge.js confirmé SANS "CAYENNE"/"Tel"/adresse (design plat, marge blanche) → diagnostic
  Dev confirmé exact.
- Séquence appliquée : `Stop-Process` (ancien PID) → backup `bridge.js.bak` → `Invoke-WebRequest`
  depuis `https://vps-418872ac.vps.ovh.net/dl/bridge.js` → nouveau fichier 11 195 octets confirmé
  contenant "LE CAYENNE", "Tel : 03 65 67 82 91", `bold()`, titre 2×. Relancé caché via le
  `borne-bridge.vbs` existant (déjà présent dans Startup, déjà au format `Run ... , 0, False` —
  la partie « lanceur caché » du runbook était déjà en place depuis une mission précédente).
  Port 9100 de nouveau LISTENING sur le nouveau PID. ✅
- Hard-reload borne fait après coup (Ctrl+Maj+R) → page d'accueil rechargée proprement, pas de
  fenêtre visible.
- **Aucune tâche planifiée ni .bat en boucle trouvés** — seulement 2 entrées Startup légitimes
  (`borne-bridge.vbs`, `borne-kiosk.vbs`).
- ⚠️ **Flash bleu observé pendant le test** : une fenêtre `powershell.exe` (icône PowerShell,
  fond bleu, sans titre visible) est apparue puis a disparu seule en quelques secondes pendant que
  je composais une commande sur la borne — donc **le flash existe toujours et n'est PAS causé par
  bridge.js/son VBS** (qui étaient déjà corrects). Cause probable : un des scripts présents dans
  `C:\borne-print\` (`watcher.js`, `probe.js`, `probe2.js`, `status.js` — non audités en détail,
  horodatage 27/06/2026) ou une tâche planifiée séparée qui invoque `powershell.exe` sans
  `-WindowStyle Hidden` ni wrapper VBS. **À investiguer spécifiquement** : lister les tâches
  planifiées Windows (`Get-ScheduledTask`) qui lancent `powershell.exe` directement, et les
  wrapper en VBS caché comme le pont, ou les convertir en tâche `.vbs`/`pythonw`/service.

### C. Test E2E réel
1. **Commande #A0013** (Cayenne, sauce Mayonnaise, Salade/Tomate/Oignon, **+ supplément Jambon**,
   sans menu, 8,30 €) passée sur la borne → écran "Rendez-vous en caisse" (routage Plan B
   `kiosk.payment_route_all_to_counter` confirmé actif). ⚠️ **Je n'ai pas pu photographier le
   ticket physique** — accès distant (AnyDesk) ne permet pas de prendre une photo papier. Le
   propriétaire doit vérifier/photographier le ticket imprimé pour cette commande (ou la
   prochaine) : pas de marge blanche sous "Merci", titre gras, tél affiché.
2. **KDS** : commande #A0013 retrouvée dans l'Historique du jour avec **"+ Jambon"** affiché en
   orange/gras à côté des garnitures (Salade, Tomate, Oignon, Jambon) — conforme au style
   supplément attendu. L'commande a été traitée/livrée très vite par le "Mode secours" (polling
   5s) avant que je puisse la voir en tant que carte active — donc pas de capture de la "fiche
   jaune" en direct pour cette commande précise, mais le format des données (couleur + gras sur
   le supplément) est confirmé correct dans l'historique.
3. **Commande #A0015** (même composition Cayenne + Jambon) passée juste après → capture réussie
   de l'écran KDS actif avec **3 cartes plein écran (A0011, A0012, A0014) + badge "+1 en
   attente"** visible en haut à droite. ✅ Comportement de file d'attente conforme au runbook.
4. **Flash terminal bleu** : toujours présent par intermittence (voir section B) — PAS résolu par
   la mise à jour du bridge seule. Nécessite investigation des autres scripts/tâches planifiées.

### Statut global
- Section A (KDS) : ✅ conforme (hors anomalie "Mode secours" à signaler)
- Section B (bridge.js) : ✅ mis à jour et vérifié par contenu fichier + port actif
- Section C (E2E) : ✅ partiellement prouvé (KDS confirmé via historique + badge file d'attente
  en direct) — ⏳ **photo ticket papier à faire par l'owner sur place** (impossible à distance)
- Point ouvert prioritaire : **flash bleu toujours présent**, cause probablement un script
  PowerShell non wrappé dans `C:\borne-print\` ou une tâche planifiée séparée à identifier.
