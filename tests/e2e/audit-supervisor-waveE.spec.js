/**
 * VAGUE E — CUISINE, ÉCRAN CLIENT ET ROUE. Phase CAPTURE seulement.
 *
 * Ce spec NE CORRIGE RIEN. Il sème des commandes, ouvre chaque surface, écrit le quartet
 * d'artefacts (png / dom.html / console.json / network.json) et EXTRAIT le texte réellement
 * rendu — en particulier la ligne des EXTRAS en cuisine, point critique de la vague.
 *
 * GARDE D'ENVIRONNEMENT. Le worktree a servi des pages en HTTP 200 alors que l'autoloader PHP
 * était amputé (1 244 fichiers manquants dans vendor/, dont thecodingmachine/safe) : le corps ne
 * contenait qu'un « Warning: require ... Failed to open stream ». Une capture prise dans cet état
 * ment. Chaque navigation passe donc par `allerVerifie()`, qui REFUSE de continuer si le HTML
 * porte une trace d'erreur PHP.
 *
 * SEMIS : préfixe EXCLUSIF `AUDE-` sur order_serial_no, nettoyé en début ET en fin de course.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsAdmin } = require('./helpers/login');

/**
 * [2026-09-01] Plus aucun identifiant d'article figé dans les fixtures de cette spec.
 * Cliquet : tests/js/e2eFixturesSansIdentifiantCode.spec.js. Le NOM est la clé stable —
 * les identifiants, eux, ont dérivé (relevé du 2026-08-25 : 27 des 36 identifiants codés
 * en dur dans tests/e2e/ ne visaient plus aucun article existant). On résout donc l'article
 * à l'exécution, dans la même commande artisan que le semis.
 *
 * On ne filtre PAS sur `status = 5` : semer un HISTORIQUE de commandes doit pouvoir viser un
 * article devenu inactif depuis (« Sandwich Classique » et « Big Tacos » sont a status = 10).
 * `orderBy('id')` rend la resolution deterministe : « Sandwich Classique » existe en double
 * parmi les articles non supprimes.
 */
function phpItemId(nom) {
    const n = String(nom).replace(/'/g, "\\'");
    return `(int) DB::table('items')->whereNull('deleted_at')->where('name', '${n}')->orderBy('id')->value('id')`;
}
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const RACINE = path.resolve(__dirname, '../..');
const SHOTS_DIR = path.resolve(__dirname, '__screenshots__/test-e2e-waveE');
const OBSERVE = path.join(SHOTS_DIR, 'observations.json');
const NBSP = String.fromCharCode(160);

fs.mkdirSync(SHOTS_DIR, { recursive: true });

const observations = {};
function noter(cle, valeur) {
  observations[cle] = valeur;
  fs.writeFileSync(OBSERVE, JSON.stringify(observations, null, 2));
  console.log(`\n[OBSERVÉ ${cle}] ${JSON.stringify(valeur, null, 2)}\n`);
}

/** Exécute du PHP dans l'application (même base que le serveur du port 8000). */
function tinker(php) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', php], {
    cwd: RACINE,
    encoding: 'utf8',
    timeout: 120_000,
  });
}

// ── LES COMMANDES SEMÉES ────────────────────────────────────────────────────────────────────
const SERIAL = 'AUDE-EXTRAS-1';
const SERIAL_OSS = 'AUDE-EXTRAS-OSS';
let ID_CUISINE = 0;
let ID_CLIENT = 0;
const SNAPSHOT_PHP =
  "['extras'=>[['extra_id'=>1,'extra_name'=>'Salade','quantity'=>1],['extra_id'=>2,'extra_name'=>'Cheddar','quantity'=>2]]]";

function lignePhpCommande(serial, typeCmd, surface, extra) {
  return [
    '$o=new \\App\\Models\\Order();',
    `$o->order_serial_no='${serial}';`,
    '$o->user_id=1;$o->branch_id=1;$o->subtotal=12.50;$o->total=12.50;',
    `$o->order_type=${typeCmd};`,
    `$o->source_surface='${surface}';`,
    extra,
    "$o->order_datetime=now('UTC');",
    '$o->preparation_time=5;',
    '$o->is_advance_order=\\App\\Enums\\Ask::NO;',
    '$o->payment_method=1;$o->payment_status=5;$o->status=7;',
    '$o->business_date=now()->toDateString();',
    '$o->save();',
    '$i=new \\App\\Models\\OrderItem();',
    '$i->order_id=$o->id;$i->branch_id=1;$i->item_id='+phpItemId('Cayenne')+';$i->quantity=1;',
    "$i->price=12.50;$i->total_price=12.50;$i->discount=0;$i->tax_rate='0';$i->tax_amount=0;",
    '$i->tax_type=1;$i->item_variation_total=0;$i->item_extra_total=0;',
    `$i->composition_snapshot=${SNAPSHOT_PHP};`,
    '$i->save();',
  ].join('');
}

