# Imprimante ticket CAISSE — SAGA SGPR-200II (setup V1 LOCAL Le Cayenne)

> 2026-06-04. Imprimante **ticket client de la caisse** (POS). La **borne**
> (kiosk) a une imprimante différente, configurée séparément via le pont
> Electron — **non concernée par ce document**.

---

## 1. Le matériel (étiquette lue)

| Champ | Valeur |
|---|---|
| Modèle | **SAGA SGPR-200II** (fabriqué par Xprinter pour SAGA) |
| Type | Imprimante ticket **thermique** |
| Largeur papier | **80 mm** (48 colonnes en police A) |
| Commandes | **ESC/POS** (standard universel) |
| Interfaces | **RS232 + USB + LAN** (les trois sur la carte) |
| Tiroir-caisse | Port **24V** intégré (kick RJ11/RJ12) |
| Vitesse | 260 mm/s |
| Alim | 24V / 2.5A |

---

## 2. Choix retenu : RÉSEAU (LAN) — **AUCUN driver à installer**

Le backend FoodKing envoie le ticket **directement à l'IP de l'imprimante sur
le port 9100** (RAW / ESC-POS — transport `escpos_tcp`). Le PC ne « voit » jamais
l'imprimante : **pas de driver, pas de file d'impression Windows, pas de
spouleur**. C'est le chemin le plus fiable pour l'impression automatique après
chaque commande, et il est déjà supporté par le code.

> Le PC caisse (Windows) et l'imprimante doivent simplement être sur le **même
> réseau local**.

---

## 3. Branchements physiques (à faire au restaurant)

1. **Alimentation** : brancher le bloc 24V/2.5A fourni → allumer l'imprimante.
2. **Papier** : charger un rouleau thermique **80 mm**.
3. **Réseau** : câble **Ethernet** de l'imprimante → ta box / switch
   (ou directement sur le port Ethernet du PC caisse, voir §5).
4. **Tiroir-caisse** : brancher le câble RJ11/RJ12 du tiroir sur le port
   **« Cash Drawer 24V »** de l'imprimante. L'ouverture automatique du tiroir
   passe par ce port (déjà câblée dans FoodKing — `openDrawer()` sur paiement
   espèces).

---

## 4. Récupérer l'adresse IP de l'imprimante

1. Imprimante **éteinte**, **maintiens le bouton FEED** (avance papier) et
   **rallume** → un **ticket d'auto-test** sort avec la config réseau et l'IP
   courante (DHCP).
2. Note l'`IP Address` imprimée (ex : `192.168.1.50`).
3. **Recommandé** : fige cette IP pour qu'elle ne change pas, au choix :
   - **Réservation DHCP** sur ta box (associer la MAC de l'imprimante à une IP
     fixe) — le plus simple ; ou
   - L'utilitaire réseau Xprinter (« Printer Test Tool » / « Net setting ») sur
     un PC Windows, ou la **page web** de l'imprimante si le modèle en a une
     (`http://<IP>`), pour définir une IP statique.

> Sans IP fixe, le DHCP peut réattribuer une autre IP au redémarrage et
> l'impression cesserait jusqu'à reconfiguration.

---

## 5. Cas « pas de box / switch » (PC + imprimante seuls)

Possible : relier l'imprimante **directement** au port Ethernet du PC caisse et
mettre les deux sur le même sous-réseau. Ex. : PC `192.168.50.1/24`, imprimante
`192.168.50.50/24`. L'impression RAW 9100 fonctionne sans routeur. (La
réservation DHCP n'existe pas dans ce cas → définir une IP statique sur
l'imprimante via l'utilitaire Xprinter.)

---

## 6. Activation logicielle — **une seule commande**

Une fois l'IP connue, sur le PC qui fait tourner FoodKing :

```bash
php artisan pos:configure-receipt-printer 192.168.1.50
```

Cette commande :
- crée/met à jour l'imprimante caisse (`branch_id=1`, `station=receipt`,
  `type=escpos_tcp`, `port=9100`, `width_chars=48`, `code_page=19` CP858 pour
  les accents FR + €) ;
- envoie immédiatement un **test print** → vérifie que le ticket sort.

Options utiles :
```bash
php artisan pos:configure-receipt-printer 192.168.1.50 --port=9100 --branch=1 --no-test
```

> On peut aussi gérer l'imprimante via l'écran Admin (CRUD imprimantes +
> bouton « test-print »). La commande ci-dessus fait la même chose en un coup.

---

## 7. Ce qui se passe ensuite (impression automatique)

- À **chaque commande payée au comptoir** (`OrderPaidAtCounter`, espèces **et**
  carte ; y compris une commande borne routée « paiement comptoir »), le backend
  rend le **ticket client NF525** et l'envoie à l'imprimante caisse —
  **automatiquement, sans action du caissier**.
- C'est **best-effort** : si l'imprimante est éteinte / injoignable, le paiement
  et la séquence fiscale **ne sont jamais bloqués ni annulés** ; l'échec est
  seulement journalisé (`storage/logs`).
