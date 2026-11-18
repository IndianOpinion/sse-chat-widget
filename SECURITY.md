# Security policy

## Reporting a problem

Email hello@indianopinion.org with "sse-chat-widget security" in the subject.
Please do not open a public issue for a security problem.

Include the version, what an attacker can do, and the steps to reproduce. We
reply within 5 working days, agree a fix date with you, and credit you in the
release notes unless you would rather we did not.

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | Yes |

## What counts

Anything that lets a visitor:

- see the system prompt, the model id, the provider URL, the API key or the
  raw passage excerpts;
- change the prompt, the model or the conversation history the model sees;
- get answers from drafts, private, scheduled or password-protected posts;
- get past the per-IP, per-session, daily or concurrency limits, or the kill
  switch;
- delete or alter log rows without an administrator's action;
- run script in a reader's or administrator's browser.

Prompt injection that only makes the model give an off-topic answer within
the output cap is a known limit (see the README), not a vulnerability, unless
it reaches one of the points above.
