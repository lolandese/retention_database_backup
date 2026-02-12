<?php

namespace Drupal\retention_database_backup\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\retention_database_backup\BackupManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Controller for backup downloads and management.
 */
class BackupDownloadController extends ControllerBase {

  /**
   * The backup manager service.
   *
   * @var \Drupal\retention_database_backup\BackupManager
   */
  protected $backupManager;

  /**
   * Constructor.
   */
  public function __construct(BackupManager $backup_manager) {
    $this->backupManager = $backup_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('retention_database_backup.backup_manager')
    );
  }

  /**
   * Download a backup file.
   *
   * @param string $filename
   *   The filename to download.
   *
   * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
   *   The file response.
   */
  public function download($filename) {
    // Check user has permission.
    if (!$this->currentUser()->hasPermission('administer retention database backup')) {
      throw new AccessDeniedHttpException('You do not have permission to download backups.');
    }

    // Validate filename - only allow alphanumeric, hyphens, underscores, brackets, dots.
    if (!preg_match('/^[\[\]\w\-\.]+\.sql\.gz(?:\.gpg)?$/', $filename)) {
      throw new AccessDeniedHttpException('Invalid filename format.');
    }

    // Generate private file URI.
    $uri = 'private://db-backups/' . $filename;
    $file_system = \Drupal::service('file_system');

    // Get the real path for BinaryFileResponse.
    $real_path = $file_system->realpath($uri);

    // Verify file exists.
    if (!$real_path || !file_exists($real_path)) {
      throw new NotFoundHttpException('Backup file not found.');
    }

    // Log the download (hook_file_download() will also log).
    \Drupal::logger('retention_database_backup')->debug(
      'Initiating download of backup: @file by @user',
      ['@file' => $filename, '@user' => $this->currentUser()->getAccountName()]
    );

    // Return the file for download via private file system.
    // hook_file_download() will be called automatically by Drupal.
    $response = new BinaryFileResponse($real_path);
    $response->setContentDisposition(
      ResponseHeaderBag::DISPOSITION_ATTACHMENT,
      $filename
    );

    return $response;
  }

}
