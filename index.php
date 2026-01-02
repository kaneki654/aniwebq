#!/usr/bin/env php
<?php
/**
 * AnimeVerse Server Launcher
 * A cool animated loading script that starts both servers
 */

// ANSI Color codes
define('RESET', "\033[0m");
define('BOLD', "\033[1m");
define('DIM', "\033[2m");
define('BLINK', "\033[5m");

// Foreground colors
define('RED', "\033[31m");
define('GREEN', "\033[32m");
define('YELLOW', "\033[33m");
define('BLUE', "\033[34m");
define('MAGENTA', "\033[35m");
define('CYAN', "\033[36m");
define('WHITE', "\033[37m");

// Bright colors
define('BRIGHT_RED', "\033[91m");
define('BRIGHT_GREEN', "\033[92m");
define('BRIGHT_YELLOW', "\033[93m");
define('BRIGHT_BLUE', "\033[94m");
define('BRIGHT_MAGENTA', "\033[95m");
define('BRIGHT_CYAN', "\033[96m");
define('BRIGHT_WHITE', "\033[97m");

// Background colors
define('BG_BLACK', "\033[40m");
define('BG_BLUE', "\033[44m");
define('BG_MAGENTA', "\033[45m");

// Build detection constants
define('NEXT_BUILD_DIR', __DIR__ . '/.next');
define('NEXT_BUILD_INDICATOR', __DIR__ . '/.next/BUILD_ID');

// Clear screen and hide cursor
function clearScreen() {
    echo "\033[2J\033[H";
}

function hideCursor() {
    echo "\033[?25l";
}

function showCursor() {
    echo "\033[?25h";
}

function moveCursor($row, $col) {
    echo "\033[{$row};{$col}H";
}

// Check if Next.js is built
function isNextJsBuilt() {
    // Check if build directory exists and has BUILD_ID file
    return file_exists(NEXT_BUILD_INDICATOR) && is_dir(NEXT_BUILD_DIR);
}

// ASCII Art for ANIME
$animeArt = [
    "    ___    _   __ ____ __  ___ ______",
    "   /   |  / | / //  _//  |/  // ____/",
    "  / /| | /  |/ / / / / /|_/ // __/   ",
    " / ___ |/ /|  /_/ / / /  / // /___   ",
    "/_/  |_/_/ |_//___//_/  /_//_____/   ",
];

// ASCII Art for VERSE
$verseArt = [
    " _    __ ______ ____   _____ ______",
    "| |  / // ____// __ \\ / ___// ____/",
    "| | / // __/  / /_/ / \\__ \\/ __/   ",
    "| |/ // /___ / _, _/ ___/ / /___   ",
    "|___//_____//_/ |_| /____/_____/   ",
];

// Combined ANIMEVERSE Art
$animeVerseArt = [
    BRIGHT_MAGENTA . "    ___    _   __ ____ __  ___ ______ " . BRIGHT_CYAN . " _    __ ______ ____   _____ ______" . RESET,
    BRIGHT_MAGENTA . "   /   |  / | / //  _//  |/  // ____/ " . BRIGHT_CYAN . "| |  / // ____// __ \\ / ___// ____/" . RESET,
    BRIGHT_MAGENTA . "  / /| | /  |/ / / / / /|_/ // __/    " . BRIGHT_CYAN . "| | / // __/  / /_/ / \\__ \\/ __/   " . RESET,
    BRIGHT_MAGENTA . " / ___ |/ /|  /_/ / / /  / // /___    " . BRIGHT_CYAN . "| |/ // /___ / _, _/ ___/ / /___   " . RESET,
    BRIGHT_MAGENTA . "/_/  |_/_/ |_//___//_/  /_//_____/    " . BRIGHT_CYAN . "|___//_____//_/ |_| /____/_____/   " . RESET,
];

// Sparkle characters
$sparkles = ['✦', '✧', '★', '☆', '✴', '✵', '❋', '❊', '✺', '✹'];
$particles = ['·', '•', '°', '∘', '○', '◦', '◌', '◍', '◎', '●'];

