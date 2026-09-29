<?php

declare(strict_types=1);

/**
 * Manual check of the parser without FreshRSS: prints what a channel would look like as a feed.
 *
 *   php tests/preview.php durov              # fetches https://t.me/s/durov
 *   php tests/preview.php page.html durov    # a saved copy of that page
 *   php tests/preview.php durov --rss        # the generated RSS instead of a summary
 */

require dirname(__DIR__) . '/lib/TelegramChannelsPage.php';
require dirname(__DIR__) . '/lib/TelegramChannelsRss.php';

$args = array_values(array_filter(array_slice($argv, 1), static fn(string $a): bool => $a !== '--rss'));
$rss = in_array('--rss', $argv, true);
if ($args === []) {
	fwrite(STDERR, "usage: php tests/preview.php <channel> | <saved-page.html> <channel> [--rss]\n");
	exit(2);
}
if (is_file($args[0])) {
	$html = (string)file_get_contents($args[0]);
	$channel = $args[1] ?? '';
} else {
	$channel = ltrim($args[0], '@');
	$context = stream_context_create(['http' => ['header' => "User-Agent: Mozilla/5.0 (FreshRSS Telegram Channels test)\r\n", 'timeout' => 20]]);
	$html = (string)@file_get_contents(TelegramChannelsPage::previewUrl($channel), false, $context);
}

$data = TelegramChannelsPage::parse($html, $channel);
if ($data === null) {
	fwrite(STDERR, "not a public channel preview\n");
	exit(1);
}
if ($rss) {
	echo TelegramChannelsRss::build($data);
	exit(0);
}
printf("%s — %s\n%s\n\n", $data['title'], $data['link'], $data['description']);
foreach (array_reverse($data['posts']) as $post) {
	printf("%s  %s\n  %s | %d image(s) | %d bytes of HTML\n", $post['date'] > 0 ? date('Y-m-d H:i', $post['date']) : '?', $post['link'],
		$post['title'], substr_count($post['html'], '<img'), strlen($post['html']));
}
