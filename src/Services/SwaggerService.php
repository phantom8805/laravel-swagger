<?php

/**
 * Created by PhpStorm.
 * User: roman
 * Date: 26.08.16
 * Time: 13:09
 */

namespace RonasIT\Support\AutoDoc\Services;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Minime\Annotations\Interfaces\AnnotationsBagInterface;
use Minime\Annotations\Reader as AnnotationReader;
use Minime\Annotations\Parser;
use Minime\Annotations\Cache\ArrayCache;
use RonasIT\Support\AutoDoc\Interfaces\DataCollectorInterface;
use RonasIT\Support\AutoDoc\Traits\AutoDocRequestTrait;
use RonasIT\Support\AutoDoc\Traits\GetDependenciesTrait;
use RonasIT\Support\AutoDoc\Exceptions\WrongSecurityConfigException;
use RonasIT\Support\AutoDoc\Exceptions\DataCollectorClassNotFoundException;
use RonasIT\Support\AutoDoc\Services\LocalDataCollector;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\Testing\File;

/**
 * @property DataCollectorInterface $dataCollector
 */
class SwaggerService
{
    use GetDependenciesTrait;

    protected $annotationReader;
    protected $dataCollector;

    protected $data;
    protected $container;
    private $uri;
    private $method;
    /**
     * @var \Illuminate\Http\Request
     */
    private $request;
    private $item;
    private $security;

    private $statusCode;

    /**
     * SwaggerService constructor.
     *
     * @param Container $container
     *
     * @throws DataCollectorClassNotFoundException
     * @throws WrongSecurityConfigException
     * @throws \Exception
     * @throws \Illuminate\Container\EntryNotFoundException
     * @throws \Throwable
     */
    public function __construct(Container $container)
    {
        $this->setDataCollector();

        if (config('app.env') == 'testing') {
            $this->container = $container;

            $this->annotationReader = new AnnotationReader(new Parser, new ArrayCache);;

            $this->security = config('auto-doc.security');

            $this->data = $this->dataCollector->getTmpData();

            if (empty($this->data)) {
                $this->data = $this->generateEmptyData();

                $this->dataCollector->saveTmpData($this->data);
            }
        }
    }


    /**
     * @throws DataCollectorClassNotFoundException
     * @throws \Exception
     * @throws \Illuminate\Container\EntryNotFoundException
     */
    protected function setDataCollector()
    {
        $dataCollectorClass = config('auto-doc.data_collector');

        if (empty($dataCollectorClass)) {
            $this->dataCollector = app(LocalDataCollector::class);
        } elseif (!class_exists($dataCollectorClass)) {
            throw new DataCollectorClassNotFoundException();
        } else {
            $this->dataCollector = app($dataCollectorClass);
        }
    }


    /**
     * @return array
     * @throws WrongSecurityConfigException
     * @throws \Exception
     * @throws \Illuminate\Container\EntryNotFoundException
     * @throws \Throwable
     */
    protected function generateEmptyData()
    {
        $data = [
            'swagger'     => config('auto-doc.swagger.version'),
            'host'        => '',
            'basePath'    => config('auto-doc.basePath'),
            'schemes'     => config('auto-doc.schemes'),
            'paths'       => [],
            'definitions' => config('auto-doc.definitions')
        ];

        $info = $this->prepareInfo(config('auto-doc.info'));
        if (!empty($info)) {
            $data['info'] = $info;
        }

        $securityDefinitions = $this->generateSecurityDefinition();
        if (!empty($securityDefinitions)) {
            $data['securityDefinitions'] = $securityDefinitions;
        }

        $data['info']['description'] = view($data['info']['description'])->render();

        return $data;
    }


    /**
     * @return mixed
     * @throws \Exception
     * @throws \Illuminate\Container\EntryNotFoundException
     */
    protected function getAppUrl()
    {
        $url = config('app.url');

        return str_replace(['http://', 'https://', '/'], '', $url);
    }


