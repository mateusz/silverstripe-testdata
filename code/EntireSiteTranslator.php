<?php
/**
 * This helper can translate the entire site using ROT13. 
 * All Text, Varchar and HTMLText fields on all SiteTree objects will be "translated".
 */
class EntireSiteTranslator extends Controller {

	static $allowed_actions = array(
		'index',
		'translate',
		'TranslationForm'
	);

	function init() {
		parent::init();

		if (!class_exists('Translatable')) {
			echo "Translatable is not present on this site.<br/>\n";
			exit;
		}

		// Basic access check.
		$canAccess = (Director::isDev() || Director::is_cli() || Permission::check("ADMIN"));
		if(!$canAccess) return Security::permissionFailure($this);
	}

	/**
	 * Builds the entry form so the user can choose what to export.
	 */
	function TranslationForm() {
		$fields = new FieldList();

		$fields->push(new LanguageDropdownField('From', 'From language'));
		$fields->push(new LanguageDropdownField('To', 'To language'));
	
		// Create actions for the form
		$actions = new FieldList(new FormAction("translate", "Translate"));

		return new Form($this, "TranslationForm", $fields, $actions);
	}

	function fakeTranslation($object) {
		// Find relational and meta fields we are not interested in writing right now.
		$noninterestingFields = array('ID', 'Created', 'LastEdited', 'ClassName', 'RecordClassName', 'YMLTag', 'Version', 'URLSegment');
		foreach (array_keys($object->has_one()) as $relation) {
			array_push($noninterestingFields, $relation.'ID');
		}

		// Write fields.
		$modifications = 0;
		foreach ($object->toMap() as $field => $value) {
			if (in_array($field, $noninterestingFields)) continue;
			// Skip non-textual fields
			$dbField = $object->obj($field);
			if (!is_object($dbField) || !($dbField instanceof DBField)) continue;
			$class = get_class($dbField);
			if (!in_array($class, array('Varchar', 'HTMLText', 'Text'))) continue;

			if ($class=='HTMLText') {
				$object->$field = "<p>".nl2br(str_rot13(strip_tags($object->$field)))."</p>";
			}
			else {
				$object->$field = str_rot13($object->$field);
			}
		}

		return $object;
	}

	/**
	 */
	function translate($data, $form) {
		increase_time_limit_to(600);

		// Simple validation
		if (!isset($data['From']) || !$data['From']) {
			echo "Specify origin language.";
			exit;
		}

		if (!isset($data['To']) || !$data['To']) {
			echo "Specify destination language.";
			exit;
		}

		$data['From'] = i18n::get_locale_from_lang($data['From']);
		$data['To'] = i18n::get_locale_from_lang($data['To']);
		$locales = array_keys(i18n::get_locale_list());
		if (!in_array($data['From'], $locales)) {
			echo "Origin language invalid.";
			exit;
		}

		if (!in_array($data['To'], $locales)) {
			echo "Destination language invalid.";
			exit;
		}

		if ($data['From']==$data['To']) {
			echo "Origin and destination languages are the same.";
			exit;
		}

		// We want to work off Draft, because that's what's visible in the CMS.
		Versioned::reading_stage('Stage');

		Translatable::set_current_locale($data['From']);
		$pages = SiteTree::get();

		// Remove the target locale's site.
		SiteTree::get()->filter('Locale', $data['To'])->removeAll();

		foreach ($pages as $page) {
			echo "Now processing $page->ID<br/>\n";

			// Start from the highest parent for each page - otherwise the parents are not translated properly.
			$stack = array();
			$parent = $page;
			while($parent && $parent->ID) {
				array_unshift($stack, $parent);
				$parent = $parent->Parent();
			}

			// We will hit the same pages multiple times, but this is an easiest way to do it and it's just a tool.
			foreach ($stack as $stackPage) {
				$translation = $stackPage->getTranslation($data['To']);
				if ($translation && $translation->ID) {
					// Skip pages that have already been translated.
					echo "$stackPage->ID: exists, skipping.<br/>\n";
				}
				else {
					$translation = $stackPage->createTranslation($data['To']);
					$this->fakeTranslation($translation);
					$translation->write();
					echo "$stackPage->ID: translated.<br/>\n";
				}
			}
		}
	}
}

