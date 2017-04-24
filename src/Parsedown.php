<?php

namespace Sc\Parsedown;

#
#
# Parsedown
# http://parsedown.org
#
# (c) Emanuil Rusev
# http://erusev.com
#
# For the full license information, view the LICENSE file that was distributed
# with this source code.
#
#

class Parsedown
{
    # ~

    const version = '1.7.0';

    protected $breaksEnabled;

    protected $markupEscaped;

    protected $urlsLinked = true;

    #
    # Lines
    #

    protected $BlockTypes = [
        '#' => ['Header'],
        '*' => ['Rule', 'List'],
        '+' => ['List'],
        '-' => ['SetextHeader', 'Table', 'Rule', 'List'],
        '0' => ['List'],
        '1' => ['List'],
        '2' => ['List'],
        '3' => ['List'],
        '4' => ['List'],
        '5' => ['List'],
        '6' => ['List'],
        '7' => ['List'],
        '8' => ['List'],
        '9' => ['List'],
        ':' => ['Table'],
        '<' => ['Comment', 'Markup'],
        '=' => ['SetextHeader'],
        '>' => ['Quote'],
        '[' => ['Reference'],
        '_' => ['Rule'],
        '`' => ['FencedCode'],
        '|' => ['Table'],
        '~' => ['FencedCode'],
    ];

    private static $instances = [];

    #
    # Fields
    #

    protected $DefinitionData;

    #
    # Read-Only

    protected $specialCharacters = [
        '\\', '`', '*', '_', '{', '}', '[', ']', '(', ')', '>', '#', '+', '-', '.', '!', '|',
    ];

    protected $StrongRegex = [
        '*' => '/^[*]{2}((?:\\\\\*|[^*]|[*][^*]*[*])+?)[*]{2}(?![*])/s',
        '_' => '/^__((?:\\\\_|[^_]|_[^_]*_)+?)__(?!_)/us',
    ];

    protected $EmRegex = [
        '*' => '/^[*]((?:\\\\\*|[^*]|[*][*][^*]+?[*][*])+?)[*](?![*])/s',
        '_' => '/^_((?:\\\\_|[^_]|__[^_]*__)+?)_(?!_)\b/us',
    ];

    protected $regexHtmlAttribute = '[a-zA-Z_:][\w:.-]*(?:\s*=\s*(?:[^"\'=<>`\s]+|"[^"]*"|\'[^\']*\'))?';

    protected $voidElements = [
        'area', 'base', 'br', 'col', 'command', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source',
    ];

    protected $textLevelElements = [
        'a', 'br', 'bdo', 'abbr', 'blink', 'nextid', 'acronym', 'basefont',
        'b', 'em', 'big', 'cite', 'small', 'spacer', 'listing',
        'i', 'rp', 'del', 'code',          'strike', 'marquee',
        'q', 'rt', 'ins', 'font',          'strong',
        's', 'tt', 'sub', 'mark',
        'u', 'xm', 'sup', 'nobr',
                   'var', 'ruby',
                   'wbr', 'span',
                          'time',
    ];

    # ~

    protected $unmarkedBlockTypes = [
        'Code',
    ];

    #
    # Inline Elements
    #

    protected $InlineTypes = [
        '"' => ['SpecialCharacter'],
        '!' => ['Image'],
        '&' => ['SpecialCharacter'],
        '*' => ['Emphasis'],
        ':' => ['Url'],
        '<' => ['UrlTag', 'EmailTag', 'Markup', 'SpecialCharacter'],
        '>' => ['SpecialCharacter'],
        '[' => ['Link'],
        '_' => ['Emphasis'],
        '`' => ['Code'],
        '~' => ['Strikethrough'],
        '\\' => ['EscapeSequence'],
    ];

    # ~

    protected $inlineMarkerList = '!"*_&[:<>`~\\';

    protected $tag_attrs = [];

    public function __construct(array $tag_attrs = [])
    {
        $this->tag_attrs = $tag_attrs;
    }

    public function text($text)
    {
        # make sure no definitions are set
        $this->DefinitionData = [];

        # standardize line breaks
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        # remove surrounding line breaks
        $text = trim($text, "\n");

        # split text into lines
        $lines = explode("\n", $text);

        # iterate through lines to identify blocks
        $markup = $this->lines($lines);

        # trim line breaks
        $markup = trim($markup, "\n");

        return $markup;
    }

    public function setMarkupEscaped($markupEscaped)
    {
        $this->markupEscaped = $markupEscaped;

        return $this;
    }

    public function setUrlsLinked($urlsLinked)
    {
        $this->urlsLinked = $urlsLinked;

        return $this;
    }

