{{--
  RÉGLAGES DE LA ROUE — l'écran qui débloque le jeu.

  Les liens du parcours (avis Google, Instagram, Snapchat, Facebook) sont les COMPTES du propriétaire :
  personne d'autre ne peut les fournir. Tant qu'ils vivaient dans des variables d'environnement, le jeu
  restait à attendre que quelqu'un les pose sur le serveur. Ici, il les colle lui-même en dix secondes,
  depuis sa tablette, et le parcours s'active immédiatement.

  L'écran DIT L'ÉTAT en haut, ligne par ligne. Deux corrections du 2026-08-10 tiennent à ça :
    · la bannière verte « le parcours tourne » s'affichait au-dessus de champs TOUS VIDES (elle
      regardait le lien de secours et l'adresse livrée par défaut) — elle nomme maintenant, champ par
      champ, ce qui vient du patron et ce qui n'en vient pas ;
    · une saisie refusée par le serveur disparaissait sans un mot — les erreurs sont affichées, et ce
      qui a été tapé est réaffiché.
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
  /* Le bilan ligne par ligne : le patron doit pouvoir lire « ce champ-là est à moi, celui-là non »
     sans interpréter une couleur. D'où une puce TEXTE en plus de la teinte. */
  .bilan{list-style:none;margin:12px 0 0;padding:0;font-size:14.5px}
  .bilan li{display:flex;gap:8px;align-items:baseline;padding:3px 0;line-height:1.45}
  .bilan .puce{flex:0 0 16px;font-weight:900;text-align:center}
  .bilan li.saisi .puce{color:#7BE495}
  .bilan li.secours .puce,.bilan li.defaut .puce{color:var(--jaune)}
  .bilan li.absent .puce,.bilan li.retire .puce{opacity:.55}
  label{display:block;font-size:12px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;
    opacity:.72;margin:16px 0 6px}
  input[type=text],input[type=url],input[type=number]{width:100%;min-height:52px;border-radius:13px;
    padding:0 14px;font-size:16px;background:#fff;color:#1a1a1a;border:2px solid transparent}
  /* Le remplacement de l'anneau de focus par une bordure ne marche QUE sur les champs texte : une
     case à cocher native ne peint pas de bordure CSS, elle perdait donc son repère sans rien en
     échange. Les cases gardent un anneau, bien visible. */
  input[type=text]:focus,input[type=url]:focus,input[type=number]:focus{outline:none;border-color:var(--jaune)}
  input[type=checkbox]:focus-visible{outline:3px solid var(--jaune);outline-offset:3px}
  input.faux{border-color:var(--rouge)}
  .aide{font-size:12.5px;opacity:.65;margin:6px 0 0;line-height:1.5}
  .aide code{background:rgba(255,255,255,.10);padding:1px 5px;border-radius:5px;font-size:12px}
  /* L'avertissement « lien de secours » vivait dans le plus petit texte de la page, sous un champ
     vide : rien ne reliait « ce champ est vide » à « on utilise donc un repli ». C'est un encart. */
  .note{margin:8px 0 0;padding:11px 13px;border-radius:12px;font-size:15px;line-height:1.5;
    background:rgba(255,184,0,.13);border:1px solid rgba(255,184,0,.45)}
  .duo{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  /* Tablette tenue à la main : toute la ligne est une cible, hauteur 44 px minimum, case comprise. */
  .bascule{display:flex;align-items:center;gap:12px;margin-top:12px;font-size:15px;min-height:44px}
  .bascule input{width:44px;height:44px;flex:0 0 44px;accent-color:var(--jaune);margin:0}
  .bascule label{margin:0;text-transform:none;letter-spacing:0;font-size:15px;opacity:1;
    display:flex;align-items:center;min-height:44px;flex:1;cursor:pointer}
  button{width:100%;min-height:60px;border:0;border-radius:15px;cursor:pointer;margin-top:22px;
    font-size:17px;font-weight:900;color:#2a1508;background:linear-gradient(100deg,var(--jaune),#FF6A3D)}
  .msg{margin:0 0 16px;padding:13px 15px;border-radius:13px;font-size:15px;line-height:1.5}
  .msg.ok{background:rgba(29,185,84,.14);border:1px solid rgba(29,185,84,.55)}
  .msg.err{background:rgba(217,48,37,.18);border:1px solid rgba(217,48,37,.6)}
  .msg.err b{display:block;margin-bottom:6px;font-size:16px}
  .msg.err ul{margin:0;padding-left:20px}
  .msg.err li{padding:2px 0}
  .champ-err{margin:6px 0 0;font-size:14px;font-weight:700;color:#FFB4AC;line-height:1.45}
  .liens{margin-top:22px;border-top:1px solid rgba(255,255,255,.12);padding-top:16px;font-size:14px}
  .liens a{display:flex;align-items:center;min-height:44px;color:var(--jaune);text-decoration:none;font-weight:700}

  /* ── LES CADEAUX ─────────────────────────────────────────────────────────────────────────
     Un tableau, parce que la question est « lequel, combien de chances, combien il en reste » —
     trois colonnes qu'on compare d'un regard. Trois blocs empilés obligeraient à retenir des
     chiffres d'une ligne à l'autre.
     Le tableau DÉFILE horizontalement sur un téléphone plutôt que de comprimer les champs à une
     largeur où le doigt ne les atteint plus. */
  .lots-titre{margin:30px 0 4px;font-size:17px;font-weight:900;letter-spacing:.02em}
  .lots-intro{margin-bottom:12px}
  .lots-enveloppe{overflow-x:auto;-webkit-overflow-scrolling:touch}
  table.lots{width:100%;border-collapse:collapse;font-size:15px;min-width:520px}
  table.lots th,table.lots td{padding:9px 8px;text-align:left;vertical-align:middle;
    border-bottom:1px solid rgba(255,255,255,.10)}
  table.lots thead th{font-size:11px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;
    opacity:.62;border-bottom:1px solid rgba(255,255,255,.2)}
  table.lots tbody th{font-weight:800;white-space:nowrap}
  /* 44 px de haut : la même cible tactile que le reste de l'écran, tenu à une main au comptoir. */
  table.lots input{width:86px;min-height:44px;padding:0 10px;margin:0;font-size:16px;text-align:center}
  table.lots td.chance{font-variant-numeric:tabular-nums;font-weight:800;white-space:nowrap}
  table.lots td.restant{font-variant-numeric:tabular-nums;white-space:nowrap;opacity:.85}
  table.lots td.restant .sur{opacity:.55}
  table.lots tr.epuise tbody th,table.lots tr.epuise td{opacity:.55}
  .etiq{display:inline-block;margin-left:7px;padding:2px 7px;border-radius:7px;
    font-size:11px;font-weight:900;letter-spacing:.06em;text-transform:uppercase;vertical-align:middle}
  .etiq-vitrine{background:rgba(255,184,0,.18);border:1px solid rgba(255,184,0,.5);color:var(--jaune)}
  .etiq-epuise{background:rgba(217,48,37,.18);border:1px solid rgba(217,48,37,.55);color:#FFB4AC}
</style>
</head>
<body>
<main class="carte">
  <h1>Réglages de la roue</h1>
  <p class="sous">Colle tes liens ici. Le parcours s'active dès qu'au moins un lien est renseigné.</p>

  @if (! empty($enregistre))
    <p class="msg ok" role="status">Réglages enregistrés.</p>
  @endif

  {{-- Un refus du serveur DOIT se voir : sans ce bloc, la page revenait à l'identique et le patron
       corrigeait à l'aveugle. --}}
  @if ($errors->any())
    <div class="msg err" role="alert">
      <b>Rien n'a été enregistré.</b>
      <ul>
        @foreach ($errors->all() as $erreur)
          <li>{{ $erreur }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  @php
    // La puce est décorative : le texte de chaque ligne dit déjà l'état, donc elle est masquée à la
    // synthèse vocale.
    $puce = static fn (string $etat) => $etat === 'saisi'
      ? '✓'
      : (in_array($etat, ['absent', 'retire'], true) ? '–' : '!');
  @endphp

  @if (! $pret)
    <div class="etat off" role="status">
      <b>Le parcours ne tourne pas encore.</b>
      Aucun lien n'est renseigné : il n'y a donc rien à ouvrir ni à chronométrer, et les étapes sont
      sautées. Colle au moins un lien ci-dessous et ce sera actif immédiatement.
    </div>
  @elseif (! $aMoi)
    <div class="etat off" role="status">
      <b>Aucun lien n'est de TOI pour l'instant.</b>
      Le jeu tourne quand même, avec un lien de secours ou une adresse livrée par défaut — mais le
      client n'arrive pas forcément sur tes comptes. Voici ce qui est réglé, ligne par ligne :
      <ul class="bilan">
        @foreach ($etatLiens as $l)
          <li class="{{ $l['etat'] }}"><span class="puce" aria-hidden="true">{{ $puce($l['etat']) }}</span>
            <span><b style="display:inline">{{ $l['nom'] }}</b> : {{ $l['dit'] }}</span></li>
        @endforeach
      </ul>
    </div>
  @else
    <div class="etat on" role="status">
      <b>Le parcours tourne.</b>
      Voici ce qui est réglé, ligne par ligne :
      <ul class="bilan">
        @foreach ($etatLiens as $l)
          <li class="{{ $l['etat'] }}"><span class="puce" aria-hidden="true">{{ $puce($l['etat']) }}</span>
            <span><b style="display:inline">{{ $l['nom'] }}</b> : {{ $l['dit'] }}</span></li>
        @endforeach
      </ul>
      Les étapes cochées sont exigées : le client doit ouvrir le lien, y passer le temps prévu, et
      revenir. Le serveur vérifie le temps lui-même — le compteur du téléphone n'est qu'un affichage.
    </div>
  @endif

  <form method="POST" action="{{ url('/admin/roue-reglages') }}">
    @csrf

    <label for="review_url">Lien pour laisser un avis Google</label>
    <input id="review_url" name="review_url" type="url" placeholder="https://g.page/r/…/review"
           class="{{ $errors->has('review_url') ? 'faux' : '' }}"
           @if ($errors->has('review_url')) aria-invalid="true" aria-describedby="err_review_url" @endif
           value="{{ old('review_url', $s['review_url'] ?? '') }}">
    @error('review_url')<p class="champ-err" id="err_review_url" role="alert">{{ $message }}</p>@enderror
    @if (! empty($reviewDerive))
      <p class="note">
        <b>Ce n'est pas encore ton vrai lien d'avis.</b>
        Pour l'instant on ouvre ta fiche Google et le client doit encore appuyer sur « Écrire un
        avis ». Colle ton lien ici : un appui de moins pour lui, donc plus d'avis pour toi.
      </p>
    @endif
    <p class="aide">
      Sur ta fiche Google : <b>Demander des avis</b> → copie le lien court. Il ressemble à
      <code>https://g.page/r/CXXXXXXXX/review</code> et ouvre le formulaire d'un coup.
    </p>
    <div class="bascule">
      {{-- Le champ caché envoie « 0 » quand la case est décochée : sans lui, le navigateur n'envoie
           RIEN et une saisie refusée réafficherait la case cochée à tort. --}}
      <input type="hidden" name="review_required" value="0">
      <input type="checkbox" id="review_required" name="review_required" value="1"
             {{ (string) old('review_required', $s['review_required'] ?? '0') === '1' ? 'checked' : '' }}>
      <label for="review_required">Obligatoire pour tourner</label>
    </div>

    <label for="instagram_url">Ton Instagram</label>
    <input id="instagram_url" name="instagram_url" type="url" placeholder="https://instagram.com/…"
           class="{{ $errors->has('instagram_url') ? 'faux' : '' }}"
           @if ($errors->has('instagram_url')) aria-invalid="true" aria-describedby="err_instagram_url" @endif
           value="{{ old('instagram_url', $s['instagram_url'] ?? '') }}">
    @error('instagram_url')<p class="champ-err" id="err_instagram_url" role="alert">{{ $message }}</p>@enderror

    <label for="snapchat_url">Ton Snapchat</label>
    <input id="snapchat_url" name="snapchat_url" type="url" placeholder="https://snapchat.com/add/…"
           class="{{ $errors->has('snapchat_url') ? 'faux' : '' }}"
           @if ($errors->has('snapchat_url')) aria-invalid="true" aria-describedby="err_snapchat_url" @endif
           value="{{ old('snapchat_url', $s['snapchat_url'] ?? '') }}">
    @error('snapchat_url')<p class="champ-err" id="err_snapchat_url" role="alert">{{ $message }}</p>@enderror

    <label for="facebook_url">Ta page Facebook</label>
    <input id="facebook_url" name="facebook_url" type="url" placeholder="https://facebook.com/…"
           class="{{ $errors->has('facebook_url') ? 'faux' : '' }}"
           @if ($errors->has('facebook_url')) aria-invalid="true" aria-describedby="err_facebook_url" @endif
           value="{{ old('facebook_url', $s['facebook_url'] ?? '') }}">
    @error('facebook_url')<p class="champ-err" id="err_facebook_url" role="alert">{{ $message }}</p>@enderror
    <p class="aide">
      Un seul réseau renseigné suffit pour que l'étape fonctionne. Mets-en autant que tu veux. Vide un
      champ et enregistre pour retirer un compte : il ne sera plus proposé au client.
    </p>
    <div class="bascule">
      <input type="hidden" name="follow_required" value="0">
      <input type="checkbox" id="follow_required" name="follow_required" value="1"
             {{ (string) old('follow_required', $s['follow_required'] ?? '0') === '1' ? 'checked' : '' }}>
      <label for="follow_required">Obligatoire pour tourner</label>
    </div>

    <div class="duo">
      <div>
        <label for="review_dwell">Temps sur l'avis (s)</label>
        <input id="review_dwell" name="review_dwell" type="number" min="0" max="180"
               class="{{ $errors->has('review_dwell') ? 'faux' : '' }}"
               @if ($errors->has('review_dwell')) aria-invalid="true" aria-describedby="err_review_dwell" @endif
               value="{{ old('review_dwell', $s['review_dwell'] ?? 20) }}">
        @error('review_dwell')<p class="champ-err" id="err_review_dwell" role="alert">{{ $message }}</p>@enderror
      </div>
      <div>
        <label for="follow_dwell">Temps sur l'abonnement (s)</label>
        <input id="follow_dwell" name="follow_dwell" type="number" min="0" max="180"
               class="{{ $errors->has('follow_dwell') ? 'faux' : '' }}"
               @if ($errors->has('follow_dwell')) aria-invalid="true" aria-describedby="err_follow_dwell" @endif
               value="{{ old('follow_dwell', $s['follow_dwell'] ?? 8) }}">
        @error('follow_dwell')<p class="champ-err" id="err_follow_dwell" role="alert">{{ $message }}</p>@enderror
      </div>
    </div>
    <p class="aide">
      Le temps que le client doit passer avant que le bouton se débloque, <b>de 0 à 180 secondes</b>.
      20 s pour un avis : assez pour écrire une phrase, trop court pour être vécu comme une attente.
      C'est le SERVEUR qui compte — impossible de tricher depuis le téléphone.
    </p>

    <label for="min_order">Minimum de commande pour utiliser le lot (€)</label>
    <input id="min_order" name="min_order" type="number" min="0" max="200" step="0.5"
           class="{{ $errors->has('min_order') ? 'faux' : '' }}"
           @if ($errors->has('min_order')) aria-invalid="true" aria-describedby="err_min_order" @endif
           value="{{ old('min_order', $s['min_order'] ?? 10) }}">
    @error('min_order')<p class="champ-err" id="err_min_order" role="alert">{{ $message }}</p>@enderror
    <p class="aide">
      <b>De 0 à 200 €.</b> Annoncé au client avant qu'il joue, redit sur son lot. Jamais découvert en caisse.
    </p>

    {{--
      ── LES CADEAUX : PROBABILITÉ ET NOMBRE ────────────────────────────────────────────────
      [2026-08-12 · propriétaire : « je veux permettre de faire la probabilité et le nombre de
      cadeaux que je veux faire gagner aux gens — 50 tiramisu, 50 boissons, 10 sandwiches, 10
      burgers pour le mois ; plus de probabilité sur les boissons aujourd'hui. »]

      Deux colonnes, deux décisions. On affiche la CHANCE EN POURCENTAGE à côté du poids, parce que
      c'est la seule chose que le propriétaire lit vraiment : « 34 » ne veut rien dire tant qu'on n'a
      pas divisé par le total. Et on affiche le RESTANT : régler une quantité sans voir ce qui est
      déjà parti, c'est régler à l'aveugle.
    --}}
    <h2 class="lots-titre">Les cadeaux</h2>
    <p class="aide lots-intro">
      <b>Probabilité</b> : un poids, pas un pourcentage. Ce qui compte, c'est le rapport entre les
      lots — la chance réelle est calculée à côté. <b>0 = affiché sur la roue, jamais gagné.</b><br>
      <b>Nombre</b> : combien de cadeaux pour cette campagne. <b>0 = illimité.</b> Épuisé, le lot
      disparaît de la roue.
    </p>

    <div class="lots-enveloppe">
    <table class="lots">
      <thead>
        <tr><th>Cadeau</th><th>Probabilité</th><th>Chance</th><th>Nombre</th><th>Restant</th></tr>
      </thead>
      <tbody>
      @foreach ($lots as $lot)
        @php
          $cleP = 'prize_'.$lot['key'].'_weight';
          $cleQ = 'prize_'.$lot['key'].'_quantity';
        @endphp
        <tr class="{{ $lot['exhausted'] ? 'epuise' : '' }}">
          <th scope="row">
            {{ $lot['label'] }}
            @if ($lot['showcase'])<span class="etiq etiq-vitrine">vitrine</span>@endif
            @if ($lot['exhausted'])<span class="etiq etiq-epuise">épuisé</span>@endif
          </th>
          <td>
            <input id="{{ $cleP }}" name="{{ $cleP }}" type="number" min="0" max="1000" step="1"
                   aria-label="Probabilité de {{ $lot['label'] }}"
                   class="{{ $errors->has($cleP) ? 'faux' : '' }}"
                   value="{{ old($cleP, $lot['weight']) }}">
            @error($cleP)<p class="champ-err" role="alert">{{ $message }}</p>@enderror
          </td>
          <td class="chance">{{ $lot['chance'] > 0 ? number_format($lot['chance'], 1, ',', ' ').' %' : '—' }}</td>
          <td>
            <input id="{{ $cleQ }}" name="{{ $cleQ }}" type="number" min="0" max="100000" step="1"
                   aria-label="Nombre de {{ $lot['label'] }}"
                   class="{{ $errors->has($cleQ) ? 'faux' : '' }}"
                   value="{{ old($cleQ, $lot['quantity']) }}">
            @error($cleQ)<p class="champ-err" role="alert">{{ $message }}</p>@enderror
          </td>
          <td class="restant">
            @if ($lot['left'] === null)
              illimité
            @else
              {{ $lot['left'] }} <span class="sur">/ {{ $lot['quantity'] }}</span>
            @endif
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
    </div>

    <button type="submit">Enregistrer</button>
  </form>

  <div class="liens">
    <a href="{{ url('/admin/roue-borne') }}">→ Écran de la tablette (à afficher au comptoir)</a>
    <a href="{{ url('/admin/roue-lot') }}">→ Remettre un lot gagné</a>
    <a href="{{ url('/admin/roue-validation') }}">→ Valider un tour à la main</a>
  </div>
</main>
</body>
</html>
