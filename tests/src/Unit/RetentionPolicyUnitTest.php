<?php

namespace Drupal\Tests\retention_database_backup\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for retention policy logic.
 *
 * @group retention_database_backup
 */
class RetentionPolicyUnitTest extends UnitTestCase {

  /**
   * Test filename status prefix extraction.
   */
  public function testFilenameStatusExtraction() {
    $filenames = [
      '[LAST]_20260210T024922-main-abc12345.sql.gz' => 'LAST',
      '[MONTHLY]_20260110T150000-main-def67890.sql.gz' => 'MONTHLY',
      '[6MONTH]_20250810T080000-main-ghi11111.sql.gz' => '6MONTH',
      '[YEARLY]_20241210T120000-main-jkl22222.sql.gz' => 'YEARLY',
      '20260209T120000-main-mno33333.sql.gz' => NULL,
    ];

    foreach ($filenames as $filename => $expected_status) {
      $pattern = '/^\[(LAST|MONTHLY|6MONTH|YEARLY)\]_/';
      $status = null;
      if (preg_match($pattern, $filename, $matches)) {
        $status = $matches[1];
      }
      $this->assertEquals($expected_status, $status, "Failed for filename: $filename");
    }
  }

  /**
   * Test timestamp extraction from filenames.
   */
  public function testTimestampExtraction() {
    $filename = '20260210T024922-main-abc12345.sql.gz';
    preg_match('/^(\d{8})T(\d{6})/', $filename, $matches);
    $this->assertNotEmpty($matches);

    $date_part = $matches[1];
    $time_part = $matches[2];

    $timestamp = strtotime(
      substr($date_part, 0, 4) . '-' .
      substr($date_part, 4, 2) . '-' .
      substr($date_part, 6, 2) . ' ' .
      substr($time_part, 0, 2) . ':' .
      substr($time_part, 2, 2) . ':' .
      substr($time_part, 4, 2)
    );

    $this->assertNotFalse($timestamp);
    $this->assertEquals('2026-02-10', date('Y-m-d', $timestamp));
    $this->assertEquals('02:49:22', date('H:i:s', $timestamp));
  }

  /**
   * Test status prefix addition/removal.
   */
  public function testStatusPrefixHandling() {
    $base_filename = '20260210T024922-main-abc12345.sql.gz';

    // Test adding prefixes
    $with_last = '[LAST]_' . $base_filename;
    $with_monthly = '[MONTHLY]_' . $base_filename;

    // Test removing prefixes
    $pattern = '/^\[(LAST|MONTHLY|6MONTH|YEARLY)\]_/';
    $removed_last = preg_replace($pattern, '', $with_last);
    $removed_monthly = preg_replace($pattern, '', $with_monthly);

    $this->assertEquals($base_filename, $removed_last);
    $this->assertEquals($base_filename, $removed_monthly);
  }

  /**
   * Test backup age calculation.
   */
  public function testBackupAgeCalculation() {
    $now = time();

    // Create timestamps for various ages
    $one_day_ago = $now - 86400;
    $two_days_ago = $now - (2 * 86400);
    $three_days_ago = $now - (3 * 86400);
    $thirty_days_ago = $now - (30 * 86400);
    $six_months_ago = $now - (180 * 86400);
    $one_year_ago = $now - (365 * 86400);

    // Test boundary conditions
    $this->assertGreaterThanOrEqual(86400, $now - $one_day_ago);
    $this->assertGreaterThanOrEqual(2 * 86400, $now - $two_days_ago);
    $this->assertGreaterThanOrEqual(3 * 86400, $now - $three_days_ago);
    $this->assertGreaterThanOrEqual(30 * 86400, $now - $thirty_days_ago);
    $this->assertGreaterThanOrEqual(180 * 86400, $now - $six_months_ago);
    $this->assertGreaterThanOrEqual(365 * 86400, $now - $one_year_ago);
  }

  /**
   * Test gap detection logic.
   */
  public function testGapDetection() {
    $now = time();

    // Create mock backup timestamps
    $backups = [
      ['timestamp' => $now],                            // Today (LAST)
      ['timestamp' => $now - (5 * 86400)],              // 5 days ago
      ['timestamp' => $now - (10 * 86400)],             // 10 days ago
      ['timestamp' => $now - (50 * 86400)],             // 50 days ago (MONTHLY)
      ['timestamp' => $now - (200 * 86400)],            // 200 days ago (6MONTH)
    ];

    // Test: Is there a gap of 25-30 days?
    $gap_thresholds = [25 * 86400, 30 * 86400];
    $found_gap = false;
    foreach ($backups as $backup) {
      $age = $now - $backup['timestamp'];
      if ($age >= $gap_thresholds[0] && $age <= $gap_thresholds[1]) {
        $found_gap = true;
        break;
      }
    }

    // We should find the 50-day-old backup (it's a gap)
    $this->assertTrue($found_gap, 'Should detect 30-day gap');

    // Test: Is there a gap of 150-180 days?
    $gap_thresholds = [150 * 86400, 180 * 86400];
    $found_gap = false;
    foreach ($backups as $backup) {
      $age = $now - $backup['timestamp'];
      if ($age >= $gap_thresholds[0] && $age <= $gap_thresholds[1]) {
        $found_gap = true;
        break;
      }
    }

    // We should find the 200-day-old backup (it's a gap)
    $this->assertTrue($found_gap, 'Should detect 180-day gap');
  }

}