    #
    # Setters
    #

    public function setBreaksEnabled($breaksEnabled)
    {
        $this->breaksEnabled = $breaksEnabled;

        return $this;
    }

    #
    # Blocks
    #

    protected function lines(array $lines)
    {
        $CurrentBlock = null;

        foreach ($lines as $line) {
            if (chop($line) === '') {
                if (isset($CurrentBlock)) {
                    $CurrentBlock['interrupted'] = true;
                }

                continue;
            }

            if (strpos($line, "\t") !== false) {
                $parts = explode("\t", $line);

                $line = $parts[0];

                unset($parts[0]);

                foreach ($parts as $part) {
                    $shortage = 4 - mb_strlen($line, 'utf-8') % 4;

                    $line .= str_repeat(' ', $shortage);
                    $line .= $part;
                }
            }

            $indent = 0;

            while (isset($line[$indent]) && $line[$indent] === ' ') {
                ++$indent;
            }

            $text = $indent > 0 ? substr($line, $indent) : $line;

            # ~

            $Line = ['body' => $line, 'indent' => $indent, 'text' => $text];

            # ~

            if (isset($CurrentBlock['continuable'])) {
                $Block = $this->{'block'.$CurrentBlock['type'].'Continue'}($Line, $CurrentBlock);

                if (isset($Block)) {
                    $CurrentBlock = $Block;

                    continue;
                } else {
                    if ($this->isBlockCompletable($CurrentBlock['type'])) {
                        $CurrentBlock = $this->{'block'.$CurrentBlock['type'].'Complete'}($CurrentBlock);
                    }
                }
            }

            # ~

            $marker = $text[0];

            # ~

            $blockTypes = $this->unmarkedBlockTypes;

            if (isset($this->BlockTypes[$marker])) {
                foreach ($this->BlockTypes[$marker] as $blockType) {
                    $blockTypes [] = $blockType;
                }
            }

            #
            # ~

            foreach ($blockTypes as $blockType) {
                $Block = $this->{'block'.$blockType}($Line, $CurrentBlock);

                if (isset($Block)) {
                    $Block['type'] = $blockType;

                    if (!isset($Block['identified'])) {
                        $Blocks [] = $CurrentBlock;

                        $Block['identified'] = true;
                    }

                    if ($this->isBlockContinuable($blockType)) {
                        $Block['continuable'] = true;
                    }

                    $CurrentBlock = $Block;

                    continue 2;
                }
            }

            # ~

            if (isset($CurrentBlock) && !isset($CurrentBlock['type']) && !isset($CurrentBlock['interrupted'])) {
                $CurrentBlock['element']['text'] .= "\n".$text;
            } else {
                $Blocks [] = $CurrentBlock;

                $CurrentBlock = $this->paragraph($Line);

                $CurrentBlock['identified'] = true;
            }
        }

        # ~

        if (isset($CurrentBlock['continuable']) && $this->isBlockCompletable($CurrentBlock['type'])) {
            $CurrentBlock = $this->{'block'.$CurrentBlock['type'].'Complete'}($CurrentBlock);
        }

        # ~

        $Blocks [] = $CurrentBlock;

        unset($Blocks[0]);

        # ~

        $markup = '';

        foreach ($Blocks as $Block) {
            if (isset($Block['hidden'])) {
                continue;
            }

            $markup .= "\n";
            $markup .= isset($Block['markup']) ? $Block['markup'] : $this->element($Block['element']);
        }

        $markup .= "\n";

        # ~

        return $markup;
    }

    protected function isBlockContinuable($Type)
    {
        return method_exists($this, 'block'.$Type.'Continue');
    }

    protected function isBlockCompletable($Type)
    {
        return method_exists($this, 'block'.$Type.'Complete');
    }

    #
    # Code

    protected function blockCode($Line, $Block = null)
    {
        if (isset($Block) && !isset($Block['type']) && !isset($Block['interrupted'])) {
            return;
        }

        if ($Line['indent'] >= 4) {
            $text = substr($Line['body'], 4);

            list($tag_name, $attrs) = $this->getTagAttributes('pre');
            list($code_tag_name, $code_attrs) = $this->getTagAttributes('code');

            $Block = [
                'element' => [
                    'name' => $tag_name,
                    'handler' => 'element',
                    'text' => [
                        'name' => $code_tag_name,
                        'text' => $text,
                        'attributes' => $code_attrs,
                    ],
                    'attributes' => $attrs,
                ],
            ];

            return $Block;
        }
    }

