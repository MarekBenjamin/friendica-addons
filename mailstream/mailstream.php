<?php
/**
 * Name: Mail Stream
 * Description: Mail all items coming into your network feed to an email address
 * Version: 2.0
 * Author: Matthew Exon <http://mat.exon.name>
 */

use Friendica\Content\Text\BBCode;
use Friendica\Core\Hook;
use Friendica\Core\Logger;
use Friendica\Core\Renderer;
use Friendica\Core\System;
use Friendica\Core\Worker;
use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Model\Contact;
use Friendica\Model\Post;
use Friendica\Model\User;
use Friendica\Network\HTTPClient\Client\HttpClientAccept;
use Friendica\Network\HTTPClient\Client\HttpClientOptions;
use Friendica\Protocol\Activity;

/**
 * Sets up the addon hooks and the database table
 */
function mailstream_install()
{
	Hook::register('addon_settings', 'addon/mailstream/mailstream.php', 'mailstream_addon_settings');
	Hook::register('addon_settings_post', 'addon/mailstream/mailstream.php', 'mailstream_addon_settings_post');
	Hook::register('post_local_end', 'addon/mailstream/mailstream.php', 'mailstream_post_hook');
	Hook::register('post_remote_end', 'addon/mailstream/mailstream.php', 'mailstream_post_hook');
	Hook::register('mailstream_send_hook', 'addon/mailstream/mailstream.php', 'mailstream_send_hook');

	Logger::info("installed mailstream");
}

/**
 * This is a statement rather than an actual function definition. The simple
 * existence of this method is checked to figure out if the addon offers a
 * module.
 */
function mailstream_module() {}

/**
 * Adds an item in "addon features" in the admin menu of the site
 *
 * @param string        $o HTML form data
 */
function mailstream_addon_admin(string &$o)
{
	$frommail = DI::config()->get('mailstream', 'frommail');
	$template = Renderer::getMarkupTemplate('admin.tpl', 'addon/mailstream/');
	$config = [
		'frommail',
		DI::l10n()->t('From Address'),
		$frommail,
		DI::l10n()->t('Email address that stream items will appear to be from.')
	];
	$o .= Renderer::replaceMacros($template, [
		'$frommail' => $config,
		'$submit' => DI::l10n()->t('Save Settings')
	]);
}

/**
 * Process input from the "addon features" part of the admin menu
 */
function mailstream_addon_admin_post()
{
	if (!empty($_POST['frommail'])) {
		DI::config()->set('mailstream', 'frommail', $_POST['frommail']);
	}
}

/**
 * Creates a message ID for a post URI in accordance with RFC 1036
 * See also http://www.jwz.org/doc/mid.html
 *
 * @param string $uri the URI to be converted to a message ID
 *
 * @return string the created message ID
 */
function mailstream_generate_id(string $uri): string
{
	$host = DI::baseUrl()->getHost();
	$resource = hash('md5', $uri);
	$message_id = "<" . $resource . "@" . $host . ">";
	Logger::debug('generated message ID', ['id' => $message_id, 'uri' => $uri]);
	return $message_id;
}

function mailstream_send_hook(array $data)
{
	$criteria = array('uid' => $data['uid'], 'contact-id' => $data['contact-id'], 'uri' => $data['uri']);
	$item = Post::selectFirst([], $criteria);
	if (empty($item)) {
		Logger::error('could not find item');
		return;
	}

	$user = User::getById($item['uid']);
	if (empty($user)) {
		Logger::error('could not find user', ['uid' => $item['uid']]);
		return;
	}

	if (!mailstream_send($data['message_id'], $item, $user)) {
		Logger::debug('send failed, will retry', $data);
		if (!Worker::defer()) {
			Logger::error('failed and could not defer', $data);
		}
	}
}

/**
 * Called when either a local or remote post is created.  If
 * mailstream is enabled and the necessary data is available, forks a
 * workerqueue item to send the email.
 *
 * @param array     $item content of the item (may or may not already be stored in the item table)
 * @return void
 */
