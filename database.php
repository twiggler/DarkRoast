<?php

namespace DarkRoast\DataBase;

use DarkRoast\IBuilder;
use DarkRoast\IDarkRoast;
use DarkRoast\IFieldExpression;
use DarkRoast\IFilter;
use DarkRoast\IImmutableFieldExpression;

require_once('darkroast.php');

interface IQueryPart {
	public function evaluate(SqlQueryBuilder $queryBuilder);
}

abstract class ImmutableFieldExpression implements IQueryPart, IImmutableFieldExpression {
	public function rename($alias) {
		return new TerminalField($this, $alias);
	}

	public function sortAscending() {
		return new TerminalField($this, null, TerminalField::ASCENDING);
	}

	public function sortDescending() {
		return new TerminalField($this, null, TerminalField::DESCENDING);
	}

	public function groupBy() {
		return new TerminalField($this, null, null, true);
	}
}

abstract class FieldExpression extends ImmutableFieldExpression implements IFieldExpression {
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

	public function alias(){
		return $this->columnName;
	}

	private $tableName;
	private $columnName;
}

class UserField extends FieldExpression {
	function __construct($columnName, $query) {
		$this->columnName = $columnName;
		$this->query = $query;
	}

	public function evaluate(SqlQueryBuilder $queryBuilder) {
		return $queryBuilder->addressUserField($this->query, $this->columnName);
	}

	public function alias() {
		return $this->columnName;
	}


	private $columnName;
	private $query;
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

	public function alias() {
		return "";
	}

	private $field;
	private $function;
	private $distinct;
}

class ConstantField extends FieldExpression {
	function __construct($expression) {
		$this->expression = $expression;
	}

	public function evaluate(SqlQueryBuilder $queryBuilder) {
		return strval($this->expression);
	}

	public function alias() {
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

	public function alias(){
		return "";
	}

	private $field1;
	private $field2;
	private $operator;
}

class TerminalField implements IQueryPart, IImmutableFieldExpression {
	const ASCENDING = 1;
	const DESCENDING = -1;

	function __construct($field, $alias, $sortDirection = null, $groupingField = false) {
		$this->field = $field;
		$this->alias = $alias;
		$this->sortDirection = $sortDirection;
		$this->groupingField = $groupingField;
	}

	public function groupBy() {
		$this->groupingField = true;
		return $this;
	}

	public function alias() {
		return isset($this->alias) ? $this->alias : $this->field->alias();
	}

