#!/usr/bin/env node
// tools/build-www.mjs — construit le paquet PUBLIABLE de l'app (dossier mobile/www/) à partir du prototype.
//
// Le prototype charge React en version DÉVELOPPEMENT et Babel depuis unpkg, puis compile le JSX DANS le
// téléphone à chaque lancement : lent, dépendant du réseau au démarrage (écran blanc sans internet ou si
// unpkg tombe) et refusé en l'état par une relecture de store. Ce script produit :
//   - chaque .jsx compilé à l'avance (esbuild, cible Safari 15 / Chrome 100) en un script classique,
//     chargé dans le MÊME ordre que index.html (même portée globale qu'avec Babel) ;
//   - React 18 production embarqué (vendor/react/) ;
//   - la configuration d'API de production (--prod) ou celle du serveur de dev (défaut) ;
//   - seulement les images réellement référencées par le code.
//
// Usage : node mobile/tools/build-www.mjs [--prod] [--out <dossier>]
//   --prod : apiBase = LC_API_BASE ou https://vps-418872ac.vps.ovh.net, apiKey = LC_API_KEY (obligatoire).
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);
const PROD = args.includes('--prod');
const OUT = path.resolve(args[args.indexOf('--out') + 1] && args.includes('--out') ? args[args.indexOf('--out') + 1] : path.join(ROOT, 'www'));

function trouverEsbuild() {
  const essais = [ROOT, path.resolve(ROOT, '..'), path.resolve(ROOT, '../../testttt')];
  for (const base of essais) {
    try { return createRequire(path.join(base, 'package.json'))('esbuild'); } catch (_) { /* suivant */ }
  }
  throw new Error('esbuild introuvable (npm i -D esbuild dans le dépôt)');
}
const esbuild = trouverEsbuild();
function trouverAcorn() {
  for (const base of [ROOT, path.resolve(ROOT, '..'), path.resolve(ROOT, '../../testttt')]) {
    try { return createRequire(path.join(base, 'package.json'))('acorn'); } catch (_) { /* suivant */ }
  }
  throw new Error('acorn introuvable');
}
const acorn = trouverAcorn();

// Babel (navigateur) abaissait const/let en var : deux fichiers pouvaient déclarer le même nom au niveau
// global. Compilé à l'avance, ce serait « Identifier already declared ». Chaque fichier est donc isolé
// dans sa propre portée, puis ses déclarations de premier niveau sont publiées sur window — même
// résultat qu'avec Babel (le dernier fichier chargé l'emporte).
function isoler(code, nom) {
  const ast = acorn.parse(code, { ecmaVersion: 'latest', sourceType: 'script' });
  const noms = new Set();
  const motif = (p) => {
    if (!p) return;
    if (p.type === 'Identifier') noms.add(p.name);
    else if (p.type === 'ObjectPattern') p.properties.forEach(q => motif(q.type === 'RestElement' ? q.argument : q.value));
    else if (p.type === 'ArrayPattern') p.elements.forEach(motif);
    else if (p.type === 'AssignmentPattern') motif(p.left);
    else if (p.type === 'RestElement') motif(p.argument);
  };
  for (const n of ast.body) {
    if (n.type === 'FunctionDeclaration' || n.type === 'ClassDeclaration') noms.add(n.id.name);
    if (n.type === 'VariableDeclaration') n.declarations.forEach(d => motif(d.id));
  }
  const liste = [...noms];
  return `/* ${nom} */\n(function () {\n${code}\n${liste.length ? `Object.assign(window, { ${liste.join(', ')} });` : ''}\n})();\n`;
}

const REACT_DIR = process.env.LC_REACT_DIR
  || ['vendor/react', '../../../lecayenne-web-deploy/Site lecayenne/vendor/react']
    .map(p => path.resolve(ROOT, p)).find(p => fs.existsSync(path.join(p, 'react.production.min.js')));
if (!REACT_DIR) throw new Error('React production introuvable : placer react.production.min.js et react-dom.production.min.js dans mobile/vendor/react/');

const html = fs.readFileSync(path.join(ROOT, 'index.html'), 'utf8');
fs.rmSync(OUT, { recursive: true, force: true });
fs.mkdirSync(path.join(OUT, 'js'), { recursive: true });

const copier = (rel) => {
  const src = path.join(ROOT, rel);
  if (!fs.existsSync(src)) return false;
  const dst = path.join(OUT, rel);
  fs.mkdirSync(path.dirname(dst), { recursive: true });
  fs.copyFileSync(src, dst);
  return true;
};

// 1. Configuration d'API
let config = html.match(/window\.LC\.config\s*=\s*\{[\s\S]*?\};/)[0];
if (PROD) {
  const base = process.env.LC_API_BASE || 'https://vps-418872ac.vps.ovh.net';
  const key = process.env.LC_API_KEY;
  if (!key) throw new Error('--prod exige LC_API_KEY (clé publique du client web, la même que le site)');
  config = config.replace(/apiBase:\s*'[^']*'/, `apiBase: '${base}'`).replace(/apiKey:\s*'[^']*'/, `apiKey: '${key}'`);
}

