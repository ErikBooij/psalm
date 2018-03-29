<?php
declare(strict_types = 1);

namespace Psalm\LanguageServer;

use Psalm\Checker\ProjectChecker;
use Psalm\Config;
use Psalm\LanguageServer\Protocol\{
    ServerCapabilities,
    ClientCapabilities,
    TextDocumentSyncKind,
    Message,
    InitializeResult,
    CompletionOptions,
    SignatureHelpOptions
};
use Psalm\LanguageServer\FilesFinder\{FilesFinder, ClientFilesFinder, FileSystemFilesFinder};
use Psalm\LanguageServer\ContentRetriever\{ContentRetriever, ClientContentRetriever, FileSystemContentRetriever};
use Psalm\LanguageServer\Index\{DependenciesIndex, GlobalIndex, Index, ProjectIndex, StubsIndex};
use Psalm\LanguageServer\Cache\{FileSystemCache, ClientCache};
use Psalm\LanguageServer\Server\TextDocument;
use Psalm\LanguageServer\Protocol\{Range, Position, Diagnostic, DiagnosticSeverity};
use AdvancedJsonRpc;
use Sabre\Event\Loop;
use Sabre\Event\Promise;
use function Sabre\Event\coroutine;
use Throwable;
use Webmozart\PathUtil\Path;

class LanguageServer extends AdvancedJsonRpc\Dispatcher
{
    /**
     * Handles textDocument/* method calls
     *
     * @var Server\TextDocument
     */
    public $textDocument;

    /**
     * Handles workspace/* method calls
     *
     * @var Server\Workspace
     */
    public $workspace;

    /**
     * @var Server\Window
     */
    public $window;

    public $telemetry;
    public $completionItem;
    public $codeLens;

    /**
     * @var ProtocolReader
     */
    protected $protocolReader;

    /**
     * @var ProtocolWriter
     */
    protected $protocolWriter;

    /**
     * @var LanguageClient
     */
    protected $client;

    /**
     * @var FilesFinder
     */
    protected $filesFinder;

    /**
     * @var ContentRetriever
     */
    protected $contentRetriever;

    /**
     * @var PhpDocumentLoader
     */
    protected $documentLoader;

    /**
     * The parsed composer.json file in the project, if any
     *
     * @var \stdClass
     */
    protected $composerJson;

    /**
     * The parsed composer.lock file in the project, if any
     *
     * @var \stdClass
     */
    protected $composerLock;

    /**
     * @var GlobalIndex
     */
    protected $globalIndex;

    /**
     * @var ProjectIndex
     */
    protected $projectIndex;

    /**
     * @var DefinitionResolver
     */
    protected $definitionResolver;

    /**
     * @var ProjectChecker
     */
    protected $project_checker;

    /**
     * @var array<string, string>
     */
    protected $filetype_checkers;

    /**
     * @param ProtocolReader  $reader
     * @param ProtocolWriter $writer
     */
    public function __construct(
        ProtocolReader $reader,
        ProtocolWriter $writer,
        ProjectChecker $project_checker,
        array $filetype_checkers,
        Config $config
    ) {
        parent::__construct($this, '/');
        $this->project_checker = $project_checker;
        $this->filetype_checkers = $filetype_checkers;
        $this->config = $config;

        $this->protocolReader = $reader;
        $this->protocolReader->on('close', function () {
            $this->shutdown();
            $this->exit();
        });
        $this->protocolReader->on('message', function (Message $msg) {
            coroutine(function () use ($msg) {
                // Ignore responses, this is the handler for requests and notifications
                if (AdvancedJsonRpc\Response::isResponse($msg->body)) {
                    return;
                }
                $result = null;
                $error = null;
                //try {
                    // Invoke the method handler to get a result
                    $result = yield $this->dispatch($msg->body);
                /*} catch (AdvancedJsonRpc\Error $e) {
                    // If a ResponseError is thrown, send it back in the Response
                    $error = $e;
                } catch (Throwable $e) {
                    // If an unexpected error occurred, send back an INTERNAL_ERROR error response
                    $error = new AdvancedJsonRpc\Error(
                        (string)$e,
                        AdvancedJsonRpc\ErrorCode::INTERNAL_ERROR,
                        null,
                        $e
                    );
                }*/
                // Only send a Response for a Request
                // Notifications do not send Responses
                if (AdvancedJsonRpc\Request::isRequest($msg->body)) {
                    if ($error !== null) {
                        $responseBody = new AdvancedJsonRpc\ErrorResponse($msg->body->id, $error);
                    } else {
                        $responseBody = new AdvancedJsonRpc\SuccessResponse($msg->body->id, $result);
                    }
                    $this->protocolWriter->write(new Message($responseBody));
                }
            })->otherwise('\\LanguageServer\\crash');
        });

        $this->protocolWriter = $writer;
        $this->client = new LanguageClient($reader, $writer);
    }

