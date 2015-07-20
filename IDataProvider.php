<?php
namespace DarkRoast;

interface IDataProvider {
	public function createTable($fieldNames, $query);

	public function reflectTable(...$tableNames);

	public function recode(array $map, $_default);

	public function placeholder($index);
}