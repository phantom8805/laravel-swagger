<?php

namespace RonasIT\Support\AutoDoc\Http\Middleware;

use Closure;
use RonasIT\Support\AutoDoc\Services\SwaggerService;

/**
 * @property SwaggerService $service
*/
class AutoDocMiddleware
{
    /**
     * @var SwaggerService
     */
    protected $service;

    /**
     * @var bool
     */
    public static $skipped = false;

    /**
     * AutoDocMiddleware constructor.
     */
    public function __construct()
    {
        $this->service = app(SwaggerService::class);
    }

    /**
     * @param         $request
     * @param Closure $next
     *
     * @return mixed
     * @throws \Exception
     * @throws \Illuminate\Container\EntryNotFoundException
     * @throws \ReflectionException
     */
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        if ((config('app.env') == 'testing') && !self::$skipped) {
            $this->service->addData($request, $response);
        }

        self::$skipped = false;

        return $response;
    }
}