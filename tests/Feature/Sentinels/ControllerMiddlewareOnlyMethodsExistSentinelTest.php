<?php

namespace Tests\Feature\Sentinels;

use App\Http\Controllers\Controller;
use ReflectionClass;
use Tests\TestCase;

/**
 * [abuse-heal 2026-06-20 W6 PHANTOM-GATE-TABLEORDER-01] Systemic guard against the false-green
 * permission-gate class.
 *
 * Laravel binds `$this->middleware([...])->only('m')` by the route HANDLER METHOD NAME. If 'm'
 * is NOT a real public method of the controller, the middleware SILENTLY never attaches — and any
 * sibling write/read handler omitted from the (wrong) ->only list runs UNGATED, with no functional
 * test catching it. This bug class hit SalesReportController (->only('overview') vs the real
 * salesReportOverview) and TableOrderController (->only('selectDeliveryBoy') — a phantom copied
 * from OnlineOrderController — leaving tokenCreate ungated, a live token-overwrite bypass).
 *
 * This sentinel reflects EVERY admin controller's recorded middleware ->only()/->except() options
 * and asserts each named method actually exists, so a future phantom-method gate fails CI loudly.
 *
 * @group sentinel
 * @group security
 */
class ControllerMiddlewareOnlyMethodsExistSentinelTest extends TestCase
{
    public function test_every_admin_controller_only_except_names_a_real_method(): void
    {
        $dir = app_path('Http/Controllers/Admin');
        $phantoms = [];
        $checked = 0;

        foreach ($this->phpFiles($dir) as $file) {
            $class = $this->classFromPath($file);
            if ($class === null || ! class_exists($class)) {
                continue;
            }
            $ref = new ReflectionClass($class);
            if ($ref->isAbstract() || ! $ref->isSubclassOf(Controller::class)) {
                continue;
            }

            try {
                $controller = $this->app->make($class);
            } catch (\Throwable $e) {
                // Cannot resolve constructor dependencies in the test container — skip (the
                // vast majority resolve; the phantom-gate class is caught on those that do).
                continue;
            }
            if (! method_exists($controller, 'getMiddleware')) {
                continue;
            }

            $methods = get_class_methods($controller);
            $checked++;

            foreach ($controller->getMiddleware() as $entry) {
                $options = $entry['options'] ?? [];
                foreach (['only', 'except'] as $key) {
                    foreach ((array) ($options[$key] ?? []) as $method) {
                        if (! in_array($method, $methods, true)) {
                            $phantoms[] = "{$class}::{$method}() named in ->{$key}() does not exist";
                        }
                    }
                }
            }
        }

        $this->assertGreaterThan(20, $checked, 'Sentinel resolved too few controllers — reflection harness broken.');
        $this->assertSame(
            [],
            $phantoms,
            "A controller permission gate names a NON-EXISTENT method — Laravel binds ->only()/->except() "
            . "by the real handler method name, so this gate silently never attaches (false-green). "
            . 'Phantoms: ' . implode(' | ', $phantoms)
        );
    }

    /** @return iterable<string> */
    private function phpFiles(string $dir): iterable
    {
        if (! is_dir($dir)) {
            return [];
        }
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }

    private function classFromPath(string $path): ?string
    {
        $appPath = app_path();
        if (! str_starts_with($path, $appPath)) {
            return null;
        }
        $rel = ltrim(substr($path, strlen($appPath)), DIRECTORY_SEPARATOR);
        $rel = preg_replace('/\.php$/', '', $rel);
        return 'App\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $rel);
    }
}
