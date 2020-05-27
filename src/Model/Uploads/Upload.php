<?php
/**
 * ScandiPWA - Progressive Web App for Magento
 *
 * Copyright © Scandiweb, Inc. All rights reserved.
 * See LICENSE for license details.
 *
 * @license OSL-3.0 (Open Software License ("OSL") v. 3.0)
 * @package scandipwa/quote-graphql
 * @link    https://github.com/scandipwa/quote-graphql
 */

namespace ScandiPWA\QuoteGraphQl\Model\Uploads;

use Exception;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Math\Random;
use Magento\MediaStorage\Model\File\Uploader;
use Magento\MediaStorage\Model\File\UploaderFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;

class Upload
{
    const MEDIA_PATH = '/';
    const FILEHASH = 'filehash';
    const FILENAME = 'filename';
    const EXTENSION = 'extension';
    const FILE = 'file';

    /**
     * @var Filesystem
     */
    protected $filesystem;
    /**
     * @var Random
     */
    protected $mathRandom;
    /**
     * @var LoggerInterface
     */
    protected $logger;
    /**
     * @var UploaderFactory
     */
    protected $fileUploaderFactory;

    /**
     * Upload constructor.
     *
     * @param Filesystem $filesystem
     * @param Random $mathRandom
     * @param LoggerInterface $logger
     * @param UploaderFactory $fileUploaderFactory
     */
    public function __construct(
        Filesystem $filesystem,
        Random $mathRandom,
        LoggerInterface $logger,
        UploaderFactory $fileUploaderFactory
    ) {
        $this->filesystem = $filesystem;
        $this->mathRandom = $mathRandom;
        $this->logger = $logger;
        $this->fileUploaderFactory = $fileUploaderFactory;
    }

    private function getTempPath()
    {
        return $this->filesystem->getDirectoryRead(
            DirectoryList::MEDIA
        )->getAbsolutePath(
            self::MEDIA_PATH
        );
    }

    public function decodeFiles($encodedFiles = []) {
        $decodedFiles = [];

        foreach ($encodedFiles as $encodedFile) {
            $fileName = $encodedFile['name'];
            $decodedFile = base64_decode($encodedFile['encoded_file']);
            $decodedFiles[$fileName] = $decodedFile;
        }

        return $this->uploadFiles($decodedFiles);
    }

    private function uploadFiles($files)
    {
        $path = $this->getTempPath();
        $writer = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $writer->create($path);

        $result = [];

        foreach ($files as $name => $file) {
            $extension = mb_strtolower(
                '.' . pathinfo($name, PATHINFO_EXTENSION)
            );

            $fileHash = $this->mathRandom->getUniqueHash();
            $filePath = $path . $fileHash;

            if ($writer->isExist($filePath)) {
                unlink($filePath);
            }

            if (file_put_contents($filePath, $file)) {
                $result = [
                    self::FILEHASH => $fileHash,
                    self::FILENAME => (string)$name,
                    self::EXTENSION => $extension
                ];
            } else {
                throw new RuntimeException('Failed saving file');
            }
        }

        return $result;
    }
}
