<?php

namespace Hummingbird\Bundle\XlsxConnectorBundle\Reader\File;

use Akeneo\Tool\Component\Connector\Reader\File\MediaPathTransformer as BaseMediaPathTransformer;
use Elasticsearch\Namespaces\DataFrameTransformDeprecatedNamespace;

class MediaPathTransformer extends BaseMediaPathTransformer
{
    public function transform(array $attributeValues, $filePath)
    {
        $mediaAttributes = $this->attributeRepository->findMediaAttributeCodes();

        foreach ($attributeValues as $code => $values) {
            // var_dump($values);
            if (in_array($code, $mediaAttributes)) {
                foreach ($values as $index => $value) {
                    if (isset($value['data'])) {
                        $dataFilePath = $value['data'];
                        echo $dataFilePath;
                        $attributeValues[$code][$index]['data'] = $dataFilePath ? $this->getPath($filePath, $dataFilePath) : null;
                    }
                }
            }
        }
        // die;
        return $attributeValues;
    }

    /**
     * @param $filePath
     * @param $data
     * @return string
     */
    protected function getPath($filePath, $data)
    {
        if (filter_var($data, FILTER_VALIDATE_URL)) {
            return $this->download($data);
        }
    
        return sprintf('%s%s%s', $filePath, DIRECTORY_SEPARATOR, $data);
    }

    /**
     * @param string $url
     * @return string|null
     */
    protected function download(string $url)
    {
        $parsedUrl = parse_url($url);
        $dir = sprintf('%s%s%s', sys_get_temp_dir(), DIRECTORY_SEPARATOR, $parsedUrl['host']);
        $filename = sprintf('%s.%s', sha1($url), pathinfo($parsedUrl['path'], PATHINFO_EXTENSION));
        $path = sprintf('%s%s%s', $dir, DIRECTORY_SEPARATOR, $filename);

        try {
            $content = file_get_contents($url);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            file_put_contents($path, $content);
        } catch (\Exception $e) {
            return null;
        }

        return $path;
    }
}