/** Commande CUISINE : POS, telle que demandée par l'assignation. */
function semer() {
  const php =
    lignePhpCommande(SERIAL, '\\App\\Enums\\OrderType::POS', 'pos', '') +
    "echo 'ID_CUISINE='.$o->id;";
  const sortie = tinker(php);
  console.log('[SEMIS cuisine] ' + sortie.trim());
  const id = Number((sortie.match(/ID_CUISINE=(\d+)/) || [])[1] || 0);
  expect(id, 'la commande cuisine doit etre semee').toBeGreaterThan(0);
  return id;
}

/**
 * L'ÉCRAN CLIENT est fail-closed sur KIOSK/TAKEAWAY porteurs d'un `queue_number` ou d'un `token`
 * (OrderStatusScreenOrderService:45-63). Une commande POS n'y paraît JAMAIS — par conception.
 * Pour capturer le mur client AVEC du contenu, on sème une SECONDE commande AUDE-, à emporter et
 * numérotée, portant le MÊME instantané d'extras.
 */
function semerEcranClient() {
  const php =
    lignePhpCommande(SERIAL_OSS, '\\App\\Enums\\OrderType::TAKEAWAY', 'kiosk', "$o->queue_number='777';") +
    "echo 'ID_CLIENT='.$o->id;";
  const sortie = tinker(php);
  console.log('[SEMIS écran client] ' + sortie.trim());
  return Number((sortie.match(/ID_CLIENT=(\d+)/) || [])[1] || 0);
}

function nettoyer() {
  const php = [
    "$ids=\\DB::table('orders')->where('order_serial_no','like','AUDE-%')->pluck('id');",
    "\\DB::table('order_items')->whereIn('order_id',$ids)->delete();",
    "\\DB::table('orders')->whereIn('id',$ids)->delete();",
    "echo 'NETTOYE='.$ids->count();",
  ].join('');
  try {
    console.log('[NETTOYAGE] ' + tinker(php).trim());
  } catch (e) {
    console.warn('[NETTOYAGE] échec souple : ' + String(e.message).slice(0, 300));
  }
}

// ── GARDE D'ENVIRONNEMENT ───────────────────────────────────────────────────────────────────
const TRACES_PHP_CASSE = [
  /Warning:\s*require/i,
  /Fatal error/i,
  /Failed to open stream/i,
  /Uncaught Error:/i,
];

async function allerVerifie(page, url, { attendre = 'domcontentloaded' } = {}) {
  const rep = await page.goto(url, { waitUntil: attendre, timeout: 60_000 });
  const html = await page.content();
  for (const rx of TRACES_PHP_CASSE) {
    if (rx.test(html)) {
      const extrait = html.replace(/\s+/g, ' ').slice(0, 600);
      throw new Error(
        `ENVIRONNEMENT CASSÉ sur ${url} — le HTML porte une erreur PHP (${rx}). ` +
          `Capture refusée. Extrait : ${extrait}`
      );
    }
  }
  return { statut: rep ? rep.status() : null, taille: html.length };
}

/** Texte visible aplati, espaces insécables normalisés. */
async function texteVisible(page, selecteur, nbsp) {
  return page.evaluate(
    ([sel, esp]) => {
      const el = sel ? document.querySelector(sel) : document.body;
      if (!el) return null;
      return (el.innerText || '').split(esp).join(' ').replace(/[ \t]+/g, ' ').trim();
    },
    [selecteur, nbsp]
  );
}

test.describe.configure({ mode: 'serial' });

