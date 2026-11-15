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
      <div onClick={onClose} aria-hidden="true" className="lc-veil-in" style={{ position: 'absolute', inset: 0, background: 'rgba(10,10,10,0.55)' }}/>
      <div
        ref={sheetRef}
        className="lc-sheet-in"
        role="dialog"
        aria-modal="true"
        aria-labelledby={labelledBy}
        style={{ position: 'absolute', left: 0, right: 0, bottom: 0, background: '#fff', borderRadius: '24px 24px 0 0', padding: '14px 20px calc(var(--lc-safe-bottom) + 16px)', maxHeight: 'min(85%, calc(100% - var(--lc-inset-top) - 12px))', height, overflowY: 'auto', boxShadow: '0 -12px 30px rgba(0,0,0,0.18)' }}
      >
        <div style={{ width: 44, height: 4, borderRadius: 999, background: 'var(--gray-2)', margin: '0 auto 14px' }} aria-hidden="true"/>
        {children}
      </div>
    </div>
  );
}

// ---------- F.2  Payment choice ----------
// [GOAL-SYNC 2026-07-08] Flag « paiement en ligne » (contrat §4) : prop onlineCardEnabled
// (nom normatif ; alias 'flag' accepté — index.html passe flag={window.LC.config.onlineCardEnabled}).
// OFF ⇒ le bouton « Payer maintenant (CB) » est ABSENT du DOM + micro-copy FR.
function ModalPayChoice({ onClose, onPickCounter, onPickCard, total = 33, onlineCardEnabled, flag }) {
  const cardOnline = onlineCardEnabled !== undefined ? !!onlineCardEnabled
    : (flag !== undefined ? !!flag : !!(window.LC && window.LC.config && window.LC.config.onlineCardEnabled));
  return (
    <ModalShell onClose={onClose} labelledBy="modal-pay-title" dataModalKind="pay">
      <h2 id="modal-pay-title" className="lc-display" style={{ margin: 0, fontSize: 36, lineHeight: 0.92, color: 'var(--ink)' }}>Comment<br/>tu paies ?</h2>
      <p style={{ margin: '8px 0 18px', color: 'var(--gray-3)', fontSize: 13 }}>Total {total.toFixed(2).replace('.', ',')} € · TVA incluse</p>

      <button data-testid="pay-counter" onClick={onPickCounter} style={{ display: 'block', width: '100%', textAlign: 'left', background: 'var(--ink)', color: '#fff', borderRadius: 18, padding: 18, border: 0, marginBottom: 12, cursor: 'pointer' }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 6 }}>
          {/* [2026-11-14 B-019] Plus de « ★ Recommandé » sur l'unique option. */}
          <span style={{ fontSize: 11, fontWeight: 700, letterSpacing: '0.16em', textTransform: 'uppercase', color: 'var(--orange)' }}>Au retrait</span>
          <span style={{ fontSize: 22, color: 'var(--orange)' }}>→</span>
        </div>
        <div className="lc-display" style={{ fontSize: 26, lineHeight: 1, color: '#fff' }}>Payer sur place</div>
        <div style={{ fontSize: 12, color: 'rgba(255,255,255,0.7)', marginTop: 6 }}>Cash ou CB à la caisse. Ton plat t'attend.</div>
      </button>

      {cardOnline ? (
        <button data-testid="pay-card-online" onClick={onPickCard} style={{ display: 'block', width: '100%', textAlign: 'left', background: 'var(--cream)', color: 'var(--ink)', borderRadius: 18, padding: 18, border: 0, cursor: 'pointer' }}>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 6 }}>
            <span style={{ fontSize: 11, fontWeight: 700, letterSpacing: '0.16em', textTransform: 'uppercase', color: 'var(--gray-3)' }}>CB en ligne</span>
            <span style={{ fontSize: 22, color: 'var(--ink)' }}>→</span>
          </div>
          <div className="lc-display" style={{ fontSize: 26, lineHeight: 1, color: 'var(--ink)' }}>Payer maintenant</div>
          <div style={{ fontSize: 12, color: 'var(--gray-4)', marginTop: 6 }}>Visa · Mastercard · Apple Pay</div>
        </button>
      ) : (
        <p data-testid="pay-counter-info" style={{ margin: '4px 2px 0', fontSize: 12, color: 'var(--gray-3)' }}>Paiement à la caisse, CB ou espèces, au retrait.</p>
      )}
    </ModalShell>
  );
}

