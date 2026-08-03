// Le Cayenne — BarcodeMock component (V0)
//
// V0 visual approximation of a Code128 barcode. NOT a real Code128 encoder —
// vertical bars with deterministically-derived widths from the value string.
// Phase 6 / Phase 11 native build : replace with jsbarcode or @capacitor/barcode.
//
// Renders a horizontally-scannable strip with value label underneath for human
// fallback. Used as alternative to QR for legacy scanners (Agent-4 F16).
//
// Props :
//   value  : string to encode (typically "FK:<loyalty_code>")
//   width  : SVG total width in px (default 264)
//   height : SVG height in px (default 88, ~5:2 ratio classic Code128)

(function () {
  'use strict';

  function BarcodeMock({ value = 'FK:LECAY', width = 264, height = 88 }) {
    // Deterministic pseudo-random width pattern from value chars.
    // Real Code128 has start/stop + checksum + 4-width bars/spaces.
    // Mock pattern: 60 narrow|wide segments mapped to char codes.
    const labelHeight = 14;
    const barAreaHeight = height - labelHeight - 8;
    const segments = 60;
    let seed = 1;
    for (let i = 0; i < value.length; i++) {
      seed = (seed * 31 + value.charCodeAt(i)) % 1000003;
    }
    const pattern = [];
    let x = 0;
    const totalUnits = segments * 1.5; // mix of 1 and 2 unit widths
    const unitW = width / totalUnits;
    for (let i = 0; i < segments; i++) {
      seed = (seed * 1103515245 + 12345) & 0x7fffffff;
      const isBar = (seed & 1) === 0;
      const isWide = (seed & 6) === 0;
      const w = unitW * (isWide ? 2 : 1);
      if (isBar) pattern.push({ x, w });
      x += w;
    }

    return (
      <svg width={width} height={height} viewBox={'0 0 ' + width + ' ' + height} style={{ background: '#fff', borderRadius: 8 }} role="img" aria-label={'Code-barres ' + value}>
        {pattern.map((p, i) => (
          <rect key={i} x={p.x} y={4} width={p.w} height={barAreaHeight} fill="#0A0A0A" />
        ))}
        <text
          x={width / 2}
          y={height - 4}
          fontFamily="var(--font-mono)"
          fontSize="11"
          fill="#0A0A0A"
          fontWeight="700"
          textAnchor="middle"
          letterSpacing="0.08em"
        >
          {value}
        </text>
      </svg>
    );
  }

  Object.assign(window, { BarcodeMock });
})();
