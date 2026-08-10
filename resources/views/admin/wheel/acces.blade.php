{{--
  L'ACCUEIL DE LA ROUE — la porte, et le sommaire.

  Cette page répond à deux défauts qui se cachaient l'un l'autre : les écrans étaient inaccessibles
  dans un navigateur, et aucun lien ne menait à eux. Un écran de service qu'on ne peut pas trouver
  n'existe pas, même quand il fonctionne.

  Elle est faite pour être lue par quelqu'un DEBOUT, en service, sur une tablette. Un écran par
  besoin, une phrase pour dire quand on s'en sert.
--}}
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>La roue — Le Cayenne</title>
<style>
  :root{--orange:#F4501E;--orange2:#FF6A3D;--jaune:#FFB800;--jaune2:#FFD34D;--noir:#100E0C;--creme:#FFF6EC}
  *{box-sizing:border-box}
  body{
    margin:0; min-height:100vh; background:
      radial-gradient(110% 80% at 50% -10%,#3A2418 0%,transparent 58%), var(--noir);
    color:var(--creme); font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    -webkit-font-smoothing:antialiased; padding:24px 18px 48px;
  }
  main{max-width:760px; margin:0 auto}
  h1{margin:0 0 6px; font-size:clamp(26px,6vw,38px); font-weight:900; letter-spacing:-.02em}
  .sous{margin:0 0 26px; font-size:15px; opacity:.72; line-height:1.5}

  .msg{border-radius:14px; padding:14px 16px; font-size:15px; line-height:1.5; margin:0 0 20px}
  .msg--info{background:rgba(255,184,0,.12); border:1px solid rgba(255,184,0,.42)}
  .msg--stop{background:rgba(217,48,37,.14); border:1px solid rgba(217,48,37,.5)}
  .msg b{color:var(--jaune2)}

  form{display:grid; gap:12px; max-width:340px; margin:0 0 8px}
  label{font-size:12px; font-weight:800; letter-spacing:.1em; text-transform:uppercase; opacity:.72}
  input{
    width:100%; min-height:56px; border-radius:14px; padding:0 16px; border:2px solid transparent;
    font-size:22px; font-weight:700; letter-spacing:.3em; text-align:center;
    background:rgba(255,255,255,.94); color:#1a1a1a;
  }
  input:focus{outline:none; border-color:var(--jaune); background:#fff}
  button.cta{
    min-height:56px; border:0; border-radius:14px; cursor:pointer; font-size:17px; font-weight:900;
    color:#2a1508; background:linear-gradient(100deg,var(--jaune),var(--orange2));
  }
  button.cta:focus-visible, a:focus-visible{outline:3px solid var(--jaune2); outline-offset:3px}

  .grille{display:grid; gap:14px; grid-template-columns:repeat(auto-fit,minmax(240px,1fr))}
  .carte{
    display:block; text-decoration:none; color:inherit; min-height:112px;
    background:rgba(255,255,255,.05); border:1px solid rgba(255,184,0,.28); border-radius:18px;
    padding:16px 18px; transition:border-color .15s, transform .12s;
  }
  .carte:hover{border-color:rgba(255,184,0,.7)}
  .carte:active{transform:translateY(1px)}
  .carte .t{display:flex; align-items:center; gap:10px; font-size:18px; font-weight:900; margin:0 0 6px}
  .carte .t span{font-size:22px}
  .carte .d{margin:0; font-size:13.5px; line-height:1.5; opacity:.74}

  .etat{margin:26px 0 0; padding:16px 18px; border-radius:16px;
        background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.1)}
  .etat h2{margin:0 0 10px; font-size:13px; letter-spacing:.14em; text-transform:uppercase; opacity:.7}
  .etat ul{margin:0; padding:0 0 0 2px; list-style:none; display:grid; gap:7px}
  .etat li{font-size:14px; line-height:1.5; display:flex; gap:9px; align-items:flex-start}
  .etat .ok{color:#8fe0ab}
  .etat .ko{color:#ffb2a6}
  .refermer{display:inline-block; margin-top:20px; font-size:13px; opacity:.6; color:inherit}

  /* ── LE PANNEAU DE CONTRÔLE ─────────────────────────────────────────────────────────────── */
  .bilan{margin:22px 0 0; padding:18px; border-radius:16px;
         background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.1)}
  .bilan h2{margin:0 0 14px; font-size:13px; letter-spacing:.14em; text-transform:uppercase; opacity:.7}
  .periode{padding:12px 0; border-top:1px solid rgba(255,255,255,.07)}
  .periode:first-of-type{border-top:0; padding-top:0}
  .ptitre{margin:0 0 8px; font-size:13px; font-weight:800; opacity:.8}
  .chiffres{display:grid; grid-template-columns:repeat(auto-fit,minmax(104px,1fr)); gap:10px}
  .chiffres div{background:rgba(255,255,255,.05); border-radius:12px; padding:10px 12px}
  .chiffres b{display:block; font-size:21px; font-weight:900; line-height:1.15; color:var(--jaune2)}
  .chiffres span{display:block; font-size:11.5px; opacity:.66; margin-top:2px; line-height:1.35}
  .bilan .detail{margin:8px 0 0; font-size:13px; line-height:1.5; opacity:.8}
  .bilan .detail b{color:var(--jaune2)}
  .bilan .note{margin:14px 0 0; font-size:11.5px; line-height:1.5; opacity:.55}
  .alerte{margin:10px 0 0; padding:10px 12px; border-radius:11px; font-size:12.5px; line-height:1.5;
          background:rgba(255,184,0,.1); border:1px solid rgba(255,184,0,.34)}
</style>
</head>
<body>
<main>
  <h1>La roue</h1>
  <p class="sous">Tout ce qui concerne le jeu, au même endroit.</p>

  @if (! empty($message))
    <p class="msg msg--stop" role="alert">{{ $message }}</p>
  @endif

  @if (! $ouvert)

    @if (! $pinConfigure)
      {{-- Fail-closed, et on dit précisément quoi faire. Un refus sans issue est vécu comme une
           panne, et personne ne sait qui appeler. --}}
      <p class="msg msg--info">
        Ces écrans distribuent des lots : ils sont fermés jusqu'à ce qu'un code soit posé sur cette
        machine. Le code se règle une fois, dans le fichier de configuration du serveur
        (<b>WHEEL_PIN</b>) — comme celui du carnet de caisse.
      </p>
    @else
      <p class="msg msg--info">
        Entre le code de la maison pour ouvrir les écrans de la roue. Il reste ouvert
        <b>{{ (int) config('wheel.access.session_minutes', 240) / 60 }} h</b> sur cette tablette.
      </p>
      <form method="POST" action="{{ route('admin.wheel.unlock') }}" autocomplete="off">
        @csrf
        <div>
          <label for="pin">Code</label>
          <input id="pin" name="pin" type="password" inputmode="numeric" autocomplete="off"
                 maxlength="32" required autofocus>
        </div>
        <button class="cta" type="submit">Ouvrir</button>
      </form>
    @endif

  @else

    <div class="grille">
      <a class="carte" href="{{ route('admin.wheel.counter') }}">
        <p class="t"><span aria-hidden="true">🎟️</span> Débloquer un tour</p>
        <p class="d">Le client a laissé son avis et s'est abonné : on lui affiche le QR à scanner.
          C'est l'écran du service.</p>
      </a>
      <a class="carte" href="{{ route('admin.wheel.prize') }}">
        <p class="t"><span aria-hidden="true">🎁</span> Remettre un lot</p>
        <p class="d">Un client réclame son cadeau : on saisit son numéro, on voit ce qu'il a gagné,
          on confirme la remise.</p>
      </a>
      <a class="carte" href="{{ route('admin.wheel.kiosk') }}">
        <p class="t"><span aria-hidden="true">📺</span> Écran vitrine</p>
        <p class="d">À laisser affiché en plein écran sur la tablette du comptoir, face aux clients.
          Le QR s'y renouvelle tout seul.</p>
      </a>
      <a class="carte" href="{{ route('admin.wheel.settings') }}">
        <p class="t"><span aria-hidden="true">🔧</span> Réglages</p>
        <p class="d">Les liens (avis Google, réseaux), les temps d'attente et le minimum d'achat.
          C'est ici que le jeu s'active.</p>
      </a>
    </div>

    {{-- L'ÉTAT DU JEU, dit en une fois. Sans ça, il faut ouvrir trois écrans pour savoir si le jeu
         tourne — et on n'y pense pas. --}}
    <div class="etat">
      <h2>Où en est le jeu</h2>
      <ul>
        <li>
          <span class="{{ $jeuOuvertAuPublic ? 'ok' : 'ko' }}" aria-hidden="true">{{ $jeuOuvertAuPublic ? '✓' : '•' }}</span>
          <span>{{ $jeuOuvertAuPublic
            ? 'Ouvert au public : tous les clients peuvent jouer.'
            : 'Fermé au public — seul le patron peut tester, avec son lien d\'aperçu.' }}</span>
        </li>
        <li>
          <span class="{{ $reglages->reviewUrlIsDerived() ? 'ko' : 'ok' }}" aria-hidden="true">{{ $reglages->reviewUrlIsDerived() ? '•' : '✓' }}</span>
          <span>{{ $reglages->reviewUrlIsDerived()
            ? 'Lien d\'avis : lien de secours (une recherche Google Maps). Colle ta vraie fiche dans les réglages pour un appui de moins.'
            : 'Lien d\'avis : ta fiche Google est en place.' }}</span>
        </li>
        <li>
          <span class="{{ $reglages->instagramUrl() !== '' ? 'ok' : 'ko' }}" aria-hidden="true">{{ $reglages->instagramUrl() !== '' ? '✓' : '•' }}</span>
          <span>Instagram : {{ $reglages->instagramUrl() !== '' ? 'en place.' : 'pas encore renseigné.' }}</span>
        </li>
        <li>
          <span class="{{ $reglages->snapchatUrl() !== '' ? 'ok' : 'ko' }}" aria-hidden="true">{{ $reglages->snapchatUrl() !== '' ? '✓' : '•' }}</span>
          <span>Snapchat : {{ $reglages->snapchatUrl() !== '' ? 'en place.' : 'pas encore renseigné.' }}</span>
        </li>
      </ul>
    </div>

    {{-- ── CE QUE LA ROUE A DONNÉ, ET CE QU'ELLE A COÛTÉ ─────────────────────────────────────
         Le jeu avait des plafonds et AUCUNE lecture de ce qui sortait vraiment : le propriétaire
         réglait des limites à l'aveugle. Un contrôle sans lecture est une intention, pas un
         contrôle. --}}
    @if (! empty($bilan))
      <div class="bilan">
        <h2>Ce que la roue a donné</h2>

        @foreach ($bilan['periodes'] as $p)
          <div class="periode">
            <p class="ptitre">{{ $p['libelle'] }}</p>
            <div class="chiffres">
              <div><b>{{ $p['tours'] }}</b><span>tour{{ $p['tours'] > 1 ? 's' : '' }}</span></div>
              <div><b>{{ $p['cadeaux_remis'] }}</b><span>cadeaux remis</span></div>
              <div><b>{{ number_format($p['valeur_offerte'], 2, ',', ' ') }} €</b><span>valeur offerte</span></div>
              <div><b>{{ $p['codes_utilises'] }}/{{ $p['codes_emis'] }}</b><span>codes utilisés</span></div>
            </div>
            @if ($p['cadeaux_dus'] > 0 || $p['exposition_max'] > 0)
              <p class="detail">
                @if ($p['cadeaux_dus'] > 0)
                  {{ $p['cadeaux_dus'] }} cadeau{{ $p['cadeaux_dus'] > 1 ? 'x' : '' }} encore
                  {{ $p['cadeaux_dus'] > 1 ? 'dus' : 'dû' }}.
                @endif
                @if ($p['exposition_max'] > 0)
                  Codes non utilisés : jusqu'à
                  <b>{{ number_format($p['exposition_max'], 2, ',', ' ') }} €</b> de remise possible.
                @endif
              </p>
            @endif
          </div>
        @endforeach

        {{-- On ne prétend PAS calculer un coût : la base ne porte aucun prix d'achat. --}}
        <p class="note">
          « Valeur offerte » = le prix de VENTE de ce qui a été donné, donc le chiffre d'affaires
          abandonné — pas ta dépense réelle (le prix d'achat n'existe pas dans le logiciel).
        </p>

        @if ($bilan['plafond_jour']['plafond'] > 0)
          <p class="detail">
            Plafond du jour : <b>{{ $bilan['plafond_jour']['utilise'] }}</b> /
            {{ $bilan['plafond_jour']['plafond'] }} tours.
          </p>
        @endif

        @foreach ($bilan['avertissements'] as $a)
          <p class="alerte" role="status">{{ $a }}</p>
        @endforeach
      </div>
    @endif

    <form method="POST" action="{{ route('admin.wheel.lock') }}" style="max-width:none">
      @csrf
      <a class="refermer" href="#" onclick="event.preventDefault();this.closest('form').submit()">Refermer les écrans</a>
    </form>

  @endif
</main>
</body>
</html>
