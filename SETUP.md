# Setup Guide: Running the Project on a New Machine

This guide explains what to download and install after you **clone** the repository on another machine, and how to run the website.
Commands are written for **Windows** (PowerShell or Git Bash). Notes for Linux/macOS are at the end.

---

## Quick Overview

| Step | What to do | Approx. time |
|---|---|---|
| 1 | Install Git, PHP, and Composer | 15 min (once per machine) |
| 2 | Clone the repository | 1 min |
| 3 | Install libraries with `composer install` | 1 min |
| 4 | (Optional) Copy the `data` folder to keep existing work | Depends on file size |
| 5 | Run the website | Seconds |
| 6 | Create the admin account | 1 min |
| 7 | Import the matching workbook and article files | 2–3 min |

---

## 1. Required Software (install once per machine)

### a) Git
- Download from https://git-scm.com/download/win and install with the default options.
- Verify:
  ```bash
  git --version
  ```

### b) PHP 8.1 or newer
1. Download the **Thread Safe x64 (Zip)** build from https://windows.php.net/download
2. Extract it to a permanent folder, e.g. `C:\php`
3. Add `C:\php` to your **PATH**:
   Start menu → search "Edit the system environment variables" → Environment Variables → select `Path` → New → type `C:\php` → OK.
4. Inside `C:\php`, copy `php.ini-development` and rename the copy to `php.ini`.
5. Open `php.ini` and enable these extensions by removing the leading semicolon `;`:
   ```ini
   extension_dir = "ext"
   extension=curl
   extension=fileinfo
   extension=gd
   extension=mbstring
   extension=openssl
   extension=zip
   ```
   > The `xml`, `dom`, `xmlreader`, `xmlwriter`, and `iconv` extensions are built into PHP on Windows and need no action.
6. (Recommended) In the same file, raise the limits for large file uploads:
   ```ini
   memory_limit = 2048M
   upload_max_filesize = 512M
   post_max_size = 520M
   max_execution_time = 600
   ```
7. Open a **new** terminal window and verify:
   ```bash
   php -v
   php -m
   ```
   The `php -m` list must include: `zip`, `gd`, `mbstring`, `fileinfo`, `xml`, `dom`, `xmlreader`, `xmlwriter`, `iconv`.

### c) Composer (PHP package manager)
- Download and run `Composer-Setup.exe` from https://getcomposer.org/download/
- When asked for PHP, choose `C:\php\php.exe`.
- Verify:
  ```bash
  composer --version
  ```

---

## 2. Clone the Repository

```bash
git clone https://github.com/nouhanicst2027-sudo/tadqeeq.git
cd tadqeeq
```

---

## 3. Install Libraries (the `vendor` folder)

The `vendor` folder is **not** on GitHub, so you must install it:

```bash
composer install
```

This creates the `vendor` folder containing the PhpSpreadsheet library.
If you skip this step, the website shows an error saying PhpSpreadsheet is not installed.

---

## 4. The `data` Folder (optional, copy manually)

All source files are already in the repository, in the project root:

| File | Contents |
|---|---|
| `Bylaws_Diwan_Qistas_Matching.xlsx` | The main matching workbook |
| `reg_articles.zip` | **Diwan** articles (~24 MB zipped, ~350 MB extracted) |
| `Bylaws_16.zip` | **Qistas** articles (~14 MB) |

The only thing **not** on GitHub is the contents of the `data` folder: user accounts, review results, backups, and the generated article index. It is excluded because it contains password hashes and one file larger than GitHub's 100 MB limit. If you want to keep your existing work, transfer it from the old machine (USB drive, Google Drive, etc.).

**Choose one path:**
- **Keep your current work:** copy the `data` folder from the old machine over the project's `data` folder, then go straight to **Step 5** (skip Steps 6 and 7).
- **Start fresh:** complete Steps 5, 6, and 7.

> ⚠️ The `data` folder contains `users.json` (hashed passwords) and all review results. Never upload it to GitHub.

---

## 5. Run the Website

From inside the project folder:

```bash
php -d memory_limit=2048M -d upload_max_filesize=512M -d post_max_size=520M -S localhost:8080 -t public
```

Then open your browser at: **http://localhost:8080**

- Keep the terminal window open while using the site. Press `Ctrl + C` to stop it.
- To make the site reachable from other devices on the same network, replace `localhost` with `0.0.0.0`, then open `http://<your-machine-IP>:8080` from the other device (you may need to allow port 8080 through the firewall).

---

## 6. Create the Admin Account (first time only)

The first time you open the site, a **first-time setup** page appears. Enter a display name, a username (Latin letters), and a password (at least 8 characters).

Or from the command line:

```bash
php tools/create-admin.php admin "Admin Name" "a-strong-password"
```

---

## 7. Import the Data

Run these commands from inside the project folder (recommended for large files):

```bash
php tools/import-workbook.php Bylaws_Diwan_Qistas_Matching.xlsx
php tools/import-articles.php diwan  reg_articles.zip
php tools/import-articles.php qistas Bylaws_16.zip
```

- Importing the Diwan articles takes about 30–60 seconds.
- All three files are already in the project root after cloning; no need to download them separately.

**Alternative via the browser:** log in as admin → open the **system administration** page → **file management** section, and upload the files there.
Or put the files in `data/incoming/` and use the "import large file from data/incoming" option on the same page.

After importing, add volunteer accounts from the **volunteer management** section of the administration page.

---

## 8. Daily Use After Setup

Each time you want to use the site:

```bash
cd tadqeeq
php -d memory_limit=2048M -S localhost:8080 -t public
```

Then open http://localhost:8080

To get the latest code from GitHub:

```bash
git pull
composer install
```

---

## 9. Troubleshooting

| Problem | Fix |
|---|---|
| `php` or `composer` is not recognized | Add `C:\php` to PATH and open a new terminal window |
| Site says PhpSpreadsheet is not installed | Run `composer install` inside the project folder |
| `composer install` asks for a missing extension (zip, gd, …) | Enable it in `C:\php\php.ini` (Step 1-b) |
| `Allowed memory size exhausted` error | Run the site with the full command from Step 5 (`memory_limit=2048M`) |
| Large file upload fails in the browser | Use the import commands in Step 7 instead |
| Port 8080 is already in use | Use another port, e.g. `-S localhost:8090`, and open http://localhost:8090 |
| Site says the file is currently in use | Wait a moment and retry; another save is in progress |

---

## Linux / macOS Notes

- **Ubuntu/Debian:**
  ```bash
  sudo apt install git php-cli php-zip php-xml php-gd php-mbstring php-curl unzip composer
  ```
- **macOS (Homebrew):**
  ```bash
  brew install git php composer
  ```
- Steps 2 to 8 are exactly the same.
- On a real web server (Apache/Nginx): set the **Document Root** to the `public` folder only, and give the web server write access to `data`:
  ```bash
  sudo chown -R www-data:www-data data && sudo chmod -R 775 data
  ```
