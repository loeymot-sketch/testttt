# Procédures d'urgence Le Cayenne (à plastifier — visible caisse + cuisine)

## 1. Internet est tombé

- L'appli POS, KDS et la borne continuent de fonctionner en local.
- Les commandes prises pendant la coupure se synchronisent **automatiquement** dès le retour réseau.
- Icône Wi-Fi rouge en haut de l'écran = mode offline actif.
- **À FAIRE** : continuer le service normalement, ne pas redémarrer les écrans, vérifier que l'icône repasse au vert dans les 30 min.

## 2. Terminal SumUp (carte) en panne

- **Espèces uniquement** jusqu'à résolution.
- Tenir le **cahier de réconciliation TPE** à la main : pour chaque client qui voulait payer carte → note nom du plat + montant + heure + signature client si > 20 €.
- Imprimer le ticket normalement (en cliquant **Encaisser carte → Confirmer carte** quand même, en notant « TPE HS » sur le ticket).
- En fin de service, donner le cahier au manager pour réconciliation avec le rapport Z.
- Procédure complète : `reports/playbooks/RECONCILIATION_TPE_RUNBOOK_2026-05-25.md` (si présent) ou demander au manager.

## 3. Borne client plante

1. Bouton physique d'alimentation en bas de la borne → maintenir 5 s → relâcher.
2. Attendre 30 s, la borne redémarre seule sur l'écran d'accueil.
3. Si la borne reste sur écran noir : prévenir le manager. Les clients commandent à la caisse en attendant.

## 4. Écran KDS cuisine plante

1. Toucher 4 coins de l'écran 2 sec → reboot logiciel (sans débrancher).
2. Si toujours bloqué : caissier passe en **mode papier** — imprime chaque commande à la caisse et la donne en main à la cuisine.
3. Prévenir le manager pour intervention technique.

## 5. Rapport Z (clôture journée) ne se ferme pas

- Le cron de récupération tourne automatiquement à **23:59** chaque soir et tente la clôture.
- Si la clôture du soir précédent n'est toujours pas faite le lendemain matin, ne PAS lancer le service avant correction — appeler le support technique.
- **Surtout ne pas** créer de nouvelle commande pour « débloquer » : NF525 verrouille la séquence fiscale tant que le Z précédent n'est pas signé.

## 6. Imprimante caisse / cuisine ne sort pas le ticket

1. Vérifier le câble USB + l'alimentation de l'imprimante.
2. Vérifier le bac à papier (pas vide, pas bourré).
3. Si OK : sur le POS, ouvrir la commande → bouton **Imprimer** (re-print). Si pas de sortie : changer d'imprimante (cuisine ↔ caisse) si possible et noter dans le cahier d'incidents.

## 7. Coupure de courant

- Onduleur (UPS) tient ~10 min pour POS et serveur.
- **Pendant ces 10 min** : ne plus accepter de paiement carte (le terminal SumUp se coupe), passer en espèces. Imprimer un ticket papier manuel si la coupure se prolonge.
- Au retour du courant : ne PAS éteindre brutalement le POS — il termine sa séquence de sauvegarde.

## Téléphones utiles

- Manager : _à remplir_
- Support technique : _à remplir_
- SumUp support : _à remplir depuis l'app SumUp_
- Fournisseur électricité : _à remplir_

---
Version 2026-05-28 · Le Cayenne · révision après J+7
