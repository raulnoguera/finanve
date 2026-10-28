<?php

return [
    'required' => 'El campo :attribute es obligatorio.',
    'email' => 'Escribe un correo válido.',
    'unique' => 'Ese :attribute ya está registrado.',
    'confirmed' => 'La confirmación de :attribute no coincide.',
    'numeric' => ':Attribute debe ser un número.',
    'string' => ':Attribute debe ser texto.',
    'date' => ':Attribute no es una fecha válida.',
    'in' => 'El valor de :attribute no es válido.',
    'exists' => 'El valor de :attribute no es válido.',
    'regex' => 'El formato de :attribute no es válido.',
    'gt' => ['numeric' => ':Attribute debe ser mayor que :value.'],
    'min' => ['numeric' => ':Attribute debe ser al menos :min.', 'string' => ':Attribute debe tener al menos :min caracteres.'],
    'max' => ['numeric' => ':Attribute no puede ser mayor que :max.', 'string' => ':Attribute no puede tener más de :max caracteres.'],
    'after' => ':Attribute debe ser una fecha posterior a :date.',
    'before_or_equal' => ':Attribute no puede ser una fecha futura.',
    'attributes' => [
        'name' => 'nombre', 'email' => 'correo', 'password' => 'contraseña', 'amount' => 'monto',
        'currency' => 'moneda', 'category_id' => 'categoría', 'date' => 'fecha', 'note' => 'nota',
        'type' => 'tipo', 'target_amount' => 'monto de la meta', 'deadline' => 'fecha límite',
        'person' => 'persona', 'due_date' => 'fecha de pago', 'monthly_budget' => 'límite mensual',
        'rate' => 'tasa', 'country' => 'país', 'kind' => 'tipo',
    ],
];
