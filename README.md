*Dark Roast is not ready for production. There are most likely security issues, performance problems, and major bugs.* 

#Requirements
Dark Roast requires Php 5.6. No effort will be made to support older versions of Php. Dark Roast will use Php language features as made available in future Php releases. No effort will be made to remain backwards compatible. 

#Introduction
Dark Roast is a query builder for Php to retrieve data from data sources such as databases and Php associative arrays. Its main design goals are:

* Relief user from brittle query string manipulation.
* Eliminate sql-injections by automatically enforcing separation of query and user data through prepared statements.
* Provide a common interface for querying data sources such as databases and PHP associative arrays.

Dark Roast is best used to build dynamic queries. Dynamic means that the structure of the query depends on variables which are bound at run-time.

#Concepts
*Fields* are columns of data which are grouped into *tables*. Tables reside in data sources. *Selectors* define the columns of the query output table, typically by referencing fields. The associated tables of the fields referenced in the query selectors are automatically concatenated in a manner analogous to the cartesian product to form the *source table*, e.g. as in a SQL CROSS JOIN. *Filters* indicate which rows of the *source table* to keep in the final query output.

A *data provider* makes fields defined in data sources such as databases accessible to Dark Roast. Currently, only a MySQL data provider is implemented.
 
#Quick start
##Basic

Start by construction a data provider. Currently, only a MySQL PDO data provider is provided:
```
require_once('../database.php');    // Include the My-Sql Data Provider.  
use DarkRoast\DataBase\DataProvider as DataProvider;
$pdo = new PDO('mysql:host=localhost; dbname=recipe', "dev", "Ig8ajGd1vtZZSaa99kvZ");
$provider = new DataProvider($pdo);
```

Then, make the tables in our data source available as Php variables using the following idiom:
```
list($recipe, $tag) = $provider->reflectTable('recipe', 'recipe_tag');
```
The objects `$recipe` and `$tag` implement array-like access to *fields*; these fields can now be used in Dark Roast selector and filter expressions. 

To change the order of operations in selector and filter expressions, use the `p(.)` function to prioritize an expression.
Example:
```
select( p($recipe['cook_time']->add(3))->multiply(2) )
```

See the [basic demo](demo/basic.php) for a in-depth example.      

## Aggregates
Dark Roast supports aggregation of data over grouping variables. Call one of the aggregation functions `max`, `min`, `sum`, `count` or `countUnique` on fields in the select clause. Fields expressions whose `group` method is called define the groups over which the aggregation takes place. Only a single call to any of the aggregation functions or `group` can be made per field expression; this is enforced syntactically.

See the [aggregates demo](demo/aggregates.php) for an example.

## Terminal field methods
The methods `name`, `sortAscending`, or`sortDescending` are meant to be used at the end of a field expression. Resulting field expression of a call to one of the methods in the interface `ITerminalFieldExpression`, that is either `name`, `sortAscending`, or `sortDescending`, provide solely for further calls to members of `ITerminalFieldExpression`. 

## Join same table multiple times
To join a table multiple times, you need to copy it first using `copy()`; the resulting table expression can now be used in queries.
Example:
```
$recipe2 = $recipe->copy();
select($recipe['cook_time'],
       $recipe2['marinate_time'])
        ->filter($recipe['id']->equals($recipe2['id']));
```