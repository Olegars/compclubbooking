<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            if (! Schema::hasColumn('clubs', 'type')) {
                $table->string('type', 16)->default('club')->after('slug');
            }
        });

        Schema::table('admins', function (Blueprint $table) {
            if (! Schema::hasColumn('admins', 'club_id')) {
                $table->foreignId('club_id')->nullable()->after('role')->constrained('clubs')->nullOnDelete();
            }
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE admins MODIFY COLUMN role ENUM('admin','supervisor','owner','store_manager','assembler','senior_manager') NOT NULL DEFAULT 'admin'");
        } elseif ($driver === 'pgsql') {
            // Laravel enum → CHECK constraint; расширяем через varchar
            DB::statement('ALTER TABLE admins DROP CONSTRAINT IF EXISTS admins_role_check');
            DB::statement('ALTER TABLE admins ALTER COLUMN role TYPE VARCHAR(32) USING role::text');
            DB::statement("ALTER TABLE admins ALTER COLUMN role SET DEFAULT 'admin'");
            DB::statement("ALTER TABLE admins ADD CONSTRAINT admins_role_check CHECK (role::text = ANY (ARRAY['admin','supervisor','owner','store_manager','assembler','senior_manager']::text[]))");
        }
        // sqlite: enum не enforced
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            if (Schema::hasColumn('admins', 'club_id')) {
                $table->dropConstrainedForeignId('club_id');
            }
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE admins MODIFY COLUMN role ENUM('admin','supervisor','owner') NOT NULL DEFAULT 'admin'");
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE admins DROP CONSTRAINT IF EXISTS admins_role_check');
            DB::statement("ALTER TABLE admins ADD CONSTRAINT admins_role_check CHECK (role::text = ANY (ARRAY['admin','supervisor','owner']::text[]))");
        }

        Schema::table('clubs', function (Blueprint $table) {
            if (Schema::hasColumn('clubs', 'type')) {
                $table->dropColumn('type');
            }
        });
    }
};
