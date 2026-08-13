{{--
  L'HISTORIQUE DE LA ROUE — la lecture qui manquait.

  [2026-08-13 · propriétaire : « toutes les fonctionnalités d'historique, de la gestion, de la
  validation, de l'utilisation — par exemple quel code promo a été validé »]

  L'accueil donne des TOTAUX. Ils servent à régler des plafonds, et à rien d'autre : devant un
  client qui affirme n'avoir jamais reçu son lot, un total ne tranche rien. Cet écran donne les
  LIGNES — et c'est la seule forme qui permette d'expliquer.

  ── POURQUOI UN TABLEAU ET NON DES CARTES ──────────────────────────────────────────────────────
  La question est comparative (« lequel, quand, remis ou pas ») : trois colonnes qu'on balaie d'un
  regard. Des cartes empilées obligeraient à retenir une date d'une carte à l'autre. Le tableau
  DÉFILE horizontalement sur un téléphone plutôt que de comprimer ses colonnes.

  ── CE QU'ON N'AFFICHE PAS ─────────────────────────────────────────────────────────────────────
  Jamais le numéro complet. Cet écran s'ouvre avec le code de la maison, sur une tablette que
  d'autres regardent par-dessus l'épaule. Quatre chiffres suffisent à confirmer une identité que le
  client vient d'annoncer, et ne permettent pas d'en constituer une liste.
--}}
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Roue — historique</title>
<style>
  :root{--orange:#F4501E;--orange2:#FF6A3D;--jaune:#FFB800;--jaune2:#FFD34D;--noir:#141414;
        --creme:#FFF6EC;--ok:#1DB954;--rouge:#D93025}
  *{box-sizing:border-box}
  body{margin:0;background:var(--noir);color:var(--creme);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    min-height:100dvh;padding:20px}
  main{width:100%;max-width:1080px;margin:0 auto}
  h1{margin:0 0 4px;font-size:19px;letter-spacing:.1em;text-transform:uppercase}
  .sous{opacity:.75;font-size:14px;line-height:1.55;margin:0 0 18px}

  /* Les périodes : des cibles de 44 px, tenues à une main au comptoir. */
  .periodes{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}
  .periodes a{display:inline-flex;align-items:center;min-height:44px;padding:0 16px;border-radius:99px;
    text-decoration:none;font-weight:800;font-size:14px;color:var(--creme);
    background:rgba(255,255,255,.06);border:1px solid rgba(255,184,0,.3)}
  .periodes a.on{background:linear-gradient(100deg,var(--jaune),var(--orange2));color:#2a1508;border-color:transparent}
  .periodes a:focus-visible{outline:3px solid var(--jaune2);outline-offset:3px}

  .enveloppe{overflow-x:auto;-webkit-overflow-scrolling:touch;
    border:1px solid rgba(255,255,255,.1);border-radius:16px}
  table{width:100%;border-collapse:collapse;font-size:14.5px;min-width:760px}
  th,td{padding:11px 12px;text-align:left;vertical-align:middle;border-bottom:1px solid rgba(255,255,255,.08)}
  thead th{font-size:11px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;opacity:.62;
    border-bottom:1px solid rgba(255,255,255,.2);position:sticky;top:0;background:#191919}
  tbody tr:last-child td{border-bottom:0}
  .quand{white-space:nowrap;font-variant-numeric:tabular-nums;opacity:.8}
  .lot{font-weight:800}
  .code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;
    letter-spacing:.04em;white-space:nowrap}
  .qui{opacity:.78;white-space:nowrap}
  .vide{opacity:.4}

  /* L'état porte une PASTILLE TEXTE, pas seulement une couleur : une pastille verte et une
     pastille orange se ressemblent pour 8 % des hommes, et cet écran sert à trancher. */
  .etat{display:inline-flex;align-items:center;gap:6px;padding:4px 11px;border-radius:99px;
    font-size:12.5px;font-weight:900;white-space:nowrap}
  .etat-remis{background:rgba(29,185,84,.16);border:1px solid rgba(29,185,84,.5);color:#8fe0ab}
  .etat-du{background:rgba(255,184,0,.14);border:1px solid rgba(255,184,0,.5);color:var(--jaune2)}
  .etat-expire{background:rgba(217,48,37,.16);border:1px solid rgba(217,48,37,.5);color:#FFB4AC}
  .etat-code{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.22);opacity:.85}
  /* Les trois degrés d'un abandon : plus la personne était allée loin, plus il coûte cher. */
  .etat-chaud{background:rgba(217,48,37,.16);border:1px solid rgba(217,48,37,.5);color:#FFB4AC}
  .etat-tiede{background:rgba(255,184,0,.14);border:1px solid rgba(255,184,0,.5);color:var(--jaune2)}
  .etat-froid{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.18);opacity:.8}
  .titre-bloc{margin:26px 0 12px; font-size:16px; letter-spacing:.08em; text-transform:uppercase;
              color:var(--jaune2); font-weight:900}

  /* L'avertissement « remis sans que le stock bouge » : le défaut du 10 août, qui doit rester
     VISIBLE ligne par ligne et pas seulement dans un total. */
  .alerte-stock{display:inline-block;margin-left:7px;padding:2px 7px;border-radius:7px;font-size:11px;
    font-weight:900;background:rgba(217,48,37,.18);border:1px solid rgba(217,48,37,.55);color:#FFB4AC}

  .rien{padding:26px 18px;text-align:center;opacity:.7;font-size:15px;line-height:1.6}
  .liens{margin-top:22px;border-top:1px solid rgba(255,255,255,.12);padding-top:14px;font-size:14px;
    display:flex;flex-wrap:wrap;gap:18px}
  .liens a{display:inline-flex;align-items:center;min-height:44px;color:var(--jaune);
    text-decoration:none;font-weight:700}
  .note{margin:16px 0 0;font-size:12px;opacity:.55;line-height:1.55}
</style>
</head>
<body>
<main>
  <h1>Historique de la roue</h1>
  <p class="sous">Ce qui a été gagné, ce qui a été remis, et ce qui reste dû.</p>

  <nav class="periodes" aria-label="Période">
    @foreach ($periodes as $p)
      <a href="{{ url('/admin/roue-historique?jours='.$p) }}"
         class="{{ $jours === $p ? 'on' : '' }}"
         @if ($jours === $p) aria-current="page" @endif>
        {{ $p === 1 ? "Aujourd'hui" : ($p === 7 ? '7 jours' : ($p === 30 ? '30 jours' : '90 jours')) }}
      </a>
    @endforeach
  </nav>

  @if (empty($lignes))
    {{-- Un état vide DOIT dire pourquoi il est vide et ce qu'on peut faire, sinon il est lu comme
         une panne. --}}
    <div class="enveloppe"><p class="rien">
      Aucun tour sur cette période.<br>
      Ce n'est pas une panne : si le jeu vient d'ouvrir, ou si personne n'a joué,
      c'est exactement ce qu'on doit lire ici. Essaie une période plus longue.
    </p></div>
  @else
    <div class="enveloppe">
      <table>
        <thead>
          <tr>
            <th scope="col">Quand</th>
            <th scope="col">Lot</th>
            <th scope="col">Client</th>
            <th scope="col">Code</th>
            <th scope="col">État</th>
            <th scope="col">Remis</th>
          </tr>
        </thead>
        <tbody>
        @foreach ($lignes as $l)
          <tr>
            <td class="quand">{{ $l['quand']?->format('d/m/Y H:i') }}</td>
            <td class="lot">{{ $l['lot'] }}</td>
            <td>
              @if ($l['prenom'] !== '')
                {{ $l['prenom'] }}
              @endif
              {{-- Les quatre derniers chiffres seulement : voir l'en-tête de ce fichier. --}}
              <span class="qui">…{{ $l['tel_fin'] }}</span>
            </td>
            <td class="code">
              @if ($l['code'] !== ''){{ $l['code'] }}@else<span class="vide">—</span>@endif
            </td>
            <td>
              @php
                $libelles = [
                  'remis'  => ['✓', 'Remis'],
                  'du'     => ['•', 'À remettre'],
                  'expire' => ['✕', 'Expiré'],
                  'code'   => ['#', 'Code à utiliser'],
                ];
                [$puce, $mot] = $libelles[$l['etat']] ?? ['?', $l['etat']];
              @endphp
              <span class="etat etat-{{ $l['etat'] }}">
                <span aria-hidden="true">{{ $puce }}</span>{{ $mot }}
              </span>
              @if ($l['etat'] === 'du' && $l['expire_le'])
                <span class="qui" style="margin-left:6px">jusqu'au {{ $l['expire_le']->format('d/m') }}</span>
              @endif
            </td>
            <td>
              @if ($l['remis_le'])
                <span class="quand">{{ $l['remis_le']->format('d/m H:i') }}</span>
                @if ($l['remis_par'])<span class="qui"> · {{ $l['remis_par'] }}</span>@endif
                {{-- Un cadeau remis dont le stock n'a pas bougé est le défaut mesuré le 10 août.
                     Il se voit ici, sur SA ligne — un total ne l'aurait jamais désigné. --}}
                @if (! $l['stock_bouge'] && $l['type'] === 'free_item')
                  <span class="alerte-stock" title="Le stock n'a pas été décrémenté pour ce cadeau">stock non décompté</span>
                @endif
              @else
                <span class="vide">—</span>
              @endif
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>

    <p class="note">
      Le numéro du client n'est jamais affiché en entier : seuls ses quatre derniers chiffres le
      sont, de quoi confirmer une identité qu'il vient d'annoncer. Au plus 200 lignes par période.
    </p>
  @endif

  {{-- ── LES PARCOURS COMMENCÉS ET JAMAIS TERMINÉS ────────────────────────────────────────
       [PROPRIÉTAIRE 2026-08-13] « voir la liste des clients qui ont joué et qui n'ont pas complété,
       et à quelle étape ».

       Pourquoi cette liste vaut plus que celle des gagnants : les gagnants, on les voit déjà — ils
       viennent chercher leur lot. Ceux qui abandonnent ne laissent AUCUNE trace visible, et ce sont
       eux qui disent si le parcours coince. Un jeu qui perd tout le monde à l'abonnement ne se voit
       nulle part ailleurs que dans ce tableau.

       Elle est ANONYME, et ce n'est pas un manque : tant que le tour n'est pas réclamé, on n'a ni
       nom ni téléphone — on ne demande l'identité qu'à la fin, exprès. Elle sert à mesurer OÙ ça
       bloque, jamais à rappeler quelqu'un. --}}
  <h2 class="titre-bloc">Parcours commencés et non terminés</h2>

  @if (empty($incomplets))
    <p class="note">
      Aucun parcours abandonné sur la période. Tous ceux qui ont scanné sont allés au bout.
    </p>
  @else
    <div class="enveloppe">
      <table>
        <thead>
          <tr>
            <th>Quand</th>
            <th>Jusqu'où il est allé</th>
            <th>Lot tombé</th>
            <th>Dernier signe</th>
          </tr>
        </thead>
        <tbody>
        @foreach ($incomplets as $i)
          <tr>
            <td>{{ \Illuminate\Support\Carbon::parse($i['quand'])->format('d/m/Y H:i') }}</td>
            <td>
              {{-- Le rang colore l'étape : plus il est allé loin, plus l'abandon est coûteux. --}}
              <span class="etat etat-{{ $i['rang'] >= 3 ? 'chaud' : ($i['rang'] >= 1 ? 'tiede' : 'froid') }}">
                {{ $i['etape'] }}
              </span>
            </td>
            <td>{{ $i['lot'] ?: '—' }}</td>
            <td>{{ \Illuminate\Support\Carbon::parse($i['dernier'])->diffForHumans() }}</td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
    <p class="note">
      Ces lignes n'ont ni nom ni numéro : l'identité n'est demandée qu'à la toute fin du parcours.
      C'est une mesure de l'endroit où ça bloque, pas une liste de gens à rappeler.
      <b>« A gagné mais n'a pas donné ses coordonnées »</b> est le plus coûteux : le lot était acquis.
    </p>
  @endif

  <div class="liens">
    <a href="{{ url('/admin/roue') }}">← L'accueil de la roue</a>
    <a href="{{ url('/admin/roue-lot') }}">→ Remettre un lot</a>
    <a href="{{ url('/admin/roue-reglages') }}">→ Réglages</a>
  </div>
</main>
</body>
</html>
