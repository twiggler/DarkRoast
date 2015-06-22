<?php



namespace DarkRoast;

interface IDarkRoast {
	public function execute(...$bindings);
}

interface IFieldExpression {
	public function equals($operand);

	public function lessThan($operand);

	public function add($operand);

	public function isDefined();

	public function isUndefined();

	public function rename($alias);

	public function ascending();

	public function descending();

	public function sum();

	public function max();

	public function min();

	public function count();

	public function countUnique();
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
    public function select(...$fields) {
        $this->selectors = array_merge($this->selectors, $fields);

        return $this;
    }

    public function where(...$conditions) {
		$this->filter = null;
		$this->_and(...$conditions);

        return $this;
    }

    public function _or(...$conditions) {
        return $this->addConditions($conditions, "_or");

    }

    public function _and(...$conditions) {
        return $this->addConditions($conditions, "_and");
    }

    public function build($provider) {
        return $provider->prepareQuery($this->selectors, $this->filter, $this->offset, $this->limit, $this->sortFields);
    }

	public function execute(...$params) {
		$darkRoast = $this->build(array_shift($params));
		return $darkRoast->execute(...$params);
	}

    public function offset($offset) {
        if (!is_int($offset)) throw new InvalidArgumentException('Offset must be an integer.');
        if ($offset < 0) throw new DomainException('Offset must be positive');

        $this->offset = $offset;

        return $this;
    }

    public function limit($limit) {
        if (isset($limit)) {
	        if (!is_int($limit)) throw new InvalidArgumentException('Limit must be an integer.');
	        if ($limit < 0) throw new DomainException('Offset must be positive');
        }

        $this->limit = $limit;

        return $this;
    }

    public function orderBy(...$sortFields) {
        $this->sortFields = array_merge($this->sortFields, $sortFields);

        return $this;
    }

    private function addConditions($conditions, $logicalOperator) {
        $firstCondition = isset($this->filter) ? $this->filter : array_shift($conditions);
	    $this->filter = array_reduce($conditions, function($filter, $condition) use($logicalOperator) {
		    return $filter->$logicalOperator($condition);
	    }, $firstCondition);

        return $this;
    }

    private $selectors = [];
    private $filter;
    private $offset;
    private $limit;
    private $sortFields = [];
}

function select(...$fields) {
    $query = new Query();

    return $query->select(...$fields);
}

function sum(...$fields) {
    if (count($fields) < 2) throw new InvalidArgumentException('Sum requires at least two operands.');

    $firstField = array_shift($fields);
    return array_reduce($fields, function($carry, $fieldExpression) {
        return $carry->add($fieldExpression);
    }, $firstField);
}

function exists(...$conditions) {
	$firstCondition = array_shift($conditions);
	$condition = array_reduce($conditions, function($filter, $condition) {
		return $filter->_and($condition);
	}, $firstCondition);

	return $condition->exists();
}

function coalesce($array, $key, $default = null) {
	return isset($array[$key]) ? $array[$key] : $default;
}
