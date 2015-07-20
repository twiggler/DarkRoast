<?php

namespace DarkRoast\MySQL;

require_once('fields.php');

use DarkRoast\IBuilder;
use DarkRoast\IDarkRoast;
use DarkRoast\IDataProvider;

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

class DarkRoast implements IDarkRoast {
	function __construct($querySource, \PDOStatement $pdoPreparedQuery, $bindings) {
		$this->querySource = $querySource;
		$this->pdoPreparedQuery = $pdoPreparedQuery;
		$this->bindValues($bindings);
	}

	private function bindValues($bindings) {
		array_walk($bindings, function ($value, $key) {
			$paramType = \PDO::PARAM_STR;
			if (is_integer($value)) $paramType = \PDO::PARAM_INT;
			$this->pdoPreparedQuery->bindValue($key, $value, $paramType);
		}, $bindings);
	}

	public function execute(...$bindings) {
		$keyedBindings = [];
		for ($keyCounter = 0; $keyCounter < count($bindings); $keyCounter++) {
			$key = ":_" . ($keyCounter + 1);
			$keyedBindings[$key] = $bindings[$keyCounter];
		}
		$this->bindValues($keyedBindings);

		$this->pdoPreparedQuery->execute();

		return $this->pdoPreparedQuery->fetchAll(\PDO::FETCH_ASSOC);
	}

	public function querySource() {
		return $this->querySource;
	}

	private $pdoPreparedQuery;
	private $querySource;
}

class SqlQueryBuilder implements ISqlQueryBuilder {
	function __construct(IDataProvider $provider) {
		$this->provider = $provider;
		$this->tableAliases = new \SplObjectStorage();
		$this->userTables = new \SplObjectStorage();
	}

	public function createChild($indentationLevel = 0) {
		$childBuilder = new SqlQueryBuilder($this->provider);
		$childBuilder->parent = $this;
		$childBuilder->depth = $this->depth + 1;
		$childBuilder->indentationLevel = $this->indentationLevel + $indentationLevel + 1;

		return $childBuilder;
	}

	public function build($selectors, $filter = null, $groupFilter = null, $window = [0, null]) {
		$this->validateQuery($selectors, $filter, $groupFilter);

		$selectClauses = array_map(function ($queryElement) {
			return $queryElement->evaluate($this);
		}, $selectors);

		$whereClause = isset($filter) ? $filter->evaluate($this) : null;

		$havingClause = isset($groupFilter) ? $groupFilter->evaluate($this) : null;

		$orderClause = [];
		foreach (isset($this->sortFields) ? $this->sortFields : [] as $fieldExpression => $sortDirection) {
			$orderClause[] = $fieldExpression . ' ' . ($sortDirection === TerminalField::ASCENDING ? "ASC" : "DESC");
		}

		$fromClause = [];
		foreach ($this->tableAliases as $table) {   // SplObjectStorage does not allow for key/value foreach
			$fromClause[] = $this->provider->escapeIdentifier($table->name()) . " AS " . $this->provider->escapeIdentifier($this->tableAliases[$table]);
		}

		foreach ($this->userTables as $tableQuery) {
			$builder = $this->createChild(1);
			$userTableSql = $tableQuery->build($builder);
			$fromClause[] = "( {$userTableSql}" . $this->indent(1) . ") AS {$this->userTables[$tableQuery]}";
		}

		$query = $this->indent(0, $this->indentationLevel > 0) .
		         "SELECT" . $this->indent(1) .
		         implode(',' . $this->indent(1), $selectClauses) . $this->indent(0) .
		         "FROM" . $this->indent(1) .
		         implode($this->indent(1) . "CROSS JOIN ", $fromClause);

		if (!empty($whereClause))
			$query .= $this->indent(0) . "WHERE" . $this->indent(1) . $whereClause;

		if (count($this->groupingFields)) {
			$query .= $this->indent(0) . "GROUP BY" . $this->indent(1) .
			          implode(',' . $this->indent(1), array_keys($this->groupingFields));
		}

		if (isset($havingClause))
			$query .=  $this->indent(0) . "HAVING " . $this->indent(1) . $havingClause;

		if (count($orderClause) > 0) {
			$query .= $this->indent(0) . "ORDER BY" . $this->indent(1) .
			          implode(",\n\t", $orderClause);
		}

		if ($window[0] > 0 or isset($window[1])) {
			$query .= $this->indent(0) . "LIMIT {$window[0]}" . (isset($window[1]) ? ", {$window[1]}" : "");
		}

		if (is_null($this->parent)) $query .= "\n";

		return $query;
	}

