#!/usr/bin/env php
<?php
/**
 * AnimeVerse Website Manager - Terminal Interface
 * Controls websites by scanning ports, managing processes, and monitoring services
 *
 * Usage: php AVManager.php [options]
 *
 * @author System Administrator
 * @version 2.0
 */

declare(strict_types=1);

// Define constants
define('AVM_VERSION', '2.0.0');
define('AVM_CONFIG_FILE', '.avmanager.json');
define('AVM_PID_DIR', '/tmp/avmanager_pids/');
define('AVM_LOG_FILE', '/tmp/avmanager.log');
define('AVM_MAX_PORT_SCAN_TIMEOUT', 10);
define('AVM_MIN_PORT_SCAN_TIMEOUT', 1);

/**
 * Main Website Manager Class
 */
class AVManager
{
    private array $config = [
        'default_ports' => [80, 443, 3000, 8000, 8080, 9000, 4200, 5000, 3306, 5432, 27017, 6379],
        'scan_timeout' => 2,
        'log_file' => AVM_LOG_FILE,
        'pid_dir' => AVM_PID_DIR,
        'auto_save' => true,
        'color_output' => true,
        'confirm_actions' => true,
        'max_retries' => 3,
        'retry_delay' => 1,
        'health_check_interval' => 60,
        'notification_email' => '',
        'enable_notifications' => false,
        'allowed_users' => [],
    ];

    private array $websites = [];
    private array $processes = [];
    private array $healthHistory = [];
    private bool $isRunning = true;
    private bool $posixAvailable;
    private string $currentDir;
    private string $configFile;
    private float $startTime;

    public function __construct(?string $configPath = null)
    {
        $this->startTime = microtime(true);
        $this->currentDir = getcwd();
        $this->configFile = $configPath ?? $this->currentDir . '/' . AVM_CONFIG_FILE;
        $this->posixAvailable = function_exists('posix_geteuid');

        // Validate runtime environment
        $this->validateEnvironment();

        $this->setupPidDir();
        $this->loadConfiguration();
        $this->loadWebsites();

        $this->log('AnimeVerse Website Manager v' . AVM_VERSION . ' started');
        $this->log("User: " . $this->getCurrentUser() . ", PID: " . getmypid());
    }

    /**
     * Validate runtime environment
     */
    private function validateEnvironment(): void
    {
        $errors = [];

        // Check PHP version
        if (version_compare(PHP_VERSION, '8.0.0', '<')) {
            $errors[] = "PHP 8.0+ required. Current version: " . PHP_VERSION;
        }

        // Check required extensions
        $requiredExtensions = ['json', 'sockets'];
        foreach ($requiredExtensions as $ext) {
            if (!extension_loaded($ext)) {
                $errors[] = "Required extension missing: {$ext}";
            }
        }

        // Check optional functions
        $optionalFunctions = ['exec', 'shell_exec', 'fsockopen', 'file_get_contents'];
        foreach ($optionalFunctions as $func) {
            if (!function_exists($func)) {
                $this->printWarning("Optional function not available: {$func}");
            }
        }

        // Check POSIX functions availability
        if (!$this->posixAvailable) {
            $this->printWarning("POSIX functions not available. Some features may be limited.");
        }

        if (!empty($errors)) {
            foreach ($errors as $error) {
                $this->printError($error);
            }
            throw new RuntimeException("Environment validation failed");
        }

        $this->printSuccess("Environment validated successfully");
    }

    /**
     * Get current user
     */
    private function getCurrentUser(): string
    {
        $user = get_current_user();
        if ($this->posixAvailable) {
            $user = posix_getpwuid(posix_geteuid())['name'] ?? $user;
        }
        return $user;
    }

    /**
     * Check if running in an interactive terminal
     */
    private function isInteractiveTerminal(): bool
    {
        // Check if we have a real TTY using POSIX
        if ($this->posixAvailable) {
            return posix_isatty(STDIN);
        }

        // Fallback: try to get tty name
        if (function_exists('posix_ttyname')) {
            $tty = posix_ttyname(STDIN);
            return !empty($tty);
        }

        // Check if stdin is a character device (terminal)
        $stat = fstat(STDIN);
        if ($stat !== false) {
            // S_IFCHR = 0x2000 (character device)
            return ($stat['mode'] & 0xC000) === 0;
        }

        return false;
    }

    /**
     * Main execution loop with arrow key navigation
     */
    public function run(): void
    {
        $this->showBanner();

        // Check for command line arguments first
        $this->handleArguments();

        if ($this->isRunning) {
            // Check if we're in an interactive terminal
            $isInteractive = $this->isInteractiveTerminal();

            if (!$isInteractive) {
                // Non-interactive mode: use numbered menu fallback
                $this->printColored("\n[INFO] Arrow keys not available. Using numbered menu.\n", 'yellow');
                $this->nonInteractiveMenu();
            } else {
                // Interactive mode: use arrow-key navigation
                while ($this->isRunning) {
                    try {
                        // Use arrow-key interactive menu
                        $selection = $this->showInteractiveMenu();

                        if ($selection === -1) {
                            // User pressed q or ESC to exit
                            $this->isRunning = false;
                            break;
                        }

                        if ($selection >= 0 && $this->isRunning) {
                            // Convert selection to choice string (1-based index + 1 for array offset)
                            $choice = (string)($selection + 1);
                            $this->handleMenuChoice($choice);
                        }

                        if ($this->isRunning) {
                            $this->pause();
                        }
                    } catch (Exception $e) {
                        $this->printError("An error occurred: " . $e->getMessage());
                        $this->log("Error in main loop: " . $e->getMessage());
                        $this->pause();
                    }
                }
            }
        }

        $this->shutdown();
    }

    /**
     * Handle command line arguments
     */
    private function handleArguments(): void
    {
        global $argv;

        $options = getopt('hvs:p:d:', [
            'help', 'version', 'scan', 'port:', 'daemon', 'status', 'quick-scan',
            'health', 'list', 'kill:', 'restart:'
        ]);

        if (isset($options['h']) || isset($options['help'])) {
            $this->showHelp();
            exit(0);
        }

        if (isset($options['v']) || isset($options['version'])) {
            echo "AnimeVerse Website Manager v" . AVM_VERSION . "\n";
            exit(0);
        }

        if (isset($options['quick-scan'])) {
            // --quick-scan: Just show open ports without populating websites array
            $ports = isset($options['p']) ? array_map('intval', explode(',', $options['p'])) : null;
            $this->quickScan($ports);
            exit(0);
        }

        if (isset($options['s']) || isset($options['scan'])) {
            // -s or --scan: Full port scan that populates websites array
            $ports = isset($options['p']) ? array_map('intval', explode(',', $options['p'])) : null;
            if ($ports) {
                // Custom ports - scan them
                $this->scanPortsWithCustomPorts($ports);
            } else {
                // Full scan via interactive method
                $this->scanPorts();
            }
            exit(0);
        }

        if (isset($options['p']) && !isset($options['scan']) && !isset($options['s'])) {
            $this->scanSpecificPorts(array_map('intval', explode(',', $options['p'])));
            exit(0);
        }

        if (isset($options['d']) || isset($options['daemon'])) {
            $this->runAsDaemon();
            exit(0);
        }

        if (isset($options['status'])) {
            $this->quickStatus();
            exit(0);
        }

        if (isset($options['health'])) {
            $this->healthCheck();
            exit(0);
        }

        if (isset($options['list'])) {
            $this->listWebsites();
            exit(0);
        }

        if (isset($options['kill'])) {
            $pid = (int)$options['kill'];
            $this->forceKillProcess($pid);
            exit(0);
        }

        if (isset($options['restart'])) {
            $port = (int)$options['restart'];
            $this->restartByPort($port);
            exit(0);
        }
    }

    /**
     * Show help message
     */
    private function showHelp(): void
    {
        $version = AVM_VERSION;
        echo <<<HELP

AnimeVerse Website Manager v{$version}

Usage: php AVManager.php [options]

Options:
  -h, --help          Show this help message
  -v, --version       Show version information
  -s, --scan          Scan ports immediately
  -p, --port          Specify ports to scan (comma-separated)
  -d, --daemon        Run as daemon mode
      --quick-scan    Quick port scan without detailed output
      --status        Show current status
      --health        Run health check on all services
      --list          List all detected websites
      --kill=PID      Kill process by PID
      --restart=PORT  Restart service on port

Interactive Commands:
  1. Scan Ports              - Scan for open ports
  2. List Websites           - List detected websites
  3. Restart Website         - Restart a service
  4. Shutdown Website        - Stop a service
  5. Sleep Website           - Reduce resource usage
  6. Process Management      - Advanced process control
  7. System Information      - View system stats
  8. Configuration           - Manage settings
  9. Health Monitor          - Monitor service health
  10. Log Viewer             - View system logs
  11. Backup/Restore         - Backup configurations
  12. Export/Import          - Export/import data
  13. Auto-Discover Services - Auto discover services
  14. Service Dependencies   - View service dependencies
  15. Exit                   - Exit manager

HELP;
    }

    /**
     * Show banner
     */
    private function showBanner(): void
    {
        $this->printColored("\n" . str_repeat('=', 60), 'cyan');
        $this->printColored("      ANIMEVERSE WEBSITE MANAGEMENT SYSTEM", 'light_cyan', true);
        $this->printColored(str_repeat('=', 60), 'cyan');
        $this->printColored("Version: " . AVM_VERSION . " | PHP: " . PHP_VERSION, 'yellow');
        $this->printColored("User: " . $this->getCurrentUser() . " | PID: " . getmypid(), 'yellow');
        $this->printColored("Directory: " . $this->currentDir, 'yellow');
        $this->printColored(str_repeat('-', 60), 'cyan');
    }

    /**
     * Show menu with arrow key navigation
     * Returns the selected index (0-based) or -1 for exit
     */
    private function showInteractiveMenu(): int
    {
        $menuItems = [
            '1. Scan Ports',
            '2. List Websites',
            '3. Restart Website',
            '4. Shutdown Website',
            '5. Sleep Website (Reduce Resources)',
            '6. Process Management',
            '7. System Information',
            '8. Configuration',
            '9. Health Monitor',
            '10. Log Viewer',
            '11. Backup/Restore',
            '12. Export/Import Data',
            '13. Auto-Discover Services',
            '14. Service Dependencies',
            '15. Exit'
        ];

        // Add info footer to items
        $footerItems = [
            "Uptime: " . $this->formatUptime(),
            $this->getMemoryUsage()
        ];

        return $this->showArrowMenuWithFooter($menuItems, 'MAIN MENU', $footerItems);
    }

    /**
     * Show arrow menu with footer info
     */
    private function showArrowMenuWithFooter(array $items, string $title, array $footerInfo): int
    {
        $this->printHeader($title);

        $selected = 0;
        $count = count($items);

        $this->enableRawMode();

        try {
            while (true) {
                echo "\033[2J\033[H"; // Clear screen

                echo "\n";
                for ($i = 0; $i < $count; $i++) {
                    $item = $items[$i];
                    $isSelected = ($i === $selected);

                    if ($isSelected) {
                        echo "  \033[1;32m\033[46m> {$item}\033[0m\033[49m\033[0m\n";
                    } else {
                        echo "    {$item}\n";
                    }
                }

                // Show footer info
                echo "\n\033[90m" . str_repeat('-', 60) . "\033[0m\n";
                foreach ($footerInfo as $info) {
                    echo "\033[36m  {$info}\033[0m\n";
                }
                echo "\033[90m" . str_repeat('-', 60) . "\033[0m\n";

                echo "\n\033[90m  [UP/DOWN or j/k to navigate, ENTER to select, q or ESC to quit]\033[0m\n";

                $key = $this->readArrowKey();

                switch ($key) {
                    case 'UP':
                    case 'k':
                        $selected = max(0, $selected - 1);
                        break;
                    case 'DOWN':
                    case 'j':
                        $selected = min($count - 1, $selected + 1);
                        break;
                    case 'ENTER':
                    case "\n":
                        $this->disableRawMode();
                        return $selected;
                    case 'ESC':
                    case 'q':
                    case 'x':
                        $this->disableRawMode();
                        return -1; // Exit
                }
            }
        } finally {
            $this->disableRawMode();
        }
    }

    /**
     * Show menu (legacy numbered input)
     */
    private function showMenu(): void
    {
        $this->printHeader('MAIN MENU');

        $menuItems = [
            'Scan Ports',
            'List Websites',
            'Restart Website',
            'Shutdown Website',
            'Sleep Website (Reduce Resources)',
            'Process Management',
            'System Information',
            'Configuration',
            'Health Monitor',
            'Log Viewer',
            'Backup/Restore',
            'Export/Import Data',
            'Auto-Discover Services',
            'Service Dependencies',
            'Exit'
        ];

        foreach ($menuItems as $index => $item) {
            $num = str_pad((string)($index + 1), 2, ' ', STR_PAD_LEFT);
            echo "  {$num}. {$item}\n";
        }

        $this->printColored("\nUptime: " . $this->formatUptime(), 'cyan');
        $this->printColored($this->getMemoryUsage() . "\n", 'cyan');
    }

