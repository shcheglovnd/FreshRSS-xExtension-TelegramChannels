# Telegram Channels — FreshRSS extension

Follow **public Telegram channels** in [FreshRSS](https://freshrss.org) directly: no RSSHub or other
proxy, no Telegram account, no API keys. Paste a channel link into *Add a feed* and you’re done.

Telegram publishes a web preview of every public channel at `https://t.me/s/<channel>` (the latest
~20 posts). Whenever FreshRSS refreshes such a subscription, this extension downloads that page,
reads the posts, and hands FreshRSS a proper RSS document built from them.

## Why

Telegram has no RSS, so channels are usually followed through [RSSHub](https://docs.rsshub.app/)’s
`/telegram/channel/…` route. Public RSSHub instances are shared by many users and are often
rate-limited (HTTP 429), which leaves every Telegram subscription broken at once, and self-hosting
RSSHub means running another service. This extension needs nothing but FreshRSS itself.

## What you get for each post

* **Title**: the post’s first line (usually its headline), or the start of the text. Posts with
  only media get a label such as *Photo*, *Album · 5* or *Video*.
* **Text** with its formatting and links. Hashtag links open the channel search.
* **Every photo**, albums included, as real images. Telegram draws them as CSS backgrounds, which a
  plain HTML scraper would lose.
* **Video** thumbnails with a link to the video. Also: the *forwarded from* line, the reply quote,
  link previews (site, title, description, image), documents, polls with their results, and the
  buttons under the post.
* The channel’s name, description and avatar for the feed. The first image of a post becomes the
  article thumbnail.

## Install

1. Download this repository into a folder named `xExtension-TelegramChannels`, either
   `git clone https://github.com/shcheglovnd/FreshRSS-xExtension-TelegramChannels.git xExtension-TelegramChannels`, or
   *Code → Download ZIP* and rename the extracted folder. FreshRSS accepts any folder name;
   `xExtension-…` is just the convention.
2. Put that folder into the `extensions/` directory of your FreshRSS. With the official Docker
   image that is the volume mounted at `/var/www/FreshRSS/extensions`.
3. In FreshRSS: *Settings → Extensions*, then enable **Telegram Channels**. It is a per-user
   extension.

Requires FreshRSS **1.28 or newer** (tested on 1.28.1 and 1.30.0).

## Use

*Subscription management → Add a feed*, then paste any of these:

* `https://t.me/durov`
* `https://t.me/s/durov`
* a link to a post, e.g. `https://t.me/durov/123`
* `@durov` (where the form accepts it)
* an RSSHub URL such as `https://rsshub.app/telegram/channel/durov`

All of them are stored as `https://t.me/s/durov`, and FreshRSS takes the channel’s name, avatar and
latest posts from the page.

### Settings

*Settings → Extensions → Telegram Channels ⚙*:

* **Refresh interval for new channels.** The default is 1 hour. Telegram may rate-limit a server
  that polls it too often, so keep it moderate if you follow many channels. Existing feeds keep
  their own interval, which you can change per feed as usual.
* **Read RSSHub Telegram feeds directly as well.** On by default. Subscriptions with
  `…/telegram/channel/<name>` URLs are then read from Telegram instead of from RSSHub.
* **Convert existing feeds.** Rewrites your RSSHub Telegram subscriptions, and any hand-made
  *HTML + XPath* scrapers of `t.me/s/…`, into direct `https://t.me/s/<channel>` subscriptions
  handled by this extension. Names, categories, articles and read states are kept. Articles
  already stored are updated in place, not duplicated: each post keeps its `https://t.me/<channel>/<id>` link as ID.

## Limitations

* **Public channels only**, the ones with a `t.me/s/…` preview. Private channels, groups and bots
  don’t work.
* **One page per refresh.** A refresh sees only the latest ~20 posts, so a channel that posts more
  than that between two refreshes loses the overflow.
* **Media lives on Telegram’s CDN.** Images load from Telegram’s servers when you read, and very
  old image links may stop working. Pair it with the
  [Image Proxy](https://github.com/FreshRSS/Extensions/tree/main/xExtension-ImageProxy) extension
  if you prefer.
* **No inline video playback.** Videos appear as a thumbnail with a link to the post; the preview’s
  video URLs are temporary.
* **Tied to Telegram’s page layout.** If Telegram changes its preview markup, the parser
  (`lib/TelegramChannelsPage.php`) needs an update.
* **Disabling the extension breaks these feeds.** `t.me/s/…` subscriptions stop working, because
  that page is not RSS on its own.

## How it works

* `check_url_before_add` normalises every supported URL form to `https://t.me/s/<channel>`.
* `simplepie_before_init` runs whenever FreshRSS loads a feed. For a Telegram channel it downloads
  the preview with FreshRSS’s own HTTP client, so proxy settings, timeouts, caching and Retry-After
  handling are respected. `TelegramChannelsPage` parses the page, `TelegramChannelsRss` renders
  RSS 2.0, and the result is given to SimplePie as the feed body. FreshRSS then processes it like
  any other feed: filters, deduplication, sanitising, notifications.
* `feed_before_insert` applies the refresh interval to newly added channels.

## Development

`tests/preview.php` runs the parser without FreshRSS:

```sh
php tests/preview.php durov                # live https://t.me/s/durov
php tests/preview.php saved-page.html durov
php tests/preview.php durov --rss          # print the generated RSS
```

## License

[MIT](LICENSE)