    protected function blockCodeContinue($Line, $Block)
    {
        if ($Line['indent'] >= 4) {
            if (isset($Block['interrupted'])) {
                $Block['element']['text']['text'] .= "\n";

                unset($Block['interrupted']);
            }

            $Block['element']['text']['text'] .= "\n";

            $text = substr($Line['body'], 4);

            $Block['element']['text']['text'] .= $text;

            return $Block;
        }
    }

    protected function blockCodeComplete($Block)
    {
        $text = $Block['element']['text']['text'];

        $text = htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');

        $Block['element']['text']['text'] = $text;

        return $Block;
    }

    #
    # Comment

    protected function blockComment($Line)
    {
        if ($this->markupEscaped) {
            return;
        }

        if (isset($Line['text'][3]) && $Line['text'][3] === '-' && $Line['text'][2] === '-' && $Line['text'][1] === '!') {
            $Block = [
                'markup' => $Line['body'],
            ];

            if (preg_match('/-->$/', $Line['text'])) {
                $Block['closed'] = true;
            }

            return $Block;
        }
    }

    protected function blockCommentContinue($Line, array $Block)
    {
        if (isset($Block['closed'])) {
            return;
        }

        $Block['markup'] .= "\n".$Line['body'];

        if (preg_match('/-->$/', $Line['text'])) {
            $Block['closed'] = true;
        }

        return $Block;
    }

    #
    # Fenced Code

    protected function blockFencedCode($Line)
    {
        if (preg_match('/^['.$Line['text'][0].']{3,}[ ]*([\w-]+)?[ ]*$/', $Line['text'], $matches)) {
            list($tag_name, $attrs) = $this->getTagAttributes('code');
            $Element = [
                'name' => $tag_name,
                'text' => '',
                'attributes' => $attrs,
            ];

            if (isset($matches[1])) {
                $class = 'language-'.$matches[1];

                $Element['attributes'] = [
                    'class' => $class,
                ];
            }

            list($tag_name, $attrs) = $this->getTagAttributes('pre');

            $Block = [
                'char' => $Line['text'][0],
                'element' => [
                    'name' => $tag_name,
                    'handler' => 'element',
                    'text' => $Element,
                    'attributes' => $attrs,
                ],
            ];

            return $Block;
        }
    }

    protected function blockFencedCodeContinue($Line, $Block)
    {
        if (isset($Block['complete'])) {
            return;
        }

        if (isset($Block['interrupted'])) {
            $Block['element']['text']['text'] .= "\n";

            unset($Block['interrupted']);
        }

        if (preg_match('/^'.$Block['char'].'{3,}[ ]*$/', $Line['text'])) {
            $Block['element']['text']['text'] = substr($Block['element']['text']['text'], 1);

            $Block['complete'] = true;

            return $Block;
        }

        $Block['element']['text']['text'] .= "\n".$Line['body'];

        return $Block;
    }

    protected function blockFencedCodeComplete($Block)
    {
        $text = $Block['element']['text']['text'];

        $text = htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');

        $Block['element']['text']['text'] = $text;

        return $Block;
    }

    #
    # Header

    protected function blockHeader($Line)
    {
        if (isset($Line['text'][1])) {
            $level = 1;

            while (isset($Line['text'][$level]) && $Line['text'][$level] === '#') {
                ++$level;
            }

            if ($level > 6) {
                return;
            }

            $text = trim($Line['text'], '# ');

            list($tag_name, $attrs) = $this->getTagAttributes('h'.min(6, $level));

            $Block = [
                'element' => [
                    'name' => $tag_name,
                    'text' => $text,
                    'handler' => 'line',
                    'attributes' => $attrs,
                ],
            ];

            return $Block;
        }
    }

    #
    # List

    protected function blockList($Line)
    {
        list($name, $pattern) = $Line['text'][0] <= '-' ? ['ul', '[*+-]'] : ['ol', '[0-9]+[.]'];

        list($tag_name, $attrs) = $this->getTagAttributes($name);

        if (preg_match('/^('.$pattern.'[ ]+)(.*)/', $Line['text'], $matches)) {
            $Block = [
                'indent' => $Line['indent'],
                'pattern' => $pattern,
                'element' => [
                    'name' => $tag_name,
                    'attributes' => $attrs,
                    'handler' => 'elements',
                ],
            ];

            list($tag_name, $attrs) = $this->getTagAttributes('li');

            $Block['li'] = [
                'name' => $tag_name,
                'handler' => 'li',
                'attributes' => $attrs,
                'text' => [
                    $matches[2],
                ],
            ];

            $Block['element']['text'] [] = &$Block['li'];

            return $Block;
        }
    }

