{{--
  ÉCRAN DE VALIDATION AU COMPTOIR — la tablette que l'équipe a sous la main.

  C'est ici que se règle le problème qu'aucune API ne résout : personne ne peut vérifier par
  programme qu'un client précis a laissé un avis Google ou s'est abonné. Un œil humain, lui, le
  voit en trois secondes. Cet écran transforme ce regard en un jeton signé, court et à usage unique.

  Rendu en Blade sans JavaScript : la tablette du comptoir doit afficher ça instantanément, même
  sur un réseau moyen, et pendant un service il n'y a pas de place pour un écran qui charge.
--}}
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
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
  .qr{background:#fff;border-radius:18px;padding:16px;display:inline-block;margin:6px 0 12px}
  .qr svg{display:block;width:min(62vw,260px);height:auto}
  .lien{font-family:ui-monospace,Menlo,monospace;font-size:11px;word-break:break-all;opacity:.6;margin:8px 0 14px}
  .expire{background:rgba(255,184,0,.12);border:1px solid rgba(255,184,0,.4);border-radius:12px;
    padding:11px 13px;font-size:14px;margin-bottom:16px}
  .encore{display:inline-block;margin-top:6px;color:var(--jaune);font-size:14px;text-decoration:none;font-weight:700}
  .err{background:rgba(217,48,37,.16);border:1px solid rgba(217,48,37,.5);border-radius:12px;padding:12px;font-size:14px}
</style>
</head>
<body>
<div class="carte">

  @if (! empty($erreur))
    <h1>Validation impossible</h1>
    <p class="err">{{ $erreur }}</p>
    <a class="encore" href="{{ url('/admin/roue-validation') }}">Réessayer</a>

  @elseif (empty($token))
    <h1>Valider un tour de roue</h1>
    <p class="sous">Le client montre son écran. Tu regardes. Tu valides. Il tourne devant toi.</p>
    <ol>
      <li>Il a laissé un <b>avis Google</b> — l'avis est visible à son nom.</li>
      <li>Il est <b>abonné aux deux comptes</b>.</li>
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
    <div class="qr">{!! $qr !!}</div>
    <p class="expire">Valable {{ $ttl }} minutes — une seule fois.</p>
    <p class="lien">{{ $url }}</p>
    <form method="POST" action="{{ url('/admin/roue-validation') }}">
      @csrf
      <button type="submit">Valider un autre client</button>
    </form>
  @endif

</div>
</body>
</html>
