<?php
declare(strict_types = 1);

namespace Psalm\LanguageServer\Server;

use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use PhpParser\{Node, NodeTraverser};
use Psalm\LanguageServer\{LanguageServer, LanguageClient, PhpDocumentLoader, PhpDocument, DefinitionResolver, CompletionProvider};
use Psalm\LanguageServer\NodeVisitor\VariableReferencesCollector;
use Psalm\LanguageServer\Protocol\{
    SymbolLocationInformation,
    SymbolDescriptor,
    TextDocumentItem,
    TextDocumentIdentifier,
    VersionedTextDocumentIdentifier,
    Position,
    Range,
    FormattingOptions,
    TextEdit,
    Location,
    SymbolInformation,
    ReferenceContext,
    Hover,
    MarkedString,
    SymbolKind,
    CompletionItem,
    CompletionItemKind
};
use Psalm\Codebase;
use Psalm\LanguageServer\Index\ReadableIndex;
use Psalm\Checker\FileChecker;
use Psalm\Checker\ClassLikeChecker;
use Sabre\Event\Promise;
use Sabre\Uri;
use function Sabre\Event\coroutine;
use function Psalm\LanguageServer\{waitForEvent, isVendored};

/**
 * Provides method handlers for all textDocument/* methods
 */
class TextDocument
{
    /**
     * @var LanguageServer
     */
    protected $server;

    /**
     * @var Codebase
     */
    protected $codebase;

    public function __construct(
        LanguageServer $server,
        Codebase $codebase
    ) {
        $this->server = $server;
        $this->codebase = $codebase;
    }

    /**
     * The document symbol request is sent from the client to the server to list all symbols found in a given text
     * document.
     *
     * @param \LanguageServer\Protocol\TextDocumentIdentifier $textDocument
     * @return Promise <SymbolInformation[]>
     */
    public function documentSymbol(TextDocumentIdentifier $textDocument): Promise
    {
        return $this->documentLoader->getOrLoad($textDocument->uri)->then(function (PhpDocument $document) {
            $symbols = [];
            foreach ($document->getDefinitions() as $fqn => $definition) {
                $symbols[] = $definition->symbolInformation;
            }
            return $symbols;
        });
    }

    /**
     * The document open notification is sent from the client to the server to signal newly opened text documents. The
     * document's truth is now managed by the client and the server must not try to read the document's truth using the
     * document's uri.
     *
     * @param \LanguageServer\Protocol\TextDocumentItem $textDocument The document that was opened.
     * @return void
     */
    public function didOpen(TextDocumentItem $textDocument)
    {
        $this->server->invalidateFileAndDependents($textDocument->uri);
        $this->server->analyzePath(\LanguageServer\uriToPath($textDocument->uri));
        $this->server->emitIssues($textDocument->uri);
    }

    public function didSave(TextDocumentItem $textDocument)
    {
        $file_path = \LanguageServer\uriToPath($textDocument->uri);
        
        $this->codebase->removeTemporaryFileChanges($file_path);
        $this->server->invalidateFileAndDependents($textDocument->uri);

        $this->server->analyzePath($file_path);
        $this->server->emitIssues($textDocument->uri);
    }

    /**
     * The document change notification is sent from the client to the server to signal changes to a text document.
     *
     * @param \LanguageServer\Protocol\VersionedTextDocumentIdentifier $textDocument
     * @param \LanguageServer\Protocol\TextDocumentContentChangeEvent[] $contentChanges
     * @return void
     */
    public function didChange(VersionedTextDocumentIdentifier $textDocument, array $contentChanges)
    {
        $this->codebase->addTemporaryFileChanges(\LanguageServer\uriToPath($textDocument->uri), $contentChanges);
        $this->server->analyzePath(\LanguageServer\uriToPath($textDocument->uri));
        $this->server->emitIssues($textDocument->uri);
    }

    /**
     * The document close notification is sent from the client to the server when the document got closed in the client.
     * The document's truth now exists where the document's uri points to (e.g. if the document's uri is a file uri the
     * truth now exists on disk).
     *
     * @param \LanguageServer\Protocol\TextDocumentItem $textDocument The document that was closed
     * @return void
     */
    public function didClose(TextDocumentIdentifier $textDocument)
    {
        //$this->documentLoader->close($textDocument->uri);
    }

    /**
     * The document formatting request is sent from the server to the client to format a whole document.
     *
     * @param TextDocumentIdentifier $textDocument The document to format
     * @param FormattingOptions $options The format options
     * @return Promise <TextEdit[]>
     */
    public function formatting(TextDocumentIdentifier $textDocument, FormattingOptions $options)
    {
        return $this->documentLoader->getOrLoad($textDocument->uri)->then(function (PhpDocument $document) {
            return $document->getFormattedText();
        });
    }

