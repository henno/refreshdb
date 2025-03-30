# -------------------------------------------
# 1) Ensure $HOME\bin is in PATH
# -------------------------------------------
if (-not ($env:PATH -split ';' -contains "$HOME\bin")) {
    [Environment]::SetEnvironmentVariable('PATH', ($env:PATH.TrimEnd(';') + ";$HOME\bin"), 'User')
}

# Create $HOME\bin if it doesn't exist
$binPath = Join-Path -Path $HOME -ChildPath "bin"
if (-not (Test-Path -Path $binPath)) {
    New-Item -ItemType Directory -Path $binPath -Force | Out-Null
}

# -------------------------------------------
# 2) Download refreshdb.php to $HOME\bin
# -------------------------------------------
$scriptUrl      = "https://raw.githubusercontent.com/henno/refreshdb/refs/heads/main/refreshdb.php"
$destinationPhp = Join-Path -Path $binPath -ChildPath "refreshdb.php"
Invoke-WebRequest -Uri $scriptUrl -OutFile $destinationPhp

# -------------------------------------------
# 3) Prompt user: search for php.exe or manually specify?
# -------------------------------------------
Write-Host "Would you like to search your entire system for php.exe (S) or specify the path manually (M)? [S/M]"
$choice = Read-Host "Your choice"

# We will store the chosen path in this variable
$phpExe = $null

if ($choice.ToUpper() -eq "S") {
    Write-Host "Searching entire system for php.exe. This might take a while..."

    # Search entire C drive for php.exe (you can narrow this if you want)
    $results = Get-ChildItem -Path "C:\" -Filter "php.exe" -Recurse -ErrorAction SilentlyContinue -Force |
            Select-Object -ExpandProperty FullName

    if (-not $results -or $results.Count -eq 0) {
        Write-Host "No php.exe found. Please specify the path manually."
        $phpExe = Read-Host "Enter the full path to your php.exe (e.g. C:\xampp\php\php.exe)"
    }
    elseif ($results.Count -eq 1) {
        # Only one result found, use it directly
        $phpExe = $results
        Write-Host "Found php.exe at: $phpExe"
    }
    else {
        # Multiple matches found; let user choose
        Write-Host "Multiple php.exe files were found. Please choose one:"
        $i = 1
        foreach ($result in $results) {
            Write-Host "[$i] $result"
            $i++
        }
        $selection = Read-Host "Enter the number of the desired php.exe"

        # Validate selection
        if ([int]::TryParse($selection, [ref]$null) -and ($selection -gt 0) -and ($selection -le $results.Count)) {
            $phpExe = $results[$selection - 1]
            Write-Host "Selected: $phpExe"
        }
        else {
            Write-Host "Invalid selection. Exiting."
            return
        }
    }
}
else {
    # User wants to specify the path manually
    $phpExe = Read-Host "Enter the full path to your php.exe (e.g. C:\xampp\php\php.exe)"
}

# If for some reason $phpExe is still not set or the file doesn't exist, stop
if (-not $phpExe -or -not (Test-Path $phpExe)) {
    Write-Host "Invalid php.exe path: '$phpExe'. Exiting."
    return
}

Write-Host "Using php.exe at: $phpExe"

# -------------------------------------------
# 4) Create db.bat with the chosen php path
# -------------------------------------------
$dbBatPath = Join-Path -Path $binPath -ChildPath "db.bat"

# We'll embed the path to the downloaded refreshdb.php as well
# (which should be in $binPath).
$scriptPath = $destinationPhp

# Here-String to build the content of db.bat
$dbBatContent = @"
@echo off
set "PHP_EXECUTABLE=$phpExe"
set "SCRIPT_PATH=$scriptPath"

%PHP_EXECUTABLE% %SCRIPT_PATH% %*
"@

# Write the content
Set-Content -Path $dbBatPath -Value $dbBatContent

Write-Host "db.bat has been created at: $dbBatPath"
Write-Host "You can now run 'db' from the terminal (if $HOME\bin is in your PATH)."
