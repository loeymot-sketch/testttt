# CAISSE V1 — Hardware Qualification Checklist

## 0. Identification
- Date :
- Lieu (lab) :
- Owner Ops :
- Branches concernées :
- Matériel requis (liste exhaustive avec modèles, série) :
  - TPE : Ingenico Move/2500 (à confirmer avec Ops) - n° série :
  - TPE : Verifone V200c (à confirmer avec Ops) - n° série :
  - Imprimante caisse : Epson TM-T20III ou Epson m30 (à confirmer avec Ops) - n° série :
  - Imprimante cuisine : Star TSP-143 ou équivalent ESC/POS (à confirmer avec Ops) - n° série :
  - Tiroir-caisse : Posiflex ou Star CD3-1616 (à confirmer avec Ops) - n° série :
  - Kiosk : Elo I-Series ou Posiflex KS-2615 (à confirmer avec Ops) - n° série :
  - Tablette POS : iPad 9th+ ou Surface Go 3 (à confirmer avec Ops) - n° série :
  - Routeur Wi-Fi 2.4GHz/5GHz + accès 4G de test - n° série :
  - Cartes et supports de test : CB EMV approve, CB decline, carte NFC, carte loyalty NFC, QR menu, QR coupon, QR loyalty, rouleaux papier, câble USB, câble Ethernet, RJ12, alimentation secours.

## 1. TPE (terminal de paiement)
- Modèles couverts : Ingenico Move/2500, Verifone V200c, autre (préciser)
- Connectivité : USB | Bluetooth | Wi-Fi | 4G
- Tests :
  1.1 Approve transaction CB EMV puce - durée < 5 s - montants 1€, 10€, 100€
  1.2 Approve sans contact NFC - durée < 2 s
  1.3 Decline carte refusée test - refus visible POS en < 5 s
  1.4 Timeout réseau TPE - déconnexion pendant transaction, retour timeout en < 60 s
  1.5 Cancel client touche annuler - état caisse cohérent en < 5 s
  1.6 Reprise après crash app POS pendant transaction - TPE seul, état orphelin identifié
  1.7 Failover ticket-restaurant si TR supporté - UI grisée si non supporté
  1.8 Reconciliation fin journée - rapport Z TPE vs Z caisse, écart attendu 0,00€

## 2. Imprimante ESC/POS (ticket de caisse + cuisine)
- Modèles : Epson TM-T20III/m30, Star TSP-143, autre
- Connectivité : USB | Ethernet | Bluetooth
- Tests :
  2.1 Impression ticket caisse standard - tous modes paiement, sortie < 3 s après validation
  2.2 Impression ticket cuisine multi-stations - routage station correct, sortie < 5 s
  2.3 Failover imprimante éteinte - mise en file d'attente + reprise sans perte
  2.4 Imprimante hors-ligne ou câble débranché - alerte UI en < 10 s
  2.5 Papier épuisé - capteur détecté + alerte UI en < 10 s
  2.6 Coupe automatique vs manuelle - coupe nette ou instruction manuelle visible
  2.7 Caractères spéciaux - accents, devises, RTL si Arabe/Bengali, aucun caractère illisible critique
  2.8 Performance - 50 tickets en burst < 60 s, aucune perte

## 3. Tiroir-caisse
- Modèles : Posiflex, Star CD3-1616, autre
- Connectivité : RJ12 sur imprimante | USB
- Tests :
  3.1 Ouverture sur paiement cash - impulsion tiroir < 1 s après validation
  3.2 Ouverture manuelle no-sale - autorisation requise, log créé
  3.3 Lock physique - fermeture verrouillée et ouverture impossible sans clé ou impulsion autorisée
  3.4 Audit ouverture sans transaction - log horodaté avec utilisateur et branche
  3.5 Comptage fin de service - procédure suivie, montant saisi, justificatif conservé
  3.6 Discrepancy détection - écart calculé et signalé si écart > seuil Ops défini

## 4. Kiosk (borne)
- Modèles : Elo I-Series, Posiflex KS-2615, autre
- Composants : touchscreen capacitif, NFC, scanner QR/code-barres, imprimante intégrée, TPE intégré ou externe
- Tests :
  4.1 Touchscreen - précision < 5 mm, multi-touch ignoré
  4.2 NFC - lecture carte loyalty < 1 s, plage anti-double-scan active
  4.3 Scanner QR - code menu, coupon, loyalty lus en < 2 s
  4.4 Sleep / wake - reprise état < 3 s, pas de perte panier
  4.5 Mode offline - queue locale + reprise après reconnexion selon M-11
  4.6 Démarrage à froid - auto-login machine, token Sanctum valide, branche correcte
  4.7 PIN admin fallback - accès admin conforme au périmètre AUDIT_KIOSK
  4.8 Crash recovery - kill process, relance, retour état idle propre en < 30 s
  4.9 Vandalisme - touches simultanées, glissement violent, lock écran, aucun contournement fonctionnel

## 5. Tablette POS (mode mobile)
- Modèles : iPad 9th+, Surface Go 3, autre
- Tests :
  5.1 Wi-Fi vers 4G failover transparent - interruption caisse < 10 s
  5.2 Sleep automatique 10 min - reprise + re-auth si nécessaire en < 15 s
  5.3 Faible batterie - alerte 20%/10%, lock à 5%
  5.4 Performance panier 50 items - latence ajout item < 200 ms
  5.5 Synchronisation après perte réseau prolongée > 5 min - reprise sans doublon ni perte
  5.6 Mode portrait/paysage - layout valide, aucun élément critique hors écran

## 6. Réseau / Infrastructure
- Tests :
  6.1 Wi-Fi 5GHz vs 2.4GHz - débit, latence, perte paquet mesurés
  6.2 Captive portal détection - blocage détecté avant ouverture service
  6.3 DNS local vs public - résolution .local mDNS et DNS public validées
  6.4 NTP synchronisation - dérive horloge < 1 s, horodatage compatible exigences fiscales NF525
  6.5 Coupure 30 s puis reconnexion - queue + outbox rejouent OK sans doublon

## 7. Signature de préparation lab
- Owner Ops :
- Owner technique :
- Date de validation de la checklist :
- Commentaires :
