{{--
  ÉCRAN DE VALIDATION AU COMPTOIR — la tablette que l'équipe a sous la main.

  C'est ici que se règle le problème qu'aucune API ne résout : personne ne peut vérifier par
  programme qu'un client précis a laissé un avis Google ou s'est abonné. Un œil humain, lui, le
  voit en trois secondes. Cet écran transforme ce regard en un jeton signé, court et à usage unique.

  Rendu en Blade sans JavaScript : la tablette du comptoir doit afficher ça instantanément, même
  sur un réseau moyen, et pendant un service il n'y a pas de place pour un écran qui charge.

  [P1 2026-08-10 — audit E2E vague C] La consigne était ÉCRITE EN DUR (« abonné aux deux comptes »)
  et réclamait des abonnements que le client ne s'était jamais vu proposer : Instagram et Snapchat
  étaient vides, seul Facebook était renseigné. Elle est maintenant composée depuis les réglages, avec
  la même règle que le moteur (`WheelStepService::required`) — l'équipe ne demande plus que ce qui a
  réellement été demandé au client.
--}}
@php
  /*
   * La liste vient des RÉGLAGES, pas du code. Les services sont résolus ici plutôt que passés par le
   * contrôleur : cette consigne doit suivre les réglages sur les deux entrées de l'écran (arrivée et
   * jeton émis), et une variable oubliée d'un côté redonnerait une consigne fausse — exactement le
   * défaut qu'on corrige.
   */
  $reglagesRoue = app(\App\Services\Wheel\WheelSettingsService::class);
  $etapesRoue = app(\App\Services\Wheel\WheelStepService::class);

  $avisExige = $etapesRoue->required(\App\Services\Wheel\WheelStepService::REVIEW);
  $abonnementExige = $etapesRoue->required(\App\Services\Wheel\WheelStepService::FOLLOW);

  // On ne nomme QUE les comptes réellement renseignés : réclamer un abonnement à un compte qui
  // n'existe pas dans le parcours, c'est demander l'impossible au client, devant témoin.
  $comptes = [];
  if ($reglagesRoue->facebookUrl() !== '') { $comptes[] = 'notre page Facebook'; }
  if ($reglagesRoue->instagramUrl() !== '') { $comptes[] = 'notre Instagram'; }
  if ($reglagesRoue->snapchatUrl() !== '') { $comptes[] = 'notre Snapchat'; }
  $listeComptes = count($comptes) > 1
    ? implode(', ', array_slice($comptes, 0, -1)) . ' et ' . end($comptes)
    : (string) ($comptes[0] ?? '');
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
{{--
  RESTER OUVERT DES HEURES SANS TOMBER SUR « 419 PAGE EXPIRED ».
  Cet écran est posé au comptoir et personne ne le touche entre deux clients. Le jeton de sécurité du
  formulaire finit par périmer, et l'équipe appuyait sur VALIDER pour tomber sur une page blanche en
  anglais. Un rechargement périodique renouvelle ce jeton et garde la session vivante — sans une ligne
  de JavaScript, et sans rien à saisir sur cet écran qu'on pourrait perdre.
  ⚠️ UNIQUEMENT sur l'écran d'attente : recharger pendant qu'un client scanne le QR le ferait
  disparaître sous ses yeux.
--}}
@if (empty($token) && empty($erreur))
  <meta http-equiv="refresh" content="600">
