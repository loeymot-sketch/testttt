// Le Cayenne — Critical modals + extra screens (Payment choice, +25 confetti,
// Redeem confirm, Card linking, Order detail, empty states, Toast)
const { useState: useState_m, useEffect: useEffect_m, useRef: useRef_m } = React;

// ---------- generic modal shell ----------
// A11-006 P1 round-3 2026-05-11 — accepts labelledBy + emits role="dialog" +
// aria-modal="true" + ESC keydown close + initial focus on first focusable
// element inside the sheet. Modal wrapper outer div has zero dimensions
// (layout-less) ; the inner sheet has role+aria attributes for screen readers.
function ModalShell({ children, onClose, height = 'auto', labelledBy, dataModalKind }) {
  const sheetRef = useRef_m(null);
  useEffect_m(() => {
    const handler = (e) => { if (e.key === 'Escape') onClose(); };
    window.addEventListener('keydown', handler);
    // Move focus to first focusable inside sheet (a11y SR + keyboard nav).
    const focusable = sheetRef.current?.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
    if (focusable) {
      try { focusable.focus({ preventScroll: true }); } catch (_e) { focusable.focus(); }
    }
    return () => window.removeEventListener('keydown', handler);
  }, [onClose]);
  return (
    <div style={{ position: 'absolute', inset: 0, zIndex: 50 }} data-modal-kind={dataModalKind}>
      <div onClick={onClose} aria-hidden="true" style={{ position: 'absolute', inset: 0, background: 'rgba(10,10,10,0.55)' }}/>
      <div
        ref={sheetRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={labelledBy}
        style={{ position: 'absolute', left: 0, right: 0, bottom: 0, background: '#fff', borderRadius: '24px 24px 0 0', padding: '14px 20px 32px', maxHeight: '85%', height, overflowY: 'auto', boxShadow: '0 -12px 30px rgba(0,0,0,0.18)' }}
      >
        <div style={{ width: 44, height: 4, borderRadius: 999, background: 'var(--gray-2)', margin: '0 auto 14px' }} aria-hidden="true"/>
        {children}
      </div>
    </div>
  );
}

// ---------- F.2  Payment choice ----------
function ModalPayChoice({ onClose, onPickCounter, onPickCard, total = 33 }) {
  return (
    <ModalShell onClose={onClose} labelledBy="modal-pay-title" dataModalKind="pay">
      <h2 id="modal-pay-title" className="lc-display" style={{ margin: 0, fontSize: 36, lineHeight: 0.92, color: 'var(--ink)' }}>Comment<br/>tu paies ?</h2>
      <p style={{ margin: '8px 0 18px', color: 'var(--gray-3)', fontSize: 13 }}>Total {total.toFixed(2).replace('.', ',')} € · TVA incluse</p>

      <button onClick={onPickCounter} style={{ display: 'block', width: '100%', textAlign: 'left', background: 'var(--ink)', color: '#fff', borderRadius: 18, padding: 18, border: 0, marginBottom: 12, cursor: 'pointer' }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 6 }}>
          <span style={{ fontSize: 11, fontWeight: 700, letterSpacing: '0.16em', textTransform: 'uppercase', color: 'var(--orange)' }}>★ Recommandé</span>
          <span style={{ fontSize: 22, color: 'var(--orange)' }}>→</span>
        </div>
        <div className="lc-display" style={{ fontSize: 26, lineHeight: 1, color: '#fff' }}>Payer à la caisse</div>
        <div style={{ fontSize: 12, color: 'rgba(255,255,255,0.7)', marginTop: 6 }}>Cash ou CB sur place. Ton plat t'attend.</div>
      </button>

      <button onClick={onPickCard} style={{ display: 'block', width: '100%', textAlign: 'left', background: 'var(--cream)', color: 'var(--ink)', borderRadius: 18, padding: 18, border: 0, cursor: 'pointer' }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 6 }}>
          <span style={{ fontSize: 11, fontWeight: 700, letterSpacing: '0.16em', textTransform: 'uppercase', color: 'var(--gray-3)' }}>CB sécurisée Stripe</span>
          <span style={{ fontSize: 22, color: 'var(--ink)' }}>→</span>
        </div>
        <div className="lc-display" style={{ fontSize: 26, lineHeight: 1, color: 'var(--ink)' }}>Payer maintenant</div>
        <div style={{ fontSize: 12, color: 'var(--gray-4)', marginTop: 6 }}>Visa · Mastercard · Apple Pay</div>
      </button>
    </ModalShell>
  );
}

