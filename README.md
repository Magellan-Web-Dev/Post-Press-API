# Post Press API

Fetch JSON records from external APIs — manually or on a schedule — and map them into WordPress posts, custom post types, custom post meta, and Advanced Custom Fields (ACF).

- **Requires:** WordPress 6.0+, PHP 8.1+
- **Optional:** Advanced Custom Fields (free or PRO). Repeater fields require ACF PRO.
- **Version:** 1.0.3

Post Press API makes **outbound** requests only. Receiving webhooks and two-way synchronization are out of scope for this version.

---

## Contents

1. [Installation](#installation)
2. [Quick start](#quick-start)
3. [The preset editor](#the-preset-editor)
4. [Path syntax](#path-syntax)
5. [Field mappings](#field-mappings)
6. [ACF fields](#acf-fields)
7. [Importing images into the Media Library](#importing-images-into-the-media-library)
8. [Example: the sample events payload](#example-the-sample-events-payload)
9. [Duplicates, matching, and updates](#duplicates-matching-and-updates)
10. [Pagination and execution](#pagination-and-execution)
11. [Run history](#run-history)
12. [Exporting and importing presets](#exporting-and-importing-presets)
13. [Scheduling and WP-Cron](#scheduling-and-wp-cron)
14. [Security and data handling](#security-and-data-handling)
15. [Deactivation and uninstall](#deactivation-and-uninstall)
16. [GitHub updates](#github-updates)
17. [Hooks for developers](#hooks-for-developers)
18. [Architecture](#architecture)
19. [Tests](#tests)
20. [Building a release zip](#building-a-release-zip)
21. [Limitations](#limitations)
22. [Changelog](#changelog)

---

## Installation

1. Copy the plugin into `wp-content/plugins/post-press-api/` (the folder name matters for updates).
2. Activate **Post Press API** on the Plugins screen. On PHP older than 8.1 the plugin shows an admin notice and loads nothing else.
3. Open **Post Press API** in the admin menu. Only users with `manage_options` can see it.
4. **Post Press API → About** (also linked from the Plugins screen) explains every feature in the admin and checks this site's setup: PHP and WordPress versions, whether ACF and ACF repeater fields are available, destination post types, preset counts, whether schedules use WP-Cron or a server cron, the site time zone, and whether GitHub updates are configured.

The plugin never registers post types or fields. Create the destination post type and any ACF field groups first (for example with ACF, a theme, or another plugin).

## Quick start

1. **Post Press API → Add New Preset.** Give it a name. New presets start **disabled** and **manual-only**.
2. **Request:** enter the endpoint URL, method, query parameters, headers, and authentication.
3. **Response selection:** set the records path (for example `data`) and the external ID path (for example `id`). Click **Test request** to fetch the first page — or paste a sample response and choose *Use the pasted sample JSON* to work offline. The discovered paths become suggestions in every path field.
4. **Field mappings:** pick the destination post type, then add mappings from source paths to post fields, custom meta, or ACF fields.
5. Click **Preview / dry run** to see, record by record, what would be created, updated, left unchanged, skipped, or rejected — with each source value, its type, and the value that would be written. Nothing is saved.
6. Save. When the editor no longer lists missing settings, tick **Enabled**, save again, and use **Run now** or choose a schedule.

## The preset editor

| Section | What it controls |
|---|---|
| General | Name and enabled state. Disabled presets never run (Test Request and Dry Run still work). |
| 1. Request | URL, GET/POST, query parameters, headers, JSON body (POST), authentication, timeout (1–60 s), maximum response size (16 KB–10 MB), retries (0–5). |
| 2. Response selection | Records path, optional success flag, record identity (single ID path or composite), optional sample JSON, Test Request. |
| 3. Field mappings | Destination post type, status and author for new posts, mapping rows. |
| 4. Duplicate handling | Duplicate policy, connecting existing posts, trashed posts. |
| 5. Pagination | None, offset/limit, or page number; stop conditions and safety limits. |
| 6. Schedule | Manual, every 5/15 minutes, hourly, twice daily, daily, or a custom interval (minimum 5 minutes); records per batch. |
| 7. Preview & run | Dry run, Run now (optionally re-applying values to unchanged posts), cancel, recent runs (which can be deleted here). |

The preset list shows each preset's enabled state, destination, schedule, last run, next run (in the site time zone), and last result. Row actions: Edit, Export JSON, Duplicate (the copy starts disabled), Enable/Disable, History, Delete. The **Import preset JSON** button above the list imports a preset exported from another site (see [Exporting and importing presets](#exporting-and-importing-presets)). The editor header links to **Export JSON** and **Run history** for the open preset.

Validation errors are listed in a summary at the top of the editor (each linked to its field) and next to the field itself. Incomplete presets can be saved as drafts; they cannot be enabled or run until complete.

## Path syntax

Paths are used for the records path, identity, success flag, pagination flags, and mapping sources. The records path resolves against the whole response; every other mapping path resolves **inside each record**.

| Path | Meaning |
|---|---|
| `event.title` | Nested object keys |
| `sessions.0.date` | Array index (numeric segment) |
| `sessions[0].date` | Array index (bracket form) |
| `items[-1]`, `products[-1].name` | The last item of any array, optionally followed by nested keys |
| `groups[-1].members[-1].email` | Last-item selection at multiple nested levels |
| `[-1]`, `$[-1]` | The last item when the current context itself is an array |
| `sessions[*].date` | Wildcard: the `date` of every session, as a list |
| `dates_text[*]` | Every item of a list of scalars |
| `sessions.*.date` | `*` as a whole segment is also a wildcard |
| `$` | The current context: the whole response (records path), the record, or the current repeater item |
| `$.data`, `$[0]` | Explicit root prefix |
| `["key.with.dots"]`, `meta['a[b]']` | Quoted keys for literal `.`, `[`, `]`, a leading `$`, or a key that is exactly `*` (escape `"`, `'`, and `\` with a backslash) |

`[-1]` works with any array key, including in template placeholders and nested ACF mappings. It selects the final item in the supplied order without sorting or skipping null items. An empty list or an object used with `[-1]` is **missing**; a null last item or null parent remains **null**. Quoted keys such as `["-1"]` still address literal object keys. Other negative indices, slices, filters, and expressions are rejected with a clear error.

**Missing vs. present values.** A path either finds nothing (**missing**), finds `null` (**null**), or finds a value. `false`, `0`, `"0"`, `""`, and `[]` are real values and are written as such. A path through a null parent — `location.city` when `location` is `null` — counts as **null**; a path through a missing key or a scalar counts as **missing**. Wildcards collect matched values and leave out items where the rest of the path is missing or null; a wildcard over an empty list is an empty list.

**Records path.** `data` selects the `data` array. Use `$` when the response itself is the array, and `items[*]` to treat the values of an object as records. A records path that is missing, `null`, or not a list fails the run with an explanation.

## Field mappings

Each mapping row has:

| Part | Options |
|---|---|
| Source | **Path** (`event.title`), **Constant** (literal text), or **Template** (`{event.title} – {website.host}`; `{{` and `}}` produce literal braces). If any placeholder is missing the template counts as missing; otherwise, if any is null, it counts as null. |
| Destination | **Post field**, **Custom meta** key, or **ACF field**. |
| Transformation | See below. |
| When missing / when null | **Keep the existing value** (default), **Clear the field**, **Use the fallback value**, or **Mark the record as failed**. Repeater sub fields offer *Leave empty in this row* and *Skip this repeater row* instead of *Keep*. |
| Fallback | A literal value. Fallbacks skip the transformation and are validated by the destination, so write them in the destination's format (for example `20261105` for an ACF date field, `draft` for status). When the image download option is on, the fallback must be an image URL. |
| Download image URL to Media Library | Offered for the Featured image, custom meta, and ACF image/URL/text/textarea fields (including sub fields). See [Importing images into the Media Library](#importing-images-into-the-media-library). |

Each destination can be mapped once per level. Mappings default to **keep the existing value**, so missing or null source data never erases what a post already has.

### Transformations

| Transformation | Result |
|---|---|
| No transformation | The value as received (arrays are only accepted by array-capable destinations). |
| Plain text | Tags stripped; booleans become `true`/`false`. |
| Integer / Decimal number | Numeric conversion; non-numbers and fractional integers are errors. Decimal can round. |
| Boolean | `true/false`, `1/0`, `yes/no`, `on/off`; anything else is an error. |
| Validate URL / Validate email | Must be a valid http(s) URL or email address. |
| Join list into text | Joins a list with a separator (`\n` = new line, `\t` = tab). Null items are skipped; nested lists are errors. |
| JSON encode | Encodes any value as JSON text. |
| Date/time | Parses a date/time with an optional input format and input time zone, and optional output format and output time zone. |
| Value map | Converts listed values (`approved` → `publish`); unlisted values are kept, replaced by the fallback, or rejected. |

No mapping ever evaluates PHP, JavaScript, or expressions. Arrays and objects are never silently turned into the text "Array": the record fails with a message suggesting Join, JSON, or an indexed path.

### Post fields

Only these core fields can be written: **Featured image**, **Title** (plain text), **Content** and **Excerpt** (rich text filtered with `wp_kses_post`, so allowed formatting stays and scripts are removed), **Slug**, **Publish date**, **Status**, and **Author** (user ID, login, or email of a user who can edit the post type). IDs, post type, parent, GUID, and other internal properties are never writable.

**Featured image** accepts either the ID of an existing local image attachment or, with *Download image URL to Media Library* enabled, a remote image URL. *Clear the field* removes the featured image (the attachment itself stays in the Media Library). The `_thumbnail_id` meta key stays blocked; use this field instead.

A record's own status field is **never** used as the post status automatically. Mapping a source value to Status requires a **Value map** whose targets are allowed statuses (`draft`, `pending`, `private`, `publish`), with unlisted values either rejected or replaced by a valid fallback. The preset's *Status for new posts* applies only when a post is created.

### Custom meta

Any meta key made of letters, numbers, `_`, `-`, `.`, or `:` can be mapped, except:

- WordPress control keys (`_edit_lock`, `_thumbnail_id`, `_wp_page_template`, anything starting `_wp_` or `_edit_`, …);
- this plugin's own keys (anything starting `_ppapi_`);
- keys ACF manages for the destination post type (a field's name, its `_name` reference key, and group/repeater sub-field storage keys) — map those as ACF fields instead.

Meta values are stored as text and filtered with `wp_kses_post` (JSON-transformed values are stored exactly). Arrays need a Join or JSON transformation.

### Dates and time zones

Values without an offset are read in the mapping's input time zone, or the site time zone when none is set. ISO 8601 values with an offset (`2026-10-18T20:05:37+00:00`, `…Z`) are converted to the output (or site) time zone. Date-only values such as `2026-11-05` are never shifted between days.

Named zones that PHP cannot resolve, such as **"Eastern"**, and ambiguous abbreviations such as `CST` are rejected — the plugin never guesses. If a feed's times are in US Eastern time, choose the Date/time transformation and set the input time zone to `America/New_York` explicitly. Relative phrases such as `tomorrow` are rejected too.

## ACF fields

When a post type is chosen, the editor lists the ACF field groups whose location rules match it, with each field's label, name, key, type, and group/parent hierarchy. Field keys always come from the site's own field groups; the plugin never invents keys or creates fields.

Values are written with `update_field()` **using field keys**, so ACF records the field reference correctly even on new posts. A `false` return from `update_field()` also means "unchanged", so the stored value is read back and compared instead of being treated as a failure.

| Field types | Support |
|---|---|
| text, textarea, wysiwyg, number, range, email, url, oembed | Converted and validated (`textarea`/`wysiwyg` keep allowed HTML). |
| true_false | Stored as 1/0. |
| select, radio, button_group, checkbox | Values must be choice keys (or exact choice labels) unless the field allows custom values. Multi-select and checkbox take lists. |
| date_picker / date_time_picker / time_picker | Normalized to ACF storage: `Ymd`, `Y-m-d H:i:s`, `H:i:s`. |
| color_picker, link | Hex colors (rgba when opacity is enabled); link from a URL or a `{url, title, target}` object. |
| group | Nested mappings resolve relative to the group's source object (`$` = the current record). Unmapped sub fields are left unchanged. |
| repeater (ACF PRO) | One row per item of the source list; nested mappings resolve relative to each item, so values from the same item always land in the same row. The row list is rebuilt on every write, removing rows that no longer exist; an empty source list empties the repeater. |
| image | Stores an attachment ID: either a local image attachment ID from the source, or a remote image URL downloaded with *Download image URL to Media Library*. Works at the top level and inside groups and repeaters. |
| url, text, textarea (with image download) | With *Download image URL to Media Library*, stores the **local** Media Library URL of the downloaded image instead of the remote one. |
| file, gallery, relationship, post object, page link, taxonomy, user, flexible content, clone | Not supported — shown disabled with the reason. Use an image field for individual images. |

Sub fields are only mappable inside their group or repeater; they are never flattened into top-level meta. Groups and repeaters can nest up to three levels deep.

If ACF is deactivated, or a field or field type a preset needs disappears (for example the repeater type without ACF PRO), runs of **that preset** fail with an explanation. Other presets keep working, and presets that only map core fields and meta never need ACF.

## Importing images into the Media Library

Any mapping whose destination is the **Featured image**, a **custom meta** key, or an **ACF image, URL, text, or textarea** field (top level or inside a group or repeater) has a **Download image URL to Media Library** checkbox. When it is ticked, the mapped value must be an absolute `http(s)` image URL. During a run, the image is downloaded, saved to the Media Library, attached to the destination post, and the field receives a local value:

| Destination | Value written |
|---|---|
| Featured image | The attachment ID (set as the post thumbnail) |
| ACF image field | The attachment ID |
| ACF URL, text, or textarea field | The local Media Library URL |
| Custom meta | Your choice under *Store downloaded image as*: **Attachment ID** or **Local media URL** |

Without the checkbox, the Featured image and ACF image fields accept only the ID of an existing local image attachment; anything else fails the record with an explanation.

**Reuse instead of duplicates.** Each downloaded attachment stores a SHA-256 hash of its complete source URL in the protected `_ppapi_image_source_hash` meta (the URL itself is not stored). The next time any record or preset maps the same URL, the existing attachment is reused as long as its file still exists, so re-runs do not fill the Media Library with copies. Query strings count as part of the URL, so signed or transformed image URLs are treated as distinct images.

**When images are fetched.** Images are downloaded only when duplicate and change detection decide a record needs a write — never for unchanged or skipped records, never in **Test request** or **Preview / dry run** (which show the URL with its query string hidden and note that the image would be downloaded or reused), and never while importing a preset file. Change detection uses the source URL, not the local attachment ID, so the same URL never triggers an update by itself. All image fields of a record are resolved before any of them is written, so a failed download leaves the post's existing images in place. Missing/null rules apply as usual.

**Checks and limits.**

- Public `http(s)` addresses only, fetched with `wp_safe_remote_get()` and TLS verification; URLs containing credentials are rejected. At most three redirects, each re-validated.
- The preset's API credentials, headers, and cookies are **never** sent with image requests.
- At most 10 MB (or the site's upload limit, if lower) and 40 megapixels.
- The file must actually be a JPEG, PNG, GIF, WebP, or AVIF image allowed by WordPress — the detected type is used, not the URL's extension, so extensionless URLs work. Files are named `import-<hash>.<ext>`.
- Each request times out after at most 10 seconds, and all images in one record share a 12-second budget; when it runs out, the record fails and images already saved are reused on the next run.
- A per-URL lock prevents two runs from downloading the same image at once; the losing record fails and is retried on the next run.
- Temporary files are always removed. Error messages never echo the image URL, so signed URLs do not leak into history.

**Permissions.** A preset that downloads images also requires the `upload_files` capability — for the administrator saving or running it, and for the preset's default author when it runs on a schedule.

Replaced or no-longer-used images are **never deleted** from the Media Library; clean them up manually if needed.

## Example: the sample events payload

```json
{
  "ok": true,
  "data": [
    {
      "id": "ada78067-d914-41ae-82ec-9a4f0cb249c9",
      "updated_at": "2026-10-18T20:05:37.463391+00:00",
      "status": "received",
      "submitter": { "name": "Test Submitter", "email": "submitter@example.com" },
      "event": {
        "title": "Medicare Open Enrollment Q&A (Virtual)",
        "description": "Live Zoom session about Medicare enrollment.",
        "virtual": true,
        "virtual_meeting_url": "https://zoom.us/j/98765432100",
        "timezone": "Eastern"
      },
      "location": null,
      "sessions": [{ "date": "2026-11-05", "time": "12:00", "time_12h": "12:00 PM" }],
      "dates_text": ["Nov 5, 2026 at 12:00 PM Eastern"],
      "image": null
    }
  ],
  "pagination": { "total": 1, "limit": 100, "offset": 0, "has_more": false }
}
```

A working configuration (field names are examples — choose your site's own fields; their keys appear in the editor):

| Setting | Value |
|---|---|
| Records path | `data` |
| Success flag | `ok` must equal `true` |
| External ID path | `id` |
| Destination | your existing events post type |
| Pagination (optional) | Offset/limit with `offset`/`limit` parameters, has-more path `pagination.has_more` |

| Source | Transformation | Destination |
|---|---|---|
| `event.title` | — | Post field: Title |
| `event.description` | — | Post field: Content |
| `event.virtual` | — | ACF true/false field |
| `event.virtual_meeting_url` | — | ACF URL field |
| `submitter.email` | — | ACF email field |
| `sessions[0].date` | — | ACF date picker (stored as `20261105`) |
| `sessions` | — | ACF repeater, with sub mappings `date` → date sub field and `time` → time sub field (missing/null: *Leave empty in this row*) |
| `dates_text[*]` | Join list into text, separator `\n` | ACF textarea/text field or a meta key |
| `location.city` | — | ACF text field (null today → existing value kept) |

Notes for this payload:

- `"status": "received"` is not a WordPress status. Leave it unmapped (new posts use *Status for new posts*), or map it with a Value map such as `approved → publish` and a `draft` fallback.
- `"timezone": "Eastern"` is not interpreted. If you need time-zone conversion for `sessions[*].time`, use the Date/time transformation with the input time zone set to `America/New_York`.
- `updated_at`, `submitted_at`, and other unmapped fields never cause updates.

## Duplicates, matching, and updates

**Identity.** A record's identity is *preset ID + destination post type + external ID*, stored on the post as `_ppapi_key` (a hash) and `_ppapi_external_id` (the ID as text, exactly as received; long numeric IDs keep every digit). Titles are never used to detect duplicates. Records whose ID is missing, null, empty, a boolean, or a list are reported as failed and **not** imported.

**Composite identity.** For feeds without stable IDs, choose up to five source paths whose combined values identify a record. If any of those values changes in the API, the record gets a new identity and is imported as a new post.

**Policies.**

| Policy | New records | Records that already have a post |
|---|---|---|
| Update changed records (default) | Created | Updated when their mapped values changed; otherwise left unchanged |
| Skip existing records | Created | Left alone |
| Always create | Created | Created again — duplicates are intended |

**Change detection.** Each import stores a fingerprint of the normalized values the mappings write, plus the mapping configuration. A record is updated only when that fingerprint differs from the one stored at its last successful import, so unmapped fields (such as `updated_at`) never trigger updates, and editing a mapping re-applies it on the next run. *Run now* can optionally re-apply mapped values to every matched post.

**Local edits.** Updates change only mapped fields; other content, meta, and fields are never touched. A local edit to a *mapped* field survives until that record's source data (or the mapping) changes — then all of the record's mapped fields are written again.

**Connecting existing posts.** Optionally match posts that were not created by this preset by a meta key or a top-level ACF text/number/email/URL field that holds the external ID. This lookup searches every post of the destination type (not only this preset's); posts already linked to a different preset are never taken over. A matched post is linked to the preset (its identity meta is added) and updated according to the policy.

**Ambiguity.** If several posts carry the same identity (for example after a "duplicate post" plugin copied its meta) or several posts match the external ID field, the record is reported as failed instead of choosing one.

**Statuses and trash.** Matching includes drafts, pending, private, scheduled, and trashed posts, so a record never gets a second post because the first is not published. Trashed matches are skipped by default (and never re-created); optionally they can be restored — as drafts — and updated.

**Partial failures.** New posts are created as drafts together with their identity and a `pending` state. Field writes follow; every write is verified. Only after every write (and the final status) succeeds are the fingerprint and `complete` state recorded. If anything fails, the next run finds the same post through its identity and completes it — even if the source did not change — so a retry never creates a second post and a half-written post is never published.

**Nothing is deleted.** Records that disappear from the API leave their posts in place, and deleting a preset keeps every post it imported.

## Pagination and execution

**Pagination modes:** none; offset/limit (`offset` and `limit` parameters, initial offset, page size); or page number (`page` parameter, optional page-size parameter). Parameters go in the query string, or in the JSON body for POST requests.

A run keeps requesting pages while:

- the **has-more** path (for example `pagination.has_more`) is true — a missing or non-boolean flag stops the run with an error; or, without one,
- the running count is below the **total** path, if set; or, without either,
- pages come back full (records = page size).

An empty page ends the run (as *stopped early* if the API claimed more records). A page identical to an earlier one stops the run with an error — usually a sign the API ignores the configured parameter names. Reaching *maximum pages* or *maximum records* while more records remain marks the run **Stopped early (more records remain)**, never complete.

**Batches.** Runs execute in short steps: each step processes up to *records per batch* buffered records, or fetches one page, within a time budget, saving progress after every record. The admin screen drives steps while it is open; a single `ppapi_continue_run` cron event always resumes an unfinished run in the background. Manual and scheduled runs use the same pipeline.

**Snapshots.** A run uses a snapshot of the preset taken when it starts. Edits made during a run apply to the next run. Disabling or deleting a preset cancels its run; *Cancel run* stops a run after its current record.

**Locking.** Each preset has an atomic lock (an `INSERT IGNORE` row in the options table, the technique WordPress uses for its upgrader lock). Only one step of a preset runs at a time, a scheduled run is skipped while a previous run is unfinished, and a lock left by a crashed process expires after five minutes and is taken over safely. A run with no progress for 30 minutes can be replaced by a new one.

**Errors and retries.** Network errors, timeouts, and HTTP 408, 425, 429, and 5xx responses are retried up to the preset's retry count, waiting 10 s, 20 s, 40 s… (at most 5 minutes) or the server's `Retry-After` (at most an hour). Waits are saved as run state, never slept. Other HTTP errors, malformed JSON, empty bodies, responses at the size limit (rejected rather than parsed as if complete), a failed success flag, and unexpected response shapes fail the run with an explanation. Record-level problems fail only that record.

**Run results.** Each run ends as *Completed*, *Completed with errors* (some records failed), *Stopped early*, *Failed*, or *Cancelled*, with counts of created, updated, unchanged, skipped, and failed records and up to 50 failed records listed. The last 25 runs per preset are kept (see [Run history](#run-history)).

## Run history

Each preset keeps a summary of its last 25 runs, shown on its **History** screen (row action, or *Run history* in the editor header) and as the five most recent in the editor's **Recent runs** table. Both tables let you delete retained entries:

- **Delete** on a row removes that run.
- Tick runs (or use the header checkbox to select every *displayed* row) and click **Delete selected**.
- **Clear all history for this preset** removes every retained run, including runs not shown in the table.

Each deletion asks for confirmation and updates the table in place. Deleting history removes **only the summaries**: imported posts, downloaded media, the activity log, and any run that is still active are kept. History is updated with a compare-and-swap write, so a run that finishes while you clear history is not lost, and a run's summary is never recorded twice. If another request changes history at the same moment and the update cannot be completed, you are asked to try again.

## Exporting and importing presets

Presets can be moved between WordPress sites as portable JSON files.

**Export.** Choose **Export JSON** from the preset list's row actions or the editor header. The export screen downloads `post-press-api-<preset-name>.json` containing the **saved** preset (save pending edits first): name, request settings, response selection, destination, mappings (including image download options), duplicate rules, pagination, schedule, and batch size. Optionally tick **Include saved sample JSON**.

The file never contains stored credentials (bearer tokens or API keys), the preset ID, its enabled state, run state, history, posts, or media files. The default author is stored as a user login rather than a numeric ID, and every ACF mapping carries a description of its field (name, type, and parent group/repeater path) so the receiving site can match its own field keys. **Values typed directly into URLs, headers, request bodies, mappings, or the sample are included** — review the file before sharing it if they contain anything sensitive.

**Import.** Click **Import preset JSON** above the preset list and upload the file (at most 2 MB). The file is validated strictly: it must be a `post-press-api-preset` file in a supported format version, and any setting that is unknown, of the wrong type, out of range, or would be truncated or changed on import is rejected with its location rather than silently altered. The review screen then lets you choose:

- the **local destination post type** (preselected when the source type exists here);
- the **local author** (matched by the exported login; otherwise your account is selected with a warning);
- a **local ACF field** for each ACF mapping, including sub fields and an ACF duplicate-matching field. Fields are matched automatically by key, or by a unique field with the same name, type, and parent path. Fields with no unique compatible match must be chosen by hand. After changing the post type or a parent group/repeater, click **Refresh matches** to see its local sub fields.

Warnings point out values that are specific to the source site, such as author mappings and local attachment IDs used without image download. Expand **Review all imported settings** to inspect the full configuration. **Create disabled preset** saves it once nothing is left to resolve.

The imported preset always gets a **new ID** and starts **disabled**. Credentials must be re-entered, and its schedule only takes effect once you enable it. Importing never fetches data or downloads images. Posts imported by the original preset on another site are not migrated, and because a record's identity includes the preset ID, existing posts on this site are not linked automatically — use *Connect existing posts* if the new preset should take over posts that are already here. The destination post type and ACF field groups must already exist on this site.

## Scheduling and WP-Cron

Each enabled, scheduled preset has exactly one recurring `ppapi_scheduled_run` event whose argument is the preset ID. Saving a preset keeps its event when the schedule is unchanged and replaces it otherwise (collapsing any duplicates). Disabling or deleting a preset removes its scheduled and continuation events. Deactivating the plugin removes every plugin event; reactivating it restores events for enabled presets. Times are shown in the site time zone.

WP-Cron only runs when the site receives visits, so scheduled times are approximate. For dependable timing, disable WP-Cron's visit trigger and call it from the server:

```php
// wp-config.php
define('DISABLE_WP_CRON', true);
```

```cron
*/5 * * * * curl -fsS "https://example.com/wp-cron.php?doing_wp_cron" >/dev/null
# or, with WP-CLI:
*/5 * * * * cd /path/to/site && wp cron event run --due-now >/dev/null
```

## Security and data handling

- **Capabilities.** Every screen, form action, and REST route requires `manage_options`. Saving or running a preset also requires the destination post type's create/edit capabilities (plus `publish_posts` when the preset can publish, `edit_others_posts` when new posts belong to someone else, and `upload_files` when a mapping downloads images). Scheduled runs re-check that the default author can still edit (and if needed publish, or upload files to) the post type.
- **Nonces.** Form submissions and row actions use per-action nonces; REST routes (`ppapi/v1`) use WordPress REST cookie authentication with explicit permission callbacks. Preset exports require a POST with a per-preset nonce, imports a nonce on every upload, review, and save step, and history deletion a per-preset history nonce in addition to the REST nonce.
- **Image downloads** follow the same public-address rules as API requests, never carry the preset's credentials, headers, or cookies, and are checked for size, pixel count, and real image type before WordPress stores them. Attachments store only a hash of the source URL; previews and messages hide image URL query strings. See [Importing images into the Media Library](#importing-images-into-the-media-library).
- **Preset files.** Exports exclude stored credentials; imported files are size-limited and validated strictly, re-validated on every review step, and always create a new, disabled preset.
- **Outbound requests** are made server-side with `wp_safe_remote_request()`, which refuses private and local addresses. TLS verification stays on. Redirects are followed by the plugin (at most three); when a credential is configured, a redirect to a different scheme, host, or port is refused, so credentials are never forwarded to another host.
- **Credentials** (bearer tokens and API keys) are stored server-side in a separate, non-autoloaded option per preset, never in the preset itself. They are never sent to the browser: the editor shows only whether one is saved, a blank field keeps it, and removing it is explicit. A credential remembers the endpoint origin it was entered for; changing the endpoint host requires re-entering it. Credentials require `https://` endpoints (filterable). WordPress has no built-in secret encryption, so credentials are stored as entered — protect database access and backups, and prefer narrowly scoped keys.
- **Redaction.** Previews, run history, and the activity log never contain credentials — even if an API echoes one back, it is masked. Logs contain identifiers and short messages, not payloads; URLs are logged without query strings.
- **Output.** Values from APIs are escaped on output; the admin script inserts all such values as text. Post content and excerpts keep formatting allowed by `wp_kses_post`.
- **Retention.** Run history keeps 25 runs per preset (individual, selected, or all entries can be deleted at any time); the activity log keeps 200 entries; previews return at most 20 KB of formatted JSON. Sample JSON pasted into a preset (max 256 KB) is stored with that preset — do not paste credentials or data you do not want stored.
- **Storage.** Presets, credentials, samples, run state, history, and the log are non-autoloaded options; only the small preset index (`ppapi_presets`, used for cron schedules) is autoloaded.

## Deactivation and uninstall

- **Deactivation** cancels unfinished runs and removes the plugin's cron events. Presets, credentials, history, every imported post, and every downloaded image are kept; reactivating restores schedules.
- **Uninstall** (deleting the plugin from the Plugins screen) removes all plugin options (`ppapi_*`: presets, credentials, samples, run state, history, locks, log), the update-check cache, and cron events. **Imported posts and downloaded Media Library images are never deleted**, and their `_ppapi_*` tracking meta (including `_ppapi_image_source_hash` on attachments) is kept so a reinstall reconnects to them and reuses the images. To remove that tracking meta manually: `DELETE FROM wp_postmeta WHERE meta_key LIKE '\_ppapi\_%';` (adjust the table prefix). After that, a reinstalled plugin downloads images again instead of reusing them.

## GitHub updates

Updates come from GitHub releases. **The repository is configured in exactly one place:**

```
src/Updates/GitHubUpdater.php, line 52:
    private const REPOSITORY = '';
```

Enter it as `'owner/repo'`. While it is empty (or malformed) the updater registers no hooks: it makes no GitHub requests, adds no "Check for updates" link, and shows no update notices.

Once configured, the updater adds the latest release to WordPress's update checks (cached for 12 hours), supplies the "View details" information, and adds a nonce- and `update_plugins`-protected **Check for updates** link. Tags such as `v1.2.0` are compared with the installed version, and the tag's ZIP is the package. GitHub archives extract into a version-stamped folder, so after installation the folder is moved to `post-press-api/`. If a `post-press-api/` folder unexpectedly still exists, it is first moved aside to `wp-content/upgrade/`; if that or the final move fails, the existing installation is kept (or restored) and the update is reported as failed — no folder is ever deleted to make room, and nothing outside this plugin's folders is touched.

## Hooks for developers

| Hook | Type | Purpose |
|---|---|---|
| `ppapi_destination_post_types` | filter | Adjust the post types offered as destinations (`name => label`). |
| `ppapi_allowed_post_statuses` | filter | Statuses imports may assign (default `draft`, `pending`, `private`, `publish`). |
| `ppapi_blocked_meta_keys` | filter | Additional meta keys mappings may never write. |
| `ppapi_allow_insecure_credentials` | filter | Return `true` to allow credentials over plain `http://` (not recommended). |
| `ppapi_request_args` | filter | Adjust outbound request arguments (redirects, TLS, and size limits are enforced afterwards). |
| `ppapi_record_imported` | action | `( $post_id, $action, $preset_id, $external_id )` after a record is written; `$action` is `created` or `updated`. |
| `ppapi_run_finished` | action | `( $preset_id, $summary )` when a run ends. |
| `ppapi_log` | action | `( $level, $message, $context )` for every (redacted) log entry. |

Cron hooks: `ppapi_scheduled_run` and `ppapi_continue_run` (argument: preset ID). REST namespace: `ppapi/v1` (including `POST /presets/{id}/history/delete`, used by the history tables). Admin-post action: `ppapi_export`.

## Architecture

```
post-press-api.php              Version guard (PHP 7-parseable), constants, autoloader, lifecycle hooks
uninstall.php                   Removes settings; keeps posts
src/Autoloader.php              PSR-4: PostPressAPI\Foo\Bar → src/Foo/Bar.php
src/Plugin.php                  Singleton composition root (plugins_loaded, priority 20)
src/Services.php                Lazily built shared services
src/Lifecycle.php               Activation / deactivation
src/Admin/                      Menu, screens (including preset export/import review), form handling, row actions
src/REST/RestController.php     ppapi/v1 routes used by the editor and history tables
src/Presets/                    Schema, repository, credential store, form parsing, validation, portable transfer
src/Http/                       Request building, fetching, redirects, decoding
src/Mapping/                    Path resolver, discovery, transformations, destinations, mapping engine, image references
src/Media/ImageImporter.php     Bounded, locked image downloads and Media Library reuse
src/Integration/ACF/            Field discovery, value normalization, verified writes
src/Sync/                       Identity, matching, fingerprints, writes, runs, locks, previews
src/Support/                    Permissions, redaction, clock, atomic (compare-and-swap) option updates
src/Scheduling/                 WP-Cron schedules and handlers
src/Logging/Logger.php          Bounded, redacted activity log
src/Updates/GitHubUpdater.php   GitHub release updates
assets/css, assets/js           Scoped admin styles and script (no build step)
tests/                          Dependency-free test suite
```

Plugin-owned names use the `ppapi_` prefix (options, transients, hooks, cron events, handles, nonces), `ppapi-` for CSS classes and IDs, `data-ppapi-` for data attributes, and `_ppapi_` for internal post meta. Post types are discovered on `init` and ACF field groups lazily (after `acf/init`), when an admin screen, REST request, or cron event needs them.

## Tests

The suite runs with plain PHP — no WordPress install, database, Composer, or network:

```sh
php tests/run.php            # everything
php tests/run.php Sync       # files whose name contains "Sync"
```

It uses in-memory WordPress, ACF, and `$wpdb` stubs that reproduce the behaviors that matter here (meta unslashing, `false` returns for unchanged values, values stored as strings, ACF repeater storage, cron duplicate rules, response truncation) and mocked HTTP responses based on the sample payload. Each test file runs in its own PHP process so some files can run without ACF loaded. Any PHP warning or deprecation fails the test that caused it.

Note: the repository's `.gitignore` excludes `/tests/`, so the suite is kept locally and is not part of releases.

## Building a release zip

To produce an installable package for **Plugins → Add New → Upload Plugin**:

```sh
./build.sh
```

This writes `build/post-press-api-<version>.zip` with a single top-level `post-press-api/` folder. Git files, `tests/`, `build/`, `.DS_Store`, and the script itself are left out. The version comes from the plugin header, and the build stops if it does not match `PPAPI_VERSION`, so bump both together. Rebuilding the same version overwrites that zip. `build/` is git-ignored.

## Limitations

- Outbound fetching only; no inbound webhook receiver or two-way sync.
- Pagination supports offset/limit and page numbers; cursor and Link-header pagination are not supported.
- Image imports handle one image per field (JPEG, PNG, GIF, WebP, AVIF; up to 10 MB / 40 megapixels). ACF file and gallery fields are not supported, and replaced images are never removed from the Media Library.
- ACF relationship-type fields, flexible content, and clone fields are not supported.
- Credentials are stored unencrypted in the database (as with most WordPress plugins), and preset exports never include them.
- Preset files move configuration only; posts, media, and history are not transferred between sites.
- On multisite, schedules are per site; activate the plugin per site rather than network-wide.

## Changelog

### 1.0.3

- **Media Library image imports.** Mappings to the new *Featured image* post field, custom meta, and ACF image/URL/text/textarea fields (including group and repeater sub fields) can download image URLs into the Media Library, reusing an existing attachment for the same URL. ACF image fields are now supported.
- **Run history deletion.** Delete individual runs, selected runs, or all retained history for a preset from the History screen or the editor's Recent runs table. Posts, media, and active runs are kept.
- **Preset export/import.** Download a preset as portable JSON (without credentials) and import it on another site, with review of the local post type, author, and ACF fields. Imported presets start disabled.

### 1.0.2

- Paths can select the last item of an array with `[-1]` (for example `items[-1]` or `groups[-1].members[-1].email`).
