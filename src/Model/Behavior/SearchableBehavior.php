<?php
/**
 * Copyright 2009-2013, Cake Development Corporation (http://cakedc.com)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright 2009 - 2014, Cake Development Corporation (http://cakedc.com)
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Search\Model\Behavior;

use Cake\Core\Configure;
use Cake\ORM\Behavior;
use Cake\ORM\Query;
use Cake\ORM\Table;
use Cake\Utility\Hash;
use Cake\Utility\Text;
// use Cake\Log\Log;
// use Cake\Error\Debugger;

/**
 * Searchable behavior
 *
 */
class SearchableBehavior extends Behavior {

/**
 * Default settings
 * - wildcardAny: the character used instead of % (% is a normal character then)
 * - wildcardOne: the character used instead of _ (_ is a normal character then)
 * - like: auto add % wildcard to beginning, end or both (both false => user can enter wildcards himself)
 * - connectorAnd: the character between search terms to specify an "and" relationship (binds stronger than or, similar to * and + in math)
 * - connectorOr: the character between search terms to specify an "or" relationship
 *
 * @var array
 */
	protected $_defaultConfig = [
		'wildcardAny' => '*', //on windows/unix/mac/google/... thats the default one
		'wildcardOne' => '?', //on windows/unix/mac thats the default one
		'like' => ['before' => true, 'after' => true],
		'connectorAnd' => null,
		'connectorOr' => null,
	];

