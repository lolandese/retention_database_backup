<?php

namespace Drupal\retention_database_backup\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Dashboard form displaying backup status and download options.
 */
class DashboardForm extends FormBase {

  /**
   * The backup manager service.
   *
   * @var \Drupal\retention_database_backup\BackupManager
   */
  protected $backupManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = new static();
    $instance->backupManager = $container->get('retention_database_backup.backup_manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'retention_database_backup_dashboard_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('retention_database_backup.settings');
    $backup_manager = $this->backupManager;

    $backup_dir = $backup_manager->getBackupDirectory();

    // Get backup status using public method
    $status = $backup_manager->getBackupStatus();
    $backup_count = $status['backup_count'];
    $total_size = $status['total_size'];
    $protected_backups = $status['protected_backups'];

    // Get all backup files for display
    $all_backups = [];
    if (is_dir($backup_dir)) {
      $files = array_diff(scandir($backup_dir), ['.', '..']);
      foreach ($files as $file) {
        $path = $backup_dir . '/' . $file;
        if (is_file($path) && (strpos($file, '.sql.gz') !== FALSE)) {
          $all_backups[] = $path;
        }
      }
    }

    // Sort backups from newest to oldest by modification time
    usort($all_backups, function($a, $b) {
      return filemtime($b) <=> filemtime($a);
    });

    // Summary section
    $form['summary'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Backup Summary'),
      '#attributes' => ['class' => ['backup-summary']],
    ];

    $form['summary']['stats'] = [
      '#markup' => '<div class="backup-stats">' .
        '<div><strong>Total Backups:</strong> ' . $backup_count . '</div>' .
        '<div><strong>Total Size:</strong> ' . $this->formatBytes($total_size) . '</div>' .
        '</div>',
    ];

    // Download section with protected tiers
    $form['downloads'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Download Backups'),
      '#attributes' => ['class' => ['backup-downloads']],
    ];

    // Get protected backup markers
    $protected_markers = [
      'LAST' => $this->t('Latest Backup (LAST)'),
      'MONTHLY' => $this->t('Monthly Backup (MONTHLY)'),
      'HALF_YEAR' => $this->t('Half-Yearly Backup (HALF_YEAR)'),
      'YEARLY' => $this->t('Yearly Backup (YEARLY)'),
    ];

    $downloads_found = FALSE;

    foreach ($protected_markers as $marker => $label) {
      // Look for both unencrypted and encrypted versions
      $encrypted_file = NULL;
      $unencrypted_file = NULL;

      foreach ($all_backups as $backup) {
        $filename = basename($backup);
        if (strpos($filename, '[' . $marker . ']') === 0) {
          if (strpos($filename, '.gpg') !== FALSE) {
            $encrypted_file = $filename;
          } else {
            $unencrypted_file = $filename;
          }
        }
      }

      // Prefer encrypted version if it exists
      $file_to_download = $encrypted_file ?? $unencrypted_file;

      if ($file_to_download) {
        $downloads_found = TRUE;
        $full_path = $backup_dir . '/' . $file_to_download;
        $file_size = filesize($full_path);
        $file_timestamp = filemtime($full_path);
        $time_ago = \Drupal::service('date.formatter')->formatTimeDiffSince($file_timestamp, ['granularity' => 2]) . ' ' . $this->t('ago');
        $download_url = Url::fromRoute('retention_database_backup.download', ['filename' => $file_to_download]);

        $form['downloads'][$marker] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['backup-row']],
        ];

        $form['downloads'][$marker]['info'] = [
          '#markup' => '<div class="backup-info">' .
            '<strong>' . $label . '</strong><br>' .
            '<small>' . $this->t('@age, @size', [
              '@age' => $time_ago,
              '@size' => $this->formatBytes($file_size),
            ]) . '</small></div>',
        ];

        $form['downloads'][$marker]['file'] = [
          '#markup' => '<div class="backup-file"><code>' . $file_to_download . '</code>' .
            ($encrypted_file ? ' <span class="badge">GPG encrypted</span>' : '') . '</div>',
        ];

        $form['downloads'][$marker]['button'] = [
          '#type' => 'link',
          '#title' => $this->t('Download'),
          '#url' => $download_url,
          '#attributes' => [
            'class' => ['button', 'button-primary'],
            'download' => $file_to_download,
          ],
        ];
      }
    }

    if (!$downloads_found) {
      $form['downloads']['empty'] = [
        '#markup' => '<p><em>' . $this->t('No protected backup tiers found yet. Backups will be organized into tiers automatically.') . '</em></p>',
      ];
    }

    // All backups section
    if (!empty($all_backups)) {
      $form['all_backups'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('All Backups'),
        '#collapsible' => TRUE,
        '#collapsed' => TRUE,
      ];

      $backup_table = [
        '#type' => 'table',
        '#header' => [
          'name' => $this->t('Filename'),
          'size' => $this->t('Size'),
          'age' => $this->t('Age'),
          'actions' => $this->t('Actions'),
        ],
      ];

      // Get list of protected backups (those with tier markers)
      $protected_backups = [];
      foreach ($all_backups as $backup) {
        $filename = basename($backup);
        if (preg_match('/^\[(LAST|MONTHLY|HALF_YEAR|YEARLY)\]/', $filename)) {
          $protected_backups[] = $filename;
        }
      }

      foreach ($all_backups as $backup) {
        $filename = basename($backup);
        $file_size = filesize($backup);
        $file_timestamp = filemtime($backup);
        $time_ago = \Drupal::service('date.formatter')->formatTimeDiffSince($file_timestamp, ['granularity' => 2]) . ' ' . $this->t('ago');
        $download_url = Url::fromRoute('retention_database_backup.download', ['filename' => $filename]);

        // Check if this is a protected backup
        $is_protected = in_array($filename, $protected_backups);

        $row = [
          'name' => [
            '#markup' => '<code>' . $filename . '</code>',
          ],
          'size' => [
            '#markup' => $this->formatBytes($file_size),
          ],
          'age' => [
            '#markup' => $time_ago,
          ],
        ];

        // Create dropbutton for actions
        if ($is_protected) {
          // Protected backups: only download action
          $row['actions'] = [
            '#type' => 'dropbutton',
            '#links' => [
              'download' => [
                'title' => $this->t('Download'),
                'url' => $download_url,
              ],
            ],
          ];
        } else {
          // Unprotected backups: download and delete actions
          $delete_url = Url::fromRoute('retention_database_backup.delete', ['filename' => $filename]);
          $row['actions'] = [
            '#type' => 'dropbutton',
            '#links' => [
              'download' => [
                'title' => $this->t('Download'),
                'url' => $download_url,
              ],
              'delete' => [
                'title' => $this->t('Delete'),
                'url' => $delete_url,
                'attributes' => [
                  'onclick' => "return confirm('" . addslashes($this->t('Are you sure you want to delete @filename?', ['@filename' => $filename])) . "');",
                ],
              ],
            ],
          ];
        }

        $backup_table[] = $row;
      }

      $form['all_backups']['backups'] = $backup_table;
    }

    // Action buttons - use proper actions container
    $form['#action'] = \Drupal::request()->getRequestUri();

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['backup_now'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate Backup Now'),
      '#button_type' => 'primary',
      '#submit' => [[$this, 'submitGenerateBackup']],
    ];

    $form['actions']['settings'] = [
      '#type' => 'link',
      '#title' => $this->t('Settings'),
      '#url' => Url::fromRoute('retention_database_backup.settings'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Form doesn't save anything by default
  }

  /**
   * Submit handler for generating backup.
   */
  public function submitGenerateBackup(array &$form, FormStateInterface $form_state) {
    // Log that handler was triggered
    \Drupal::logger('retention_database_backup')->info('Generate backup button clicked');

    try {
      $config = \Drupal::config('retention_database_backup.settings');

      // Use atomic method: creates backup AND applies retention in one call
      $result = $this->backupManager->createBackupAndApplyRetention();
      $backup_file = $result['backup_file'];
      $deleted_files = $result['deleted_files'];

      if ($backup_file) {
        $this->messenger()->addStatus($this->t('Backup created successfully: @file',
          ['@file' => basename($backup_file)]));

        if (!empty($deleted_files)) {
          $this->messenger()->addStatus($this->t('Cleanup: Deleted @count old backup(s).',
            ['@count' => count($deleted_files)]));
        }

        // Send email notifications if recipients are configured
        $email_recipients = $config->get('email_recipients');
        if (!empty($email_recipients)) {
          try {
            $this->backupManager->sendBackupEmail($backup_file);
            $this->messenger()->addStatus($this->t('Backup notification emails sent to configured recipients.'));
          }
          catch (\Exception $e) {
            $this->messenger()->addWarning($this->t('Backup created, but failed to send notification emails: @error',
              ['@error' => $e->getMessage()]));
            $this->logger('retention_database_backup')->warning('Failed to send backup email: @error',
              ['@error' => $e->getMessage()]);
          }
        }

        // Sync to install folder if enabled
        if ($config->get('enable_install_folder_sync')) {
          try {
            $this->backupManager->syncToInstallFolder($backup_file);
            $this->messenger()->addStatus($this->t('Backup synced to install folder.'));
          }
          catch (\Exception $e) {
            $this->messenger()->addWarning($this->t('Backup created, but failed to sync to install folder: @error',
              ['@error' => $e->getMessage()]));
            $this->logger('retention_database_backup')->warning('Failed to sync backup to install folder: @error',
              ['@error' => $e->getMessage()]);
          }
        }

        // Redirect to prevent form resubmission on refresh (POST-Redirect-GET pattern)
        $form_state->setRedirect('retention_database_backup.dashboard');
      } else {
        $this->messenger()->addError($this->t('Failed to create backup.'));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error: @message', ['@message' => $e->getMessage()]));
      $this->logger('retention_database_backup')->error('Dashboard backup error: @error',
        ['@error' => $e->getMessage()]);
    }
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

  /**
   * Config factory.
   */
  protected function config($name) {
    return \Drupal::config($name);
  }

  /**
   * Logger.
   */
  protected function logger($channel) {
    return \Drupal::logger($channel);
  }

}
