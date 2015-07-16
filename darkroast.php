<?php

namespace DarkRoast;

require_once('IDataProvider.php');

interface IDarkRoast {
	/**
	 * Execute the build query and returns the result as an array.
	 * When the query contains placeholders, invoke this function with unbound arguments. Each placeholder
	 * N is replaced by the Nth argument.
	 * @param mixed ...$bindings Bound values for placeholders.
	 * @return array Result tuple.
	 */
	public function execute(...$bindings);
}

interface IReorderable {
	public function parenthesis();
}

interface ITerminalFieldExpression {
	public function alias();

	public function name($alias);

	public function sortAscending();

	public function sortDescending();
}

interface IEqualityFilterExpression {
	public function equals($operand);

	public function lessThan($operand);

	public function greaterThan($operand);

	public function lessOrEqualThan($operand);

	public function greaterOrEqualThan($operand);
}

interface IFieldExpression extends ITerminalFieldExpression, IReorderable, IEqualityFilterExpression {
	public function add($operand);

	public function minus($operand);

	public function multiply($operand);

	public function divide($operand);

	public function isDefined();

	public function isUndefined();
}

interface IAggregateableExpression extends IFieldExpression {
	public function sum();

	public function max();

	public function min();

	public function count();

	public function countUnique();

	public function group();
}

interface IBuilder {
	public function build($selectors, $filter = null, $groupFilter = null, $window = [0, null]);
}

interface IFilter extends IReorderable {
    public function _and($condition);

    public function _or($condition);

	public function not($condition);

	public function exists();
}

class Query {
	/**
	 * Add selectors to query.
	 * @param ITerminalFieldExpression ...$fields Selectors to add.
	 * @return $this
	 */
	public function select(ITerminalFieldExpression ...$fields) {
        $this->selectors = array_merge($this->selectors, $fields);

        return $this;
    }

    public function filter(IFilter ...$conditions) {
		$this->filter = null;
		$this->_and(...$conditions);

        return $this;
    }

	public function groupFilter(IFilter ...$conditions) {
		$this->groupFilter = null;
		$this->addConditions($conditions, "_and", $this->groupFilter);

		return $this;
	}

    public function _or(IFilter ...$conditions) {
        return $this->addConditions($conditions, "_or", $this->filter);
    }

    public function _and(IFilter ...$conditions) {
        return $this->addConditions($conditions, "_and", $this->filter);
    }

    public function build(IBuilder $builder) {
        return $builder->build($this->selectors, $this->filter, $this->groupFilter, $this->window);
    }

	public function table($provider) {
		$userFieldNum = 0;

		$fieldAliases = [];
		foreach ($this->selectors as &$selector) {
			$userFieldNum++;
			$alias = $selector->alias();
			if ($alias === '') {
				$alias = "UserField{$userFieldNum}";
				$selector = $selector->name($alias);
			}

			$fieldAliases[] = $alias;
		}

		return $provider->createTable($fieldAliases, $this);
	}

	/**
	 * Shorthand to build and execute query.
	 * @param $builder IBuilder
	 * @param ...$params mixed Unbound arguments forwarded to IDarkRoast::execute
	 * @see Query::build
	 * @see IDarkRoast::execute
	 * @return array Result tuple
	 */
	public function execute(IBuilder $builder, ...$params) {
		$darkRoast = $this->build($builder);
		return $darkRoast->execute(...$params);
	}

	/** Specify a window in the result tuple.
	 * @param $offset
	 * @param null $limit
	 * @return $this
	 */
	public function window($offset, $limit = null) {
		if (!is_int($offset)) throw new \InvalidArgumentException('Offset must be an integer.');
		if ($offset < 0) throw new \DomainException('Offset must be positive');
		if (isset($limit)) {
			if (!is_int($limit)) throw new \InvalidArgumentException('Limit must be an integer.');
			if ($limit < 0) throw new \DomainException('Offset must be positive');
		}

		$this->window = [$offset, $limit];
		return $this;
	}

    private function addConditions(array $conditions, $logicalOperator, &$targetFilter) {
        $firstCondition = isset($targetFilter) ? $targetFilter : p(array_shift($conditions));
	    $targetFilter = array_reduce($conditions, function($filter, $condition) use($logicalOperator) {
		    return $filter->$logicalOperator(p($condition));
	    }, $firstCondition);

        return $this;
    }

    private $selectors = [];
    private $filter = null;
	private $groupFilter = null;
	private $window = [0, null];
}

/**
 * @param ...$fields
 * @return Query
 */
function select(ITerminalFieldExpression ...$fields) {
    $query = new Query();

    return $query->select(...$fields);
}

/**
 * Constructs a table expression from a query.
 * The fields of the resulting table expression are defined by the selectors of the source query, and are applicable everywhere
 * where regular fields can be used.
 * If the x*th* selector is unnamed and no name can be inferred, the corresponding field in the table expression is named `UserField{x}`.
 * Note that execution of the source query is deferred and tied to execution of the query using the table expression.
 * For DBMS providers, table expressions are typically implemented as (correlated) sub-queries.
 * @param Query $query
 * @param IDataProvider $provider
 * @return mixed
 */
function table(Query $query, IDataProvider $provider) {
	return $query->table($provider);
}

function reduceFields(callable $func, ITerminalFieldExpression ...$fields) {
	if (count($fields) < 2) throw new \InvalidArgumentException('Operation requires at least two operands.');
	$firstField = array_shift($fields);
	return array_reduce($fields, $func, $firstField);
}

function sum(ITerminalFieldExpression ...$fields) {
	return reduceFields(function($carry, $fieldExpression) {
		return $carry->add($fieldExpression);
	}, ...$fields);
}


/**
 * Constructs an exists filter from one or multiple filter expressions which are concatenated using the logical `and` operator.
 * The resulting filter expressions is evaluated in the context of an anonymous table expression for each row of the enclosing query.
 * Any row of the enclosing query for which the table expression result tuple is empty is filtered out.
 * The exists filter is most useful when the supplied conditions correlate with the outer query.
 * @example "demo/basic.php" Exist filter in action.
 *
 * @param IFilter ...$conditions
 * @return mixed
 */
function exists(IFilter ...$conditions) {
	$firstCondition = array_shift($conditions);
	$condition = array_reduce($conditions, function($filter, $condition) {
		return $filter->_and($condition);
	}, $firstCondition);

	return $condition->exists();
}
function coalesce(array $array, $key, $default = null) {
	return isset($array[$key]) ? $array[$key] : $default;
}

function p(IReorderable $queryElement) {
	return $queryElement->parenthesis();
}