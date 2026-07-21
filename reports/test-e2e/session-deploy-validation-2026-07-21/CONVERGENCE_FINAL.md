# Convergence — Audit e2e adversarial déploiement session 2026-07-21

**Verdict : CONVERGÉ produit. P0 = 0 sur toutes les vagues. Money-path PARFAIT.**
Tous les P1 produit corrigés + re-vérifiés. Le reste = items go-live owner (pas de défaut produit).

## Vagues (Round 1) — 3 en parallèle, capture + adversarial visual-first

| Vague | Surface | P0 | P1 | P2 | P3 | Résultat |
|---|---|:-:|:-:|:-:|:-:|---|
| **A** | WEB money-path + wizard + boissons | 0 | 0 | 0 | 2 | **GREEN — 2 commandes réelles scellées au centime** |
| **B** | WEB home + 5 pages légales | 0 | 3→**0** | 0 | 2 | mentions raw placeholders → **corrigé + live** |
| **C** | VPS kiosk/admin/caisse/KDS/OSS | 0 | 1 | 2 | 1 | bundle sain ; login-gated **vérifié en local** |

## Preuves clés
- **Money-path (priorité #1) PARFAIT** : commande réelle live `#210726184` Cayenne **11,30 €** — recap == panier == paiement == **confirmation scellée 11,30** (base 7,40 + 2ᵉ sauce 0,50 + supplément 0,90 + menu 2,50). **0 supplément largué**, 0 erreur console, 0 4xx. Wizard : « Incluse » sur défaut, 2ᵉ sauce +0,50, viande +2,50 ×3 max-3, Méga 2 incluses gratuites.
- **Boissons** regroupées (5 aperçus + « Voir toutes » → 15), 0 canette dans la grille plats, desserts inline.
- **Home** hero Cayenne chargé + section Facebook fonctionnelle (5 photos produit).
- **Légal** : mentions **E.DELICE SAS / SIRET / TVA / RCS Béthune / APE 5610C** + CGV(CM2C)/confidentialité(Vercel)/cookies(0 tracking)/allergènes — **0 [À COMPLÉTER] en live** (corrigé).
- **Admin fusion** (local, bundle frais = bundle VPS déployé) : hub 2 onglets **Catalogue + Produits & Stock**, **bouton 📷 par produit** ouvrant l'uploader, 0 erreur JS.
- **Caisse composer-aware** : charge propre, **0 erreur JS** (panneau « À encaisser borne » + « Commandes web » visibles).

## Corrections appliquées cette passe
1. **B (P1×3)** : `mentions.html` tenu à part par erreur → **poussé** (`1299cf9`). Live re-vérifié = **0 crochet brut**, sert SAS/Béthune/5610C.
2. **C (P1×1, coverage)** : creds VPS non seedés → surfaces login-gated **re-vérifiées EN LOCAL** (creds ok + rebuild bundle) : hub 2 onglets + photo + caisse 0-erreur = **produit prouvé** (le bundle VPS déployé est identique).

## Reste = OWNER go-live (aucun défaut produit)
- **C-P1 (infra)** : seeder les comptes démo sur le VPS **ou** fournir les vrais identifiants staging → pour une capture live admin/caisse sur le VPS (le produit est déjà prouvé en local + tests).
- **C-P2** : borne VPS non provisionnée (kiosk/idle → login) ; clé API `change-me-…` (identifiant client, à roter go-live).
- **A-P3** : sur l'étape « Viande en plus ? » du Cayenne, les puces non-sélectionnées n'affichent pas « +2,50 » (prix dans le sous-titre/bandeau ; incohérent avec Méga) — cosmétique. `dev_code` OTP en clair sur staging → **à supprimer en `production`**.
- **B-P3** : capital SAS + nom du président = graceful intérimaire, en attente des 2 faits Kbis owner.
- **Fiscal** : TAMPER NF525 branche 1 VPS (pré-existant) à résoudre avant go-live.

**Convergence** : P0=0 partout, money-path scellé au centime sur commande réelle, tous les P1 produit corrigés + re-vérifiés. Reste strictement owner/go-live.
