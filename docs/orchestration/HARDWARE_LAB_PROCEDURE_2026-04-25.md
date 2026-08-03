# HARDWARE LAB PROCEDURE — Caisse V1

## 1. Réservation lab
- Responsable réservation : Owner Ops Caisse V1.
- Fenêtre recommandée : 1 journée complète pour qualification initiale, 0,5 journée pour re-test ciblé.
- Préavis minimal : 5 jours ouvrés avant la date de lab.
- Participants requis : Owner Ops, Ops Hardware, QA POS, QA Kiosk, Ops Réseau, Finance Ops si tests Z caisse/TPE.
- Créneau type :
  - 09:00-09:30 installation et inventaire matériel.
  - 09:30-12:30 TPE, imprimantes, tiroir-caisse.
  - 13:30-16:30 kiosk, tablette POS, réseau.
  - 16:30-17:30 consolidation preuves, acceptance grid, verdict.

## 2. Matériel à apporter

| Catégorie | Matériel | Pré-vérification avant lab | N° série / référence |
|---|---|---|---|
| TPE | Ingenico Move/2500 (à confirmer avec Ops) | Batterie > 80%, firmware noté, moyen CB test disponible |   |
| TPE | Verifone V200c (à confirmer avec Ops) | Alimentation, connectivité, firmware noté |   |
| Paiement | Cartes test EMV approve, decline, NFC, ticket-restaurant si supporté | Cartes actives et scénarios confirmés |   |
| Imprimante caisse | Epson TM-T20III ou Epson m30 (à confirmer avec Ops) | Papier chargé, câble USB/Ethernet, alimentation |   |
| Imprimante cuisine | Star TSP-143 ou ESC/POS équivalent (à confirmer avec Ops) | Papier chargé, station cuisine configurée |   |
| Tiroir-caisse | Posiflex ou Star CD3-1616 (à confirmer avec Ops) | Clé présente, RJ12/USB disponible, lock testé |   |
| Kiosk | Elo I-Series ou Posiflex KS-2615 (à confirmer avec Ops) | Touchscreen nettoyé, NFC, scanner, imprimante intégrée vérifiés |   |
| Tablette POS | iPad 9th+ ou Surface Go 3 (à confirmer avec Ops) | Batterie > 80%, Wi-Fi et 4G actifs, chargeur présent |   |
| Réseau | Routeur dual-band, SIM 4G, câbles Ethernet, accès captive portal si test | SSID lab, mots de passe et DNS/NTP connus |   |
| Consommables | Papier thermique, câbles USB, RJ12, Ethernet, multiprise, étiquettes | Quantité suffisante pour 50 tickets burst + re-tests |   |

## 3. Backups matériel
- Prévoir au minimum 1 TPE de remplacement compatible avec le même scénario paiement.
- Prévoir 1 imprimante ESC/POS de remplacement avec rouleaux papier supplémentaires.
- Prévoir 1 câble de chaque type : USB, Ethernet, RJ12, alimentation imprimante, alimentation kiosk.
- Prévoir 1 tablette de remplacement ou 1 device POS mobile équivalent.
- Prévoir une connexion 4G de secours indépendante du routeur lab.
- Si un équipement principal échoue avant test, exécuter le même protocole sur le matériel backup et noter le remplacement dans l'acceptance grid.

## 4. Escalation si échec

| Cas d'échec | Contact primaire | Délai cible | Action attendue |
|---|---|---:|---|
| TPE paiement bloqué, decline incohérent, reconciliation impossible | Ops Hardware + prestataire paiement | 4 h ouvrées | Fournir diagnostic TPE, firmware, logs transaction et décision re-test |
| Imprimante ou tiroir non fonctionnel | Ops Hardware | 1 jour ouvré | Remplacer câble ou matériel, confirmer modèle supporté |
| Kiosk touchscreen, NFC ou scanner instable | Ops Hardware + QA Kiosk | 1 jour ouvré | Calibrer, remplacer périphérique ou ouvrir ticket fournisseur |
| Tablette POS failover, sleep ou batterie non conforme | Ops Hardware + QA POS | 1 jour ouvré | Vérifier OS, profil MDM, batterie, connectivité |
| Réseau, DNS, NTP ou captive portal non conforme | Ops Réseau | 4 h ouvrées | Corriger configuration routeur/DNS/NTP et fournir preuve |
| Sujet fiscal NF525 au-delà de la mesure lab | Ops Compliance + partenaire conformité externe | Selon contrat | Qualifier l'exigence réglementaire et produire avis externe |

## 5. Conservation des résultats
- Dossier de sortie principal : `reports/hardware/`.
- Checklist signée : `reports/hardware/CAISSE_V1_HARDWARE_QUALIF_CHECKLIST_2026-04-25.md` ou export PDF signé.
- Protocoles exécutés : joindre captures, photos, tickets papier scannés et rapports Z.
- Acceptance grid signée : `reports/hardware/CAISSE_V1_HARDWARE_ACCEPTANCE_GRID_2026-04-25.md` ou export PDF signé.
- Nommage preuves : `CV1-M16_<test-id>_<device-serial>_<YYYY-MM-DD>.<ext>`.
- Rétention minimale : durée projet Caisse V1 + 12 mois, sauf exigence Ops ou compliance plus longue.

## 6. Cadence
- Qualification initiale : avant GO Caisse V1 sur matériel lab.
- Re-qualification semestrielle : tous les 6 mois pour le parc validé.
- Re-qualification obligatoire hors cadence : changement modèle matériel, firmware TPE, firmware imprimante, OS kiosk/tablette, routeur, configuration DNS/NTP, prestataire paiement, ou incident production majeur.
- Re-test ciblé : tout test FAIL doit être rejoué après correction avec nouvelle date et nouveau signataire.

## 7. Règles de verdict
- GO : tous les tests critiques PASS, aucun FAIL non accepté par Ops, preuves archivées.
- HOLD : un ou plusieurs FAIL non critiques avec workaround documenté et propriétaire nommé.
- NO-GO : FAIL sur paiement, impression obligatoire, tiroir cash critique, branche kiosk incorrecte, sync offline, NTP, ou tout échec sans contournement validé.