// ---------- Stripe placeholder ----------
function ScreenStripe({ go, total = 33 }) {
  return (
    <div data-screen-label="11b Stripe" style={{ position: 'absolute', inset: 0, background: '#fff' }}>
      <div className="lc-screen" style={{ paddingBottom: 120 }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '4px 20px 8px' }}>
          <button onClick={() => go('cart')} style={{ width: 36, height: 36, borderRadius: 999, background: 'var(--cream)', border: 0, color: 'var(--ink)', cursor: 'pointer' }}>←</button>
          <div style={{ fontSize: 12, fontWeight: 700, letterSpacing: '0.18em', textTransform: 'uppercase', color: 'var(--ink)' }}>Paiement</div>
          <div style={{ width: 36 }}/>
        </div>
        <div style={{ padding: '10px 20px 0' }}>
          <div className="lc-display" style={{ fontSize: 44, lineHeight: 0.9, color: 'var(--ink)' }}>{total.toFixed(2).replace('.', ',')} €</div>
          <div style={{ fontSize: 12, color: 'var(--gray-3)', marginTop: 4 }}>Réservé sur ta CB jusqu'au retrait</div>
        </div>
        {/* [W3 HEAL P1-HONESTY 2026-06-06] §0.2 mock boundary — this prototype is
            decoupled: no real charge / no PSP. Reuse the wallet/opt-out disclosure
            pattern (orange pill + cream info box). */}
        <div style={{ margin: '14px 20px 0', background: 'var(--cream)', borderRadius: 12, padding: 12 }}>
          <div style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '4px 10px', background: 'var(--orange)', color: 'var(--ink)', borderRadius: 999, fontSize: 10, fontWeight: 700, letterSpacing: '0.12em', textTransform: 'uppercase' }}>DÉMO</div>
          <div style={{ marginTop: 8, fontSize: 11, color: 'var(--gray-4)', lineHeight: 1.5 }}>
            Paiement & commande <b style={{ color: 'var(--ink)' }}>non synchronisés</b> — prototype de démonstration. Aucune carte n'est débitée, aucune commande n'est envoyée en cuisine.
          </div>
        </div>
        <div style={{ margin: '20px 20px 0', padding: 18, background: 'var(--cream)', borderRadius: 18 }}>
          <label style={{ fontSize: 11, fontWeight: 700, letterSpacing: '0.14em', textTransform: 'uppercase', color: 'var(--gray-3)' }}>Numéro de carte</label>
          <div style={{ marginTop: 8, padding: '14px 14px', background: '#fff', borderRadius: 12, fontFamily: 'var(--font-mono)', fontSize: 15, letterSpacing: '0.06em', color: 'var(--ink)', display: 'flex', justifyContent: 'space-between' }}>
            <span>4242 4242 4242 4242</span>
            <span style={{ fontSize: 11, color: 'var(--orange)', fontWeight: 700 }}>VISA</span>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, marginTop: 12 }}>
            <div>
              <label style={{ fontSize: 11, fontWeight: 700, letterSpacing: '0.14em', textTransform: 'uppercase', color: 'var(--gray-3)' }}>Exp</label>
              <div style={{ marginTop: 6, padding: '12px 14px', background: '#fff', borderRadius: 12, fontFamily: 'var(--font-mono)', color: 'var(--ink)' }}>12 / 28</div>
            </div>
            <div>
              <label style={{ fontSize: 11, fontWeight: 700, letterSpacing: '0.14em', textTransform: 'uppercase', color: 'var(--gray-3)' }}>CVV</label>
              <div style={{ marginTop: 6, padding: '12px 14px', background: '#fff', borderRadius: 12, fontFamily: 'var(--font-mono)', color: 'var(--ink)' }}>•••</div>
            </div>
          </div>
        </div>
        <div style={{ padding: '14px 20px 0', display: 'flex', alignItems: 'center', gap: 8, color: 'var(--gray-4)', fontSize: 11 }}>
          <span style={{ width: 8, height: 8, borderRadius: 999, background: 'var(--green)' }}/> Paiement chiffré · Stripe · 3D-Secure
        </div>
      </div>
      <div style={{ position: 'absolute', left: 0, right: 0, bottom: 0, background: '#fff', padding: '14px 20px 30px', borderTop: '1px solid var(--gray-1)' }}>
        <button onClick={() => go('confirm')} className="lc-btn" style={{ background: 'var(--ink)', color: '#fff', width: '100%', height: 58 }}>
          Payer {total.toFixed(2).replace('.', ',')} €
        </button>
      </div>
    </div>
  );
}

