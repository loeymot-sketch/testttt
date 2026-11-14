// TOUR 2, vague A : enregistreur à budget de PNG (disque ≈ 1,5 Go). Le quatuor DOM/console/réseau est
// toujours écrit ; le PNG n'est écrit que si le nom est dans la liste blanche ET sous le plafond du processus.
const { attachMegaAuditRecorder: brut } = require('../../e2e/helpers/mega-audit-snap');
const ALLOW = process.env.R2_ALLOW ? new RegExp(process.env.R2_ALLOW) : /viandes|essai|^09-mobile-refus|^16-mobile-confirmation|^D-confirmation$/;
const CAP = Number(process.env.R2_PNG_CAP || 2);
let used = 0;
function attachMegaAuditRecorder(page, dir) {
  const r = brut(page, dir);
  return { ...r, snap: async (name) => {
    const ok = ALLOW.test(String(name)) && used < CAP;
    if (ok) { used += 1; return r.snap(name); }
    const orig = page.screenshot.bind(page);
    page.screenshot = async () => Buffer.alloc(0);
    try { return await r.snap(name); } finally { page.screenshot = orig; }
  } };
}
module.exports = { attachMegaAuditRecorder };
