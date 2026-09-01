<?php

namespace Tests\Unit\Grok;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Le contrat de voie Grok est un artefact livré : s'il promet un chemin
 * fantôme ou empiète sur une zone gelée, la mission est fausse.
 */
class MissionGrokContractTest extends TestCase
{
    public function test_mission_file_states_owner_goal_and_delivery_rules(): void
    {
        $mission = file_get_contents(base_path('docs/grok/MISSION_GROK.md'));

        $this->assertStringContainsString('CONTRÔLE TOTAL DU BACK-OFFICE', $mission);
        $this->assertStringContainsString('docs/grok/FRONTIERES.md', $mission);
        $this->assertStringContainsString('reports/grok/JOURNAL.md', $mission);
        $this->assertStringContainsString('G-DATA', $mission);
        $this->assertStringContainsString('safe-test.sh', $mission);
        $this->assertStringContainsString('git add .', $mission);
        $this->assertStringContainsString('php artisan test', $mission);
    }

    public function test_every_grok_owned_glob_resolves_to_real_files(): void
    {
        $globs = $this->fence(base_path('docs/grok/FRONTIERES.md'), 'grok-owned');
        $this->assertGreaterThan(10, count($globs));

        $resolved = [];
        foreach ($globs as $glob) {
            foreach ($this->expand($glob) as $file) {
                $resolved[$file] = $glob;
            }
        }

        $this->assertArrayHasKey(
            realpath(base_path('app/Services/ItemVariationService.php')),
            $resolved
        );
    }

    public function test_never_touch_globs_exist_and_do_not_overlap_grok_owned(): void
    {
        $owned = [];
        foreach ($this->fence(base_path('docs/grok/FRONTIERES.md'), 'grok-owned') as $glob) {
            foreach ($this->expand($glob) as $file) {
                $owned[$file] = true;
            }
        }

        $forbidden = [];
        foreach ($this->fence(base_path('docs/grok/FRONTIERES.md'), 'never-touch') as $glob) {
            foreach ($this->expand($glob) as $file) {
                $forbidden[$file] = true;
                $this->assertArrayNotHasKey(
                    $file,
                    $owned,
                    'FRONTIERES overlap: '.$file
                );
            }
        }

        $this->assertArrayHasKey(
            realpath(base_path('app/Services/Pricing/PricingService.php')),
            $forbidden
        );
        $this->assertArrayHasKey(
            realpath(base_path('resources/js/components/admin/pos/PaymentComponent.vue')),
            $forbidden
        );
    }

    public function test_i18n_prefixes_are_declared(): void
    {
        $prefixes = $this->fence(base_path('docs/grok/FRONTIERES.md'), 'i18n-prefix');
        $this->assertContains('itemVariation', $prefixes);
        $this->assertContains('itemCategory', $prefixes);
        $this->assertContains('composer', $prefixes);
    }

    /**
     * @return list<string>
     */
    private function fence(string $path, string $name): array
    {
        $text = file_get_contents($path);
        $this->assertNotFalse($text);
        $ok = preg_match('/```'.$name.'\n(.*)\n```/sU', $text, $m);
        $this->assertSame(1, $ok, "missing fence {$name} in {$path}");
        $lines = preg_split("/\r\n|\n|\r/", trim($m[1]));
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function expand(string $pattern): array
    {
        if (str_ends_with($pattern, '/**')) {
            $dir = base_path(substr($pattern, 0, -3));
            $this->assertDirectoryExists($dir, $pattern);
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
            $files = [];
            foreach ($it as $file) {
                if ($file->isFile()) {
                    $files[] = $file->getRealPath();
                }
            }
            $this->assertNotEmpty($files, $pattern);

            return $files;
        }

        $full = base_path($pattern);
        $this->assertFileExists($full, $pattern);

        return [realpath($full)];
    }
}
