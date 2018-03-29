<?php
namespace Psalm;

use PhpParser;
use Psalm\Provider\ClassLikeStorageProvider;
use Psalm\Provider\FileProvider;
use Psalm\Provider\FileStorageProvider;
use Psalm\Provider\StatementsProvider;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FileStorage;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Checker\ClassLikeChecker;

class Codebase
{
    /**
     * @var Config
     */
    public $config;

    /**
     * A map of fully-qualified use declarations to the files
     * that reference them (keyed by filename)
     *
     * @var array<string, array<string, array<int, \Psalm\CodeLocation>>>
     */
    public $use_referencing_locations = [];

    /**
     * A map of file names to the classes that they contain explicit references to
     * used in collaboration with use_referencing_locations
     *
     * @var array<string, array<string, bool>>
     */
    public $use_referencing_files = [];

    /**
     * @var FileStorageProvider
     */
    public $file_storage_provider;

    /**
     * @var ClassLikeStorageProvider
     */
    public $classlike_storage_provider;

    /**
     * @var bool
     */
    public $collect_references = false;

    /**
     * @var FileProvider
     */
    private $file_provider;

    /**
     * @var StatementsProvider
     */
    public $statements_provider;

    /**
     * @var bool
     */
    private $debug_output = false;

    /**
     * @var array<string, Type\Union>
     */
    private static $stubbed_constants = [];

    /**
     * Whether to log functions just at the file level or globally (for stubs)
     *
     * @var bool
     */
    public $register_global_functions = false;

    /**
     * @var bool
     */
    public $find_unused_code = false;

    /**
     * @var Codebase\Reflection
     */
    private $reflection;

    /**
     * @var Codebase\Scanner
     */
    public $scanner;

    /**
     * @var Codebase\Analyzer
     */
    public $analyzer;

    /**
     * @var Codebase\Functions
     */
    public $functions;

    /**
     * @var Codebase\ClassLikes
     */
    public $classlikes;

    /**
     * @var Codebase\Methods
     */
    public $methods;

    /**
     * @var Codebase\Properties
     */
    public $properties;

    /**
     * @var Codebase\Populator
     */
    public $populator;

    /**
     * @var bool
     */
    public $server_mode = false;

    /**
     * @var array
     */
    private $reference_map = [];

    /**
     * @var array
     */
    private $type_map = [];

    /**
     * @param bool $collect_references
     * @param bool $debug_output
     */
    public function __construct(
        Config $config,
        FileStorageProvider $file_storage_provider,
        ClassLikeStorageProvider $classlike_storage_provider,
        FileProvider $file_provider,
        StatementsProvider $statements_provider,
        $debug_output = false
    ) {
        $this->config = $config;
        $this->file_storage_provider = $file_storage_provider;
        $this->classlike_storage_provider = $classlike_storage_provider;
        $this->debug_output = $debug_output;
        $this->file_provider = $file_provider;
        $this->statements_provider = $statements_provider;
        $this->debug_output = $debug_output;

        self::$stubbed_constants = [];

        $this->reflection = new Codebase\Reflection($classlike_storage_provider, $this);

        $this->scanner = new Codebase\Scanner(
            $this,
            $this->config,
            $this->file_storage_provider,
            $this->file_provider,
            $this->reflection,
            $this->debug_output
        );

        $this->analyzer = new Codebase\Analyzer($config, $file_provider, $debug_output);

        $this->functions = new Codebase\Functions($this->file_storage_provider, $this->reflection);
        $this->methods = new Codebase\Methods($this->classlike_storage_provider);
        $this->properties = new Codebase\Properties($this->classlike_storage_provider);

        $this->classlikes = new Codebase\ClassLikes(
            $this->config,
            $this,
            $this->classlike_storage_provider,
            $this->scanner,
            $this->methods,
            $this->debug_output
        );
        $this->populator = new Codebase\Populator(
            $this->config,
            $this->classlike_storage_provider,
            $this->file_storage_provider,
            $this->classlikes,
            $this->debug_output
        );

        $this->loadAnalyzer();
    }

