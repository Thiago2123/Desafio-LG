<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class PlantDataUnavailableException extends RuntimeException
{
    private $planta;

    public function __construct(string $planta, Throwable $excecaoAnterior)
    {
        $this->planta = $planta;

        parent::__construct(
            sprintf('Não foi possível consultar os dados da %s.', $planta),
            0,
            $excecaoAnterior
        );
    }

    public function planta(): string
    {
        return $this->planta;
    }
}