    protected function blockListContinue($Line, array $Block)
    {
        if ($Block['indent'] === $Line['indent'] && preg_match('/^'.$Block['pattern'].'(?:[ ]+(.*)|$)/', $Line['text'], $matches)) {
            if (isset($Block['interrupted'])) {
                $Block['li']['text'] [] = '';

                unset($Block['interrupted']);
            }

            unset($Block['li']);

            $text = isset($matches[1]) ? $matches[1] : '';

            list($tag_name, $attrs) = $this->getTagAttributes('li');

            $Block['li'] = [
                'name' => $tag_name,
                'handler' => 'li',
                'attributes' => $attrs,
                'text' => [
                    $text,
                ],
            ];

            $Block['element']['text'] [] = &$Block['li'];

            return $Block;
        }

        if ($Line['text'][0] === '[' && $this->blockReference($Line)) {
            return $Block;
        }

        if (!isset($Block['interrupted'])) {
            $text = preg_replace('/^[ ]{0,4}/', '', $Line['body']);

            $Block['li']['text'] [] = $text;

            return $Block;
        }

        if ($Line['indent'] > 0) {
            $Block['li']['text'] [] = '';

            $text = preg_replace('/^[ ]{0,4}/', '', $Line['body']);

            $Block['li']['text'] [] = $text;

            unset($Block['interrupted']);

            return $Block;
        }
    }

    #
    # Quote

    protected function blockQuote($Line)
    {
        if (preg_match('/^>[ ]?(.*)/', $Line['text'], $matches)) {
            list($tag_name, $attrs) = $this->getTagAttributes('blockquote');

            $Block = [
                'element' => [
                    'name' => $tag_name,
                    'handler' => 'lines',
                    'text' => (array) $matches[1],
                    'attributes' => $attrs,
                ],
            ];

            return $Block;
        }
    }

    protected function blockQuoteContinue($Line, array $Block)
    {
        if ($Line['text'][0] === '>' && preg_match('/^>[ ]?(.*)/', $Line['text'], $matches)) {
            if (isset($Block['interrupted'])) {
                $Block['element']['text'] [] = '';

                unset($Block['interrupted']);
            }

            $Block['element']['text'] [] = $matches[1];

            return $Block;
        }

        if (!isset($Block['interrupted'])) {
            $Block['element']['text'] [] = $Line['text'];

            return $Block;
        }
    }

    #
    # Rule

    protected function blockRule($Line)
    {
        if (preg_match('/^(['.$Line['text'][0].'])([ ]*\1){2,}[ ]*$/', $Line['text'])) {
            list($tag_name, $attrs) = $this->getTagAttributes('hr');
            $Block = [
                'element' => [
                    'name' => $tag_name,
                    'attributes' => $attrs,
                ],
            ];

            return $Block;
        }
    }

    #
    # Setext

    protected function blockSetextHeader($Line, array $Block = null)
    {
        if (!isset($Block) || isset($Block['type']) || isset($Block['interrupted'])) {
            return;
        }

        if (chop($Line['text'], $Line['text'][0]) === '') {
            $Block['element']['name'] = $Line['text'][0] === '=' ? 'h1' : 'h2';

            return $Block;
        }
    }

    #
    # Markup

    protected function blockMarkup($Line)
    {
        if ($this->markupEscaped) {
            return;
        }

        if (preg_match('/^<(\w*)(?:[ ]*'.$this->regexHtmlAttribute.')*[ ]*(\/)?>/', $Line['text'], $matches)) {
            $element = strtolower($matches[1]);

            if (in_array($element, $this->textLevelElements)) {
                return;
            }

            list($tag_name, $attrs) = $this->getTagAttributes($matches[1]);

            $Block = [
                'name' => $tag_name,
                'depth' => 0,
                'markup' => $Line['text'],
                'attributes' => $attrs,
            ];

            $length = strlen($matches[0]);

            $remainder = substr($Line['text'], $length);

            if (trim($remainder) === '') {
                if (isset($matches[2]) || in_array($matches[1], $this->voidElements)) {
                    $Block['closed'] = true;

                    $Block['void'] = true;
                }
            } else {
                if (isset($matches[2]) || in_array($matches[1], $this->voidElements)) {
                    return;
                }

                if (preg_match('/<\/'.$matches[1].'>[ ]*$/i', $remainder)) {
                    $Block['closed'] = true;
                }
            }

            return $Block;
        }
    }