    /**
     * @return void
     */
    private function loadAnalyzer()
    {
        $this->analyzer = new Codebase\Analyzer($this->config, $this->file_provider, $this->debug_output);
    }

    /**
     * @param array<string> $diff_files
     *
     * @return void
     */
    public function reloadFiles(array $diff_files)
    {
        if (!$this->server_mode) {
            throw new \LogicException('Why are we reloading if not in server mode?');
        }

        $this->loadAnalyzer();

        \Psalm\Provider\FileReferenceProvider::loadReferenceCache();

        $referenced_files = \Psalm\Checker\ProjectChecker::getReferencedFilesFromDiff($diff_files, false);

        foreach ($diff_files as $diff_file_path) {
            $this->invalidateInformationForFile($diff_file_path);
        }

        foreach ($referenced_files as $referenced_file_path) {
            if (in_array($referenced_file_path, $diff_files)) {
                continue;
            }

            $file_storage = $this->file_storage_provider->get($referenced_file_path);

            foreach ($file_storage->classlikes_in_file as $fq_classlike_name) {
                $this->classlike_storage_provider->remove($fq_classlike_name);
                $this->classlikes->removeClassLike($fq_classlike_name);
            }

            $this->file_storage_provider->remove($referenced_file_path);
            $this->scanner->removeFile($referenced_file_path);

            unset(
                $this->type_map[strtolower($referenced_file_path)],
                $this->reference_map[strtolower($referenced_file_path)]
            );
        }

        $this->scanner->addFilesToDeepScan($referenced_files);
        $this->scanner->scanFiles($this->classlikes);

        \Psalm\Provider\FileReferenceProvider::updateReferenceCache($this, $referenced_files);

        $this->populator->populateCodebase();
    }

    /**
     * @return void
     */
    public function enterServerMode()
    {
        $this->server_mode = true;
    }

    /**
     * @return void
     */
    public function collectReferences()
    {
        $this->collect_references = true;
        $this->classlikes->collect_references = true;
        $this->methods->collect_references = true;
        $this->properties->collect_references = true;
    }

    /**
     * @return void
     */
    public function reportUnusedCode()
    {
        $this->collectReferences();
        $this->find_unused_code = true;
    }

    /**
     * @param array<string, string> $files_to_analyze
     *
     * @return void
     */
    public function addFilesToAnalyze(array $files_to_analyze)
    {
        $this->scanner->addFilesToDeepScan($files_to_analyze);
        $this->analyzer->addFiles($files_to_analyze);
    }

    /**
     * Scans all files their related files
     *
     * @return void
     */
    public function scanFiles()
    {
        $has_changes = $this->scanner->scanFiles($this->classlikes);

        if ($has_changes) {
            $this->populator->populateCodebase();
        }
    }

    /**
     * @param  string $file_path
     *
     * @return string
     */
    public function getFileContents($file_path)
    {
        return $this->file_provider->getContents($file_path);
    }

    /**
     * @param  string $file_path
     *
     * @return array<int, PhpParser\Node\Stmt>
     */
    public function getStatementsForFile($file_path)
    {
        return $this->statements_provider->getStatementsForFile(
            $file_path,
            $this->debug_output
        );
    }

    public function addNodeType(string $file_path, PhpParser\Node $node, string $node_type) : void
    {
        $this->type_map[strtolower($file_path)][(int)$node->getAttribute('startFilePos')] = [
            (int)$node->getAttribute('endFilePos'),
            $node_type
        ];
    }

    public function addNodeReference(string $file_path, PhpParser\Node $node, string $reference) : void
    {
        $this->reference_map[strtolower($file_path)][(int)$node->getAttribute('startFilePos')] = [
            (int)$node->getAttribute('endFilePos'),
            $reference
        ];
    }

