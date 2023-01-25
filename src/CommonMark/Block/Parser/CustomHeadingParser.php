<?php

namespace App\CommonMark\Block\Parser;

use League\CommonMark\Extension\CommonMark\Parser\Block\HeadingParser;
use League\CommonMark\Parser\Block\BlockStart;
use League\CommonMark\Parser\Block\BlockStartParserInterface;
use League\CommonMark\Parser\Cursor;
use League\CommonMark\Parser\MarkdownParserStateInterface;
use League\CommonMark\Util\RegexHelper;

/**
 * Custom Heading Parser based on HeadingStartParser.
 *
 * @see \League\CommonMark\Extension\CommonMark\Parser\Block\HeadingStartParser
 */
class CustomHeadingParser implements BlockStartParserInterface
{
    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
    {
        if ($cursor->isIndented() || !\in_array($cursor->getNextNonSpaceCharacter(), ['#', '-', '='], true)) {
            return BlockStart::none();
        }

        $cursor->advanceToNextNonSpaceOrTab();

        if ($atxHeading = self::getAtxHeader($cursor)) {
            return BlockStart::of($atxHeading)->at($cursor);
        }

        $setextHeadingLevel = self::getSetextHeadingLevel($cursor);
        if ($setextHeadingLevel > 0) {
            $content = $parserState->getParagraphContent();
            if ($content !== null) {
                $cursor->advanceToEnd();

                return BlockStart::of(new HeadingParser($setextHeadingLevel, $content))
                    ->at($cursor)
                    ->replaceActiveBlockParser();
            }
        }

        return BlockStart::none();
    }

    private static function getAtxHeader(Cursor $cursor): ?HeadingParser
    {
        $match = RegexHelper::matchFirst('/^#{1,6}(?:[ \t]+|$)/', $cursor->getRemainder());
        if (!$match) {
            return null;
        }

        $cursor->advanceToNextNonSpaceOrTab();
        $cursor->advanceBy(\strlen($match[0]));

        $level = \strlen(\trim($match[0]));
        $str   = $cursor->getRemainder();
        $str   = \preg_replace('/^[ \t]*#+[ \t]*$/', '', $str);
        \assert(\is_string($str));
        $str = \preg_replace('/[ \t]+#+[ \t]*$/', '', $str);
        \assert(\is_string($str));

        if ($level < 6) {
            $level++;
        }

        return new HeadingParser($level, $str);
    }

    private static function getSetextHeadingLevel(Cursor $cursor): int
    {
        $match = RegexHelper::matchFirst('/^(?:=+|-+)[ \t]*$/', $cursor->getRemainder());
        if ($match === null) {
            return 0;
        }

        return $match[0][0] === '=' ? 1 : 2;
    }
}