test.describe('VAGUE E — capture cuisine / écran client / roue', () => {
  test.beforeAll(() => {
    nettoyer(); // toute trace AUDE- d'un essai précédent
  });

  test.afterAll(() => {
    nettoyer();
  });

  // ── ÉTAT 1 — /kds au chargement (avant tout semis) ────────────────────────────────────────
  test('E1 — /kds au chargement', async ({ page }) => {
    const rec = attachMegaAuditRecorder(page, SHOTS_DIR);
    await loginAsAdmin(page);
    const s = await allerVerifie(page, '/kds');
    await page.waitForTimeout(7000);
    await rec.snap('01-kds-chargement');

    const etat = await page.evaluate(() => ({
      url: location.href,
      grilleV2: !!document.querySelector('[class*="kds-v2"]'),
      barre: !!document.querySelector('[data-testid="kds-toolbar"]'),
      cartes: document.querySelectorAll('.kds-card').length,
      numerosVus: [...document.querySelectorAll('.kds-card')].map(
        (c) => ((c.innerText || '').match(/N°\s*\S+/) || [''])[0]
      ),
      titre: document.title,
      corpsVide: (document.body.innerText || '').trim().length < 40,
    }));
    noter('E1_kds_chargement', { httpStatut: s.statut, htmlOctets: s.taille, ...etat });
    rec.dispose();
  });

  // ── ÉTAT 2 — /kds AVEC les extras de l'instantané NF525 (LE point critique) ───────────────
  test('E2 — /kds avec extras dinstantane (Salade / Cheddar)', async ({ page }) => {
    ID_CUISINE = semer();

    const rec = attachMegaAuditRecorder(page, SHOTS_DIR);
    await loginAsAdmin(page);
    const s = await allerVerifie(page, '/kds');
    await page.waitForTimeout(9000);
    await rec.snap('02-kds-extras-instantane-v2-defaut');

    // La carte V2 n'affiche PAS le numéro de série : elle affiche « N° {queue_number || id} »
    // (KdsOrderCard.vue:92). On la retrouve donc par l'id de la commande semée.
    const carte = await page.evaluate(
      ([id, esp]) => {
        const cartes = [...document.querySelectorAll('.kds-card')];
        const hote =
          cartes.find((c) => new RegExp('N°\\s*' + id + '\\b').test(c.innerText || '')) ||
          cartes.find((c) => /Cheddar/i.test(c.innerText || '')) ||
          null;
        const numeros = cartes.map((c) => ((c.innerText || '').match(/N°\s*\S+/) || [''])[0]);
        if (!hote) {
          return { carteTrouvee: false, cartesPresentes: cartes.length, numerosVus: numeros };
        }
        const brut = (hote.innerText || '').split(esp).join(' ');
        const lignes = [...hote.querySelectorAll('.kds-line')].map((l) => ({
          type: (String(l.className).match(/kds-line--([\w-]+)/) || [, ''])[1],
          texte: (l.innerText || '').split(esp).join(' ').replace(/\s+/g, ' ').trim(),
        }));
        return {
          carteTrouvee: true,
          cartesPresentes: cartes.length,
          numerosVus: numeros,
          classeCarte: hote.className,
          texteExact: brut,
          texteAplati: brut.replace(/\s*\n\s*/g, ' | ').replace(/[ \t]+/g, ' ').trim(),
          lignesTypees: lignes,
        };
      },
      [ID_CUISINE, NBSP]
    );

    const corps = (await texteVisible(page, null, NBSP)) || '';

    // La carte V2 replie « Salade » en symbole crudité « S » (kdsSymbolic CRUDITE_TABLE).
    // Le seul endroit où le mot complet peut apparaître à l'écran est la LÉGENDE, dépliée par
    // le bouton « Afficher les noms ». On capture cet état : il décide si la cuisine peut
    // relire le symbole.
    let legende = { ouverte: false };
    const boutonLegende = page.locator('[data-testid="kds-legend-toggle"]');
    if (await boutonLegende.isVisible().catch(() => false)) {
      await boutonLegende.click();
      await page.waitForTimeout(1200);
      await rec.snap('02b-kds-legende-symboles');
      legende = await page.evaluate(
        ([esp]) => {
          const p = document.querySelector('[data-testid="kds-symbol-legend"]');
          const t = p ? (p.innerText || '').split(esp).join(' ').replace(/\s*\n\s*/g, ' | ').trim() : '';
          return { ouverte: !!p, contientSalade: /salade/i.test(t), texte: t.slice(0, 600) };
        },
        [NBSP]
      );
    }

    noter('E2_kds_extras_v2_defaut', {
      legendeSymboles: legende,
      httpStatut: s.statut,
      idCommandeSemee: ID_CUISINE,
      ...carte,
      contientSaladeEnToutesLettres: /Salade/i.test(carte.texteExact || ''),
      contientCheddarEnToutesLettres: /Cheddar/i.test(carte.texteExact || ''),
      motifVirgulesVidesDansLaPage: /Extras\s*:\s*(,\s*)+/i.test(corps),
    });
    rec.dispose();
  });

  // ── ÉTAT 3 — la bascule V2 (drapeau ?v2=) ────────────────────────────────────────────────
  test('E3 — /kds bascule V2 (?v2=1) et repli herite (?v2=0)', async ({ page }) => {
    const rec = attachMegaAuditRecorder(page, SHOTS_DIR);
    await loginAsAdmin(page);

    // V2 explicite
    const sV2 = await allerVerifie(page, '/kds?v2=1');
    await page.waitForTimeout(8000);
    await rec.snap('03a-kds-v2-explicite');
    const v2 = await page.evaluate(() => ({
      grilleV2: !!document.querySelector('[class*="kds-v2"]'),
      plancheArticles: !!document.getElementById('item-order'),
      cartes: document.querySelectorAll('.kds-card').length,
    }));

    // Repli hérité — c'est CE gabarit qui porte kdsExtraDisplayName / le libellé « Extras: »
    const sLegacy = await allerVerifie(page, '/kds?v2=0');
    await page.waitForTimeout(9000);
    await rec.snap('03b-kds-herite-v2-0');
    const herite = await page.evaluate(
      ([serial, esp]) => {
        const corps = (document.body.innerText || '').split(esp).join(' ');
        // Le bloc parent du libellé « Extras: », tel qu'il est rendu, mot pour mot.
        const blocs = [...document.querySelectorAll('span,div,li,h3')]
          .filter((n) => /^\s*extras\s*:\s*$/i.test((n.textContent || '').trim()))
          .map((n) => {
            const parent = n.parentElement;
            return parent
              ? (parent.innerText || '').split(esp).join(' ').replace(/\s*\n\s*/g, ' ').replace(/\s+/g, ' ').trim()
              : '';
          });
        const noeud = [...document.querySelectorAll('*')].find(
          (n) => n.children.length === 0 && (n.textContent || '').includes(serial)
        );
        const carte = noeud
          ? noeud.closest('.kds-card') || noeud.closest('[class*="card"]') || noeud.parentElement
          : null;
        return {
          grilleV2: !!document.querySelector('[class*="kds-v2"]'),
          plancheArticles: !!document.getElementById('item-order'),
          blocsExtrasRendus: blocs,
          serialVisible: !!noeud,
          texteAutourDuSerial: carte
            ? (carte.innerText || '').split(esp).join(' ').replace(/\s*\n\s*/g, ' | ').trim().slice(0, 400)
            : null,
          motifVirgulesVides: /Extras\s*:\s*(,\s*)+/i.test(corps),
        };
      },
      [SERIAL, NBSP]
    );

    noter('E3_kds_bascule_v2', {
      note: 'V2 est le DEFAUT (config/kds.php v2_default_enabled=true) ; ?v2=0 est le repli herite.',
      v2Explicite: { httpStatut: sV2.statut, ...v2 },
      heriteV2Zero: { httpStatut: sLegacy.statut, ...herite },
    });
    rec.dispose();
  });

  // ── ÉTAT 4 — écran client ────────────────────────────────────────────────────────────────
  test('E4 — /admin/order-status-screen au chargement', async ({ page }) => {
    const rec = attachMegaAuditRecorder(page, SHOTS_DIR);
    await loginAsAdmin(page);

    // 4a — tel quel : la commande POS semée n'y a PAS sa place (liste blanche KIOSK/TAKEAWAY).
    const s = await allerVerifie(page, '/admin/order-status-screen');
    await page.waitForTimeout(8000);
    await rec.snap('04a-ecran-client-sans-commande-eligible');
    const sansCmd = await page.evaluate(
      ([serial, esp]) => {
        const corps = (document.body.innerText || '').split(esp).join(' ');
        return {
          url: location.href,
          titre: document.title,
          corpsOctets: corps.length,
          contientCommandePos: corps.includes(serial),
          libellesBruts: (corps.match(/\b(label|message|menu)\.[a-z_]+/gi) || []).slice(0, 20),
          extrait: corps.replace(/\s*\n\s*/g, ' | ').slice(0, 600),
        };
      },
      [SERIAL, NBSP]
    );

    // 4b — avec une commande éligible (à emporter, numérotée) : le mur doit l'annoncer.
    ID_CLIENT = semerEcranClient();
    await allerVerifie(page, '/admin/order-status-screen');
    await page.waitForTimeout(9000);
    await rec.snap('04b-ecran-client-avec-commande');
    const avecCmd = await page.evaluate(
      ([esp]) => {
        const corps = (document.body.innerText || '').split(esp).join(' ');
        return {
          corpsOctets: corps.length,
          contientNumero777: /\b777\b/.test(corps),
          motifsCasses: (corps.match(/undefined|NaN|\[object Object\]|null null/g) || []).slice(0, 10),
          extrait: corps.replace(/\s*\n\s*/g, ' | ').slice(0, 800),
        };
      },
      [NBSP]
    );

    noter('E4_ecran_client', {
      httpStatut: s.statut,
      htmlOctets: s.taille,
      note: 'OrderStatusScreenOrderService:45-63 est fail-closed KIOSK/TAKEAWAY + queue_number|token — une commande POS est exclue PAR CONCEPTION.',
      sansCommandeEligible: sansCmd,
      avecCommandeEligible: { idSeme: ID_CLIENT, ...avecCmd },
    });
    rec.dispose();
  });

  // ── ÉTAT 5 — les 6 pages « roue » ────────────────────────────────────────────────────────
  test('E5 — les 6 pages roue (acces direct, puis code de la maison)', async ({ page }) => {
    const rec = attachMegaAuditRecorder(page, SHOTS_DIR);

    const ECRANS = [
      ['roue', '/admin/roue'],
      ['roue-validation', '/admin/roue-validation'],
      ['roue-borne', '/admin/roue-borne'],
      ['roue-lot', '/admin/roue-lot'],
      ['roue-historique', '/admin/roue-historique'],
      ['roue-reglages', '/admin/roue-reglages'],
    ];

    // -- Passe A : accès direct par URL, sans rien déverrouiller. C'est ce que voit un poste
    //    qui tape l'adresse. On NE FORCE RIEN : on constate.
    const passeA = {};
    for (const [nom, url] of ECRANS) {
      const s = await allerVerifie(page, url);
      await page.waitForTimeout(900);
      await rec.snap(`05-A-${nom}-acces-direct`);
      passeA[nom] = {
        httpStatut: s.statut,
        urlFinale: page.url(),
        redirigeVersAccueil: /\/admin\/roue(\?|$)/.test(page.url()) && url !== '/admin/roue',
        texte: ((await texteVisible(page, null, NBSP)) || '').replace(/\s*\n\s*/g, ' | ').slice(0, 400),
      };
    }

    // -- Passe B : le chemin d'accès PRÉVU par le produit — le code de la maison saisi dans le
    //    formulaire de /admin/roue (EnsureWheelAccess, chemin 2). Ce n'est pas un contournement :
    //    c'est la porte que le middleware documente. Le code vient de l'environnement.
    let code = process.env.WHEEL_PIN || '';
    if (!code) {
      try {
        const env = fs.readFileSync(path.join(RACINE, '.env'), 'utf8');
        code = ((env.match(/^WHEEL_PIN=(.*)$/m) || [])[1] || '').trim();
      } catch (_e) {
        /* pas de .env lisible */
      }
    }

    const passeB = {};
    if (!code) {
      passeB.__note =
        "WHEEL_PIN absent de l'environnement : passe B non tentee, les 6 ecrans restent en etat verrouille (passe A).";
    } else {
      await allerVerifie(page, '/admin/roue');
      await page.locator('#pin').fill(code);
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30_000 }).catch(() => {}),
        page.locator('form[action*="ouvrir"] button, form[action*="ouvrir"] [type="submit"]').first().click(),
      ]);
      await page.waitForTimeout(800);

      for (const [nom, url] of ECRANS) {
        const s = await allerVerifie(page, url);
        await page.waitForTimeout(1400);
        await rec.snap(`05-B-${nom}`);
        const info = await page.evaluate(
          ([esp]) => {
            const corps = (document.body.innerText || '').split(esp).join(' ');
            return {
              titre: document.title,
              corpsOctets: corps.length,
              libellesBruts: (corps.match(/\b(label|message|menu)\.[a-z_]+/gi) || []).slice(0, 15),
              motifsCasses: (corps.match(/undefined|NaN|\[object Object\]|null null/g) || []).slice(0, 10),
              extrait: corps.replace(/\s*\n\s*/g, ' | ').slice(0, 700),
            };
          },
          [NBSP]
        );
        passeB[nom] = { httpStatut: s.statut, urlFinale: page.url(), ...info };
      }
    }

    noter('E5_roue', { passeA_accesDirect: passeA, passeB_codeMaison: passeB });
    rec.dispose();
  });
});