// Loading spinners
$spinners = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
$dots = ['⣾', '⣽', '⣻', '⢿', '⡿', '⣟', '⣯', '⣷'];
$bounce = ['⠁', '⠂', '⠄', '⠂'];

// Progress bar characters
$progressFull = '█';
$progressEmpty = '░';
$progressHead = '▓';

function drawFrame($frame, $centerRow = 5) {
    foreach ($frame as $i => $line) {
        moveCursor($centerRow + $i, 5);
        echo $line;
    }
}

function drawProgressBar($percent, $row, $col, $width = 40) {
    $filled = (int)(($percent / 100) * $width);
    $empty = $width - $filled;

    moveCursor($row, $col);
    echo BRIGHT_MAGENTA . '[' . RESET;
    echo BRIGHT_CYAN . str_repeat('█', $filled);
    if ($filled < $width) {
        echo BRIGHT_YELLOW . '▓' . RESET;
        echo DIM . str_repeat('░', max(0, $empty - 1)) . RESET;
    }
    echo BRIGHT_MAGENTA . ']' . RESET;
    echo BRIGHT_WHITE . " {$percent}%" . RESET;
}

function drawSpinner($spinnerArray, $index, $row, $col, $color = BRIGHT_CYAN) {
    moveCursor($row, $col);
    echo $color . $spinnerArray[$index % count($spinnerArray)] . RESET;
}

function drawRandomSparkles($count = 10, $startRow = 3, $endRow = 12, $startCol = 5, $endCol = 80) {
    global $sparkles;
    $colors = [BRIGHT_YELLOW, BRIGHT_CYAN, BRIGHT_MAGENTA, BRIGHT_WHITE, YELLOW];

    for ($i = 0; $i < $count; $i++) {
        $row = rand($startRow, $endRow);
        $col = rand($startCol, $endCol);
        $sparkle = $sparkles[array_rand($sparkles)];
        $color = $colors[array_rand($colors)];
        moveCursor($row, $col);
        echo $color . $sparkle . RESET;
    }
}

function drawWave($row, $col, $width, $frame) {
    $wave = '';
    for ($i = 0; $i < $width; $i++) {
        $offset = sin(($i + $frame) * 0.3) * 2;
        if ($offset > 1) $wave .= '▀';
        elseif ($offset > 0) $wave .= '─';
        elseif ($offset > -1) $wave .= '─';
        else $wave .= '▄';
    }
    moveCursor($row, $col);
    echo BRIGHT_BLUE . $wave . RESET;
}

function animateIntro() {
    global $animeArt, $verseArt, $animeVerseArt, $sparkles, $spinners;

    $totalFrames = 60;
    $maxOffset = 25;

    for ($frame = 0; $frame < $totalFrames; $frame++) {
        clearScreen();

        // Calculate animation progress
        $progress = $frame / $totalFrames;
        $offset = (int)($maxOffset * (1 - $progress));

        // Draw border
        echo BRIGHT_MAGENTA;
        moveCursor(1, 1);
        echo "╔" . str_repeat("═", 85) . "╗";
        for ($i = 2; $i <= 20; $i++) {
            moveCursor($i, 1);
            echo "║";
            moveCursor($i, 87);
            echo "║";
        }
        moveCursor(21, 1);
        echo "╚" . str_repeat("═", 85) . "╝";
        echo RESET;

        // Draw title animation
        if ($frame < 40) {
            // ANIME coming from left
            for ($i = 0; $i < count($animeArt); $i++) {
                moveCursor(5 + $i, max(3, 3 - $offset));
                echo BRIGHT_MAGENTA . $animeArt[$i] . RESET;
            }

            // VERSE coming from right
            for ($i = 0; $i < count($verseArt); $i++) {
                moveCursor(5 + $i, 45 + $offset);
                echo BRIGHT_CYAN . $verseArt[$i] . RESET;
            }
        } else {
            // Show merged ANIMEVERSE
            drawFrame($animeVerseArt, 5);

            // Add sparkles around the merged text
            if ($frame % 3 == 0) {
                drawRandomSparkles(8, 4, 11, 3, 85);
            }
        }

        // Draw wave animation
        drawWave(13, 5, 78, $frame);

        // Draw loading text
        moveCursor(15, 30);
        $loadingText = "LOADING ANIMEVERSE";
        $colors = [BRIGHT_MAGENTA, BRIGHT_CYAN, BRIGHT_YELLOW, BRIGHT_WHITE];
        for ($i = 0; $i < strlen($loadingText); $i++) {
            $colorIndex = ($i + $frame) % count($colors);
            echo $colors[$colorIndex] . $loadingText[$i] . RESET;
        }

        // Draw spinner
        drawSpinner($spinners, $frame, 15, 25, BRIGHT_YELLOW);
        drawSpinner($spinners, $frame, 15, 50, BRIGHT_YELLOW);

        // Draw progress bar
        $currentProgress = min(100, (int)(($frame / $totalFrames) * 100));
        drawProgressBar($currentProgress, 17, 22, 45);

        // Draw animated dots
        moveCursor(19, 32);
        $dotsCount = ($frame % 4) + 1;
        echo BRIGHT_CYAN . str_repeat("● ", $dotsCount) . str_repeat("○ ", 4 - $dotsCount) . RESET;

        usleep(50000); // 50ms per frame
    }
}

