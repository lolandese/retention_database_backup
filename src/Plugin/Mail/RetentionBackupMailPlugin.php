<?php

namespace Drupal\retention_database_backup\Plugin\Mail;

use Drupal\symfony_mailer\Address;
use Drupal\symfony_mailer\Plugin\Mail\SymfonyMailerPluginBase;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;

/**
 * Defines a mail plugin for retention database backup emails with attachments.
 *
 * @Mail(
 *   id = "retention_database_backup",
 *   label = @Translation("Retention Database Backup"),
 *   description = @Translation("Sends retention database backup notifications with GPG-encrypted attachments.")
 * )
 */
class RetentionBackupMailPlugin extends SymfonyMailerPluginBase {

  /**
   * {@inheritdoc}
   */
  public function mail(array $message): bool {
    $email = $this->getEmail($message);

    // Get the message body.
    $body = is_array($message['body']) ? implode("\n\n", $message['body']) : $message['body'];
    $email->text($body);

    // Handle attachments.
    if (isset($message['params']['attachments']) && is_array($message['params']['attachments'])) {
      foreach ($message['params']['attachments'] as $attachment) {
        $filepath = $attachment['filepath'] ?? NULL;
        $filename = $attachment['filename'] ?? NULL;

        if ($filepath && file_exists($filepath) && $filename) {
          try {
            $file = new File($filepath);
            $email->attach($file, $filename);
            \Drupal::logger('retention_database_backup')->info(
              'Attachment added to email: @file',
              ['@file' => $filename]
            );
          }
          catch (\Exception $e) {
            \Drupal::logger('retention_database_backup')->error(
              'Failed to attach file @file: @error',
              ['@file' => $filename, '@error' => $e->getMessage()]
            );
          }
        }
      }
    }

    // Support legacy single attachment parameter.
    if (isset($message['params']['attachment'])) {
      $filepath = $message['params']['attachment'];
      if (file_exists($filepath)) {
        try {
          $file = new File($filepath);
          $email->attach($file, basename($filepath));
          \Drupal::logger('retention_database_backup')->info(
            'Legacy attachment added to email: @file',
            ['@file' => basename($filepath)]
          );
        }
        catch (\Exception $e) {
          \Drupal::logger('retention_database_backup')->error(
            'Failed to attach legacy file @file: @error',
            ['@file' => $filepath, '@error' => $e->getMessage()]
          );
        }
      }
    }

    return parent::mail($message);
  }

}
