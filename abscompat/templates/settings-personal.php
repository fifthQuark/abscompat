<?php
/** @var \OCP\IL10N $l */
?>
<div id="abscompat-settings" class="section abscompat">
	<h2><?php p($l->t('Audiobookshelf')); ?></h2>
	<p class="settings-hint"><?php p($l->t('Listen to the audiobooks in your files with the Audiobookshelf app or another compatible player, and sync your position with CrossPoint Sync.')); ?></p>

	<h3><?php p($l->t('Connect an app')); ?></h3>
	<div class="abscompat-row">
		<code id="abscompat-server-url" class="abscompat-url"></code>
		<button type="button" class="button" data-copy="#abscompat-server-url"><?php p($l->t('Copy')); ?></button>
	</div>
	<ul class="abscompat-steps">
		<li><?php p($l->t('Audiobookshelf app: add this address as a new server and log in with your Nextcloud account name and password.')); ?></li>
		<li><?php p($l->t('If your account uses two-factor authentication, log in with an app password from Security settings instead.')); ?></li>
		<li><?php p($l->t('CrossPoint Sync: choose Audiobookshelf, enter this address, and paste an API key created below.')); ?></li>
	</ul>

	<h3><?php p($l->t('Libraries')); ?></h3>
	<p class="settings-hint"><?php p($l->t('Each folder becomes a library. Put each book in its own folder, e.g. Author/Title or Author/Series/1 - Title. A single audio file directly in the folder is also a book.')); ?></p>
	<p id="abscompat-default-note" class="settings-hint" hidden></p>
	<ul id="abscompat-libraries" class="abscompat-list" aria-live="polite"></ul>
	<div class="abscompat-row">
		<button type="button" id="abscompat-add-folder" class="button"><?php p($l->t('Add folder')); ?></button>
		<button type="button" id="abscompat-reset-folders" class="button" hidden><?php p($l->t('Use default folders')); ?></button>
		<button type="button" id="abscompat-rescan" class="button primary"><?php p($l->t('Rescan')); ?></button>
		<span id="abscompat-scan-status" class="abscompat-status" role="status"></span>
	</div>

	<h3><?php p($l->t('Streaming')); ?></h3>
	<p class="settings-hint"><?php p($l->t('Off by default: apps download a book once and play it from the device. With streaming on, the Audiobookshelf app can also play books that are not downloaded.')); ?></p>
	<div class="abscompat-warning">
		<p><strong><?php p($l->t('Before you turn it on:')); ?></strong></p>
		<ul>
			<li><?php p($l->t('Bandwidth: every listen sends the audio from this server again, about 30 to 60 MB per hour, up to 150 MB for high quality files, and it counts as mobile data on the phone.')); ?></li>
			<li><?php p($l->t('Server load: while a book plays, it can keep one of the server\'s processes busy for a long time. On small or shared hosting, Nextcloud may become slow or stop answering for everyone.')); ?></li>
			<li><?php p($l->t('Your hosting provider: some limit traffic or long downloads, or do not allow media streaming in their terms. They may slow down, charge for or suspend an account. Check this first.')); ?></li>
		</ul>
	</div>
	<p id="abscompat-streaming-forbidden" class="settings-hint" hidden><?php p($l->t('The administrator of this Nextcloud does not allow streaming.')); ?></p>
	<p id="abscompat-streaming-row">
		<input type="checkbox" id="abscompat-streaming" class="checkbox">
		<label for="abscompat-streaming"><?php p($l->t('Allow my apps to stream')); ?></label>
	</p>
	<p class="settings-hint"><?php p($l->t('Voice uses the same address for downloading and streaming, so this switch does not stop it from streaming. Use its downloads instead.')); ?></p>

	<div id="abscompat-opds" hidden>
		<h3><?php p($l->t('Ebooks from OPDS+')); ?></h3>
		<p class="settings-hint"><?php p($l->t('OPDS+ is installed. Its book list is only read here; nothing in OPDS+ is changed.')); ?></p>
		<p>
			<input type="checkbox" id="abscompat-opds-links" class="checkbox">
			<label for="abscompat-opds-links"><?php p($l->t('Add the matching ebook to audiobooks that have none (same title and author)')); ?></label>
		</p>
		<p>
			<input type="checkbox" id="abscompat-opds-library" class="checkbox">
			<label for="abscompat-opds-library"><?php p($l->t('Show the other ebooks as a library of their own')); ?></label>
		</p>
	</div>

	<h3><?php p($l->t('API keys')); ?></h3>
	<p class="settings-hint"><?php p($l->t('For services that use a key instead of logging in, such as CrossPoint Sync. A key gives access to your audiobooks and listening progress, not to your other files.')); ?></p>
	<form id="abscompat-key-form" class="abscompat-row">
		<label for="abscompat-key-name" class="hidden-visually"><?php p($l->t('Key name')); ?></label>
		<input type="text" id="abscompat-key-name" placeholder="<?php p($l->t('Name, e.g. CrossPoint Sync')); ?>" maxlength="100">
		<button type="submit" class="button"><?php p($l->t('Create API key')); ?></button>
	</form>
	<div id="abscompat-new-key" class="abscompat-newkey" hidden>
		<p><strong><?php p($l->t('Copy this key now. It will not be shown again.')); ?></strong></p>
		<div class="abscompat-row">
			<code id="abscompat-new-key-value"></code>
			<button type="button" class="button" data-copy="#abscompat-new-key-value"><?php p($l->t('Copy')); ?></button>
		</div>
	</div>

	<h3><?php p($l->t('Signed-in devices and keys')); ?></h3>
	<table id="abscompat-tokens" class="grid">
		<thead><tr>
			<th scope="col"><?php p($l->t('Name')); ?></th>
			<th scope="col"><?php p($l->t('Type')); ?></th>
			<th scope="col"><?php p($l->t('Created')); ?></th>
			<th scope="col"><?php p($l->t('Last used')); ?></th>
			<th scope="col"><span class="hidden-visually"><?php p($l->t('Actions')); ?></span></th>
		</tr></thead>
		<tbody></tbody>
	</table>
	<p id="abscompat-no-tokens" class="settings-hint" hidden><?php p($l->t('No devices signed in and no API keys yet.')); ?></p>
	<div class="abscompat-row">
		<button type="button" id="abscompat-revoke-devices" class="button" hidden><?php p($l->t('Sign out all devices')); ?></button>
	</div>
</div>