	public function indent($level = 0, $newLine = true) {
		return ($newLine ? "\n" : '') . str_repeat("\t", $this->indentationLevel + $level);
	}

	public function addressField($table, $columnName) {
		if (isset($this->tableAliases[$table]))
			$alias = $this->tableAliases[$table];
		elseif (isset($this->parent) and isset($this->parent->tableAliases[$table]))
			$alias = $this->parent->tableAliases[$table];
		else {
			$alias = str_repeat('t', $this->depth + 1) . count($this->tableAliases);
			$this->tableAliases[$table] = $alias;
		}

		return $this->provider->escapeIdentifier($alias) . "." . $this->provider->escapeIdentifier($columnName);
	}

	public function addressUserField($query, $columnName) {
		if (isset($this->userTables[$query]))
			$alias = $this->userTables[$query];
		elseif (isset($this->parent) and isset($this->parent->userTables[$query]))
			$alias = $this->parent->userTables[$query];
		else {
			$alias = str_repeat('u', $this->depth + 1) . count($this->userTables);
			$this->userTables[$query] = $alias;
		}

		return $this->provider->escapeIdentifier($alias) . "." . $this->provider->escapeIdentifier($columnName);
	}

	public function addGroupingField($fieldExpression) {
		$this->groupingFields[$fieldExpression] = null;
	}

	public function escapeIdentifier($identifier) {
		return $this->provider->escapeIdentifier($identifier);
	}

	public function addOrderByExpression($expression, $sortDirection) {
		$this->sortFields[$expression] = $sortDirection;
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

	private static function validateQuery($selectors, $filter, $groupFilter) {
		if (is_null($selectors)) throw new \InvalidArgumentException('At least one selector is required.');

		$aggregatedQuery = reset($selectors)->isAggregate();
		while ($selector = next($selectors)) {
			if ($selector->isAggregate() xor $aggregatedQuery)
				throw new \InvalidArgumentException('Aggregate and non-aggregate fields cannot be mixed.');
		}

		if (isset($filter) and $filter->isAggregate())
			throw new \InvalidArgumentException('Filter is a group filter.');

		if (isset($groupFilter) and $groupFilter->isAggregate() === FALSE)
			throw new \InvalidArgumentException('Group filter is a filter');
	}

	private $parent = null;
	private $indentationLevel = 0;
	private $depth = 0;
	private $tableAliases;
	private $userTables;
	private $provider;
	private $bindings = [];
	private $groupingFields = [];
	private $sortFields = null;
}

class DataProvider implements IBuilder, IDataProvider {
	function __construct(\PDO $pdo) {
		$this->pdo = $pdo;
	}

	public function build($selectors, $filter = null, $groupFilter = null, $window = [0, null]) {
		$queryBuilder = new SqlQueryBuilder($this);
		$sqlStatement = $queryBuilder->build($selectors, $filter, $groupFilter, $window);
		return new DarkRoast($sqlStatement, $this->pdo->prepare($sqlStatement), $queryBuilder->bindings());
	}

	public function escapeIdentifier($identifier) {
		return "`" . str_replace("`", "``", $identifier) . "`";
	}

	public function createTable($fieldNames, $query) {
		$table = [];
		foreach ($fieldNames as $fieldName) {
			$table[$fieldName] = new UserField($fieldName, $query);
		}

		return $table;
	}

	public function reflectTable(...$tableNames) {
		$tables = array_map(function ($tableName) {
			$table = new Table($tableName);

			$query = "show columns from " . $this->escapeIdentifier($tableName);
			foreach ($this->pdo->query($query) as $column) {
				$columnName = reset($column);
				$table[$columnName] = new Field($table, $columnName);
			};

			return $table;
		}, $tableNames);

		return count($tables) > 1 ? $tables : reset($tables);
	}

	public function placeholder($index) {
		return new Placeholder($index);
	}

	public function recode(array $map, $_default) {
		return new RecodedField($map, $_default);
	}

	private $pdo;
}

function evaluate($queryPart, $queryBuilder) {
	return (is_string($queryPart) or is_numeric($queryPart)) ? $queryBuilder->addBinding($queryPart) :
															   $queryPart->evaluate($queryBuilder);
}

function isAggregate($queryPart) {
	return (is_string($queryPart) or is_numeric($queryPart)) ? null : $queryPart->isAggregate($queryPart);
}