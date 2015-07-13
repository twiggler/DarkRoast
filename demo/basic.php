<?php

require_once('providers/mysql/mysql.php');   // Include the MySql Data Provider.

use function DarkRoast\select as select;
use function DarkRoast\exists as exists;
use function DarkRoast\sum as sum;
use function DarkRoast\coalesce as coalesce;
use DarkRoast\MySQL\DataProvider as DataProvider;
use DarkRoast\Placeholders as Placeholders;


$pdo = new PDO('mysql:host=localhost; dbname=recipe', "dev", "Ig8ajGd1vtZZSaa99kvZ");
$provider = new DataProvider($pdo);
list($recipe, $recipe_tag, $tag) = $provider->reflectTable('recipe', 'recipe_tag', 'tag');

$params = ['offset' => 0, 'tag' => [38, 39], 'video' => 'ja', 'limit' => 7];

$query = select($recipe['id']->sortAscending(),
                $recipe['title'],
                $recipe['image_url']->name('imageUrl'),     // Field is designated 'imageUrl' in result tuple
                $recipe['summary'],
                $recipe['ingredients']);

$cookingTime = sum($recipe['prep_time'], $recipe['cook_time'], $recipe['prove_time'], $recipe['marinate_time'],
                   $recipe['rest_time'], $recipe['chill_time'], $recipe['soak_time']);    // Define a new field

if (isset($params['maxcalories']))
	$query->filter($recipe['kcals']->lessThan($params['maxcalories']));

// The placeholder is bound on query execution.
// Query::_and calls Query::filter when no filter is set.
$query->_and($cookingTime->lessOrEqualThan($provider->placeholder(1)));

$query->_and($recipe['group']->equals(coalesce($params, 'group',
                                             null))); // Filter is optimized away when operand is null.


$query->_and($recipe['video']->equals(isset($params['video']) ? 1 : null),
             $recipe['id']->isDefined());     // Multiple filter expressions are by default combined using the and operator

if (isset($params['tag'])) {
	foreach ($params['tag'] as $tagId) {
		$existsFilter = exists($recipe_tag['recipe_id']->equals($recipe['id']),
		                       $recipe_tag['tag_id']->equals($tag['id'])->equals($tagId));
		$query->_and($existsFilter);
	}
}

$query->window($params['offset'], coalesce($params, 'limit'));

$darkRoast = $query->build($provider);
print_r($darkRoast->execute(60));           // cookingTime is less than 60 minutes.
echo $darkRoast->querySource();

/* Generated Query:
SELECT
	`t0`.`id`,
	`t0`.`title`,
	`t0`.`image_url` AS imageUrl,
	`t0`.`summary`,
	`t0`.`ingredients`
FROM
	`recipe` AS `t0`
WHERE
	(`t0`.`prep_time` + `t0`.`cook_time` + `t0`.`prove_time` + `t0`.`marinate_time` + `t0`.`rest_time` + `t0`.`chill_time` + `t0`.`soak_time` <= :_1) AND
	(`t0`.`video` = :b0) AND
	(`t0`.`id` is not null) AND
	(EXISTS (
		SELECT
			1
		FROM
			`recipe_tag` AS `tt0`
			CROSS JOIN `tag` AS `tt1`
		WHERE
			`tt0`.`recipe_id` = `t0`.`id` AND
			`tt0`.`tag_id` = `tt1`.`id` AND
			`tt1`.`id` = :b1
		)) AND
	(EXISTS (
		SELECT
			1
		FROM
			`recipe_tag` AS `tt0`
			CROSS JOIN `tag` AS `tt1`
		WHERE
			`tt0`.`recipe_id` = `t0`.`id` AND
			`tt0`.`tag_id` = `tt1`.`id` AND
			`tt1`.`id` = :b2
		))
ORDER BY
	`t0`.`id` ASC
LIMIT 0, 7 */