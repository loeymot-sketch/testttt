# MISSION 4 (VALIDATION 100 %) — COWORK : passer de vraies commandes À DISTANCE

> À coller à Claude cowork (AnyDesk, poste BORNE Le Cayenne).
> Le dev a identifié POURQUOI tu étais bloqué + te donne la méthode + un script clé-en-main.

---

## 0. LE DÉBLOCAGE (lis ça en premier — ça change tout)

Tu étais bloqué parce que tu utilisais des **gestes TACTILES** (`Input.synthesizeTapGesture`)
qui échouent à distance. **Les boutons de la borne répondent au CLIC, pas seulement au
toucher** — le dev l'a prouvé (commande composée placée à la souris). Donc :

- ❌ N'utilise PLUS `synthesizeTapGesture`.
- ✅ Utilise **`Runtime.evaluate` pour cliquer en JavaScript** : `document.querySelector('[data-testid="..."]').click()`. Ça déclenche le vrai handler Vue, sans coordonnées, sans toucher.
- ✅ (Ou `Input.dispatchMouseEvent` mousePressed+mouseReleased aux coordonnées.)

C'est LA solution. Avec ça tu peux passer une commande complète à distance.

---

## 1. LANCER LA BORNE EN MODE TEST (avec port debug pour CDP)

Ferme la borne (Alt+F4), puis dans PowerShell lance cette version AVEC le port debug
(uniquement pour le test — on remettra le lanceur normal après) :

```powershell
Get-Process chrome -ErrorAction SilentlyContinue | Stop-Process -Force
$PROFILE2 = "$env:LOCALAPPDATA\LeCayenneKiosk"
Remove-Item -Recurse -Force "$PROFILE2\Default\Cache","$PROFILE2\Default\Code Cache" -ErrorAction SilentlyContinue
& "C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk "https://vps-418872ac.vps.ovh.net/kiosk?machine_key=lcb-227b5373163391c875eeb43f7ee1affe3972" --user-data-dir="$PROFILE2" --remote-debugging-port=9222 --disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks --kiosk-printing --noerrdialogs --disable-infobars
```