function mailstream_post_hook(array &$item)
{
	if ($item['uid'] === 0) {
		Logger::debug('mailstream: root user, skipping item ' . $item['id']);
		return;
	}
	if (!DI::pConfig()->get($item['uid'], 'mailstream', 'enabled')) {
		Logger::debug('mailstream: not enabled.', ['item' => $item['id'], ' uid ' => $item['uid']]);
		return;
	}
	if (!$item['contact-id']) {
		Logger::debug('no contact-id', ['item' => $item['id']]);
		return;
	}
	if (!$item['uri']) {
		Logger::debug('no uri', ['item' => $item['id']]);
		return;
	}
	if ($item['verb'] == Activity::ANNOUNCE) {
		Logger::debug('ignoring announce', ['item' => $item['id']]);
		return;
	}
	if (DI::pConfig()->get($item['uid'], 'mailstream', 'nolikes')) {
		if ($item['verb'] == Activity::LIKE) {
			Logger::debug('ignoring like', ['item' => $item['id']]);
			return;
		}
		if ($item['verb'] == Activity::DISLIKE) {
			Logger::debug('ignoring dislike', ['item' => $item['id']]);
			return;
		}
	}

	$message_id = mailstream_generate_id($item['uri']);

	$send_hook_data = [
		'uid' => $item['uid'],
		'contact-id' => $item['contact-id'],
		'uri' => $item['uri'],
		'message_id' => $message_id,
		'tries' => 0,
	];
	Hook::fork(Worker::PRIORITY_LOW, 'mailstream_send_hook', $send_hook_data);
}

/**
 * If the user has configured attaching images to emails as
 * attachments, this function searches the post for such images,
 * retrieves the image, and inserts the data and metadata into the
 * supplied array
 *
 * @param array         $item        content of the item
 * @param array         $attachments contains an array element for each attachment to add to the email
 *
 * @return array new value of the attachments table (results are also stored in the reference parameter)
 */
function mailstream_do_images(array &$item, array &$attachments)
{
	if (!DI::pConfig()->get($item['uid'], 'mailstream', 'attachimg')) {
		return;
	}

	$attachments = [];

	preg_match_all("/\[img\=([0-9]*)x([0-9]*)\](.*?)\[\/img\]/ism", $item["body"], $matches1);
	preg_match_all("/\[img\](.*?)\[\/img\]/ism", $item["body"], $matches2);
	preg_match_all("/\[img\=([^\]]*)\]([^[]*)\[\/img\]/ism", $item["body"], $matches3);

	foreach (array_merge($matches1[3], $matches2[1], $matches3[1]) as $url) {
		$components = parse_url($url);

		if (!$components) {
			continue;
		}

		$cookiejar = tempnam(System::getTempPath(), 'cookiejar-mailstream-');
		try {
			$curlResult = DI::httpClient()->get($url, HttpClientAccept::DEFAULT, [HttpClientOptions::COOKIEJAR => $cookiejar]);
			if (!$curlResult->isSuccess()) {
				Logger::debug('mailstream: fetch image url failed', [
					'url' => $url,
					'item_id' => $item['id'],
					'return_code' => $curlResult->getReturnCode()
				]);
				continue;
			}
		} catch (InvalidArgumentException $e) {
			Logger::error('exception fetching url', ['url' => $url, 'item_id' => $item['id']]);
			continue;
		}
		$attachments[$url] = [
			'data' => $curlResult->getBodyString(),
			'guid' => hash('crc32', $url),
			'filename' => basename($components['path']),
			'type' => $curlResult->getContentType()
		];

		if (strlen($attachments[$url]['data'])) {
			$item['body'] = str_replace($url, 'cid:' . $attachments[$url]['guid'], $item['body']);
			continue;
		}
	}

	return $attachments;
}

/**
 * Creates a sender to use in the email, either from the contact or the author of the item, or both
 *
 * @param array $item content of the item
 *
 * @return string sender suitable for use in the email
 */
function mailstream_sender(array $item): string
{
	$contact = Contact::getById($item['contact-id']);
	if (DBA::isResult($contact)) {
		if ($contact['name'] != $item['author-name']) {
			return $contact['name'] . ' - ' . $item['author-name'];
		}
	}
	return $item['author-name'];
}

/**
 * Converts a bbcode-encoded subject line into a plaintext version suitable for the subject line of an email
 *
 * @param string $subject bbcode-encoded subject line
 * @param int    $uri_id
 *
 * @return string plaintext subject line
 */
function mailstream_decode_subject(string $subject, int $uri_id): string
{
	$html = BBCode::convertForUriId($uri_id, $subject);
	if (!$html) {
		return $subject;
	}
	$notags = strip_tags($html);
	if (!$notags) {
		return $subject;
	}
	$noentity = html_entity_decode($notags);
	if (!$noentity) {
		return $notags;
	}
	$nocodes = preg_replace_callback("/(&#[0-9]+;)/", function ($m) {
		return mb_convert_encoding($m[1], 'UTF-8', 'HTML-ENTITIES');
	}, $noentity);
	if (!$nocodes) {
		return $noentity;
	}
	$trimmed = trim($nocodes);
	if (!$trimmed) {
		return $nocodes;
	}
	return $trimmed;
}

/**
 * Creates a subject line to use in the email
 *
 * @param array $item content of the item
 *
 * @return string subject line suitable for use in the email
 */
