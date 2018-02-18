<?php

namespace Psalm\LanguageServer\Protocol;

class InitializeResult
{
    /**
     * The capabilities the language server provides.
     *
     * @var Psalm\LanguageServer\Protocol\ServerCapabilities
     */
    public $capabilities;

    /**
     * @param Psalm\LanguageServer\Protocol\ServerCapabilities $capabilities
     */
    public function __construct(ServerCapabilities $capabilities = null)
    {
        $this->capabilities = $capabilities ?? new ServerCapabilities();
    }
}
