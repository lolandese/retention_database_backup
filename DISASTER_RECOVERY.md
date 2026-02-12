# Disaster Recovery Guide: Retention Database Backup Module

## Overview: The Three-Legged Stool

A complete Drupal site backup strategy relies on **three independent components**:

```
Complete Recovery = Code (Git) + Database + Files
         │              │           │          │
         │              │           │          └─ User uploads, private files
         │              │           └─ Content, config, state (SQL)
         │              └─ Modules, themes, custom code
         └─ Three-Legged Stool: All three legs required for stability
```

### This Module's Role

**Retention Database Backup** handles **Leg #2: Database**
- Content (nodes, users, taxonomy, etc.)
- Configuration (site settings, modules, permissions)
- State (cron times, cache metadata, form tokens)
- **Commit-hash tagging** to align database with code version

### The Other Two Legs

**Leg #1: Code** → Already backed up via Git (GitHub/GitLab remote repository)
- Commit history provides version tracking
- Branch protection ensures code safety
- Clone from remote anytime

**Leg #3: Files** → Use [Restic Backup](https://www.drupal.org/project/restic_backup) module (recommended)
- Incremental backups of user uploads
- Deduplication saves storage space
- Multiple storage backends (SFTP, S3)
- Automatic cache exclusions

**Why separate modules?** Each "leg" has different requirements:
- Code: Never deleted, always in git
- Database: Changes frequently, needs retention tiers
- Files: Large binaries, need incremental backups

This modular approach lets you choose your own file backup solution while still having comprehensive database protection with intelligent retention policy.

---

## Why Commit-Hash Tagging Matters

This module's unique feature: **backup filenames include git commit hashes**.

**Example filename**: `20260209T143022-main-a1b2c3d4.sql.gz`
- `20260209T143022` = Timestamp
- `main` = Git branch
- `a1b2c3d4` = Git commit hash (first 8 chars)

### Why This Is Critical for Disaster Recovery

**Problem**: Database schema and code must match
- Code update adds new field → Old database missing that field → Site breaks
- Code update removes old field → Old database has that field → Errors appear
- Configuration changes between commits → Mismatch causes unexpected behavior

**Solution**: Always align code and database
```bash
# Never do this (random code + old database)
git checkout main
drush sql-cli < old-backup.sql  # ❌ WRONG

# Always do this (commit hash alignment)
git checkout a1b2c3d4           # Match backup's commit hash
drush sql-cli < 20260209T143022-main-a1b2c3d4.sql  # ✅ CORRECT
```

**Real-world example**:
1. Monday: Deploy new feature, adds `field_product_sku` to database
2. Tuesday: Database corrupted, need to restore
3. Restore Monday's backup at commit `a1b2c3d4` → ✅ Works perfectly
4. Restore Monday's backup but leave code at Tuesday's commit → ❌ Code expects field that doesn't exist yet

The commit hash in the filename tells you exactly which code version to use.

---

## Recovery Scenarios

### Scenario 1: Database Corruption (Site Down, Files & Code OK)

**Situation**: Database corrupted, but web server files and code repository are intact.

**Recovery Steps**:

```bash
# 1. cd to your project directory
cd /var/www/html  # or wherever your site lives

# 2. Stop the site (optional but recommended)
ddev stop  # For DDEV
# Or: maintenance mode via Drush if site still bootstraps

# 3. List available backups
ls -lh db-backups/
# Look for: 20260209T143022-main-a1b2c3d4.sql.gz

# 4. Identify the commit hash from filename
# Example: a1b2c3d4

# 5. Checkout matching code version
git checkout a1b2c3d4
# Verify: git log --oneline -1

# 6. Reinstall dependencies (if code changed)
composer install

# 7. Decompress backup (keep original with -k flag)
gunzip -k db-backups/20260209T143022-main-a1b2c3d4.sql.gz

# 8. Restore database
drush sql-drop  # Drop current (corrupted) database
drush sql-cli < db-backups/20260209T143022-main-a1b2c3d4.sql

# 9. Run database updates (if needed)
drush updatedb -y

# 10. Rebuild caches
drush cache:rebuild

# 11. Verify site works
drush status
ddev launch  # Or visit site URL
```

**Expected recovery time**: 15-30 minutes

---

### Scenario 2: Complete Site Loss (Server Gone)

**Situation**: Hardware failure, data center disaster, need to rebuild everything from scratch.

#### Step 1: Provision New Server

```bash
# Install required software
sudo apt-get update
sudo apt-get install -y \
  php8.3 php8.3-cli php8.3-fpm php8.3-mysql php8.3-gd \
  php8.3-xml php8.3-mbstring php8.3-curl php8.3-zip \
  nginx mariadb-server composer git drush
```

