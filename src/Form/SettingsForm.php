<?php

namespace Drupal\retention_database_backup\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure settings for Retention Database Backup module.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['retention_database_backup.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'retention_database_backup_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('retention_database_backup.settings');

    $form['backup_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Backup Settings'),
      '#collapsible' => FALSE,
    ];

    $form['backup_settings']['enable_automatic_backups'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Automatic Backups'),
      '#description' => $this->t('Enable automatic database backups via Drupal cron.'),
      '#default_value' => $config->get('enable_automatic_backups'),
    ];

    $form['email_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Email Recipients'),
      '#collapsible' => FALSE,
      '#description' => $this->t('Configured recipients will receive notification emails when database backups are created.'),
    ];

    $form['email_settings']['email_recipients'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Recipient Email Addresses'),
      '#description' => $this->t('Email addresses to receive backup notifications. Enter one email per line, or use comma-separated values. When new recipients are added, they will receive an automatic welcome notification email explaining why they were added and how to opt-out.'),
      '#default_value' => $config->get('email_recipients'),
      '#rows' => 3,
    ];

    $form['email_settings']['email_from_address'] = [
      '#type' => 'email',
      '#title' => $this->t('From Email Address'),
      '#description' => $this->t('Email address to use as the "From" header for backup notifications. This should be a real person\'s email address so that recipients can reply with opt-out requests. If empty, the system email will be used.'),
      '#default_value' => $config->get('email_from_address'),
    ];

    $form['encryption'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Encryption Settings'),
      '#collapsible' => FALSE,
      '#description' => $this->t('Encrypt backups to protect sensitive database contents. <strong>Required for install folder sync.</strong> When you enable encryption, a test will verify GPG is working (if install folder sync is also enabled).'),
    ];

    $form['encryption']['enable_encryption'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable GPG Encryption'),
      '#description' => $this->t('When enabled, backups will be encrypted with GPG for security. Required if you want to use install folder sync. Requires gpg command to be available on the server. See README.md "GPG Encryption Setup & Troubleshooting" section for setup instructions.'),
      '#default_value' => $config->get('enable_encryption'),
    ];

    $form['encryption']['gpg_recipient'] = [
      '#type' => 'textfield',
      '#title' => $this->t('GPG Recipient Email or Key ID'),
      '#description' => $this->t('Email address or GPG key ID (e.g., admin@example.com or ABC123DEF456). This key must exist in the server\'s GPG keyring. Check /admin/reports/status to verify GPG is properly configured. Need help? See README.md section "Configuring Drupal to Use GPG" or "GPG Encryption Troubleshooting".'),
      '#default_value' => $config->get('gpg_recipient'),
      '#states' => [
        'visible' => [
          ':input[name="enable_encryption"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['install_folder'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Install Folder Sync'),
      '#collapsible' => FALSE,
      '#description' => $this->t('⚠️ <strong>Requires GPG encryption to be enabled and working.</strong> Syncs encrypted backups to your project\'s install folder, allowing new developers to quickly set up their local environment. Encryption will be tested when you save this form.'),
    ];

    $form['install_folder']['enable_install_folder_sync'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Install Folder Sync'),
      '#description' => $this->t('When enabled, encrypted backups will be copied to the specified folder after each backup creation. You must have GPG encryption enabled and a valid GPG recipient configured.'),
      '#default_value' => $config->get('enable_install_folder_sync'),
      '#states' => [
        'disabled' => [
          ':input[name="enable_encryption"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $form['install_folder']['install_folder_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Install Folder Path'),
      '#description' => $this->t('Relative path from project root (e.g., "install" for the install/ folder). Backups will be saved as "latest-backup.sql.gz" in this folder. Leave empty to use default "install" folder.'),
      '#default_value' => $config->get('install_folder_path') ?: 'install',
      '#states' => [
        'visible' => [
          ':input[name="enable_install_folder_sync"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['retention_policy'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Retention Policy Reading'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
      '#description' => $this->t('The module uses a 4-tier retention strategy. Typically you will have about 4-10 backups maintained across these tiers.'),
    ];

    $form['retention_policy']['info'] = [
      '#markup' => '<dl>
        <dt><strong>Tier 1 (LAST)</strong></dt>
        <dd>The most recent backup from the last 48 hours. Always kept for quick recovery from recent mistakes.</dd>
        <dt><strong>Tier 2 (MONTHLY)</strong></dt>
        <dd>One representative backup from the past month. Good for rolling back recent site issues.</dd>
        <dt><strong>Tier 3 (HALF_YEAR)</strong></dt>
        <dd>One representative backup from the past 6 months. For recovering from issues discovered weeks later.</dd>
        <dt><strong>Tier 4 (YEARLY)</strong></dt>
        <dd>One representative backup from the past year. For recovering from very late-discovered issues or compliance/audit needs.</dd>
      </dl>',
    ];
    // Attach JavaScript and CSS to handle encryption/sync checkbox dependency
    $form['#attached']['library'][] = 'retention_database_backup/settings-form';
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $enable_sync = $form_state->getValue('enable_install_folder_sync');
    $enable_encryption = $form_state->getValue('enable_encryption');
    $gpg_recipient = $form_state->getValue('gpg_recipient');

    // If trying to enable install folder sync, require encryption
    if ($enable_sync && !$enable_encryption) {
      $form_state->setErrorByName('enable_install_folder_sync',
        $this->t('Install folder sync requires GPG encryption to be enabled for security. Unencrypted backups cannot be synced to the install folder.'));
      return;
    }

    // If enabling install folder sync with encryption, verify GPG works
    if ($enable_sync && $enable_encryption) {
      if (empty($gpg_recipient)) {
        $form_state->setErrorByName('gpg_recipient',
          $this->t('GPG recipient email/key ID is required when using install folder sync with encryption.'));
        return;
      }

      // Test encryption by creating a test file
      try {
        $backup_manager = \Drupal::service('retention_database_backup.backup_manager');

        // Create a temporary test file
        $test_content = 'GPG Encryption Test - ' . date('Y-m-d H:i:s') . "\n";
        $test_file = sys_get_temp_dir() . '/retention_db_backup_test_' . uniqid() . '.txt';
        file_put_contents($test_file, $test_content);

        try {
          // Test encryption
          $encrypted_file = $backup_manager->encryptFile($test_file, $gpg_recipient);

          if (empty($encrypted_file) || !file_exists($encrypted_file)) {
            throw new \Exception('Encryption returned empty or non-existent file');
          }

          // Clean up test files
          @unlink($test_file);
          @unlink($encrypted_file);

          $this->messenger()->addStatus($this->t('✓ GPG encryption verified successfully.'));
        }
        finally {
          // Ensure test file is cleaned up
          @unlink($test_file);
        }
      }
      catch (\Exception $e) {
        // Extract user-friendly error message from exception
        $user_message = $this->extractUserFriendlyGpgError($e->getMessage());

        $form_state->setErrorByName('enable_encryption', $user_message);

        // Log full error for debugging
        \Drupal::logger('retention_database_backup')->error('GPG encryption test failed: @error',
          ['@error' => $e->getMessage()]);

        // Revert the enable_encryption checkbox
        $form_state->setValue('enable_encryption', FALSE);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('retention_database_backup.settings');

    // Get old and new recipients to detect new ones.
    $old_recipients_raw = $config->get('email_recipients');
    $new_recipients_raw = $form_state->getValue('email_recipients');

    // Parse recipients.
    $old_recipients = array_filter(array_map('trim', explode(',', $old_recipients_raw)));
    $new_recipients = array_filter(array_map('trim', explode(',', $new_recipients_raw)));

    // Find newly added recipients.
    $newly_added = array_diff($new_recipients, $old_recipients);

    $config
      ->set('enable_automatic_backups', $form_state->getValue('enable_automatic_backups'))
      ->set('email_recipients', $form_state->getValue('email_recipients'))
      ->set('email_from_address', $form_state->getValue('email_from_address'))
      ->set('enable_encryption', $form_state->getValue('enable_encryption'))
      ->set('gpg_recipient', $form_state->getValue('gpg_recipient'))
      ->set('enable_install_folder_sync', $form_state->getValue('enable_install_folder_sync'))
      ->set('install_folder_path', $form_state->getValue('install_folder_path') ?: 'install')
      ->save();

    // Send notification to newly added recipients.
    if (!empty($newly_added)) {
      try {
        $backup_manager = \Drupal::service('retention_database_backup.backup_manager');
        $backup_manager->notifyNewRecipients($newly_added);
        $this->messenger()->addMessage($this->t('Configuration saved. Notification emails sent to @count new recipient(s).',
          ['@count' => count($newly_added)]));
      }
      catch (\Exception $e) {
        $this->messenger()->addWarning($this->t('Configuration saved, but failed to send notifications to new recipients: @error',
          ['@error' => $e->getMessage()]));
      }
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * Extract user-friendly error message from GPG exception.
   *
   * @param string $error_message
   *   The full exception message from encryptFile().
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   A user-friendly error message.
   */
  protected function extractUserFriendlyGpgError($error_message) {
    // Check for specific error patterns and provide targeted messages
    if (strpos($error_message, 'skipped: No data') !== FALSE) {
      return $this->t('GPG recipient key not found: The email address or key ID you entered does not exist in the GPG keyring.<br/><br/>Steps to fix:<ol><li>Verify the correct email address at /admin/config/system/retention-database-backup</li><li>Or generate a new GPG key using: <code>ddev exec gpg --batch --gen-key</code></li><li>See README.md "Configuring Drupal to Use GPG" for detailed setup instructions</li></ol>');
    }
    elseif (strpos($error_message, 'error retrieving') !== FALSE) {
      return $this->t('Failed to download GPG key from key server: The system tried to download your key but could not connect.<br/><br/>Steps to fix:<ol><li>Verify the GPG key is installed locally: <code>ddev exec gpg --list-keys your@email.com</code></li><li>If not installed, generate one or import it from your system</li><li>Or disable encryption and try again: /admin/config/system/retention-database-backup</li></ol>');
    }
    elseif (strpos($error_message, 'permission denied') !== FALSE) {
      return $this->t('Permission denied: Cannot write encrypted backup file. The db-backups directory may have permission issues or the disk might be full.<br/><br/>Check the backup directory permissions and disk space, then try again.');
    }
    elseif (strpos($error_message, 'GPG is not available') !== FALSE) {
      return $this->t('GPG is not installed on this system. Install it with:<br/><code>ddev exec apk add gnupg</code><br/>Then reload this page and try again. See README.md for more details.');
    }
    else {
      // Fallback for unexpected errors
      return $this->t('GPG encryption test failed. Please check your encryption settings and GPG configuration.<br/>See README.md "GPG Encryption Troubleshooting" for troubleshooting steps.');
    }
  }

  /**
   * Format bytes as human-readable string.
   *
   * @param int $bytes
   *   Number of bytes.
   * @return string
   *   Formatted string (e.g., "1.5 MB").
   */
  protected function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));

    return round($bytes, 2) . ' ' . $units[$pow];
  }

}
