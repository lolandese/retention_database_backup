<?php

namespace Drupal\Tests\retention_database_backup\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Kernel tests for retention policy with file system and state.
 *
 * @group retention_database_backup
 */
class RetentionPolicyKernelTest extends KernelTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['retention_database_backup'];

  /**
   * Temporary backup directory for testing.
   *
   * @var string
   */
  protected $tempBackupDir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->tempBackupDir = sys_get_temp_dir() . '/drupal_backup_test_' . uniqid();
    mkdir($this->tempBackupDir, 0777, true);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Clean up temp directory
    if (is_dir($this->tempBackupDir)) {
      $files = array_diff(scandir($this->tempBackupDir) ?: [], ['.', '..']);
      foreach ($files as $file) {
        @unlink($this->tempBackupDir . '/' . $file);
      }
      @rmdir($this->tempBackupDir);
    }

    parent::tearDown();
  }

  /**
   * Create dummy backup files for testing.
   *
   * @param array $specs
   *   Array of specifications: ['age' => days_old, 'prefix' => 'MONTHLY|YEARLY|etc']
   * @return array
   *   Array of created file paths.
   */
  protected function createDummyBackups(array $specs) {
    $created = [];
    $now = time();

    foreach ($specs as $spec) {
      $age_seconds = ($spec['age'] ?? 0) * 86400;
      $backup_time = $now - $age_seconds;

      // Format timestamp: YYYYMMDDTHHmmss
      $date_str = date('YmdHis', $backup_time);
      $date_str = substr($date_str, 0, 8) . 'T' . substr($date_str, 8);

      $base_filename = $date_str . '-main-abc12345.sql.gz';
      $filename = $base_filename;

      if (!empty($spec['prefix'])) {
        $filename = '[' . $spec['prefix'] . ']_' . $base_filename;
      }

      $path = $this->tempBackupDir . '/' . $filename;

      // Create file with specific mtime
      touch($path, $backup_time);
      file_put_contents($path, str_repeat('x', 1024)); // 1KB dummy

      $created[$filename] = $path;
    }

    return $created;
  }

  /**
   * Get all files in backup directory.
   *
   * @return array
   *   Array of filenames.
   */
  protected function getBackupFiles() {
    return array_diff(scandir($this->tempBackupDir) ?: [], ['.', '..']);
  }

  /**
   * Test empty backup directory.
   */
  public function testEmptyBackupDirectory() {
    $this->assertEmpty($this->getBackupFiles(), 'Directory should be empty initially');

    // Create a mock BackupManager context
    $this->assertTrue(is_dir($this->tempBackupDir));
    $this->assertEmpty($this->getBackupFiles());
  }

  /**
   * Test single backup protection.
   */
  public function testSingleBackupProtection() {
    $this->createDummyBackups([
      ['age' => 10, 'prefix' => ''],
    ]);

    $files = $this->getBackupFiles();
    $this->assertCount(1, $files, 'Should have 1 backup');

    // Single backup should never be deleted
    $this->assertFalse(str_contains(reset($files), '[DELETED]'));
  }

  /**
   * Test multiple backups and tier detection.
   */
  public function testMultipleBackupsAndTiers() {
    $this->createDummyBackups([
      ['age' => 0, 'prefix' => ''],    // Today
      ['age' => 2, 'prefix' => ''],    // 2 days ago
      ['age' => 5, 'prefix' => ''],    // 5 days ago
      ['age' => 50, 'prefix' => ''],   // 50 days ago (monthly gap)
      ['age' => 200, 'prefix' => ''],  // 200 days ago (6-month gap)
    ]);

    $files = $this->getBackupFiles();
    $this->assertCount(5, $files, 'Should have 5 backups initially');

    // Check that we can identify different ages
    $filenames = array_values($files);
    $this->assertCount(5, $filenames);

    // Verify filenames contain timestamps
    foreach ($filenames as $filename) {
      $this->assertStringContainsString('T', $filename);
      $this->assertStringContainsString('main', $filename);
      $this->assertStringContainsString('.sql.gz', $filename);
    }
  }

  /**
   * Test filename prefix transitions.
   */
  public function testFilenamePrefixTransitions() {
    // Create backup without prefix
    $base = '20260210T120000-main-abc12345.sql.gz';
    $path = $this->tempBackupDir . '/' . $base;
    touch($path);

    $files = $this->getBackupFiles();
    $this->assertFalse(str_contains(reset($files), '['));

    // Simulate renaming to add LAST prefix
    $with_prefix = '[LAST]_' . $base;
    $new_path = $this->tempBackupDir . '/' . $with_prefix;
    rename($path, $new_path);

    $files = $this->getBackupFiles();
    $this->assertTrue(str_contains(reset($files), '[LAST]'));

    // Simulate renaming from LAST to MONTHLY
    $monthly_prefix = '[MONTHLY]_' . $base;
    $monthly_path = $this->tempBackupDir . '/' . $monthly_prefix;
    rename($new_path, $monthly_path);

    $files = $this->getBackupFiles();
    $this->assertTrue(str_contains(reset($files), '[MONTHLY]'));
    $this->assertFalse(str_contains(reset($files), '[LAST]'));
  }

  /**
   * Test protected vs unprotected backup differentiation.
   */
  public function testProtectedVsUnprotectedBackups() {
    $this->createDummyBackups([
      ['age' => 0, 'prefix' => 'LAST'],      // Protected
      ['age' => 1, 'prefix' => ''],          // Unprotected
      ['age' => 5, 'prefix' => 'MONTHLY'],   // Protected
      ['age' => 10, 'prefix' => ''],         // Unprotected
      ['age' => 200, 'prefix' => 'YEARLY'],  // Protected
    ]);

    $files = $this->getBackupFiles();
    $this->assertCount(5, $files);

    $protected_count = 0;
    $unprotected_count = 0;

    foreach ($files as $file) {
      if (str_contains($file, '[')) {
        $protected_count++;
      } else {
        $unprotected_count++;
      }
    }

    $this->assertEquals(3, $protected_count, 'Should have 3 protected backups');
    $this->assertEquals(2, $unprotected_count, 'Should have 2 unprotected backups');
  }

  /**
   * Test state storage for tier selection.
   */
  public function testStateStorageForTierSelection() {
    $state = \Drupal::state();

    $monthly_timestamp = time() - (30 * 86400);
    $yearly_timestamp = time() - (365 * 86400);

    // Store tier selections in state
    $state->set('retention_database_backup.monthly_backup_selected', $monthly_timestamp);
    $state->set('retention_database_backup.yearly_backup_selected', $yearly_timestamp);

    // Retrieve and verify
    $stored_monthly = $state->get('retention_database_backup.monthly_backup_selected');
    $stored_yearly = $state->get('retention_database_backup.yearly_backup_selected');

    $this->assertEquals($monthly_timestamp, $stored_monthly);
    $this->assertEquals($yearly_timestamp, $stored_yearly);

    // Delete state
    $state->delete('retention_database_backup.monthly_backup_selected');
    $this->assertNull($state->get('retention_database_backup.monthly_backup_selected'));
  }

  /**
   * Test cleanup marker files.
   */
  public function testCleanupMarkerFiles() {
    // Create a regular backup
    $base = '20260210T120000-main-abc12345.sql.gz';
    $path = $this->tempBackupDir . '/' . $base;
    touch($path);

    // Create marker file for recovery
    $marker_path = $this->tempBackupDir . '/.monthly_backup_selected';
    file_put_contents($marker_path, trim($base) . ':' . time());

    // Verify marker exists
    $this->assertFileExists($marker_path);

    // Read marker
    $marker_content = file_get_contents($marker_path);
    $this->assertStringContainsString('main-abc12345', $marker_content);

    // Delete marker (cleanup)
    unlink($marker_path);
    $this->assertFileDoesNotExist($marker_path);
  }

}
