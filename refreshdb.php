#!/usr/bin/env php
<?php

//-----------------------------
// Configuration
//-----------------------------
$config = [
    'databaseUsername' => 'root',
    'databasePassword' => '',
    'databaseHostname' => '127.0.0.1',
    'databaseName' => '',
    'configFilePaths' => ['./config.php', './wp-config.php'],
    'dumpFilePath' => 'doc/database.sql',
    'tempFilePath' => 'doc/database.sql.tmp',
    'mysqlExecutablePath' => '/Applications/MAMP/Library/bin/mysql',
    'mysqldumpExecutablePath' => '/Applications/MAMP/Library/bin/mysqldump',
    'maxLineLength' => 120,
];

//-----------------------------
// Time & Timezone Setup
//-----------------------------
$start = microtime(true);
date_default_timezone_set('UTC');
log_message("Started at: " . date('Y-m-d H:i:s'));

try {
    $timezone = json_decode(file_get_contents('http://ip-api.com/json'))->timezone;
    date_default_timezone_set($timezone);
} catch (Exception $e) {
    // Keep UTC if fetching timezone fails
}

//-----------------------------
// Determine Mode
//-----------------------------
$options = getopt('', ['dump', 'restore', 'repair']);
$mode = isset($options['dump'])
    ? 'dump'
    : (isset($options['restore'])
        ? 'restore'
        : (isset($options['repair'])
            ? 'repair'
            : 'help'));

if ($mode === 'help') {
    showHelp();
    exit(0);
}

//-----------------------------
// Validate Dump File for Restore/Repair
//-----------------------------
if ($mode === 'restore' || $mode === 'repair') {
    if (!file_exists($config['dumpFilePath'])) {
        log_message("No dump file found at {$config['dumpFilePath']}. "
            . "Please create a dump file before restoring or repairing.");
        exit(1);
    }
    if (filesize($config['dumpFilePath']) === 0) {
        log_message("The dump file is empty. "
            . "Please create a non-empty dump file before restoring or repairing.");
        exit(1);
    }
}

//-----------------------------
// Read Database Credentials
//-----------------------------
readDatabaseCredentials($config);
log_message("Database name: {$config['databaseName']}");

//-----------------------------
// Execute Mode
//-----------------------------
if ($mode === 'dump') {
    dumpDatabase($config);
} elseif ($mode === 'restore') {
    restoreDatabase($config);
} elseif ($mode === 'repair') {
    repairDumpFile($config);
}

//-----------------------------
// Print Time Taken
//-----------------------------
$elapsed = microtime(true) - $start;
log_message("Time taken: "
    . ($elapsed < 1 ? round($elapsed * 1000) . " ms" : round($elapsed, 2) . " s"));

//====================================================
// FUNCTIONS
//====================================================
/**
 * Dump the Database
 */
function dumpDatabase(array $config): void
{
    // Create temp file for raw dump
    $tmpFile = $config['tempFilePath'];

    // Build mysqldump command
    $passwordOption = empty($config['databasePassword']) ? "" : "-p" . escapeshellarg($config['databasePassword']);

    if (executeCommand(sprintf(
        '%s --default-character-set=utf8mb4 -u %s %s -h %s %s > %s',
        escapeshellcmd($config['mysqldumpExecutablePath']),
        escapeshellarg($config['databaseUsername']),
        $passwordOption,
        escapeshellarg($config['databaseHostname']),
        escapeshellarg($config['databaseName']),
        escapeshellarg($config['dumpFilePath'])
    ))) {
        normalizeDumpFile($config);
        logFileStats($config['dumpFilePath']);
    } else {
        log_message("Failed to create dump file.");
    }
}

/**
 * Repair an existing dump file
 */
function repairDumpFile(array $config): void
{
    log_message("Repairing dump file: {$config['dumpFilePath']}");

    if (isUtf16($config['dumpFilePath'])) {
        log_message("Detected UTF16LE encoding, will convert to UTF8");

        // Try using system iconv command for converting
        if (executeCommand("iconv -f UTF-16LE -t UTF-8 \"{$config['dumpFilePath']}\" > \"{$config['tempFilePath']}\"")) {
            rename($config['tempFilePath'], $config['dumpFilePath']);
            log_message("Successfully converted file using iconv");
        } else {
            log_message("Failed to convert with iconv, use some tool to convert the file from UTF16LE to UTF8");
            exit(1);
        }
    }
    normalizeDumpFile($config);
    logFileStats($config['dumpFilePath']);
}