#### Step 2: Restore Code from Git

```bash
# Create web root
sudo mkdir -p /var/www/html
sudo chown $USER:$USER /var/www/html
cd /var/www/html

# Clone repository
git clone https://github.com/your-org/your-site.git .

# Identify which commit hash to use
# Option 1: If you have backup filename (best)
# Use commit hash from filename: 20260209T143022-main-a1b2c3d4.sql.gz
git checkout a1b2c3d4

# Option 2: If using install/database.sql.gz (check when it was created)
# Use commit close to that date
git log --until="2026-02-09" --oneline -1
# Then: git checkout <commit-hash>

# Install dependencies
composer install --no-dev --optimize-autoloader
```

#### Step 3: Restore Database

**Option A: Using install/database.sql.gz** (if in git repo):

```bash
cd /var/www/html

# If encrypted with GPG
gpg --decrypt --output database.sql.gz install/database.sql.gz.gpg

# Decompress
gunzip -k install/database.sql.gz

# Import
drush sql-cli < install/database.sql
```

**Option B: Using db-backups directory** (if accessible):

```bash
# If you have access to backup directory
gunzip -k /path/to/db-backups/20260209T143022-main-a1b2c3d4.sql.gz
drush sql-cli < /path/to/db-backups/20260209T143022-main-a1b2c3d4.sql
```

#### Step 4: Restore Files (Separate Module Required)

**This module does NOT back up files.** To restore user uploads and private files, you need:

