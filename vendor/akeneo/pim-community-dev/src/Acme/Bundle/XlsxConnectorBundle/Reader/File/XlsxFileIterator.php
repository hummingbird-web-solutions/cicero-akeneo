<?php

namespace Acme\Bundle\XlsxConnectorBundle\Reader\File;

use Akeneo\Tool\Component\Connector\Reader\File\FileIteratorInterface;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;

class XlsxFileIterator implements FileIteratorInterface
{
    /** @var string **/
    protected $type;

    /** @var string **/
    protected $filePath;

    /** @var \SplFileInfo **/
    protected $fileInfo;

    /** @var \SimpleXLSXIterator */
    protected $xlsxFileIterator;

    /**
     * {@inheritdoc}
     */
    public function __construct($type, $filePath, array $options = [])
    {
        $this->type     = $type;
        $this->filePath = $filePath;
        $this->fileInfo = new \SplFileInfo($filePath);

        if (!$this->fileInfo->isFile()) {
            throw new FileNotFoundException(sprintf('File "%s" could not be found', $this->filePath));
        }

        $this->xlsxFileIterator = simplexlsx_load_file($filePath, 'SimpleXLSXIterator');
        $this->xlsxFileIterator->rewind();
    }

    /**
     * {@inheritdoc}
     */
    public function getDirectoryPath()
    {
        if (null === $this->archivePath) {
            return $this->fileInfo->getPath();
        }

        return $this->archivePath;
    }

    /**
     * {@inheritdoc}
     */
    public function getHeaders()
    {
        $headers = [];
        foreach ($this->xlsxFileIterator->current()->attributes() as $header => $value) {
            $headers[] = $header;
        }

        return $headers;
    }

    /**
     * {@inheritdoc}
     */
    public function current()
    {
        $elem = $this->xlsxFileIterator->current();

        return $this->xlsxElementToFlat($elem);
    }

    /**
     * {@inheritdoc}
     */
    public function next()
    {
        $this->xlsxFileIterator->next();
    }

    /**
     * {@inheritdoc}
     */
    public function key()
    {
        return $this->xlsxFileIterator->key();
    }

    /**
     * {@inheritdoc}
     */
    public function valid()
    {
        return $this->xlsxFileIterator->valid();
    }

    /**
     * {@inheritdoc}
     */
    public function rewind()
    {
        $this->xlsxFileIterator->rewind();
    }

    /**
     * Converts an xlsx node into an array of values
     *
     * @param \SimpleXLSXIterator $elem
     *
     * @return array
     */
    protected function xlsxElementToFlat($elem)
    {
        $flatElem = [];

        foreach ($elem->attributes() as $value) {
            $flatElem[] = (string) $value;
        }

        return $flatElem;
    }
}