/**
 * Restore the Database
 */
function restoreDatabase(array $config): void
{
    // Build a password option
    $passwordOption = empty($config['databasePassword']) ? "" : "-p" . escapeshellarg($config['databasePassword']);

    // Drop & create
    if (!executeCommand(sprintf(
        "%s -u %s %s -h %s -e \"DROP DATABASE IF EXISTS \`%s\`; CREATE DATABASE \`%s\`; SET @@SESSION.sql_mode='NO_AUTO_VALUE_ON_ZERO';\"",
        escapeshellcmd($config['mysqlExecutablePath']),
        escapeshellarg($config['databaseUsername']),
        $passwordOption,
        escapeshellarg($config['databaseHostname']),
        $config['databaseName'],
        $config['databaseName']
    ))) {
        log_message("Failed to drop and/or create the database.");
        return;
    }

    // Check if the dump file might be in UTF16LE format
    if (isUtf16($config['dumpFilePath'])) {
        log_message("Detected UTF16LE encoding in the dump file, converting to UTF8 for import...");
        log_message("Please use the --repair option to convert the dump file to UTF8 first.");
        exit(1);
    }

    if (executeCommand(sprintf(
        '%s -u %s %s -h %s %s < %s',
        escapeshellcmd($config['mysqlExecutablePath']),
        escapeshellarg($config['databaseUsername']),
        $passwordOption,
        escapeshellarg($config['databaseHostname']),
        escapeshellarg($config['databaseName']),
        escapeshellarg($config['dumpFilePath'])
    ))) log_message("Database restored successfully."); else log_message("Failed to restore the database.");
}

/**
 * Read Database Credentials from config files
 */
function readDatabaseCredentials(array &$config): void
{
    foreach ($config['configFilePaths'] as $path) {
        if (!file_exists($path)) {
            continue;
        }

        // Include the file safely to read constants
        try {
            // Turn off output buffering to prevent any potential output
            ob_start();
            require $path;
            ob_end_clean();

            // Check for DATABASE_ constants
            if (defined('DATABASE_HOSTNAME')) {
                $config['databaseHostname'] = DATABASE_HOSTNAME;
            }

            if (defined('DATABASE_USERNAME')) {
                $config['databaseUsername'] = DATABASE_USERNAME;
            }

            if (defined('DATABASE_PASSWORD')) {
                $config['databasePassword'] = DATABASE_PASSWORD;
            }

            if (defined('DATABASE_DATABASE')) {
                $config['databaseName'] = DATABASE_DATABASE;
            }

            // Check for WordPress-style DB_ constants
            if (empty($config['databaseHostname']) && defined('DB_HOST')) {
                $config['databaseHostname'] = DB_HOST;
            }

            if (empty($config['databaseUsername']) && defined('DB_USER')) {
                $config['databaseUsername'] = DB_USER;
            }

            if (empty($config['databasePassword']) && defined('DB_PASSWORD')) {
                $config['databasePassword'] = DB_PASSWORD;
            }

            if (empty($config['databaseName']) && defined('DB_NAME')) {
                $config['databaseName'] = DB_NAME;
            }

            // If we found all credentials, stop searching
            if (!empty($config['databaseName']) && !empty($config['databaseHostname']) && !empty($config['databaseUsername'])) {
                break;
            }
        } catch (Throwable $e) {
            // If there was an error including the file, log it
            log_message("Error reading config file $path: " . $e->getMessage());
        }
    }

    // Fallback for database name if not found in config files
    if (empty($config['databaseName'])) {
        $config['databaseName'] = basename(getcwd());
    }
}

/**
 * Print Help / Usage
 */
function showHelp(): void
{
    echo <<<HELP
This script facilitates dumping and restoring MySQL/MariaDB databases. It also
processes dump files to minimize differences when switching between MySQL and
MariaDB, ensuring consistent output and minimal noise in version control.

Usage:
  php refreshdb.php [--dump | --restore | --repair]

Options:
  --dump      Create a database dump file with transformations applied.
  --restore   Restore the database from an existing dump file.
  --repair    Apply normalization rules to an existing dump file without accessing the database.

Configuration:
  The script will read database credentials from config.php or wp-config.php.
  You can also customize settings by editing the \$config array in this script.

HELP;
}

