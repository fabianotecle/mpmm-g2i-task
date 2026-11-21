<?php

use App\Http\Controllers\InviteLinkController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/convite/{code}', [InviteLinkController::class, '__invoke']);
