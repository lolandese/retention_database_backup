<?php

namespace Drupal\retention_database_backup\Drush;

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

      // Create backup and apply retention atomically
      $result = $backup_manager->createBackupAndApplyRetention();
      $backup_file = $result['backup_file'];
      $deleted_files = $result['deleted_files'];

      if (!$backup_file) {
        $this->logger()->error('Failed to create database backup.');
        return DrushCommands::EXIT_FAILURE;
      }

      $this->logger()
        ->success('Database backup created: ' . basename($backup_file));

      if (!empty($deleted_files)) {
        $this->logger()->info(
          'Retention policy applied. Deleted ' . count($deleted_files) . ' backup(s).'
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
            ->warning('Backup created, but failed to send notification emails: ' . $e->getMessage());
        }
      }

      if ($config->get('enable_install_folder_sync')) {
        $backup_manager->syncToInstallFolder($backup_file);
        $this->logger()->info('Backup synced to install folder.');
      }

      return DrushCommands::EXIT_SUCCESS;
    }
    catch (\Exception $e) {
      $this->logger()
        ->error('Backup error: ' . $e->getMessage());

      return DrushCommands::EXIT_FAILURE;
    }
  }

  /**
   * Create an encrypted backup for the install folder.
   *
   * This command creates a database backup, optionally encrypts it, and
   * saves it to the install folder with a predictable name for new developers.
   * Useful when sharing the repo with team members who need the latest database.
   *
   * @usage retention-backup:export
   *   Create an encrypted backup for sharing.
   */
  #[CLI\Command(name: 'retention-backup:export', aliases: ['rdb-export'])]
  #[CLI\Version(version: '1.0.0')]
  public function export(): int {
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
        ->success('Database backup created: ' . basename($backup_file));

      $export_file = $backup_file;
      $is_encrypted = str_ends_with($backup_file, '.gpg');

      // Encrypt if enabled and not already encrypted.
      if ($config->get('enable_encryption') && !$is_encrypted) {
        $gpg_recipient = $config->get('gpg_recipient');
        if (empty($gpg_recipient)) {
          $this->logger()
            ->warning('Encryption enabled but GPG recipient not configured.');
          $this->logger()
            ->warning('Set GPG recipient email at /admin/config/system/retention-database-backup');
          $this->logger()
            ->warning('Or see README.md section "Configuring Drupal to Use GPG" for setup instructions.');
          $this->logger()
            ->warning('Exporting unencrypted.');
        }
        else {
          try {
            $export_file = $backup_manager->encryptFile($backup_file, $gpg_recipient);
            $is_encrypted = true;
            $this->logger()->success('✓ Backup encrypted successfully with GPG.');
          }
          catch (\Exception $e) {
            $this->logger()
              ->error('✗ GPG encryption failed: ' . $e->getMessage());
            $this->logger()
              ->warning('See module README.md section "GPG Encryption Troubleshooting" for solutions.');
            $this->logger()
              ->warning('Quick checklist:');
            $this->logger()
              ->warning('  1) Is GPG installed? Run: ddev exec which gpg');
            $this->logger()
              ->warning('  2) Does the key exist? Run: ddev exec gpg --list-keys ' . $gpg_recipient);
            $this->logger()
              ->warning('  3) Check /admin/config/system/retention-database-backup for encryption status.');
            $this->logger()
              ->warning('Exporting unencrypted.');
          }
        }
      }

      // Determine the export folder (install folder).
      $install_folder = \Drupal::root() . '/../install';
      if (!is_dir($install_folder)) {
        @mkdir($install_folder, 0755, TRUE);
      }

      // Create predictable filename.
      $extension = $is_encrypted ? 'sql.gz.gpg' : 'sql.gz';
      $export_name = "database-latest.$extension";
      $export_path = $install_folder . '/' . $export_name;

      // Copy to install folder.
      if (!copy($export_file, $export_path)) {
        $this->logger()
          ->error('Failed to copy backup to install folder.');
        return DrushCommands::EXIT_FAILURE;
      }

      $this->logger()
        ->success(($is_encrypted ? 'Encrypted' : 'Unencrypted') . ' backup exported for team: ' . $export_name);
      $this->logger()
        ->info('Location: install/' . $export_name);

      if ($is_encrypted) {
        $this->logger()
          ->info('✓ This backup is encrypted. Recipients need to decrypt it with GPG before importing.');
      }
      else {
        $this->logger()
          ->warning('⚠ This backup is NOT encrypted. Consider enabling GPG encryption in module settings.');
      }

      $this->logger()
        ->info('New developers can import this with: ddev pull database (or use configure-imported-database.sh)');

      return DrushCommands::EXIT_SUCCESS;
    }
    catch (\Exception $e) {
      $this->logger()
        ->error('Export error: ' . $e->getMessage());

      return DrushCommands::EXIT_FAILURE;
    }
  }

  /**
   * Apply retention policy and clean up old backups.
   *
   * Removes backups older than 3 days that don't fit retention policy.
   * Protects: latest backup, monthly/6-month/yearly representatives, backups 24+ hours old.
   *
   * @usage retention-backup:cleanup
   *   Apply retention policy and delete expired backups.
   */
  #[CLI\Command(name: 'retention-backup:cleanup', aliases: ['rdb-cleanup'])]
  #[CLI\Version(version: '1.0.0')]
  public function cleanup(): int {
    try {
      $backup_manager = \Drupal::service('retention_database_backup.backup_manager');
      $status = $backup_manager->getBackupStatus();

      if (!$status['dir_exists']) {
        $this->logger()->warning('Backup directory does not exist: @dir', [
          '@dir' => $status['backup_dir'],
        ]);
        return DrushCommands::EXIT_SUCCESS;
      }

      if ($status['backup_count'] === 0) {
        $this->logger()->info('No backups found in @dir', [
          '@dir' => $status['backup_dir'],
        ]);
        return DrushCommands::EXIT_SUCCESS;
      }

      $this->logger()->info('Starting retention policy cleanup...');
      $this->logger()->info('Current backups: @count', ['@count' => $status['backup_count']]);
      $this->logger()->info('Total size: @size bytes', ['@size' => $status['total_size']]);

      $deleted_files = $backup_manager->applyRetentionPolicy();

      $remaining_status = $backup_manager->getBackupStatus();

      $this->logger()->success('✓ Retention policy applied');
      $this->logger()->success('  Deleted: @count file(s)', ['@count' => count($deleted_files)]);
      $this->logger()->info('  Remaining backups: @count', ['@count' => $remaining_status['backup_count']]);
      $this->logger()->info('  Remaining size: @size bytes', ['@size' => $remaining_status['total_size']]);

      if (!empty($remaining_status['protected_backups'])) {
        $this->logger()->info('Protected backups:');
        foreach ($remaining_status['protected_backups'] as $backup) {
          $age_days = round((time() - $backup['timestamp']) / 86400, 1);
          $this->logger()->info('  [@status] @file (@age days old, @size KB)', [
            '@status' => $backup['status'],
            '@file' => $backup['filename'],
            '@age' => $age_days,
            '@size' => round($backup['size'] / 1024, 1),
          ]);
        }
      }

      return DrushCommands::EXIT_SUCCESS;
    }
    catch (\Exception $e) {
      $this->logger()->error('Cleanup error: @error', ['@error' => $e->getMessage()]);
      return DrushCommands::EXIT_FAILURE;
    }
  }

}
