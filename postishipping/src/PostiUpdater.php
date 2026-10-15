<?php

namespace Posti\PrestaShop\Postishipping;

use Configuration;
use Tools;

class PostiUpdater
{
    const CACHE_TTL = 3600;
    const CONFIG_LATEST_VERSION = 'POSTISHIPPING_LATEST_VERSION';
    const CONFIG_LATEST_VERSION_URL = 'POSTISHIPPING_LATEST_VERSION_URL';
    const CONFIG_LATEST_CHANGELOG = 'POSTISHIPPING_LATEST_CHANGELOG';
    const CONFIG_LAST_CHECK = 'POSTISHIPPING_LAST_UPDATE_CHECK';

    /** @var string Default GitHub Releases API URL */
    const DEFAULT_UPDATE_URL = 'https://api.github.com/repos/PostiDigital/prestashop-shipping-plugin/releases/latest';

    /** @var string */
    private $currentVersion;

    /** @var string */
    private $updateUrl;

    /** @var string */
    private $moduleDir;

    public function __construct(string $currentVersion)
    {
        $this->currentVersion = $currentVersion;
        $this->moduleDir = dirname(__DIR__);
        $this->updateUrl = $this->loadUpdateUrl();
    }

    private function loadUpdateUrl(): string
    {
        $configFile = $this->moduleDir . '/posticonfig.json';
        if (file_exists($configFile)) {
            $config = json_decode(file_get_contents($configFile), true);
            if (is_array($config) && isset($config['updateUrl'])) {
                $val = trim((string) $config['updateUrl']);
                // "-" explicitly disables update checking
                if ($val === '-') {
                    return '';
                }
                if ($val !== '') {
                    return $val;
                }
            }
        }

        return self::DEFAULT_UPDATE_URL;
    }

    /**
     * Whether update checking is enabled (has a non-empty URL).
     */
    public function isConfigured(): bool
    {
        return $this->updateUrl !== '';
    }

    /**
     * @return array{version: string, download_url: string, has_update: bool, changelog: string}|null
     */
    public function checkForUpdates(bool $force = false): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        if (!$force) {
            $lastCheck = (int) Configuration::get(self::CONFIG_LAST_CHECK);
            if ((time() - $lastCheck) < self::CACHE_TTL) {
                $latestVersion = Configuration::get(self::CONFIG_LATEST_VERSION);
                $downloadUrl = Configuration::get(self::CONFIG_LATEST_VERSION_URL);
                if ($latestVersion && $downloadUrl) {
                    return [
                        'version' => $latestVersion,
                        'download_url' => $downloadUrl,
                        'has_update' => version_compare($latestVersion, $this->currentVersion, '>'),
                        'changelog' => (string) Configuration::get(self::CONFIG_LATEST_CHANGELOG),
                    ];
                }
            }
        }

        $result = $this->fetchLatestRelease();

        if ($result) {
            Configuration::updateValue(self::CONFIG_LATEST_VERSION, $result['version']);
            Configuration::updateValue(self::CONFIG_LATEST_VERSION_URL, $result['download_url']);
            Configuration::updateValue(self::CONFIG_LATEST_CHANGELOG, $result['changelog']);
            Configuration::updateValue(self::CONFIG_LAST_CHECK, time());
        }

