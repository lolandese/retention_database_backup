<?php

namespace Drupal\retention_database_backup\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Retention Database Backup.
 */
class DatabaseBackupCommands extends DrushCommands {

  /**
   * Create a database backup manually.
   *
   * Create a database backup immediately without waiting for cron.
   *
   * @usage retention-backup:create
   *   Create a database backup.
   */
  #[CLI\Command(name: 'retention-backup:create', aliases: ['rdb-create'])]
  #[CLI\Version(version: '1.0.0')]
  public function create(): int {
    $config = \Drupal::config('retention_database_backup.settings');

    if (!$config->get('enable_automatic_backups')) {
      $this->logger()
        ->warning('Automatic backups are disabled. Enable them in admin settings.');
      return DrushCommands::EXIT_FAILURE;
    }

    try {
      $backup_manager = \Drupal::service('retention_database_backup.backup_manager');
      $backup_file = $backup_manager->createBackup();

      if (!$backup_file) {
        $this->logger()->error('Failed to create database backup.');
        return DrushCommands::EXIT_FAILURE;
      }

      $this->logger()
        ->success('Database backup created: @file', ['@file' => $backup_file]);

      $backup_manager->applyRetentionPolicy();

      // Update backup_file path since retention policy may have renamed it with [LAST] prefix
      $backup_filename = basename($backup_file);
      if (!str_starts_with($backup_filename, '[LAST]_')) {
        $last_backup_path = dirname($backup_file) . '/[LAST]_' . $backup_filename;
        if (file_exists($last_backup_path)) {
          $backup_file = $last_backup_path;
        }
      }

      $deleted_files = [];

      if (!empty($deleted_files)) {
        $this->logger()->info(
          'Retention policy applied. Deleted @count backup(s).',
          ['@count' => count($deleted_files)]
        );
      }

      // Send email notifications if recipients are configured
      $email_recipients = $config->get('email_recipients');
      if (!empty($email_recipients)) {
        try {
          $backup_manager->sendBackupEmail($backup_file);
          $this->logger()
            ->info('Backup notification emails sent to configured recipients.');
        }
        catch (\Exception $e) {
          $this->logger()
            ->warning('Backup created, but failed to send notification emails: @error',
              ['@error' => $e->getMessage()]);
        }
      }

      // Sync to install folder if enabled
      if ($config->get('enable_install_folder_sync')) {
        try {
          $backup_manager->syncToInstallFolder($backup_file);
          $this->logger()->info('Backup synced to install folder.');
        }
        catch (\Exception $e) {
          $this->logger()
            ->warning('Backup created, but failed to sync to install folder: @error',
              ['@error' => $e->getMessage()]);
        }
      }

      return DrushCommands::EXIT_SUCCESS;
    }
    catch (\Exception $e) {
      $this->logger()
        ->error('Backup error: @error', ['@error' => $e->getMessage()]);

      return DrushCommands::EXIT_FAILURE;
    }
  }

}
