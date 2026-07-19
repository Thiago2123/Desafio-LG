<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductionRecordsTable extends Migration
{
    public function up()
    {
        Schema::create('production_records', function (Blueprint $tabela) {
            $tabela->bigIncrements('id');

            // Liga a medição ao produto cadastrado na mesma planta.
            $tabela->unsignedBigInteger('product_id');

            // Valores absolutos usados para calcular qualidade e eficiência.
            $tabela->unsignedInteger('produced_quantity');
            $tabela->unsignedInteger('defective_quantity');
            $tabela->unsignedInteger('target_quantity');

            // Momento industrial da medição; é diferente do horário de criação no sistema.
            $tabela->dateTime('recorded_at');
            $tabela->timestamps();

            // Impede registros apontando para produtos inexistentes.
            $tabela->foreign('product_id')
                ->references('id')
                ->on('products')
                ->onDelete('restrict');
            // Índices aceleram filtros por dia e agregações por produto + período.
            $tabela->index('recorded_at');
            $tabela->index(['product_id', 'recorded_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('production_records');
    }
}