        return $result;
    }

    private function fetchLatestRelease(): ?array
    {
        $response = $this->httpGet($this->updateUrl);

        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);

        if (!is_array($data)) {
            return null;
        }

        // GitHub Releases API format
        if (isset($data['tag_name'])) {
            return $this->parseGithubRelease($data);
        }

        // Custom JSON format: {"version": "1.2.3", "download_url": "https://...", "changelog": "..."}
        if (isset($data['version'], $data['download_url'])) {
            return [
                'version' => ltrim($data['version'], 'vV'),
                'download_url' => $data['download_url'],
                'has_update' => version_compare(ltrim($data['version'], 'vV'), $this->currentVersion, '>'),
                'changelog' => isset($data['changelog']) ? (string) $data['changelog'] : '',
            ];
        }

        return null;
    }

    private function parseGithubRelease(array $data): ?array
    {
        $version = ltrim($data['tag_name'], 'vV');
        $downloadUrl = '';

        // Prefer a .zip asset from the release
        if (!empty($data['assets']) && is_array($data['assets'])) {
            foreach ($data['assets'] as $asset) {
                $name = isset($asset['name']) ? (string) $asset['name'] : '';
                if (isset($asset['browser_download_url']) && substr($name, -4) === '.zip') {
                    $downloadUrl = $asset['browser_download_url'];
                    break;
                }
            }
        }

        // Fall back to zipball_url
        if (empty($downloadUrl) && !empty($data['zipball_url'])) {
            $downloadUrl = $data['zipball_url'];
        }

        if (empty($downloadUrl)) {
            return null;
        }

        return [
            'version' => $version,
            'download_url' => $downloadUrl,
            'has_update' => version_compare($version, $this->currentVersion, '>'),
            'changelog' => isset($data['body']) ? (string) $data['body'] : '',
        ];
    }

    /**
     * @return array{success: bool, message: string, version: string}
     */
    public function performUpdate(): array
    {
        // Pre-flight checks
        $preflight = $this->preflightChecks();
        if ($preflight !== null) {
            return $preflight + ['version' => ''];
        }

        $updateInfo = $this->checkForUpdates(true);

        if (!$updateInfo || !$updateInfo['has_update']) {
            return ['success' => false, 'message' => 'No update available.', 'version' => ''];
        }

        $tempDir = $this->createTempDir();
        if ($tempDir === null) {
            return ['success' => false, 'message' => 'Failed to create temporary directory. Check that the system temp directory is writable.', 'version' => ''];
        }
        $tempZip = $tempDir . '/update.zip';
        $backupDir = $tempDir . '/backup';

        try {
            // Download the ZIP
            $zipContent = $this->httpGet($updateInfo['download_url'], 120);
            if ($zipContent === false || empty($zipContent)) {
                throw new \RuntimeException('Failed to download update file.');
            }

            if (file_put_contents($tempZip, $zipContent) === false) {
                throw new \RuntimeException('Failed to save update file to temp directory.');
            }

            // Extract ZIP
            $zip = new \ZipArchive();
            $openResult = $zip->open($tempZip);
            if ($openResult !== true) {
                throw new \RuntimeException('Failed to open downloaded archive (error code: ' . $openResult . ').');
            }

            $extractDir = $tempDir . '/extracted';
            if (!mkdir($extractDir, 0755, true)) {
                throw new \RuntimeException('Failed to create extraction directory.');
            }

            $zip->extractTo($extractDir);
            $zip->close();

            // Find the module root inside the extracted archive
            $sourceDir = $this->findModuleRoot($extractDir);
            if (!$sourceDir) {
                throw new \RuntimeException('Could not find module files in the archive. Check that the ZIP contains postishipping.php.');
            }

            // Verify the new version file exists
            if (!file_exists($sourceDir . '/postishipping.php')) {
                throw new \RuntimeException('The archive does not contain a valid PostiShipping module.');
            }

            // Create backup of current module (excluding vendor to save space/time)
            if (!mkdir($backupDir, 0755, true)) {
                throw new \RuntimeException('Failed to create backup directory.');
            }
            $this->copyDirectory($this->moduleDir, $backupDir, true);

            // Copy the new files over the existing module
            try {
                $this->copyDirectory($sourceDir, $this->moduleDir);
            } catch (\Throwable $copyError) {
                // Restore from backup
                $this->copyDirectory($backupDir, $this->moduleDir);
                throw new \RuntimeException('Update failed during file copy: ' . $copyError->getMessage() . '. Module has been restored from backup.');
            }

            // Invalidate opcache for all changed files
            $this->invalidateOpcache($this->moduleDir);

            // Clear update cache
            Configuration::updateValue(self::CONFIG_LAST_CHECK, 0);

            // Files are updated. The caller must update ps_module.version
            // (via Module::upgradeModuleVersion) and redirect so PrestaShop
            // reloads the module from the new files.
            return [
                'success' => true,
                'message' => 'Module files updated to version ' . $updateInfo['version'] . '.',
                'version' => $updateInfo['version'],
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'version' => ''];
        } finally {
            $this->removeDirectory($tempDir);
        }
    }

    /**
     * @return array{success: bool, message: string}|null null if all checks pass
     */
    private function preflightChecks(): ?array
    {
        if (!class_exists('ZipArchive')) {
            return ['success' => false, 'message' => 'PHP ZipArchive extension is required for updates.'];
        }

        if (!function_exists('curl_init') && !ini_get('allow_url_fopen')) {
            return ['success' => false, 'message' => 'Either cURL extension or allow_url_fopen must be enabled.'];
        }

        // Check module directory is writable
        if (!is_writable($this->moduleDir)) {
            return ['success' => false, 'message' => 'Module directory is not writable. Check file permissions for: ' . $this->moduleDir];
        }

        // Spot-check a few key files/dirs for writability
        $checkPaths = ['postishipping.php', 'config.xml', 'src', 'views'];
        foreach ($checkPaths as $relative) {
            $full = $this->moduleDir . '/' . $relative;
            if (file_exists($full) && !is_writable($full)) {
                return ['success' => false, 'message' => 'File/directory is not writable: ' . $relative . '. Check file permissions.'];
            }
        }

        // Check temp directory is usable
        $tmpTest = $this->createTempDir();
        if ($tmpTest === null) {
            return ['success' => false, 'message' => 'Cannot create temporary directory. Check system temp directory permissions or open_basedir restrictions.'];
        }
        $this->removeDirectory($tmpTest);

        return null;
    }

    /**
     * @return string|null path to created temp dir, or null on failure
     */
    private function createTempDir(): ?string
    {
        // Try system temp first
        $bases = [sys_get_temp_dir()];

        // Fallback: use PrestaShop cache directory if open_basedir blocks system temp
        if (defined('_PS_CACHE_DIR_')) {
            $bases[] = _PS_CACHE_DIR_;
        }

        foreach ($bases as $base) {
            if (!is_dir($base) || !is_writable($base)) {
                continue;
            }

            $dir = $base . '/postishipping_update_' . time() . '_' . mt_rand(1000, 9999);
            if (@mkdir($dir, 0755, true)) {
                return $dir;
            }
        }

        return null;
    }

    private function findModuleRoot(string $dir): ?string
    {
        // Check if postishipping.php is directly in the extracted directory
        if (file_exists($dir . '/postishipping.php')) {
            return $dir;
        }

        // GitHub zipball creates a subdirectory like "owner-repo-hash/"
        $entries = scandir($dir);
        if ($entries === false) {
            return null;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && file_exists($path . '/postishipping.php')) {
                return $path;
            }
        }

        return null;
    }

    private function copyDirectory(string $source, string $dest, bool $isBackup = false): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relativePath = $iterator->getSubPathname();
            $targetPath = $dest . '/' . $relativePath;

            // Skip vendor directory during backup to save time/space
            if ($isBackup && strpos($relativePath, 'vendor') === 0) {
                continue;
            }

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    if (!@mkdir($targetPath, 0755, true)) {
                        throw new \RuntimeException('Failed to create directory: ' . $relativePath);
                    }
                }
            } else {
                // Never overwrite local configuration
                if ($relativePath === 'posticonfig.json') {
                    continue;
                }
                if (!@copy($item->getPathname(), $targetPath)) {
                    throw new \RuntimeException('Failed to copy file: ' . $relativePath);
                }
            }
        }
    }

    private function invalidateOpcache(string $dir): void
    {
        if (!function_exists('opcache_invalidate')) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isFile() && $item->getExtension() === 'php') {
                @opcache_invalidate($item->getPathname(), true);
            }
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }

    /**
     * @return string|false
     */
    private function httpGet(string $url, int $timeout = 15)
    {
        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== 'https' && $scheme !== 'http') {
            return false;
        }

        // Use cURL if available for better redirect handling
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => 'PostiShipping-PrestaShop/' . $this->currentVersion,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/vnd.github.v3+json',
                ],
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300 && is_string($response)) {
                return $response;
            }

            return false;
        }

        // Fallback to file_get_contents
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", [
                    'User-Agent: PostiShipping-PrestaShop/' . $this->currentVersion,
                    'Accept: application/vnd.github.v3+json',
                ]),
                'timeout' => $timeout,
                'follow_location' => true,
                'max_redirects' => 5,
            ],
            'ssl' => [
                'verify_peer' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        return $response !== false ? $response : false;
    }
}
