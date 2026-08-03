<?php

namespace App\Console\Commands;

use App\Support\MenuImageThumb;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * [W5-PERF #1 2026-07-06] Génère les vignettes WebP ≤320 px des images menu
 * (public/images/menu par défaut — `menu_images.base_path`) servies par les
 * fallbacks `getThumbAttribute` POS/borne via App\Support\MenuImageThumb.
 *
 * Idempotente : une vignette existante ET plus récente que sa source est
 * sautée (re-run sûr en cron/déploiement). `--force` regénère tout ; les
 * images swappées plus tard (LOCK images réelles viandes/boissons) sont
 * rattrapées au run suivant grâce à la comparaison filemtime.
 *
 * GD requis (dispo : ext-gd + imagewebp vérifiés). Alpha préservé (PNG
 * détourés) ; les sources plus petites que --max sont quand même converties
 * en WebP (gain de poids sans upscale).
 */
class GeneratePosThumbsCommand extends Command
{
    protected $signature = 'images:generate-pos-thumbs
        {--max=320 : Bord le plus long (px) des vignettes générées}
        {--quality=82 : Qualité WebP (0-100)}
        {--force : Regénère même si une vignette à jour existe}';

    protected $description = '[W5-PERF] Génère les vignettes WebP <=320px du catalogue menu (fallbacks thumb POS/borne)';

    public function handle(): int
    {
        if (!function_exists('imagewebp')) {
            $this->error('GD/imagewebp indisponible — impossible de générer les vignettes.');

            return self::FAILURE;
        }

        $maxEdge = max(16, (int) $this->option('max'));
        $quality = min(100, max(1, (int) $this->option('quality')));
        $force = (bool) $this->option('force');

        $basePath = Config::get('menu_images.base_path', 'images/menu');
        $sourceDir = public_path($basePath);
        if (!is_dir($sourceDir)) {
            $this->error("Dossier source introuvable : {$sourceDir}");

            return self::FAILURE;
        }

        $thumbDir = $sourceDir . DIRECTORY_SEPARATOR . MenuImageThumb::SUBDIR;
        if (!is_dir($thumbDir) && !@mkdir($thumbDir, 0775, true) && !is_dir($thumbDir)) {
            $this->error("Impossible de créer {$thumbDir}");

            return self::FAILURE;
        }

        $sources = glob($sourceDir . '/*.{png,jpg,jpeg,PNG,JPG,JPEG}', GLOB_BRACE) ?: [];
        $generated = 0;
        $skipped = 0;
        $failed = 0;
        $sourceBytes = 0;
        $thumbBytes = 0;

        foreach ($sources as $source) {
            $filename = basename($source);
            $relative = MenuImageThumb::relativePath($basePath, $filename);
            if ($relative === null) {
                continue;
            }
            $target = public_path($relative);

            if (!$force && file_exists($target) && filemtime($target) >= filemtime($source)) {
                $skipped++;
                $sourceBytes += (int) filesize($source);
                $thumbBytes += (int) filesize($target);
                continue;
            }

            if ($this->convert($source, $target, $maxEdge, $quality)) {
                $generated++;
                $sourceBytes += (int) filesize($source);
                $thumbBytes += (int) filesize($target);
            } else {
                $failed++;
                $this->warn("Échec conversion : {$filename}");
            }
        }

        $this->info(sprintf(
            'Vignettes : %d générées, %d à jour (sautées), %d échecs — sources %s → vignettes %s',
            $generated,
            $skipped,
            $failed,
            $this->humanBytes($sourceBytes),
            $this->humanBytes($thumbBytes),
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Redimensionne (fit ≤ maxEdge, ratio préservé, jamais d'upscale) et
     * encode en WebP avec alpha préservé.
     */
    private function convert(string $source, string $target, int $maxEdge, int $quality): bool
    {
        $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        $image = match ($ext) {
            'png' => @imagecreatefrompng($source),
            'jpg', 'jpeg' => @imagecreatefromjpeg($source),
            default => false,
        };
        if ($image === false) {
            return false;
        }

        // Les PNG palette ne supportent ni resample propre ni alpha 8-bit.
        if (!imageistruecolor($image)) {
            @imagepalettetotruecolor($image);
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1.0, $maxEdge / max(1, max($width, $height)));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $thumb = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
        imagefill($thumb, 0, 0, $transparent);
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);

        $ok = @imagewebp($thumb, $target, $quality);
        imagedestroy($thumb);

        if (!$ok && file_exists($target)) {
            @unlink($target); // pas de vignette corrompue à moitié écrite
        }

        return (bool) $ok;
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return sprintf('%.1f Mo', $bytes / 1048576);
        }
        if ($bytes >= 1024) {
            return sprintf('%.0f Ko', $bytes / 1024);
        }

        return "{$bytes} o";
    }
}