- **NF525 duplicata** : la 1ʳᵉ impression est l'**original** (`receipt_print_count`
  = 1, sans marqueur). Toute **réimpression** ultérieure (bouton « Imprimer ticket »
  via `/print-receipt`) passe à 2+ et est correctement marquée **DUPLICATA**.
- **Kill-switch** sans redéploiement : `POS_AUTO_PRINT_RECEIPT=false` dans `.env`
  désactive l'auto-print (retour à l'impression écran/manuelle uniquement).

> ⚠ **Identité fiscale de la branche (NF525)** : sur la base actuelle, la
> branche « Le Cayenne (principal) » n'a **pas** de `siret` / `vat_intra` /
> `register_id` / `legal_footer` renseignés → ces lignes n'apparaissent pas sur
> le ticket. Pour un ticket **pleinement conforme NF525**, renseigner ces 4
> champs dans l'admin (réglages branche). Le rendu (écran + imprimante)
> les affichera automatiquement (même source SSOT `ReceiptDataService`).
>
> ⚠ **Ligne « Opérateur »** : affiche actuellement le client (« Client
> passage ») et non le caissier — défaut connu pré-existant
> (`ReceiptDataService` lit `order->user` = client). Indépendant de l'imprimante.

Aperçu d'un ticket rendu (80 mm / 48 colonnes) :

```
                  LE CAYENNE
          12 rue de la Paix, 75000 Paris
              SIRET 80012345600015
                TVA FR40800123456

------------------------------------------------
Commande                           ORD-2026-0042
N. appel                                     A07
Ticket fiscal                               #128
Caisse                                 CAISSE-01
Operateur                                  Sarah
Date                            04/06/2026 13:37
------------------------------------------------
2x Tacos Poulet                          17,00 €
  - Galette
  - Cheddar
  - Sauce Algerienne
  > Sans oignons
1x Coca-Cola 33cl                         2,90 €
1x Frites Maison                          3,50 €
------------------------------------------------
Sous-total                               23,40 €
TOTAL A PAYER                            23,40 €
------------------------------------------------
TVA
  10%  HT 21,27 €                     TVA 2,13 €
------------------------------------------------
   <mentions légales / merci / à bientôt>
```

---

## 8. Dépannage

| Symptôme | Vérifier |
|---|---|
| Test print échoue | Câble Ethernet branché ? Imprimante allumée ? |
| `tcp_open_failed` | L'IP est-elle joignable depuis le PC ? `ping 192.168.1.50` |
| Rien ne sort | Port 9100 ouvert (pare-feu) ? Bon papier 80 mm chargé ? |
| Accents en « ? » ou charabia | `code_page` (défaut 19 = CP858) — réessayer 0 (PC437) ou 16 (WPC1252) via `--code-page=` |
| L'IP a changé | Figer l'IP (réservation DHCP ou IP statique, voir §4) puis relancer la commande |
| Ticket en double | Le bouton manuel « Imprimer ticket » imprime une **réimpression** (marquée DUPLICATA) — c'est attendu |

---

## 9. Si un jour on veut l'USB (NON recommandé pour V1)

L'imprimante a aussi l'USB, mais :
- il faut **installer un driver Xprinter** sur le PC (la SAGA est une Xprinter
  rebrandée — driver dispo sur le site support Xprinter, version Windows) ;
- **et** ajouter un nouveau transport USB côté code (le code ne gère
  aujourd'hui que le réseau `escpos_tcp`).

Le LAN évite tout ça. À ne faire que si le réseau est impossible.

---

## 10. Détails techniques (pour les sessions futures)

- Config : `config/pos.php` → `auto_print_receipt` (env `POS_AUTO_PRINT_RECEIPT`,
  défaut true) + `receipt_printer_station` (défaut `receipt`).
- Rendu ESC/POS : `app/Services/Receipt/PosReceiptEscPosRenderer.php`
  (réutilise `EscPosCommandBuilder` + en-tête fiscal SSOT `ReceiptDataService`
  + breakdown TVA façon `OrderDetailsResource::buildTaxLines`).
- Déclencheur : `app/Listeners/PrintPosReceiptOnOrderPaidAtCounter.php` sur
  `OrderPaidAtCounter` (dispatch **après commit**), enregistré dans
  `EventServiceProvider`.
- Transport : `TcpPrinterTransport` (port 9100) ou `NullPrinterTransport`
  (env testing / `PRINTING_BYPASS_MODE`).
- Tiroir-caisse : `EscPosPrinterService::openDrawer()` (déjà câblé).
- Tests : `tests/Feature/Receipt/PosReceiptEscPosRendererTest.php` +
  `PosReceiptAutoPrintListenerTest.php`.
- **Frozen zones** : aucune touchée (ni PaymentComponent, ni pos-wizard.js, ni
  services fiscaux).
- ⚠ Le test d'impression **physique** sur la vraie SAGA n'a pas pu être fait en
  dev (imprimante au restaurant). Preuves dev = PHPUnit vert + aperçu décodé
  ci-dessus. Le vrai test print se fait via la commande artisan au restaurant.
```
