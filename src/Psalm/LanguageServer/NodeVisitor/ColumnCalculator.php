<?php
declare(strict_types = 1);

namespace Psalm\LanguageServer\NodeVisitor;

use PhpParser\{NodeVisitorAbstract, Node};

class ColumnCalculator extends NodeVisitorAbstract
{
    /**
     * @var string
     */
    private $code;

    /**
     * @var int
     */
    private $codeLength;

    /**
     * @param string $code
     */
    public function __construct($code)
    {
        $this->code = $code;
        $this->codeLength = strlen($code);
    }

    public function enterNode(Node $node)
    {
        $startFilePos = (int)$node->getAttribute('startFilePos');
        $endFilePos = (int)$node->getAttribute('endFilePos');

        if ($startFilePos > $this->codeLength || $endFilePos > $this->codeLength) {
            throw new \RuntimeException('Invalid position information');
        }

        $startLinePos = strrpos($this->code, "\n", $startFilePos - $this->codeLength);
        if ($startLinePos === false) {
            $startLinePos = -1;
        }

        $endLinePos = strrpos($this->code, "\n", $endFilePos - $this->codeLength);
        if ($endLinePos === false) {
            $endLinePos = -1;
        }

        $node->setAttribute('startColumn', $startFilePos - $startLinePos);
        $node->setAttribute('endColumn', $endFilePos - $endLinePos);
    }
}
