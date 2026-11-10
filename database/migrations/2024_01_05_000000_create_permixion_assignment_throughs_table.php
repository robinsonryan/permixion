<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * What a role assignment acts through: one row per through, keyed by the
 * assignment — the id of the Taxon `taggables` row that carries the role.
 *
 * A row is either a holder (`holder_type` / `holder_id`, a morph onto any model)
 * or a ceiling role (`role_id`, a role tag). A row naming neither — its role was
 * deleted — caps the assignment to nothing: it fails closed.
 *
 * Deliberately no foreign keys onto Taxon's tables. Taxon publishes its
 * migrations with a fresh timestamp, so in a consumer this file can run before
 * `taggables` exists. Permixion deletes these rows itself when it removes an
 * assignment; a row orphaned some other way names an assignment id that is never
 * reused and so grants nothing.
 *
 * The table's own key is a database-generated UUID v7 in both key modes. The
 * columns that hold Taxon's keys, `assignment_id` and `role_id`, follow
 * `taxon.id_type`. `holder_id` is a string: a holder may be keyed by anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        $useUuid = config('taxon.id_type') === 'uuid7';

        Schema::create($this->table(), function (Blueprint $table) use ($useUuid): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            if ($useUuid) {
                $table->uuid('assignment_id')->index();
                $table->uuid('role_id')->nullable();
            } else {
                $table->unsignedBigInteger('assignment_id')->index();
                $table->unsignedBigInteger('role_id')->nullable();
            }

            $table->string('holder_type')->nullable();
            $table->string('holder_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('permixion.tables.assignment_throughs', 'permixion_assignment_throughs');
    }
};
