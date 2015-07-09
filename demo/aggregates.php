<?php

require_once('providers/mysql/mysql.php');

use function DarkRoast\select as select;
use function DarkRoast\table as table;

$pdo = new PDO('mysql:host=localhost; dbname=recipe', "dev", "Ig8ajGd1vtZZSaa99kvZ");
$provider = new \DarkRoast\MySQL\DataProvider($pdo);
$recipe = $provider->reflectTable('recipe');

$recipe2 = $recipe->copy();
$aggregates = table(select($recipe2['cook_time']->max()
                                                ->name('maxCookingTime'),
                           $recipe2['prep_time']->min(),
                           $recipe2['id']->count(),
                           $recipe2['rest_time']->sum()
                                                ->name('sumRestTime'),
                           $recipe2['group']->group())->groupFilter($recipe2['cook_time']->max()
                                                                                         ->lessThan(70)),
                    $provider);

$query = select($recipe['title'],
                $aggregates['maxCookingTime']->sortDescending(),
                $aggregates['maxCookingTime']->sortAscending(), // Last sort takes precedence.
                $aggregates['sumRestTime'])->filter($recipe['group']->equals($aggregates['group']));

$darkRoast = $query->build($provider);
echo $darkRoast->querySource();
print_r($darkRoast->execute());

/* Generated Query (verbatim):
SELECT
	`t0`.`title`,
	`u0`.`maxCookingTime`,
	`u0`.`maxCookingTime`,
	`u0`.`sumRestTime`
FROM
	`recipe` AS `t0`
	CROSS JOIN (
	SELECT
		max(`tt0`.`cook_time`) AS maxCookingTime,
		min(`tt0`.`prep_time`) AS UserField2,
		count(`tt0`.`id`) AS UserField3,
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
	`t0`.`group` = `u0`.`group`
ORDER BY
	`u0`.`maxCookingTime` ASC */