function mailstream_subject(array $item): string
{
	if ($item['title']) {
		return mailstream_decode_subject($item['title'], $item['uri-id']);
	}
	$parent = $item['thr-parent'];
	// Don't look more than 100 levels deep for a subject, in case of loops
	for ($i = 0; ($i < 100) && $parent; $i++) {
		$parent_item = Post::selectFirst(['thr-parent', 'title'], ['uri' => $parent]);
		if (!DBA::isResult($parent_item)) {
			break;
		}
		if ($parent_item['thr-parent'] === $parent) {
			break;
		}
		if ($parent_item['title']) {
			return DI::l10n()->t('Re:') . ' ' . mailstream_decode_subject($parent_item['title'], $item['uri-id']);
		}
		$parent = $parent_item['thr-parent'];
	}
	$contact = Contact::selectFirst([], ['id' => $item['contact-id'], 'uid' => $item['uid']]);
	if (!DBA::isResult($contact)) {
		Logger::error('no contact', [
			'item' => $item['id'],
			'plink' => $item['plink'],
			'contact id' => $item['contact-id'],
			'uid' => $item['uid']
		]);
		return DI::l10n()->t("Friendica post");
	}
	if ($contact['network'] === 'dfrn') {
		return DI::l10n()->t("Friendica post");
	}
	if ($contact['network'] === 'dspr') {
		return DI::l10n()->t("Diaspora post");
	}
	if ($contact['network'] === 'face') {
		$text = mailstream_decode_subject($item['body'], $item['uri-id']);
		// For some reason these do show up in Facebook
		$text = preg_replace('/\xA0$/', '', $text);
		$subject = (strlen($text) > 150) ? (substr($text, 0, 140) . '...') : $text;
		return preg_replace('/\\s+/', ' ', $subject);
	}
	if ($contact['network'] === 'feed') {
		return DI::l10n()->t("Feed item");
	}
	if ($contact['network'] === 'mail') {
		return DI::l10n()->t("Email");
	}
	return DI::l10n()->t("Friendica Item");
}

/**
 * Sends a message using PHPMailer
 *
 * @param string $message_id ID of the message (RFC 1036)
 * @param array  $item       content of the item
 * @param array  $user       results from the user table
 *
 * @return bool True if this message has been completed.  False if it should be retried.
 */
function mailstream_send(string $message_id, array $item, array $user): bool
{
	if (!is_array($item)) {
		Logger::error('item is empty', ['message_id' => $message_id]);
		return false;
	}

	if (!$item['visible']) {
		Logger::debug('item not yet visible', ['item uri' => $item['uri']]);
		return false;
	}
	if (!$message_id) {
		Logger::error('no message ID supplied', ['item uri' => $item['uri'], 'user email' => $user['email']]);
		return true;
	}

	require_once(dirname(__file__) . '/phpmailer/class.phpmailer.php');

	$item['body'] = Post\Media::addAttachmentsToBody($item['uri-id'], $item['body']);

	$attachments = [];
	mailstream_do_images($item, $attachments);
	$frommail = DI::config()->get('mailstream', 'frommail');
	if ($frommail == '') {
		$frommail = 'friendica@localhost.local';
	}
	$address = DI::pConfig()->get($item['uid'], 'mailstream', 'address');
	if (!$address) {
		$address = $user['email'];
	}
	$mail = new PHPMailer();
	try {
		$mail->XMailer = 'Friendica Mailstream Addon';
		$mail->SetFrom($frommail, mailstream_sender($item));
		$mail->AddAddress($address, $user['username']);
		$mail->MessageID = $message_id;
		$mail->Subject = mailstream_subject($item);
		if ($item['thr-parent'] != $item['uri']) {
			$mail->addCustomHeader('In-Reply-To: ' . mailstream_generate_id($item['thr-parent']));
		}
		$mail->addCustomHeader('X-Friendica-Mailstream-URI: ' . $item['uri']);
		if ($item['plink']) {
			$mail->addCustomHeader('X-Friendica-Mailstream-Plink: ' . $item['plink']);
		}
		$encoding = 'base64';
		foreach ($attachments as $url => $image) {
			$mail->AddStringEmbeddedImage(
				$image['data'],
				$image['guid'],
				$image['filename'],
				$encoding,
				$image['type']
			);
		}
		$mail->IsHTML(true);
		$mail->CharSet = 'utf-8';
		$template = Renderer::getMarkupTemplate('mail.tpl', 'addon/mailstream/');
		$mail->AltBody = BBCode::toPlaintext($item['body']);
		$item['body'] = BBCode::convertForUriId($item['uri-id'], $item['body'], BBCode::CONNECTORS);
		$item['url'] = DI::baseUrl() . '/display/' . $item['guid'];
		$mail->Body = Renderer::replaceMacros($template, [
			'$upstream' => DI::l10n()->t('Upstream'),
			'$uri' => DI::l10n()->t('URI'),
			'$local' => DI::l10n()->t('Local'),
			'$item' => $item
		]);
		$mail->Body = mailstream_html_wrap($mail->Body);
		if (!$mail->Send()) {
			throw new Exception($mail->ErrorInfo);
		}
		Logger::debug('sent message', [
			'message ID' => $mail->MessageID,
			'subject' => $mail->Subject,
			'address' => $address
		]);
	} catch (phpmailerException $e) {
		Logger::debug('PHPMailer exception sending message', ['id' => $message_id, 'error' => $e->errorMessage()]);
	} catch (Exception $e) {
		Logger::debug('exception sending message', ['id' => $message_id, 'error' => $e->getMessage()]);
	}

	return true;
}

