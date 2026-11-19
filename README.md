# sse-chat-widget

Written from scratch; not derived from any third-party plugin.

A WordPress chat widget that answers readers' questions from your own
published posts, with links to the posts it used, and streams the answer
with Server-Sent Events. The system prompt, the model, the provider key and
the retrieval stay on the server; the browser only ever sends the reader's
message, with per-IP and per-session limits, a daily cap, a kill switch and
a conversation log that only an administrator can delete.

Measured in production on the site this came out of: moving the retrieval
scan from decoding stored JSON vectors row by row to a packed binary index
read the same vectors about fifty times faster, and the wait before the
first streamed token stopped growing with the size of the archive.

This project came out of the work on indianopinion.org and is written from
scratch; it is independent of and not affiliated with any third-party
plugin it integrates with.

## Contents

- [Install](#install)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [How it works](#how-it-works)
- [Hooks, CLI and constants](#hooks-cli-and-constants)
- [Servers, proxies and caches](#servers-proxies-and-caches)
- [Is it working?](#is-it-working)
- [Privacy](#privacy)
- [Limitations](#limitations)
- [FAQ](#faq)
- [Licence](#licence)

## Install

WordPress 6.4+, PHP 8.1+ with mbstring. The curl extension is recommended:
without it answers arrive in one piece and are replayed in short pieces.

**From a release archive (no Composer).** Unzip into
`wp-content/plugins/sse-chat-widget/` and activate. Nothing else is needed.

**With Composer.**

```sh
composer require indianopinion/sse-chat-widget
```

**Optional: the buffered-json caller.** It needs
[`indianopinion/llm-json-php`](https://github.com/IndianOpinion/llm-json-php).
With Composer, add the `vcs` repository and require it. Without Composer,
copy the library to `wp-content/plugins/sse-chat-widget/lib/llm-json-php/`;
the plugin loads its `bootstrap.php` from there.

**As a must-use plugin.** Copy the plugin folder to
`wp-content/mu-plugins/sse-chat-widget/` and
`mu-plugin/sse-chat-widget-loader.php` to `wp-content/mu-plugins/`. Must-use
plugins have no activation hook, so the tables are created on the first
admin page load.

## Quick start

1. Settings > Chat widget. Enter the provider base URL (any
   OpenAI-compatible chat completions endpoint, for example
   `https://api.example.com/v1`; the plugin adds `/chat/completions`), the
   model, and the key. Better: put the key in `wp-config.php`:

   ```php
   define('SSE_CHAT_API_KEY', 'sk-...');
   ```

2. Tick Enabled and save.
3. Press "Send a test question". You see the answer, the sources and the
   assembled prompt (administrators only).
4. Put `[sse_chat]` on a page, add the "Chat widget" block, or tick
   "Floating launcher".

## Configuration

Every setting is on one screen and validated on save; a bad field keeps its
old value and shows an error. Defaults can be changed in code with
`sse_chat_settings_defaults`.

| Setting | Default | Range | Notes |
|---|---|---|---|
| Enabled | off | | Off until you turn it on. |
| Provider base URL | empty | https; http only for localhost | Never sent to the browser. |
| Model | empty | up to 128 characters | Never sent to the browser. |
| API key | none | | Constant `SSE_CHAT_API_KEY`, then env `SSE_CHAT_API_KEY`, then the screen. Stored with autoload off; shown as its last four characters. |
| Caller | `stream` | `stream`, `buffered-json` | `buffered-json` appears only when the library is installed. |
| Max output tokens | 600 | 64 to 4,000 | A `length` finish shows "Answer cut short". |
| Temperature | 0.3 | 0 to 1.5 | |
| Prompt template | generic | up to 8,000 characters, must contain `{passages}` | Placeholders: `{site_name}`, `{site_description}`, `{date}`, `{passages}`, `{context_post}`. |
| Post types | `post` | public types | Retrieval and citation only ever use published posts of these types without a password. |
| Passages | 4 | 1 to 10 | |
| Passage length | 120 words | 40 to 400 | |
| When nothing is found | decline | decline, answer | Decline streams your "could not find" message and calls no model. |
| Show related when uncited | off | | Labelled "Related on this site", never as sources. |
| Message length | 1,000 characters | 100 to 4,000 | |
| History window | 12 messages | 0 to 40 | |
| Turns per conversation | 20 | 1 to 100 | |
| Per IP | 20 per rolling hour | 1 to 1,000 | |
| Per session | 30 total, 10 per 10 minutes | 1 to 1,000 | |
| Open streams per IP / site | 1 / 8 | 1 to 10 / 1 to 100 | Each stream holds one PHP worker. |
| Daily messages | 500 | 1 to 1,000,000 | Resets at local midnight. |
| Daily tokens | off | 1,000 upwards | Usage is known only after a call, so calls in flight when the cap is reached can pass it. |
| Day time zone | site time zone | | |
| Max stream duration | 90 s | 15 to 300 | |
| Session lifetime | 24 h | 1 to 168 h | |
| Extra allowed origins | none | https origins | The site's own origin is always allowed. |
| Trusted proxies | none | IPs and CIDRs | Empty means `X-Forwarded-For` is ignored. |
| Log mode | full | full, metadata, off | Changing it never deletes rows. |
| Log retention | 90 days | 0 (forever) to 3,650 | Marks rows eligible. The plugin never deletes them itself. |
| Delete log on uninstall | off | | Or constant `SSE_CHAT_DELETE_LOG_ON_UNINSTALL`. |
| Floating launcher | off | | With post type and path exclusions. |
| Display strings | plain English | up to 500 characters each | Also filterable with `sse_chat_display_strings`. |

## How it works

1. The page holds static markup: an endpoint URL and the page's post id. No
   nonce, no token, no prompt. Page caches can cache it.
2. On first use the widget calls `POST /wp-json/sse-chat/v1/session` and
   gets a signed session token (random id, issue time, HMAC) and the display
   strings. It keeps them in `sessionStorage`.
3. A question goes to `POST /wp-json/sse-chat/v1/stream` as
   `{session, conversation, message, context_post}`. Nothing else is read.
4. The server checks, in order: enabled, Origin (or Referer), method, body
   size and JSON, configured, session, message, conversation, turn limit,
   kill switch, the `sse_chat_verify_request` filter, open streams, per-IP,
   per-session and daily counts (taken atomically before the call), the
   daily token cap, and spend-meter when present (see below). The first failure returns
   `{"code", "message"}` with a status; nothing is streamed or spent.
5. It sends `meta`, then searches published posts, builds the prompt from
   your template with the passages and the reader's message in separate
   labelled messages, and calls the model.
6. Text arrives as `delta` events. Markers like `[2]` are tracked across
   chunks. Then `citations` (posts whose markers appeared, each checked again
   for being published), then `done`.
7. The turn is written to the log and the working history is updated.

Events:

| Event | Data |
|---|---|
| `meta` | `{"conversation": "hex", "message": int, "buffered": bool, "history": bool}` |
| `status` | `{"text": "Looking through the archive"}` |
| `delta` | `{"text": "..."}` |
| `citations` | `{"sources": [{"n", "post", "title", "url", "date"}], "related": [...]}` |
| `error` | `{"code": "provider_down", "message": "display string"}` |
| `done` | `{"finish": "stop" or "length" or "aborted"}` |

Error codes: `disabled`, `origin_refused`, `bad_request`, `too_large`,
`bad_encoding`, `session_invalid`, `empty_message`, `message_too_long`,
`conversation_unknown`, `conversation_full`, `paused`, `busy`, `rate_ip`,
`rate_session`, `daily_cap`, `spend_denied`, `limits_unavailable`,
`not_configured`, `provider_auth`, `provider_credit`, `provider_busy`,
`provider_down`, `timeout`, plus any code a verify filter returns.

The design and every decision are in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md);
the behaviour is specified case by case in [docs/PRD.md](docs/PRD.md).

## Hooks, CLI and constants

Filters: `sse_chat_settings_defaults`, `sse_chat_capability` (cap, context:
`settings`, `log_read`, `log_delete`), `sse_chat_client_ip`,
`sse_chat_verify_request`, `sse_chat_model_caller`, `sse_chat_retriever`,
`sse_chat_post_allowed`, `sse_chat_passage`, `sse_chat_prompt`,
`sse_chat_display_strings`, `sse_chat_log_row`. `sse_chat_post_vectors` and
`sse_chat_embed_query` are reserved for the vector retriever (roadmap).

Actions: `sse_chat_answer_completed`, `sse_chat_request_refused`,
`sse_chat_kill_switch_changed`, `sse_chat_log_deleted`.

spend-meter (optional, contract `SPEND_METER_API` = 1): before each answer
the plugin applies `spend_meter_pre_spend` with purpose `chat`, caller
`sse-chat-widget`, the provider host, the model, `est_input_tokens` and
`max_output_tokens`. A `null` answer means no meter is listening and the
plugin's own caps decide; any answer whose `allowed` is not exactly `true`
refuses with `spend_denied`. Each attempt that reaches the provider fires
`spend_meter_record` with the `reservation_id`; if no attempt starts,
`spend_meter_release` fires once with the reservation id. The plugin's own
caps and kill switch apply whether or not spend-meter is installed.

Front end: CSS custom properties `--sse-chat-accent`, `--sse-chat-radius`,
`--sse-chat-font`; DOM events `ssechat:open`, `ssechat:send`,
`ssechat:answer`, `ssechat:error` on the widget root.

Runnable samples are in [`examples/`](examples):
`parse-recorded-stream.php` runs offline; `custom-caller.php`,
`custom-retriever.php` and `verify-request.php` are drop-in snippets.

WP-CLI:

```sh
wp sse-chat status
wp sse-chat kill on|off
wp sse-chat ask "When did the bridge open?" [--context=123]
wp sse-chat index status|rebuild        # reports that the vector index is not shipped yet
wp sse-chat logs export --from=2026-11-01 --to=2026-11-30 [--format=csv|json]
wp sse-chat logs purge [--older-than=<days>] [--yes]
```

`logs purge` prints the count first. Without `--yes` it asks, and when not
run in a terminal it deletes nothing.

Constants: `SSE_CHAT_API_KEY`, `SSE_CHAT_KILL` (true pauses the chat and
cannot be undone from the screen), `SSE_CHAT_INDEX_DIR`,
`SSE_CHAT_DELETE_LOG_ON_UNINSTALL`, `SSE_CHAT_VERSION`.

## Servers, proxies and caches

A stream needs every layer to pass bytes through as they are written. The
plugin ends PHP's output buffers, turns off `zlib.output_compression`, asks
Apache not to gzip, and sends `X-Accel-Buffering: no` and
`Cache-Control: no-cache, no-store, private`. If the answer still arrives in
one lump at the end, a layer in front is buffering.

nginx with PHP-FPM: `X-Accel-Buffering: no` turns off proxy buffering, but
some FastCGI setups still hold bytes. Add:

```nginx
location ~ ^/wp-json/sse-chat/v1/stream {
    fastcgi_buffering off;
    gzip off;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    fastcgi_pass php;   # your upstream
}
```

Apache with mod_proxy_fcgi: add `flushpackets=on` to the `ProxyPass` or
`SetHandler` line, and exclude the route from mod_deflate:
`SetEnvIf Request_URI "^/wp-json/sse-chat/" no-gzip`.

Page caches: exclude `/wp-json/sse-chat/`. Most skip REST and POST already.
CDNs: both routes send `no-store`; streaming passes through when the origin
does not compress.

Each open stream holds one PHP worker for up to the max stream duration.
On a host with few workers keep "open streams across the site" low.

Security plugins that block anonymous REST requests break the widget. The
settings screen has a "Check anonymous REST access" button.

## Is it working?

- The status box (Settings > Chat widget) and `wp sse-chat status` show:
  enabled, kill switch and its source, caller, retriever, today's messages
  against the cap, refusals by code, aborted and uncited answers, log write
  failures and the last provider error.
- Tools > Chat log lists conversations with filters and exports CSV.
- With `WP_DEBUG_LOG` on, each answer writes one line: conversation,
  outcome, retriever, passages, cited count, time to first token, total
  time, tokens. Never the message text or the key.
- The session route and refused stream requests carry a `Server-Timing`
  header.

## Privacy

- IP addresses are never stored. The log keeps a 16-character keyed hash.
  The hash key is derived from the WordPress salts and rotates every
  calendar year (UTC): abuse can be grouped within a year, and hashes from
  different years cannot be joined. Rotating the salts invalidates sessions
  and changes future hashes too. IPv6 readers are hashed and limited by
  their /64 prefix, since one subscriber usually holds a whole /64.
- The log holds what readers typed. Set a retention period that matches
  your privacy notice, and purge eligible rows from the log screen or with
  `wp sse-chat logs purge`. The plugin never deletes them on its own.
- The plugin adds a privacy policy suggestion, a personal data exporter and
  an eraser for logged-in readers. Anonymous rows are keyed only by session
  and IP hash.
- "Forget this conversation" hides the conversation from the chat and
  clears its working history. It does not delete or change any log row, and
  does not lower any limit. The reader is told the site keeps its own record
  for the period in its privacy notice.

## Limitations

- Retrieval is keyword search through the core search query. It finds
  posts that share words with the question, not posts that mean the same
  thing. The vector retriever is on the roadmap; its index format is fixed
  (see ARCHITECTURE).
- Prompt injection: passages and the reader's message are placed in
  separate labelled messages and the template tells the model to treat them
  as material. This lowers the risk; it does not remove it. The worst case
  is an off-topic answer within the output cap, which the limits bound.
- The daily token cap can be passed by calls in flight when it is reached.
- Answers are plain paragraphs. No lists, tables or emphasis.
- Two requests arriving at the same instant at the edge of the open-stream
  limit can both be refused. They can never both pass.
- The buffered-json caller and hosts without curl show the answer only
  after the whole of it is generated, replayed in short pieces.

## FAQ

**Why not EventSource?** It cannot send a POST body. The widget reads the
stream with `fetch` and parses the frames itself.

**Why no nonce?** Pages with the widget are often served from a cache, so a
nonce in the page would be stale. The Origin check, the signed session token
and the limits take its place.

**Can a reader change the model or the prompt?** No. The stream route reads
four keys and ignores the rest. The history the model sees is held on the
server.

**Does the retention setting delete anything?** No. It marks conversations
as eligible on the log screen. You delete them.

**Which providers work?** Any OpenAI-compatible chat completions endpoint
that streams with `data:` lines and ends with `data: [DONE]`.

**Where do I plug in my own model client?** Return an object implementing
`IndianOpinion\SseChatWidget\Model\ModelCaller` from
`sse_chat_model_caller`. See `examples/custom-caller.php`.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Licence

MIT. Copyright (c) 2026 Indian Opinion. See [LICENSE](LICENSE).
