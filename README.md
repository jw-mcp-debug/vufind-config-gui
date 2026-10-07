# vufind-config-gui

[![Tests](https://github.com/jw-mcp-debug/vufind-config-gui/actions/workflows/tests.yml/badge.svg)](https://github.com/jw-mcp-debug/vufind-config-gui/actions/workflows/tests.yml)

A small web interface for VuFind's local configuration files. It is meant for
librarians and developers who want to understand and tune a
[VuFind®](https://vufind.org) installation without editing ini files by hand.

[Deutsche Fassung](README.de.md)

> **Status: beta.** This is an independent project, not part of VuFind and not
> endorsed by the VuFind community. Test it on a non-production installation
> first. Read [SECURITY.md](SECURITY.md) before running it anywhere but your
> own machine.

## Purpose

VuFind is configured through about a hundred `.ini`, `.yaml` and `.properties`
files with thousands of options. Most of them are documented only in comments
inside the files. In practice it is hard to answer simple questions: Which
options are set at all? What differs from the default? Which setting changes
the order of my search results, and by how much?

vufind-config-gui is meant to make these questions easy to answer:

- **Understand:** every option with its help text, its default and whether it
  is active. A diff shows all local changes, and a global search covers all
  files.
- **Change safely:** VuFind's originals are never touched, every save is backed
  up, and files that PHP could not read are refused.
- **See the effect before saving:** the ranking editor compares the current
  and the edited relevance settings directly in Solr.
- **Try out AI access:** the optional MCP module shows what an AI client
  receives from VuFind's proposed MCP server.

It grew out of a test lab built to learn how VuFind's settings affect search
and display, and to evaluate the MCP server for a library catalogue.

### Who it is for

- system librarians and small teams who run or evaluate VuFind
- workshops and teaching, where you want to show what a setting does
- developers who need to switch settings quickly on a test installation

## Scope

**In scope**

- VuFind's local configuration files in `config/vufind/` (`.ini`, plus raw
  editing of `.yaml`) and `import/` (`.properties`)
- one or a few VuFind installations on the same machine or Docker network
- read-only queries to Solr for previews, field statistics and filter checks
- one administrator at a time, on localhost or in a trusted network

**Out of scope** (by design)

- **No draft or staging mode.** A save is live in VuFind immediately (see
  below). The only exception is the ranking preview, which tests unsaved
  settings against Solr. Use a second instance as a test system.
- **No user management.** There are no roles, no approval workflow and no audit
  log beyond the backup copies.
- **Nothing outside the configuration:** no themes or templates, no language
  files, no Solr schema, no reindexing, no installation or updates of VuFind.
- **No replacement for configuration management.** If you keep your local
  directory in Git or deploy it with Ansible, keep doing so. The GUI only
  edits files and works well alongside version control.
- **Not for the public internet.** See [SECURITY.md](SECURITY.md).

### Changes are live

VuFind looks for every configuration file in its local directory first
(`VUFIND_LOCAL_DIR/config/vufind/…`) and falls back to the original
(`VUFIND_HOME/config/vufind/…`). On the first save the GUI copies the original
into the local directory and changes only your lines there. From then on
VuFind uses that copy. The GUI clears VuFind's caches, so the change is
visible with the next page load. "Remove local copy" returns to the original.

## What it does

- **Structured editing of `.ini` and `.properties` files.** Every option of
  VuFind's original file is listed with its help text. You can switch options
  on and off, change values and reset them to the default. Comments,
  commented-out options and formatting are kept, and only the edited lines
  change.
- **Never touches the originals.** Originals are read from `VUFIND_HOME`, and
  all writes go to `VUFIND_LOCAL_DIR`. A local copy is created on the first
  save. Before every write the previous version is backed up. After the write
  VuFind's configuration caches are cleared. If a file changed outside the GUI
  in the meantime, saving is refused.
- **Validation before saving.** An ini file that PHP could not read (and
  VuFind therefore could not either) is never written.
- **Raw text editor and diff** against the original for any file, including
  YAML.
- **Global search** across all settings, values and help texts of all
  configuration files (press <kbd>/</kbd>).
- **Ranking editor** for `searchspecs.yaml`: field weights per search type,
  dismax parameters, phrase search settings and `GlobalExtraParams`. The
  preview sends the same query VuFind would send directly to Solr, once with
  the saved settings and once with your draft, and shows how each hit moves.
  Only search types that differ from the original are saved, because VuFind
  inherits the rest.
- **Several VuFind instances** in one GUI (for example test and production).
- **English and German**, switchable in the header. More languages are welcome.
- **Optional, experimental: MCP editor and MCP test** for the Model Context
  Protocol server proposed in
  [vufind-org/vufind#4939](https://github.com/vufind-org/vufind/pull/4939)
  (not merged yet). See [MCP module](#mcp-module-experimental).

## Requirements

- PHP 8.1 or newer, with `json`. `curl` is needed for the MCP test,
  `mbstring` is recommended.
- A VuFind installation. Tested with 10.1.1, 11.1.0 and the current `dev`
  branch. Symfony YAML is taken from VuFind's own `vendor/` directory.
- The `diff` program for the diff view.
- Write access to VuFind's local directory (`VUFIND_LOCAL_DIR`).
- Optional: network access from the GUI to Solr, for the ranking preview,
  field statistics and filter checks.

## Quick start

```bash
git clone https://github.com/jw-mcp-debug/vufind-config-gui.git
cd vufind-config-gui
cp config/config.example.php config/config.php   # set home, local, url, solr
php -S 127.0.0.1:8181 -t public
```

Open <http://127.0.0.1:8181/>. Without `config/config.php` the GUI uses VuFind's
environment variables (`VUFIND_HOME`, `VUFIND_LOCAL_DIR`, plus `VUFIND_URL` and
`VUFIND_SOLR_URL`).

With Docker, next to an existing installation, see
[docker/docker-compose.example.yml](docker/docker-compose.example.yml).

PHP's built-in web server is sufficient for one administrator. To run the GUI
under Apache or nginx instead, use `public/` as document root and keep `src/`,
`config/` and `lang/` outside of it. Do not make it reachable from the
internet without additional protection (see [SECURITY.md](SECURITY.md)).

## Configuration

All options are documented in
[config/config.example.php](config/config.example.php):

| Option | Meaning |
|---|---|
| `language` | `auto` (browser), `en` or `de` |
| `allowed_hosts` | host names the GUI answers to (protection against DNS rebinding) |
| `auth` | optional HTTP Basic user and `password_hash()` |
| `instances.<key>.home` | `VUFIND_HOME`, originals (may be mounted read-only) |
| `instances.<key>.local` | `VUFIND_LOCAL_DIR`, where the GUI writes |
| `instances.<key>.url` | public VuFind URL, for test searches and record links |
| `instances.<key>.solr` | Solr core URL as seen from the GUI |
| `instances.<key>.backup_dir` | default `<local>/gui-backups` |
| `instances.<key>.protected_files` | local files the GUI must not delete |
| `instances.<key>.mcp` | enables the experimental MCP module |

The configuration file can live anywhere if you set
`VUFIND_CONFIG_GUI_CONFIG=/path/to/config.php`.

## How saving works

1. The GUI sends only the changed lines (line number, key, value, on/off).
2. The server checks that each line still holds the expected key. Otherwise
   it answers "please reload" (HTTP 409).
3. An unchanged value keeps its exact spelling and quotes. A changed value
   keeps its quote style and trailing comment.
4. The result is parsed with `parse_ini_string()`. If PHP cannot read it,
   nothing is written.
5. The current local file is copied to the backup directory. Then the file is
   written in place, so owner and permissions are kept, and VuFind's caches
   for configs, search specs, YAML and objects are emptied.

The effect is immediate for most files. Index mappings (`marc*.properties`)
only take effect after reindexing, and the GUI says so.

## MCP module (experimental)

If an instance has an `mcp` entry, two more views appear:

- **MCP editor** for `ModelContextProtocol.yaml`: server on/off, tools and
  resources, parameter descriptions, content types with a Solr filter check,
  and response fields. Disabled tools are parked under a key VuFind ignores
  (`GuiDisabled`), so they can be switched on again. YAML comments are lost
  when saving through the editor; the commented original stays in
  `config/vufind/`.
- **MCP test**: talks to the server like an AI client (`initialize`,
  `tools/list`, `tools/call`, `resources/read`) and shows hits, the size of
  the raw answer and the JSON-RPC trace.

This module follows the state of pull request #4939 and will need updates as
the pull request changes. The MCP SDK only accepts `localhost` hosts, so the
GUI sends the host of `public_url` as `Host` header.

## Tests

```bash
composer install
vendor/bin/phpunit --testsuite unit
# Parser checks against all config files of a real VuFind installation:
VUFIND_HOME=/usr/local/vufind vendor/bin/phpunit --testsuite integration
```

The integration suite checks every `.ini` and `.properties` file of the
installation:

- active values must match `parse_ini_string()`;
- a save without changes must leave every file byte-identical;
- switching any single option on or off must change only that line and keep
  the file readable.

CI runs it against VuFind 10.1.1, 11.1.0 and `dev`.

## Known limitations

- YAML files other than `searchspecs.yaml` and `ModelContextProtocol.yaml`
  can only be edited as raw text.
- The ranking preview reproduces dismax search types, phrase settings and
  `GlobalExtraParams`. QueryFields (Lucene "munge" rules) are not reproduced.
- Values that use PHP constants or `${...}` interpolation are shown as
  written, not resolved.
- Saving through the ranking or MCP editor drops comments in the local YAML
  file (with a warning and a backup).
- Help texts come from the comments in VuFind's original files and are
  therefore in English.

## Contributing

Issues and pull requests are welcome. For a new language, copy `lang/en.json`
to `lang/<code>.json`, translate it and add the code to `I18n::LANGUAGES`. The
test suite checks that all language files have the same keys and placeholders.

## License

GNU General Public License, version 2 only (GPL-2.0-only), like VuFind. See [LICENSE](LICENSE).

VuFind® is a registered trademark of Villanova University. This project is not
affiliated with Villanova University or the VuFind project.
