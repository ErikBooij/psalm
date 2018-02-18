<?php

namespace Psalm\LanguageServer\Protocol;

use PhpParser\{Error, Node};

/**
 * A range in a text document expressed as (zero-based) start and end positions.
 */
class Range
{
    /**
     * The range's start position.
     *
     * @var Position
     */
    public $start;

    /**
     * The range's end position.
     *
     * @var Position
     */
    public $end;

    /**
     * Returns the range the node spans
     *
     * @param Node $node
     * @return self
     */
    public static function fromNode(Node $node)
    {
        return new self(
            new Position($node->getAttribute('startLine'), $node->getAttribute('startColumn') - 1),
            new Position($node->getAttribute('endLine'), $node->getAttribute('endColumn'))
        );
    }

    /**
     * Returns the range where an error occured
     *
     * @param \PhpParser\Error $error
     * @param string $content
     * @return self
     */
    public static function fromError(Error $error, string $content)
    {
        $startLine   = max($error->getStartLine(), 0);
        $endLine     = max($error->getEndLine(), $startLine);
        $startColumn = $error->hasColumnInfo() ? $error->getStartColumn($content) - 1 : 0;
        $endColumn   = $error->hasColumnInfo() ? $error->getEndColumn($content) : 0;
        return new self(new Position($startLine, $startColumn), new Position($endLine, $endColumn));
    }

    public function __construct(Position $start = null, Position $end = null)
    {
        $this->start = $start;
        $this->end = $end;
    }

    /**
     * Checks if a position is within the range
     *
     * @param Position $position
     * @return bool
     */
    public function includes(Position $position): bool
    {
        return $this->start->compare($position) <= 0 && $this->end->compare($position) >= 0;
    }
}
