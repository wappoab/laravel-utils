<?php

declare(strict_types=1);

namespace Wappo\LaravelUtils\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

class MakeEntity extends Command
{
    protected $signature = 'make:entity
        {model : The name of the Eloquent model (e.g. "User")}
        {--N|namespace=Entities : The namespace (under \\App) to place this Entity class}
        {--s|suffix=Entity : The suffix to append to the generated class name}
        {--resource : Inherit Resource instead of Data}
        {--f|force : Overwrite the file if it already exists}';

    protected $description = 'Generate a Spatie Data-based Entity class from an Eloquent model by parsing its code (resolving imported classes, custom casts, etc.).';

    public function handle(): int
    {
        $model = $this->argument('model');
        $namespace = trim($this->option('namespace'), '\\/');
        $suffix = $this->option('suffix');
        $force = $this->option('force');
        $resource = (bool) $this->option('resource');

        // 1) Kolla att app/Models/{Model}.php finns
        $modelPath = base_path("app/Models/{$model}.php");
        if (!file_exists($modelPath)) {
            $this->error("Model file [{$modelPath}] does not exist.");
            return self::FAILURE;
        }

        // 2) Bygg sökvägar och namn
        $className = $model.$suffix;
        $fullNamespace = 'App\\'.$namespace;
        $directory = base_path('app/'.str_replace('\\', '/', $namespace));
        $filePath = $directory.'/'.$className.'.php';

        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        if (File::exists($filePath) && !$force) {
            $this->error("File [{$filePath}] already exists. Use --force to overwrite.");
            return self::FAILURE;
        }

        // 3) Parsa model-filen (hämta use-imports + casts)
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $code = File::get($modelPath);

        try {
            $ast = $parser->parse($code);
            $imports = $this->collectUseImports($ast);
            $casts = $this->parseCastsArray($ast, $imports);
        } catch (Error $e) {
            $this->error("Parse error in model file [{$modelPath}]: {$e->getMessage()}");
            return self::FAILURE;
        }

        // 4) Hämta kolumner från DB (kräver en enkel instans)
        $modelClass = "App\\Models\\{$model}";
        $modelObject = new $modelClass(); // endast för tabellnamn
        $connection = $modelObject->getConnection();
        $columns = $connection->getSchemaBuilder()->getColumnListing($modelObject->getTable());

        // 5) Generera Spatie Data-klass
        $entityContent = $this->generateEntityClass(
            namespace: $fullNamespace,
            className: $className,
            columns: $columns,
            casts: $casts,
            resource: $resource
        );

        File::put($filePath, $entityContent);
        $this->info("Entity class [{$className}] created at [{$filePath}].");

        return self::SUCCESS;
    }

    private function collectUseImports(array $ast): array
    {
        $nodeFinder = new NodeFinder();
        $imports = [];

        $useNodes = $nodeFinder->find($ast, fn(Node $node) => $node instanceof Node\Stmt\Use_
            || $node instanceof Node\Stmt\GroupUse
        );

        foreach ($useNodes as $useNode) {
            if ($useNode instanceof Node\Stmt\GroupUse) {
                // t.ex. use App\Casts\{AsUuid, AnotherCast};
                $prefix = $useNode->prefix->toString();
                foreach ($useNode->uses as $useUse) {
                    $shortName = $useUse->alias?->toString() ?? $useUse->name->getLast();
                    $fullName = $prefix.'\\'.$useUse->name->getLast();
                    $imports[$shortName] = $fullName;
                }
            } elseif ($useNode instanceof Node\Stmt\Use_) {
                // t.ex. use App\Casts\AsUuid;
                foreach ($useNode->uses as $useUse) {
                    $shortName = $useUse->alias?->toString() ?? $useUse->name->getLast();
                    $fullName = $useUse->name->toString();
                    $imports[$shortName] = $fullName;
                }
            }
        }

        return $imports;
    }

    private function parseCastsArray(array $ast, array $imports): array
    {
        $nodeFinder = new NodeFinder();
        $propertyCasts = [];
        $methodCasts = [];

        $propertyNodes = $nodeFinder->find($ast, fn(Node $node) => $node instanceof Node\Stmt\Property
            && !empty($node->props)
            && $node->props[0]->name->toString() === 'casts'
        );

        if (count($propertyNodes) > 0) {
            /** @var Node\Stmt\Property $prop */
            $prop = $propertyNodes[0];
            $value = $prop->props[0]->default ?? null;
            $propertyCasts = $this->extractArrayFromNode($value, $imports);
        }

        $methodNodes = $nodeFinder->find($ast, fn(Node $node) => $node instanceof Node\Stmt\ClassMethod
            && $node->name->name === 'casts'
        );

        if (count($methodNodes) > 0) {
            /** @var Node\Stmt\ClassMethod $m */
            $m = $methodNodes[0];
            // leta efter en "return [ ... ];"
            $returnStmt = $nodeFinder->findFirst([$m], fn(Node $n) => $n instanceof Node\Stmt\Return_);
            if ($returnStmt instanceof Node\Stmt\Return_) {
                $methodCasts = $this->extractArrayFromNode($returnStmt->expr, $imports);
            }
        }

        return array_merge($propertyCasts, $methodCasts);
    }

