<#
.SYNOPSIS
  Downloads the refreshdb.php script, detects installed PHP versions in common locations,
  prompts the user to select one, adds $HOME\bin to the user's PATH, and creates
  a db.bat wrapper script in $HOME\bin.

.DESCRIPTION
  This script performs the following steps:
  1. Checks if $HOME\bin is in the user's PATH environment variable and adds it if missing.
  2. Ensures the $HOME\bin directory exists.
  3. Downloads the latest refreshdb.php script from GitHub to $HOME\bin.
  4. Searches common PHP installation locations (XAMPP, Laragon, WAMP, C:\PHP) across
     all fixed drives for php.exe.
  5. If php.exe is found, prompts the user to select which installation to use.
  6. Creates a db.bat file in $HOME\bin that uses the selected php.exe to run the
     downloaded refreshdb.php script, passing along any command-line arguments.
#>

# --- Initial Setup ---

# Append $HOME\bin to PATH if it's not already included
$binPath = Join-Path -Path $HOME -ChildPath "bin"
$pathNeedsUpdate = $true
try {
    # Get User PATH, handle if it doesn't exist or is empty
    $userPath = [Environment]::GetEnvironmentVariable('PATH', 'User')
    if ($null -ne $userPath -and ($userPath -split ';' -contains $binPath)) {
        $pathNeedsUpdate = $false
        Write-Verbose "PATH already includes $binPath."
    }
} catch {
    Write-Warning "Could not read user PATH variable. Will attempt to set it."
    $userPath = "" # Assume it needs setting
}

if ($pathNeedsUpdate) {
    try {
        $newPath = ($userPath.TrimEnd(';') + ";$binPath").TrimStart(';')
        [Environment]::SetEnvironmentVariable('PATH', $newPath, 'User')
        Write-Host "Added '$binPath' to user PATH. You may need to restart PowerShell/Explorer for changes to take effect."
        # Also update the current process's PATH for immediate use
        $env:PATH = $env:PATH.TrimEnd(';') + ";$binPath"
    } catch {
        Write-Error "Failed to update user PATH variable. Error: $($_.Exception.Message)"
        # Continue script execution if possible, but warn the user.
    }
}

# Ensure the bin directory exists
if (-not (Test-Path -Path $binPath -PathType Container)) {
    Write-Host "Creating directory: $binPath"
    try {
        New-Item -ItemType Directory -Path $binPath -Force -ErrorAction Stop | Out-Null
    } catch {
        Write-Error "Failed to create directory '$binPath'. Error: $($_.Exception.Message)"
        Start-Sleep -Seconds 5
        return # Cannot proceed without bin directory
    }
}

# Download the script to the bin directory
$scriptUrl = "https://raw.githubusercontent.com/henno/refreshdb/refs/heads/main/refreshdb.php"
$destinationPath = Join-Path -Path $binPath -ChildPath "refreshdb.php"
Write-Host "Downloading '$($scriptUrl)' to '$destinationPath'..."
try {
    Invoke-WebRequest -Uri $scriptUrl -OutFile $destinationPath -ErrorAction Stop
    Write-Host "Download complete."
} catch {
    Write-Error "Failed to download script from '$scriptUrl'. Error: $($_.Exception.Message)"
    Write-Error "Please check your internet connection and the URL."
    Start-Sleep -Seconds 5
    return # Cannot proceed without the script
}

# --- PHP Detection Logic ---

$filter = "php.exe"
$targetPatterns = @(
    "PHP\$filter",
    "PHP\*\$filter",
    "xampp*\php\$filter",
    "laragon\bin\php\php*\$filter",
    "laragon\bin\php\$filter",
    "wamp*\bin\php\$filter",
    "wamp*\bin\php\php*\$filter"
)

$found = [System.Collections.Generic.List[string]]::new()

