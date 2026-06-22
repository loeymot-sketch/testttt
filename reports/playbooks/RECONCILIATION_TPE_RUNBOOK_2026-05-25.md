# Réconciliation TPE — Routine Matin Caissier

**Date** : 2026-05-25
**Pour** : Caissier d'ouverture / Propriétaire Le Cayenne
**Durée** : 5 minutes chaque matin
**Format** : A4 imprimable (~6 pages)
**Conservation** : 6 ans (obligation NF525)

> **Règle d'or** : si l'écart entre TPE et caisse est supérieur à 50 € ou 5 %, **appelle le propriétaire immédiatement** avant d'ouvrir les portes.

---

## §1 — Pourquoi cette routine ?

La réconciliation TPE matche les paiements carte enregistrés par le **terminal physique** (TPE Shine / Worldline Valina) avec ceux enregistrés par l'**application Le Cayenne** (base de données Laravel).

Ces deux compteurs **doivent** être identiques chaque jour. S'ils divergent, c'est un signal d'alerte.

### Les 4 risques que cette routine attrape

1. **Coupure électrique en plein paiement**
   Le TPE peut avoir validé la transaction MAIS l'app n'a pas reçu la confirmation. Argent encaissé, commande "non payée" en système.

2. **Drop fibre Internet pendant transaction**
   Même scénario : TPE valide hors-ligne, app reste bloquée sur "en cours". Le client part, persuadé d'avoir payé. Personne ne remarque sans réconciliation.

3. **Charge fantôme (orphaned charge)**
   Le TPE enregistre un encaissement réel, mais la commande n'a jamais été ouverte dans l'app. Argent dans la poche, sans trace. Perte directe.

4. **Faux positif app (app dit "payé" mais TPE a fail)**
   Le caissier marque la commande payée carte, mais le TPE a refusé ou plantE silencieusement. Le client part sans payer. Perte directe.

### Obligation légale NF525

La loi française **NF525** (logiciel de caisse) impose une réconciliation comptable quotidienne entre les flux paiement et les enregistrements de caisse. Les écarts non documentés sont un motif de redressement fiscal en cas de contrôle. Conservation **6 ans minimum**.

---

## §2 — Routine matin (5 min) — par étape

**Quand** : avant ouverture des portes au client, idéalement entre 8h45 et 8h55.
**Qui** : propriétaire OU premier caissier formé.
**Matériel** :
- TPE physique (Shine ou Worldline Valina)
- Ordinateur ouvert avec accès admin Le Cayenne
- Cahier de réconciliation (papier ou Sheets — voir §5)
- Stylo

### Étape 1 — Ticket TPE J-1 (1 min)

Sur le TPE physique :

- **TPE Shine** : appuie sur **Menu** → **Récapitulatif** → **Journée précédente** → **Imprimer**
- **TPE Worldline Valina** : appuie sur **F4** → **Totaux** → **Hier** → **Print**

> *Si manuel TPE perdu, demande au propriétaire ou contacte le support fabricant (numéro au dos du TPE).*

Note sur le ticket :
- Nombre total de transactions carte (ligne "NB CB" ou "TX TOTAL")
- Montant total carte (ligne "MONTANT TOTAL")
- Heure de première transaction
- Heure de dernière transaction

### Étape 2 — Cash overview Laravel (1 min)

Ouvre dans navigateur :

```
http://127.0.0.1:8000/admin/cash-overview?date=hier
```

Note dans le cahier :
- Nombre transactions **carte** (colonne CB)
- Montant total **carte**
- Nombre transactions **liquide** (colonne Cash)
- Montant total **liquide**
- Heure première / dernière transaction (si affichées)

### Étape 3 — Z-report Laravel (1 min)

Imprime le Z-report du jour J-1 si la fonction est disponible :

```
http://127.0.0.1:8000/admin/reports/z-report?date=hier
```

Sinon, fais une **capture d'écran** propre de cette page et imprime-la (ou colle-la dans le cahier).

> *Le Z-report est ton justificatif fiscal officiel. Toujours conserver une copie papier.*

### Étape 4 — Comparaison ligne par ligne (2 min)

Sur le cahier (§5), remplis les colonnes pour la date d'hier :

| Critère | TPE physique | Laravel (Cash overview) | Écart |
|---------|-------------|------------------------|-------|
| Nombre transactions carte | __ | __ | __ |
| Montant total carte | __ € | __ € | __ € |
| Heure 1ère transaction | __:__ | __:__ | __ |
| Heure dernière transaction | __:__ | __:__ | __ |

### Étape 5 — Action selon écart (variable)

Vois **§3 — Actions selon scénario écart**.

---

## §3 — Actions selon scénario écart (5 cas réels)

### Cas 1 — TPE total > Laravel total

**Exemple** : TPE indique 850 €, Laravel indique 800 € → écart +50 € TPE.

**Cause probable** : charge fantôme. Un client a payé par carte, le TPE a validé, MAIS la commande n'a jamais été enregistrée dans Le Cayenne. Argent encaissé sans trace système.

**Actions** :