    protected function blockMarkupContinue($Line, array $Block)
    {
        if (isset($Block['closed'])) {
            return;
        }

        if (preg_match('/^<'.$Block['name'].'(?:[ ]*'.$this->regexHtmlAttribute.')*[ ]*>/i', $Line['text'])) {
            # open

            ++$Block['depth'];
        }

        if (preg_match('/(.*?)<\/'.$Block['name'].'>[ ]*$/i', $Line['text'], $matches)) {
            # close

            if ($Block['depth'] > 0) {
                --$Block['depth'];
            } else {
                $Block['closed'] = true;
            }
        }

        if (isset($Block['interrupted'])) {
            $Block['markup'] .= "\n";

            unset($Block['interrupted']);
        }

        $Block['markup'] .= "\n".$Line['body'];

        return $Block;
    }

    #
    # Reference

    protected function blockReference($Line)
    {
        if (preg_match('/^\[(.+?)\]:[ ]*<?(\S+?)>?(?:[ ]+["\'(](.+)["\')])?[ ]*$/', $Line['text'], $matches)) {
            $id = strtolower($matches[1]);

            $Data = [
                'url' => $matches[2],
                'title' => null,
            ];

            if (isset($matches[3])) {
                $Data['title'] = $matches[3];
            }

            $this->DefinitionData['Reference'][$id] = $Data;

            $Block = [
                'hidden' => true,
            ];

            return $Block;
        }
    }

    #
    # Table

    protected function blockTable($Line, array $Block = null)
    {
        if (!isset($Block) || isset($Block['type']) || isset($Block['interrupted'])) {
            return;
        }

        if (strpos($Block['element']['text'], '|') !== false && chop($Line['text'], ' -:|') === '') {
            $alignments = [];

            $divider = $Line['text'];

            $divider = trim($divider);
            $divider = trim($divider, '|');

            $dividerCells = explode('|', $divider);

            foreach ($dividerCells as $dividerCell) {
                $dividerCell = trim($dividerCell);

                if ($dividerCell === '') {
                    continue;
                }

                $alignment = null;

                if ($dividerCell[0] === ':') {
                    $alignment = 'left';
                }

                if (substr($dividerCell, -1) === ':') {
                    $alignment = $alignment === 'left' ? 'center' : 'right';
                }

                $alignments [] = $alignment;
            }

            # ~

            $HeaderElements = [];

            $header = $Block['element']['text'];

            $header = trim($header);
            $header = trim($header, '|');

            $headerCells = explode('|', $header);

            foreach ($headerCells as $index => $headerCell) {
                $headerCell = trim($headerCell);

                list($tag_name, $attrs) = $this->getTagAttributes('th');

                $HeaderElement = [
                    'name' => $tag_name,
                    'text' => $headerCell,
                    'handler' => 'line',
                    'attributes' => $attrs,
                ];

                if (isset($alignments[$index])) {
                    $alignment = $alignments[$index];

                    $HeaderElement['attributes']['style'] = 'text-align: '.$alignment.';';
                }

                $HeaderElements [] = $HeaderElement;
            }

            # ~

            list($tag_name, $attrs) = $this->getTagAttributes('table');

            $Block = [
                'alignments' => $alignments,
                'identified' => true,
                'element' => [
                    'name' => $tag_name,
                    'handler' => 'elements',
                    'attributes' => $attrs,
                ],
            ];

            list($tag_name, $attrs) = $this->getTagAttributes('thead');

            $Block['element']['text'] [] = [
                'name' => $tag_name,
                'handler' => 'elements',
                'attributes' => $attrs,
            ];

            list($tag_name, $attrs) = $this->getTagAttributes('tbody');

            $Block['element']['text'] [] = [
                'name' => $tag_name,
                'handler' => 'elements',
                'text' => [],
                'attributes' => $attrs,
            ];

            list($tag_name, $attrs) = $this->getTagAttributes('tr');

            $Block['element']['text'][0]['text'] [] = [
                'name' => $tag_name,
                'handler' => 'elements',
                'text' => $HeaderElements,
                'attributes' => $attrs,
            ];

            return $Block;
        }
    }

    protected function blockTableContinue($Line, array $Block)
    {
        if (isset($Block['interrupted'])) {
            return;
        }

        if ($Line['text'][0] === '|' || strpos($Line['text'], '|')) {
            $Elements = [];

            $row = $Line['text'];

            $row = trim($row);
            $row = trim($row, '|');

            preg_match_all('/(?:(\\\\[|])|[^|`]|`[^`]+`|`)+/', $row, $matches);

            foreach ($matches[0] as $index => $cell) {
                $cell = trim($cell);

                list($tag_name, $attrs) = $this->getTagAttributes('td');

                $Element = [
                    'name' => $tag_name,
                    'handler' => 'line',
                    'text' => $cell,
                    'attributes' => $attrs,
                ];

                if (isset($Block['alignments'][$index])) {
                    $Element['attributes']['style'] = 'text-align: '.$Block['alignments'][$index].';';
                }

                $Elements [] = $Element;
            }

            list($tag_name, $attrs) = $this->getTagAttributes('tr');

            $Element = [
                'name' => $tag_name,
                'handler' => 'elements',
                'text' => $Elements,
                'attributes' => $attrs,
            ];

            $Block['element']['text'][1]['text'] [] = $Element;

            return $Block;
        }
    }

