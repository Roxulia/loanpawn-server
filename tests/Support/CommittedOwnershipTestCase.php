<?php

namespace Tests\Support;

/** Isolated committed fixtures for independent-connection and monthly-publication tests. */
abstract class CommittedOwnershipTestCase extends OwnershipMySqlTestCase
{
    use CommittedOwnershipFixture;

    protected bool $wrapFixtureTransaction = false;
}
