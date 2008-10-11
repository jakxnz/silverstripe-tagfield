<?php
/**
 * Provides a Formfield for saving a string of tags into either
 * a many_many relationship or a text property.
 * By default, tags are separated by whitespace.
 *
 * Features:
 * - Bundled with jQuery-based autocomplete library which is applied to a textfield
 * - Autosuggest functionality (currently JSON only)
 * 
 * Example Datamodel and Instanciation:
 * <code>
 * class Article extends DataObject {
 * 	static $many_many = array('Tags'=>'Tag');
 * }
 * class Tag extends DataObject {
 * 	static $db = array('Title'=>'Varchar');
 * 	static $belongs_many_many = array('Articles'=>'Article');
 * }
 * </code>
 * <code>
 * $form = new Form($this,'Form',
 * 	new FieldSet(
 * 		new TagField('Tags', 'My Tags', null, 'Article')
 *	)
 * 	new FieldSet()
 * );
 * $form->loadDataFrom($myArticle);
 * $form->saveInto($myArticle);
 * </code>
 * 
 * @author Ingo Schommer, SilverStripe Ltd. (<firstname>@silverstripe.com)
 * @package formfields
 * @subpackage tagfield
 */
class TagField extends TextField {
	
	/**
	 * @var string $tagTextbasedClass The DataObject class with a text property
	 * or many_many relation matching the name of the Field.
	 */
	protected $tagTopicClass;
	
	/**
	 * @var string $tagObjectField Only applies to object-based tagging.
	 * The fieldname for textbased tagging is inferred from the formfield name.
	 */
	protected $tagObjectField = 'Title';
	
	/**
	 * @var string $tagFilter
	 */
	protected $tagFilter;
	
	/**
	 * @var string $tagSort If {@link suggest()} finds multiple matches, in which order should they
	 * be presented.
	 */
	protected $tagSort;
	
	/**
	 * @var $separator Determines on which character to split tags in a string.
	 */
	protected $separator = ' ';
	
	protected static $separator_to_regex = array(
		' ' => '\s',
	);
	
	/**
	 * @var array $customTags Override the tagging behaviour with a custom set
	 * which is used by the javascript library directly instead of querying {@link suggest()}.
	 */
	protected $customTags;
	
	function __construct($name, $title = null, $value = null, $tagTopicClass = null) {
		$this->tagTopicClass = $tagTopicClass;
		
		parent::__construct($name, $title, $value);
	}
	
