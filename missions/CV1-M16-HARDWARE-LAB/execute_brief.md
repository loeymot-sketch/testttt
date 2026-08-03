# EXECUTE BRIEF — CV1-M16-HARDWARE-LAB (M-16)

## INVIOLABLE
1. Lis `AGENTS.md`, `missions/CV1-M16-HARDWARE-LAB/input.json`, `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md` (mission M-16).
2. Allowlist : 4 fichiers `.md` uniquement.
3. **Aucun code, aucun script, aucune migration.** Tu produis de la documentation de qualification.

## OBJECTIF EXACT

Préparer la **lab readiness** pour Caisse V1 : checklist hardware exhaustive + protocoles de test + grille d'acceptation signable + procédure lab.

## STRUCTURE OBLIGATOIRE — `CAISSE_V1_HARDWARE_QUALIF_CHECKLIST_2026-04-25.md`

```
# CAISSE V1 — Hardware Qualification Checklist

## 0. Identification
- Date :
- Lieu (lab) :
- Owner Ops :
- Branches concernées :
- Matériel requis (liste exhaustive avec modèles, série) :

## 1. TPE (terminal de paiement)
- Modèles couverts : Ingenico Move/2500, Verifone V200c, autre (préciser)
- Connectivité : USB | Bluetooth | Wi-Fi | 4G
- Tests :
  1.1 Approve transaction CB EMV puce — durée < 5s — montant 1€/10€/100€
  1.2 Approve sans contact NFC — < 2s
  1.3 Decline (carte refusée test)
  1.4 Timeout réseau TPE (déconnecter pendant transaction)
  1.5 Cancel client (touche annuler) — état caisse cohérent
  1.6 Reprise après crash app POS pendant transaction (TPE seul, état orphelin)
  1.7 Failover ticket-restaurant (si TR supporté) — UI grisée si non supporté
  1.8 Reconciliation fin journée (rapport Z TPE vs Z caisse)

## 2. Imprimante ESC/POS (ticket de caisse + cuisine)
- Modèles : Epson TM-T20III/m30, Star TSP-143, autre
- Connectivité : USB | Ethernet | Bluetooth
- Tests :
  2.1 Impression ticket caisse standard (tous modes paiement)
  2.2 Impression ticket cuisine multi-stations
  2.3 Failover : imprimante éteinte → file d'attente + reprise
  2.4 Imprimante hors-ligne / cable débranché (alerte UI ?)
  2.5 Papier épuisé (capteur + alerte)
  2.6 Coupe automatique vs manuelle
  2.7 Caractères spéciaux (accents, devises, RTL si Arabe/Bengali)
  2.8 Performance : 50 tickets en burst < 60s

## 3. Tiroir-caisse
- Modèles : Posiflex, Star CD3-1616, autre
- Connectivité : RJ12 sur imprimante | USB
- Tests :
  3.1 Ouverture sur paiement cash
  3.2 Ouverture manuelle "no sale" (avec autorisation)
  3.3 Lock physique
  3.4 Audit : log d'ouverture sans transaction
  3.5 Comptage fin de service (procédure)
  3.6 Discrepancy détection

## 4. Kiosk (borne)
- Modèles : Elo I-Series, Posiflex KS-2615, autre
- Composants : touchscreen capacitif, NFC, scanner QR/code-barres, imprimante intégrée, TPE intégré ou externe
- Tests :
  4.1 Touchscreen : précision < 5mm, multi-touch ignoré
  4.2 NFC : lecture carte loyalty < 1s, plage anti-double-scan
  4.3 Scanner QR : code menu / coupon / loyalty
  4.4 Sleep / wake : reprise état < 3s, pas de perte panier
  4.5 Mode offline : queue locale + reprise après reconnexion (cf. M-11)
  4.6 Démarrage à froid : auto-login machine, token Sanctum, branche correcte
  4.7 PIN admin fallback (cf. AUDIT_KIOSK)
  4.8 Crash recovery : kill process → relance → état idle propre
  4.9 Vandalisme : touches simultanées, glissement violent, lock écran

## 5. Tablette POS (mode mobile)
- Modèles : iPad 9th+, Surface Go 3, autre
- Tests :
  5.1 Wi-Fi → 4G failover transparent
  5.2 Sleep automatique (10min) → reprise + re-auth si nécessaire
  5.3 Faible batterie : alerte 20%/10%, lock à 5%
  5.4 Performance : panier 50 items, latence ajout < 200ms
  5.5 Synchronisation après perte réseau prolongée (> 5min)
  5.6 Mode portrait/paysage : layout valide

## 6. Réseau / Infrastructure
- Tests :
  6.1 Wi-Fi 5GHz vs 2.4GHz — débit, latence
  6.2 Captive portal détection
  6.3 DNS local vs public (résolution .local mDNS)
  6.4 NTP synchronisation (fiscal NF525 exige horodatage)
  6.5 Coupure 30s puis reconnexion : queue + outbox rejoue OK
```

## STRUCTURE — `CAISSE_V1_HARDWARE_TEST_PROTOCOLS_2026-04-25.md`

Pour chaque test (X.Y) ci-dessus, détailler :
- Objectif (1 phrase)
- Prérequis (matériel, état système, données de test)
- Étapes (numérotées, exactes, reproductibles)
- Résultat attendu (mesurable)
- Critères PASS / FAIL (binaire)
- Durée estimée
- Owner technique

## STRUCTURE — `CAISSE_V1_HARDWARE_ACCEPTANCE_GRID_2026-04-25.md`

Tableau signable :

| Test | PASS | FAIL | Notes | Date | Signataire |
|------|------|------|-------|------|------------|
| 1.1 TPE Approve EMV | ☐ | ☐ |   |   |   |
| 1.2 TPE NFC sans contact | ☐ | ☐ |   |   |   |
| ... |

Avec en bas : section `Verdict global : GO | HOLD | NO-GO` + signature humaine Ops.

## STRUCTURE — `HARDWARE_LAB_PROCEDURE_2026-04-25.md`

- Réservation lab : qui, quand, durée
- Matériel à apporter : liste avec n° série / pré-vérification
- Backups : matériel de remplacement disponible
- Escalation si échec : qui contacter, quel délai
- Conservation des résultats : où archiver les rapports signés
- Cadence : qualification initiale + re-qualification semestrielle

## RÈGLES

- Markdown propre, GFM tables.
- Pas d'inventer un modèle de matériel : utiliser des génériques (Epson TM-T20III, Ingenico Move/2500) en marquant `(à confirmer avec Ops)` si incertain.
- Numérotation stable : 1.1, 1.2, ... pour permettre référence dans tickets/PRs.
- Durées estimées en minutes (quantifiables).

## INTERDITS

- Toute modif de code.
- Toute fixation de critère sans donnée mesurable (ex: "rapide" → INTERDIT, écrire "< 200ms").
- Inventer des modèles précis non confirmés par Ops.

## SI BLOCAGE

- Liste matériel non disponible → `notes`: lister ce qui doit être commandé/loué.
- Tests non spécifiables (ex: NF525 fiscal hardware) → `risks`: `ESCALATION: NF525 hardware certification requires external compliance partner`.