    /**
     * Non-interactive menu fallback (uses numbered input)
     */
    private function nonInteractiveMenu(): void
    {
        while ($this->isRunning) {
            $this->showMenu();
            $choice = $this->readInput("\nEnter option (0 to exit): ");

            if ($choice === '0' || in_array(strtolower($choice), ['exit', 'q', 'quit'])) {
                $this->isRunning = false;
                return;
            }

            if (empty($choice)) {
                continue;
            }

            $this->handleMenuChoice($choice);

            if ($this->isRunning) {
                $this->pause();
            }
        }
    }

    /**
     * Handle menu choice
     */
    private function handleMenuChoice(string $choice): void
    {
        $methodMap = [
            '1' => 'scanPorts',
            '2' => 'listWebsites',
            '3' => 'restartWebsite',
            '4' => 'shutdownWebsite',
            '5' => 'sleepWebsite',
            '6' => 'manageProcesses',
            '7' => 'showSystemInfo',
            '8' => 'configureSettings',
            '9' => 'healthMonitor',
            '10' => 'viewLogs',
            '11' => 'backupRestore',
            '12' => 'exportImport',
            '13' => 'autoDiscoverServices',
            '14' => 'showDependencies',
        ];

        $exitCommands = ['0', 'exit', 'quit', 'x', 'q'];

        if (in_array(strtolower($choice), $exitCommands)) {
            $this->isRunning = false;
            return;
        }

        if (isset($methodMap[$choice])) {
            $method = $methodMap[$choice];
            $this->$method();
        } else {
            $this->printError("Invalid option: {$choice}");
        }
    }

    // ==================== PORT SCANNING ====================

    /**
     * Scan for open ports and detect websites
     */
    public function scanPorts(): void
    {
        $this->printHeader('Port Scanning');

        $customPorts = $this->readInput('Enter custom ports to scan (comma-separated, or Enter for default): ');

        if (!empty($customPorts)) {
            $ports = $this->parsePortsInput($customPorts);
        } else {
            $ports = $this->config['default_ports'];
        }

        $this->printInfo("Scanning " . count($ports) . " ports with timeout: {$this->config['scan_timeout']}s");

        $foundSites = [];
        $totalPorts = count($ports);
        $startTime = microtime(true);

        foreach ($ports as $index => $port) {
            $this->showProgress($index + 1, $totalPorts, "Port {$port}");

            if ($this->isPortOpen($port)) {
                $processInfo = $this->getProcessInfo($port);
                $siteInfo = $this->buildSiteInfo($port, $processInfo);
                $foundSites[$port] = $siteInfo;
                $this->printSuccess("  Found service on port {$port}: {$siteInfo['process']}");
            }
        }

        $scanTime = round(microtime(true) - $startTime, 2);

        // Merge found sites with proper key handling (port as key)
        foreach ($foundSites as $port => $siteInfo) {
            $this->websites[$port] = $siteInfo;
        }

        if ($this->config['auto_save']) {
            $this->saveWebsites();
        }

        $this->printInfo("\nScan complete! Found " . count($foundSites) . " services in {$scanTime}s");
        $this->log("Port scan completed. Found " . count($foundSites) . " services in {$scanTime}s");
    }

    /**
     * Scan with custom ports (non-interactive)
     */
    public function scanPortsWithCustomPorts(array $ports): void
    {
        $this->printHeader('Port Scanning');

        $this->printInfo("Scanning " . count($ports) . " ports with timeout: {$this->config['scan_timeout']}s");

        $foundSites = [];
        $totalPorts = count($ports);
        $startTime = microtime(true);

        foreach ($ports as $index => $port) {
            $this->showProgress($index + 1, $totalPorts, "Port {$port}");

            if ($this->isPortOpen($port)) {
                $processInfo = $this->getProcessInfo($port);
                $siteInfo = $this->buildSiteInfo($port, $processInfo);
                $foundSites[$port] = $siteInfo;
                $this->printSuccess("  Found service on port {$port}: {$siteInfo['process']}");
            }
        }

        $scanTime = round(microtime(true) - $startTime, 2);

        // Merge found sites with proper key handling (port as key)
        foreach ($foundSites as $port => $siteInfo) {
            $this->websites[$port] = $siteInfo;
        }

        if ($this->config['auto_save']) {
            $this->saveWebsites();
        }

        $this->printInfo("\nScan complete! Found " . count($foundSites) . " services in {$scanTime}s");
        $this->log("Port scan completed. Found " . count($foundSites) . " services in {$scanTime}s");
    }

    /**
     * Quick scan for daemon mode
     */
    private function quickScan(?array $ports = null): void
    {
        $ports = $ports ?? $this->config['default_ports'];
        $found = [];

        foreach ($ports as $port) {
            if ($this->isPortOpen($port, 1)) {
                $processInfo = $this->getProcessInfo($port);
                $found[$port] = $processInfo['process'] ?? 'Unknown';

                // Only display if we have a real process name
                $processName = $processInfo['process'] ?? 'Unknown';
                if ($processName === 'Unknown' || strpos(strtolower($processName), 'tcp') !== false) {
                    // Try to get command from /proc
                    $cmd = $this->getProcessCommand($port);
                    if ($cmd) {
                        $processName = basename($cmd);
                    }
                }

                echo "Port {$port}: {$processName}\n";
            }
        }

        $this->log("Quick scan found " . count($found) . " services");
    }

