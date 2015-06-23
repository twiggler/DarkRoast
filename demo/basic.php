<?php

require_once('../database.php');    // Include the MySql Data Provider.

use function DarkRoast\select as select;
use function DarkRoast\exists as exists;
use function DarkRoast\sum as sum;
use function DarkRoast\coalesce as coalesce;
use DarkRoast\DataBase\DataProvider as DataProvider;
use DarkRoast\Placeholders as Placeholders;


$pdo = new PDO('mysql:host=localhost; dbname=recipe', "dev", "Ig8ajGd1vtZZSaa99kvZ");
$provider = new DataProvider($pdo);
list($recipe, $tag) = $provider->reflectTable('recipe', 'recipe_tag');

$params = array('offset' => 0, 'tag' => array(38, 39), 'video' => 'ja', 'limit' => 7);

$query = select($recipe->id,
                $recipe->title,
                $recipe->image_url->rename('imageUrl'),     // Field is designated 'imageUrl' in result tuple
                $recipe->summary,
                $recipe->ingredients);

$cookingTime = sum($recipe->prep_time, $recipe->cook_time, $recipe->prove_time, $recipe->marinate_time,
                   $recipe->rest_time, $recipe->chill_time, $recipe->soak_time);    // Define a new field

if (isset($params['maxcalories']))
	$query->where($recipe->kcals->lessThan($params['maxcalories']));

// The placeholder is bound on query execution.
// Query::_and calls Query::where when no filter is set.
$query->_and($cookingTime->lessThan(Placeholders::$_1));

$query->_and($recipe->group->equals(coalesce($params, 'group',
                                             null))); // Filter is optimized away when operand is null.


$query->_and($recipe->video->equals(isset($params['video']) ? 1 : null),
             $recipe->id->isDefined());     // Multiple filter expressions are by default combined using the and operator

if (isset($params['tag'])) {
	foreach ($params['tag'] as $tagId) {
		$existsFilter = exists($tag->recipe_id->equals($recipe->id),
		                       $tag->tag_id->equals($tagId));
		$query->_and($existsFilter);
	}
}

$query->orderBy($recipe->id)
      ->offset($params['offset'])
      ->limit(coalesce($params, 'limit'));

$darkRoast = $query->build($provider);
print_r($darkRoast->execute(60));           // cookingTime is less than 60 minutes.
echo $darkRoast->querySource();

/* Generated Query (verbatim):
SELECT
	`t0`.`id`,
	`t0`.`title`,
	`t0`.`image_url`  as  :b0,
	`t0`.`summary`,
	`t0`.`ingredients`
FROM
	`recipe` AS `t0`
WHERE
	`t0`.`prep_time` + `t0`.`cook_time` + `t0`.`prove_time` + `t0`.`marinate_time` + `t0`.`rest_time` + `t0`.`chill_time` + `t0`.`soak_time` < :_1 AND
	`t0`.`video` = :b1 AND
	`t0`.`id` is not null AND
	EXISTS (
		SELECT
			1
		FROM
			`recipe_tag` AS `tt0`
		WHERE
			`tt0`.`recipe_id` = `t0`.`id` AND
			`tt0`.`tag_id` = :b2
		) AND
	EXISTS (
		SELECT
			1
		FROM
			`recipe_tag` AS `tt0`
		WHERE
			`tt0`.`recipe_id` = `t0`.`id` AND
			`tt0`.`tag_id` = :b3
		)
ORDER BY
	`t0`.`id`
LIMIT 0, 7 */