@endif
<title>Roue — valider un tour</title>
<style>
  :root{--orange:#F4501E;--jaune:#FFB800;--noir:#141414;--creme:#FFF6EC;--ok:#1DB954}
  *{box-sizing:border-box}
  body{margin:0;background:var(--noir);color:var(--creme);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    min-height:100dvh;display:grid;place-items:center;padding:20px}
  .carte{width:100%;max-width:520px;background:#1E1A17;border:1px solid rgba(255,184,0,.3);
    border-radius:22px;padding:24px 22px;text-align:center}
  h1{margin:0 0 6px;font-size:19px;letter-spacing:.1em;text-transform:uppercase}
  .sous{opacity:.75;font-size:14px;line-height:1.5;margin:0 0 20px}
  ol{text-align:left;margin:0 0 20px;padding-left:22px;font-size:15px;line-height:1.7}
  ol b{color:var(--jaune)}
  button{width:100%;min-height:66px;border:0;border-radius:16px;cursor:pointer;
    font-size:19px;font-weight:900;color:#2a1508;
    background:linear-gradient(100deg,var(--jaune),#FF6A3D);box-shadow:0 8px 22px rgba(244,80,30,.4)}
  .qr{background:#fff;border-radius:18px;padding:16px;display:inline-block;margin:6px 0 12px;position:relative}
  .qr svg{display:block;width:min(62vw,260px);height:auto}
  /* Même logo qu'en tablette (borne.blade.php) — voir ce fichier pour le raisonnement complet
     (Imagick absent, overlay CSS plutôt que merge binaire, H+margin(2) déjà posés au contrôleur). */
  .qr-logo{
    position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);
    width:20%;aspect-ratio:1;border-radius:50%;background:#fff;
    padding:8%;box-sizing:border-box;pointer-events:none;
  }
  .qr-logo img{display:block;width:100%;height:100%;object-fit:contain}
  .lien{font-family:ui-monospace,Menlo,monospace;font-size:11px;word-break:break-all;opacity:.6;margin:8px 0 14px}
  .expire{background:rgba(255,184,0,.12);border:1px solid rgba(255,184,0,.4);border-radius:12px;
    padding:11px 13px;font-size:14px;margin-bottom:16px}
  /* Cible tactile : une tablette se pilote au pouce, un lien de 17 px de haut ne s'attrape pas. */
  .encore{display:inline-flex;align-items:center;justify-content:center;min-height:44px;margin-top:6px;
    color:var(--jaune);font-size:15px;text-decoration:none;font-weight:700}
  .err{background:rgba(217,48,37,.16);border:1px solid rgba(217,48,37,.5);border-radius:12px;padding:12px;font-size:14px}
  .err-suite{font-size:14px;line-height:1.5;opacity:.85;margin:12px 0 0}
</style>
</head>
<body>
<main class="carte">

  @if (! empty($erreur))
    <h1>Validation impossible</h1>
    <p class="err" role="alert">{{ $erreur }}</p>
    {{-- Le texte de l'erreur vient du contrôleur et cite un nom technique. On ne peut pas le
         réécrire d'ici, mais on peut dire à l'équipe QUOI FAIRE : sans ça, elle réessaie en boucle
         un geste qui ne peut pas aboutir. --}}
    <p class="err-suite">
      Si ce message revient, il n'y a rien à corriger depuis cet écran : c'est un réglage.
      Préviens le patron, et encaisse la commande normalement.
    </p>
    <a class="encore" href="{{ url('/admin/roue-validation') }}">Réessayer</a>

  @elseif (empty($token))
    <h1>Valider un tour de roue</h1>
    <p class="sous">Le client montre son écran. Tu regardes. Tu valides. Il tourne devant toi.</p>
    <ol>
      @if ($avisExige)
        <li>Il a laissé un <b>avis Google</b> — l'avis est visible à son nom.</li>
      @endif
      @if ($abonnementExige && $listeComptes !== '')
        <li>Il est <b>abonné</b> à {{ $listeComptes }}.</li>
      @endif
      @if (! $avisExige && ! $abonnementExige)
        <li>
          <b>Aucune condition n'est exigée en ce moment</b> — le client n'a rien eu à faire pour
          jouer. Valide seulement s'il a commandé.
        </li>
      @endif
      <li>Alors seulement, tu appuies ci-dessous.</li>
    </ol>
    <form method="POST" action="{{ url('/admin/roue-validation') }}">
      @csrf
      <button type="submit">VALIDER — il peut tourner</button>
    </form>
    <p class="sous" style="margin:16px 0 0;font-size:13px">
      Chaque validation ne sert qu'<b>une fois</b>. Si le client repart sans tourner, il faudra
      revalider — c'est voulu : un code qui traîne se partage.
    </p>

  @else
    <h1>À lui de tourner</h1>
    <p class="sous">Fais-lui scanner ce QR maintenant, devant toi.</p>
    <div class="qr">{!! $qr !!}<div class="qr-logo"><img src="{{ asset('images/wheel/logo-mark.png') }}" alt=""></div></div>
    <p class="expire">Valable {{ $ttl }} minutes — une seule fois.</p>
    {{-- [P1 2026-08-09] L'adresse COMPLÈTE, jeton compris, était affichée en clair : n'importe qui
         dans la file pouvait la photographier et consommer la validation avec SON numéro — le client
         légitime recevant ensuite « tu as déjà tourné la roue », qui l'accuse à tort. Le QR reste
         (il faut bien scanner quelque chose) mais le texte ne montre plus que le domaine. --}}
    <p class="lien">{{ parse_url($url, PHP_URL_HOST) }} — scanne le QR ci-dessus</p>
    <form method="POST" action="{{ url('/admin/roue-validation') }}">
      @csrf
      <button type="submit">Valider un autre client</button>
    </form>
  @endif

    {{-- Les deux écrans du comptoir se répondent : valider un tour / remettre un lot. Sans ce
         lien, l'équipe devrait retenir deux adresses. --}}
    <a class="encore" href="{{ url('/admin/roue-lot') }}" style="display:flex;margin-top:18px">Remettre un lot gagné →</a>
</main>
</body>
</html>
