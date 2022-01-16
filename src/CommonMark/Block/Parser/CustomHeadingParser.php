<?php

namespace App\CommonMark\Block\Parser;

use League\CommonMark\Block\Element\Heading;
use League\CommonMark\Block\Parser\BlockParserInterface;
use League\CommonMark\ContextInterface;
use League\CommonMark\Cursor;
use League\CommonMark\Util\RegexHelper;

/**
 * Custom Heading Parser based on ATXHeadingParser.
 *
 * @see \League\CommonMark\Block\Parser\ATXHeadingParser
 */
class CustomHeadingParser implements BlockParserInterface
{
    public function parse(ContextInterface $context, Cursor $cursor): bool
    {
        if ($cursor->isIndented()) {
            return false;
        }

        $match = RegexHelper::matchFirst('/^#{1,6}(?:[ \t]+|$)/', $cursor->getLine(), $cursor->getNextNonSpacePosition());
        if (!$match) {
            return false;
        }

        $cursor->advanceToNextNonSpaceOrTab();

        $cursor->advanceBy(\strlen($match[0]));

        $level = \strlen(\trim($match[0]));
        $str = $cursor->getRemainder();
        /** @var string $str */
        $str = \preg_replace('/^[ \t]*#+[ \t]*$/', '', $str);
        /** @var string $str */
        $str = \preg_replace('/[ \t]+#+[ \t]*$/', '', $str);

        // Push heading levels up so that headings are only h2 >> h6.
        if ($level < 6) {
            $level++;
        }

        $context->addBlock(new Heading($level, $str));
        $context->setBlocksParsed(true);

        return true;
    }
}
