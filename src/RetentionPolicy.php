<?php

namespace Drupal\retention_database_backup;

use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Service to manage backup file retention policy.
 */
class RetentionPolicy {

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructor.
   */
  public function __construct(LoggerChannelInterface $logger) {
    $this->logger = $logger;
  }

  /**
   * Apply the retention policy to backup directory.
   *
   * Retention tiers:
   * - Tier 1: Keep all backups from last 2 days
   * - Tier 2: Keep 1 backup per 2 weeks for past 1 month
   * - Tier 3: Keep 1 backup per 2 months for past 6 months
   * - Tier 4: Keep 1 backup per 6 months for past 1 year
   *
   * @return array
   *   Array of deleted filenames.
   */
  public function applyRetentionPolicy() {
    $backup_dir = \Drupal::service('retention_database_backup.backup_manager')->getBackupDirectory();

    if (!is_dir($backup_dir)) {
      return [];
    }

    $backups = $this->getBackupFiles($backup_dir);

    if (empty($backups)) {
      return [];
    }

    // Determine which files to keep based on retention policy.
    $files_to_keep = $this->determineRetentionTiers($backups);

    // Delete backups not in the keep list.
    $deleted = [];
    foreach ($backups as $file) {
      if (!in_array($file, $files_to_keep)) {
        if (unlink($file)) {
          $deleted[] = basename($file);
        }
      }
    }

    return $deleted;
  }

  /**
   * Get all backup files sorted by date.
   *
   * @param string $dir
   *   The backup directory path.
   *
   * @return array
   *   Array of backup file paths, sorted newest first.
   */
  protected function getBackupFiles($dir) {
    $files = [];
    $handle = @opendir($dir);

    if (!$handle) {
      return $files;
    }

    while (($file = readdir($handle)) !== FALSE) {
      if (strpos($file, '.sql.gz') !== FALSE) {
        $files[] = $dir . '/' . $file;
      }
    }
    closedir($handle);

    // Sort by modification time, newest first.
    usort($files, function ($a, $b) {
      return filemtime($b) - filemtime($a);
    });

    return $files;
  }

  /**
   * Determine which backups to keep based on retention policy.
   *
   * @param array $backups
   *   Array of backup file paths sorted newest first.
   *
   * @return array
   *   Array of file paths to keep.
   */
  protected function determineRetentionTiers(array $backups) {
    $files_to_keep = [];
    $now = time();
    $two_days_ago = $now - (2 * 24 * 60 * 60);
    $one_month_ago = $now - (30 * 24 * 60 * 60);
    $six_months_ago = $now - (180 * 24 * 60 * 60);
    $one_year_ago = $now - (365 * 24 * 60 * 60);

    // Track which tier representative we're keeping.
    $tier2_kept = FALSE;
    $tier3_kept = FALSE;
    $tier4_kept = FALSE;

    foreach ($backups as $file) {
      $file_time = filemtime($file);

      // Tier 1: Keep all backups from last 2 days.
      if ($file_time >= $two_days_ago) {
        $files_to_keep[] = $file;
        continue;
      }

      // Tier 2: Keep 1 representative backup per 2 weeks for past 1 month.
      if ($file_time >= $one_month_ago && !$tier2_kept) {
        $files_to_keep[] = $file;
        $tier2_kept = TRUE;
        continue;
      }

      // Tier 3: Keep 1 representative backup per 2 months for past 6 months.
      if ($file_time >= $six_months_ago && !$tier3_kept) {
        $files_to_keep[] = $file;
        $tier3_kept = TRUE;
        continue;
      }

      // Tier 4: Keep 1 representative backup per 6 months for past 1 year.
      if ($file_time >= $one_year_ago && !$tier4_kept) {
        $files_to_keep[] = $file;
        $tier4_kept = TRUE;
        continue;
      }
    }

    // Always keep at least the most recent backup.
    if (empty($files_to_keep) && !empty($backups)) {
      $files_to_keep[] = $backups[0];
    }

    return $files_to_keep;
  }

  /**
   * Check if a backup file matches the 1-month tier.
   *
   * This is used to determine if an email should be sent.
   *
   * @param string $backup_file
   *   The backup file path.
   *
   * @return bool
   *   TRUE if this is a 1-month tier backup, FALSE otherwise.
   */
  public function isMonthlyBackupFile($backup_file) {
    $backup_dir = \Drupal::service('retention_database_backup.backup_manager')->getBackupDirectory();
    $backups = $this->getBackupFiles($backup_dir);

    if (empty($backups)) {
      return FALSE;
    }

    $now = time();
    $two_days_ago = $now - (2 * 24 * 60 * 60);
    $one_month_ago = $now - (30 * 24 * 60 * 60);

    $file_time = filemtime($backup_file);

    // Monthly backup is one that falls outside the 2-day window but is within the 1-month window.
    // And is a representative of that tier (the most recent in its tier).
    if ($file_time < $two_days_ago && $file_time >= $one_month_ago) {
      // Check if this is the most recent backup in the month tier.
      foreach ($backups as $backup) {
        $backup_time = filemtime($backup);
        if ($backup_time >= $two_days_ago) {
          // Skip tier 1 backups for monthly check.
          continue;
        }
        if ($backup_time >= $one_month_ago && $backup_time < $two_days_ago) {
          // This is the first backup outside the 2-day window, which is our monthly tier representative.
          return $backup === $backup_file;
        }
      }
    }

    return FALSE;
  }

}
