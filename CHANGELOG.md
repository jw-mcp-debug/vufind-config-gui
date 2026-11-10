# Changelog

## Unreleased

- MCP module follows pull request #4939 at commit `7ba7a62`: the removed
  `versionSuffix` setting is no longer offered.
- New `contrib/mcp-search2`: MCP search capability for VuFind's second index
  (Search2), with unit test and example configuration, as a reference for the
  VuFind project. CI runs the test and VuFind's code checks against the pull
  request branch.
- MCP editor: shows which index each tool and resource searches, adds Search2
  tools and resources (including `CapabilityNamespaces`), checks content types
  against the index of the tools that use them and marks content types shared
  across indexes. New optional instance settings `solr_search2` and
  `mcp.search2_class`.
- Tools and resources from `contrib/mcp-search2` are marked as an add-on in the
  MCP editor and the MCP test. The MCP test offers “not set” for optional
  enum parameters such as `contentType`.

## 0.1.0 – 2026-10-07

First public version, extracted from a VuFind test lab.

- Structured editor for `.ini` and `.properties` files that keeps comments and
  formatting, with defaults, help texts, filters and global settings search.
- Raw text editor and diff against the original.
- Ranking editor for `searchspecs.yaml` with a before/after preview against Solr.
- Experimental MCP editor and MCP test for VuFind pull request #4939.
- Configuration file or environment variables instead of fixed paths; several
  instances.
- English and German interface.
- Protection against CSRF, DNS rebinding and clickjacking; strict CSP; optional
  HTTP Basic authentication.
- Parser reads values exactly as PHP does (concatenated segments, escapes,
  inline comments), tells commented-out options from prose, and refuses to
  write ini files PHP cannot read.
- Tests, including checks against all configuration files of VuFind 10.1,
  11.1 and `dev`.
