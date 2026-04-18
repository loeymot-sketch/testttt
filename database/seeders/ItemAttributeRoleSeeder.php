<?php

namespace Database\Seeders;

use App\Models\ItemAttribute;
use Illuminate\Database\Seeder;

class ItemAttributeRoleSeeder extends Seeder
{
    private const ROLE_PATTERNS = [
        'bread' => ['pain', 'bread', 'khobz'],
        'meat' => ['viande', 'meat', 'lahma'],
        'sauce' => ['sauce', 'sos'],
        'size' => ['taille', 'size', 'hajm'],
        'topping' => ['garniture', 'topping'],
        'drink' => ['boisson', 'drink'],
        'condiment' => ['condiment'],
    ];

    public function run(): void
    {
        ItemAttribute::query()
            ->whereNull('role')
            ->get()
            ->each(function (ItemAttribute $attribute): void {
                $role = $this->guessRole($attribute->name);

                if ($role === null) {
                    return;
                }

                $attribute->forceFill(['role' => $role])->save();
            });
    }

    private function guessRole(?string $name): ?string
    {
        $normalized = mb_strtolower((string) $name);

        foreach (self::ROLE_PATTERNS as $role => $patterns) {
            foreach ($patterns as $pattern) {
                if (str_contains($normalized, $pattern)) {
                    return $role;
                }
            }
        }

        return null;
    }
}
