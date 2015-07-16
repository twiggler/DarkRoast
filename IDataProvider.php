<?php
namespace DarkRoast;

interface IDataProvider {
	public function createTable($fieldNames, $query);

	public function reflectTable(...$tableNames);

	public function placeholder($index);
}