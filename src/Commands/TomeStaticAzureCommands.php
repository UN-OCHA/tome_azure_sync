<?php

namespace Drupal\tome_static_azure\Commands;

use Drush\Commands\DrushCommands;
use Drupal\Core\Site\Settings;
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;
use Mimey\MimeTypes;

/**
 * A Drush commandfile.
 *
 * This is a very basic command that uses the azure_storage wrapper to upload
 * a directory to Azure blob storage.
 *
 * It assumes you create the container outside Drupal.
 */
class TomeStaticAzureCommands extends DrushCommands {

  /**
   * A static website container is always this one.
   */
  const AZURE_SITE_CONTAINER = '$web';

  /**
   * The blob service.
   *
   * @var \MicrosoftAzure\Storage\Blob\Internal\IBlob
   */
  protected $blobService;

  /**
   * Constructor that should keep a blob service handy.
   */
  public function __construct() {
    if (!$this->blobService) {
      // @codingStandardsIgnoreLine
      $storage_client = \Drupal::service('azure_storage.client');
      $this->blobService = $storage_client->getStorageBlobService();
    }
  }

  /**
   * Synchronise the tome_static output directory to an azure storsage account.
   *
   * @usage drush tome-static-azure-sync
   *   Copies all files from the configured tome_static directory to Azure.
   *
   * @command tome:azure-sync
   */
  public function sync() {

    // We get our files from the tome_static module settings.
    $tome_dir = Settings::get('tome_static_directory', '../html');

    if (!file_exists($tome_dir) || !is_dir($tome_dir)) {
      $this->logger()->error(dt('The tome_static source directory @dir does not exist!', ['@dir' => $tome_dir]));
      exit(1);
    }

    $files = $this->getFileList($tome_dir);
    $this->logger()->info(dt('Going to synchronise @count files from @dir',
      ['@count' => count($files), '@dir' => $tome_dir]
    ));

    // Look up the mime type based on the extension because the magic file
    // via the finfo_* makes trouble. Do this here so we can more easily
    // compare the flat files list against the blob list.
    $mimes = new MimeTypes();

    foreach ($files as $file) {
      try {
        // Set the file content-type, or Azure will default to forcing a
        // download for everything.
        $options = new CreateBlockBlobOptions();
        $options->setContentType($mimes->getMimeType(pathinfo($file, PATHINFO_EXTENSION)));

        $content = fopen("${tome_dir}/${file}", "r");
        $this->blobService->createBlockBlob(TomeStaticAzureCommands::AZURE_SITE_CONTAINER, $file, $content, $options);

        $this->logger()->success(dt('Uploaded @file', ['@file' => $file]));
      }
      catch (ServiceException $e) {
        $this->logger()->error(dt('Error uploading @file: Service Error @code: @message', [
          '@file'    => $file,
          '@code'    => $e->getCode(),
          '@message' => $e->getMessage(),
        ]));
      }
      catch (InvalidArgumentTypeException $e) {
        $this->logger()->error(dt('Error uploading @file: Invalid Argument @code: @message.', [
          '@file'    => $file,
          '@code'    => $e->getCode(),
          '@message' => $e->getMessage(),
        ]));
      }
    }

    // Remove any orphaned files from Azure.
    $this->cleanup($files);
  }

  /**
   * Display a list of blobs in the storage account.
   *
   * @usage drush tome-static-azure-blobs
   *   List all the blobs in the Azure storage account/
   *
   * @command tome:azure-blobs
   */
  public function blobs() {
    $blobs = $this->getBlobList();
    foreach ($blobs as $blob) {
      $this->logger()->success(dt('@blob', ['@blob' => $blob]));
    }
  }

  /**
   * Helper to delete orphaned files off Azure.
   *
   * Generate a list of all blobs on Azure that are not in the current
   * files list and then delete them from the storage container.
   *
   * @param array $files
   *   An array of files that *should* exist on Azure.
   */
  private function cleanup(array $files) {
    $blobs = $this->getBlobList();

    foreach (array_diff($blobs, $files) as $orphan) {
      try {
        $this->blobService->deleteBlob(TomeStaticAzureCommands::AZURE_SITE_CONTAINER, $orphan);
        $this->logger()->success(dt('Deleted @blob', ['@blob' => $orphan]));
      }
      catch (ServiceException $e) {
        $this->logger()->error(dt('Error deleting @blob: Service Error @code: @message', [
          '@blob'    => $orphan,
          '@code'    => $e->getCode(),
          '@message' => $e->getMessage(),
        ]));
      }
      catch (InvalidArgumentTypeException $e) {
        $this->logger()->error(dt('Error deleting @blob: Invalid Argument @code: @message.', [
          '@blob'    => $orphan,
          '@code'    => $e->getCode(),
          '@message' => $e->getMessage(),
        ]));
      }
    }
  }

  /**
   * Helper to generate a list of files for syncing.
   *
   * @param string $path
   *   A directory.
   *
   * @return array
   *   An array of filenames.
   */
  private function getFileList($path) {
    $files = [];

    // Make sure we do not end with a slash.
    $path = rtrim($path, '/');

    $directory = new \RecursiveDirectoryIterator($path);
    $filter = new \RecursiveCallbackFilterIterator($directory, function ($current, $key, $iterator) {
      // Skip hidden files and directories.
      // @codingStandardsIgnoreLine
      if ($current->getFilename()[0] === '.') return FALSE;
      return TRUE;
    });
    $iterator = new \RecursiveIteratorIterator($filter);

    foreach ($iterator as $file) {
      $files[] = substr($file->getPathname(), strlen($path) + 1);
    }

    return $files;
  }

  /**
   * Helper to generate a list of files on Azure.
   *
   * @return array
   *   An array of filenames.
   */
  private function getBlobList() {
    $blobs = [];
    $list = $this->blobService->listBlobs(TomeStaticAzureCommands::AZURE_SITE_CONTAINER);

    foreach ($list->getBlobs() as $blob) {
      $blobs[] = $blob->getName();
    }

    return $blobs;
  }

}