    /**
     * Get process command for a port
     */
    private function getProcessCommand(int $port): ?string
    {
        // Try using ss command
        $output = $this->safeExec("ss -tlnp 2>/dev/null | grep :{$port}");

        if (!empty($output)) {
            foreach ($output as $line) {
                // Extract pid from format like "users:(("node",pid=1234,fd=17))"
                if (preg_match('/pid=(\d+)/', $line, $matches)) {
                    $pid = (int)$matches[1];
                    $cmdPath = $this->safeExec("readlink /proc/{$pid}/exe 2>/dev/null");
                    if (!empty($cmdPath) && isset($cmdPath[0])) {
                        return trim($cmdPath[0]);
                    }
                    // Fallback to cmdline
                    $cmdline = $this->safeExec("cat /proc/{$pid}/cmdline 2>/dev/null | tr '\0' ' '");
                    if (!empty($cmdline) && isset($cmdline[0])) {
                        return trim($cmdline[0]);
                    }
                }
            }
        }

        // Try lsof
        $output = $this->safeExec("lsof -i :{$port} -sTCP:LISTEN 2>/dev/null | grep -v COMMAND");
        if (!empty($output)) {
            foreach ($output as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) > 1 && is_numeric($parts[1])) {
                    $pid = (int)$parts[1];
                    $cmdPath = $this->safeExec("readlink /proc/{$pid}/exe 2>/dev/null");
                    if (!empty($cmdPath) && isset($cmdPath[0])) {
                        return trim($cmdPath[0]);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Scan specific ports
     */
    private function scanSpecificPorts(array $ports): void
    {
        foreach ($ports as $port) {
            if ($this->isPortOpen($port)) {
                $processInfo = $this->getProcessInfo($port);
                echo "Port {$port}: " . json_encode($processInfo, JSON_PRETTY_PRINT) . "\n";
            }
        }
    }

    /**
     * Check if a port is open with improved reliability
     */
    public function isPortOpen(int $port, ?int $timeout = null): bool
    {
        $timeout = $timeout ?? $this->config['scan_timeout'];
        $timeout = max(AVM_MIN_PORT_SCAN_TIMEOUT, min($timeout, AVM_MAX_PORT_SCAN_TIMEOUT));

        $methods = [
            ['127.0.0.1', AF_INET],
            ['::1', AF_INET6],
        ];

        foreach ($methods as [$host, $family]) {
            $socket = @socket_create($family, SOCK_STREAM, SOL_TCP);

            if ($socket === false) {
                continue;
            }

            socket_set_nonblock($socket);
            $start = microtime(true);

            $connected = @socket_connect($socket, $host, $port);
            $elapsed = microtime(true) - $start;

            if ($connected) {
                socket_close($socket);
                return true;
            }

            // Wait for connection with timeout
            while ($elapsed < $timeout) {
                $read = [$socket];
                $write = [$socket];
                $except = null;

                $result = socket_select($read, $write, $except, 0, 200000); // 200ms

                if ($result === false) {
                    break;
                }

                if ($result > 0) {
                    $errno = socket_last_error($socket);
                    // errno 0 = success, EINPROGRESS (115) = operation in progress
                    // EISCONN (114) = already connected
                    if ($errno === 0 || $errno === 115 || $errno === 114) {
                        socket_close($socket);
                        return true;
                    }
                }

                $elapsed = microtime(true) - $start;
            }

            socket_close($socket);
        }

        return false;
    }

    /**
     * Parse ports input
     */
    private function parsePortsInput(string $input): array
    {
        $ports = array_map('trim', explode(',', $input));
        $ports = array_filter($ports, 'is_numeric');
        $ports = array_map('intval', $ports);
        $ports = array_unique(array_filter($ports, fn($p) => $p > 0 && $p <= 65535));

        return array_values($ports);
    }

    /**
     * Build site info array
     */
    private function buildSiteInfo(int $port, array $processInfo): array
    {
        $command = $processInfo['command'] ?? '';

        $siteInfo = [
            'port' => $port,
            'process' => $processInfo['process'] ?? 'Unknown',
            'pid' => $processInfo['pid'] ?? null,
            'user' => $processInfo['user'] ?? 'Unknown',
            'command' => $command,
            'status' => 'running',
            'detected_at' => date('Y-m-d H:i:s'),
            'last_check' => date('Y-m-d H:i:s'),
            'technology' => $this->detectTechnology($port, $command),
            'memory_mb' => $processInfo['memory_mb'] ?? 0,
            'cpu_percent' => $processInfo['cpu_percent'] ?? 0,
            'connections' => $processInfo['connections'] ?? 0,
        ];

        // Add service URL if applicable
        if ($port == 443 || $port == 8443) {
            $siteInfo['url'] = "https://localhost:{$port}";
        } elseif ($port == 80) {
            $siteInfo['url'] = "http://localhost";
        } else {
            $siteInfo['url'] = "http://localhost:{$port}";
        }

        return $siteInfo;
    }

    /**
     * Get process information for a port
     */
    public function getProcessInfo(int $port): array
    {
        $result = [
            'process' => 'Unknown',
            'pid' => null,
            'user' => 'Unknown',
            'command' => 'Unknown',
            'memory_mb' => 0,
            'cpu_percent' => 0,
            'connections' => 0,
            'start_time' => null,
        ];

        // First, try to find PID from ss command (most reliable)
        $ssOutput = $this->safeExec("ss -tlnp 2>/dev/null | grep ':{$port}'");

        if (!empty($ssOutput)) {
            foreach ($ssOutput as $line) {
                // Extract PID from ss output format: "users:(("node",pid=1234,fd=17))"
                if (preg_match('/pid=(\d+)/', $line, $pidMatches)) {
                    $result['pid'] = (int)$pidMatches[1];

                    // Extract process name
                    if (preg_match('/users:\(\("([^"]+)/', $line, $cmdMatches)) {
                        $result['command'] = $cmdMatches[1];
                        $result['process'] = basename($cmdMatches[1]);
                    }
                    break;
                }
            }
        }

        // If no PID from ss, try lsof
        if (!$result['pid']) {
            $lsofOutput = $this->safeExec("lsof -i :{$port} -sTCP:LISTEN 2>/dev/null | grep -v COMMAND");

            foreach ($lsofOutput as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) >= 2 && is_numeric($parts[1])) {
                    $result['pid'] = (int)$parts[1];
                    $result['command'] = $parts[0];
                    $result['process'] = basename($parts[0]);
                    $result['user'] = $parts[2] ?? 'Unknown';
                    break;
                }
            }
        }

        // If still no PID, try fuser
        if (!$result['pid']) {
            $fuserOutput = $this->safeExec("fuser {$port}/tcp 2>/dev/null");
            foreach ($fuserOutput as $line) {
                $parts = preg_split('/\s+/', trim($line));
                foreach ($parts as $part) {
                    if (is_numeric($part)) {
                        $result['pid'] = (int)$part;
                        break 2;
                    }
                }
            }
        }

        // If we have a PID, get additional info from /proc
        if ($result['pid']) {
            // Get command from /proc
            $cmdlineFile = "/proc/{$result['pid']}/cmdline";
            if (is_readable($cmdlineFile)) {
                $cmdline = @file_get_contents($cmdlineFile);
                if ($cmdline) {
                    $cmdline = str_replace("\0", ' ', $cmdline);
                    $cmdline = trim($cmdline);
                    if (!empty($cmdline)) {
                        $result['command'] = explode(' ', $cmdline)[0];
                        $result['process'] = basename($result['command']);
                    }
                }
            }

            // Get user from /proc status
            $statusFile = "/proc/{$result['pid']}/status";
            if (is_readable($statusFile)) {
                $status = @file_get_contents($statusFile);
                if ($status) {
                    // Get UID
                    if (preg_match('/Uid:\s+(\d+)/', $status, $uidMatches)) {
                        $uid = (int)$uidMatches[1];
                        if (function_exists('posix_getpwuid')) {
                            $userInfo = posix_getpwuid($uid);
                            $result['user'] = $userInfo['name'] ?? 'Unknown';
                        }
                    }
                }
            }

            // Get memory from /proc status
            if (is_readable($statusFile)) {
                $status = @file_get_contents($statusFile);
                if ($status && preg_match('/VmRSS:\s+(\d+)\s+kB/', $status, $memMatches)) {
                    $result['memory_mb'] = round((int)$memMatches[1] / 1024, 2);
                }
            }

            // Get CPU from ps
            $psOutput = $this->safeExec("ps -p {$result['pid']} -o %cpu= 2>/dev/null");
            if (!empty($psOutput) && isset($psOutput[0])) {
                $result['cpu_percent'] = (float)trim($psOutput[0]);
            }

            // Get start time
            $startOutput = $this->safeExec("ps -p {$result['pid']} -o lstart= 2>/dev/null");
            if (!empty($startOutput) && isset($startOutput[0])) {
                $result['start_time'] = trim($startOutput[0]);
            }

            // Count open connections/files
            $fdDir = "/proc/{$result['pid']}/fd";
            if (is_dir($fdDir)) {
                $fds = glob($fdDir . '/*');
                $result['connections'] = count($fds ?? []);
            }
        }

        return $result;
    }

    /**
     * Safe execute command
     */
    private function safeExec(string $command): array
    {
        $output = [];
        $returnCode = 0;

        @exec($command, $output, $returnCode);

        if ($returnCode !== 0 || empty($output)) {
            return [];
        }

        return $output;
    }

    /**
     * Parse process output
     */
    private function parseProcessOutput(array $output, int $port, array $result): array
    {
        foreach ($output as $line) {
            // Skip lines that don't contain the port we're looking for
            if (strpos($line, (string)$port) === false && strpos($line, 'tcp') === false && strpos($line, 'LISTEN') === false) {
                continue;
            }

            // Try to extract PID from ss output format: "users:(("node",pid=1234,fd=17))"
            if (preg_match('/pid=(\d+)/', $line, $pidMatches)) {
                $result['pid'] = (int)$pidMatches[1];
                // Extract command name from users section
                if (preg_match('/users:\(\("([^"]+)"/', $line, $cmdMatches)) {
                    $result['command'] = basename($cmdMatches[1]);
                    $result['process'] = $result['command'];
                }
            }

            // Try lsof format: "COMMAND PID USER FD TYPE DEVICE SIZE/OFF NODE NAME"
            if (!$result['pid'] && preg_match('/^\s*(\S+)\s+(\d+)/', $line, $matches)) {
                $result['command'] = basename($matches[1]);
                $result['pid'] = (int)$matches[2];
                $result['process'] = $result['command'];
            }

            // Try netstat format with numeric port
            if (!$result['pid'] && preg_match('/:'.$port.'\s+.*?\s+(\d+)/', $line, $matches)) {
                $result['pid'] = (int)$matches[1];
            }

            // Try to get user from lsof/netstat output
            if (preg_match('/\s+(\S+)\s+\S+\s+\S+\s+(?:LISTEN|$)/', $line, $userMatches)) {
                $possibleUser = $userMatches[1];
                // User is usually not a number
                if (!is_numeric($possibleUser)) {
                    $result['user'] = $possibleUser;
                }
            }

            // If we found a valid PID, we're done
            if ($result['pid']) {
                // Try to get more info via /proc
                if (is_dir("/proc/{$result['pid']}")) {
                    $cmdline = @file_get_contents("/proc/{$result['pid']}/cmdline");
                    if ($cmdline) {
                        $cmdline = str_replace("\0", ' ', $cmdline);
                        $result['command'] = trim(explode(' ', $cmdline)[0]);
                        $result['process'] = basename($result['command']);
                    }

                    // Get user from /proc
                    $status = @file_get_contents("/proc/{$result['pid']}/status");
                    if ($status && preg_match('/Uid:\s+(\d+)/', $status, $uidMatches)) {
                        if (function_exists('posix_getpwuid')) {
                            $userInfo = posix_getpwuid((int)$uidMatches[1]);
                            $result['user'] = $userInfo['name'] ?? 'unknown';
                        }
                    }
                }
                break;
            }
        }

        return $result;
    }

    /**
     * Get process statistics
     */
    private function getProcessStats(int $pid): array
    {
        $stats = [
            'memory_mb' => 0,
            'cpu_percent' => 0,
            'connections' => 0,
            'start_time' => null,
        ];

        // Read from /proc on Linux
        if (is_readable("/proc/{$pid}/status")) {
            $status = file_get_contents("/proc/{$pid}/status");

            // Memory
            if (preg_match('/VmRSS:\s+(\d+)\s+kB/', $status, $matches)) {
                $stats['memory_mb'] = round($matches[1] / 1024, 2);
            }

            // Connections
            if (is_readable("/proc/{$pid}/fd")) {
                $fds = glob("/proc/{$pid}/fd/*");
                $stats['connections'] = count($fds ?? []);
            }
        }

        // CPU usage via ps
        $psOutput = $this->safeExec("ps -p {$pid} -o %cpu= 2>/dev/null");
        if (!empty($psOutput) && isset($psOutput[0])) {
            $stats['cpu_percent'] = (float)trim($psOutput[0]);
        }

        // Start time
        $startOutput = $this->safeExec("ps -p {$pid} -o lstart= 2>/dev/null");
        if (!empty($startOutput) && isset($startOutput[0])) {
            $stats['start_time'] = trim($startOutput[0]);
        }

        return $stats;
    }

    /**
     * Detect technology/framework based on port and process
     */
    public function detectTechnology(int $port, string $command): string
    {
        $techMap = [
            'node' => 'Node.js',
            'npm' => 'Node.js',
            'yarn' => 'Node.js/Yarn',
            'pnpm' => 'Node.js/pnpm',
            'react' => 'React',
            'next' => 'Next.js',
            'nuxt' => 'Nuxt.js',
            'vite' => 'Vite',
            'ng' => 'Angular',
            'php' => 'PHP',
            'php-fpm' => 'PHP-FPM',
            'httpd' => 'Apache',
            'nginx' => 'Nginx',
            'mysql' => 'MySQL',
            'mariadb' => 'MariaDB',
            'postgres' => 'PostgreSQL',
            'redis' => 'Redis',
            'mongodb' => 'MongoDB',
            'python' => 'Python',
            'python3' => 'Python3',
            'gunicorn' => 'Gunicorn',
            'uwsgi' => 'uWSGI',
            'ruby' => 'Ruby',
            'rails' => 'Ruby on Rails',
            'java' => 'Java',
            'jar' => 'Java',
            'tomcat' => 'Tomcat',
            'docker' => 'Docker',
            'containerd' => 'Docker/Containerd',
            'caddy' => 'Caddy',
            'traefik' => 'Traefik',
        ];

        $commandLower = strtolower($command);

        foreach ($techMap as $key => $technology) {
            if (strpos($commandLower, $key) !== false) {
                return $technology;
            }
        }

        // Guess by port number
        $portMap = [
            80 => 'HTTP',
            443 => 'HTTPS',
            3000 => 'Node.js/React',
            5000 => 'Flask/Python',
            8000 => 'Django/Python',
            8080 => 'Tomcat/Java',
            9000 => 'PHP-FPM',
            3306 => 'MySQL',
            5432 => 'PostgreSQL',
            27017 => 'MongoDB',
            6379 => 'Redis',
            27000 => 'LMS/Media',
        ];

        return $portMap[$port] ?? 'Unknown';
    }

    // ==================== WEBSITE MANAGEMENT ====================

    /**
     * List all detected websites
     */
    public function listWebsites(): void
    {
        $this->printHeader('Detected Websites');

        if (empty($this->websites)) {
            $this->printWarning("No websites detected. Run port scan first.");
            return;
        }

        printf("\n%-6s %-8s %-12s %-15s %-10s %-8s %-10s %s\n",
            'PORT', 'PID', 'PROCESS', 'TECHNOLOGY', 'STATUS', 'MEM(MB)', 'CPU%', 'URL');
        printf("%s\n", str_repeat('-', 100));

        foreach ($this->websites as $site) {
            $statusColor = $this->getStatusColor($site['status'] ?? 'unknown');
            $status = $site['status'] ?? 'unknown';

            printf("%-6d %-8s %-12s %-15s {$statusColor}%-10s\033[0m %-8.1f %-10.1f %s\n",
                $site['port'],
                $site['pid'] ?? 'N/A',
                substr($site['process'] ?? 'Unknown', 0, 12),
                substr($site['technology'] ?? 'Unknown', 0, 15),
                strtoupper($status),
                $site['memory_mb'] ?? 0,
                $site['cpu_percent'] ?? 0,
                $site['url'] ?? "localhost:{$site['port']}"
            );
        }

        $this->printInfo("\nTotal: " . count($this->websites) . " website(s) detected.");
    }

    /**
     * Get status color
     */
    private function getStatusColor(string $status): string
    {
        return match ($status) {
            'running' => "\033[32m",
            'sleeping' => "\033[33m",
            'stopped', 'dead' => "\033[31m",
            'unknown' => "\033[90m",
            default => "\033[37m",
        };
    }

    /**
     * Restart a website
     */
    public function restartWebsite(): void
    {
        $this->printHeader('Restart Website');

        if (empty($this->websites)) {
            $this->printWarning("No websites detected. Run port scan first.");
            return;
        }

        $port = $this->selectWebsite('Select website to restart (by port): ');

        if (!$port) {
            return;
        }

        $this->restartByPort($port);
    }

    /**
     * Restart by port
     */
    public function restartByPort(int $port): bool
    {
        if (!isset($this->websites[$port])) {
            $this->printError("No website found on port {$port}");
            return false;
        }

        $site = $this->websites[$port];

        $this->printWarning("Are you sure you want to restart service on port {$port}?");
        $this->printInfo("Process: {$site['process']} (PID: {$site['pid']})");

        if (!$this->config['confirm_actions'] || $this->confirmAction()) {
            $this->log("Attempting to restart service on port {$port}");

            // Kill the process
            if ($site['pid']) {
                $this->killProcess($site['pid']);
                $this->printSuccess("Process {$site['pid']} terminated.");
            }

            // Wait for port to be released
            $this->waitForPortRelease($port, 5);

            // Try to restart based on technology
            $restarted = $this->restartByTechnology($site['technology'], $port, $site['command'] ?? '');

            if ($restarted) {
                $this->printSuccess("Website on port {$port} restarted successfully!");
                $this->websites[$port]['status'] = 'running';
                $this->websites[$port]['restarted_at'] = date('Y-m-d H:i:s');
                $this->saveWebsites();
                $this->log("Service on port {$port} restarted successfully.");
                return true;
            } else {
                $this->printWarning("Website stopped. You may need to restart it manually.");
                $this->websites[$port]['status'] = 'stopped';
                $this->websites[$port]['stopped_at'] = date('Y-m-d H:i:s');
                $this->saveWebsites();
            }
        }

        return false;
    }

    /**
     * Wait for port to be released
     */
    private function waitForPortRelease(int $port, int $maxWait): bool
    {
        for ($i = 0; $i < $maxWait * 10; $i++) {
            if (!$this->isPortOpen($port, 1)) {
                return true;
            }
            usleep(100000); // 100ms
        }
        return false;
    }

    /**
     * Shutdown a website
     */
    public function shutdownWebsite(): void
    {
        $this->printHeader('Shutdown Website');

        if (empty($this->websites)) {
            $this->printWarning("No websites detected. Run port scan first.");
            return;
        }

        $port = $this->selectWebsite('Select website to shutdown (by port): ');

        if (!$port) {
            return;
        }

        $site = $this->websites[$port];

        $this->printError("WARNING: This will completely stop the service!");
        $this->printInfo("Process: {$site['process']} (PID: {$site['pid']})");

        if (!$this->config['confirm_actions'] || $this->confirmAction()) {
            if ($site['pid']) {
                $this->killProcess($site['pid']);
                $this->printSuccess("Process {$site['pid']} terminated.");
                $this->websites[$port]['status'] = 'stopped';
                $this->websites[$port]['shutdown_at'] = date('Y-m-d H:i:s');
                $this->saveWebsites();
                $this->log("Service on port {$port} shut down.");
            } else {
                // Try to kill by port
                $this->safeExec("sudo fuser -k " . escapeshellarg((string)$port) . "/tcp 2>/dev/null");
                $this->printInfo("Attempted to kill process on port {$port}");
            }
        }
    }

    /**
     * Put website in sleep mode (reduce resource usage)
     */
    public function sleepWebsite(): void
    {
        $this->printHeader('Sleep Mode');

        if (empty($this->websites)) {
            $this->printWarning("No websites detected. Run port scan first.");
            return;
        }

        $port = $this->selectWebsite('Select website to put to sleep (by port): ');

        if (!$port) {
            return;
        }

        $site = $this->websites[$port];

        $this->printInfo("Sleep mode will reduce process priority and memory usage.");
        $this->printInfo("Process: {$site['process']} (PID: {$site['pid']})");

        if (!$this->config['confirm_actions'] || $this->confirmAction()) {
            if ($site['pid']) {
                // Lower process priority
                $this->safeExec("sudo renice 19 -p {$site['pid']} 2>/dev/null");

                // Limit CPU usage (if cpulimit available)
                $this->safeExec("sudo cpulimit -p {$site['pid']} -l 10 -b 2>/dev/null");

                $this->websites[$port]['status'] = 'sleeping';
                $this->websites[$port]['sleep_since'] = date('Y-m-d H:i:s');
                $this->saveWebsites();

                $this->printSuccess("Website on port {$port} is now in sleep mode.");
                $this->log("Service on port {$port} put to sleep.");
            }
        }
    }

    /**
     * Kill a process
     */
    public function killProcess(int $pid, bool $force = false): bool
    {
        if ($pid <= 0) {
            return false;
        }

        // Check if process exists
        if (!$this->processExists($pid)) {
            $this->printWarning("Process {$pid} does not exist or already terminated.");
            return true;
        }

        // Try graceful shutdown first
        $signal = $force ? SIGKILL : SIGTERM;
        $result = $this->posixAvailable ? posix_kill($pid, $signal) : false;

        if (!$result) {
            // Fallback to system kill
            $cmd = $force ? "sudo kill -9 {$pid}" : "sudo kill {$pid}";
            $this->safeExec($cmd);
        }

        // Wait for process to terminate
        $waited = 0;
        while ($waited < 10 && $this->processExists($pid)) {
            usleep(100000);
            $waited += 0.1;
        }

        if ($this->processExists($pid)) {
            $this->printError("Failed to kill process {$pid}");
            return false;
        }

        return true;
    }

    /**
     * Force kill process
     */
    private function forceKillProcess(int $pid): void
    {
        $this->printHeader("Force Kill Process");
        $this->printInfo("Attempting to kill PID: {$pid}");

        if ($this->killProcess($pid, true)) {
            $this->printSuccess("Process {$pid} killed successfully.");
        } else {
            $this->printError("Failed to kill process {$pid}.");
        }
    }

    /**
     * Check if process exists
     */
    private function processExists(int $pid): bool
    {
        if ($this->posixAvailable) {
            return posix_kill($pid, 0);
        }

        // Alternative check - safe array access
        $result = $this->safeExec("ps -p " . escapeshellarg((string)$pid) . " -o pid=");
        $hasProcess = !empty($result) && isset($result[0]) && trim($result[0]) !== '';
        return file_exists("/proc/{$pid}") || $hasProcess;
    }

    // ==================== PROCESS MANAGEMENT ====================

    /**
     * Manage processes manually
     */
    public function manageProcesses(): void
    {
        $this->printHeader('Process Management');

        echo "1. View all running processes\n";
        echo "2. Kill process by PID\n";
        echo "3. Search processes\n";
        echo "4. View process tree\n";
        echo "5. Monitor resource usage\n";
        echo "6. Find by port\n";
        echo "7. Process history\n";
        echo "0. Back\n";

        $choice = $this->readInput('Select: ');

        switch ($choice) {
            case '1':
                $this->viewAllProcesses();
                break;
            case '2':
                $this->killProcessByInput();
                break;
            case '3':
                $this->searchProcesses();
                break;
            case '4':
                $this->viewProcessTree();
                break;
            case '5':
                $this->monitorResources();
                break;
            case '6':
                $this->findProcessByPort();
                break;
            case '7':
                $this->showProcessHistory();
                break;
        }
    }

    /**
     * View all processes
     */
    private function viewAllProcesses(): void
    {
        $this->printHeader('Running Processes');
        echo "TOP 30 PROCESSES BY CPU USAGE:\n\n";
        system("ps aux --sort=-%cpu | head -35");
    }

    /**
     * Kill process by input
     */
    private function killProcessByInput(): void
    {
        $pid = (int)$this->readInput('Enter PID to kill (0 to cancel): ');

        if ($pid <= 0) {
            return;
        }

        $force = $this->readInput('Force kill? (y/N): ');
        $force = in_array(strtolower($force), ['y', 'yes']);

        if ($this->killProcess($pid, $force)) {
            $this->printSuccess("Process {$pid} terminated.");
        } else {
            $this->printError("Failed to terminate process {$pid}.");
        }
    }

    /**
     * Search processes
     */
    private function searchProcesses(): void
    {
        $term = $this->sanitizeInput($this->readInput('Search term: '));

        if (empty($term)) {
            return;
        }

        echo "\nMatching processes:\n";
        system("ps aux | grep -i " . escapeshellarg($term) . " | grep -v grep");
    }

    /**
     * View process tree
     */
    private function viewProcessTree(): void
    {
        echo "\nProcess Tree:\n";
        system("pstree -p 2>/dev/null || ps auxf");
    }

    /**
     * Monitor resources
     */
    private function monitorResources(): void
    {
        $this->printHeader('Resource Monitor');

        echo "CPU and Memory usage (top 20):\n\n";
        system("top -b -n 1 | head -30");
    }

    /**
     * Find process by port
     */
    private function findProcessByPort(): void
    {
        $port = (int)$this->readInput('Enter port number: ');

        if ($port <= 0) {
            return;
        }

        $processInfo = $this->getProcessInfo($port);

        if ($processInfo['pid']) {
            echo "\nProcess on port {$port}:\n";
            print_r($processInfo);
        } else {
            $this->printWarning("No process found on port {$port}");
        }
    }

    /**
     * Show process history
     */
    private function showProcessHistory(): void
    {
        $this->printHeader('Process History');

        $logFile = $this->config['log_file'];

        if (file_exists($logFile)) {
            $lines = $this->safeExec("tail -100 {$logFile}");

            foreach ($lines as $line) {
                echo $line . "\n";
            }
        } else {
            $this->printWarning("No log file found.");
        }
    }

    // ==================== RESTART BY TECHNOLOGY ====================

    /**
     * Restart based on technology
     */
    private function restartByTechnology(string $tech, int $port, string $command): bool
    {
        $this->printInfo("Attempting to restart {$tech} service...");

        $cwd = $this->findProjectDirectory($port, $command);
        $techLower = strtolower($tech);

        // Node.js based services
        if (in_array($techLower, ['node.js', 'react', 'next.js', 'vite', 'node.js/yarn', 'node.js/pnpm'])) {
            return $this->restartNodeService($cwd, $port);
        }

        // PHP services
        if (in_array($techLower, ['php', 'php-fpm'])) {
            return $this->restartPhpService($cwd, $port);
        }

        // Python services
        if (in_array($techLower, ['python', 'python3', 'gunicorn', 'uwsgi'])) {
            return $this->restartPythonService($cwd, $port);
        }

        // Database services
        if (in_array($techLower, ['mysql', 'mariadb', 'postgresql', 'redis', 'mongodb'])) {
            return $this->restartDatabaseService($techLower);
        }

        $this->printWarning("Auto-restart not supported for {$tech}. Please restart manually.");
        return false;
    }

    /**
     * Restart Node.js service
     */
    private function restartNodeService(?string $cwd, int $port): bool
    {
        if (!$cwd || !is_dir($cwd)) {
            $this->printError("Cannot find project directory");
            return false;
        }

        chdir($cwd);

        if (!file_exists('package.json')) {
            $this->printError("package.json not found in {$cwd}");
            return false;
        }

        $package = json_decode(file_get_contents('package.json'), true);
        if (!$package) {
            $this->printError("Failed to parse package.json");
            return false;
        }

        $scripts = $package['scripts'] ?? [];
        $script = $scripts['start'] ?? $scripts['dev'] ?? null;

        if (!$script) {
            $this->printError("No start or dev script found in package.json");
            return false;
        }

        $cmdParts = explode(' ', $script);
        $scriptName = $cmdParts[0];
        $fullCmd = "nohup npm run {$scriptName} > /tmp/node_app_{$port}.log 2>&1 &";

        exec($fullCmd);
        $this->log("Started Node.js app with command: {$fullCmd}");

        // Wait for service to start
        $attempts = 0;
        while ($attempts < 10 && !$this->isPortOpen($port, 1)) {
            usleep(500000);
            $attempts++;
        }

        if ($this->isPortOpen($port)) {
            $this->printSuccess("Node.js service started on port {$port}");
            return true;
        }

        $this->printWarning("Service started but port {$port} not responding");
        return true;
    }

    /**
     * Restart PHP service
     */
    private function restartPhpService(?string $cwd, int $port): bool
    {
        if (!$cwd) {
            $cwd = $this->currentDir;
        }

        chdir($cwd);
        $cmd = "nohup php -S localhost:{$port} > /tmp/php_server_{$port}.log 2>&1 &";
        exec($cmd);

        $this->log("Started PHP server: {$cmd}");

        $attempts = 0;
        while ($attempts < 10 && !$this->isPortOpen($port, 1)) {
            usleep(500000);
            $attempts++;
        }

        if ($this->isPortOpen($port)) {
            $this->printSuccess("PHP service started on port {$port}");
            return true;
        }

        return false;
    }

    /**
     * Restart Python service
     */
    private function restartPythonService(?string $cwd, int $port): bool
    {
        if (!$cwd) {
            $cwd = $this->currentDir;
        }

        chdir($cwd);

        $cmd = '';
        if (file_exists('manage.py')) {
            $cmd = "nohup python manage.py runserver 0.0.0.0:{$port} > /tmp/django_{$port}.log 2>&1 &";
        } elseif (file_exists('app.py')) {
            $cmd = "nohup python app.py > /tmp/flask_{$port}.log 2>&1 &";
        } elseif (file_exists('main.py')) {
            $cmd = "nohup python main.py > /tmp/python_{$port}.log 2>&1 &";
        } else {
            $cmd = "nohup python -m http.server {$port} > /tmp/python_server_{$port}.log 2>&1 &";
        }

        exec($cmd);
        $this->log("Started Python server: {$cmd}");

        $attempts = 0;
        while ($attempts < 10 && !$this->isPortOpen($port, 1)) {
            usleep(500000);
            $attempts++;
        }

        if ($this->isPortOpen($port)) {
            $this->printSuccess("Python service started on port {$port}");
            return true;
        }

        return false;
    }

    /**
     * Restart database service
     */
    private function restartDatabaseService(string $type): bool
    {
        $serviceName = match ($type) {
            'mysql' => 'mysql',
            'mariadb' => 'mariadb',
            'postgresql' => 'postgresql',
            'redis' => 'redis-server',
            'mongodb' => 'mongod',
            default => null,
        };

        if (!$serviceName) {
            return false;
        }

        $this->printInfo("Attempting to restart {$type} service...");

        // Try systemctl first
        $cmd = "sudo systemctl restart " . escapeshellarg($serviceName) . " 2>&1";
        $output = $this->safeExec($cmd);
        $outputStr = implode(' ', $output);

        // Check if restart failed
        if (!empty($outputStr) && strpos($outputStr, 'failed') !== false) {
            // systemctl failed, try direct restart
            $this->printWarning("systemctl restart failed, trying direct restart...");
        } else {
            $this->printSuccess("{$type} service restarted via systemctl");
            return true;
        }

        // Try direct restart
        $this->safeExec("sudo pkill " . escapeshellarg($serviceName));
        sleep(2);
        $cmd = "sudo nohup {$serviceName} > /tmp/{$type}.log 2>&1 &";
        exec($cmd);

        $this->printInfo("Attempted to restart {$type} directly");
        return true;
    }

    /**
     * Try to find project directory
     */
    private function findProjectDirectory(int $port, string $command): ?string
    {
        $commonDirs = [
            $this->currentDir,
            '/var/www/html',
            '/home/' . $this->getCurrentUser() . '/projects',
            '/home/' . $this->getCurrentUser(),
            '/srv',
            '/opt',
        ];

        foreach ($commonDirs as $dir) {
            if (is_dir($dir)) {
                $files = scandir($dir);
                foreach ($files as $file) {
                    if (in_array($file, ['package.json', 'composer.json', 'manage.py', 'requirements.txt', 'index.php', 'app.py', 'main.py'])) {
                        return $dir;  // Return directory, not file path
                    }
                }
            }
        }

        return null;
    }

    // ==================== SYSTEM INFORMATION ====================

    /**
     * Show system information
     */
    public function showSystemInfo(): void
    {
        $this->printHeader('System Information');

        // Memory info
        $meminfo = @file_get_contents('/proc/meminfo');
        $memData = $this->parseMemInfo($meminfo);

        // Uptime
        $uptime = $this->getUptime();

        // Disk space
        $diskData = $this->getDiskInfo();

        // CPU info
        $cpuData = $this->getCpuInfo();

        echo "╔══════════════════════════════════════════════════════════════╗\n";
        echo "║ SYSTEM OVERVIEW                                               ║\n";
        echo "╠══════════════════════════════════════════════════════════════╣\n";
        printf("║ OS:        %-45s║\n", php_uname('s') . ' ' . php_uname('r'));
        printf("║ Hostname:  %-45s║\n", gethostname());
        printf("║ Uptime:    %-45s║\n", $uptime);
        printf("║ PHP Ver:   %-45s║\n", PHP_VERSION);
        printf("║ Manager Uptime: %-43s║\n", $this->formatUptime());
        echo "╠══════════════════════════════════════════════════════════════╣\n";
        echo "║ HARDWARE                                                   ║\n";
        echo "╠══════════════════════════════════════════════════════════════╣\n";
        printf("║ CPU:       %-45s║\n", $cpuData['model'] ?? 'Unknown');
        printf("║ Cores:     %-45d║\n", $cpuData['cores'] ?? intval(shell_exec('nproc')));
        printf("║ Memory:    %s / %s (%.1f%% used)                        ║\n",
            $memData['used'], $memData['total'], $memData['percent']);
        printf("║ Disk:      %s / %s (%.1f%% used)                        ║\n",
            $diskData['used'], $diskData['total'], $diskData['percent']);
        echo "╠══════════════════════════════════════════════════════════════╣\n";
        printf("║ Websites:  %-45d║\n", count($this->websites));
        printf("║ Running:   %-45d║\n", count(array_filter($this->websites, fn($s) => ($s['status'] ?? '') === 'running')));
        printf("║ Sleeping:  %-45d║\n", count(array_filter($this->websites, fn($s) => ($s['status'] ?? '') === 'sleeping')));
        echo "╚══════════════════════════════════════════════════════════════╝\n";
    }

    /**
     * Parse memory info
     */
    private function parseMemInfo(string $meminfo): array
    {
        $data = ['total' => 'Unknown', 'used' => 'Unknown', 'percent' => 0];

        if (preg_match('/MemTotal:\s+(\d+)/', $meminfo, $totalMatch) &&
            preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $availMatch)) {

            $totalGB = round($totalMatch[1] / 1024 / 1024, 2);
            $availGB = round($availMatch[1] / 1024 / 1024, 2);
            $usedGB = round($totalGB - $availGB, 2);
            $percent = round(($usedGB / $totalGB) * 100, 1);

            $data = [
                'total' => "{$totalGB}GB",
                'used' => "{$usedGB}GB",
                'percent' => $percent,
            ];
        }

        return $data;
    }

