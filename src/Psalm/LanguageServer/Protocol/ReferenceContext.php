<?php

namespace Psalm\LanguageServer\Protocol;

class ReferenceContext
{
    /**
     * Include the declaration of the current symbol.
     *
     * @var bool
     */
    public $includeDeclaration;
}
