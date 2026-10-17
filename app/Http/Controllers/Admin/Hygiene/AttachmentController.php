<?php

namespace App\Http\Controllers\Admin\Hygiene;

use App\Models\HygieneAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * [CAYENNE HYGIÈNE 2026-10-17] Pièces jointes photo (BL, étiquette fournisseur, bandelette de test d'huile…).
 *
 * L'image reçue n'est JAMAIS conservée telle quelle : elle est décodée puis ré-encodée en JPEG (côté long ≤ 1600 px), ce qui retire
 * EXIF/GPS et neutralise tout contenu qui n'est pas une vraie image. Stockage privé (disque `local`), servi derrière authentification.
 */
class AttachmentController extends HygieneController
{
    private const MAX_SIDE = 1600;
    private const MAX_PIXELS = 40_000_000;

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'kind' => ['nullable', 'in:bl,label,strip,task,other'],
        ], [], ['file' => 'photo']);

        $file = $v['file'];
        $raw = @file_get_contents($file->getRealPath());
        $info = $raw === false ? false : @getimagesizefromstring($raw);
        if ($info === false || ! in_array($info[2] ?? 0, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return response()->json(['message' => 'Ce fichier n’est pas une photo (JPEG, PNG ou WebP).', 'errors' => ['file' => ['Photo illisible.']]], 422);
        }
        // un PNG de 400 Ko peut déclarer 20 000 × 20 000 px et réclamer ~1 Go au décodage : on refuse AVANT de décoder
        if ((int) $info[0] * (int) $info[1] > self::MAX_PIXELS) {
            return response()->json(['message' => 'Photo trop grande (plus de 40 mégapixels).', 'errors' => ['file' => ['Photo trop grande.']]], 422);
        }
        $img = @imagecreatefromstring($raw);
        if ($img === false) {
            return response()->json(['message' => 'Photo illisible.', 'errors' => ['file' => ['Photo illisible.']]], 422);
        }
        $img = $this->orient($img, $raw, (int) $info[2]);

        [$w, $h] = [imagesx($img), imagesy($img)];
        $scale = min(1.0, self::MAX_SIDE / max($w, $h));
        if ($scale < 1.0) {
            $resized = imagescale($img, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
            if ($resized !== false) {
                imagedestroy($img);
                $img = $resized;
                [$w, $h] = [imagesx($img), imagesy($img)];
            }
        }
        // fond blanc (PNG transparent → JPEG)
        $flat = imagecreatetruecolor($w, $h);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);
        imagedestroy($img);
        ob_start();
        imagejpeg($flat, null, 82);
        $bytes = (string) ob_get_clean();
        imagedestroy($flat);

        $branch = $this->branchId($request);
        $path = 'hygiene/'.$branch.'/'.now()->format('Y/m').'/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, $bytes);

        $row = new HygieneAttachment([
            'kind' => $v['kind'] ?? 'other', 'path' => $path, 'mime' => 'image/jpeg', 'bytes' => strlen($bytes),
            'width' => $w, 'height' => $h, 'sha256' => hash('sha256', $bytes), 'uploaded_by' => $request->user()?->id,
        ]);
        $row->branch_id = $branch;
        $row->save();

        return response()->json(['data' => $this->present($row)], 201);
    }

    public function show(string $attachment): StreamedResponse
    {
        $row = HygieneAttachment::query()->findOrFail((int) $attachment);
        abort_unless(Storage::disk('local')->exists($row->path), 404);

        return Storage::disk('local')->response($row->path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function present(HygieneAttachment $a): array
    {
        return ['id' => $a->id, 'kind' => $a->kind, 'bytes' => $a->bytes, 'width' => $a->width, 'height' => $a->height, 'url' => url('/api/admin/hygiene/attachments/'.$a->id)];
    }

    /** Remet la photo à l'endroit d'après l'orientation EXIF (les téléphones l'écrivent au lieu de pivoter les pixels). */
    private function orient($img, string $raw, int $type)
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) return $img;
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($raw));
        $o = (int) ($exif['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($angle !== 0) {
            $rot = imagerotate($img, $angle, 0);
            if ($rot !== false) {
                imagedestroy($img);

                return $rot;
            }
        }

        return $img;
    }
}
