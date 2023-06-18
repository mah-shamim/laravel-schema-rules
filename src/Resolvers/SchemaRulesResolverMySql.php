<?php

namespace LaracraftTech\LaravelSchemaRules\Resolvers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

class SchemaRulesResolverMySql implements SchemaRulesResolverInterface
{
    private string $table;
    private array $columns;

    public static array $integerTypes = [
        'tinyint' => [
            'unsigned' => ['0', '255'],
            'signed' => ['-128', '127'],
        ],
        'smallint' => [
            'unsigned' => ['0', '65535'],
            'signed' => ['-32768', '32767'],
        ],
        'mediumint' => [
            'unsigned' => ['0', '16777215'],
            'signed' => ['-8388608', '8388607'],
        ],
        'int' => [
            'unsigned' => ['0', '4294967295'],
            'signed' => ['-2147483648', '2147483647'],
        ],
        'bigint' => [
            'unsigned' => ['0', '18446744073709551615'],
            'signed' => ['-9223372036854775808', '9223372036854775807'],
        ],
    ];

    public function __construct(string $table, array $columns = [])
    {
        $this->table = $table;
        $this->columns = $columns;
    }

    public function generate(): array
    {
        $tableColumns = $this->getColumnsDefinitionsFromTable();

        $tableRules = [];
        foreach ($tableColumns as $column) {
            $field = $column->Field;

            // If specific columns where supplied only process those...
            if (! empty($this->columns) && ! in_array($field, $this->columns)) {
                continue;
            }

            // We do not need a rule for auto increments
            if ($column->Extra === 'auto_increment') {
                continue;
            }

            $tableRules[$field] = $this->generateColumnRules($column);
        }
        //dd($tableColumns);
        return $tableRules;
    }

    private function getColumnsDefinitionsFromTable()
    {
        return DB::select('SHOW COLUMNS FROM '.$this->table);
    }

    private function generateColumnRules(stdClass $column): array
    {
        $columnRules = [];
        $columnRules[] = $column->Null === "YES" ? 'nullable' : 'required' ;

        $type = Str::of($column->Type);
        switch (true) {
            case $type == 'tinyint(1)' && config('schema-rules.tinyint1_to_bool'):
                $columnRules[] = "boolean";

                break;
            case $type->contains('char'):
                $columnRules[] = "string";
                $columnRules[] = "min:".config('schema-rules.string_min_length');
                $columnRules[] = "max:".filter_var($type, FILTER_SANITIZE_NUMBER_INT);

                break;
            case $type == 'text':
                $columnRules[] = "string";
                $columnRules[] = "min:".config('schema-rules.string_min_length');

                break;
            case $type->contains('int'):
                $sign = ($type->contains('unsigned')) ? 'unsigned' : 'signed' ;
                $intType = $type->before(' unsigned')->__toString();
                $columnRules[] = "integer";
                $columnRules[] = "min:".self::$integerTypes[$intType][$sign][0];
                $columnRules[] = "max:".self::$integerTypes[$intType][$sign][1];

                break;
            case $type->contains('double') ||
            $type->contains('decimal') ||
            $type->contains('dec') ||
            $type->contains('float'):
                // should we do more specific here?
                // some kind of regex validation for double, double unsigned, double(8, 2), decimal etc...?
                $columnRules[] = "numeric";

                break;
            case $type->contains('enum') || $type->contains('set'):
                preg_match_all("/'([^']*)'/", $type, $matches);
                $columnRules[] = 'string';
                $columnRules[] = 'in:'.implode(',', $matches[1]);

                break;
            case $type == 'year':
                $columnRules[] = 'integer';
                $columnRules[] = 'min:1901';
                $columnRules[] = 'max:2155';

                break;
            case $type == 'date' || $type == 'time':
                $columnRules[] = 'date';

                break;
            case $type == 'timestamp':
                // handle mysql "year 2038 problem"
                $columnRules[] = 'date';
                $columnRules[] = 'after_or_equal:1970-01-01 00:00:01';
                $columnRules[] = 'before_or_equal:2038-01-19 03:14:07';

                break;
            case $type == 'json':
                $columnRules[] = 'json';

                break;

            default:
                // I think we skip BINARY and BLOB for now
                break;
        }

        return $columnRules;
    }
}