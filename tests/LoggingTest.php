<?php
namespace LoggingTesting;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
class LoggingTest extends TestCase {
    private string $logDir;
    private string $logFile;

    protected function setUp(): void {
        if (defined('WP_CONTENT_DIR') && is_dir(WP_CONTENT_DIR)) {
            foreach (glob(WP_CONTENT_DIR . '/*') ?: [] as $file) {
                is_dir($file) ? $this->rrmdir($file) : unlink($file);
            }
        } else {
            mkdir(WP_CONTENT_DIR, 0777, true);
        }

        if ( ! defined( 'EFORM_LOG_FILE_MAX_SIZE' ) ) {
            define( 'EFORM_LOG_FILE_MAX_SIZE', 200 );
        }
        if ( ! defined( 'EFORM_LOG_RETENTION_DAYS' ) ) {
            define( 'EFORM_LOG_RETENTION_DAYS', 1 );
        }
        $this->logDir = WP_CONTENT_DIR . '/uploads/logs';
        $this->logFile = $this->logDir . '/forms.log';
    }

    protected function tearDown(): void {
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
        foreach (glob($this->logDir . '/forms-*.log') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->logDir)) {
            rmdir($this->logDir);
        }
        if (is_dir(WP_CONTENT_DIR . '/uploads')) {
            rmdir(WP_CONTENT_DIR . '/uploads');
        }
        if (is_dir(WP_CONTENT_DIR)) {
            $this->rrmdir(WP_CONTENT_DIR);
        }
    }

    public function test_prepare_log_file_creates_log_file(): void {
        $logger = new \Logging();
        $ref    = new \ReflectionClass($logger);
        $method = $ref->getMethod('prepare_log_file');
        $method->setAccessible(true);
        $method->invoke($logger);
        $this->assertFileExists($this->logFile);
    }

    public function test_format_context_sanitizes_data(): void {
        $_SERVER['REQUEST_URI'] = '/test';
        $logger = new \Logging();
        $ref    = new \ReflectionClass($logger);
        $method = $ref->getMethod('format_context');
        $method->setAccessible(true);
        $context = $method->invoke($logger, 'msg', \Logging::LEVEL_WARNING, ['template' => '<b>tpl</b>'], ['name' => 'John']);
        $this->assertSame('tpl', $context['template']);
        $this->assertSame('msg', $context['message']);
        $this->assertSame(\Logging::LEVEL_WARNING, $context['level']);
        $this->assertSame('/test', $context['request_uri']);
        $this->assertArrayHasKey('timestamp', $context);
    }

    public function test_write_log_entry_does_not_escape_slashes(): void {
        $logger = new \Logging();
        $ref    = new \ReflectionClass($logger);
        $prep   = $ref->getMethod('prepare_log_file');
        $prep->setAccessible(true);
        $prep->invoke($logger);
        $write = $ref->getMethod('write_log_entry');
        $write->setAccessible(true);
        $write->invoke($logger, ['url' => 'http://example.com/foo']);
        $contents = trim(file_get_contents($this->logFile));
        $this->assertStringContainsString('http://example.com/foo', $contents);
        $this->assertStringNotContainsString('\\/', $contents);
        $this->assertStringNotContainsString('  ', $contents);
    }

    public function test_rotation_when_size_exceeded(): void {
        $logger = new \Logging();
        $message = str_repeat('a', 300);
        $logger->log($message);
        $this->assertFileExists($this->logFile);
        clearstatcache();
        $this->assertGreaterThan(200, filesize($this->logFile));
        $this->assertCount(0, glob($this->logDir . '/forms-*.log'));

        $logger->log('second');
        $rotated = glob($this->logDir . '/forms-*.log');
        $this->assertNotEmpty($rotated, 'Log file should rotate after exceeding size limit');
    }

    public function test_old_rotated_logs_are_deleted(): void {
        $logger = new \Logging();
        $message = str_repeat('a', 300);

        // Initial rotation to create first rotated file.
        $logger->log($message);
        $logger->log('second');
        $rotated = glob($this->logDir . '/forms-*.log');
        $this->assertCount(1, $rotated);

        // Age the rotated file beyond retention.
        $oldFile = $rotated[0];
        touch($oldFile, time() - 2 * 86400);

        // Ensure subsequent rotation has a different timestamp.
        sleep(2);

        // Trigger another rotation and cleanup.
        $logger->log($message);
        $logger->log('fourth');

        $rotatedAfter = glob($this->logDir . '/forms-*.log');
        $this->assertCount(1, $rotatedAfter, 'Old rotated log should be removed after retention period');
        $this->assertNotEquals($oldFile, $rotatedAfter[0], 'Old rotated log should be deleted');
    }

    public function test_logs_request_uri_and_template(): void {
        $_SERVER['REQUEST_URI'] = '/sample-page';
        $logger = new \Logging();
        $logger->log('hi', \Logging::LEVEL_INFO, ['template' => 'contact']);
        $contents = file_get_contents($this->logFile);
        $this->assertNotEmpty($contents);
        $entry = json_decode($contents, true);
        $this->assertSame('/sample-page', $entry['request_uri']);
        $this->assertSame('contact', $entry['template']);
    }

    private function rrmdir(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
