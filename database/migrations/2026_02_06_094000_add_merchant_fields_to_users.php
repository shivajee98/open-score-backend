<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('business_segment')->nullable()->after('business_address'); // wholesaler, retailer, distributor, super_distributor
            $table->string('business_type')->nullable()->after('business_segment'); // type of shop
            $table->text('shop_images')->nullable()->after('business_type'); // JSON array of cloudinary URLs
            $table->string('map_location_url')->nullable()->after('shop_images'); // Google Maps link
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['business_segment', 'business_type', 'shop_images', 'map_location_url']);
        });
    }
};
