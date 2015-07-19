<?php

namespace DarkRoast\MySQL;

require_once('darkroast.php');

use DarkRoast\IBuilder as IBuilder;

interface IQueryPart {
	public function evaluate(ISqlQueryBuilder $queryBuilder);

	public function isAggregate();
}

interface ISqlQueryBuilder extends IBuilder {
	public function createChild($indentationLevel = 0);

	public function indent($level = 0, $newLine = true);

	public function addressField($table, $columnName);

	public function addressUserField($query, $columnName);

	public function addGroupingField($fieldExpression);

	public function escapeIdentifier($identifier);

	public function addOrderByExpression($expression, $sortDirection);

	public function addBinding($value);

	public function bindings();
}