    /**
     * The references request is sent from the client to the server to resolve project-wide references for the symbol
     * denoted by the given text document position.
     *
     * @param ReferenceContext $context
     * @return Promise <Location[]>
     */
    public function references(
        ReferenceContext $context,
        TextDocumentIdentifier $textDocument,
        Position $position
    ): Promise {
        return coroutine(function () use ($textDocument, $position) {
            $document = yield $this->documentLoader->getOrLoad($textDocument->uri);
            $node = $document->getNodeAtPosition($position);
            if ($node === null) {
                return [];
            }
            $locations = [];
            // Variables always stay in the boundary of the file and need to be searched inside their function scope
            // by traversing the AST
            if (
                $node instanceof Node\Expr\Variable
                || $node instanceof Node\Param
                || $node instanceof Node\Expr\ClosureUse
            ) {
                if ($node->name instanceof Node\Expr) {
                    return null;
                }
                // Find function/method/closure scope
                $n = $node;
                while (isset($n) && !($n instanceof Node\FunctionLike)) {
                    $n = $n->getAttribute('parentNode');
                }
                if (!isset($n)) {
                    $n = $node->getAttribute('ownerDocument');
                }
                $traverser = new NodeTraverser;
                $refCollector = new VariableReferencesCollector($node->name);
                $traverser->addVisitor($refCollector);
                $traverser->traverse($n->getStmts());
                foreach ($refCollector->nodes as $ref) {
                    $locations[] = Location::fromNode($ref);
                }
            } else {
                // Definition with a global FQN
                $fqn = DefinitionResolver::getDefinedFqn($node);
                // Wait until indexing finished
                if (!$this->index->isComplete()) {
                    yield waitForEvent($this->index, 'complete');
                }
                if ($fqn === null) {
                    $fqn = $this->definitionResolver->resolveReferenceNodeToFqn($node);
                    if ($fqn === null) {
                        return [];
                    }
                }
                $refDocuments = yield Promise\all(array_map(
                    [$this->documentLoader, 'getOrLoad'],
                    $this->index->getReferenceUris($fqn)
                ));
                foreach ($refDocuments as $document) {
                    $refs = $document->getReferenceNodesByFqn($fqn);
                    if ($refs !== null) {
                        foreach ($refs as $ref) {
                            $locations[] = Location::fromNode($ref);
                        }
                    }
                }
            }
            return $locations;
        });
    }

    /**
     * The goto definition request is sent from the client to the server to resolve the definition location of a symbol
     * at a given text document position.
     *
     * @param TextDocumentIdentifier $textDocument The text document
     * @param Position $position The position inside the text document
     * @return Promise <Location|Location[]>
     */
    public function definition(TextDocumentIdentifier $textDocument, Position $position): Promise
    {
        return coroutine(function () use ($textDocument, $position) {
            if (false) {
                yield true;
            }

            $file_path = \LanguageServer\uriToPath($textDocument->uri);

            $file_contents = $this->codebase->getFileContents($file_path);

            $offset = $position->toOffset($file_contents);

            list($reference_map, $type_map) = $this->server->getMapsForPath($file_path);

            $reference = null;
            $type = null;

            foreach ($reference_map as $start_pos => list($end_pos, $possible_reference)) {
                if ($offset < $start_pos) {
                    break;
                }

                if ($offset > $end_pos + 1) {
                    continue;
                }

                $reference = $possible_reference;
            }

            if ($reference === null) {
                return new Hover([]);
            }

            $code_location = $this->codebase->getSymbolLocation($file_path, $reference);

            if (!$code_location) {
                return [];
            }

            return new Location(
                \LanguageServer\pathToUri($code_location->file_path),
                new Range(
                    new Position($code_location->getLineNumber() - 1, $code_location->getColumn() - 1),
                    new Position($code_location->getEndLineNumber() - 1, $code_location->getEndColumn() - 1)
                )
            );
        });
    }

    /**
     * The hover request is sent from the client to the server to request hover information at a given text document position.
     *
     * @param TextDocumentIdentifier $textDocument The text document
     * @param Position $position The position inside the text document
     * @return Promise <Hover>
     */
    public function hover(TextDocumentIdentifier $textDocument, Position $position): Promise
    {
        return coroutine(function () use ($textDocument, $position) {
            if (false) {
                yield true;
            }

            $file_path = \LanguageServer\uriToPath($textDocument->uri);

            $file_contents = $this->codebase->getFileContents($file_path);

            $offset = $position->toOffset($file_contents);

            list($reference_map, $type_map) = $this->server->getMapsForPath($file_path);

            $reference = null;
            $type = null;

            foreach ($reference_map as $start_pos => list($end_pos, $possible_reference)) {
                if ($offset < $start_pos) {
                    break;
                }

                if ($offset > $end_pos + 1) {
                    continue;
                }

                $reference = $possible_reference;
            }

            if ($reference === null) {
                return new Hover([]);
            }
            
            $range = new Range(
                Position::fromOffset($start_pos, $file_contents),
                Position::fromOffset($end_pos, $file_contents)
            );

            $contents = [];
            $contents[] = new MarkedString('php', "<?php\n" . $this->codebase->getSymbolInformation($file_path, $reference));
            
            return new Hover($contents, $range);
        });
    }

