<?php

namespace DarkRoast;

interface IDarkRoast {
	/**
	 * Execute the build query and returns the result as an array.
	 * When the query contains placeholders, invoke this function with unbound arguments. Each placeholder
	 * _N is replaced by the Nth argument.
	 * @param mixed ...$bindings Bound values for placeholders.
	 * @return array Result tuple.
	 */
	public function execute(...$bindings);
}

interface ITerminalFieldExpression {
	public function alias();

	public function name($alias);

	public function sortAscending();

	public function sortDescending();
}

interface IFieldExpression extends ITerminalFieldExpression {
	public function equals($operand);

	public function lessThan($operand);

	public function add($operand);

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
	public function build($selectors, $filter = null, $groupFilter = null, $offset = 0, $limit = null);
}

interface IFilter
{
    public function _and($condition);

    public function _or($condition);

	public function exists();
}

class Placeholder {
    private $_index;

    function __construct($index)
    {
        $this->_index = $index;
    }

    public function index() {
        return $this->_index;
    }
}

class Placeholders {
    static public $_1;
    static public $_2;
    static public $_3;
    static public $_4;
    static public $_5;
    static public $_6;
    static public $_7;
    static public $_8;
    static public $_9;
}

Placeholders::$_1 = new Placeholder(1);
Placeholders::$_2 = new Placeholder(2);
Placeholders::$_3 = new Placeholder(3);
Placeholders::$_4 = new Placeholder(4);
Placeholders::$_5 = new Placeholder(5);
Placeholders::$_6 = new Placeholder(6);
Placeholders::$_7 = new Placeholder(7);
Placeholders::$_8 = new Placeholder(8);
Placeholders::$_9 = new Placeholders(9);

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
        return $builder->build($this->selectors, $this->filter, $this->groupFilter, $this->offset, $this->limit);
    }

	public function table($provider) {
		return $provider->createTable(array_map(function($selector) use($provider) {
			return $selector->alias($provider);
		}, $this->selectors), $this);
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

    public function offset($offset) {
        if (!is_int($offset)) throw new \InvalidArgumentException('Offset must be an integer.');
        if ($offset < 0) throw new \DomainException('Offset must be positive');

        $this->offset = $offset;

        return $this;
    }

    public function limit($limit) {
        if (isset($limit)) {
	        if (!is_int($limit)) throw new \InvalidArgumentException('Limit must be an integer.');
	        if ($limit < 0) throw new \DomainException('Offset must be positive');
        }

        $this->limit = $limit;

        return $this;
    }

    private function addConditions(array $conditions, $logicalOperator, &$targetFilter) {
        $firstCondition = isset($targetFilter) ? $targetFilter : array_shift($conditions);
	    $targetFilter = array_reduce($conditions, function($filter, $condition) use($logicalOperator) {
		    return $filter->$logicalOperator($condition);
	    }, $firstCondition);

        return $this;
    }

    private $selectors = [];
    private $filter = null;
	private $groupFilter = null;
    private $offset = 0;
    private $limit = null;
}

/**
 * @param ...$fields
 * @return Query
 */
function select(ITerminalFieldExpression ...$fields) {
    $query = new Query();

    return $query->select(...$fields);
}

function table(Query $query, $provider) {
	return $query->table($provider);
}

function sum(ITerminalFieldExpression ...$fields) {
    if (count($fields) < 2) throw new InvalidArgumentException('Sum requires at least two operands.');

    $firstField = array_shift($fields);
    return array_reduce($fields, function($carry, $fieldExpression) {
        return $carry->add($fieldExpression);
    }, $firstField);
}

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