    public function cacheMapsForFile(string $file_path)
    {
        $file_contents = $this->file_provider->getContents($file_path);

        $cached_value = $this->file_storage_provider->cache->getLatestFromCache($file_path, $file_contents);

        if (!$cached_value) {
            throw new \UnexpectedValueException('Bad');
        }

        $file_path_lc = strtolower($file_path);

        if (!isset($this->reference_map[$file_path_lc])) {
            $this->reference_map[$file_path_lc] = [];
        }

        if (!isset($this->type_map[$file_path_lc])) {
            $this->type_map[$file_path_lc] = [];
        }

        ksort($this->reference_map[$file_path_lc]);
        ksort($this->type_map[$file_path_lc]);

        $cached_value->reference_map = $this->reference_map[$file_path_lc];
        $cached_value->type_map = $this->type_map[$file_path_lc];

        $this->file_storage_provider->cache->writeToCache(
            $cached_value,
            $file_contents
        );

        $this->file_storage_provider->remove($file_path);

        $this->file_storage_provider->has($file_path, $file_contents);
    }

    public function getMapsForFile(\Psalm\Checker\ProjectChecker $project_checker, string $file_path)
    {
        $file_contents = $this->file_provider->getContents($file_path);

        $cached_value = $this->file_storage_provider->cache->getLatestFromCache($file_path, $file_contents);

        if (!$cached_value || $cached_value->reference_map === null || $cached_value->type_map === null) {
            $this->addFilesToAnalyze([$file_path => $file_path]);
            $this->analyzer->analyzeFiles($project_checker, 1, false);
            error_log('analysing ' . $file_path);
        }

        $storage = $this->file_storage_provider->get($file_path);

        return [$storage->reference_map, $storage->type_map];
    }

    /**
     * @param  string $fq_classlike_name
     *
     * @return ClassLikeStorage
     */
    public function createClassLikeStorage($fq_classlike_name)
    {
        return $this->classlike_storage_provider->create($fq_classlike_name);
    }

    /**
     * @param  string $file_path
     *
     * @return void
     */
    public function cacheClassLikeStorage(ClassLikeStorage $classlike_storage, $file_path)
    {
        $file_contents = $this->file_provider->getContents($file_path);
        $this->classlike_storage_provider->cache->writeToCache($classlike_storage, $file_path, $file_contents);
    }

    /**
     * @param  string $fq_classlike_name
     * @param  string $file_path
     *
     * @return void
     */
    public function exhumeClassLikeStorage($fq_classlike_name, $file_path)
    {
        $file_contents = $this->file_provider->getContents($file_path);
        $storage = $this->classlike_storage_provider->exhume($fq_classlike_name, $file_path, $file_contents);

        if ($storage->is_trait) {
            $this->classlikes->addFullyQualifiedTraitName($fq_classlike_name, $file_path);
        } elseif ($storage->is_interface) {
            $this->classlikes->addFullyQualifiedInterfaceName($fq_classlike_name, $file_path);
        } else {
            $this->classlikes->addFullyQualifiedClassName($fq_classlike_name, $file_path);
        }
    }

    /**
     * @param  string $file_path
     *
     * @return FileStorage
     */
    public function createFileStorageForPath($file_path)
    {
        return $this->file_storage_provider->create($file_path);
    }

    /**
     * @param  string $symbol
     *
     * @return array<string, \Psalm\CodeLocation[]>
     */
    public function findReferencesToSymbol($symbol)
    {
        if (!$this->collect_references) {
            throw new \UnexpectedValueException('Should not be checking references');
        }

        if (strpos($symbol, '::') !== false) {
            return $this->findReferencesToMethod($symbol);
        }

        return $this->findReferencesToClassLike($symbol);
    }

    /**
     * @param  string $method_id
     *
     * @return array<string, \Psalm\CodeLocation[]>
     */
    public function findReferencesToMethod($method_id)
    {
        list($fq_class_name, $method_name) = explode('::', $method_id);

        try {
            $class_storage = $this->classlike_storage_provider->get($fq_class_name);
        } catch (\InvalidArgumentException $e) {
            die('Class ' . $fq_class_name . ' cannot be found' . PHP_EOL);
        }

        $method_name_lc = strtolower($method_name);

        if (!isset($class_storage->methods[$method_name_lc])) {
            die('Method ' . $method_id . ' cannot be found' . PHP_EOL);
        }

        $method_storage = $class_storage->methods[$method_name_lc];

        if ($method_storage->referencing_locations === null) {
            die('No references found for ' . $method_id . PHP_EOL);
        }

        return $method_storage->referencing_locations;
    }

