// Le Cayenne — WizardRedeem (V0, DEC-12 in 99_VERDICT.md §2)
//
// 3-step bottom-sheet wizard replacing ModalRedeem. Per Agent-4 §5 layout.
//
// State machine :
//   idle → step1 → cancel→close  | continue→step2
//   step2 → back→step1            | apply→Promise (LC.dev.redeemReward)
//                                 ├─ ok=true            → step3 success
//                                 ├─ INSUFFICIENT_POINTS → close + toast
//                                 └─ other error         → step2 + retry banner
//   step3 → close (one-shot — back is blocked)
//
// Idempotency : key generated once at step1 via
// LC.loyaltyRewardState.redemptionIdempotencyKey(user_id, reward_id)
// = `redeem-{user}-{reward}-{floor(now/10min)}`. Same key on back→retry
// within the 10-min window. Boundary-crossing replay is a KNOWN GAP (V0-only).

(function () {
  'use strict';
  const { useState, useEffect, useRef } = React;

  function WizardRedeem({ ctx, onClose, onSuccess }) {
    const LC = window.LC;
    const reward = (ctx && ctx.reward != null && LC.loyalty.rewardById)
      ? LC.loyalty.rewardById(ctx.reward)
      : null;
    const account = LC.loyalty.account;
    const userId = account.user_id;

    const [step, setStep] = useState(1);
    const [error, setError] = useState(null);
    const [inflight, setInflight] = useState(false);
    const [result, setResult] = useState(null);
    const idempotencyKeyRef = useRef(null);

    // Generate idempotency key once when wizard opens. Re-tries within the
    // same 10-min window use the SAME key (dedupe on Phase 6 server). Back-nav
    // doesn't regenerate.
    useEffect(() => {
      if (reward && !idempotencyKeyRef.current && LC.loyaltyRewardState) {
        idempotencyKeyRef.current = LC.loyaltyRewardState.redemptionIdempotencyKey(userId, reward.id);
      }
    }, [reward, userId, LC.loyaltyRewardState]);

    if (!reward) {
      return (
        <window.ModalShell onClose={onClose}>
          <div role="alert" style={{ padding: 20, textAlign: 'center' }}>
            <div style={{ fontSize: 32, marginBottom: 8 }}>⚠️</div>
            <h2 className="lc-display" style={{ fontSize: 24, margin: '4px 0' }}>Récompense introuvable</h2>
            <p style={{ color: 'var(--gray-4)', fontSize: 13 }}>Réessaye depuis l'onglet Récompenses.</p>
            <button onClick={onClose} className="lc-btn lc-btn--ink" style={{ marginTop: 16, height: 48 }}>Fermer</button>
          </div>
        </window.ModalShell>
      );
    }

    const balanceBefore = account.balance;
    const balanceAfter = balanceBefore - reward.points_cost;
    const canRedeem = balanceBefore >= reward.points_cost;

    const onApply = () => {
      if (!canRedeem) {
        setError({ kind: 'INSUFFICIENT_POINTS', message: 'Solde insuffisant pour cette récompense.' });
        return;
      }
      setError(null);
      setInflight(true);
      // V0 uses LC.dev.redeemReward (atomic localStorage debit + history entry).
      // Phase 6 will POST /api/v1/frontend/loyalty/redeem with X-Idempotency-Key
      // header = idempotencyKeyRef.current.
      LC.dev.redeemReward(reward.id, { idempotency_key: idempotencyKeyRef.current })
        .then(r => {
          if (r.ok) {
            // [test-e2e fix D-009 round-2 2026-05-11] replay → show "Déjà échangée"
            // banner instead of "Échangé !" + zero balance change toast (balance
            // already debited on the original call).
            setResult({
              code: 'LCY-' + (idempotencyKeyRef.current || '').slice(-6).toUpperCase(),
              balance_after: r.balance_after,
              replayed: !!r.replayed,
            });
            setStep(3);
            if (onSuccess) onSuccess(r);
          } else if (r.error === 'INSUFFICIENT_POINTS') {
            setError({ kind: 'INSUFFICIENT_POINTS', message: 'Solde insuffisant — quelqu\'un a peut-être déjà utilisé tes points.' });
          } else {
            setError({ kind: 'NETWORK', message: 'Erreur — réessaye.' });
          }
        })
        .finally(() => setInflight(false));
    };

    const onShare = () => {
      const text = 'J\'ai débloqué une récompense Le Cayenne : ' + reward.name + ' 🎉';
      if (navigator.share) {
        navigator.share({ title: 'Le Cayenne', text }).catch(() => {});
      } else if (navigator.clipboard) {
        navigator.clipboard.writeText(text).catch(() => {});
      }
    };

    const onSaveForLater = () => {
      if (LC.storage.setPendingRedemption) {
        LC.storage.setPendingRedemption({
          reward_id: reward.id,
          reward_name: reward.name,
          points: reward.points_cost,
          code: 'LCY-' + (idempotencyKeyRef.current || '').slice(-6).toUpperCase(),
          created_at: Date.now(),
          ttl_days: 30,
        });
      }
      onApply();
    };

    // Confetti decorator (reused from ModalPointsGain) — only step 3 on FIRST debit
    // [test-e2e fix D-009 round-2 2026-05-11] no confetti on replay (nothing happened)
    const confetti = (step === 3 && result && !result.replayed) ? Array.from({ length: 18 }).map((_, i) => {
      const colors = ['#FF5A1F', '#FFD93D', '#0A0A0A', '#1FA653'];
      const c = colors[i % colors.length];
      const left = (i * 5.6) % 100;
      const delay = (i % 6) * 0.18;
      return <span key={i} aria-hidden="true" style={{ position: 'absolute', top: -20, left: left + '%', width: 8, height: 12, background: c, animation: 'lc-confetti 3.4s ' + delay + 's ease-in infinite' }}/>;
    }) : null;

    const StepDots = (
      <div role="group" aria-label={'Étape ' + step + ' sur 3'} style={{ display: 'flex', gap: 6, justifyContent: 'center', marginBottom: 8 }}>
        {[1, 2, 3].map(n => (
          <div key={n} style={{ width: n === step ? 20 : 6, height: 6, borderRadius: 999, background: n <= step ? 'var(--orange)' : 'var(--gray-2)', transition: 'all 0.2s' }}/>
        ))}
      </div>
    );

    return (
      <div data-testid="modal" data-modal-kind="redeem-wizard" role="dialog" aria-modal="true" aria-labelledby="wizard-redeem-title">
        <window.ModalShell onClose={onClose}>
          <div data-testid="redeem-wizard" data-step={step}>
            {confetti}
            {StepDots}

            {/* STEP 1 — Preview & confirm */}
            {step === 1 && (
              <>
                <div style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '4px 10px', background: 'var(--orange-text)', color: '#fff', borderRadius: 999, fontSize: 10, fontWeight: 700, letterSpacing: '0.12em', textTransform: 'uppercase' }}>★ Récompense</div>
                <h2 id="wizard-redeem-title" className="lc-display" style={{ margin: '12px 0 4px', fontSize: 32, lineHeight: 0.92, color: 'var(--ink)' }}>
                  Confirmer<br/>l'échange ?
                </h2>
                <p style={{ margin: '0 0 18px', color: 'var(--gray-4)', fontSize: 13 }}>Tu peux annuler avant l'étape 2.</p>

                <div style={{ background: 'var(--cream)', borderRadius: 16, padding: 16, marginBottom: 12 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 10 }}>
                    <div style={{ fontSize: 32 }}>{reward.icon}</div>
                    <div>
                      <div style={{ fontSize: 11, fontWeight: 700, letterSpacing: '0.14em', textTransform: 'uppercase', color: 'var(--gray-4)' }}>Tu reçois</div>
                      <div style={{ fontWeight: 700, fontSize: 17, marginTop: 2, color: 'var(--ink)' }}>{reward.name}</div>
                    </div>
                  </div>
                  <div style={{ display: 'flex', justifyContent: 'space-between', paddingTop: 10, borderTop: '1px solid var(--gray-2)', fontSize: 12, color: 'var(--gray-4)' }}>
                    <span>Solde avant</span>
                    <span style={{ fontWeight: 700, color: 'var(--ink)' }}>{balanceBefore} pts</span>
                  </div>
                  <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 12, color: 'var(--gray-4)' }}>
                    <span>Coût</span>
                    <span style={{ fontWeight: 700, color: 'var(--orange-text)' }}>−{reward.points_cost} pts</span>
                  </div>
                  <div style={{ display: 'flex', justifyContent: 'space-between', paddingTop: 6, borderTop: '2px solid var(--ink)', marginTop: 6, fontSize: 13 }}>
                    <span style={{ fontWeight: 700 }}>Solde après</span>
                    <span className="lc-display" style={{ fontSize: 22, color: balanceAfter >= 0 ? 'var(--green-text)' : 'var(--red-text)' }}>{balanceAfter} pts</span>
                  </div>
                </div>

                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1.4fr', gap: 10, marginTop: 14 }}>
                  <button data-testid="redeem-cancel-btn" onClick={onClose} className="lc-btn" style={{ background: 'var(--cream)', color: 'var(--ink)', height: 52 }}>Annuler</button>
                  <button data-testid="redeem-next-btn" onClick={() => canRedeem && setStep(2)} disabled={!canRedeem} aria-disabled={!canRedeem} className="lc-btn" style={{ background: canRedeem ? 'var(--ink)' : 'var(--gray-2)', color: canRedeem ? '#fff' : 'var(--gray-4)', height: 52 }}>Continuer →</button>
                </div>
              </>
            )}

            {/* STEP 2 — Timing choice */}
            {step === 2 && (
              <>
                <h2 className="lc-display" style={{ margin: 0, fontSize: 28, lineHeight: 0.95, color: 'var(--ink)' }}>Quand utiliser<br/>cette récompense ?</h2>
                <p style={{ margin: '8px 0 18px', color: 'var(--gray-4)', fontSize: 13 }}>{reward.name} · −{reward.points_cost} pts</p>

                {error && (
                  <div role="alert" data-testid="redeem-error-banner" style={{ background: 'var(--red-text)', color: '#fff', borderRadius: 10, padding: 12, marginBottom: 12, fontSize: 13 }}>{error.message}</div>
                )}

                <button
                  data-testid="redeem-apply-now-btn"
                  onClick={onApply}
                  disabled={inflight}
                  aria-busy={inflight}
                  style={{ display: 'block', width: '100%', textAlign: 'left', background: 'var(--ink)', color: '#fff', borderRadius: 16, padding: 16, border: 0, marginBottom: 10, cursor: inflight ? 'wait' : 'pointer' }}
                >
                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 6 }}>
                    <span style={{ fontSize: 10, fontWeight: 700, letterSpacing: '0.16em', textTransform: 'uppercase', color: 'var(--orange)' }}>★ Recommandé</span>
                    <span style={{ fontSize: 18, color: 'var(--orange)' }}>{inflight ? '…' : '→'}</span>
                  </div>
                  <div style={{ fontSize: 17, fontWeight: 700, color: '#fff' }}>Appliquer maintenant</div>
                  <div style={{ fontSize: 11, color: 'rgba(255,255,255,0.7)', marginTop: 4 }}>Code généré à présenter à la caisse pour ta prochaine commande</div>
                </button>

                <button
                  data-testid="redeem-save-later-btn"
                  onClick={onSaveForLater}
                  disabled={inflight}
                  style={{ display: 'block', width: '100%', textAlign: 'left', background: 'var(--cream)', color: 'var(--ink)', borderRadius: 16, padding: 16, border: 0, marginBottom: 14, cursor: inflight ? 'wait' : 'pointer' }}
                >
                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                    <span style={{ fontSize: 17, fontWeight: 700 }}>Garder dans mes récompenses</span>
                    <span style={{ fontSize: 18 }}>→</span>
                  </div>
                  <div style={{ fontSize: 11, color: 'var(--gray-4)', marginTop: 4 }}>Code valable 30 jours, activable plus tard</div>
                </button>

                <button data-testid="redeem-back-btn" onClick={() => setStep(1)} disabled={inflight} style={{ display: 'block', width: '100%', background: 'transparent', border: 0, padding: 12, fontSize: 12, fontWeight: 700, color: 'var(--gray-4)', cursor: 'pointer', letterSpacing: '0.08em', textTransform: 'uppercase' }}>← Retour</button>
              </>
            )}

            {/* STEP 3 — Success + share (or replay banner — D-009) */}
            {step === 3 && result && (
              <div data-testid="redeem-success" data-replayed={result.replayed ? 'true' : 'false'}>
                <div style={{ textAlign: 'center', marginBottom: 10 }}>
                  <div style={{ fontSize: 48 }}>{reward.icon}</div>
                </div>
                {/* [test-e2e fix D-009 round-2 2026-05-11] distinct heading on replay */}
                <h2 className="lc-display" style={{ margin: 0, fontSize: 30, lineHeight: 0.92, color: 'var(--ink)', textAlign: 'center' }}>
                  {result.replayed ? 'Déjà échangée' : 'Échangé !'}
                </h2>
                <p style={{ margin: '8px 0 18px', color: 'var(--gray-4)', fontSize: 13, textAlign: 'center' }}>
                  {result.replayed
                    ? 'Cette récompense est déjà active — aucun point supplémentaire n\'a été débité.'
                    : reward.name + ' t\'attend à la caisse.'}
                </p>

                {result.replayed && (
                  <div role="status" data-testid="redeem-replay-banner" style={{ background: 'var(--yellow-soft, #FFF4D6)', color: 'var(--ink)', borderRadius: 12, padding: 12, marginBottom: 14, fontSize: 12, display: 'flex', alignItems: 'center', gap: 8 }}>
                    <span aria-hidden="true">ℹ︎</span>
                    <span>Tu as déjà confirmé l'échange. Présente le code ci-dessous à la caisse.</span>
                  </div>
                )}

                <div style={{ background: 'var(--ink)', color: '#fff', borderRadius: 16, padding: 18, textAlign: 'center', marginBottom: 14 }}>
                  <div className="lc-eyebrow" style={{ color: 'var(--yellow)' }}>Code à présenter</div>
                  <div data-testid="redeem-success-code" style={{ fontFamily: 'var(--font-mono)', fontSize: 24, fontWeight: 700, letterSpacing: '0.12em', marginTop: 6 }}>{result.code}</div>
                  <div style={{ fontSize: 11, color: 'rgba(255,255,255,0.7)', marginTop: 6 }}>Solde : {result.balance_after} pts</div>
                </div>

                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1.4fr', gap: 10 }}>
                  <button data-testid="redeem-share-btn" onClick={onShare} className="lc-btn" style={{ background: 'var(--cream)', color: 'var(--ink)', height: 50 }}>Partager</button>
                  <button data-testid="redeem-close-btn" onClick={onClose} className="lc-btn" style={{ background: 'var(--orange)', color: '#fff', height: 50 }}>Fermer</button>
                </div>
              </div>
            )}
          </div>
        </window.ModalShell>
      </div>
    );
  }

  Object.assign(window, { WizardRedeem });
})();
