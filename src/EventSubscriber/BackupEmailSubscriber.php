<?php

namespace Drupal\retention_database_backup\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\File;

/**
 * Event subscriber to handle backup email attachments.
 */
class BackupEmailSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'symfony_mailer.pre_send' => 'preSend',
    ];
  }

  /**
   * Handles pre-send event to add attachments.
   *
   * @param \Symfony\Component\Mime\Email $email
   *   The email being sent.
   */
  public function preSend($email) {
    // This event is handled by symfony_mailer module.
    // We need to intercept before the message is sent.
    if (!($email instanceof Email)) {
      return;
    }

    // Check if this is a retention_database_backup email.
    // The message params might contain attachment info.
    \Drupal::logger('retention_database_backup')->debug('BackupEmailSubscriber triggered');
  }

}