    /**
     * @param  string $fq_class_name
     *
     * @return array<string, \Psalm\CodeLocation[]>
     */
    public function findReferencesToClassLike($fq_class_name)
    {
        try {
            $class_storage = $this->classlike_storage_provider->get($fq_class_name);
        } catch (\InvalidArgumentException $e) {
            die('Class ' . $fq_class_name . ' cannot be found' . PHP_EOL);
        }

        if ($class_storage->referencing_locations === null) {
            die('No references found for ' . $fq_class_name . PHP_EOL);
        }

        $classlike_references_by_file = $class_storage->referencing_locations;

        $fq_class_name_lc = strtolower($fq_class_name);

        if (isset($this->use_referencing_locations[$fq_class_name_lc])) {
            foreach ($this->use_referencing_locations[$fq_class_name_lc] as $file_path => $locations) {
                if (!isset($classlike_references_by_file[$file_path])) {
                    $classlike_references_by_file[$file_path] = $locations;
                } else {
                    $classlike_references_by_file[$file_path] = array_merge(
                        $locations,
                        $classlike_references_by_file[$file_path]
                    );
                }
            }
        }

        return $classlike_references_by_file;
    }

    /**
     * @param  string $file_path
     * @param  string $closure_id
     *
     * @return FunctionLikeStorage
     */
    public function getClosureStorage($file_path, $closure_id)
    {
        $file_storage = $this->file_storage_provider->get($file_path);

        // closures can be returned here
        if (isset($file_storage->functions[$closure_id])) {
            return $file_storage->functions[$closure_id];
        }

        throw new \UnexpectedValueException(
            'Expecting ' . $closure_id . ' to have storage in ' . $file_path
        );
    }

    /**
     * @param  string $const_id
     * @param  Type\Union $type
     *
     * @return  void
     */
    public function addStubbedConstantType($const_id, $type)
    {
        self::$stubbed_constants[$const_id] = $type;
    }

    /**
     * @param  string $const_id
     *
     * @return Type\Union|null
     */
    public function getStubbedConstantType($const_id)
    {
        return isset(self::$stubbed_constants[$const_id]) ? self::$stubbed_constants[$const_id] : null;
    }

    /**
     * @param  string $file_path
     *
     * @return bool
     */
    public function fileExists($file_path)
    {
        return $this->file_provider->fileExists($file_path);
    }

    /**
     * Check whether a class/interface exists
     *
     * @param  string          $fq_class_name
     * @param  CodeLocation $code_location
     *
     * @return bool
     */
    public function classOrInterfaceExists($fq_class_name, CodeLocation $code_location = null)
    {
        return $this->classlikes->classOrInterfaceExists($fq_class_name, $code_location);
    }

    /**
     * @param  string       $fq_class_name
     * @param  string       $possible_parent
     *
     * @return bool
     */
    public function classExtendsOrImplements($fq_class_name, $possible_parent)
    {
        return $this->classlikes->classExtends($fq_class_name, $possible_parent)
            || $this->classlikes->classImplements($fq_class_name, $possible_parent);
    }

    /**
     * Determine whether or not a given class exists
     *
     * @param  string       $fq_class_name
     *
     * @return bool
     */
    public function classExists($fq_class_name)
    {
        return $this->classlikes->classExists($fq_class_name);
    }

    /**
     * Determine whether or not a class extends a parent
     *
     * @param  string       $fq_class_name
     * @param  string       $possible_parent
     *
     * @return bool
     */
    public function classExtends($fq_class_name, $possible_parent)
    {
        return $this->classlikes->classExtends($fq_class_name, $possible_parent);
    }

    /**
     * Check whether a class implements an interface
     *
     * @param  string       $fq_class_name
     * @param  string       $interface
     *
     * @return bool
     */
    public function classImplements($fq_class_name, $interface)
    {
        return $this->classlikes->classImplements($fq_class_name, $interface);
    }

    /**
     * @param  string         $fq_interface_name
     *
     * @return bool
     */
    public function interfaceExists($fq_interface_name)
    {
        return $this->classlikes->interfaceExists($fq_interface_name);
    }

