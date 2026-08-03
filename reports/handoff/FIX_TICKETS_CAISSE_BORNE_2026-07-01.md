# Correction des tickets CAISSE + BORNE (client + cuisine)

**Date** : 2026-07-01 · d'après les photos owner (IMG_1670/1671/1672)

## Ce que montraient les photos (défauts)

**Ticket CLIENT imprimé (IMG_1670)** :
- « **EUR** » au lieu de « € »
- prix **coupés sur 2 lignes** (« 7,\n40 EUR », « 9,\n90 EUR »)
- composition affichée **2 fois** (structurée + « ** CAYENNE / Galette - Salade… »)
- menu affiché **2 fois** (note sous le produit + ligne séparée)
- « **?** » à la place de la flèche ↳

**Aperçu écran CUISINE (IMG_1672)** :
- le menu affiché **avec un PRIX** « (+2,50 €) » (interdit en cuisine)
- menu **en double** (note + ligne « 1 x MENU (FRITES + BOISSON) »)

## Diagnostic (vérifié contre le code)

Le **code de rendu ACTUEL est déjà propre** — les défauts viennent de **deux causes
opérationnelles**, pas d'un bug de code non corrigé :

1. **Le VPS tourne une ANCIENNE version.** Les correctifs (symbole €, compo non dupliquée,
   cuisine sans prix, menu non dupliqué, flèche ↳ correcte) sont **committés et poussés** sur
   `origin/pos/category-first-caisse-2026-06-23` mais **pas encore déployés**.
2. **L'impression tombe sur `window.print()`** (HTML rendu par le navigateur → mal mis en
   page sur l'imprimante thermique = « EUR », coupures). Le code essaie D'ABORD l'ESC/POS via
   le pont local `127.0.0.1:9100/raw` (`ReceiptComponent.handlePrintClientClick`), et ne
   retombe sur `window.print()` **que si le pont est absent**. → Le pont n'est pas installé.

## Preuve : rendu ESC/POS ACTUEL (propre) d'une commande Cayenne + Menu

**TICKET CLIENT** (code actuel) :
```
Le Cayenne (principal)
437 Rue Élie Gruyelle, 62110 Hénin-Beaumont
Tél : 03 65 67 82 91   ·   SIRET 10417050100019
------------------------------------------------
A0004            1 juillet 2026 00:13
*** À EMPORTER ***
QT ARTICLES                              MONTANT
------------------------------------------------
1  Cayenne                                8,90 €
   Algérienne, Pain, Salade, Tomate, Oignon
   + Menu (Frites + Boisson)              1,50 €
------------------------------------------------
SOUS-TOTAL :                              8,90 €
MONTANT TOTAL:    8,90 €
TVA 10% :                                 0,81 €
** A REGLER EN CAISSE **
------------------------------------------------
BON APPÉTIT ET À BIENTÔT !     Ticket fiscal N…
```
→ « € » ✓, compo **une seule fois** ✓, menu **une fois** ✓, **aucun** « ? », prix **non coupés** ✓.

**TICKET CUISINE** (code actuel) :
```
CUISINE
A0004     *** À EMPORTER ***     00:13
================================================
1 x S | CAYENNE | STO | ALG
  MENU : ALG
```
→ symbolique ✓, **aucun prix** ✓, menu **une fois** ✓ (S=sandwich/G=galette selon le pain,
STO=Salade Tomate Oignon, ALG=Algérienne).

## Correction — 2 actions sur la machine (cowork / owner)

### 1. Déployer la version propre
```bash
cd /var/www/lecayenne
bash tools/deploy-vps.sh /var/www/lecayenne     # reset origin + rebuild + clear caches
php artisan view:clear
```
→ apporte : € au lieu de EUR, compo/menu non dupliqués, cuisine sans prix, ↳ correcte
(aperçus écran caisse **et** rendu ESC/POS).

### 2. Activer l'impression ESC/POS (pour ne PLUS tomber sur window.print)
Sur le PC caisse **et** la borne :
- Lancer le **pont d'impression local** qui écoute `127.0.0.1:9100/raw` (cf.
  `docs/PRINT_SAGA_USB_WINDOWS_SETUP.md` / la mission borne AnyDesk).
- Lancer Chrome avec le flag (sinon Chrome bloque loopback depuis une page HTTPS) :
  `--disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks`
- Config serveur : `PRINT_DRIVER=windows_raw`, imprimante déclarée
  (`php artisan pos:setup-receipt-printer "<NOM_SAGA>"`).

**Vérif** : passe une commande, clique « Ticket Client » / « Ticket Cuisine ». Le ticket doit
sortir en ESC/POS propre (€, pas de coupure). Si tu vois encore « EUR »/coupures → le pont
n'est pas joignable (Chrome retombe sur window.print).

## Résumé
Le rendu est **déjà corrigé dans le code (poussé)**. Il faut **(1) déployer** et **(2) brancher
le pont ESC/POS** pour que l'imprimante sorte le ticket propre au lieu du fallback navigateur.
Aucune nouvelle correction de code n'est nécessaire — les tickets sont propres dès le déploiement
(aperçus écran) et parfaits à l'impression une fois le pont actif.
