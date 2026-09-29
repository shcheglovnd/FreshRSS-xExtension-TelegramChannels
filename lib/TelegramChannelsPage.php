<?php

declare(strict_types=1);

/**
 * Reads Telegram's public web preview of a channel, https://t.me/s/<channel>, which lists the
 * latest ~20 posts as HTML, and turns it into plain data: channel details plus one entry per post,
 * each with ready-to-use HTML content.
 *
 * Telegram draws photos, video thumbnails and link-preview images as CSS backgrounds rather than
 * <img> tags; they are converted to real <img> elements here, so they survive feed sanitising.
 *
 * Independent of FreshRSS, so it can be tested on saved pages.
 */
final class TelegramChannelsPage {
	/** Labels used in generated content; replaced with translations by the extension. */
	public const LABELS = [
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
	];

	/** A channel username as Telegram allows it: 5-32 letters, digits and underscores. */
	public const CHANNEL_PATTERN = '[A-Za-z][A-Za-z0-9_]{3,31}';

	private const TITLE_MAX = 140;
	private const TITLE_MIN_FIRST_LINE = 20;

	private readonly DOMXPath $xpath;
	private readonly string $channel;

	/** @var array<string,string> */
	private array $labels;

	/**
	 * @param array<string,string> $labels overrides for {@see LABELS}
	 */
	private function __construct(DOMDocument $doc, string $channel, array $labels) {
		$this->xpath = new DOMXPath($doc);
		$this->channel = $channel;
		$this->labels = array_merge(self::LABELS, $labels);
	}

	/** @return non-empty-string */
	public static function previewUrl(string $channel): string {
		return 'https://t.me/s/' . $channel;
	}

	public static function channelUrl(string $channel): string {
		return 'https://t.me/' . $channel;
	}

