<?php

require_once('../database.php');
use function DarkRoast\select as select;

$pdo = new PDO('mysql:host=localhost; dbname=recipe', "dev", "Ig8ajGd1vtZZSaa99kvZ");
$provider = new \DarkRoast\DataBase\DataProvider($pdo);
$recipe = $provider->reflectTable('recipe');

$query = select($recipe->cook_time->max(),
				$recipe->prep_time->min(),
				$recipe->id->count(),
				$recipe->rest_time->sum(),
				$recipe->group);        // Aggregate by $recipe->group

$darkRoast = $query->build($provider);
echo $darkRoast->querySource();
print_r($darkRoast->execute());

/* Generated Query (verbatim):
SELECT
	max(`t0`.`cook_time`),
	min(`t0`.`prep_time`),
	count(`t0`.`id`),
	sum(`t0`.`rest_time`),
	`t0`.`group`
FROM
	`recipe` AS `t0`
GROUP BY
	`t0`.`group` */