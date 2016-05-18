<?php 
namespace Eav\Database\Query;

use Closure;
use Eav\Entity;
use Eav\ProcessAttributes;
use Eav\Traits\Attribute;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Database\Query\Builder as QueryBuilder;

class Builder extends QueryBuilder
{
    use Attribute;
            
    protected $isProcessed = false;
            
    public $hasAttributeConditions = false;
    
    /**
     * The where constraints for the query.
     *
     * @var array
     */
    public $attributeWheres;

    public $attributeOrderBy;

    public $attributeColumns;
    
    protected $baseEntity;

    public $joinCache = [];

    
    /**
     * Create a new query builder instance.
     *
     * @param  \Illuminate\Database\ConnectionInterface  $connection
     * @param  \Illuminate\Database\Query\Grammars\Grammar  $grammar
     * @param  \Illuminate\Database\Query\Processors\Processor  $processor
     * @return void
     */
    public function __construct(ConnectionInterface $connection,
                                Grammar $grammar = null,
                                Processor $processor = null,
                                Entity $baseEntity = null)
    {
        $this->connection = $connection;
        $this->grammar = $grammar ?: $connection->getQueryGrammar();
        $this->processor = $processor ?: $connection->getPostProcessor();
        $this->baseEntity = $baseEntity;
    }
    
    public function baseEntity()
    {
        return $this->baseEntity;
    }
    
    /**
     * Insert a new record into the database.
     *
     * @param  array  $values
     * @return bool
     */
    public function getInsertSql(array $values)
    {
        if (empty($values)) {
            return true;
        }

        // Since every insert gets treated like a batch insert, we will make sure the
        // bindings are structured in a way that is convenient for building these
        // inserts statements by verifying the elements are actually an array.
        if (! is_array(reset($values))) {
            $values = [$values];
        }

        // Since every insert gets treated like a batch insert, we will make sure the
        // bindings are structured in a way that is convenient for building these
        // inserts statements by verifying the elements are actually an array.
        else {
            foreach ($values as $key => $value) {
                ksort($value);
                $values[$key] = $value;
            }
        }

        // We'll treat every insert like a batch insert so we can easily insert each
        // of the records into the database consistently. This will make it much
        // easier on the grammars to just handle one type of record insertion.
        $bindings = [];

        foreach ($values as $record) {
            foreach ($record as $value) {
                $bindings[] = $value;
            }
        }

        $sql = $this->grammar->compileInsert($this, $values);

        // Once we have compiled the insert statement's SQL we can execute it on the
        // connection and return a result as a boolean success indicator as that
        // is the same type of result returned by the raw connection instance.
        $bindings = $this->cleanBindings($bindings);
        
        
        foreach ($bindings as $binding) {
            $value = is_numeric($binding) ? $binding : "'".$binding."'";
            $sql = preg_replace('/\?/', $value, $sql, 1);
        }
        
        return $sql;
    }

    public function processAttributes($loadedAttributes = null, $noJoin = false)
    {
        if (!$this->hasAttributeConditions || $this->isProcessed) {
            return;
        }

        if (!$loadedAttributes) {
            $filterAttr = (array) $this->attributeColumns['columns'];
            $loadedAttributes = $this->loadAttributes($filterAttr);
        }
        
        ProcessAttributes::process($this, $loadedAttributes, $this->baseEntity(), $noJoin);
        $this->isProcessed = true;
    }

    protected function fixColumns()
    {
        if (!$this->baseEntity()) {
            return;
        }

        $columns = $this->columns;
        if ($columns == ['attr.*'] || $columns == 'attr.*') {
            $loadedAttributes = $this->loadAttributes();
            $columns = ["{$this->from}.*"];
            $loadedAttributes->each(function ($attribute, $key) use (&$columns) {
                if (!$attribute->isStatic()) {
                    $columns[] = $attribute->setEntity($this->baseEntity())
                        ->addAttributeJoin($this,'left')->getSelectColumn();
                }
            });
        } else {
            $filterAttr = (array) $this->attributeColumns['columns'];

            $loadedAttributes = null;

            if ($columns != ['*']) {
            	$orgColumns = (array) $columns;
                $filterAttr = array_merge($filterAttr, $orgColumns);
                $loadedAttributes = $this->loadAttributes($filterAttr);
            }

            $columns = ["{$this->from}.*"];
            if ($loadedAttributes) {
                $loadedAttributes->each(function ($attribute, $key) use (&$columns, $orgColumns) {
                    if (!$attribute->isStatic()) {                    
                    	if(in_array($attribute->getAttributeCode(),$orgColumns)) {
                    		$type = 'left';	
                    	} else {
                    		$type = 'inner';	
                    	}
                        $columns[] = $attribute->setEntity($this->baseEntity())
                            ->addAttributeJoin($this,$type)->getSelectColumn();
                    }
                });
            }
        }
        
        $this->columns = $columns;

        return $loadedAttributes;
    }
    

