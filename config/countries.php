<?php

// Países disponibles al registrarse.
// currency = moneda en que se lleva todo. ves = además muestra equivalente en bolívares y tasa USD/Bs (solo Venezuela).
return [
    'VE' => ['name' => 'Venezuela', 'currency' => 'USD', 'ves' => true],
    'US' => ['name' => 'Estados Unidos', 'currency' => 'USD', 'ves' => false],
    'CO' => ['name' => 'Colombia', 'currency' => 'COP', 'ves' => false],
    'MX' => ['name' => 'México', 'currency' => 'MXN', 'ves' => false],
    'ES' => ['name' => 'España', 'currency' => 'EUR', 'ves' => false],
    'CL' => ['name' => 'Chile', 'currency' => 'CLP', 'ves' => false],
];