// ---------- I.2 +25 points confetti ----------
function ModalPointsGain({ onClose, onSee, gain = 25 }) {
  // A11-007 P2 closed round-3 : confetti spans aria-hidden + container
  // role=dialog + ESC keydown. A11-014 : skip confetti DOM nodes entirely
  // when prefers-reduced-motion (cosmetic, no visual loss).
  const reducedMotion = typeof window !== 'undefined' && window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const pieces = reducedMotion ? [] : Array.from({ length: 24 });
  useEffect_m(() => {
    const handler = (e) => { if (e.key === 'Escape') onClose(); };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [onClose]);
  return (
    <div role="dialog" aria-modal="true" aria-labelledby="modal-points-gain-title" data-modal-kind="points-gain" style={{ position: 'absolute', inset: 0, zIndex: 60, background: 'rgba(10,10,10,0.7)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20, overflow: 'hidden' }}>
      {pieces.map((_, i) => {
        const colors = ['#FF5A1F', '#FFD93D', '#0A0A0A', '#1FA653'];
        const c = colors[i % colors.length];
        const left = (i * 4.2) % 100;
        const delay = (i % 8) * 0.12;
        return <span key={i} aria-hidden="true" style={{ position: 'absolute', top: -20, left: `${left}%`, width: 10, height: 14, background: c, animation: `lc-confetti 3.4s ${delay}s ease-in infinite`, transformOrigin: 'center', willChange: 'transform, opacity' }}/>;
      })}
      <div style={{ position: 'relative', background: 'var(--yellow)', borderRadius: 28, padding: '32px 24px', textAlign: 'center', maxWidth: 320, boxShadow: '8px 8px 0 var(--ink)' }}>
        <div style={{ fontSize: 11, fontWeight: 700, letterSpacing: '0.18em', textTransform: 'uppercase', color: 'var(--ink)' }}>// Bienvenue au club</div>
        <div id="modal-points-gain-title" className="lc-display" style={{ fontSize: 88, lineHeight: 0.85, color: 'var(--ink)', margin: '6px 0 4px' }}>+{gain}</div>
        <div className="lc-display" style={{ fontSize: 24, lineHeight: 0.9, color: 'var(--orange)' }}>points gagnés</div>
        <p style={{ fontSize: 12.5, color: 'var(--gray-4)', margin: '14px 0 18px', lineHeight: 1.45 }}>Tu fais partie du club Le Cayenne. Continue à commander pour débloquer des récompenses gratuites.</p>
        <button onClick={onSee} className="lc-btn" style={{ background: 'var(--ink)', color: '#fff', width: '100%', height: 50, marginBottom: 8 }}>Voir ma carte</button>
        <button onClick={onClose} style={{ background: 'transparent', border: 0, color: 'var(--gray-4)', fontSize: 12, fontWeight: 700, letterSpacing: '0.1em', textTransform: 'uppercase', cursor: 'pointer' }}>Plus tard</button>
      </div>
    </div>
  );
}

// ---------- I.3 Reward redeem confirm ----------
function ModalRedeem({ onClose, onConfirm, reward = 'Burger gratuit', cost = 1000 }) {
  return (
    <ModalShell onClose={onClose} labelledBy="modal-redeem-title" dataModalKind="redeem">
      <div style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '4px 10px', background: 'var(--orange)', color: '#fff', borderRadius: 999, fontSize: 10, fontWeight: 700, letterSpacing: '0.12em', textTransform: 'uppercase' }}>★ Récompense</div>
      <h2 id="modal-redeem-title" className="lc-display" style={{ margin: '12px 0 4px', fontSize: 36, lineHeight: 0.92, color: 'var(--ink)' }}>Confirmer<br/>l'échange ?</h2>
      <p style={{ margin: '0 0 18px', color: 'var(--gray-3)', fontSize: 13 }}>Cette action est irréversible.</p>

      <div style={{ background: 'var(--cream)', borderRadius: 16, padding: 16, display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <div>
          <div style={{ fontSize: 11, fontWeight: 700, letterSpacing: '0.14em', textTransform: 'uppercase', color: 'var(--gray-3)' }}>Tu reçois</div>
          <div style={{ fontWeight: 700, fontSize: 16, marginTop: 2, color: 'var(--ink)' }}>{reward}</div>
        </div>
        <div style={{ textAlign: 'right' }}>
          <div style={{ fontSize: 11, fontWeight: 700, letterSpacing: '0.14em', textTransform: 'uppercase', color: 'var(--gray-3)' }}>Tu dépenses</div>
          <div className="lc-display" style={{ fontSize: 24, color: 'var(--orange)', lineHeight: 1 }}>−{cost}</div>
          <div style={{ fontSize: 11, color: 'var(--gray-4)' }}>points</div>
        </div>
      </div>

      <div style={{ marginTop: 14, padding: '10px 14px', background: 'var(--yellow-soft)', borderRadius: 12, fontSize: 12, color: 'var(--ink)', display: 'flex', gap: 8, alignItems: 'flex-start' }}>
        <span style={{ flexShrink: 0 }}>ℹ︎</span>
        <span>Ta réduction sera appliquée automatiquement sur ta prochaine commande.</span>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1.6fr', gap: 10, marginTop: 18 }}>
        <button onClick={onClose} className="lc-btn" style={{ background: 'var(--cream)', color: 'var(--ink)', height: 54 }}>Annuler</button>
        <button onClick={onConfirm} className="lc-btn" style={{ background: 'var(--orange)', color: '#fff', height: 54 }}>Confirmer</button>
      </div>
    </ModalShell>
  );
}

// ---------- I.4 Card linking ----------
function ModalCardLink({ onClose, onScan }) {
  return (
    <ModalShell onClose={onClose} labelledBy="modal-cardlink-title" dataModalKind="card-link">
      <h2 id="modal-cardlink-title" className="lc-display" style={{ margin: 0, fontSize: 32, lineHeight: 0.92, color: 'var(--ink)' }}>Lier ta carte<br/>plastique</h2>
      <p style={{ margin: '8px 0 18px', color: 'var(--gray-3)', fontSize: 13 }}>Scanne le QR au dos de ta carte pour fusionner les points.</p>
      <div style={{ position: 'relative', height: 220, background: 'var(--ink)', borderRadius: 18, overflow: 'hidden', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
        <div style={{ position: 'absolute', inset: 24, border: '2px solid var(--orange)', borderRadius: 14 }}/>
        <div style={{ position: 'absolute', top: 24, left: 24, width: 24, height: 24, borderTop: '4px solid var(--yellow)', borderLeft: '4px solid var(--yellow)', borderTopLeftRadius: 14 }}/>
        <div style={{ position: 'absolute', top: 24, right: 24, width: 24, height: 24, borderTop: '4px solid var(--yellow)', borderRight: '4px solid var(--yellow)', borderTopRightRadius: 14 }}/>
        <div style={{ position: 'absolute', bottom: 24, left: 24, width: 24, height: 24, borderBottom: '4px solid var(--yellow)', borderLeft: '4px solid var(--yellow)', borderBottomLeftRadius: 14 }}/>
        <div style={{ position: 'absolute', bottom: 24, right: 24, width: 24, height: 24, borderBottom: '4px solid var(--yellow)', borderRight: '4px solid var(--yellow)', borderBottomRightRadius: 14 }}/>
        <div style={{ color: 'rgba(255,255,255,0.5)', fontSize: 12 }}>Cadre la carte ici</div>
      </div>
      <button onClick={onScan} className="lc-btn" style={{ background: 'var(--orange)', color: '#fff', width: '100%', height: 54, marginTop: 16 }}>Activer l'appareil photo</button>
      <button onClick={onClose} style={{ background: 'transparent', border: 0, color: 'var(--gray-4)', fontSize: 12, fontWeight: 700, letterSpacing: '0.1em', textTransform: 'uppercase', cursor: 'pointer', display: 'block', margin: '12px auto 0' }}>Pas maintenant</button>
    </ModalShell>
  );
}

// ---------- Order detail ----------
// [MOBILE FINAL V1 HEAL 2026-05-19] Z6-DEF-01 P2: remove fictional fallback
// (Box Nashville / Bowl Gratiné / Frite XXL) when LC.orders.findById returns
// null. Anti-fiction discipline (CLAUDE.md §13). Items=null branch renders a
// role="status" empty-state UI instead of canonical-catalogue-violating mock.
function ScreenOrderDetail({ go, orderId = 'C-1234' }) {
  // Pull real order from data layer (window.LC.orders.findById) when available.
  const real = window.LC && window.LC.orders ? window.LC.orders.findById(orderId) : null;
  const items = real
    ? real.items.map(i => ({ name: i.name, sups: i.extras_summary || '', qty: i.qty, price: i.line_total / Math.max(1, i.qty) }))
    : null;
  const total = real ? real.total : 0;
  const dateLabel = real
    ? new Date(real.created_at).toLocaleString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }).replace(',', ' ·')
    : '';
  const branch = real ? real.branch_city : '';
  const status = real ? real.status_label : '';
  const isDelivered = !!real && real.status === 'delivered';

  return (
    <div data-screen-label="12b Order detail" style={{ position: 'absolute', inset: 0, background: '#fff' }}>
      <div className="lc-screen" style={{ paddingBottom: 120 }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '4px 20px 8px' }}>
          <button onClick={() => go('orders')} style={{ width: 36, height: 36, borderRadius: 999, background: 'var(--cream)', border: 0, color: 'var(--ink)', cursor: 'pointer', fontSize: 16 }}>←</button>
          <div style={{ fontSize: 12, fontWeight: 700, letterSpacing: '0.18em', textTransform: 'uppercase', color: 'var(--ink)' }}>Détail</div>
          <div style={{ width: 36 }}/>
        </div>
        {items ? (
          <>
            <div style={{ padding: '6px 20px 0' }}>
              <div style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '4px 10px', background: isDelivered ? 'var(--green)' : 'var(--orange)', color: '#fff', borderRadius: 999, fontSize: 10, fontWeight: 700, letterSpacing: '0.12em', textTransform: 'uppercase' }}>{isDelivered ? '✓' : '●'} {status}</div>
              <h1 className="lc-display" style={{ margin: '8px 0 2px', fontSize: 38, lineHeight: 0.9, color: 'var(--ink)' }}>#{orderId}</h1>
              <div style={{ fontSize: 12, color: 'var(--gray-3)' }}>{dateLabel} · {branch}</div>
            </div>
            <div style={{ margin: '20px 20px 0', background: 'var(--cream)', borderRadius: 18, padding: 16 }}>
              {items.map((it, i) => (
                <div key={i} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', padding: '12px 0', borderBottom: i < items.length-1 ? '1px solid var(--gray-2)' : 'none' }}>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontWeight: 700, fontSize: 14, color: 'var(--ink)' }}>{it.qty} × {it.name}</div>
                    {it.sups && <div style={{ fontSize: 11, color: 'var(--gray-3)', marginTop: 2 }}>+ {it.sups}</div>}
                  </div>
                  <div style={{ fontFamily: 'var(--font-mono)', fontWeight: 700, color: 'var(--ink)' }}>{(it.price*it.qty).toFixed(2).replace('.', ',')} €</div>
                </div>
              ))}
              <div style={{ display: 'flex', justifyContent: 'space-between', paddingTop: 14, marginTop: 6, borderTop: '2px solid var(--ink)' }}>
                <span style={{ fontSize: 11, fontWeight: 700, letterSpacing: '0.14em', textTransform: 'uppercase', color: 'var(--gray-3)' }}>Total</span>
                <span className="lc-display" style={{ fontSize: 24, color: 'var(--orange)' }}>{total.toFixed(2).replace('.', ',')} €</span>
              </div>
            </div>
            <div style={{ margin: '14px 20px 0', background: 'var(--ink)', color: '#fff', borderRadius: 18, padding: 16, display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
              <div>
                <div style={{ fontSize: 11, fontWeight: 700, letterSpacing: '0.14em', textTransform: 'uppercase', color: 'var(--orange)' }}>+ Loyalty</div>
                <div style={{ fontSize: 14, fontWeight: 700, marginTop: 4 }}>+{Math.round(total)} pts crédités</div>
              </div>
              <div className="lc-display" style={{ fontSize: 26, color: 'var(--yellow)' }}>+{Math.round(total)}</div>
            </div>
            {/* [W3 HEAL P1-HONESTY 2026-06-06] §0.2 mock boundary — badge ONLY; the
                NF525 fiscal text is left intact per contract (whether a customer app
                should show NF525 at all = owner gate G6). The badge marks this as
                authored mock data, not real fiscal output. */}
            <div style={{ margin: '14px 20px 0', textAlign: 'center' }}>
              <div style={{ display: 'inline-flex', alignItems: 'center', gap: 6, marginBottom: 6, padding: '4px 10px', background: 'var(--orange)', color: 'var(--ink)', borderRadius: 999, fontSize: 9, fontWeight: 700, letterSpacing: '0.1em', textTransform: 'uppercase' }}>DÉMO — non synchronisé</div>
              <div style={{ fontSize: 12, color: 'var(--gray-3)' }}>
                Payé en caisse · Reçu fiscal NF525 #{orderId}-R
              </div>
            </div>
          </>
        ) : (
          <div role="status" data-testid="order-detail-empty" style={{ padding: 32, textAlign: 'center', color: 'var(--gray-4)', marginTop: 40 }}>
            <div style={{ fontSize: 36, marginBottom: 12 }} aria-hidden="true">🧾</div>
            <div style={{ fontSize: 15, fontWeight: 600, color: 'var(--ink)', marginBottom: 8 }}>Commande introuvable</div>
            <div style={{ fontSize: 13, lineHeight: 1.5, marginBottom: 16 }}>
              Le détail de cette commande n'est pas disponible.
            </div>
            <button onClick={() => go('orders')} style={{ background: 'transparent', border: 0, color: 'var(--orange)', fontSize: 13, fontWeight: 700, cursor: 'pointer', textDecoration: 'underline' }}>Retour à mes commandes</button>
          </div>
        )}
      </div>
      <div style={{ position: 'absolute', left: 0, right: 0, bottom: 0, background: '#fff', padding: '14px 20px 30px', borderTop: '1px solid var(--gray-1)' }}>
        <button onClick={() => go(items ? 'menu' : 'orders')} className="lc-btn" style={{ background: 'var(--orange)', color: '#fff', width: '100%', height: 56 }}>{items ? '↻ Recommander' : '← Retour'}</button>
      </div>
    </div>
  );
}

// ---------- Toast ----------
function Toast({ message, kind = 'info', onClose }) {
  useEffect_m(() => { const t = setTimeout(onClose, 3000); return () => clearTimeout(t); }, []);
  const bg = kind === 'success' ? 'var(--green)' : kind === 'error' ? 'var(--red)' : 'var(--ink)';
  return (
    <div data-testid="toast" data-kind={kind} role="status" aria-live="polite" style={{ position: 'absolute', top: 'calc(var(--ios-safe-top) + 8px)', left: 14, right: 14, zIndex: 70, background: bg, color: '#fff', borderRadius: 14, padding: '12px 16px', display: 'flex', alignItems: 'center', gap: 10, boxShadow: '0 8px 22px rgba(0,0,0,0.25)', animation: 'lc-slide-down 0.3s ease' }}>
      <span style={{ width: 8, height: 8, borderRadius: 999, background: kind === 'success' ? 'var(--yellow)' : '#fff' }}/>
      <span style={{ fontSize: 13, fontWeight: 600, flex: 1 }}>{message}</span>
      <button onClick={onClose} aria-label="Fermer le toast" style={{ background: 'transparent', border: 0, color: '#fff', fontSize: 16, cursor: 'pointer', opacity: 0.7 }}>×</button>
    </div>
  );
}

// ---------- Wallet V0 notice modal (commit-4 — DEC-10) ----------
// V0 = informant modal explaining Apple/Google Wallet is pending production
// deployment. Wired from ScreenLoyalty ACTIONS RAPIDES pills.
// Phase 6 : when walletSpec.v0_mock === false, the pill swaps to fetch(endpoint)
// flow returning .pkpass or save_url. See mobile/WALLET_PLAN.md.
function ModalWalletV0Notice({ onClose, onSeeQR, kind = 'apple' }) {
  const isApple = kind === 'apple';
  const title = isApple ? 'Apple Wallet' : 'Google Wallet';
  return (
    <div data-testid="modal" data-modal-kind={'wallet-' + kind} role="dialog" aria-modal="true" aria-labelledby="modal-wallet-title">
      <ModalShell onClose={onClose}>
        <div style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '4px 10px', background: isApple ? 'var(--ink)' : 'var(--paper)', color: isApple ? '#fff' : 'var(--ink)', border: isApple ? 'none' : '1px solid var(--gray-2)', borderRadius: 999, fontSize: 10, fontWeight: 700, letterSpacing: '0.12em', textTransform: 'uppercase' }}>
          {isApple ? '' : 'G'} {title}
        </div>
        <h2 id="modal-wallet-title" className="lc-display" style={{ margin: '12px 0 4px', fontSize: 30, lineHeight: 0.92, color: 'var(--ink)' }}>Bientôt<br/>disponible</h2>
        <p style={{ margin: '0 0 18px', color: 'var(--gray-4)', fontSize: 13, lineHeight: 1.5 }}>
          Cette fonctionnalité sera disponible lors du déploiement en production. Pour l'instant, présente ton QR fidélité directement depuis l'app.
        </p>
        <div style={{ background: 'var(--cream)', borderRadius: 12, padding: 12, marginBottom: 14, fontSize: 11, color: 'var(--gray-4)', lineHeight: 1.5 }}>
          <div style={{ fontWeight: 700, color: 'var(--ink)', marginBottom: 4 }}>Pourquoi ?</div>
          {isApple
            ? 'Apple Wallet nécessite un Pass Type ID et un certificat WWDR (Apple Developer Program). En cours de provisioning.'
            : 'Google Wallet nécessite un Issuer ID et un compte de service Google Cloud. En cours de provisioning.'}
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
          <button onClick={onClose} className="lc-btn" style={{ background: 'var(--cream)', color: 'var(--ink)', height: 50 }}>Fermer</button>
          <button onClick={onSeeQR} className="lc-btn" style={{ background: 'var(--orange)', color: '#fff', height: 50 }}>Voir mon QR</button>
        </div>
      </ModalShell>
    </div>
  );
}

