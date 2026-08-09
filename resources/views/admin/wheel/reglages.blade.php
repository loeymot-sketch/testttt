{{--
  RÉGLAGES DE LA ROUE — l'écran qui débloque le jeu.

  Les trois adresses (avis Google, Instagram, Snapchat) sont les COMPTES du propriétaire : personne
  d'autre ne peut les fournir. Tant qu'elles vivaient dans des variables d'environnement, le jeu
  restait à attendre que quelqu'un les pose sur le serveur. Ici, il les colle lui-même en dix
  secondes, depuis sa tablette, et le parcours s'active immédiatement.

  L'écran DIT L'ÉTAT en haut : « le parcours tourne » ou « il ne tourne pas encore, et voici
  pourquoi ». Un réglage dont on ne voit pas l'effet est un réglage qu'on ne touche pas.
--}}
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Roue — réglages</title>
<style>
  :root{--orange:#F4501E;--jaune:#FFB800;--noir:#141414;--creme:#FFF6EC;--ok:#1DB954;--rouge:#D93025}
  *{box-sizing:border-box}
  body{margin:0;background:var(--noir);color:var(--creme);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    min-height:100dvh;display:grid;place-items:start center;padding:20px}
  .carte{width:100%;max-width:640px;background:#1E1A17;border:1px solid rgba(255,184,0,.3);
    border-radius:22px;padding:24px;margin-top:12px}
  h1{margin:0 0 4px;font-size:19px;letter-spacing:.1em;text-transform:uppercase}
  .sous{opacity:.75;font-size:14px;line-height:1.55;margin:0 0 18px}
  .etat{border-radius:14px;padding:14px 16px;font-size:15px;line-height:1.5;margin-bottom:20px}
  .etat.on{background:rgba(29,185,84,.14);border:1px solid rgba(29,185,84,.55)}
  .etat.off{background:rgba(255,184,0,.12);border:1px solid rgba(255,184,0,.5)}
  .etat b{display:block;margin-bottom:4px;font-size:16px}
  label{display:block;font-size:12px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;
    opacity:.72;margin:16px 0 6px}
  input[type=text],input[type=url],input[type=number]{width:100%;min-height:52px;border-radius:13px;
    padding:0 14px;font-size:16px;background:#fff;color:#1a1a1a;border:2px solid transparent}
  input:focus{outline:none;border-color:var(--jaune)}
  .aide{font-size:12.5px;opacity:.65;margin:6px 0 0;line-height:1.5}
  .aide code{background:rgba(255,255,255,.10);padding:1px 5px;border-radius:5px;font-size:12px}
  .duo{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .bascule{display:flex;align-items:center;gap:10px;margin-top:12px;font-size:15px}
  .bascule input{width:22px;height:22px;accent-color:var(--jaune)}
  button{width:100%;min-height:60px;border:0;border-radius:15px;cursor:pointer;margin-top:22px;
    font-size:17px;font-weight:900;color:#2a1508;background:linear-gradient(100deg,var(--jaune),#FF6A3D)}
  .msg{margin:0 0 16px;padding:13px 15px;border-radius:13px;font-size:15px}
  .msg.ok{background:rgba(29,185,84,.14);border:1px solid rgba(29,185,84,.55)}
  .liens{margin-top:22px;border-top:1px solid rgba(255,255,255,.12);padding-top:16px;font-size:14px}
  .liens a{display:block;color:var(--jaune);text-decoration:none;font-weight:700;padding:5px 0}
</style>
</head>
<body>
<div class="carte">
  <h1>Réglages de la roue</h1>
  <p class="sous">Colle tes liens ici. Le parcours s'active dès qu'au moins un lien est renseigné.</p>

  @if (! empty($enregistre))
    <p class="msg ok">Réglages enregistrés.</p>
  @endif

  @if ($pret)
    <div class="etat on">
      <b>Le parcours tourne.</b>
      Les étapes cochées sont exigées : le client doit ouvrir le lien, y passer le temps prévu, et
      revenir. Le serveur vérifie le temps lui-même — le compteur du téléphone n'est qu'un affichage.
    </div>
  @else
    <div class="etat off">
      <b>Le parcours ne tourne pas encore.</b>
      Aucun lien n'est renseigné : il n'y a donc rien à ouvrir ni à chronométrer, et les étapes sont
      sautées. Colle au moins un lien ci-dessous et ce sera actif immédiatement.
    </div>
  @endif

  <form method="POST" action="{{ url('/admin/roue-reglages') }}">
    @csrf

    <label for="review_url">Lien pour laisser un avis Google</label>
    <input id="review_url" name="review_url" type="url" placeholder="https://g.page/r/…/review"
           value="{{ $s['review_url'] ?? '' }}">
    <p class="aide">
      Sur ta fiche Google : <b>Demander des avis</b> → copie le lien court. Il ressemble à
      <code>https://g.page/r/CXXXXXXXX/review</code>.
    </p>
    <div class="bascule">
      <input type="checkbox" id="review_required" name="review_required" value="1"
             {{ ($s['review_required'] ?? '0') === '1' ? 'checked' : '' }}>
      <label for="review_required" style="margin:0;text-transform:none;letter-spacing:0;font-size:15px;opacity:1">
        Obligatoire pour tourner
      </label>
    </div>

    <label for="instagram_url">Ton Instagram</label>
    <input id="instagram_url" name="instagram_url" type="url" placeholder="https://instagram.com/…"
           value="{{ $s['instagram_url'] ?? '' }}">

    <label for="snapchat_url">Ton Snapchat</label>
    <input id="snapchat_url" name="snapchat_url" type="url" placeholder="https://snapchat.com/add/…"
           value="{{ $s['snapchat_url'] ?? '' }}">
    <div class="bascule">
      <input type="checkbox" id="follow_required" name="follow_required" value="1"
             {{ ($s['follow_required'] ?? '0') === '1' ? 'checked' : '' }}>
      <label for="follow_required" style="margin:0;text-transform:none;letter-spacing:0;font-size:15px;opacity:1">
        Obligatoire pour tourner
      </label>
    </div>

    <div class="duo">
      <div>
        <label for="review_dwell">Temps avis (secondes)</label>
        <input id="review_dwell" name="review_dwell" type="number" min="0" max="180"
               value="{{ $s['review_dwell'] ?? 20 }}">
      </div>
      <div>
        <label for="follow_dwell">Temps abonnement (s)</label>
        <input id="follow_dwell" name="follow_dwell" type="number" min="0" max="180"
               value="{{ $s['follow_dwell'] ?? 8 }}">
      </div>
    </div>
    <p class="aide">
      Le temps que le client doit passer avant que le bouton se débloque. 20 s pour un avis : assez
      pour écrire une phrase, trop court pour être vécu comme une attente. C'est le SERVEUR qui
      compte — impossible de tricher depuis le téléphone.
    </p>

    <label for="min_order">Minimum de commande pour utiliser le lot (€)</label>
    <input id="min_order" name="min_order" type="number" min="0" step="0.5"
           value="{{ $s['min_order'] ?? 10 }}">
    <p class="aide">Annoncé au client avant qu'il joue, redit sur son lot. Jamais découvert en caisse.</p>

    <button type="submit">Enregistrer</button>
  </form>

  <div class="liens">
    <a href="{{ url('/admin/roue-borne') }}">→ Écran de la tablette (à afficher au comptoir)</a>
    <a href="{{ url('/admin/roue-lot') }}">→ Remettre un lot gagné</a>
    <a href="{{ url('/admin/roue-validation') }}">→ Valider un tour à la main</a>
  </div>
</div>
</body>
</html>
