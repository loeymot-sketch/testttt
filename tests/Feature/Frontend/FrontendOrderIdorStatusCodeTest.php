<?php

namespace Tests\Feature\Frontend;

use Tests\TestCase;

/**
 * [W14 AUTHZ 2026-07-20] Régression : Frontend\OrderController doit PRÉSERVER le code HTTP
 * levé par le service. L'IDOR de show()/changeStatus() lève abort(403) (heal FRONT-SHOW-403-422,
 * FrontendOrderService::show/changeStatus), mais un catch(Exception)→422 SEUL l'aplatissait en 422,
 * défaisant le heal (trouvé par l'audit adversaire W14, reproduit live VPS : show/{autrui} = 422).
 * On verrouille structurellement le catch(HttpException) AVANT le catch(Exception) générique dans
 * les deux méthodes — même pattern que store() plus haut dans le même contrôleur.
 *
 * Test statique (file_get_contents, 0 DB) — même style que KioskOfflinePaymentScopeTest.
 */
class FrontendOrderIdorStatusCodeTest extends TestCase
{
    private function controllerSource(): string
    {
        return file_get_contents(base_path('app/Http/Controllers/Frontend/OrderController.php'));
    }

    /** Corps d'une méthode : de sa signature jusqu'à la prochaine `public function` (ou fin). */
    private function methodBody(string $src, string $signatureNeedle): string
    {
        $start = strpos($src, $signatureNeedle);
        $this->assertNotFalse($start, "méthode introuvable dans le contrôleur : {$signatureNeedle}");
        $next = strpos($src, 'public function', $start + strlen($signatureNeedle));

        return $next === false ? substr($src, $start) : substr($src, $start, $next - $start);
    }

    private function assertPreservesHttpStatus(string $body, string $method): void
    {
        $httpCatch = strpos($body, 'catch (HttpException');
        $genericCatch = strpos($body, 'catch (Exception');

        $this->assertNotFalse($httpCatch,
            "{$method}() doit attraper HttpException pour préserver le 403 IDOR (au lieu de l'aplatir en 422)");
        $this->assertStringContainsString('$exception->getStatusCode()', $body,
            "{$method}() doit renvoyer le status de l'HttpException, pas un code figé");
        $this->assertNotFalse($genericCatch, "{$method}() garde bien un catch(Exception) générique en dernier recours");
        $this->assertLessThan($genericCatch, $httpCatch,
            "{$method}() : catch(HttpException) DOIT précéder catch(Exception), sinon le 422 générique gagne");
    }

    public function test_show_preserves_service_http_status(): void
    {
        $body = $this->methodBody($this->controllerSource(), 'function show(FrontendOrder');
        $this->assertPreservesHttpStatus($body, 'show');
    }

    public function test_change_status_preserves_service_http_status(): void
    {
        $body = $this->methodBody($this->controllerSource(), 'function changeStatus(FrontendOrder');
        $this->assertPreservesHttpStatus($body, 'changeStatus');
    }

    /** Garde-fou : le service conserve bien l'abort(403) que le contrôleur doit désormais laisser passer. */
    public function test_service_still_aborts_403_on_ownership_mismatch(): void
    {
        $service = file_get_contents(base_path('app/Services/FrontendOrderService.php'));
        $this->assertStringContainsString("abort(403, 'Access denied: you do not own this order.')", $service,
            'la garde de propriété 403 du service ne doit pas disparaître');
    }
}