    /**
     * @param  string         $interface_name
     * @param  string         $possible_parent
     *
     * @return bool
     */
    public function interfaceExtends($interface_name, $possible_parent)
    {
        return $this->classlikes->interfaceExtends($interface_name, $possible_parent);
    }

    /**
     * @param  string         $fq_interface_name
     *
     * @return array<string>   all interfaces extended by $interface_name
     */
    public function getParentInterfaces($fq_interface_name)
    {
        return $this->classlikes->getParentInterfaces($fq_interface_name);
    }

    /**
     * Determine whether or not a class has the correct casing
     *
     * @param  string $fq_class_name
     *
     * @return bool
     */
    public function classHasCorrectCasing($fq_class_name)
    {
        return $this->classlikes->classHasCorrectCasing($fq_class_name);
    }

    /**
     * @param  string $fq_interface_name
     *
     * @return bool
     */
    public function interfaceHasCorrectCasing($fq_interface_name)
    {
        return $this->classlikes->interfaceHasCorrectCasing($fq_interface_name);
    }

    /**
     * @param  string $fq_trait_name
     *
     * @return bool
     */
    public function traitHasCorrectCase($fq_trait_name)
    {
        return $this->classlikes->traitHasCorrectCase($fq_trait_name);
    }

    /**
     * Whether or not a given method exists
     *
     * @param  string       $method_id
     * @param  CodeLocation|null $code_location
     *
     * @return bool
     */
    public function methodExists($method_id, CodeLocation $code_location = null)
    {
        return $this->methods->methodExists($method_id, $code_location);
    }

    /**
     * @param  string $method_id
     *
     * @return array<int, \Psalm\FunctionLikeParameter>
     */
    public function getMethodParams($method_id)
    {
        return $this->methods->getMethodParams($method_id);
    }

    /**
     * @param  string $method_id
     *
     * @return bool
     */
    public function isVariadic($method_id)
    {
        return $this->methods->isVariadic($method_id);
    }

    /**
     * @param  string $method_id
     * @param  string $self_class
     *
     * @return Type\Union|null
     */
    public function getMethodReturnType($method_id, &$self_class)
    {
        return $this->methods->getMethodReturnType($method_id, $self_class);
    }

    /**
     * @param  string $method_id
     *
     * @return bool
     */
    public function getMethodReturnsByRef($method_id)
    {
        return $this->methods->getMethodReturnsByRef($method_id);
    }

    /**
     * @param  string               $method_id
     * @param  CodeLocation|null    $defined_location
     *
     * @return CodeLocation|null
     */
    public function getMethodReturnTypeLocation(
        $method_id,
        CodeLocation &$defined_location = null
    ) {
        return $this->methods->getMethodReturnTypeLocation($method_id, $defined_location);
    }

    /**
     * @param  string $method_id
     *
     * @return string|null
     */
    public function getDeclaringMethodId($method_id)
    {
        return $this->methods->getDeclaringMethodId($method_id);
    }

    /**
     * Get the class this method appears in (vs is declared in, which could give a trait)
     *
     * @param  string $method_id
     *
     * @return string|null
     */
    public function getAppearingMethodId($method_id)
    {
        return $this->methods->getAppearingMethodId($method_id);
    }

    /**
     * @param  string $method_id
     *
     * @return array<string>
     */
    public function getOverriddenMethodIds($method_id)
    {
        return $this->methods->getOverriddenMethodIds($method_id);
    }

    /**
     * @param  string $method_id
     *
     * @return string
     */
    public function getCasedMethodId($method_id)
    {
        return $this->methods->getCasedMethodId($method_id);
    }

