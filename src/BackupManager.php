<?php

namespace Drupal\retention_database_backup;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Service to manage database backups.
 */
class BackupManager {

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Constructor.
   */
  public function __construct(
    MailManagerInterface $mail_manager,
    LoggerChannelInterface $logger,
    ConfigFactoryInterface $config_factory,
    FileSystemInterface $file_system,
  ) {
    $this->mailManager = $mail_manager;
    $this->logger = $logger;
    $this->configFactory = $config_factory;
    $this->fileSystem = $file_system;
  }

  /**
   * Create a database backup.
   *
   * @return string|null
   *   The path to the created backup file, or NULL on failure.
   *
   * @throws \Exception
   */
  public function createBackup() {
    $backup_dir = $this->getBackupDirectory();
    $this->fileSystem->prepareDirectory($backup_dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    // Generate backup filename with timestamp, branch, and commit hash.
    $filename = $this->generateBackupFilename();
    $backup_file = $backup_dir . '/' . $filename;

    // Build the drush command.
    $cmd = [
      'drush',
      'sql:dump',
      '--gzip',
      '--result-file=' . $backup_file,
    ];

    try {
      $process = new Process($cmd);
      $process->setTimeout(3600); // 1 hour timeout.
      $process->run();

      if (!$process->isSuccessful()) {
        throw new ProcessFailedException($process);
      }

      // Verify the file was created.
      $final_backup_file = $backup_file . '.gz';
      if (!file_exists($final_backup_file)) {
        throw new \Exception('Backup file was not created.');
      }

      // Encrypt the backup if encryption is enabled.
      $config = $this->configFactory->get('retention_database_backup.settings');
      if ($config->get('enable_encryption')) {
        $gpg_recipient = $config->get('gpg_recipient');
        if (!empty($gpg_recipient)) {
          try {
            $encrypted_file = $this->encryptFile($final_backup_file, $gpg_recipient);
            // Remove the unencrypted file since we only want encrypted backups when encryption is enabled.
            if (file_exists($final_backup_file)) {
              @unlink($final_backup_file);
            }
            $final_backup_file = $encrypted_file;
            $this->logger->info('Backup encrypted with GPG for storage.');
          }
          catch (\Exception $e) {
            $this->logger->error('Failed to encrypt backup: @error', ['@error' => $e->getMessage()]);
            // Continue with unencrypted backup if encryption fails.
          }
        }
        else {
          $this->logger->warning('Encryption enabled but GPG recipient not configured.');
        }
      }

      return $final_backup_file;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create database backup: @error', ['@error' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Create a backup and apply retention policy in a single atomic operation.
   *
   * This ensures retention (which applies the [LAST] prefix) is only called
   * when a backup is actually created, never during form rendering.
   *
   * @return array
   *   Array with keys:
   *   - 'backup_file': Path to the created backup (with [LAST] prefix if newest)
   *   - 'deleted_files': Array of deleted filenames from retention cleanup
   *
   * @throws \Exception
   */
  public function createBackupAndApplyRetention() {
    // Step 1: Create the backup
    $backup_file = $this->createBackup();

    // Step 2: Apply retention policy (handles [LAST] prefix)
    $deleted_files = $this->applyRetentionPolicy();

    // Step 3: Find the final path of our backup (retention might have renamed it with [LAST])
    $backup_filename = basename($backup_file);
    $backup_dir = dirname($backup_file);

    // Check if retention applied [LAST] prefix
    if (!str_starts_with($backup_filename, '[LAST]_')) {
      $last_backup_path = $backup_dir . '/[LAST]_' . $backup_filename;
      if (file_exists($last_backup_path)) {
        $backup_file = $last_backup_path;
      }
    }

    return [
      'backup_file' => $backup_file,
      'deleted_files' => $deleted_files,
    ];
  }

  /**
   * Generate a backup filename with timestamp, branch, and commit.
   *
   * Format: YYYYMMDDTHHMMSSS-branch-commithash.sql.gz
   *
   * @return string
   *   The generated filename.
   */
  protected function generateBackupFilename() {
    $timestamp = date('YmdHis');
    // Insert 'T' separator after YYYYMMDD (YmdHis produces 14 chars, so T goes after first 8).
    $timestamp = substr($timestamp, 0, 8) . 'T' . substr($timestamp, 8);
    $branch = $this->getCurrentBranch();
    $commit = $this->getCurrentCommitHash();

    return "{$timestamp}-{$branch}-{$commit}.sql";
  }

  /**
   * Get the current git branch name, sanitized.
   *
   * @return string
   *   The branch name, or 'main' if not in a git repo.
   */
  protected function getCurrentBranch() {
    $output = [];
    $return_code = 0;
    @exec('git rev-parse --abbrev-ref HEAD 2>/dev/null', $output, $return_code);

    if ($return_code === 0 && !empty($output[0])) {
      $branch = $output[0];
      // Sanitize the branch name to be filename-safe.
      $branch = preg_replace('/[^A-Za-z0-9._-]/', '_', $branch);
      return $branch;
    }

    return 'unknown';
  }

  /**
   * Get the first 8 characters of the current git commit hash.
   *
   * @return string
   *   The short commit hash, or '00000000' if not in a git repo.
   */
  protected function getCurrentCommitHash() {
    $output = [];
    $return_code = 0;
    @exec('git rev-parse HEAD 2>/dev/null', $output, $return_code);

    if ($return_code === 0 && !empty($output[0])) {
      return substr($output[0], 0, 8);
    }

    return '00000000';
  }

  /**
   * Get the backup directory path.
   *
   * @return string
   *   The backup directory path.
   */
  public function getBackupDirectory() {
    // Store backups in the private files directory for security.
    // This path is outside the web root and access is controlled via hook_file_download().
    $private_path = \Drupal::service('file_system')->realpath('private://db-backups');
    if ($private_path && !is_dir($private_path)) {
      \Drupal::service('file_system')->prepareDirectory($private_path, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);
    }
    return $private_path;
  }

  /**
   * Send backup file via email.
   *
   * @param string $backup_file
   *   The path to the backup file.
   *
   * @throws \Exception
   */
  public function notifyNewRecipients(array $new_recipients) {
    if (empty($new_recipients)) {
      return;
    }

    $config = $this->configFactory->get('retention_database_backup.settings');
    $from_email = $config->get('email_from_address');
    if (empty($from_email)) {
      $from_email = \Drupal::config('system.site')->get('mail');
    }

    $site_name = \Drupal::config('system.site')->get('name');
    $subject = t('You have been added to @site backup email list', ['@site' => $site_name]);

    $body = t('Hello,

You have been added to the backup notification email list for @site.

You will receive monthly database backup notifications to this email address. If you believe this is not appropriate or you would like to be removed, please reply to this email at @from_email explaining your situation.

Thank you,
@site Backup System', [
      '@site' => $site_name,
      '@from_email' => $from_email,
    ]);

    $failed_recipients = [];
    foreach ($new_recipients as $email) {
      $sent = $this->sendEmail($email, $subject, $body, NULL, $from_email);
      if ($sent) {
        $this->logger->info('Sent new recipient notification to @email', ['@email' => $email]);
      } else {
        $failed_recipients[] = $email;
      }
    }

    if (!empty($failed_recipients)) {
      throw new \Exception('Failed to send notification emails to: ' . implode(', ', $failed_recipients));
    }
  }

  /**
   * Send a backup file via email.
   *
   * @param string $backup_file
   *   The path to the backup file.
   *
   * @throws \Exception
   */
  public function sendBackupEmail($backup_file) {
    $config = $this->configFactory->get('retention_database_backup.settings');
    $recipients = $config->get('email_recipients');
    $from_email = $config->get('email_from_address');

    if (empty($recipients)) {
      $this->logger->warning('No email recipients configured for backup email.');
      return;
    }

    // Parse comma-separated recipients.
    $emails = array_map('trim', explode(',', $recipients));
    $emails = array_filter($emails);

    if (empty($emails)) {
      $this->logger->warning('No valid email recipients configured.');
      return;
    }

    $file_to_check = $backup_file;

    // Check if file will be encrypted.
    $encryption_enabled = $config->get('enable_encryption');
    if ($encryption_enabled) {
      try {
        $gpg_recipient = $config->get('gpg_recipient');
        if (empty($gpg_recipient)) {
          $this->logger->error('Encryption enabled but GPG recipient not configured.');
          return;
        }
        $encrypted_file = $this->encryptFile($backup_file, $gpg_recipient);
        $file_to_check = $encrypted_file;
        $this->logger->info('Backup encrypted with GPG.');
      }
      catch (\Exception $e) {
        $this->logger->error('Failed to encrypt backup: @error', ['@error' => $e->getMessage()]);
        return;
      }
    }

    // Prepare email with download instructions (no attachment).
    $site_name = \Drupal::config('system.site')->get('name');
    $site_url = \Drupal::config('system.site')->get('page.front');
    $subject = t('Monthly Database Backup - @site', ['@site' => $site_name]);

    // Build comprehensive email body with download instructions.
    $file_size_bytes = @filesize($file_to_check);
    if ($file_size_bytes === FALSE) {
      $file_size_bytes = 0;
    }
    $file_size_mb = $file_size_bytes > 0 ? round($file_size_bytes / (1024 * 1024), 2) : 0;

    $file_time = @filemtime($file_to_check);
    if ($file_time === FALSE) {
      $file_time = time();
    }
    $backup_date = \Drupal::service('date.formatter')->format($file_time, 'long');
    $commit_hash = $this->extractCommitFromFilename($backup_file);
    $encryption_status = $encryption_enabled ? 'YES (GPG encrypted)' : 'NO (unencrypted)';

    $body = t('DATABASE BACKUP NOTIFICATION

Site: @site
Backup File: @filename
Backup Date: @date
File Size: @size MB
Git Commit: @commit
Encrypted: @encryption

---

ABOUT THIS BACKUP:
This is an automated monthly database backup of your site. The backup contains:
- Database schema
- All data in the database
- Database configuration

---

ENCRYPTION STATUS:
@encryption_status_text

---

DOWNLOAD INSTRUCTIONS:
1. Log into the admin control panel: @admin_url
2. Go to: Administration > System > Retention Database Backup
3. Look for your backup file: @filename
4. Click the download button to download the backup file

---

RESTORE INSTRUCTIONS:
1. Download the backup file from the admin panel (see DOWNLOAD INSTRUCTIONS above)
2. Decrypt if encrypted (GPG):
   gpg --decrypt @filename.gpg > @filename
3. Extract if compressed:
   gzip -d @filename
4. Import to local database:
   mysql -u db_user -p database_name < @filename
5. For detailed instructions, see: repo root/README.md or
   web/modules/custom/retention_database_backup/README.md

---', [
      '@site' => $site_name,
      '@admin_url' => \Drupal::request()->getSchemeAndHttpHost() . '/admin/config/system/retention-database-backup',
      '@filename' => basename($backup_file),
      '@date' => $backup_date,
      '@size' => $file_size_mb,
      '@commit' => $commit_hash,
      '@encryption' => $encryption_status,
      '@encryption_status_text' => $encryption_enabled
        ? 'This backup is ENCRYPTED with GPG. You MUST decrypt it before use.

See RESTORE INSTRUCTIONS for decryption command.
For detailed GPG setup and troubleshooting, see: AGENTS.md section "GPG Encryption Setup & Troubleshooting"'
        : 'This backup is NOT encrypted. Use directly without decryption.',
    ]);

    // Send email WITHOUT attachment (download from admin panel instead).
    $failed_count = 0;
    foreach ($emails as $email) {
      $sent = $this->sendEmail($email, $subject, $body, NULL, $from_email);
      if (!$sent) {
        $failed_count++;
      }
    }

    if ($failed_count > 0) {
      $this->logger->warning('Failed to send backup email to @count recipient(s)', ['@count' => $failed_count]);
    }
  }

  /**
   * Send error notification email.
   *
   * @param string $error_message
   *   The error message to include.
   */
  public function sendErrorEmail($error_message) {
    $config = $this->configFactory->get('retention_database_backup.settings');
    $recipients = $config->get('email_recipients');
    $from_email = $config->get('email_from_address');

    if (empty($recipients)) {
      return;
    }

    $emails = array_map('trim', explode(',', $recipients));
    $emails = array_filter($emails);

    $subject = t('Database Backup Error - @site', ['@site' => \Drupal::config('system.site')->get('name')]);

    foreach ($emails as $email) {
      $this->sendEmail($email, $subject, $error_message, NULL, $from_email);
    }
  }

  /**
   * Send email using Drupal mail system.
   *
   * @param string $to
   *   Recipient email address.
   * @param string $subject
   *   Email subject.
   * @param string $body
   *   Email body.
   * @param string|null $attachment_file
   *   Optional attachment file path.
   * @param string|null $from_email
   *   Optional from email address. If empty, uses system email.
   */
  protected function sendEmail($to, $subject, $body, $attachment_file = NULL, $from_email = NULL) {
    if (empty($from_email)) {
      $from_email = \Drupal::config('system.site')->get('mail');
    }

    // In Drupal 10 with Symfony Mailer, we pass parameters to hook_mail
    $mailbox = [
      'to' => $to,
      'subject' => $subject,
      'body' => $body,
      'from' => $from_email,
    ];

    // Send via mail manager
    try {
      $result = $this->mailManager->mail(
        'retention_database_backup',
        'backup_notification',
        $to,
        \Drupal::languageManager()->getDefaultLanguage()->getId(),
        $mailbox
      );

      // Check result - in Drupal 10, result is typically a boolean or array with 'result' key
      if (is_array($result)) {
        $success = $result['result'] ?? FALSE;
      } else {
        $success = (bool) $result;
      }

      if (!$success) {
        $this->logger->error('Failed to send email to @email with subject @subject',
          ['@email' => $to, '@subject' => $subject]);
        return FALSE;
      }

      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Exception sending email to @email: @error',
        ['@email' => $to, '@error' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Send email with attachment using system mail function.
   *
   * @param string $to
   *   Recipient email address.
   * @param string $subject
   *   Email subject.
   * @param string $body
   *   Email body.
   * @param string $attachment_file
   *   Attachment file path.
   * @param string|null $from_email
   *   Optional from email address. If empty, uses system email.
   */
  protected function sendEmailWithAttachment($to, $subject, $body, $attachment_file, $from_email = NULL) {
    if (empty($from_email)) {
      $from_email = \Drupal::config('system.site')->get('mail');
    }

    // Prepare email headers with attachment (simple implementation).
    // Note: For production, consider using a mail module that supports attachments.
    $headers = [
      'From' => $from_email,
      'Content-Type' => 'text/plain; charset=UTF-8',
    ];

    // For now, log that attachment support is limited.
    $this->logger->warning('Email attachment support is limited. Consider using a mail module (e.g., Mailsystem, Swiftmailer) for reliable attachment delivery.');

    // Send basic email with attachment info in body.
    $file_size_formatted = $this->formatBytes(filesize($attachment_file));
    $body_with_attachment_info = $body . "\n\nBackup file: " . basename($attachment_file) . " (" . $file_size_formatted . ")";
    mail($to, (string) $subject, (string) $body_with_attachment_info, $headers);
  }

  /**
   * Format bytes to human-readable format.
   *
   * @param int $bytes
   *   Number of bytes.
   * @param int $precision
   *   Decimal precision.
   *
   * @return string
   *   Formatted size string.
   */
  private function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
  }

  /**
   * Sync the latest backup to the install folder.
   *
   * @param string $backup_file
   *   The path to the backup file.
   */
  public function syncToInstallFolder($backup_file) {
    $config = $this->configFactory->get('retention_database_backup.settings');
    $install_path = $config->get('install_folder_path') ?: 'install';
    $install_dir = \Drupal::root() . '/../' . $install_path;

    // Create install directory if it doesn't exist.
    if (!is_dir($install_dir)) {
      @mkdir($install_dir, 0755, TRUE);
    }

    $file_to_sync = $backup_file;

    // Encrypt if enabled and file is not already encrypted.
    if ($config->get('enable_encryption') && !str_ends_with($backup_file, '.gpg')) {
      try {
        $gpg_recipient = $config->get('gpg_recipient');
        if (empty($gpg_recipient)) {
          $this->logger->error('Encryption enabled but GPG recipient not configured.');
          return;
        }
        // Create temporary encrypted copy for install folder.
        $temp_encrypted = $this->encryptFile($backup_file, $gpg_recipient);
        $file_to_sync = $temp_encrypted;
        $this->logger->info('Backup encrypted with GPG for install folder.');
      }
      catch (\Exception $e) {
        $this->logger->error('Failed to encrypt backup for install folder: @error', ['@error' => $e->getMessage()]);
        return;
      }
    }

    $install_file = $install_dir . '/database.sql.gz';
    if ($config->get('enable_encryption')) {
      $install_file .= '.gpg';
    }

    // Copy backup to install folder.
    if (!copy($file_to_sync, $install_file)) {
      $this->logger->error('Failed to sync backup to install folder: @file', ['@file' => $install_file]);
      return;
    }

    // Clean up temporary encrypted file if it was created.
    if ($file_to_sync !== $backup_file && file_exists($file_to_sync)) {
      @unlink($file_to_sync);
    }

    $this->logger->info('Backup synced to install folder: @file', ['@file' => $install_file]);
  }

  /**
   * Extract commit hash from backup filename.
   *
   * @param string $filename
   *   The backup filename.
   *
   * @return string
   *   The commit hash, or empty string if not found.
   */
  protected function extractCommitFromFilename($filename) {
    $basename = basename($filename);
    // Format: YYYYMMDDTHHMMSSS-branch-commithash.sql.gz
    $parts = explode('-', $basename);
    if (count($parts) >= 3) {
      return substr($parts[count($parts) - 1], 0, 8); // Get commit hash (first 8 chars).
    }
    return '';
  }

  /**
   * Check if GPG encryption is available.
   *
   * @return bool
   *   TRUE if gpg command is available.
   */
  public function isGpgAvailable() {
    $output = [];
    $return_code = 0;
    @exec('which gpg 2>/dev/null', $output, $return_code);
    return $return_code === 0;
  }

  /**
   * Encrypt a file using GPG.
   *
   * @param string $file_path
   *   Path to the file to encrypt.
   * @param string $recipient
   *   GPG recipient (email or key ID).
   *
   * @return string|null
   *   Path to encrypted file (.gpg), or NULL on failure.
   *
   * @throws \Exception
   */
  public function encryptFile($file_path, $recipient) {
    if (!file_exists($file_path)) {
      throw new \Exception("File not found: $file_path");
    }

    // Prevent double encryption: if file is already encrypted, return it as-is
    if (str_ends_with($file_path, '.gpg')) {
      $this->logger->warning('File is already encrypted, skipping re-encryption: @file', ['@file' => basename($file_path)]);
      return $file_path;
    }

    if (!$this->isGpgAvailable()) {
      $message = "GPG is not available on this system. " .
        "Install GPG with: ddev exec apk add gnupg (for DDEV) or sudo apt-get install gnupg2 (for production). " .
        "See module README.md section 'Installing GPG on the Server' for details, or disable encryption at /admin/config/system/retention-database-backup";
      throw new \Exception($message);
    }

    $encrypted_file = $file_path . '.gpg';

    // Build and run GPG command.
    $cmd = [
      'gpg',
      '--trust-model', 'always',
      '--encrypt',
      '--recipient', $recipient,
      '--output', $encrypted_file,
      $file_path,
    ];

    try {
      $process = new Process($cmd);
      $process->setTimeout(300);
      $process->run();

      if (!$process->isSuccessful()) {
        // Extract detailed error output from GPG.
        $error_output = $process->getErrorOutput();
        $error_msg = trim($error_output) ?: $process->getExceptionMessage();

        // Provide contextual help based on common GPG errors.
        $help_message = '';
        if (strpos($error_output, 'skipped: No data') !== FALSE) {
          $help_message = " The GPG recipient key is not found or not trusted. " .
            "See README.md section 'GPG Encryption Troubleshooting' for: " .
            "1) Verify the key exists: ddev exec gpg --list-keys $recipient; " .
            "2) Generate a new key if missing; " .
            "3) Update /admin/config/system/retention-database-backup with correct GPG recipient email.";
        }
        elseif (strpos($error_output, 'error retrieving') !== FALSE) {
          $help_message = " Failed to download key from key server (WKD). " .
            "See README.md section 'Troubleshooting: GPG encryption failed: No data' for solutions. " .
            "Usually requires: ddev exec gpg --update-trustdb";
        }
        elseif (strpos($error_output, 'permission denied') !== FALSE) {
          $help_message = " Permission denied writing encrypted file. Check db-backups directory permissions or disk space.";
        }

        $full_message = "GPG encryption failed for recipient '$recipient': $error_msg.$help_message";
        throw new ProcessFailedException($process, $full_message);
      }

      if (!file_exists($encrypted_file)) {
        throw new \Exception('GPG encryption failed: output file was not created. This may indicate insufficient disk space or file permission issues. Check db-backups directory.');
      }

      return $encrypted_file;
    }
    catch (\Exception $e) {
      $this->logger->error('GPG encryption failed: @error', ['@error' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Get current time (mockable for testing).
   *
   * @return int
   *   Unix timestamp.
   */
  protected function getCurrentTime() {
    return time();
  }

  /**
   * Get the protection status for a backup file.
   *
   * @param string $filename
   *   The backup filename.
   * @return string|null
   *   Protection status: 'LAST', 'MONTHLY', '6MONTH', 'YEARLY', or NULL.
   */
  protected function getFilenameStatus($filename) {
    $pattern = '/^\[(LAST|MONTHLY|6MONTH|YEARLY)\]_/';
    if (preg_match($pattern, $filename, $matches)) {
      return $matches[1];
    }
    return null;
  }

  /**
   * Extract the base filename without status prefix.
   *
   * @param string $filename
   *   The backup filename (may include status prefix).
   * @return string
   *   The filename without prefix.
   */
  protected function removeStatusPrefix($filename) {
    return preg_replace('/^\[(LAST|MONTHLY|6MONTH|YEARLY)\]_/', '', $filename);
  }

  /**
   * Add a status prefix to a filename.
   *
   * @param string $filename
   *   The filename (without prefix).
   * @param string $status
   *   The status: 'LAST', 'MONTHLY', '6MONTH', 'YEARLY', or NULL to remove.
   * @return string
   *   The filename with prefix.
   */
  protected function addStatusPrefix($filename, $status) {
    $base = $this->removeStatusPrefix($filename);
    if ($status) {
      return "[{$status}]_{$base}";
    }
    return $base;
  }

  /**
   * Extract timestamp from backup filename.
   *
   * @param string $filename
   *   Backup filename with or without status prefix.
   * @return int|null
   *   Unix timestamp or NULL if unparseable.
   */
  protected function extractTimestamp($filename) {
    $base = $this->removeStatusPrefix($filename);
    if (preg_match('/^(\d{8})T(\d{6})/', $base, $matches)) {
      $date_part = $matches[1];
      $time_part = $matches[2];
      try {
        return strtotime(
          substr($date_part, 0, 4) . '-' .
          substr($date_part, 4, 2) . '-' .
          substr($date_part, 6, 2) . ' ' .
          substr($time_part, 0, 2) . ':' .
          substr($time_part, 2, 2) . ':' .
          substr($time_part, 4, 2)
        );
      }
      catch (\Exception $e) {
        return null;
      }
    }
    return null;
  }

  /**
   * Get all backup files with metadata.
   *
   * @return array
   *   Array of backups, keyed by path, with: filename, timestamp, status, size, mtime.
   */
  protected function getAllBackups() {
    $backup_dir = $this->getBackupDirectory();
    $backups = [];

    if (!is_dir($backup_dir)) {
      return $backups;
    }

    $files = array_diff(scandir($backup_dir) ?: [], ['.', '..']);

    foreach ($files as $file) {
      $path = $backup_dir . '/' . $file;

      if (!is_file($path) || (!str_ends_with($file, '.sql.gz') && !str_ends_with($file, '.sql.gz.gpg'))) {
        continue;
      }

      $timestamp = $this->extractTimestamp($file);
      if ($timestamp === null) {
        continue;
      }

      $backups[$path] = [
        'path' => $path,
        'filename' => $file,
        'timestamp' => $timestamp,
        'status' => $this->getFilenameStatus($file),
        'size' => filesize($path),
        'mtime' => filemtime($path),
      ];
    }

    return $backups;
  }

  /**
   * Get state key for a protection tier.
   *
   * @param string $tier
   *   Tier: 'monthly', '6month', 'yearly'.
   * @return string
   *   State key.
   */
  protected function getStateKey($tier) {
    return "retention_database_backup.{$tier}_backup_selected";
  }

  /**
   * Get the selected (explicitly marked) backup timestamp for a tier.
   *
   * @param string $tier
   *   Tier: 'monthly', '6month', 'yearly'.
   * @return int|null
   *   Unix timestamp of selected backup, or NULL if none selected.
   */
  protected function getSelectedBackupForTier($tier) {
    $state_key = $this->getStateKey($tier);
    return \Drupal::state()->get($state_key);
  }

  /**
   * Mark a backup as the representative for a tier.
   *
   * @param string $tier
   *   Tier: 'monthly', '6month', 'yearly'.
   * @param int $timestamp
   *   Backup timestamp to mark.
   */
  protected function markBackupForTier($tier, $timestamp) {
    $state_key = $this->getStateKey($tier);
    \Drupal::state()->set($state_key, $timestamp);
    $this->logger->info('Marked backup (timestamp: @ts) for @tier tier', [
      '@ts' => $timestamp,
      '@tier' => $tier,
    ]);
  }

  /**
   * Unmark a backup from a tier.
   *
   * @param string $tier
   *   Tier: 'monthly', '6month', 'yearly'.
   */
  protected function unmarkBackupForTier($tier) {
    $state_key = $this->getStateKey($tier);
    \Drupal::state()->delete($state_key);
  }

  /**
   * Find tier-representative backup using gap detection.
   *
   * Scans backup timeline for gaps. The backup before a gap becomes the tier representative.
   *
   * @param array $backups
   *   All backups sorted by timestamp (newest first).
   * @param string $tier
   *   Tier: 'monthly' (30 days), '6month' (180), 'yearly' (365).
   * @return array|null
   *   Backup array matching tier, or NULL if none found.
   */
  protected function detectGapBackup($backups, $tier) {
    if (empty($backups)) {
      return null;
    }

    $now = $this->getCurrentTime();
    $gap_thresholds = [
      'monthly' => [25 * 86400, 30 * 86400],     // 25-30 days
      '6month' => [150 * 86400, 180 * 86400],    // 150-180 days
      'yearly' => [330 * 86400, 365 * 86400],    // 330-365 days
    ];

    if (!isset($gap_thresholds[$tier])) {
      return null;
    }

    [$min_gap, $max_gap] = $gap_thresholds[$tier];

    // Sort by timestamp (oldest first for gap scan)
    $sorted = $backups;
    usort($sorted, function ($a, $b) {
      return $a['timestamp'] <=> $b['timestamp'];
    });

    // Look for a backup that's within the gap window
    foreach ($sorted as $backup) {
      $age = $now - $backup['timestamp'];
      if ($age >= $min_gap && $age <= $max_gap) {
        return $backup;
      }
    }

    // If no gap found, return the oldest backup (fallback)
    return end($sorted);
  }

  /**
   * Apply retention policy: rename files, update state, delete old backups.
   *
   * Strategy:
   * - Always keep: newest backup, at least one 24+ hours old
   * - 1-month tier: Keep 1 backup 25-30 days old (explicit or gap-detected)
   * - 6-month tier: Keep 1 backup 150-180 days old (explicit or gap-detected)
   * - 1-year tier: Keep 1 backup 330-365 days old (explicit or gap-detected)
   * - Delete: All single backups >3 days old that don't fit above rules
   *
   * @return array
   *   Array of deleted file paths.
   */
  public function applyRetentionPolicy() {
    $deleted_files = [];
    $backups = $this->getAllBackups();

    if (count($backups) < 2) {
      // Keep the last backup always, no cleanup needed
      return $deleted_files;
    }

    $now = $this->getCurrentTime();
    $three_days_ago = $now - (3 * 86400);

    // Sort by timestamp: newest first
    usort($backups, function ($a, $b) {
      $cmp = $b['timestamp'] <=> $a['timestamp'];
      // Log for debugging
      if ($cmp !== 0) {
        \Drupal::logger('retention_database_backup')->debug(
          'Comparing @file_a (@time_a) vs @file_b (@time_b): @cmp',
          [
            '@file_a' => basename($a['filename']),
            '@time_a' => date('Y-m-d H:i:s', $a['timestamp']),
            '@file_b' => basename($b['filename']),
            '@time_b' => date('Y-m-d H:i:s', $b['timestamp']),
            '@cmp' => $cmp > 0 ? 'A newer' : 'B newer',
          ]
        );
      }
      return $cmp;
    });

    // Identify backups to protect
    $protected_timestamps = [];

    // 1. Always protect the newest backup
    $newest = reset($backups);
    if ($newest) {
      $protected_timestamps[] = $newest['timestamp'];
      $this->logger->info('Newest backup identified: @file (timestamp: @ts)', [
        '@file' => basename($newest['filename']),
        '@ts' => date('Y-m-d H:i:s', $newest['timestamp']),
      ]);

      // First, remove [LAST] prefix from any existing backups
      foreach ($backups as $backup) {
        if ($this->getFilenameStatus($backup['filename']) === 'LAST') {
          $this->renameBackupStatus($backup, null);
        }
      }

      // Then add [LAST] prefix to the newest backup
      $this->renameBackupStatus($newest, 'LAST');
    }

    // 2. Protect at least one backup 24+ hours old
    $old_backup = null;
    foreach ($backups as $backup) {
      if (($now - $backup['timestamp']) >= 86400) {
        $old_backup = $backup;
        $protected_timestamps[] = $backup['timestamp'];
        break;
      }
    }

    // 3. Protect tier representatives
    $tiers = ['monthly' => ['monthly', 'MONTHLY'], '6month' => ['6month', '6MONTH'], 'yearly' => ['yearly', 'YEARLY']];
    foreach ($tiers as $tier_key => [$state_tier, $status_name]) {
      $selected = $this->getSelectedBackupForTier($state_tier);
      $tier_backup = null;

      if ($selected !== null) {
        // Use explicitly marked backup
        foreach ($backups as $backup) {
          if ($backup['timestamp'] === $selected) {
            $tier_backup = $backup;
            break;
          }
        }
      }

      if (!$tier_backup) {
        // Use gap detection
        $tier_backup = $this->detectGapBackup($backups, $state_tier);
      }

      if ($tier_backup && !in_array($tier_backup['timestamp'], $protected_timestamps)) {
        $protected_timestamps[] = $tier_backup['timestamp'];
        $this->renameBackupStatus($tier_backup, $status_name);

        // Update state with this backup's timestamp
        $this->markBackupForTier($state_tier, $tier_backup['timestamp']);
      }
    }

    // 4. Remove old prefixes from unprotected backups >3 days old
    foreach ($backups as $backup) {
      if (!in_array($backup['timestamp'], $protected_timestamps) && $backup['timestamp'] < $three_days_ago) {
        if ($backup['status']) {
          $this->renameBackupStatus($backup, null);
        }
      }
    }

    // 5. Delete unprotected backups >3 days old
    foreach ($backups as $backup) {
      if (!in_array($backup['timestamp'], $protected_timestamps) && $backup['timestamp'] < $three_days_ago) {
        if (file_exists($backup['path']) && @unlink($backup['path'])) {
          $deleted_files[] = $backup['path'];
          $this->logger->info('Deleted backup: @file', [
            '@file' => basename($backup['path']),
          ]);
        }
      }
    }

    return $deleted_files;
  }

  /**
   * Rename a backup file to update its status prefix.
   *
   * @param array $backup
   *   Backup info array with 'path' and 'filename'.
   * @param string|null $new_status
   *   New status ('LAST', 'MONTHLY', '6MONTH', 'YEARLY', null to remove prefix).
   * @return bool
   *   TRUE if renamed, FALSE otherwise.
   */
  protected function renameBackupStatus($backup, $new_status) {
    $old_path = $backup['path'];
    $old_filename = $backup['filename'];
    $current_status = $this->getFilenameStatus($old_filename);

    // Only rename if status changed
    if ($current_status === $new_status) {
      return true;
    }

    $new_filename = $this->addStatusPrefix($this->removeStatusPrefix($old_filename), $new_status);
    $new_path = dirname($old_path) . '/' . $new_filename;

    if (rename($old_path, $new_path)) {
      $this->logger->info('Renamed backup from @old to @new', [
        '@old' => $old_filename,
        '@new' => $new_filename,
      ]);
      return true;
    }

    $this->logger->error('Failed to rename backup @file', [
      '@file' => $old_filename,
    ]);
    return false;
  }

  /**
   * Get backup status information for admin UI.
   *
   * @return array
   *   Status information.
   */
  public function getBackupStatus() {
    $backups = $this->getAllBackups();
    $backup_dir = $this->getBackupDirectory();

    if (empty($backups)) {
      return [
        'backup_count' => 0,
        'total_size' => 0,
        'latest_backup' => null,
        'oldest_backup' => null,
        'protected_backups' => [],
        'backup_dir' => $backup_dir,
        'dir_exists' => is_dir($backup_dir),
        'dir_writable' => is_writable($backup_dir),
      ];
    }

    usort($backups, function ($a, $b) {
      return $b['timestamp'] <=> $a['timestamp'];
    });

    $total_size = array_reduce($backups, function ($sum, $b) {
      return $sum + $b['size'];
    }, 0);

    $protected = array_filter($backups, function ($b) {
      return !empty($b['status']);
    });

    return [
      'backup_count' => count($backups),
      'total_size' => $total_size,
      'latest_backup' => reset($backups),
      'oldest_backup' => end($backups),
      'protected_backups' => $protected,
      'backup_dir' => $backup_dir,
      'dir_exists' => is_dir($backup_dir),
      'dir_writable' => is_writable($backup_dir),
    ];
  }

}