// ---------- Stripe placeholder ----------
// [GOAL-SYNC 2026-07-08] Garde en tête (défense en profondeur, contrat §4) : si le flag
// window.LC.config.onlineCardEnabled est OFF, cet écran ne rend JAMAIS le formulaire —
// état FR « Paiement en ligne indisponible » + retour panier. (index.html redirige déjà
// en amont ; ce garde protège tout rendu direct du composant.)
function ScreenStripe({ go, total = 33 }) {
  if (!(window.LC && window.LC.config && window.LC.config.onlineCardEnabled)) {
    return (
      <div data-screen-label="11b Stripe" data-testid="stripe-unavailable" style={{ position: 'absolute', inset: 0, background: '#fff', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', padding: 32, textAlign: 'center' }}>
        <div style={{ fontSize: 40, marginBottom: 14 }} aria-hidden="true">💳</div>
        <div className="lc-display" style={{ fontSize: 30, lineHeight: 0.95, color: 'var(--ink)' }}>Paiement en ligne<br/>indisponible</div>
        <p style={{ marginTop: 14, fontSize: 13, lineHeight: 1.5, color: 'var(--gray-4)', maxWidth: 280 }}>Tu règles ta commande sur place, en CB ou en espèces.</p>
        <button onClick={() => go('cart')} className="lc-btn" style={{ marginTop: 20, background: 'var(--ink)', color: '#fff', height: 52, padding: '0 28px' }}>← Retour au panier</button>
      </div>
    );
  }
  return (
    <div data-screen-label="11b Stripe" style={{ position: 'absolute', inset: 0, background: '#fff' }}>
      <div className="lc-screen" style={{ paddingBottom: 'calc(var(--lc-safe-bottom) + 104px)' }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '4px 20px 8px' }}>
          <button onClick={() => go('cart')} style={{ width: 36, height: 36, borderRadius: 999, background: 'var(--cream)', border: 0, color: 'var(--ink)', cursor: 'pointer' }}>←</button>
          <div style={{ fontSize: 12, fontWeight: 700, letterSpacing: '0.18em', textTransform: 'uppercase', color: 'var(--ink)' }}>Paiement</div>
          <div style={{ width: 36 }}/>
        </div>
        <div style={{ padding: '10px 20px 0' }}>
          <div className="lc-display" style={{ fontSize: 44, lineHeight: 0.9, color: 'var(--ink)' }}>{total.toFixed(2).replace('.', ',')} €</div>
          <div style={{ fontSize: 12, color: 'var(--gray-3)', marginTop: 4 }}>Réservé sur ta CB jusqu'au retrait</div>
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
      <div className="lc-foot">
        <button onClick={() => go('confirm')} className="lc-btn" style={{ background: 'var(--ink)', color: '#fff', width: '100%', height: 58 }}>
          Payer {total.toFixed(2).replace('.', ',')} €
        </button>
      </div>
    </div>
  );
}

// ---------- I.2 points gagnés confetti ----------
// [GOAL-SYNC 2026-07-08] Points RÉELS passés en prop (contrat §2 : Math.floor(total ×
// points_per_euro) calculé par l'appelant) — prop normative `points`, alias `gain`
// conservé (index.html passe gain={Math.floor(lastOrder.total)}) ; fallback 25 (story
// standalone) en dernier recours. Plus de « +25 » fixe.
function ModalPointsGain({ onClose, onSee, points, gain }) {
  // [2026-11-14 B-004] Règle propriétaire : les points sont crédités quand le restaurant VALIDE la
  // commande — la modale annonce une ESTIMATION « à créditer », jamais des points « gagnés ».
  // Plus de repli inventé (25) : sans montant connu, la phrase n'avance aucun chiffre.
  const shownGain = Number.isFinite(Number(points)) && points != null ? Math.floor(Number(points))
    : (Number.isFinite(Number(gain)) && gain != null ? Math.floor(Number(gain)) : null);
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
    <div role="dialog" aria-modal="true" aria-labelledby="modal-points-gain-title" data-modal-kind="points-gain" style={{ position: 'absolute', inset: 0, zIndex: 60, background: 'rgba(10,10,10,0.7)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 'calc(var(--lc-inset-top) + 12px) 20px calc(var(--lc-safe-bottom) + 12px)', overflow: 'hidden' }}>
      {pieces.map((_, i) => {
        const colors = ['#FF5A1F', '#FFD93D', '#0A0A0A', '#1FA653'];
        const c = colors[i % colors.length];
        const left = (i * 4.2) % 92; // [2026-11-14 B-026] borné : plus de débordement horizontal
        const delay = (i % 8) * 0.12;
        return <span key={i} aria-hidden="true" style={{ position: 'absolute', top: -20, left: `${left}%`, width: 10, height: 14, background: c, animation: `lc-confetti 3.4s ${delay}s ease-in infinite`, transformOrigin: 'center', willChange: 'transform, opacity' }}/>;
      })}
      <div className="lc-pop-in" style={{ position: 'relative', background: 'var(--yellow)', borderRadius: 28, padding: '32px 24px', textAlign: 'center', maxWidth: 320, boxShadow: '8px 8px 0 var(--ink)' }}>
        <div style={{ fontSize: 11, fontWeight: 700, letterSpacing: '0.18em', textTransform: 'uppercase', color: 'var(--ink)' }}>// Club Le Cayenne</div>
        {shownGain != null && shownGain > 0 ? (
          <>
            <div className="lc-display" aria-hidden="true" style={{ fontSize: 72, lineHeight: 0.85, color: 'var(--ink)', margin: '6px 0 4px' }}>~{shownGain}</div>
            <div className="lc-display" style={{ fontSize: 22, lineHeight: 0.9, color: 'var(--orange)' }}>points à créditer</div>
            <p id="modal-points-gain-title" data-testid="points-pending-text" style={{ fontSize: 13, color: 'var(--ink)', margin: '14px 0 18px', lineHeight: 1.45 }}>Environ {shownGain} points seront crédités dès que le restaurant valide ta commande.</p>
          </>
        ) : (
          <p id="modal-points-gain-title" data-testid="points-pending-text" style={{ fontSize: 13, color: 'var(--ink)', margin: '14px 0 18px', lineHeight: 1.45 }}>Tes points seront crédités dès que le restaurant valide ta commande.</p>
        )}
        <button onClick={onSee} className="lc-btn" style={{ background: 'var(--ink)', color: '#fff', width: '100%', height: 50, marginBottom: 8 }}>Voir ma carte</button>
        <button onClick={onClose} style={{ background: 'transparent', border: 0, color: 'var(--gray-4)', fontSize: 12, fontWeight: 700, letterSpacing: '0.1em', textTransform: 'uppercase', cursor: 'pointer', minHeight: 44, padding: '0 16px' }}>Plus tard</button>
      </div>
    </div>
  );
}

// ---------- Order detail ----------
// [MOBILE FINAL V1 HEAL 2026-05-19] Z6-DEF-01 P2: remove fictional fallback
// (Box Nashville / Bowl Gratiné / Frite XXL) when LC.orders.findById returns
// null. Anti-fiction discipline (CLAUDE.md §13). Items=null branch renders a
// role="status" empty-state UI instead of canonical-catalogue-violating mock.
// [2026-11-14 B-006] Fiche d'une commande SERVEUR (id préfixé 'srv-' par ScreenOrders) :
// GET /api/frontend/order/show/{id} via LC.mobileApi.getOrder (garde de propriété côté serveur).
// Affiche le n° d'appel, les lignes réelles (composition figée), le total serveur et les points
// RÉELLEMENT crédités — une estimation n'apparaît que pour une commande en cours, libellée « à créditer ».
function lcRemoteOrderLineSups(oi) {
  const pick = (arr, keys) => (Array.isArray(arr) ? arr : []).map(x => {
    if (!x || typeof x !== 'object') return '';
    for (const k of keys) { if (x[k]) return String(x[k]); }
    return '';
  });
  return [
    ...pick(oi.item_variations, ['variation_name', 'name']),
    ...pick(oi.item_extras, ['name', 'extra_name', 'item_extra_name']),
    ...pick(oi.item_addons, ['name', 'item_name', 'addon_name']),
  ].filter(Boolean).join(' · ');
}

function ScreenOrderDetailRemote({ go, dbId }) {
  const [state, setState] = useState_m({ loading: true, order: null, error: '' });
  useEffect_m(() => {
    const api = window.LC && window.LC.mobileApi;
    if (!(api && api.getOrder)) { setState({ loading: false, order: null, error: 'Service indisponible pour le moment.' }); return undefined; }
    let alive = true;
    api.getOrder(dbId).then(o => {
      if (alive) setState({ loading: false, order: o || null, error: o ? '' : 'Commande introuvable.' });
    }).catch(e => {
      if (alive) setState({ loading: false, order: null, error: (e && e.message) || 'Chargement de la commande impossible.' });
    });
    return () => { alive = false; };
  }, [dbId]);
  const o = state.order;
  const fmt = (n) => (Number(n) || 0).toFixed(2).replace('.', ',') + ' €';
  const sn = String((o && o.status_name) || '').toLowerCase();
  // [2026-11-15] Statut NUMÉRIQUE d'abord (comme la liste) : « Retournée » (22) restait « en cours » avec la promesse
  // de points, « En livraison » (10) s'affichait terminée. Les mots ne servent que si le numéro manque.
  const stn = Number(o && o.status) || 0;
  const isCancelled = stn ? (stn === 16 || stn === 19 || stn === 22) : /annul|rejet|refus|retourn/.test(sn);
  const isDone = stn ? (stn === 8 || stn === 13) : /(termin|livr[ée]e|récup|recup|complet|prêt|pret|servi)/.test(sn);
  const awarded = Number(o && o.loyalty_points_awarded) || 0;
  const loyCfg = (window.LC && window.LC.loyalty && window.LC.loyalty.config) || {};
  const earnRatio = Number(loyCfg.points_per_euro != null ? loyCfg.points_per_euro : loyCfg.earn_ratio) || 10;
  const estimate = o ? Math.floor((Number(o.total) || 0) * earnRatio) : 0;
  const lines = o && Array.isArray(o.order_items) ? o.order_items : [];
  const callNo = o && o.queue_number ? String(o.queue_number) : '';
  return (
    <div data-screen-label="12b Order detail" data-testid="order-detail-remote" style={{ position: 'absolute', inset: 0, background: '#fff' }}>
      <div className="lc-screen lc-screen--tabs">
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '4px 20px 8px' }}>
          <button onClick={() => go('orders')} aria-label="Retour à mes commandes" style={{ width: 44, height: 44, borderRadius: 999, background: 'var(--cream)', border: 0, color: 'var(--ink)', cursor: 'pointer', fontSize: 16 }}>←</button>
          <div style={{ fontSize: 12, fontWeight: 700, letterSpacing: '0.18em', textTransform: 'uppercase', color: 'var(--ink)' }}>Détail</div>
          <div style={{ width: 44 }}/>
        </div>
        {state.loading && (
          <div role="status" style={{ margin: '20px', background: 'var(--cream)', borderRadius: 16, padding: 24, textAlign: 'center', color: 'var(--gray-4)', fontSize: 13 }}>Chargement de ta commande…</div>
        )}
        {!state.loading && !o && (
          <div role="alert" data-testid="order-detail-error" style={{ padding: 32, textAlign: 'center', color: 'var(--gray-4)', marginTop: 40 }}>
            <div style={{ fontSize: 15, fontWeight: 600, color: 'var(--ink)', marginBottom: 8 }}>Commande indisponible</div>
            <div style={{ fontSize: 13, lineHeight: 1.5 }}>{state.error}</div>
          </div>
        )}
        {o && (
          <>
            <div style={{ padding: '6px 20px 0' }}>
              <div style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '4px 10px', background: isCancelled ? 'var(--red)' : isDone ? 'var(--green)' : 'var(--orange)', color: '#fff', borderRadius: 999, fontSize: 10, fontWeight: 700, letterSpacing: '0.12em', textTransform: 'uppercase' }}>{isCancelled ? '✕' : isDone ? '✓' : '●'} {o.status_name || ''}</div>
              {callNo && <div style={{ marginTop: 10, fontSize: 10, fontWeight: 700, letterSpacing: '0.14em', textTransform: 'uppercase', color: 'var(--gray-3)' }}>Numéro d'appel</div>}
              <h1 data-testid="order-detail-call-number" className="lc-display" style={{ margin: callNo ? '2px 0 2px' : '8px 0 2px', fontSize: 44, lineHeight: 0.9, color: 'var(--ink)' }}>{callNo || ('#' + (o.order_serial_no || o.id))}</h1>
              <div style={{ fontSize: 12, color: 'var(--gray-3)' }}>{callNo && o.order_serial_no ? 'Commande #' + o.order_serial_no + ' · ' : ''}{o.order_datetime || ''}</div>
            </div>
            <div style={{ margin: '20px 20px 0', background: 'var(--cream)', borderRadius: 18, padding: 16 }}>
              {lines.map((oi, i) => {
                const sups = lcRemoteOrderLineSups(oi);
                return (
                  <div key={oi.id || i} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 10, padding: '12px 0', borderBottom: i < lines.length - 1 ? '1px solid var(--gray-2)' : 'none' }}>
                    <div style={{ flex: 1, minWidth: 0 }}>
                      <div style={{ fontWeight: 700, fontSize: 14, color: 'var(--ink)' }}>{oi.quantity} × {oi.item_name}</div>
                      {sups && <div style={{ fontSize: 11, color: 'var(--gray-3)', marginTop: 2, lineHeight: 1.4 }}>{sups}</div>}
                      {/* [2026-11-15] Une information par ligne, sans la notation technique de la cuisine (« [Note : … ] »). */}
                      {oi.instruction && String(oi.instruction).split(/\r?\n/).map(l => l.trim()).filter(Boolean).map((l, k) => (
                        <div key={k} style={{ fontSize: 11, color: 'var(--gray-4)', marginTop: 2, fontStyle: 'italic' }}>{l.replace(/^\[(Note(?: commande)?) : (.*)\]$/, '$1 : $2').replace(/^Boisson menu:\s*/, 'Boisson : ')}</div>
                      ))}
                    </div>
                    <div style={{ fontFamily: 'var(--font-mono)', fontWeight: 700, color: 'var(--ink)' }}>{fmt(oi.total_price)}</div>
                  </div>
                );
              })}
              <div style={{ display: 'flex', justifyContent: 'space-between', paddingTop: 14, marginTop: 6, borderTop: '2px solid var(--ink)' }}>
                <span style={{ fontSize: 11, fontWeight: 700, letterSpacing: '0.14em', textTransform: 'uppercase', color: 'var(--gray-3)' }}>Total</span>
                <span data-testid="order-detail-total" className="lc-display" style={{ fontSize: 24, color: 'var(--orange)' }}>{fmt(o.total)}</span>
              </div>
            </div>
            {!isCancelled && <div style={{ margin: '14px 20px 0', fontSize: 12, color: 'var(--gray-4)', textAlign: 'center' }}>Paiement en caisse, CB ou espèces.</div>}
            {awarded > 0 ? (
              <div data-testid="order-detail-points" style={{ margin: '14px 20px 0', background: 'var(--ink)', color: '#fff', borderRadius: 18, padding: 16, fontSize: 14, fontWeight: 700 }}>+{awarded} pts crédités sur ta carte</div>
            ) : (!isCancelled && !isDone && estimate > 0) ? (
              <div data-testid="order-detail-points" style={{ margin: '14px 20px 0', background: 'var(--ink)', color: '#fff', borderRadius: 18, padding: 16, fontSize: 13, lineHeight: 1.45 }}>Environ {estimate} points seront crédités dès que le restaurant valide ta commande.</div>
            ) : null}
          </>
        )}
        {/* Bouton dans le flux (la barre d'onglets couvre le bas de l'écran sur cette fiche). */}
        {!state.loading && (
          <div style={{ padding: '18px 20px 0' }}>
            <button onClick={() => go(o ? 'menu' : 'orders')} className="lc-btn" style={{ background: 'var(--orange)', color: '#fff', width: '100%', height: 56 }}>{o ? 'Commander à nouveau sur la carte' : '← Retour à mes commandes'}</button>
          </div>
        )}
      </div>
    </div>
  );
}

