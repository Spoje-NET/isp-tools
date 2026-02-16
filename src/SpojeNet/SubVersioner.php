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

namespace SpojeNet;

/**
 * Description of SubVersioner.
 *
 * @author Vitex <info@vitexsoftware.cz>
 */
class SubVersioner implements NetworkBackendInterface
{
    private ?string $svnUser;
    private ?string $svnPass;
    private ?string $svnUrl;
    private ?string $svnBin;
    private ?string $logFile;
    private string $tempDir;
    private string $repoName = 'hostsbrevnov';

    public function __construct(?string $workingDir = null)
    {
        $this->svnUser = \Ease\Shared::cfg('SVNUSER');
        $this->svnPass = \Ease\Shared::cfg('SVNPASS');
        $this->svnUrl = \Ease\Shared::cfg('SVNURL');
        $this->svnBin = \Ease\Shared::cfg('SVNBIN');
        $this->logFile = \Ease\Shared::cfg('LOGFILE');

        if (!$this->svnUser || !$this->svnPass || !$this->svnUrl || !$this->svnBin || !$this->logFile) {
            throw new \Exception('Missing required SVN configuration. Please check SVNUSER, SVNPASS, SVNURL, SVNBIN, and LOGFILE in configuration.');
        }

        $this->tempDir = $workingDir ?? sys_get_temp_dir().'/'.$this->repoName;
    }

    /**
     * Block IP by setting speed to 0.
     */
    public function blockIp(string $ip): bool
    {
        try {
            $this->log('block requested for IP '.$ip);
            $this->checkoutRepo();
        $hostsFile = $this->tempDir.'/hosts';
        $tempFile = $this->tempDir.'/tmpnewhosts';
        $newContent = '';

        $lines = file($hostsFile, \FILE_IGNORE_NEW_LINES);

        foreach ($lines as $line) {
            $keep = true;

            if ($line[0] !== '#') {
                $fields = explode("\t", $line);

                if (\count($fields) > 0 && trim($fields[0]) === $ip) {
                    $commentParts = explode('#', $line);

                    if (\count($commentParts) > 1) {
                        // Modify comment to speed=0, remove dashboard parts
                        $modifiedComment = preg_replace('/speed=\d+/', '', $commentParts[1]);
                        $modifiedComment = str_replace(['dashboard-neplatic', 'dashboard-smlouva', '-odpojen', 'dashboard'], '', $modifiedComment);
                        $modifiedComment = trim($modifiedComment);
                        $newLine = $commentParts[0].'# speed=0'.$modifiedComment;
                        $newContent .= $newLine."\n";
                        $keep = false;
                    }
                }
            }

            if ($keep) {
                $newContent .= $line."\n";
            }
        }

        file_put_contents($tempFile, $newContent);
        unlink($hostsFile);
        rename($tempFile, $hostsFile);
        $this->commitRepo('Auto-block-IP-'.$ip);
        $this->cleanup();
        } catch (\Exception $e) {
            $this->log('Error blocking IP: ' . $e->getMessage());
            return false;
        }
        return true;
    }

    /**
     * Unblock IP by setting speed to given value.
     */
    public function unblockIp(string $ip, int $speed): bool
    {
        try {
        $this->log('unblock requested for IP '.$ip.' to speed '.$speed);
        $this->checkoutRepo();
        $hostsFile = $this->tempDir.'/hosts';
        $tempFile = $this->tempDir.'/tmpnewhosts';
        $newContent = '';

        $lines = file($hostsFile, \FILE_IGNORE_NEW_LINES);

        foreach ($lines as $line) {
            $keep = true;

            if ($line[0] !== '#') {
                $fields = explode("\t", $line);

                if (\count($fields) > 0 && trim($fields[0]) === $ip) {
                    $commentParts = explode('#', $line);

                    if (\count($commentParts) > 1) {
                        // Modify comment to speed=$speed, remove dashboard parts
                        $modifiedComment = preg_replace('/speed=\d+/', '', $commentParts[1]);
                        $modifiedComment = str_replace(['dashboard-neplatic', 'dashboard-smlouva', '-odpojen', 'dashboard'], '', $modifiedComment);
                        $modifiedComment = trim($modifiedComment);
                        $newLine = $commentParts[0].'# speed='.$speed.$modifiedComment;
                        $newContent .= $newLine."\n";
                        $keep = false;
                    }
                }
            }

            if ($keep) {
                $newContent .= $line."\n";
            }
        }

        file_put_contents($tempFile, $newContent);
        unlink($hostsFile);
        rename($tempFile, $hostsFile);
        $this->commitRepo('Auto-unblock-IP-'.$ip);
        $this->cleanup();
        } catch (\Exception $e) {
            $this->log('Error unblocking IP: ' . $e->getMessage());
            return false;
        }
        return true;
    }