/**
 * Email tends to break if you send excessively long lines.  To make
 * bbcode's output suitable for transmission, we try to break things
 * up so that lines are about 200 characters.
 *
 * @param string $text text to word wrap
 * @return string wrapped text
 */
function mailstream_html_wrap(string &$text)
{
	$lines = str_split($text, 200);
	for ($i = 0; $i < count($lines); $i++) {
		$lines[$i] = preg_replace('/ /', "\n", $lines[$i], 1);
	}
	$text = implode($lines);
	return $text;
}

/**
 * Form for configuring mailstream features for a user
 *
 * @param array $data Hook data array
 * @throws \Friendica\Network\HTTPException\ServiceUnavailableException
 */
function mailstream_addon_settings(array &$data)
{
	$enabled   = DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'mailstream', 'enabled');
	$address   = DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'mailstream', 'address');
	$nolikes   = DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'mailstream', 'nolikes');
	$attachimg = DI::pConfig()->get(DI::userSession()->getLocalUserId(), 'mailstream', 'attachimg');

	$template  = Renderer::getMarkupTemplate('settings.tpl', 'addon/mailstream/');
	$html      = Renderer::replaceMacros($template, [
		'$enabled'   => [
			'mailstream_enabled',
			DI::l10n()->t('Enabled'),
			$enabled
		],
		'$address'   => [
			'mailstream_address',
			DI::l10n()->t('Email Address'),
			$address,
			DI::l10n()->t('Leave blank to use your account email address')
		],
		'$nolikes'   => [
			'mailstream_nolikes',
			DI::l10n()->t('Exclude Likes'),
			$nolikes,
			DI::l10n()->t('Check this to omit mailing "Like" notifications')
		],
		'$attachimg' => [
			'mailstream_attachimg',
			DI::l10n()->t('Attach Images'),
			$attachimg,
			DI::l10n()->t('Download images in posts and attach them to the email.  ' .
				'Useful for reading email while offline.')
		],
	]);

	$data = [
		'addon' => 'mailstream',
		'title' => DI::l10n()->t('Mail Stream Settings'),
		'html'  => $html,
	];
}

/**
 * Process data submitted to user's mailstream features form
 * @param array          $post POST data
 * @return void
 */
function mailstream_addon_settings_post(array $post)
{
	if (!DI::userSession()->getLocalUserId() || empty($post['mailstream-submit'])) {
		return;
	}

	if ($post['mailstream_address'] != "") {
		DI::pConfig()->set(DI::userSession()->getLocalUserId(), 'mailstream', 'address', $post['mailstream_address']);
	} else {
		DI::pConfig()->delete(DI::userSession()->getLocalUserId(), 'mailstream', 'address');
	}
	if ($post['mailstream_nolikes']) {
		DI::pConfig()->set(DI::userSession()->getLocalUserId(), 'mailstream', 'nolikes', $post['mailstream_enabled']);
	} else {
		DI::pConfig()->delete(DI::userSession()->getLocalUserId(), 'mailstream', 'nolikes');
	}
	if ($post['mailstream_enabled']) {
		DI::pConfig()->set(DI::userSession()->getLocalUserId(), 'mailstream', 'enabled', $post['mailstream_enabled']);
	} else {
		DI::pConfig()->delete(DI::userSession()->getLocalUserId(), 'mailstream', 'enabled');
	}
	if ($post['mailstream_attachimg']) {
		DI::pConfig()->set(DI::userSession()->getLocalUserId(), 'mailstream', 'attachimg', $post['mailstream_attachimg']);
	} else {
		DI::pConfig()->delete(DI::userSession()->getLocalUserId(), 'mailstream', 'attachimg');
	}
}
