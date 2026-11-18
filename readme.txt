=== SSE Chat Widget ===
Contributors: indianopinion
Tags: chat, search, server-sent events, ai, rate limit
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

A chat widget that answers from your own published posts and streams the answer. The prompt, the model and the retrieval stay on the server.

== Description ==

Written from scratch; not derived from any third-party plugin.

Readers ask a question; the answer comes from your published posts, with links to the posts it used, and streams in as it is written (Server-Sent Events).

* The browser sends only the reader's message. The system prompt, the model, the provider key, the conversation history and the passages stay on the server.
* Retrieval only ever uses published posts of the types you choose, without a password. Every citation is checked again when it is shown.
* Limits per IP, per session and per day, a cap on open streams, an optional daily token cap, and a kill switch on the screen, in WP-CLI or as a constant.
* A conversation log with a retention setting. The plugin never deletes log rows on its own; only an administrator does, and each deletion is audited.
* Works behind page caches: the widget markup holds no nonce, token or user data.
* Any OpenAI-compatible chat completions endpoint. Optional buffered caller through indianopinion/llm-json-php, or your own caller through a filter.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

== Installation ==

1. Upload the plugin folder to `wp-content/plugins/` and activate it.
2. Settings > Chat widget: enter the provider base URL, the model and the key (or define `SSE_CHAT_API_KEY` in wp-config.php). Tick Enabled.
3. Press "Send a test question".
4. Add `[sse_chat]`, the Chat widget block, or turn on the floating launcher.

As a must-use plugin, copy the folder into `wp-content/mu-plugins/sse-chat-widget/` and `mu-plugin/sse-chat-widget-loader.php` into `wp-content/mu-plugins/`.

== Frequently Asked Questions ==

= The answer arrives all at once =

A layer in front of PHP is buffering. See "Servers, proxies and caches" in the README for nginx and Apache settings.

= Does the retention setting delete old conversations? =

No. It marks them as eligible on Tools > Chat log. You delete them with the button there or with `wp sse-chat logs purge`.

= What does "Forget this conversation" do? =

It hides the conversation from the chat and clears its working history. It does not delete the site's log rows.

== Privacy ==

The plugin logs what readers type and the answers, with a keyed hash of the IP address (never the address). It sends the reader's question and passages from your posts to the model provider you configure. It adds a privacy policy suggestion, a personal data exporter and an eraser for logged-in users.

== Changelog ==

= 0.1.0 =
* First release.
