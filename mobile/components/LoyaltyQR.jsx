// Le Cayenne — LoyaltyQR component
//
// [GOAL-SYNC 2026-07-08] QR RÉEL (contrat §3) : rendu du token serveur
// 'lqr.…' (POST /api/frontend/loyalty/qr via useLoyaltyQR) avec la lib
// vendorisée LOCALE `window.qrcode` (vendor/qrcode.js) —
// createSvgTag({cellSize:3, margin:0, scalable:true}). Le QRMock décoratif
// est SUPPRIMÉ de ce composant (il ne scannait rien ; le format legacy
// 'FK:<code>' est REJETÉ backend).
//
// UX : compte à rebours FR « Expire dans Xs » + bouton « Actualiser » +
// loyalty_code en clair sous le QR + « Présentez ce QR ou dictez votre
// numéro en caisse ». États propres FR : hors-ligne / connexion requise.
//
// Memoized child of ScreenLoyalty per DEC-19 : le tick 1 s re-rend CE
// composant uniquement, pas le parent (pas de jank au scroll historique).
//
// data-testid conservés : loyalty-qr (data-payload = token réel),
// loyalty-qr-countdown, loyalty-member-number + nouveaux loyalty-qr-svg /
// loyalty-qr-refresh / loyalty-code-text / loyalty-qr-error.