function animateServerStart($serverName, $port, $row) {
    global $spinners, $dots;

    // Determine if this is production or development
    $isProduction = ($serverName === "Next.js App" && isNextJsBuilt());
    
    if ($isProduction) {
        $stages = [
            "Initializing...",
            "Loading production build...",
            "Optimizing...",
            "Starting production server...",
            "Production server ready!"
        ];
    } else {
        $stages = [
            "Initializing...",
            "Loading modules...",
            "Configuring...",
            "Starting server...",
            "Server ready!"
        ];
    }

    foreach ($stages as $stageIndex => $stage) {
        for ($i = 0; $i < 8; $i++) {
            moveCursor($row, 10);
            echo str_repeat(" ", 70); // Clear line
            moveCursor($row, 10);

            drawSpinner($dots, $i, $row, 10, BRIGHT_YELLOW);

            if ($stageIndex == count($stages) - 1) {
                if ($isProduction) {
                    echo " " . BRIGHT_GREEN . "✓ " . BOLD . $serverName . RESET . BRIGHT_GREEN . " (Production on Port $port) - $stage" . RESET;
                } else {
                    echo " " . BRIGHT_GREEN . "✓ " . BOLD . $serverName . RESET . BRIGHT_GREEN . " (Port $port) - $stage" . RESET;
                }
            } else {
                if ($isProduction) {
                    echo " " . BRIGHT_CYAN . $serverName . RESET . " (Production on Port $port) - " . YELLOW . $stage . RESET;
                } else {
                    echo " " . BRIGHT_CYAN . $serverName . RESET . " (Port $port) - " . YELLOW . $stage . RESET;
                }
            }

            usleep(40000);
        }
    }
}

