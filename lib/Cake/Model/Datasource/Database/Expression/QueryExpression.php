<?php
/**
 * 
 * PHP Version 5.4
 *
 * CakePHP(tm) Tests <http://book.cakephp.org/2.0/en/development/testing.html>
 * Copyright 2005-2012, Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The Open Group Test Suite License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright 2005-2013, Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://book.cakephp.org/2.0/en/development/testing.html CakePHP(tm) Tests
 * @package       Cake.Test.Case.Model.Datasource.Database
 * @since         CakePHP(tm) v 3.0
 * @license       MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
namespace Cake\Model\Datasource\Database\Expression;

use Cake\Model\Datasource\Database\Expression;
use Cake\Model\Datasource\Database\Query;
use \Countable;

/**
 * Represents a SQL Query expression. Internally it stores a tree of
 * expressions that can be compiled by converting this object to string
 * and will contain a correctly parenthesized and nested expression.
 *
 * This class also deals with internally binding values to parts of the expression,
 * used for condition comparisons. When a string representation of an instance
 * of this class is built any value bound will be expressed as a placeholder,
 * thus this class exposes methods for getting the actual bound values for each of
 * them so they can be used in statements or replaced directly.
 */
class QueryExpression implements Expression, Countable {

/**
 * String to be used for joining each of the internal expressions
 * this object internally stores for example "AND", "OR", etc.
 *
 * @var string
 */
	protected $_conjunction;

/**
 * A list of strings or other expression objects that represent the "branches" of
 * the expression tree. For example one key of the array might look like "sum > :value"
 *
 * @var array
 */
	protected $_conditions = [];

/**
 * Array containing a list of bound values to the conditions on this
 * object. Each array entry is another array structure containing the actual
 * bound value, its type and the placeholder it is bound to.
 *
 * @var array
 */
	protected $_bindings = [];

/**
 * An unique string that identifies this object. It is used to create unique
 * placeholders.
 * 
 * @car string
 */
	protected $_identifier;

/**
 * A counter of the number of parameters bound in this expression object
 *
 * @var integer
 */
	protected $_bindingsCount = 0;

/**
 * Whether to process placeholders that are meant to bind multiple other
 * placeholders out of an array of values. This value is automatically
 * set to true when an "IN" condition is used or when a value is bound
 * with an array type.
 *
 * @var boolean
 */
	protected $_replaceArrayParams = false;

/**
 * Constructor. A new expression object can be created without any params and
 * be built dynamically. Otherwise it is possible to pass an array of conditions
 * containing either a tree-like array structure to be parsed and/or other
 * expression objects. Optionally, you can set the conjunction keyword to be used
 * for joining each part of this level of the expression tree.
 *
 * @param array $conditions tree-like array structure containing all the conditions
 * to be added or nested inside this expression object.
 * @param array types associative array of types to be associated with the values
 * passed in $conditions.
 * @param string $conjunction the glue that will join all the string conditions at this
 * level of the expression tree. For example "AND", "OR", "XOR"...
 * @see QueryExpression::add() for more details on $conditions and $types
 * @return void
 */
	public function __construct($conditions = [], $types = [], $conjunction = 'AND') {
		$this->_conjunction = strtoupper($conjunction);
		$this->_identifier = substr(spl_object_hash($this), 7, 9);
		if (!empty($conditions)) {
			$this->add($conditions, $types);
		}
	}

/**
 * Changes the conjunction for the conditions at this level of the expression tree.
 * If called with no arguments it will return the currently configured value.
 *
 * @param string $conjunction value to be used for joining conditions. If null it
 * will not set any value, but return the currently stored one
 * @return string
 */
	public function type($conjunction = null) {
		if ($conjunction === null) {
			return $this->_conjunction;
		}

		$this->_conjunction = strtoupper($conjunction);
		return $this;
	}

/**
 * Adds one or more conditions to this expression object. Conditions can be
 * expressed in a one dimensional array, that will cause all conditions to
 * be added directly at this level of the tree or they can be nested arbitrarily
 * making it create more expression objects that will be nested inside and
 * configured to use the specified conjunction.
 *
 * If the type passed for any of the fields is expressed "type[]" (note braces)
 * then it will cause the placeholder to be re-written dynamically so if the
 * value is an array, it will create as many placeholders as values are in it.
 *
 * @param string|array $conditions single or multiple conditions to be added. When
 * using and array and the key is 'OR' or 'AND' a new expression object will be
 * created with that conjunction and internal array value passed as conditions.
 * @param array associative array of fields pointing to the type of the values
 * that are being passed. Used for correctly binding values to statements.
 * @see Cake\Model\Datasource\Database\Query::where() for examples on conditions
 * @return QueryExpression
 */
	public function add($conditions, $types = []) {
		if (is_string($conditions)) {
			$this->_conditions[] = $conditions;
			return $this;
		}

		if ($conditions instanceof self && count($conditions) > 0) {
			$this->_conditions[] = $conditions;
			return $this;
		}

		$this->_addConditions($conditions, $types);
		return $this;
	}

/**
 * Adds a new condition to the expression object in the form "field = value".
 *
 * @param string $field database field to be compared against value
 * @param mixed $value the value to be bound to $field for comparison
 * @param string $type the type name for $value as configured using the Type map.
 * If it is suffixed with "[]" and the value is an array then multiple placeholders
 * will be created, one per each value in the array.
 * @return QueryExpression
 */
	public function eq($field, $value, $type = null) {
		return $this->add([$field => $value], $type ? [$field => $type] : []);
	}

/**
 * Adds a new condition to the expression object in the form "field != value".
 *
 * @param string $field database field to be compared against value
 * @param mixed $value the value to be bound to $field for comparison
 * @param string $type the type name for $value as configured using the Type map.
 * If it is suffixed with "[]" and the value is an array then multiple placeholders
 * will be created, one per each value in the array.
 * @return QueryExpression
 */
	public function notEq($field, $value, $type = null) {
		return $this->add([$field . ' !=' => $value], $type ? [$field => $type] : []);
	}

/**
 * Adds a new condition to the expression object in the form "field > value".
 *
 * @param string $field database field to be compared against value
 * @param mixed $value the value to be bound to $field for comparison
 * @param string $type the type name for $value as configured using the Type map.
 * @return QueryExpression
 */
	public function gt($field, $value, $type = null) {
		return $this->add([$field . ' >' => $value], $type ? [$field => $type] : []);
	}

/**
 * Adds a new condition to the expression object in the form "field < value".
 *
 * @param string $field database field to be compared against value
 * @param mixed $value the value to be bound to $field for comparison
 * @param string $type the type name for $value as configured using the Type map.
 * @return QueryExpression
 */
	public function lt($field, $value, $type = null) {
		return $this->add([$field . ' <' => $value], $type ? [$field => $type] : []);
	}

/**
 * Adds a new condition to the expression object in the form "field >= value".
 *
 * @param string $field database field to be compared against value
 * @param mixed $value the value to be bound to $field for comparison
 * @param string $type the type name for $value as configured using the Type map.
 * @return QueryExpression
 */
	public function gte($field, $value, $type = null) {
		return $this->add([$field . ' >=' => $value], $type ? [$field => $type] : []);
	}

/**
 * Adds a new condition to the expression object in the form "field <= value".
 *
 * @param string $field database field to be compared against value
 * @param mixed $value the value to be bound to $field for comparison
 * @param string $type the type name for $value as configured using the Type map.
 * @return QueryExpression
 */
	public function lte($field, $value, $type = null) {
		return $this->add([$field . ' <=' => $value], $type ? [$field => $type] : []);
	}

/**
 * Adds a new condition to the expression object in the form "field IS NULL".
 *
 * @param string $field database field to be tested for null
 * @return QueryExpression
 */
	public function isNull($field) {
		return $this->add($field . ' IS NULL');
	}

/**
 * Adds a new condition to the expression object in the form "field IS NOT NULL".
 *
 * @param string $field database field to be tested for not null
 * @return QueryExpression
 */
	public function isNotNull($field) {
		return $this->add($field . ' IS NOT NULL');
	}

/**
 * Adds a new condition to the expression object in the form "field LIKE value".
 *
 * @param string $field database field to be compared against value
 * @param mixed $value the value to be bound to $field for comparison
 * @param string $type the type name for $value as configured using the Type map.
 * @return QueryExpression
 */
	public function like($field, $value, $type = null) {
		return $this->add([$field . ' LIKE' => $value], $type ? [$field => $type] : []);
	}

/**
 * Adds a new condition to the expression object in the form "field NOT LIKE value".
 *
 * @param string $field database field to be compared against value
 * @param mixed $value the value to be bound to $field for comparison
 * @param string $type the type name for $value as configured using the Type map.
 * @return QueryExpression
 */
	public function notLike($field, $value, $type = null) {
		return $this->add([$field . ' NOT LIKE' => $value], $type ? [$field => $type] : []);
	}

/**
 * Adds a new condition to the expression object in the form
 * "field IN (value1, value2)".
 *
 * @param string $field database field to be compared against value
 * @param array $value the value to be bound to $field for comparison
 * @param string $type the type name for $value as configured using the Type map.
 * @return QueryExpression
 */
	public function in($field, $values, $type = null) {
		return $this->add([$field . ' IN' => $values], $type ? [$field => $type] : []);
	}

/**
 * Adds a new condition to the expression object in the form
 * "field NOT IN (value1, value2)".
 *
 * @param string $field database field to be compared against value
 * @param array $value the value to be bound to $field for comparison
 * @param string $type the type name for $value as configured using the Type map.
 * @return QueryExpression
 */
	public function notIn($field, $values, $type = null) {
		return $this->add([$field . ' NOT IN' => $values], $type ? [$field => $type] : []);
	}

