<?php

namespace DarkRoast\DataBase;

use DarkRoast\IFieldExpression;
use DarkRoast\IFilter;

require_once('darkroast.php');

interface IQueryPart {
	public function evaluate(SqlQueryBuilder $queryBuilder);
}

abstract class FieldExpression implements IQueryPart, IFieldExpression {
	public function equals($operand) {
		return isset($operand) ? new BinaryFilterExpression($this, $operand, '=', false) : new NullFilter();
	}

	public function lessThan($operand) {
		return isset($operand) ? new BinaryFilterExpression($this, $operand, '<', false) : new NullFilter();
	}

	public function add($operand) {
		return new BinaryFieldExpression($this, $operand, '+', false);
	}

	public function isDefined() {
		return new UnaryFilterExpression($this, 'is not null', UnaryFilterExpression::POSTFIX);
	}

	public function isUndefined() {
		return new UnaryFilterExpression($this, 'is null', UnaryFilterExpression::POSTFIX);
	}

	public function rename($alias) {
		return new BinaryFieldExpression($this, $alias, ' as ');
	}

	public function ascending() {
		return new SortedField($this, SortedField::ASCENDING);
	}

	public function descending() {
		return new SortedField($this, SortedField::DESCENDING);
	}

	public function sum() {
		return new AggregatedField($this, 'sum', false);
	}

	public function max() {
		return new AggregatedField($this, 'max', false);
	}

	public function min() {
		return new AggregatedField($this, 'min', false);
	}

	public function count() {
		return new AggregatedField($this, 'count', false);
	}

	public function countUnique() {
		return new AggregatedField($this, 'count', true);
	}
}


class Field extends FieldExpression {
	function __construct($tableName, $columnName) {
		$this->tableName = $tableName;
		$this->columnName = $columnName;
	}

	public function evaluate(SqlQueryBuilder $queryBuilder) {
		return $queryBuilder->addressField($this->tableName, $this->columnName);
	}

	private $tableName;
	private $columnName;
}

class AggregatedField extends FieldExpression {
	function __construct($field, $function, $distinct) {
		$this->field = $field;
		$this->function = $function;
		$this->distinct = $distinct;
	}

	public function evaluate(SqlQueryBuilder $queryBuilder) {
		return "{$this->function}(" . ($this->distinct ? 'DISTINCT' : '') . $this->field->evaluate($queryBuilder) . ")";
	}

	private $field;
	private $function;
	private $distinct;
}

class ConstantField implements IQueryPart{
	function __construct($expression) {
		$this->expression = $expression;
	}

	public function evaluate(SqlQueryBuilder $queryBuilder) {
		return strval($this->expression);
	}

	private $expression;
}

class BinaryFieldExpression extends FieldExpression {
	function __construct(IQueryPart $field1, $field2, $operator) {
		$this->field1 = $field1;
		$this->field2 = $field2;
		$this->operator = $operator;
	}

	public function evaluate(SqlQueryBuilder $queryBuilder) {
		$part = $this->field1->evaluate($queryBuilder) . " {$this->operator} ";
		if (is_string($this->field2))
			$part .= $queryBuilder->addBinding($this->field2);
		else $part .= $this->field2->evaluate($queryBuilder);

		return $part;
	}

	private $field1;
	private $field2;
	private $operator;
}

class SortedField implements IQueryPart {
	const ASCENDING = 1;
	const DESCENDING = -1;

	function __construct($field, $direction) {
		$this->field = $field;
		$this->sortAscending = $direction === self::ASCENDING;
	}

	public function evaluate(SqlQueryBuilder $queryBuilder) {
		return $this->field->evaluate($queryBuilder) . " " . ($this->sortAscending ? "ASC" : "DESC");
	}

	private $field;
	private $sortAscending;
}


abstract class Filter implements IQueryPart, IFilter  {
	public function _and($condition) {
		return new BinaryFilterExpression($this, $condition, "AND", true);
	}

	public function _or($condition) {
		return new BinaryFilterExpression($this, $condition, "OR", true);
	}

	public function exists() {
		return new ExistsFilter($this);
	}
}


