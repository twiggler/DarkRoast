<?php

require_once('../database.php');
use function DarkRoast\select as select;

$pdo = new PDO('mysql:host=localhost; dbname=recipe', "dev", "Ig8ajGd1vtZZSaa99kvZ");
$provider = new \DarkRoast\DataBase\DataProvider($pdo);
$recipe = $provider->reflectTable('recipe');

$aggregates = select($recipe->cook_time->max()->rename('maxCookingTime'),
                     $recipe->prep_time->min(),
                     $recipe->id->count(),
                     $recipe->rest_time->sum()->rename('sumRestTime'),
                     $recipe->group->groupBy()) // Aggregate by $recipe->group
				->groupFilter($recipe->cook_time->max()->lessThan(70))
				->table($provider);

$query = select($recipe->title,
                $aggregates->maxCookingTime,
                $aggregates->sumRestTime)
		->filter($recipe->group->equals($aggregates->group));

$darkRoast = $query->build($provider);
echo $darkRoast->querySource();
print_r($darkRoast->execute());

/* Generated Query (verbatim):
SELECT
	`t0`.`title`,
	`u0`.`maxCookingTime`,
	`u0`.`sumRestTime`
FROM
	`recipe` AS `t0`
	CROSS JOIN (
	SELECT
		max(`tt0`.`cook_time`) AS maxCookingTime,
		min(`tt0`.`prep_time`),
		count(`tt0`.`id`),
		sum(`tt0`.`rest_time`) AS sumRestTime,
		`tt0`.`group`
	FROM
		`recipe` AS `tt0`
	GROUP BY
		`tt0`.`group`
	HAVING
		max(`tt0`.`cook_time`) < :b0
	 ) AS u0
WHERE
	`t0`.`group` = `u0`.`group` */