    /**
     * @return array|string
     * @throws WrongSecurityConfigException
     */
    protected function generateSecurityDefinition()
    {
        $availableTypes = ['jwt', 'laravel'];
        $security = $this->security;

        if (empty($security)) {
            return '';
        }

        if (!in_array($security, $availableTypes)) {
            throw new WrongSecurityConfigException();
        }

        return [
            $security => $this->generateSecurityDefinitionObject($security)
        ];
    }


    /**
     * @param $type
     *
     * @return array
     */
    protected function generateSecurityDefinitionObject($type)
    {
        switch ($type) {
            case 'jwt':
                return [
                    'type' => 'apiKey',
                    'name' => 'authorization',
                    'in'   => 'header'
                ];

            case 'laravel':
                return [
                    'type' => 'apiKey',
                    'name' => 'Cookie',
                    'in'   => 'header'
                ];
        }
    }

    /**
     * @param Request $request
     * @param         $response
     *
     * @throws \ReflectionException
     */
    public function addData(Request $request, $response)
    {
        $this->request = $request;
        $this->statusCode = $response->getStatusCode();

        $this->prepareItem();

        $this->parseRequest($request);
        $this->parseResponse($response);

        $this->dataCollector->saveTmpData($this->data);
    }

    /**
     * @throws \Exception
     * @throws \Illuminate\Container\EntryNotFoundException
     */
    protected function prepareItem()
    {
        $this->uri = "/{$this->getUri()}";
        $this->method = strtolower($this->request->getMethod());

        if (empty(array_get($this->data, "paths.{$this->uri}.{$this->method}"))) {
            $this->data['paths'][$this->uri][$this->method] = [
                'tags'        => [],
                'consumes'    => [],
                'produces'    => [],
                'parameters'  => $this->getPathParams(),
                'responses'   => [],
                'security'    => [],
                'description' => ''
            ];
        }

        $this->item = &$this->data['paths'][$this->uri][$this->method];
    }


    /**
     * @return null|string|string[]
     * @throws \Exception
     * @throws \Illuminate\Container\EntryNotFoundException
     */
    protected function getUri()
    {
        $uri = $this->request->route()->uri();
        $basePath = preg_replace("/^\//", '', config('auto-doc.basePath'));
        $preparedUri = preg_replace("/^{$basePath}/", '', $uri);

        return preg_replace("/^\//", '', $preparedUri);
    }


