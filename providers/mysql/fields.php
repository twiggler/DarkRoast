<?php

namespace DarkRoast\MySQL;

require_once('filters.php');
require_once('terminalfields.php');

use DarkRoast\IAggregateableExpression;
use DarkRoast\IFieldExpression;

abstract class FieldExpression extends FieldFilterExpression  {
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

	private $field;
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

	public function alias() {
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

	public function alias() {
		return "";
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

	private $field;
}
