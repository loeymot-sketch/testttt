<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('printers')) {
            Schema::create('printers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id');
                $table->string('name', 80);
                $table->string('type', 16)->default('escpos_tcp');
                $table->string('host', 64)->nullable();
                $table->unsignedSmallInteger('port')->default(9100);
                $table->string('station', 32)->nullable();
                $table->unsignedTinyInteger('width_chars')->default(48);
                $table->unsignedTinyInteger('status')->default(1);
                $table->json('options')->nullable();
                $table->timestamps();

                $table->index(['branch_id', 'status'], 'printers_branch_status_idx');
                $table->index(['branch_id', 'station'], 'printers_branch_station_idx');
                $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            });

            return;
        }

        if (! $this->indexExists('printers', 'printers_branch_status_idx')) {
            try {
                Schema::table('printers', function (Blueprint $table) {
                    $table->index(['branch_id', 'status'], 'printers_branch_status_idx');
                });
            } catch (\Throwable) {
                // Keep migration idempotent across drivers.
            }
        }

        if (! $this->indexExists('printers', 'printers_branch_station_idx')) {
            try {
                Schema::table('printers', function (Blueprint $table) {
                    $table->index(['branch_id', 'station'], 'printers_branch_station_idx');
                });
            } catch (\Throwable) {
                // Keep migration idempotent across drivers.
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('printers');
    }

    private function indexExists(string $table, string $indexName): bool
    {
        try {
            $schemaManager = Schema::getConnection()->getDoctrineSchemaManager();
            $indexes = $schemaManager->listTableIndexes($table);

            foreach ($indexes as $index) {
                if (strtolower($index->getName()) === strtolower($indexName)) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }
};
