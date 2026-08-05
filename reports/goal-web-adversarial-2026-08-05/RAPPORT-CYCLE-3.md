# GOAL WEB — Cycle 3 : 5 agents adversariaux contre le code CORRIGÉ
**Date** : 2026-08-05 · **Web** : `lecayenne-web-deploy/Site lecayenne` → `ebf2e1b`
⚠️ **Le dépôt web est lui aussi modifié EN PARALLÈLE par une autre session** (travaux « 8 axes » :
crudités payantes, « Sans crudités »). Mes fichiers stagés ont été absorbés par ses commits —
contenu vérifié présent dans HEAD. Coordination owner requise (`PARALLEL_PROTOCOL.md`).

---

## 1. Ce que le cycle 3 a changé par rapport aux cycles 1 et 2

Les cycles précédents avaient surtout **lu du code**. Le cycle 3 a **exercé** : parcours d'achat
réels menés jusqu'à la commande (`#0508266107`, 10,80 €), inscription jouée de bout en bout avec
lecture du code OTP en base, séquences d'abandon rejouées, 206 captures produites dont 41 lues à
l'image. Résultat : **11 défauts que la seule lecture n'avait pas vus**, dont un de mes propres
correctifs qui était **du code mort**.

## 2. Le piège de méthode (à retenir)

Mon miroir de test local liait les fichiers par lien symbolique **sauf `index.html`, qui était une
copie**. Toutes mes modifications d'`index.html` n'étaient donc **pas servies** : je validais du
code que je n'avais pas modifié. Un agent l'a détecté seul, a lancé son propre serveur sur le vrai
dépôt et a refait ses verdicts dessus. Deux autres artefacts de méthode ont été écartés de la même
façon : les captures `fullPage` n'exécutent pas les révélations au scroll (bandes blanches
fantômes) et écrasent les modales `position: fixed` (fausse « modale coupée »).

> **Leçon** : un banc de test qui ne sert pas exactement le code audité produit des verdicts faux
> dans les deux sens. Vérifier l'identité octet à octet entre ce qui est servi et ce qui est lu.

## 3. Corrections livrées au cycle 3 (13)

| # | Sévérité | Défaut | Preuve |
|---|---|---|---|
| 1 | P1 | **Mon correctif du délai était mort** : `slotTime` initialisé à `'~12 min'`, donc toujours truthy → le repli « Dès que prêt » ne s'exécutait jamais. Le ticket promettait 12 min pour 30-35 min réels — **pire** que le « ~15-20 min » d'origine | mesuré sur 2 parcours |
| 2 | P1 | **Ticket + QR délivrés pour une commande ANNULÉE** : le `catch` fourre-tout traitait « Mollie injoignable » et « commande annulée (422) » à l'identique | séquence rejouée, DB à l'appui |
| 3 | P1 | **Un `?order=` étranger détruisait un paiement 3DS en cours** (purge avant contrôle d'appartenance) | rejoué |
| 4 | P1 | **« Se déconnecter » ne révoquait rien** : le token lisait encore profil, commandes et fidélité 30 jours après | prouvé au curl |
| 5 | P1 | **Toute erreur OTP maquillée en « Code incorrect »**, y compris un code JUSTE bloqué par le débit (429) | reproduit |
| 6 | BLOQUANT | **Modale compte inaccessible en 1366×768** : bouton hors écran, molette sans effet (`fixed`), focus piégé | mesuré aux 3 résolutions, avant/après |
| 7 | FORT | **L'upsell revendait une boisson déjà payée dans la formule** (la garde ignorait les options de ligne) | capture |
| 8 | FORT | Trois « prêt en ~10-15 min » figés en vitrine contre 30-35 min au paiement | mesuré |
| 9 | MOYEN | CTA d'upsell blanc sur orange = **3,12:1**, échec AA que le dépôt documente lui-même | mesuré |
| 10 | MOYEN | « Crée un compte pour cumuler tes points » **faux** : le compte est déjà créé par l'OTP | tracé backend |
| 11 | P2 | **Viande surplus** dont l'id a disparu : facturée +2,50 € à l'écran, absente du payload (seul chemin resté fail-silent) | reproduit |
| 12 | P2 | **Aucun timeout réseau** : une requête sans réponse verrouillait deux loquets non récupérables | analyse |
| 13 | P2/P3 | « Too Many Attempts. » en anglais (ma garde de locale ne couvrait pas ces mots) · drapeau anti-boucle jamais levé | reproduit |

## 4. Ce que les agents ont VALIDÉ (et qu'il ne faut pas casser)

- **Money-path SAIN** : 9/9 parités au centime, dont 7,40 + 0,90 + 2,50 = 10,80 € scellé ligne à
  ligne. Garde `expected_total` vivante et offensive (total forgé à 1,00 € → 422, 0 commande).
  Idempotence 3/3 (rejeu, changement de mode, 3 POST concurrents).
- **Mon verrou anti-double-clic fonctionne** : 4 clics dans le même tick → **1 seule** commande.
- **Le correctif « annuler l'inscription » tient sur les TROIS fermetures** (croix, Escape, clic
  sur le fond), vérifié en base — et le bug d'origine reproduit sur l'ancien code.
- **Aucun débordement horizontal réel** nulle part, à aucun viewport (test décisif : `scrollTo`
  puis relecture de `scrollX`).
- **Fidélité cohérente** sur les 6 surfaces (taux, seuil 100, non-expiration).
- **Aucun takeover de compte** : le code OTP part toujours à l'email lié au compte, jamais à
  celui fourni par l'appelant.

## 5. Reste ouvert

**Gate owner** — G-W5 (commande carte web diffusée avant paiement) · variations de sauces sur le
VPS · pré-commandes hors service · **verrou de paiement côté serveur** (la seule protection
anti-double-paiement est aujourd'hui du JavaScript client) · paliers de statut : le site et les
CGV annoncent 4 rangs `[0/500/1500/5000]`, l'API en publie 5 `[100/250/500/1000/2000]` que la
borne affiche — deux échelles pour un même client · afficher l'économie réelle du Menu complet
(1,30 €), l'agent a vérifié que la borne n'affiche aucune économie, donc aucune parité à rompre.

**P1/P2 non traités** — chemin `pending` : ticket + QR figés sans aucune sonde serveur · deux
onglets = deux commandes réelles (aucune synchronisation inter-onglets) · coupon promis à
l'écran puis refusé par le kill-switch backend au dernier clic (cul-de-sac de vente) · écran OTP
qui annonce un destinataire pouvant être faux ou inexistant · throttle OTP par IP (verrouillage
collectif derrière un NAT) · compte créé dès la 4ᵉ touche, sans possibilité d'annuler.

**Cosmétiques** — pastille d'avatar affichant les 2 derniers chiffres du téléphone (confondue avec
un compteur) · panier vide sans bouton · double croix dans la recherche · allergènes à 10px (info
de sécurité alimentaire) · pas de page 404 de marque · format de date « 03:58, 05-08-2026 ».

## 6. Verdict de convergence

**NON CONVERGÉ.** Le cycle 3 a produit 13 corrections : par définition, il n'est pas « zéro
P0/P1 ». Le critère exige deux cycles consécutifs propres — un cycle 4 est nécessaire.

**Tendance** : P0 = 0 sur les trois cycles. Le cœur argent et l'isolation des comptes résistent à
chaque passe ; ce qui tombe, ce sont les **promesses faites au client** (délais, tickets, points,
messages d'erreur) et les **chemins d'abandon**. C'est cohérent avec la plainte d'origine.
