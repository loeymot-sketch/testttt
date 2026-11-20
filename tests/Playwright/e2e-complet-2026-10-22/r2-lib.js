// Tour 2 : enregistreur à QUOTA de PNG (disque ~1,5 Go). Au-delà du quota, on n'écrit que console/network (JSON, quelques Ko).
const fs = require('fs');
const path = require('path');
const { attachMegaAuditRecorder } = require('../../e2e/helpers/mega-audit-snap');
const DIR = path.resolve(__dirname, '../../e2e/__screenshots__/e2e-complet-B-r2');
const QUOTA = Number(process.env.R2_PNG_QUOTA || 6);
fs.mkdirSync(DIR, { recursive: true });
const nbPng = () => fs.readdirSync(DIR).filter((f) => f.endsWith('.png')).length;
function attachR2(page, _dir) {
  const real = attachMegaAuditRecorder(page, DIR);
  // wrapper: quota ; sinon on écrit seulement un marqueur minimal
  const snap = async (name) => {
    if (nbPng() < QUOTA) return real.snap(name);
    fs.writeFileSync(path.join(DIR, `${name}.sansPNG.txt`), 'quota PNG atteint');
  };
  return { snap, dispose: real.dispose };
}
async function shot(page, opts) {
  if (nbPng() >= Number(process.env.R2_PNG_MAX || 15)) return;
  return page.screenshot(opts);
}
module.exports = { attachR2, shot, DIR, nbPng };
