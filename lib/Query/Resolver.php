<?php
namespace Spot\Query;

use Spot\Mapper;
use Spot\Query;
use Spot\Relation;

/**
 * Main query resolver
 *
 * @package Spot
 * @author Vance Lucas <vance@vancelucas.com>
 */
class Resolver
{
    /**
     * @var \Spot\Mapper
     */
    protected $mapper;

    /**
     * Constructor Method
     *
     * @param \Spot\Mapper $mapper
     */
    public function __construct(Mapper $mapper)
    {
        $this->mapper = $mapper;
    }

    /**
     * Migrate table structure changes to database
     *
     * @return bool
     * @throws \Doctrine\DBAL\Schema\SchemaException
     * @throws \Spot\Exception
     */
    public function migrate()
    {
        // Mapper knows currently set entity
        $entity = $this->mapper->entity();
        $table = $entity::table();
        $fields = $this->mapper->entityManager()->fields();
        $fieldIndexes = $this->mapper->entityManager()->fieldKeys();
        $connection = $this->mapper->connection();

        $schemaManager = $this->mapper->connection()->getSchemaManager();
        $tableObject = $schemaManager->listTableDetails($table);
        $tableObjects[] = $tableObject;
        $schema = new \Doctrine\DBAL\Schema\Schema($tableObjects);

        $tableColumns = $tableObject->getColumns();
        $tableExists = !empty($tableColumns);
        if ($tableExists) {
            // Update existing table
            $existingTable = $schema->getTable($table);
            $newSchema = $this->migrateCreateSchema();
            $queries = $schema->getMigrateToSql($newSchema, $connection->getDatabasePlatform());
        } else {
            // Create new table
            $newSchema = $this->migrateCreateSchema();
            $queries = $newSchema->toSql($connection->getDatabasePlatform());
        }

        // Execute resulting queries
        $lastResult = false;
        foreach ($queries as $sql) {
            $lastResult = $connection->exec($sql);
        }

        return $lastResult;
    }

    /**
     * Migrate create schema
     *
     * @return \Doctrine\DBAL\Schema\Schema
     */
    public function migrateCreateSchema()
    {
        $entityName = $this->mapper->entity();
        $table = $entityName::table();
        $fields = $this->mapper->entityManager()->fields();
        $fieldIndexes = $this->mapper->entityManager()->fieldKeys();

        $schema = new \Doctrine\DBAL\Schema\Schema();
        $table = $schema->createTable($table);

        foreach ($fields as $field => $fieldInfo) {
            $fieldType = $fieldInfo['type'];
            unset($fieldInfo['type']);
            $table->addColumn($field, $fieldType, $fieldInfo);
        }

        // PRIMARY
        if ($fieldIndexes['primary']) {
            $table->setPrimaryKey($fieldIndexes['primary']);
        }
        // UNIQUE
        foreach ($fieldIndexes['unique'] as $keyName => $keyFields) {
            $table->addUniqueIndex($keyFields, $keyName);
        }
        // INDEX
        foreach ($fieldIndexes['index'] as $keyName => $keyFields) {
            $table->addIndex($keyFields, $keyName);
        }

        // Add foreign key constraints if necessary.
        $relations = call_user_func(array($entityName, 'relations'), $this->mapper, new $entityName);

        // Skip, if there are no relations added.
        if (count($relations)) {
            foreach ($relations as $name => $relation) {
                /**
                 * Since OneToMany and ManyToMany are at the inverse side
                 * of the relations, their purpose is to return a collection
                 * of entities/objects and there are no foreign keys on this side.
                 *
                 * So, we skip those entities, and expect a ManyToOne relation to be
                 * defined on the owning side.
                 */
                if ($relation instanceof Relation\HasMany || $relation instanceof Relation\HasManyThrough) {
                    continue;
                }

                $parentEntity = $relation->entityName();
                $foreignTable = call_user_func([$parentEntity, 'table']);

                $localKey = $relation->localKey();
                $foreignKey = $relation->foreignKey();

                /**
                 * Create an empty array that would hold our constraint options.
                 * By default we can create a foreign key without having to
                 * passing onDelete/onUpdate clauses, so at the very least,
                 * an empty array is required.
                 *
                 * This array structure should be pairs of "column" => "option"
                 * <code>
                 * 	array("onDelete" => "CASCADE", "onUpdate" => "CASCADE")
                 * </code>
                 */
                $constrains = [];

                /**
                 * Check if onUpdate clause is been added.
                 * If there is, add it to $constraints array.
                 */
                if (isset($fieldIndexes['constraints']['onUpdate'])) {
                    if (array_key_exists($localKey, $fieldIndexes['constraints']['onUpdate'])) {
                        $constrains['onUpdate'] = $fieldIndexes['constraints']['onUpdate'][ $localKey ];
                    }
                }

                /**
                 * Check if onDelete clause is been added.
                 * If there is, add it to $constraints array.
                 */
                if (isset($fieldIndexes['constraints']['onDelete'])) {
                    if (array_key_exists($localKey, $fieldIndexes['constraints']['onDelete'])) {
                        $constrains['onDelete'] = $fieldIndexes['constraints']['onDelete'][ $localKey ];
                    }
                }

                // Create the foreign key.
                $table->addForeignKeyConstraint($foreignTable, [$localKey], [$foreignKey], $constrains);
            }
        }

        return $schema;
    }