function drawFinalScreen() {
    clearScreen();

    global $animeVerseArt, $sparkles;

    // Draw fancy border
    echo BRIGHT_MAGENTA;
    moveCursor(1, 1);
    echo "╔" . str_repeat("═", 85) . "╗";
    for ($i = 2; $i <= 24; $i++) {
        moveCursor($i, 1);
        echo "║";
        moveCursor($i, 87);
        echo "║";
    }
    moveCursor(25, 1);
    echo "╚" . str_repeat("═", 85) . "╝";
    echo RESET;

    // Draw title
    drawFrame($animeVerseArt, 3);

    // Add permanent sparkles
    $sparklePositions = [
        [3, 10], [3, 40], [3, 70],
        [9, 15], [9, 45], [9, 75],
        [4, 25], [4, 60],
        [8, 20], [8, 55]
    ];
    foreach ($sparklePositions as $pos) {
        moveCursor($pos[0], $pos[1]);
        echo BRIGHT_YELLOW . $sparkles[array_rand($sparkles)] . RESET;
    }

    // Draw decorative line
    moveCursor(10, 5);
    echo BRIGHT_CYAN . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" . RESET;

    // Status section
    moveCursor(12, 30);
    echo BOLD . BRIGHT_WHITE . "✨ SERVER STATUS ✨" . RESET;

    // Server status boxes
    moveCursor(14, 15);
    echo BRIGHT_MAGENTA . "┌───────────────────────────┐" . RESET;
    moveCursor(15, 15);
    echo BRIGHT_MAGENTA . "│" . RESET . " " . BRIGHT_GREEN . "●" . RESET . " Next.js App          " . BRIGHT_MAGENTA . "│" . RESET;
    moveCursor(16, 15);
    echo BRIGHT_MAGENTA . "│" . RESET . "   " . CYAN . "http://localhost:3000" . RESET . " " . BRIGHT_MAGENTA . "│" . RESET;
    moveCursor(17, 15);
    echo BRIGHT_MAGENTA . "└───────────────────────────┘" . RESET;

    moveCursor(14, 50);
    echo BRIGHT_CYAN . "┌───────────────────────────┐" . RESET;
    moveCursor(15, 50);
    echo BRIGHT_CYAN . "│" . RESET . " " . BRIGHT_GREEN . "●" . RESET . " AniWatch API         " . BRIGHT_CYAN . "│" . RESET;
    moveCursor(16, 50);
    echo BRIGHT_CYAN . "│" . RESET . "   " . CYAN . "http://localhost:4000" . RESET . " " . BRIGHT_CYAN . "│" . RESET;
    moveCursor(17, 50);
    echo BRIGHT_CYAN . "└───────────────────────────┘" . RESET;

    // Instructions
    moveCursor(19, 5);
    echo BRIGHT_CYAN . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" . RESET;

    moveCursor(21, 25);
    echo BRIGHT_YELLOW . "🎬 " . BOLD . "Open your browser to start watching!" . RESET;

    moveCursor(23, 30);
    echo DIM . "Press Ctrl+C to stop servers" . RESET;
}

function startProductionServer() {
    $webDir = __DIR__;
    
    // Kill existing processes on port 3000
    exec("pkill -f 'next start' 2>/dev/null");
    exec("fuser -k 3000/tcp 2>/dev/null");
    
    sleep(1);
    
    // Start production server
    $prodCmd = "cd '$webDir' && npm start > /dev/null 2>&1 &";
    exec($prodCmd);
    
    return true;
}

function startServers() {
    $webDir = __DIR__;
    $apiDir = dirname(__DIR__) . '/aniwatch-api';

    // Kill existing processes on ports 3000 and 4000
    exec("pkill -f 'next dev' 2>/dev/null");
    exec("pkill -f 'next start' 2>/dev/null");
    exec("pkill -f 'tsx watch' 2>/dev/null");
    exec("fuser -k 3000/tcp 2>/dev/null");
    exec("fuser -k 4000/tcp 2>/dev/null");

    sleep(1);

    // Check if Next.js is built and start appropriate server
    if (isNextJsBuilt()) {
        // Start production server for Next.js
        $nextCmd = "cd '$webDir' && npm start > /dev/null 2>&1 &";
    } else {
        // Start development server for Next.js
        $nextCmd = "cd '$webDir' && npm run dev:next > /dev/null 2>&1 &";
    }
    
    // Always start API in dev mode
    $apiCmd = "cd '$apiDir' && npm run dev > /dev/null 2>&1 &";

    exec($nextCmd);
    exec($apiCmd);

    return true;
}