	public function and_($conditions, $types = []) {
		if (is_callable($conditions)) {
			return $conditions(new self);
		}
		return new self($conditions, $types);
	}

	public function or_($conditions, $types = []) {
		if (is_callable($conditions)) {
			return $conditions(new self([], [], 'OR'));
		}
		return new self($conditions, $types, 'OR');
	}

	public function not($conditions, $types = []) {
		return $this->add(['NOT' => $conditions], $types);
	}

/**
 * Associates a query placeholder to a value and a type for next execution
 *
 * @param string|integer $token placeholder to be replaced with quoted version
 * of $value
 * @param mixed $value the value to be bound
 * @param string|integer $type the mapped type name, used for casting when sending
 * to database
 * @return string placeholder name or question mark to be used in the query string
 */
	public function bind($param, $value, $type) {
		$number = $this->_bindingsCount;
		$this->_bindings[$number] = compact('value', 'type') + [
			'placeholder' => substr($param, 1)
		];
		if (strpos($type, '[]') !== false) {
			$this->_replaceArrayParams = true;
		}
		return $this;
	}

	public function placeholder($token) {
		$param = $token;
		$number = $this->_bindingsCount++;

		if (is_numeric($token)) {
			$param = '?';
		} else if ($param[0] !== ':') {
			$param = sprintf(':c%s%s', $this->_identifier, $number);
		}

		return $param;
	}

/**
 * Returns all values bound to this expression object at this nesting level.
 * Subexpression bound values will nit be returned with this function.
 *
 * @return array
 **/
	public function bindings() {
		return $this->_bindings;
	}