Connecte-toi en CDP sur `http://127.0.0.1:9222` (la cible dont l'URL contient `/kiosk`).

---

## 2. PASSER UNE COMMANDE SIMPLE — SCRIPT CLÉ-EN-MAIN

Exécute ce script via **`Runtime.evaluate`** (avec `awaitPromise: true`). Il pilote tout
le parcours par `.click()` sur les `data-testid` et renvoie le n° de commande :

```js
(async () => {
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const click = sel => { const e = document.querySelector(sel); if (e) { e.click(); return true; } return false; };
  const log = [];
  // 1) Accueil → commencer
  if (location.pathname.includes('idle')) { click('[data-testid="kiosk-idle-touch-btn"]'); await sleep(1800); }
  log.push('apres-touch: ' + location.pathname);
  // 2) Ajouter le 1er produit simple disponible (boisson = pas de wizard)
  await sleep(1200);
  const add = document.querySelector('[data-testid^="kiosk-product-add-"]');
  if (add) { add.click(); log.push('produit ajoute'); } else log.push('AUCUN produit trouve');
  await sleep(1500);
  // 3) Valider la commande (depuis la barre panier OU la page panier)
  click('[data-testid="kiosk-cart-checkout"]'); await sleep(1800);
  click('[data-testid="kiosk-cart-checkout"]'); await sleep(1800);
  log.push('apres-checkout: ' + location.pathname);
  // 4) Upsell → continuer sans
  click('[data-testid="kiosk-upsell-add-continue"]'); await sleep(1800);
  log.push('apres-upsell: ' + location.pathname);
  // 5) Paiement → confirmer (Plan B caisse)
  click('[data-testid="kiosk-payment-counter-confirm"]') || click('[data-testid="kiosk-payment-confirm"]');
  await sleep(3500);
  // 6) Lire le n° de commande
  const num = (document.querySelector('[data-testid="kiosk-cash-order-number"]')?.textContent || '').trim();
  log.push('==> COMMANDE: ' + (num || location.search));
  return log.join('\n');
})();
```

**Attendu :** le dernier log = `==> COMMANDE: A00xx`. **= la commande est passée et
acceptée par le serveur (donc enregistrée).** Si le n° apparaît, le cœur marche.
**Au même instant, un ticket doit sortir du SK1-31** (le pont reçoit l'ESC/POS). 📸 le ticket.

---

## 3. PASSER UNE COMMANDE COMPOSÉE (pour vérifier la compo sur le ticket)

Même méthode (`.click()`), mais en passant par le configurateur. Tu es un agent : lis la
page à chaque étape et clique les bons éléments. Parcours :
1. `kiosk-idle-touch-btn` → catégories.
2. Clique une catégorie « Tacos » ou « Burgers » (lis la barre latérale), puis le produit
   (`[data-testid^="kiosk-product-card-"]`) pour ouvrir le configurateur.
3. Dans le configurateur : clique une **viande**, puis 2e viande différente (si Tacos L),
   une **sauce**, laisse les **crudités** par défaut, clique **SUIVANT** entre les étapes,
   choisis **Sans menu** (ou Menu), puis **AJOUTER AU PANIER**.
   - Astuce : pour cliquer un bouton par son texte : `[...document.querySelectorAll('button')].find(b=>/SUIVANT|AJOUTER|SANS MENU/i.test(b.textContent))?.click()`.
4. `kiosk-cart-checkout` → upsell `kiosk-upsell-add-continue` → `kiosk-payment-counter-confirm`.
5. Lis le n° de commande.
**Vérifie sur le ticket imprimé** : la sauce, les crudités et les 2 viandes (différentes)
sont bien écrites. 📸

---

## 4. VÉRIFIER : enregistrement + synchro + cuisine

Pour CHAQUE commande passée :
- **Enregistrée** : le n° de commande s'affiche (preuve que le serveur a accepté + sauvegardé).
- **Ticket** : un ticket sort du SK1-31. (Si rien ne sort : `curl http://127.0.0.1:9100/health` = UP ? le pont reçoit-il le POST ? regarde sa console.) 📸 le papier.
- **Écran cuisine (KDS)** : ouvre dans un autre navigateur/fenêtre (PAS la borne kiosk)
  `https://vps-418872ac.vps.ovh.net/kds` connecté avec un compte cuisine, OU demande à
  l'owner de regarder l'écran cuisine. La commande doit apparaître en ≤ 5 s, **un seul item
  fusionné** (pas de doublure), mêmes **symboles** que le ticket cuisine. 📸
- **Caisse** : ouvre `https://vps-418872ac.vps.ovh.net/admin/pos` (compte caisse). La
  commande apparaît ? **Encaisser** → reçu imprimé (SAGA) + **tiroir s'ouvre** + n° fiscal
  qui s'incrémente. Teste le **choix d'impression** : ticket CLIENT seul / CUISINE seul /
  LES DEUX. 📸

> Passe **3 commandes** d'affilée → vérifie que **les 3** sont enregistrées (caisse) ET
> apparaissent **toutes** au KDS, **en ordre**, numéros séquentiels sans trou.

---

## 5. ROBUSTESSE (autonomie de la borne)
- Ferme Chrome (Alt+F4) → le **watchdog** doit le relancer seul en ≤ 2 min. 📸
- Redémarre Windows → la borne doit **s'ouvrir seule en plein écran**. 📸

---

## 6. REMETTRE LE MODE PRODUCTION (sécurité — important)
Le port debug `9222` ne doit PAS rester en production. Après les tests :
```powershell
Get-Process chrome -ErrorAction SilentlyContinue | Stop-Process -Force
Start-Process "C:\LeCayenne\start-kiosk.bat"
```
→ relance la borne avec le lanceur normal (sans port debug).

---

## 7. RAPPORT FINAL ATTENDU (avec photos)
| Point | Résultat |
|---|---|
| Commande simple passée (n° obtenu) | A00__ / ÉCHEC |
| Ticket client sort + détaillé + n° gros | OUI/NON 📸 |
| Commande composée : sauce+crudités+2 viandes sur le ticket | OUI/NON 📸 |
| Ticket cuisine symbolique 3 lignes | OUI/NON 📸 |
| KDS : apparaît ≤5s, 1 item fusionné, mêmes symboles | OUI/NON 📸 |
| 3 commandes : toutes enregistrées + toutes au KDS en ordre | OUI/NON 📸 |
| Caisse : encaisser → reçu + tiroir + n° fiscal++ | OUI/NON 📸 |
| Choix impression client / cuisine / les deux | OUI/NON 📸 |
| Watchdog relance Chrome ; boot ouvre la borne seule | OUI/NON 📸 |
| Mode production remis (sans port debug) | OUI/NON |

**Tout problème = photo + n° de commande + l'erreur console exacte.** Ne modifie aucun
fichier serveur ni `.env`. Les erreurs WebSocket en console = **normales, à ignorer**
(synchro par polling). Livrable = ce tableau rempli + toutes les photos.
