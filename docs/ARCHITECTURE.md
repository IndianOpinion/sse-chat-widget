# Architecture

This document describes how sse-chat-widget 0.1.0 is built and records
every decision taken while building it from [PRD.md](PRD.md). Case numbers
(for example "case 26") refer to PRD section 4.

## Modules

| Path | Role |
|---|---|
| `sse-chat-widget.php`, `autoload.php` | Plugin header, constants, PSR-4 autoloader for installs without Composer, optional `lib/llm-json-php` loader. |
| `src/Plugin.php` | Hook wiring, activation, deactivation (stops events only), hourly counter pruning, the service factory, capability filter, kill switch setter. |
| `src/Settings.php` | Defaults, display strings, validation of every field (section 7), key resolution (case 76), kill switch resolution (case 78). |
| `src/Http/` | `RestController` (the three routes), `IncomingRequest` (framework-free request), `OriginCheck` (case 12), `ClientIp` (case 27), `Refusal` (status, code, message, Retry-After). |
| `src/Session/` | `SessionTokens` (stateless HMAC tokens, case 3), `Keys` (HMAC keys from the salts; yearly IP hash key). |
| `src/Limits/` | `CounterStore` and `WpdbCounterStore` (one table, atomic upsert), `Limiter` (slots, rolling windows, daily caps), `Reservation` (give back on later refusal), `Stats` (daily counters, case 79). |
| `src/State/` | `WorkingState` and `CacheWorkingState`: history in the object cache when persistent, else a transient (case 29). |
| `src/Retrieval/` | `Retriever` interface, `KeywordRetriever`, `PostGate` (case 34), `PassageBuilder` (case 38), `QueryBuilder` (case 33), `RetrievalRunner` (fallbacks, dedup, numbering). |
| `src/Model/` | `ModelCaller` interface, `StreamingCaller`, `Transport` (`CurlTransport`, `WpHttpTransport`), `SseLineParser`, `ErrorMapper` (case 54), `Replay`, `BufferedJsonCaller` with `JsonAsker` and `LlmJsonAsker`, `CallerFactory`. |
| `src/Chat/` | `ChatService` (checks, stream, log), `PromptBuilder`, `CitationTracker`, emitters (`SseEmitter`, `JsonCollector`, `RecordingEmitter`). |
| `src/Spend/SpendMeterBridge.php` | Optional spend-meter integration through hooks. |
| `src/Log/` | `Schema` (dbDelta plus column check), `TurnLog`, `WpdbLogStore`, `TurnRecord`. |
| `src/Vector/` | `IndexFormat` and `IndexUnusable`: the packed index format only. |
| `src/Admin/`, `src/Cli/`, `src/Front/`, `src/Privacy.php`, `src/Uninstaller.php` | Settings and log screens, WP-CLI, shortcode/block/launcher, privacy tools, uninstall. |
| `assets/` | The widget script (about 6 KB gzipped) and stylesheet (about 1 KB gzipped). |

## Data flow of one question

```
browser                              server
  | POST /session  ----------------> origin, method; issue HMAC token, strings
  | POST /stream {session,           ChatService::check()
  |   conversation, message,           enabled > origin > method/size/JSON >
  |   context_post}                    configured > session > message >
  |                                    conversation > turns > kill > verify filter >
  |                                    slots > per-IP > per-session > daily >
  |                                    token cap > spend-meter
  |                                  (refusal: JSON {code, message}, nothing spent)
  | <---- event: meta               working state reserves the turn
  | <---- event: status             RetrievalRunner: custom or keyword, gate, number
  |                                  PromptBuilder: system | history | PASSAGES | READER
  | <---- event: delta (many)       ModelCaller streams; CitationTracker reads markers;
  | <---- : ping (while idle)        shouldStop polls abort, deadline, pings
  | <---- event: citations          markers in order, gate rechecked
  | <---- event: done               counters, history, log row, action, debug line
```

