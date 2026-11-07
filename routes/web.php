<?php

use Illuminate\Support\Facades\Route;

/*
| The whole backend is served from routes/api.php (/api/...).
| This file only serves the React single-page app.
*/

// Opening the site always starts at the USER login (the admin login is only at /admin)
Route::redirect('/', '/login');

// Everything that is not an API / asset request is handled by React Router.
Route::get('/{any}', function () {
    return view('app');
})->where('any', '^(?!api|storage|build|images|sanctum).+$');
