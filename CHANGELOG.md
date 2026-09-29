# Changelog

## 0.1.0 — 2026-09-29

First release.

* Subscribe to a public channel with “Add a feed” and its link (`https://t.me/<channel>`, `t.me/s/<channel>`, a post link, `@<channel>`).
* Posts are read from the channel’s public web preview; no RSSHub, no Telegram account.
* Content: text with formatting and links, **every photo** of a post (albums included), video thumbnails, forwarded-from line, reply quote, link preview, documents, polls, buttons.
* Titles from the post’s first line; media-only posts get a label (Photo, Album · 5, Video…).
* RSSHub `/telegram/channel/<channel>` subscriptions can be read directly as well, and converted in one click together with hand-made “HTML + XPath” scrapers of `t.me/s/…`.
* Default refresh interval for new channels (1 hour), to stay clear of Telegram’s rate limits.
* English and Russian translations.