    /**
     * Get uptime
     */
    private function getUptime(): string
    {
        $uptime = @file_get_contents('/proc/uptime');
        if ($uptime) {
            $seconds = (int)explode('.', $uptime)[0];
            return $this->formatDuration($seconds);
        }

        $uptimeCmd = trim(shell_exec('uptime -p 2>/dev/null') ?? '');
        return $uptimeCmd ?: 'Unknown';
    }

    /**
     * Format duration
     */
    private function formatDuration(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $mins = intdiv($seconds % 3600, 60);

        $parts = [];
        if ($days > 0) $parts[] = "{$days}d";
        if ($hours > 0) $parts[] = "{$hours}h";
        if ($mins > 0) $parts[] = "{$mins}m";

        return implode(' ', $parts) ?: '0m';
    }

    /**
     * Get disk info
     */
    private function getDiskInfo(): array
    {
        $free = disk_free_space('/');
        $total = disk_total_space('/');

        $usedGB = round(($total - $free) / 1024 / 1024 / 1024, 1);
        $totalGB = round($total / 1024 / 1024 / 1024, 1);
        $percent = round((($total - $free) / $total) * 100, 1);

        return [
            'used' => "{$usedGB}GB",
            'total' => "{$totalGB}GB",
            'percent' => $percent,
        ];
    }