    /**
     * The Completion request is sent from the client to the server to compute completion items at a given cursor
     * position. Completion items are presented in the IntelliSense user interface. If computing full completion items
     * is expensive, servers can additionally provide a handler for the completion item resolve request
     * ('completionItem/resolve'). This request is sent when a completion item is selected in the user interface. A
     * typically use case is for example: the 'textDocument/completion' request doesn't fill in the documentation
     * property for returned completion items since it is expensive to compute. When the item is selected in the user
     * interface then a 'completionItem/resolve' request is sent with the selected completion item as a param. The
     * returned completion item should have the documentation property filled in.
     *
     * @param TextDocumentIdentifier The text document
     * @param Position $position The position
     * @return Promise <CompletionItem[]|CompletionList>
     */
    public function completion(TextDocumentIdentifier $textDocument, Position $position): Promise
    {
        error_log('called');
        return coroutine(function () use ($textDocument, $position) {
            if (false) {
                yield true;
            }

            $file_path = \LanguageServer\uriToPath($textDocument->uri);

            $file_contents = $this->codebase->getFileContents($file_path);

            $offset = $position->toOffset($file_contents);

            list($reference_map, $type_map) = $this->server->getMapsForPath($file_path);

            $reference = null;
            $recent_type = null;

            $reversed_type_map = array_reverse($type_map, true);

            foreach ($reversed_type_map as $start_pos => list($end_pos, $possible_type)) {
                $recent_type = $possible_type;
                
                if ($offset < $start_pos) {
                    continue;
                }

                if ($offset > $end_pos + 1) {
                    break;
                }
            }

            if (!$recent_type || $recent_type === 'mixed') {
                return [];
            }

            $gap = substr($file_contents, $end_pos + 1, $offset - $end_pos - 1);

            $completion_items = [];

            if ($gap === '->') {
                try {
                    $class_storage = $this->codebase->classlike_storage_provider->get($recent_type);

                    foreach ($class_storage->appearing_method_ids as $declaring_method_id) {
                        $method_storage = $this->codebase->methods->getStorage($declaring_method_id);

                        $completion_items[] = new CompletionItem(
                            (string)$method_storage,
                            CompletionItemKind::METHOD,
                            null,
                            null,
                            null,
                            null,
                            $method_storage->cased_name . '()'
                        );
                    }

                    foreach ($class_storage->declaring_property_ids as $property_name => $declaring_property_id) {
                        $property_storage = $this->codebase->properties->getStorage($declaring_property_id);

                        $completion_items[] = new CompletionItem(
                            $property_storage->getInfo() . ' $' . $property_name,
                            CompletionItemKind::PROPERTY,
                            null,
                            null,
                            null,
                            null,
                            $property_name
                        );
                    }
                } catch (\Exception $e) {
                    error_log($e->getMessage());
                    return [];
                }
            }

            return $completion_items;
        });
    }

    /**
     * This method is the same as textDocument/definition, except that
     *
     * The method returns metadata about the definition (the same metadata that workspace/xreferences searches for).
     * The concrete location to the definition (location field) is optional. This is useful because the language server
     * might not be able to resolve a goto definition request to a concrete location (e.g. due to lack of dependencies)
     * but still may know some information about it.
     *
     * @param TextDocumentIdentifier $textDocument The text document
     * @param Position               $position     The position inside the text document
     * @return Promise <SymbolLocationInformation[]>
     */
    public function xdefinition(TextDocumentIdentifier $textDocument, Position $position): Promise
    {
        return coroutine(function () use ($textDocument, $position) {
            $document = yield $this->documentLoader->getOrLoad($textDocument->uri);
            $node = $document->getNodeAtPosition($position);
            if ($node === null) {
                return [];
            }
            // Handle definition nodes
            while (true) {
                if ($fqn) {
                    $def = $this->index->getDefinition($definedFqn);
                } else {
                    // Handle reference nodes
                    $def = $this->definitionResolver->resolveReferenceNodeToDefinition($node);
                }
                // If no result was found and we are still indexing, try again after the index was updated
                if ($def !== null || $this->index->isComplete()) {
                    break;
                }
                yield waitForEvent($this->index, 'definition-added');
            }
            if (
                $def === null
                || $def->symbolInformation === null
                || Uri\parse($def->symbolInformation->location->uri)['scheme'] === 'phpstubs'
            ) {
                return [];
            }
            $symbol = new SymbolDescriptor;
            foreach (get_object_vars($def->symbolInformation) as $prop => $val) {
                $symbol->$prop = $val;
            }
            $symbol->fqsen = $def->fqn;
            $packageName = getPackageName($def->symbolInformation->location->uri, $this->composerJson);
            if ($packageName && $this->composerLock !== null) {
                // Definition is inside a dependency
                foreach (array_merge($this->composerLock->packages, $this->composerLock->{'packages-dev'}) as $package) {
                    if ($package->name === $packageName) {
                        $symbol->package = $package;
                        break;
                    }
                }
            } else if ($this->composerJson !== null) {
                // Definition belongs to a root package
                $symbol->package = $this->composerJson;
            }
            return [new SymbolLocationInformation($symbol, $symbol->location)];
        });
    }
}