    private function extractArrayFromNode(?Node $node, array $imports): array
    {
        if (!$node instanceof Node\Expr\Array_) {
            return [];
        }

        $result = [];
        foreach ($node->items as $item) {
            if (!$item || !$item->key instanceof Node\Scalar\String_) {
                continue;
            }
            $key = $item->key->value;
            $value = null;

            // T.ex. 'datetime' eller 'int'
            if ($item->value instanceof Node\Scalar\String_) {
                $value = $item->value->value;
            } // T.ex. AsUuid::class
            elseif ($item->value instanceof Node\Expr\ClassConstFetch) {
                $value = $this->resolveClassConstFetch($item->value, $imports);
            }

            if ($value !== null) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function resolveClassConstFetch(Node\Expr\ClassConstFetch $node, array $imports): ?string
    {
        if ($node->name->toString() !== 'class') {
            return null; // inte "Någonting::class"
        }

        $className = $node->class->toString(); // "AsUuid" eller "Some\Package\Class"

        // Om "AsUuid" finns i import-listan => \App\Casts\AsUuid
        if (isset($imports[$className])) {
            return '\\'.ltrim($imports[$className], '\\');
        }

        // Om det redan är "App\Casts\AsUuid" => se till att prefixa med "\"
        return '\\'.ltrim($className, '\\');
    }

    private function generateEntityClass(
        string $namespace,
        string $className,
        array $columns,
        array $casts,
        bool $resource
    ): string {
        $phpTypesPerColumn = [];
        foreach ($casts as $col => $cast) {
            $phpTypesPerColumn[$col] = $this->mapCastToPhpType($cast);
        }

        $dataClass = ($resource?'Resource':'Data');

        $allPhpTypes = collect($phpTypesPerColumn)->values()->unique();
        $imports = $allPhpTypes
            ->filter(fn($t) => str_starts_with($t, '\\'))
            ->map(fn($t) => 'use '.ltrim($t, '\\').';')
            ->push('use Spatie\\LaravelData\\'.$dataClass.';')
            ->sort()
            ->implode("\n");

        $constructorParams = collect($columns)
            ->reject(fn($c) => in_array($c, ['updated_at', 'created_at', 'deleted_at']))
            ->map(function ($column) use ($phpTypesPerColumn) {
                $type = $phpTypesPerColumn[$column] ?? 'string';
                if (str_contains($type, '\\')) {
                    $type = last(explode('\\', $type));
                }
                return "        public readonly {$type} \$$column,";
            })
            ->implode("\n");

        $constructorParams = rtrim($constructorParams, ',');

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

{$imports}

final class {$className} extends {$dataClass}
{
    public function __construct(
{$constructorParams}
    ) {
    }
}
PHP;
    }

    private function mapCastToPhpType(?string $castType): string
    {
        if (!$castType) {
            return 'string';
        }

        if (str_starts_with($castType, '\\')) {
            $maybeType = $this->parseCustomCastReturnType($castType);
            return $maybeType ?: 'string';
        }

        $baseCast = strtolower(trim(explode(':', $castType)[0]));

        return match ($baseCast) {
            'int', 'integer' => 'int',
            'real', 'float', 'double' => 'float',
            'bool', 'boolean' => 'bool',
            'array', 'json', 'object' => 'array',
            'collection' => '\Illuminate\Support\Collection',
            'date', 'datetime',
            'immutable_date',
            'immutable_datetime' => '\Illuminate\Support\Carbon',
            'timestamp' => 'int',
            'decimal' => 'string',
            default => 'string',
        };
    }

    private function parseCustomCastReturnType(string $castClassName): ?string
    {
        $trimmed = ltrim($castClassName, '\\');
        if (!str_starts_with($trimmed, 'App\\')) {
            return null;
        }

        $relativePath = str_replace('\\', '/', Str::replaceFirst('App\\', '', $trimmed)).'.php';
        $filePath = base_path('app/'.$relativePath);

        if (!file_exists($filePath)) {
            return null;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $nodeFinder = new NodeFinder();

        try {
            $ast = $parser->parse(File::get($filePath));
            $classNode = $nodeFinder->findFirstInstanceOf($ast, Node\Stmt\Class_::class);
            if (!$classNode) {
                return null;
            }

            $imports = $this->collectUseImports($ast);

            $implementsCasts = collect($classNode->implements)->contains(
                fn($impl) => ($imports[$impl->toString()] ?? $impl->toString())
                    === 'Illuminate\\Contracts\\Database\\Eloquent\\CastsAttributes'
            );

            if (!$implementsCasts) {
                return null;
            }

            $getMethodNode = $nodeFinder->findFirst(
                $classNode->getMethods(),
                fn(Node\Stmt\ClassMethod $m) => $m->name->toString() === 'get'
            );

            if (!$getMethodNode instanceof Node\Stmt\ClassMethod) {
                return null;
            }

            $typeNode = $getMethodNode->getReturnType();
            if (!$typeNode) {
                return null;
            }

            if ($typeNode instanceof Node\NullableType) {
                $typeNode = $typeNode->type;
            }

            if ($typeNode instanceof Node\Identifier) {
                // ex. "string", "int", "bool"
                return $this->mapPhpBuiltinToPhpType($typeNode->name);
            } elseif ($typeNode instanceof Node\Name && !empty($imports[$typeNode->toString()])) {
                // ex. "Carbon" => kolla import => \Carbon\Carbon
                return '\\'.ltrim($imports[$typeNode->toString()], '\\');
            } elseif ($typeNode instanceof Node\Name\FullyQualified) {
                // ex. \Carbon\Carbon
                return '\\'.ltrim($typeNode->toString(), '\\');
            }

            return null;
        } catch (Error) {
            return null;
        }
    }

    private function mapPhpBuiltinToPhpType(string $typeName): string
    {
        return match (strtolower($typeName)) {
            'int' => 'int',
            'bool',
            'boolean' => 'bool',
            'float',
            'double' => 'float',
            'string' => 'string',
            'array' => 'array',
            default => 'string',
        };
    }
}
