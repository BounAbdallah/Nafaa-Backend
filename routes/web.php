<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'app'     => 'QIWAM ERP API',
        'version' => '1.0.0',
        'status'  => 'operational',
    ]);
});

// Fallback to help users hitting the wrong port in development
Route::fallback(function () {
    $uri = request()->getRequestUri();
    return response()->json([
        'error' => '404 - Route non trouvée sur le Backend',
        'message' => "Vous essayez d'accéder à une route frontend via le port du Backend (8000).",
        'action' => "Veuillez utiliser le port 3000 pour l'interface utilisateur.",
        'correct_url' => 'http://localhost:3000' . $uri,
    ], 404);
});