/**
 * Execute Shell Command
 */
function executeCommand(string $command): bool
{
    log_message("Executing: $command");
    system($command, $result);
    if ($result !== 0) {
        log_message("Command failed with exit code $result");
        return false;
    }
    return true;
}

/**
 * Process Dump File for Consistency (line by line to handle large files)
 */
function normalizeDumpFile($config): bool
{
    log_message("Processing dump file for more consistent output...");

    if (!file_exists($config['dumpFilePath'])) {
        log_message("Source file does not exist: $config[dumpFilePath]");
        return false;
    }

    // Check if the file is large
    $filesizeInMB = round(filesize($config['dumpFilePath']) / 1024 / 1024, 0);
    if ($filesizeInMB > 50) {
        log_message($config['dumpFilePath'] . " is large: " . $filesizeInMB . " MB");
    }

    // Open source file
    if (!($sourceHandle = fopen($config['dumpFilePath'], 'r'))) {
        log_message("Failed to open source file: $config[dumpFilePath]");
        return false;
    }

    // Open target file
    if (!($targetHandle = fopen($config['tempFilePath'], 'w'))) {
        fclose($sourceHandle);
        log_message("Failed to open target file: $config[tempFilePath]");
        return false;
    }

    // Add header with current date and host
    addHeader($targetHandle);

    // Initialize loop state
    $lineCount = 0;
    $inInsertStatement = false;
    $valueBlocks = [];
    $insertStmt = '';
    $startTime = microtime(true);

    // Read lines
    while (($line = fgets($sourceHandle)) !== false) {

        $lineCount++;

        logProgress($lineCount, $startTime);

        // Handle INSERT mode
        if ($inInsertStatement) {

            // Check if line contains value blocks
            $trimmedLine = trim($line);

            // Check if line contains value blocks (starts with a parenthesis or contains value blocks)
            if (preg_match('/^\s*\(/', $trimmedLine) || preg_match('/\)\s*,\s*\(/', $trimmedLine)) {
                // Handle multiple value blocks in a single line
                $parsedBlocks = parseValueBlocks($trimmedLine);
                if (!empty($parsedBlocks)) {
                    $valueBlocks = array_merge($valueBlocks, $parsedBlocks);
                }

                // End of VALUES detection
                if (preg_match('/\);\s*$/', $trimmedLine)) {
                    writeConsolidatedInsert($targetHandle, $insertStmt, $valueBlocks);
                    $inInsertStatement = false;
                }
                continue;
            }

            // End of INSERT detection
            if (preg_match('/;\s*$/', $trimmedLine) || !preg_match('/^\s*\(/i', $trimmedLine)) {
                if (!empty($valueBlocks)) {
                    writeConsolidatedInsert($targetHandle, $insertStmt, $valueBlocks);
                }

                $inInsertStatement = false;
                if (!preg_match('/^\s*;\s*$/', $trimmedLine)) {
                    fwrite($targetHandle, $line);
                }
                continue;
            }

            continue;
        }

        // Skip lines we don’t want (comments, empties, db statements, etc.)
        if (shouldSkipLine($line)) {
            continue;
        }

        // Transform line in various ways (remove DEFINER, AUTO_INCREMENT, etc.)
        $line = transformLine($line);

        // Detect start of an INSERT statement
        if (preg_match('/^INSERT INTO `([^`]+)`(\s+VALUES|\s+\([^)]+\)\s+VALUES)\s*\(?/', $line, $matches)) {
            $inInsertStatement = true;
            $insertStmt = "INSERT INTO `{$matches[1]}` VALUES\n";
            $valueBlocks = [];

            // Extract VALUES on same line
            if (preg_match('/VALUES\s*(\(.*)/i', $line, $values)) {
                $valueStr = rtrim($values[1], " \t\n\r\0\x0B;");
                $valueBlocks = parseValueBlocks($valueStr);

                // One-liner INSERT
                if (preg_match('/\);\s*$/', $line)) {
                    writeConsolidatedInsert($targetHandle, $insertStmt, $valueBlocks);
                    $inInsertStatement = false;
                }
            }
            continue;
        }

        // Write normal line with table spacing
        if (str_starts_with($line, '-- Table structure for table')) {
            fwrite($targetHandle, "\n");
        }
        fwrite($targetHandle, $line);
    }

    // Handle any remaining INSERT data
    if ($inInsertStatement && !empty($valueBlocks)) {
        writeConsolidatedInsert($targetHandle, $insertStmt, $valueBlocks);
    }

    // Cleanup
    fclose($sourceHandle);
    fclose($targetHandle);
    rename($config['tempFilePath'], $config['dumpFilePath']);
    log_message("Processed $lineCount lines total");

    return true;
}

