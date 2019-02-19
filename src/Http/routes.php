<?php
/**
 * Created by PhpStorm.
 * User: roman
 * Date: 29.08.16
 * Time: 11:37
 */

use RonasIT\Support\AutoDoc\Http\Controllers\AutoDocController;

use Illuminate\Support\Facades\Route;

Route::get('/auto-doc/documentation', ['uses' => AutoDocController::class.'@documentation']);
Route::get('/auto-doc/{file}', ['uses' => AutoDocController::class.'@getFile']);
Route::get('/auto-doc/', ['uses' => AutoDocController::class.'@index']);