    /**
     * Check hosts file sanity.
     */
    public function checkHostsSanity(string $hostsFile = 'hosts'): array
    {
        $errors = [];
        $allHostnames = [];
        $allIps = [];

        try {
            $lines = file($hostsFile, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);

            foreach ($lines as $i => $line) {
                $lineNum = $i + 1;

                // Skip comments and empty lines
                if (\strlen($line) > 1 && $line[0] !== '#') {
                    if (!str_contains($line, "\t")) {
                        $errors[] = "Line {$lineNum}: Valid line must contain at least one tabulator. {$line}";

                        continue;
                    }

                    $sections = explode('#', $line);
                    $tabs = explode("\t", $sections[0]);
                    $fields = array_filter($tabs, static fn ($tab) => $tab !== '');

                    // Check formatting
                    if (\count($sections) > 1 && \count($fields) < \count($tabs) - 1) {
                        $errors[] = "Line {$lineNum}: Line formatted suspiciously - possible mix of spaces and tabs? {$line}";

                        continue;
                    }

                    // Check IP
                    $ip = $fields[0];
                    $ipParts = explode('.', $ip);

                    if (\count($ipParts) !== 4) {
                        $errors[] = "Line {$lineNum}: IPv4 address must be formed by three dots and four numbers. {$line}";

                        continue;
                    }

                    foreach ($ipParts as $part) {
                        if (!is_numeric($part) || (int) $part < 0 || (int) $part > 255) {
                            $errors[] = "Line {$lineNum}: IPv4 address must contain valid numbers in range 0-255. {$line}";

                            continue 2;
                        }
                    }

                    if (\in_array($ip, $allIps, true)) {
                        $errors[] = "Line {$lineNum}: Duplicate IP address: {$ip} {$line}";

                        continue;
                    }

                    $allIps[] = $ip;

                    // Check hostnames
                    $hostnames = explode(' ', $fields[1]);

                    foreach ($hostnames as $hostname) {
                        if ($hostname !== '') {
                            if (\in_array($hostname, $allHostnames, true)) {
                                $errors[] = "Line {$lineNum}: Duplicate hostname: {$hostname} {$line}";

                                continue;
                            }

                            $allHostnames[] = $hostname;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $errors[] = "Failed to read file {$hostsFile}: ".$e->getMessage();
        }

        return $errors;
    }

    private function checkoutRepo(): void
    {
        $workingDir = dirname($this->tempDir);
        $repoDir = basename($this->tempDir);

        if (!is_dir($workingDir)) {
            mkdir($workingDir, 0755, true);
        }

        chdir($workingDir);
        $this->rmdirRecursive($repoDir);

        $cmd = sprintf('%s co --username %s --password %s %s %s', $this->svnBin, $this->svnUser, $this->svnPass, $this->svnUrl, $repoDir);
        exec($cmd);
    }

    private function commitRepo(string $message): void
    {
        $workingDir = dirname($this->tempDir);
        $repoDir = basename($this->tempDir);

        chdir($workingDir);
        $cmd = sprintf('%s ci -m "%s" --password %s %s', $this->svnBin, $message, $this->svnPass, $repoDir);
        exec($cmd);
    }

    private function cleanup(): void
    {
        $this->rmdirRecursive($this->tempDir);
    }

    private function log(string $message): void
    {
        $logEntry = date('D, d M Y H:i:s O').' '.$message."\n";
        file_put_contents($this->logFile, $logEntry, \FILE_APPEND);
    }

    private function rmdirRecursive(string $dir): void
    {
        if (is_dir($dir)) {
            $files = array_diff(scandir($dir), ['.', '..']);

            foreach ($files as $file) {
                $path = $dir.'/'.$file;
                is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
            }

            rmdir($dir);
        }
    }
}
