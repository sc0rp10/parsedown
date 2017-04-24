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

    protected $blockTypes = [
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

    protected $definitionData;

    protected $specialCharacters = [
        '\\',
        '`',
        '*',
        '_',
        '{',
        '}',
        '[',
        ']',
        '(',
        ')',
        '>',
        '#',
        '+',
        '-',
        '.',
        '!',
        '|',
    ];

    protected $strongRegex = [
        '*' => '/^[*]{2}((?:\\\\\*|[^*]|[*][^*]*[*])+?)[*]{2}(?![*])/s',
        '_' => '/^__((?:\\\\_|[^_]|_[^_]*_)+?)__(?!_)/us',
    ];

    protected $emRegex = [
        '*' => '/^[*]((?:\\\\\*|[^*]|[*][*][^*]+?[*][*])+?)[*](?![*])/s',
        '_' => '/^_((?:\\\\_|[^_]|__[^_]*__)+?)_(?!_)\b/us',
    ];

    protected $regexHtmlAttribute = '[a-zA-Z_:][\w:.-]*(?:\s*=\s*(?:[^"\'=<>`\s]+|"[^"]*"|\'[^\']*\'))?';

    protected $voidElements = [
        'area',
        'base',
        'br',
        'col',
        'command',
        'embed',
        'hr',
        'img',
        'input',
        'link',
        'meta',
        'param',
        'source',
    ];

    protected $textLevelElements = [
        'a',
        'br',
        'bdo',
        'abbr',
        'blink',
        'nextid',
        'acronym',
        'basefont',
        'b',
        'em',
        'big',
        'cite',
        'small',
        'spacer',
        'listing',
        'i',
        'rp',
        'del',
        'code',
        'strike',
        'marquee',
        'q',
        'rt',
        'ins',
        'font',
        'strong',
        's',
        'tt',
        'sub',
        'mark',
        'u',
        'xm',
        'sup',
        'nobr',
        'var',
        'ruby',
        'wbr',
        'span',
        'time',
    ];

    # ~

    protected $unmarkedBlockTypes = [
        'Code',
    ];

    #
    # Inline Elements
    #

    protected $inlineTypes = [
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
        $this->definitionData = [];

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

        $markup = $this->processParagraphs($markup);

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

    protected function lines(array $lines, $no_attrs = false)
    {
        $currentBlock = null;

        foreach ($lines as $line) {
            if (chop($line) === '') {
                if (isset($currentBlock)) {
                    $currentBlock['interrupted'] = true;
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

            $line = ['body' => $line, 'indent' => $indent, 'text' => $text];

            # ~

            if (isset($currentBlock['continuable'])) {
                $block = $this->{'block'.$currentBlock['type'].'Continue'}($line, $currentBlock);

                if (isset($block)) {
                    $currentBlock = $block;

                    continue;
                } else {
                    if ($this->isBlockCompletable($currentBlock['type'])) {
                        $currentBlock = $this->{'block'.$currentBlock['type'].'Complete'}($currentBlock);
                    }
                }
            }

            # ~

            $marker = $text[0];

            # ~

            $blockTypes = $this->unmarkedBlockTypes;

            if (isset($this->blockTypes[$marker])) {
                foreach ($this->blockTypes[$marker] as $blockType) {
                    $blockTypes [] = $blockType;
                }
            }

            #
            # ~

            foreach ($blockTypes as $blockType) {
                $block = $this->{'block'.$blockType}($line, $currentBlock);

                if (isset($block)) {
                    $block['type'] = $blockType;

                    if (!isset($block['identified'])) {
                        $blocks [] = $currentBlock;

                        $block['identified'] = true;
                    }

                    if ($this->isBlockContinuable($blockType)) {
                        $block['continuable'] = true;
                    }

                    $currentBlock = $block;

                    continue 2;
                }
            }

            # ~

            if (isset($currentBlock) && !isset($currentBlock['type']) && !isset($currentBlock['interrupted'])) {
                $currentBlock['element']['text'] .= "\n".$text;
            } else {
                $blocks [] = $currentBlock;

                $currentBlock = $this->paragraph($line, $no_attrs);

                $currentBlock['identified'] = true;
            }
        }

        # ~

        if (isset($currentBlock['continuable']) && $this->isBlockCompletable($currentBlock['type'])) {
            $currentBlock = $this->{'block'.$currentBlock['type'].'Complete'}($currentBlock);
        }

        # ~

        $blocks [] = $currentBlock;

        unset($blocks[0]);

        # ~

        $markup = '';

        foreach ($blocks as $block) {
            if (isset($block['hidden'])) {
                continue;
            }

            $markup .= "\n";
            $markup .= isset($block['markup']) ? $block['markup'] : $this->element($block['element']);
        }

        $markup .= "\n";

        # ~

        return $markup;
    }

    protected function isBlockContinuable($type)
    {
        return method_exists($this, 'block'.$type.'Continue');
    }

    protected function isBlockCompletable($type)
    {
        return method_exists($this, 'block'.$type.'Complete');
    }

    #
    # Code

    protected function blockCode($line, $block = null)
    {
        if (isset($block) && !isset($block['type']) && !isset($block['interrupted'])) {
            return;
        }

        if ($line['indent'] >= 4) {
            $text = substr($line['body'], 4);

            list($tag_name, $attrs) = $this->getTagAttributes('pre');
            list($code_tag_name, $code_attrs) = $this->getTagAttributes('code');

            $block = [
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

            return $block;
        }
    }

    protected function blockCodeContinue($line, $block)
    {
        if ($line['indent'] >= 4) {
            if (isset($block['interrupted'])) {
                $block['element']['text']['text'] .= "\n";

                unset($block['interrupted']);
            }

            $block['element']['text']['text'] .= "\n";

            $text = substr($line['body'], 4);

            $block['element']['text']['text'] .= $text;

            return $block;
        }
    }

    protected function blockCodeComplete($block)
    {
        $text = $block['element']['text']['text'];

        $text = htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');

        $block['element']['text']['text'] = $text;

        return $block;
    }

    #
    # Comment

    protected function blockComment($line)
    {
        if ($this->markupEscaped) {
            return;
        }

        if (isset($line['text'][3]) && $line['text'][3] === '-' && $line['text'][2] === '-' && $line['text'][1] === '!') {
            $block = [
                'markup' => $line['body'],
            ];

            if (preg_match('/-->$/', $line['text'])) {
                $block['closed'] = true;
            }

            return $block;
        }
    }

    protected function blockCommentContinue($line, array $block)
    {
        if (isset($block['closed'])) {
            return;
        }

        $block['markup'] .= "\n".$line['body'];

        if (preg_match('/-->$/', $line['text'])) {
            $block['closed'] = true;
        }

        return $block;
    }

    #
    # Fenced Code

    protected function blockFencedCode($line)
    {
        if (preg_match('/^['.$line['text'][0].']{3,}[ ]*([\w-]+)?[ ]*$/', $line['text'], $matches)) {
            list($tag_name, $attrs) = $this->getTagAttributes('code');

            $element = [
                'name' => $tag_name,
                'text' => '',
                'attributes' => $attrs,
            ];

            if (isset($matches[1])) {
                $class = 'language-'.$matches[1];

                $element['attributes'] = [
                    'class' => $class,
                ];
            }

            list($tag_name, $attrs) = $this->getTagAttributes('pre');

            $block = [
                'char' => $line['text'][0],
                'element' => [
                    'name' => $tag_name,
                    'handler' => 'element',
                    'text' => $element,
                    'attributes' => $attrs,
                ],
            ];

            return $block;
        }
    }

    protected function blockFencedCodeContinue($line, $block)
    {
        if (isset($block['complete'])) {
            return;
        }

        if (isset($block['interrupted'])) {
            $block['element']['text']['text'] .= "\n";

            unset($block['interrupted']);
        }

        if (preg_match('/^'.$block['char'].'{3,}[ ]*$/', $line['text'])) {
            $block['element']['text']['text'] = substr($block['element']['text']['text'], 1);

            $block['complete'] = true;

            return $block;
        }

        $block['element']['text']['text'] .= "\n".$line['body'];

        return $block;
    }

    protected function blockFencedCodeComplete($block)
    {
        $text = $block['element']['text']['text'];

        $text = htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');

        $block['element']['text']['text'] = $text;

        return $block;
    }

    #
    # Header

    protected function blockHeader($line)
    {
        if (isset($line['text'][1])) {
            $level = 1;

            while (isset($line['text'][$level]) && $line['text'][$level] === '#') {
                ++$level;
            }

            if ($level > 6) {
                return;
            }

            $text = trim($line['text'], '# ');

            list($tag_name, $attrs) = $this->getTagAttributes('h'.min(6, $level));

            $block = [
                'element' => [
                    'name' => $tag_name,
                    'text' => $text,
                    'handler' => 'line',
                    'attributes' => $attrs,
                ],
            ];

            return $block;
        }
    }

    #
    # List

    protected function blockList($line)
    {
        list($name, $pattern) = $line['text'][0] <= '-' ? ['ul', '[*+-]'] : ['ol', '[0-9]+[.]'];

        list($tag_name, $attrs) = $this->getTagAttributes($name);

        if (preg_match('/^('.$pattern.'[ ]+)(.*)/', $line['text'], $matches)) {
            $block = [
                'indent' => $line['indent'],
                'pattern' => $pattern,
                'element' => [
                    'name' => $tag_name,
                    'attributes' => $attrs,
                    'handler' => 'elements',
                ],
            ];

            list($tag_name, $attrs) = $this->getTagAttributes('li');

            $block['li'] = [
                'name' => $tag_name,
                'handler' => 'li',
                'attributes' => $attrs,
                'text' => [
                    $matches[2],
                ],
            ];

            $block['element']['text'][] = &$block['li'];

            return $block;
        }
    }

    protected function blockListContinue($line, array $block)
    {
        if ($block['indent'] === $line['indent'] && preg_match('/^'.$block['pattern'].'(?:[ ]+(.*)|$)/', $line['text'], $matches)) {
            if (isset($block['interrupted'])) {
                $block['li']['text'][] = '';

                unset($block['interrupted']);
            }

            unset($block['li']);

            $text = isset($matches[1]) ? $matches[1] : '';

            list($tag_name, $attrs) = $this->getTagAttributes('li');

            $block['li'] = [
                'name' => $tag_name,
                'handler' => 'li',
                'attributes' => $attrs,
                'text' => [
                    $text,
                ],
            ];

            $block['element']['text'][] = &$block['li'];

            return $block;
        }

        if ($line['text'][0] === '[' && $this->blockReference($line)) {
            return $block;
        }

        if (!isset($block['interrupted'])) {
            $text = preg_replace('/^[ ]{0,4}/', '', $line['body']);

            $block['li']['text'][] = $text;

            return $block;
        }

        if ($line['indent'] > 0) {
            $block['li']['text'][] = '';

            $text = preg_replace('/^[ ]{0,4}/', '', $line['body']);

            $block['li']['text'][] = $text;

            unset($block['interrupted']);

            return $block;
        }
    }

    #
    # Quote

    protected function blockQuote($line)
    {
        if (preg_match('/^>[ ]?(.*)/', $line['text'], $matches)) {
            list($tag_name, $attrs) = $this->getTagAttributes('blockquote');

            $block = [
                'element' => [
                    'name' => $tag_name,
                    'handler' => 'lines',
                    'text' => (array)$matches[1],
                    'attributes' => $attrs,
                ],
            ];

            return $block;
        }
    }

    protected function blockQuoteContinue($line, array $block)
    {
        if ($line['text'][0] === '>' && preg_match('/^>[ ]?(.*)/', $line['text'], $matches)) {
            if (isset($block['interrupted'])) {
                $block['element']['text'][] = '';

                unset($block['interrupted']);
            }

            $block['element']['text'][] = $matches[1];

            return $block;
        }

        if (!isset($block['interrupted'])) {
            $block['element']['text'][] = $line['text'];

            return $block;
        }
    }

    #
    # Rule

    protected function blockRule($line)
    {
        if (preg_match('/^(['.$line['text'][0].'])([ ]*\1){2,}[ ]*$/', $line['text'])) {
            list($tag_name, $attrs) = $this->getTagAttributes('hr');

            $block = [
                'element' => [
                    'name' => $tag_name,
                    'attributes' => $attrs,
                ],
            ];

            return $block;
        }
    }

    #
    # Setext

    protected function blockSetextHeader($line, array $block = null)
    {
        if (!isset($block) || isset($block['type']) || isset($block['interrupted'])) {
            return;
        }

        if (chop($line['text'], $line['text'][0]) === '') {
            $tag = $line['text'][0] === '=' ? 'h1' : 'h2';
            list($tag_name, $attrs) = $this->getTagAttributes($tag);
            $block['element']['name'] = $tag_name;
            $block['element']['attributes'] = $attrs;

            return $block;
        }
    }

    #
    # Markup

    protected function blockMarkup($line)
    {
        if ($this->markupEscaped) {
            return;
        }

        if (preg_match('/^<(\w*)(?:[ ]*'.$this->regexHtmlAttribute.')*[ ]*(\/)?>/', $line['text'], $matches)) {
            $element = strtolower($matches[1]);

            if (in_array($element, $this->textLevelElements)) {
                return;
            }

            list($tag_name, $attrs) = $this->getTagAttributes($matches[1]);

            $block = [
                'name' => $tag_name,
                'depth' => 0,
                'markup' => $line['text'],
                'attributes' => $attrs,
            ];

            $length = strlen($matches[0]);

            $remainder = substr($line['text'], $length);

            if (trim($remainder) === '') {
                if (isset($matches[2]) || in_array($matches[1], $this->voidElements)) {
                    $block['closed'] = true;

                    $block['void'] = true;
                }
            } else {
                if (isset($matches[2]) || in_array($matches[1], $this->voidElements)) {
                    return;
                }

                if (preg_match('/<\/'.$matches[1].'>[ ]*$/i', $remainder)) {
                    $block['closed'] = true;
                }
            }

            return $block;
        }
    }

    protected function blockMarkupContinue($line, array $block)
    {
        if (isset($block['closed'])) {
            return;
        }

        if (preg_match('/^<'.$block['name'].'(?:[ ]*'.$this->regexHtmlAttribute.')*[ ]*>/i', $line['text'])) {
            # open

            ++$block['depth'];
        }

        if (preg_match('/(.*?)<\/'.$block['name'].'>[ ]*$/i', $line['text'], $matches)) {
            # close

            if ($block['depth'] > 0) {
                --$block['depth'];
            } else {
                $block['closed'] = true;
            }
        }

        if (isset($block['interrupted'])) {
            $block['markup'] .= "\n";

            unset($block['interrupted']);
        }

        $block['markup'] .= "\n".$line['body'];

        return $block;
    }

    #
    # Reference

    protected function blockReference($line)
    {
        if (preg_match('/^\[(.+?)\]:[ ]*<?(\S+?)>?(?:[ ]+["\'(](.+)["\')])?[ ]*$/', $line['text'], $matches)) {
            $id = strtolower($matches[1]);

            $data = [
                'url' => $matches[2],
                'title' => null,
            ];

            if (isset($matches[3])) {
                $data['title'] = $matches[3];
            }

            $this->definitionData['Reference'][$id] = $data;

            $block = [
                'hidden' => true,
            ];

            return $block;
        }
    }

    #
    # Table

    protected function blockTable($line, array $block = null)
    {
        if (!isset($block) || isset($block['type']) || isset($block['interrupted'])) {
            return;
        }

        if (strpos($block['element']['text'], '|') !== false && chop($line['text'], ' -:|') === '') {
            $alignments = [];

            $divider = $line['text'];

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

            $headerElements = [];

            $header = $block['element']['text'];

            $header = trim($header);
            $header = trim($header, '|');

            $headerCells = explode('|', $header);

            foreach ($headerCells as $index => $headerCell) {
                $headerCell = trim($headerCell);

                list($tag_name, $attrs) = $this->getTagAttributes('th');

                $headerElement = [
                    'name' => $tag_name,
                    'text' => $headerCell,
                    'handler' => 'line',
                    'attributes' => $attrs,
                ];

                if (isset($alignments[$index])) {
                    $alignment = $alignments[$index];

                    $headerElement['attributes']['style'] = 'text-align: '.$alignment.';';
                }

                $headerElements [] = $headerElement;
            }

            # ~

            list($tag_name, $attrs) = $this->getTagAttributes('table');

            $block = [
                'alignments' => $alignments,
                'identified' => true,
                'element' => [
                    'name' => $tag_name,
                    'handler' => 'elements',
                    'attributes' => $attrs,
                ],
            ];

            list($tag_name, $attrs) = $this->getTagAttributes('thead');

            $block['element']['text'][] = [
                'name' => $tag_name,
                'handler' => 'elements',
                'attributes' => $attrs,
            ];

            list($tag_name, $attrs) = $this->getTagAttributes('tbody');

            $block['element']['text'][] = [
                'name' => $tag_name,
                'handler' => 'elements',
                'text' => [],
                'attributes' => $attrs,
            ];

            list($tag_name, $attrs) = $this->getTagAttributes('tr');

            $block['element']['text'][0]['text'][] = [
                'name' => $tag_name,
                'handler' => 'elements',
                'text' => $headerElements,
                'attributes' => $attrs,
            ];

            return $block;
        }
    }

    protected function blockTableContinue($line, array $block)
    {
        if (isset($block['interrupted'])) {
            return;
        }

        if ($line['text'][0] === '|' || strpos($line['text'], '|')) {
            $elements = [];

            $row = $line['text'];

            $row = trim($row);
            $row = trim($row, '|');

            preg_match_all('/(?:(\\\\[|])|[^|`]|`[^`]+`|`)+/', $row, $matches);

            foreach ($matches[0] as $index => $cell) {
                $cell = trim($cell);

                list($tag_name, $attrs) = $this->getTagAttributes('td');

                $element = [
                    'name' => $tag_name,
                    'handler' => 'line',
                    'text' => $cell,
                    'attributes' => $attrs,
                ];

                if (isset($block['alignments'][$index])) {
                    $element['attributes']['style'] = 'text-align: '.$block['alignments'][$index].';';
                }

                $elements [] = $element;
            }

            list($tag_name, $attrs) = $this->getTagAttributes('tr');

            $element = [
                'name' => $tag_name,
                'handler' => 'elements',
                'text' => $elements,
                'attributes' => $attrs,
            ];

            $block['element']['text'][1]['text'][] = $element;

            return $block;
        }
    }

    #
    # ~
    #

    protected function paragraph(array $line)
    {
        $block = [
            'element' => [
                'name' => 'p',
                'text' => $line['text'],
                'handler' => 'line',
            ],
        ];

        return $block;
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

            $excerpt = ['text' => $excerpt, 'context' => $text];

            foreach ($this->inlineTypes[$marker] as $inlineType) {
                $inline = $this->{'inline'.$inlineType}($excerpt);

                if (!isset($inline)) {
                    continue;
                }

                # makes sure that the inline belongs to "our" marker

                if (isset($inline['position']) && $inline['position'] > $markerPosition) {
                    continue;
                }

                # sets a default inline position

                if (!isset($inline['position'])) {
                    $inline['position'] = $markerPosition;
                }

                # the text that comes before the inline
                $unmarkedText = substr($text, 0, $inline['position']);

                # compile the unmarked text
                $markup .= $this->unmarkedText($unmarkedText);

                # compile the inline
                $markup .= isset($inline['markup']) ? $inline['markup'] : $this->element($inline['element']);

                # remove the examined text
                $text = substr($text, $inline['position'] + $inline['extent']);

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

    protected function inlineCode($excerpt)
    {
        $marker = $excerpt['text'][0];

        if (preg_match('/^('.$marker.'+)[ ]*(.+?)[ ]*(?<!'.$marker.')\1(?!'.$marker.')/s', $excerpt['text'], $matches)) {
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

    protected function inlineEmailTag($excerpt)
    {
        if (strpos($excerpt['text'], '>') !== false && preg_match('/^<((mailto:)?\S+?@\S+?)>/i', $excerpt['text'], $matches)) {
            $url = $matches[1];

            if (!isset($matches[2])) {
                $url = 'mailto:'.$url;
            }

            list(, $attrs) = $this->getTagAttributes('a');
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

    protected function inlineEmphasis($excerpt)
    {
        if (!isset($excerpt['text'][1])) {
            return;
        }

        $marker = $excerpt['text'][0];

        if ($excerpt['text'][1] === $marker && preg_match($this->strongRegex[$marker], $excerpt['text'], $matches)) {
            $emphasis = 'strong';
        } elseif (preg_match($this->emRegex[$marker], $excerpt['text'], $matches)) {
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

    protected function inlineEscapeSequence($excerpt)
    {
        if (isset($excerpt['text'][1]) && in_array($excerpt['text'][1], $this->specialCharacters)) {
            return [
                'markup' => $excerpt['text'][1],
                'extent' => 2,
            ];
        }
    }

    protected function inlineImage($excerpt)
    {
        if (!isset($excerpt['text'][1]) || $excerpt['text'][1] !== '[') {
            return;
        }

        $excerpt['text'] = substr($excerpt['text'], 1);

        $link = $this->inlineLink($excerpt);

        if ($link === null) {
            return;
        }

        list(, $attrs) = $this->getTagAttributes('img');
        $attrs['src'] = $link['element']['attributes']['href'];
        $attrs['alt'] = $link['element']['text'];

        $inline = [
            'extent' => $link['extent'] + 1,
            'element' => [
                'name' => 'img', // we don't touch images
                'attributes' => $attrs,
            ],
        ];

        $inline['element']['attributes'] += $link['element']['attributes'];

        unset($inline['element']['attributes']['href']);

        return $inline;
    }

    protected function inlineLink($excerpt)
    {
        list(, $attrs) = $this->getTagAttributes('a');
        $attrs['href'] = null;
        $attrs['title'] = null;

        $element = [
            'name' => 'a', // we don't touch links
            'handler' => 'line',
            'text' => null,
            'attributes' => $attrs,
        ];

        $extent = 0;

        $remainder = $excerpt['text'];

        if (preg_match('/\[((?:[^][]|(?R))*)\]/', $remainder, $matches)) {
            $element['text'] = $matches[1];

            $extent += strlen($matches[0]);

            $remainder = substr($remainder, $extent);
        } else {
            return;
        }

        if (preg_match('/^[(]((?:[^ ()]|[(][^ )]+[)])+)(?:[ ]+("[^"]*"|\'[^\']*\'))?[)]/', $remainder, $matches)) {
            $element['attributes']['href'] = $matches[1];

            if (isset($matches[2])) {
                $element['attributes']['title'] = substr($matches[2], 1, -1);
            }

            $extent += strlen($matches[0]);
        } else {
            if (preg_match('/^\s*\[(.*?)\]/', $remainder, $matches)) {
                $definition = strlen($matches[1]) ? $matches[1] : $element['text'];
                $definition = strtolower($definition);

                $extent += strlen($matches[0]);
            } else {
                $definition = strtolower($element['text']);
            }

            if (!isset($this->definitionData['Reference'][$definition])) {
                return;
            }

            $definition = $this->definitionData['Reference'][$definition];

            $element['attributes']['href'] = $definition['url'];
            $element['attributes']['title'] = $definition['title'];
        }

        $element['attributes']['href'] = str_replace(['&', '<'], ['&amp;', '&lt;'], $element['attributes']['href']);

        return [
            'extent' => $extent,
            'element' => $element,
        ];
    }

    protected function inlineMarkup($excerpt)
    {
        if ($this->markupEscaped || strpos($excerpt['text'], '>') === false) {
            return;
        }

        if ($excerpt['text'][1] === '/' && preg_match('/^<\/\w*[ ]*>/s', $excerpt['text'], $matches)) {
            return [
                'markup' => $matches[0],
                'extent' => strlen($matches[0]),
            ];
        }

        if ($excerpt['text'][1] === '!' && preg_match('/^<!---?[^>-](?:-?[^-])*-->/s', $excerpt['text'], $matches)) {
            return [
                'markup' => $matches[0],
                'extent' => strlen($matches[0]),
            ];
        }

        if ($excerpt['text'][1] !== ' ' && preg_match('/^<\w*(?:[ ]*'.$this->regexHtmlAttribute.')*[ ]*\/?>/s', $excerpt['text'], $matches)) {
            return [
                'markup' => $matches[0],
                'extent' => strlen($matches[0]),
            ];
        }
    }

    protected function inlineSpecialCharacter($excerpt)
    {
        if ($excerpt['text'][0] === '&' && !preg_match('/^&#?\w+;/', $excerpt['text'])) {
            return [
                'markup' => '&amp;',
                'extent' => 1,
            ];
        }

        $specialCharacter = ['>' => 'gt', '<' => 'lt', '"' => 'quot'];

        if (isset($specialCharacter[$excerpt['text'][0]])) {
            return [
                'markup' => '&'.$specialCharacter[$excerpt['text'][0]].';',
                'extent' => 1,
            ];
        }
    }

    protected function inlineStrikethrough($excerpt)
    {
        if (!isset($excerpt['text'][1])) {
            return;
        }

        if ($excerpt['text'][1] === '~' && preg_match('/^~~(?=\S)(.+?)(?<=\S)~~/', $excerpt['text'], $matches)) {
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

    protected function inlineUrl($excerpt)
    {
        if ($this->urlsLinked !== true || !isset($excerpt['text'][2]) || $excerpt['text'][2] !== '/') {
            return;
        }

        if (preg_match('/\bhttps?:[\/]{2}[^\s<]+\b\/*/ui', $excerpt['context'], $matches, PREG_OFFSET_CAPTURE)) {
            list(, $attrs) = $this->getTagAttributes('a');
            $attrs['href'] = $matches[0][0];

            $inline = [
                'extent' => strlen($matches[0][0]),
                'position' => $matches[0][1],
                'element' => [
                    'name' => 'a', // we don't touch links
                    'text' => $matches[0][0],
                    'attributes' => $attrs,
                ],
            ];

            return $inline;
        }
    }

    protected function inlineUrlTag($excerpt)
    {
        if (strpos($excerpt['text'], '>') !== false && preg_match('/^<(\w+:\/{2}[^ >]+)>/i', $excerpt['text'], $matches)) {
            $url = str_replace(['&', '<'], ['&amp;', '&lt;'], $matches[1]);
            list(, $attrs) = $this->getTagAttributes('a');
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

    protected function element(array $element)
    {
        $markup = '<'.$element['name'];

        if (isset($element['attributes'])) {
            foreach ($element['attributes'] as $name => $value) {
                if ($value === null) {
                    continue;
                }

                $markup .= ' '.$name.'="'.$value.'"';
            }
        }

        if (isset($element['text'])) {
            $markup .= '>';

            if (isset($element['handler'])) {
                $markup .= $this->{$element['handler']}($element['text']);
            } else {
                $markup .= $element['text'];
            }

            $markup .= '</'.$element['name'].'>';
        } else {
            $markup .= ' />';
        }

        return $markup;
    }

    protected function elements(array $elements)
    {
        $markup = '';

        foreach ($elements as $element) {
            $markup .= "\n".$this->element($element);
        }

        $markup .= "\n";

        return $markup;
    }

    # ~

    protected function li($lines)
    {
        $markup = $this->lines($lines, true);

        $trimmedMarkup = trim($markup);

        if (!in_array('', $lines) && substr($trimmedMarkup, 0, 3) === '<p>') {
            $markup = $trimmedMarkup;
            $markup = substr($markup, 3);

            $position = strpos($markup, '</p>');
            $markup = substr_replace($markup, '', $position, 4);
        }

        return $markup;
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

    protected function processParagraphs($text)
    {
        [$tag_name, $tag_attrs] = $this->getTagAttributes('p');

        if ($tag_name !== 'p' || $tag_attrs) {
            $markup = "<$tag_name";

            foreach ($tag_attrs as $name => $value) {
                if ($value === null) {
                    continue;
                }

                $markup .= ' '.$name.'="'.$value.'"';
            }

            $markup = $markup . '>';

            $text = str_replace(['<p>', '</p>'], [$markup, "</$tag_name>"], $text);
        }

        return $text;
    }
}
