<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DashboardQueryRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        // Impede que nomes de conexão ou datas arbitrárias cheguem à regra de negócio.
        return [
            'scope' => ['required', Rule::in(['plant_a', 'plant_b', 'all'])],
            'date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public function messages()
    {
        return [
            'scope.in' => 'A visualização deve ser plant_a, plant_b ou all.',
            'date.date_format' => 'A data deve usar o formato AAAA-MM-DD.',
        ];
    }

    protected function prepareForValidation()
    {
        // Permite abrir a API sem parâmetros usando os valores padrão da aplicação.
        $this->merge([
            'scope' => $this->input('scope', 'all'),
            'date' => $this->input('date', config('simulation.date')),
        ]);
    }
}