    /**
     * Get CPU info
     */
    private function getCpuInfo(): array
    {
        $cpuInfo = @file_get_contents('/proc/cpuinfo');

        $data = ['model' => 'Unknown', 'cores' => 0];

        if ($cpuInfo) {
            if (preg_match('/model name\s*:\s*(.+)/', $cpuInfo, $modelMatch)) {
                $data['model'] = trim($modelMatch[1]);
            } elseif (preg_match('/Hardware\s*:\s*(.+)/', $cpuInfo, $hwMatch)) {
                $data['model'] = trim($hwMatch[1]);
            }

            $cores = preg_match_all('/^processor\s/m', $cpuInfo);
            $data['cores'] = $cores ?: 1;
        }

        return $data;
    }

    /**
     * Get memory usage
     */
    private function getMemoryUsage(): string
    {
        $memory = memory_get_usage(true);
        return "Memory: " . round($memory / 1024 / 1024, 2) . "MB";
    }

    /**
     * Format uptime
     */
    private function formatUptime(): string
    {
        $elapsed = (int)(microtime(true) - $this->startTime);
        return $this->formatDuration((int)$elapsed);
    }

    // ==================== CONFIGURATION ====================

    /**
     * Configure settings
     */
    public function configureSettings(): void
    {
        $this->printHeader('Configuration');

        echo "1. Change default ports\n";
        echo "2. Set scan timeout\n";
        echo "3. Toggle confirmations\n";
        echo "4. Set auto-save\n";
        echo "5. Configure notifications\n";
        echo "6. Set max retries\n";
        echo "7. View current configuration\n";
        echo "8. Reset to defaults\n";
        echo "9. Advanced settings\n";
        echo "0. Back\n";

        $choice = $this->readInput('Select: ');

        switch ($choice) {
            case '1':
                $this->setDefaultPorts();
                break;
            case '2':
                $this->setScanTimeout();
                break;
            case '3':
                $this->toggleConfirmations();
                break;
            case '4':
                $this->setAutoSave();
                break;
            case '5':
                $this->configureNotifications();
                break;
            case '6':
                $this->setMaxRetries();
                break;
            case '7':
                $this->viewConfiguration();
                break;
            case '8':
                $this->resetConfiguration();
                break;
            case '9':
                $this->advancedSettings();
                break;
        }
    }

    /**
     * Set default ports
     */
    private function setDefaultPorts(): void
    {
        $portsInput = $this->readInput('Enter default ports (comma-separated): ');
        $ports = $this->parsePortsInput($portsInput);

        if (!empty($ports)) {
            $this->config['default_ports'] = $ports;
            $this->saveConfiguration();
            $this->printSuccess("Default ports updated to: " . implode(', ', $ports));
        } else {
            $this->printError("Invalid port input");
        }
    }

    /**
     * Set scan timeout
     */
    private function setScanTimeout(): void
    {
        $timeout = (int)$this->readInput('Enter scan timeout (1-10 seconds): ');

        if ($timeout >= 1 && $timeout <= 10) {
            $this->config['scan_timeout'] = $timeout;
            $this->saveConfiguration();
            $this->printSuccess("Scan timeout set to {$timeout} seconds");
        } else {
            $this->printError("Invalid timeout. Must be between 1 and 10.");
        }
    }

    /**
     * Toggle confirmations
     */
    private function toggleConfirmations(): void
    {
        $current = $this->config['confirm_actions'] ? 'enabled' : 'disabled';
        $this->printInfo("Confirmations are currently {$current}");

        $new = $this->readInput('Enable confirmations? (y/N): ');
        $this->config['confirm_actions'] = in_array(strtolower($new), ['y', 'yes']);
        $this->saveConfiguration();

        $this->printSuccess("Confirmations " . ($this->config['confirm_actions'] ? 'enabled' : 'disabled'));
    }

    /**
     * Set auto-save
     */
    private function setAutoSave(): void
    {
        $new = $this->readInput('Enable auto-save? (y/N): ');
        $this->config['auto_save'] = in_array(strtolower($new), ['y', 'yes']);
        $this->saveConfiguration();

        $this->printSuccess("Auto-save " . ($this->config['auto_save'] ? 'enabled' : 'disabled'));
    }

    /**
     * Configure notifications
     */
    private function configureNotifications(): void
    {
        $this->printHeader('Notification Settings');

        echo "Current: " . ($this->config['enable_notifications'] ? 'Enabled' : 'Disabled') . "\n";
        if ($this->config['notification_email']) {
            echo "Email: " . $this->config['notification_email'] . "\n";
        }

        $enable = $this->readInput('Enable notifications? (y/N): ');
        $this->config['enable_notifications'] = in_array(strtolower($enable), ['y', 'yes']);

        if ($this->config['enable_notifications']) {
            $email = $this->readInput('Enter notification email: ');
            $this->config['notification_email'] = filter_var($email, FILTER_VALIDATE_EMAIL) ?: '';
        }

        $this->saveConfiguration();
        $this->printSuccess("Notification settings updated");
    }

    /**
     * Set max retries
     */
    private function setMaxRetries(): void
    {
        $retries = (int)$this->readInput('Enter max retries (1-10): ');

        if ($retries >= 1 && $retries <= 10) {
            $this->config['max_retries'] = $retries;
            $this->saveConfiguration();
            $this->printSuccess("Max retries set to {$retries}");
        } else {
            $this->printError("Invalid value. Must be between 1 and 10.");
        }
    }

    /**
     * View configuration
     */
    private function viewConfiguration(): void
    {
        $this->printHeader('Current Configuration');
        print_r($this->config);
    }

    /**
     * Reset configuration
     */
    private function resetConfiguration(): void
    {
        $confirm = $this->readInput('Reset all settings to defaults? (y/N): ');

        if (in_array(strtolower($confirm), ['y', 'yes'])) {
            $this->config = [
                'default_ports' => [80, 443, 3000, 8000, 8080, 9000, 4200, 5000, 3306, 5432, 27017, 6379],
                'scan_timeout' => 2,
                'log_file' => AVM_LOG_FILE,
                'pid_dir' => AVM_PID_DIR,
                'auto_save' => true,
                'color_output' => true,
                'confirm_actions' => true,
                'max_retries' => 3,
                'retry_delay' => 1,
                'health_check_interval' => 60,
                'notification_email' => '',
                'enable_notifications' => false,
                'allowed_users' => [],
            ];
            $this->saveConfiguration();
            $this->printSuccess("Configuration reset to defaults");
        }
    }

    /**
     * Advanced settings
     */
    private function advancedSettings(): void
    {
        $this->printHeader('Advanced Settings');

        echo "1. Set log file location\n";
        echo "2. Set PID directory\n";
        echo "3. Color output toggle\n";
        echo "4. Set health check interval\n";
        echo "5. Manage allowed users\n";
        echo "0. Back\n";

        $choice = $this->readInput('Select: ');

        switch ($choice) {
            case '1':
                $logFile = $this->readInput('Enter log file path: ');
                if (!empty($logFile)) {
                    $this->config['log_file'] = $logFile;
                    $this->saveConfiguration();
                    $this->printSuccess("Log file updated");
                }
                break;
            case '2':
                $pidDir = $this->readInput('Enter PID directory path: ');
                if (!empty($pidDir)) {
                    $this->config['pid_dir'] = rtrim($pidDir, '/') . '/';
                    $this->setupPidDir();
                    $this->saveConfiguration();
                    $this->printSuccess("PID directory updated");
                }
                break;
            case '3':
                $color = $this->readInput('Enable color output? (y/N): ');
                $this->config['color_output'] = in_array(strtolower($color), ['y', 'yes']);
                $this->saveConfiguration();
                break;
            case '4':
                $interval = (int)$this->readInput('Health check interval (seconds): ');
                if ($interval > 0) {
                    $this->config['health_check_interval'] = $interval;
                    $this->saveConfiguration();
                    $this->printSuccess("Health check interval set to {$interval}s");
                }
                break;
        }
    }

    // ==================== HEALTH MONITOR ====================

    /**
     * Health monitor menu
     */
    public function healthMonitor(): void
    {
        $this->printHeader('Health Monitor');

        echo "1. Run health check now\n";
        echo "2. Start continuous monitoring\n";
        echo "3. View health history\n";
        echo "4. Configure health alerts\n";
        echo "5. Check specific service\n";
        echo "0. Back\n";

        $choice = $this->readInput('Select: ');

        switch ($choice) {
            case '1':
                $this->healthCheck();
                break;
            case '2':
                $this->startContinuousMonitoring();
                break;
            case '3':
                $this->viewHealthHistory();
                break;
            case '4':
                $this->configureHealthAlerts();
                break;
            case '5':
                $this->checkSpecificService();
                break;
        }
    }

    /**
     * Run health check on all services
     */
    public function healthCheck(): array
    {
        $this->printHeader('Health Check');

        if (empty($this->websites)) {
            $this->printWarning("No websites to check. Run port scan first.");
            return [];
        }

        $results = [];
        $timestamp = date('Y-m-d H:i:s');

        foreach ($this->websites as $site) {
            // Get the actual port from site data, not the array key
            $port = $site['port'] ?? 0;
            if ($port <= 0) {
                continue;
            }

            $status = $this->checkServiceHealth($port);
            $site['health_status'] = $status['status'];
            $site['latency_ms'] = $status['latency'];
            $site['last_check'] = $timestamp;

            // Update using port as key
            $this->websites[$port] = $site;

            $results[$port] = $status;

            $colorCode = $status['status'] === 'healthy' ? "\033[32m" : ($status['status'] === 'unhealthy' ? "\033[31m" : "\033[33m");
            echo "Port {$port}: {$colorCode}" . strtoupper($status['status']) . "\033[0m";

            if ($status['latency'] > 0) {
                echo " ({$status['latency']}ms)";
            }

            if (!empty($status['message'])) {
                echo " - {$status['message']}";
            }
            echo "\n";
        }

        // Save results
        $this->healthHistory[$timestamp] = $results;
        $this->saveHealthHistory();

        if ($this->config['auto_save']) {
            $this->saveWebsites();
        }

        $healthy = count(array_filter($results, fn($r) => $r['status'] === 'healthy'));
        $total = count($results);

        $this->printInfo("\nHealth check complete: {$healthy}/{$total} services healthy");

        return $results;
    }

