<?php
/** @var \OCP\IL10N $l */
?>
<div id="abscompat-admin" class="section abscompat">
	<h2><?php p($l->t('Audiobookshelf')); ?></h2>
	<p class="settings-hint"><?php p($l->t('Default library folders for users who have not chosen their own, one per line, relative to each user\'s files. Users can change theirs in their personal settings.')); ?></p>
	<label for="abscompat-default-folders" class="hidden-visually"><?php p($l->t('Default library folders')); ?></label>
	<textarea id="abscompat-default-folders" rows="3" spellcheck="false"></textarea>
	<div class="abscompat-row">
		<button type="button" id="abscompat-save-defaults" class="button primary"><?php p($l->t('Save')); ?></button>
		<span id="abscompat-admin-status" class="abscompat-status" role="status"></span>
	</div>
	<p>
		<input type="checkbox" id="abscompat-allow-streaming" class="checkbox">
		<label for="abscompat-allow-streaming"><?php p($l->t('Users may turn on streaming')); ?></label>
	</p>
	<p class="settings-hint"><?php p($l->t('Streaming is off for every user until they turn it on. It costs bandwidth for every listen and can keep a server process busy while a book plays. Some hosting providers limit or forbid it.')); ?></p>
</div>