function waitForServers($timeout = 60) {
    $startTime = time();
    $nextReady = false;
    $apiReady = false;
    $isProduction = isNextJsBuilt();

    while ((time() - $startTime) < $timeout) {
        // Update status message based on mode
        moveCursor(14, 25);
        if ($isProduction) {
            echo BRIGHT_YELLOW . "⏳ Waiting for production servers to be ready..." . str_repeat(" ", 10) . RESET;
        } else {
            echo BRIGHT_YELLOW . "⏳ Waiting for development servers to be ready..." . str_repeat(" ", 10) . RESET;
        }
        
        // Add a small spinner
        static $waitSpinner = 0;
        $spinnerChars = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
        moveCursor(14, 23);
        echo BRIGHT_CYAN . $spinnerChars[$waitSpinner % count($spinnerChars)] . RESET;
        $waitSpinner++;

        // Check port 3000
        if (!$nextReady) {
            $fp = @fsockopen('localhost', 3000, $errno, $errstr, 1);
            if ($fp) {
                fclose($fp);
                $nextReady = true;
            }
        }

        // Check port 4000
        if (!$apiReady) {
            $fp = @fsockopen('localhost', 4000, $errno, $errstr, 1);
            if ($fp) {
                fclose($fp);
                $apiReady = true;
            }
        }

        if ($nextReady && $apiReady) {
            return true;
        }

        usleep(500000); // Check every 500ms
    }

    return false;
}

// Main execution
try {
    hideCursor();
    clearScreen();

    // Play intro animation
    animateIntro();

    // Clear and show server starting
    clearScreen();

    // Draw border for server start section
    echo BRIGHT_MAGENTA;
    moveCursor(1, 1);
    echo "╔" . str_repeat("═", 85) . "╗";
    for ($i = 2; $i <= 15; $i++) {
        moveCursor($i, 1);
        echo "║";
        moveCursor($i, 87);
        echo "║";
    }
    moveCursor(16, 1);
    echo "╚" . str_repeat("═", 85) . "╝";
    echo RESET;

    // Title
    moveCursor(3, 30);
    echo BOLD . BRIGHT_CYAN . "🚀 STARTING SERVERS 🚀" . RESET;

    moveCursor(5, 5);
    echo BRIGHT_MAGENTA . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" . RESET;

    // Check and show build status
    moveCursor(7, 10);
    if (isNextJsBuilt()) {
        echo BRIGHT_GREEN . "✓ Detected production build - starting in production mode" . RESET;
    } else {
        echo BRIGHT_YELLOW . "⚙ No production build detected - starting in development mode" . RESET;
    }

    // Start servers
    startServers();

    // Animate server startup
    animateServerStart("Next.js App", 3000, 8);
    animateServerStart("AniWatch API", 4000, 10);

    moveCursor(12, 5);
    echo BRIGHT_CYAN . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" . RESET;

    moveCursor(14, 25);
    $modeText = isNextJsBuilt() ? "production" : "development";
    echo BRIGHT_YELLOW . "⏳ Waiting for $modeText servers to be ready..." . RESET;

    // Wait for servers
    $ready = waitForServers(60);

    if ($ready) {
        // Show brief success message
        echo BRIGHT_GREEN . "\n✓ Servers started successfully!\n" . RESET;
        echo "  - Next.js App: http://localhost:3000\n";
        echo "  - AniWatch API: http://localhost:4000\n\n";
        echo BRIGHT_YELLOW . "Script terminating. Servers are running in background." . RESET . "\n";

        // Clean up cursor and exit
        showCursor();
        exit(0);
    } else {
        moveCursor(14, 20);
        echo BRIGHT_RED . "⚠ Some servers may not have started properly" . RESET;
        moveCursor(15, 20);
        echo YELLOW . "Check the logs and try running 'npm run dev' manually" . RESET;
    }

} catch (Exception $e) {
    showCursor();
    echo RESET . "\nError: " . $e->getMessage() . "\n";
} finally {
    // Cleanup on exit
    register_shutdown_function(function() {
        showCursor();
        echo RESET . "\n";
    });
}

// Handle Ctrl+C gracefully
pcntl_signal(SIGINT, function() {
    showCursor();
    clearScreen();
    moveCursor(1, 1);
    echo BRIGHT_CYAN . "\n\n  👋 Thanks for using AnimeVerse! See you next time!\n\n" . RESET;
    exit(0);
});
pcntl_async_signals(true);
?>
