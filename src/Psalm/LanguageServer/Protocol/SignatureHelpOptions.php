<?php

namespace Psalm\LanguageServer\Protocol;

/**
 * Signature help options.
 */
class SignatureHelpOptions
{
    /**
     * The characters that trigger signature help automatically.
     *
     * @var string[]|null
     */
    public $triggerCharacters;
}
