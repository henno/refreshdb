# Append $HOME\bin to PATH if it's not already included
if (-not ($env:PATH -split ';' -contains "$HOME\bin")) { [Environment]::SetEnvironmentVariable('PATH', ($env:PATH.TrimEnd(';') + ";$HOME\bin"), 'User') }

# Ensure the bin directory exists
$binPath = Join-Path -Path $HOME -ChildPath "bin"
if (-not (Test-Path -Path $binPath)) { New-Item -ItemType Directory -Path $binPath -Force }

# Download the script to the bin directory
$scriptUrl = "https://raw.githubusercontent.com/henno/refreshdb/refs/heads/main/refreshdb.php"
$destinationPath = Join-Path -Path $binPath -ChildPath "refreshdb.php"
Invoke-WebRequest -Uri $scriptUrl -OutFile $destinationPath

# Create a wrapper db.bat to avoid having to preceed the php file with the full path to php.exe
Set-Content -Path "$HOME\bin\db.bat" -Value @'
@echo off
set "PHP_EXECUTABLE=C:\xampp824\php\php.exe"
set "SCRIPT_PATH=c:\users\user\bin\refreshdb.php"

%PHP_EXECUTABLE% %SCRIPT_PATH% %*
'@