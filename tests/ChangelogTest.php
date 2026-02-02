<?php

declare(strict_types=1);

namespace Claude\AgentSdk\Tests;

use PHPUnit\Framework\TestCase;

final class ChangelogTest extends TestCase {

  public function testChangelogExists(): void {
    $this->assertFileExists(__DIR__ . '/../CHANGELOG.md');
  }

  public function testChangelogStartsWithHeader(): void {
    $content = file_get_contents(__DIR__ . '/../CHANGELOG.md');
    $this->assertIsString($content);
    $this->assertStringStartsWith('# Changelog', $content);
  }

  public function testChangelogHasValidVersionFormat(): void {
    $content = file_get_contents(__DIR__ . '/../CHANGELOG.md');
    $lines = explode("\n", $content ?: '');
    $pattern = '/^## \d+\.\d+\.\d+(?:\s+\(\d{4}-\d{2}-\d{2}\))?$/';

    $versions = [];
    foreach ($lines as $line) {
      if (str_starts_with($line, '## ')) {
        $this->assertMatchesRegularExpression($pattern, $line);
        if (preg_match('/^## (\d+\.\d+\.\d+)/', $line, $matches)) {
          $versions[] = $matches[1];
        }
      }
    }

    $this->assertNotEmpty($versions);
  }

  public function testChangelogHasBulletPoints(): void {
    $content = file_get_contents(__DIR__ . '/../CHANGELOG.md');
    $lines = explode("\n", $content ?: '');

    $inSection = false;
    $hasBullets = false;

    foreach ($lines as $line) {
      if (str_starts_with($line, '## ')) {
        if ($inSection) {
          $this->assertTrue($hasBullets);
        }
        $inSection = true;
        $hasBullets = false;
      }
      elseif ($inSection && str_starts_with($line, '- ')) {
        $hasBullets = true;
      }
    }

    if ($inSection) {
      $this->assertTrue($hasBullets);
    }
  }

  public function testChangelogVersionsDescending(): void {
    $content = file_get_contents(__DIR__ . '/../CHANGELOG.md');
    $lines = explode("\n", $content ?: '');

    $versions = [];
    foreach ($lines as $line) {
      if (str_starts_with($line, '## ')) {
        if (preg_match('/^## (\d+)\.(\d+)\.(\d+)/', $line, $matches)) {
          $versions[] = [(int) $matches[1], (int) $matches[2], (int) $matches[3]];
        }
      }
    }

    for ($i = 1; $i < count($versions); $i++) {
      $this->assertTrue($versions[$i - 1] > $versions[$i]);
    }

    if (count($versions) < 2) {
      $this->assertTrue(true);
    }
  }

  public function testChangelogNoEmptyBulletPoints(): void {
    $content = file_get_contents(__DIR__ . '/../CHANGELOG.md');
    $lines = explode("\n", $content ?: '');
    foreach ($lines as $line) {
      $this->assertNotSame('-', trim($line));
    }
  }

}
