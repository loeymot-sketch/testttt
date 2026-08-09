{{--
  ÉCRAN COMPTOIR — REMETTRE UN LOT.
  Le client dit son numéro, l'équipe le tape, voit le lot, appuie. C'est tout.
  Trois audits adversaires avaient montré qu'aucune surface ne disait au comptoir qu'un client
  avait un lot à recevoir : cet écran est ce maillon.
  Blade sans JavaScript : pendant un service, un écran qui charge est un écran qu'on n'utilise pas.
--}}
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Roue — remettre un lot</title>
<style>
  :root{--orange:#F4501E;--jaune:#FFB800;--noir:#141414;--creme:#FFF6EC;--ok:#1DB954;--rouge:#D93025}
  *{box-sizing:border-box}
  body{margin:0;background:var(--noir);color:var(--creme);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    min-height:100dvh;display:grid;place-items:start center;padding:20px}
  .carte{width:100%;max-width:520px;background:#1E1A17;border:1px solid rgba(255,184,0,.3);
    border-radius:22px;padding:22px;margin-top:12px}
  h1{margin:0 0 4px;font-size:18px;letter-spacing:.1em;text-transform:uppercase}
  .sous{opacity:.72;font-size:14px;line-height:1.5;margin:0 0 18px}
  label{display:block;font-size:12px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;opacity:.72;margin-bottom:6px}
  input{width:100%;min-height:56px;border-radius:14px;padding:0 15px;font-size:19px;font-weight:600;
    background:#fff;color:#1a1a1a;border:2px solid transparent}
  input:focus{outline:none;border-color:var(--jaune)}
  button{width:100%;min-height:60px;border:0;border-radius:15px;cursor:pointer;margin-top:12px;
    font-size:17px;font-weight:900;color:#2a1508;background:linear-gradient(100deg,var(--jaune),#FF6A3D)}
  button.remettre{background:linear-gradient(100deg,#7BE495,var(--ok));color:#062d13;font-size:19px;min-height:70px}
  .lot{margin:18px 0;padding:18px;border-radius:16px;background:rgba(255,184,0,.10);
    border:1px solid rgba(255,184,0,.45);text-align:center}
  .lot b{display:block;font-size:30px;line-height:1.15;margin-bottom:6px}
  .lot small{opacity:.8;font-size:13px}
  .msg{margin:14px 0 0;padding:13px 15px;border-radius:13px;font-size:15px;line-height:1.5}
  .msg.ok{background:rgba(29,185,84,.14);border:1px solid rgba(29,185,84,.55)}
  .msg.err{background:rgba(217,48,37,.16);border:1px solid rgba(217,48,37,.55)}
  .msg.info{background:rgba(255,184,0,.12);border:1px solid rgba(255,184,0,.42)}
  .hist{margin-top:18px;border-top:1px solid rgba(255,255,255,.12);padding-top:14px}
  .hist h2{font-size:12px;letter-spacing:.12em;text-transform:uppercase;opacity:.6;margin:0 0 8px}
  .hist li{list-style:none;font-size:14px;padding:6px 0;border-bottom:1px dashed rgba(255,255,255,.08)}
  .hist ul{margin:0;padding:0}
  .hist em{font-style:normal;opacity:.6;font-size:12px}
  a.retour{display:inline-block;margin-top:16px;color:var(--jaune);font-size:14px;text-decoration:none;font-weight:700}
</style>
</head>
<body>
<div class="carte">
  <h1>Remettre un lot</h1>
  <p class="sous">Le client donne son numéro. Tu vérifies, tu remets, tu appuies.</p>

  <form method="GET" action="{{ url('/admin/roue-lot') }}">
    <label for="phone">Numéro du client</label>
    <input id="phone" name="phone" type="tel" inputmode="tel" placeholder="06 12 34 56 78"
           value="{{ $phone ?? '' }}" maxlength="20" autofocus>
    <button type="submit">Chercher son lot</button>
  </form>

  @if (! empty($message))
    <p class="msg {{ $messageType ?? 'info' }}">{{ $message }}</p>
  @endif

  @if (! empty($spin))
    <div class="lot">
      <b>{{ $spin->prize_label }}</b>
      <small>
        Gagné le {{ $spin->created_at?->format('d/m/Y à H:i') }}
        @if ($spin->prize_type === 'points') · à créditer sur son compte @endif
      </small>
    </div>

    {{-- Le bouton de remise est VERT et plus grand que tout le reste : pendant un service, c'est le
         seul geste qui compte sur cet écran, et il ne doit pas se chercher. --}}
    <form method="POST" action="{{ url('/admin/roue-lot/remettre') }}">
      @csrf
      <input type="hidden" name="spin_id" value="{{ $spin->id }}">
      <input type="hidden" name="phone" value="{{ $phone ?? '' }}">
      <button class="remettre" type="submit">✓ REMIS AU CLIENT</button>
    </form>
  @endif

  @if (! empty($history) && count($history))
    <div class="hist">
      <h2>Ses derniers tours</h2>
      <ul>
        @foreach ($history as $h)
          <li>
            {{ $h->prize_label }}
            <em>
              — {{ $h->created_at?->format('d/m/Y') }}
              @if ($h->delivered_at) · remis le {{ $h->delivered_at->format('d/m/Y') }}
              @elseif (in_array($h->prize_type, ['coupon_percent','coupon_fixed'], true)) · code à utiliser sur le site
              @else · EN ATTENTE
              @endif
            </em>
          </li>
        @endforeach
      </ul>
    </div>
  @endif

  <a class="retour" href="{{ url('/admin/roue-validation') }}">← Valider un tour</a>
</div>
</body>
</html>
