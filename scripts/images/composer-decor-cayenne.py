#!/usr/bin/env python3
"""Compose un visuel produit DÉTOURÉ (PNG alpha) sur le décor Le Cayenne, au
même format 1536x1024 que les sandwichs / burgers / tacos / galettes du menu.

Usage (depuis la racine du dépôt) :
    python3 scripts/images/composer-decor-cayenne.py nuggets tarte ...
    → réécrit public/images/menu/<nom>.png (palette 256 couleurs), puis
    php artisan images:generate-pos-thumbs   (vignettes WebP servies à la borne/caisse)

Règles (2026-09-30, demande owner) :
- Les BOISSONS restent en détouré sur blanc (elles se lisent bien ainsi).
- Un PNG déjà opaque (fond présent) est sauté.
- Les pixels partiellement transparents INTÉRIEURS (texte blanc d'un gobelet,
  reflets) sont opacifiés : invisibles sur blanc, ils laissaient voir le décor.
  Ça ne répare PAS un détourage troué (raclette, fromage, champignons, boursin :
  à refaire à la source).
"""
import os, sys
from PIL import Image, ImageDraw, ImageFilter

HERE = os.path.dirname(os.path.abspath(__file__))
BACKDROP = os.path.join(HERE, 'decor-cayenne-1536x1024.png')
MENU = os.path.join(HERE, '..', '..', 'public', 'images', 'menu')


def composer(name: str) -> str:
    src = os.path.join(MENU, name + '.png')
    if not os.path.exists(src):
        return f'ABSENT {name}'
    im = Image.open(src).convert('RGBA')
    if im.getchannel('A').getextrema()[0] == 255:
        return f'SAUTÉ (déjà opaque) {name}'
    bg = Image.open(BACKDROP).convert('RGBA')
    W, H = bg.size
    max_h, max_w = int(H * 0.60), int(W * 0.60)
    floor_y = int(H * 0.855)  # ligne d'appui sur le plan de travail

    im = im.crop(im.getbbox())
    a = im.getchannel('A')
    core = a.point(lambda v: 255 if v > 40 else 0)
    closed = core.filter(ImageFilter.MaxFilter(31)).filter(ImageFilter.MinFilter(31))
    interior = closed.filter(ImageFilter.MinFilter(9))
    im.putalpha(Image.composite(Image.new('L', a.size, 255), a, interior))

    r = min(max_h / im.height, max_w / im.width)
    im = im.resize((max(1, round(im.width * r)), max(1, round(im.height * r))), Image.LANCZOS)
    x, y = (W - im.width) // 2, floor_y - im.height

    out = bg.copy()
    shadow = Image.new('RGBA', (W, H), (0, 0, 0, 0))
    ew, eh = int(im.width * 0.95), int(im.height * 0.16)
    ImageDraw.Draw(shadow).ellipse(
        (W // 2 - ew // 2, floor_y - eh // 2, W // 2 + ew // 2, floor_y + eh // 2), fill=(0, 0, 0, 170))
    out.alpha_composite(shadow.filter(ImageFilter.GaussianBlur(28)))
    soft = Image.new('RGBA', (W, H), (0, 0, 0, 0))
    soft.paste(Image.new('RGBA', im.size, (0, 0, 0, 120)), (x + 10, y + 26), im)
    out.alpha_composite(soft.filter(ImageFilter.GaussianBlur(18)))
    out.alpha_composite(im, (x, y))

    out.convert('RGB').quantize(256, method=Image.Quantize.MEDIANCUT,
                                dither=Image.Dither.FLOYDSTEINBERG).save(src, 'PNG', optimize=True)
    return f'OK {name} {im.size}'


if __name__ == '__main__':
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    for n in sys.argv[1:]:
        print(composer(n))