/**
 * @param int $lineCount
 * @param float $startTime
 * @return void
 */
function logProgress(int $lineCount, float $startTime): void
{
    if ($lineCount % 20000 === 0) {
        $elapsed = microtime(true) - $startTime;
        $rate = $lineCount / $elapsed;
        log_message(sprintf("Processed %d lines... (%.1f lines/sec)", $lineCount, $rate));
    }
}

/**
 * @param $targetHandle
 * @return void
 */
function addHeader($targetHandle): void
{
    fwrite($targetHandle, "-- Dump created on " . date('Y-m-d H:i:s') . " by " . gethostname() . "\n");
    fwrite($targetHandle, "SET FOREIGN_KEY_CHECKS=0;\n");
    fwrite($targetHandle, "SET @@SESSION.sql_mode='NO_AUTO_VALUE_ON_ZERO';\n\n");
}

/**
 * @param string $sourcePath
 * @return bool
 */
function isUtf16(string $sourcePath): bool
{
    $firstBytes = file_get_contents($sourcePath, false, null, 0, 2);
    $isUtf16le = ($firstBytes === "\xFF\xFE");
    return $isUtf16le;
}

/**
 * Helper: Check if line should be skipped.
 */
function shouldSkipLine(string $line): bool
{
    $patterns = [
        '/^-- (MySQL dump|Dump|Server version|Dump completed on|MariaDB dump|Host:|Current Database:|Dump created on)/',
        '/^-- -{10,}/',                 // Separator lines like "-- ---------"
        '/^--\s*$/',                    // Empty comment lines (just "--")
        '/^\s*$/',                      // Completely empty lines
        '/^\s*\/\*![0-9]+\s+SET/',      // Lines containing only /*!... SET ...
        '/\/\*M!999999\\\\- enable the sandbox mode \*\//', // Specific sandbox comment
        '/DROP TABLE IF EXISTS|DROP DATABASE|CREATE DATABASE|USE\s+`/',
        '/^SET FOREIGN_KEY_CHECKS=0;?$/', // Header added by addHeader function
        '/^SET @@SESSION\.sql_mode=\'NO_AUTO_VALUE_ON_ZERO\';?$/' // Header added by addHeader function
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $line)) {
            return true;
        }
    }

    return false;
}

/**
 * Helper: Transform line by applying replacements/removals
 */
function transformLine(string $line): string
{
    $replacements = [
        '/DEFINER=`[^`]+`@`[^`]+`\s*/' => '',         // Remove DEFINER clauses
        '/\s+AUTO_INCREMENT=\d+/' => '',         // Remove AUTO_INCREMENT
        '/\butf8\b/' => 'utf8mb4',  // Replace utf8 with utf8mb4 (whole word only)
        '/\b(tiny|small|medium|big)?int\(\d+\)/' => '$1int',    // Convert int(N) to int, tinyint(N) to tinyint, etc.
        '/\s+COLLATE\s+[\'"]?[a-zA-Z0-9_]+[\'"]?/' => '',         // Remove COLLATE in column definitions
        '/\s+COLLATE\s*=\s*[\'"]?[a-zA-Z0-9_]+[\'"]?/' => '',         // Remove COLLATE in table definitions
        '/(=\s*)(\'(\d+(\.\d+)?)\')/i' => '$1$3',     // Remove quotes from numeric literals
    ];

    foreach ($replacements as $pattern => $replacement) {
        $line = preg_replace($pattern, $replacement, $line);
    }

    return $line;
}

/**
 * Helper: Parse multiple value blocks from a chunk like
 * "(1,'abc'),(2,'xyz'),(3,'foo')..."
 */
