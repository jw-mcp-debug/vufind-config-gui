# Changelog

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
