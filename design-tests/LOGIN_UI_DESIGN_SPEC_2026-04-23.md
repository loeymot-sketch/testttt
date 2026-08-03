# Test de design — écran de connexion (SaaS / FoodKing)

> **Livrable interactif (Cursor) :** ouvrir la Canvas `login-screen-design-test.canvas.tsx` (panneau Canvas).  
> **L’appel API gpt-5.4 / pro (mission `missions/LOGIN-UI-001/`) a pu échouer** si le proxy ne renvoie pas de contenu d’assistant — c’est côté fournisseur, pas côté maquette.

## Pattern

**Split panneau** : colonne identité (marque / périphérique) + zone formulaire centrée, largeur max 400px.

## Zones d’écran

| Zone        | Rôle |
|------------|------|
| Bandeau gauche | Titre produit, phrase de confiance, contrainte métier (iso données). |
| Zone principale  | Titre “Connexion”, descriptif court, carte compte, liens secondaires, note a11y. |
| Sous-CTA | Rappel légal / support / conformité (lien texte, pas bannière graphique lourde). |

## Champs & actions

- E-mail (type `email`, placeholder explicite).
- Mot de passe (type `password`).
- “Mémoriser l’ouverture” = préférence d’**usage** (wording clair, pas de promesse côté client seul).
- CTA **Se connecter** (plein largeur dans le formulaire, une action primaire seulement).
- Liens : *Mot de passe oublié* · *Aide / conformité* (texte, `href` côté app réel).

## A11y (mini-critères)

1. **Ordre de tab** : e-mail → mot de passe → case option → CTA → liens.
2. **Libellés** : texte visible pour chaque champ, pas d’e-mail seul en placeholder.
3. **Focus** : style visible fourni par le thème (Canvas : tokens IDE).

## Risques UI à éviter

- Demander bannière/gradient/marketing lourd sur l’écran (politique projet : app plat, tokens, pas ombre inutile).
- Champs cachés ou intitulés ambigus pour “compte multi-marque / branche” (risque d’erreur d’ouverture de session).
