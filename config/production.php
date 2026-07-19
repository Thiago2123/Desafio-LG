<?php

return [
    // Regra central de qualidade: somente valores acima deste limite geram alerta.
    'defect_alert_threshold' => 5.0,

    // Meta planejada de cada produto para um intervalo gerado pelo simulador.
    'products' => [
        [
            'slug' => 'geladeira',
            'name' => 'Geladeira',
            'target_per_interval' => 120,
        ],
        [
            'slug' => 'monitor',
            'name' => 'Monitor',
            'target_per_interval' => 300,
        ],
        [
            'slug' => 'maquina-de-lavar',
            'name' => 'Máquina de Lavar',
            'target_per_interval' => 100,
        ],
        [
            'slug' => 'tv',
            'name' => 'TV',
            'target_per_interval' => 250,
        ],
        [
            'slug' => 'ar-condicionado',
            'name' => 'Ar-Condicionado',
            'target_per_interval' => 90,
        ],
    ],
];