class NullFilter implements IQueryPart, IFilter {
	public function evaluate(SqlQueryBuilder $queryBuilder) {
		return '';
	}

	public function _and($condition) {
		return $condition;
	}

	public function _or($condition) {
		return $condition;
	}

	public function exists() {
		return $this;
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

	public function evaluate(SqlQueryBuilder $queryBuilder) {
		if ($this->postfix)
			return $this->operand->evaluate($queryBuilder) . " {$this->operator}";
		else
			return "{$this->operator} " . $this->operand->evaluate($queryBuilder);
	}

	private $postfix;
	private $operator;
	private $operand;
}

class BinaryFilterExpression extends Filter {
	function __construct(IQueryPart $operand1, $operand2, $operator, $indent) {
		$this->operand1 = $operand1;
		$this->operand2 = $operand2;
		$this->operator = $operator;
		$this->indent = $indent;
	}

	public function evaluate(SqlQueryBuilder $queryBuilder) {
		$part1 = $this->operand1->evaluate($queryBuilder);

		if (is_string($this->operand2) or is_numeric($this->operand2))
			$part2 = $queryBuilder->addBinding($this->operand2);
		elseif (is_object($this->operand2) and get_class($this->operand2) === 'DarkRoast\Placeholder')
			$part2 = ":_" . $this->operand2->index();
		else
			$part2 = $this->operand2->evaluate($queryBuilder);

		if ($part1 !== '' and $part2 !== '')
			return $part1 . " {$this->operator}" . ($this->indent ? $queryBuilder->indent(1) : ' ') . $part2;
		else
			return $part1 . $part2;
	}

	private $operand1;
	private $operand2;
	private $operator;
	private $indent;
}

;

class ExistsFilter extends Filter implements IQueryPart {
	function __construct($condition) {
		$this->condition = $condition;
	}

	function evaluate(SqlQueryBuilder $queryBuilder) {
		$subQueryBuilder = $queryBuilder->createChild(1);
		$subQuerySql = $subQueryBuilder->build([new ConstantField(1)], $this->condition);
		return "EXISTS (" .
		       $subQuerySql . ")";
	}

	private $condition;
}

class DarkRoast implements \DarkRoast\IDarkRoast {
	function __construct($querySource, \PDOStatement $pdpPreparedQuery, $bindings) {
		$this->querySource = $querySource;
		$this->pdpPreparedQuery = $pdpPreparedQuery;
		$this->bindValues($bindings);
	}

	private function bindValues($bindings) {
		array_walk($bindings, function ($value, $key) {
			$paramType = \PDO::PARAM_STR;
			if (is_integer($value)) $paramType = \PDO::PARAM_INT;
			$this->pdpPreparedQuery->bindValue($key, $value, $paramType);
		}, $bindings);
	}

	public function execute(...$bindings) {
		$keyedBindings = [];
		for ($keyCounter = 0; $keyCounter < count($bindings); $keyCounter++) {
			$key = ":_" . ($keyCounter + 1);
			$keyedBindings[$key] = $bindings[$keyCounter];
		}
		$this->bindValues($keyedBindings);

		$this->pdpPreparedQuery->execute();

		return $this->pdpPreparedQuery->fetchAll(\PDO::FETCH_ASSOC);
	}

	public function querySource() {
		return $this->querySource;
	}

	private $pdpPreparedQuery;
	private $querySource;
}

class DataProvider {
	function __construct(\PDO $pdo) {
		$this->pdo = $pdo;
	}

	public function prepareQuery($selectors, $filter, $offset, $limit, $sortFields) {
		$queryBuilder = new SqlQueryBuilder($this);
		$sqlStatement = $queryBuilder->build($selectors, $filter, $offset, $limit, $sortFields);
		return new DarkRoast($sqlStatement, $this->pdo->prepare($sqlStatement), $queryBuilder->bindings());
	}

	public function escapeIdentifier($identifier) {
		return "`" . str_replace("`", "``", $identifier) . "`";
	}

