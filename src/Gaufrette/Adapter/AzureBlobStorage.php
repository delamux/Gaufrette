<?php

namespace Gaufrette\Adapter;

use Gaufrette\Adapter;
use Gaufrette\Util;
use Gaufrette\Adapter\AzureBlobStorage\BlobProxyFactoryInterface;

use WindowsAzure\Blob\Models\CreateBlobOptions;
use WindowsAzure\Blob\Models\CreateContainerOptions;
use WindowsAzure\Blob\Models\DeleteContainerOptions;
use WindowsAzure\Common\ServiceException;

/**
 * Microsoft Azure Blob Storage adapter
 *
 * @author Luciano Mammino <lmammino@oryzone.com>
 * @author Paweł Czyżewski <pawel.czyzewski@enginewerk.com>
 */
class AzureBlobStorage implements Adapter,
                                  MetadataSupporter
{
    /**
     * Error constants
     */
    const ERROR_CONTAINER_ALREADY_EXISTS = 'ContainerAlreadyExists';
    const ERROR_CONTAINER_NOT_FOUND = 'ContainerNotFound';

    /**
     * @var AzureBlobStorage\BlobProxyFactoryInterface $blobProxyFactory
     */
    protected $blobProxyFactory;

    /**
     * @var string $containerName
     */
    protected $containerName;

    /**
     * @var bool $detectContentType
     */
    protected $detectContentType;

    /**
     * @var \WindowsAzure\Blob\Internal\IBlob $blobProxy
     */
    protected $blobProxy;

    /**
     * Constructor
     *
     * @param AzureBlobStorage\BlobProxyFactoryInterface $blobProxyFactory
     * @param string                                     $containerName
     * @param bool                                       $create
     * @param bool                                       $detectContentType
     */
    public function __construct(BlobProxyFactoryInterface $blobProxyFactory, $containerName, $create = false, $detectContentType = true)
    {
        $this->blobProxyFactory = $blobProxyFactory;
        $this->containerName = $containerName;
        $this->detectContentType = $detectContentType;
        if ($create) {
            $this->createContainer($containerName);
        }
    }

    /**
     * Creates a new container
     *
     * @param  string                                           $containerName
     * @param  \WindowsAzure\Blob\Models\CreateContainerOptions $options
     * @throws \RuntimeException                                if cannot create the container
     */
    public function createContainer($containerName, CreateContainerOptions $options = null)
    {
        $this->init();

        try {
            $this->blobProxy->createContainer($containerName, $options);
        } catch (ServiceException $e) {
            $errorCode = $this->getErrorCodeFromServiceException($e);

            if ($errorCode != self::ERROR_CONTAINER_ALREADY_EXISTS) {
                throw new \RuntimeException(sprintf(
                    'Failed to create the configured container "%s": %s (%s).',
                    $containerName,
                    $e->getErrorText(),
                    $errorCode
                ), $e->getCode());
            }
        }
    }

    /**
     * Deletes a container
     *
     * @param  string                 $containerName
     * @param  DeleteContainerOptions $options
     * @throws \RuntimeException      if cannot delete the container
     */
    public function deleteContainer($containerName, DeleteContainerOptions $options = null)
    {
        $this->init();

        try {
            $this->blobProxy->deleteContainer($containerName, $options);
        } catch (ServiceException $e) {
            $errorCode = $this->getErrorCodeFromServiceException($e);

            if ($errorCode != self::ERROR_CONTAINER_NOT_FOUND) {
                throw new \RuntimeException(sprintf(
                    'Failed to delete the configured container "%s": %s (%s).',
                    $containerName,
                    $e->getErrorText(),
                    $errorCode
                ), $e->getCode());
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function read($key)
    {
        $this->init();

        try {
            $blob = $this->blobProxy->getBlob($this->containerName, $key);

            return stream_get_contents($blob->getContentStream());
        } catch (ServiceException $e) {
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function write($key, $content)
    {
        $this->init();

        try {
            $options = new CreateBlobOptions();

            if ($this->detectContentType) {
                $fileInfo = new \finfo(FILEINFO_MIME_TYPE);
                $contentType = $fileInfo->buffer($content);
                $options->setContentType($contentType);
            }

            $this->blobProxy->createBlockBlob($this->containerName, $key, $content, $options);

            return Util\Size::fromContent($content);
        } catch (ServiceException $e) {
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function exists($key)
    {
        $this->init();

        try {
            $this->blobProxy->getBlob($this->containerName, $key);

            return true;
        } catch (ServiceException $e) {
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function keys()
    {
        $this->init();

        try {
            $blobList = $this->blobProxy->listBlobs($this->containerName);
            $blobs = $blobList->getBlobs();
            $keys = array();

            foreach ($blobs as $blob) {
                $keys[] = $blob->getName();
            }

            return $keys;
        } catch (ServiceException $e) {
            $errorCode = $this->getErrorCodeFromServiceException($e);

            throw new \RuntimeException(sprintf(
                'Failed to list keys for the container "%s": %s (%s).',
                $this->containerName,
                $e->getErrorText(),
                $errorCode
            ), $e->getCode());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function mtime($key)
    {
        $this->init();

        try {
            $properties = $this->blobProxy->getBlobProperties($this->containerName, $key);

            return $properties->getProperties()->getLastModified()->getTimestamp();
        } catch (ServiceException $e) {
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function delete($key)
    {
        $this->init();

        try {
            $this->blobProxy->deleteBlob($this->containerName, $key);

            return true;
        } catch (ServiceException $e) {
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function rename($sourceKey, $targetKey)
    {
        $this->init();

        try {
            $this->blobProxy->copyBlob($this->containerName, $targetKey, $this->containerName, $sourceKey);
            $this->blobProxy->deleteBlob($this->containerName, $sourceKey);

            return true;
        } catch (ServiceException $e) {
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function isDirectory($key)
    {
        $this->init();

        if ($this->exists($key.'/')) {
            return true;
        }

        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function setMetadata($key, $content)
    {
        $this->init();

        try {
            $this->blobProxy->setBlobMetadata($this->containerName, $key, $content);
        } catch (ServiceException $e) {
            $errorCode = $this->getErrorCodeFromServiceException($e);

            throw new \RuntimeException(sprintf(
                'Failed to set metadata for blob "%s" in container "%s": %s (%s).',
                $key,
                $this->containerName,
                $e->getErrorText(),
                $errorCode
            ), $e->getCode());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getMetadata($key)
    {
        $this->init();

        try {
            $properties = $this->blobProxy->getBlobProperties($this->containerName, $key);

            return $properties->getMetadata();
        } catch (ServiceException $e) {
            $errorCode = $this->getErrorCodeFromServiceException($e);

            throw new \RuntimeException(sprintf(
                'Failed to get metadata for blob "%s" in container "%s": %s (%s).',
                $key,
                $this->containerName,
                $e->getErrorText(),
                $errorCode
            ), $e->getCode());
        }
    }

    /**
     * Lazy initialization, automatically called when some method is called after construction
     */
    protected function init()
    {
        if ($this->blobProxy == null) {
            $this->blobProxy = $this->blobProxyFactory->create();
        }
    }

    /**
     * Extracts the error code from a service exception
     *
     * @param  ServiceException $exception
     * @return string
     */
    protected function getErrorCodeFromServiceException(ServiceException $exception)
    {
        $xml = simplexml_load_string($exception->getErrorReason());
        if(isset($xml->Code))

            return (string) $xml->Code;

        return $exception->getErrorReason();
    }
}
