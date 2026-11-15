# sse-chat-widget: product requirements

Status: draft for build, 14 November 2026. Package: WordPress plugin
`sse-chat-widget`, Composer name `indianopinion/sse-chat-widget`,
namespace `IndianOpinion\SseChatWidget`, function and hook prefix
`sse_chat_`. This document is the only input the builder needs. Every name
in it is invented for this project and forms its public contract.

Written from scratch; not derived from any third-party plugin.

The README carries that exact line in its first section.

## 1. Problem

A site wants readers to ask questions and get answers drawn from its own
published writing, with links to the posts the answer used. The common way
to add a chat box to a WordPress site goes wrong in five ways:

- **The prompt lives in the browser.** Widgets that assemble the system
  prompt, the model name and the context in JavaScript hand all three to
  anyone who opens the developer tools. A visitor can rewrite the prompt,
  pick a costlier model, or turn the site's API key into a free general
  chatbot. The server cannot tell a real question from a forged request.
- **The answer is not grounded.** A model with no retrieval answers from
  its training data, invents posts the site never published, and cites
  nothing a reader can check.
- **Nothing stops the spend.** Without limits per visitor, per session and
  per day, one script can run up the bill overnight. Without a kill switch
  the only way to stop it is to deactivate the plugin.
- **The reader waits.** A non-streamed answer arrives after the whole
  generation finishes, often ten seconds or more. Readers close the box
  first. Streaming fails silently behind proxies and output buffers that
  hold the bytes until the response ends.
- **Logs are either missing or vanish.** Either nothing is kept, so the
  operator cannot see what readers asked or what the model said, or a
  plugin purges logs on a timer the administrator never chose.

Measured in production on the site this came out of: moving the retrieval
scan from decoding stored JSON vectors row by row to a packed binary index
read the same vectors about fifty times faster, and the wait before the
first streamed token stopped growing with the size of the archive.

sse-chat-widget fixes this with a widget that only ever sends the reader's
question, and a server that owns the prompt, the model, the retrieval, the
limits and the log, and streams the answer back with Server-Sent Events.

## 2. Goals and non-goals

Goals:

1. A front-end chat widget (shortcode, block and optional floating
   launcher) that sends only the reader's message and a session token, and
   renders a streamed answer as it arrives.
2. A server endpoint that holds the system prompt, the model choice, the
   provider credentials and the conversation history, and streams the
   answer as Server-Sent Events.
3. Retrieval over the site's own published posts only. Every answer lists
   the posts it actually used, as links.
4. Rate limits per IP and per session, a site-wide daily cap, an optional
   daily token cap, and a kill switch that the admin screen, WP-CLI or a
   constant can set.
5. A conversation log with a retention setting. The plugin never deletes
   log rows on its own. Only an administrator, by an explicit action,
   deletes them. A reader-facing "forget this conversation" control may
   hide a conversation from the widget; it never deletes a row (case 86).
6. A pluggable model caller. A built-in streaming caller for any
   OpenAI-compatible chat completions endpoint over the WordPress HTTP API,
   an optional buffered caller through `indianopinion/llm-json-php`, and a
   filter so a site can plug in its own caller.
7. A pluggable retriever. A built-in keyword retriever that spends nothing,
   and an optional vector retriever fed by another plugin's vectors.
8. Works behind page caches and CDNs without per-visitor HTML.

Non-goals:

- Web search, browsing, tools or function calling. The model sees the
  site's passages and the conversation, nothing else.
- Retrieval over drafts, private posts, password-protected posts,
  scheduled posts, attachments, comments, other sites or uploaded files.
- Computing post embeddings. The vector retriever consumes vectors another
  plugin supplies; this plugin never embeds the archive.
- Characters or role-play features. The prompt is one generic
  template the administrator edits.
- Publishing anything. The widget answers readers; it writes no posts,
  comments or pages.
- Moderating or rewriting user messages beyond the size and shape checks
  in section 4.
- Analytics dashboards beyond the counts in section 11.
- Automatic deletion of logs on a schedule, in any form.
- Shipping any provider SDK, any third-party JavaScript framework, or any
  adapter for a vendor chat plugin.

## 3. Users and setting

- **Site administrator** installs the plugin, enters the provider base URL,
  model and key, edits the prompt template, sets limits and retention,
  reads and exports the log, and purges old log rows when they choose to.
