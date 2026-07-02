<?php

declare(strict_types=1);

/**
 * This file is part of the ISP Tools package
 *
 * https://github.com/Spoje-NET/isp-tools
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
 * Test SubVersioner functionality against a throw-away svn repository
 * created in the system temp directory (the checked-in fixture repo must
 * stay untouched so the git tree remains clean).
 */
class SubVersionerTest extends TestCase
{
    private string $tempBase;
    private string $workingDir;
    private array $originalEnv;

    protected function setUp(): void
    {
        $this->tempBase = sys_get_temp_dir().'/isp-tools-test-'.uniqid();
        $this->workingDir = $this->tempBase.'/wc';
        mkdir($this->tempBase, 0o755, true);

        // Backup original environment
        $this->originalEnv = [
            'SVNUSER' => getenv('SVNUSER'),
            'SVNPASS' => getenv('SVNPASS'),
            'SVNURL' => getenv('SVNURL'),
            'SVNBIN' => getenv('SVNBIN'),
            'LOGFILE' => getenv('LOGFILE'),
        ];

        putenv('SVNUSER=testuser');
        putenv('SVNPASS=testpass');
        putenv('SVNBIN=svn');
        putenv('LOGFILE='.$this->tempBase.'/test.log');
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
        if (is_dir($this->tempBase)) {
            $this->removeDirectory($this->tempBase);
        }
    }

    public function testConstructorThrowsExceptionWithMissingConfig(): void
    {
        putenv('SVNURL=file:///nonexistent');
        putenv('SVNUSER'); // Remove required config

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Missing required SVN configuration');

        new SubVersioner($this->workingDir);
    }

    public function testImplementsNetworkBackendInterface(): void
    {
        putenv('SVNURL=file:///nonexistent');
        $this->assertInstanceOf(\SpojeNet\NetworkBackendInterface::class, new SubVersioner($this->workingDir));
    }

    public function testBlockPreservesOriginalSpeedAndUnblockRestoresIt(): void
    {
        $subversioner = $this->makeSubVersioner();

        $this->assertTrue($subversioner->blockIp('10.0.0.1'));
        $blocked = $this->hostsLine('10.0.0.1');
        $this->assertStringContainsString('# speed=0 orig=512', $blocked);
        $this->assertStringContainsString('{code:TEST1}', $blocked);

        // fallback speed 100 must lose against the recorded orig=512
        $this->assertTrue($subversioner->unblockIp('10.0.0.1', 100));
        $unblocked = $this->hostsLine('10.0.0.1');
        $this->assertStringContainsString('speed=512', $unblocked);
        $this->assertStringNotContainsString('orig=', $unblocked);
    }

    public function testBlockAndUnblockLineWithoutPriorSpeed(): void
    {
        $subversioner = $this->makeSubVersioner();

        $this->assertTrue($subversioner->blockIp('10.0.0.2'));
        $blocked = $this->hostsLine('10.0.0.2');
        $this->assertStringContainsString('# speed=0', $blocked);
        $this->assertStringNotContainsString('orig=', $blocked);
        $this->assertStringContainsString('BREVNOVNETBASIC', $blocked);

        // no orig token and fallback 0 -> no speed token at all
        $this->assertTrue($subversioner->unblockIp('10.0.0.2', 0));
        $unblocked = $this->hostsLine('10.0.0.2');
        $this->assertStringNotContainsString('speed=', $unblocked);
        $this->assertStringContainsString('BREVNOVNETBASIC', $unblocked);
    }

    public function testBlockIsIdempotent(): void
    {
        $subversioner = $this->makeSubVersioner();

        $this->assertTrue($subversioner->blockIp('10.0.0.1'));
        $this->assertTrue($subversioner->blockIp('10.0.0.1'));

        $blocked = $this->hostsLine('10.0.0.1');
        $this->assertSame(1, substr_count($blocked, 'speed=0'));
        $this->assertStringContainsString('orig=512', $blocked);
    }

    public function testUnblockUsesFallbackSpeedWithoutOrigToken(): void
    {
        $subversioner = $this->makeSubVersioner();

        $this->assertTrue($subversioner->unblockIp('10.0.0.3', 200));
        $this->assertStringContainsString('speed=200', $this->hostsLine('10.0.0.3'));
    }

    public function testGetCustomerIPsPrefersExactCodeToken(): void
    {
        $subversioner = $this->makeSubVersioner();

        // 10.0.0.4 mentions TEST1 only as plain text; {code:TEST1} wins
        $this->assertSame(['10.0.0.1'], $subversioner->getCustomerIPs('TEST1'));
        // no {code:} token anywhere -> falls back to plain substring match
        $this->assertSame(['10.0.0.4'], $subversioner->getCustomerIPs('plaintextonly'));
        $this->assertSame([], $subversioner->getCustomerIPs('NOSUCHCODE'));
    }

    /**
     * Create a scratch svn repository with a known hosts file and return
     * a SubVersioner bound to it.
     */
    private function makeSubVersioner(): SubVersioner
    {
        $repoDir = $this->tempBase.'/repo';
        $importDir = $this->tempBase.'/import';
        mkdir($importDir, 0o755, true);

        $hosts = [
            '# test fixture',
            "10.0.0.1\thost1\t\t#speed=512 {code:TEST1}",
            "10.0.0.2\thost2\t\t#BREVNOVNETBASIC {code:TEST2}",
            "10.0.0.3\thost3\t\t#speed=0 {code:TEST3}",
            "10.0.0.4\thost4\t\t#plaintextonly TEST1 old note",
        ];
        file_put_contents($importDir.'/hosts', implode("\n", $hosts)."\n");

        exec('svnadmin create '.escapeshellarg($repoDir), $output, $exitCode);
        $this->assertSame(0, $exitCode, 'svnadmin create failed');
        exec(
            'svn import -q -m initial '.escapeshellarg($importDir).' file://'.$repoDir,
            $output,
            $exitCode,
        );
        $this->assertSame(0, $exitCode, 'svn import failed');

        putenv('SVNURL=file://'.$repoDir);

        return new SubVersioner($this->workingDir);
    }

    /**
     * Fetch the committed hosts line for given IP straight from the repository.
     */
    private function hostsLine(string $ip): string
    {
        exec('svn cat file://'.$this->tempBase.'/repo/hosts', $lines, $exitCode);
        $this->assertSame(0, $exitCode, 'svn cat failed');

        foreach ($lines as $line) {
            if (str_starts_with($line, $ip."\t")) {
                return $line;
            }
        }

        $this->fail('No hosts line for IP '.$ip);
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
