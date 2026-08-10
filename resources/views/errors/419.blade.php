{{--
  « SESSION EXPIRÉE » — l'écran qu'on voit VRAIMENT au comptoir.

  [P1 2026-08-10 — audit E2E vague C] Jusqu'ici, la plateforme servait sa page d'usine : « 419 |
  PAGE EXPIRED », en anglais, fond blanc au milieu d'écrans noirs, aucun lien de retour. C'est l'erreur
  la PLUS probable en service : les écrans de la roue sont en HTML simple, la tablette reste allumée
  des heures au comptoir, le jeton de sécurité du formulaire finit par périmer, et l'équipe appuie sur
  VALIDER pour tomber là-dessus, devant le client.

  Trois choses à dire, dans cet ordre : ce n'est pas ta faute, RIEN n'a été enregistré, voilà le
  bouton pour repartir. Pas de nom technique : personne au comptoir ne sait ce qu'est un jeton CSRF.
  (L'écran de validation se recharge maintenant tout seul pour éviter d'arriver ici — voir
  admin/wheel/validation.blade.php.)
--}}
@php
    $retour = url()->previous();
    // Renvoyer vers l'adresse qu'on vient d'envoyer répondrait « méthode non autorisée » : une
    // impasse de plus. Dans ce cas, l'accueil.
    if (! is_string($retour) || $retour === '' || rtrim($retour, '/') === rtrim(request()->url(), '/')) {
        $retour = url('/');
    }
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Écran expiré — recharge-le</title>
<style>
  :root{--jaune:#FFB800;--noir:#141414;--creme:#FFF6EC}
  *{box-sizing:border-box}
  body{margin:0;background:var(--noir);color:var(--creme);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    min-height:100dvh;display:grid;place-items:center;padding:20px}
  .carte{width:100%;max-width:520px;background:#1E1A17;border:1px solid rgba(255,184,0,.3);
    border-radius:22px;padding:26px 22px;text-align:center}
  h1{margin:0 0 10px;font-size:20px;letter-spacing:.08em;text-transform:uppercase}
  p{font-size:15px;line-height:1.6;margin:0 0 14px}
  .rassure{background:rgba(255,184,0,.12);border:1px solid rgba(255,184,0,.42);border-radius:13px;
    padding:13px 15px;font-size:15px;line-height:1.5;margin-bottom:18px}
  .rassure b{color:var(--jaune)}
  a.bouton{display:flex;align-items:center;justify-content:center;min-height:66px;border-radius:16px;
    text-decoration:none;font-size:19px;font-weight:900;color:#2a1508;
    background:linear-gradient(100deg,var(--jaune),#FF6A3D);box-shadow:0 8px 22px rgba(244,80,30,.4)}
  a.secondaire{display:inline-flex;align-items:center;justify-content:center;min-height:44px;
    margin-top:14px;color:var(--jaune);font-size:15px;font-weight:700;text-decoration:none}
  .petit{font-size:13px;opacity:.7;line-height:1.5;margin:16px 0 0}
</style>
</head>
<body>
<main class="carte">
  <h1>L'écran a expiré</h1>
  <p class="rassure" role="alert">
    <b>Rien n'a été enregistré.</b> Cet écran est resté ouvert trop longtemps : il faut le recharger,
    puis refaire le geste. Ce n'est pas une panne.
  </p>
  <a class="bouton" href="{{ $retour }}">Recharger l'écran</a>
  {{-- Cette page sert à TOUTE l'application : le second lien reste neutre, et disparaît quand il
       mènerait au même endroit que le bouton. --}}
  @if (rtrim($retour, '/') !== rtrim(url('/'), '/'))
    <a class="secondaire" href="{{ url('/') }}">Aller à l'accueil →</a>
  @endif
  <p class="petit">
    Si ça se reproduit plusieurs fois de suite, ferme l'onglet et rouvre-le : la connexion de la
    tablette a été coupée.
  </p>
</main>
</body>
</html>
