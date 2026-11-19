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
</div>
