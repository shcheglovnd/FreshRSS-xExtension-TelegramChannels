<?php

declare(strict_types=1);

/**
 * Follow public Telegram channels in FreshRSS without RSSHub or any other proxy.
 *
 * A channel is subscribed to as https://t.me/s/<channel>, the public web preview Telegram serves
 * for every public channel. Whenever FreshRSS loads such a feed, this extension downloads that page
 * itself, reads the posts ({@see TelegramChannelsPage}) and hands SimplePie a ready RSS document
 * ({@see TelegramChannelsRss}) instead of letting it fetch the HTML page.
 */
final class TelegramChannelsExtension extends Minz_Extension {
	/** Refresh intervals offered for new Telegram channels, in minutes; 0 keeps the FreshRSS default. */
	public const TTL_CHOICES = [0, 15, 30, 60, 120, 240, 480, 1440];
	private const TTL_DEFAULT_MINUTES = 60;

	/** Paths on t.me that are not channels. */
	private const RESERVED = ['s', 'c', 'addlist', 'addemoji', 'addstickers', 'addtheme', 'bg', 'boost', 'confirmphone', 'contact',
		'giftcode', 'invoice', 'iv', 'joinchat', 'login', 'proxy', 'setlanguage', 'share', 'socks'];

	#[\Override]
	public function init(): void {
		parent::init();
		require_once __DIR__ . '/lib/TelegramChannelsPage.php';
		require_once __DIR__ . '/lib/TelegramChannelsRss.php';
		$this->registerTranslates();
		// Hook names as strings rather than Minz_HookType, to stay compatible with FreshRSS 1.28
		$this->registerHook('check_url_before_add', [$this, 'checkUrlBeforeAdd']);
		$this->registerHook('feed_before_insert', [$this, 'feedBeforeInsert']);
		$this->registerHook('simplepie_before_init', [$this, 'simplepieBeforeInit']);
	}

	/**
	 * “Add a feed” accepts https://t.me/<channel>, t.me/s/<channel>, a link to one of its posts, @<channel>,
	 * or an RSSHub /telegram/channel/<channel> URL; all become https://t.me/s/<channel>.
	 */
	public function checkUrlBeforeAdd(string $url): string {
		$channel = $this->channelFromUrl($url, allowHandle: true);
		return $channel === null ? $url : TelegramChannelsPage::previewUrl($channel);
	}

	/** New Telegram channels get the refresh interval chosen in this extension’s settings. */
	public function feedBeforeInsert(FreshRSS_Feed $feed): FreshRSS_Feed {
		if ($this->channelFromUrl($this->plainUrl($feed)) !== null && $feed->ttl() === FreshRSS_Feed::TTL_DEFAULT) {
			$minutes = $this->ttlMinutes();
			if ($minutes > 0) {
				$feed->_ttl($minutes * 60);
			}
		}
		return $feed;
	}

	/**
	 * Before SimplePie fetches a Telegram channel, give it the RSS built from the channel’s web preview.
	 * @throws FreshRSS_Feed_Exception when the page cannot be loaded or is not a public channel
	 */
	public function simplepieBeforeInit(FreshRSS_SimplePieCustom $simplePie, FreshRSS_Feed $feed): void {
		if (!in_array($feed->kind(), [FreshRSS_Feed::KIND_RSS, FreshRSS_Feed::KIND_RSS_FORCED], true)) {
			return;
		}
		$url = $this->plainUrl($feed);
		$channel = $this->channelFromUrl($url);
		if ($channel === null) {
			return;
		}

		$previewUrl = TelegramChannelsPage::previewUrl($channel);
		$response = FreshRSS_http_Util::httpGet($previewUrl, $feed->cacheFilename($previewUrl), 'html', $feed->attributes(), $feed->curlOptions());
		$body = $response['body'];
		if ($response['fail'] || $body === '') {
			$status = abs($response['status']);
			throw new FreshRSS_Feed_Exception(_t('ext.telegram_channels.error.fetch', $previewUrl, $status > 0 ? 'HTTP ' . $status : $response['error']), $status);
		}
		$data = TelegramChannelsPage::parse($body, $channel, $this->labels());
		if ($data === null) {
			throw new FreshRSS_Feed_Exception(_t('ext.telegram_channels.error.not_a_channel', $channel, $previewUrl));
		}

		$file = \SimplePie\File::fromResponse(new \SimplePie\HTTP\RawTextResponse(TelegramChannelsRss::build($data), $url));
		$simplePie->set_file($file);
		$simplePie->force_feed(true);   // the body has no Content-Type for SimplePie to recognise; it is our own RSS
	}

