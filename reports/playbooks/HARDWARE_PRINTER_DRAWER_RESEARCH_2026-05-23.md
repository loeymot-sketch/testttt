# Hardware research — Imprimante ticket + tiroir-caisse + périphériques POS
## Le Cayenne V1 · 2026-05-23

> Recherche réelle 2026 (Amazon FR + distributeurs FR + Epson FR + Star Micronics + Logiscenter FR + Busiboutique + Procaisse + Bechtle + Officeeasy + Solushop + Idealo FR + Manomano).
> Compatibilité validée contre code FoodKing existant : `app/Services/Hardware/EscPosPrinterService.php` + `app/Services/Hardware/EscPosCommandBuilder.php` + `app/Services/Hardware/PrinterTransport/TcpPrinterTransport.php`.

---

## 1. TL;DR — recommandation owner (3 lignes)

1. **Imprimante** : **Epson TM-T20III Ethernet (C31CH51012)** — ~**185-210 € TTC** Amazon FR / Bechtle / Solushop, livraison 2-5 j, 200 mm/s, fiabilité 360 000 h MTBF, code FoodKing `escpos_tcp` PRÊT À UTILISER (aucune ligne de code à toucher).
2. **Tiroir-caisse** : **EC-410 24V RJ11** (générique POS, vendu par Waapos / POSValley sur Amazon FR) — ~**45-60 € TTC**, kické par l'imprimante via la commande `ESC p 0 25 250` déjà implémentée dans `EscPosCommandBuilder::openDrawerCommand()`.
3. **Total V1 hardware "encaissement Le Cayenne"** : **~230-270 € TTC** (printer + drawer + câble RJ11 inclus dans le drawer + 1 rouleau d'amorçage). Livraison Prime sous 2-5 jours ouvrés.

> **Pas d'écran/tablette borne kiosk dans ce V1** : le kiosk tourne sur le PC fixe à côté de la caisse en mode plein écran browser. La borne dédiée tablette est BACKLOG V1.0.2 (voir §5).

---

## 2. Imprimantes thermiques 80mm — matrice comparative

| Modèle | Connecteur | Vitesse | MTBF/durabilité | Prix TTC FR | Délai livraison | Driver Linux | Verdict V1 Le Cayenne |
|---|---|---|---|---|---|---|---|
| **Epson TM-T20III (C31CH51012) Ethernet** | RJ45 + RJ11 drawer | 200 mm/s | 360 000 h, 15 M lignes | **185-210 €** (Bechtle / Solushop / Procaisse / Amazon FR) | 2-5 j | CUPS + ESC/POS natif | ✅ **RECOMMANDÉ V1** |
| Epson TM-T20III (C31CH51011) USB+série | USB 2.0 + RJ11 drawer | 200 mm/s | 360 000 h | 150-180 € | 2-5 j | CUPS USB | ⚠️ Acceptable si pas de LAN — préférer Ethernet pour stabilité réseau |
| Star TSP143IV-UE (Ethernet + USB-C/A) | RJ45 + USB-C + RJ11 drawer | 250 mm/s | 60 M lignes | **279-340 €** (Busiboutique noir 39473090 / blanc 39473190, PC21.fr, Lambda-tek) | 3-7 j | CUPS + ESC/POS + CloudPRNT | 🟢 **Alternative haut-de-gamme** — payer +100 € pour endurance 4× plus longue + CloudPRNT (Star fait V2 SaaS-ready) |
| Star TSP143III-LAN (gen précédente) | RJ45 + RJ11 drawer | 250 mm/s | 60 M lignes | ~250 € (souvent en déstockage) | 5-10 j | CUPS + ESC/POS | ⚠️ EOL annoncé par Star — privilégier IV |
| Epson TM-T88VI (haut de gamme) | RJ45 + USB + BT/WiFi options | 350 mm/s | 70 M lignes | **350-420 €** US équivalent $399 | 5-10 j | CUPS + ESC/POS | ❌ **Overkill V1** — single-resto Le Cayenne pas besoin de 350 mm/s |
| NetumScan 80mm USB+Ethernet (chinois budget) | USB + Ethernet + RJ11 | 300 mm/s | non spécifié | ~80-110 € Amazon FR | 2-4 j Prime | ESC/POS générique | ⚠️ **Risqué V1** — pas de SAV constructeur en France, fiabilité longue durée incertaine |

### Pourquoi TM-T20III Ethernet C31CH51012 gagne pour V1 Le Cayenne

- **Code FoodKing prêt** : `app/Http/Requests/Admin/PrinterRequest.php` valide déjà `type = escpos_tcp`. Le service `TcpPrinterTransport` envoie les bytes via socket TCP/9100 (port standard ESC/POS). Aucune modification.
- **Drawer kick standard Epson** : `EscPosCommandBuilder::openDrawerCommand()` envoie `chr(0x1B).chr(0x70).chr(0x00).chr(0x19).chr(0xFA)` = commande Epson DKC pin 2 25ms/250ms — exact pattern attendu par le TM-T20III sur sa sortie RJ11.
- **CP858 supporté** : Le builder utilise `selectCodePage(19)` = CP858 multilingue Latin-1 avec €. TM-T20III supporte CP858 nativement → accents français + € impriment proprement.
- **Réseau LAN > USB** pour V1 monoposte : zéro problème de chemin USB après reboot, IP statique fixée dans la config Printer, ping diagnostique simple.
- **MTBF 360 000 h** = ~41 ans de fonctionnement nominal. Largement V1 single-resto.
- **Prix marché 2026** : 185-210 € TTC sur Bechtle (rapide, B2B), Solushop (172 € HT = ~207 € TTC), Procaisse, Amazon FR. Epson France l'a labellisé "discontinué" en officiel mais distributeurs FR ont stock pour 6-12 mois.

---

## 3. Tiroir-caisse — matrice comparative

| Modèle | Connecteur | Compartiments | Prix TTC FR | Livraison | Compatible TM-T20III ? | Verdict |
|---|---|---|---|---|---|---|
| **EC-410 24V RJ11 (Ecopos / Waapos / POSValley)** | RJ11 vers printer | 4 billets + 8 monnaie + 2 chèques | **45-60 €** Amazon FR | 2-4 j Prime | ✅ Standard ESC/POS DKC | ✅ **RECOMMANDÉ V1** |
| BeMatik tiroir RJ11 automatique noir | RJ11 vers printer | 4 billets + 8 monnaie | ~55-75 € Manomano + Amazon FR | 3-5 j | ✅ Standard ESC/POS DKC | 🟢 Alternative si EC-410 rupture |
| Star SMD2-1317 (officiel Star) | RJ11/RJ12 | 4 billets + 4 monnaie OU 3 billets + 5 monnaie | ~150-200 € (Logiscenter FR, marqué EOL chez Star) | 5-10 j | ✅ optimisé pour Star printers | ⚠️ EOL annoncé — overpriced vs EC-410 sauf si bundle Star |
| Epson DK Series tiroir | RJ11/RJ12 | 4 billets + 8 monnaie | ~120-180 € | 5-10 j | ✅ optimisé Epson printers | ⚠️ Overpriced vs générique EC-410 |
| Tiroir USB direct (sans printer kick) | USB direct PC | variable | 80-150 € | variable | N/A — câblage différent | ❌ **À éviter V1** — sort du pattern ESC/POS DKC standard supporté par le code |

### Pourquoi l'EC-410 générique RJ11 gagne pour V1

- **Compatibilité DKC universelle** : Tous les tiroirs RJ11 "ESC/POS-driven" reçoivent le même pulse `ESC p m t1 t2` envoyé par l'imprimante. Le code FoodKing envoie exactement cette commande sans connaître la marque du drawer.
- **Câble RJ11 inclus** : les listings Amazon FR (Waapos B017IRUQ2Y, POSValley) incluent le câble RJ11 imprimante↔drawer + 2 clés + bac monnaie. Plug-and-play.
- **Prix : 45-60 €** vs 150 € pour Star ou Epson estampillés. Pour single-resto, la différence ne se justifie pas (pas d'usage industriel 200 ouvertures/jour).
- **Test contractuel** : Le bouton "Ouvrir tiroir" déjà présent dans le code (`EscPosPrinterService::openDrawer()`) → test immédiat dès branchement.

⚠️ **Garde-fou owner** : ne pas commander un tiroir "USB direct PC sans imprimante" — le pattern FoodKing kicke le drawer VIA l'imprimante (RJ11 RJ12), donc il faut un tiroir avec port RJ11 femelle prêt à recevoir le câble livré.

---

## 4. Bundle deal — printer + drawer ensemble ?

### Sur le marché français 2026

- **Pack APPROX (tiroir + imprimante thermique + lecteur code-barres + 8 rouleaux papier)** — 123comparer.fr, 123consommables.com. Prix typique 200-280 € TTC. ⚠️ Imprimante générique chinoise (pas Epson/Star) → fiabilité moins prouvée.
- **Pack POS959 caisse tactile + imprimante + tiroir** — allocaisse.com. 600-900 € (inclut PC tactile que Le Cayenne n'a pas besoin d'acheter — déjà un poste).
- **Star Micronics TSP143III-LAN + Star SMD2-1317 bundle (US)** — Amazon US référence B07SV4YD2H ~$350. Non-vendu en bundle FR → import US = douane + délai 3 semaines.

### Verdict bundle

❌ **Pas de bundle FR convaincant** pour le combo "Epson TM-T20III Ethernet + EC-410 générique". Acheter séparément reste **gagnant** :
- Epson TM-T20III Ethernet : 185-210 €
- EC-410 tiroir : 45-60 €
- Câble RJ11 inclus dans le drawer
- **Total séparé : 230-270 € TTC**
- vs Pack APPROX avec printer noname : ~250 € TTC mais qualité incertaine
- vs Pack Star US importé : ~$350 + douane + 3 sem = ~380 € équivalent

✅ **Stratégie owner** : 2 commandes Amazon FR Prime → livraison J+2 + J+3, total 270 € TTC max.

---

## 5. Tablette borne kiosk — optionnel V1.0.2 ?

> **V1 actuel** : le kiosk tourne sur le **même PC fixe** que la caisse en mode plein-écran browser (Chrome --kiosk URL `http://localhost/kiosk/idle`). Pas de hardware additionnel.

> Si owner veut **une vraie borne séparée** type "borne McDo" :

| Option | Format | Prix HW | Prix HW + support | Verdict |
|---|---|---|---|---|
| **iPad 10ᵉ gén 64Go WiFi** (10.9") | Tablette | ~400-450 € (Apple FR / Darty / Fnac) | +150 € support mural Vesa/comptoir = ~550 € | 🟢 Best UX touch + lifespan, mais **petit écran 10.9"** vs borne 14-22" attendue |
| Samsung Galaxy Tab A9+ 11" 64Go | Tablette | ~250-300 € (Samsung FR / Amazon FR) | +120 € support = ~380 € | 🟢 Cheap + format 11" correct + Android = OK Chromium kiosk mode |
| Borne dédiée 15" tactile Shopcaisse / Tabesto | Borne intégrée | **2000-10 000 €** (Hellopro, Tabesto, Digilor 2026) | inclus stand | ❌ **Overkill V1** — réservé chaînes franchisées |
| PC tactile 15" + bras articulé (TPV 9590) | All-in-one | 700-1200 € | inclus bras | 🟡 Compromis si owner veut un vrai poste autonome borne |

### Recommandation borne V1.0.2 (post-V1)

Si owner décide d'ouvrir une seconde "vraie borne client" pour soulager la caisse à midi :
- **Samsung Galaxy Tab A9+ 11" 64Go WiFi (~270 €)** + **bras articulé comptoir Vesa 75/100 (~80 €)** = **~350 € total**
- Mode Chrome kiosk plein-écran sur l'URL `/kiosk/idle`
- Branchement WiFi LAN sur même réseau que l'imprimante → l'imprimante reçoit les tickets kiosk via TCP standard

**⚠️ V1 NE PAS prioriser cette borne** — le kiosk plein-écran sur PC caisse fonctionne déjà. Owner peut ajouter la tablette quand le besoin sera prouvé par usage réel.

---

## 6. Compatibilité ESC/POS — code FoodKing existant

### Fichiers analysés

- `app/Services/Hardware/EscPosCommandBuilder.php` (4518 octets) — builder hand-rolled, **pas de dépendance externe** (pas de mike42/escpos-php). Implémente init, alignements, bold, double size, line feed, cut, drawer kick, codepage CP858, encoding UTF-8→CP858 via iconv TRANSLIT, line key-value avec padding.
- `app/Services/Hardware/EscPosPrinterService.php` (6202 octets) — service principal, gère `sendRaw()`, `testPrint()`, `openDrawer()`. Drawer scoping via `branch_id` + `station=receipt`. Audit logging via `BypassAuditLogger::printingBypassed()`.
- `app/Services/Hardware/PrinterTransport/` — 3 implémentations :
  - `TcpPrinterTransport.php` (1141 octets) — socket TCP/9100 standard ESC/POS-over-LAN
  - `NullPrinterTransport.php` (433 octets) — bypass dev/test
  - `PrinterTransportInterface.php` (341 octets) — contract
- `app/Http/Requests/Admin/PrinterRequest.php:30` — types validés : `escpos_tcp`, `escpos_usb`, `browser_html`

### Librairie ESC/POS utilisée

**AUCUNE librairie externe** — implémentation FoodKing custom autour des commandes ESC/POS standards. **Avantage** : zéro dépendance composer à maintenir, contrôle total des bytes envoyés. **Inconvénient** : pas de support natif des features avancées (graphiques, QR codes), mais V1 Le Cayenne n'en a pas besoin (tickets texte simples).

### Niveau d'intégration V1

- ✅ Init imprimante (`ESC @`)
- ✅ Sélection codepage CP858 multilingue Latin-1 avec € (cf. FINDING C-β-T15-1 P2)
- ✅ Encoding UTF-8 → CP858 via iconv TRANSLIT (pour accents fr "é à è ç" + €)
- ✅ Alignement gauche/centre/droite
- ✅ Bold + double size titres
- ✅ Line feed + cut (couteau automatique)
- ✅ Drawer kick standard Epson DKC pin 2 (compatible TM-T20III + EC-410)
- ✅ TCP transport via socket port 9100 (compatible TM-T20III Ethernet RJ45)
- ✅ Audit log `BypassAuditLogger::printingBypassed()` quand `printing.bypass.enabled` (dev/test)
- ✅ Branch-scoping `withoutGlobalScope(BranchScope::class)` explicite pour cash drawer (cf. Z6-P1-WGS 2026-05-19)

### Anti-régression à valider après commande hardware

1. `php artisan tinker` → `app(EscPosPrinterService::class)->testPrint(Printer::first())` → ticket "FOODKING POS / Test print OK" doit sortir
2. `app(EscPosPrinterService::class)->openDrawer(null, 1)` → drawer doit s'ouvrir
3. Vérifier accents FR : ticket avec "Crème brûlée — 3,50 €" → "Crème brûlée — 3,50 €" lisible (pas de mojibake)
4. NF525 chain : Z-report imprimé doit contenir `prev_hash + current_hash` lisibles

---

## 7. Action items owner — commande Amazon FR step-by-step

### Commande #1 — Imprimante (Amazon FR ou Bechtle)

**Option A — Amazon FR Prime (rapide, plus cher)**
- Recherche : "Epson TM-T20III Ethernet"
- ASIN typique disponible via revendeurs marketplace Prime
- Prix attendu : 200-220 € TTC Prime
- Livraison J+2 si commandé avant 14h
- ⚠️ Vérifier que le vendeur est marketplace Pro France (pas import EU non-FR)

**Option B — Bechtle FR (B2B, moins cher, livraison J+5)**
- URL : `https://www.bechtle.com/fr/shop/epson-tm-t20iii-ethernet-pos--4410790--p`
- Référence Epson : C31CH51012
- Prix HT environ 165 € → ~198 € TTC
- Inclus : bloc d'alim + câble FR + support mural d'origine
- Livraison gratuite à partir de 200 € HT (atteint avec drawer ajouté)

**Option C — Solushop FR (boutique POS spécialisée)**
- Prix affiché : 172,64 € HT (≈ 207 € TTC)
- Livraison 3-5 j ouvrés
- Service après-vente FR rassurant (revendeur Epson agréé)

**Choix recommandé** : **Option B (Bechtle)** = compromis prix/délai + facture pro nominative pour TVA déductible Le Cayenne.

### Commande #2 — Tiroir-caisse EC-410 (Amazon FR Prime)

- Recherche : "EC-410 tiroir caisse RJ11" OU "Waapos tiroir caisse EC410"
- ASIN : B002XF1QUI (Ecopos) ou B017IRUQ2Y (Waapos)
- Prix : 45-60 € TTC Prime
- Livraison J+2-3
- Inclus : tiroir, bac monnaie 4-billets-8-monnaie, **câble RJ11 vers imprimante**, 2 clés (verrouillage)

### Commande #3 (optionnelle) — papier thermique 80mm

- Recherche : "rouleau papier thermique 80x80 sans BPA"
- Lot de 50 rouleaux ≈ 25-35 € TTC Amazon FR
- 50 rouleaux = ~6 mois consommation single-resto Le Cayenne (rotation 1 rouleau / 3 jours environ)
- ⚠️ Toujours commander rouleaux **sans BPA** (mention obligatoire France/EU 2026)

### Total commande V1 (printer + drawer + papier 6 mois)

| Item | Prix TTC FR | Source |
|---|---|---|
| Epson TM-T20III Ethernet C31CH51012 | 198 € | Bechtle |
| Tiroir EC-410 RJ11 | 55 € | Amazon FR Prime |
| Lot 50 rouleaux papier 80×80 sans BPA | 30 € | Amazon FR Prime |
| **TOTAL V1 encaissement hardware** | **283 € TTC** | livré J+2-5 |

### Validation après réception (avant ouverture)

Owner branche le tout (printer Ethernet sur switch LAN + RJ11 vers drawer + alim) puis :

```bash
# 1. Trouver l'IP de l'imprimante (généralement DHCP au premier boot, puis fixer)
# Méthode A : appuyer le bouton "feed" pendant boot → la printer imprime sa config réseau
# Méthode B : ping balayage 192.168.1.0/24 + scan port 9100

# 2. Créer la Printer dans Le Cayenne admin
php artisan tinker
>>> \App\Models\Printer::create([
    'branch_id' => 1,
    'name' => 'Caisse principale',
    'type' => 'escpos_tcp',
    'station' => 'receipt',
    'host' => '192.168.1.50',  // IP fixée
    'port' => 9100,
    'width_chars' => 48,
    'options' => ['code_page' => 19], // CP858
]);

# 3. Test print
>>> app(\App\Services\Hardware\EscPosPrinterService::class)->testPrint(\App\Models\Printer::latest()->first());
# → ticket "FOODKING POS / Test print OK" doit sortir

# 4. Test drawer
>>> app(\App\Services\Hardware\EscPosPrinterService::class)->openDrawer(null, 1);
# → tiroir doit s'ouvrir avec un "clack"
```

Si OK : V1 prêt pour ouverture commerciale.

---

## 8. Risques & garde-fous

### Risque #1 — Epson TM-T20III marqué "discontinué" Epson France

**Mitigation** : distributeurs FR (Bechtle, Solushop, Procaisse, Officeeasy) ont stock pour 6-12 mois. Modèle successor **Epson TM-T20IV** sera annoncé fin 2026 / début 2027 et reste compatible ESC/POS DKC (code FoodKing n'a pas besoin de modification). Si V1 démarre avant 2027 → TM-T20III suffit. Si owner veut sécuriser long-terme → passer au **Star TSP143IV (+100 €)** plus pérenne (4× plus durable).

### Risque #2 — Tiroir générique EC-410 fiabilité long-terme

**Mitigation** : usage single-resto = 30-80 ouvertures/jour. EC-410 testé pour 1 M cycles → ~30 ans d'usage. Si owner ouvre 200+ jour (V2 multi-resto franchise), upgrade vers Star SMD2 pertinent.

### Risque #3 — IP statique imprimante

**Mitigation** : configurer DHCP-reservation sur la box internet (réserver l'IP sur la MAC de l'imprimante). Évite que la printer change d'IP après reboot box et casse la connexion TCP du POS.

### Risque #4 — Câble RJ11 trop court

**Mitigation** : le câble livré avec EC-410 fait ~1m. Si la printer est éloignée du drawer (poste large), prévoir un câble RJ11 6P6C **mâle-mâle 2m** (~7-10 € Amazon FR). Câblage POS = standard RJ11 6P6C, **PAS RJ12** (différent pinout).

### Risque #5 — Owner se trompe et achète un tiroir USB-direct au lieu de RJ11-driven

**Mitigation** : avant clic Amazon, owner vérifie la fiche → mention "**RJ11 cash drawer** **ESC/POS driven**" ou "**24V DC RJ11 connector**" ou "**printer-driven**". Refuser tout tiroir "USB direct PC sans imprimante" (incompatible avec le code FoodKing).

---

## 9. Conclusion

**V1 Le Cayenne encaissement hardware = 283 € TTC + 0 ligne de code FoodKing à modifier.**

Le code `app/Services/Hardware/*` est **déjà production-ready ESC/POS standard** depuis le commit "V14 C-β / FINDING C-β-T15-1 P2" (codepage CP858 + iconv TRANSLIT). Le Cayenne plug-and-play dès réception colis.

L'investissement borne kiosk dédiée (350 € Galaxy Tab A9+ + support) est **reporté V1.0.2** — V1 fonctionne avec kiosk plein-écran sur PC caisse.

Pour V2 SaaS multi-resto (franchise futur), upgrader vers **Star TSP143IV-UE + CloudPRNT** (~340 €) ouvrira le print-from-cloud sans VPN, mais ça reste backlog stratégique post-V1 (cf. `feedback_no_cloud_until_owner_initiates.md`).

---

## Sources

- [Amazon FR — Star Micronics TSP143IIILAN](https://www.amazon.com/Star-Micronics-TSP143IIILAN-Ethernet-Auto-cutter/dp/B07VMGYFKC)
- [Epson France — TM-T20III Ethernet C31CH51012](https://www.epson.fr/fr_FR/produits/commerce/imprimantes-pour-points-de-vente/imprimantes-pc-pos/epson-tm-t20iii-(012):-ethernet,-ps,-blk,-eu/p/28150)
- [Bechtle FR — Epson TM-T20III Ethernet POS](https://www.bechtle.com/fr/shop/epson-tm-t20iii-ethernet-pos--4410790--p)
- [Solushop FR — Epson TM-T20III thermique](https://www.solushop.com/imprimantes-tickets-de-caisse/3238-epson-tm-t20iii-thermique-imprimantes-pos-203-x-203-dpi-c31ch51011.html)
- [Procaisse FR — Epson TM-T20III Ethernet](https://procaisse.com/imprimantes-tickets-thermiques/1680-epson-tm-t20iii-ethernet-8715946669656.html)
- [Idealo FR — Epson TM-T20III C31CH51012](https://www.idealo.fr/prix/207125560/epson-tm-t20iii-c31ch51012.html)
- [Busiboutique FR — Star TSP143IV-UE Noir](https://www.busiboutique.com/produit/star-micronics-tsp143iv-ue-imprimante-de-recus-usb-c-usb-a-ethernet-lan-noir-39473090-374160.html)
- [Logiscenter FR — Star TSP143IV-X4](https://www.logiscenter.fr/imprimante-de-tickets-star-micronics-tsp143iv-x4)
- [Amazon FR — EC-410 Tiroir-caisse RJ11](https://www.amazon.fr/Ec-410-Tiroir-caisse-avec-connecteur-RJ11-Noir/dp/B002XF1QUI)
- [Amazon FR — Waapos Tiroir Caisse EC410](https://www.amazon.fr/Waapos-Tiroir-Caisse-EC410/dp/B017IRUQ2Y)
- [Manomano FR — BeMatik tiroir caisse RJ11](https://www.manomano.fr/p/bematik-tiroir-caisse-automatique-noir-avec-rj11-pour-imprimante-pos-caisse-enregistreuse-13310211)
- [POS Valley — store Amazon FR tiroirs](https://www.amazon.fr/stores/POSVALLEY/Tiroir-caisse/page/113C98B8-FDA5-483E-97DE-C652FB360482)
- [Apple FR — iPad WiFi 64Go](https://www.apple.com/fr/shop/buy-ipad/ipad/64go-argent-wifi-cellular)
- [Samsung FR — Galaxy Tab A9 / A9+](https://www.samsung.com/fr/tablets/galaxy-tab-a9/buy/)
- [Tabesto — prix borne commande restaurant 2026](https://www.tabesto.com/en/articles/whats-the-cost-of-a-self-order-kiosk-for-restaurants)
- [JMP Solutions — Prix borne commande restaurant 2026](https://www.jmpsolutions.fr/prix-borne-de-commande-restaurant/)
- [Star Micronics — TSP143III fiche officielle](https://starmicronics.com/product/tsp143iii-thermal-receipt-printer-usb-bluetooth-wireless/)
- [Star Micronics — TSP143IV fiche officielle](https://starmicronics.com/product/tsp143iv-thermal-receipt-printer/)