    /**
     * Find records with custom SQL query
     *
     * @param \Spot\Query $query SQL query to execute
     * @return \Spot\Entity\Collection
     * @throws \Spot\Exception
     */
    public function read(Query $query)
    {
        $stmt = $query->builder()->execute();

        // Set PDO fetch mode
        $stmt->setFetchMode(\PDO::FETCH_ASSOC);

        $collection = $query->mapper()->collection($stmt, $query->with());

        // Ensure statement is closed
        $stmt->closeCursor();

        return $collection;
    }

    /**
     * Create new row object with set properties
     *
     * @param string $table Table name
     * @param array $data Array of data to save in 'field' => 'value' format
     */
    public function create($table, array $data)
    {
        $connection = $this->mapper->connection();
        $result = $connection->insert($table, $data);

        return $result;
    }

    /**
     * Update
     *
     * @param string $table Table name
     * @param array $data Array of data for WHERE clause in 'field' => 'value' format
     * @param array $where
     * @return
     * @throws \Spot\Exception
     */
    public function update($table, array $data, array $where)
    {
        $connection = $this->mapper->connection();

        return $connection->update($table, $data, $where);
    }

    /**
     * Execute provided query and return result
     *
     * @param  \Spot\Query $query SQL query to execute
     * @return \Doctrine\DBAL\Driver\Statement|int
     * @throws \Spot\Exception
     */
    public function exec(Query $query)
    {
        return $query->builder()->execute();
    }

    /**
     * Truncate Table
     *
     * @param string $table Table name
     * @param bool $cascade
     * @return
     * @throws \Spot\Exception
     * @internal param array $data Array of data for WHERE clause in 'field' => 'value' format
     */
    public function truncate($table, $cascade = false)
    {
        $mapper = $this->mapper;
        $connection = $mapper->connection();

        // SQLite doesn't support TRUNCATE
        if ($mapper->connectionIs("sqlite")) {
            $sql = "DELETE FROM " . $table;
        } elseif ($mapper->connectionIs("pgsql")) {
            $sql = "TRUNCATE TABLE " . $table . ($cascade ? " CASCADE" : "");
        } else {
            $sql = "TRUNCATE TABLE " . $table . "";
        }

        return $connection->transactional(function ($conn) use ($sql) {
            $conn->exec($sql);
        });
    }

    /**
     * Drop Table
     *
     * @param string $table Table name
     * @return bool
     */
    public function dropTable($table)
    {
        $result = false;
        $connection = $this->mapper->connection();
        try {
            $result = $connection->getSchemaManager()->dropTable($table);
        } catch (\Exception $e) {
            $result = false;
        }

        return $result;
    }
}
