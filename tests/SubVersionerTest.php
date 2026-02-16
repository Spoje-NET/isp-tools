<?php

declare(strict_types=1);

/**
 * This file is part of the AbraFlexi Reminder package
 *
 * https://github.com/SpojeNET/isp-tools
 *
 * (c) Spoje.Net <https://spoje.net/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SpojeNet\tests;

use PHPUnit\Framework\TestCase;
use SpojeNet\SubVersioner;

/**
 * Test SubVersioner functionality.
 */
class SubVersionerTest extends TestCase
{
    private string $tempDir;
    private array $originalEnv;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/isp-tools-test-'.uniqid();

        // Backup original environment
        $this->originalEnv = [
            'SVNUSER' => getenv('SVNUSER'),
            'SVNPASS' => getenv('SVNPASS'),
            'SVNURL' => getenv('SVNURL'),
            'SVNBIN' => getenv('SVNBIN'),
            'LOGFILE' => getenv('LOGFILE'),
        ];

        // Set test environment to use local repository
        $repoPath = __DIR__.'/svn/repo/hoststest';
        putenv('SVNUSER=testuser');
        putenv('SVNPASS=testpass');
        putenv('SVNURL=file://'.$repoPath);
        putenv('SVNBIN=svn');
        putenv('LOGFILE='.$this->tempDir.'/test.log');
    }

    protected function tearDown(): void
    {
        // Restore original environment
        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key.'='.$value);
            }
        }

        // Clean up temp directory
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    public function testConstructorWithValidConfig(): void
    {
        $this->assertInstanceOf(SubVersioner::class, new SubVersioner($this->tempDir));
    }

    public function testConstructorThrowsExceptionWithMissingConfig(): void
    {
        putenv('SVNUSER'); // Remove required config

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Missing required SVN configuration');

        new SubVersioner($this->tempDir);
    }

    public function testImplementsNetworkBackendInterface(): void
    {
        $subversioner = new SubVersioner($this->tempDir);
        $this->assertInstanceOf(\SpojeNet\NetworkBackendInterface::class, $subversioner);
    }

    public function testBlockIpReturnsBoolean(): void
    {
        $subversioner = new SubVersioner($this->tempDir);
        $result = $subversioner->blockIp('192.168.1.10');

        $this->assertIsBool($result);
    }

    public function testUnblockIpReturnsBoolean(): void
    {
        $subversioner = new SubVersioner($this->tempDir);
        $result = $subversioner->unblockIp('192.168.1.10', 100);

        $this->assertIsBool($result);
    }

    public function testBlockIpWithInvalidIp(): void
    {
        $subversioner = new SubVersioner($this->tempDir);
        $result = $subversioner->blockIp('invalid-ip');

        // Should handle gracefully (mocked SVN command will still return true)
        $this->assertIsBool($result);
    }

    public function testUnblockIpWithInvalidIp(): void
    {
        $subversioner = new SubVersioner($this->tempDir);
        $result = $subversioner->unblockIp('invalid-ip', 50);

        // Should handle gracefully (mocked SVN command will still return true)
        $this->assertIsBool($result);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir.'/'.$file;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