// 2. Scripts dans l'ordre de index.html
const balises = [...html.matchAll(/<script([^>]*)>([\s\S]*?)<\/script>/g)];
const sorties = [];
let n = 0;
for (const [, attrs, corps] of balises) {
  const src = (attrs.match(/src="([^"?]+)/) || [])[1];
  const babel = /type="text\/babel"/.test(attrs);
  if (src && /^https?:/.test(src)) {
    if (/react(-dom)?@/.test(src)) continue; // remplacé par la version production locale
    if (/babel/.test(src)) continue;          // plus de compilation dans le téléphone
    throw new Error('Dépendance distante inattendue : ' + src);
  }
  if (!src && /window\.LC\.config\s*=/.test(corps)) { sorties.push({ inline: `window.LC = window.LC || {};\n${config}` }); continue; }
  if (!src && /URLSearchParams\(window\.location\.search\)\.has\('dev'\)/.test(corps)) continue; // aides de dev exclues
  const code = src ? fs.readFileSync(path.join(ROOT, src), 'utf8') : corps;
  const nom = src ? src.replace(/[\/]/g, '__').replace(/\.(jsx|js)$/, '') : 'app-root';
  const res = babel || /\.jsx$/.test(src || '')
    ? esbuild.transformSync(code, { loader: 'jsx', jsx: 'transform', jsxFactory: 'React.createElement', jsxFragment: 'React.Fragment', target: ['safari15', 'chrome100'], minify: PROD, sourcefile: src || 'index.html' }).code
    : (PROD ? esbuild.transformSync(code, { loader: 'js', target: ['safari15', 'chrome100'], minify: true }).code : code);
  const fichier = `js/${String(++n).padStart(2, '0')}-${nom}.js`;
  fs.writeFileSync(path.join(OUT, fichier), (babel || /\.jsx$/.test(src || '')) ? isoler(res, src || 'index.html') : res);
  sorties.push({ src: fichier });
}

// 3. React production local
fs.mkdirSync(path.join(OUT, 'vendor/react'), { recursive: true });
for (const f of ['react.production.min.js', 'react-dom.production.min.js']) fs.copyFileSync(path.join(REACT_DIR, f), path.join(OUT, 'vendor/react', f));

// 4. Page d'entrée : mêmes styles, scripts compilés, React local avant les composants.
const avantScripts = html.slice(0, html.indexOf('<script'));
const idxReact = sorties.findIndex(s => s.src && /image-slot/.test(s.src));
const tags = sorties.map(s => s.inline ? `<script>\n${s.inline}\n</script>` : `<script src="${s.src}"></script>`);
tags.splice(idxReact < 0 ? tags.length : idxReact, 0,
  '<script src="vendor/react/react.production.min.js"></script>',
  '<script src="vendor/react/react-dom.production.min.js"></script>');
const page = avantScripts + tags.join('\n') + '\n</body>\n</html>\n';
fs.writeFileSync(path.join(OUT, 'index.html'), page);

// 5. Feuilles de style + images référencées
for (const css of [...html.matchAll(/href="([^"?:]+\.css)/g)].map(m => m[1])) copier(css);
const textes = fs.readdirSync(path.join(OUT, 'js')).map(f => fs.readFileSync(path.join(OUT, 'js', f), 'utf8')).join('\n')
  + ['styles.css', 'redesigns-styles.css'].map(f => fs.existsSync(path.join(ROOT, f)) ? fs.readFileSync(path.join(ROOT, f), 'utf8') : '').join('\n');
const refs = new Set();
for (const m of textes.matchAll(/(?:assets|uploads)\/[\w./%-]+?\.(?:png|webp|jpe?g|svg|gif)/gi)) refs.add(decodeURIComponent(m[0]));
for (const m of textes.matchAll(/['"`]([\w.-]+\.(?:png|webp|jpe?g|svg))['"`]/gi)) {
  for (const dir of ['assets/menu/', 'assets/', 'uploads/']) if (fs.existsSync(path.join(ROOT, dir + m[1]))) refs.add(dir + m[1]);
}
let copies = 0, manquants = [];
for (const r of refs) (copier(r) ? copies++ : manquants.push(r));
for (const extra of ['vendor/qrcode.js']) copier(extra);

const taille = (d) => fs.readdirSync(d, { withFileTypes: true }).reduce((s, e) => s + (e.isDirectory() ? taille(path.join(d, e.name)) : fs.statSync(path.join(d, e.name)).size), 0);
console.log(JSON.stringify({ sortie: OUT, mode: PROD ? 'production' : 'dev', scripts: sorties.length, images: copies, images_absentes: manquants, taille_Mo: +(taille(OUT) / 1048576).toFixed(1) }, null, 1));
