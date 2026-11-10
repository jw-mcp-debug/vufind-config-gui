# MCP search for the second index (Search2)

A reference implementation for the MCP server proposed in
[vufind-org/vufind#4939](https://github.com/vufind-org/vufind/pull/4939):
a capability class that searches VuFind's **second Solr index (Search2)**
instead of the main catalog index.

Status: experimental, like the pull request it builds on. Tested against
commit `7ba7a62` of the pull request branch.

## Why

The pull request ships one search capability, `SearchSolr`, and it always
searches the main index (`Solr`, usually the core `biblio`). Content types in
`ModelContextProtocol.yaml` are only filters within that one search.

Many libraries keep a second index next to the catalog, for example for
journal articles or a repository, and connect it as Search2. For an AI client
those records do not exist: a content type such as `(format:Article)` finds
nothing in the main index, and the model takes the catalog for the whole
collection.

VuFind already has the same split in its REST API: `/api/v1/search` for the
main index and `/api/v1/index2/search` for Search2. This class does the same
for MCP.

## What it does

`SearchSearch2` extends `AbstractSearch` from the pull request and changes
only three things:

| | `SearchSolr` | `SearchSearch2` |
|---|---|---|
| Backend (`getSearchClassId`) | `Solr` | `Search2` |
| Result list route (`getSearchActionRoute`) | `search-results` | `search2-results` |
| Record fields (`$recordFieldConfigFile`) | `SearchApiRecordFields` | `Search2ApiRecordFields` |

Searching, content type filters, record retrieval, error handling and the
`search_results_page` link are inherited unchanged. `Search2.ini` applies as
for the web interface, including `[HiddenFilters]`. The model cannot remove
those, so they are the right place for licence or visibility rules.

## Files

```
module/VuFindApi/src/VuFindApi/Mcp/Capabilities/SearchSearch2.php
module/VuFindApi/tests/unit-tests/src/VuFindTest/Mcp/Capabilities/SearchSearch2Test.php
config/vufind/ModelContextProtocol.search2-example.yaml
```

The paths match the VuFind repository, so the files can be copied onto the
pull request branch as they are.

## Try it on the pull request branch

```bash
git clone --branch mcp-server https://github.com/maccabeelevine/vufind.git
cp -r contrib/mcp-search2/module/. vufind/module/
cd vufind && composer install
vendor/bin/phpunit -c module/VuFind/tests/phpunit.xml \
  module/VuFindApi/tests/unit-tests/src/VuFindTest/Mcp/Capabilities/SearchSearch2Test.php
```

The class also passes VuFind's `phpcs`, `php-cs-fixer` and `phpstan`
configurations. The CI of this repository runs the test and the checks
against the pull request branch.

Then merge the sections of `ModelContextProtocol.search2-example.yaml` into
`local/config/vufind/ModelContextProtocol.yaml`.

## Use it without changing VuFind's code

To keep `module/VuFindApi` untouched, put the class into a local module, for
example as `MyLibrary\Mcp\Capabilities\SearchSearch2` (change the
`namespace` line), and allow that namespace in `ModelContextProtocol.yaml`:

```yaml
CapabilityNamespaces:
  - MyLibrary\Mcp\Capabilities\
```

Without this entry the pull request's `FirewallContainer` refuses to build the
class. The constructor needs no services beyond the core ones, so
`AllowedServices` is not needed.

## Configuration notes

- **Tool descriptions decide which tool the model picks.** Say what is in the
  second index and when to use the catalog tools instead.
- **Content types are shared by all tools.** A Search2 content type listed in
  a `SearchSolr` tool, or the other way round, finds nothing. Use names that
  make the index clear.
- **Use a resource scheme of its own** (for example `article://record/{id}`):
  `getRecord` looks the id up in one backend only.
- **`ResponseFields` are shared too.** Fields such as `cleanDoi` or
  `containerTitle` help with articles but also change the catalog answers.

## In vufind-config-gui

If the instance config names the class (`mcp.search2_class`) and a Search2
Solr core (`solr_search2`), the MCP editor shows which index each tool
searches, offers a button for a Search2 search tool, and checks content type
filters against the index of the tools that use them. See the main README.

## Proposal

A possible follow-up to #4939 after it is merged: add `SearchSearch2`, its
test and a commented example in `config/vufind/ModelContextProtocol.yaml`,
and a short section on the wiki page about searching other indexes.

Not part of the class, but noticed while building it: in the current `dev`
branch, `module/VuFindApi/config/module.config.php` configures the Search2
search API (`vufindapi_search2_search`) with `'backendId' => 'Solr'`, and
`Search2ApiRecordFields` is no longer referenced anywhere. Before the refactor
in [vufind-org/vufind#5785](https://github.com/vufind-org/vufind/pull/5785)
the Search2 API controller used `Search2` and `Search2RecordFormatter`. This
was found by reading the code; it was not checked against a running instance.