## Decisions

### Rulings on the PRD's open questions

1. **Q1, streaming transport: a direct curl handle with a write callback;
   the WordPress HTTP API is the non-streaming fallback.** The HTTP API only
   reaches curl through transport hooks whose shape changed with the
   Requests library across WordPress 6.4 to current. A direct handle gives a
   write callback (each 200 body piece goes to the parser as it arrives) and
   a progress callback (polled about once a second, so a stop or a ping does
   not wait for the next chunk). Returning a short count from the write
   callback, or non-zero from the progress callback, closes the connection,
   which stops the provider generating (lesson 4). The URL is validated as
   https at save time; redirects are off and only http and https protocols
   are allowed. Without curl, `WpHttpTransport` sends `stream: false` and
   the answer is replayed in pieces of about 40 characters with
   `"buffered": true` (case 53).
2. **Q2: ship the buffered llm-json-php caller plus the caller filter.**
   `BufferedJsonCaller` asks for `{"answer", "cited"}` and replays the
   answer; citations are `cited` intersected with the markers in the text
   (case 58). The library is a `vcs` dev dependency and a `suggest`, never
   bundled; `LlmJsonAsker` is the only file that names its classes, and is
   loaded only when `IndianOpinion\LlmJson\Client` exists. Without it the
   option is hidden on the screen and a stale value falls back to streaming
   with an admin notice. A site can always plug in its own caller with
   `sse_chat_model_caller` and run without the dependency.
3. **Q3: the stream endpoint is a REST route**, `sse-chat/v1/stream`. Every
   method is registered so the checks run in the documented order and a GET
   gets 405 from the plugin, not 404 from the REST server. Fallback for
   hosts that block anonymous REST: none in code; the settings screen has a
   loopback check that says so, and the README tells the operator to allow
   the namespace. The stream callback takes over output and exits.
4. **Q4: atomic counters in the plugin's own table with an upsert.** One
   table, `{prefix}sse_chat_counters (bucket, window_start, count,
   expires)`, is the single source of truth for rate windows, concurrency
   slots and daily totals, whether or not a persistent object cache exists.
   `increment()` is `INSERT ... ON DUPLICATE KEY UPDATE count =
   LAST_INSERT_ID(count + n)`, so the same statement adds and returns the
   new value per connection, with no read-then-write race (lesson 8).
   Rolling windows are one-minute rows: a request's total is the sum of
   earlier minutes (which no longer change) plus its own position in the
   current minute, so concurrent requests see distinct totals and exactly
   one passes at the limit. A database test forks six processes at the
   limit minus one and asserts exactly one pass.
5. **Q5: keyword retrieval through the core search query; no FULLTEXT
   index.** One `WP_Query` with `s`, the allowed types, `post_status =>
   publish`, `has_password => false`, relevance then date, `4 x passages`
   rows. Core search requires every term to match, which drops most natural
   questions, so for this one query a `posts_search` filter (added and
   removed around it, and gated on a query var) matches any of up to eight
   distinctive terms (stopwords and words under three letters removed). The
   rerank then scores title hits 3 and excerpt hits 1. Nothing is added to
   the posts table.
6. **Q6: vector retriever: interface and index format only; roadmap.**
   0.1.0 ships the `Retriever` interface, the `sse_chat_retriever` filter
   (any retriever, including a vector one from another plugin, can be used
   today and is gated, deduplicated and falls back to keyword on a throw),
   and `Vector\IndexFormat` with its header checks. The built-in vector
   retriever, the index builder, the index directory resolution and the
   hourly rebuild are not shipped. `wp sse-chat index` says so.
   Not implemented as a result: cases 61 to 63, 65, 66 and PRD tests T50,
   T51, T52, T54; T53 is covered for the format checks only.
