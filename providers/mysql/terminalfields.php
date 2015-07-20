<?php

namespace DarkRoast\MySQL;

require_once('ISqlQueryBuilder.php');

use DarkRoast\IFieldExpression;
use DarkRoast\ITerminalFieldExpression;

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

abstract class FieldFilterExpression extends TerminalFieldExpression implements IFieldExpression {
	public function equals($operand) {
		return isset($operand) ? new BinaryFilterExpression($this, $operand, '=', false) : new NullFilter();
	}

	public function lessThan($operand) {
		return isset($operand) ? new BinaryFilterExpression($this, $operand, '<', false) : new NullFilter();
	}

	public function greaterThan($operand) {
		return isset($operand) ? new BinaryFilterExpression($this, $operand, '>', false) : new NullFilter();
	}

	public function lessOrEqualThan($operand) {
		return isset($operand) ? new BinaryFilterExpression($this, $operand, '<=', false) : new NullFilter();
	}

	public function greaterOrEqualThan($operand) {
		return isset($operand) ? new BinaryFilterExpression($this, $operand, '>=', false) : new NullFilter();
	}

	public function isDefined() {
		return new UnaryFilterExpression($this, 'is not null', UnaryFilterExpression::POSTFIX);
	}

	public function isUndefined() {
		return new UnaryFilterExpression($this, 'is null', UnaryFilterExpression::POSTFIX);
	}

	public function isAggregate() {
		return false;
	}
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

	public function isAggregate() {
		return $this->field->isAggregate();
	}

	private $field;
	private $alias;
	private $sortDirection;
}