    #
    # ~
    #

    protected function paragraph($Line)
    {
        list($tag_name, $attrs) = $this->getTagAttributes('p');

        $Block = [
            'element' => [
                'name' => $tag_name,
                'text' => $Line['text'],
                'handler' => 'line',
                'attributes' => $attrs,
            ],
        ];

        return $Block;
    }

    #
    # ~
    #

    public function line($text)
    {
        $markup = '';

        # $excerpt is based on the first occurrence of a marker

        while ($excerpt = strpbrk($text, $this->inlineMarkerList)) {
            $marker = $excerpt[0];

            $markerPosition = strpos($text, $marker);

            $Excerpt = ['text' => $excerpt, 'context' => $text];

            foreach ($this->InlineTypes[$marker] as $inlineType) {
                $Inline = $this->{'inline'.$inlineType}($Excerpt);

                if (!isset($Inline)) {
                    continue;
                }

                # makes sure that the inline belongs to "our" marker

                if (isset($Inline['position']) && $Inline['position'] > $markerPosition) {
                    continue;
                }

                # sets a default inline position

                if (!isset($Inline['position'])) {
                    $Inline['position'] = $markerPosition;
                }

                # the text that comes before the inline
                $unmarkedText = substr($text, 0, $Inline['position']);

                # compile the unmarked text
                $markup .= $this->unmarkedText($unmarkedText);

                # compile the inline
                $markup .= isset($Inline['markup']) ? $Inline['markup'] : $this->element($Inline['element']);

                # remove the examined text
                $text = substr($text, $Inline['position'] + $Inline['extent']);

                continue 2;
            }

            # the marker does not belong to an inline

            $unmarkedText = substr($text, 0, $markerPosition + 1);

            $markup .= $this->unmarkedText($unmarkedText);

            $text = substr($text, $markerPosition + 1);
        }

        $markup .= $this->unmarkedText($text);

        return $markup;
    }

    #
    # ~
    #

    protected function inlineCode($Excerpt)
    {
        $marker = $Excerpt['text'][0];

        if (preg_match('/^('.$marker.'+)[ ]*(.+?)[ ]*(?<!'.$marker.')\1(?!'.$marker.')/s', $Excerpt['text'], $matches)) {
            $text = $matches[2];
            $text = htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');
            $text = preg_replace("/[ ]*\n/", ' ', $text);

            list($tag_name, $attrs) = $this->getTagAttributes('code');

            return [
                'extent' => strlen($matches[0]),
                'element' => [
                    'name' => $tag_name,
                    'text' => $text,
                    'attributes' => $attrs,
                ],
            ];
        }
    }

    protected function inlineEmailTag($Excerpt)
    {
        if (strpos($Excerpt['text'], '>') !== false && preg_match('/^<((mailto:)?\S+?@\S+?)>/i', $Excerpt['text'], $matches)) {
            $url = $matches[1];

            if (!isset($matches[2])) {
                $url = 'mailto:'.$url;
            }

            list($tag_name, $attrs) = $this->getTagAttributes('a');
            $attrs['href'] = $url;

            return [
                'extent' => strlen($matches[0]),
                'element' => [
                    'name' => 'a', // we don't touch links
                    'text' => $matches[1],
                    'attributes' => $attrs,
                ],
            ];
        }
    }

    protected function inlineEmphasis($Excerpt)
    {
        if (!isset($Excerpt['text'][1])) {
            return;
        }

        $marker = $Excerpt['text'][0];

        if ($Excerpt['text'][1] === $marker && preg_match($this->StrongRegex[$marker], $Excerpt['text'], $matches)) {
            $emphasis = 'strong';
        } elseif (preg_match($this->EmRegex[$marker], $Excerpt['text'], $matches)) {
            $emphasis = 'em';
        } else {
            return;
        }

        list($tag_name, $attrs) = $this->getTagAttributes($emphasis);

        return [
            'extent' => strlen($matches[0]),
            'element' => [
                'name' => $tag_name,
                'handler' => 'line',
                'text' => $matches[1],
                'attributes' => $attrs,
            ],
        ];
    }

