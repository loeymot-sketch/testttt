# Le Dictionnaire des Erreurs API (Comment y réagir)

Lorsque le Kiosk Flutter (ou tout autre front) interagit avec le Backend FoodKing, il doit parser la dimension HTTP de la réponse. Voici la norme contractuelle du système.

---

## 🚫 401 Unauthorized (Erreur de Token)

Le client tente d'accéder à l'API Sans Token `Bearer`, avec un Token expiré, ou avec un Token falsifié.

- **Exemple Backend :** Token supprimé par un Manager depuis le dashboard.
- **Réaction exigée par l'UX Kiosk :** Le Kiosk ne doit pas crasher. Il doit clear son Cache local, afficher un écran "Borne Déconnectée" et inviter le Staff à re-saisir le mot de passe Kiosk via la modale secrète.

## 🧱 403 Forbidden (Erreur d'Ability / Scope)

Le Token est parfaitement valide (L'API sait qui vous êtes), mais vous n'avez pas le *droit* de frapper cette porte (Vous n'êtes pas un Admin, ou pas de la bonne succursale).

- **Exemple Backend :** Un token Kiosk (ability: `kiosk:order`) accède aux Stats Administrateur (`/api/admin/dashboard`).
- **Réaction exigée par l'UX Kiosk :** Ce cas ne devrait jamais arriver s'il n'y a pas de bug front-end appelant les mauvaises URL. Ignorer silencieusement pour le client, logger l'erreur sur Firebase Crashlytics.

## 🛑 422 Unprocessable Entity (Erreur de Formulaire / Validation)

La requête HTTP est passée (Auth OK, Droits OK), mais les données du payload JSON sont invalides, incomplètes ou illogiques. Laravel renvoie des messages de validation.

- **Exemple Backend :** Création de commande sans `items` dans le tableau, ou utilisation d'un code Coupon qui a expiré.
- **Payload retourné typique :**
```json
{
    "message": "The given data was invalid.",
    "errors": {
        "coupon_code": ["This coupon has expired or is invalid for your branch."]
    }
}
```
- **Réaction exigée par l'UX Kiosk :** Garder le client sur son écran actuel. Afficher une Toast / Snackbar rouge avec le message précis ("Code Promo invalide").

## 💣 400 Bad Request / 500 Server Error (Crash Logique / Physique)

- **400 :** Requête globalement incomprise ou contenant une violation de règle métier très grave (Tentative de saut de statut KDS interdit).
- **500 :** Le serveur de BDD est mort, une migration a cassé, Laravel Crash.
- **Réaction exigée par l'UX Kiosk :** Afficher un écran d'erreur générique : *"Une erreur système est survenue. Veuillez appeler un équipier."* La borne ne doit pas bloquer la file d'attente indéfiniment.

## 🔒 405 Method Not Allowed (Sécurité en Écriture)

Vous tentez un POST sur une route qui n'est designée qu'en GET.
- **Exemple Backend :** L'écran de salle de commande (OSS) qui essaye de supprimer ou passer à "Delivered" un Order Status.
- **Réaction :** Crash de dev Front-End. Refactorer le Front. L'API est verrouillée (`OSSReadOnlyTest` valide ça).