(function () {
  'use strict';
  const { memo, useEffect, useRef } = React;

  function LoyaltyQRImpl({ loyaltyCode, memberNumber, name, mode }) {
    const { token, secondsLeft, loading, error, refresh, loyaltyCode: serverCode } = window.useLoyaltyQR();
    const svgHostRef = useRef(null);
    const liveRegionRef = useRef(null);
    const lastAnnouncementRef = useRef('init');

    // loyalty_code affiché : celui minté par le serveur prime (source de vérité).
    const displayCode = serverCode || loyaltyCode || '';

    // Render-counter hook for perf tests (Agent-6 §6.3)
    useEffect(() => {
      if (window.LC && window.LC.dev && window.LC.dev.notifyRender) {
        window.LC.dev.notifyRender('LoyaltyQR');
      }
    });

    // [GOAL-SYNC 2026-07-08] Rendu SVG réel du token (lib locale, contrat §3).
    useEffect(() => {
      if (!svgHostRef.current) return;
      if (!token || typeof window.qrcode !== 'function') {
        svgHostRef.current.innerHTML = '';
        return;
      }
      try {
        const q = window.qrcode(0, 'M');
        q.addData(token);
        q.make();
        svgHostRef.current.innerHTML = q.createSvgTag({ cellSize: 3, margin: 0, scalable: true });
      } catch (e) {
        svgHostRef.current.innerHTML = '';
      }
    }, [token]);

    // Annonces SR throttlées (chargé / 60 s / 10 s / rafraîchi)
    useEffect(() => {
      if (!liveRegionRef.current) return;
      let msg = null;
      if (lastAnnouncementRef.current === 'init' && token) {
        msg = 'Code QR fidélité chargé, valable 5 minutes.';
        lastAnnouncementRef.current = 'loaded';
      } else if (secondsLeft === 60 && lastAnnouncementRef.current !== 'warn60') {
        msg = 'Code QR expire dans 1 minute.';
        lastAnnouncementRef.current = 'warn60';
      } else if (secondsLeft === 10 && lastAnnouncementRef.current !== 'warn10') {
        msg = 'Code QR expire dans 10 secondes.';
        lastAnnouncementRef.current = 'warn10';
      } else if (lastAnnouncementRef.current === 'warn10' && secondsLeft > 200) {
        lastAnnouncementRef.current = 'loaded';
        msg = 'Code QR rafraîchi.';
      }
      if (msg) liveRegionRef.current.textContent = msg;
    }, [secondsLeft, token]);

    const countdownText = secondsLeft >= 60
      ? Math.floor(secondsLeft / 60) + ' min ' + (secondsLeft % 60) + ' s'
      : secondsLeft + ' s';
    const expiringSoon = secondsLeft > 0 && secondsLeft < 60;

    // ── États d'erreur propres (FR) ──────────────────────────────────────
    if (error === 'auth_required') {
      return (
        <div data-testid="loyalty-qr" data-qr-state="auth" role="status" style={{ width: 224, textAlign: 'center', padding: '24px 8px' }}>
          <div style={{ fontSize: 32 }} aria-hidden="true">🔒</div>
          <div style={{ marginTop: 8, fontWeight: 700, fontSize: 14 }}>Connexion requise</div>
          <div data-testid="loyalty-qr-error" style={{ marginTop: 4, fontSize: 12, color: 'var(--gray-3)' }}>
            Connecte-toi pour afficher ton QR fidélité et cumuler des points en caisse.
          </div>
        </div>
      );
    }

    if (error === 'network' || (error && !token)) {
      return (
        <div data-testid="loyalty-qr" data-qr-state="offline" role="status" style={{ width: 224, textAlign: 'center', padding: '24px 8px' }}>
          <div style={{ fontSize: 32 }} aria-hidden="true">📡</div>
          <div style={{ marginTop: 8, fontWeight: 700, fontSize: 14 }}>Hors ligne</div>
          <div data-testid="loyalty-qr-error" style={{ marginTop: 4, fontSize: 12, color: 'var(--gray-3)' }}>
            {error === 'network'
              ? 'Le QR fidélité nécessite une connexion. Tu peux dicter ton numéro en caisse.'
              : 'Impossible de générer le code pour le moment. Réessaie dans un instant.'}
          </div>
          {displayCode ? (
            <div data-testid="loyalty-code-text" style={{ marginTop: 10, fontFamily: 'var(--font-mono)', fontSize: 18, fontWeight: 700, letterSpacing: '0.14em' }}>{displayCode}</div>
          ) : null}
          <button
            data-testid="loyalty-qr-refresh"
            onClick={() => refresh()}
            disabled={loading}
            className="lc-btn lc-btn--ink"
            style={{ marginTop: 12, height: 40, fontSize: 12 }}
          >
            {loading ? 'Nouvelle tentative…' : 'Réessayer'}
          </button>
        </div>
      );
    }

    return (
      <div data-testid="loyalty-qr" data-qr-state={token ? 'ready' : 'loading'} data-payload={token || ''} role="img" aria-label={'Code QR fidélité, expire dans ' + countdownText}>
        {/* SR-only live region for QR refresh announcements */}
        <div ref={liveRegionRef} role="status" aria-live="polite" aria-atomic="true" style={{ position: 'absolute', width: 1, height: 1, padding: 0, margin: -1, overflow: 'hidden', clip: 'rect(0,0,0,0)', whiteSpace: 'nowrap', border: 0 }} />
        {/* The actual QR/Barcode visual */}
        {mode === 'barcode' ? (
          <window.BarcodeMock value={displayCode || 'LECAYENNE'} width={264} height={88} />
        ) : (
          <div className={token && !expiringSoon ? 'lc-pulse' : ''} style={{ borderRadius: 12, padding: 4, display: 'inline-block', background: '#fff', position: 'relative' }}>
            {/* Conteneur du SVG réel (remplace la grille décorative .rdl-qr-art) */}
            <div
              ref={svgHostRef}
              data-testid="loyalty-qr-svg"
              style={{ width: 208, height: 208, display: 'flex', alignItems: 'center', justifyContent: 'center' }}
            />
            {!token && (
              <div role="status" style={{ position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', textAlign: 'center', fontSize: 12, color: 'var(--gray-3)', fontWeight: 600 }}>
                Génération du code…
              </div>
            )}
          </div>
        )}
        {/* loyalty_code en clair + consigne caisse (contrat §3) */}
        <div style={{ marginTop: 10, textAlign: 'center' }}>
          {displayCode ? (
            <div data-testid="loyalty-code-text" style={{ fontFamily: 'var(--font-mono)', fontSize: 18, fontWeight: 700, letterSpacing: '0.16em', color: 'var(--ink)' }}>{displayCode}</div>
          ) : null}
          <div style={{ marginTop: 4, fontSize: 11, color: 'var(--gray-3)', fontWeight: 600 }}>
            Présentez ce QR ou dictez votre numéro en caisse
          </div>
          <div className="lc-eyebrow" style={{ color: 'var(--gray-4)', marginTop: 8 }} data-testid="loyalty-member-number">
            LE CAYENNE FIDÉLITÉ
          </div>
          <div style={{ fontFamily: 'var(--font-mono)', fontSize: 11, color: 'var(--gray-4)', marginTop: 2 }}>
            {memberNumber ? '#' + memberNumber : ''} {name ? '· ' + name : ''}
          </div>
        </div>
        {/* TTL countdown + Actualiser */}
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
            background: expiringSoon ? 'var(--yellow-soft)' : 'var(--cream)',
            color: expiringSoon ? 'var(--orange-text)' : 'var(--ink)',
            borderRadius: 999,
            fontSize: 11,
            fontWeight: 700,
            letterSpacing: '0.08em',
          }}
        >
          <span aria-hidden="true">⏱</span>
          <span>{token ? 'Expire dans ' + countdownText : 'Génération…'}</span>
          <button
            data-testid="loyalty-qr-refresh"
            onClick={() => refresh()}
            disabled={loading}
            aria-label="Actualiser le code"
            style={{
              background: 'transparent',
              border: 0,
              color: 'inherit',
              fontSize: 11,
              cursor: loading ? 'wait' : 'pointer',
              fontWeight: 700,
              padding: 0,
              textTransform: 'uppercase',
              letterSpacing: '0.08em',
              textDecoration: 'underline',
            }}
          >
            {loading ? '…' : 'Actualiser'}
          </button>
        </div>
      </div>
    );
  }

  // memo with props comparator: this component manages its OWN refresh state
  // internally. Parent re-renders are ignored unless props actually change.
  const LoyaltyQR = memo(LoyaltyQRImpl, (prev, next) => {
    return prev.loyaltyCode === next.loyaltyCode
      && prev.memberNumber === next.memberNumber
      && prev.name === next.name
      && prev.mode === next.mode;
  });

  Object.assign(window, { LoyaltyQR });
})();