- **Editor** (any role given the plugin's log capability) may read the log
  but not change settings or delete rows, unless the administrator grants
  that capability too.
- **Reader** is an anonymous or logged-in visitor on the public site who
  types questions into the widget.
- **Plugin authors** supply a retriever, a model caller, vectors, or
  request checks through filters.

Runs on: a single WordPress site (multisite works per site; see 10), PHP
8.1+, WordPress 6.4+, behind any combination of nginx, Apache, PHP-FPM, a
page cache, an object cache and a CDN. The stream holds one PHP worker for
the length of an answer, so hosts with very few workers need low
concurrency limits (section 7).

## 4. Behaviour

Numbers in brackets in section 12 refer to these cases.

### 4.1 Widget and session

1. The widget renders from static markup plus one script and one
   stylesheet. The markup holds no nonce, no session, no user data and no
   prompt text, so the page stays cacheable by any page cache.
2. On first open (not on page load), the widget calls the session endpoint
   with `POST`. The server returns a session token, its expiry, and the
   public display strings (title, greeting, placeholder, disclaimer, the
   message length limit). The widget keeps the token in `sessionStorage`.
   If storage is unavailable it keeps it in memory for the page.
3. The session token is stateless: a random session id, the issue time and
   an HMAC over both, keyed from the WordPress salts. It is not bound to
   the IP, because mobile readers change IP mid-session. Lifetime default
   24 hours.
4. An expired or invalid token on the stream endpoint returns HTTP 401
   with code `session_invalid`. The widget fetches a new session once,
   silently, and resends the same message once. A second 401 shows the
   generic error string.
5. Logged-in readers get the same flow. The server records the user id in
   the log when one is present. No nonce is required, because the page may
   come from a cache; the Origin check (case 12) takes its place.
6. The widget sends `POST` to the stream endpoint with JSON body:
   `session` (token), `conversation` (id or empty for a new one),
   `message` (the reader's text), `context_post` (optional post id of the
   page the widget sits on). It never sends a prompt, a model, earlier
   turns, temperature or any other generation setting. Unknown body keys
   are ignored and logged at debug level once per request.
7. Because `EventSource` cannot send a `POST` body, the widget reads the
   stream with `fetch` and a `ReadableStream`, and parses the SSE frames
   itself. A browser without streaming `fetch` sends `Accept:
   application/json` and gets the whole answer in one JSON response
   (case 47).
8. The reader can stop an answer. The widget aborts the `fetch`. The
   server notices the closed connection on its next write and stops the
   upstream call (case 41).
9. Model output is rendered as text. The widget builds paragraphs from
   blank lines and turns citation markers like `[2]` into links to the
   cited post, using only the URLs from the `citations` event. It never
   assigns model output to `innerHTML`.
10. The answer region is an `aria-live="polite"` region. The input,
    send and stop controls work by keyboard, keep visible focus, and the
    launcher respects `prefers-reduced-motion`.

### 4.2 Request checks (before any spend)

Checks run in this order. The first failure ends the request with a JSON
error body `{"code": "...", "message": "..."}` and the HTTP status shown.
Nothing is streamed and no model is called.

11. Plugin disabled in settings: 503 `disabled`.
12. Origin check. If the request carries an `Origin` header, it must match
    the site's home URL origin or an entry in the allowed origins setting.
    If there is no `Origin`, the `Referer` host must match. If neither
    header is present the request is refused. Failure: 403
    `origin_refused`. This stops other sites from embedding the endpoint.
13. Method other than `POST`: 405. Body not JSON or not an object: 400
    `bad_request`. Body larger than 16 KB: 413 `too_large`.
14. Session token missing, malformed, wrong HMAC or expired: 401
    `session_invalid`.
15. `message` missing, not a string, or empty after trimming: 400
    `empty_message`. Longer than the limit (default 1,000 characters,
    counted with `mb_strlen`): 413 `message_too_long`. Invalid UTF-8: 400
    `bad_encoding`. Control characters other than tab and newline are
    stripped before the length check.
16. `conversation` present but unknown, or owned by a different session:
    404 `conversation_unknown`. The widget then starts a new conversation
    and tells the reader the earlier one has expired.
17. Conversation already at its turn limit (default 20 reader turns): 429
    `conversation_full`. The widget offers "Start a new conversation".
18. Kill switch on (setting, WP-CLI or constant): 503 `paused`, with the
    administrator's paused message.
19. Extra request check filter (`sse_chat_verify_request`) returns a
    `WP_Error`: its status (default 403) and code. This is where a site
    adds a challenge, a block list or a login requirement.
20. Concurrent stream limit: at most N open streams per IP (default 1) and
    M across the site (default 8). Over either: 429 `busy`, with a
    `Retry-After` of 5 seconds. The slot is released when the stream ends,
    errors or the worker dies (the slot expires after the maximum stream
    duration plus 30 seconds).
21. Per-IP limit: default 20 reader messages per rolling hour. Over: 429
    `rate_ip`, `Retry-After` set to the seconds until the oldest counted
    message leaves the window, rounded up to the minute.
22. Per-session limit: default 30 messages per session lifetime and 10 per
    10 minutes. Over: 429 `rate_session`.
23. Site daily message cap: default 500 reader messages per day in the
    configured time zone. Over: 429 `daily_cap` with the administrator's
    "come back tomorrow" message. The day resets at local midnight.
24. Site daily token cap (optional, off by default): when the sum of input
    and output tokens recorded today reaches the cap, 429 `daily_cap`.
    Because usage is only known after a call, this cap can be exceeded by
    the calls in flight at the moment it is reached; the README says so.
25. If spend-meter is active (`SPEND_METER_API` defined, contract 1), the
    plugin asks `spend_meter_pre_spend` with purpose `chat`, caller
    `sse-chat-widget`, provider, model, estimated input tokens and the
    output token cap. Anything other than `allowed === true`: 503
    `spend_denied`. The reader sees the paused message. The plugin keeps
    its own caps and kill switch even when spend-meter is present.
26. Counters for cases 21 to 23 are incremented atomically before the
    model call (a reservation), not after. A request refused by a later
    check does not count. A request that fails upstream still counts,
    because the provider may have billed it.
27. The client IP is `REMOTE_ADDR` unless the request comes from an
    address in the trusted proxies setting, in which case the right-most
    untrusted address in `X-Forwarded-For` is used. A filter
    (`sse_chat_client_ip`) can replace this. No forwarded header is ever
    trusted by default. An empty or unparsable IP is treated as one shared
    bucket named `unknown`, which is rate limited like any IP.

### 4.3 Conversation state

28. The server keeps the conversation history. The client never sends
    earlier turns, so a reader cannot forge an assistant turn or a system
    turn.
29. History is held in working state keyed by conversation id (object
    cache when persistent, else a transient) with a lifetime equal to the
    session lifetime. This working state is not the log. It exists even
    when logging is off, and its expiry is not log deletion.
30. The model sees the last K messages of history (default 12, counting
    both roles) plus the new message. Older turns are dropped from the
    model input, oldest first, never from the middle.
31. If the working state has expired but the log still holds the
    conversation, the server does not rebuild history from the log; the
    request gets 404 `conversation_unknown` (case 16). The log is a record,
    not a store the reader can resume from.
32. A new conversation gets a random 128-bit id, hex encoded, returned in
    the `meta` event.

### 4.4 Retrieval

33. Retrieval runs on the server for every reader message. The query is
    the new message, plus the title of the context post when one is given
    and valid, plus the previous reader message when the new one is under
    six words (so "and what about the second one?" still retrieves).
34. Only posts that are, at the moment of the request: status `publish`,
    of a post type in the allowed list (default `post`), without a
    password, and not excluded by the `sse_chat_post_allowed` filter. The
    check runs again when the passage is built and again when a citation
    is emitted, because a post can be unpublished between retrieval and
    citation.
35. The context post id is used only if it passes case 34. Otherwise it is
    ignored without an error, and the log notes `context_ignored`.
36. The built-in keyword retriever uses the WordPress search query over
    the allowed post types, ordered by relevance, newest first on ties,
    limited to 4 times the passage count, then reranks by how many query
    terms appear in the title (weight 3) and in the excerpt (weight 1).
    It costs no model call.
37. The optional vector retriever (case 61 onwards) runs only when a
    vector source and a query embedder are both registered. Otherwise the
    keyword retriever runs. A failing vector retriever falls back to the
    keyword retriever for that request and logs `retriever_fallback`.
38. Each passage holds: marker number (1 to n), post id, title, permalink,
    publication date in the site's date format, and an excerpt. The
    excerpt is the manual excerpt if set, else the content with shortcodes
    and tags stripped, whitespace collapsed, cut to the passage length
    (default 120 words) at a word boundary. Passages default to 4, maximum
    10. Duplicate posts are dropped.
39. When retrieval finds no passage, the behaviour follows the "no context"
    setting. Default `decline`: the server streams the administrator's "I
    could not find that on this site" message as the answer, calls no
    model, and logs `no_context`. Option `answer`: the model is called
    with an empty passage block and the prompt's no-context instruction.
40. Retrieval time is bounded. The keyword retriever runs one query with a
    row limit. The vector retriever reads its index in fixed-size chunks
    and never holds more than one chunk plus the top candidates in memory
    (case 64).

### 4.5 Streaming

41. Once all checks pass, the server ends every output buffer, turns off
    `zlib.output_compression` for the request, sends headers
    `Content-Type: text/event-stream; charset=utf-8`, `Cache-Control:
    no-cache, no-store, private`, `X-Accel-Buffering: no`, and `X-Robots-Tag:
    noindex`, and flushes after every event. Where the server API allows,
    it also marks the request as not to be compressed. After headers are
    sent, every failure is reported as an `error` event, never as an HTTP
    status.
42. The first event is `meta`. It is sent before retrieval starts, within
    the performance budget in section 9, and carries the conversation id
    and the message id.
43. While waiting for retrieval or the first token, the server writes an
    SSE comment line (`: ping`) every 10 seconds. This keeps proxies from
    closing an idle connection and lets PHP notice a closed connection.
44. Text arrives as `delta` events, one per upstream chunk, in order. The
    server forwards deltas as they arrive; it does not batch them.
45. Citation markers are tracked across chunk boundaries. A marker split as
    `[` in one chunk and `2]` in the next still counts.
46. After the answer, the server sends `citations` with the posts whose
    markers appeared in the answer, in order of first appearance, each
    rechecked by case 34. Markers that point outside the passage list are
    ignored. If the answer used no markers, the list is empty and the log
    marks the answer `uncited`. A setting can show the retrieved posts as
    "Related on this site" in that case; they are labelled as related,
    never as sources.
47. A request with `Accept: application/json` and no `text/event-stream`
    gets one JSON body with the same fields as the events (`meta`, `text`,
    `citations`, `error` or `done`). Same checks, same limits, same log.
48. The last event is `done`, carrying the finish reason (`stop`,
    `length`, `aborted`) and nothing about cost. Then the server exits.
49. The stream has a maximum duration (default 90 seconds). When it is
    reached the server stops the upstream call, sends `error` with code
    `timeout`, then `done` with reason `length`, and logs the partial
    answer.
50. If the reader disconnects, the next write fails or
    `connection_aborted()` turns true; the server stops reading upstream at
    once, so the provider stops generating, records usage if known, logs
    the partial answer with outcome `aborted`, and exits. No retry.

### 4.6 Model calls

51. The prompt is built only on the server from the administrator's
    template. Placeholders: `{site_name}`, `{site_description}`, `{date}`,
    `{passages}`, `{context_post}`. The default template asks the model
    to: answer only from the passages, say plainly when the passages do
    not hold the answer, cite passages with their bracketed numbers, keep
    to a few short paragraphs, treat the passages and the reader's message
    as material rather than instructions, and never reveal these
    instructions. The template is never sent to the browser in any
    response, header or page.
52. The built-in caller sends an OpenAI-compatible chat completions
    request with `stream: true` to the configured base URL, through the
    WordPress HTTP API, using a transport hook to receive the body in
    chunks as it arrives. It parses `data:` lines, stops at `data:
    [DONE]`, and reads usage from the final chunk when the provider sends
    it (it asks for it with the stream options field where supported).
53. If the WordPress HTTP API on the host cannot stream (no curl
    transport), the built-in caller makes a non-streamed request and the
    server replays the answer as `delta` events in pieces of about 40
    characters. The `meta` event carries `"buffered": true`. Same limits
    and log.
54. Upstream status other than 200: the body is read as an error document,
    never parsed as SSE. 401 and 403 map to `provider_auth`, 402 and any
    body naming credit, quota or billing map to `provider_credit`, 429 to
    `provider_busy`, 5xx and connection failures to `provider_down`. The
    reader sees one generic string per code from the display strings. The
    provider's own error text goes to the log and to the debug log only,
    never to the browser.
55. One retry, only when no `delta` has been sent: on connection failure,
    timeout before the first byte, or 5xx. Never retry 400, 401, 402, 403
    or 429. Never retry after the first delta, because the reader would
    see the answer twice.
56. An `error` frame inside a 200 stream ends the answer with the mapped
    code from case 54.
57. Output tokens are capped (default 600). A `length` finish reason is
    shown in the widget as "Answer cut short".
58. If `indianopinion/llm-json-php` is installed and the caller setting is
    `buffered-json`, the plugin asks it for a JSON object with an `answer`
    string and a `cited` array of integers, then replays the answer as
    deltas (case 53) and takes citations from `cited` intersected with
    the markers present in the text. The library's own exceptions map to
    the codes in case 54. Without the library, the setting is not
    offered and a stale value falls back to the built-in caller with an
    admin notice.
59. The `sse_chat_model_caller` filter can return any object implementing
    the caller interface (section 5.4). The plugin then uses it for every
    call and never loads the built-in caller or the library. A filter that
    returns something else is ignored, logged once per hour, and the
    built-in caller runs.
60. After every call, if spend-meter granted a reservation, the plugin
    sends exactly one `spend_meter_record` (status `ok`, `error_billed` or
    `error_unbilled`, with tokens and HTTP status) or, if the call never
    started, one `spend_meter_release`. A retry is recorded as its own
    attempt with the same reservation id.

### 4.7 Vector retriever and its index

61. The vector retriever needs two things from other code: the
    `sse_chat_post_vectors` filter, which yields `(post_id, float[])` pairs
    in batches, and the `sse_chat_embed_query` filter, which turns the
    query text into a float array of the same dimension. The query
    embedding is a paid call: it goes through the same caps and kill
    switch, counted as part of the reader's message, not as a second
    message.
62. The plugin builds a packed binary index from the vectors: a fixed
    header (format tag, format version, dimension, record count, build
    time) followed by fixed-size records (post id, then float32 values).
    It writes to a temporary file in the same directory and renames it
    over the old one, so a reader never sees a half-written file.
63. The index lives outside the web root by default (a directory set by a
    constant, else the uploads base directory's parent when writable and
    outside the document root, else a protected directory under uploads
    with deny rules written for Apache and a README line for nginx). The
    admin screen warns when the index is inside the document root.
64. Reading the index: the server checks the header before trusting any
    record. Wrong tag, unknown version, dimension that differs from the
    query embedding, dimension under 8, a file shorter than the header, or
    a body length that is not a whole number of records: the index is
    unusable. The request falls back to the keyword retriever, a rebuild
    is scheduled (once, not per request), and the log notes
    `index_unusable` with the reason. Records are read in chunks of 256.
65. Similarity is cosine. Candidates under the minimum similarity (default
    0.30) are dropped. The top passages are kept with a bounded heap, not
    by sorting every score. Each surviving post id then goes through case
    34.
66. The index is rebuilt hourly by WP-Cron when older than the rebuild
    interval, on demand from WP-CLI, and once after a fallback. A rebuild
    reads vectors in batches and never loads the whole set. A rebuild that
    fails leaves the previous index in place.

### 4.8 Log

67. Log mode setting: `full` (default: messages, answers, citations,
    outcome, tokens, timings), `metadata` (everything except message and
    answer text) or `off` (no conversation rows; only the daily counters
    in case 79). Changing the mode never deletes existing rows.
68. Each reader message and each answer is one row, written when the
    answer ends (or at the failure point), so an aborted or failed answer
    is still logged with what was streamed so far.
69. IP addresses are never stored. The log stores a keyed hash of the IP
    (HMAC with a site key), truncated to 16 hex characters, enough to
    group abuse and not reversible without the key.
70. Retention setting: a number of days (default 90; 0 means "keep
    forever"). Rows older than retention are marked "eligible for
    deletion" on the log screen with a count. The plugin never deletes
    them on its own: no cron, no activation, no update routine, no
    deactivation, no setting change deletes a log row.
71. Deletion happens only by an administrator's explicit action: the
    "Delete eligible rows" button (capability, nonce, a confirmation that
    states the count), the per-conversation delete on the log screen,
    the WP-CLI purge command (needs `--yes` or an interactive
    confirmation), or the WordPress personal data eraser run by an
    administrator for a logged-in user. Each deletion writes one audit
    entry (who, when, how many rows, by which route) that the plugin never
    deletes.
72. The log screen lists conversations newest first with filters (date
    range, outcome, cited or uncited, IP hash) and shows a conversation's
    turns with their citations and outcomes. Export to CSV for a date
    range streams rows in batches.
73. Uninstall removes settings, counters, working state, the index file and
    scheduled events. It keeps the log and audit tables unless the
    administrator has ticked "Delete the conversation log when the plugin
    is uninstalled" or defined the matching constant. The uninstall notice
    on the plugins screen says which will happen.
74. Deactivation stops scheduled events only. It deletes nothing.

### 4.9 Administration

75. Settings live on one screen under Settings, needing `manage_options`
    (filterable). Every save checks a nonce and the capability, and
    validates each field (section 7 ranges); an invalid field keeps its
    old value and shows an error for that field.
76. The API key can come from a constant, an environment variable, or the
    settings screen, in that order. A stored key is never printed back:
    the field shows the last four characters and an empty input keeps the
    key. The key is stored with autoload off.
77. A "Send a test question" button runs the full path (retrieval, prompt,
    model call, citations) as the current administrator, shows the
    streamed answer and the passages used, counts against the caps, and is
    logged with outcome `admin_test`. It shows the assembled prompt to the
    administrator only.
78. The kill switch toggles from the settings screen, the WP-CLI command,
    or the constant `SSE_CHAT_KILL` (true wins and the screen shows it
    cannot be undone there).
79. Daily counters (messages, refusals by code, tokens, aborted answers,
    uncited answers) are kept per day for 400 days. These are counts, not
    log rows; pruning them after 400 days is allowed and is not log
    deletion.

### 4.10 Failure modes summary

80. Retriever throws: fall back to keyword retrieval once; if that throws,
    treat as no context (case 39). Fail closed for the model call when the
    no-context setting is `decline`.
81. Counter storage unavailable (database error): refuse with 503
    `limits_unavailable`. Limits fail closed, because failing open spends
    without a cap.
82. Log write fails: the answer still streams (the reader is not punished
    for the log), the failure goes to the PHP error log once per minute,
    and the daily counter `log_write_failed` increments. The admin screen
    shows a notice when it is above zero today.
83. spend-meter denies or is unavailable: fail closed (case 25).
84. Provider key missing: the plugin refuses with 503 `not_configured` and
    the admin screen shows a notice. The widget hides itself after the
    session endpoint reports `configured: false`.
85. Working state store unavailable: the request runs as a one-turn
    conversation (no history), and the `meta` event says `"history":
    false`.

### 4.11 Reader "forget" control

86. The widget offers "Forget this conversation". It calls the forget
    route with the session token and the conversation id. The server
    checks the session owns the conversation, sets a "hidden from widget"
    flag on the conversation row, clears the working history (case 29),
    and returns 200 `{"forgotten": true}`. After that the conversation id
    gives 404 `conversation_unknown` and the widget starts a new one. The
    control never deletes, edits or blanks any log row, message text or
    audit entry. It does not lower any counter, so it cannot be used to
    reset a limit. The administrator's log screen still shows the
    conversation, marked "hidden by reader", and only the administrator
    actions in case 71 can delete it. The reader is told in the widget
    that the conversation is hidden from the chat and that the site keeps
    its own record for the period in the privacy notice. A hide request
    for an unknown conversation or another session's conversation gets 404
    and changes nothing. It is subject to the origin check (case 12) and
    the per-IP limit.

## 5. Public interface

### 5.1 HTTP endpoints

All under the REST namespace `sse-chat/v1`. Responses carry `Cache-Control:
no-store, private` and `X-Robots-Tag: noindex`.

| Route | Method | Body | Success |
|---|---|---|---|
| `/session` | POST | `{}` | 200 `{"session": "...", "expires": 1731600000, "configured": true, "strings": {...}, "limits": {"message_chars": 1000}}` |
| `/stream` | POST | `{"session", "conversation", "message", "context_post"}` | 200 `text/event-stream` (or JSON, case 47) |
| `/forget` | POST | `{"session", "conversation"}` | 200 `{"forgotten": true}` (hides only, case 86) |

The stream callback takes over output and exits; it does not return a
REST response object once streaming starts.

### 5.2 SSE events

Each event is `event: <name>\ndata: <json>\n\n`. Comments are `: ping`.

| Event | Data |
|---|---|
| `meta` | `{"conversation": "hex", "message": int, "buffered": bool, "history": bool}` |
| `status` | `{"text": "Looking through the archive"}` (display string) |
| `delta` | `{"text": "..."}` |
| `citations` | `{"sources": [{"n": 2, "post": 123, "title": "...", "url": "...", "date": "..."}], "related": [...]}` |
| `error` | `{"code": "provider_down", "message": "display string"}` |
| `done` | `{"finish": "stop" | "length" | "aborted"}` |

Error codes: `disabled`, `origin_refused`, `bad_request`, `too_large`,
`bad_encoding`, `session_invalid`, `empty_message`, `message_too_long`,
`conversation_unknown`, `conversation_full`, `paused`, `busy`, `rate_ip`,
`rate_session`, `daily_cap`, `spend_denied`, `limits_unavailable`,
`not_configured`, `provider_auth`, `provider_credit`, `provider_busy`,
`provider_down`, `timeout`, plus any code a verify filter returns.

### 5.3 Front end

- Shortcode `[sse_chat title="" greeting="" context="auto|none"]`.
- Block `sse-chat/widget` with the same attributes.
- Floating launcher: setting, off by default, with post type and path
  exclusions.
- Script handle `sse-chat-widget`, stylesheet handle `sse-chat-widget`;
  both loaded only on pages that show the widget. CSS custom properties
  (`--sse-chat-accent`, `--sse-chat-radius`, `--sse-chat-font`) for
  theming.
- DOM events on the widget root: `ssechat:open`, `ssechat:send`,
  `ssechat:answer`, `ssechat:error`, for site analytics.

### 5.4 PHP interfaces

- `ModelCaller`:
  `stream(ChatRequest $request, callable $onDelta, callable $shouldStop): ChatResult`.
  `ChatRequest` holds messages (role, content), model, max output tokens,
  temperature. `$onDelta(string $text): void`. `$shouldStop(): bool` is
  polled between chunks. `ChatResult` holds full text, finish reason,
  input tokens, output tokens (nullable), HTTP status, provider request id
  and a mapped error code or null. Implementations throw nothing for
  provider failures; they return a result with an error code.
- `Retriever`: `retrieve(RetrievalQuery $query): Passage[]`.
  `RetrievalQuery` holds text, context post id, limit, allowed post types.
  `Passage` holds post id, title, url, date, excerpt, score.
- `QueryEmbedder` and vector source are filters, not interfaces (5.5).

### 5.5 Hooks

Filters:

| Filter | Arguments | Default use |
|---|---|---|
| `sse_chat_settings_defaults` | array | Change defaults |
| `sse_chat_capability` | string, context (`settings`, `log_read`, `log_delete`) | `manage_options` |
| `sse_chat_client_ip` | string ip, array server | Resolve client IP |
| `sse_chat_verify_request` | true, array request | Return `WP_Error` to refuse |
| `sse_chat_model_caller` | null, array settings | Return a `ModelCaller` |
| `sse_chat_retriever` | null, array settings | Return a `Retriever` |
| `sse_chat_post_allowed` | bool, WP_Post | Exclude posts from retrieval and citation |
| `sse_chat_passage` | Passage, WP_Post | Change a passage before prompting |
| `sse_chat_post_vectors` | null, int after_id, int batch | Yield vectors |
| `sse_chat_embed_query` | null, string text | Return float[] or `WP_Error` |
| `sse_chat_prompt` | string system, array passages, array context | Last change to the system prompt |
| `sse_chat_display_strings` | array | Translate or reword strings |
| `sse_chat_log_row` | array row | Redact before write |

Actions: `sse_chat_answer_completed` (conversation id, message id, outcome,
citations, tokens), `sse_chat_request_refused` (code, ip hash),
`sse_chat_kill_switch_changed` (bool, source), `sse_chat_log_deleted`
(count, route, user id).

### 5.6 WP-CLI

- `wp sse-chat status` (enabled, kill switch, today's counts, caps, caller,
  retriever, index state).
- `wp sse-chat kill on|off`.
- `wp sse-chat ask "<question>" [--context=<post>]` (runs the full path,
  prints the answer and sources, counts against caps, logged as
  `admin_test`).
- `wp sse-chat index rebuild|status`.
- `wp sse-chat logs export --from=<date> --to=<date> [--format=csv|json]`.
- `wp sse-chat logs purge --older-than=<days> [--yes]`. With no
  `--older-than`, it uses the retention setting. Prints the count first.

### 5.7 Constants

`SSE_CHAT_API_KEY`, `SSE_CHAT_KILL`, `SSE_CHAT_INDEX_DIR`,
`SSE_CHAT_DELETE_LOG_ON_UNINSTALL`, `SSE_CHAT_VERSION`.

## 6. Data

| Store | What | Retention | Uninstall |
|---|---|---|---|
| Option `sse_chat_settings` | Settings (autoload on, no key) | n/a | Removed |
| Option `sse_chat_api_key` | Key when stored (autoload off) | n/a | Removed |
| Table `{prefix}sse_chat_conversations` | id, session hash, user id, ip hash, context post, started, last activity, turns, outcome, hidden-by-reader flag and time | Retention setting marks rows eligible; only an administrator deletes | Kept unless opted in |
| Table `{prefix}sse_chat_messages` | id, conversation id, role, text (mode `full`), retrieved post ids, cited post ids, outcome code, model, input and output tokens, latency to first token and total, created | Same | Same |
| Table `{prefix}sse_chat_audit` | Deletions and kill switch changes | Never deleted by the plugin | Same as log |
| Table `{prefix}sse_chat_counters` | Bucket key, window start, count; daily totals | Rate windows pruned when expired; daily totals 400 days | Removed |
| Object cache or transient | Working history, concurrency slots | Session lifetime / stream max plus 30 s | Removed |
| Index file | Packed vectors | Replaced on rebuild | Removed |

Schema changes go through `dbDelta` and are verified afterwards by reading
the columns back; a missing column shows an admin notice and blocks
logging (case 82) rather than writing partial rows.

## 7. Configuration and sensible defaults

| Setting | Default | Range |
|---|---|---|
| Enabled | off until a key and model are set | |
| Provider base URL | empty | https only, except `localhost` for development |
| Model | empty | up to 128 characters |
| Caller | `stream` | `stream`, `buffered-json` (library present), filter |
| Max output tokens | 600 | 64 to 4,000 |
| Temperature | 0.3 | 0 to 1.5 |
| Prompt template | generic default (case 51) | up to 8,000 characters, must contain `{passages}` |
| Post types | `post` | public post types only |
| Passages | 4 | 1 to 10 |
| Passage length | 120 words | 40 to 400 |
| Minimum similarity (vector) | 0.30 | 0 to 1 |
| No context | `decline` | `decline`, `answer` |
| Show related when uncited | off | |
| Message length | 1,000 chars | 100 to 4,000 |
| History window | 12 messages | 0 to 40 |
| Turns per conversation | 20 | 1 to 100 |
| Per IP | 20 per hour | 1 to 1,000 |
| Per session | 30 total, 10 per 10 min | 1 to 1,000 |
| Concurrent per IP / site | 1 / 8 | 1 to 10 / 1 to 100 |
| Daily messages | 500 | 1 to 1,000,000 |
| Daily tokens | off | 1,000 upwards |
| Day time zone | site time zone | |
| Max stream duration | 90 s | 15 to 300 |
| Session lifetime | 24 h | 1 to 168 h |
| Allowed origins | site origin | list of https origins |
| Trusted proxies | none | IPs and CIDRs |
| Log mode | `full` | `full`, `metadata`, `off` |
| Retention | 90 days | 0 (forever) to 3,650 |
| Delete log on uninstall | off | |
| Display strings | plain English defaults | each up to 500 characters |

## 8. Security and privacy

- The browser never receives the prompt, the model id, the provider URL,
  the key or the passages' raw excerpts (only titles and URLs of cited
  posts).
- No `eval`, no shell, no `@`. Every input is validated (section 4.2).
  Every admin output is escaped; the log viewer escapes message and answer
  text as text.
- Admin actions check capability and nonce. Public endpoints rely on the
  session token, the Origin check and the limits, because cached pages
  cannot carry a nonce.
- The HMAC key for sessions and IP hashes is derived from the WordPress
  salts with a plugin-specific label. Rotating the salts invalidates
  sessions and changes future IP hashes; the README says so.
- Prompt injection: reader text and passages are placed in separate,
  labelled messages; the template tells the model to treat them as
  material. This lowers the risk; it does not remove it. The worst case is
  an off-topic answer within the output cap, which the limits bound.
- Retrieval never reaches non-public content (case 34).
- The log holds what readers typed, which may include personal data. The
  plugin registers a privacy policy text suggestion, a personal data
  exporter and an eraser for logged-in users' rows. Anonymous rows are
  keyed only by session and IP hash. The README tells operators to set a
  retention period that matches their privacy notice and to purge.
- Outbound requests go only to the configured base URL. The URL is
  validated as https and passed through `wp_http_validate_url`.

## 9. Performance budget

- `meta` event within 300 ms of the request reaching PHP, before retrieval
  or the model, on a site with 50,000 published posts.
- Keyword retrieval: one query, under 200 ms at the 95th percentile on
  50,000 posts.
- Vector retrieval: under 300 ms for 100,000 records of dimension 512;
  memory under 16 MB above baseline (chunked read, bounded heap).
- Request checks and counters: under 20 ms, at most 4 database writes.
- Front-end: script under 12 KB and stylesheet under 4 KB gzipped, no
  framework, loaded with `defer`, nothing on pages without the widget.
- Session endpoint: no model call, no retrieval, under 50 ms.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. WordPress 6.4+. MySQL 5.7+ or MariaDB 10.4+.
- Works as a normal plugin. As a must-use plugin through a loader file;
  tables are then created on the first admin page load.
- nginx with FastCGI: `X-Accel-Buffering: no` turns off proxy buffering
  per response; FastCGI buffering in some configs still holds bytes, and
  the README gives the location block to use.
- Apache with mod_deflate or mod_proxy_fcgi: compression or proxy
  buffering can hold the stream. The plugin asks for no compression where
  `apache_setenv` exists; the README gives the config for the rest.
- PHP `output_buffering` ini and `zlib.output_compression`: handled per
  request (case 41).
- Page caches: the REST routes must be excluded; most caches skip REST and
  POST already. The widget markup is cacheable.
- CDNs: streaming responses pass through when not cached and not
  compressed by the origin. `Cache-Control: no-store` on both routes.
- Object cache optional. Multisite: per-site settings, tables and caps.
- Security plugins that block anonymous REST access break the widget; the
  admin screen detects this with a loopback check and says so.

## 11. Observability

- Settings screen status box: enabled, kill switch and its source, caller,
  retriever, index state and age, today's messages against the cap,
  refusals by code, aborted and uncited answers, log write failures, the
  last provider error code and time.
- Log screen as in case 72.
- `wp sse-chat status` prints the same.
- With `WP_DEBUG_LOG`, one line per answer: conversation, outcome,
  retriever, passages, cited count, time to first token, total time,
  tokens. Never the message text or the key.
- Actions in 5.5 for external monitoring.
- `Server-Timing` header on the session route and on refused stream
  requests (checks, retrieval).

## 12. Acceptance tests

Each test names the cases it covers. Tests run under PHPUnit with a stub
layer for WordPress and a fake `ModelCaller` and fake transport; the
widget script has a small DOM test with a fake stream.

- T1. Widget markup on a rendered page contains no nonce, token, prompt or
  model id. (1)
- T2. Session endpoint returns a token that verifies, expires at the set
  lifetime, and carries display strings only. (2, 3)
- T3. Tampered, expired and malformed tokens get 401 `session_invalid`;
  widget retries once then shows the error. (4, 14)
- T4. Logged-in request logs the user id; anonymous logs none. (5)
- T5. Stream request body with extra keys (`system`, `model`,
  `messages`) has no effect on the prompt or model sent upstream. (6, 28)
- T6. JSON fallback with `Accept: application/json` returns the full
  answer and citations in one body. (7, 47)
- T7. Widget stop button aborts the fetch; server-side abort test in T31. (8)
- T8. Model output containing HTML is rendered as text; `[2]` becomes a
  link only to a URL from `citations`; an unknown marker stays text. (9)
- T9. Widget DOM test: live region, keyboard send, focus after answer. (10)
- T10. Disabled plugin: 503 `disabled`, no upstream call. (11)
- T11. Foreign `Origin`, foreign `Referer`, and no header: 403; allowed
  origin passes. (12)
- T12. GET: 405; non-JSON: 400; 17 KB body: 413. (13)
- T13. Empty, whitespace, 1,001-character, invalid UTF-8 and
  control-character messages give the documented codes. (15)
- T14. Unknown conversation and another session's conversation: 404. (16)
- T15. Twenty-first turn: 429 `conversation_full`. (17)
- T16. Kill switch from setting, constant and CLI: 503 `paused`; constant
  cannot be cleared from the screen. (18, 78)
- T17. Verify filter returning `WP_Error` refuses with its status. (19)
- T18. Second concurrent stream from one IP: 429 `busy`; slot freed after
  the stream ends and after expiry. (20)
- T19. Twenty-first message in an hour from one IP: 429 `rate_ip` with
  `Retry-After`. (21)
- T20. Session limits total and per 10 minutes. (22)
- T21. Daily cap reached: 429 `daily_cap`; resets at local midnight in the
  configured zone. (23)
- T22. Token cap counts recorded tokens and refuses once reached. (24)
- T23. spend-meter present and denying: 503 `spend_denied`, no upstream
  call; allowing: one record per attempt, release when the call never
  starts. (25, 60, 83)
- T24. Two parallel requests at the limit minus one: exactly one passes
  (atomic counter). A request refused by a later check is not counted;
  a failed upstream call is. (26)
- T25. `X-Forwarded-For` ignored by default; honoured only from a trusted
  proxy, taking the right-most untrusted hop; empty IP buckets as
  `unknown`. (27)
- T26. History window keeps the last 12 messages; model input order is
  oldest first. (29, 30)
- T27. Expired working state with log present: 404, history not rebuilt.
  (31)
- T28. Conversation id is 32 hex characters and unique over 10,000
  draws. (32)
- T29. Short follow-up adds the previous message to the query; context
  title added only for a valid context post. (33, 35)
- T30. Drafts, private, future, password-protected, disallowed types and
  filter-excluded posts never appear in passages or citations; a post
  unpublished between retrieval and citation is dropped from citations.
  (34)
- T31. Reader disconnect mid-answer: upstream reading stops within one
  chunk, outcome `aborted`, partial answer logged, no retry. (50, 8)
- T32. Keyword retriever reranks by title term matches and returns no
  duplicates. (36, 38)
- T33. Vector retriever missing a source or embedder: keyword retriever
  runs. Throwing vector retriever: fallback logged. (37, 80)
- T34. Passage excerpt: manual excerpt wins; shortcodes and tags removed;
  cut at a word boundary. (38)
- T35. No passages with `decline`: canned message streamed, no upstream
  call, logged `no_context`. With `answer`: one call. (39)
- T36. Headers on a stream response and buffers cleared; every event is
  flushed. (41)
- T37. `meta` first, `done` last, `delta` order preserved; ping comments
  while waiting. (42, 43, 44, 48)
- T38. Marker split across chunks is detected. (45)
- T39. Citations hold only markers that appeared, in order of first use,
  ignoring out-of-range markers; uncited answer gives empty sources and
  optional related list labelled related. (46)
- T40. Max duration reached: `error timeout`, `done length`, partial
  logged. (49)
- T41. Prompt contains the template with placeholders filled, passages in
  their own message, and is absent from every response body and header.
  (51)
- T42. Built-in caller parses a recorded chat completions stream with
  usage in the last chunk and `[DONE]`. (52)
- T43. Host without streaming transport: buffered replay with `buffered:
  true`. (53)
- T44. Upstream 401, 402, quota text in a 400 body, 429, 500 and
  connection failure map to the right codes; provider text never reaches
  the browser. (54)
- T45. Retry once on 5xx before any delta; no retry after a delta; no
  retry on 4xx. (55)
- T46. Error frame inside a 200 stream ends the answer with a mapped code.
  (56)
- T47. `length` finish reason shows "Answer cut short". (57)
- T48. With a fake llm-json-php client, `buffered-json` replays the answer
  and intersects `cited` with markers present; without the library the
  option is hidden and falls back. (58)
- T49. Custom caller from the filter is used; an invalid return is ignored
  and the built-in caller runs. (59)
- T50. Query embedding counts against caps and kill switch as part of the
  same message. (61)
- T51. Index build writes header and records, uses temp file and rename;
  a reader during the build sees the old file. (62, 66)
- T52. Index directory resolution prefers the constant, refuses the
  document root, and writes deny rules when it must use uploads. (63)
- T53. Index with wrong tag, wrong version, wrong dimension, short header,
  or partial record: unusable, keyword fallback, one rebuild scheduled.
  (64)
- T54. Cosine scores, threshold, bounded heap top-k match a brute-force
  sort on random data; peak memory under budget on 100,000 records. (65,
  40)
- T55. Log modes: `full` stores text, `metadata` stores none, `off` writes
  no rows; switching mode deletes nothing. (67)
- T56. Failed and aborted answers are logged with partial text. (68)
- T57. No raw IP appears anywhere in the database after a request. (69)
- T58. Rows past retention are counted as eligible and still present after
  running every cron hook, activation, deactivation, update routine and a
  settings save. (70, 74)
- T59. Purge button without nonce or capability fails; with both deletes
  only eligible rows and writes one audit entry; CLI purge without `--yes`
  deletes nothing in non-interactive mode. (71)
- T60. Log screen filters and CSV export in batches. (72)
- T61. Uninstall keeps log and audit tables by default and removes them
  only when opted in; removes settings, counters and index. (73)
- T62. Settings save rejects out-of-range values per field. (75)
- T63. Key resolution order; stored key never printed; autoload off. (76)
- T64. Admin test question runs the full path and is logged `admin_test`.
  (77)
- T65. Daily counters kept per day; pruning after 400 days leaves log rows
  untouched. (79)
- T66. Counter store failure: 503 `limits_unavailable`, no upstream call.
  (81)
- T67. Log write failure: answer still streams, counter increments,
  notice shown. (82)
- T68. Missing key: 503 `not_configured`; session reports
  `configured: false`; widget hides. (84)
- T69. Working state unavailable: one-turn conversation with `history:
  false`. (85)
- T70. Retriever and keyword fallback both throwing: no-context path.
  (80)
- T71. Forget: the conversation is hidden from the widget (404 on reuse),
  working history is cleared, row counts in the conversations, messages
  and audit tables are unchanged and message text is intact, the log
  screen shows "hidden by reader", counters are not lowered, another
  session's id gets 404 with no change. (86)

## 13. Open questions

Answered by the builder in `docs/ARCHITECTURE.md`.

- Q1. Streaming transport: the WordPress HTTP API exposes curl only
  through a transport hook. Confirm a write-callback hook works on the
  bundled Requests version for 6.4 to current, and decide whether a direct
  curl handle is justified where it does not.
- Q2. llm-json-php does not stream. Is the `buffered-json` caller worth
  shipping in 0.1.0, or should the dependency wait until the library
  streams, with only the caller filter offered?
- Q3. REST route versus `admin-ajax.php` for the stream: REST is cleaner,
  but some hosts and security plugins block anonymous REST. Pick one and
  record the fallback.
- Q4. Atomic counters: own table with an upsert, or the object cache's
  increment when a persistent cache is present? Pick one source of truth.
- Q5. Keyword retrieval: the core search query, or an optional MySQL
  FULLTEXT index the administrator can add? Adding an index to the posts
  table is invasive on large sites.
- Q6. Should the vector retriever ship in 0.1.0, or only its interface
  and index format, until a public vector source plugin is released?
- Q7. Minimal formatting in the widget: plain paragraphs only, or a small
  safe subset (lists, emphasis) parsed client-side without HTML?
- Q8. Personal data export and erasure for anonymous readers, who have no
  account: is a reader-facing "forget this conversation" control needed,
  and how does it square with the rule that only an administrator deletes
  log rows? Settled in the PRD: the control hides, it never deletes
  (case 86). The builder confirms the wording shown to the reader.
- Q9. Should the IP hash key rotate (for example yearly), trading
  long-term abuse grouping for privacy?

## 14. Lessons from production

Written as rules. Each one cost real time once.

1. Keep the prompt, the model and the context assembly on the server. A
   browser that sends a prompt sends whatever prompt the visitor likes.
2. Never accept conversation history from the client. Hold it on the
   server and accept only the new message, or a visitor can forge
   assistant turns.
3. End every output buffer, send `X-Accel-Buffering: no`, and flush after
   every event. Without all three, the stream arrives in one lump at the
   end and looks like a slow, non-streaming answer.
4. Stop the upstream call when the reader leaves. Check for a closed
   connection inside the chunk callback, not after the call, or the
   provider keeps generating, and billing, for nobody.
5. When the upstream status is not 200, read the body as an error
   document. Parsing an error body as SSE finds no frames and the reader
   gets an empty answer with no error.
6. Map provider failures to a few reader-facing messages. Credit or quota
   exhaustion gets its own calm message; raw provider text goes to the
   log, not the screen.
7. Count the message against the limits before the call, not after it.
   A limit checked after the call lets every concurrent request through.
8. Make the counters atomic. A read-then-write counter in a transient
   lets parallel requests share one slot.
9. Never load the whole retrieval corpus into memory. Page through it with
   a keyed cursor and a fixed batch. A query that returned every row at
   once passed the PHP memory limit as the archive grew.
10. Decoding stored JSON vectors one row at a time is slow enough to push
    the first token past ten seconds. Use a packed binary index and read
    it in chunks.
11. Validate the packed index header (tag, version, dimension, whole
    records) before reading a record, and fall back to the slow path when
    it does not match. A dimension change upstream otherwise yields
    garbage scores, not an error.
12. Write the index to a temporary file and rename it into place. A reader
    must never see a half-written file.
13. Keep the index outside the web root. It is the archive in a form that
    is easy to download.
14. Recheck that a post is published at the moment it is cited. Retrieval
    data can be older than the post's status.
15. Retry only before the first delta. A retry after text has streamed
    shows the reader the answer twice.
16. Limits fail closed. If the counter store is down, refuse; failing
    open spends without a cap.
17. Logs are deleted by people, not by timers. A retention setting tells
    the administrator what is due; the administrator decides.
18. A reader's "forget" control hides; it does not delete. Hiding a
    conversation from the widget and removing its rows are different acts,
    and only the administrator may do the second.
