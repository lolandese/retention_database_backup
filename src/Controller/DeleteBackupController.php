<?php

namespace Drupal\retention_database_backup\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\retention_database_backup\BackupManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for backup deletion.
 */
class DeleteBackupController extends ControllerBase {

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
   * Delete a backup file.
   *
   * @param string $filename
   *   The filename to delete.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect response back to dashboard.
   */
  public function delete($filename) {
    // Check user has permission.
    if (!$this->currentUser()->hasPermission('administer retention database backup')) {
      throw new AccessDeniedHttpException('You do not have permission to delete backups.');
    }

    // Validate filename - only allow alphanumeric, hyphens, underscores, brackets, dots.
    if (!preg_match('/^[\[\]\w\-\.]+\.sql\.gz(?:\.gpg)?$/', $filename)) {
      throw new AccessDeniedHttpException('Invalid filename format.');
    }

    // Prevent deletion of protected backups.
    if (preg_match('/^\[(LAST|MONTHLY|HALF_YEAR|YEARLY)\]/', $filename)) {
      $this->messenger()->addError($this->t('Cannot delete protected backup: @filename', ['@filename' => $filename]));
      return new RedirectResponse('/admin/config/system/retention-database-backup');
    }

    try {
      $uri = 'private://db-backups/' . $filename;
      $file_system = \Drupal::service('file_system');
      $real_path = $file_system->realpath($uri);

      // Verify file exists and is in the backup directory.
      if (!$real_path || !file_exists($real_path)) {
        throw new NotFoundHttpException('Backup file not found.');
      }

      // Get file info before deletion for logging.
      $file_size = filesize($real_path);

      // Delete the file.
      if (unlink($real_path)) {
        // Log the deletion.
        \Drupal::logger('retention_database_backup')->info(
          'Backup deleted by @user: @file (@size bytes)',
          [
            '@user' => $this->currentUser()->getAccountName(),
            '@file' => $filename,
            '@size' => $file_size,
          ]
        );

        $this->messenger()->addStatus($this->t('Backup deleted: @filename (@size)', [
          '@filename' => $filename,
          '@size' => $this->formatBytes($file_size),
        ]));
      } else {
        throw new \Exception('Failed to delete file from disk');
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('retention_database_backup')->error(
        'Failed to delete backup @file: @error',
        ['@file' => $filename, '@error' => $e->getMessage()]
      );

      $this->messenger()->addError($this->t('Failed to delete backup: @error',
        ['@error' => $e->getMessage()]));
    }

    // Redirect back to dashboard.
    return new RedirectResponse('/admin/config/system/retention-database-backup');
  }

  /**
   * Format bytes to human-readable format.
   */
  private function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));

    return round($bytes, 2) . ' ' . $units[$pow];
  }

}
