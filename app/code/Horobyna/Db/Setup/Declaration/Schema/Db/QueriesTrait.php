<?php
/**
 * PostgreSQL database adapter for Magento
 */
namespace Horobyna\Db\Setup\Declaration\Schema\Db;

/**
 * Complete SQL statements of a declarative schema statement
 *
 * A MySQL statement is a fragment of one ALTER TABLE; PostgreSQL needs several statements for one change.
 */
trait QueriesTrait
{
    /**
     * @var string[]
     */
    private $queries = [];

    /**
     * Set the SQL statements to run, in order
     *
     * @param string[] $queries
     * @return $this
     */
    public function setQueries(array $queries)
    {
        $this->queries = array_values($queries);
        return $this;
    }

    /**
     * Get the SQL statements to run, in order
     *
     * @return string[]
     */
    public function getQueries(): array
    {
        return $this->queries;
    }
}