// ---------- Opt-out RGPD confirm modal (commit-5 — DEC-15) ----------
// [test-e2e fix D-002 round-2 2026-05-11] copy aligned with actual behaviour
// (balance cleared on confirm, RGPD article 17 right-to-erasure).
function ModalOptOutConfirm({ onClose, onConfirm }) {
  const currentBalance = (window.LC && window.LC.loyalty && window.LC.loyalty.account)
    ? window.LC.loyalty.account.balance
    : 0;
  return (
    <div data-testid="modal" data-modal-kind="opt-out-confirm" role="dialog" aria-modal="true" aria-labelledby="modal-opt-out-title">
      <ModalShell onClose={onClose}>
        <div style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '4px 10px', background: 'var(--red)', color: '#fff', borderRadius: 999, fontSize: 10, fontWeight: 700, letterSpacing: '0.12em', textTransform: 'uppercase' }}>⚠ RGPD</div>
        <h2 id="modal-opt-out-title" className="lc-display" style={{ margin: '12px 0 4px', fontSize: 30, lineHeight: 0.92, color: 'var(--ink)' }}>Désactiver mon<br/>compte fidélité ?</h2>
        <p style={{ margin: '0 0 14px', color: 'var(--gray-4)', fontSize: 13, lineHeight: 1.5 }}>
          Tes points ne seront plus crédités lors de tes achats. <b style={{ color: 'var(--red)' }}>Tes {currentBalance} points actuels seront effacés</b> (droit à l'effacement, RGPD article 17). Cette action est irréversible.
        </p>
        <div style={{ background: 'var(--yellow-soft)', borderRadius: 12, padding: 12, marginBottom: 16, fontSize: 12, color: 'var(--ink)', display: 'flex', gap: 8 }}>
          <span style={{ flexShrink: 0 }}>ℹ︎</span>
          <span>Tu peux réactiver ton compte à tout moment, mais le solde repartira de 0.</span>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1.4fr', gap: 10 }}>
          <button onClick={onClose} className="lc-btn" style={{ background: 'var(--cream)', color: 'var(--ink)', height: 50 }}>Annuler</button>
          <button data-testid="opt-out-confirm-btn" onClick={onConfirm} className="lc-btn" style={{ background: 'var(--red)', color: '#fff', height: 50 }}>Désactiver</button>
        </div>
      </ModalShell>
    </div>
  );
}

Object.assign(window, { ModalPayChoice, ScreenStripe, ModalPointsGain, ModalRedeem, ModalCardLink, ModalWalletV0Notice, ModalOptOutConfirm, ModalShell, ScreenOrderDetail, Toast });