	public function count() {
		return count($this->_conditions);
	}

	public function __toString() {
		return $this->sql();
	}

	public function sql() {
		if ($this->_replaceArrayParams) {
			$this->_replaceArrays();
		}
		$conjunction = $this->_conjunction;
		$template = ($this->count() === 1) ? '%s' : '(%s)';
		return sprintf($template, implode(" $conjunction ", $this->_conditions));
	}

	public function traverse($callable) {
		foreach ($this->_conditions as $c) {
			if ($c instanceof self) {
				$c->traverse($callable);
			}
		}
		$callable($this);
	}

	protected function _addConditions(array $conditions, array $types) {
		$operators = array('and', 'or', 'xor');

		foreach ($conditions as $k => $c) {
			$numericKey = is_numeric($k);

			if ($numericKey && empty($c)) {
				continue;
			}

			if ($numericKey && is_string($c)) {
				$this->_conditions[] = $c;
				continue;
			}

			if ($numericKey && is_array($c) || in_array(strtolower($k), $operators)) {
				$this->_conditions[] = new self($c, $types, $numericKey ? 'AND' : $k);
				continue;
			}

			if (strtolower($k) === 'not') {
				$this->_conditions[] = new UnaryExpression(new self($c, $types), [], 'NOT');
				continue;
			}

			if ($c instanceof self && count($c) > 0) {
				$this->_conditions[] = $c;
				continue;
			}

			if (!$numericKey) {
				$this->_conditions[] = $this->_parseCondition($k, $c, $types);
			}
		}
	}

	protected function _parseCondition($field, $value, $types) {
		$operator = '=';
		$expression = $field;
		$parts = explode(' ', trim($field), 2);

		if (count($parts) > 1) {
			list($expression, $operator) = $parts;
		}

		$type = isset($types[$expression]) ? $types[$expression] : null;
		$multi = false;

		if (in_array(strtolower(trim($operator)), ['in', 'not in'])) {
			$type = $type ?: 'string';
			$type .= strpos($type, '[]') === false ? '[]' : null;
			$multi = true;
		}

		if ($value instanceof Expression || $multi === false) {
			return new Comparisson($expression, $value, $type, $operator);
		}

		$placeholder = $this->_bindValue($field, $value, $type);
		return sprintf('%s %s (%s)', $expression,  $operator, $placeholder);
	}

	protected function _bindValue($field, $value, $type) {
		$param = $this->placeholder($field);
		$this->bind($param, $value, $type);
		return $param;
	}

	protected function _replaceArrays() {
		$replacements = [];
		foreach ($this->_bindings as $n => $b) {
			if (strpos($b['type'], '[]') === false) {
				continue;
			}
			$type = str_replace('[]', '', $b['type']);
			$params = [];
			foreach ($b['value'] as $value) {
				$params[] = $this->_bindValue($b['placeholder'], $value, $type);
			}
			$token = ':' . $b['placeholder'];
			$replacements[$token] = implode(', ', $params);
			unset($this->_bindings[$n]);
		}

		foreach ($this->_conditions as $k => $condition) {
			if (!is_string($condition)) {
				continue;
			}
			foreach ($replacements as $token => $r) {
				$this->_conditions[$k] = str_replace($token, $r, $condition);
			}
		}
	}

}