	/**
	 * The public channel a URL points at, or null.
	 * @param bool $allowHandle also accept a bare `@channel`
	 * @param bool|null $rsshub also recognise RSSHub `/telegram/channel/<name>` routes (null: per the user’s setting)
	 */
	public function channelFromUrl(string $url, bool $allowHandle = false, ?bool $rsshub = null): ?string {
		$url = trim($url);
		$name = TelegramChannelsPage::CHANNEL_PATTERN;
		if ($allowHandle && preg_match('/^@(' . $name . ')$/', $url, $matches) === 1) {
			return $matches[1];
		}
		if (preg_match('#^(?:https?://)?(?:www\.)?(?:t|telegram)\.me/(?:s/)?(' . $name . ')(?:/\d+)?/?(?:[?\#].*)?$#i', $url, $matches) === 1) {
			return in_array(strtolower($matches[1]), self::RESERVED, true) ? null : $matches[1];
		}
		if (($rsshub ?? $this->rsshubEnabled())
			&& preg_match('#^https?://[^/?\#]+(?:/[^?\#]*)?/telegram/channel/(' . $name . ')(?:[/?\#].*)?$#i', $url, $matches) === 1) {
			return $matches[1];
		}
		return null;
	}

	/**
	 * Points this user’s existing Telegram feeds at the channel preview and hands them to this extension:
	 * RSSHub /telegram/channel/… subscriptions, and “HTML + XPath” scrapers of t.me/s/… set up by hand.
	 * Names, categories, articles and read states are kept.
	 * @return int number of feeds changed
	 */
	public function convertFeeds(bool $dryRun = false): int {
		$dao = FreshRSS_Factory::createFeedDao();
		$changed = 0;
		foreach ($dao->listFeeds() as $feed) {
			$channel = $this->channelFromUrl($this->plainUrl($feed), rsshub: true);
			if ($channel === null) {
				continue;
			}
			$values = [];
			$previewUrl = TelegramChannelsPage::previewUrl($channel);
			if ($this->plainUrl($feed) !== $previewUrl && $dao->searchByUrl($previewUrl) === null) {
				$values['url'] = $previewUrl;
			}
			if (!in_array($feed->kind(), [FreshRSS_Feed::KIND_RSS, FreshRSS_Feed::KIND_RSS_FORCED], true)) {
				$values['kind'] = FreshRSS_Feed::KIND_RSS;
			}
			if ($feed->attributeArray('xpath') !== null) {
				$feed->_attribute('xpath', null);
				$values['attributes'] = $feed->attributes();
			}
			if (!str_starts_with($feed->website(), 'https://t.me/')) {
				$values['website'] = TelegramChannelsPage::channelUrl($channel);
			}
			if ($values !== [] && ($dryRun || $dao->updateFeed($feed->id(), $values))) {
				$changed++;
			}
		}
		return $changed;
	}

	#[\Override]
	public function handleConfigureAction(): void {
		$this->registerTranslates();
		if (!Minz_Request::isPost()) {
			return;
		}
		$minutes = Minz_Request::paramInt('ttl_minutes');
		$this->setUserConfiguration([
			'ttl_minutes' => in_array($minutes, self::TTL_CHOICES, true) ? $minutes : self::TTL_DEFAULT_MINUTES,
			'rsshub' => Minz_Request::paramBoolean('rsshub'),
		]);
		if (Minz_Request::paramString('tg_action') === 'convert') {
			$changed = $this->convertFeeds();
			Minz_Request::good(_t('ext.telegram_channels.convert.done', $changed),
				['c' => 'extension', 'a' => 'configure', 'params' => ['e' => $this->getName()]]);
		}
	}

	public function ttlMinutes(): int {
		return $this->getUserConfigurationInt('ttl_minutes') ?? self::TTL_DEFAULT_MINUTES;
	}

	public function rsshubEnabled(): bool {
		return $this->getUserConfigurationBool('rsshub') ?? true;
	}

	/** @return array<string,string> labels for generated content, in the user’s language */
	private function labels(): array {
		$labels = [];
		foreach (array_keys(TelegramChannelsPage::LABELS) as $key) {
			$labels[$key] = _t('ext.telegram_channels.label.' . $key);
		}
		return $labels;
	}

	private function plainUrl(FreshRSS_Feed $feed): string {
		return htmlspecialchars_decode($feed->url(), ENT_QUOTES);
	}
}