    /**
     * The initialize request is sent as the first request from the client to the server.
     *
     * @param ClientCapabilities $capabilities The capabilities provided by the client (editor)
     * @param string|null $rootPath The rootPath of the workspace. Is null if no folder is open.
     * @param int|null $processId The process Id of the parent process that started the server. Is null if the process has not been started by another process. If the parent process is not alive then the server should exit (see exit notification) its process.
     * @return Promise <InitializeResult>
     */
    public function initialize(ClientCapabilities $capabilities, string $rootPath = null, int $processId = null): Promise
    {
        return coroutine(function () use ($capabilities, $rootPath, $processId) {
            // Eventually, this might block on something. Leave it as a generator.
            if (false) {
                yield true;
            }

            $this->project_checker->codebase->scanFiles();

            if ($this->textDocument === null) {
                $this->textDocument = new TextDocument(
                    $this,
                    $this->project_checker->codebase
                );
            }

            $serverCapabilities = new ServerCapabilities();

            $serverCapabilities->textDocumentSync = TextDocumentSyncKind::FULL;


            // Support "Find all symbols"
            $serverCapabilities->documentSymbolProvider = false;
            // Support "Find all symbols in workspace"
            $serverCapabilities->workspaceSymbolProvider = false;
            // Support "Go to definition"
            $serverCapabilities->definitionProvider = true;
            // Support "Find all references"
            $serverCapabilities->referencesProvider = false;
            // Support "Hover"
            $serverCapabilities->hoverProvider = true;
            // Support "Completion"
            /*$serverCapabilities->completionProvider = new CompletionOptions;
            $serverCapabilities->completionProvider->resolveProvider = false;
            $serverCapabilities->completionProvider->triggerCharacters = ['$', '>'];

            $serverCapabilities->signatureHelpProvider = new SignatureHelpOptions();
            $serverCapabilities->signatureHelpProvider->triggerCharacters = ['(', ','];
            */

            // Support global references
            $serverCapabilities->xworkspaceReferencesProvider = false;
            $serverCapabilities->xdefinitionProvider = false;
            $serverCapabilities->xdependenciesProvider = false;

            return new InitializeResult($serverCapabilities);
        });
    }

    public function initialized()
    {

    }

    public function invalidateFileAndDependents(string $uri)
    {
        $file_path = \LanguageServer\uriToPath($uri);
        $this->project_checker->codebase->reloadFiles([$file_path]);
    }

    public function getMapsForPath(string $file_path)
    {
        $relative_path_to_analyze = $this->config->shortenFileName($file_path);

        $codebase = $this->project_checker->codebase;

        return $codebase->getMapsForFile($this->project_checker, $file_path);
    }

    public function analyzePath(string $file_path)
    {
        $relative_path_to_analyze = $this->config->shortenFileName($file_path);

        $codebase = $this->project_checker->codebase;

        $codebase->addFilesToAnalyze([$file_path => $file_path]);
        $codebase->analyzer->analyzeFiles($this->project_checker, 1, false);
    }

    public function emitIssues(string $uri)
    {
        $data = \Psalm\IssueBuffer::clear();

        $file_path = \LanguageServer\uriToPath($uri);

        $data = array_values(array_filter(
            $data,
            function (array $issue_data) use ($file_path) : bool {
                return $issue_data['file_path'] === $file_path;
            }
        ));

        $diagnostics = array_map(
            function (array $issue_data) use ($file_path) : Diagnostic {
                $issue_file_path = $issue_data['file_path'];

                //$check_name = $issue['check_name'];
                $description = htmlentities($issue_data['message']);
                $severity = $issue_data['severity'];

                $issue_uri = \LanguageServer\pathToUri($issue_file_path);
                $start_line = max($issue_data['line_from'], 1);
                $end_line = $issue_data['line_to'];
                $start_column = $issue_data['column_from'];
                $end_column = $issue_data['column_to'];
                // Language server has 0 based lines and columns, phan has 1-based lines and columns.
                $range = new Range(new Position($start_line - 1, $start_column - 1), new Position($end_line - 1, $end_column - 1));
                switch ($severity) {
                    case \Psalm\Config::REPORT_INFO:
                        $diagnostic_severity = DiagnosticSeverity::WARNING;
                        break;
                    case \Psalm\Config::REPORT_ERROR:
                    default:
                        $diagnostic_severity = DiagnosticSeverity::ERROR;
                        break;
                }
                // TODO: copy issue code in 'json' format
                return new Diagnostic(
                    $description,
                    $range,
                    null,
                    $diagnostic_severity,
                    'Psalm'
                );
            },
            $data
        );

        $this->client->textDocument->publishDiagnostics($uri, $diagnostics);
    }

    /**
     * The shutdown request is sent from the client to the server. It asks the server to shut down, but to not exit
     * (otherwise the response might not be delivered correctly to the client). There is a separate exit notification that
     * asks the server to exit.
     *
     * @return void
     */
    public function shutdown()
    {
        unset($this->project);
    }

    /**
     * A notification to ask the server to exit its process.
     *
     * @return void
     */
    public function exit()
    {
        exit(0);
    }

    /**
     * Called before indexing, can return a Promise
     *
     * @param string $rootPath
     */
    protected function beforeIndex(string $rootPath)
    {
    }
}
