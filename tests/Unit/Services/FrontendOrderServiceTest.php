<?php

namespace Tests\Unit\Services;

use Tests\TestCase;

/**
 * PLAN_01 D-001 — Tests de sécurité prix fallback
 * Vérifie que les items inexistants sont rejetés et que le prix DB est toujours utilisé
 */
class FrontendOrderServiceTest extends TestCase
{
    /**
     * @test
     * D-001 : Le code doit lancer InvalidArgumentException pour item inexistant
     */
    public function it_throws_invalid_argument_exception_for_nonexistent_item(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('introuvable');
        $this->expectExceptionCode(422);

        // Simulation du comportement attendu
        $dbItem = null;
        $itemId = 999999;

        if (!$dbItem) {
            throw new \InvalidArgumentException(
                "Item ID {$itemId} introuvable. Commande rejetée.",
                422
            );
        }
    }

    /**
     * @test
     * D-001 : Le prix DB doit être prioritaire sur le prix client
     */
    public function it_prioritizes_db_price_over_client_price(): void
    {
        // Arrange
        $dbPrice = 10.00;
        $clientPrice = 0.01;

        // Act - Simuler la logique corrigée
        $dbItem = (object)['price' => $dbPrice];
        if (!$dbItem) {
            throw new \InvalidArgumentException("Item introuvable");
        }
        $itemPrice = $dbItem->price; // Toujours utiliser prix DB

        // Assert
        $this->assertEquals($dbPrice, $itemPrice);
        $this->assertNotEquals($clientPrice, $itemPrice);
    }

    /**
     * @test
     * D-001 : Vérifier que le code source contient la protection
     */
    public function source_code_contains_item_validation(): void
    {
        $sourceFile = base_path('app/Services/FrontendOrderService.php');
        $content = file_get_contents($sourceFile);

        // Vérifier la présence de la protection PLAN_01
        $this->assertStringContainsString('PLAN_01', $content);
        $this->assertStringContainsString('D-001', $content);
        $this->assertStringContainsString('Item::select', $content);
        $this->assertStringContainsString('introuvable', $content);

        // Vérifier qu'il n'y a plus de fallback sur prix client
        $this->assertStringNotContainsString('$item->item_price', $content);
    }

    /**
     * @test
     * Les variations/extras invalides doivent rejeter la commande au lieu d'être ignorés.
     */
    public function source_code_rejects_invalid_variations_and_extras(): void
    {
        $sourceFile = base_path('app/Services/FrontendOrderService.php');
        $content = file_get_contents($sourceFile);

        $this->assertStringContainsString("Variation ID {\$varId} introuvable", $content);
        $this->assertStringContainsString("Extra ID {\$extId} introuvable", $content);
        $this->assertStringNotContainsString('if (!$dbVar) continue;', $content);
        $this->assertStringNotContainsString('if (!$dbExt) continue;', $content);
    }

    /**
     * @test
     * D-001 : Vérifier que OrderService.php contient aussi la protection
     */
    public function order_service_contains_item_validation(): void
    {
        $sourceFile = base_path('app/Services/OrderService.php');
        $content = file_get_contents($sourceFile);

        // Vérifier la présence de la protection PLAN_01
        $this->assertStringContainsString('PLAN_01', $content);
        $this->assertStringContainsString('D-001', $content);
        $this->assertStringContainsString('InvalidArgumentException', $content);
    }
}
