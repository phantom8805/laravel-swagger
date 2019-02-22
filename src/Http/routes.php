<?php
/**
 * Created by PhpStorm.
 * User: roman
 * Date: 29.08.16
 * Time: 11:37
 */

use RonasIT\Support\AutoDoc\Http\Controllers\AutoDocController;

use Illuminate\Support\Facades\Route;

Route::group(['prefix' => config()->get('auto-doc.route')], function () {
    Route::get('/documentation', ['uses' => AutoDocController::class.'@documentation']);
    Route::get('/{file}', ['uses' => AutoDocController::class.'@getFile']);
    Route::get('/', ['uses' => AutoDocController::class.'@index']);

    Route::get('asset/{asset}', [
        'as' => 'l5-swagger.asset',
        'uses' => AutoDocController::class.'@asset'
    ]);
});