    /**
     * Check service health
     */
    private function checkServiceHealth(int $port): array
    {
        $status = 'unknown';
        $latency = 0;
        $message = '';

        // Check if port is open
        if (!$this->isPortOpen($port, 3)) {
            $status = 'unhealthy';
            $message = 'Port not responding';
        } else {
            // Measure latency
            $start = microtime(true);
            $errno = 0;
            $errstr = '';
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 3);

            if ($socket) {
                $latency = round((microtime(true) - $start) * 1000, 2);
                fclose($socket);

                if ($latency < 100) {
                    $status = 'healthy';
                } elseif ($latency < 500) {
                    $status = 'degraded';
                    $message = 'Slow response';
                } else {
                    $status = 'degraded';
                    $message = 'High latency';
                }
            } else {
                $status = 'unhealthy';
                $message = "Connection failed: {$errstr} (errno: {$errno})";
            }
        }

        return [
            'status' => $status,
            'latency' => $latency,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Start continuous monitoring
     */
    private function startContinuousMonitoring(): void
    {
        $this->printHeader('Continuous Monitoring');
        $this->printInfo("Starting continuous monitoring... Press Ctrl+C to stop.");

        $interval = $this->config['health_check_interval'];
        $iteration = 0;

        while (true) {
            $iteration++;
            echo "\n\033[2m--- Iteration {$iteration} ---\033[0m\n";

            $this->healthCheck();

            // Check for alerts
            $this->checkAlerts();

            sleep($interval);
        }
    }

    /**
     * Check for alerts
     */
    private function checkAlerts(): void
    {
        foreach ($this->websites as $site) {
            $port = $site['port'] ?? 0;
            if ($port <= 0) {
                continue;
            }
            $status = $site['health_status'] ?? 'unknown';

            if ($status === 'unhealthy') {
                $this->sendNotification("ALERT: Service on port {$port} is unhealthy!");

                if ($this->config['confirm_actions']) {
                    $this->printError("ALERT: Port {$port} is unhealthy!");
                    $restart = $this->readInput("Restart service? (y/N): ");
                    if (in_array(strtolower($restart), ['y', 'yes'])) {
                        $this->restartByPort($port);
                    }
                }
            }
        }
    }

    /**
     * Send notification
     */
    private function sendNotification(string $message): void
    {
        if (!$this->config['enable_notifications'] || empty($this->config['notification_email'])) {
            return;
        }

        $subject = "AnimeVerse Manager Alert";
        $headers = "From: no-reply@localhost\r\n";

        @mail($this->config['notification_email'], $subject, $message, $headers);

        $this->log("Notification sent: {$message}");
    }

    /**
     * View health history
     */
    private function viewHealthHistory(): void
    {
        $this->printHeader('Health History');

        if (empty($this->healthHistory)) {
            $this->printWarning("No health history available.");
            return;
        }

        // Show last 10 entries
        $entries = array_slice($this->healthHistory, -10, 10, true);

        foreach ($entries as $timestamp => $results) {
            echo "\n\033[1m{$timestamp}\033[0m\n";

            foreach ($results as $port => $result) {
                $color = $result['status'] === 'healthy' ? "\033[32m" : ($result['status'] === 'unhealthy' ? "\033[31m" : "\033[33m");
                echo "  Port {$port}: {$color}" . strtoupper($result['status']) . "\033[0m";

                if ($result['latency'] > 0) {
                    echo " ({$result['latency']}ms)";
                }
                echo "\n";
            }
        }
    }

    /**
     * Configure health alerts
     */
    private function configureHealthAlerts(): void
    {
        $this->printHeader('Health Alert Configuration');

        echo "1. Enable/disable auto-restart on failure\n";
        echo "2. Set latency threshold (ms)\n";
        echo "3. Configure email alerts\n";
        echo "0. Back\n";

        $choice = $this->readInput('Select: ');

        switch ($choice) {
            case '1':
                $current = $this->config['auto_restart_on_failure'] ?? false;
                $this->printInfo("Auto-restart is currently " . ($current ? 'enabled' : 'disabled'));
                $enable = $this->readInput('Enable auto-restart on failure? (y/N): ');
                $this->config['auto_restart_on_failure'] = in_array(strtolower($enable), ['y', 'yes']);
                $this->saveConfiguration();
                $this->printSuccess("Auto-restart " . ($this->config['auto_restart_on_failure'] ? 'enabled' : 'disabled'));
                break;

            case '2':
                $current = $this->config['latency_threshold_ms'] ?? 500;
                $this->printInfo("Current latency threshold: {$current}ms");
                $threshold = (int)$this->readInput('Enter latency threshold in ms (100-5000): ');
                if ($threshold >= 100 && $threshold <= 5000) {
                    $this->config['latency_threshold_ms'] = $threshold;
                    $this->saveConfiguration();
                    $this->printSuccess("Latency threshold set to {$threshold}ms");
                } else {
                    $this->printError("Invalid threshold. Must be between 100 and 5000.");
                }
                break;

            case '3':
                $this->configureNotifications();
                break;

            case '0':
                return;

            default:
                $this->printError("Invalid choice.");
        }
    }

    /**
     * Check specific service
     */
    private function checkSpecificService(): void
    {
        $port = (int)$this->readInput('Enter port to check: ');

        if ($port <= 0) {
            return;
        }

        $this->printInfo("Checking port {$port}...");

        $result = $this->checkServiceHealth($port);

        echo "\nResults:\n";
        echo "Status: " . strtoupper($result['status']) . "\n";
        echo "Latency: {$result['latency']}ms\n";
        echo "Message: {$result['message']}\n";
        echo "Timestamp: {$result['timestamp']}\n";
    }

    /**
     * Save health history
     */
    private function saveHealthHistory(): void
    {
        $historyFile = $this->config['pid_dir'] . 'health_history.json';

        // Keep only last 100 entries
        if (count($this->healthHistory) > 100) {
            $this->healthHistory = array_slice($this->healthHistory, -100, null, true);
        }

        file_put_contents($historyFile, json_encode($this->healthHistory, JSON_PRETTY_PRINT));
    }

    // ==================== LOG VIEWER ====================

    /**
     * View logs
     */
    public function viewLogs(): void
    {
        $this->printHeader('Log Viewer');

        echo "1. View recent logs\n";
        echo "2. Search logs\n";
        echo "3. View errors only\n";
        echo "4. Clear logs\n";
        echo "5. Export logs\n";
        echo "0. Back\n";

        $choice = $this->readInput('Select: ');

        switch ($choice) {
            case '1':
                $this->showRecentLogs();
                break;
            case '2':
                $this->searchLogs();
                break;
            case '3':
                $this->showErrorLogs();
                break;
            case '4':
                $this->clearLogs();
                break;
            case '5':
                $this->exportLogs();
                break;
        }
    }

    /**
     * Show recent logs
     */
    private function showRecentLogs(): void
    {
        $logFile = $this->config['log_file'];

        if (!file_exists($logFile)) {
            $this->printWarning("Log file not found.");
            return;
        }

        $lines = (int)$this->readInput('Number of lines (default 50): ');
        $lines = $lines ?: 50;

        $output = $this->safeExec("tail -{$lines} {$logFile}");

        foreach ($output as $line) {
            // Color code based on log level
            if (strpos($line, 'ERROR') !== false || strpos($line, 'error') !== false) {
                echo "\033[31m{$line}\033[0m\n";
            } elseif (strpos($line, 'WARNING') !== false || strpos($line, 'warning') !== false) {
                echo "\033[33m{$line}\033[0m\n";
            } elseif (strpos($line, 'SUCCESS') !== false || strpos($line, 'success') !== false) {
                echo "\033[32m{$line}\033[0m\n";
            } else {
                echo "{$line}\n";
            }
        }
    }

    /**
     * Search logs
     */
    private function searchLogs(): void
    {
        $term = $this->readInput('Search term: ');

        if (empty($term)) {
            return;
        }

        $logFile = $this->config['log_file'];

        if (!file_exists($logFile)) {
            return;
        }

        $output = $this->safeExec("grep -i '{$term}' {$logFile} | tail -100");

        foreach ($output as $line) {
            echo "{$line}\n";
        }
    }

    /**
     * Show error logs only
     */
    private function showErrorLogs(): void
    {
        $logFile = $this->config['log_file'];

        if (!file_exists($logFile)) {
            return;
        }

        $output = $this->safeExec("grep -iE '(error|failed|exception)' {$logFile} | tail -50");

        foreach ($output as $line) {
            echo "\033[31m{$line}\033[0m\n";
        }
    }

    /**
     * Clear logs
     */
    private function clearLogs(): void
    {
        $confirm = $this->readInput('Clear all logs? This cannot be undone. (y/N): ');

        if (in_array(strtolower($confirm), ['y', 'yes'])) {
            $logFile = $this->config['log_file'];
            file_put_contents($logFile, '');
            $this->printSuccess("Logs cleared");
        }
    }

    /**
     * Export logs
     */
    private function exportLogs(): void
    {
        $logFile = $this->config['log_file'];

        if (!file_exists($logFile)) {
            $this->printWarning("No logs to export.");
            return;
        }

        $filename = 'avmanager_logs_' . date('Y-m-d_His') . '.txt';

        copy($logFile, $this->currentDir . '/' . $filename);

        $this->printSuccess("Logs exported to: {$filename}");
    }

    // ==================== BACKUP/RESTORE ====================

    /**
     * Backup/Restore menu
     */
    public function backupRestore(): void
    {
        $this->printHeader('Backup & Restore');

        echo "1. Create backup\n";
        echo "2. Restore from backup\n";
        echo "3. List backups\n";
        echo "4. Schedule automatic backups\n";
        echo "0. Back\n";

        $choice = $this->readInput('Select: ');

        switch ($choice) {
            case '1':
                $this->createBackup();
                break;
            case '2':
                $this->restoreFromBackup();
                break;
            case '3':
                $this->listBackups();
                break;
            case '4':
                $this->scheduleBackups();
                break;
        }
    }

    /**
     * Create backup
     */
    private function createBackup(): void
    {
        $timestamp = date('Y-m-d_His');
        $filename = 'avmanager_backup_' . $timestamp . '.json';

        $backupData = [
            'timestamp' => $timestamp,
            'version' => AVM_VERSION,
            'config' => $this->config,
            'websites' => $this->websites,
            'health_history' => $this->healthHistory,
            'php_version' => PHP_VERSION,
        ];

        $filepath = $this->config['pid_dir'] . $filename;

        if (file_put_contents($filepath, json_encode($backupData, JSON_PRETTY_PRINT))) {
            $this->printSuccess("Backup created: {$filepath}");
            $this->log("Backup created: {$filename}");
        } else {
            $this->printError("Failed to create backup.");
        }
    }

    /**
     * Restore from backup
     */
    private function restoreFromBackup(): void
    {
        $this->listBackups();

        $filename = $this->readInput('Enter backup filename to restore: ');

        if (empty($filename)) {
            return;
        }

        $filepath = $this->config['pid_dir'] . $filename;

        if (!file_exists($filepath)) {
            $this->printError("Backup file not found: {$filepath}");
            return;
        }

        $confirm = $this->readInput('This will overwrite current data. Continue? (y/N): ');

        if (!in_array(strtolower($confirm), ['y', 'yes'])) {
            return;
        }

        $data = json_decode(file_get_contents($filepath), true);

        if ($data) {
            if (isset($data['config'])) {
                $this->config = array_merge($this->config, $data['config']);
            }
            if (isset($data['websites'])) {
                $this->websites = $data['websites'];
            }
            if (isset($data['health_history'])) {
                $this->healthHistory = $data['health_history'];
            }

            $this->saveConfiguration();
            $this->saveWebsites();
            $this->saveHealthHistory();

            $this->printSuccess("Restore completed successfully!");
            $this->log("Restored from backup: {$filename}");
        } else {
            $this->printError("Failed to parse backup file.");
        }
    }

    /**
     * List backups
     */
    private function listBackups(): void
    {
        $this->printHeader('Available Backups');

        $files = glob($this->config['pid_dir'] . 'avmanager_backup_*.json');

        if (empty($files)) {
            $this->printWarning("No backups found.");
            return;
        }

        rsort($files);

        foreach ($files as $index => $file) {
            $filename = basename($file);
            $size = round(filesize($file) / 1024, 1);
            $mtime = date('Y-m-d H:i:s', filemtime($file));

            printf("%2d. %-40s %6sKB  %s\n", $index + 1, $filename, $size, $mtime);
        }
    }

    /**
     * Schedule backups
     */
    private function scheduleBackups(): void
    {
        $this->printHeader('Schedule Backups');

        echo "1. Daily backup\n";
        echo "2. Weekly backup\n";
        echo "3. Disable scheduled backups\n";
        echo "0. Back\n";

        $choice = $this->readInput('Select: ');

        $cronSchedule = match ($choice) {
            '1' => '0 2 * * *',
            '2' => '0 2 * * 0',
            '3' => '',
            default => null,
        };

        if ($cronSchedule !== null) {
            $this->config['backup_schedule'] = $cronSchedule;
            $this->saveConfiguration();

            if ($cronSchedule) {
                $this->printSuccess("Scheduled backup configured");
            } else {
                $this->printInfo("Scheduled backups disabled");
            }
        }
    }

    // ==================== EXPORT/IMPORT ====================

    /**
     * Export/Import menu
     */
    public function exportImport(): void
    {
        $this->printHeader('Export & Import');

        echo "1. Export websites to CSV\n";
        echo "2. Import websites from CSV\n";
        echo "3. Export configuration\n";
        echo "4. Import configuration\n";
        echo "5. Generate report\n";
        echo "0. Back\n";

        $choice = $this->readInput('Select: ');

        switch ($choice) {
            case '1':
                $this->exportWebsitesCsv();
                break;
            case '2':
                $this->importWebsitesCsv();
                break;
            case '3':
                $this->exportConfiguration();
                break;
            case '4':
                $this->importConfiguration();
                break;
            case '5':
                $this->generateReport();
                break;
        }
    }

    /**
     * Export websites to CSV
     */
    private function exportWebsitesCsv(): void
    {
        if (empty($this->websites)) {
            $this->printWarning("No websites to export.");
            return;
        }

        $filename = 'websites_export_' . date('Y-m-d_His') . '.csv';

        $handle = fopen($filename, 'w');
        fputcsv($handle, ['Port', 'PID', 'Process', 'User', 'Technology', 'Status', 'URL', 'Memory(MB)', 'CPU(%)', 'Detected At']);

        foreach ($this->websites as $site) {
            fputcsv($handle, [
                $site['port'],
                $site['pid'] ?? '',
                $site['process'] ?? '',
                $site['user'] ?? '',
                $site['technology'] ?? '',
                $site['status'] ?? '',
                $site['url'] ?? '',
                $site['memory_mb'] ?? 0,
                $site['cpu_percent'] ?? 0,
                $site['detected_at'] ?? '',
            ]);
        }

        fclose($handle);

        $this->printSuccess("Exported to: {$filename}");
    }

    /**
     * Import websites from CSV
     */
    private function importWebsitesCsv(): void
    {
        $filename = $this->readInput('Enter CSV filename: ');

        if (!file_exists($filename)) {
            $this->printError("File not found: {$filename}");
            return;
        }

        $handle = fopen($filename, 'r');
        $headers = fgetcsv($handle);

        $imported = 0;

        while (($row = fgetcsv($handle)) !== false) {
            // Validate row has correct number of columns
            if (count($headers) !== count($row)) {
                $this->printWarning("Skipping malformed row (column mismatch)");
                continue;
            }

            $data = array_combine($headers, $row);

            if ($data === false) {
                continue; // Skip if combine fails
            }

            $port = (int)($data['Port'] ?? 0);

            // Validate port number
            if ($port <= 0 || $port > 65535) {
                continue;
            }

            if ($port > 0) {
                $this->websites[$port] = [
                    'port' => $port,
                    'pid' => !empty($data['PID']) ? (int)$data['PID'] : null,
                    'process' => $data['Process'] ?? 'Unknown',
                    'user' => $data['User'] ?? 'Unknown',
                    'technology' => $data['Technology'] ?? 'Unknown',
                    'status' => $data['Status'] ?? 'unknown',
                    'url' => $data['URL'] ?? "localhost:{$port}",
                    'memory_mb' => (float)($data['Memory(MB)'] ?? 0),
                    'cpu_percent' => (float)($data['CPU(%)'] ?? 0),
                    'detected_at' => $data['Detected At'] ?? date('Y-m-d H:i:s'),
                    'imported_at' => date('Y-m-d H:i:s'),
                ];

                $imported++;
            }
        }

        fclose($handle);

        $this->saveWebsites();
        $this->printSuccess("Imported {$imported} websites.");
    }

    /**
     * Export configuration
     */
    private function exportConfiguration(): void
    {
        $filename = 'avmanager_config_' . date('Y-m-d_His') . '.json';

        file_put_contents($filename, json_encode($this->config, JSON_PRETTY_PRINT));

        $this->printSuccess("Configuration exported to: {$filename}");
    }

    /**
     * Import configuration
     */
    private function importConfiguration(): void
    {
        $filename = $this->readInput('Enter configuration filename: ');

        if (!file_exists($filename)) {
            $this->printError("File not found: {$filename}");
            return;
        }

        $config = json_decode(file_get_contents($filename), true);

        if ($config) {
            $this->config = array_merge($this->config, $config);
            $this->saveConfiguration();
            $this->printSuccess("Configuration imported.");
        } else {
            $this->printError("Invalid configuration file.");
        }
    }

    /**
     * Generate report
     */
    private function generateReport(): void
    {
        $filename = 'avmanager_report_' . date('Y-m-d_His') . '.txt';

        $report = [];
        $report[] = "=========================================";
        $report[] = "AnimeVerse Website Manager Report";
        $report[] = "Generated: " . date('Y-m-d H:i:s');
        $report[] = "=========================================";
        $report[] = "";
        $report[] = "SUMMARY";
        $report[] = "--------";
        $report[] = "Total Websites: " . count($this->websites);
        $report[] = "Running: " . count(array_filter($this->websites, fn($s) => ($s['status'] ?? '') === 'running'));
        $report[] = "Sleeping: " . count(array_filter($this->websites, fn($s) => ($s['status'] ?? '') === 'sleeping'));
        $report[] = "Stopped: " . count(array_filter($this->websites, fn($s) => ($s['status'] ?? '') === 'stopped'));
        $report[] = "";
        $report[] = "WEBSITES";
        $report[] = "--------";

        foreach ($this->websites as $site) {
            $report[] = "Port {$site['port']}:";
            $report[] = "  Process: {$site['process']}";
            $report[] = "  Technology: {$site['technology']}";
            $report[] = "  Status: {$site['status']}";
            $report[] = "  URL: {$site['url']}";
            $report[] = "  Memory: {$site['memory_mb']}MB";
            $report[] = "  CPU: {$site['cpu_percent']}%";
            $report[] = "";
        }

        file_put_contents($filename, implode("\n", $report));

        $this->printSuccess("Report generated: {$filename}");
    }

    // ==================== AUTO DISCOVERY ====================

    /**
     * Auto discover services
     */
    public function autoDiscoverServices(): void
    {
        $this->printHeader('Auto-Discover Services');

        $this->printInfo("Discovering services...");

        // Scan common ports
        $this->scanPorts();

        // Check for known project locations
        $projects = $this->scanProjectDirectories();

        if (!empty($projects)) {
            $this->printInfo("\nFound projects:");
            foreach ($projects as $project) {
                echo "  - {$project['path']} ({$project['type']})\n";
            }
        }

        // Check for known process patterns
        $processes = $this->scanRunningProcesses();

        if (!empty($processes)) {
            $this->printInfo("\nRunning web services:");
            foreach ($processes as $proc) {
                echo "  - PID {$proc['pid']}: {$proc['command']} (Port: {$proc['port']})\n";
            }
        }

        $this->printSuccess("Discovery complete!");
    }

    /**
     * Scan project directories
     */
    private function scanProjectDirectories(): array
    {
        $projects = [];
        $dirs = [
            '/home',
            '/var/www',
            '/srv',
            '/opt',
            $this->currentDir,
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $items = @scandir($dir);
            if (!$items) {
                continue;
            }

            foreach ($items as $item) {
                if ($item[0] === '.') {
                    continue;
                }

                $path = $dir . '/' . $item;

                if (is_dir($path)) {
                    $type = $this->detectProjectType($path);
                    if ($type) {
                        $projects[] = [
                            'path' => $path,
                            'type' => $type,
                            'name' => $item,
                        ];
                    }
                }
            }
        }

        return $projects;
    }

    /**
     * Detect project type
     */
    private function detectProjectType(string $path): ?string
    {
        $indicators = [
            'package.json' => 'Node.js',
            'composer.json' => 'PHP',
            'requirements.txt' => 'Python',
            'manage.py' => 'Django',
            'app.py' => 'Python/Flask',
            'pom.xml' => 'Java/Maven',
            'build.gradle' => 'Java/Gradle',
            'go.mod' => 'Go',
            'Cargo.toml' => 'Rust',
            'Gemfile' => 'Ruby',
            'yarn.lock' => 'Node.js/Yarn',
            'package-lock.json' => 'Node.js',
            'next.config.js' => 'Next.js',
            'vite.config.js' => 'Vite',
        ];

        foreach ($indicators as $file => $type) {
            if (file_exists($path . '/' . $file)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Scan running processes
     */
    private function scanRunningProcesses(): array
    {
        $processes = [];
        $patterns = [
            'node' => '/node/',
            'npm' => '/npm/',
            'php' => '/php/',
            'python' => '/python/',
            'nginx' => '/nginx/',
            'apache' => '/apache2?/',
            'docker' => '/docker/',
        ];

        $output = $this->safeExec("ps aux 2>/dev/null");

        foreach ($output as $line) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line) && strpos($line, 'grep') === false) {
                    if (preg_match('/(\d+)/', $line, $matches)) {
                        $pid = (int)$matches[1];

                        // Check for listening ports
                        $portOutput = $this->safeExec("ss -tlnp 2>/dev/null | grep {$pid}");

                        foreach ($portOutput as $portLine) {
                            if (preg_match('/:(\d+)/', $portLine, $portMatch)) {
                                $processes[] = [
                                    'pid' => $pid,
                                    'line' => trim($line),
                                    'port' => (int)$portMatch[1],
                                ];
                            }
                        }
                    }
                    break;
                }
            }
        }

        return $processes;
    }

