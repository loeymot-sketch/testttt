# Runbook ouverture Le Cayenne — lundi 2026-06-01

> Toutes les actions à exécuter dimanche soir et lundi matin. Document à imprimer et cocher au fur et à mesure.

## Dimanche soir (avant 22h)

### 1. Catalogue : valider et re-seeder

Pré-requis : la revue `docs/playbooks/CATALOG_REVIEW_2026-05-28.md` est annotée et validée par le propriétaire.

```bash
# 1.1 État courant
php artisan menu:verify

# 1.2 Backup tables menu (rollback si besoin)
mkdir -p storage/backups
mysqldump -u $DB_USER -p$DB_PASSWORD $DB_DATABASE \
  items item_categories item_variations item_extras item_addons item_attributes \
  > storage/backups/pre-launch-$(date +%F).sql

# 1.3 Re-seed depuis config/menu.php (SSOT)
php artisan menu:reset --force

# 1.4 Vérification post-seed
php artisan menu:verify
```

Spot-check `/admin/items` :
- [ ] 14 catégories visibles dans l'ordre attendu (Tacos → Suppléments)
- [ ] Tacos XXL = 12,50 € · Le Cayenne = 7,00 € · Le Méga = 8,00 € · Coca-Cola = 1,50 €
- [ ] Aucun item placeholder en anglais (`Beef`, `Soup`, etc.)

**Rollback** si un prix sort de l'écran de spot-check :
```bash
mysql -u $DB_USER -p$DB_PASSWORD $DB_DATABASE < storage/backups/pre-launch-$(date +%F).sql
```

### 2. Identité NF525 (SIRET, TVA intracom)

1. Se connecter à `/admin/settings/company`.
2. Renseigner les 4 nouveaux champs introduits dans cette release :
   - **SIRET** (14 chiffres, obligatoire) — sur l'extrait Kbis
   - **TVA intracom** (format `FR` + 11 chiffres) si l'entreprise est assujettie TVA
   - **Code NAF** (4 chiffres + 1 lettre, ex. `5610C` restauration)
   - **Forme juridique** (SARL, SAS, EI…)
3. Cliquer **Save**.
4. Vérifier que `/admin/pos` → commande test → ticket → le pied de page affiche bien `SIRET: … | TVA: … | NAF: …`.

### 3. Configuration paiement au comptoir

`.env` (vérifier puis recharger config) :
```env
KIOSK_PAY_AT_COUNTER_ONLY=true
```
```bash
php artisan config:clear
php artisan optimize:clear
```

Test : `/kiosk` → composer une commande → cliquer **Confirmer** → l'écran doit **sauter la grille CB / Cash / TR** et afficher directement « Numéro de commande A001 — Présentez ce ticket à la caisse ». Le ticket est imprimé sur l'imprimante HP standard via le dialogue navigateur.

Côté caisse : `/admin/posOrders` → taper `A001` dans la recherche → la commande s'ouvre, statut **Non payé**.

### 4. Fixtures de bureau (à plastifier et coller avant l'arrivée des équipes)

- `docs/playbooks/KITCHEN_KDS_CHEATSHEET.md` → cuisine
- `docs/playbooks/CASHIER_POS_CHEATSHEET.md` → caisse
- `docs/playbooks/EMERGENCY_PROCEDURES.md` → caisse **et** cuisine (deux exemplaires)

Compléter les numéros de téléphone manquants dans `EMERGENCY_PROCEDURES.md` avant impression.

### 5. Z-Report J-1

Si l'environnement vient de tests internes : forcer un Z propre la veille pour démarrer la séquence fiscale lundi sur zéro.

```bash
php artisan z-report:close --branch=1 --force
```

## Lundi matin

### Pré-ouverture (1h avant)

- [ ] POS allumé, écran de connexion → caissier se connecte
- [ ] Borne allumée, écran d'accueil visible
- [ ] KDS cuisine allumé, écran de tickets visible
- [ ] OSS (écran d'appel client) allumé
- [ ] Imprimantes HP : papier OK + test print depuis le POS
- [ ] Terminal SumUp : chargé à 100 %, connecté en 4G/Wi-Fi
- [ ] Fond de caisse compté et saisi : `/admin/pos` → **Démarrer service** → saisir montant

### Premier client (test à blanc)

1. Un employé compose une commande borne (Tacos M + Coca).
2. Récupère le ticket A001.
3. Caisse : recherche A001 → encaisse espèces → tiroir s'ouvre → ticket imprimé.
4. Cuisine : ticket A001 apparaît sur KDS → bump → OSS affiche A001.

Si l'un des 4 points ne fonctionne pas → ne pas ouvrir, escalader au support.

### Pendant le service

- Suivre les antisèches caisse / cuisine.
- En cas d'incident : suivre `EMERGENCY_PROCEDURES.md`.
- Manager check toutes les 2 h : variance caisse cumulée, files KDS sans bouchon, borne up.

### Clôture du soir (vers 23h)

1. POS → **Clôture Z** → compter le tiroir → saisir total → comparer.
2. Si écart > 2 € : motif obligatoire (sans falsification).
3. Le rapport Z est signé NF525 et archivé automatiquement dans `storage/fiscal/`.
4. Sauvegarde DB rapide :
   ```bash
   mysqldump -u $DB_USER -p$DB_PASSWORD $DB_DATABASE > storage/backups/$(date +%F).sql
   ```

## Décision en attente du propriétaire

**Routing cuisine pour les commandes borne non payées.**
Aujourd'hui, dès qu'une commande borne est envoyée (statut `payment_status=UNPAID`, `payment_method=PAY_AT_COUNTER`), elle apparaît immédiatement sur le KDS cuisine. La cuisine commence à préparer avant que le client ait payé à la caisse. Avantages : pas d'attente, débit maximal. Risque : si un client part sans payer, on a déjà cuit la commande.

Option A (par défaut V1 actuelle) : cuisine voit la commande dès l'envoi borne.
Option B : cuisine ne voit la commande qu'après que la caisse a marqué `PAID`.

À trancher avant J+7 pour cadrer la suite — l'option B nécessite un petit changement de gating côté `FrontendOrderService` (ne dispatcher les signaux nouvelle-commande qu'après confirmation paiement).

## Téléphones critiques

- Support technique : _à remplir_
- Manager : _à remplir_
- SumUp : _via app_

---
Version 2026-05-28 · Le Cayenne