7. **Q7: widget output is plain paragraphs only.** Blank lines make
   paragraphs, single newlines are kept with `white-space: pre-wrap`,
   markers become links only to URLs from the `citations` event, and model
   text is only ever placed with text nodes.
8. **Q8: settled by the PRD: forget hides, never deletes.** Wording shown
   to the reader after a forget (display string `forgotten`): "This
   conversation is now hidden from the chat. The site keeps its own record
   for the period set out in its privacy notice." The forget route checks
   ownership against the working state or, when that has expired, against
   the session hash on the log row; another session gets 404 and nothing
   changes. Forget requests count in their own per-IP bucket so they are
   limited without touching message counts.
9. **Q9: the IP hashing key rotates yearly, documented.** The key is
   `HMAC(nonce salt, "sse-chat-widget|ip|" + UTC year)`. Hashes group abuse
   within a calendar year and cannot be joined across years. Rate-limit
   buckets use the same hash, so they reset at the new year, which is
   harmless for hourly and daily windows.

### Decisions the PRD left open

- **Session check order.** `not_configured` (case 84) runs right after
  the body checks, before the session check, so a site without a key
  refuses early without verifying tokens.
- **Session route.** Returns `configured: false` with the display strings
  and no token when the plugin is disabled or not configured, so the
  widget hides itself. It is subject to the origin and method checks.
- **Session tokens and reloads.** The widget stores the display strings
  with the token, so a reload reuses the session instead of fetching a new
  one; a new token per page would reset the per-session limits.
- **Invalid UTF-8** anywhere in the body is `bad_encoding`, checked before
  JSON parsing, because PHP's JSON parser rejects it outright.
- **Control characters.** Only Unicode category Cc other than tab and
  newline is stripped. Format characters such as zero-width joiners stay,
  because Indic scripts need them.
- **Concurrency slots** are rows in the counter table, one per stream, with
  an expiry of max duration plus 30 seconds. Insert then count: two
  simultaneous arrivals at the edge may both be refused, never both pass.
- **Message id** in `meta` is the turn number within the conversation, so
  it can be sent before anything is logged.
- **Prompt layout.** `{passages}` in the template is replaced by a pointer
  to "the numbered passages in the message labelled PASSAGES"; the passages
  themselves and the reader's message are separate `user` messages after
  the history. The template must still contain `{passages}` so the pointer
  cannot be lost.
- **Errors after headers.** After an `error` event the stream ends with
  `done` and finish `stop`. Timeout ends with `error timeout` and `done
  length` (case 49). A reader abort ends with `done aborted` if it can
  still be written.
- **Retrieval and pings.** Retrieval is one synchronous query, so pings
  are sent while waiting for the model (from the transport's progress
  callback), not during retrieval. A `status` event is sent before
  retrieval starts.
- **No-context when both retrievers throw (case 80)** follows the
  no-context setting: `decline` calls no model, `answer` calls it with an
  empty passage block.
- **The admin test question** runs the full path synchronously and shows
  the answer, sources and assembled prompt when it finishes, rather than
  streaming into the settings screen.
- **Spend-meter contract.** Checked against spend-meter 0.1.0 (its README
  and PRD sections 5.2 and 5.3). `SpendMeterBridge` applies
  `spend_meter_pre_spend(null, $intent)` with `purpose`, `caller`,
  `provider` (the base URL host as a lower-case slug), `model`,
  `est_input_tokens` and `max_output_tokens`. A `null` result means no
  meter is listening: the plugin's own caps decide and nothing is
  recorded. Any other result whose `allowed` is not exactly `true` refuses
  with `spend_denied` (fail closed). After an allow, each attempt that
  reached the provider fires `spend_meter_record` with `reservation_id`,
  `status` (`ok`, `error_billed`, `error_unbilled`), tokens,
  `http_status`, `attempt`, `error_code` and `latency_ms`; when no attempt
  started, `spend_meter_release` fires once with the reservation id
  string. Never both for one reservation.
