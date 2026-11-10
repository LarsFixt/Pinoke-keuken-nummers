<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The products the POS printed on kitchen tickets so far, written exactly as on the ticket.
     *
     * @var array<string, list<string>>
     */
    private const array PRODUCTS = [
        'tosti' => ['Tosti ham/kaas', 'Tosti kaas', 'Tosti gluten vrij'],
        'sandwich' => ['Br. Kroket', 'Br. Kip', 'Br. Bal', 'Br. Frikadel', 'Br. Kaassoufflé', 'Br. van de maand'],
        'snack' => ['Friet', 'Loaded Friet', 'Kroket los', 'Frikandel los', 'Kaassouflé los', 'Kaasstengels', 'Kipsnack'],
        'bites' => ['Bittergarnituur 10st.', 'Bittergarnituur team', 'Biterballen'],
        'panini_pizza' => ['Panini capresse', 'Panini Kip', 'Pizza Pepperoni', 'Pizza Margherita'],
        'meal' => ['Lunch 3', 'Lunch 5', 'Pinoké Burger', 'Kipsaté', 'Caesar salade', 'Schnitzel', 'Pasta pesto kip', 'Pasta pesto vega', 'Pasta bolognese', 'Dagsoep', 'Chefs special'],
        'kids' => ['Burger Kids', 'Kindermenu Snack', 'Pasta Pesto Kids', 'Pasta Bolo Kids'],
        'drink' => ['Smoothie Tropical', 'Smoothie power groen'],
        'staff' => ['Personeelseten'],
    ];

    /**
     * Misreadings seen in production, mapped to the product they belong to.
     *
     * @var array<string, string>
     */
    private const array ALIASES = [
        'losti ham/kaas' => 'Tosti ham/kaas',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('category')->default('other');
            $table->timestamps();
        });

        Schema::create('product_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('alias', 100)->unique();
            $table->timestamps();
        });

        $now = now();

        foreach (self::PRODUCTS as $category => $names) {
            DB::table('products')->insert(array_map(fn (string $name): array => [
                'name' => $name,
                'category' => $category,
                'created_at' => $now,
                'updated_at' => $now,
            ], $names));
        }

        foreach (self::ALIASES as $alias => $productName) {
            DB::table('product_aliases')->insert([
                'product_id' => DB::table('products')->where('name', $productName)->value('id'),
                'alias' => $alias,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_aliases');
        Schema::dropIfExists('products');
    }
};
