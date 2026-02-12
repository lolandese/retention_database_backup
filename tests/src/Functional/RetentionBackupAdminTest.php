<?php

namespace Drupal\Tests\retention_database_backup\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Functional tests for retention database backup admin UI.
 *
 * @group retention_database_backup
 */
class RetentionBackupAdminTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['retention_database_backup', 'system'];

  /**
   * Admin user for testing.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create admin user with necessary permissions
    $this->adminUser = $this->drupalCreateUser([
      'administer site configuration',
      'administer retention database backup',
    ]);
  }

  /**
   * Test admin settings page access.
   */
  public function testSettingsPageAccess() {
    // Access denied for anonymous user
    $this->drupalGet('/admin/config/system/retention-database-backup');
    $this->assertSession()->statusCodeEquals(403);

    // Access allowed for admin user
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/system/retention-database-backup');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->titleContains('Retention Database Backup');
  }

  /**
   * Test backup status section visibility.
   */
  public function testBackupStatusSection() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/system/retention-database-backup');

    // Check for status section
    $this->assertSession()->elementExists('xpath', "//legend[contains(text(), 'Backup Status')]");
    $this->assertSession()->pageTextContains('Backups Count');
    $this->assertSession()->pageTextContains('Total Size');
  }

  /**
   * Test action buttons existence.
   */
  public function testActionButtons() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/system/retention-database-backup');

    // Check for buttons
    $this->assertSession()->buttonExists('Generate Backup Now');
    $this->assertSession()->buttonExists('Cleanup Backups Now');
  }

  /**
   * Test encryption settings visibility.
   */
  public function testEncryptionSettings() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/system/retention-database-backup');

    // Check encryption fieldset exists
    $this->assertSession()->elementExists('xpath', "//legend[contains(text(), 'Encryption Settings')]");
    $this->assertSession()->pageTextContains('Enable GPG Encryption');
    $this->assertSession()->pageTextContains('GPG Recipient Email or Key ID');
  }

  /**
   * Test retention policy reading section.
   */
  public function testRetentionPolicyReading() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/system/retention-database-backup');

    // Check retention policy section
    $this->assertSession()->elementExists('xpath', "//legend[contains(text(), 'Retention Policy Reading')]");
    $this->assertSession()->pageTextContains('Tier 1 (2-Day Daily)');
    $this->assertSession()->pageTextContains('Tier 2 (1-Month)');
    $this->assertSession()->pageTextContains('Tier 3 (6-Month)');
    $this->assertSession()->pageTextContains('Tier 4 (1-Year)');
  }

  /**
   * Test email settings conditional display.
   */
  public function testEmailSettingsConditional() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/system/retention-database-backup');

    // Email recipients field should exist
    $this->assertSession()->fieldExists('email_recipients');
  }

  /**
   * Test form submission without backup creation.
   */
  public function testFormSubmission() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/system/retention-database-backup');

    // Update a simple field
    $edit = [
      'enable_automatic_backups' => 1,
    ];

    $this->submitForm($edit, 'Save configuration');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('The configuration options have been saved');
  }

  /**
   * Test status report shows module status.
   */
  public function testStatusReport() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/reports/status');

    // Module status should appear in status report
    $this->assertSession()->pageTextContains('Retention Database Backup');
  }

}
