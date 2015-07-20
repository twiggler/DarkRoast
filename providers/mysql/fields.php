<?php

namespace DarkRoast\MySQL;

require_once('filters.php');
require_once('terminalfields.php');

use DarkRoast\IAggregateableExpression;

abstract class FieldExpression extends FieldFilterExpression {
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

	public function parenthesis() {
		return new ReorderedFieldExpression($this);
	}

	public function alias() {
		return '';
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

	public function isAggregate() {
		return false;
	}
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

class AggregatedField extends FieldFilterExpression {
	function __construct(FieldExpression $field) {
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

	public function isAggregate() {
		return true;
	}

	private $field;
}

class BinaryFieldExpression extends AggregatableExpression {
	function __construct(IQueryPart $field1, $field2, $operator) {
		$this->field1 = $field1;
		$this->field2 = $field2;
		$this->operator = $operator;
	}

	public function evaluate(ISqlQueryBuilder $queryBuilder) {
		return $this->field1->evaluate($queryBuilder) . " {$this->operator} " . evaluate($this->field2, $queryBuilder);
	}

	public function isAggregate() {
		return $this->field1->isAggregate() or isAggregate($this->field2);
	}

	private $field1;
	private $field2;
	private $operator;
}

class ReorderedFieldExpression extends AggregatableExpression {
	function __construct(FieldFilterExpression $field) {
		$this->field = $field;
	}

	public function evaluate(ISqlQueryBuilder $queryBuilder) {
		return "(" . $this->field->evaluate($queryBuilder) . ")";
	}

	public function isAggregate() {
		return $this->field->isAggregate();
	}

	private $field;
}

class UserField extends AggregatableExpression {
	function __construct($columnName, $query) {
		$this->columnName = $columnName;
		$this->query = $query;
	}

	public function evaluate(ISqlQueryBuilder $queryBuilder) {
		return $queryBuilder->addressUserField($this->query, $this->columnName);
	}

	public function alias() {
		return $this->columnName;
	}

	private $columnName;
	private $query;
}

class ConstantField extends FieldExpression {
	function __construct($expression) {
		$this->expression = $expression;
	}

	public function evaluate(ISqlQueryBuilder $queryBuilder) {
		return strval($this->expression);
	}

	private $expression;
}

class Aggregation extends FieldExpression { // TODO Improve naming with respect to AggregatedField
	function __construct(FieldExpression $field, $function, $distinct) {
		$this->field = $field;
		$this->function = $function;
		$this->distinct = $distinct;
	}

	public function evaluate(ISqlQueryBuilder $queryBuilder) {
		return "{$this->function}(" . ($this->distinct ? 'DISTINCT' : '') . $this->field->evaluate($queryBuilder) . ")";
	}

	public function isAggregate() {
		return true;
	}

	private $field;
	private $function;
	private $distinct;
}

class GroupingField extends FieldExpression {
	function __construct(FieldExpression $field) {
		$this->field = $field;
	}

	public function evaluate(ISqlQueryBuilder $queryBuilder) {
		$fieldExpression = $this->field->evaluate($queryBuilder);
		$queryBuilder->addGroupingField($fieldExpression);

		return $fieldExpression;
	}

	public function alias() {
		return $this->field->alias();
	}

	public function isAggregate() {
		return true;
	}

	private $field;
}

class RecodedField extends AggregatableExpression {
	function __construct(array $map, $_default) {
		$this->map = $map;
		$this->_default = $_default;
	}

	public function evaluate(ISqlQueryBuilder $queryBuilder) {
		$cases = array_map(function(array $element) use ($queryBuilder) {
			return 'WHEN ' . $element[0]->evaluate($queryBuilder) . ' THEN ' . evaluate($element[1], $queryBuilder);
		}, $this->map);

		$part = 'CASE' . $queryBuilder->indent(2) . implode($queryBuilder->indent(2), $cases);
		if (isset($this->_default))
			$part .= $queryBuilder->indent(2) . 'ELSE ' . evaluate($this->_default, $queryBuilder);
		$part .= $queryBuilder->indent(1) . 'END';

		return $part;
	}


	private $map;
	private $_default;
}

class Placeholder extends FieldExpression {
	function __construct($index) {
		$this->index = $index;
	}

	public function evaluate(ISqlQueryBuilder $queryBuilder) {
		return ":_{$this->index}";
	}

	private $index;
}