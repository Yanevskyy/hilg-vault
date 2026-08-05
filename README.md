# HILG Vault

A WordPress plugin that adds cloud file sharing, folder level permissions, a
network member role, and learning platform embedding.

Built for tender **WCCC26/432** (Waterford City and County Council, on behalf of
Healthy Ireland Local Government) to demonstrate an approach in working code
rather than in description. It is not a past client project.

Live demonstration: **https://hilg-demo.clarityweb.ie**

---

## What it does

### File sharing that does not use the media library

Files are stored in S3 compatible object storage. The browser uploads straight
to the bucket using a presigned URL, so the payload never passes through PHP.
Two consequences matter:

- PHP upload limits stop being the ceiling. Files of many gigabytes work on
  ordinary hosting.
- The web server stops being the bottleneck, which is what makes a library
  beyond one terabyte practical.

Files above 32 MB are split and uploaded in parts through a bounded concurrency
pool, so an interrupted upload resumes by repeating only the missing parts.

Works with AWS S3, Cloudflare R2, MinIO, Wasabi, or any other S3 compatible
endpoint.

### Three access modes

Set per folder, inherited down the tree unless a child overrides it:

| Mode | Behaviour |
|---|---|
| `public` | Anyone |
| `password` | One shared password, not tied to a user account |
| `private` | Signed in users only, filtered by the role matrix |

Two rules hold throughout:

1. **Permission is checked when a file is served**, not only when a list is
   drawn. Every download URL is minted after the same check that produced the
   listing, so guessing a file id gets a 403 rather than a file.
2. **Anything unresolvable fails closed.** A library for a public body should
   never default to open.

### Role to folder matrix

An administrator decides which role can view, upload to, or manage each folder.
Grants inherit down the tree, and the screen shows where an inherited grant
comes from so nobody has to guess why a role already has access.

The plugin registers a `hilg_network_member` role for external participants who
manage their own profile and content.

### Learning platform embedding

Modules and lessons are pulled from an external platform and selected from an
automatically populated list in the editor, then placed on any page with a block
or shortcode.

The provider is configurable rather than hard coded: base URL, paths,
authentication, and a field map for platforms that call a title `name` or
`fullname`. Supporting an unusual platform means adding one class that
implements `LmsProvider`, not revisiting the blocks or the caching.

**When the platform is unreachable**, the last known content is shown with a
plain note saying when it was captured. It does not invent content, and it does
not show an empty block implying the courses were deleted. "The platform is
down" and "the platform has no modules" are different facts, and the code keeps
them distinct.

---

## Requirements

- WordPress 6.5 or newer (developed against 7.0)
- PHP 8.2 or newer
- An S3 compatible bucket

## Installation

Copy the plugin into `wp-content/plugins` (or `app/plugins` on Bedrock) and
activate it. There is no build step and no vendor directory.

Configure storage through environment variables or constants:

```
S3_ENDPOINT=https://your-bucket-endpoint
S3_REGION=eu-west-1
S3_BUCKET=your-bucket
S3_KEY=...
S3_SECRET=...
S3_PATH_STYLE=true      # false for virtual host style (AWS default, R2)
```

Storage settings deliberately live in the environment rather than the database,
so credentials are not carried around in database exports.

If storage is not configured, the plugin says so plainly and refuses to accept
uploads. It never quietly falls back to the media library, because that would
defeat the entire reason it exists.

## Usage

Shortcodes:

```
[hilg_vault folder="12" layout="grid"]
[hilg_lms module="102"]
```

Blocks: **File Vault Folder** and **Learning Module**, both under Widgets.

---

## Design notes

Decisions worth explaining, since they are the reason the code looks the way it
does.

**Dedicated tables, not custom post types.** A CPT gives you an admin screen for
free, then charges for it later: on a library of hundreds of thousands of files
every metadata lookup joins a `postmeta` table with millions of rows. Dedicated
tables with proper indexes keep listing a folder at constant cost.

**Materialised paths.** Each folder stores its ancestor path, so resolving
breadcrumbs or a subtree is one indexed query instead of a recursive walk.
Moves pay a little; reads, which outnumber moves by orders of magnitude, pay
nothing.

**Random, permanent object keys.** The key in the bucket never contains the file
name. That removes path traversal, removes name collisions, and makes renaming a
ten gigabyte file an update to one database row instead of a copy.

**No AWS SDK.** Presigning is a deterministic algorithm, so the whole surface
needed here fits in one auditable file. Under a support contract committing to
weekly updates for eighteen months, every dependency is future work and future
exposure.

**Asset versions follow file modification time.** Behind a page cache and a CDN,
a version string that only changes on release means a CSS fix keeps serving
stale, and "we fixed it" becomes "nothing changed for me".

**No HTML assignment for user supplied values.** Rows are built with
`createElement` and text set with `textContent`. File names here are supplied by
external contributors and read by everyone else, so escaping by hand would hold
only until someone adds a field and forgets.

**Accessibility is structural, not decorative.** The listing is rendered
server side and enhanced by JavaScript, so files remain reachable without
scripting. Navigation moves focus into the refreshed listing, a live region
announces what changed, icons are inline SVG rather than emoji, targets are at
least 44px, contrast is at or above 4.5:1, and motion respects
`prefers-reduced-motion`. Under the Disability Act 2005 section 27 these are
obligations, not polish.

---

## Verified behaviour

Checked against a running instance, not asserted from reading the code:

- 40 MB file uploaded in three parts, reassembled, byte count exact
- download through a signed URL returns the file with the correct name
- iterating file ids as an outsider returns 403 for every private file
- listing a private folder returns 403
- wrong password rejected and rate limited, correct password opens the folder
- shared password survives WordPress logout, as the brief requires
- learning module keeps rendering with a dated notice while the platform is
  stopped, and refreshes itself when it returns
- 375px viewport: no horizontal overflow, no target under 44px

---

## Licence

GPL-2.0-or-later, matching WordPress.

Built by [ClarityWeb](https://clarityweb.ie).
