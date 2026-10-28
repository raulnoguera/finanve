<?php

// Formato de visualización por moneda: símbolo, decimales, separador decimal y de miles, y posición del símbolo.
return [
    'USD' => ['symbol' => '$', 'decimals' => 2, 'dec' => '.', 'thou' => ',', 'after' => false, 'name' => 'dólares'],
    'COP' => ['symbol' => '$', 'decimals' => 0, 'dec' => ',', 'thou' => '.', 'after' => false, 'name' => 'pesos colombianos'],
    'CLP' => ['symbol' => '$', 'decimals' => 0, 'dec' => ',', 'thou' => '.', 'after' => false, 'name' => 'pesos chilenos'],
    'MXN' => ['symbol' => '$', 'decimals' => 2, 'dec' => '.', 'thou' => ',', 'after' => false, 'name' => 'pesos mexicanos'],
    'EUR' => ['symbol' => '€', 'decimals' => 2, 'dec' => ',', 'thou' => '.', 'after' => true, 'name' => 'euros'],
];