function ScreenOrderDetail({ go, orderId = 'C-1234' }) {
  const srv = /^srv-(\d+)$/.exec(String(orderId || ''));
  if (srv) return <ScreenOrderDetailRemote go={go} dbId={srv[1]}/>;
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
  // [GOAL-SYNC 2026-07-08] earn ratio 100% via config (contrat §2) : points_per_euro
  // (nom API backend GET /api/frontend/loyalty/config) prioritaire, sinon earn_ratio
  // (couche data locale), sinon 10 pt/€ (défaut backend, relevé le 2026-09-25).
  // Estimation = Math.floor(total × ratio) (FLOOR, aligné backend ; l'EARN réel est
  // crédité côté backend au statut PREPARED/DELIVERED). [FISCAL GATE 2026-07-07] le reçu
  // fiscal NF525 ne s'affiche que si la commande est réglée (payment_status === 'paid').
  const isPaid = !!real && real.payment_status === 'paid';
  const loyCfg = (window.LC && window.LC.loyalty && window.LC.loyalty.config) || {};
  const earnRatio = Number(loyCfg.points_per_euro != null ? loyCfg.points_per_euro : loyCfg.earn_ratio) || 10;
  const pointsCredited = real
    ? (Number(real.points_earned != null ? real.points_earned : real.points_earned_estimate) || Math.floor((Number(total) || 0) * earnRatio))
    : 0;

  return (
    <div data-screen-label="12b Order detail" style={{ position: 'absolute', inset: 0, background: '#fff' }}>
      <div className="lc-screen" style={{ paddingBottom: 'calc(var(--lc-tabs-h) + 100px)' }}>
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
                <div style={{ fontSize: 14, fontWeight: 700, marginTop: 4 }}>+{pointsCredited} pts {isPaid ? 'crédités' : 'à créditer'}</div>
              </div>
              <div className="lc-display" style={{ fontSize: 26, color: 'var(--yellow)' }}>+{pointsCredited}</div>
            </div>
            {isPaid && (
              <div style={{ margin: '14px 20px 0', fontSize: 12, color: 'var(--gray-3)', textAlign: 'center' }}>
                Payé en caisse · Reçu fiscal NF525 #{orderId}-R
              </div>
            )}
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
      <div className="lc-foot" style={{ bottom: 'var(--lc-tabs-h)', paddingBottom: 14 }}>
        <button onClick={() => go(items ? 'menu' : 'orders')} className="lc-btn" style={{ background: 'var(--orange)', color: '#fff', width: '100%', height: 56 }}>{items ? 'Commander à nouveau sur la carte' : '← Retour'}</button>
      </div>
    </div>
  );
}

