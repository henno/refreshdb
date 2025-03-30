# RefreshDB

## 1. Overview

**RefreshDB** is a tool for MariaDB/MySQL for effortlessly:

- producing concise and consistent SQL dumps, regardless of the database engine type and version used
- restoring a dump into a database while ensuring the result is consistent
- applying the same cleanup/normalization rules to existing SQL dumps

For that, it offers three modes:

1. **Dump** – Generate a normalized SQL dump from a live database.
2. **Restore** – Restore `doc/database.sql` into a (freshly dropped and re-created) database.
3. **Repair** – Take an existing `doc/database.sql` and apply the same cleanup/normalization rules as with --dump.

## 2. Installation

### Windows

You can use the PowerShell installation script to set up RefreshDB on Windows:

1. Run the following command in PowerShell:
   ```powershell
   Invoke-Expression (Invoke-WebRequest -Uri https://raw.githubusercontent.com/henno/refreshdb/main/INSTALL.ps1 -UseBasicParsing).Content
   ```

This will:
- Add `$HOME\bin` to your PATH if it's not already included
- Create the bin directory if it doesn't exist
- Download the latest refreshdb.php script to your bin directory
- Create a wrapper batch file (db.bat) so you can run the tool with just `db [options]`

## 3. Usage

### Linux/macOS
```bash
php refreshdb.php [--dump | --restore | --repair]
```

### Windows
If you installed using the PowerShell script (INSTALL.ps1), you can simply use:
```cmd
db [--dump | --restore | --repair]
```

You can only use one mode at a time. If no mode is specified, the script will show usage information.

## 4. Reading Database Credentials

- If `config.php` is present, parse out `DATABASE_USERNAME`, `DATABASE_HOSTNAME`, `DATABASE_DATABASE`, and `DATABASE_PASSWORD`.
- If `DATABASE_PASSWORD` is empty, the -p flag will be omitted from the commands.
- Allow for varied quoting (single/double) and spacing.
- Both `const` and `define()` forms are possible.
- If `config.php` is missing, look for WordPress-style `DB_` prefixed constants. Same parsing logic as above.
- If neither file is found or needed constants can't be read, output an error and exit.

## 5. Dumping & Repairing

Both **`--dump`** and **`--repair`** perform the **same normalization/cleanup** on the SQL output:

- **`--dump`**
    - **Encoding**: Ensures that everything is UTF-8 encoded.
    - **Source**: Reads tables from the live database.
    - **Streaming**: Reads by line and replaces text before writing the line to disk, to avoid running out of memory.
    - **Output**: Writes the processed result to `doc/database.sql` (configurable).

- **`--repair`**
    - **Encoding**: Can detect if the coding is in UTF16LE but writes the output always in UTF8.
    - **Source**: Reads existing SQL from `doc/database.sql`.
    - **Streaming**: Reads by line and replaces text before writing the line to disk, to avoid running out of memory.
    - **Output**: Overwrites `doc/database.sql` with the cleaned version (identical to what `--dump` would produce).

### 5.1 Normalization Rules for `--dump` and `--repair`

- Adds Current DateTime and host name to the top of the file as comments.
- Adds `SET FOREIGN_KEY_CHECKS=0;` and `SET @@SESSION.sql_mode='NO_AUTO_VALUE_ON_ZERO';` to avoid interdependency problems and ensure consistent handling of zero values in auto-increment fields.
- Converts `int(11)` to `int`, etc. (remove length parentheses) to make output consistent.
- Removes quotes from numeric literals like '1' or "0" to make output consistent.
- Omits any `COLLATE` clauses to get around MySQL and MariaDB collation name differences.
- Replaces `utf8` (whole word only) with `utf8mb4` to make output consistent.
- Removes all `DEFINER` references from triggers, procedures, etc. because they will cause errors when restoring to a
  different database that doesn't have the same user.
- Consolidates all VALUE blocks of INSERT INTOs onto as few lines as possible, while not exceeding a configured maximum
  line length (120 characters by default). If adding another VALUE block would exceed that limit, places it on a new line. Keeps each
  VALUE block intact (does not split it into multiple lines).
- Removes `AUTO_INCREMENT` from `CREATE TABLE` statements.
- Removes all Mysqldump comments (including "MySQL dump", "Dump", "Server version", "Dump completed on", "MariaDB dump", "Host:", "Current Database:", and separator lines).
- Removes `DROP TABLE IF EXISTS` from CREATE TABLE statements (restoring starts with a fresh database).
- Removes `/*M!999999\- enable the sandbox mode */` line (this is a comment that is not supported by older versions of MariaDB).

## 6. Restore Mode

- Drops the database if it exists and creates it. This prevents leftover tables not in the dump.
- Reads from `doc/database.sql` (configurable).
- Uses `--binary-mode` to ensure binary data is not misread.
- Does not do any transformations to the SQL during restore.

## 7. Logging & Progress

- Outputs total time spent at the end.
- Logs executed commands to help with debugging and troubleshooting.
- Each log is prefixed with `[0.0]` where the number is the total time spent in seconds since the start of the script.
- If any errors occur, they're printed and the script exits with a non-zero status code.

## 8. Configuration

The script uses default paths and settings that can be modified in the source code:
- Default input/output path: `doc/database.sql`
- Default maximum line length for INSERT statements: 120 characters