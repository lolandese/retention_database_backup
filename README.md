# Retention Database Backup

A Drupal module that provides automated database backups with a sophisticated 4-tier retention policy. Designed for flexible backup management with optional email notifications and external storage support.

## Features

- **Automated Daily Backups**: Creates database backups via Drupal cron
- **4-Tier Retention Strategy**: Intelligently maintains backups across multiple time scales
  - Recent backups (last 2 days)
  - 1-month snapshot
  - 6-month snapshot
  - 1-year snapshot
- **Email Notifications**: Sends backup creation notifications to configured recipients
- **Gzip Compression**: All backups are compressed to save disk space
- **Git Integration**: Backup filename includes branch name and commit hash for easy version tracking
- **Optional Install Folder Sync**: Can sync latest backup to project's `install/` folder for distribution with codebase
- **Extensible Architecture**: Ready for future external storage plugins (SFTP, S3, Webhooks)
- **Comprehensive Logging**: All operations logged to Drupal watchdog

### 🚨 Disaster Recovery

**For complete site recovery scenarios** (database corruption, server loss, total disaster), see **[DISASTER_RECOVERY.md](DISASTER_RECOVERY.md)**.

This module handles **database backups** with commit-hash tagging. For complete site protection, you also need:
- **Code backups**: Use Git (GitHub/GitLab)
- **File backups**: Use [Restic Backup](https://www.drupal.org/project/restic_backup) module (recommended for user uploads and private files)

Together, these three components form the "three-legged stool" of complete Drupal backup strategy. See the disaster recovery guide for detailed recovery scenarios and the importance of code/database alignment.

## Requirements

- Drupal 10.x or 11.x
- Drush 13.x+ (must be installed and accessible in system PATH)
- PHP 8.1+
- Git repository (optional, but recommended for commit tracking)

## Installation

1. **Place the module** in `web/modules/custom/retention_database_backup/`

2. **Enable the module**:
   ```bash
   ddev exec drush pm:enable retention_database_backup
   ```

3. **Configure the module**:
   - Go to `/admin/config/system/retention-database-backup`
   - Or navigate to: Administration > Configuration > System > Retention Database Backup Settings

4. **Verify Drush requirement**: Check `/admin/reports/status` to ensure Drush is detected and accessible

## Configuration

### Manual drush.yml Setup (Required)

Add the following to your project root `drush.yml` (create if it doesn't exist) to configure which tables should skip data in backups:

```yaml
command:
  sql:
    dump:
      options:
        structure-tables-key: common
```

This tells Drush to keep the schema/structure for cache tables (watchdog, cache, sessions, etc.) but skip their data, which significantly reduces backup size while preserving the ability to rebuild these tables after restore.

For more information on Drush configuration, see: https://github.com/drush-ops/drush/blob/master/examples/example.drush.yml

### Admin Settings

1. **Backup Settings**
   - **Enable Automatic Backups**: Toggle to enable/disable backups on cron runs

2. **Email Settings** (optional)
   - **Email Recipients**: Email addresses to receive backup creation notifications. Enter one per line or use comma-separated values. Newly added recipients receive a welcome email explaining why they were added and how to opt-out.
   - **From Email Address**: Email address for the "From" header. Recipients can reply to this address to request removal from the list.

3. **External Storage Service** (under development)
   - Future support for SFTP, AWS S3, and Webhook/HTTP POST for large backups

4. **Install Folder Sync** (optional)
   - **Enable Install Folder Sync**: When enabled, the latest backup is copied to the configured install folder
   - **Install Folder Path**: Relative path from project root (default: `install`)

## Retention Policy

The module maintains a 4-tier retention strategy to balance storage, recovery flexibility, and disaster recovery capability.

### Tier 1: Recent Backups (2-Day Daily)
- **Age Range**: Last 2 calendar days
- **Strategy**: Keep ALL backups from this period
- **Use Case**: Recover from mistakes made in the last 1-2 days
- **Typical Count**: 2-5 files

### Tier 2: 1-Month Snapshot
- **Age Range**: 2 days to 30 days old
- **Strategy**: Keep 1 representative file from this period
- **Use Case**: Roll back site issues discovered within the month
- **Typical Count**: 1 file

### Tier 3: 6-Month Snapshot
- **Age Range**: 30 days to 180 days old
- **Strategy**: Keep 1 representative file from this period
- **Use Case**: Recover from issues discovered weeks or months later
- **Typical Count**: 1 file

### Tier 4: 1-Year Snapshot
- **Age Range**: 180 days to 365 days old
- **Strategy**: Keep 1 representative file from this period
- **Use Case**: Year-over-year recovery or compliance/audit requirements
- **Typical Count**: 1 file

**Total**: Typically maintains 4-10 backup files total, balancing storage use and recovery options.

## Backup Filename Format

Backup files follow this naming convention:

```
YYYYMMDDTHHMMSSS-branch-commithash.sql.gz
```

**Breakdown**:
- `YYYYMMDDTHHMMSSS`: ISO 8601 timestamp (e.g., 20260209T143022)
- `branch`: Current git branch, sanitized (e.g., `main`, `develop`)
- `commithash`: First 8 characters of current git commit (e.g., `a1b2c3d4`)

**Example**:
```
20260209T143022-main-a1b2c3d4.sql.gz
```

This format allows you to:
- Sort backups chronologically by default
- Know when the backup was taken
- Identify the git branch and commit state
- Restore code to the same commit as the database (important for schema compatibility)

## Restoring from Backup

### Basic Restore Process

```bash
# 1. Stop and drop the current database
drush sql-drop

# 2. Decompress the backup (keep original with -k flag)
gunzip -k /path/to/backup/YYYYMMDDTHHMMSSS-branch-commithash.sql.gz

# 3. Restore the database from the SQL file
drush sql-cli < /path/to/backup/YYYYMMDDTHHMMSSS-branch-commithash.sql

# 4. IMPORTANT: Reset codebase to the commit hash in the backup filename
git checkout COMMITHASH

# 5. Reinstall dependencies
composer install

# 6. Run database updates
ddev exec drush updatedb

# 7. Rebuild caches
ddev exec drush cache:rebuild
```

### Why Align Codebase and Database

The commit hash in the backup filename tells you which version of the code was running when the backup was created. This is crucial because:

- **Database Schema Changes**: Code updates might modify entity fields, content types, or database schema. Running mismatched code can cause errors.
- **Configuration State**: Configuration changes between commits affect features, permissions, and system behavior.
- **Module Dependencies**: Newer code might depend on modules not yet enabled, or expect entity types that don't exist in the old database.
- **Field Access Errors**: Mismatched code/database can produce cryptic field access errors or cause pages to break.

**Best Practice**: Always reset your codebase to the same commit hash as your backup to ensure compatibility.

## Backup Storage

### Backup Directory

All backups are stored in `db-backups/` directory in the project root:

```
project-root/
├── db-backups/              # Backup storage (in .gitignore)
│   ├── 20260209T143022-main-a1b2c3d4.sql.gz
│   ├── 20260208T140015-main-8f7e6d5c.sql.gz
│   └── backup.log
├── install/
├── web/
└── ...
```

**Note**: The `db-backups/` directory is **not committed to git** because backup files can be large. See `.gitignore`.

### Optional: Install Folder Distribution

If enabled, the latest backup is also copied to the `install/` folder for distribution with the codebase:

```
project-root/
├── install/
│   ├── database.sql.gz      # Latest backup (optional, can be tracked)
│   └── README.md            # Instructions for new developers
├── db-backups/              # (not in git)
└── ...
```

This allows new developers cloning the repo to have a pre-populated database. See "Install Folder README" below for more details.

## Manual Database Backups

You can trigger a database backup immediately without waiting for cron to run. Multiple methods are available (choose the simplest for your workflow).

### Recommended: Drush Command

The easiest way to trigger a backup manually:

```bash
ddev exec drush retention-backup:create
```

**Aliases:** `rdb-create`

**Output:**
```
[success] Database backup created: 20260210T143022-main-a1b2c3d4.sql.gz
Retention policy applied. Deleted 1 backup(s).
```

This command automatically:
- ✓ Creates a backup file immediately
- ✓ Applies retention policy (deletes old backups if needed)
- ✓ Sends backup creation email to configured recipients
- ✓ Logs all actions for audit trail

### Alternative: Shell Script

A convenience wrapper script at `./scripts/backup-now.sh`:

```bash
./scripts/backup-now.sh
```

### Alternative: Direct PHP via Drush

For scripting or custom integrations:

```bash
ddev exec drush php:eval '\$bm = \Drupal::service("retention_database_backup.backup_manager"); \$file = \$bm->createBackup(); print "Backup created: " . basename(\$file) . "\n";'
```

**Output:**
```
Backup created: 20260210T143022-main-a1b2c3d4.sql.gz
```

### Alternative: Complete Backup Workflow

To create a backup and apply retention policy:

```bash
ddev exec drush php:eval '
\$bm = \Drupal::service("retention_database_backup.backup_manager");
\$rp = \Drupal::service("retention_database_backup.retention_policy");

\$file = \$bm->createBackup();
print "✓ Backup created: " . basename(\$file) . "\n";

\$deleted = \$rp->applyRetentionPolicy();
if (!empty(\$deleted)) {
  print "✓ Deleted " . count(\$deleted) . " old backup(s)\n";
} else {
  print "✓ No old backups to delete\n";
}
'
```

Emails are sent automatically when backups are created (if recipients are configured). Email notifications include backup filename, size, and creation time.

## Exporting Backups for Team Members

### For Sharing with New Developers

Create an encrypted (or unencrypted) backup specifically for sharing with team members who are cloning the repo:

```bash
ddev exec drush retention-backup:export
```

**Aliases:** `rdb-export`

**Output:**
```
[success] Database backup created: 20260210T143022-main-a1b2c3d4.sql.gz
[success] Encrypted backup exported for team: database-latest.sql.gz.gpg
[info] Location: install/database-latest.sql.gz.gpg
[info] This backup is encrypted. Recipients need to decrypt it with GPG before importing.
[info] New developers can import this with: ddev pull database (or use configure-imported-database.sh)
```

This command:
- ✓ Creates a fresh database backup
- ✓ Encrypts it with GPG (if configured) for security
- ✓ Saves it to `install/database-latest.sql.gz` or `install/database-latest.sql.gz.gpg`
- ✓ Provides predictable filename for automated imports
- ✓ Works with team's standard onboarding process

### Use Cases

**Scenario 1: Onboarding New Developers**
1. Lead developer runs: `ddev exec drush rdb-export`
2. Commit the `install/database-latest.sql.gz.gpg` file to the repository
3. New developers clone the repo and run setup script (e.g., `./scripts/setup.sh` or `ddev import-db`)
4. New developers have the latest database with all content

**Scenario 2: Staging Environment Clones**
1. Export production database: `ddev exec drush rdb-export`
2. CI/CD pipeline imports from `install/database-latest.sql.gz.gpg`
3. Automated testing has current data

**Scenario 3: Emergency Database Recovery**
1. Export current state: `ddev exec drush rdb-export`
2. Store the `install/database-latest.sql.gz.gpg` file in secure location
3. Later, restore with: `ddev import-db < install/database-latest.sql.gz`

## GPG Encryption Setup & Troubleshooting

### Why GPG Encryption?

Database backups contain sensitive data:
- User account credentials (hashed passwords)
- User email addresses and personal information
- Site configuration with API keys and secrets
- Custom data and confidential content

**Encryption protects your backups when:**
- Sharing with team members via email or git repositories
- Storing in the `install/` folder that gets committed to version control
- Transferring backups between environments
- Storing in cloud services or external locations

### Installing GPG on the Server

**In DDEV container** (local development):
GPG is pre-installed in DDEV's Debian-based containers. No additional installation is required.

**On production servers:**
```bash
# Ubuntu/Debian
sudo apt-get install gnupg2

# CentOS/RHEL
sudo yum install gnupg2

# macOS
brew install gnupg
```

Verify installation:
```bash
ddev exec which gpg
ddev exec gpg --version
```

### Generating a New GPG Key

For automated backups, create a key **without a passphrase** (automation can't interactively enter passphrases):

```bash
# Generate key for martinuspostma@gmail.com (adjust email as needed)
ddev exec gpg --batch --gen-key << EOF
%echo Generating GPG key...
Key-Type: RSA
Key-Length: 2048
Name-Real: Drupal Backup
Name-Email: martinuspostma@gmail.com
Expire-Date: 2y
%no-protection
%commit
%echo Done
EOF
```

Verify the key was created:
```bash
ddev exec gpg --list-keys martinuspostma@gmail.com
```

**Expected output:**
```
pub   rsa2048 2026-02-10 [SCEA] [expires: 2028-02-10]
     ABC123DEF456GHI789JKL012MNO345PQR678STU
uid           [ultimate] Drupal Backup <martinuspostma@gmail.com>
```

### Configuring Drupal to Use GPG

1. Go to `/admin/config/system/retention-database-backup`
2. Under "Encryption Settings":
   - ✓ Check "Enable GPG Encryption"
   - Enter "GPG Recipient Email or Key ID": `martinuspostma@gmail.com` (or the key ID like `ABC123DEF...`)
   - Click "Save configuration"

3. Check system status at `/admin/reports/status`:
   - You should see ✓ "Retention Database Backup: GPG Encryption - GPG found"
   - If you see ✗ "GPG not found", the `gpg` command is not accessible

### Importing an Existing GPG Key

If you already have a GPG key on your local machine and want to use it in DDEV:

```bash
# Export key from local machine
gpg --export-secret-key martinuspostma@gmail.com > /tmp/my-key.gpg

# Import into DDEV container
ddev exec gpg --import /tmp/my-key.gpg

# Verify import
ddev exec gpg --list-secret-keys martinuspostma@gmail.com
```

### Testing GPG Encryption

Test that encryption is working:

```bash
# Run the export command
ddev exec drush rdb-export

# Should see success message like:
# [success] Database backup created: 20260210T143022-main-a1b2c3d4.sql.gz
# [success] Encrypted backup exported for team: database-latest.sql.gz.gpg
```

If you see warning messages like `[warning] Failed to encrypt backup`, see "GPG Encryption Troubleshooting" below.

### Decrypting Backups

**For team members receiving encrypted backups:**

```bash
# Decrypt the file
gpg --decrypt --output database.sql.gz install/database-latest.sql.gz.gpg

# Enter passphrase if one was set (or press Enter if not)
# Two files will be created: database.sql.gz

# Then import into Drupal
ddev exec drush sql:cli < database.sql.gz

# Or use DDEV's import helper
ddev import-db < database.sql.gz
```

### GPG Encryption Troubleshooting

#### Error: "gpg: error retrieving 'email@example.com' via WKD: No data"

**Symptom**: Encryption fails with message about WKD lookup failure.

**Cause**: GPG tried to download the public key from a key server (WKD - Web Key Directory) and failed.

**Solution**:
1. Verify the key exists locally: `ddev exec gpg --list-keys email@example.com`
2. If key doesn't exist, generate one (see "Generating a New GPG Key" above)
3. If key exists, trust it locally:
   ```bash
   ddev exec gpg --edit-key email@example.com
   # Type: trust
   # Choose: 5 (ultimate trust)
   # Then: quit
   ```

#### Error: "gpg: martinuspostma@gmail.com: skipped: No data"

**Symptom**: GPG encryption fails, backup exported unencrypted with warning.

**Cause**: The GPG key is not found or not trusted in the keyring.

**Solution**:
1. Check if key exists: `ddev exec gpg --list-keys | grep martinuspostma`
2. If missing, generate it (see "Generating a New GPG Key")
3. If it exists, check `retention_database_backup.settings` config to ensure email matches exactly:
   ```bash
   ddev exec drush config:get retention_database_backup.settings gpg_recipient
   ```
4. Rebuild the GPG keyring cache:
   ```bash
   ddev exec gpg --update-trustdb
   ```

#### Error: "encryption failed: No data"

**Symptom**: Backup created but encryption fails completely.

**Cause**: Usually means the public key is not in the GPG keyring or trust database is corrupted.

**Solution**:
1. List all GPG keys: `ddev exec gpg --list-keys`
2. If no output, you need to generate or import a key
3. If keys exist but encryption still fails, rebuild trust database:
   ```bash
   ddev exec gpg --update-trustdb
   ```

#### Error: "GPG is not available on this system"

**Symptom**: Status page shows error: "Encryption is enabled but GPG is not installed."

**Cause**: Either `gpg` is not installed or not in the system PATH.

**Solution**:
1. Install GPG (see "Installing GPG on the Server" above)
2. Verify it's accessible: `ddev exec which gpg`
3. If `which gpg` returns empty or error, GPG is not installed

#### Encryption works locally but fails on production

**Symptom**: Works in DDEV but fails when cron runs on production server.

**Causes**:
1. GPG not installed on production server
2. GPG key not imported on production server
3. Different user running backups doesn't have access to GPG keyring
4. SELinux or AppArmor is blocking GPG command

**Solutions**:
1. Install GPG on production server
2. Generate or import GPG key on production server
3. Ensure the web server user (e.g., `www-data`) can access GPG:
   ```bash
   sudo -u www-data gpg --list-keys
   ```
4. Check security policies or ask your hosting provider about command restrictions

#### How to disable encryption if GPG setup is problematic

If you want to skip encryption for now:

1. Go to `/admin/config/system/retention-database-backup`
2. Uncheck "Enable GPG Encryption"
3. Save configuration
4. Backups will export unencrypted (no .gpg files)
5. Remove the error from `/admin/reports/status`

You can re-enable it later once GPG is properly set up.

### GPG Configuration

If you want encrypted backups (recommended for sensitive data):

## Email Configuration

### Prerequisites

- Drupal mail system must be configured (usually uses system sendmail by default)
- At least one recipient email configured in module settings

### Testing Email

Test if emails work by enabling the option and triggering cron manually:

```bash
ddev exec drush cron
```

Check logs: `/admin/reports/dblog` with channel "retention_database_backup"

### Large Backup Handling

If a monthly backup exceeds the `max_email_attachment_size` setting:
1. Email notification is sent explaining the file is too large
2. You're prompted to configure an external storage service (future feature)
3. Or manually upload the backup to your secure location

### Email Content

Backup notification emails include:
- Backup filename (for easy identification)
- File size (in human-readable format)
- Creation timestamp
- Git commit hash (if available, for version tracking)

### New Recipient Notifications

When you add a new email address to the recipient list, the module automatically sends a welcome notification:

1. **Automatic notification**: New recipients receive an email explaining:
   - They've been added to the backup notification list
   - They will receive notifications when database backups are created
   - How to opt-out if it's inappropriate
   - The reply-to address for their concerns

2. **Opt-out process**: If a recipient replies that they don't want the emails:
   - You receive their reply at the "From Email Address" you configured
   - Manually remove their email from the recipient list
   - Save settings (no notification is sent when removing)

### Setting the From Email Address

The "From Email Address" setting is crucial for email management:

1. Go to `/admin/config/system/retention-database-backup`
2. Under "Email Settings", enter a "From Email Address":
   - Should be a real person's email (e.g., `backup-admin@company.com`)
   - **NOT the system email** - those often aren't monitored
   - Include name/team if needed (e.g., `Database Team <db-team@company.com>`)
   - This is where opt-out requests will be sent

3. **Why this matters**:
   - Recipients can reply to emails with questions or concerns
   - Those replies go to the address you specify
   - If you use the system email, important feedback gets lost
   - Example of bad practice: Using `noreply@site` as From address

### Managing Email Recipients

**Adding recipients**:
1. Edit the "Email Recipients" field with comma-separated or line-separated emails
2. New recipients (not in the old list) automatically receive notification emails
3. Save settings
4. Existing recipients are unaffected

**Removing recipients**:
1. Edit the "Email Recipients" field and remove the email address
2. No notification is sent to removed recipients
3. Save settings

**Bulk update**:
- You can add/remove multiple recipients at once
- Only newly added ones receive welcome notifications
- Removed recipients receive no notification

### Example Emails

**Welcome email for new recipients**:

```
Subject: You have been added to Example Site backup email list

Hello,

You have been added to receive database backup notifications for Example Site.

You will receive emails when database backups are created. If you believe this is not appropriate or you would like to be removed, please reply to this email at backup-team@example.com explaining your situation.

Thank you,
Example Site Backup System
```

**Backup notification email**:

```
Subject: Database backup created for Example Site

Hello,

A database backup has been created for Example Site.

Backup: 20260210T140000-main-a1b2c3d4.sql.gz
Size: 1.35 MB
Created: 2026-02-10 14:00:00

If you no longer wish to receive these notifications, please reply to this email.

Thank you,
Example Site Backup System
```

## External Storage (Future Feature)

Currently, the module supports:
- Local `db-backups/` directory
- Optional `install/` folder sync

Future versions will support:
- **SFTP**: Upload to remote SFTP server for offsite storage
- **AWS S3**: Store in Amazon S3 buckets for cost-effective long-term storage
- **Webhooks**: HTTP POST to custom endpoints for integration with third-party services

## Comparison with Backup & Migrate Module

**Retention Database Backup** is a **complementary** module, not a replacement for [Backup & Migrate](https://www.drupal.org/project/backup_migrate). Here's how they compare:

### Feature Comparison

| Feature | Retention DB Backup | Backup & Migrate |
|---------|-------------------|------------------|
| **Automated Daily Backups** | ✅ Yes | ✅ Yes (via UI) |
| **4-Tier Retention Policy** | ✅ Custom-designed | ✅ Customizable |
| **Git Integration** | ✅ Commit hash in filename | ❌ No |
| **Install Folder Distribution** | ✅ Yes | ❌ No |
| **Email Notifications** | ✅ 1-month tier | ✅ After every backup |
| **Gzip Compression** | ✅ Automatic | ✅ Yes |
| **GPG Encryption** | ✅ Optional | ✅ Optional (via credentials) |
| **External Storage (S3, SFTP)** | 🚧 Planned | ✅ Yes (many sites) |
| **Admin UI** | ✅ Minimal, focused | ✅ Comprehensive dashboard |
| **Drupal Restore UI** | ❌ No | ✅ Full UI restoration |
| **Code/Module Backup** | ❌ Database only | ✅ Full site backup |
| **File System Backup** | ❌ No | ✅ Yes |
| **Scheduled Backups** | ✅ Cron (daily, flexible) | ✅ UI-configured schedules |
| **Configuration Import/Export** | ❌ No | ❌ No (use Drupal core) |

### When to Use Retention Database Backup

✅ **Best for:**
- Projects that prioritize **codebase alignment** (commit hash tracking)
- Teams distributing database snapshots **within the repository** (`install/` folder)
- Simple, **focused backup needs** (database only)
- Development environments where **retention strategy matters** over feature richness
- Projects already using Drush heavily
- Minimal additional complexity / reduced UI cognitive load

### When to Use Backup & Migrate

✅ **Best for:**
- Projects needing **restore UI** and full-featured restoration experience
- Organizations backing up **code, files, and database** together
- Sites requiring **external storage** (S3, SFTP, FTP) at this moment
- Teams wanting **comprehensive backup administration** dashboard
- Legacy or complex restoration workflows
- File/module/theme backups alongside database

### Using Both Together (Recommended)

This is **not an either-or choice**. Many organizations use both:

1. **Retention Database Backup** for:
   - Frequent, lightweight **database-only snapshots**
   - Developer-friendly **git-integrated backups** in `install/` folder
   - Automated 4-tier retention without admin overhead
   - Quick recovery from recent mistakes

2. **Backup & Migrate** for:
   - Weekly/monthly **full site backups** (code + files + database)
   - **Offsite storage** for disaster recovery (S3, SFTP)
   - **Restoration UI** for less technical users
   - Compliance/audit trail with comprehensive logging

**Example Architecture:**
```
Daily (Automated):        Retention Database Backup
└─ Lightweight, 4-tier retention, git tracking

Weekly (Scheduled):       Backup & Migrate
└─ Full site backup to external storage (S3)

Monthly (Archive):        Backup & Migrate
└─ Long-term compliance copy
```

This combination provides:
- Fast, frequent database recovery (Retention DB Backup)
- Security against full site loss (Backup & Migrate to S3)
- Developer-friendly git integration (Retention DB Backup)
- Non-technical restoration capability (Backup & Migrate UI)

### Key Differences in Philosophy

| Aspect | Retention DB Backup | Backup & Migrate |
|--------|-------------------|------------------|
| **Focus** | Git-aware retention | Comprehensive site backup |
| **Complexity** | Minimal & focused | Feature-rich |
| **Learning Curve** | Shallow | Moderate |
| **Administrator** | Developers/DevOps | Site admins / backup specialists |
| **Primary Use** | Development/QA | Production / high-compliance |
| **Storage Strategy** | Retention tiers | Single location or external |

## Troubleshooting

### Backups Not Created

**Problem**: No backup files in `db-backups/` directory

**Solutions**:
1. Verify module is enabled: `ddev exec drush pm:list | grep retention_database_backup`
2. Check module status page: `/admin/reports/status`
3. Verify Drush requirement is met: `ddev exec drush --version`
4. Check Drush is in PATH: `which drush`
5. Check cron is running: `/admin/config/system/cron`
6. Manually trigger cron: `ddev exec drush cron`
7. Check logs: `/admin/reports/dblog` for "retention_database_backup" channel

### Emails Not Sending

**Problem**: Module settings show email enabled, but no emails received

**Solutions**:
1. Verify Drupal mail system is configured: Go to `/admin/config/system/site-information` and check "Email address"
2. Test email: `ddev exec drush php:eval "mail('test@example.com', 'Test', 'Test email from Drupal');"`
3. Check mail logs: `/admin/reports/dblog`
4. Consider installing a mail module (e.g., Mailsystem, Swiftmailer) for better email support with attachments
5. Verify email format in module settings (should be valid email addresses)

### Backup Files Too Large

**Problem**: "Backup Too Large for Email" notification

**Solutions**:
1. Increase `max_email_attachment_size` setting (if email provider supports it)
2. Or reduce backup size by:
   - Excluding more cache tables via `drush.yml`
   - Clearing watchdog logs: `ddev exec drush watchdog:delete all`
   - Cleaning up file uploads: `/admin/content/files`
3. Configure external storage service (future feature)
4. Manually upload backups to secure location

### Retention Policy Not Deleting Old Files

**Problem**: Backups older than policy should allow are still present

**Solutions**:
1. Verify cron is running and executing the module: Check `/admin/reports/dblog`
2. Run cron manually: `ddev exec drush cron`
3. Manually check retention logic by examining backup files:
   ```bash
   ls -ltr db-backups/ | tail -20
   ```
4. Verify file system permissions allow deletion: `ls -ld db-backups/`

### Module Not Installing

**Problem**: "Missing module" or "Dependency error"

**Solutions**:
1. Ensure Drush is installed: `ddev exec drush --version`
2. Clear module cache: `ddev exec drush cc all` (Drupal 8) or `ddev exec drush cache:rebuild` (Drupal 9+)
3. Re-enable module: `ddev exec drush pm:uninstall retention_database_backup` then `ddev exec drush pm:enable retention_database_backup`

## Contributing

This module is designed to be extended. Planned plugin system for custom backup destinations (SFTP, S3, etc.).

### Future Enhancements

- [ ] Plugin system for external storage services
- [ ] SFTP destination plugin
- [ ] AWS S3 destination plugin
- [ ] Webhook/HTTP POST destination plugin
- [ ] Backup restoration UI
- [ ] Backup size estimation
- [ ] Manual backup trigger in admin UI
- [ ] Scheduled backup times (beyond daily cron)

## Security Considerations

### Database Contents

Database backups contain sensitive data:
- User account credentials (hashed)
- User email addresses
- Site configuration and secrets
- Custom data and content

**Recommendations**:
1. Restrict `db-backups/` directory access (not world-readable)
2. Limit email recipients to trusted team members
3. Backup files sent via email should only go to secure company email
4. Store logs in secure location
5. Use external services (S3, SFTP) with encryption in transit (TLS/SSL)

### Install Folder Database

If you enable install folder sync and commit `install/database.sql.gz` to git:
1. This includes sensitive data (user credentials, config, etc.)
2. The database is readable by anyone who clones the repository
3. **Mitigation**: New developers should reset the admin password using `drush uli`
4. See "Install Folder README" for details

### External Services

If using external services (future feature):
1. Store credentials securely in Drupal configuration (never hardcode)
2. Use environment variables for sensitive keys
3. Ensure service credentials have minimal required permissions
4. Use encryption in transit (TLS/SSL, HTTPS)

## License

This module is available under the same license as Drupal core (GPL v2 or later).

## Support

- Issue tracker: (To be determined)
- Drupal.org project: (To be determined after publishing)

## Author

Retention Database Backup module for Drupal.
