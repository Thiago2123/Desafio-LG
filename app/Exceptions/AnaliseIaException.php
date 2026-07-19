<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class AnaliseIaException extends RuntimeException
{
    private $statusHttp;

    public function __construct(
        string $mensagem,
        int $statusHttp = 502,
        ?Throwable $excecaoAnterior = null
    ) {
        $this->statusHttp = $statusHttp;

        parent::__construct($mensagem, 0, $excecaoAnterior);
    }

    /** Retorna o status que o controller deve usar sem expor detalhes da Groq. */
    public function statusHttp(): int
    {
        return $this->statusHttp;
    }
}
