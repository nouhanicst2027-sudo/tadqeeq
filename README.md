# Tadqeeq — Jordanian Bylaws Audit System (Diwan · Qistas)

Tadqeeq is a lightweight web application for the **manual audit of Jordanian bylaws** across two sources:

- **Diwan** — the Legislation and Opinion Bureau (ديوان التشريع والرأي)
- **Qistas** — the Qistas legal database (قسطاس)

An automated matching step paired Diwan records with Qistas records in advance. Volunteers then use this app to check those pairs by hand, record their decision and evidence, and send each result to an administrator for approval.

The app is built with plain PHP, HTML, CSS and JavaScript, using the PhpSpreadsheet library. **It uses no database.** All data lives in Excel and JSON files inside the `data` folder.

---

## Downloads

| # | Item | Link |
|---|---|---|
| 1 | `data.zip`: the full working data folder | [Google Drive: data.zip](https://drive.google.com/file/d/1nymVBdhKvJ0C8ZmrbNvpREZ2ItkNrACE/view?usp=sharing) |
| 2 | `Jordan_Bylaws_Matched_Diwan_Qistas_v1.0_2026-11-26.json`: the final matched-bylaws file | [Google Drive: matched bylaws JSON](https://drive.google.com/file/d/199gCXoElCLajFXWiD3FWkAst3zvY4NTe/view?usp=drive_link) |

### 1. `data.zip` (~136 MB zipped, ~780 MB extracted)

A complete snapshot of the app's `data` folder, taken on 2026-11-25. Extract it into the project root so that it becomes `data/`. The app then opens with all users, audit work and article indexes already in place, so no import is needed.

| Path | Contents |
|---|---|
| `source/main.xlsx` | The matching workbook (`Bylaws_Diwan_Qistas_Matching.xlsx`) with the sheets *Matched*, *Diwan only* and *Qistas only* |
| `source/diwan_articles.csv` | All Diwan bylaw articles (one row per article, ~350 MB) |
| `source/qistas_articles.json` | All Qistas bylaws with their articles |
| `reviews/reviews.xlsx` | **The audit results**: one row per audited record (decision, reviewer, approved values, notes, approval status, history) |
| `index/diwan/`, `index/qistas/` | Fast lookup index: one small JSON file per bylaw (~7,100 Diwan and ~7,500 Qistas) |
| `index/*_meta.json` | Per-bylaw metadata (dates, status, end date, cancelling law) |
| `cache/` | Pre-built caches of records and reviews (rebuilt automatically if deleted) |
| `backups/` | Automatic backups of reviews, users and earlier matching workbooks |
| `logs/journal.jsonl` | Permanent log of every save, approval and admin action |
| `users.json` | Volunteer and admin accounts (**contains password hashes**) |
| `locks/`, `tmp/`, `incoming/`, `exports/` | Working folders used by the app |

> ⚠️ **Private:** `data.zip` contains `users.json` with password hashes, so it must stay private.
> - Share the Drive link only with the team, never with "Anyone with the link".
> - Never commit the `data` folder to GitHub.

### 2. Matched bylaws JSON (~6 MB)

The **final deliverable** of the audit. It contains **577 bylaws** that are confirmed as the same law in Diwan and Qistas, in one structured file. It covers bylaws **up to and including 1960**, using the year of the original bylaw.

| Included group | Count | Meaning |
|---|---|---|
| `human_review` | 460 | A reviewer decided *confirmed match* and an administrator approved it |
| `auto_high_confidence_100` | 117 | A high-confidence automatic match whose articles are 100% identical in both sources. No reviewer checked it |

**Not included:**
- Partial matches.
- Records decided as *not matched* or *needs investigation*.
- Results still waiting for approval.
- High-confidence matches whose articles are not 100% identical.
- *Diwan only* and *Qistas only* records.
- Anything after 1960.

Each bylaw in the file includes:
- **IDs, name, number and year from both sources**, plus the value the reviewer approved.
- **Publication metadata**: Official Gazette issue, page, gazette date, type, status, effective date, end date, and parent bylaw (for amendments only).
- **`metadata_sources`**: which source each metadata value came from (`reviewer`, `qistas` or `diwan`).
- **Audit details**:
  - the method used to confirm the match
  - for human reviews: the reviewer, the approver and the dates
  - for automatic matches: the matching evidence
  - fields the reviewer marked as wrong in a source
- **Notes**: reviewer and admin notes, and any article differences the reviewer recorded.
- **Articles**: the full article texts (from Qistas, or from Diwan when Qistas has none), plus the article count in each source.

The full field reference is in [Matched bylaws JSON format](#matched-bylaws-json-format) below.

---

## Quick Start

For full step-by-step installation on a new machine (Windows, Linux and macOS), see **[SETUP.md](SETUP.md)**.

```bash
git clone https://github.com/nouhanicst2027-sudo/tadqeeq.git
cd tadqeeq
composer install
# Optional: extract data.zip here to restore all existing work
php -d memory_limit=2048M -d upload_max_filesize=512M -d post_max_size=520M -S localhost:8080 -t public
```

Then open **http://localhost:8080**.

### Requirements

- PHP 8.1 or newer, with these extensions: `zip`, `xml`, `xmlreader`, `xmlwriter`, `dom`, `mbstring`, `gd`, `fileinfo`, `iconv`
- Composer
- About 1 GB of disk space for data
- PHP memory: 512 MB for normal pages, 2 GB for importing the article files

### Starting without `data.zip`

1. Open the site. The **first-time setup** page lets you create the admin account. You can also use the command line:
   ```bash
   php tools/create-admin.php admin "Admin Name" "a-strong-password"
   ```
2. Import the source files from the project root:
   ```bash
   php tools/import-workbook.php Bylaws_Diwan_Qistas_Matching.xlsx
   php tools/import-articles.php diwan  reg_articles.zip
   php tools/import-articles.php qistas Bylaws_16.zip
   ```
3. Add volunteers from **Administration → Volunteer management**.

---

## How the Audit Works

### Record categories and stable IDs

Each record has a stable ID that does not depend on its row number, so audit results stay linked even if the matching workbook is replaced.

| Category | Sheet | ID format |
|---|---|---|
| Matched | المتطابقة | `M-{Diwan pmk_ID}` |
| Diwan only | الديوان فقط | `D-{Diwan pmk_ID}` |
| Qistas only | قسطاس فقط | `Q-{Qistas ID}` |

### Work priorities

| Priority | Group | Approach |
|---|---|---|
| 1 | Matched, confidence *needs review* | Full manual review |
| 2 | Matched, *medium* confidence | Full manual review |
| 3 | Diwan only | Manual search in Qistas |
| 4 | Qistas only | Manual search in Diwan |
| 5 | Matched, high confidence, **article differences** | Full manual review |
| 6 | Matched, high confidence | Sample check |

### Audit scope

The default scope is bylaws **up to and including 1960**. For amending bylaws, the year of the original bylaw is used. An admin can change this in **Administration → Audit settings**.

### Workflow

1. **Volunteer:** opens *Legislation cards*, filters by name, number, year, category, status or priority, and clicks **Start audit**. The record is then reserved for that volunteer.
2. **Review page:** shows the record details and a side-by-side comparison of both sources' metadata. The **article comparison** lets the volunteer:
   - choose articles manually, search, and jump between them
   - see article numbers that exist in only one source
   - see highlighted word differences (a visual aid only)
   - search manually for another record to compare against
3. The volunteer approves the correct value for each field. They can save a **draft** at any time and **submit** when finished.
4. **Administrator:** approves the result, returns it for correction, or sends it for further investigation. They can also release or reassign a reservation. Administrators do not start audits themselves, which keeps auditing and approval separate.

### Final decisions

| Category | Decisions |
|---|---|
| Matched | `confirmed_match` · `partial_match` · `not_matched` · `needs_investigation` |
| Diwan only | `found_in_qistas` · `found_partial` · `missing_in_qistas` · `needs_investigation` |
| Qistas only | `found_in_diwan` · `found_partial` · `missing_in_diwan` · `needs_investigation` |

Notes are required for every decision except a full match. A "missing" decision is accepted only after **all** mandatory search methods are documented with their results.

---

## Matched Bylaws JSON Format

### Generating the file

The file is produced by `export_matched_json.php`. This script is currently kept **one level above the repository**, next to the `tadqeeq` folder, and loads `tadqeeq/src/bootstrap.php`.

```bash
php -d memory_limit=2048M export_matched_json.php Jordan_Bylaws_Matched_Diwan_Qistas_v1.1_<date>.json
```

Before saving, the script checks the following and reports any problems:
- no Diwan or Qistas ID appears twice
- every record is a confirmed match
- every record has articles, and the article count matches
- original bylaws have no `parent`
- no empty strings remain

Never overwrite a published file. Give every new export the next version number.

### Top level

```json
{
  "version": "1.0",
  "generated_at": "2026-11-26T09:23:11",
  "scope": "حتى 1960 (شاملة) — حسب سنة التشريع الأصلي",
  "total": 577,
  "counts_by_method": { "human_review": 460, "auto_high_confidence_100": 117 },
  "rules": { "...": "plain-language description of every rule below" },
  "laws": [ ... ]
}
```

### One bylaw (`laws[]`)

| Key | Type | Description |
|---|---|---|
| `record_id` | string | Stable ID, e.g. `M-506` |
| `ids` | object | `diwan`, `diwan_guid`, `qistas` |
| `qistas_url` | string | Link to the bylaw on Qistas |
| `name` | object | `diwan`, `qistas`, `approved` (the reviewer's choice, or `null`) |
| `number` | object | `diwan`, `qistas`, `approved` (strings) |
| `year` | object | `diwan`, `qistas`, `approved` (integers) |
| `metadata` | object | `magazine`, `page`, `magazine_date`, `type`, `status`, `active_date`, `parent`, `end_date`, `canceled_by` |
| `metadata_sources` | object | Source of each metadata value: `reviewer`, `qistas`, `diwan` or `null` |
| `audit` | object | See below |
| `notes` | object/null | `reviewer`, `admin`, `content_differences[]` (human reviews only) |
| `articles_source` | string | `qistas` or `diwan` |
| `articles_count` | object | Number of articles in `diwan` and in `qistas` |
| `articles` | array | `{ "number": "1", "text": "..." }` |

**`audit` fields:**
- **All records:** `method`, `method_label`, `decision` (always `confirmed_match`).
- **Human reviews:** `reviewer`, `submitted_at`, `approved_by`, `approved_at`, and `marked_wrong`. `marked_wrong` lists fields where the reviewer marked a source's value as wrong. For example, `{"number": ["diwan"]}` means the Diwan number is wrong, even if no replacement value was approved.
- **Automatic matches:** `evidence`, which holds `match_level`, `confidence` and `articles_similarity`.

### Value rules

- **Precedence for metadata:** the reviewer's approved value comes first, then Qistas, then Diwan. **Status** comes from Diwan first.
- **Fields kept from both sources:** IDs, name, number and year are always stored from both sources.
- **`parent`:** filled only for amendments. It is `null` for original bylaws.
- **Value formats:**
  - IDs, numbers, gazette issues and pages are strings (some contain letters)
  - years are integers
  - dates use the format `YYYY-MM-DD`
  - Arabic-Indic digits are converted to `0–9`
  - a missing value is `null`, never `""`

---

## Data Safety and Backups

- Every save follows the same steps:
  1. Take an exclusive file lock.
  2. Make an automatic backup.
  3. Write a temporary file and **check that it can be read back**.
  4. Replace the original file in one atomic step.
- Every review has a version number. If another window changed the review in the meantime, the save is rejected rather than silently overwriting it.
- Backups are kept in `data/backups/`:
  - `reviews/` (the last 300 versions)
  - `source/` (previous matching workbooks)
  - `users/`
- Logs:
  - `data/logs/journal.jsonl`: permanent log of every action
  - `data/logs/app.log`: errors
- To restore a backup, use **Administration → Export & backups → Restore**. The current version is saved before restoring.
- A full backup only needs a copy of the `data` folder.

### Exporting to Excel

Use **Administration → Export & backups → Build & download updated Excel**. The result is the original matching workbook, with all its sheets unchanged, plus an extra **Audit results** sheet.

---

## Project Structure

```
public/            Web root
  index.php        Dashboard
  cards.php        Legislation cards (list and filters)
  review.php       Audit / review page
  admin.php        Administration, files, volunteers, settings
  api.php          Article lookup and search (JSON)
  download.php     Downloads
  login.php · logout.php · setup.php
  assets/          CSS and JavaScript
src/               Application logic (storage, locks, Excel, articles, reviews, export)
tools/             Command-line tools (create admin, import workbook, import articles)
data/              Runtime data (not in Git; see data.zip)
vendor/            PhpSpreadsheet (installed with composer install)
```

On a real web server, set the **document root to `public/` only**. `src`, `data`, `tools` and `vendor` must not be reachable from the web. The root `.htaccess` blocks them on Apache.

---

## Important Notes

- The app **does not run any automatic matching** and does not judge legal equivalence. The "best candidate" shown for a record comes from the matching workbook and is a hint only.
- No result is final until an administrator approves it.
- The matching workbook is never modified. Audit results are stored separately in `data/reviews/reviews.xlsx`.