    /**
     * @return array
     */
    protected function getPathParams()
    {
        $params = [];

        preg_match_all('/{.*?}/', $this->uri, $params);

        $params = array_collapse($params);

        $result = [];

        $routeParam = [];

        if (count($params)) {
            try {
                $action = $this->request->route()->action;
                $usedController = $action['controller'];
                list($controllerName, $controllerAction) = explode('@', $usedController);

                $controllerAnnotations = $this->annotationReader->getMethodAnnotations($controllerName, $controllerAction);
                $routeParamNames = $controllerAnnotations->getAsArray('routeParam');

                foreach ($routeParamNames as $routeParamName) {
                    list($paramName, $paramDescription) = explode('=>', $routeParamName);
                    $explodeParamRes = explode('=>', $routeParamName);
                    $paramName = $explodeParamRes[0] ?? null;
                    $paramDescription = $explodeParamRes[1] ?? null;

                    if ($paramName && $paramDescription) {
                        $routeParam[$paramName] = $paramDescription;
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        foreach ($params as $param) {
            $key = preg_replace('/[{}]/', '', $param);
            $description = $routeParam[$key] ?? '';

            $result[] = [
                'in'          => 'path',
                'name'        => $key,
                'description' => $description,
                'required'    => true,
                'type'        => 'string'
            ];
        }

        return $result;
    }


    /**
     * @param $request
     *
     * @throws \ReflectionException
     */
    protected function parseRequest($request)
    {
        $this->saveConsume();
        $this->saveTags();
        $this->saveSecurity();

        $concreteRequest = $this->getConcreteRequest();

        if (empty($concreteRequest)) {
            $this->item['description'] = '';

            return;
        }

        $annotations = $this->annotationReader->getClassAnnotations($concreteRequest);

        $this->saveParameters($concreteRequest, $annotations);
        $this->saveDescription($concreteRequest, $annotations);
    }


    /**
     * @param $response
     */
    protected function parseResponse($response)
    {
        $produceList = $this->data['paths'][$this->uri][$this->method]['produces'];

        $produce = $response->headers->get('Content-type');
        if (is_null($produce)) {
            $produce = 'text/plain';
        }

        if (!in_array($produce, $produceList)) {
            $this->item['produces'][] = $produce;
        }

        $responses = $this->item['responses'];
        $code = $response->getStatusCode();

        if (!in_array($code, $responses)) {
            $this->saveExample(
                $response->getStatusCode(),
                $response->getContent(),
                $produce
            );
        }
    }


    /**
     * @param $code
     * @param $content
     * @param $produce
     */
    protected function saveExample($code, $content, $produce)
    {
        $description = $this->getResponseDescription($code);
        $availableContentTypes = [
            'application',
            'text'
        ];
        $explodedContentType = explode('/', $produce);

        if (in_array($explodedContentType[0], $availableContentTypes)) {
            $this->item['responses'][$code] = $this->makeResponseExample($content, $produce, $description);
        } else {
            $this->item['responses'][$code] = '*Unavailable for preview*';
        }
    }


    /**
     * @param        $content
     * @param        $mimeType
     * @param string $description
     *
     * @return array
     */
    protected function makeResponseExample($content, $mimeType, $description = '')
    {
        $responseExample = [
            'description' => $description
        ];

        if ($mimeType === 'application/json') {
            $responseExample['schema'] = [
                'example' => json_decode($content, true),
            ];
        } else {
            $responseExample['examples']['example'] = $content;
        }

        return $responseExample;
    }

    protected function saveParameters($request, AnnotationsBagInterface $annotations)
    {
        $rules = [];
        if (method_exists($request, 'rules')) {

            try {
                $requestInstance = app($request);
                $route = $this->request->route();
                $requestInstance->setRouteResolver(function () use ($route) {
                    return $route;
                });

                $rules = $requestInstance->rules();
            } catch (\Throwable $e) {
                $rules = [];
            }

        }

        $actionName = $this->getActionName($this->uri);

        if (in_array($this->method, ['get', 'delete'])) {
            $this->saveGetRequestParameters($rules, $annotations);
        } else {
            $this->savePostRequestParameters($actionName, $rules, $annotations);
        }
    }


    /**
     * @param                         $rules
     * @param AnnotationsBagInterface $annotations
     */
    protected function saveGetRequestParameters($rules, AnnotationsBagInterface $annotations)
    {
        foreach ($rules as $parameter => $rule) {
            $validation = $this->getRuleArray($rule);

            $description = $annotations->get($parameter, implode(', ', $validation));

            $existedParameter = array_first($this->item['parameters'], function ($existedParameter, $key) use ($parameter) {
                return $existedParameter['name'] == $parameter;
            });

            if (empty($existedParameter)) {
                $parameterDefinition = [
                    'in'          => 'query',
                    'name'        => $parameter,
                    'description' => $description,
                    'type'        => $this->getParameterType($validation)
                ];
                if (in_array('required', $validation)) {
                    $parameterDefinition['required'] = true;
                }

                $this->item['parameters'][] = $parameterDefinition;
            }
        }
    }


    /**
     * @param                         $actionName
     * @param                         $rules
     * @param AnnotationsBagInterface $annotations
     */
    protected function savePostRequestParameters($actionName, $rules, AnnotationsBagInterface $annotations)
    {
        if ($this->requestHasMoreProperties($actionName)) {
            if ($this->requestHasBody()) {
                $this->item['parameters'][] = [
                    'in'          => 'body',
                    'name'        => 'body',
                    'description' => '',
                    'required'    => true,
                    'schema'      => [
                        "\$ref" => "#/definitions/{$actionName}Object"
                    ]
                ];
            }

            $this->saveDefinitions($actionName, $rules, $annotations);
        }
    }


    /**
     * @param $objectName
     * @param $rules
     * @param $annotations
     */
    protected function saveDefinitions($objectName, $rules, $annotations)
    {
        $data = [
            'type'       => 'object',
            'properties' => []
        ];
        foreach ($rules as $parameter => $rule) {
            $rulesArray = $this->getRuleArray($rule);
            $parameterType = $this->getParameterType($rulesArray);
            $this->saveParameterType($data, $parameter, $parameterType);
            $this->saveParameterDescription($data, $parameter, $rulesArray, $annotations);

            if (in_array('required', $rulesArray)) {
                $data['required'][] = $parameter;
            }
        }

        if($this->statusCode >= 200 && $this->statusCode < 300) {
            $data['example'] = $this->generateExample($data['properties']);
            $this->data['definitions'][$objectName . 'Object'] = $data;
        }
    }


    /**
     * @param array $validation
     *
     * @return mixed|string
     */
    protected function getParameterType(array $validation)
    {
        $validationRules = [
            'array'   => 'object',
            'boolean' => 'boolean',
            'date'    => 'date',
            'digits'  => 'integer',
            'email'   => 'string',
            'integer' => 'integer',
            'numeric' => 'double',
            'string'  => 'string'
        ];

        $parameterType = 'string';

        foreach ($validation as $item) {
            if (in_array($item, array_keys($validationRules))) {
                $parameterType = $validationRules[$item];
                break;
            }
        }

        return $parameterType;
    }

    /**
     * @param $data
     * @param $parameter
     * @param $parameterType
     */
    protected function saveParameterType(&$data, $parameter, $parameterType)
    {
        $data['properties'][$parameter] = [
            'type' => $parameterType,
        ];
    }


    /**
     * @param                         $data
     * @param                         $parameter
     * @param array $rulesArray
     * @param AnnotationsBagInterface $annotations
     */
    protected function saveParameterDescription(&$data, $parameter, array $rulesArray, AnnotationsBagInterface $annotations)
    {
        $description = $annotations->get($parameter, implode(', ', $rulesArray));
        $data['properties'][$parameter]['description'] = $description;
    }


    /**
     * @param $actionName
     *
     * @return bool
     */
    protected function requestHasMoreProperties($actionName)
    {
        $requestParametersCount = count($this->request->all());

        if (isset($this->data['definitions'][$actionName . 'Object']['properties'])) {
            $objectParametersCount = count($this->data['definitions'][$actionName . 'Object']['properties']);
        } else {
            $objectParametersCount = 0;
        }

        return $requestParametersCount > $objectParametersCount;
    }


    /**
     * @return bool
     */
    protected function requestHasBody()
    {
        $parameters = $this->data['paths'][$this->uri][$this->method]['parameters'];

        $bodyParamExisted = array_where($parameters, function ($value, $key) {
            return $value['name'] == 'body';
        });

        return empty($bodyParamExisted);
    }


    /**
     * @return mixed|null
     * @throws \ReflectionException
     */
    public function getConcreteRequest()
    {
        $controller = $this->request->route()->getActionName();

        if ($controller == 'Closure') {
            return null;
        }

        $explodedController = explode('@', $controller);

        $class = $explodedController[0];
        $method = $explodedController[1];

        $instance = app($class);
        $route = $this->request->route();

        $parameters = $this->resolveClassMethodDependencies(
            $route->parametersWithoutNulls(), $instance, $method
        );

        return array_first($parameters, function ($key, $parameter) {
            return preg_match('/Request/', $key);
        });
    }


    /**
     *
     */
    public function saveConsume()
    {
        $consumeList = $this->data['paths'][$this->uri][$this->method]['consumes'];
        $consume = $this->request->header('Content-Type');

        if (!empty($consume) && !in_array($consume, $consumeList)) {
            $this->item['consumes'][] = $consume;
        }
    }

    /**
     *
     */
    public function saveTags()
    {
        $tagIndex = 1;

        $explodedUri = explode('/', $this->uri);

        $exeptWordsForSingularize = [
            'media',
            'group-media'
        ];

        $tag = array_get($explodedUri, $tagIndex);
        $tag = in_array($tag, $exeptWordsForSingularize) ? $tag : str_singular($tag);

        $explodedRouteName = explode('.', $this->request->route()->getName());
        $routeName = array_get($explodedRouteName, 0);


        $routeName = in_array($tag, $exeptWordsForSingularize) ? $tag : str_singular($routeName);;

        if ($routeName and count($explodedRouteName) > 1 and $routeName !== $tag) {
            $tag = $routeName;
        }

        $this->item['tags'] = [$tag];
    }


    /**
     * @param                         $request
     * @param AnnotationsBagInterface $annotations
     */
    public function saveDescription($request, AnnotationsBagInterface $annotations)
    {
        $this->item['summary'] = $this->getSummary($request, $annotations);

        $description = $annotations->get('description');

        if (!empty($description)) {
            $this->item['description'] = $description;
        }
    }

    /**
     *
     */
    protected function saveSecurity()
    {
        if ($this->requestSupportAuth()) {
            $this->addSecurityToOperation();
        }
    }

    /**
     *
     */
    protected function addSecurityToOperation()
    {
        $security = &$this->data['paths'][$this->uri][$this->method]['security'];
        if (empty($security)) {
            $security[] = [
                "{$this->security}" => []
            ];
        }
    }


    /**
     * @param                         $request
     * @param AnnotationsBagInterface $annotations
     *
     * @return mixed|null|string|string[]
     */
    protected function getSummary($request, AnnotationsBagInterface $annotations)
    {
        $summary = $annotations->get('summary');

        if (empty($summary)) {
            $summary = $this->parseRequestName($request);
        }

        return $summary;
    }


    /**
     * @return bool
     */
    protected function requestSupportAuth()
    {
        switch ($this->security) {
            case 'jwt' :
                $header = $this->request->header('authorization');
                break;
            case 'laravel' :
                $header = $this->request->cookie('__ym_uid');
                break;
        }

        return !empty($header);

    }


    /**
     * @param $request
     *
     * @return null|string|string[]
     */
    protected function parseRequestName($request)
    {
        $explodedRequest = explode('\\', $request);
        $requestName = array_pop($explodedRequest);

        $underscoreRequestName = $this->camelCaseToUnderScore($requestName);

        return preg_replace('/[_]/', ' ', $underscoreRequestName);
    }


    /**
     * @param $code
     *
     * @return mixed
     * @throws \ReflectionException
     */
    protected function getResponseDescription($code)
    {
        $request = $this->getConcreteRequest();

        return elseChain(
            function () use ($request, $code) {
                return empty($request) ? Response::$statusTexts[$code] : null;
            },
            function () use ($request, $code) {
                return $this->annotationReader->getClassAnnotations($request)->get("_{$code}");
            },
            function () use ($code) {
                return config("auto-doc.defaults.code-descriptions.{$code}");
            },
            function () use ($code) {
                return Response::$statusTexts[$code];
            }
        );
    }


    /**
     * @param $uri
     *
     * @return string
     */
    protected function getActionName($uri)
    {
        $action = preg_replace('[\/]', '', $uri);

        return Str::camel($action);
    }


    /**
     * @throws \Exception
     * @throws \Illuminate\Container\EntryNotFoundException
     */
    protected function saveTempData()
    {
        $exportFile = config('auto-doc.files.temporary');
        $data = json_encode($this->data);

        file_put_contents($exportFile, $data);
    }


    /**
     *
     */
    public function saveProductionData()
    {
        $this->dataCollector->saveData();
    }


    /**
     * @return mixed
     */
    public function getDocFileContent()
    {
        $data = $this->dataCollector->getDocumentation();

        return $data;
    }


    /**
     * @param $input
     *
     * @return string
     */
    private function camelCaseToUnderScore($input)
    {
        preg_match_all('!([A-Z][A-Z0-9]*(?=$|[A-Z][a-z0-9])|[A-Za-z][a-z0-9]+)!', $input, $matches);
        $ret = $matches[0];
        foreach ($ret as &$match) {
            $match = $match == strtoupper($match) ? strtolower($match) : lcfirst($match);
        }
        return implode('_', $ret);
    }


    /**
     * @param $properties
     *
     * @return array
     */
    protected function generateExample($properties)
    {
        $parameters = $this->replaceObjectValues($this->request->all());
        $example = [];

        $this->replaceNullValues($parameters, $properties, $example);

        return $example;
    }


    /**
     * @param $parameters
     *
     * @return array
     */
    protected function replaceObjectValues($parameters)
    {
        $classNamesValues = [
            File::class => '[uploaded_file]',
        ];

        $parameters = array_dot($parameters);
        $returnParameters = [];

        foreach ($parameters as $parameter => $value) {
            if (is_object($value)) {
                $class = get_class($value);

                $value = array_get($classNamesValues, $class, $class);
            }

            array_set($returnParameters, $parameter, $value);
        }

        return $returnParameters;
    }

    /**
     * NOTE: All functions below are temporary solution for
     * this issue: https://github.com/OAI/OpenAPI-Specification/issues/229
     * We hope swagger developers will resolve this problem in next release of Swagger OpenAPI
     * */

    private function replaceNullValues($parameters, $types, &$example)
    {
        foreach ($parameters as $parameter => $value) {
            if (is_null($value) && in_array($parameter, $types)) {
                $example[$parameter] = $this->getDefaultValueByType($types[$parameter]['type']);
            } elseif (is_array($value)) {
                $this->replaceNullValues($value, $types, $example[$parameter]);
            } else {
                $example[$parameter] = $value;
            }
        }
    }

    /**
     * @param $type
     *
     * @return mixed
     */
    private function getDefaultValueByType($type)
    {
        $values = [
            'object'  => 'null',
            'boolean' => false,
            'date'    => "0000-00-00",
            'integer' => 0,
            'string'  => '',
            'double'  => 0
        ];

        return $values[$type];
    }

    /**
     * @param $info
     * @return mixed
     */
    protected function prepareInfo($info)
    {
        if (empty($info)) {
            return $info;
        }

        foreach ($info['license'] as $key => $value) {
            if (empty($value)) {
                unset($info['license'][$key]);
            }
        }
        if (empty($info['license'])) {
            unset($info['license']);
        }

        return $info;
    }


    /**
     *
     */
    protected function throwTraitMissingException()
    {
        $message = "ERROR:\n" .
            "It looks like you did not add AutoDocRequestTrait to your requester. \n" .
            "Please add it or mark in the test that you do not want to collect the \n" .
            "documentation for this case using the skipDocumentationCollecting() method\n";

        fwrite(STDERR, print_r($message, TRUE));

        die;
    }

    /**
     * @param array|string $rule
     * @return array
     */
    protected function getRuleArray($rule)
    {
        if (is_string($rule)) {
            return explode('|', $rule);
        }

        if (is_array($rule)) {
            return array_map(function ($validationItem) {
                return (string)$validationItem;
            }, $rule);
        }

        return [];
    }
}
