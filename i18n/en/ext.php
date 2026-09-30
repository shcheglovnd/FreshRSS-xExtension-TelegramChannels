<?php

return [
	'telegram_channels' => [
		'intro' => 'Subscribe to a public Telegram channel with “Add a feed” and its link, e.g. <code>https://t.me/durov</code>. The channel is read from its public web preview (<code>t.me/s/…</code>) — no RSSHub or other proxy, no Telegram account.',
		'ttl' => 'Refresh interval for new channels',
		'ttl_freshrss' => 'FreshRSS default',
		'minutes' => '%d minutes',
		'hours' => '%d h',
		'ttl_help' => 'Applied when a channel is added. Telegram may rate-limit a server that asks too often, so one hour is a good choice for many channels.',
		'rsshub' => 'Read RSSHub Telegram feeds (…/telegram/channel/…) directly as well',
		'rsshub_help' => 'Such subscriptions then stop depending on the RSSHub instance, which is often rate-limited.',
		'convert' => [
			'title' => 'Existing feeds',
			'button' => 'Convert %d feed(s)',
			'help' => 'Turns your RSSHub Telegram feeds and hand-made “HTML + XPath” scrapers of t.me/s/… into direct subscriptions. Names, categories, articles and read states are kept.',
			'done' => '%d feed(s) converted',
		],
		'label' => [
			'forwarded' => 'Forwarded from',
			'photo' => 'Photo',
			'album' => 'Album',
			'video' => 'Video',
			'round_video' => 'Video message',
			'voice' => 'Voice message',
			'sticker' => 'Sticker',
			'poll' => 'Poll',
			'file' => 'File',
			'post' => 'Post',
			'open' => 'Open in Telegram',
		],
		'error' => [
			'fetch' => 'Telegram: could not load %s (%s)',
			'no_preview' => 'Telegram served no preview of “%s” this time — it does so now and then; FreshRSS will try again at the next refresh (%s)',
			'not_a_channel' => 'Telegram: “%s” is not a public channel, or it has no public preview (%s)',
		],
	],
];