**Option 1: Using [Restic Backup](https://www.drupal.org/project/restic_backup) module** (recommended):
```bash
# Set restic repository and password
export RESTIC_PASSWORD="your-secure-password"
export RESTIC_REPOSITORY="/mnt/backups/restic-repo"

# Restore files
cd /var/www/html
restic restore latest --target /var/www/html
```

**Option 2: Using Backup & Migrate** or other file backup solution

**Option 3: Manual file restoration** from separate backup location

#### Step 5: Configure and Verify

```bash
# Set file permissions
sudo chown -R www-data:www-data web/sites/default/files
sudo chmod -R 755 web/sites/default/files

# Configure settings.php
cp web/sites/default/default.settings.php web/sites/default/settings.php
nano web/sites/default/settings.php
# Add database credentials, trusted host patterns

# Run updates
drush updatedb -y

# Import configuration
drush config:import -y

# Rebuild caches
drush cache:rebuild

# Verify
drush status
```

**Expected recovery time**: 2-4 hours

---

### Scenario 3: Rollback After Bad Deploy

**Situation**: Deployed new code that breaks the site, need to roll back.

**Recovery Steps**:

```bash
# 1. Identify last known good backup
ls -lh db-backups/ | tail -5
# Example: 20260208T140000-main-7e6f5d4c.sql.gz (yesterday)

# 2. Checkout matching code
git checkout 7e6f5d4c

# 3. Restore database
gunzip -k db-backups/20260208T140000-main-7e6f5d4c.sql.gz
drush sql-drop
drush sql-cli < db-backups/20260208T140000-main-7e6f5d4c.sql

# 4. Reinstall dependencies
composer install

# 5. Clear caches and verify
drush cache:rebuild
drush status
```

**Expected recovery time**: 10-20 minutes

---

### Scenario 4: Development/Staging Environment Setup

**Situation**: New developer needs production-like database for local development.

**Recommended Workflow**:

```bash
# 1. Clone repository
git clone https://github.com/your-org/your-site.git
cd your-site
ddev start

# 2. Import database from install/ folder
ddev import-db --src=install/database.sql.gz
# Or if encrypted:
# gpg --decrypt install/database-latest.sql.gz.gpg | gunzip | ddev import-db

# 3. Install dependencies
ddev composer install

# 4. Run updates
ddev exec drush updatedb

# 5. Clear caches
ddev exec drush cache:rebuild

# 6. Launch site
ddev launch
```

**Files restoration** (if needed):
```bash
# Using Restic Backup module
ddev exec drush restic:restore latest
```

---

## Testing Your Disaster Recovery Plan

**Critical**: Untested DR plans fail during actual disasters. Schedule quarterly drills.

### Quarterly DR Drill Checklist

**Recommended frequency**: Every 3 months

**Goal**: Verify you can recover from database corruption in < 30 minutes

#### Pre-Drill Preparation
- [ ] Designate test server/VM (do NOT use production)
- [ ] Gather git repository access
- [ ] Verify you can access db-backups/ directory
- [ ] Block off 2 hours in team calendar
- [ ] Notify stakeholders this is a drill

#### Drill Steps
- [ ] **Minute 0**: Start timer
- [ ] **Step 1**: Fresh git clone
- [ ] **Step 2**: Identify backup file and commit hash
- [ ] **Step 3**: Checkout matching commit
- [ ] **Step 4**: Restore database from backup
- [ ] **Step 5**: Run composer install
- [ ] **Step 6**: Run updatedb and cache:rebuild
- [ ] **Step 7**: Verify site functions (login, view content)
- [ ] **Minute X**: Stop timer, record duration

#### Success Criteria
- ✅ Site loads and functions correctly
- ✅ Can log in as admin
- ✅ Can view existing content
- ✅ All configuration present
- ✅ Completed in < 30 minutes

#### Post-Drill Actions
- [ ] Document any issues encountered
- [ ] Update documentation with new information
- [ ] Fix broken credentials or outdated steps
- [ ] Schedule next drill (add to calendar now)
- [ ] Share results with team

### Common Drill Failures (Learn From Others)

| Failure | Why It Happens | Prevention |
|---------|----------------|------------|
| **Code/DB mismatch** | Forgot to checkout commit hash | Always use commit hash from backup filename |
| **Missing dependencies** | Skipped `composer install` | Add to checklist, automate with script |
| **Database import fails** | Wrong database credentials in settings.php | Use settings.local.php for local overrides |
| **Site errors after restore** | Skipped `drush updatedb` | Always run updatedb after restore |
| **Permission denied** | File permissions not set | Document exact chmod/chown commands |

---

## Recovery Time Objectives (RTO) and Recovery Point Objectives (RPO)

### Expected Recovery Times

| Scenario | RTO (Time to Recover) | RPO (Data Loss Window) | Complexity |
|----------|-----------------------|------------------------|------------|
| **Database corruption** (code intact) | 15-30 minutes | 0-24 hours | Low |
| **Rollback bad deploy** | 10-20 minutes | 0-24 hours | Low |
| **Complete site loss** | 2-4 hours | 0-24 hours | High |
| **Development setup** | 30-60 minutes | N/A | Low |

### Factors Affecting RTO

**Faster recovery** when you have:
- ✅ Commit hash tagged in backup filename (this module's key feature)
- ✅ Recent DR drill experience
- ✅ install/database.sql.gz readily available in git repo
- ✅ Clear documentation of dependencies
- ✅ Automated scripts for standard tasks

**Slower recovery** when:
- ❌ Don't know which code version matches backup
- ❌ First time performing recovery
- ❌ Missing dependencies or packages
- ❌ Complex external service integrations
- ❌ Database backups not easily accessible

### Improving Your RTO/RPO

**To reduce RTO (faster recovery)**:
1. Practice DR drills quarterly
2. Use install/database.sql.gz in git repo for quick access
3. Document exact composer install commands
4. Create recovery scripts (backup-now.sh, restore.sh)

**To reduce RPO (less data loss)**:
1. Increase backup frequency (configure cron to run hourly if critical)
2. Monitor backup success (alerting on failures)
3. Use multiple backup locations (local + remote)
4. Enable optional install/ folder sync for git-tracked backups

---

## Appendix A: Direct Database Restoration (Without Drush)

When Drush is unavailable, use direct MySQL/MariaDB commands.

### Using mysql Command

```bash
# 1. Decompress backup
gunzip -k db-backups/20260209T143022-main-a1b2c3d4.sql.gz

# 2. Drop existing database (careful!)
mysql -u root -p -e "DROP DATABASE drupal_db;"
mysql -u root -p -e "CREATE DATABASE drupal_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 3. Import backup
mysql -u drupal_user -p drupal_db < db-backups/20260209T143022-main-a1b2c3d4.sql

# 4. Grant permissions (if needed)
mysql -u root -p -e "GRANT ALL PRIVILEGES ON drupal_db.* TO 'drupal_user'@'localhost';"
```

### Using MariaDB Command

```bash
# Same as above, but use mariadb command
mariadb -u root -p -e "DROP DATABASE drupal_db;"
mariadb -u root -p -e "CREATE DATABASE drupal_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mariadb -u drupal_user -p drupal_db < db-backups/20260209T143022-main-a1b2c3d4.sql
```

### PostgreSQL

```bash
# 1. Drop and recreate database
psql -U postgres -c "DROP DATABASE drupal_db;"
psql -U postgres -c "CREATE DATABASE drupal_db OWNER drupal_user;"

# 2. Import backup
gunzip -c db-backups/20260209T143022-main-a1b2c3d4.sql.gz | psql -U drupal_user drupal_db
```

---

## Appendix B: Using install/ Folder for Team Distribution

### What is install/ Folder Sync?

This module can optionally copy the latest backup to `install/` folder with predictable filename:
- `install/database.sql.gz` (unencrypted)
- `install/database.sql.gz.gpg` (encrypted)

This allows:
- ✅ Commit database to git repository (if appropriate for your team)
- ✅ New developers get latest data automatically
- ✅ Predictable filename for automated imports
- ✅ CI/CD pipelines can use it

### When to Enable install/ Folder Sync

**Enable if:**
- ✅ Working with small team
- ✅ Database doesn't contain sensitive production data
- ✅ Using GPG encryption for security
- ✅ Want new developers to have instant database access

**Disable if:**
- ❌ Database contains customer PII or sensitive data
- ❌ Repository is public or has many contributors
- ❌ Compliance requirements prohibit database in git
- ❌ Database is very large (> 100 MB compressed)

### Exporting for Team

```bash
# Create encrypted backup for team
ddev exec drush retention-backup:export

# Output: install/database-latest.sql.gz.gpg

# Then commit it
git add install/database-latest.sql.gz.gpg
git commit -m "Update team database backup"
git push
```

### Importing on New Machine

```bash
# Clone repository
git clone https://github.com/your-org/your-site.git
cd your-site

# Decrypt and import
gpg --decrypt install/database-latest.sql.gz.gpg | gunzip | ddev import-db

# Or if unencrypted
ddev import-db --src=install/database-latest.sql.gz
```

---

## Appendix C: Environment Documentation Checklist

Complete this checklist NOW and store it securely (password manager, printed copy, company wiki):

```markdown
# Site Recovery Information: [SITE NAME]

## Infrastructure
□ Server OS: _____________ (e.g., Ubuntu 22.04 LTS)
□ PHP version: _____________ (e.g., 8.3.2)
□ Web server: _____________ (e.g., Nginx 1.24 / Apache 2.4)
□ Database: _____________ (e.g., MariaDB 10.11 / PostgreSQL 15)
□ Database name: _____________
□ Database user: _____________

## PHP Extensions (required)
□ Enabled: gd, opcache, pdo_mysql, xml, mbstring, curl, zip, ...
□ Disabled/Not needed: _____________

## External Services
□ Redis/Memcache: _____________ (host:port, version)
□ Solr: _____________ (URL, core name, version)
□ Mail service: _____________ (SMTP host, port, credentials location)
□ CDN: _____________ (provider, zone ID)

## Backup Configuration
□ db-backups/ location: _____________ (full path)
□ install/ folder enabled: ☐ Yes ☐ No
□ GPG encryption enabled: ☐ Yes ☐ No
□ GPG recipient email: _____________
□ Backup frequency: _____________ (daily via cron)
□ Retention policy confirmed: ☐ Yes ☐ No

## Git Repository
□ Remote URL: _____________ (GitHub/GitLab URL)
□ Main branch: _____________ (usually 'main' or 'master')
□ Deploy branch: _____________ (production branch)
□ Access method: _____________ (SSH key location or access token)

## Access Credentials (secure location)
□ Server SSH: _____________ (username, key location)
□ Database root password: _____________ (stored in: _____________)
□ Database user password: _____________ (stored in: _____________)
□ GPG passphrase: _____________ (if used, stored in: _____________)
□ Git access token: _____________ (stored in: _____________)

## Critical Paths
□ Web root: _____________ (e.g., /var/www/html)
□ Private files: _____________ (e.g., /var/www/html/private)
□ Public files: _____________ (e.g., /var/www/html/web/sites/default/files)
□ db-backups directory: _____________ (e.g., /var/www/html/db-backups)
□ Composer binary: _____________ (e.g., /usr/local/bin/composer)
□ Drush binary: _____________ (e.g., /usr/local/bin/drush)

## Drupal Configuration
□ Site name: _____________
□ Admin email: _____________
□ Trusted host patterns: _____________
□ Config sync directory: _____________ (e.g., ../config/sync)

## Last Updated
□ Date: _____________
□ By: _____________
□ Verified working: ☐ Yes ☐ No (last DR drill: _________)
```

---

## Additional Resources

### Module Documentation
- README.md: Daily usage and configuration
- This guide: Disaster recovery scenarios

### Complementary Modules
- **Restic Backup**: https://www.drupal.org/project/restic_backup
  - Handles file backups (user uploads, private files)
  - Incremental backups with deduplication
  - Perfect complement for complete site protection

### Drupal Backup Best Practices
- Backup & Migrate: https://www.drupal.org/project/backup_migrate (full-featured alternative)
- Config Management: Use `drush config:export` for configuration backups
- Version Control: Keep all custom code in Git

---

## Support and Contributions

If you encounter issues or have suggestions:
- **Issue Queue**: https://www.drupal.org/project/issues/retention_database_backup
- **Documentation**: This guide and README.md
- **Community**: #backup on Drupal Slack

Remember: **The best disaster recovery plan is the one you've tested.** Schedule your first DR drill today.
