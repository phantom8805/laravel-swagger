<?php

/**
 * Created by PhpStorm.
 * User: roman
 * Date: 29.08.16
 * Time: 11:29
 */

namespace RonasIT\Support\AutoDoc\Http\Controllers;

use Illuminate\Routing\Controller as BaseController;
use RonasIT\Support\AutoDoc\Services\SwaggerService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Http\Response;

class AutoDocController extends BaseController
{
    protected $service;

    public function __construct()
    {
        $this->service = app(SwaggerService::class);
    }

    public function documentation()
    {
        $documentation = $this->service->getDocFileContent();

        return response()->json($documentation);
    }

    public function index()
    {
        $data = [
            'secure'           => false,
            'urlToDocs'        => config('auto-doc.production_path'),
            'operationsSorter' => null,
            'configUrl'        => null,
            'validatorUrl'     => null
        ];


        return view('auto-doc::documentation', $data);
    }

    public function getFile($file)
    {
        $filePath = base_path("vendor/ronasit/laravel-swagger/src/Views/swagger/{$file}");

        if (!file_exists($filePath)) {
            throw new NotFoundHttpException();
        }

        $content = file_get_contents($filePath);

        return response($content);
    }

    public function asset($asset)
    {
        $path = swagger_ui_dist_path($asset);
        return (new Response(
            file_get_contents($path), 200, [
                'Content-Type' => (pathinfo($asset))['extension'] == 'css' ?
                    'text/css' : 'application/javascript',
            ]
        ))->setSharedMaxAge(31536000)
            ->setMaxAge(31536000)
            ->setExpires(new \DateTime('+1 year'));
    }
}