	/**
	 * @param array<string,string> $labels overrides for {@see LABELS}
	 * @return array{title:string,description:string,image:string,link:string,
	 *   posts:list<array{link:string,title:string,author:string,date:int,html:string,image:string}>}|null
	 *   null when the page is not a public channel preview (unknown name, private channel, a user or bot)
	 */
	public static function parse(string $html, string $channel, array $labels = []): ?array {
		if (trim($html) === '') {
			return null;
		}
		$doc = new DOMDocument();
		$doc->recover = true;
		$doc->strictErrorChecking = false;
		if (!$doc->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT)) {
			return null;
		}
		return (new self($doc, $channel, $labels))->read();
	}

	/**
	 * @return array{title:string,description:string,image:string,link:string,
	 *   posts:list<array{link:string,title:string,author:string,date:int,html:string,image:string}>}|null
	 */
	private function read(): ?array {
		$messages = $this->nodes("//div[@data-post and " . self::cls('tgme_widget_message') . "]");
		if ($messages === [] && $this->first("//div[" . self::cls('tgme_channel_info') . "]") === null) {
			return null;
		}
		$posts = [];
		foreach ($messages as $message) {
			if ($message instanceof DOMElement && !self::hasClass($message, 'service_message')) {
				$post = $this->readPost($message);
				if ($post !== null) {
					$posts[] = $post;
				}
			}
		}
		return [
			'title' => $this->meta('og:title') ?: self::clean($this->text("//div[" . self::cls('tgme_channel_info_header_title') . "]")) ?: $this->channel,
			'description' => $this->meta('og:description'),
			'image' => $this->meta('og:image'),
			'link' => self::channelUrl($this->channel),
			'posts' => $posts,
		];
	}

	/**
	 * @return array{link:string,title:string,author:string,date:int,html:string,image:string}|null
	 */
	private function readPost(DOMElement $message): ?array {
		$id = $message->getAttribute('data-post');   // "<channel>/<number>"
		if (!preg_match('#^[A-Za-z0-9_]+/\d+$#', $id)) {
			return null;
		}
		$link = 'https://t.me/' . $id;
		$bubble = $this->first(".//div[" . self::cls('tgme_widget_message_bubble') . "]", $message) ?? $message;
		$html = '';
		$images = [];

		// Forwarded from another channel
		$forwarded = $this->first(".//div[" . self::cls('tgme_widget_message_forwarded_from') . "]", $bubble);
		if ($forwarded !== null) {
			$source = $this->first(".//a[@href]", $forwarded);
			$name = self::clean($this->text(".//*[" . self::cls('tgme_widget_message_forwarded_from_name') . "]", $forwarded))
				?: self::clean(preg_replace('/^\s*Forwarded from\s*/i', '', $forwarded->textContent) ?? '');
			$html .= '<p>↪️ <em>' . self::esc($this->labels['forwarded']) . '</em> '
				. ($source instanceof DOMElement ? '<a href="' . self::esc($this->absolute($source->getAttribute('href'))) . '">' . self::esc($name) . '</a>' : self::esc($name))
				. '</p>';
		}

		// Reply to an earlier post
		$reply = $this->first(".//a[" . self::cls('tgme_widget_message_reply') . "]", $bubble);
		if ($reply instanceof DOMElement) {
			$author = self::clean($this->text(".//*[" . self::cls('tgme_widget_message_author_name') . "]", $reply));
			$quote = self::clean($this->text(".//*[" . self::cls('tgme_widget_message_metatext') . "]", $reply));
			$html .= '<blockquote><p><a href="' . self::esc($this->absolute($reply->getAttribute('href'))) . '">↩️ ' . self::esc($author) . '</a></p>'
				. ($quote !== '' ? '<p>' . self::esc($quote) . '</p>' : '') . '</blockquote>';
		}

		// Photos, albums, videos, round videos and stickers, in page order
		$media = $this->nodes(".//a[" . self::cls('tgme_widget_message_photo_wrap') . "]"
			. " | .//a[" . self::cls('tgme_widget_message_video_player') . "]"
			. " | .//div[" . self::cls('tgme_widget_message_roundvideo_player') . "]"
			. " | .//div[" . self::cls('tgme_widget_message_sticker_wrap') . "]", $bubble);
		$kinds = [];
		foreach ($media as $item) {
			if (!$item instanceof DOMElement || $this->insideReply($item)) {
				continue;
			}
			$href = $this->absolute($item->getAttribute('href') ?: $link);
			if (self::hasClass($item, 'tgme_widget_message_photo_wrap')) {
				$src = self::backgroundUrl($item->getAttribute('style'));
				if ($src !== '') {
					$images[] = $src;
					$html .= '<p><a href="' . self::esc($href) . '"><img src="' . self::esc($src) . '" alt="" /></a></p>';
					$kinds[] = 'photo';
				}
			} elseif (self::hasClass($item, 'tgme_widget_message_sticker_wrap')) {
				$kinds[] = 'sticker';
				$html .= '<p><a href="' . self::esc($link) . '">' . self::esc($this->labels['sticker']) . '</a></p>';
			} else {
				$round = self::hasClass($item, 'tgme_widget_message_roundvideo_player');
				$thumb = $this->first(".//i[" . self::cls($round ? 'tgme_widget_message_roundvideo_thumb' : 'tgme_widget_message_video_thumb') . "]", $item);
				$src = $thumb instanceof DOMElement ? self::backgroundUrl($thumb->getAttribute('style')) : '';
				$duration = self::clean($this->text(".//time[" . self::cls('message_video_duration') . " or " . self::cls('tgme_widget_message_roundvideo_duration') . "]", $item));
				$note = self::clean($this->text(".//*[" . self::cls('message_media_not_supported_label') . "]", $item));
				$label = '▶️ ' . $this->labels[$round ? 'round_video' : 'video'] . ($duration !== '' && $duration !== '0:00' ? ' ' . $duration : '');
				$html .= '<p>';
				if ($src !== '') {
					$images[] = $src;
					$html .= '<a href="' . self::esc($href) . '"><img src="' . self::esc($src) . '" alt="" /></a><br />';
				}
				$html .= '<a href="' . self::esc($href) . '">' . self::esc($label) . '</a>' . ($note !== '' ? ' <small>(' . self::esc($note) . ')</small>' : '') . '</p>';
				$kinds[] = 'video';
			}
		}

		// Voice messages
		foreach ($this->nodes(".//*[" . self::cls('tgme_widget_message_voice_player') . "]", $bubble) as $voice) {
			$duration = self::clean($this->text(".//time", $voice));
			$html .= '<p>🎤 <a href="' . self::esc($link) . '">' . self::esc($this->labels['voice'] . ($duration !== '' ? ' ' . $duration : '')) . '</a></p>';
			$kinds[] = 'voice';
		}

		// Text
		$textNode = null;
		foreach ($this->nodes(".//div[" . self::cls('tgme_widget_message_text') . "]", $bubble) as $candidate) {
			if (!$this->insideReply($candidate)) {
				$textNode = $candidate;
				break;
			}
		}
		$plain = '';
		if ($textNode instanceof DOMElement) {
			$this->prepareText($textNode);
			$plain = self::plainText($textNode);
			$inner = '';
			foreach ($textNode->childNodes as $child) {
				$part = $textNode->ownerDocument?->saveHTML($child);
				$inner .= is_string($part) ? $part : '';
			}
			if (trim($inner) !== '') {
				$html .= '<div>' . $inner . '</div>';
			}
		}

		// Documents and music
		$fallbackTitle = '';
		foreach ($this->nodes(".//a[" . self::cls('tgme_widget_message_document_wrap') . "]", $bubble) as $document) {
			if (!$document instanceof DOMElement) {
				continue;
			}
			$name = self::clean($this->text(".//*[" . self::cls('tgme_widget_message_document_title') . "]", $document));
			$extra = self::clean($this->text(".//*[" . self::cls('tgme_widget_message_document_extra') . "]", $document));
			$html .= '<p>📎 <a href="' . self::esc($this->absolute($document->getAttribute('href') ?: $link)) . '">' . self::esc($name !== '' ? $name : $this->labels['file']) . '</a>'
				. ($extra !== '' ? ' <small>' . self::esc($extra) . '</small>' : '') . '</p>';
			$fallbackTitle = $fallbackTitle ?: $name;
		}

		// Poll
		$poll = $this->first(".//div[" . self::cls('tgme_widget_message_poll') . "]", $bubble);
		if ($poll !== null) {
			$question = self::clean($this->text(".//*[" . self::cls('tgme_widget_message_poll_question') . "]", $poll));
			$type = self::clean($this->text(".//*[" . self::cls('tgme_widget_message_poll_type') . "]", $poll));
			$html .= '<p><strong>📊 ' . self::esc($question) . '</strong>' . ($type !== '' ? ' <small>' . self::esc($type) . '</small>' : '') . '</p><ul>';
			foreach ($this->nodes(".//*[" . self::cls('tgme_widget_message_poll_option') . "]", $poll) as $option) {
				$percent = self::clean($this->text(".//*[" . self::cls('tgme_widget_message_poll_option_percent') . "]", $option));
				$answer = self::clean($this->text(".//*[" . self::cls('tgme_widget_message_poll_option_text') . "]", $option));
				$html .= '<li>' . self::esc(($percent !== '' ? $percent . ' — ' : '') . $answer) . '</li>';
			}
			$html .= '</ul>';
			$fallbackTitle = $fallbackTitle ?: '📊 ' . $question;
		}

		// Link preview
		$preview = $this->first(".//a[" . self::cls('tgme_widget_message_link_preview') . "]", $bubble);
		if ($preview instanceof DOMElement) {
			$url = $this->absolute($preview->getAttribute('href'));
			$site = self::clean($this->text(".//*[" . self::cls('link_preview_site_name') . "]", $preview));
			$title = self::clean($this->text(".//*[" . self::cls('link_preview_title') . "]", $preview));
			$description = self::clean($this->text(".//*[" . self::cls('link_preview_description') . "]", $preview));
			$picture = $this->first(".//i[" . self::cls('link_preview_image') . " or " . self::cls('link_preview_right_image') . "]", $preview);
			$src = $picture instanceof DOMElement ? self::backgroundUrl($picture->getAttribute('style')) : '';
			$html .= '<blockquote>'
				. ($site !== '' ? '<p><strong>' . self::esc($site) . '</strong></p>' : '')
				. '<p><a href="' . self::esc($url) . '">' . self::esc($title !== '' ? $title : $url) . '</a></p>'
				. ($description !== '' ? '<p>' . self::esc($description) . '</p>' : '')
				. ($src !== '' ? '<p><a href="' . self::esc($url) . '"><img src="' . self::esc($src) . '" alt="" /></a></p>' : '')
				. '</blockquote>';
			if ($src !== '') {
				$images[] = $src;
			}
			$fallbackTitle = $fallbackTitle ?: $title;
		}

		// Buttons under the post
		$buttons = [];
		foreach ($this->nodes(".//a[" . self::cls('tgme_widget_message_inline_button') . "]", $message) as $button) {
			if ($button instanceof DOMElement && ($label = self::clean($button->textContent)) !== '') {
				$buttons[] = '<a href="' . self::esc($this->absolute($button->getAttribute('href') ?: $link)) . '">' . self::esc($label) . '</a>';
			}
		}
		if ($buttons !== []) {
			$html .= '<p>🔘 ' . implode(' · ', $buttons) . '</p>';
		}

		// Content the preview cannot show (e.g. stories, live streams, some media)
		foreach ($this->nodes(".//div[" . self::cls('message_media_not_supported') . "]", $bubble) as $unsupported) {
			if ($this->first("ancestor::a[" . self::cls('tgme_widget_message_video_player') . "]", $unsupported) === null) {
				$note = self::clean($this->text(".//*[" . self::cls('message_media_not_supported_label') . "]", $unsupported));
				$html .= '<p><a href="' . self::esc($link) . '">' . self::esc(($note !== '' ? $note . ' — ' : '') . $this->labels['open']) . '</a></p>';
				$kinds[] = 'unsupported';
			}
		}

		$signature = self::clean($this->text(".//*[" . self::cls('tgme_widget_message_from_author') . "]", $message));
		$owner = self::clean($this->text(".//*[" . self::cls('tgme_widget_message_owner_name') . "]", $message));
		$datetime = $this->first(".//a[" . self::cls('tgme_widget_message_date') . "]//time[@datetime]", $message);
		$date = $datetime instanceof DOMElement ? strtotime($datetime->getAttribute('datetime')) : false;

		return [
			'link' => $link,
			'title' => $this->title($plain, $fallbackTitle, $kinds),
			'author' => $signature !== '' ? $signature : $owner,
			'date' => $date === false ? 0 : $date,
			'html' => $html,
			'image' => $images[0] ?? '',
		];
	}

	/**
	 * First line of the text when it reads like a headline, otherwise the start of the text;
	 * for posts without text, a document / poll / link title or the kind of media.
	 * @param list<string> $kinds
	 */
	private function title(string $plain, string $fallback, array $kinds): string {
		$lines = array_values(array_filter(array_map(static fn(string $line): string => self::clean($line), explode("\n", $plain)),
			static fn(string $line): bool => $line !== ''));
		if ($lines !== []) {
			$first = $lines[0];
			$title = (mb_strlen($first) >= self::TITLE_MIN_FIRST_LINE || count($lines) === 1) ? $first : implode(' ', $lines);
			return self::shorten($title, self::TITLE_MAX);
		}
		if ($fallback !== '') {
			return self::shorten($fallback, self::TITLE_MAX);
		}
		$photos = count(array_keys($kinds, 'photo', true));
		return match (true) {
			$photos > 1 => $this->labels['album'] . ' · ' . $photos,
			$photos === 1 => $this->labels['photo'],
			in_array('video', $kinds, true) => $this->labels['video'],
			in_array('voice', $kinds, true) => $this->labels['voice'],
			in_array('sticker', $kinds, true) => $this->labels['sticker'],
			default => $this->labels['post'],
		};
	}

	/** Emoji images become the emoji character again; relative links become absolute. */
	private function prepareText(DOMElement $text): void {
		foreach ($this->nodes(".//i[" . self::cls('emoji') . "]", $text) as $emoji) {
			$emoji->parentNode?->replaceChild($text->ownerDocument?->createTextNode($emoji->textContent) ?? $emoji, $emoji);
		}
		foreach ($this->nodes(".//a[@href]", $text) as $anchor) {
			if ($anchor instanceof DOMElement) {
				$anchor->setAttribute('href', $this->absolute($anchor->getAttribute('href')));
				$anchor->removeAttribute('onclick');
			}
		}
	}

	/** Text with the line breaks Telegram renders as <br>. */
	private static function plainText(DOMElement $text): string {
		$copy = $text->cloneNode(true);
		if (!$copy instanceof DOMElement || $copy->ownerDocument === null) {
			return $text->textContent;
		}
		foreach (iterator_to_array($copy->getElementsByTagName('br')) as $br) {
			$br->parentNode?->replaceChild($copy->ownerDocument->createTextNode("\n"), $br);
		}
		return $copy->textContent;
	}

	private function absolute(string $href): string {
		$href = trim($href);
		if ($href === '') {
			return '';
		}
		if (str_starts_with($href, '?')) {   // hashtags and searches: ?q=%23tag
			return self::previewUrl($this->channel) . $href;
		}
		if (str_starts_with($href, '//')) {
			return 'https:' . $href;
		}
		if (str_starts_with($href, '/')) {
			return 'https://t.me' . $href;
		}
		return $href;
	}

	private function insideReply(DOMNode $node): bool {
		return $this->first("ancestor::a[" . self::cls('tgme_widget_message_reply') . "]", $node) !== null;
	}

	private function meta(string $property): string {
		$node = $this->first("//meta[@property='" . $property . "']/@content");
		return $node === null ? '' : self::clean($node->nodeValue ?? '');
	}

	/** @return list<DOMNode> */
	private function nodes(string $expression, ?DOMNode $context = null): array {
		$list = @$this->xpath->query($expression, $context);
		if ($list === false) {
			return [];
		}
		$nodes = [];
		foreach ($list as $node) {
			if ($node instanceof DOMNode) {
				$nodes[] = $node;
			}
		}
		return $nodes;
	}

	private function first(string $expression, ?DOMNode $context = null): ?DOMNode {
		return $this->nodes($expression, $context)[0] ?? null;
	}

	private function text(string $expression, ?DOMNode $context = null): string {
		return $this->first($expression, $context)->textContent ?? '';
	}

	/** XPath test for one class name among several. */
	private static function cls(string $class): string {
		return "contains(concat(' ', normalize-space(@class), ' '), ' " . $class . " ')";
	}

	private static function hasClass(DOMElement $element, string $class): bool {
		return in_array($class, preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [], true);
	}

	/** URL of `background-image:url('…')` in a style attribute; empty for none or inline data. */
	private static function backgroundUrl(string $style): string {
		if (!preg_match('/background-image\s*:\s*url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', $style, $matches)) {
			return '';
		}
		$url = trim($matches[1]);
		if (str_starts_with($url, '//')) {
			$url = 'https:' . $url;
		}
		return preg_match('#^https?://#i', $url) ? $url : '';
	}

	private static function clean(string $text): string {
		return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
	}

	private static function shorten(string $text, int $max): string {
		if (mb_strlen($text) <= $max) {
			return $text;
		}
		$cut = mb_substr($text, 0, $max);
		$space = mb_strrpos($cut, ' ');
		if ($space !== false && $space > $max * 0.6) {
			$cut = mb_substr($cut, 0, $space);
		}
		return rtrim($cut, " \t,;:.-–—") . '…';
	}

	private static function esc(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
	}
}
