<?php

namespace DarkRoast\MySQL;

require_once('filters.php');

use DarkRoast\ITerminalFieldExpression;
use DarkRoast\IAggregateableExpression;
use DarkRoast\IFieldExpression;

class Table implements \ArrayAccess {
	function __construct($identifier, array $fields = []) {
		$this->identifier = $identifier;
		$this->fields = $fields;
		$this->_id = uniqid();
	}

	public function copy() {
		$clone = new Table($this->identifier);

		foreach ($this->fields as $fieldName => $field) {
			$clone->fields[$fieldName] = $field->copy($clone);
		}

		return ($clone);
	}

	public function offsetExists($offset) {
		return isset($this->fields[$offset]);
	}

	public function offsetGet($offset) {
		return isset($this->fields[$offset]) ? $this->fields[$offset] : null;
	}

	public function offsetSet($offset, $value) {
		if (is_null($offset)) throw new \InvalidArgumentException("Field must have an identifier");

		$this->fields[$offset] = $value;
	}

	public function offsetUnset($offset) {
		unset($this->fields[$offset]);
	}

	public function name() {
		return $this->identifier;
	}

	private $identifier;
	private $fields;
	private $_id;
}


abstract class TerminalFieldExpression implements IQueryPart, ITerminalFieldExpression {
	public function name($alias) {
		return new TerminalField($this, $alias);
	}

	public function sortAscending() {
		return new TerminalField($this, null, TerminalField::ASCENDING);
	}

	public function sortDescending() {
		return new TerminalField($this, null, TerminalField::DESCENDING);
	}
}

abstract class FieldExpression extends TerminalFieldExpression implements IFieldExpression {
	public function equals($operand) {
		return isset($operand) ? new BinaryFilterExpression($this, $operand, '=', false) : new NullFilter();
	}

	public function lessThan($operand) {
		return isset($operand) ? new BinaryFilterExpression($this, $operand, '<', false) : new NullFilter();
	}

	public function add($operand) {
		return new BinaryFieldExpression($this, $operand, '+', false);
	}

	public function minus($operand) {
		return new BinaryFieldExpression($this, $operand, '-', false);
	}

	public function multiply($operand) {
		return new BinaryFieldExpression($this, $operand, '*', false);
	}

	public function divide($operand) {
		return new BinaryFieldExpression($this, $operand, '/', false);
	}

	public function isDefined() {
		return new UnaryFilterExpression($this, 'is not null', UnaryFilterExpression::POSTFIX);
	}

	public function isUndefined() {
		return new UnaryFilterExpression($this, 'is null', UnaryFilterExpression::POSTFIX);
	}

	public function parenthesis() {
		return new ReorderedFieldExpression($this);
	}
}

abstract class AggregatableExpression extends FieldExpression implements IAggregateableExpression {
	public function sum() {
		return new AggregatedField(new Aggregation($this, 'sum', false));
	}

	public function max() {
		return new AggregatedField(new Aggregation($this, 'max', false));
	}

	public function min() {
		return new AggregatedField(new Aggregation($this, 'min', false));
	}

	public function count() {
		return new AggregatedField(new Aggregation($this, 'count', false));
	}

	public function countUnique() {
		return new AggregatedField(new Aggregation($this, 'count', true));
	}

	public function group() {
		return new GroupingField($this);
	}
}

class AggregatedField extends FieldExpression {
	function __construct($field) {
		$this->field = $field;
	}

	public function evaluate(ISqlQueryBuilder $queryBuilder) {
		return $this->field->evaluate($queryBuilder);
	}

	public function alias() {
		return $this->field->alias();
	}

	public function add($operand) {
		$this->field = new BinaryFieldExpression($this->field, $operand, '+', false);

		return $this;
	}

	public function minus($operand) {
		$this->field = new BinaryFieldExpression($this->field, $operand, '-', false);

		return $this;
	}

	public function multiply($operand) {
		$this->field = new BinaryFieldExpression($this->field, $operand, '*', false);

		return $this;
	}

	public function divide($operand) {
		$this->field = new BinaryFieldExpression($this->field, $operand, '/', false);

		return $this;
	}

	public function parenthesis() {
		$this->field = new ReorderedFieldExpression($this);

		return $this;
	}

	private $field;
}


class Field extends AggregatableExpression {
	function __construct($table, $columnName) {
		$this->table = $table;
		$this->columnName = $columnName;
	}

	public function copy($table) {
		return new Field($table, $this->columnName);
	}

	public function evaluate(ISqlQueryBuilder $queryBuilder) {
		return $queryBuilder->addressField($this->table, $this->columnName);
	}

	public function alias() {
		return $this->columnName;
	}

	private $table;
	private $columnName;
}

class BinaryFieldExpression extends AggregatableExpression {
	function __construct(IQueryPart $field1, $field2, $operator) {
		$this->field1 = $field1;
		$this->field2 = $field2;
		$this->operator = $operator;
	}

	public function evaluate(ISqlQueryBuilder $queryBuilder) {
		$part = $this->field1->evaluate($queryBuilder) . " {$this->operator} ";

		if (is_numeric($this->field2))
			$part .= $queryBuilder->addBinding($this->field2);
		elseif ($this->field2 instanceof IFieldExpression)
			$part .= $this->field2->evaluate($queryBuilder);
		else
			throw new \InvalidArgumentException("Invalid operand type specified for binary field expression");

		return $part;
	}

	public function alias() {
		return "";
	}

	private $field1;
	private $field2;
	private $operator;
}

class ReorderedFieldExpression extends AggregatableExpression {
	function __construct($field) {
		$this->field = $field;
	}

	public function evaluate(ISqlQueryBuilder $queryBuilder) {
		return "(" . $this->field->evaluate($queryBuilder) . ")";
	}

	public function alias() {
		return "";
	}

	private $field;
}