	public function rename($alias) {
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

	public function evaluate(SqlQueryBuilder $queryBuilder) {
		$fieldExpression = $this->field->evaluate($queryBuilder);

		if ($this->groupingField)
			$queryBuilder->addGroupingField($fieldExpression);

		if (isset($this->sortDirection))
			$queryBuilder->addOrderByExpression($fieldExpression . " " . ($this->sortAscending === self::ASCENDING ? "ASC" : "DESC"));

		if (isset($this->alias))
			$fieldExpression .= " AS {$this->alias}";

		return $fieldExpression;
	}

	private $field;
	private $alias;
	private $groupingField;
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

class ExistsFilter extends Filter implements IQueryPart {
	function __construct($condition) {
		$this->condition = $condition;
	}

	function evaluate(SqlQueryBuilder $queryBuilder) {
		$subQueryBuilder = $queryBuilder->createChild(1, true /* Correlated sub-query */);
		$subQuerySql = $subQueryBuilder->build([new ConstantField(1)], $this->condition);
		return "EXISTS (" .  $subQuerySql . ")";
	}

	private $condition;
}

class DarkRoast implements IDarkRoast {
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

class SqlQueryBuilder implements IBuilder {
	function __construct(DataProvider $provider) {
		$this->provider = $provider;
		$this->userTables = new \SplObjectStorage();
	}

	public function createChild($indentationLevel = 0, $correlatedQuery = false) {
		$childBuilder = new SqlQueryBuilder($this->provider);
		$childBuilder->parent = $this;
		$childBuilder->depth = $this->depth + 1;
		$childBuilder->indentationLevel = $this->indentationLevel + $indentationLevel + 1;
		$childBuilder->correlatedQuery = $correlatedQuery;

		return $childBuilder;
	}

	public function build($selectors, $filter = null, $groupFilter = null, $offset = 0, $limit = null) {
		$selectClauses = array_map(function ($queryElement) {
			return $queryElement->evaluate($this);
		}, $selectors);

		$whereClause = isset($filter) ? $filter->evaluate($this) : null;

		$havingClause = isset($groupFilter) ? $groupFilter->evaluate($this) : null;

		$fromClause = [];
		foreach ($this->tableAliases as $tableName => $tableAlias) {
			$fromClause[] = $this->provider->escapeIdentifier($tableName) . " AS " . $this->provider->escapeIdentifier($tableAlias);
		}

		foreach ($this->userTables as $tableQuery) {
			$builder = $this->createChild();
			$userTableSql = $tableQuery->build($builder);
			$fromClause[] = "( {$userTableSql} ) AS {$this->userTables[$tableQuery]}";
		}

		$query = $this->indent(0, $this->indentationLevel > 0) .
		         "SELECT" . $this->indent(1) .
		         implode(',' . $this->indent(1), $selectClauses) . $this->indent(0) .
		         "FROM" . $this->indent(1) .
		         implode($this->indent(1) . "CROSS JOIN ", $fromClause) . $this->indent(0);

		if (isset($whereClause))
			$query .= "WHERE" . $this->indent(1) . $whereClause . $this->indent(0);

		if (count($this->groupingFields)) {
			$query .= "GROUP BY" . $this->indent(1) .
			          implode(',' . $this->indent(1), array_keys($this->groupingFields)) . $this->indent(0);
		}

		if (isset($havingClause))
			$query .= "HAVING " . $this->indent(1) . $havingClause . $this->indent(0);

		if (count($this->orderClauses) > 0) {
			$query .= "ORDER BY" . $this->indent(1) .
			          implode(",\n\t", $this->orderClauses) . $this->indent(0);
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
		if (isset($this->tableAliases[$tableName]))
			$alias = $this->tableAliases[$tableName];
		elseif ($this->correlatedQuery and isset($this->parent) and isset($this->parent->tableAliases[$tableName]))
			$alias = $this->parent->tableAliases[$tableName];
		else {
			$alias = str_repeat('t', $this->depth + 1) . count($this->tableAliases);
			$this->tableAliases[$tableName] = $alias;
		}

		$fieldName = $this->provider->escapeIdentifier($alias) . "." . $this->provider->escapeIdentifier($columnName);
		return $fieldName;
	}

	public function addressUserField($query, $columnName) {
		if (isset($this->userTables[$query]))
			$alias = $this->userTables[$query];
		elseif ($this->correlatedQuery and isset($this->parent) and isset($this->parent->userTables[$query]))
			$alias = $this->parent->userTables[$query];
		else {
			$alias = str_repeat('u', $this->depth + 1) . count($this->userTables);
			$this->userTables[$query] = $alias;
		}

		$fieldName = $this->provider->escapeIdentifier($alias) . "." . $this->provider->escapeIdentifier($columnName);
		return $fieldName;
	}

	public function addGroupingField($fieldExpression) {
		$this->groupingFields[$fieldExpression] = null;
	}

	public function escapeIdentifier($identifier) {
		return $this->provider->escapeIdentifier($identifier);
	}

	public function addOrderByExpression($expression) {
		$this->orderClauses[] = $expression;
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
	private $userTables;
	private $provider;
	private $bindings = [];
	private $groupingFields = [];
	private $orderClauses = null;
	private $correlatedQuery = false;
}

class DataProvider implements IBuilder {
	function __construct(\PDO $pdo) {
		$this->pdo = $pdo;
	}

	public function build($selectors, $filter = null, $groupFilter = null, $offset = 0, $limit = null) {
		$queryBuilder = new SqlQueryBuilder($this);
		$sqlStatement = $queryBuilder->build($selectors, $filter, $groupFilter, $offset, $limit);
		return new DarkRoast($sqlStatement, $this->pdo->prepare($sqlStatement), $queryBuilder->bindings());
	}

	public function escapeIdentifier($identifier) {
		return "`" . str_replace("`", "``", $identifier) . "`";
	}

	public function createTable($fieldNames, $query) {
		$table = new \stdClass();
		foreach ($fieldNames as $fieldName) {
			if ($fieldName !== '')
				$table->{$fieldName} = new UserField($fieldName, $query);
		}

		return $table;
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