    public function getSymbolInformation(string $file_path, string $symbol) : ?string
    {
        try {
            if (strpos($symbol, '::')) {
                list($fq_class_name, $symbol_name) = explode('::', $symbol);

                if (strpos($symbol, '$') !== false) {
                    $storage = $this->properties->getStorage($symbol);
                    
                    $symbol_text = ($storage->type ?: 'mixed') . ' ' . $symbol_name;
                } else {
                    $declaring_method_id = $this->methods->getDeclaringMethodId($symbol);
                    $storage = $this->methods->getStorage($declaring_method_id);

                    $symbol_text = 'function ' . $storage->cased_name . '(' . implode(
                        ', ',
                        array_map(
                            function(FunctionLikeParameter $param) : string {
                                return ($param->type ?: 'mixed') . ' $' . $param->name;
                            },
                            $storage->params
                        )
                    ) . ') : ' . ($storage->return_type ?: 'mixed');
                }

                switch ($storage->visibility) {
                    case ClassLikeChecker::VISIBILITY_PRIVATE:
                        $visibility_text = 'private';
                        break;
                    
                    case ClassLikeChecker::VISIBILITY_PROTECTED:
                        $visibility_text = 'protected';

                    default:
                        $visibility_text = 'public';
                }

                return $visibility_text . ' ' . $symbol_text;
            }

            if (strpos($symbol, '()')) {
                $file_storage = $this->file_storage_provider->get($file_path);

                $function_name = substr($symbol, 0, -2);

                if (isset($file_storage->functions[$function_name])) {
                    $function_storage = $file_storage->functions[$function_name];

                    return $symbol_text = 'function ' . $function_storage->cased_name . '(' . implode(
                        ', ',
                        array_map(
                            function(FunctionLikeParameter $param) : string {
                                return ($param->type ?: 'mixed') . ' $' . $param->name;
                            },
                            $function_storage->params
                        )
                    ) . ') : ' . ($storage->return_type ?: 'mixed');
                }

                return null;
            }

            $storage = $this->classlike_storage_provider->get($symbol);

            return ($storage->abstract ? 'abstract ' : '') . 'class ' . $storage->name;
        } catch (\UnexpectedValueException $e) {
            error_log($e->getMessage());
            return null;
        }
    }

    public function getSymbolLocation(string $file_path, string $symbol) : ?\Psalm\CodeLocation
    {
        try {
            if (strpos($symbol, '::')) {
                list($fq_class_name, $symbol_name) = explode('::', $symbol);

                if (strpos($symbol, '$') !== false) {
                    $storage = $this->properties->getStorage($symbol);
                    
                    return $storage->location;
                }
                
                $declaring_method_id = $this->methods->getDeclaringMethodId($symbol);
                $storage = $this->methods->getStorage($declaring_method_id);

                return $storage->location;
            }

            if (strpos($symbol, '()')) {
                $file_storage = $this->file_storage_provider->get($file_path);

                $function_name = substr($symbol, 0, -2);

                if (isset($file_storage->functions[$function_name])) {
                    return $file_storage->functions[$function_name]->location;
                }

                return null;
            }

            $storage = $this->classlike_storage_provider->get($symbol);

            return $storage->location;
        } catch (\UnexpectedValueException $e) {
            error_log($e->getMessage());
            return null;
        }
    }

    /**
     * @param \LanguageServer\Protocol\TextDocumentContentChangeEvent[] $changes
     */
    public function addTemporaryFileChanges(string $file_path, array $changes)
    {
        $this->file_provider->addTemporaryFileChanges($file_path, $changes);
        $this->invalidateInformationForFile($file_path);
        $this->scanner->addFilesToDeepScan([$file_path]);
        $this->scanner->scanFiles($this->classlikes);
    }
    
    private function invalidateInformationForFile(string $file_path)
    {
        try {
            $file_storage = $this->file_storage_provider->get($file_path);
        } catch (\InvalidArgumentException $e) {
            return;
        }

        foreach ($file_storage->classlikes_in_file as $fq_classlike_name) {
            $this->classlike_storage_provider->remove($fq_classlike_name, $file_path);
            $this->classlikes->removeClassLike($fq_classlike_name);
        }

        $this->file_storage_provider->remove($file_path);
        $this->scanner->removeFile($file_path);

        unset($this->type_map[strtolower($file_path)], $this->reference_map[strtolower($file_path)]);
    }

    public function removeTemporaryFileChanges(string $file_path)
    {
        $this->file_provider->removeTemporaryFileChanges($file_path);
        $this->invalidateInformationForFile($file_path);
        $this->scanner->addFilesToDeepScan([$file_path]);
        $this->scanner->scanFiles($this->classlikes);
    }
}