	public function Field() {
		Requirements::javascript(THIRDPARTY_DIR . "/jquery/jquery.js");
		Requirements::javascript(THIRDPARTY_DIR . "/jquery/jquery_improvements.js");
		Requirements::javascript("tagfield/javascript/jquery.tags.js");
		Requirements::css("tagfield/css/TagField.css");

		if($this->customTags) {
			Requirements::customScript("jQuery(document).ready(function() {
				jQuery('#" . $this->id() . "').tagSuggest({
					tags: " . Convert::raw2json($this->customTags) . "
				});
			});");
		} else {
			Requirements::customScript("jQuery(document).ready(function() {
				jQuery('#" . $this->id() . "').tagSuggest({
				    url: '" . parse_url($this->Link(),PHP_URL_PATH) . "/suggest',
					separator: '" . $this->separator . "'
				});
			});");
		}
		
		return parent::Field();
	}
	
	/**
	 * Helper for autocompletion in javascript library.
	 * 
	 * @return string JSON array
	 */
	public function suggest($request) {
		$tagTopicClassObj = singleton($this->tagTopicClass);
		
		$searchString = $request->requestVar('tag');
		
		if($this->customTags) {
			$tags = $this->customTags;
		} else if($tagTopicClassObj->many_many($this->Name())) {
			$tags = $this->getObjectTags($searchString);
		} else if($tagTopicClassObj->hasField($this->Name())) {
			$tags = $this->getTextbasedTags($searchString);
		} else {
			user_error('TagField::suggest(): Cant find valid relation or text property with name "' . $this->Name() . '"', E_USER_ERROR);
		}
		
		return Convert::raw2json($tags);
	}
	
	function saveInto($record) {		
		if($this->value) {
			// $record should match the $tagTopicClass
			if($record->many_many($this->Name())) {
				$this->saveIntoObjectTags($record);
			} elseif($record->hasField($this->Name())) {
				$this->saveIntoTextbasedTags($record);
			} else {
				user_error('TagField::saveInto(): Cant find valid field or relation to save into', E_USER_ERROR);
			}
		}
	}
	
	function setValue($value, $obj = null) {
		if(isset($obj) && is_object($obj) && $obj instanceof DataObject) {
			if(!$obj->many_many($this->Name())) user_error("TagField::setValue(): Cant find relationship named '$this->Name()' on object", E_USER_ERROR);
			$tags = $obj->{$this->Name()}();
			$this->value = implode($this->separator, array_values($tags->map('ID',$this->tagObjectField)));
		} else {
			parent::setValue($value, $obj);
		}
	}
	
	/**
	 * @param string $class Classname of the DataObject which contains
	 * the relation or field for tags.
	 */
	public function setTagTopicClass($class) {
		$this->tagTopicClass = $class;
	}
	
	/**
	 * @return string Classname
	 */
	public function getTagTopicClass() {
		return $this->tagTopicClass;
	}
	
	protected function saveIntoObjectTags($record) {
		// HACK We can't save relationship tables without having an ID
		if(!$record->isInDB()) $record->write();
		
		$tagsArr = $this->splitTagsToArray($this->value);
		$relationName = $this->Name();
		$existingTagsComponentSet = $record->$relationName();
		$tagClass = $this->getTagClass();
		$tagBaseClass = ClassInfo::baseDataClass($tagClass);
		
		$tagsToAdd = array();
		if($tagsArr) foreach($tagsArr as $tagString) {
			$SQL_filter = sprintf('`%s`.`%s` = "%s"',
				$tagBaseClass,
				$this->tagObjectField,
				Convert::raw2sql($tagString)
			);
			$tagObj = DataObject::get_one($tagClass, $SQL_filter);
			if(!$tagObj) {
				$tagObj = new $tagClass();
				$tagObj->{$this->tagObjectField} = $tagString;
				$tagObj->write();
			}
			$tagsToAdd[] = $tagObj;
		}

		// remove all before readding
		$existingTagsComponentSet->removeAll();
		$existingTagsComponentSet->addMany($tagsToAdd);
	}
	
	protected function saveIntoTextbasedTags($record) {
		$tagFieldName = $this->Name();
		
		// necessary step to filter whitespace etc.
		$RAW_tagsArr = $this->splitTagsToArray($this->value);
		$record->$tagFieldName = $this->combineTagsFromArray($RAW_tagsArr);
	}
	
	protected function splitTagsToArray($tagsString) {
		$separator = (isset(self::$separator_to_regex[$this->separator])) ? self::$separator_to_regex[$this->separator] : $this->separator;
		return array_unique(preg_split('/\s*' . $separator . '\s*/', trim($tagsString)));
	}
	
	protected function combineTagsFromArray($tagsArr) {
		return ($tagsArr) ? implode($this->separator, $tagsArr) : array();
	}
	
	/**
	 * Use only when storing tags in objects
	 */
	protected function getTagClass() {
		$tagManyMany = singleton($this->tagTopicClass)->many_many($this->Name());
		if(!$tagManyMany) {
			user_error('TagField::getTagClass(): Cant find relation with name "' . $this->Name() . '"', E_USER_ERROR);
		}

		return $tagManyMany[1];
	}
	
	protected function getObjectTags($searchString) {
		$tagClass = $this->getTagClass();
		$tagBaseClass = ClassInfo::baseDataClass($tagClass);
		
		$SQL_filter = sprintf("`%s`.`%s` LIKE '%%%s%%'",
			$tagBaseClass,
			$this->tagObjectField,
			Convert::raw2sql($searchString)
		);
		if($this->tagFilter) $SQL_filter .= ' AND ' . $this->tagFilter;
		
		$tagObjs = DataObject::get($tagClass, $SQL_filter, $this->tagSort);
		$tagArr = ($tagObjs) ? array_values($tagObjs->map('ID', $this->tagObjectField)) : array();
		
		return $tagArr;
	}
	
	protected function getTextbasedTags($searchString) {
		$baseClass = ClassInfo::baseDataClass($this->tagTopicClass);
		
		$SQL_filter = sprintf("`%s`.`%s` LIKE '%%%s%%'",
			$baseClass,
			$this->Name(),
			Convert::raw2sql($searchString)
		);
		if($this->tagFilter) $SQL_filter .= ' AND ' . $this->tagFilter;

		$allTopicObjs = DataObject::get($this->tagTopicClass, $SQL_filter, $this->tagSort);
		$multipleTagsArr = ($allTopicObjs) ? array_values($allTopicObjs->map('ID', $this->Name())) : array();
		$filteredTagArr = array();
		foreach($multipleTagsArr as $multipleTags) {
			$singleTagsArr = $this->splitTagsToArray($multipleTags);
			foreach($singleTagsArr as $singleTag) {
				// only add those tags of the whole string which
				// match the search terms
				if(stripos($singleTag, $searchString) !== false) {
					$filteredTagArr[] = $singleTag;
				}
			}
		}
		// remove duplicates (retains case sensitive duplicates)
		$filteredTagArr = array_unique($filteredTagArr);
		
		return $filteredTagArr;
	}
	
	public function setTagFilter($sql) {
		$this->tagFilter = $sql;
	}
	
	public function getTagFilter() {
		return $this->tagFilter;
	}
	
	public function setTagSort($sql) {
		$this->tagSort = $sql;
	}
	
	public function getTagSort() {
		return $this->tagSort;
	}
	
	public function setSeparator($separator) {
		$this->separator = $separator;
	}
	
	public function getSeparator() {
		return $this->separator;
	}
	
	public function setCustomTags($tags) {
		$this->customTags = $tags;
	}
	
	public function getCustomTags() {
		return $this->customTags;
	}
	
}
?>