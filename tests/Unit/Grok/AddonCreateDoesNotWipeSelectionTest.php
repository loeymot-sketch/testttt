<?php

namespace Tests\Unit\Grok;

use Tests\TestCase;

/**
 * Gestes commerçant : « Ajouter un addon » → choisir un produit dans la liste.
 * Le handler variation() recréait le formulaire avec addon_item_id: null,
 * donc le produit choisi disparaissait avant Enregistrer.
 */
class AddonCreateDoesNotWipeSelectionTest extends TestCase
{
    public function test_variation_handler_does_not_reset_addon_item_id_to_null(): void
    {
        $src = file_get_contents(
            base_path('resources/js/components/admin/items/addon/ItemAddonCreateComponent.vue')
        );
        $this->assertNotFalse($src);

        $this->assertSame(
            1,
            preg_match('/variation:\s*function\s*\(id\)\s*\{(.*)\},\s*save:/s', $src, $m),
            'le handler variation() doit toujours exister'
        );
        $this->assertStringContainsString('this.props.form.addon_item_id = id', $m[1]);
        $this->assertStringNotContainsString('addon_item_id: null', $m[1]);
    }

    public function test_save_stringifies_variation_picks_only_when_they_are_an_object(): void
    {
        $src = file_get_contents(
            base_path('resources/js/components/admin/items/addon/ItemAddonCreateComponent.vue')
        );

        $this->assertStringContainsString('typeof this.props.form.addon_item_variation === \'object\'', $src);
    }

    public function test_role_create_reset_uses_role_store_not_analytic(): void
    {
        $src = file_get_contents(
            base_path('resources/js/components/admin/settings/Role/RoleCreateComponent.vue')
        );
        $this->assertStringContainsString("dispatch('role/reset')", $src);
        $this->assertStringNotContainsString("dispatch('analytic/reset')", $src);
    }

    public function test_role_list_hides_delete_for_pos_operator_by_name(): void
    {
        $src = file_get_contents(
            base_path('resources/js/components/admin/settings/Role/RoleListComponent.vue')
        );
        $this->assertStringContainsString('POS Operator', $src);
        $this->assertStringContainsString('isProtectedRole', $src);
        $this->assertStringNotContainsString(
            'v-if="!enums.roleEnumArray.includes(role.id)"',
            $src
        );
    }

    public function test_category_list_has_status_fallback_label(): void
    {
        $src = file_get_contents(
            base_path('resources/js/components/admin/settings/ItemCategory/ItemCateogryListComponent.vue')
        );
        $this->assertStringContainsString('statusLabel', $src);
        $this->assertStringContainsString('statusLabel(itemCategory.status)', $src);
    }
}