function parseValueBlocks(string $lineFragment): array
{
    $blocks = [];
    $lineFragment = trim($lineFragment);

    // Remove any trailing semicolon or comma
    $lineFragment = rtrim($lineFragment, ',;');

    // If we see multiple row blocks in one string => split them
    if (str_contains($lineFragment, '),(')) {
        $parts = preg_split('/\),\s*\(/', $lineFragment);
        foreach ($parts as $i => $part) {
            $part = trim($part);

            // Remove any trailing commas first
            $part = rtrim($part, ',');

            // Add missing parentheses
            if ($i === 0) {
                // The First part - it may already have opening parenthesis
                if (!str_starts_with($part, '(')) {
                    $part = '(' . $part;
                }
                // Add closing parenthesis if missing
                if (!str_ends_with($part, ')')) {
                    $part .= ')';
                }
            } elseif ($i === count($parts) - 1) {
                // Last part - add opening and closing if missing
                if (!str_starts_with($part, '(')) {
                    $part = '(' . $part;
                }
                if (!str_ends_with($part, ')')) {
                    $part .= ')';
                }
            } else {
                // Middle parts - always need both parentheses
                if (!str_starts_with($part, '(')) {
                    $part = '(' . $part;
                }
                if (!str_ends_with($part, ')')) {
                    $part .= ')';
                }
            }
            $blocks[] = $part;
        }
    } else {
        // Single row block
        $lineFragment = rtrim($lineFragment, ',;');

        // Ensure it has opening and closing parentheses
        if (!str_starts_with($lineFragment, '(')) {
            $lineFragment = '(' . $lineFragment;
        }
        if (!str_ends_with($lineFragment, ')')) {
            $lineFragment .= ')';
        }
        $blocks[] = $lineFragment;
    }
    return $blocks;
}

/**
 * Helper: Consolidate values into lines respecting maxLineLength, then write the
 * consolidated INSERT statement to $targetHandle.
 */
function writeConsolidatedInsert($targetHandle, string $insertHeader, array $valuesBuffer): void
{
    global $config;

    fwrite($targetHandle, $insertHeader);

    $consolidatedValues = [];
    $currentValueLine = "";
    $maxLineLength = $config['maxLineLength'];

    foreach ($valuesBuffer as $valueBlock) {
        // Remove trailing comma if present to prevent double commas
        $valueBlock = rtrim($valueBlock, ",");

        if (strlen($currentValueLine . $valueBlock) > $maxLineLength) {
            if (!empty($currentValueLine)) {
                $consolidatedValues[] = $currentValueLine;
            }
            $currentValueLine = $valueBlock;
        } else {
            if (!empty($currentValueLine)) {
                $currentValueLine .= ",";
            }
            $currentValueLine .= $valueBlock;
        }
    }
    if (!empty($currentValueLine)) {
        $consolidatedValues[] = $currentValueLine;
    }

    // When we join the lines, we need to make sure the last line in each group
    // doesn't have a trailing comma, which would create the ",)" pattern
    if (!empty($consolidatedValues)) {
        for ($i = 0; $i < count($consolidatedValues) - 1; $i++) {
            if (!str_ends_with($consolidatedValues[$i], ",")) {
                $consolidatedValues[$i] .= ",";
            }
        }
        // Make sure the last item doesn't have a trailing comma
        $consolidatedValues[count($consolidatedValues) - 1] = rtrim($consolidatedValues[count($consolidatedValues) - 1], ",");
    }

    fwrite($targetHandle, implode("\n", $consolidatedValues));

    // Ensure we end with a semicolon
    $lastValue = end($consolidatedValues);
    if (substr($lastValue, -1) === ';') {
        fwrite($targetHandle, "\n");
    } else {
        fwrite($targetHandle, ";\n");
    }
}

/**
 * Log a message
 */
function log_message($message): void
{
    global $start;
    $elapsed = microtime(true) - $start;
    echo "\e[1;36m[" . number_format($elapsed, 1) . "]\e[0m $message\n";
}

/**
 * Format bytes to human-readable form and log file statistics
 */
function logFileStats(string $filePath): void
{
    if (!file_exists($filePath)) {
        log_message("File not found: $filePath");
        return;
    }

    $size = filesize($filePath);
    log_message("Database file statistics:");
    log_message("- Path: $filePath");
    log_message("- Size: " . formatBytes($size));
}

/**
 * Format bytes to human-readable form
 */
function formatBytes(int $bytes, int $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);

    $bytes /= (1 << (10 * $pow));

    return round($bytes, $precision) . ' ' . $units[$pow];
}
