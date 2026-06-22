// Le Cayenne — LoyaltyQR component (V0)
//
// Memoized child of ScreenLoyalty per DEC-19 (99_VERDICT.md §2). The QR refresh
// every 5min via useLoyaltyQR re-renders THIS component only, not the parent
// ScreenLoyalty — which avoids jank when scrolling history during refresh tick.
//
// ARIA: role="img" + aria-label with TTL announcement (Agent-4 §6.3). Throttled
// live region: announce only on regen + at 60s remaining + at 10s.
//
// data-testid: loyalty-qr, loyalty-qr-payload (data-payload attr),
// loyalty-qr-countdown — per Agent-6 §1.4.

(function () {
  'use strict';
  const { memo, useEffect, useRef } = React;

  function LoyaltyQRImpl({ loyaltyCode, memberNumber, name, mode }) {
    const { qr, refresh, remainingMs, isInflight } = window.useLoyaltyQR(loyaltyCode);
    const liveRegionRef = useRef(null);
    const lastAnnouncementRef = useRef('init');

    // Render-counter hook for perf tests (Agent-6 §6.3)
    useEffect(() => {
      if (window.LC && window.LC.dev && window.LC.dev.notifyRender) {
        window.LC.dev.notifyRender('LoyaltyQR');
      }
    });

    // Throttled SR live region announcement
    useEffect(() => {
      if (!liveRegionRef.current) return;
      const s = Math.floor(remainingMs / 1000);
      let msg = null;
      if (lastAnnouncementRef.current === 'init' && qr) {
        msg = 'Code QR fidélité chargé, valable 5 minutes.';
        lastAnnouncementRef.current = 'loaded';
      } else if (s === 60 && lastAnnouncementRef.current !== 'warn60') {
        msg = 'Code QR expire dans 1 minute.';
        lastAnnouncementRef.current = 'warn60';
      } else if (s === 10 && lastAnnouncementRef.current !== 'warn10') {
        msg = 'Code QR expire dans 10 secondes.';
        lastAnnouncementRef.current = 'warn10';
      } else if (lastAnnouncementRef.current === 'warn10' && s > 200) {
        // Refreshed past warning threshold — reset for next cycle.
        lastAnnouncementRef.current = 'loaded';
        msg = 'Code QR rafraîchi.';
      }
      if (msg) liveRegionRef.current.textContent = msg;
    }, [remainingMs, qr]);

    const payload = qr ? qr.payload : ('FK:' + (loyaltyCode || ''));
    const countdownText = window.formatRemaining(remainingMs);
    const expiringSoon = remainingMs < 60000;

    return (
      <div data-testid="loyalty-qr" data-payload={payload} role="img" aria-label={'Code QR fidélité ' + payload + ', expire dans ' + countdownText}>
        {/* SR-only live region for QR refresh announcements */}
        <div ref={liveRegionRef} role="status" aria-live="polite" aria-atomic="true" style={{ position: 'absolute', width: 1, height: 1, padding: 0, margin: -1, overflow: 'hidden', clip: 'rect(0,0,0,0)', whiteSpace: 'nowrap', border: 0 }} />
        {/* The actual QR/Barcode visual */}
        {mode === 'barcode' ? (
          <window.BarcodeMock value={payload} width={264} height={88} />
        ) : (
          <div className={remainingMs > 0 && !expiringSoon ? 'lc-pulse' : ''} style={{ borderRadius: 12, padding: 4, display: 'inline-block' }}>
            <window.QRMock size={208} value={payload} />
          </div>
        )}
        {/* Member badge */}
        <div style={{ marginTop: 10, textAlign: 'center' }}>
          <div className="lc-eyebrow" style={{ color: 'var(--gray-4)' }} data-testid="loyalty-member-number">
            LE CAYENNE FIDÉLITÉ
          </div>
          <div style={{ fontFamily: 'var(--font-mono)', fontSize: 11, color: 'var(--gray-4)', marginTop: 2 }}>
            #{memberNumber} {name ? '· ' + name : ''}
          </div>
        </div>
        {/* TTL countdown chip */}
        <div
          data-testid="loyalty-qr-countdown"
          role="timer"
          aria-label={'Expire dans ' + countdownText}
          style={{
            marginTop: 10,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            gap: 8,
            padding: '6px 12px',
            background: expiringSoon ? 'var(--orange-soft)' : 'var(--gray-1)',
            color: expiringSoon ? 'var(--orange-dark)' : 'var(--ink)',
            borderRadius: 999,
            fontSize: 11,
            fontWeight: 700,
            letterSpacing: '0.08em',
          }}
        >
          <span aria-hidden="true">⏱</span>
          <span>Expire dans {countdownText}</span>
          <button
            onClick={() => refresh()}
            disabled={isInflight}
            aria-label="Régénérer le code"
            style={{
              background: 'transparent',
              border: 0,
              color: 'inherit',
              fontSize: 13,
              cursor: isInflight ? 'wait' : 'pointer',
              fontWeight: 700,
              padding: 0,
            }}
          >
            ↻
          </button>
        </div>
      </div>
    );
  }

  // memo with always-equal comparator: this component manages its OWN refresh
  // state internally. Parent re-renders are ignored unless props (loyaltyCode/
  // memberNumber/name/mode) actually change.
  const LoyaltyQR = memo(LoyaltyQRImpl, (prev, next) => {
    return prev.loyaltyCode === next.loyaltyCode
      && prev.memberNumber === next.memberNumber
      && prev.name === next.name
      && prev.mode === next.mode;
  });

  Object.assign(window, { LoyaltyQR });
})();