	public function reflectTable(...$tableNames) {
		$tables = array_map(function ($tableName) {
			$table = new \stdClass();
			$query = "show columns from " . $this->escapeIdentifier($tableName);

			foreach ($this->pdo->query($query) as $column) {
				$columnName = reset($column);
				$table->{$columnName} = new Field($tableName, $columnName);
			}

			return $table;
		}, $tableNames);

		return count($tables) > 1 ? $tables : reset($tables);
	}

	private $pdo;
}

class SqlQueryBuilder {
	function __construct(DataProvider $provider) {
		$this->provider = $provider;
	}

	public function createChild($indentationLevel = 0) {
		$childBuilder = new SqlQueryBuilder($this->provider);
		$childBuilder->parent = $this;
		$childBuilder->depth = $this->depth + 1;
		$childBuilder->indentationLevel = $this->indentationLevel + $indentationLevel + 1;

		return $childBuilder;
	}

	public function build($selectors, IQueryPart $filter = null, $offset = 0, $limit = null, $sortFields = []) {
		$selectClauses = array_map(function ($queryElement) {
			$fieldExpression = $queryElement->evaluate($this);
			if (get_class($queryElement) === 'DarkRoast\DataBase\AggregatedField')
				$this->aggregation = true;
			else
				$this->possibleGroupingExpressions[$fieldExpression] = null;

			return $fieldExpression;
		}, $selectors);

		$whereClause = isset($filter) ? $filter->evaluate($this) : null;

		$orderClauses = array_map(function (IQueryPart $queryElement) {
			return $queryElement->evaluate($this);
		}, $sortFields);

		$fromClauses = [];
		foreach ($this->tableAliases as $tableName => $tableAlias) {
			$fromClauses[] = $this->provider->escapeIdentifier($tableName) . " AS " . $this->provider->escapeIdentifier($tableAlias);
		}

		$query = $this->indent(0, $this->indentationLevel > 0) .
		         "SELECT" . $this->indent(1) .
		         implode(',' . $this->indent(1), $selectClauses) . $this->indent(0) .
		         "FROM" . $this->indent(1) .
		         implode($this->indent(1) . "CROSS JOIN ", $fromClauses) . $this->indent(0);

		if (isset($whereClause))
			$query .= "WHERE" . $this->indent(1) . $whereClause . $this->indent(0);

		if ($this->aggregation and count($this->possibleGroupingExpressions)) {
			$query .= "GROUP BY" . $this->indent(1) .
		              implode(',' . $this->indent(1), array_keys($this->possibleGroupingExpressions)) . $this->indent(0);
		}

		if (count($orderClauses) > 0) {
			$query .= "ORDER BY" . $this->indent(1) .
			          implode(",\n\t", $orderClauses) . $this->indent(0);
		}

		if ($offset > 0 or isset($limit)) {
			$query .= "LIMIT {$offset}" . (isset($limit) ? ", {$limit}" : "");
		}

		return $query;
	}

	public function indent($level = 0, $newLine = true) {
		return ($newLine ? "\n" : '') . str_repeat("\t", $this->indentationLevel + $level);
	}

	public function addressField($tableName, $columnName) {
		$alias = null;
		for ($builder = $this; isset($builder); $builder = $builder->parent)
			if (isset($builder->tableAliases[$tableName]))
				$alias = $builder->tableAliases[$tableName];

		if (is_null($alias)) {
			$alias = str_repeat("t", $this->depth + 1) . count($this->tableAliases);
			$this->tableAliases[$tableName] = $alias;
		}

		$fieldName = $this->provider->escapeIdentifier($alias) . "." . $this->provider->escapeIdentifier($columnName);
		return $fieldName;
	}

	public function escapeIdentifier($identifier) {
		return $this->provider->escapeIdentifier($identifier);
	}

	public function addBinding($value) {
		if (isset($this->parent))
			return $this->parent->addBinding($value);
		else {
			$key = ':b' . count($this->bindings);
			$this->bindings[$key] = $value;
			return $key;
		}
	}

	public function bindings() {
		return $this->bindings;
	}

	private $parent = null;
	private $indentationLevel = 0;
	private $depth = 0;
	private $tableAliases = [];
	private $provider;
	private $bindings = [];
	private $possibleGroupingExpressions = [];
	private $aggregation = false;
}
