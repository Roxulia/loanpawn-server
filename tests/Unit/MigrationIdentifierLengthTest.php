<?php

namespace Tests\Unit;

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MigrationIdentifierLengthTest extends TestCase
{
    public function test_october_migration_identifiers_fit_mysql_limits(): void
    {
        // Describe schema callbacks only: never instantiate migrations or execute up/down/SQL.
        $connection = new MySqlConnection(static fn () => throw new \LogicException('Database access is forbidden in this audit.'));
        $connection->useDefaultSchemaGrammar();
        $addFluentIndexes = new ReflectionMethod(Blueprint::class, 'addFluentIndexes');
        $checked = 0;
        $columnBytes = [];
        $byteChecks = 0;
        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $file) {
            if (basename($file) < '2026_10_01') continue;
            $source = explode('public function down', file_get_contents($file), 2)[0];
            $pattern = <<<'REGEX'
/Schema::(?:create|table)\(\s*'([^']+)'\s*,\s*function\s*\(Blueprint \$table\)[^{]*\{/
REGEX;
            preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as $index => [$header, $offset]) {
                $tableName = $matches[1][$index][0];
                $body = $this->callbackBody(substr($source, $offset + strlen($header)));
                $callback = eval('return function (\\Illuminate\\Database\\Schema\\Blueprint $table): void {'.$body.'};');
                $blueprint = new Blueprint($connection, $tableName, $callback);
                $addFluentIndexes->invoke($blueprint);
                $this->assertLessThanOrEqual(64, strlen($tableName), basename($file).': table '.$tableName);
                foreach ($blueprint->getColumns() as $column) {
                    $this->assertLessThanOrEqual(64, strlen($column->name), basename($file).': column '.$column->name);
                    // Use utf8mb4's maximum width for newly declared string key columns.
                    $columnBytes[$tableName][$column->name] = match ($column->type) {
                        'string', 'char' => 4 * ($column->length ?? 255),
                        'bigInteger', 'integer', 'mediumInteger', 'smallInteger', 'tinyInteger',
                        'boolean', 'date', 'dateTime', 'timestamp', 'time', 'year', 'float', 'double' => 8,
                        'decimal' => 16,
                        default => null,
                    };
                }
                foreach ($blueprint->getCommands() as $command) {
                    if (! in_array($command->name, ['foreign', 'index', 'unique', 'primary', 'fulltext', 'spatialIndex'], true)) continue;
                    ++$checked;
                    $this->assertLessThanOrEqual(64, strlen($command->index), basename($file).': '.$command->index);
                    // Check known BTREE columns against modern InnoDB's 3072-byte budget.
                    // Changes referencing columns declared before October retain name checks only.
                    if (! in_array($command->name, ['index', 'unique', 'primary'], true)) continue;
                    $widths = array_map(fn ($name) => $columnBytes[$tableName][$name] ?? null, $command->columns);
                    if (in_array(null, $widths, true)) continue;
                    ++$byteChecks;
                    $this->assertLessThanOrEqual(3072, array_sum($widths), basename($file).': key bytes '.$command->index);
                }
            }
        }
        // Guard against a parser change silently skipping the schema definitions.
        $this->assertGreaterThan(150, $checked);
        $this->assertGreaterThan(50, $byteChecks);
    }

    private function callbackBody(string $remaining): string
    {
        // Tokenize braces so comments and quoted text cannot end a callback accidentally.
        $body = '';
        $depth = 1;
        foreach (token_get_all('<?php '.$remaining) as $token) {
            if (is_array($token)) {
                if ($token[0] !== T_OPEN_TAG) $body .= $token[1];
                continue;
            }
            if ($token === '{') ++$depth;
            if ($token === '}' && --$depth === 0) return $body;
            // Only closing braces reduce nesting; retain all other callback characters.
            if ($token === '}') {
                $body .= $token;
                continue;
            }
            $body .= $token;
        }
        throw new \LogicException('Unclosed schema callback.');
    }
}