    protected function inlineEscapeSequence($Excerpt)
    {
        if (isset($Excerpt['text'][1]) && in_array($Excerpt['text'][1], $this->specialCharacters)) {
            return [
                'markup' => $Excerpt['text'][1],
                'extent' => 2,
            ];
        }
    }

    protected function inlineImage($Excerpt)
    {
        if (!isset($Excerpt['text'][1]) || $Excerpt['text'][1] !== '[') {
            return;
        }

        $Excerpt['text'] = substr($Excerpt['text'], 1);

        $Link = $this->inlineLink($Excerpt);

        if ($Link === null) {
            return;
        }

        list($tag_name, $attrs) = $this->getTagAttributes('img');
        $attrs['src'] = $Link['element']['attributes']['href'];
        $attrs['alt'] = $Link['element']['text'];

        $Inline = [
            'extent' => $Link['extent'] + 1,
            'element' => [
                'name' => 'img', // we don't touch images
                'attributes' => $attrs,
            ],
        ];

        $Inline['element']['attributes'] += $Link['element']['attributes'];

        unset($Inline['element']['attributes']['href']);

        return $Inline;
    }

    protected function inlineLink($Excerpt)
    {
        list($tag_name, $attrs) = $this->getTagAttributes('a');
        $attrs['href'] = null;
        $attrs['title'] = null;

        $Element = [
            'name' => 'a', // we don't touch links
            'handler' => 'line',
            'text' => null,
            'attributes' => $attrs,
        ];

        $extent = 0;

        $remainder = $Excerpt['text'];

        if (preg_match('/\[((?:[^][]|(?R))*)\]/', $remainder, $matches)) {
            $Element['text'] = $matches[1];

            $extent += strlen($matches[0]);

            $remainder = substr($remainder, $extent);
        } else {
            return;
        }

        if (preg_match('/^[(]((?:[^ ()]|[(][^ )]+[)])+)(?:[ ]+("[^"]*"|\'[^\']*\'))?[)]/', $remainder, $matches)) {
            $Element['attributes']['href'] = $matches[1];

            if (isset($matches[2])) {
                $Element['attributes']['title'] = substr($matches[2], 1, -1);
            }

            $extent += strlen($matches[0]);
        } else {
            if (preg_match('/^\s*\[(.*?)\]/', $remainder, $matches)) {
                $definition = strlen($matches[1]) ? $matches[1] : $Element['text'];
                $definition = strtolower($definition);

                $extent += strlen($matches[0]);
            } else {
                $definition = strtolower($Element['text']);
            }

            if (!isset($this->DefinitionData['Reference'][$definition])) {
                return;
            }

            $Definition = $this->DefinitionData['Reference'][$definition];

            $Element['attributes']['href'] = $Definition['url'];
            $Element['attributes']['title'] = $Definition['title'];
        }

        $Element['attributes']['href'] = str_replace(['&', '<'], ['&amp;', '&lt;'], $Element['attributes']['href']);

        return [
            'extent' => $extent,
            'element' => $Element,
        ];
    }

    protected function inlineMarkup($Excerpt)
    {
        if ($this->markupEscaped || strpos($Excerpt['text'], '>') === false) {
            return;
        }

        if ($Excerpt['text'][1] === '/' && preg_match('/^<\/\w*[ ]*>/s', $Excerpt['text'], $matches)) {
            return [
                'markup' => $matches[0],
                'extent' => strlen($matches[0]),
            ];
        }

        if ($Excerpt['text'][1] === '!' && preg_match('/^<!---?[^>-](?:-?[^-])*-->/s', $Excerpt['text'], $matches)) {
            return [
                'markup' => $matches[0],
                'extent' => strlen($matches[0]),
            ];
        }

        if ($Excerpt['text'][1] !== ' ' && preg_match('/^<\w*(?:[ ]*'.$this->regexHtmlAttribute.')*[ ]*\/?>/s', $Excerpt['text'], $matches)) {
            return [
                'markup' => $matches[0],
                'extent' => strlen($matches[0]),
            ];
        }
    }

    protected function inlineSpecialCharacter($Excerpt)
    {
        if ($Excerpt['text'][0] === '&' && !preg_match('/^&#?\w+;/', $Excerpt['text'])) {
            return [
                'markup' => '&amp;',
                'extent' => 1,
            ];
        }

        $SpecialCharacter = ['>' => 'gt', '<' => 'lt', '"' => 'quot'];

        if (isset($SpecialCharacter[$Excerpt['text'][0]])) {
            return [
                'markup' => '&'.$SpecialCharacter[$Excerpt['text'][0]].';',
                'extent' => 1,
            ];
        }
    }

