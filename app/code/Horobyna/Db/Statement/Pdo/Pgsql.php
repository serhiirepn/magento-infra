<?php
/**
 * PostgreSQL PDO statement
 */
namespace Horobyna\Db\Statement\Pdo;

use Magento\Framework\DB\Statement\Parameter;

/**
 * PostgreSQL PDO statement
 *
 * - binds blob parameters as PDO::PARAM_LOB, so binary strings reach bytea columns intact;
 * - returns bytea values as strings: pdo_pgsql fetches them as streams, Magento code expects strings.
 */
class Pgsql extends \Magento\Framework\DB\Statement\Pdo\Mysql
{
    /**
     * Executes statement with binding values to it. Allows transferring specific options to DB driver.
     *
     * @param array $params Array of values to bind to parameter placeholders.
     * @return bool
     * @throws \Zend_Db_Statement_Exception
     */
    public function _executeWithBinding(array $params)
    {
        // Check whether we deal with named bind
        $isPositionalBind = true;
        foreach ($params as $k => $v) {
            if (!is_int($k)) {
                $isPositionalBind = false;
                break;
            }
        }

        /* @var $statement \PDOStatement */
        $statement = $this->_stmt;
        $bindValues = [];
        // Separate array with values, as they are bound by reference
        foreach ($params as $name => $param) {
            $dataType = \PDO::PARAM_STR;
            $length = is_string($param) ? strlen($param) : 0;
            $driverOptions = null;

            if ($param instanceof Parameter) {
                if ($param->getIsBlob()) {
                    $dataType = \PDO::PARAM_LOB;
                    $length = 0;
                } else {
                    $dataType = $param->getDataType();
                    $length = $param->getLength();
                    $driverOptions = $param->getDriverOptions();
                }
                $bindValues[$name] = $param->getValue();
            } else {
                $bindValues[$name] = $param;
            }

            $paramName = $isPositionalBind ? $name + 1 : $name;
            $statement->bindParam($paramName, $bindValues[$name], $dataType, $length, $driverOptions);
        }

        $previousLevel = error_reporting(\E_ERROR); // disable warnings for PDO bugs #63812, #74401
        try {
            return $statement->execute();
        } catch (\PDOException $e) {
            $message = sprintf('%s, query was: %s', $e->getMessage(), $this->_stmt->queryString);
            throw new \Zend_Db_Statement_Exception($message, (int)$e->getCode(), $e);
        } finally {
            error_reporting($previousLevel);
        }
    }

    /**
     * Fetches a row from the result set.
     *
     * @param int $style  OPTIONAL Fetch mode for this fetch operation.
     * @param int $cursor OPTIONAL Absolute, relative, or other.
     * @param int $offset OPTIONAL Number for absolute or relative cursors.
     * @return mixed Array, object, or scalar depending on fetch mode.
     * @throws \Zend_Db_Statement_Exception
     */
    public function fetch($style = null, $cursor = null, $offset = null)
    {
        return $this->readStreams(parent::fetch($style, $cursor, $offset));
    }

    /**
     * Returns an array containing all of the result set rows.
     *
     * @param int $style OPTIONAL Fetch mode.
     * @param int $col   OPTIONAL Column number, if fetch mode is by column.
     * @return array Collection of rows, each in a format by the fetch mode.
     * @throws \Zend_Db_Statement_Exception
     */
    public function fetchAll($style = null, $col = null)
    {
        $rows = parent::fetchAll($style, $col);
        foreach ($rows as $key => $row) {
            $rows[$key] = $this->readStreams($row);
        }
        return $rows;
    }

    /**
     * Returns a single column from the next row of a result set.
     *
     * @param int $col OPTIONAL Position of the column to fetch.
     * @return string
     * @throws \Zend_Db_Statement_Exception
     */
    public function fetchColumn($col = 0)
    {
        return $this->readStreams(parent::fetchColumn($col));
    }

    /**
     * Fetches the next row and returns it as an object.
     *
     * @param string $class  OPTIONAL Name of the class to create.
     * @param array  $config OPTIONAL Constructor arguments for the class.
     * @return mixed One object instance of the specified class.
     * @throws \Zend_Db_Statement_Exception
     */
    public function fetchObject($class = 'stdClass', array $config = [])
    {
        return $this->readStreams(parent::fetchObject($class, $config));
    }

    /**
     * Replace bytea streams in a fetched value, row or object with their contents
     *
     * @param mixed $data
     * @return mixed
     */
    private function readStreams($data)
    {
        if (is_resource($data)) {
            return stream_get_contents($data);
        }
        if (is_array($data) || $data instanceof \stdClass) {
            foreach ($data as $key => $value) {
                if (is_resource($value)) {
                    if (is_array($data)) {
                        $data[$key] = stream_get_contents($value);
                    } else {
                        $data->$key = stream_get_contents($value);
                    }
                }
            }
        }
        return $data;
    }
}