     /**
     * Get the SQL representation of the query.
     *
     * @return string
     */
    public function toSql()
    {
        $this->processAttributes($this->fixColumns());

        return $this->grammar->compileSelect($this);
    }

    /**
     * Get a new instance of the query builder.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function newQuery()
    {
        return new static($this->connection, $this->grammar, $this->processor, $this->baseEntity);
    }

    /**
     * Add a basic where clause to the query.
     *
     * @param  string|array|\Closure  $column
     * @param  string  $operator
     * @param  mixed   $value
     * @param  string  $boolean
     * @return $this
     *
     * @throws \InvalidArgumentException
     */
    protected function addWhereAttribute($column, $values)
    {
        $this->hasAttributeConditions = true;

        $this->attributeColumns['columns'] = array_merge((array)$this->attributeColumns['columns'], (array)$column);
        $this->attributeWheres['binding'][$values['type']][] = $values;

        return $this;
    }
    
    
    /**
     * Add a basic where clause to the query.
     *
     * @param  string|array|\Closure  $column
     * @param  string  $operator
     * @param  mixed   $value
     * @param  string  $boolean
     * @return $this
     *
     * @throws \InvalidArgumentException
     */
    public function whereAttribute($column, $operator = null, $value = null, $boolean = 'and')
    {
        $type = 'Basic';

        // Here we will make some assumptions about the operator. If only 2 values are
        // passed to the method, we will assume that the operator is an equals sign
        // and keep going. Otherwise, we'll require the operator to be passed in.
        if (func_num_args() == 2) {
            list($value, $operator) = [$operator, '='];
        } elseif ($this->invalidOperatorAndValue($operator, $value)) {
            throw new InvalidArgumentException('Illegal operator and value combination.');
        }

        // If the columns is actually a Closure instance, we will assume the developer
        // wants to begin a nested where statement which is wrapped in parenthesis.
        // We'll add that Closure to the query then return back out immediately.
        if ($column instanceof Closure) {
            return $this->whereNestedAttribute($column, $boolean);
        }

        // If the given operator is not found in the list of valid operators we will
        // assume that the developer is just short-cutting the '=' operators and
        // we will set the operators to '=' and set the values appropriately.
        if (! in_array(strtolower($operator), $this->operators, true)) {
            list($value, $operator) = [$operator, '='];
        }


        return $this->addWhereAttribute($column, compact('column', 'operator', 'value', 'boolean', 'type'));
    }

    /**
     * Add a nested where statement to the query.
     *
     * @param  \Closure $callback
     * @param  string   $boolean
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function whereNestedAttribute(Closure $callback, $boolean = 'and')
    {
        $query = $this->forNestedWhere();

        call_user_func($callback, $query);

        return $this->addNestedWhereQueryAttribute($query, $boolean);
    }

    /**
     * Add another query builder as a nested where to the query builder.
     *
     * @param  \Illuminate\Database\Query\Builder|static $query
     * @param  string  $boolean
     * @return $this
     */
    public function addNestedWhereQueryAttribute($query, $boolean = 'and')
    {
        if ($query->hasAttributeConditions) {
            $type = 'Nested';
            $this->addWhereAttribute($query->attributeColumns['columns'], compact('type', 'query', 'boolean'));
        }

        return $this;
    }

    /**
     * Add an "or where" clause to the query.
     *
     * @param  string  $column
     * @param  string  $operator
     * @param  mixed   $value
     * @return \Illuminate\Database\Eloquent\Builder|static
     */
    public function orWhereAttribute($column, $operator = null, $value = null)
    {
        return $this->whereAttribute($column, $operator, $value, 'or');
    }

    /**
     * Add a where between statement to the query.
     *
     * @param  string  $column
     * @param  array   $values
     * @param  string  $boolean
     * @param  bool  $not
     * @return $this
     */
    public function whereBetweenAttribute($column, array $values, $boolean = 'and', $not = false)
    {
        $type = 'between';

        return $this->addWhereAttribute($column, compact('column', 'values', 'type', 'boolean', 'not', 'type'));
    }

    /**
     * Add an or where between statement to the query.
     *
     * @param  string  $column
     * @param  array   $values
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function orWhereBetweenAttribute($column, array $values)
    {
        return $this->whereBetweenAttribute($column, $values, 'or');
    }

    /**
     * Add a where not between statement to the query.
     *
     * @param  string  $column
     * @param  array   $values
     * @param  string  $boolean
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function whereNotBetweenAttribute($column, array $values, $boolean = 'and')
    {
        return $this->whereBetweenAttribute($column, $values, $boolean, true);
    }

    /**
     * Add an or where not between statement to the query.
     *
     * @param  string  $column
     * @param  array   $values
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function orWhereNotBetweenAttribute($column, array $values)
    {
        return $this->whereNotBetweenAttribute($column, $values, 'or');
    }

    /**
     * Add a "where in" clause to the query.
     *
     * @param  string  $column
     * @param  mixed   $values
     * @param  string  $boolean
     * @param  bool    $not
     * @return $this
     */
    public function whereInAttribute($column, $values, $boolean = 'and', $not = false)
    {
        $type = $not ? 'NotIn' : 'In';

        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }

