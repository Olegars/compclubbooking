<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE admins MODIFY COLUMN role ENUM('admin','intern','supervisor','owner','store_manager','assembler','senior_manager') NOT NULL DEFAULT 'admin'");
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE admins DROP CONSTRAINT IF EXISTS admins_role_check');
            DB::statement('ALTER TABLE admins ALTER COLUMN role TYPE VARCHAR(32) USING role::text');
            DB::statement("ALTER TABLE admins ALTER COLUMN role SET DEFAULT 'admin'");
            DB::statement("ALTER TABLE admins ADD CONSTRAINT admins_role_check CHECK (role::text = ANY (ARRAY['admin','intern','supervisor','owner','store_manager','assembler','senior_manager']::text[]))");
        }

        DB::table('admins')->where('role', 'admin_inactive')->update(['role' => 'admin']);
    }

    public function down(): void
    {
        DB::table('admins')->where('role', 'intern')->update(['role' => 'admin']);

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE admins MODIFY COLUMN role ENUM('admin','supervisor','owner','store_manager','assembler','senior_manager') NOT NULL DEFAULT 'admin'");
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE admins DROP CONSTRAINT IF EXISTS admins_role_check');
            DB::statement("ALTER TABLE admins ADD CONSTRAINT admins_role_check CHECK (role::text = ANY (ARRAY['admin','supervisor','owner','store_manager','assembler','senior_manager']::text[]))");
        }
    }
};
