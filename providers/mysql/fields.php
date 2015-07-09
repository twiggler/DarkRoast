<?php

namespace DarkRoast\MySQL;

require_once('corefields.php');

use DarkRoast\ITerminalFieldExpression;

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


class TerminalField implements IQueryPart, ITerminalFieldExpression {
	const ASCENDING = 1;
	const DESCENDING = -1;

	function __construct($field, $alias, $sortDirection = null) {
		$this->field = $field;
		$this->alias = $alias;
		$this->sortDirection = $sortDirection;
	}

	public function alias() {
		return isset($this->alias) ? $this->alias : $this->field->alias();
	}

	public function name($alias) {
		$this->alias = $alias;

		return $this;
	}

	public function sortAscending() {
		$this->sortDirection = self::ASCENDING;

		return $this;
	}

	public function sortDescending() {
		$this->sortDirection = self::DESCENDING;

		return $this;
	}

	public function evaluate(ISqlQueryBuilder $queryBuilder) {
		$fieldExpression = $this->field->evaluate($queryBuilder);

		if (isset($this->sortDirection))
			$queryBuilder->addOrderByExpression($fieldExpression, $this->sortDirection);

		if (isset($this->alias))
			$fieldExpression .= " AS {$this->alias}";

		return $fieldExpression;
	}

	private $field;
	private $alias;
	private $sortDirection;
}

class Aggregation extends FieldExpression { // TODO Improve naming with respect to AggregatedField
	function __construct($field, $function, $distinct) {
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
	function __construct($field) {
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
