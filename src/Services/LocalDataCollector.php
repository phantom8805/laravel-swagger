<?php

namespace RonasIT\Support\AutoDoc\Services;


use Illuminate\Contracts\Filesystem\FileNotFoundException;
use RonasIT\Support\Interfaces\DataCollectorInterface;

class LocalDataCollector implements DataCollectorInterface
{
    public $prodFilePath;
    public $tempFilePath;
    protected static $data;

    /**
     * LocalDataCollector constructor.
     *
     * @throws \Exception
     * @throws \Illuminate\Container\EntryNotFoundException
     */
    public function __construct()
    {
        $this->prodFilePath = base_path() . '/' . config('auto-doc.production_path', '');
        if (empty($this->prodFilePath)) {
            throw new \Exception('production path not set');
        }
    }

    /**
     * @param array $tempData
     */
    public function saveTmpData($tempData)
    {
        self::$data = $tempData;
    }

    /**
     * @return mixed
     */
    public function getTmpData()
    {
        return self::$data;
    }

    /**
     *
     */
    public function saveData()
    {
        $content = json_encode(self::$data);
        file_put_contents($this->prodFilePath, $content);
        self::$data = [];
    }

    /**
     * @return mixed
     * @throws FileNotFoundException
     */
    public function getDocumentation()
    {
        if (!file_exists($this->prodFilePath)) {
            throw new FileNotFoundException();
        }
        $fileContent = file_get_contents($this->prodFilePath);
        return json_decode($fileContent);
    }
}