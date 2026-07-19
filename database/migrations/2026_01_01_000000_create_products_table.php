<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductsTable extends Migration
{
    public function up()
    {
        Schema::create('products', function (Blueprint $tabela) {
            // Identificador interno. Pode ser diferente para o mesmo produto em cada planta.
            $tabela->bigIncrements('id');

            // Nome para exibição, chave estável de consolidação e linha responsável.
            $tabela->string('name', 100);
            $tabela->string('slug', 100)->unique();
            $tabela->string('line_name', 100);

            // Cria created_at e updated_at, mantidos automaticamente pelo Eloquent.
            $tabela->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('products');
    }
}