	protected $_table;

/**
 * Configuration of model
 *
 * @param Table $table Table that the behavior is attached too
 * @param array $config Configuration details
 */
	public function __construct(Table $table, array $config = []) {
		$this->_defaultConfig = array_merge($this->_defaultConfig, (array)Configure::read('Search.Searchable'));
		parent::__construct($table, $config);
		$this->_table = $table;
	}

/**
 * Validate search
 *
 * @param array $searchData Data to validate
 *
 * @return bool always true
 */
	public function validateSearch($searchData) {
		// Log::debug("Called validateSearch");
		// Log::debug($searchData);

		foreach ($searchData as $key => $value) {
			if (empty($value)) {
				unset($searchData[$key]);
			}
		}
		// Log::debug($searchData);
		// Log::debug("Finished validateSearch");
		return true;
	}

/**
 * Prepares the filter args based on the model information and calls
 * Model::getFilterArgs if present to set up the filterArgs with proper model
 * aliases.
 *
 * @return bool|array
 */
	public function setupFilterArgs() {
		// Log::debug("Called setupFilterArgs");

		if (method_exists($this->_table, 'getFilterArgs')) {
			$this->_table->getFilterArgs();
		}
		if (empty($this->_table->filterArgs)) {
			return false;
		}
		foreach ($this->_table->filterArgs as $key => $val) {
			if (!isset($val['name'])) {
				$this->_table->filterArgs[$key]['name'] = $key;
			}
			if (!isset($val['field'])) {
				$this->_table->filterArgs[$key]['field'] = $this->_table->filterArgs[$key]['name'];
			}
			if (!isset($val['type'])) {
				$this->_table->filterArgs[$key]['type'] = 'value';
			}
		}

		// Log::debug("Finished setupFilterArgs");
		return $this->_table->filterArgs;
	}

/**
 * findSearchable
 * Parses the get parameters into query conditions based on the rules defined in the table filterArgs property
 *
 * @param Query $query The query to find with
 * @param array $data Criteria of key->value pairs from post/named parameters
 *
 * @return Query
 */
	public function findSearchable(Query $query, $data) {
		// Log::debug("Called findSearchable");
		// Log::debug("Query-data:");
		// Log::debug($data);

		$this->setupFilterArgs();

		foreach ($this->_table->filterArgs as $field) {
			// Log::debug("Field to be analysed:");
			// Log::debug($field);

			// If this field was not passed and a default value exists, use that instead.
			if (!array_key_exists($field['name'], $data) && array_key_exists('defaultValue', $field)) {
				$data[$field['name']] = $field['defaultValue'];
			}

			if ($field['type'] === 'like') {
				$cond = $this->_addCondLike($data, $field);
				if (!empty($cond)) {
					$query->where($cond);
				}
			} elseif (in_array($field['type'], ['value', 'lookup'])) {
				$cond = $this->_addCondValue($data, $field);
				if (!empty($cond)) {
					$query->where($cond);
				}
			} elseif ($field['type'] === 'finder') {
				$this->_addCondFinder($query, $data, $field);
			}
		}

		// Log::debug("Finished findSearchable");
		return $query;
	}

/**
 * filter retrieving variables only that present in  Model::filterArgs
 *
 * @param array $vars variables to filter
 *
 * @return array, filtered args
 */
	public function passedArgs($vars) {
		// Log::debug("CALLED PassedArgs");
		$this->setupFilterArgs($this->_table);

		$result = [];
		foreach ($vars as $var => $val) {
			if (in_array($var, Hash::extract($this->_table->filterArgs, '{n}.name'))) {
				$result[$var] = $val;
			}
		}
		// Log::debug($vars);
		// Log::debug($result);
		return $result;
	}

/**
 * For custom queries inside the model
 * example "makePhoneCondition": $cond = array('OR' => array_merge($this->condLike('cell_number', $filter), $this->condLike('landline_number', $filter, array('before' => false))));
 *
 * @param string $name Name of field
 * @param array $data Data to search with
 * @param array $field Field configuration details
 *
 * @return array of conditions
 */
	public function condLike($name, $data, $field = []) {
		// Log::debug("condLike");

		$field['name'] = $name;
		if (!is_array($data)) {
			$data = [$name => $data];
		}
		if (!isset($field['field'])) {
			$field['field'] = $field['name'];
		}
		return $this->_addCondLike($data, $field);
	}

/**
 * Replace substitutions with original wildcards
 * but first, escape the original wildcards in the text to use them as normal search text
 *
 * @param array $data  Data to search with
 * @param array $options Options array (WildcardAny and WildcardOne)
 *
 * @return string queryLikeString
 */
	public function formatLike($data, $options = []) {
		// Log::debug("formatLike");

		$options = array_merge($this->_config, $options);
		$from = $to = $substFrom = $substTo = [];
		if ($options['wildcardAny'] !== '%') {
			$from[] = '%';
			$to[] = '\%';
			$substFrom[] = $options['wildcardAny'];
			$substTo[] = '%';
		}
		if ($options['wildcardOne'] !== '_') {
			$from[] = '_';
			$to[] = '\_';
			$substFrom[] = $options['wildcardOne'];
			$substTo[] = '_';
		}
		if (!empty($from)) {
			// escape first
			$data = str_replace($from, $to, $data);
			// replace wildcards
			$data = str_replace($substFrom, $substTo, $data);
		}
		return $data;
	}

/**
 * Return the current chars for querying LIKE statements on this model
 *
 * @param array $options Wildcard options
 *
 * @return array, [one=>..., any=>...]
 */
	public function getWildcards($options = []) {
		// Log::debug("getWildcards");

		$options = array_merge($this->_config, $options);
		return ['any' => $options['wildcardAny'], 'one' => $options['wildcardOne']];
	}

/**
 * Add Conditions based on fuzzy comparison
 *
 * @param array $data Array of data used in search query
 * @param array $field Field definition information
 *
 * @return array
 */
	protected function _addCondLike($data, $field) {
		// Log::debug("_addCondLike");

		if (!is_array($this->_config['like'])) {
			$this->_config['like'] = ['before' => $this->_config['like'], 'after' => $this->_config['like']];
		}
		$field = array_merge($this->_config['like'], $field);
		if (empty($data[$field['name']])) {
			return [];
		}
		$fieldNames = (array)$field['field'];

		$cond = [];
		foreach ($fieldNames as $fieldName) {
			if (strpos($fieldName, '.') === false) {
				$fieldName = $this->_table->alias() . '.' . $fieldName;
			}

			if ($field['before'] === true) {
				$field['before'] = '%';
			}
			if ($field['after'] === true) {
				$field['after'] = '%';
			}

			$options = $this->_config;
			$from = $to = $substFrom = $substTo = [];
			if ($options['wildcardAny'] !== '%') {
				$from[] = '%';
				$to[] = '\%';
				$from[] = $options['wildcardAny'];
				$to[] = '%';
			}
			if ($options['wildcardOne'] !== '_') {
				$from[] = '_';
				$to[] = '\_';
				$from[] = $options['wildcardOne'];
				$to[] = '_';
			}
			$value = $data[$field['name']];
			if (!empty($from)) {
				$value = str_replace($from, $to, $value);
			}

			if (!empty($field['connectorAnd']) || !empty($field['connectorOr'])) {
				$cond[] = $this->_connectedLike($value, $field, $fieldName);
			} else {
				$cond[$fieldName . " LIKE"] = $field['before'] . $value . $field['after'];
			}
		}
		if (count($cond) > 1) {
			$cond = [
				'or' => $cond
			];
		}

		return $cond;
	}

/**
 * Form AND/OR query array using String::tokenize to separate
 * search terms by or/and connectors.
 *
 * @param mixed $value Value to search
 * @param array $field Field information
 * @param string $fieldName Field name to search
 *
 * @return array Conditions
 */
	protected function _connectedLike($value, $field, $fieldName) {
		// Log::debug("_connectedLike");

		$or = [];
		$orValues = Text::tokenize($value, $field['connectorOr']);
		foreach ($orValues as $orValue) {
			$andValues = Text::tokenize($orValue, $field['connectorAnd']);
			$and = [];
			foreach ($andValues as $andValue) {
				$and[] = [$fieldName . " LIKE" => $field['before'] . $andValue . $field['after']];
			}

			$or[] = ['AND' => $and];
		}

		return ['OR' => $or];
	}

/**
 * Add Conditions based on exact comparison
 *
 * @param array $data Array of data used in search query
 * @param array $field Field definition information
 *
 * @return array of conditions
 */
	protected function _addCondValue($data, $field) {
		// Log::debug("_addCondValue");

		$fieldNames = (array)$field['field'];
		$fieldValue = isset($data[$field['name']]) ? $data[$field['name']] : null;

		$cond = [];
		foreach ($fieldNames as $fieldName) {
			if (strpos($fieldName, '.') === false) {
				$fieldName = $this->_table->alias() . '.' . $fieldName;
			}
			if (is_array($fieldValue) && empty($fieldValue)) {
				continue;
			}
			if (!is_array($fieldValue) && ($fieldValue === null || $fieldValue === '' && empty($field['allowEmpty']))) {
				continue;
			}

			if (is_array($fieldValue) || !is_array($fieldValue) && (string)$fieldValue !== '') {
				$cond[$fieldName] = $fieldValue;
			} elseif (isset($data[$field['name']]) && !empty($field['allowEmpty'])) {
				$schema = $this->_table->schema()->column($field['name']);
				if (isset($schema) && ($schema['default'] !== null || !empty($schema['null']))) {
					$cond[$fieldName] = $schema['default'];
				} elseif (!empty($fieldValue)) {
					$cond[$fieldName] = $fieldValue;
				} else {
					$cond[$fieldName] = $fieldValue;
				}
			}
		}
		if (count($cond) > 1) {
			$cond = [
				'or' => $cond
			];
		}

		return $cond;
	}

/**
 * Add Conditions based query to search conditions.
 *
 * @param Query $query Query object.
 * @param array $data Data for a field.
 * @param array $field Info for field.
 *
 * @return array of conditions modified by this method
 */
	protected function _addCondFinder(Query $query, $data, $field) {
		// Log::debug("Called _addCondFinder");
		// Log::debug("Data:");
		// Log::debug($data);
		// Log::debug("Field:");
		// Log::debug($field);
		$data_keys = array_keys($data);
		$matched_data = preg_grep('/^' . $field['name'] . '$/', $data_keys);
		// Log::debug($matched_data);

		//if allowEmpty is not empty for the field or if the fieldname is matched within the data
		if ( (!empty($field['allowEmpty']) || $matched_data) ) {
			// Log::debug("Changing query");
			foreach($matched_data as $key => $matched_dat){
				//the field name and field entry could be regex
				//overwrite them to be the matched field
				$field['name'] = $matched_dat;
				$field['field'] = $matched_dat;

				$query->find($field['finder'], [
					'data' => $data,
					'field' => $field
				]);
			}
		// } else {
		// 	Log::debug("Query not effected");
		}

		// Log::debug("Finished _addCondFinder");
		return $query;
	}
}
