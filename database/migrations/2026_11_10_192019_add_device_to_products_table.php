<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The device each known product is made on. Products not listed need no device.
     *
     * @var array<string, list<string>>
     */
    private const array DEVICES = [
        'grill' => ['Tosti ham/kaas', 'Tosti kaas', 'Tosti gluten vrij', 'Panini capresse', 'Panini Kip'],
        'fryer' => [
            'Friet', 'Loaded Friet', 'Kroket los', 'Frikandel los', 'Kaassouflé los', 'Kaasstengels', 'Kipsnack',
            'Bittergarnituur 10st.', 'Bittergarnituur team', 'Biterballen', 'Kindermenu Snack',
            'Br. Kroket', 'Br. Frikadel', 'Br. Kaassoufflé', 'Br. Kip', 'Schnitzel',
        ],
        'pizza_oven' => ['Pizza Pepperoni', 'Pizza Margherita'],
        'stove' => ['Dagsoep', 'Pasta pesto kip', 'Pasta pesto vega', 'Pasta bolognese', 'Pasta Pesto Kids', 'Pasta Bolo Kids', 'Kipsaté', 'Br. Bal'],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('device')->default('none')->after('category');
        });

        foreach (self::DEVICES as $device => $names) {
            DB::table('products')->whereIn('name', $names)->update(['device' => $device]);
        }

        // Tostis and panini come from the same grill and count as one category; pizza has its own.
        DB::table('products')->where('category', 'tosti')->update(['category' => 'tosti_panini']);
        DB::table('products')->where('category', 'panini_pizza')->where('name', 'like', 'Panini%')->update(['category' => 'tosti_panini']);
        DB::table('products')->where('category', 'panini_pizza')->update(['category' => 'pizza']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('products')->where('category', 'pizza')->update(['category' => 'panini_pizza']);
        DB::table('products')->where('category', 'tosti_panini')->where('name', 'like', 'Panini%')->update(['category' => 'panini_pizza']);
        DB::table('products')->where('category', 'tosti_panini')->update(['category' => 'tosti']);

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('device');
        });
    }
};