Write-Host "Detecting PHP installations..."
Write-Host "Identifying fixed drives..."
$fixedDrives = Get-CimInstance -ClassName Win32_LogicalDisk | Where-Object { $_.DriveType -eq 3 } | Select-Object -ExpandProperty DeviceID
if (-not $fixedDrives) {
    Write-Error "No fixed drives (DriveType 3) found using Get-CimInstance. Cannot auto-detect PHP."
    Write-Error "You will need to manually create or edit db.bat with the correct path to php.exe."
    Start-Sleep -Seconds 5
    return
}
Write-Host "Found drives: $($fixedDrives -join ', ')"

$searchPaths = @()
foreach ($drive in $fixedDrives) {
    foreach ($pattern in $targetPatterns) {
        $searchPaths += Join-Path -Path $drive -ChildPath $pattern
    }
}

Write-Host "Searching for '$filter' using patterns like:"
Write-Host "- $($targetPatterns -join "`n- ")" # Show relative patterns for brevity
Write-Host ""

foreach ($pathPattern in $searchPaths) {
    try {
        $items = Get-ChildItem -Path $pathPattern -ErrorAction SilentlyContinue -Force
        foreach ($item in $items) {
            if ($item.Name -eq $filter -and !$found.Contains($item.FullName)) {
                Write-Verbose "Found: $($item.FullName)"
                $found.Add($item.FullName)
            }
        }
    } catch {
        Write-Warning "Error processing pattern '$pathPattern': $($_.Exception.Message)"
    }
}

if ($found.Count -eq 0) {
    Write-Host "`nNo '$filter' found using the specific common patterns."
    Write-Error "Cannot create db.bat automatically without a PHP executable."
    Write-Error "Please install PHP (e.g., XAMPP, Laragon, WAMP, or standard PHP) or manually create db.bat."
    Start-Sleep -Seconds 5
    return
}

# Sort results
$found.Sort()

# Let User Choose PHP
Write-Host "`nMultiple PHP installations found. Select the one you want to use:`n"
for ($i = 0; $i -lt $found.Count; $i++) {
    Write-Host "[$($i + 1)] $($found[$i])"
}

$choice = $null
do {
    $inputChoice = Read-Host "Enter number 1-$($found.Count) (or 'q' to quit)"
    if ($inputChoice -eq 'q') {
        Write-Host "Selection cancelled by user. db.bat not created."
        Start-Sleep -Seconds 3
        return
    }
    if ($inputChoice -match '^\d+$' -and [int]$inputChoice -ge 1 -and [int]$inputChoice -le $found.Count) {
        $choice = [int]$inputChoice
    } else {
        Write-Warning "Invalid input. Please enter a number between 1 and $($found.Count) or 'q'."
    }
} while ($choice -eq $null)

$selectedPhp = $found[$choice - 1]
Write-Host "`nUsing selected PHP: $selectedPhp"

# --- Create Wrapper db.bat ---

$dbBatPath = Join-Path -Path $binPath -ChildPath "db.bat"

# Define the content for db.bat using the selected PHP and calculated script path
# NOTE the use of @"..."@ for the expandable here-string
$dbBatContent = @"
@echo off
rem Batch file generated by PowerShell script to run refreshdb.php

rem Set the selected PHP executable path
set "PHP_EXECUTABLE=$selectedPhp"

rem Set the path to the refreshdb.php script (calculated by the PowerShell script)
set "SCRIPT_PATH=$destinationPath"

rem Execute the PHP script, passing all command line arguments (%*)
"%PHP_EXECUTABLE%" "%SCRIPT_PATH%" %*
"@

Write-Host "Creating wrapper script: $dbBatPath"
try {
    # Use ASCII encoding for basic .bat compatibility
    Set-Content -Path $dbBatPath -Value $dbBatContent -Encoding Ascii -ErrorAction Stop
    Write-Host "`ndb.bat created/updated successfully."
} catch {
    Write-Error "Failed to write db.bat to '$dbBatPath'. Error: $($_.Exception.Message)"
    Start-Sleep -Seconds 5
    return
}

Write-Host "`nSetup complete. You can now run 'db' from your terminal."
# Optional pause at the end if run by double-clicking
# Read-Host "Press Enter to exit"