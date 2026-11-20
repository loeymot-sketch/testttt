/**
 * labelAlarm.js — [ETIQUETTES 2026-10-16] Signal sonore quand l'impression AUTOMATIQUE est bloquée alors que des
 * commandes attendent : une tablette posée sans surveillance doit pouvoir appeler quelqu'un (rouleau vide, capot
 * ouvert, imprimante éteinte). Trois bips, espacés au moins de 20 s. Jamais d'exception : sans audio, on se tait.
 */
export const ALARM_MIN_INTERVAL_MS = 20000;

export function createAlarm({ AudioCtx = typeof window !== 'undefined' ? (window.AudioContext || window.webkitAudioContext) : undefined, now = Date.now } = {}) {
    let last = 0;
    let ctx = null;
    return {
        /** @returns {boolean} un signal a-t-il été émis ? */
        ring() {
            if (!AudioCtx || now() - last < ALARM_MIN_INTERVAL_MS) return false;
            last = now();
            try {
                ctx = ctx || new AudioCtx();
                if (ctx.state === 'suspended' && ctx.resume) ctx.resume();
                [0, 0.32, 0.64].forEach((offset) => {
                    const osc = ctx.createOscillator();
                    const gain = ctx.createGain();
                    osc.type = 'square';
                    osc.frequency.value = 880;
                    gain.gain.value = 0.12;
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    osc.start(ctx.currentTime + offset);
                    osc.stop(ctx.currentTime + offset + 0.18);
                });
                return true;
            } catch (_) {
                return false;
            }
        },
    };
}