// ---------- Toast ----------
function Toast({ message, kind = 'info', onClose }) {
  // [2026-11-14] Une ERREUR reste affichée jusqu'à ce que le client la ferme : un refus de commande
  // effacé en 3 s laissait le client devant son panier sans savoir pourquoi (relecture adverse).
  useEffect_m(() => { if (kind === 'error') return undefined; const t = setTimeout(onClose, 3500); return () => clearTimeout(t); }, [message, kind]);
  const bg = kind === 'success' ? 'var(--green)' : kind === 'error' ? 'var(--red)' : 'var(--ink)';
  return (
    <div data-testid="toast" data-kind={kind} role={kind === 'error' ? 'alert' : 'status'} aria-live={kind === 'error' ? 'assertive' : 'polite'} style={{ position: 'absolute', top: 'calc(var(--lc-inset-top) + 8px)', left: 14, right: 14, zIndex: 70, background: bg, color: '#fff', borderRadius: 14, padding: '12px 16px 14px', display: 'flex', alignItems: 'center', gap: 10, boxShadow: '0 8px 22px rgba(0,0,0,0.25)', animation: 'lc-slide-down 0.3s ease', overflow: 'hidden' }}>
      <span style={{ width: 8, height: 8, borderRadius: 999, background: kind === 'success' ? 'var(--yellow)' : '#fff' }}/>
      <span style={{ fontSize: 13, fontWeight: 600, flex: 1 }}>{message}</span>
      <button onClick={onClose} aria-label="Fermer le message" style={{ background: 'transparent', border: 0, color: '#fff', fontSize: 20, cursor: 'pointer', opacity: 0.85, minWidth: 44, minHeight: 44, margin: '-12px -12px -12px 0' }}>×</button>
      {/* [UI/UX 2026-11-15] Trait de temps : on voit qu'un message d'information se fermera seul (jamais pour une erreur). */}
      {kind !== 'error' && <span key={message} className="lc-toast__bar" aria-hidden="true"/>}
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

// ---------- Account deletion (Apple 5.1.1(v)) ----------
// The destructive action is server-backed and requires an explicit second tap.
// Errors stay in the modal so a failed request cannot look like a local success.
function ModalDeleteAccountConfirm({ onClose, onConfirm }) {
  const [busy, setBusy] = useState_m(false);
  const [error, setError] = useState_m('');
  const confirm = async () => {
    if (busy) return;
    setBusy(true);
    setError('');
    try {
      await onConfirm();
    } catch (e) {
      setBusy(false);
      setError((e && e.message) || 'Suppression impossible pour le moment. Réessaie.');
    }
  };
  return (
    <div data-testid="modal-delete-account" data-modal-kind="delete-account" role="dialog" aria-modal="true" aria-labelledby="modal-delete-account-title">
      <ModalShell onClose={busy ? () => {} : onClose}>
        <div style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '7px 10px', background: 'var(--red)', color: '#fff', borderRadius: 999, fontSize: 10, fontWeight: 800, letterSpacing: '0.12em', textTransform: 'uppercase' }}>
          <I.Trash size={14} stroke="#fff" /> Action définitive
        </div>
        <h2 id="modal-delete-account-title" className="lc-display" style={{ margin: '12px 0 4px', fontSize: 30, lineHeight: 0.92, color: 'var(--ink)' }}>Supprimer mon<br/>compte ?</h2>
        <p style={{ margin: '0 0 14px', color: 'var(--gray-4)', fontSize: 13, lineHeight: 1.5 }}>
          Ton profil, tes données personnelles et ta carte fidélité seront supprimés. Les justificatifs de commandes conservés par obligation légale ne sont pas effacés.
        </p>
        <div style={{ background: 'var(--cream)', borderRadius: 12, padding: 12, marginBottom: 14, fontSize: 12, color: 'var(--ink)', lineHeight: 1.45 }}>
          Une commande encore en préparation peut empêcher la suppression. Dans ce cas, rien ne sera supprimé et tu pourras réessayer plus tard.
        </div>
        {error && <div data-testid="delete-account-error" role="alert" style={{ background: '#FFF1F0', color: 'var(--red)', borderRadius: 10, padding: '10px 12px', marginBottom: 14, fontSize: 12, lineHeight: 1.4 }}>{error}</div>}
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1.4fr', gap: 10 }}>
          <button data-testid="delete-account-cancel-btn" onClick={onClose} disabled={busy} className="lc-btn" style={{ background: 'var(--cream)', color: 'var(--ink)', height: 50, opacity: busy ? 0.55 : 1 }}>Annuler</button>
          <button data-testid="delete-account-confirm-btn" onClick={confirm} disabled={busy} className="lc-btn" style={{ background: 'var(--red)', color: '#fff', height: 50, opacity: busy ? 0.7 : 1 }}>{busy ? 'Suppression…' : 'Supprimer'}</button>
        </div>
      </ModalShell>
    </div>
  );
}

Object.assign(window, { ModalPayChoice, ScreenStripe, ModalPointsGain, ModalWalletV0Notice, ModalOptOutConfirm, ModalDeleteAccountConfirm, ModalShell, ScreenOrderDetail, Toast });
