{{--
  ÉCRAN D'ATTENTE DE LA TABLETTE — posé au comptoir, face aux clients.

  Il n'a qu'un seul travail : faire lever les yeux, et donner un QR à scanner. Personne ne le
  touche : ni l'équipe (elle a autre chose à faire pendant un service), ni le client (il scanne).
  Tout est donc automatique, y compris le renouvellement du jeton.

  POURQUOI LE QR SE RENOUVELLE. Le jeton est à usage unique et court : un QR figé serait consommé
  par le premier scan, et les suivants tomberaient sur « validation introuvable ». La page en
  redemande donc un régulièrement, et à chaque fois qu'il vient d'être utilisé.
  Effet de bord utile : une photo du QR partagée à l'extérieur ne vaut plus rien quelques minutes
  plus tard. Il faut être DEVANT le comptoir.
--}}
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Le Cayenne — Tourne la roue</title>
<meta name="robots" content="noindex, nofollow">
<style>
  :root{--orange:#F4501E;--orange2:#FF6A3D;--jaune:#FFB800;--jaune2:#FFD34D;--noir:#100E0C;--creme:#FFF6EC}
  *{box-sizing:border-box}
  html,body{margin:0;height:100%;overflow:hidden}
  body{
    background:radial-gradient(120% 90% at 50% -10%,#3A2418 0%,transparent 60%),
               radial-gradient(90% 60% at 50% 110%,#2A1A12 0%,transparent 55%), var(--noir);
    color:var(--creme); font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    display:grid; place-items:center; padding:3vmin;
    /* La tablette reste allumée toute la journée : aucune animation ne doit consommer inutilement,
       et rien ne clignote — un écran qui clignote au comptoir devient invisible en une heure. */
  }
  .scene{display:grid; grid-template-columns:1.05fr .95fr; gap:4vmin; align-items:center;
    width:100%; max-width:1500px}
  @media (max-aspect-ratio:1/1){ .scene{grid-template-columns:1fr; gap:2.5vmin} }

  .gauche{text-align:center}
  .logo{
    height:min(9vmin,90px); width:auto; display:block; margin:0 auto 2vmin;
    /* Lettrage sombre sur fond sombre : on l'éclaircit sans le rendre blanc cassant. */
    filter:brightness(0) invert(1) sepia(.16) saturate(1.6) hue-rotate(-12deg);
    opacity:.97;
  }
  h1{
    margin:0; font-size:min(13vmin,150px); line-height:.94; font-weight:900; letter-spacing:-.02em;
    background:linear-gradient(100deg,var(--jaune2),var(--orange2) 62%,var(--jaune));
    -webkit-background-clip:text; background-clip:text; color:transparent;
  }
  .sous{margin:2vmin 0 0; font-size:min(3.4vmin,34px); opacity:.9; line-height:1.35}
  .sous b{color:var(--jaune2)}

  .roue{width:min(52vmin,520px); height:min(52vmin,520px); margin:3vmin auto 0; display:block;
    filter:drop-shadow(0 3vmin 5vmin rgba(0,0,0,.5))}

  .droite{text-align:center}
  .qr{background:#fff; border-radius:3vmin; padding:2.4vmin; display:inline-block;
    box-shadow:0 2vmin 6vmin rgba(0,0,0,.45)}
  .qr svg{display:block; width:min(40vmin,400px); height:auto}
  .scanne{margin:2.4vmin 0 0; font-size:min(4.6vmin,46px); font-weight:900; letter-spacing:-.01em}
  .fleche{font-size:min(6vmin,60px); line-height:1; margin-bottom:1vmin; animation:pointe 2.2s ease-in-out infinite}
  @keyframes pointe{0%,100%{transform:translateY(0)}50%{transform:translateY(1.2vmin)}}
  .detail{margin:1.6vmin 0 0; font-size:min(2.5vmin,25px); opacity:.72; line-height:1.5}

  .err{background:rgba(217,48,37,.16); border:1px solid rgba(217,48,37,.5); border-radius:2vmin;
    padding:2vmin; font-size:min(2.8vmin,28px)}
  @media (prefers-reduced-motion:reduce){ .fleche{animation:none} }
</style>
</head>
<body>
<div class="scene">

  <div class="gauche">
    {{-- Le VRAI logo, en grand : c'est la première chose vue de loin, et c'est ce qui fait
         reconnaître l'écran comme celui du restaurant et non une publicité quelconque. --}}
    <img class="logo" src="{{ asset('images/kiosk-attract/logo.png') }}" alt="Le Cayenne">
    <h1>Tu gagnes<br>à 100 %</h1>
    <p class="sous">
      {{-- Un `@if` COLLÉ à un mot (« commande@if ») n'est pas reconnu comme directive par Blade :
           le `@endif` devient alors orphelin et la vue ne compile plus. On utilise une expression. --}}
      Un lot pour ta prochaine commande{!! !empty($minOrder)
          ? ', <b>dès ' . number_format($minOrder, 2, ',', ' ') . ' €</b>'
          : '' !!}.
    </p>
    <canvas class="roue" id="roue" width="720" height="720" aria-hidden="true"></canvas>
  </div>

  <div class="droite">
    @if (! empty($erreur))
      <p class="err">{{ $erreur }}</p>
    @else
      <div class="fleche" aria-hidden="true">↓</div>
      <div class="qr" id="qr">{!! $qr !!}</div>
      <p class="scanne">Scanne avec ton téléphone</p>
      <p class="detail">Aucune application à installer.<br>Ça prend moins d'une minute.</p>
    @endif
  </div>

</div>

<script>
(function () {
  'use strict';
  /* ── LA ROUE, dessinée en canvas ──────────────────────────────────────────────────────────
     Même objet que sur le téléphone du client : il doit RECONNAÎTRE la roue qu'il vient de voir
     sur la tablette. Une roue différente d'un écran à l'autre casse le lien entre les deux. */
  var SEGMENTS = @json($segments);
  var cv = document.getElementById('roue'), ctx = cv.getContext('2d');
  var PALETTE = [['#F4501E','#FFF6EC'],['#17140F','#FFB800'],['#FFB800','#3A1C00'],
                 ['#241A12','#FFD34D'],['#FF6A3D','#2A1508'],['#0F0D0A','#FFF6EC'],['#FFD34D','#3A1C00']];
  var angle = 0, moinsDAnim = false;
  try { moinsDAnim = matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}

  function ombrer(hex, t){
    var m=/^#?([0-9a-f]{6})$/i.exec(hex); if(!m) return hex;
    var v=parseInt(m[1],16), r=(v>>16)&255, g=(v>>8)&255, b=v&255;
    function f(x){return Math.max(0,Math.min(255,Math.round(t<0?x*(1+t):x+(255-x)*t)));}
    return 'rgb('+f(r)+','+f(g)+','+f(b)+')';
  }

  function dessiner(pulse){
    var n = SEGMENTS.length; if(!n) return;
    var W=cv.width, R=W/2, pas=(Math.PI*2)/n, Rr=R-30;
    ctx.clearRect(0,0,W,W);
    ctx.save(); ctx.translate(R,R);
    var an=ctx.createLinearGradient(-R,-R,R,R);
    an.addColorStop(0,'#5A3A1A'); an.addColorStop(.35,'#FFD98A'); an.addColorStop(.5,'#8A5A22');
    an.addColorStop(.7,'#FFE9B8'); an.addColorStop(1,'#4A2E12');
    ctx.beginPath(); ctx.arc(0,0,R-15,0,Math.PI*2); ctx.lineWidth=26; ctx.strokeStyle=an; ctx.stroke();
    for(var k=0;k<24;k++){
      var ak=(k/24)*Math.PI*2-Math.PI/2, vive=((k+Math.floor(pulse*6))%3)!==0;
      var bx=Math.cos(ak)*(R-15), by=Math.sin(ak)*(R-15);
      var g=ctx.createRadialGradient(bx,by,0,bx,by,vive?11:7);
      g.addColorStop(0,vive?'#FFFDF2':'#FFE9B8'); g.addColorStop(.45,vive?'#FFD34D':'#B98A3A');
      g.addColorStop(1,'rgba(255,184,0,0)');
      ctx.beginPath(); ctx.arc(bx,by,vive?11:7,0,Math.PI*2); ctx.fillStyle=g; ctx.fill();
    }
    ctx.restore();
    ctx.save(); ctx.translate(R,R); ctx.rotate(angle);
    for(var i=0;i<n;i++){
      var c=PALETTE[i%PALETTE.length], d=i*pas-Math.PI/2-pas/2;
      var gs=ctx.createRadialGradient(0,0,Rr*0.18,0,0,Rr);
      gs.addColorStop(0,ombrer(c[0],-0.22)); gs.addColorStop(.55,c[0]); gs.addColorStop(1,ombrer(c[0],0.10));
      ctx.beginPath(); ctx.moveTo(0,0); ctx.arc(0,0,Rr,d,d+pas); ctx.closePath();
      ctx.fillStyle=gs; ctx.fill();
      ctx.lineWidth=2.5; ctx.strokeStyle='rgba(0,0,0,.30)'; ctx.stroke();
      ctx.save(); var mi=d+pas/2; ctx.rotate(mi);
      var abs=((mi+angle)%(Math.PI*2)+Math.PI*2)%(Math.PI*2);
      var ret=abs>Math.PI/2&&abs<Math.PI*1.5; if(ret) ctx.rotate(Math.PI);
      ctx.textAlign=ret?'left':'right'; ctx.textBaseline='middle';
      var mots=String(SEGMENTS[i].label||'').split(' ');
      var l1=mots.length>1?mots.slice(0,Math.ceil(mots.length/2)).join(' '):mots[0];
      var l2=mots.length>1?mots.slice(Math.ceil(mots.length/2)).join(' '):'';
      var t=(l1.length>9||l2.length>9)?29:37;
      ctx.font='900 '+t+'px -apple-system, Helvetica, Arial, sans-serif';
      ctx.shadowColor='rgba(0,0,0,.45)'; ctx.shadowBlur=6; ctx.fillStyle=c[1];
      var x=ret?-(Rr-26):(Rr-26);
      if(l2){ctx.fillText(l1,x,-t*0.55);ctx.fillText(l2,x,t*0.55);}else{ctx.fillText(l1,x,0);}
      ctx.restore();
    }
    var ve=ctx.createLinearGradient(-Rr,-Rr,Rr*0.2,Rr*0.5);
    ve.addColorStop(0,'rgba(255,255,255,.20)'); ve.addColorStop(.45,'rgba(255,255,255,.05)');
    ve.addColorStop(.7,'rgba(0,0,0,.10)'); ve.addColorStop(1,'rgba(0,0,0,.22)');
    ctx.beginPath(); ctx.arc(0,0,Rr,0,Math.PI*2); ctx.fillStyle=ve; ctx.fill();
    ctx.restore();
  }

  var repos = 0;
  function boucle(t){
    /* OSCILLATION, pas rotation : une rotation continue retournerait les libellés à chaque
       demi-tour. Le balancement suffit à attirer l'œil, et c'est le chenillard qui porte le
       mouvement. Sur un écran allumé toute la journée, la sobriété est ce qui garde l'attention. */
    if(!moinsDAnim) angle = repos + Math.sin(t/1400)*0.05;
    dessiner((t%3000)/3000);
    requestAnimationFrame(boucle);
  }
  dessiner(0); requestAnimationFrame(boucle);

  /* ── RENOUVELLEMENT DU QR ─────────────────────────────────────────────────────────────────
     Le jeton est court et à usage unique : un QR figé serait consommé par le premier scan. On
     recharge donc la page à intervalle régulier — c'est le mécanisme le plus simple qui ne peut pas
     se désynchroniser, et une tablette au comptoir n'a rien d'autre à faire. */
  setTimeout(function () { location.reload(); }, {{ (int) $refreshMs }});
})();
</script>
</body>
</html>