- **Timeouts.** Each model attempt is given the seconds left before the
  stream deadline (`ChatRequest::$timeoutSeconds`), and the built-in and
  buffered callers never wait longer, so a slow provider cannot hold the
  PHP worker past the maximum stream duration even on the non-streaming
  transport, which cannot be polled.
- **IPv6 limits.** IPv6 addresses are hashed and limited by their /64
  prefix (IPv4-mapped addresses as the IPv4 address), because a single
  subscriber usually holds a whole /64.
- **Prompt material.** Passage titles, dates and excerpts, and the context
  post title, are collapsed to one line and bracketed numbers in them
  become parentheses, so post text cannot open a new numbered passage or
  plant a citation marker.
- **Forget during a stream.** The working history is written back at the
  end of an answer only if it still exists, so a forget that lands while
  an answer streams is not undone.
- **Reader user id.** REST cookie auth drops the user without a nonce, and
  cached pages cannot carry one, so the logged-in cookie is validated
  directly for logging the user id only. It grants nothing.
- **Purge confirmation.** The "Delete eligible rows" form posts the count
  the administrator saw; if the eligible count changed meanwhile nothing is
  deleted. CSV cells starting with `=`, `+`, `-`, `@` are prefixed with a
  quote.
- **Uninstall** drops the counter table and removes options, transients and
  scheduled events. The log and audit tables are dropped only when opted
  in. It also removes `sse-chat-vectors.idx` under `SSE_CHAT_INDEX_DIR` if a
  later version left one there.
- **Capabilities.** One filter, `sse_chat_capability`, with contexts
  `settings`, `log_read` and `log_delete`, all `manage_options` by default.
  A site gives editors the log by returning a capability they hold for
  `log_read`.

## Packed vector index format (fixed for the roadmap)

All integers little-endian. Header, 32 bytes: tag `SCVX` (4), version
uint16 (1), reserved uint16, dimension uint32 (8 or more), record count
uint32, build time uint64, 8 reserved bytes. Then records: post id uint64,
then `dimension` float32 values. A reader must reject a wrong tag, unknown
version, dimension under 8 or different from the query embedding, a file
shorter than the header, or a body that is not exactly `count` whole
records, and fall back to keyword retrieval (case 64). Records are meant to
be read in chunks of 256.

## Testing

- `tests/Unit` (92 tests) run on a stub layer (`tests/stubs/`) with fakes
  for the counter store, working state, log, clock, caller and transport.
  No network.
- `tests/Db` (13 tests) run against a real MySQL or MariaDB: the upsert,
  the forked parallel limit test, pruning, log modes, raw-IP absence,
  retention, purge and audit, filters and CSV batches, uninstall, forget,
  privacy tools and the schema check. They skip without a database.
- `tests/js` (10 tests) run the widget against a tiny fake DOM that throws
  if `innerHTML` is ever set.

PRD test map: T1 `WidgetMarkupTest`; T2, T3 `SessionTest` and the JS
suite; T4, T5, T10 to T17, T66, T68 `RequestChecksTest`; T16, T18 to T22,
T24 `LimitsTest` and `CounterStoreDbTest`; T23 `SpendMeterTest`; T25
`ClientIpTest`; T26 to T28, T69, T71 `ConversationTest` and
`LogStoreDbTest`; T29, T30, T32 to T34, T70 `RetrievalTest`; T6, T31, T35,
T37 to T41, T45, T47, T49, T56, T64, T67 `StreamFlowTest`; T36
`SseEmitterTest`; T42 to T46 `StreamingCallerTest`; T48
`BufferedJsonCallerTest`; T53 (format) `IndexFormatTest`; T55, T57 to T61,
T65 `LogStoreDbTest` and `CounterStoreDbTest`; T62, T63 `SettingsTest`;
T7 to T9, T47 widget side `tests/js`. T50 to T52 and T54 wait for the
vector retriever.
