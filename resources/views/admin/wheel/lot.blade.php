{{--
  ÉCRAN COMPTOIR — REMETTRE UN LOT.
  Le client dit son numéro, l'équipe le tape, voit le lot, appuie. C'est tout.
  Trois audits adversaires avaient montré qu'aucune surface ne disait au comptoir qu'un client
  avait un lot à recevoir : cet écran est ce maillon.
  Blade sans JavaScript : pendant un service, un écran qui charge est un écran qu'on n'utilise pas.

  [P1 2026-08-10 — audit E2E vague C] L'écran engageait de l'argent sans rien montrer : ni le nom
  enregistré (n'importe qui connaissant un numéro obtenait le produit), ni la condition d'achat, ni le
  code du coupon que le client vient justement chercher quand il a perdu son e-mail. Les trois sont
  affichés ici. La condition d'achat, elle, n'est PAS contrôlée par le logiciel — on le dit, plutôt
  que de laisser croire le contraire.
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
  .lot{margin:18px 0 0;padding:18px;border-radius:16px;background:rgba(255,184,0,.10);
    border:1px solid rgba(255,184,0,.45);text-align:center}
  .lot b{display:block;font-size:30px;line-height:1.15;margin-bottom:6px}
  .lot small{opacity:.8;font-size:13px}
  .aqui{margin:12px 0 0;padding-top:12px;border-top:1px dashed rgba(255,255,255,.16);
    font-size:16px;line-height:1.45}
  .aqui b{display:inline;font-size:19px;color:var(--jaune)}
  .aqui em{font-style:normal;display:block;font-size:13px;opacity:.72;margin-top:4px}
  /* La condition d'achat doit être LUE avant le geste : elle est donc entre le lot et le bouton, pas
     en petit sous l'écran. Le logiciel ne la contrôle pas — c'est écrit noir sur blanc. */
  .condition{margin:12px 0 0;padding:13px 15px;border-radius:13px;font-size:15px;line-height:1.5;
    background:rgba(244,80,30,.14);border:1px solid rgba(244,80,30,.55)}
  .condition b{color:#FFD08A}
  .msg{margin:0 0 16px;padding:13px 15px;border-radius:13px;font-size:15px;line-height:1.5}
  .msg.ok{background:rgba(29,185,84,.14);border:1px solid rgba(29,185,84,.55)}
  .msg.err{background:rgba(217,48,37,.16);border:1px solid rgba(217,48,37,.55)}
  .msg.info{background:rgba(255,184,0,.12);border:1px solid rgba(255,184,0,.42)}
  .hist{margin-top:18px;border-top:1px solid rgba(255,255,255,.12);padding-top:14px}
  .hist h2{font-size:12px;letter-spacing:.12em;text-transform:uppercase;opacity:.6;margin:0 0 8px}
  .hist li{list-style:none;font-size:14px;padding:6px 0;border-bottom:1px dashed rgba(255,255,255,.08)}
  .hist ul{margin:0;padding:0}
  .hist em{font-style:normal;opacity:.6;font-size:12px}
  .hist code{font-family:ui-monospace,Menlo,monospace;font-size:13px;font-weight:700;color:var(--jaune);
    background:rgba(255,184,0,.12);padding:1px 6px;border-radius:5px;opacity:1}
  /* Cible tactile : une tablette se pilote au pouce, 17 px de haut ne s'attrapent pas. */
  a.retour{display:inline-flex;align-items:center;min-height:44px;margin-top:10px;color:var(--jaune);
    font-size:15px;text-decoration:none;font-weight:700}
</style>
</head>
<body>
@php
  // « 12 € » et non « 12,00 € » : un montant qui traîne des zéros se relit deux fois.
  $euros = static fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',') . ' €';
@endphp
<main class="carte">
  <h1>Remettre un lot</h1>
  {{-- [2026-08-13] Ce sous-titre disait encore « le client donne son numéro » alors que le chemin
       principal est devenu son CODE. Un écran qui décrit un geste qu'on ne fait plus apprend le
       mauvais réflexe à l'équipe — et c'est le genre de détail qu'on laisse traîner parce qu'il ne
       casse rien. Il dit maintenant ce que l'écran fait vraiment. --}}
  <p class="sous">Le client montre son code, ou donne son numéro. Tu vérifies, tu remets, tu appuies.</p>

  {{-- Le message vient AVANT le formulaire : chaque geste recharge la page, et le résultat de ce
       geste est ce qu'il faut lire en premier — y compris à la synthèse vocale. --}}
  @if (! empty($message))
    <p class="msg {{ $messageType ?? 'info' }}"
       role="{{ ($messageType ?? 'info') === 'err' ? 'alert' : 'status' }}">{{ $message }}</p>
  @endif

  {{-- ── DEUX FAÇONS DE RETROUVER UN CLIENT, ET LE CODE D'ABORD ─────────────────────────────
       [2026-08-13 · propriétaire : « valider le code promo au cas où, ou bien dans la caisse »]
       Cet écran ne savait chercher que par NUMÉRO. Or ce que le client tend au comptoir, c'est
       son code — sur sa page, dans son mail, sur une capture. Le seul objet que le jeu lui remet
       était le seul avec lequel l'équipe ne pouvait rien faire.

       Le code est mis EN PREMIER parce que c'est le geste le plus fréquent et le plus sûr : il
       désigne UN tour précis, là où un numéro peut en porter plusieurs. Le numéro reste juste en
       dessous, pour le client qui a perdu son message — on n'enlève rien, on ajoute.

       Deux formulaires séparés et non un seul à deux champs : un formulaire unique obligerait
       l'équipe à vider l'autre case avant de chercher, debout, pendant un service. Chaque bouton
       fait exactement une chose. --}}
  <form method="GET" action="{{ url('/admin/roue-lot') }}">
    <label for="code">Code du client</label>
    <input id="code" name="code" type="text" inputmode="latin" placeholder="ROUE-FLZ5EN"
           value="{{ $code ?? '' }}" maxlength="32" autocomplete="off" autocapitalize="characters"
           spellcheck="false" autofocus>
    <button type="submit">Chercher par le code</button>
  </form>

  <form method="GET" action="{{ url('/admin/roue-lot') }}" style="margin-top:22px">
    <label for="phone">Ou son numéro de téléphone</label>
    <input id="phone" name="phone" type="tel" inputmode="tel" placeholder="06 12 34 56 78"
           value="{{ $phone ?? '' }}" maxlength="20" autocomplete="off">
    <button type="submit">Chercher par le numéro</button>
  </form>

  @if (! empty($spin))
    <div class="lot">
      <b>{{ $spin->prize_label }}</b>
      <small>
        Gagné le {{ $spin->created_at?->format('d/m/Y à H:i') }}
        @if ($spin->prize_type === 'points') · à créditer sur son compte @endif
      </small>

      {{-- Le nom est en base depuis le tirage. Ne pas l'afficher, c'était remettre le lot à
           quiconque connaît un numéro de téléphone. --}}
      @if (filled($spin->customer_name))
        <p class="aqui">
          Au nom de <b>{{ $spin->customer_name }}</b>
          <em>Demande-lui son prénom avant de remettre : c'est la seule vérification possible ici.</em>
        </p>
      @else
        <p class="aqui">
          Aucun nom enregistré pour ce tour
          <em>Rien à recouper : assure-toi que c'est bien son numéro avant de remettre.</em>
        </p>
      @endif
    </div>

    @if (! empty($exigeCommande) && ($minOrder ?? 0) > 0)
      <p class="condition" role="status">
        À vérifier <b>avant</b> de remettre : une commande de <b>{{ $euros($minOrder) }} minimum</b>,
        encaissée maintenant. Le logiciel ne peut pas le contrôler — regarde le ticket.
      </p>
    @endif

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
              @elseif (in_array($h->prize_type, ['coupon_percent','coupon_fixed'], true))
                @php
                  // Le minimum vient du COUPON, pas des réglages du jour : c'est la condition qui a
                  // été promise à ce client-là, ce jour-là. Un réglage changé depuis ne doit pas
                  // réécrire ce qu'on lui a dit.
                  $mini = (float) ($h->coupon->minimum_order ?? 0);
                @endphp
                @if (filled($h->coupon?->code))
                  · code <code>{{ $h->coupon->code }}</code>, à saisir sur le site{{ $mini > 0 ? ', minimum ' . $euros($mini) : '' }}
                @else
                  · remise à saisir sur le site — code introuvable, dis-lui de rouvrir son écran de lot
                @endif
              @else · EN ATTENTE
              @endif
            </em>
          </li>
        @endforeach
      </ul>
    </div>
  @endif

  <a class="retour" href="{{ url('/admin/roue-validation') }}">← Valider un tour</a>
</main>
</body>
</html>