        return $this->addWhereAttribute($column, compact('column', 'values', 'boolean', 'not', 'type'));
    }

     /**
     * Add an "or where in" clause to the query.
     *
     * @param  string  $column
     * @param  mixed   $values
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function orWhereInAttribute($column, $values)
    {
        return $this->whereInAttribute($column, $values, 'or');
    }

    /**
     * Add a "where not in" clause to the query.
     *
     * @param  string  $column
     * @param  mixed   $values
     * @param  string  $boolean
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function whereNotInAttribute($column, $values, $boolean = 'and')
    {
        return $this->whereInAttribute($column, $values, $boolean, true);
    }

    /**
     * Add an "or where not in" clause to the query.
     *
     * @param  string  $column
     * @param  mixed   $values
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function orWhereNotInAttribute($column, $values)
    {
        return $this->whereNotInAttribute($column, $values, 'or');
    }

    /**
     * Add a "where null" clause to the query.
     *
     * @param  string  $column
     * @param  string  $boolean
     * @param  bool    $not
     * @return $this
     */
    public function whereNullAttribute($column, $boolean = 'and', $not = false)
    {
        $type = $not ? 'NotNull' : 'Null';

        return $this->addWhereAttribute($column, compact('column', 'boolean', 'not', 'type'));
    }

    /**
     * Add an "or where null" clause to the query.
     *
     * @param  string  $column
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function orWhereNullAttribute($column)
    {
        return $this->whereNullAttribute($column, 'or');
    }

    /**
     * Add a "where not null" clause to the query.
     *
     * @param  string  $column
     * @param  string  $boolean
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function whereNotNullAttribute($column, $boolean = 'and')
    {
        return $this->whereNullAttribute($column, $boolean, true);
    }

    /**
     * Add an "or where not null" clause to the query.
     *
     * @param  string  $column
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function orWhereNotNullAttribute($column)
    {
        return $this->whereNotNullAttribute($column, 'or');
    }

    /**
     * Add a "where date" statement to the query.
     *
     * @param  string  $column
     * @param  string   $operator
     * @param  int   $value
     * @param  string   $boolean
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function whereDateAttribute($column, $operator, $value, $boolean = 'and')
    {
        return $this->addDateBasedWhereAttribute('Date', $column, $operator, $value, $boolean);
    }

    /**
     * Add a "where day" statement to the query.
     *
     * @param  string  $column
     * @param  string   $operator
     * @param  int   $value
     * @param  string   $boolean
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function whereDayAttribute($column, $operator, $value, $boolean = 'and')
    {
        return $this->addDateBasedWhereAttribute('Day', $column, $operator, $value, $boolean);
    }

    /**
     * Add a "where month" statement to the query.
     *
     * @param  string  $column
     * @param  string   $operator
     * @param  int   $value
     * @param  string   $boolean
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function whereMonthAttribute($column, $operator, $value, $boolean = 'and')
    {
        return $this->addDateBasedWhereAttribute('Month', $column, $operator, $value, $boolean);
    }

    /**
     * Add a "where year" statement to the query.
     *
     * @param  string  $column
     * @param  string   $operator
     * @param  int   $value
     * @param  string   $boolean
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function whereYearAttribute($column, $operator, $value, $boolean = 'and')
    {
        return $this->addDateBasedWhereAttribute('Year', $column, $operator, $value, $boolean);
    }

    /**
     * Add a date based (year, month, day) statement to the query.
     *
     * @param  string  $type
     * @param  string  $column
     * @param  string  $operator
     * @param  int  $value
     * @param  string  $boolean
     * @return $this
     */
    protected function addDateBasedWhereAttribute($type, $column, $operator, $value, $boolean = 'and')
    {
        return $this->addWhereAttribute($column, compact('column', 'type', 'boolean', 'operator', 'value'));
    }

    /**
     * Add an "order by" clause to the query.
     *
     * @param  string  $column
     * @param  string  $direction
     * @return $this
     */
    public function orderByAttribute($column, $direction = 'asc')
    {
        $property = $this->unions ? 'unionOrders' : 'orders';
        $direction = strtolower($direction) == 'asc' ? 'asc' : 'desc';

        $this->hasAttributeConditions = true;

        $this->attributeColumns['columns'] = array_merge((array)$this->attributeColumns['columns'], (array)$column);
        $this->attributeOrderBy['binding'][$property][] = compact('column', 'direction');

        return $this;
    }

    /**
     * Add an "order by" clause for a timestamp to the query.
     *
     * @param  string  $column
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function latest($column = 'created_at')
    {
        return $this->orderByAttribute($column, 'desc');
    }

    /**
     * Add an "order by" clause for a timestamp to the query.
     *
     * @param  string  $column
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function oldest($column = 'created_at')
    {
        return $this->orderByAttribute($column, 'asc');
    }
}