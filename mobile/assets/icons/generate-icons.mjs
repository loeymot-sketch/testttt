// Le Cayenne — PWA icon generator (B3-M, 2026-06-10)
//
// Canvas-free, dependency-free PNG writer (node:zlib only — no network, no
// node-canvas/sharp). Generates icon-192.png + icon-512.png:
//   • full-bleed NOIR background (#0A0A0A — the design's --ink, maskable-safe)
//   • ORANGE #FF5A1F circle (brand primary — NEVER #F4501E, mobile mandate)
//   • "LC" monogram in WHITE, 5×7 bitmap font scaled (within the maskable
//     80% safe zone)
//   • YELLOW #FFD93D accent bar under the monogram
//
// Run: node mobile/assets/icons/generate-icons.mjs   (from repo root)
// Output is committed-as-asset; re-run only if the palette changes.

import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import { fileURLToPath } from 'node:url';

const OUT_DIR = path.dirname(fileURLToPath(import.meta.url));

// ── Palette (mobile mandate: NOIR / ORANGE #FF5A1F / JAUNE / BLANC) ──
const NOIR = [0x0a, 0x0a, 0x0a, 255];
const ORANGE = [0xff, 0x5a, 0x1f, 255];
const JAUNE = [0xff, 0xd9, 0x3d, 255];
const BLANC = [0xff, 0xff, 0xff, 255];

// ── Minimal PNG encoder (RGBA8, no filter) ──
const CRC_TABLE = (() => {
  const t = new Uint32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    t[n] = c >>> 0;
  }
  return t;
})();
function crc32(buf) {
  let c = 0xffffffff;
  for (let i = 0; i < buf.length; i++) c = CRC_TABLE[(c ^ buf[i]) & 0xff] ^ (c >>> 8);
  return (c ^ 0xffffffff) >>> 0;
}
function chunk(type, data) {
  const len = Buffer.alloc(4);
  len.writeUInt32BE(data.length);
  const typeBuf = Buffer.from(type, 'ascii');
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(Buffer.concat([typeBuf, data])));
  return Buffer.concat([len, typeBuf, data, crc]);
}
function encodePNG(width, height, rgba) {
  const sig = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(width, 0);
  ihdr.writeUInt32BE(height, 4);
  ihdr[8] = 8;  // bit depth
  ihdr[9] = 6;  // color type RGBA
  // raw scanlines: filter byte 0 + pixels
  const raw = Buffer.alloc(height * (1 + width * 4));
  for (let y = 0; y < height; y++) {
    const rowStart = y * (1 + width * 4);
    raw[rowStart] = 0;
    rgba.copy(raw, rowStart + 1, y * width * 4, (y + 1) * width * 4);
  }
  const idat = zlib.deflateSync(raw, { level: 9 });
  return Buffer.concat([sig, chunk('IHDR', ihdr), chunk('IDAT', idat), chunk('IEND', Buffer.alloc(0))]);
}

// ── 5×7 bitmap glyphs ──
const GLYPHS = {
  L: ['10000', '10000', '10000', '10000', '10000', '10000', '11111'],
  C: ['01110', '10001', '10000', '10000', '10000', '10001', '01110'],
};

function drawIcon(size) {
  const px = Buffer.alloc(size * size * 4);
  const put = (x, y, [r, g, b, a]) => {
    if (x < 0 || y < 0 || x >= size || y >= size) return;
    const i = (y * size + x) * 4;
    px[i] = r; px[i + 1] = g; px[i + 2] = b; px[i + 3] = a;
  };

  // 1. noir background (full bleed — maskable-safe)
  for (let y = 0; y < size; y++) for (let x = 0; x < size; x++) put(x, y, NOIR);

  // 2. orange circle, radius 40% (inside the 80% maskable safe zone)
  const cx = size / 2, cy = size / 2, r = size * 0.4;
  for (let y = 0; y < size; y++) {
    for (let x = 0; x < size; x++) {
      const dx = x + 0.5 - cx, dy = y + 0.5 - cy;
      if (dx * dx + dy * dy <= r * r) put(x, y, ORANGE);
    }
  }

  // 3. "LC" monogram in white — 2 glyphs of 5 cols + 1 col gap = 11 cols × 7 rows
  const cols = 11, rows = 7;
  const scale = Math.max(1, Math.floor((size * 0.42) / cols));
  const textW = cols * scale, textH = rows * scale;
  const ox = Math.round(cx - textW / 2);
  const oy = Math.round(cy - textH / 2) - Math.round(size * 0.02);
  const letters = ['L', 'C'];
  letters.forEach((ch, li) => {
    const glyph = GLYPHS[ch];
    const gx = ox + li * 6 * scale; // 5 cols + 1 gap
    for (let gy = 0; gy < 7; gy++) {
      for (let gc = 0; gc < 5; gc++) {
        if (glyph[gy][gc] === '1') {
          for (let sy = 0; sy < scale; sy++)
            for (let sx = 0; sx < scale; sx++)
              put(gx + gc * scale + sx, oy + gy * scale + sy, BLANC);
        }
      }
    }
  });

  // 4. yellow accent bar under the monogram
  const barW = Math.round(textW * 0.8), barH = Math.max(2, Math.round(scale * 0.8));
  const bx = Math.round(cx - barW / 2), by = oy + textH + Math.round(scale * 1.2);
  for (let y = by; y < by + barH; y++)
    for (let x = bx; x < bx + barW; x++) put(x, y, JAUNE);

  return encodePNG(size, size, px);
}

for (const size of [192, 512]) {
  const out = path.join(OUT_DIR, `icon-${size}.png`);
  fs.writeFileSync(out, drawIcon(size));
  console.log(`wrote ${out} (${fs.statSync(out).size} bytes)`);
}