1. **Recherche dans audit_logs** (vérifie si paiement Stripe orphelin) :
   ```bash
   php artisan stripe:drain-stranded-cpn
   ```
   Cette commande existe déjà (K2-HEAL-05) et liste les charges Stripe sans commande associée.

2. Si commande retrouvée tardivement (rare) → applique remboursement OU note la perte dans le cahier.

3. Si charge confirmée orpheline (50 € sans commande) :
   - Note dans cahier : `Cas 1 — Charge fantôme 50€ — DrainStranded confirmé OK`
   - Signe + date
   - Si pattern récurrent (>3 fois/semaine) → escalation propriétaire pour audit caissier ou bug app

4. **Vérifie audit chain NF525** :
   ```bash
   php artisan fiscal:verify-chain
   ```
   Réponse attendue : `CHAIN OK`. Si KO → **STOP**, n'ouvre pas, appelle support.

### Cas 2 — Laravel total > TPE total

**Exemple** : Laravel indique 850 €, TPE indique 800 € → écart +50 € Laravel.

**Cause probable** : le caissier a marqué une commande "payée carte" mais n'a jamais réellement passé le TPE. Soit erreur (oubli), soit fraude (volontaire).

**Actions** :

1. **Vérifie vidéo CCTV** si caméra installée et accessible (revue 24h).

2. **Recompte cash physique** vs liquide compté la veille :
   - Si le cash physique est > Laravel cash, alors un paiement "carte" était en fait du cash (erreur saisie caissier).
   - Si pas d'écart cash → vol probable ou bug réel.

3. Si écart confirmé non-récupérable :
   - Note dans cahier : `Cas 2 — Manque 50€ TPE — cause investiguée [vol/erreur saisie/bug]`
   - Signature propriétaire **obligatoire**
   - Si récurrent même caissier → entretien staff (voir §4)

### Cas 3 — Nombre transactions égal, montants différents

**Exemple** : 42 transactions TPE et 42 transactions Laravel, mais TPE = 820 €, Laravel = 815 €.

**Cause probable** :
- Erreur de saisie montant (caissier a tapé 5 € au lieu de 10 €)
- Remboursement partiel non-réconcilié
- Pourboire encaissé séparément non-reporté

**Actions** :

1. Ouvre **Stripe Dashboard** (si Stripe utilisé) → onglet Paiements → filtre date J-1.

2. Compare tx par tx : pour chaque ligne TPE, vérifie le montant exact dans Stripe.

3. Identifie la (ou les) ligne(s) divergente(s). Souvent 1-2 transactions seulement.

4. Note dans cahier : `Cas 3 — Écart 5€ — saisie erronée tx #X | corrigé via [remboursement/note]`

### Cas 4 — Nombre de transactions différent

**Exemple** : TPE = 43 tx, Laravel = 42 tx.

**Cause probable** :
- **Si TPE > Laravel** : transaction TPE physique SANS commande Laravel ouverte. Caissier a oublié d'ouvrir la commande dans l'app avant de passer la carte. **LOSS confirmée** car aucune trace système.
- **Si Laravel > TPE** : commande Laravel marquée "carte payée" sans paiement TPE réel. **BUG ou FRAUDE** — système marque payé sans encaissement.

**Actions** :

1. **ESCALATION immédiate au propriétaire**. Cette catégorie n'est pas auto-résolue.

2. N'ouvre pas les portes tant que le propriétaire n'a pas validé.

3. Note dans cahier : `Cas 4 — Δ nombre tx — ESCALATION owner — décision : __`

### Cas 5 — Tout cohérent (résultat attendu 95% du temps)

**Exemple** : TPE = 42 tx / 800 €, Laravel = 42 tx / 800 €, heures identiques.

**Actions** :

1. Coche dans cahier la ligne "OK".
2. Signe + date.
3. Colle ticket TPE imprimé et capture cash-overview dans le cahier.
4. Ouvre les portes. Routine terminée.

---

## §4 — Quand appeler le support

| Situation | Qui appeler | Délai |
|-----------|-------------|-------|
| Écart > 50 € ou > 5 % | Propriétaire | Immédiat |
| Pattern récurrent (même caissier 3+ fois/semaine) | Propriétaire | Sous 24h |
| Problème technique TPE (papier, écran, erreur) | Support Shine ou Worldline (numéro au dos TPE) | Immédiat |
| Dispute Stripe (chargeback client) | Propriétaire via Stripe Dashboard | Sous 7 jours réglementaire |
| Transaction fantôme persistante 48h après réconciliation | Banque + propriétaire | Immédiat |
| NF525 `verify-chain` KO | **STOP**, nouvelle session Claude Code OU support technique | Immédiat |
| App Laravel inaccessible | Support technique | Avant ouverture |

> *Le numéro support TPE est généralement collé sous le terminal. Si introuvable : Shine 01 84 80 73 73 / Worldline support marchand selon contrat.*

---

## §5 — Template cahier réconciliation (A4 imprimable)

Imprime ce tableau, photocopie x30, perfore et range dans un classeur dédié.

