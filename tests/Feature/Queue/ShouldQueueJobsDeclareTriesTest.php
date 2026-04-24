<?php

namespace Tests\Feature\Queue;

use Illuminate\Contracts\Queue\ShouldQueue;
use ReflectionClass;
use Tests\TestCase;

/**
 * [F-NEW03 / Audit Claude T-CLA-3] Architectural invariant — every Job that
 * implements ShouldQueue MUST declare a finite retry budget (`$tries` property
 * or `tries()` method). Combined with the production worker config
 * `--tries=0` (which delegates the per-job budget; see
 * `docs/operations/QUEUE_TOPOLOGY.md` §2), a Job missing `$tries` would mean
 * **infinite retries on failure** — a pager-grade footgun.
 *
 * This test acts as a CI tripwire so that every new ShouldQueue Job added to
 * `app/Jobs/` is forced to think about its retry policy at write time.
 */
class ShouldQueueJobsDeclareTriesTest extends TestCase
{
    public function test_every_should_queue_job_in_app_jobs_declares_a_retry_budget(): void
    {
        $jobsDir = base_path('app/Jobs');
        $this->assertDirectoryExists($jobsDir, 'app/Jobs directory MUST exist.');

        $files = glob($jobsDir . '/*.php');
        $offenders = [];

        foreach ($files as $file) {
            $class = $this->resolveClassFromFile($file);
            if ($class === null || ! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait()) {
                continue;
            }
            if (! $reflection->implementsInterface(ShouldQueue::class)) {
                continue;
            }

            $hasTriesProperty = $reflection->hasProperty('tries')
                && $reflection->getProperty('tries')->getDeclaringClass()->getName() === $reflection->getName();
            $hasTriesMethod = $reflection->hasMethod('tries')
                && $reflection->getMethod('tries')->getDeclaringClass()->getName() === $reflection->getName();

            // Inherited $tries from a base Job class is also acceptable.
            $hasInheritedTries = ! $hasTriesProperty
                && $reflection->hasProperty('tries')
                && $reflection->getProperty('tries')->getDefaultValue() !== null;

            if (! $hasTriesProperty && ! $hasTriesMethod && ! $hasInheritedTries) {
                $offenders[] = $class;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "ShouldQueue jobs missing a retry budget (\$tries property or tries() method):\n  - "
                . implode("\n  - ", $offenders)
                . "\nWith --tries=0 worker config, a missing budget = infinite retries. "
                . 'See docs/operations/QUEUE_TOPOLOGY.md §2 for the operational rationale.'
        );
    }

    private function resolveClassFromFile(string $path): ?string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        if (! preg_match('/^namespace\s+([^;]+);/m', $contents, $nsMatch)) {
            return null;
        }
        if (! preg_match('/^(?:abstract\s+|final\s+)?class\s+(\w+)/m', $contents, $classMatch)) {
            return null;
        }

        return $nsMatch[1] . '\\' . $classMatch[1];
    }
}
