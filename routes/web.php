<?php

use App\Http\Controllers\Web\Home;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

// Backend khusus API — frontend terpisah (project React).
// Root domain tidak lagi me-render Blade landing (butuh build Vite yang
// tidak ada di server produksi → 500).
Route::get('', fn () => response()->json([
    'status' => 'success',
    'message' => 'LSK LHL API is running',
]));

Route::get('home', [Home\HomeController::class, 'index'])->middleware(['auth', 'verified', 'password.confirm'])->name('home');

// Swagger Documentation
Route::get('swagger', fn () => view('swagger.index'))->name('swagger');