```
+------------+-----------+--------+----------+----------+---------+---------+---------+---------+--------+
| Date       | Cashier   | Heure  | TPE Tx   | Laravel  | TPE €   | Laravel | Écart € | Cas     | Sign.  |
|            |           |        |          | Tx       |         | €       |         | (1-5)   |        |
+------------+-----------+--------+----------+----------+---------+---------+---------+---------+--------+
| 2026-05-25 | Marie     | 08:50  | 42       | 42       | 800.00  | 800.00  | 0.00    | 5 OK    |   MD   |
|            |           |        |          |          |         |         |         |         |        |
+------------+-----------+--------+----------+----------+---------+---------+---------+---------+--------+
|            |           |        |          |          |         |         |         |         |        |
|            |           |        |          |          |         |         |         |         |        |
+------------+-----------+--------+----------+----------+---------+---------+---------+---------+--------+
|            |           |        |          |          |         |         |         |         |        |
|            |           |        |          |          |         |         |         |         |        |
+------------+-----------+--------+----------+----------+---------+---------+---------+---------+--------+
|            |           |        |          |          |         |         |         |         |        |
|            |           |        |          |          |         |         |         |         |        |
+------------+-----------+--------+----------+----------+---------+---------+---------+---------+--------+
|            |           |        |          |          |         |         |         |         |        |
|            |           |        |          |          |         |         |         |         |        |
+------------+-----------+--------+----------+----------+---------+---------+---------+---------+--------+

Notes / Actions :
________________________________________________________________________________
________________________________________________________________________________
________________________________________________________________________________

Visa propriétaire (si écart) : ______________________
```

**Conservation** : ce classeur doit être archivé **6 ans** conformément à NF525.
Range-le dans un endroit sec, à l'abri du soleil. Numérote les classeurs (Année / N°).

---

## §6 — Template Excel / Google Sheets

Pour une version numérique parallèle au cahier papier.

### Structure colonnes

| Col | Nom | Type | Formule |
|-----|-----|------|---------|
| A | Date | Date | (saisie) |
| B | Cashier | Texte | (saisie) |
| C | Heure | Heure | (saisie) |
| D | TPE Tx (nb) | Nombre | (saisie) |
| E | Laravel Tx (nb) | Nombre | (saisie) |
| F | TPE Total (€) | Devise | (saisie) |
| G | Laravel Total (€) | Devise | (saisie) |
| H | Écart absolu (€) | Devise | `=F-G` |
| I | Écart % | Pourcentage | `=SI(F=0;0;H/F*100)` |
| J | Cas (1-5) | Nombre | (saisie selon §3) |
| K | Action prise | Texte | (saisie) |
| L | Signature | Texte | (saisie initiales) |

### Mise en forme conditionnelle

- Si `ABS(I) > 5` → cellule **rouge** (écart > 5 %)
- Si `ABS(H) > 50` → cellule **rouge** (écart > 50 €)
- Si `J = 5` → cellule **verte** (cas OK)
- Si `J = 4` → cellule **orange foncé** (escalation)

### Lien template

> *Template Google Sheets public à créer ultérieurement par le propriétaire. URL future : `https://docs.google.com/spreadsheets/d/[À-REMPLIR]`*

Pour reproduire manuellement : crée un nouveau Sheets, colle les en-têtes ci-dessus, applique les formules colonnes H et I, puis les règles de mise en forme conditionnelle via **Format → Mise en forme conditionnelle**.

---

## Annexe — Commandes utiles

### Vérification chaîne audit NF525

```bash
cd /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
php artisan fiscal:verify-chain
```

**Réponse attendue** : `CHAIN OK` (vert).
**Si KO** : STOP, ne pas ouvrir les portes, escalation immédiate.

### Drainer charges Stripe orphelines

```bash
php artisan stripe:drain-stranded-cpn
```

Liste et tente de réconcilier les charges Stripe qui n'ont pas de commande associée dans Le Cayenne. À utiliser en Cas 1 (TPE > Laravel).

### Vue sessions caisse du jour

```bash
php artisan tinker --execute='
\App\Models\CashDrawerSession::whereDate("opened_at", today())->get()->each(function ($s) {
    echo "Session #{$s->id} cashier={$s->user_id} opened={$s->opened_at} closed={$s->closed_at} cash_start={$s->cash_start_amount}\n";
});
'
```

Affiche toutes les sessions de caisse ouvertes aujourd'hui, avec caissier, horodatage et fond de caisse initial.

### Recherche manuelle paiements dans audit_logs

```sql
SELECT id, action, user_id, created_at, payload
FROM audit_logs
WHERE created_at LIKE '2026-05-25%'
  AND action LIKE '%payment%'
ORDER BY created_at DESC
LIMIT 50;
```

Remplace `2026-05-25` par la date à investiguer. Utile si la commande `stripe:drain-stranded-cpn` ne suffit pas pour identifier une charge fantôme.

---

**Fin du runbook.** Garde cet exemplaire imprimé près de la caisse principale. Document de référence pour formation nouveau caissier.

**Source** : Mandate gap-hunt Phase A.3 du 2026-05-25, ops gate #3 from Super.4.
**Auteur** : Claude Opus 4.7 (1M context) — agent A.3 FoodKing.
