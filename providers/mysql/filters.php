<?php

namespace DarkRoast\MySql;

require_once('ISqlQueryBuilder.php');

use DarkRoast\IEqualityFilterExpression;
use DarkRoast\IFilter;

abstract class Filter implements IQueryPart, IFilter {
	public function _and($condition) {
		return new BinaryFilterExpression($this, $condition, "AND", true);
	}

	public function _or($condition) {
		return new BinaryFilterExpression($this, $condition, "OR", true);
	}

	public function not($condition) {
		return new UnaryFilterExpression($this, 'NOT', UnaryFilterExpression::PREFIX);
	}

	public function exists() {
		return new ExistsFilter($this);
	}

	public function parenthesis() {
		return new ReorderedFilterExpression($this);
	}
}

class ReorderedFilterExpression extends Filter {
	function __construct($field) {
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

class NullFilter implements IQueryPart, IFilter {
	public function evaluate(ISqlQueryBuilder $queryBuilder) {
		return '';
	}

	public function _and($condition) {
		return $condition;
	}

	public function _or($condition) {
		return $condition;
	}

	public function not($operand) {
		return $this;
	}

	public function exists() {
		return $this;
	}

	public function parenthesis() {
		return $this;
	}

	public function equals($operand) {
		return $this;
	}

	public function lessThan($operand) {
		return $this;
	}

	public function greaterThan($operand) {
		return $this;
	}

	public function isAggregate() {
		return null;
	}
}

class UnaryFilterExpression extends Filter {
	const PREFIX = -1;
	const POSTFIX = 1;

	function __construct(IQueryPart $operand, $operator, $position) {
		$this->operand = $operand;
		$this->operator = $operator;
		$this->postfix = $position === self::POSTFIX;
	}

	public function evaluate(ISqlQueryBuilder $queryBuilder) {
		if ($this->postfix)
			return $this->operand->evaluate($queryBuilder) . " {$this->operator}";
		else
			return "{$this->operator} " . $this->operand->evaluate($queryBuilder);
	}

	public function isAggregate() {
		return $this->operand->isAggregate();
	}


	private $postfix;
	private $operator;
	private $operand;
}

class BinaryFilterExpression extends Filter implements IEqualityFilterExpression {
	function __construct(IQueryPart $operand1, $operand2, $operator, $indent) {
		$part1 = $operand1->isAggregate();
		$part2 = isAggregate($operand2);
		if (isset($part1) and isset($part2) and ($part1 xor $part2))
			throw new \InvalidArgumentException("Cannot mix aggregation and non-aggregation field expressions.");
		$this->isAggregate = (!isset($part1) and !isset($part2)) ? null : ($part1 or $part2);

		$this->operand1 = $operand1;
		$this->operand2 = $operand2;
		$this->operator = $operator;
		$this->indent = $indent;
	}

	public function evaluate(ISqlQueryBuilder $queryBuilder) {
		$part1 = $this->operand1->evaluate($queryBuilder);
		$part2 = evaluate($this->operand2, $queryBuilder);

		if ($part1 !== '' and $part2 !== '')
			return $part1 . " {$this->operator}" . ($this->indent ? $queryBuilder->indent(1) : ' ') . $part2;
		else
			return $part1 . $part2;
	}

	public function equals($operand) {
		return $this->_and($this->operand2->equals($operand));
	}

	public function lessThan($operand) {
		return $this->_and($this->operand2->lessThan($operand));
	}

	public function greaterThan($operand) {
		return $this->_and($this->operand2->greaterThan($operand));
	}

	public function lessOrEqualThan($operand) {
		return $this->_and($this->operand2->lessOrEqualThan($operand));
	}

	public function greaterOrEqualThan($operand) {
		return $this->_and($this->operand2->greaterOrEqualThan($operand));
	}

	public function isAggregate() {
		return $this->isAggregate;
	}

	private $isAggregate;
	private $operand1;
	private $operand2;
	private $operator;
	private $indent;
}

class ExistsFilter extends Filter implements IQueryPart {
	function __construct(IFilter $condition) {
		$this->condition = $condition;
	}

	function evaluate(ISqlQueryBuilder $queryBuilder) {
		$subQueryBuilder = $queryBuilder->createChild(1);
		$subQuerySql = $subQueryBuilder->build([new ConstantField(1)], $this->condition);

		return "EXISTS (" . $subQuerySql . $queryBuilder->indent(1) . ")";
	}

	public function parenthesis() {
		return $this;
	}

	public function isAggregate() {
		return $this->condition->isAggregate();
	}

	private $condition;
}
