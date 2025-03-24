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

## 2. Usage

```bash
php refreshdb.php [--dump | --restore | --repair]
```

## 3. Reading Database Credentials

- If `config.php` is present, parse out `DATABASE_USERNAME`, `DATABASE_HOSTNAME`, `DATABASE_DATABASE`.
- If DATABASE_PASSWORD is empty, the -p flag will be omitted from the commands.
- Allow for varied quoting (single/double) and spacing.
- Both `const` and `define()` forms are possible.
- If `config.php` is missing, look for WordPress-style `DB_` prefixed constants. Same parsing logic as above.
- If neither file is found or needed constants can’t be read, output an error and exit.

## 4. Dumping & Repairing

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

### 4.1 Normalization Rules for `--dump` and `--repair`

- Adds Current DateTime and host name to the top of the file as comments.
- Adds `SET FOREIGN_KEY_CHECKS=0;` to avoid interdependency problems while restoring tables.
- Converts `int(11)` to `int`, etc. (remove length parentheses) to make output consistent.
- Removes quotes from numeric literals like '1' or "0" to make output consistent.
- Omits any `COLLATE` clauses to get around MySQL and MariaDB collation name differences.
- Replaces `utf8` (whole word only) with `utf8mb4` to make output consistent.
- Removes all `DEFINER` references from triggers, procedures, etc. because they will cause errors when restoring to a
  different database that doesn’t have the same user.
- Consolidates all VALUE blocks of INSERT INTOs onto as few lines as possible, with each line up to a maximum of 220
  characters (it is very important not to exceed 220-characters per line limit). If adding another VALUE block would
  exceed that limit, places it on a new line. Keeps each VALUE block intact (does not split it into multiple lines).
- Removes `AUTO_INCREMENT` from `CREATE TABLE` statements.
- Removes all Mysqldump comments.
- Removes DROP TABLE IF NOT EXISTS from CREATE TABLE statements (restoring starts with a fresh database).
- Removes /*M!999999\- enable the sandbox mode */ line

## 5. Restore mode

- Drops the database if it exists and creates it. This prevents leftover tables not in the dump.
- Reads from `doc/database.sql`
- Uses `--binary-mode` to ensure binary data is not misread.
- Does not do any transformations

## 6. Logging & Progress

- Outputs total time spent at the end.
- Logs executed commands to help with debugging.
- Each log is prefixed with `[0.0]` where the number is the total time spent in seconds since the start of the script.
- If any errors occur, they’re printed and the script exits.