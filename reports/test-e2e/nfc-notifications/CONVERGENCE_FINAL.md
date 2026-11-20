# test-e2e — convergence NFC · raccourci · notifications · messages (2026-11-20)

Équipe de capture (specs, PHPUnit, banc de la chaîne réelle) contre relecteurs adverses indépendants (lecture seule, preuve `fichier:ligne` + reproduction exigée).

| Tour | Code relu | Serveur | Site | Visuel | P0+P1 |
|---|---|---|---|---|---|
| 1 | tel que déployé le 2026-11-19 | 0 P0/P1 · 3 P2 · 6 P3 | 0 P0/P1 · 3 P2 · 4 P3 | 0 P0/P1 · 4 P2 · 5 P3 | **0** |
| 2 | messages réécrits + correctifs d'envoi | 0 P0/P1 · 2 P2 · 8 P3 | (couvert par le visuel) | 0 P0/P1 · 4 P2 · 3 P3 | **0** |
| 3 | code quasi final | 0 P0/P1 · 1 P2 · 5 P3 | (visuel + site réunis) 0 P0/P1 · 2 P2 · 3 P3 | | **0** |

Trois tours consécutifs à P0+P1 = 0, ensembles identiques (vides). Aucun P0/P1 n'a jamais survécu à la vérification `fichier:ligne`.

## Corrigé à la suite des tours (tous reproduits avant correction)
- **S-01** une panne d'OpenSSL supprimait les abonnements valides → sonde de création de clé avant l'envoi (aucune suppression, aucun envoi).
- **S-02 / S2-02** une urgence ou un TTL invalide dans l'environnement = 400 sur chaque appareil → valeurs validées (urgence ∈ 4 valeurs, TTL 60–86 400 sinon 900) ; 413 jamais compté contre l'appareil.
- **S-03** « N points gagnés » dit à quelqu'un qui n'a pas reçu les points (code fidélité d'un autre compte) → annoncé SEULEMENT si le grand livre `loyalty_transactions` le confirme au destinataire.
- **S2-01** l'urgence « normale » sur une annulation reposait sur une prémisse fausse (l'en-tête règle la livraison, pas le son) → « high » partout sauf la remise.
- **S3-01** cache injoignable → l'écouteur remontait une exception à la caisse et coupait la chaîne des écouteurs → `Cache::forget` gardé (test qui échoue sans le correctif).
- **V-01** bandeau d'installation par-dessus le menu hamburger · **V-02** message d'échec gris au lieu de rouge · **V-03** impasse « Installez d'abord… » sous Instagram · **V-05/V-09/W-05** boutons non centrés, « prévenu(e) », promesse plus large que ce que le serveur envoie · **V2-01** hub Profil « Commande prête : activée » · **V2-02** promesse de points « à part » · **V2-03** chemin de déblocage propre à iPhone · **V2-04 / V3-02** césures (« Le / Cayenne », « ? » ou « › » orphelins) · **V3-01** résumé du hub lu trop tôt.
- **Textes** : tous réécrits (titre = état, corps = suite ; « 1 point » ; espaces insécables ; aucune formule genrée ; aucune promesse invérifiable ; « le livreur est parti », pas « arrive »).

## Preuves finales
- PHPUnit `tests/Feature/Push` : 80 tests, 290 assertions · sentinelles `tests/Feature/Sentinels` : 373 tests verts (3 ignorés d'origine).
- Chaîne réelle (`tools/banc-web-push/chaine-push.cjs`) : vraie commande → file → requête Web Push chiffrée → déchiffrée par le banc : **22/22**, dont textes exacts, points, urgence « normale » à la remise, « validée » abandonnée si la cuisine est plus rapide.
- Site : `installation-nfc` 42 · `notifications-abonnement` 49 · `sw-push-et-mise-a-jour` 15 · `suivi-sans-rafraichir` 17 ; `compile-jsx --check` et `check-asset-versions --check` verts.
- Chaîne fiscale : CHAIN OK sur les 7 branches · zones gelées : 0 ligne.
- Un banc ajouté n'est pas cru sur parole : le test du cache et celui des textes échouent sans leur correctif.

## Limites assumées (P2/P3 NON corrigés, à connaître)
- Réseau coupé pendant « Oui, m'alerter » : la permission est consommée, la carte disparaît sans message (W-02). Clé VAPID changée côté serveur non détectée côté appareil (W-01) — les clés ne changent plus jamais.
- Clic sur une notification alors qu'un tiroir/fiche est ouvert : le premier appui ferme la modale (W-03).
- À l'arrivée par la carte NFC, le bandeau peut recouvrir le bouton « COMMANDER » du hero (V-04) ; piste d'interrupteur désactivé à 1,51:1 (composant partagé, V-06).
- `localStorage` indisponible : l'origine `?src=nfc` est perdue (W-04). `notificationclick` n'est exécuté par aucun banc (W-06) : épreuve appareil.
- TTL de 900 s : « prête » est abandonnée si le téléphone reste éteint plus de 15 min ; une étape sautée par un employé n'envoie pas « prête » (S2-03).
- Course théorique entre deux workers (S-04) ; file `default` (S-05) ; abonnement non lié à la session (S-08) ; bcmath (S-09).
- **Non prouvé sans appareil réel** : l'abonnement d'un iPhone installé, l'option « Sur l'écran d'accueil » dans la feuille de partage, les zones sûres iPhone, le rendu des notifications sur l'écran de verrouillage. Le dernier correctif (chevron, hub, texte Android) n'a pas eu de 4e tour adverse : il est couvert par les 4 specs et un test qui échoue sans lui.