    protected function inlineStrikethrough($Excerpt)
    {
        if (!isset($Excerpt['text'][1])) {
            return;
        }

        if ($Excerpt['text'][1] === '~' && preg_match('/^~~(?=\S)(.+?)(?<=\S)~~/', $Excerpt['text'], $matches)) {
            list($tag_name, $attrs) = $this->getTagAttributes('del');

            return [
                'extent' => strlen($matches[0]),
                'element' => [
                    'name' => $tag_name,
                    'text' => $matches[1],
                    'handler' => 'line',
                    'attributes' => $attrs,
                ],
            ];
        }
    }

    protected function inlineUrl($Excerpt)
    {
        if ($this->urlsLinked !== true || !isset($Excerpt['text'][2]) || $Excerpt['text'][2] !== '/') {
            return;
        }

        if (preg_match('/\bhttps?:[\/]{2}[^\s<]+\b\/*/ui', $Excerpt['context'], $matches, PREG_OFFSET_CAPTURE)) {
            list($tag_name, $attrs) = $this->getTagAttributes('a');
            $attrs['href'] = $matches[0][0];

            $Inline = [
                'extent' => strlen($matches[0][0]),
                'position' => $matches[0][1],
                'element' => [
                    'name' => 'a', // we don't touch links
                    'text' => $matches[0][0],
                    'attributes' => $attrs,
                ],
            ];

            return $Inline;
        }
    }

    protected function inlineUrlTag($Excerpt)
    {
        if (strpos($Excerpt['text'], '>') !== false && preg_match('/^<(\w+:\/{2}[^ >]+)>/i', $Excerpt['text'], $matches)) {
            $url = str_replace(['&', '<'], ['&amp;', '&lt;'], $matches[1]);
            list($tag_name, $attrs) = $this->getTagAttributes('a');
            $attrs['href'] = $url;

            return [
                'extent' => strlen($matches[0]),
                'element' => [
                    'name' => 'a', // we don't touch links
                    'text' => $url,
                    'attributes' => $attrs,
                ],
            ];
        }
    }

    # ~

    protected function unmarkedText($text)
    {
        if ($this->breaksEnabled) {
            $text = preg_replace('/[ ]*\n/', "<br />\n", $text);
        } else {
            $text = preg_replace('/(?:[ ][ ]+|[ ]*\\\\)\n/', "<br />\n", $text);
            $text = str_replace(" \n", "\n", $text);
        }

        return $text;
    }

    #
    # Handlers
    #

    protected function element(array $Element)
    {
        $markup = '<'.$Element['name'];

        if (isset($Element['attributes'])) {
            foreach ($Element['attributes'] as $name => $value) {
                if ($value === null) {
                    continue;
                }

                $markup .= ' '.$name.'="'.$value.'"';
            }
        }

        if (isset($Element['text'])) {
            $markup .= '>';

            if (isset($Element['handler'])) {
                $markup .= $this->{$Element['handler']}($Element['text']);
            } else {
                $markup .= $Element['text'];
            }

            $markup .= '</'.$Element['name'].'>';
        } else {
            $markup .= ' />';
        }

        return $markup;
    }

    protected function elements(array $Elements)
    {
        $markup = '';

        foreach ($Elements as $Element) {
            $markup .= "\n".$this->element($Element);
        }

        $markup .= "\n";

        return $markup;
    }

    # ~

    protected function li($lines)
    {
        $markup = $this->lines($lines);

        $trimmedMarkup = trim($markup);

        if (!in_array('', $lines) && substr($trimmedMarkup, 0, 3) === '<p>') {
            $markup = $trimmedMarkup;
            $markup = substr($markup, 3);

            $position = strpos($markup, '</p>');

            $markup = substr_replace($markup, '', $position, 4);
        }

        return $markup;
    }

    #
    # Deprecated Methods
    #

    public function parse($text)
    {
        $markup = $this->text($text);

        return $markup;
    }

    #
    # Static Methods
    #

    public static function instance($name = 'default')
    {
        if (isset(self::$instances[$name])) {
            return self::$instances[$name];
        }

        $instance = new static();

        self::$instances[$name] = $instance;

        return $instance;
    }

    protected function getTagAttributes($tag_name)
    {
        $attrs = [];

        if (isset($this->tag_attrs[$tag_name])) {
            $info = $this->tag_attrs[$tag_name];
            $tag_name = isset($info['tag_name']) ? $info['tag_name'] : $tag_name;
            unset($info['tag_name']);
            $attrs = $info;
        }

        return [$tag_name, $attrs];
    }
}
