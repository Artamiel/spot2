<?php

namespace Spot\Relation;

/**
 * Constraint class.
 *
 * @package Spot
 */
class Constraint
{

    /**
     * A keyword from standard SQL. In MySQL, equivalent to RESTRICT. The MySQL Server rejects the delete or update
     * operation for the parent table if there is a related foreign key value in the referenced table.
     * Some database systems have deferred checks, and NO ACTION is a deferred check. In MySQL,
     * foreign key constraints are checked immediately, so NO ACTION is the same as RESTRICT.
     */
    const NO_ACTION     = 'NO ACTION';

    /**
     * Delete or update the row from the parent table, and set the foreign key column or columns in the child table to NULL.
     * Both ON DELETE SET NULL and ON UPDATE SET NULL clauses are supported.
     * If you specify a SET NULL action, make sure that you have not declared the columns in the child table as NOT NULL.
     */
    const SET_NULL      = 'SET NULL';

    /**
     * Rejects the delete or update operation for the parent table. Specifying RESTRICT (or NO ACTION)
     * is the same as omitting the ON DELETE or ON UPDATE clause.
     */
    const RESTRICT      = 'RESTRICT';

    /**
     * This action is recognized by the MySQL parser, but both InnoDB and NDB reject table definitions
     * containing ON DELETE SET DEFAULT or ON UPDATE SET DEFAULT clauses.
     */
    const SET_DEFAULT   = 'SET DEFAULT';

    /**
     * Delete or update the row from the parent table, and automatically delete or update the matching rows in the child table.
     */
    const CASCADE       = 'CASCADE';

}