    // ==================== DEPENDENCIES ====================

    /**
     * Show service dependencies
     */
    public function showDependencies(): void
    {
        $this->printHeader('Service Dependencies');

        echo "1. View dependency map\n";
        echo "2. Check port conflicts\n";
        echo "3. Find process dependencies\n";
        echo "4. Restart dependent services\n";
        echo "0. Back\n";

        $choice = $this->readInput('Select: ');

        switch ($choice) {
            case '1':
                $this->showDependencyMap();
                break;
            case '2':
                $this->checkPortConflicts();
                break;
            case '3':
                $this->findProcessDependencies();
                break;
            case '4':
                $this->restartDependentServices();
                break;
        }
    }

    /**
     * Show dependency map
     */
    private function showDependencyMap(): void
    {
        $this->printHeader('Dependency Map');

        echo "\nCommon Dependencies:\n";
        echo "  Port 3306 (MySQL) <-- Port 8080 (App)\n";
        echo "  Port 5432 (PostgreSQL) <-- Port 3000 (App)\n";
        echo "  Port 6379 (Redis) <-- Port 9000 (App)\n";
        echo "  Port 27017 (MongoDB) <-- Port 3000 (App)\n";

        echo "\nYour Services:\n";
        foreach ($this->websites as $site) {
            echo "  Port {$site['port']}: {$site['technology']}\n";
        }
    }

    /**
     * Check port conflicts
     */
    private function checkPortConflicts(): void
    {
        $this->printHeader('Port Conflicts');

        $ports = array_keys($this->websites);
        $duplicates = array_count_values($ports);
        $conflicts = array_filter($duplicates, fn($c) => $c > 1);

        if (empty($conflicts)) {
            $this->printSuccess("No port conflicts detected.");
        } else {
            foreach ($conflicts as $port => $count) {
                $this->printWarning("Port {$port} has {$count} services!");
            }
        }

        // Check for common reserved ports
        $reserved = [80, 443, 22, 25, 53];
        foreach ($reserved as $port) {
            if (in_array($port, $ports)) {
                $this->printInfo("Port {$port} is in use (HTTP/HTTPS/SSH/DNS)");
            }
        }
    }

    /**
     * Find process dependencies
     */
    private function findProcessDependencies(): void
    {
        $this->printHeader('Process Dependencies');

        $pid = (int)$this->readInput('Enter PID to check: ');

        if ($pid <= 0) {
            return;
        }

        echo "\nParent process:\n";
        $ppid = $this->safeExec("ps -o ppid= -p {$pid}");
        if (!empty($ppid)) {
            echo "  PPID: " . trim($ppid[0]) . "\n";
        }

        echo "\nOpen files:\n";
        $lsof = $this->safeExec("lsof -p {$pid} 2>/dev/null");

        $connections = array_filter($lsof, fn($l) => strpos($l, 'IPv4') !== false || strpos($l, 'IPv6') !== false);

        foreach (array_slice($connections, 0, 10) as $conn) {
            echo "  " . trim($conn) . "\n";
        }

        echo "\nChild processes:\n";
        $children = $this->safeExec("pgrep -P {$pid} 2>/dev/null");
        foreach ($children as $child) {
            echo "  PID: " . trim($child) . "\n";
        }
    }

