# Changelog

All notable changes to this project are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-11-19

### Added

- Chat widget as a shortcode (`[sse_chat]`), a block (`sse-chat/widget`) and
  an optional floating launcher. Static, cacheable markup; no framework.
- REST routes `sse-chat/v1/session`, `/stream` and `/forget`. Answers stream
  as Server-Sent Events, with a one-body JSON fallback.
- Stateless HMAC session tokens; server-held conversation history.
- Request checks in a fixed order: enabled, origin, method and body,
  session, message, conversation, kill switch, verify filter, concurrency,
  per-IP, per-session and daily limits (IPv6 counted per /64), optional
  daily token cap and optional spend-meter (contract 1).
- Atomic counters in the plugin's own table with an upsert.
- Keyword retrieval through the core search query, over published posts
  only, with citation markers checked again when cited.
- Built-in streaming caller for OpenAI-compatible endpoints on a direct curl
  handle, with a buffered fallback through the WordPress HTTP API; optional
  buffered-json caller through `indianopinion/llm-json-php`; caller filter.
- Conversation log with full, metadata and off modes, a retention setting
  that only marks rows, administrator-only deletion with an audit trail, CSV
  export, personal data exporter and eraser.
- Reader "forget this conversation" control that hides, never deletes.
- Settings screen with status box, test question and REST loopback check;
  log screen; WP-CLI `wp sse-chat`.
- Packed vector index format (`IndexFormat`) with header validation. The
  vector retriever itself is on the roadmap.