    /**
     * Restart dependent services
     */
    private function restartDependentServices(): void
    {
        $this->printHeader('Restart Dependent Services');

        $this->printInfo("Select a service to restart along with its dependencies:");
        echo "\n";

        $index = 1;
        foreach ($this->websites as $site) {
            $port = $site['port'] ?? 0;
            if ($port > 0) {
                $tech = $site['technology'] ?? 'Unknown';
                echo "{$index}. {$tech} (Port {$port})\n";
                $index++;
            }
        }

        $selection = (int)$this->readInput('\nSelect number: ');

        // Find the port by index
        $port = 0;
        $idx = 1;
        foreach ($this->websites as $site) {
            if ($idx === $selection) {
                $port = $site['port'] ?? 0;
                break;
            }
            $idx++;
        }

        if ($port <= 0 || !isset($this->websites[$port])) {
            $this->printError("Invalid selection.");
            return;
        }

        $this->printInfo("Restarting service on port {$port}...");

        if ($this->restartByPort($port)) {
            $this->printSuccess("Service restarted successfully!");
        }
    }

    // ==================== DAEMON MODE ====================

    /**
     * Run as daemon
     */
    private function runAsDaemon(): void
    {
        $this->printHeader('Daemon Mode');
        $this->printInfo("Running in daemon mode. Press Ctrl+C to stop.");

        $interval = $this->config['health_check_interval'];

        while (true) {
            // Check all services
            $this->healthCheck();

            // Sleep between checks
            sleep($interval);
        }
    }

    /**
     * Quick status
     */
    private function quickStatus(): void
    {
        echo "AnimeVerse Website Manager v" . AVM_VERSION . "\n\n";

        echo "Websites: " . count($this->websites) . "\n";

        $running = count(array_filter($this->websites, fn($s) => ($s['status'] ?? '') === 'running'));
        echo "Running: {$running}\n";

        if (!empty($this->websites)) {
            echo "\nServices:\n";
            foreach ($this->websites as $site) {
                // Get actual port from site data
                $port = $site['port'] ?? 0;
                if ($port <= 0) {
                    continue;
                }
                $status = ($site['status'] ?? 'unknown') === 'running' ? '●' : '○';
                $tech = $site['technology'] ?? 'Unknown';
                echo "  {$status} Port {$port}: {$tech}\n";
            }
        }
    }

    // ==================== UTILITY FUNCTIONS ====================

    /**
     * Select a website from list
     */
    private function selectWebsite(string $prompt): ?int
    {
        $this->listWebsites();

        $port = (int)$this->readInput($prompt);

        if (isset($this->websites[$port])) {
            return $port;
        }

        $this->printError("Invalid port number.");
        return null;
    }

    /**
     * Confirm action
     */
    private function confirmAction(): bool
    {
        $response = strtolower($this->readInput('Confirm? (y/N): '));
        return in_array($response, ['y', 'yes', '1']);
    }

    /**
     * Print colored message
     */
    private function printColored(string $message, string $color, bool $bold = false): void
    {
        if (!$this->config['color_output']) {
            echo $message . "\n";
            return;
        }

        $colorCodes = [
            'black' => '30',
            'red' => '31',
            'green' => '32',
            'yellow' => '33',
            'blue' => '34',
            'magenta' => '35',
            'cyan' => '36',
            'white' => '37',
            'light_cyan' => '96',
        ];

        $code = $colorCodes[$color] ?? '37';
        $boldCode = $bold ? '1;' : '';

        echo "\033[{$boldCode}{$code}m{$message}\033[0m\n";
    }

    /**
     * Print header
     */
    private function printHeader(string $text): void
    {
        $width = 60;
        echo "\n\033[1;34m" . str_repeat('=', $width) . "\033[0m\n";
        echo "\033[1;34m" . str_pad($text, $width, ' ', STR_PAD_BOTH) . "\033[0m\n";
        echo "\033[1;34m" . str_repeat('=', $width) . "\033[0m\n";
    }

    /**
     * Read a single arrow key from stdin
     * Returns: 'UP', 'DOWN', 'LEFT', 'RIGHT', 'ENTER', 'ESC', or the character pressed
     */
    private function readArrowKey(): string
    {
        // Read single character
        $char = fread(STDIN, 1);
        
        if ($char === false || strlen($char) === 0) {
            return '';
        }

        // Check for escape sequence (arrow keys start with ESC)
        if (ord($char) === 27) {
            // Read the next character
            $char2 = fread(STDIN, 1);
            if ($char2 === false || strlen($char2) === 0) {
                return 'ESC';
            }
            
            if (ord($char2) === 91) {
                // Read the third character
                $char3 = fread(STDIN, 1);
                if ($char3 === false || strlen($char3) === 0) {
                    return 'ESC';
                }
                
                switch ($char3) {
                    case 'A': return 'UP';
                    case 'B': return 'DOWN';
                    case 'C': return 'RIGHT';
                    case 'D': return 'LEFT';
                    case 'F': return 'END';
                    case 'H': return 'HOME';
                }
            } else {
                // ESC key alone
                return 'ESC';
            }
        }

        // Handle special characters
        $ord = ord($char);
        if ($ord === 10 || $ord === 13) {
            return 'ENTER'; // Enter/Return key
        }

        // Handle letter keys (including navigation shortcuts)
        $lowerChar = strtolower($char);
        if (in_array($lowerChar, ['k', 'j', 'h', 'l', 'q', 'x'])) {
            return $lowerChar;
        }

        return $char;
    }

    /**
     * Enable raw mode for terminal
     * @return bool True if raw mode was successfully enabled
     */
    private function enableRawMode(): bool
    {
        // Check if we have a TTY
        if (!$this->isInteractiveTerminal()) {
            return false;
        }

        // Set terminal to raw mode
        system('stty -icanon -echo 2>/dev/null');
        
        return true;
    }

    /**
     * Disable raw mode for terminal
     */
    private function disableRawMode(): void
    {
        // Restore terminal settings
        system('stty icanon echo 2>/dev/null 2>/dev/null');
    }

    /**
     * Clear screen
     */
    private function clearScreen(): void
    {
        echo "\033[2J\033[H";
    }

    /**
     * Sanitize user input - trim, escape, and limit length
     */
    private function sanitizeInput(string $input): string
    {
        $input = trim($input);
        $input = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Limit length to prevent DoS
        return substr($input, 0, 255);
    }

    /**
     * Validate port number (1-65535)
     */
    private function validatePort(int $port): bool
    {
        return $port > 0 && $port <= 65535;
    }

    /**
     * Validate email address
     */
    private function validateEmail(string $email): bool
    {
        if (empty($email)) {
            return false;
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate file path for security (prevent path traversal)
     */
    private function validatePath(string $path): bool
    {
        // Prevent path traversal
        if (strpos($path, '..') !== false || strpos($path, '/../') !== false) {
            return false;
        }
        // Only allow safe characters
        return preg_match('/^[a-zA-Z0-9_\-\.\/]+$/', $path) === 1;
    }

    /**
     * Safe JSON decode with error handling
     */
    private function safeJsonDecode(string $json): ?array
    {
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log("JSON decode error: " . json_last_error_msg());
            return null;
        }
        return is_array($data) ? $data : null;
    }

    /**
     * Safe JSON encode with error handling
     */
    private function safeJsonEncode(array $data): ?string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log("JSON encode error: " . json_last_error_msg());
            return null;
        }
        return $json;
    }

    /**
     * Print success message
     */
    private function printSuccess(string $message): void
    {
        $this->printColored("[✓] {$message}", 'green');
    }

    /**
     * Print error message
     */
    private function printError(string $message): void
    {
        $this->printColored("[✗] {$message}", 'red');
    }

    /**
     * Print warning message
     */
    private function printWarning(string $message): void
    {
        $this->printColored("[!] {$message}", 'yellow');
    }

    /**
     * Print info message
     */
    private function printInfo(string $message): void
    {
        $this->printColored("[i] {$message}", 'cyan');
    }

    /**
     * Show progress bar
     */
    private function showProgress(int $current, int $total, string $label = ''): void
    {
        $width = 40;
        $percent = ($current / $total) * 100;
        $bars = (int)round(($width * $percent) / 100);

        printf("\r\033[36m[%s] %3d%% %s\033[0m",
            str_pad(str_repeat('=', $bars), $width),
            $percent,
            $label
        );

        if ($current == $total) {
            echo "\n";
        }
    }

    /**
     * Read user input
     */
    private function readInput(string $prompt): string
    {
        echo "\033[33m{$prompt}\033[0m";
        return trim(fgets(STDIN) ?? '');
    }

    /**
     * Pause execution
     */
    private function pause(): void
    {
        if ($this->isRunning) {
            echo "\n\033[36mPress Enter to continue...\033[0m";
            fgets(STDIN);
        }
    }

    /**
     * Setup PID directory
     */
    private function setupPidDir(): void
    {
        if (!is_dir($this->config['pid_dir'])) {
            @mkdir($this->config['pid_dir'], 0755, true);
        }
    }

    /**
     * Load configuration
     */
    private function loadConfiguration(): void
    {
        if (file_exists($this->configFile)) {
            $config = json_decode(file_get_contents($this->configFile), true);
            if ($config) {
                $this->config = array_merge($this->config, $config);
            }
        }
    }

    /**
     * Save configuration
     */
    private function saveConfiguration(): void
    {
        file_put_contents($this->configFile, json_encode($this->config, JSON_PRETTY_PRINT));
    }

    /**
     * Load websites
     */
    private function loadWebsites(): void
    {
        $websitesFile = $this->config['pid_dir'] . 'websites.json';

        if (file_exists($websitesFile)) {
            $websites = json_decode(file_get_contents($websitesFile), true);
            if ($websites) {
                $this->websites = $websites;
            }
        }

        // Load health history
        $historyFile = $this->config['pid_dir'] . 'health_history.json';
        if (file_exists($historyFile)) {
            $history = json_decode(file_get_contents($historyFile), true);
            if ($history) {
                $this->healthHistory = $history;
            }
        }
    }

    /**
     * Save websites
     */
    private function saveWebsites(): void
    {
        $websitesFile = $this->config['pid_dir'] . 'websites.json';
        file_put_contents($websitesFile, json_encode($this->websites, JSON_PRETTY_PRINT));
    }

    /**
     * Log activity with rotation
     */
    private function log(string $message): void
    {
        $user = $this->getCurrentUser();
        $logEntry = date('Y-m-d H:i:s') . " | {$user} | {$message}\n";
        $logFile = $this->config['log_file'];

        // Check for log rotation (max 10MB)
        if (file_exists($logFile) && filesize($logFile) > 10 * 1024 * 1024) {
            $this->rotateLog($logFile);
        }

        @file_put_contents($logFile, $logEntry, FILE_APPEND);
    }

    /**
     * Rotate log file
     */
    private function rotateLog(string $logFile): void
    {
        $maxArchives = 5;
        $baseName = basename($logFile);

        // Remove oldest archive if we have too many
        for ($i = $maxArchives; $i >= 1; $i--) {
            $oldFile = $logFile . '.' . $i;
            if (file_exists($oldFile)) {
                if ($i === $maxArchives) {
                    @unlink($oldFile);
                } else {
                    @rename($oldFile, $logFile . '.' . ($i + 1));
                }
            }
        }

        // Rename current log to .1
        @rename($logFile, $logFile . '.1');

        $this->printInfo("Log rotated (max 10MB, keeping {$maxArchives} archives)");
    }

    /**
     * Retry an operation with exponential backoff
     * Returns the result of the operation or throws exception after max retries
     */
    private function retryOperation(callable $operation, ?int $maxRetries = null, ?int $delayMs = null): mixed
    {
        $maxRetries = $maxRetries ?? $this->config['max_retries'] ?? 3;
        $delayMs = $delayMs ?? ($this->config['retry_delay'] ?? 1) * 1000;

        $lastException = null;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                return $operation();
            } catch (Exception $e) {
                $lastException = $e;
                if ($attempt < $maxRetries) {
                    // Exponential backoff
                    $waitMs = $delayMs * pow(2, $attempt);
                    usleep($waitMs * 1000);
                }
            }
        }

        throw $lastException;
    }

    /**
     * Shutdown manager
     */
    private function shutdown(): void
    {
        $this->printHeader('Shutting Down');

        $this->printInfo("Saving configuration...");
        $this->saveConfiguration();
        $this->saveWebsites();

        $uptime = $this->formatUptime();
        $this->log("Website Manager stopped. Uptime: {$uptime}");

        $this->printSuccess("Goodbye!");
    }
}

// ==================== MAIN EXECUTION ====================

// Check for minimum PHP version
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    echo "Error: PHP 8.0 or higher is required. Current version: " . PHP_VERSION . "\n";
    exit(1);
}

// Check for required functions
$requiredFunctions = ['exec', 'file_get_contents', 'json_decode', 'json_encode'];
foreach ($requiredFunctions as $func) {
    if (!function_exists($func)) {
        echo "Error: Required function '{$func}' is not available.\n";
        exit(1);
    }
}

// Check if running as root/sudo
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    echo "\033[33mWarning: Running as root is not recommended.\033[0m\n\n";
}

// Run the manager
try {
    $manager = new AVManager();
    $manager->run();
} catch (Exception $e) {
    echo "\033[31mError: " . $e->getMessage() . "\033[0m\n";
    exit(1);
}
